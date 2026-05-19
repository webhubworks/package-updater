<?php

namespace App\Providers;

use App\Support\UserConfig;
use Illuminate\Support\ServiceProvider;

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
        if (config('package-updater.repos_dir') !== null) {
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
