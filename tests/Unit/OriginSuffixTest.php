<?php

use PackageUpdater\Actions\UpdateRepoAction;

it('marks sweep and Panoptikum updates, and leaves Craft updates plain', function () {
    expect(UpdateRepoAction::originSuffix(['origin' => 'sweep']))->toBe('  (via composer sweep)')
        ->and(UpdateRepoAction::originSuffix(['origin' => 'panoptikum']))->toBe('  (Panoptikum security fix)')
        ->and(UpdateRepoAction::originSuffix([]))->toBe('');
});
