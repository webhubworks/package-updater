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

class RemoveCommand extends Command
{
    use ResolvesReposDir;

    protected $signature = 'remove
        {package? : Composer package name (vendor/name) — wildcards allowed, e.g. laravel-lang/*}
        {--reps-dir= : Directory containing repos (default: ~/reps)}
        {--parallel= : Number of repos to update concurrently (default: prompt; 1 = sequential)}
        {--dry-run : List matching repos with the packages that would be removed and exit}
        {--repo=* : Process only the specified repo path(s); can be passed multiple times. Skips the interactive repo selection.}
        {--filter-name= : Keep only repos whose composer.json "name" contains this substring}
        {--no-ssh-auth : Skip the initial `ddev auth ssh` step}
        {--stop-ddev : Stop the ddev project in each repo after a successful remove (default: keep running)}
        {--open : After the run, open every repo with uncommitted changes in GitKraken (skips the prompt)}
        {--no-open : Skip the end-of-run "open in GitKraken" prompt entirely}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Remove a Composer package from every local repo that directly requires it';

    public function handle(): int
    {
        $reposDir = $this->resolveReposDir();
        if ($reposDir === null) {
            return self::FAILURE;
        }

        if (! is_dir($reposDir)) {
            $this->error("Repos directory not found: {$reposDir}");
            return self::FAILURE;
        }

        $package = $this->argument('package') ?: text(
            label: 'Which Composer package should be removed?',
            placeholder: 'vendor/foo or laravel-lang/*',
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

        // Build per-repo remove plan and pre-skip transitive-only repos.
        // `composer remove` only operates on direct deps.
        [$plans, $preSkipped] = $this->buildRemovePlans($matches, $package, $isPattern);

        info(sprintf(
            'Found %d repositor%s%s.',
            count($matches),
            count($matches) === 1 ? 'y' : 'ies',
            count($matches) !== $totalFound ? sprintf(' (filtered from %d by name)', $totalFound) : '',
        ));

        if (! empty($preSkipped)) {
            info(sprintf(
                '%d of %d repos use %s only transitively — skipping. %d will be processed.',
                count($preSkipped),
                count($matches),
                $package,
                count($plans),
            ));
        }

        if ($this->option('dry-run')) {
            $rows = [];
            foreach ($plans as $plan) {
                foreach ($plan['spec'] as $entry) {
                    $rows[] = [
                        basename($plan['path']),
                        $entry['name'],
                        $entry['dev'] ? 'require-dev' : 'require',
                    ];
                }
            }
            table(['Repo', 'Package', 'Section'], $rows);
            note('Dry run — no changes were made.');
            return self::SUCCESS;
        }

        if (empty($plans)) {
            $this->printSummary($preSkipped);
            return self::SUCCESS;
        }

        $plans = $this->resolvePlanSelection($plans);
        if (empty($plans)) {
            info('No repos selected — exiting.');
            return self::SUCCESS;
        }

        $parallel = $this->resolveParallel();
        $keepDdevRunning = $this->resolveKeepDdevRunning();

        $mode = $parallel === 1 ? 'sequentially' : "with {$parallel} workers in parallel";

        if (! $this->option('yes') && ! confirm("Run `composer remove {$package}` in " . count($plans) . " repos {$mode}?", default: true)) {
            return self::SUCCESS;
        }

        LastRunStore::save('remove', ['package' => $package], [
            'reps-dir' => $reposDir,
            'parallel' => (string) $parallel,
            'filter-name' => $this->option('filter-name') ?: null,
            'repo' => array_map(fn ($p) => $p['path'], $plans),
            'stop-ddev' => ! $keepDdevRunning,
            'no-ssh-auth' => (bool) $this->option('no-ssh-auth'),
            'yes' => true,
        ]);

        if (! $this->option('no-ssh-auth')) {
            $this->ensureDdevSshAuth();
        }

        $updater = fn (array $plan, callable $onProgress) => UpdateRepoAction::update(
            $plan['path'],
            $plan['spec'][0]['name'],
            $onProgress,
            false,
            null,
            $keepDdevRunning,
            null,
            null,
            true,
            $plan['spec'],
        );

        $buildCmd = function (array $plan, string $php, string $binary) use ($keepDdevRunning): array {
            $cmd = [$php, $binary, 'remove:single', $plan['path']];
            foreach ($plan['spec'] as $entry) {
                $cmd[] = '--package=' . $entry['name'] . '=' . ($entry['dev'] ? '1' : '0');
            }
            if (! $keepDdevRunning) {
                $cmd[] = '--stop-ddev';
            }
            return $cmd;
        };

        $results = $parallel === 1
            ? $this->runSequential($plans, $updater)
            : $this->runParallel($plans, $parallel, $buildCmd);

        $allResults = array_merge($preSkipped, $results);
        $this->printSummary($allResults);
        LastRunStore::saveResults('remove', array_map(fn ($r) => $r->toArray(), $allResults));
        $this->offerOpenPrompt($allResults);

        return self::SUCCESS;
    }

    /**
     * Per repo, narrow the matched packages to direct deps and tag each with
     * its require section. Repos with only transitive matches are pre-skipped
     * (composer remove only works on declared deps).
     *
     * @param  list<array{path: string, version: string, isDirect: bool, matchedPackages?: list<array{name: string, version: string, isDirect: bool}>}>  $matches
     * @return array{0: list<array{path: string, spec: list<array{name: string, dev: bool}>}>, 1: list<RepoUpdateResult>}
     */
    protected function buildRemovePlans(array $matches, string $package, bool $isPattern): array
    {
        $plans = [];
        $preSkipped = [];

        foreach ($matches as $m) {
            $candidates = $isPattern
                ? array_values(array_filter(
                    $m['matchedPackages'] ?? [],
                    fn ($pkg) => $pkg['isDirect'],
                ))
                : ($m['isDirect'] ? [['name' => $package, 'version' => $m['version'], 'isDirect' => true]] : []);

            if (empty($candidates)) {
                $preSkipped[] = RepoUpdateResult::skipped(
                    $m['path'],
                    $isPattern
                        ? "no matching package is a direct dependency"
                        : "{$package} is only a transitive dependency — composer remove not applicable",
                );
                continue;
            }

            $spec = [];
            foreach ($candidates as $pkg) {
                $type = FindReposAction::requireType($m['path'], $pkg['name']);
                // requireType is guaranteed non-null here because isDirect was true.
                $spec[] = ['name' => $pkg['name'], 'dev' => $type === 'require-dev'];
            }

            $plans[] = ['path' => $m['path'], 'spec' => $spec];
        }

        return [$plans, $preSkipped];
    }

    /**
     * @param  list<array{path: string, spec: list<array{name: string, dev: bool}>}>  $plans
     * @return list<array{path: string, spec: list<array{name: string, dev: bool}>}>
     */
    protected function resolvePlanSelection(array $plans): array
    {
        $cliRepos = array_values(array_filter(
            array_map('strval', (array) $this->option('repo')),
            fn ($p) => $p !== '',
        ));

        if (! empty($cliRepos)) {
            $set = array_flip($cliRepos);
            return array_values(array_filter($plans, fn ($p) => isset($set[$p['path']])));
        }

        if ($this->option('yes')) {
            return $plans;
        }

        $options = [];
        foreach ($plans as $p) {
            $names = array_map(fn ($e) => $e['name'] . ($e['dev'] ? ' [dev]' : ''), $p['spec']);
            $options[$p['path']] = basename($p['path']) . ' (' . implode(', ', $names) . ')';
        }

        $selected = multiselect(
            label: 'Which repos should be processed?',
            options: $options,
            default: [],
            hint: 'Space to toggle · Ctrl+A to select/deselect all · Enter to confirm',
            required: false,
        );

        $set = array_flip(array_map('strval', (array) $selected));

        return array_values(array_filter($plans, fn ($p) => isset($set[$p['path']])));
    }

    /**
     * @param  list<array{path: string}>  $matches
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

    protected function resolveKeepDdevRunning(): bool
    {
        if ($this->option('stop-ddev')) {
            return false;
        }

        if ($this->option('yes')) {
            return true;
        }

        return confirm(
            label: 'Keep the ddev project running in each repo after a successful remove?',
            default: true,
            hint: 'Choose "no" to run `ddev stop` after each successful remove.',
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
     * @param  list<array{path: string, spec: list<array{name: string, dev: bool}>}>  $plans
     * @param  callable(array, callable): RepoUpdateResult  $updater
     * @return list<RepoUpdateResult>
     */
    protected function runSequential(array $plans, callable $updater): array
    {
        $results = [];
        $total = count($plans);

        foreach ($plans as $i => $plan) {
            $n = $i + 1;
            $name = basename($plan['path']);
            $this->line('');
            $this->line("<fg=cyan>━━ [{$n}/{$total}] {$name} ━━</>");
            $result = $updater($plan, $this->streamingCallback());
            $results[] = $result;
            $this->line($this->formatRepoLine($result, $n, $total));
        }

        return $results;
    }

    /**
     * @param  list<array{path: string, spec: list<array{name: string, dev: bool}>}>  $plans
     * @param  callable(array, string, string): list<string>  $buildCmd
     * @return list<RepoUpdateResult>
     */
    protected function runParallel(array $plans, int $workers, callable $buildCmd): array
    {
        $php = (new PhpExecutableFinder())->find() ?: PHP_BINARY;
        $binary = base_path('package-updater');
        $consoleOutput = $this->getConsoleOutput();
        $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $tick = 0;

        $queue = $plans;
        /** @var list<array{process: Process, repo: string, index: int, section: ?ConsoleSectionOutput, started: float}> $running */
        $running = [];
        $results = [];
        $total = count($plans);
        $started = 0;

        while (! empty($queue) || ! empty($running)) {
            while (count($running) < $workers && ! empty($queue)) {
                $plan = array_shift($queue);
                $cmd = $buildCmd($plan, $php, $binary);
                $process = new Process($cmd);
                $process->setTimeout(3600);
                $process->start();
                $started++;

                $section = $consoleOutput?->section();
                $entry = [
                    'process' => $process,
                    'repo' => $plan['path'],
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

    protected function sectionWriteln(?ConsoleSectionOutput $section, string $message): void
    {
        if ($section === null) {
            $this->line($message);
            return;
        }
        $section->writeln($this->output->getFormatter()->format($message), OutputInterface::OUTPUT_RAW);
    }

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
            warning('ddev auth ssh did not run cleanly: ' . $e->getMessage() . '. Continuing — repos needing SSH may fail.');
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

    /** @param list<RepoUpdateResult> $results */
    protected function printSummary(array $results): void
    {
        $success = array_values(array_filter($results, fn ($r) => $r->status === 'success'));
        $skipped = array_values(array_filter($results, fn ($r) => $r->status === 'skipped'));
        $failed = array_values(array_filter($results, fn ($r) => $r->status === 'failed'));

        $this->newLine();
        info(sprintf(
            'Done: %d removed, %d skipped, %d failed.',
            count($success),
            count($skipped),
            count($failed),
        ));

        if (! empty($success)) {
            note('Successful removes:');
            table(
                ['Repo', 'Branch', 'Tests', 'Note'],
                array_map(function (RepoUpdateResult $r) {
                    $note = $r->committed
                        ? '<fg=green>✓ committed</>'
                        : ($r->hasUncommittedChanges ? 'uncommitted changes' : '-');
                    return [basename($r->repoPath), $r->branch ?? '-', self::testsCell($r), $note];
                }, $success),
            );
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
                $name = basename($r->repoPath);
                $branch = $r->branch ?? '-';
                $this->newLine();
                $this->line("  <fg=red;options=bold>✗ {$name}</> <fg=gray>({$branch})</>");
                $this->line("    <fg=gray>error:</> {$r->message}");
                if ($r->logPath !== null) {
                    $this->line("    <fg=gray>log:</>   {$r->logPath}");
                }
                if ($r->transcriptPath !== null) {
                    $this->line("    <fg=gray>transcript:</> {$r->transcriptPath}");
                }
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
}
