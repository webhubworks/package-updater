<?php

use PackageUpdater\Commands\RetryCommand;

it('picks only the failed repos from the stored results', function () {
    $results = [
        ['repoPath' => '/repos/a', 'status' => 'success'],
        ['repoPath' => '/repos/b', 'status' => 'failed'],
        ['repoPath' => '/repos/c', 'status' => 'skipped'],
        ['repoPath' => '/repos/d', 'status' => 'failed'],
    ];

    expect(RetryCommand::failedPaths($results))->toBe(['/repos/b', '/repos/d']);
});

it('returns no repos when nothing failed', function () {
    expect(RetryCommand::failedPaths([['repoPath' => '/repos/a', 'status' => 'success']]))->toBe([]);
});

it('narrows the repo and crawler selection to the failed repos', function () {
    $options = RetryCommand::narrowToRepos([
        'repo' => ['/repos/a', '/repos/b', '/repos/c'],
        'crawl-repo' => ['/repos/a', '/repos/b'],
        'no-crawl' => false,
    ], ['/repos/b', '/repos/c']);

    expect($options['repo'])->toBe(['/repos/b', '/repos/c'])
        ->and($options['crawl-repo'])->toBe(['/repos/b'])
        ->and($options['no-crawl'])->toBeFalse();
});

it('switches the crawler off when none of its repos failed', function () {
    $options = RetryCommand::narrowToRepos([
        'repo' => ['/repos/a', '/repos/b'],
        'crawl-repo' => ['/repos/a'],
        'no-crawl' => false,
    ], ['/repos/b']);

    expect($options['crawl-repo'])->toBe([])
        ->and($options['no-crawl'])->toBeTrue();
});

it('leaves options without a crawler selection alone', function () {
    $options = RetryCommand::narrowToRepos(['repo' => ['/repos/a', '/repos/b'], 'push' => true], ['/repos/a']);

    expect($options)->toBe(['repo' => ['/repos/a'], 'push' => true]);
});
