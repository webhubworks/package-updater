<?php

namespace PackageUpdater\Commands;

use LaravelZero\Framework\Commands\Command;
use PackageUpdater\Actions\LastRunStore;

use function Laravel\Prompts\info;

class RetryCommand extends Command
{
    protected $signature = 'retry
        {--failed : Replay only against the repos that failed in the last run}';

    protected $description = 'Re-run the most recently executed update command non-interactively';

    public function handle(): int
    {
        $data = LastRunStore::load();
        if ($data === null) {
            $this->error('No previous run found at '.LastRunStore::path().'. Run `update:all` or `update:craft` first.');

            return self::FAILURE;
        }

        try {
            $target = $this->getApplication()->find($data['command']);
        } catch (\Throwable $e) {
            $this->error("Stored command `{$data['command']}` is not registered: ".$e->getMessage());

            return self::FAILURE;
        }
        $allowedOptions = array_keys($target->getDefinition()->getOptions());

        if ($this->option('failed')) {
            $failedPaths = $this->failedPathsFor($data);
            if ($failedPaths === null) {
                return self::FAILURE;
            }
            if ($failedPaths === []) {
                info('Nothing failed in the last run, so there is nothing to retry.');

                return self::SUCCESS;
            }
            $data['options'] = self::narrowToRepos($data['options'], $failedPaths);
        }

        $params = $data['arguments'];
        foreach ($data['options'] as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if (! in_array($name, $allowedOptions, true)) {
                continue;
            }
            $params['--'.$name] = $value;
        }

        info(sprintf(
            'Replaying `%s` (saved %s)%s.',
            $data['command'],
            $data['timestamp'] ?? '?',
            $this->option('failed') ? sprintf(' for %d failed repo(s)', count($data['options']['repo'])) : '',
        ));

        return $this->call($data['command'], $params);
    }

    /**
     * The repos that failed in the last run, or null (after printing why)
     * when the stored results can't be trusted to describe that run: they
     * are missing, belong to another command, or predate it because the run
     * never reached its summary.
     *
     * @param  array{command: string, timestamp: ?string}  $run
     * @return list<string>|null
     */
    protected function failedPathsFor(array $run): ?array
    {
        $results = LastRunStore::loadResults();
        if ($results === null) {
            $this->error('No previous results found at '.LastRunStore::resultsPath().', so the failed repos are unknown.');

            return null;
        }

        if ($results['command'] !== $run['command'] || self::finishedBefore($results['timestamp'], $run['timestamp'])) {
            $this->error("The stored results don't belong to the last `{$run['command']}` run (it probably didn't finish). Run `retry` without --failed instead.");

            return null;
        }

        return self::failedPaths($results['results']);
    }

    /**
     * @param  list<array<string, mixed>>  $results  RepoUpdateResult::toArray() values
     * @return list<string>
     */
    public static function failedPaths(array $results): array
    {
        return array_values(array_map(
            fn (array $r) => (string) $r['repoPath'],
            array_filter($results, fn (array $r) => ($r['status'] ?? null) === 'failed'),
        ));
    }

    /**
     * Restrict the stored options to the given repos. The crawler selection
     * is narrowed the same way, and switched off when none of its repos are
     * left, since an empty --crawl-repo would fall back to crawling them all.
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public static function narrowToRepos(array $options, array $paths): array
    {
        $options['repo'] = $paths;

        if (is_array($options['crawl-repo'] ?? null) && $options['crawl-repo'] !== []) {
            $options['crawl-repo'] = array_values(array_intersect($options['crawl-repo'], $paths));
            if ($options['crawl-repo'] === []) {
                $options['no-crawl'] = true;
            }
        }

        return $options;
    }

    protected static function finishedBefore(?string $resultsTimestamp, ?string $runTimestamp): bool
    {
        if ($resultsTimestamp === null || $runTimestamp === null) {
            return false;
        }

        return strtotime($resultsTimestamp) < strtotime($runTimestamp);
    }
}
