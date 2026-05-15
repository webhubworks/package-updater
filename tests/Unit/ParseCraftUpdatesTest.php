<?php

use App\Actions\UpdateRepoAction;

test('parses the Performing N updates block from craft output', function () {
    $output = <<<TXT
Fetching available updates ... done
Performing 3 updates:

    - craft 5.9.22 => 5.10.1
    - ckeditor 4.11.3 => 5.5.0
    - typografy 5.0.2 => 5.0.3

Updating Composer dependencies...
  - Upgrading craftcms/cms (5.9.22 => 5.10.1)
TXT;

    expect(UpdateRepoAction::parseCraftUpdates($output))->toBe([
        ['name' => 'craft', 'from' => '5.9.22', 'to' => '5.10.1'],
        ['name' => 'ckeditor', 'from' => '4.11.3', 'to' => '5.5.0'],
        ['name' => 'typografy', 'from' => '5.0.2', 'to' => '5.0.3'],
    ]);
});

test('parses a single-update block', function () {
    $output = <<<TXT
Performing 1 update:

    - commerce 4.5.0 => 4.6.0

Done.
TXT;

    expect(UpdateRepoAction::parseCraftUpdates($output))->toBe([
        ['name' => 'commerce', 'from' => '4.5.0', 'to' => '4.6.0'],
    ]);
});

test('parses craft\'s English-word count header ("Performing one update:")', function () {
    $output = <<<TXT
Performing one update:
    - craft 4.17.15 => 4.18.0
Skipping database backup.
TXT;

    expect(UpdateRepoAction::parseCraftUpdates($output))->toBe([
        ['name' => 'craft', 'from' => '4.17.15', 'to' => '4.18.0'],
    ]);
});

test('strips ANSI color codes before matching', function () {
    $output = "\x1b[32mPerforming 1 update:\x1b[0m\n\n    - \x1b[36mcraft\x1b[0m 5.9.22 => 5.10.1\n";

    expect(UpdateRepoAction::parseCraftUpdates($output))->toBe([
        ['name' => 'craft', 'from' => '5.9.22', 'to' => '5.10.1'],
    ]);
});

test('returns empty list when no block is present', function () {
    expect(UpdateRepoAction::parseCraftUpdates("Nothing to update.\n"))->toBe([]);
});

test('ignores composer Upgrading lines that follow the block', function () {
    $output = <<<TXT
Performing 1 update:

    - craft 5.9.22 => 5.10.1

  - Upgrading craftcms/cms (5.9.22 => 5.10.1)
  - Upgrading other/pkg (1.0.0 => 1.1.0)
TXT;

    expect(UpdateRepoAction::parseCraftUpdates($output))->toBe([
        ['name' => 'craft', 'from' => '5.9.22', 'to' => '5.10.1'],
    ]);
});
