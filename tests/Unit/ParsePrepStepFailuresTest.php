<?php

use App\Actions\UpdateRepoAction;

it('pairs a swallowed step failure with its command and error excerpt', function () {
    // Mirrors webhub's prep script: each step is announced with "> Running:",
    // and a crashed step is swallowed with a "continuing..." marker (with ANSI).
    $output = "\x1b[1;34m> Running: php -d memory_limit=-1 artisan test --parallel --testsuite=Feature,Unit\x1b[0m\n"
        . "\n"
        . "In Container.php line 1411:\n"
        . "\n"
        . "  Target [Laravel\\Nightwatch\\Contracts\\Ingest] is not instantiable while building [App\\Providers\\NightwatchServiceProvider, Laravel\\Nightwatch\\Core].\n"
        . "\n"
        . "paratest [--functional] [-m|--max-batch-size MAX-BATCH-SIZE] ...\n"
        . "\n"
        . "\x1b[0;33m  (Command exited with code 1, continuing...)\x1b[0m\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['command'])->toBe('php -d memory_limit=-1 artisan test --parallel --testsuite=Feature,Unit')
        ->and($failures[0]['error'])->toContain('Target [Laravel\\Nightwatch\\Contracts\\Ingest] is not instantiable');
});

it('captures multiple swallowed failures in order', function () {
    $output = "> Running: artisan test\n"
        . "In Container.php line 10:\n  boom\n"
        . "  (Command exited with code 1, continuing...)\n"
        . "> Running: ./vendor/bin/phpstan analyse\n"
        . "Invalid configuration:\nUnexpected item 'parameters › checkMissingIterableValueType'.\n"
        . "  (Command exited with code 1, continuing...)\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(2)
        ->and($failures[0]['command'])->toBe('artisan test')
        ->and($failures[0]['error'])->toBe('boom')
        ->and($failures[1]['command'])->toBe('./vendor/bin/phpstan analyse')
        ->and($failures[1]['error'])->toBe('Invalid configuration:');
});

it('falls back to the first error-like line when there is no "In ... line N:" header', function () {
    $output = "> Running: artisan config:validate\n"
        . "   ERROR  Command \"config:validate\" is not defined. Did you mean one of these?\n"
        . "  (Command exited with code 1, continuing...)\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['error'])->toContain('is not defined');
});

it('returns an empty list when every step succeeds', function () {
    $output = "> Running: artisan test\nTests: 463 passed\n> Running: pint\nPASS\n";

    expect(UpdateRepoAction::parsePrepStepFailures($output))->toBe([]);
});
