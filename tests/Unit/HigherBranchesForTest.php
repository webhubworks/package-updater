<?php

use PackageUpdater\Actions\UpdateRepoAction;

test('develop sees every higher tier as a higher branch', function () {
    expect(UpdateRepoAction::higherBranchesFor('develop'))->toBe([
        'staging', 'stag', 'stage', 'main', 'master', 'prod', 'live',
    ]);
});

test('a staging-tier branch skips its own synonyms and only sees main and above', function () {
    expect(UpdateRepoAction::higherBranchesFor('staging'))->toBe([
        'main', 'master', 'prod', 'live',
    ]);
});

test('main and master share a tier so neither counts the other as higher', function () {
    expect(UpdateRepoAction::higherBranchesFor('main'))->toBe(['prod', 'live']);
    expect(UpdateRepoAction::higherBranchesFor('master'))->toBe(['prod', 'live']);
});

test('the top tier has no higher branch', function () {
    expect(UpdateRepoAction::higherBranchesFor('prod'))->toBe([]);
    expect(UpdateRepoAction::higherBranchesFor('live'))->toBe([]);
});

test('an unknown branch name has no higher branch', function () {
    expect(UpdateRepoAction::higherBranchesFor('feature/foo'))->toBe([]);
});
