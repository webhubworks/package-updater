<?php

use PackageUpdater\Actions\UpdateRepoAction;
use PackageUpdater\DataTransferObjects\RepoUpdateResult;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->repo = sys_get_temp_dir().'/pu-nopu-test-'.uniqid();
    mkdir($this->repo);
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->repo]))->run();
});

it('skips a repo with a local .nopu before any network call', function () {
    $repo = $this->repo;
    foreach ([
        ['git', 'init', '-q', '-b', 'develop'],
        ['git', 'config', 'user.email', 'test@example.com'],
        ['git', 'config', 'user.name', 'Test'],
        ['git', 'commit', '-q', '--allow-empty', '-m', 'init'],
        // An origin that fails at once if anything tries to reach it.
        ['git', 'remote', 'add', 'origin', 'https://127.0.0.1:9/unreachable.git'],
    ] as $command) {
        (new Process($command, $repo))->mustRun();
    }
    touch($repo.'/.nopu');

    $result = UpdateRepoAction::prepareBranch($repo, null, resetIfDirty: false, resetIfDiverged: false);

    expect($result)->toBeInstanceOf(RepoUpdateResult::class)
        ->and($result->excluded)->toBeTrue()
        ->and($result->branch)->toBe('develop');
});
