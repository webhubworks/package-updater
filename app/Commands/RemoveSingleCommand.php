<?php

namespace App\Commands;

use App\Actions\UpdateRepoAction;
use App\Support\ChildProgressEmitter;
use LaravelZero\Framework\Commands\Command;

class RemoveSingleCommand extends Command
{
    protected $signature = 'remove:single
        {repoPath}
        {--package=* : name=dev pairs, e.g. --package=laravel-lang/lang=0 --package=laravel-lang/common=1}
        {--stop-ddev}
        {--push}';

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
        $result = UpdateRepoAction::update(
            repoPath: $this->argument('repoPath'),
            package: $spec[0]['name'],
            onProgress: ChildProgressEmitter::for($this->output),
            keepDdevRunning: ! $this->option('stop-ddev'),
            commit: true,
            removeSpec: $spec,
            push: (bool) $this->option('push'),
        );

        $this->output->writeln(json_encode($result->toArray()));

        return self::SUCCESS;
    }
}
