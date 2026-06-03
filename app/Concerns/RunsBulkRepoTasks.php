<?php

namespace App\Concerns;

use App\Actions\OpenInGitKrakenAction;
use App\Commands\OpenCommand;
use App\DataTransferObjects\RepoUpdateResult;
use Closure;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
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
 *   - sequential and parallel runners (with a live spinner block per worker)
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
        $decorated = $consoleOutput !== null && $consoleOutput->isDecorated();
        $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $tick = 0;

        $queue = $items;
        /** @var list<array{process: Process, repo: string, index: int, started: float, buffer: string, lines: list<array{kind: string, text: string}>}> $running */
        $running = [];
        $results = [];
        $total = count($items);
        $started = 0;

        // Manual line accounting for the bottom "live" block. We bypass
        // Symfony's ConsoleSectionOutput on purpose: every section overwrite
        // does `cursor-up + erase-to-end-of-screen + rewrite`, which leaves
        // the live region visibly blank between the erase and the rewrite —
        // that gap is what looks like flicker once the block is ~18 lines
        // tall (3 workers × 6 rows). Instead we overwrite each line in place
        // with `\x1b[K` so the terminal never sees an empty frame.
        $liveLineCount = 0;

        if ($decorated) {
            // Hide the cursor for the whole run so the per-tick redraw
            // doesn't show it skipping across the screen.
            $consoleOutput->write("\x1b[?25l", false, OutputInterface::OUTPUT_RAW);
        }

        try {
            while (! empty($queue) || ! empty($running)) {
                while (count($running) < $workers && ! empty($queue)) {
                    $item = array_shift($queue);
                    $repo = $pathOf($item);
                    $cmd = $buildCmd($item, $php, $binary);
                    $process = new Process($cmd);
                    $process->setTimeout(3600);
                    $process->start();
                    $started++;

                    $running[] = [
                        'process' => $process,
                        'repo' => $repo,
                        'index' => $started,
                        'started' => microtime(true),
                        'buffer' => '',
                        'lines' => [],
                    ];
                }

                usleep(200_000);
                $tick++;

                foreach ($running as $key => $entry) {
                    $chunk = $entry['process']->getIncrementalOutput();
                    if ($chunk === '') {
                        continue;
                    }
                    $running[$key]['buffer'] .= $chunk;
                    while (($pos = strpos($running[$key]['buffer'], "\n")) !== false) {
                        $line = substr($running[$key]['buffer'], 0, $pos);
                        $running[$key]['buffer'] = substr($running[$key]['buffer'], $pos + 1);
                        foreach ($this->extractProgressLines($line) as $rendered) {
                            $running[$key]['lines'][] = $rendered;
                        }
                        if (count($running[$key]['lines']) > 5) {
                            $running[$key]['lines'] = array_slice($running[$key]['lines'], -5);
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
                    $this->writeHistoryLine($consoleOutput, $decorated, $liveLineCount, $finalLine);
                }

                $running = array_values($running);

                if ($decorated) {
                    if (! empty($running)) {
                        $frame = $spinnerFrames[$tick % count($spinnerFrames)];
                        $blocks = array_map(
                            fn (array $entry) => $this->formatRunningSection($entry, $frame, $total),
                            $running,
                        );
                        $this->refreshLiveBlock($consoleOutput, $liveLineCount, implode("\n", $blocks));
                    } else {
                        $this->clearLiveBlock($consoleOutput, $liveLineCount);
                    }
                }
            }
        } finally {
            if ($decorated && $consoleOutput !== null) {
                $consoleOutput->write("\x1b[?25h", false, OutputInterface::OUTPUT_RAW);
            }
        }

        return $results;
    }

    /**
     * Emit a completed worker's final row above the live block. When the
     * live block is non-empty, it's erased first (history lines should
     * appear above, then the live block gets re-drawn fresh by the next
     * tick). When the output isn't a TTY this falls back to a plain line.
     */
    protected function writeHistoryLine(?ConsoleOutputInterface $consoleOutput, bool $decorated, int &$liveLineCount, string $line): void
    {
        if (! $decorated || $consoleOutput === null) {
            $this->line($line);
            return;
        }

        $formatted = $this->output->getFormatter()->format($line);
        $buffer = '';
        if ($liveLineCount > 0) {
            $buffer .= sprintf("\x1b[%dA\r\x1b[0J", $liveLineCount);
            $liveLineCount = 0;
        }
        $buffer .= $formatted . "\n";
        $consoleOutput->write($buffer, false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Repaint the live block in place. Bypasses Symfony's section logic
     * because that path does erase-then-redraw, which is visibly blank for
     * one terminal frame and reads as flicker once the block is tall. We
     * overwrite each existing line by writing the new content followed by
     * `\x1b[K` (clear-to-end-of-line) so the terminal never sees an empty
     * region. Trailing stale lines from a previously taller block are
     * removed with `\x1b[0J` at the end. The whole update is sent as a
     * single write so cursor moves don't get split across frames.
     */
    protected function refreshLiveBlock(ConsoleOutputInterface $consoleOutput, int &$liveLineCount, string $content): void
    {
        $formatted = $this->output->getFormatter()->format($content);
        $lines = explode("\n", $formatted);
        $newCount = count($lines);

        $buffer = '';
        if ($liveLineCount > 0) {
            $buffer .= sprintf("\x1b[%dA\r", $liveLineCount);
        }
        foreach ($lines as $line) {
            $buffer .= $line . "\x1b[K\n";
        }
        if ($liveLineCount > $newCount) {
            $buffer .= "\x1b[0J";
        }
        $consoleOutput->write($buffer, false, OutputInterface::OUTPUT_RAW);
        $liveLineCount = $newCount;
    }

    protected function clearLiveBlock(ConsoleOutputInterface $consoleOutput, int &$liveLineCount): void
    {
        if ($liveLineCount === 0) {
            return;
        }
        $consoleOutput->write(
            sprintf("\x1b[%dA\r\x1b[0J", $liveLineCount),
            false,
            OutputInterface::OUTPUT_RAW,
        );
        $liveLineCount = 0;
    }

    /**
     * Render the multi-line block shown in a worker's section: the spinner
     * header row plus the last (up to 5) output lines from the child process,
     * indented under the header. When no progress has streamed yet, only the
     * header is rendered.
     *
     * @param array{repo: string, index: int, started: float, lines: list<array{kind: string, text: string}>} $entry
     */
    protected function formatRunningSection(array $entry, string $spinnerFrame, int $total): string
    {
        $header = $this->formatRunningLine($entry, $spinnerFrame, $total);
        if (empty($entry['lines'])) {
            return $header;
        }

        $indent = '      ';
        $maxWidth = max(40, (new Terminal())->getWidth() - mb_strlen($indent) - 4);
        $body = implode("\n", array_map(function (array $l) use ($indent, $maxWidth) {
            $text = self::truncateForDisplay($l['text'], $maxWidth);
            $escaped = OutputFormatter::escape($text);
            return match ($l['kind']) {
                'step' => $indent . '<fg=blue>→</> ' . $escaped,
                'err' => $indent . '<fg=yellow>' . $escaped . '</>',
                default => $indent . '<fg=gray>' . $escaped . '</>',
            };
        }, $entry['lines']));

        return $header . "\n" . $body;
    }

    /**
     * Parse a single JSON line emitted by a child process via
     * childProgressEmitter() into one or more {kind, text} entries suitable
     * for the running-section's "last 5 rows" buffer. Lines that aren't
     * progress events (e.g. the final result JSON) return an empty list.
     * ANSI escapes are stripped so we can apply our own colouring at render
     * time; the raw text is stored so truncation respects character bounds
     * rather than cutting through markup tags.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function extractProgressLines(string $rawLine): array
    {
        $rawLine = trim($rawLine);
        if ($rawLine === '') {
            return [];
        }

        $decoded = json_decode($rawLine, true);
        if (! is_array($decoded) || ($decoded['pu_event'] ?? null) === null) {
            return [];
        }

        $event = (string) $decoded['pu_event'];
        $type = isset($decoded['pu_type']) ? (string) $decoded['pu_type'] : null;
        $payload = (string) ($decoded['pu_payload'] ?? '');

        if ($event === 'step-start') {
            $label = trim(self::stripAnsi($payload));
            if ($label === '') {
                return [];
            }
            return [['kind' => 'step', 'text' => $label]];
        }

        $kind = $type === 'err' ? 'err' : 'out';
        $out = [];
        foreach (preg_split("/\r\n|\r|\n/", $payload) as $line) {
            $line = rtrim(self::stripAnsi($line));
            if ($line === '') {
                continue;
            }
            $out[] = ['kind' => $kind, 'text' => $line];
        }

        return $out;
    }

    protected static function stripAnsi(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;
    }

    protected static function truncateForDisplay(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 1 || mb_strlen($text) <= $maxWidth) {
            return $text;
        }
        return mb_substr($text, 0, $maxWidth - 1) . '…';
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
     * Decide whether to push the resulting commit in each repo. Asked right
     * after resolveCommit() and only relevant when committing is on — a push
     * needs a commit to push. The push itself is gated inside UpdateRepoAction
     * on an error-free run (no failing tests/PHPStan/crawler errors).
     * Precedence:
     *   1. $commit is false  (returns false — nothing to push)
     *   2. --no-push         (returns false)
     *   3. --push            (returns true)
     *   4. --yes             (defaults to true)
     *   5. confirm()         (default: yes)
     */
    protected function resolvePush(bool $commit): bool
    {
        if (! $commit) {
            return false;
        }
        if ($this->option('no-push')) {
            return false;
        }
        if ($this->option('push')) {
            return true;
        }
        if ($this->option('yes')) {
            return true;
        }

        return confirm(
            label: 'Push the commit if no error occur?',
            default: true,
            hint: 'Runs `git push origin <branch>` after committing — skipped for any repo whose run had failing tests, PHPStan errors, or a site-crawler failure.',
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
