<?php

namespace PackageUpdater\Commands;

use PackageUpdater\Actions\FindReposAction;
use PackageUpdater\Actions\LastRunStore;
use PackageUpdater\Actions\UpdateNpmRepoAction;
use PackageUpdater\DataTransferObjects\RepoUpdateResult;
use PackageUpdater\Support\PanoptikumClient;
use PackageUpdater\Support\RepoName;
use PackageUpdater\Support\UserConfig;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Applies the npm fixes Panoptikum lists (`GET /api/v1/npm-updates`) to the
 * local repos they belong to. A repo matches when its composer name, or the
 * owner/repo of its origin, equals the repository Panoptikum reports.
 * Inherits the crawler prompts and the Slack summary from update:craft.
 */
class UpdateNpmCommand extends UpdateCraftCommand
{
    protected $signature = 'update:npm
        {--reps-dir= : Directory containing repos (default: ~/reps)}
        {--parallel= : Number of repos to update concurrently (default: prompt; 1 = sequential)}
        {--dry-run : List the matching repos with the packages Panoptikum wants updated and exit}
        {--repo=* : Process only the specified repo path(s); can be passed multiple times. Skips the interactive repo selection.}
        {--filter-name= : Keep only repos whose Panoptikum key (composer name or origin owner/repo) contains this substring}
        {--key= : Keep only the repo whose Panoptikum key (composer name or origin owner/repo) is exactly this}
        {--no-ssh-auth : Skip the initial `ddev auth ssh` step}
        {--stop-ddev : Stop the ddev project in each repo after a successful update (default: keep running)}
        {--commit : Commit the lockfile as "npm security updates" (skips the prompt)}
        {--no-commit : Skip the commit step (skips the prompt)}
        {--push : After committing, push the commit for any error-free repo (skips the prompt)}
        {--no-push : Skip the push step (skips the prompt)}
        {--crawl-repo=* : Run the site-crawler only in these repo path(s). Skips the interactive crawler-selection prompt.}
        {--no-crawl : Skip the site-crawler step entirely.}
        {--crawler-command= : Full shell command to run as the crawler (skips the editable-command prompt).}
        {--open : After the run, open every repo with uncommitted changes in GitKraken (skips the prompt)}
        {--no-open : Skip the end-of-run "open in GitKraken" prompt entirely}
        {--maintenance : Dedicated-server run. Implies --yes and defaults to --parallel=3, --stop-ddev, commit + push, and crawling every ddev repo. Resets dirty or diverged repos and posts a Slack summary when a webhook is configured.}
        {--yes : Skip the confirmation prompt}
        {--retrying : Set by `retry`, not meant to be passed by hand. Keeps logs/last-run.json untouched so the original run stays the one that is replayed.}';

    protected $description = 'Update the npm packages Panoptikum reports as vulnerable, in every local repo it lists';

