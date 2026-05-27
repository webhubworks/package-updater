<?php

namespace App\Commands;

use App\Actions\UpdateRepoAction;
use LaravelZero\Framework\Commands\Command;

class RemoveSingleCommand extends Command
{
    protected $signature = 'remove:single
        {repoPath}
        {--package=* : name=dev pairs, e.g. --package=laravel-lang/lang=0 --package=laravel-lang/common=1}
        {--stop-ddev}';

    protected $description = 'Internal: composer remove in a single repo and emit JSON result on stdout';

    protected $hidden = true;

    public function handle(): int
    {
        $spec = [];
        foreach ((array) $this->option('package') as $raw) {
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            [$name, $dev] = array_pad(explode('=', $raw, 2), 2, '0');
            $spec[] = ['name' => $name, 'dev' => $dev === '1'];
        }

        if (empty($spec)) {
            $this->error('--package is required at least once');
            return self::FAILURE;
        }

        // First spec entry doubles as the "tracked package" for version
        // lookups — irrelevant in remove mode (lockedVersion goes from X to
        // null), but the field is required by update().
        $tracked = $spec[0]['name'];

        $result = UpdateRepoAction::update(
            $this->argument('repoPath'),
            $tracked,
            null,
            false,
            null,
            ! $this->option('stop-ddev'),
            null,
            null,
            true,
            $spec,
        );

        $this->output->writeln(json_encode($result->toArray()));

        return self::SUCCESS;
    }
}
