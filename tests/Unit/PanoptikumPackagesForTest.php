<?php

use PackageUpdater\Commands\UpdateCommand;

it('returns the packages Panoptikum lists for the repo key', function () {
    $repositories = [
        ['repository' => 'acme/other', 'packages' => ['vendor/other']],
        ['repository' => 'Acme/Shop', 'packages' => ['symfony/mailer', 'craftcms/cms']],
    ];

    expect(UpdateCommand::panoptikumPackagesFor('acme/shop', $repositories))->toBe(['symfony/mailer', 'craftcms/cms'])
        ->and(UpdateCommand::panoptikumPackagesFor('acme/missing', $repositories))->toBe([]);
});

it('drops names that could reach composer as an option', function () {
    $repositories = [['repository' => 'acme/shop', 'packages' => ['--working-dir=/tmp', '-x/y', 'symfony/mailer', 'a b/c', 42]]];

    expect(UpdateCommand::panoptikumPackagesFor('acme/shop', $repositories))->toBe(['symfony/mailer']);
});
