<?php

use PackageUpdater\Actions\UpdateRepoAction;

it('pairs a swallowed step failure with its command and error excerpt', function () {
    // Mirrors webhub's prep script: each step is announced with "> Running:",
    // and a crashed step is swallowed with a "continuing..." marker (with ANSI).
    $output = "\x1b[1;34m> Running: php -d memory_limit=-1 artisan test --parallel --testsuite=Feature,Unit\x1b[0m\n"
        ."\n"
        ."In Container.php line 1411:\n"
        ."\n"
        ."  Target [Laravel\\Nightwatch\\Contracts\\Ingest] is not instantiable while building [App\\Providers\\NightwatchServiceProvider, Laravel\\Nightwatch\\Core].\n"
        ."\n"
        ."paratest [--functional] [-m|--max-batch-size MAX-BATCH-SIZE] ...\n"
        ."\n"
        ."\x1b[0;33m  (Command exited with code 1, continuing...)\x1b[0m\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['command'])->toBe('php -d memory_limit=-1 artisan test --parallel --testsuite=Feature,Unit')
        ->and($failures[0]['error'])->toContain('Target [Laravel\\Nightwatch\\Contracts\\Ingest] is not instantiable');
});

it('captures multiple swallowed failures in order', function () {
    $output = "> Running: artisan test\n"
        ."In Container.php line 10:\n  boom\n"
        ."  (Command exited with code 1, continuing...)\n"
        ."> Running: ./vendor/bin/phpstan analyse\n"
        ."Invalid configuration:\nUnexpected item 'parameters › checkMissingIterableValueType'.\n"
        ."  (Command exited with code 1, continuing...)\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(2)
        ->and($failures[0]['command'])->toBe('artisan test')
        ->and($failures[0]['error'])->toBe('boom')
        ->and($failures[1]['command'])->toBe('./vendor/bin/phpstan analyse')
        ->and($failures[1]['error'])->toBe('Invalid configuration:');
});

it('falls back to the first error-like line when there is no "In ... line N:" header', function () {
    $output = "> Running: artisan config:validate\n"
        ."   ERROR  Command \"config:validate\" is not defined. Did you mean one of these?\n"
        ."  (Command exited with code 1, continuing...)\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['error'])->toContain('is not defined');
});

it('returns an empty list when every step succeeds', function () {
    $output = "> Running: artisan test\nTests: 463 passed\n> Running: pint\nPASS\n";

    expect(UpdateRepoAction::parsePrepStepFailures($output))->toBe([]);
});

it('reports a step the prep script killed for overrunning its timeout', function () {
    // The prep script terminates the step and moves on without a "continuing"
    // marker, so the timeout line is the only trace the step left behind.
    $output = "\x1b[1;34m> Running: php -d memory_limit=-1 ./vendor/bin/pest --parallel --tia\x1b[0m\n"
        ."  \x1b[90;1m.\x1b[39;22m\x1b[90;1m.\x1b[39;22m\n"
        ."\x1b[0;33m  Command timed out after 300 seconds, terminating...\x1b[0m\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['command'])->toBe('php -d memory_limit=-1 ./vendor/bin/pest --parallel --tia')
        ->and($failures[0]['error'])->toBe('timed out after 300s');
});

it('reports an uncaught PHP error that took the prep script down', function () {
    $output = "> Running: php -d memory_limit=-1 artisan test --parallel\n"
        ."PHP Fatal error:  Uncaught TypeError: fclose(): Argument #1 (\$stream) must be an open stream resource in /var/www/html/prep.php:139\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['command'])->toBe('php -d memory_limit=-1 artisan test --parallel')
        ->and($failures[0]['error'])->toContain('prep aborted: Uncaught TypeError: fclose()');
});

it('reports a timeout followed by a fatal error once, against the step that timed out', function () {
    // What a run against a suite too large for the timeout actually logs: the
    // step is killed, and the kill path itself then aborts the prep script,
    // which is why the steps after it produce no output at all. PHP prints the
    // fatal twice, once per stream.
    $output = "> Running: php -d memory_limit=-1 ./vendor/bin/pest --parallel --tia\n"
        ."  Command timed out after 300 seconds, terminating...\n"
        ."PHP Fatal error:  Uncaught TypeError: fclose(): Argument #1 must be an open stream resource in prep.php:139\n"
        ."Fatal error: Uncaught TypeError: fclose(): Argument #1 must be an open stream resource in prep.php:139\n";

    $failures = UpdateRepoAction::parsePrepStepFailures($output);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['error'])->toBe('timed out after 300s');
});

it('does not mistake a fatal error inside test output for an aborted script', function () {
    $output = "> Running: artisan test\n"
        ."   The response threw: Fatal error: something the app printed\n"
        ."Tests: 1 failed, 462 passed\n";

    expect(UpdateRepoAction::parsePrepStepFailures($output))->toBe([]);
});
