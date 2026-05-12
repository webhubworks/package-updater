<?php

namespace App\Commands;

use App\Actions\LastRunStore;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class RetryCommand extends Command
{
    protected $signature = 'retry';

    protected $description = 'Re-run the most recently executed update command non-interactively';

    public function handle(): int
    {
        $data = LastRunStore::load();
        if ($data === null) {
            $this->error('No previous run found at ' . LastRunStore::path() . '. Run `update` or `update:craft` first.');
            return self::FAILURE;
        }

        $params = $data['arguments'];
        foreach ($data['options'] as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $params['--' . $name] = $value;
        }

        info(sprintf(
            'Replaying `%s` (saved %s).',
            $data['command'],
            $data['timestamp'] ?? '?',
        ));

        return $this->call($data['command'], $params);
    }
}
