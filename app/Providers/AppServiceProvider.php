<?php

namespace PackageUpdater\Providers;

use Illuminate\Support\ServiceProvider;
use PackageUpdater\Support\UserConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * Merges the user-level config file (~/.config/package-updater/config.json)
     * into Laravel's config repository. The env-backed value from
     * config/package-updater.php wins when set; otherwise we fall through to
     * the user config. Both can be overridden per-run via `--reps-dir=`.
     */
    public function boot(): void
    {
        // Treat both null and empty string as "env didn't provide a value" —
        // an empty REPOS_DIR= line in .env (or no .env at all in a global
        // install) shouldn't prevent the user config file from kicking in.
        $current = config('package-updater.repos_dir');
        if (is_string($current) && $current !== '') {
            return;
        }

        $userReposDir = UserConfig::getReposDir();
        if ($userReposDir !== null) {
            config()->set('package-updater.repos_dir', $userReposDir);
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
