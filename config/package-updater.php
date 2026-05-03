<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repositories Directory
    |--------------------------------------------------------------------------
    |
    | Default directory the tool scans for repos that depend on the chosen
    | composer package. Override per-run with `--reps-dir=`.
    |
    */

    'repos_dir' => env('REPOS_DIR', ($_SERVER['HOME'] ?? '~') . '/reps'),

];
