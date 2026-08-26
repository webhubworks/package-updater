<?php

use PackageUpdater\Actions\BuildMaintenanceSlackMessage;
use PackageUpdater\DataTransferObjects\RepoUpdateResult;

/** Collect every mrkdwn/plain_text string in the payload's blocks. */
function blockTexts(array $payload): string
{
    $texts = [];
    foreach ($payload['blocks'] as $block) {
        if (isset($block['text']['text'])) {
            $texts[] = $block['text']['text'];
        }
        foreach ($block['elements'] ?? [] as $el) {
            if (isset($el['text'])) {
                $texts[] = $el['text'];
            }
        }
    }

    return implode("\n", $texts);
}

test('a fully pushed repo lands in the pushed bucket', function () {
    $results = [
        RepoUpdateResult::success(
            path: '/reps/alpha', branch: 'main', hasUncommittedChanges: false,
            packageUpdates: [['name' => 'craftcms/cms', 'from' => '5.1.0', 'to' => '5.2.0']],
            committed: true, pushed: true,
        ),
    ];

    $text = blockTexts(BuildMaintenanceSlackMessage::build($results, 'all', '/reps', '2026-07-17 09:00'));

    expect($text)->toContain('Updated, committed & pushed* (1)');
    expect($text)->toContain('*alpha* - 1 update');
});

test('a committed-but-not-pushed repo names the blocking issue', function () {
    $results = [
        RepoUpdateResult::success(
            path: '/reps/beta', branch: 'main', hasUncommittedChanges: false,
            prepRan: true, crawlerRan: true,
            crawlerServerErrorUrls: ['https://beta.test/a', 'https://beta.test/b'],
            packageUpdates: [['name' => 'x/y', 'from' => '1.0', 'to' => '1.1']],
            committed: true, pushed: false,
        ),
    ];

    $text = blockTexts(BuildMaintenanceSlackMessage::build($results, 'all', '/reps', '2026-07-17 09:00'));

    expect($text)->toContain('Updated but not pushed* (1)');
    expect($text)->toContain('*beta* - committed, not pushed (2 URLs returned 5xx)');
});

test('an uncommitted success with failing tests is flagged for review', function () {
    $results = [
        RepoUpdateResult::success(
            path: '/reps/gamma', branch: 'main', hasUncommittedChanges: true,
            prepRan: true, testsFailed: 3,
            packageUpdates: [['name' => 'x/y', 'from' => '1.0', 'to' => '1.1']],
            committed: false, pushed: false,
        ),
    ];

    $text = blockTexts(BuildMaintenanceSlackMessage::build($results, 'all', '/reps', '2026-07-17 09:00'));

    expect($text)->toContain('*gamma* - uncommitted changes (3 tests failed)');
});

test('failed and skipped repos share the failed bucket', function () {
    $results = [
        RepoUpdateResult::failed('/reps/delta', 'composer prep failed: boom', 'main'),
        RepoUpdateResult::skipped('/reps/epsilon', 'uncommitted changes'),
    ];

    $text = blockTexts(BuildMaintenanceSlackMessage::build($results, 'all', '/reps', '2026-07-17 09:00'));

    expect($text)->toContain('Failed / other issues* (2)');
    expect($text)->toContain('*delta* - composer prep failed: boom');
    expect($text)->toContain('*epsilon* - skipped: uncommitted changes');
});

test('a success with no changes counts as up to date, not a bucket entry', function () {
    $results = [
        RepoUpdateResult::success(
            path: '/reps/zeta', branch: 'main', hasUncommittedChanges: false,
            committed: false, pushed: false,
        ),
    ];

    $payload = BuildMaintenanceSlackMessage::build($results, 'all', '/reps', '2026-07-17 09:00');
    $text = blockTexts($payload);

    expect($text)->toContain('already up to date');
    expect($text)->toContain('Updated, committed & pushed* (0)');
    expect($text)->not->toContain('zeta');
});

test('an up-to-date repo with a 5xx crawl is surfaced, not counted as up to date', function () {
    $results = [
        // No package updates, but the crawl returned a 5xx — must not vanish.
        RepoUpdateResult::success(
            path: '/reps/gruene-mitte', branch: 'develop', hasUncommittedChanges: false,
            prepRan: true, crawlerRan: true,
            crawlerServerErrorUrls: ['https://gruene-mitte.ddev.site'],
            committed: false, pushed: false,
        ),
        // A genuinely clean, up-to-date repo for contrast.
        RepoUpdateResult::success(
            path: '/reps/justiz', branch: 'develop', hasUncommittedChanges: false,
            committed: false, pushed: false,
        ),
    ];

    $payload = BuildMaintenanceSlackMessage::build($results, 'all', '/reps', '2026-07-17 09:00');
    $text = blockTexts($payload);

    expect($text)->toContain('Failed / other issues* (1)');
    expect($text)->toContain('*gruene-mitte* - 1 URL returned 5xx');
    expect($text)->toContain('https://gruene-mitte.ddev.site');
    // Only the genuinely clean repo counts as up to date.
    expect($text)->toContain('1 already up to date');
    expect($text)->not->toContain('gruene-mitte* - 0');
});

test('a crawler crash on an up-to-date repo is surfaced', function () {
    $results = [
        RepoUpdateResult::success(
            path: '/reps/kappa', branch: 'develop', hasUncommittedChanges: false,
            prepRan: true, crawlerRan: true, crawlerFailed: true,
            committed: false, pushed: false,
        ),
    ];

    $text = blockTexts(BuildMaintenanceSlackMessage::build($results, 'all', '/reps', '2026-07-17 09:00'));

    expect($text)->toContain('Failed / other issues* (1)');
    expect($text)->toContain('*kappa* - site-crawler failed');
});

test('empty buckets render a _none_ placeholder and header/fallback are present', function () {
    $payload = BuildMaintenanceSlackMessage::build([], 'commerce', null, '2026-07-17 09:00');

    expect($payload['text'])->toBe('Craft maintenance summary: 0 pushed, 0 need review, 0 failed/other');
    expect($payload['blocks'][0]['type'])->toBe('header');
    expect(blockTexts($payload))->toContain('_none_');
    expect(blockTexts($payload))->toContain('handle `commerce`');
});

test('the payload overrides the sender name and icon', function () {
    $payload = BuildMaintenanceSlackMessage::build([], 'all', '/reps', '2026-07-17 09:00');

    expect($payload['username'])->toBe('Hulk');
    expect($payload['icon_emoji'])->toBe(':muscle:');
});

test('long buckets are split across multiple section blocks under the char limit', function () {
    $results = [];
    for ($i = 0; $i < 200; $i++) {
        $results[] = RepoUpdateResult::success(
            path: '/reps/repo-with-a-fairly-long-name-'.$i, branch: 'main', hasUncommittedChanges: false,
            packageUpdates: [['name' => 'x/y', 'from' => '1.0', 'to' => '1.1']],
            committed: true, pushed: true,
        );
    }

    $payload = BuildMaintenanceSlackMessage::build($results, 'all', '/reps', '2026-07-17 09:00');

    $sectionLengths = [];
    foreach ($payload['blocks'] as $block) {
        if (($block['type'] ?? null) === 'section') {
            $sectionLengths[] = mb_strlen($block['text']['text']);
        }
    }

    expect(max($sectionLengths))->toBeLessThan(3000);
    // The pushed bucket alone should have spilled into more than one section.
    expect(count($sectionLengths))->toBeGreaterThan(4);
});
