<?php

namespace App\Commands;

use App\Actions\UpdateRepoAction;
use LaravelZero\Framework\Commands\Command;

class UpdateSingleCommand extends Command
{
    protected $signature = 'update:single
        {repoPath}
        {package}
        {--with-all-dependencies}
        {--update-package=}
        {--stop-ddev}';

    protected $description = 'Internal: update a single repo and emit JSON result on stdout';

    protected $hidden = true;

    public function handle(): int
    {
        $updatePackage = $this->option('update-package');

        $result = UpdateRepoAction::update(
            $this->argument('repoPath'),
            $this->argument('package'),
            null,
            (bool) $this->option('with-all-dependencies'),
            is_string($updatePackage) && $updatePackage !== '' ? $updatePackage : null,
            ! $this->option('stop-ddev'),
        );

        $this->output->writeln(json_encode($result->toArray()));

        return self::SUCCESS;
    }
}
