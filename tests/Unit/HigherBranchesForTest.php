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

test('the default branch is the only member of its tier that counts', function () {
    expect(UpdateRepoAction::higherBranchesFor('develop', 'main'))->toBe([
        'staging', 'stag', 'stage', 'main', 'prod', 'live',
    ]);

    expect(UpdateRepoAction::higherBranchesFor('develop', 'master'))->toBe([
        'staging', 'stag', 'stage', 'master', 'prod', 'live',
    ]);
});

test('a default branch in a lower tier leaves the higher tiers alone', function () {
    expect(UpdateRepoAction::higherBranchesFor('develop', 'develop'))->toBe([
        'staging', 'stag', 'stage', 'main', 'master', 'prod', 'live',
    ]);
});

test('a default branch that is not a known long-lived name changes nothing', function () {
    expect(UpdateRepoAction::higherBranchesFor('develop', 'production-v2'))->toBe([
        'staging', 'stag', 'stage', 'main', 'master', 'prod', 'live',
    ]);
});

test('the checked-out branch is still placed by the collapsed tiers', function () {
    // `main` is the default, so `master` drops out of the tier entirely and a
    // repo sitting on it is an unknown branch rather than a main-tier one.
    expect(UpdateRepoAction::higherBranchesFor('master', 'main'))->toBe([]);
    expect(UpdateRepoAction::higherBranchesFor('main', 'main'))->toBe(['prod', 'live']);
});
