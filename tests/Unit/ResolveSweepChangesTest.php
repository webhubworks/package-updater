<?php

use App\Actions\UpdateRepoAction;

/** Shape a `composer outdated` candidate as filterOutdatedByPatterns returns it. */
function candidate(string $name, string $from, string $to): array
{
    return ['name' => $name, 'from' => $from, 'to' => $to, 'origin' => 'sweep'];
}

test('drops a candidate whose constraint blocked the advertised version', function () {
    // `outdated` advertised 4.6.1 but the ^1 constraint kept it at 1.4.0.
    $candidates = [candidate('webhubworks/craft-panoptikum-cell', '1.4.0', '4.6.1')];
    $installedAfter = ['webhubworks/craft-panoptikum-cell' => '1.4.0'];

    expect(UpdateRepoAction::resolveSweepChanges($candidates, $installedAfter))->toBe([]);
});

test('reports the real installed version, not the advertised latest', function () {
    // Candidate advertised 1.9.0, but composer could only reach 1.8.1.
    $candidates = [candidate('webhubworks/panoptikum-cell', '1.5.0', '1.9.0')];
    $installedAfter = ['webhubworks/panoptikum-cell' => '1.8.1'];

    expect(UpdateRepoAction::resolveSweepChanges($candidates, $installedAfter))->toBe([
        ['name' => 'webhubworks/panoptikum-cell', 'from' => '1.5.0', 'to' => '1.8.1', 'origin' => 'sweep'],
    ]);
});

test('keeps only the packages that actually moved', function () {
    $candidates = [
        candidate('webhubworks/craft-panoptikum-cell', '1.4.0', '4.6.1'),
        candidate('webhubworks/panoptikum-cell', '1.5.0', '1.8.1'),
    ];
    $installedAfter = [
        'webhubworks/craft-panoptikum-cell' => '1.4.0', // blocked, unchanged
        'webhubworks/panoptikum-cell' => '1.8.1',       // moved
    ];

    $changes = UpdateRepoAction::resolveSweepChanges($candidates, $installedAfter);

    expect($changes)->toHaveCount(1);
    expect($changes[0]['name'])->toBe('webhubworks/panoptikum-cell');
    expect($changes[0]['to'])->toBe('1.8.1');
});

test('drops a candidate that is no longer present in the lock', function () {
    $candidates = [candidate('some/removed-pkg', '1.0.0', '2.0.0')];

    expect(UpdateRepoAction::resolveSweepChanges($candidates, ['some/removed-pkg' => null]))->toBe([]);
    expect(UpdateRepoAction::resolveSweepChanges($candidates, []))->toBe([]);
});
