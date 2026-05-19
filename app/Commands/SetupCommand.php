<?php

namespace App\Commands;

use App\Support\UserConfig;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class SetupCommand extends Command
{
    protected $signature = 'setup
        {--repos-dir= : Set the repos directory non-interactively and exit}';

    protected $description = 'Configure package-updater (repos directory, stored in ~/.config/package-updater)';

    public function handle(): int
    {
        $current = UserConfig::getReposDir();

        $cli = $this->option('repos-dir');
        if (is_string($cli) && $cli !== '') {
            $resolved = $this->resolvePath($cli);
            if (! is_dir($resolved)) {
                $this->error("Not a directory: {$resolved}");
                return self::FAILURE;
            }
            UserConfig::setReposDir($resolved);
            info("Saved repos directory: {$resolved}");
            note('Config file: '.UserConfig::path());
            return self::SUCCESS;
        }

        if ($current !== null) {
            info("Current repos directory: {$current}");
        } else {
            info('Welcome to package-updater. Let\'s configure where your local repos live.');
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '~';
        $default = $current ?? rtrim((string) $home, '/').'/reps';

        $value = text(
            label: 'Path to the directory containing your local repos',
            placeholder: $default,
            default: $default,
            required: true,
            validate: function (string $value): ?string {
                $resolved = $this->resolvePath($value);
                if (! is_dir($resolved)) {
                    return "Not a directory: {$resolved}";
                }
                return null;
            },
            hint: 'package-updater walks this directory looking for repos that require the chosen composer package.',
        );

        $resolved = $this->resolvePath($value);
        UserConfig::setReposDir($resolved);

        info("Saved repos directory: {$resolved}");
        note('Config file: '.UserConfig::path());

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, '~/') || $path === '~') {
            $home = $_SERVER['HOME'] ?? getenv('HOME');
            if (is_string($home) && $home !== '') {
                $path = $home.substr($path, 1);
            }
        }

        return rtrim($path, '/');
    }
}
