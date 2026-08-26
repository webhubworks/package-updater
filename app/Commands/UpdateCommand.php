<?php

namespace PackageUpdater\Commands;

use LaravelZero\Framework\Commands\Command;
use PackageUpdater\Actions\FindCraftReposAction;
use PackageUpdater\Actions\UpdateRepoAction;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class UpdateCommand extends Command
{
    protected $signature = 'update
        {package?* : Composer package name(s) to update; blank = full update}
        {--no-ddev : Skip ddev detection and run composer on the host}
        {--no-craft : Force composer update even if this repo is a Craft CMS repo}
        {--commit : Always commit the resulting changes (skip prompt)}
        {--no-commit : Never commit (skip prompt)}
        {--show-output : Stream composer output live instead of running it under a spinner}
        {--yes : Skip the run confirmation (defaults to committing if neither --commit nor --no-commit is set)}';

    protected $description = 'Update the current repo (Craft repos: `ddev php craft update all`; otherwise: `composer update`), parse the package changes, and commit them';

    public function handle(): int
    {
        $cwd = getcwd() ?: '.';

        if (! is_file($cwd.'/composer.json')) {
            $this->error("No composer.json found in {$cwd}");

            return self::FAILURE;
        }

        if (! is_dir($cwd.'/.git')) {
            $this->error("{$cwd} is not a git repository");

            return self::FAILURE;
        }

        $status = $this->exec(['git', 'status', '--porcelain'], $cwd, stream: false);
        if (! $status->isSuccessful()) {
            $this->error('git status failed: '.trim($status->getErrorOutput() ?: $status->getOutput()));

            return self::FAILURE;
        }
        if (trim($status->getOutput()) !== '') {
            warning('Working tree is dirty - uncommitted changes will be mixed into this update.');
            if (! $this->option('yes') && ! confirm('Continue anyway?', default: false)) {
                return self::FAILURE;
            }
        }

        $useDdev = ! $this->option('no-ddev') && is_file($cwd.'/.ddev/config.yaml');
        $packages = array_values(array_filter(
            array_map('strval', (array) $this->argument('package')),
            fn ($p) => $p !== '',
        ));

        // Craft mode only kicks in when we have ddev (needed to run `php craft`),
        // no explicit package list (craft update is a whole-site refresh), and
        // the user didn't opt out via --no-craft.
        $isCraft = $useDdev
            && empty($packages)
            && ! $this->option('no-craft')
            && self::isCraftRepo($cwd);

        if ($isCraft) {
            $defaultCmd = 'ddev php craft update all --interactive=0 --with-expired --minor-only --backup=1';
        } else {
            $base = $useDdev ? 'ddev composer update' : 'composer update';
            $defaultCmd = trim($base.' '.implode(' ', $packages));
        }

        if ($this->option('yes')) {
            $cmd = $defaultCmd;
        } else {
            $cmd = trim((string) text(
                label: "Do you want to run the following command in {$cwd}?",
                default: $defaultCmd,
                required: true,
                hint: 'Edit if needed, then press Enter to run it.',
            ));
            if ($cmd === '') {
                return self::SUCCESS;
            }
        }
        $label = $cmd;

        $update = $this->runComposer($cmd, $cwd, $label);
        $logPath = $this->writeLog($cwd, $cmd, $update);

        if (! $update->isSuccessful()) {
            $failedLabel = $isCraft ? 'craft update' : 'composer update';
            $this->error("{$failedLabel} failed (exit {$update->getExitCode()}):");
            $this->dumpOutput($update);
            $this->printLogPath($logPath);

            return self::FAILURE;
        }

        $combined = $update->getOutput()."\n".$update->getErrorOutput();
        $updates = $isCraft
            ? UpdateRepoAction::parseCraftUpdates($combined)
            : self::parseComposerUpdates($combined);

        $this->newLine();
        if (empty($updates)) {
            info($isCraft
                ? 'No package changes detected in craft update output.'
                : 'No package changes detected in composer output.');
        } else {
            info(sprintf('%d package change(s):', count($updates)));
            foreach ($updates as $u) {
                $this->line('  '.($isCraft ? self::formatCraftUpdateLine($u) : self::formatUpdateLine($u)));
            }
        }

        $audit = null;
        if (! $isCraft) {
            $audit = self::parseAuditSummary($combined);
            $this->renderAudit($audit);
        }
        $this->printLogPath($logPath);

        $statusAfter = $this->exec(['git', 'status', '--porcelain'], $cwd, stream: false);
        $hasChanges = $statusAfter->isSuccessful() && trim($statusAfter->getOutput()) !== '';

        $exitCode = self::SUCCESS;
        if (! $hasChanges) {
            info($isCraft
                ? 'No working-tree changes after craft update — nothing to commit.'
                : 'No working-tree changes after composer update — nothing to commit.');
        } elseif ($this->shouldCommit() && ! $this->commit($cwd, $updates, $isCraft)) {
            $exitCode = self::FAILURE;
        }

        // Craft mode skips composer prep — `ddev php craft update` is its own
        // verification layer and the user opted out of running prep on top.
        if (! $isCraft && $this->shouldRunPrep($cwd, $audit)) {
            $this->runPrep($cwd, $useDdev);
        }

        return $exitCode;
    }

    private static function isCraftRepo(string $cwd): bool
    {
        $content = @file_get_contents($cwd.'/composer.json');
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return false;
        }

        return isset($data['require'][FindCraftReposAction::CraftPackage])
            || isset($data['require-dev'][FindCraftReposAction::CraftPackage]);
    }

    /**
     * When the update surfaced security advisories, prep (tests/phpstan) is
     * usually a poor use of time until the vulnerabilities are dealt with, so
     * we ask before running it instead of barrelling ahead. With no advisories
     * (or with --yes), prep runs as before. The prompt is skipped entirely when
     * the repo has no `prep` script — there'd be nothing to run either way.
     *
     * @param  array{state: string, vulnerabilityCount: int, abandonedCount: int, packages: list<string>}|null  $audit
     */
    private function shouldRunPrep(string $cwd, ?array $audit): bool
    {
        if (! UpdateRepoAction::hasComposerScript($cwd, 'prep')) {
            return true;
        }
        if ($audit === null || $audit['vulnerabilityCount'] === 0 || $this->option('yes')) {
            return true;
        }

        $this->newLine();

        return confirm(
            label: 'Security advisories were found. Still run prep?',
            default: true,
        );
    }

    /**
     * Runs `composer prep` (when the repo defines it) after the update +
     * commit step so the user sees test results before walking away. Failures
     * here do not change the command's exit code — the update itself already
     * succeeded; prep is a verification layer.
     */
    private function runPrep(string $cwd, bool $useDdev): void
    {
        if (! UpdateRepoAction::hasComposerScript($cwd, 'prep')) {
            return;
        }

        $cmd = $useDdev ? ['ddev', 'composer', 'prep'] : ['composer', 'prep'];
        $label = implode(' ', $cmd);

        $this->newLine();
        // Prep typically invokes phpstan/pest, which buffer their output when
        // they don't see a TTY — without a pty the tail renderer has nothing
        // to draw until the run finishes. setPty makes them flush per line.
        $prep = $this->runComposer($cmd, $cwd, $label, usePty: Process::isPtySupported());
        $logPath = $this->writeLog($cwd, $cmd, $prep, 'pu-prep');

        $outcome = UpdateRepoAction::summarizePrep($prep);

        // Prep steps that crashed but were swallowed by the script's
        // continue-on-error behaviour. Drop the ones already explained by the
        // test/phpstan displays below so we don't report the same failure
        // twice; a crash with no parseable summary (e.g. paratest dying on a
        // container binding) survives the filter and is surfaced here.
        $stepFailures = array_values(array_filter(
            $outcome['prepStepFailures'],
            function (array $f) use ($outcome): bool {
                if ($outcome['testsSummary'] !== null && preg_match('/\b(artisan test|pest|phpunit|paratest)\b/i', $f['command'])) {
                    return false;
                }
                if (($outcome['phpstanErrors'] ?? null) !== null && preg_match('/phpstan/i', $f['command'])) {
                    return false;
                }

                return true;
            },
        ));

        $this->newLine();
        if ($outcome['testsSummary'] === null) {
            if ($stepFailures === []) {
                $this->line($prep->isSuccessful()
                    ? '  <fg=gray>Prep ran but no test summary detected.</>'
                    : "  <fg=red;options=bold>✗ Prep failed (exit {$prep->getExitCode()}); no test summary detected.</>");
            }
        } elseif (($outcome['testsFailed'] ?? 0) > 0) {
            $this->line("  <fg=red;options=bold>✗ Tests: {$outcome['testsSummary']}</>");
            foreach ($outcome['failedTests'] as $failed) {
                $location = $failed['at'] !== null ? " <fg=gray>({$failed['at']})</>" : '';
                $this->line("    <fg=red>•</> {$failed['name']}{$location}");
            }
        } else {
            $this->line("  <fg=green>✓ Tests: {$outcome['testsSummary']}</>");
        }

        $phpstanErrors = $outcome['phpstanErrors'];
        if ($phpstanErrors !== null && $phpstanErrors > 0) {
            $this->line(sprintf(
                '  <fg=red;options=bold>✗ PHPStan: %d error%s</>',
                $phpstanErrors,
                $phpstanErrors === 1 ? '' : 's',
            ));
        }

        if ($stepFailures !== []) {
            $this->line('  <fg=red;options=bold>✗ Prep step failed (crashed before producing a summary):</>');
            foreach ($stepFailures as $failed) {
                $this->line("    <fg=red>•</> {$failed['command']}");
                if ($failed['error'] !== null) {
                    $this->line("      <fg=gray>{$failed['error']}</>");
                }
            }
        }

        $this->printLogPath($logPath);
    }

    /** @param  list<string>|string  $cmd */
    private function writeLog(string $cwd, array|string $cmd, Process $process, string $prefix = 'pu-update'): ?string
    {
        $dir = $this->resolveLogDir($cwd);
        if ($dir === null) {
            return null;
        }

        $slug = preg_replace('/[^a-z0-9]+/i', '-', basename($cwd)) ?: 'repo';
        $file = sprintf('%s/%s-%s-%s.log', $dir, $prefix, trim($slug, '-'), date('Ymd-His'));

        $cmdStr = is_array($cmd) ? implode(' ', $cmd) : $cmd;
        $contents = '# Command: '.$cmdStr."\n"
            .'# CWD: '.$cwd."\n"
            .'# Exit: '.$process->getExitCode()."\n"
            .'# Timestamp: '.date('c')."\n"
            ."\n--- STDOUT ---\n".$process->getOutput()
            ."\n--- STDERR ---\n".$process->getErrorOutput();

        return @file_put_contents($file, $contents) === false ? null : $file;
    }

    /**
     * Prefer the repo's own `storage/logs` (Laravel/Craft convention — keeps
     * the log next to the code that produced it, and gets gitignored
     * automatically). Fall back to a per-user dotdir when the repo isn't
     * Laravel/Craft shaped so we don't pollute its working tree.
     */
    private function resolveLogDir(string $cwd): ?string
    {
        $repoLogs = $cwd.'/storage/logs';
        if (is_dir($repoLogs) && is_writable($repoLogs)) {
            return $repoLogs;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: null;
        if ($home === null) {
            return null;
        }

        $fallback = $home.'/.pu-update/logs';
        if (! is_dir($fallback) && ! @mkdir($fallback, 0755, true) && ! is_dir($fallback)) {
            return null;
        }

        return $fallback;
    }

    private function printLogPath(?string $logPath): void
    {
        if ($logPath === null) {
            return;
        }
        $this->newLine();
        $this->line("  <fg=gray>Log:</> {$logPath}");
    }

    /** @param  list<string>|string  $cmd */
    private function runComposer(array|string $cmd, string $cwd, string $label, bool $usePty = false): Process
    {
        if ($this->option('show-output')) {
            info("Running: {$label}");

            return $this->exec($cmd, $cwd, stream: true, usePty: $usePty);
        }

        return $this->runComposerWithTail($cmd, $cwd, $label, $usePty);
    }

    /**
     * Runs composer with a 5-line live "tail" view: header + the last few
     * output lines, redrawn in place via ANSI cursor controls. Falls back to
     * a single static "Running..." line for non-decorated outputs (pipes,
     * CI), where redraw escapes would render as garbage.
     *
     * @param  list<string>|string  $cmd
     */
    private function runComposerWithTail(array|string $cmd, string $cwd, string $label, bool $usePty = false): Process
    {
        if (! $this->output->isDecorated()) {
            $this->line("  Running `{$label}`...");

            return $this->exec($cmd, $cwd, stream: false, usePty: $usePty);
        }

        $maxLines = 5;
        $width = max(40, (new Terminal)->getWidth() - 6);
        $buffer = [];
        $rendered = 0;
        $output = $this->output;

        $output->writeln("  <fg=cyan>⠋</> Running <options=bold>{$label}</>...");

        $clear = function () use (&$rendered, $output): void {
            for ($i = 0; $i < $rendered; $i++) {
                $output->write("\033[1A\033[2K");
            }
            $rendered = 0;
        };

        $draw = function () use (&$buffer, &$rendered, $maxLines, $width, $output, $clear): void {
            $clear();
            $shown = array_slice($buffer, -$maxLines);
            foreach ($shown as $line) {
                $line = mb_strimwidth($line, 0, $width, '…');
                $output->writeln('    <fg=gray>'.OutputFormatter::escape($line).'</>');
                $rendered++;
            }
        };

        $process = is_string($cmd)
            ? Process::fromShellCommandline($cmd, $cwd)
            : new Process($cmd, $cwd);
        $process->setTimeout(3600);
        if ($usePty) {
            $process->setPty(true);
        }
        $process->run(function (string $type, string $chunk) use (&$buffer, $draw): void {
            $chunk = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $chunk) ?? $chunk;
            foreach (preg_split("/\r\n|\r|\n/", $chunk) as $line) {
                $line = rtrim($line);
                if ($line === '') {
                    continue;
                }
                $buffer[] = $line;
                $draw();
            }
        });

        // Wipe the tail strip and the "Running..." header so the summary that
        // follows starts on a clean line.
        $clear();
        $output->write("\033[1A\033[2K");

        return $process;
    }

    private function dumpOutput(Process $process): void
    {
        $combined = trim($process->getOutput()."\n".$process->getErrorOutput());
        foreach (preg_split("/\r\n|\r|\n/", $combined) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            $this->line("    <fg=gray>{$line}</>");
        }
    }

    /**
     * @param  array{state: string, vulnerabilityCount: int, abandonedCount: int, packages: list<string>}  $audit
     */
    private function renderAudit(array $audit): void
    {
        $this->newLine();
        match ($audit['state']) {
            'clean' => $this->line('  <fg=green>✓ Security audit: no advisories</>'),
            'unknown' => $this->line('  <fg=gray>Security audit: no result parsed</>'),
            default => $this->renderAuditFindings($audit),
        };
    }

    /**
     * @param  array{state: string, vulnerabilityCount: int, abandonedCount: int, packages: list<string>}  $audit
     */
    private function renderAuditFindings(array $audit): void
    {
        $parts = [];
        if ($audit['vulnerabilityCount'] > 0) {
            $parts[] = sprintf(
                '%d security advisor%s',
                $audit['vulnerabilityCount'],
                $audit['vulnerabilityCount'] === 1 ? 'y' : 'ies',
            );
        }
        if ($audit['abandonedCount'] > 0) {
            $parts[] = sprintf(
                '%d abandoned package%s',
                $audit['abandonedCount'],
                $audit['abandonedCount'] === 1 ? '' : 's',
            );
        }

        $color = $audit['vulnerabilityCount'] > 0 ? 'red' : 'yellow';
        $this->line(sprintf('  <fg=%s;options=bold>! Security audit: %s</>', $color, implode(', ', $parts)));

        if (! empty($audit['packages'])) {
            foreach ($audit['packages'] as $name) {
                $this->line("    <fg={$color}>- {$name}</>");
            }
        }
        $this->line('  <fg=gray>Run `composer audit` for details.</>');
    }

    private function shouldCommit(): bool
    {
        if ($this->option('no-commit')) {
            return false;
        }
        if ($this->option('commit') || $this->option('yes')) {
            return true;
        }

        return confirm(
            label: 'Commit the changes? (title: "Package updates", body: parsed update list)',
            default: true,
        );
    }

    /** @param  list<array<string, mixed>>  $updates */
    private function commit(string $cwd, array $updates, bool $isCraft = false): bool
    {
        $add = $this->exec(['git', 'add', '-A'], $cwd, stream: false);
        if (! $add->isSuccessful()) {
            warning('git add -A failed.');

            return false;
        }

        if (empty($updates)) {
            $body = $isCraft
                ? '(no update list parsed from craft output)'
                : '(no update list parsed from composer output)';
        } else {
            $formatter = $isCraft ? self::formatCraftUpdateLine(...) : self::formatUpdateLine(...);
            $body = implode("\n", array_map($formatter, $updates));
        }

        $commit = $this->exec(
            ['git', 'commit', '-m', 'Package updates', '-m', $body],
            $cwd,
            stream: false,
        );
        if (! $commit->isSuccessful()) {
            warning('git commit failed.');

            return false;
        }

        info('Committed.');

        return true;
    }

    /** @param  array{kind: string, name: string, from: ?string, to: ?string}  $u */
    private static function formatUpdateLine(array $u): string
    {
        return match ($u['kind']) {
            'upgrade' => sprintf('- %s %s => %s', $u['name'], $u['from'], $u['to']),
            'downgrade' => sprintf('- %s %s => %s (downgrade)', $u['name'], $u['from'], $u['to']),
            'install' => sprintf('- %s installed (%s)', $u['name'], $u['to']),
            'remove' => sprintf('- %s removed (was %s)', $u['name'], $u['from']),
        };
    }

    /** @param  array{name: string, from: string, to: string}  $u */
    private static function formatCraftUpdateLine(array $u): string
    {
        return sprintf('- %s %s => %s', $u['name'], $u['from'], $u['to']);
    }

    /**
     * Parses composer's "Lock file operations" lines:
     *
     *   - Upgrading vendor/foo (1.0.0 => 1.0.1)
     *   - Downgrading vendor/foo (1.0.1 => 1.0.0)
     *   - Installing vendor/foo (1.0.0)
     *   - Removing vendor/foo (1.0.0)
     *
     * Only the "Lock file operations" block is read — it's the one that mirrors
     * what changed in composer.lock (and thus the commit). The later "Package
     * operations" block reflects the local vendor tree catching up to the lock,
     * which on a repo whose lock is already ahead of vendor balloons into
     * unrelated packages the commit never touches. De-dupe by name.
     *
     * @return list<array{kind: string, name: string, from: ?string, to: ?string}>
     */
    public static function parseComposerUpdates(string $output): array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $lines = preg_split("/\r\n|\r|\n/", $stripped) ?: [];

        $sawLockHeader = false;
        $inLockSection = false;
        $byName = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if (preg_match('/^Lock file operations:/i', $trimmed)) {
                $sawLockHeader = true;
                $inLockSection = true;

                continue;
            }
            if ($inLockSection && preg_match('/^(Writing lock file|Package operations:|Installing dependencies)/i', $trimmed)) {
                $inLockSection = false;

                continue;
            }
            // Once we've seen the lock block, ignore everything outside it. If
            // composer never printed that header (very old/unusual output),
            // fall back to scanning every bullet line.
            if ($sawLockHeader && ! $inLockSection) {
                continue;
            }
            if (! str_starts_with($trimmed, '- ')) {
                continue;
            }
            $rest = substr($trimmed, 2);

            if (preg_match('/^Upgrading\s+(\S+)\s+\(([^\s)]+)\s*=>\s*([^\s)]+)\)/', $rest, $m)) {
                $byName[$m[1]] = ['kind' => 'upgrade', 'name' => $m[1], 'from' => $m[2], 'to' => $m[3]];

                continue;
            }
            if (preg_match('/^Downgrading\s+(\S+)\s+\(([^\s)]+)\s*=>\s*([^\s)]+)\)/', $rest, $m)) {
                $byName[$m[1]] = ['kind' => 'downgrade', 'name' => $m[1], 'from' => $m[2], 'to' => $m[3]];

                continue;
            }
            if (preg_match('/^Installing\s+(\S+)\s+\(([^\s)]+)\)/', $rest, $m)) {
                if (! isset($byName[$m[1]])) {
                    $byName[$m[1]] = ['kind' => 'install', 'name' => $m[1], 'from' => null, 'to' => $m[2]];
                }

                continue;
            }
            if (preg_match('/^Removing\s+(\S+)\s+\(([^\s)]+)\)/', $rest, $m)) {
                $byName[$m[1]] = ['kind' => 'remove', 'name' => $m[1], 'from' => $m[2], 'to' => null];
            }
        }

        return array_values($byName);
    }

    /**
     * Parses `composer audit` output (which composer runs automatically after
     * a successful update). Composer prints one of:
     *
     *   No security vulnerability advisories found.
     *
     *   Found N security vulnerability advisories affecting M package(s):
     *   +-----------+--------------------------+
     *   | Package   | symfony/http-foundation  |
     *   ...
     *
     *   Found N abandoned package(s):
     *   +-----------+-------------------+
     *   | Package   | foo/bar           |
     *   ...
     *
     * The audit can run twice in a single composer-update flow (e.g. when
     * a post-install script like `boost:update` triggers another composer
     * call); the parser de-dupes packages by name so the summary stays
     * truthful.
     *
     * @return array{state: string, vulnerabilityCount: int, abandonedCount: int, packages: list<string>}
     */
    public static function parseAuditSummary(string $output): array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;

        $clean = preg_match('/(?:^|\n)\s*No security vulnerability advisories found\.\s*(?:\n|$)/', $stripped) === 1;

        $vulnCount = 0;
        if (preg_match('/(?:^|\n)\s*Found (\d+) security vulnerability advisor/i', $stripped, $m)) {
            $vulnCount = (int) $m[1];
        }

        $abandonedCount = 0;
        if (preg_match('/(?:^|\n)\s*Found (\d+) abandoned package/i', $stripped, $m)) {
            $abandonedCount = (int) $m[1];
        }

        $packages = [];
        if (preg_match_all('/^\s*\|\s*Package\s*\|\s*([^|]+?)\s*\|\s*$/m', $stripped, $matches)) {
            foreach ($matches[1] as $name) {
                $packages[] = trim($name);
            }
        }
        $packages = array_values(array_unique($packages));

        if ($vulnCount > 0 || $abandonedCount > 0) {
            $state = 'findings';
        } elseif ($clean) {
            $state = 'clean';
        } else {
            $state = 'unknown';
        }

        return [
            'state' => $state,
            'vulnerabilityCount' => $vulnCount,
            'abandonedCount' => $abandonedCount,
            'packages' => $packages,
        ];
    }

    /** @param  list<string>|string  $cmd */
    private function exec(array|string $cmd, string $cwd, bool $stream, bool $usePty = false): Process
    {
        $process = is_string($cmd)
            ? Process::fromShellCommandline($cmd, $cwd)
            : new Process($cmd, $cwd);
        $process->setTimeout(3600);
        if ($usePty) {
            $process->setPty(true);
        }

        if ($stream) {
            $process->run(function (string $type, string $buffer): void {
                foreach (preg_split("/\r\n|\r|\n/", $buffer) as $line) {
                    $line = rtrim($line);
                    if ($line === '') {
                        continue;
                    }
                    $this->line("    <fg=gray>{$line}</>");
                }
            });
        } else {
            $process->run();
        }

        return $process;
    }
}
