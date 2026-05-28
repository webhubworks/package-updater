<?php

namespace App\Concerns;

use App\Actions\OpenInGitKrakenAction;
use App\Commands\OpenCommand;
use App\DataTransferObjects\RepoUpdateResult;
use Closure;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Shared machinery for commands that fan out a per-repo operation across many
 * local repos (e.g. `update:all`, `update:craft`, `remove`).
 *
 * Provides:
 *   - sequential and parallel runners (with a spinner section per worker)
 *   - streaming-output formatter for child progress events
 *   - JSON-line parser for child-process results
 *   - common option prompts (parallel, keep-ddev, name-filter, open-prompt)
 *   - `ddev auth ssh` warm-up
 *
 * The runners are agnostic about the "item" they iterate — each call site
 * supplies a $pathOf closure so the trait can derive the repo path for
 * display, while the original item is passed back into $updater / $buildCmd.
 */
trait RunsBulkRepoTasks
{
    /**
     * @template TItem
     * @param  list<TItem>  $items
     * @param  Closure(TItem): string  $pathOf
     * @param  Closure(TItem, Closure): RepoUpdateResult  $updater
     * @return list<RepoUpdateResult>
     */
    protected function runSequential(array $items, Closure $pathOf, Closure $updater): array
    {
        $results = [];
        $total = count($items);

        foreach ($items as $i => $item) {
            $n = $i + 1;
            $path = $pathOf($item);
            $name = basename($path);
            $this->line('');
            $this->line("<fg=cyan>━━ [{$n}/{$total}] {$name} ━━</>");
            $result = $updater($item, $this->streamingCallback());
            $results[] = $result;
            $this->line($this->formatRepoLine($result, $n, $total));
        }

        return $results;
    }

    /**
     * @template TItem
     * @param  list<TItem>  $items
     * @param  Closure(TItem): string  $pathOf
     * @param  Closure(TItem, string, string): list<string>  $buildCmd
     * @return list<RepoUpdateResult>
     */
    protected function runParallel(array $items, int $workers, Closure $pathOf, Closure $buildCmd): array
    {
        $php = (new PhpExecutableFinder())->find() ?: PHP_BINARY;
        $binary = base_path('package-updater');
        $consoleOutput = $this->getConsoleOutput();
        $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $tick = 0;

        $queue = $items;
        /** @var list<array{process: Process, repo: string, index: int, section: ?ConsoleSectionOutput, started: float}> $running */
        $running = [];
        $results = [];
        $total = count($items);
        $started = 0;

        while (! empty($queue) || ! empty($running)) {
            while (count($running) < $workers && ! empty($queue)) {
                $item = array_shift($queue);
                $repo = $pathOf($item);
                $cmd = $buildCmd($item, $php, $binary);
                $process = new Process($cmd);
                $process->setTimeout(3600);
                $process->start();
                $started++;

                $entry = [
                    'process' => $process,
                    'repo' => $repo,
                    'index' => $started,
                    'section' => $consoleOutput?->section(),
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
     * Write a freshly-formatted message into a console section. Pre-formats
     * through the parent OutputStyle's formatter and passes OUTPUT_RAW to the
     * section so ANSI codes always reach the underlying doWrite, bypassing any
     * path where the section's own formatter mis-handles `<fg=...>` tags and
     * prints them literally. Falls back to $this->line() when no section
     * (non-TTY / parallel disabled).
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

    protected function streamingCallback(): Closure
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

    protected function resolveKeepDdevRunning(?string $verb = null): bool
    {
        if ($this->option('stop-ddev')) {
            return false;
        }

        if ($this->option('yes')) {
            return true;
        }

        $verb ??= 'update';

        return confirm(
            label: "Keep the ddev project running in each repo after a successful {$verb}?",
            default: true,
            hint: "Choose \"no\" to run `ddev stop` after each successful {$verb}.",
        );
    }

    /**
     * Decide whether to auto-commit the resulting changes in each repo with
     * title "Package updates" and a body listing the parsed updates. Asked
     * once up-front (like keep-ddev). Precedence:
     *   1. --no-commit  (returns false)
     *   2. --commit     (returns true)
     *   3. --yes        (defaults to true)
     *   4. confirm()    (default: yes)
     */
    protected function resolveCommit(): bool
    {
        if ($this->option('no-commit')) {
            return false;
        }
        if ($this->option('commit')) {
            return true;
        }
        if ($this->option('yes')) {
            return true;
        }

        return confirm(
            label: 'Commit the package updates in each repo? (title: "Package updates", body: parsed update list)',
            default: true,
            hint: 'Runs `git add -A` and `git commit` after a successful update — only fires when at least one package was actually bumped.',
        );
    }

    /**
     * Right before the run starts, scan every selected repo for uncommitted
     * changes and surface the list in one block so the user has a chance to
     * commit/stash before any work begins. Without this gate, dirty repos
     * silently get marked "skipped" by the per-repo `git status` guard in
     * UpdateRepoAction — easy to miss when fanning out across many repos.
     *
     * Returns true to proceed, false when the user wants to abort. In --yes
     * mode the list is still printed but the prompt is auto-confirmed.
     *
     * @param  list<string>  $repos  absolute repo paths
     */
    protected function confirmDirtyRepos(array $repos): bool
    {
        if (empty($repos)) {
            return true;
        }

        info(sprintf('Scanning %d repo(s) for uncommitted changes...', count($repos)));

        /** @var list<array{repo: string, count: int}> $dirty */
        $dirty = [];
        foreach ($repos as $repo) {
            $process = new Process(['git', 'status', '--porcelain'], $repo);
            $process->setTimeout(60);
            try {
                $process->run();
            } catch (\Throwable) {
                continue;
            }
            if (! $process->isSuccessful()) {
                continue;
            }
            $output = trim($process->getOutput());
            if ($output === '') {
                continue;
            }
            $lines = array_filter(preg_split('/\r\n|\r|\n/', $output) ?: []);
            $dirty[] = ['repo' => $repo, 'count' => count($lines)];
        }

        if (empty($dirty)) {
            return true;
        }

        warning(sprintf(
            '%d of %d repo(s) have uncommitted changes — they will be skipped unless cleaned up before the run:',
            count($dirty),
            count($repos),
        ));
        foreach ($dirty as $entry) {
            $name = basename($entry['repo']);
            $files = $entry['count'] === 1 ? 'file' : 'files';
            $this->line(sprintf(
                '  <fg=yellow>!</> %s <fg=gray>(%d %s changed - %s)</>',
                $name,
                $entry['count'],
                $files,
                $entry['repo'],
            ));
        }

        if ($this->option('yes')) {
            return true;
        }

        return confirm(
            label: 'Continue once you have committed/stashed those changes?',
            default: true,
            hint: 'Repos that are still dirty when the run reaches them get skipped as "uncommitted changes".',
        );
    }

    /**
     * Filter matches by substring match against the repo's composer.json "name"
     * field. No-op when --filter-name is not set.
     *
     * @param  list<array{path: string, ...}>  $matches
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

    /**
     * Offer to open every non-skipped repo (success or failure) in GitKraken
     * (one tab per repo via the gitkraken:// URL scheme). Skipped if --no-open
     * is set, or if --yes is set without an explicit --open.
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
}
