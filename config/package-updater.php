<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repositories Directory
    |--------------------------------------------------------------------------
    |
    | Directory the tool scans for repos that depend on the chosen composer
    | package. Resolution precedence (highest first):
    |
    |   1. `--reps-dir=` CLI option (per-run override)
    |   2. `REPOS_DIR` env var (read here; useful for CI / one-off overrides)
    |   3. User config file (~/.config/package-updater/config.json, written
    |      by `pu setup`; merged in via App\Providers\AppServiceProvider)
    |
    | When all three are missing, the first command that needs it drops into
    | `pu setup` interactively.
    |
    */

    'repos_dir' => env('REPOS_DIR'),

];
