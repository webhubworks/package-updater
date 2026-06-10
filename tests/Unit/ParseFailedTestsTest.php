<?php

use App\Actions\UpdateRepoAction;

it('pairs each FAILED test with its file location', function () {
    $output = <<<TXT
   FAILED  Tests\Feature\PriceRangeFilterTest > it filters products by price range correctly
  Failed asserting that actual size 2 matches expected size 1.

  at tests/Feature/PriceRangeFilterTest.php:77

   FAILED  Tests\Feature\Actions\Weclapp\CapitalizeFrenchArticleFieldsTest > it uses demo attribute IDs when base_url contains demo
  Failed asserting that 25973482 is identical to 24969717.

  at tests/Feature/Actions/Weclapp/CapitalizeFrenchArticleFieldsTest.php:78
TXT;

    expect(UpdateRepoAction::parseFailedTests($output))->toBe([
        [
            'name' => 'Tests\Feature\PriceRangeFilterTest > it filters products by price range correctly',
            'at' => 'tests/Feature/PriceRangeFilterTest.php:77',
        ],
        [
            'name' => 'Tests\Feature\Actions\Weclapp\CapitalizeFrenchArticleFieldsTest > it uses demo attribute IDs when base_url contains demo',
            'at' => 'tests/Feature/Actions/Weclapp/CapitalizeFrenchArticleFieldsTest.php:78',
        ],
    ]);
});

it('strips ANSI colour codes from the badge and test name', function () {
    $output = "\x1b[41;1m FAILED \x1b[49;22m \x1b[1mTests\Feature\ExampleTest\x1b[22m \x1b[90m>\x1b[39m it does a thing\n"
        ."  at \x1b[32mtests/Feature/ExampleTest.php\x1b[39m:\x1b[32m12\x1b[39m";

    expect(UpdateRepoAction::parseFailedTests($output))->toBe([
        ['name' => 'Tests\Feature\ExampleTest > it does a thing', 'at' => 'tests/Feature/ExampleTest.php:12'],
    ]);
});

it('returns the test with a null location when no "at" line follows', function () {
    expect(UpdateRepoAction::parseFailedTests('  FAILED  Tests\Unit\FooTest > it works'))
        ->toBe([['name' => 'Tests\Unit\FooTest > it works', 'at' => null]]);
});

it('returns an empty list when there are no failures', function () {
    expect(UpdateRepoAction::parseFailedTests("Tests: 463 passed\nOK"))->toBe([]);
});
