<?php

use PackageUpdater\Actions\UpdateRepoAction;

test('reads only the Lock file operations block, not Package operations', function () {
    // Simulates a repo whose composer.lock was already ahead of its installed
    // vendor dir (e.g. right after `git pull`): the lock only changes guzzle +
    // panoptikum, but the "Package operations" block lists every package whose
    // installed version catches up to the lock. The commit reflects the lock,
    // so the parser must ignore the Package operations block.
    $output = <<<'TXT'
Loading composer repositories with package information
Updating dependencies
Lock file operations: 0 installs, 2 updates, 0 removals
  - Upgrading guzzlehttp/guzzle (7.10.4 => 7.11.0)
  - Upgrading webhubworks/panoptikum-cell (1.5.0 => 1.6.3)
Writing lock file
Installing dependencies from lock file (including require-dev)
Package operations: 0 installs, 4 updates, 0 removals
  - Upgrading guzzlehttp/guzzle (7.10.4 => 7.11.0)
  - Upgrading webhubworks/panoptikum-cell (1.5.0 => 1.6.3)
  - Upgrading craftcms/cms (5.9.22 => 5.10.3)
  - Upgrading symfony/string (v7.4.8 => v7.4.13)
Generating optimized autoload files
TXT;

    expect(UpdateRepoAction::parseComposerUpdates($output))->toBe([
        ['name' => 'guzzlehttp/guzzle', 'from' => '7.10.4', 'to' => '7.11.0'],
        ['name' => 'webhubworks/panoptikum-cell', 'from' => '1.5.0', 'to' => '1.6.3'],
    ]);
});

test('returns no updates when the lock file is unchanged', function () {
    $output = <<<'TXT'
Updating dependencies
Lock file operations: 0 installs, 0 updates, 0 removals
Nothing to modify in lock file
Installing dependencies from lock file (including require-dev)
Package operations: 0 installs, 3 updates, 0 removals
  - Upgrading craftcms/cms (5.9.22 => 5.10.3)
  - Upgrading symfony/string (v7.4.8 => v7.4.13)
  - Upgrading twig/twig (v3.21.1 => v3.26.0)
TXT;

    expect(UpdateRepoAction::parseComposerUpdates($output))->toBe([]);
});

test('skips newly-installed packages in the lock block', function () {
    $output = <<<'TXT'
Lock file operations: 1 install, 1 update, 0 removals
  - Installing some/new-package (1.0.0)
  - Upgrading guzzlehttp/guzzle (7.10.4 => 7.11.0)
Writing lock file
TXT;

    expect(UpdateRepoAction::parseComposerUpdates($output))->toBe([
        ['name' => 'guzzlehttp/guzzle', 'from' => '7.10.4', 'to' => '7.11.0'],
    ]);
});

test('falls back to scanning all bullet lines when no lock header is present', function () {
    $output = <<<'TXT'
  - Upgrading guzzlehttp/guzzle (7.10.4 => 7.11.0)
  - Downgrading some/pkg (2.0.0 => 1.9.0)
TXT;

    expect(UpdateRepoAction::parseComposerUpdates($output))->toBe([
        ['name' => 'guzzlehttp/guzzle', 'from' => '7.10.4', 'to' => '7.11.0'],
        ['name' => 'some/pkg', 'from' => '2.0.0', 'to' => '1.9.0'],
    ]);
});
