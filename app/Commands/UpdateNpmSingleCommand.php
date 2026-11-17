<?php

namespace PackageUpdater\Commands;

use LaravelZero\Framework\Commands\Command;
use PackageUpdater\Actions\UpdateNpmRepoAction;
use PackageUpdater\Support\ChildProgressEmitter;

class UpdateNpmSingleCommand extends Command
{
    protected $signature = 'update:npm-single
        {repoPath}
        {--plan= : JSON with this repo\'s "packages" and the "blocked_versions" from Panoptikum}
        {--stop-ddev}
        {--crawler-command=}
        {--commit}
        {--push}
        {--reset-if-dirty}
        {--reset-if-diverged}';

    protected $description = 'Internal: run update:npm for a single repo and emit JSON result on stdout';

    protected $hidden = true;

    public function handle(): int
    {
        $plan = json_decode((string) $this->option('plan'), true);
        $crawlerCommand = $this->option('crawler-command');

        $result = UpdateNpmRepoAction::update(
            repoPath: $this->argument('repoPath'),
            packages: (array) ($plan['packages'] ?? []),
            blockedVersions: (array) ($plan['blocked_versions'] ?? []),
            onProgress: ChildProgressEmitter::for($this->output),
            keepDdevRunning: ! $this->option('stop-ddev'),
            crawlerCommandLine: is_string($crawlerCommand) && $crawlerCommand !== '' ? $crawlerCommand : null,
            commit: (bool) $this->option('commit'),
            push: (bool) $this->option('push'),
            resetIfDirty: (bool) $this->option('reset-if-dirty'),
            resetIfDiverged: (bool) $this->option('reset-if-diverged'),
        );

        $this->output->writeln(json_encode($result->toArray()));

        return self::SUCCESS;
    }
}
