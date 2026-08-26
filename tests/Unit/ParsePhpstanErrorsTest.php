<?php

use PackageUpdater\Actions\UpdateRepoAction;

it('parses the [ERROR] Found N errors line', function () {
    $output = <<<'TXT'
 ------ ----------------------------------------
  Line   src/Foo.php
 ------ ----------------------------------------
  42     Access to an undefined property
 ------ ----------------------------------------

 [ERROR] Found 9 errors
TXT;
    expect(UpdateRepoAction::parsePhpstanErrors($output))->toBe(9);
});

it('handles the singular form', function () {
    expect(UpdateRepoAction::parsePhpstanErrors(' [ERROR] Found 1 error '))->toBe(1);
});

it('returns null when no phpstan error block is present', function () {
    expect(UpdateRepoAction::parsePhpstanErrors("Tests: 463 passed\nOK"))->toBeNull();
});

it('strips ANSI codes before matching', function () {
    $ansi = "\x1b[1;31m [ERROR] Found 3 errors \x1b[0m";
    expect(UpdateRepoAction::parsePhpstanErrors($ansi))->toBe(3);
});
