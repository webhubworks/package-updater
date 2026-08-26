<?php

use PackageUpdater\Actions\UpdateRepoAction;

test('matches a single fnmatch pattern', function () {
    $json = json_encode(['installed' => [
        ['name' => 'webhubworks/panoptikum-cell', 'version' => '1.6.0', 'latest' => '1.6.2'],
        ['name' => 'craftcms/cms', 'version' => '5.10.3', 'latest' => '5.10.4'],
    ]]);

    expect(UpdateRepoAction::filterOutdatedByPatterns($json, ['webhubworks/*']))->toBe([
        ['name' => 'webhubworks/panoptikum-cell', 'from' => '1.6.0', 'to' => '1.6.2', 'origin' => 'sweep'],
    ]);
});

test('matches multiple patterns and supports exact package names', function () {
    $json = json_encode(['installed' => [
        ['name' => 'vendor-a/lib', 'version' => '1.0.0', 'latest' => '1.1.0'],
        ['name' => 'vendor-b/lib', 'version' => '2.0.0', 'latest' => '2.0.1'],
        ['name' => 'unrelated/pkg', 'version' => '3.0.0', 'latest' => '4.0.0'],
    ]]);

    $patterns = ['vendor-a/*', 'vendor-b/lib'];

    expect(UpdateRepoAction::filterOutdatedByPatterns($json, $patterns))->toBe([
        ['name' => 'vendor-a/lib', 'from' => '1.0.0', 'to' => '1.1.0', 'origin' => 'sweep'],
        ['name' => 'vendor-b/lib', 'from' => '2.0.0', 'to' => '2.0.1', 'origin' => 'sweep'],
    ]);
});

test('returns empty array when no installed entries match', function () {
    $json = json_encode(['installed' => [
        ['name' => 'foo/bar', 'version' => '1.0.0', 'latest' => '2.0.0'],
    ]]);

    expect(UpdateRepoAction::filterOutdatedByPatterns($json, ['webhubworks/*']))->toBe([]);
});

test('returns empty array when output is not valid JSON', function () {
    expect(UpdateRepoAction::filterOutdatedByPatterns('not json', ['*']))->toBe([]);
});

test('skips entries missing name, version, or latest', function () {
    $json = json_encode(['installed' => [
        ['name' => '', 'version' => '1.0.0', 'latest' => '2.0.0'],
        ['name' => 'foo/bar', 'version' => '', 'latest' => '2.0.0'],
        ['name' => 'foo/baz', 'version' => '1.0.0', 'latest' => ''],
        ['name' => 'foo/good', 'version' => '1.0.0', 'latest' => '2.0.0'],
    ]]);

    expect(UpdateRepoAction::filterOutdatedByPatterns($json, ['foo/*']))->toBe([
        ['name' => 'foo/good', 'from' => '1.0.0', 'to' => '2.0.0', 'origin' => 'sweep'],
    ]);
});

test('only matches a package once even if multiple patterns would match', function () {
    $json = json_encode(['installed' => [
        ['name' => 'webhubworks/panoptikum-cell', 'version' => '1.6.0', 'latest' => '1.6.2'],
    ]]);

    $patterns = ['webhubworks/*', '*/panoptikum-cell'];

    expect(UpdateRepoAction::filterOutdatedByPatterns($json, $patterns))->toBe([
        ['name' => 'webhubworks/panoptikum-cell', 'from' => '1.6.0', 'to' => '1.6.2', 'origin' => 'sweep'],
    ]);
});
