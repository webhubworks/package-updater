<?php

namespace App\Commands;

use App\Actions\FindReposAction;
use App\Actions\LastRunStore;
use App\Actions\OpenInGitKrakenAction;
use App\Actions\UpdateRepoAction;
use App\Concerns\ResolvesReposDir;
use App\DataTransferObjects\RepoUpdateResult;
use Illuminate\Console\OutputStyle;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class UpdateAllCommand extends Command
{
    use ResolvesReposDir;

    protected $signature = 'update:all
        {package? : Composer package name (vendor/name)}
        {--reps-dir= : Directory containing repos (default: ~/reps)}
        {--parallel= : Number of repos to update concurrently (default: prompt; 1 = sequential)}
        {--dry-run : List matching repos with their currently locked version and exit}
        {--repo=* : Process only the specified repo path(s); can be passed multiple times. Skips the interactive repo selection.}
        {--filter-name= : Keep only repos whose composer.json "name" contains this substring}
        {--no-ssh-auth : Skip the initial `ddev auth ssh` step}
        {--with-all-dependencies : Pass -W to composer (always set when --update-package is used)}
        {--update-package= : Run `composer update` on this package instead of the target. Useful for transitive targets where a parent constraint blocks reaching the desired version.}
        {--target-version= : Skip repos whose composer.lock already has this version of the package}
        {--stop-ddev : Stop the ddev project in each repo after a successful update (default: keep running)}
        {--open : After the run, open every repo with uncommitted changes in GitKraken (skips the prompt)}
        {--no-open : Skip the end-of-run "open in GitKraken" prompt entirely}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Update a Composer package across all local repos that depend on it';

    public function handle(): int
    {
        // Resolve the repos dir BEFORE any other prompt so the first-run
        // setup flow runs before we start asking about packages/plugins.
        $reposDir = $this->resolveReposDir();
        if ($reposDir === null) {
            return self::FAILURE;
        }

        if (! is_dir($reposDir)) {
            $this->error("Repos directory not found: {$reposDir}");
            return self::FAILURE;
        }

        $package = $this->argument('package') ?: text(
            label: 'Which Composer package should be updated?',
            placeholder: 'webhubworks/panoptikum-cell or laravel-lang/*',
            required: true,
            validate: fn (string $value) => str_contains($value, '/')
                ? null
                : 'Use vendor/package format (wildcards allowed, e.g. laravel-lang/*)',
        );

        $isPattern = FindReposAction::isPattern($package);

        info("Scanning {$reposDir} for repos that require {$package}...");
        $matches = FindReposAction::find($reposDir, $package);

        if (empty($matches)) {
            warning("No repositories under {$reposDir} require {$package}.");
            return self::SUCCESS;
        }

        $totalFound = count($matches);
        $matches = $this->applyNameFilter($matches);

        if (empty($matches)) {
            warning(sprintf(
                "No repos remain after --filter-name=%s (started with %d).",
                (string) $this->option('filter-name'),
                $totalFound,
            ));
            return self::SUCCESS;
        }

        info(sprintf(
            'Found %d repositor%s%s.',
            count($matches),
            count($matches) === 1 ? 'y' : 'ies',
            count($matches) !== $totalFound ? sprintf(' (filtered from %d by name)', $totalFound) : '',
        ));

        if ($this->option('dry-run')) {
            if ($isPattern) {
                $rows = [];
                foreach ($matches as $m) {
                    foreach ($m['matchedPackages'] ?? [] as $pkg) {
                        $rows[] = [
                            basename($m['path']),
                            $pkg['name'],
                            $pkg['version'],
                            $pkg['isDirect'] ? 'direct' : 'transitive',
                        ];
                    }
                }
                table(['Repo', 'Package', 'Locked version', 'Dep type'], $rows);
            } else {
                table(
                    ['Repo', "Locked {$package} version", 'Dep type'],
                    array_map(fn ($m) => [
                        basename($m['path']),
                        $m['version'],
                        $m['isDirect'] ? 'direct' : 'transitive',
                    ], $matches),
                );
            }
            note('Dry run — no changes were made. Note: versions reflect each repo\'s current local composer.lock and may be stale.');
            return self::SUCCESS;
        }

        if ($isPattern) {
            $rawTarget = null;
            $targetVersion = null;
            $preSkipped = [];
        } else {
            $rawTarget = $this->resolveTargetVersion();
            [$matches, $preSkipped, $targetVersion] = $this->applyTargetVersionFilter($matches, $rawTarget);

            if (empty($matches)) {
                $this->printSummary($preSkipped, $targetVersion);
                return self::SUCCESS;
            }
        }

        $updatePackage = $isPattern ? $package : $this->resolveUpdatePackage($matches, $package);

        if ($updatePackage !== $package) {
            $applicable = [];
            $skippedNoParent = [];
            foreach ($matches as $m) {
                if (FindReposAction::hasPackageInLock($m['path'], $updatePackage)) {
                    $applicable[] = $m;
                    continue;
                }
                $skippedNoParent[] = RepoUpdateResult::skipped(
                    $m['path'],
                    "{$updatePackage} not present in composer.lock",
                );
            }

            if (! empty($skippedNoParent)) {
                info(sprintf(
                    '%d of %d remaining repos do not have %s — skipping. %d will be processed.',
                    count($skippedNoParent),
                    count($matches),
                    $updatePackage,
                    count($applicable),
                ));
            }

            $matches = $applicable;
            $preSkipped = array_merge($preSkipped, $skippedNoParent);

            if (empty($matches)) {
                $this->printSummary($preSkipped, $targetVersion);
                return self::SUCCESS;
            }
        }

        $withAllDependencies = $isPattern
            ? true
            : $this->resolveWithAllDependencies($updatePackage, $package);

        $matches = $this->resolveRepoSelection($matches);
        if (empty($matches)) {
            info('No repos selected — exiting.');
            return self::SUCCESS;
        }

        $parallel = $this->resolveParallel();
        $keepDdevRunning = $this->resolveKeepDdevRunning();

        $repos = array_map(fn ($m) => $m['path'], $matches);
        $mode = $parallel === 1 ? 'sequentially' : "with {$parallel} workers in parallel";

        $composerCmd = 'composer update ' . $updatePackage . ($withAllDependencies ? ' -W' : '');
        if (! $this->option('yes') && ! confirm("Run `{$composerCmd}` in " . count($repos) . " repos {$mode}?", default: true)) {
            return self::SUCCESS;
        }

        LastRunStore::save('update:all', ['package' => $package], [
            'reps-dir' => $reposDir,
            'parallel' => (string) $parallel,
            'target-version' => $rawTarget,
            'filter-name' => $this->option('filter-name') ?: null,
            'update-package' => $updatePackage !== $package ? $updatePackage : null,
            'with-all-dependencies' => $withAllDependencies,
            'repo' => array_map(fn ($m) => $m['path'], $matches),
            'stop-ddev' => ! $keepDdevRunning,
            'no-ssh-auth' => (bool) $this->option('no-ssh-auth'),
            'yes' => true,
        ]);

        if (! $this->option('no-ssh-auth')) {
            $this->ensureDdevSshAuth();
        }

        $updater = fn (string $repo, callable $onProgress) => UpdateRepoAction::update(
            $repo,
            $package,
            $onProgress,
            $withAllDependencies,
            $updatePackage,
            $keepDdevRunning,
        );

        $buildCmd = function (string $repo, string $php, string $binary) use ($package, $withAllDependencies, $updatePackage, $keepDdevRunning): array {
            $cmd = [$php, $binary, 'update:single', $repo, $package];
            if ($withAllDependencies) {
                $cmd[] = '--with-all-dependencies';
            }
            if ($updatePackage !== $package) {
                $cmd[] = '--update-package=' . $updatePackage;
            }
            if (! $keepDdevRunning) {
                $cmd[] = '--stop-ddev';
            }
            return $cmd;
        };

        $results = $parallel === 1
            ? $this->runSequential($repos, $updater)
            : $this->runParallel($repos, $parallel, $buildCmd);

        $allResults = array_merge($preSkipped, $results);
        $this->printSummary($allResults, $targetVersion);
        LastRunStore::saveResults('update:all', array_map(fn ($r) => $r->toArray(), $allResults));
        $this->offerOpenPrompt($allResults);

        return self::SUCCESS;
    }

    private function resolveWithAllDependencies(string $updatePackage, string $package): bool
    {
        if ($updatePackage !== $package) {
            return true;
        }

        if ($this->option('with-all-dependencies')) {
            return true;
        }

        if ($this->option('yes')) {
            return false;
        }

        return confirm(
            label: "Pass --with-all-dependencies (-W) so composer also updates {$package}'s own dependencies?",
            default: false,
            hint: 'Without -W, composer updates only the named package and refuses if any of its deps would also need to move.',
        );
    }

    /**
     * Offer to open every non-skipped repo (success or failure) in GitKraken
     * (one tab per repo via the gitkraken:// URL scheme). Successes are worth
     * a tab so the user can review the commit / push it; failures so they can
     * investigate. The default selection is all candidates. Skipped if
     * --no-open is set, or if --yes is set without an explicit --open.
     *
     * @param  list<RepoUpdateResult>  $results
     */
    protected function offerOpenPrompt(array $results): void
    {
        if ($this->option('no-open')) {
            return;
        }

        $candidates = array_values(array_filter(
            $results,
            fn (RepoUpdateResult $r) => $r->status !== 'skipped',
        ));
        if (empty($candidates)) {
            return;
        }

        if ($this->option('open')) {
            $paths = array_map(fn ($r) => $r->repoPath, $candidates);
        } elseif ($this->option('yes')) {
            // Non-interactive run without an explicit --open: don't open.
            return;
        } else {
            $options = [];
            foreach ($candidates as $r) {
                $options[$r->repoPath] = basename($r->repoPath) . OpenCommand::badge($r);
            }
            $selected = multiselect(
                label: sprintf('Open %d repo(s) in GitKraken?', count($candidates)),
                options: $options,
                default: array_keys($options),
                hint: 'Space to toggle · Ctrl+A to select/deselect all · Enter to confirm',
                required: false,
            );
            $paths = array_values(array_map('strval', (array) $selected));
        }

        if (empty($paths)) {
            return;
        }

        $report = OpenInGitKrakenAction::open($paths);
        info(sprintf('Opened %d repo(s) in GitKraken.', $report['opened']));
        if (! empty($report['failed'])) {
            warning(sprintf('Failed to open %d repo(s):', count($report['failed'])));
            foreach ($report['failed'] as $p) {
                $this->line("  <fg=yellow>! {$p}</>");
            }
        }
    }

    /**
     * Filter $matches down to the repos the user wants to process. Precedence:
     *   1. --repo=...  (any number; skips the prompt)
     *   2. --yes        (selects all $matches non-interactively)
     *   3. multiselect  (default: nothing selected; Ctrl+A toggles all)
     *
     * @param  list<array{path: string, version: string, ...}>  $matches
     * @return list<array<string, mixed>>
     */
    protected function resolveRepoSelection(array $matches): array
    {
        $cliRepos = array_values(array_filter(
            array_map('strval', (array) $this->option('repo')),
            fn ($p) => $p !== '',
        ));

        if (! empty($cliRepos)) {
            $set = array_flip($cliRepos);
            return array_values(array_filter($matches, fn ($m) => isset($set[$m['path']])));
        }

        if ($this->option('yes')) {
            return $matches;
        }

        $options = [];
        foreach ($matches as $m) {
            $label = basename($m['path']) . ' (' . $m['version'] . ')';
            if (isset($m['matchedPackages']) && count($m['matchedPackages']) > 1) {
                $names = array_map(fn ($p) => $p['name'], $m['matchedPackages']);
                $preview = array_slice($names, 0, 3);
                $suffix = implode(', ', $preview);
                if (count($names) > 3) {
                    $suffix .= ', ...';
                }
                $label = basename($m['path']) . ' (' . $m['version'] . ': ' . $suffix . ')';
            }
            $options[$m['path']] = $label;
        }

        $selected = multiselect(
            label: 'Which repos should be updated?',
            options: $options,
            default: [],
            hint: 'Space to toggle · Ctrl+A to select/deselect all · Enter to confirm',
            required: false,
        );

        $set = array_flip(array_map('strval', (array) $selected));

        return array_values(array_filter($matches, fn ($m) => isset($set[$m['path']])));
    }

    /**
     * Filter matches by substring match against the repo's composer.json "name"
     * field. No-op when --filter-name is not set.
     *
     * @param  list<array{path: string, version: string, ...}>  $matches
     * @return list<array<string, mixed>>
     */
    protected function applyNameFilter(array $matches): array
    {
        $filter = $this->option('filter-name');
        if (! is_string($filter) || $filter === '') {
            return $matches;
        }

        return array_values(array_filter($matches, function ($m) use ($filter) {
            $composerJson = $m['path'] . '/composer.json';
            $content = @file_get_contents($composerJson);
            if ($content === false) {
                return false;
            }
            $data = json_decode($content, true);
            if (! is_array($data)) {
                return false;
            }
            $name = (string) ($data['name'] ?? '');

            return $name !== '' && str_contains($name, $filter);
        }));
    }

    protected function resolveTargetVersion(): ?string
    {
        $option = $this->option('target-version');
        if ($option !== null && $option !== '') {
            return (string) $option;
        }

        if ($this->option('yes')) {
            return null;
        }

        $value = text(
            label: 'Target version (blank = no constraint; "5.9.23" = same-major only; "!5.9.23" = force across majors)',
            placeholder: 'e.g. 5.9.23 or !5.9.23',
            default: '',
        );

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Split the raw target value into a cleaned version and a force-major flag.
     * A leading "!" means "yes, cross major-version boundaries"; otherwise the
     * filter keeps only repos whose current major matches the target.
     *
     * @return array{0: ?string, 1: bool}
     */
    protected static function parseTargetVersion(?string $raw): array
    {
        if ($raw === null) {
            return [null, false];
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [null, false];
        }
        if (str_starts_with($trimmed, '!')) {
            return [trim(substr($trimmed, 1)), true];
        }

        return [$trimmed, false];
    }

    protected static function extractMajor(string $version): ?int
    {
        $v = ltrim(trim($version), 'vV');
        if (preg_match('/^(\d+)/', $v, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Apply the target-version filter: pre-skip repos already at the cleaned
     * target, and (unless forceMajor) repos whose current major differs from
     * the target's major. Returns the remaining matches, the pre-skipped
     * results, and the cleaned target (without the leading "!").
     *
     * @param  list<array{path: string, version: string, ...}>  $matches
     * @return array{0: list<array<string, mixed>>, 1: list<RepoUpdateResult>, 2: ?string}
     */
    protected function applyTargetVersionFilter(array $matches, ?string $rawTarget): array
    {
        [$cleanTarget, $forceMajor] = self::parseTargetVersion($rawTarget);

        /** @var list<RepoUpdateResult> $preSkipped */
        $preSkipped = [];
        if ($cleanTarget === null) {
            return [$matches, $preSkipped, null];
        }

        $targetMajor = self::extractMajor($cleanTarget);
        $alreadyAt = 0;
        $differentMajor = 0;
        $remaining = [];

        foreach ($matches as $m) {
            if (self::versionsEqual($m['version'], $cleanTarget)) {
                $preSkipped[] = RepoUpdateResult::skipped($m['path'], "already at {$cleanTarget}");
                $alreadyAt++;
                continue;
            }

            if (! $forceMajor && $targetMajor !== null) {
                $currentMajor = self::extractMajor($m['version']);
                if ($currentMajor !== null && $currentMajor !== $targetMajor) {
                    $preSkipped[] = RepoUpdateResult::skipped(
                        $m['path'],
                        "on major {$currentMajor} ({$m['version']}); prefix target with `!` to cross majors",
                    );
                    $differentMajor++;
                    continue;
                }
            }

            $remaining[] = $m;
        }

        $msg = sprintf('%d already at %s', $alreadyAt, $cleanTarget);
        if (! $forceMajor) {
            $msg .= sprintf(', %d on different major', $differentMajor);
        }
        $msg .= sprintf(' — will skip. %d remain to update.', count($remaining));
        info($msg);

        return [$remaining, $preSkipped, $cleanTarget];
    }

    /**
     * Decide which package composer should `update`. By default this is the
     * target itself — composer will bump it within whatever range its parents
     * allow. For transitive targets, the user can override with a parent
     * package name (combined with -W) when a parent constraint blocks
     * reaching a desired version.
     *
     * @param  list<array{path: string, version: string, isDirect: bool}>  $matches
     */
    private function resolveUpdatePackage(array $matches, string $package): string
    {
        $option = $this->option('update-package');
        if (is_string($option) && $option !== '') {
            return $option;
        }

        $transitive = array_values(array_filter($matches, fn ($m) => ! $m['isDirect']));
        if (empty($transitive) || $this->option('yes')) {
            return $package;
        }

        $candidates = FindReposAction::findParentCandidates($matches, $package);

        info(sprintf(
            '%d of %d repos have %s as a transitive dependency. `composer update %s` works as long as every parent constraint allows the new version; otherwise you need to update a parent package (with -W) so composer can bump them together.',
            count($transitive),
            count($matches),
            $package,
            $package,
        ));

        if (! empty($candidates)) {
            $totalRepos = count($matches);
            $hint = "Detected parent packages (across matched repos):\n  "
                . implode("\n  ", array_map(
                    fn ($c) => sprintf('• %s (in %d/%d repos)', $c['name'], $c['repoCount'], $totalRepos),
                    array_slice($candidates, 0, 5),
                ));
            note($hint);
        }

        $value = text(
            label: 'Which package should composer update?',
            placeholder: $package,
            default: $package,
            hint: "Default `{$package}` works when parent constraints are loose. If the target ends up below your desired version, re-run with one of the parents above.",
            validate: fn (string $v) => str_contains($v, '/')
                ? null
                : 'Use vendor/package format',
        );

        return trim($value);
    }

    protected static function versionsEqual(string $a, string $b): bool
    {
        $normalize = static fn (string $v) => ltrim(trim($v), 'vV');

        return $normalize($a) === $normalize($b);
    }

    protected function resolveKeepDdevRunning(): bool
    {
        if ($this->option('stop-ddev')) {
            return false;
        }

        if ($this->option('yes')) {
            return true;
        }

        return confirm(
            label: 'Keep the ddev project running in each repo after a successful update?',
            default: true,
            hint: 'Choose "no" to run `ddev stop` after each successful update.',
        );
    }

    protected function resolveParallel(): int
    {
        $option = $this->option('parallel');
        if ($option !== null) {
            return max(1, (int) $option);
        }

        if ($this->option('yes')) {
            return 1;
        }

        $value = text(
            label: 'How many repos should be processed in parallel? (1 = sequential)',
            default: '1',
            validate: fn (string $v) => ctype_digit($v) && (int) $v >= 1
                ? null
                : 'Enter an integer >= 1',
        );

        return (int) $value;
    }

    /**
     * @param  list<string>  $repos
     * @param  callable(string $repoPath, callable $onProgress): RepoUpdateResult  $updater
     * @return list<RepoUpdateResult>
     */
    protected function runSequential(array $repos, callable $updater): array
    {
        $results = [];
        $total = count($repos);

        foreach ($repos as $i => $repo) {
            $n = $i + 1;
            $name = basename($repo);
            $this->line('');
            $this->line("<fg=cyan>━━ [{$n}/{$total}] {$name} ━━</>");
            $result = $updater($repo, $this->streamingCallback());
            $results[] = $result;
            $this->printRepoLine($result, $n, $total);
        }

        return $results;
    }

    /**
     * @param  list<string>  $repos
     * @param  callable(string $repoPath, string $php, string $binary): list<string>  $buildCmd
     * @return list<RepoUpdateResult>
     */
    protected function runParallel(array $repos, int $workers, callable $buildCmd): array
    {
        $php = (new PhpExecutableFinder())->find() ?: PHP_BINARY;
        $binary = base_path('package-updater');
        $consoleOutput = $this->getConsoleOutput();
        $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $tick = 0;

        $queue = $repos;
        /** @var list<array{process: Process, repo: string, index: int, section: ?ConsoleSectionOutput, started: float}> $running */
        $running = [];
        $results = [];
        $total = count($repos);
        $started = 0;

        while (! empty($queue) || ! empty($running)) {
            while (count($running) < $workers && ! empty($queue)) {
                $repo = array_shift($queue);
                $cmd = $buildCmd($repo, $php, $binary);
                $process = new Process($cmd);
                $process->setTimeout(3600);
                $process->start();
                $started++;

                $section = $consoleOutput?->section();
                $entry = [
                    'process' => $process,
                    'repo' => $repo,
                    'index' => $started,
                    'section' => $section,
                    'started' => microtime(true),
                ];
                $running[] = $entry;

                $this->sectionWriteln($entry['section'], $this->formatRunningLine($entry, $spinnerFrames[0], $total));
            }

            usleep(200_000);
            $tick++;

            if ($consoleOutput !== null) {
                $frame = $spinnerFrames[$tick % count($spinnerFrames)];
                foreach ($running as $entry) {
                    if ($entry['process']->isRunning() && $entry['section'] !== null) {
                        $this->sectionOverwrite($entry['section'], $this->formatRunningLine($entry, $frame, $total));
                    }
                }
            }

            foreach ($running as $key => $entry) {
                if ($entry['process']->isRunning()) {
                    continue;
                }

                $result = $this->parseChildOutput($entry['process']->getOutput(), $entry['repo']);
                $results[] = $result;
                unset($running[$key]);

                $finalLine = $this->formatRepoLine($result, $entry['index'], $total, microtime(true) - $entry['started']);
                if ($entry['section'] !== null) {
                    $this->sectionOverwrite($entry['section'], $finalLine);
                } else {
                    $this->line($finalLine);
                }
            }

            $running = array_values($running);
        }

        return $results;
    }

    /**
     * Write a freshly-formatted message into a console section. We pre-format
     * the message through the parent OutputStyle's formatter and pass
     * OUTPUT_RAW to the section, so that the rendered ANSI codes (or stripped
     * tags, when not decorated) always reach the section's doWrite — bypassing
     * any path where the section's own formatter mis-handles `<fg=…>` tags
     * and prints them literally. Falls back to $this->line() when the section
     * is null (non-TTY/parallel disabled).
     */
    protected function sectionWriteln(?ConsoleSectionOutput $section, string $message): void
    {
        if ($section === null) {
            $this->line($message);
            return;
        }
        $section->writeln($this->output->getFormatter()->format($message), OutputInterface::OUTPUT_RAW);
    }

    /**
     * Re-render an existing section line in place. Equivalent to
     * $section->overwrite(...) but pre-formats the message so the ANSI codes
     * reach doWrite intact (see sectionWriteln for the rationale).
     */
    protected function sectionOverwrite(ConsoleSectionOutput $section, string $message): void
    {
        $section->clear();
        $section->writeln($this->output->getFormatter()->format($message), OutputInterface::OUTPUT_RAW);
    }

    /** @param array{repo: string, index: int, started: float} $entry */
    protected function formatRunningLine(array $entry, string $spinnerFrame, int $total): string
    {
        $elapsed = (int) round(microtime(true) - $entry['started']);
        $name = basename($entry['repo']);

        return sprintf(
            '  <fg=cyan>%s</> [%d/%d] %s — running (%ds)',
            $spinnerFrame,
            $entry['index'],
            $total,
            $name,
            $elapsed,
        );
    }

    protected function getConsoleOutput(): ?ConsoleOutputInterface
    {
        $output = $this->output;
        if (! $output instanceof OutputStyle) {
            return null;
        }

        $inner = $output->getOutput();

        return $inner instanceof ConsoleOutputInterface ? $inner : null;
    }

    protected function ensureDdevSshAuth(): void
    {
        $process = new Process(['ddev', 'auth', 'ssh']);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (\Throwable $e) {
            warning('ddev auth ssh did not run cleanly: ' . $e->getMessage() . '. Continuing — repos needing SSH may fail. If your SSH keys have passphrases, run `ddev auth ssh` manually first then re-run with --no-ssh-auth.');
            return;
        }

        if (! $process->isSuccessful()) {
            warning('ddev auth ssh exited non-zero. Repos requiring SSH-based composer sources may fail.');
            return;
        }

        $combined = $process->getOutput() . "\n" . $process->getErrorOutput();
        $count = preg_match('/(?:Adding|Successfully added)\s+(\d+)\s+SSH private key/i', $combined, $m)
            ? (int) $m[1]
            : null;

        info($count !== null
            ? "Loaded {$count} SSH key(s) into ddev."
            : 'ddev SSH agent ready.');
    }

    protected function streamingCallback(): \Closure
    {
        return function (string $event, ?string $type, ?string $payload): void {
            if ($event === 'step-start') {
                $this->line("  <fg=blue>→</> {$payload}");
                return;
            }

            $color = $type === 'err' ? 'yellow' : 'gray';
            foreach (preg_split("/\r\n|\r|\n/", (string) $payload) as $line) {
                $line = rtrim($line);
                if ($line === '') {
                    continue;
                }
                $this->line("    <fg={$color}>{$line}</>");
            }
        };
    }

    protected function parseChildOutput(string $output, string $repo): RepoUpdateResult
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)));
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['status'], $decoded['repoPath'], $decoded['message'])) {
                return RepoUpdateResult::fromArray($decoded);
            }
        }

        return RepoUpdateResult::failed($repo, 'child process produced no parsable result');
    }

    protected function printRepoLine(RepoUpdateResult $result, int $done, int $total): void
    {
        $this->line($this->formatRepoLine($result, $done, $total));
    }

    protected function formatRepoLine(RepoUpdateResult $result, int $index, int $total, ?float $elapsedSeconds = null): string
    {
        $name = basename($result->repoPath);
        [$icon, $color] = match ($result->status) {
            'success' => ['✓', 'green'],
            'skipped' => ['↷', 'yellow'],
            'failed' => ['✗', 'red'],
        };
        $elapsed = $elapsedSeconds !== null ? sprintf(' <fg=gray>(%ds)</>', (int) round($elapsedSeconds)) : '';

        return sprintf(
            '  <fg=%s>[%d/%d] %s %s</>%s — %s',
            $color,
            $index,
            $total,
            $icon,
            $name,
            $elapsed,
            $result->message,
        );
    }

    /** @param  list<RepoUpdateResult>  $results */
    protected function printSummary(array $results, ?string $targetVersion = null): void
    {
        $success = array_values(array_filter($results, fn ($r) => $r->status === 'success'));
        $skipped = array_values(array_filter($results, fn ($r) => $r->status === 'skipped'));
        $failed = array_values(array_filter($results, fn ($r) => $r->status === 'failed'));

        $belowTargetCount = 0;
        if ($targetVersion !== null) {
            foreach ($success as $r) {
                if (self::isBelowTarget($r->installedVersion, $targetVersion)) {
                    $belowTargetCount++;
                }
            }
        }

        $this->newLine();
        info(sprintf(
            'Done: %d updated, %d skipped, %d failed.',
            count($success),
            count($skipped),
            count($failed),
        ));

        if (! empty($success)) {
            note('Successful updates' . ($targetVersion !== null ? " (target: {$targetVersion})" : '') . ':');
            table(
                ['Repo', 'Branch', 'From', 'To', 'Tests', 'Note'],
                array_map(function (RepoUpdateResult $r) use ($targetVersion) {
                    // When the run didn't track a single package (e.g. `craft
                    // update all`), the per-package versions are deliberately
                    // null — show '-' instead of '?' to avoid implying a
                    // failed lookup.
                    $singlePackageTracked = $r->previousVersion !== null || $r->installedVersion !== null;
                    $from = $r->previousVersion ?? ($singlePackageTracked ? '?' : '-');
                    $to = $r->installedVersion ?? ($singlePackageTracked ? '?' : '-');
                    $note = self::successNote($r, $targetVersion);
                    return [basename($r->repoPath), $r->branch ?? '-', $from, $to, self::testsCell($r), $note];
                }, $success),
            );

            $withUpdates = array_values(array_filter(
                $success,
                fn (RepoUpdateResult $r) => ! empty($r->packageUpdates),
            ));
            if (! empty($withUpdates)) {
                note('Package updates per repo:');
                foreach ($withUpdates as $r) {
                    $name = basename($r->repoPath);
                    $count = count($r->packageUpdates);
                    $marker = $r->committed
                        ? '<fg=green>✓ committed</>'
                        : '<fg=yellow>⚠ uncommitted</>';
                    $this->line("  <options=bold>{$name}</> — {$count} update(s) · {$marker}");
                    foreach ($r->packageUpdates as $u) {
                        $this->line(sprintf('    - %s %s => %s', $u['name'], $u['from'], $u['to']));
                    }
                }
            }
        }

        if ($belowTargetCount > 0) {
            warning(sprintf(
                '%d repo(s) ended up below target version %s — composer could not satisfy a higher version given the constraints. Investigate parent dependencies.',
                $belowTargetCount,
                $targetVersion,
            ));
        }

        $testFailures = array_values(array_filter(
            $success,
            fn (RepoUpdateResult $r) => $r->prepRan && (($r->testsFailed ?? 0) > 0 || $r->testsSummary === null && $r->prepLogPath !== null),
        ));
        if (! empty($testFailures)) {
            warning(sprintf(
                '%d repo(s) had failing tests after `composer prep`:',
                count($testFailures),
            ));
            foreach ($testFailures as $r) {
                $name = basename($r->repoPath);
                $summary = $r->testsSummary ?? 'no test summary';
                $this->line("  <fg=red;options=bold>✗ {$name}</> — {$summary}");
                if ($r->prepLogPath !== null) {
                    $this->line("    <fg=gray>log:</> {$r->prepLogPath}");
                }
                if ($r->transcriptPath !== null) {
                    $this->line("    <fg=gray>transcript:</> {$r->transcriptPath}");
                }
            }
        }

        $crawlerFailures = array_values(array_filter(
            $success,
            fn (RepoUpdateResult $r) => $r->crawlerRan && $r->crawlerFailed,
        ));
        if (! empty($crawlerFailures)) {
            warning(sprintf(
                '%d repo(s) had a site-crawler failure (update itself succeeded):',
                count($crawlerFailures),
            ));
            foreach ($crawlerFailures as $r) {
                $name = basename($r->repoPath);
                $this->line("  <fg=yellow;options=bold>! {$name}</> — site-crawler crawl:ddev failed");
                if ($r->crawlerLogPath !== null) {
                    $this->line("    <fg=gray>log:</> {$r->crawlerLogPath}");
                }
                if ($r->transcriptPath !== null) {
                    $this->line("    <fg=gray>transcript:</> {$r->transcriptPath}");
                }
            }
        }

        $crawler5xx = array_values(array_filter(
            $success,
            fn (RepoUpdateResult $r) => ! empty($r->crawlerServerErrorUrls),
        ));
        if (! empty($crawler5xx)) {
            warning(sprintf(
                '%d repo(s) returned 5xx responses during site-crawler — investigate:',
                count($crawler5xx),
            ));
            foreach ($crawler5xx as $r) {
                $name = basename($r->repoPath);
                $count = count($r->crawlerServerErrorUrls);
                $this->line("  <fg=red;options=bold>✗ {$name}</> — {$count} URL(s) returned 5xx");
                foreach ($r->crawlerServerErrorUrls as $url) {
                    $this->line("    <fg=red>{$url}</>");
                }
                if ($r->crawlerLogPath !== null) {
                    $this->line("    <fg=gray>log:</> {$r->crawlerLogPath}");
                }
                if ($r->transcriptPath !== null) {
                    $this->line("    <fg=gray>transcript:</> {$r->transcriptPath}");
                }
            }
        }

        if (! empty($skipped)) {
            note('Skipped repos:');
            table(
                ['Repo', 'Reason'],
                array_map(fn ($r) => [basename($r->repoPath), $r->message], $skipped),
            );
        }

        if (! empty($failed)) {
            warning('Failed repos:');
            foreach ($failed as $r) {
                $this->printFailureBlock($r);
            }
        }
    }

    private static function testsCell(RepoUpdateResult $r): string
    {
        if (! $r->prepRan) {
            return '-';
        }

        if ($r->testsFailed === null) {
            return '<fg=yellow>?</>';
        }

        if ($r->testsFailed > 0) {
            return "<fg=red;options=bold>✗ {$r->testsFailed} failed</>";
        }

        return '<fg=green>✓ pass</>';
    }

    private static function successNote(RepoUpdateResult $r, ?string $targetVersion): string
    {
        $parts = [];

        if ($targetVersion !== null && self::isBelowTarget($r->installedVersion, $targetVersion)) {
            $parts[] = "<fg=red;options=bold>! below target ({$targetVersion})</>";
        } elseif ($targetVersion !== null && self::versionsEqual((string) $r->installedVersion, $targetVersion)) {
            $parts[] = '<fg=green>✓ at target</>';
        }

        if ($r->previousVersion !== null && $r->installedVersion !== null
            && self::versionsEqual($r->previousVersion, $r->installedVersion)) {
            $parts[] = 'unchanged';
        }

        if ($r->committed) {
            $count = count($r->packageUpdates);
            $parts[] = $count > 0
                ? sprintf('committed %d update(s)', $count)
                : 'committed (no parsed updates)';
        } elseif ($r->hasUncommittedChanges) {
            $parts[] = 'uncommitted changes';
        }

        return implode(' · ', $parts) ?: '-';
    }

    private function printFailureBlock(RepoUpdateResult $r): void
    {
        $name = basename($r->repoPath);
        $branch = $r->branch ?? '-';
        [$step, $detail, $hint] = self::splitFailureMessage($r->message);

        $maxLen = 240;
        if (mb_strlen($detail) > $maxLen) {
            $detail = mb_substr($detail, 0, $maxLen) . '… (full output in log)';
        }

        $this->newLine();
        $this->line("  <fg=red;options=bold>✗ {$name}</> <fg=gray>({$branch})</>");
        if ($step !== '') {
            $this->line("    <fg=gray>step:</>  {$step}");
        }
        $this->line("    <fg=gray>error:</> {$detail}");
        if ($hint !== '') {
            $this->line("    <fg=yellow>hint:</>  {$hint}");
        }
        if ($r->logPath !== null) {
            $this->line("    <fg=gray>log:</>   {$r->logPath}");
        }
        if ($r->transcriptPath !== null) {
            $this->line("    <fg=gray>transcript:</> {$r->transcriptPath}");
        }
    }

    /**
     * The failure message is built as: "<step> failed: <detail> [— hint: <hint>] [(log: <path>)]".
     * Split it back into ($step, $detail, $hint) for nicer rendering.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function splitFailureMessage(string $message): array
    {
        $step = '';
        $detail = $message;
        $hint = '';

        if (preg_match('/^(.*?) failed: (.*)$/s', $message, $m)) {
            $step = trim($m[1]);
            $detail = trim($m[2]);
        }

        if (preg_match('/^(.*?) — hint: (.*)$/s', $detail, $m)) {
            $detail = trim($m[1]);
            $hint = trim($m[2]);
        }

        if (preg_match('/^(.*?) \(log: [^)]+\)$/s', $detail, $m)) {
            $detail = trim($m[1]);
        }
        if ($hint !== '' && preg_match('/^(.*?) \(log: [^)]+\)$/s', $hint, $m)) {
            $hint = trim($m[1]);
        }

        return [$step, $detail, $hint];
    }

    private static function isBelowTarget(?string $installed, string $target): bool
    {
        if ($installed === null) {
            return false;
        }

        $normalize = static fn (string $v) => ltrim(trim($v), 'vV');

        return version_compare($normalize($installed), $normalize($target), '<');
    }
}
