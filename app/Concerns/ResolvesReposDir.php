<?php

namespace PackageUpdater\Concerns;

use PackageUpdater\Support\UserConfig;

use function Laravel\Prompts\info;

/**
 * Adds repos-dir resolution with first-run auto-setup. Precedence:
 *   1. --reps-dir CLI option
 *   2. config('package-updater.repos_dir')  (env REPOS_DIR > user config file)
 *   3. Drop into `pu setup` interactively, then re-read.
 *
 * Returns null if setup did not complete; the caller should surface that as
 * a command failure.
 */
trait ResolvesReposDir
{
    protected function resolveReposDir(): ?string
    {
        $cli = $this->option('reps-dir');
        if (is_string($cli) && $cli !== '') {
            return rtrim($cli, '/');
        }

        $configured = config('package-updater.repos_dir');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        // Belt-and-suspenders: read the user config file directly, in case
        // the service-provider merge step didn't take effect for whatever
        // reason. Avoids triggering setup again when the file is already
        // there with a valid value.
        $userValue = UserConfig::getReposDir();
        if ($userValue !== null) {
            config()->set('package-updater.repos_dir', $userValue);

            return rtrim($userValue, '/');
        }

        info('No repos directory configured yet — running `pu setup` first.');
        if ($this->call('setup') !== 0) {
            $this->error('Setup did not complete; aborting.');

            return null;
        }

        $configured = UserConfig::getReposDir();
        if (! is_string($configured) || $configured === '') {
            $this->error('Setup did not save a repos directory; aborting.');

            return null;
        }

        config()->set('package-updater.repos_dir', $configured);

        return rtrim($configured, '/');
    }
}
