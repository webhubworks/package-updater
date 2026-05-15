<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class UpdateCommand extends Command
{
    protected $signature = 'update
        {package?* : Composer package name(s) to update; blank = full update}
        {--no-ddev : Skip ddev detection and run composer on the host}
        {--commit : Always commit the resulting changes (skip prompt)}
        {--no-commit : Never commit (skip prompt)}
        {--yes : Skip the run confirmation (defaults to committing if neither --commit nor --no-commit is set)}';

    protected $description = 'Run composer update in the current repo, parse the package changes, and commit them';

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
            $this->error('Working tree is dirty — commit or stash before running update.');
            return self::FAILURE;
        }

        $useDdev = ! $this->option('no-ddev') && is_file($cwd.'/.ddev/config.yaml');
        $packages = array_values(array_filter(
            array_map('strval', (array) $this->argument('package')),
            fn ($p) => $p !== '',
        ));

        $cmd = $useDdev ? ['ddev', 'composer', 'update'] : ['composer', 'update'];
        $cmd = array_merge($cmd, $packages);
        $label = implode(' ', $cmd);

        if (! $this->option('yes') && ! confirm("Run `{$label}` in {$cwd}?", default: true)) {
            return self::SUCCESS;
        }

        info("Running: {$label}");
        $update = $this->exec($cmd, $cwd, stream: true);

        if (! $update->isSuccessful()) {
            $this->error('composer update failed (exit '.$update->getExitCode().')');
            return self::FAILURE;
        }

        $combined = $update->getOutput()."\n".$update->getErrorOutput();
        $updates = self::parseComposerUpdates($combined);

        $this->newLine();
        if (empty($updates)) {
            info('No package changes detected in composer output.');
        } else {
            info(sprintf('%d package change(s):', count($updates)));
            foreach ($updates as $u) {
                $this->line('  '.self::formatUpdateLine($u));
            }
        }

        if (! $this->shouldCommit()) {
            return self::SUCCESS;
        }

        return $this->commit($cwd, $updates) ? self::SUCCESS : self::FAILURE;
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

    /** @param  list<array{kind: string, name: string, from: ?string, to: ?string}>  $updates */
    private function commit(string $cwd, array $updates): bool
    {
        $status = $this->exec(['git', 'status', '--porcelain'], $cwd, stream: false);
        if (! $status->isSuccessful() || trim($status->getOutput()) === '') {
            info('No working-tree changes after composer update — nothing to commit.');
            return true;
        }

        $add = $this->exec(['git', 'add', '-A'], $cwd, stream: true);
        if (! $add->isSuccessful()) {
            warning('git add -A failed.');
            return false;
        }

        $body = empty($updates)
            ? '(no update list parsed from composer output)'
            : implode("\n", array_map(self::formatUpdateLine(...), $updates));

        $commit = $this->exec(
            ['git', 'commit', '-m', 'Package updates', '-m', $body],
            $cwd,
            stream: true,
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

    /**
     * Parses composer's "Package operations" / "Lock file operations" lines:
     *
     *   - Upgrading vendor/foo (1.0.0 => 1.0.1)
     *   - Downgrading vendor/foo (1.0.1 => 1.0.0)
     *   - Installing vendor/foo (1.0.0)
     *   - Removing vendor/foo (1.0.0)
     *
     * Composer prints these twice (once for lock-file ops, once for package
     * ops). De-dupe by name; an upgrade always wins over a later install line
     * for the same package.
     *
     * @return list<array{kind: string, name: string, from: ?string, to: ?string}>
     */
    public static function parseComposerUpdates(string $output): array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $lines = preg_split("/\r\n|\r|\n/", $stripped) ?: [];

        $byName = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
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

    /** @param  list<string>  $cmd */
    private function exec(array $cmd, string $cwd, bool $stream): Process
    {
        $process = new Process($cmd, $cwd);
        $process->setTimeout(3600);

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
