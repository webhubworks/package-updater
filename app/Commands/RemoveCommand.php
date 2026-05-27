<?php

namespace App\Commands;

use App\Actions\FindReposAction;
use App\Actions\LastRunStore;
use App\Actions\UpdateRepoAction;
use App\Concerns\ResolvesReposDir;
use App\Concerns\RunsBulkRepoTasks;
use App\DataTransferObjects\RepoUpdateResult;
use LaravelZero\Framework\Commands\Command;

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
    use RunsBulkRepoTasks;

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
        $keepDdevRunning = $this->resolveKeepDdevRunning('remove');

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
            repoPath: $plan['path'],
            package: $plan['spec'][0]['name'],
            onProgress: $onProgress,
            keepDdevRunning: $keepDdevRunning,
            commit: true,
            removeSpec: $plan['spec'],
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

        $pathOf = fn (array $plan): string => $plan['path'];
        $results = $parallel === 1
            ? $this->runSequential($plans, $pathOf, $updater)
            : $this->runParallel($plans, $parallel, $pathOf, $buildCmd);

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
                        ? 'no matching package is a direct dependency'
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
     * Filter $plans down to the repos the user wants to process. Precedence:
     *   1. --repo=...  (any number; skips the prompt)
     *   2. --yes        (selects all $plans non-interactively)
     *   3. multiselect  (default: nothing selected; Ctrl+A toggles all)
     *
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
                array_map(fn (RepoUpdateResult $r) => [
                    basename($r->repoPath),
                    $r->branch ?? '-',
                    self::testsCell($r),
                    self::successNote($r),
                ], $success),
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

    private static function successNote(RepoUpdateResult $r): string
    {
        if ($r->committed) {
            return '<fg=green>✓ committed</>';
        }

        return $r->hasUncommittedChanges ? 'uncommitted changes' : '-';
    }
}