    public function handle(): int
    {
        if ($this->option('maintenance')) {
            $this->applyMaintenanceDefaults();
        }

        $reposDir = $this->resolveReposDir();
        if ($reposDir === null) {
            return self::FAILURE;
        }
        if (! is_dir($reposDir)) {
            $this->error("Repos directory not found: {$reposDir}");

            return self::FAILURE;
        }

        $url = UserConfig::getPanoptikumUrl();
        $token = UserConfig::getPanoptikumToken();
        if ($url === null || $token === null) {
            $this->error('Panoptikum is not configured. Run `pu setup --panoptikum-url=https://... --panoptikum-token=...` first.');

            return self::FAILURE;
        }

        try {
            $work = PanoptikumClient::npmUpdates($url, $token);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        info("Scanning {$reposDir} for repos with a package-lock.json...");
        $dirs = array_values(array_filter(
            FindReposAction::collectRepoDirs($reposDir, markerFiles: ['composer.lock', 'package-lock.json']),
            fn (string $dir) => is_file($dir.'/package-lock.json'),
        ));
        ['matches' => $matches, 'unmatched' => $unmatched] = self::matchRepositories($dirs, $work['repositories'], fn (string $dir) => RepoName::panoptikumKey($dir));

        if ($unmatched !== []) {
            note(sprintf('%d repositor%s with npm work in Panoptikum match no single local folder: %s', count($unmatched), count($unmatched) === 1 ? 'y' : 'ies', implode(', ', $unmatched)));
        }

        $filter = $this->option('filter-name');
        if (is_string($filter) && $filter !== '') {
            $matches = array_values(array_filter($matches, fn (array $m) => str_contains($m['key'], strtolower($filter))));
        }

        $key = $this->option('key');
        if (is_string($key) && $key !== '') {
            $matches = array_values(array_filter($matches, fn (array $m) => $m['key'] === strtolower(trim($key))));
        }

        if ($matches === []) {
            warning("No repo under {$reposDir} has npm work in Panoptikum.");

            return self::SUCCESS;
        }

        info(sprintf('Found %d repo(s) with npm work.', count($matches)));

        if ($this->option('dry-run')) {
            table(['Repo', 'Package', 'Installed', 'Fixed', 'Relevance', 'Note'], self::dryRunRows($matches));
            note('Dry run, no changes were made. Installed versions are what the production cells run.');

            return self::SUCCESS;
        }

        // update:all's selection prompt labels each repo with its "version".
        $matches = array_map(fn (array $m) => $m + ['version' => count($m['packages']).' package(s)'], $matches);
        $matches = $this->resolveRepoSelection($matches);
        if ($matches === []) {
            info('No repos selected, exiting.');

            return self::SUCCESS;
        }

        $parallel = $this->resolveParallel();
        $keepDdevRunning = $this->resolveKeepDdevRunning();
        $commit = $this->resolveCommit();
        $push = $this->resolvePush($commit);
        $ddevMatches = array_values(array_filter($matches, fn (array $m) => is_file($m['path'].'/.ddev/config.yaml')));
        $crawlPaths = $ddevMatches === [] ? [] : $this->resolveCrawlSelection($ddevMatches);
        $crawlSet = array_flip($crawlPaths);
        $crawlerCommandLine = $crawlPaths !== [] ? $this->resolveCrawlerCommand() : null;

        $paths = array_map(fn (array $m) => $m['path'], $matches);
        $maintenance = (bool) $this->option('maintenance');
        if (! $this->confirmDirtyRepos($paths, willAutoReset: $maintenance)) {
            return self::SUCCESS;
        }

        if (! $this->option('retrying')) {
            LastRunStore::save('update:npm', [], [
                'reps-dir' => $reposDir,
                'parallel' => (string) $parallel,
                'filter-name' => $this->option('filter-name') ?: null,
                'repo' => $paths,
                'stop-ddev' => ! $keepDdevRunning,
                'no-ssh-auth' => (bool) $this->option('no-ssh-auth'),
                'crawl-repo' => $crawlPaths,
                'no-crawl' => $crawlPaths === [],
                'crawler-command' => $crawlerCommandLine,
                'commit' => $commit,
                'no-commit' => ! $commit,
                'push' => $push,
                'no-push' => ! $push,
                'maintenance' => $maintenance,
                'yes' => true,
            ]);
        }

        if ($ddevMatches !== [] && ! $this->option('no-ssh-auth')) {
            $this->ensureDdevSshAuth();
        }

        $packagesByPath = array_column($matches, 'packages', 'path');
        $blocked = $work['blocked_versions'];

        $updater = fn (string $repo, callable $onProgress) => UpdateNpmRepoAction::update(
            repoPath: $repo,
            packages: $packagesByPath[$repo],
            blockedVersions: $blocked,
            onProgress: $onProgress,
            keepDdevRunning: $keepDdevRunning,
            crawlerCommandLine: isset($crawlSet[$repo]) ? $crawlerCommandLine : null,
            commit: $commit,
            push: $push,
            resetIfDirty: $maintenance,
            resetIfDiverged: $maintenance,
        );

        $buildCmd = function (string $repo, string $php, string $binary) use ($packagesByPath, $blocked, $keepDdevRunning, $crawlSet, $crawlerCommandLine, $commit, $push, $maintenance): array {
            $cmd = [$php, $binary, 'update:npm-single', $repo, '--plan='.json_encode(['packages' => $packagesByPath[$repo], 'blocked_versions' => $blocked])];
            if (! $keepDdevRunning) {
                $cmd[] = '--stop-ddev';
            }
            if (isset($crawlSet[$repo]) && $crawlerCommandLine !== null) {
                $cmd[] = '--crawler-command='.$crawlerCommandLine;
            }
            if ($commit) {
                $cmd[] = '--commit';
            }
            if ($push) {
                $cmd[] = '--push';
            }
            if ($maintenance) {
                $cmd[] = '--reset-if-dirty';
                $cmd[] = '--reset-if-diverged';
            }

            return $cmd;
        };

        $results = $parallel === 1
            ? $this->runSequential($paths, fn (string $repo) => $repo, $updater)
            : $this->runParallel($paths, $parallel, fn (string $repo) => $repo, $buildCmd);

        $this->printSummary($results);
        LastRunStore::saveResults('update:npm', array_map(fn (RepoUpdateResult $r) => $r->toArray(), $results));

        if ($maintenance) {
            $this->sendMaintenanceSlackSummary($results, 'npm', $reposDir);
        }

        $this->offerOpenPrompt($results);

        return self::SUCCESS;
    }

    /**
     * The update:craft preset minus its handle argument, which this command
     * doesn't have.
     */
    protected function applyMaintenanceDefaults(): void
    {
        if ($this->option('parallel') === null) {
            $this->input->setOption('parallel', '3');
        }

        $this->input->setOption('yes', true);
        $this->input->setOption('stop-ddev', true);
    }

    /**
     * Pair local repo folders with the repositories Panoptikum lists. Only
     * repositories with packages count. A key found in several folders
     * (a generic composer name like laravel/laravel) matches none of them,
     * because the work can't be attributed.
     *
     * @param  list<string>  $dirs
     * @param  list<array<string, mixed>>  $repositories
     * @param  callable(string): string  $keyOf
     * @return array{matches: list<array{path: string, key: string, packages: list<array<string, mixed>>}>, unmatched: list<string>}
     */
    public static function matchRepositories(array $dirs, array $repositories, callable $keyOf): array
    {
        $dirsByKey = [];
        foreach ($dirs as $dir) {
            $dirsByKey[$keyOf($dir)][] = $dir;
        }

        $matches = [];
        $unmatched = [];
        foreach ($repositories as $repository) {
            $key = strtolower((string) ($repository['repository'] ?? ''));
            $packages = array_values((array) ($repository['packages'] ?? []));
            if ($key === '' || $packages === []) {
                continue;
            }
            if (count($dirsByKey[$key] ?? []) !== 1) {
                $unmatched[] = isset($dirsByKey[$key]) ? "{$key} (".count($dirsByKey[$key]).' local folders)' : $key;

                continue;
            }
            $matches[] = ['path' => $dirsByKey[$key][0], 'key' => $key, 'packages' => $packages];
        }

        return ['matches' => $matches, 'unmatched' => $unmatched];
    }

    /**
     * @param  list<array{path: string, packages: list<array<string, mixed>>}>  $matches
     * @return list<list<string>>
     */
    private static function dryRunRows(array $matches): array
    {
        $rows = [];
        foreach ($matches as $m) {
            foreach ($m['packages'] as $i => $package) {
                $rows[] = [
                    $i === 0 ? RepoName::for($m['path']) : '',
                    (string) $package['name'],
                    implode(', ', (array) ($package['installed_versions'] ?? [])),
                    (string) ($package['fixed_version'] ?? '-'),
                    (string) ($package['relevance'] ?? ''),
                    match (true) {
                        ! ($package['fixable'] ?? false) => (string) ($package['blocked_reason'] ?? 'not fixable'),
                        (bool) ($package['direct'] ?? false) => 'direct',
                        default => 'transitive',
                    },
                ];
            }
        }

        return $rows;
    }
}
