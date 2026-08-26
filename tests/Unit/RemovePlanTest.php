<?php

use PackageUpdater\Actions\FindReposAction;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/pu-remove-'.uniqid();
    mkdir($this->root, 0755, true);
});

afterEach(function () {
    if (is_dir($this->root)) {
        $rrmdir = function (string $dir) use (&$rrmdir): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir.'/'.$entry;
                is_dir($path) ? $rrmdir($path) : unlink($path);
            }
            rmdir($dir);
        };
        $rrmdir($this->root);
    }
});

function makeRemoveRepo(string $root, string $name, array $require = [], array $requireDev = [], array $extraLockPackages = []): string
{
    $repo = $root.'/'.$name;
    mkdir($repo.'/.git', 0755, true);
    $composer = ['name' => 'acme/'.$name];
    if (! empty($require)) {
        $composer['require'] = $require;
    }
    if (! empty($requireDev)) {
        $composer['require-dev'] = $requireDev;
    }
    file_put_contents($repo.'/composer.json', json_encode($composer));

    $lockPackages = [];
    foreach ($require as $pkg => $_) {
        $lockPackages[] = ['name' => $pkg, 'version' => '1.0.0'];
    }
    foreach ($extraLockPackages as $pkg) {
        $lockPackages[] = $pkg;
    }
    $lockPackagesDev = [];
    foreach ($requireDev as $pkg => $_) {
        $lockPackagesDev[] = ['name' => $pkg, 'version' => '1.0.0'];
    }
    file_put_contents($repo.'/composer.lock', json_encode([
        'packages' => $lockPackages,
        'packages-dev' => $lockPackagesDev,
    ]));

    return $repo;
}

it('requireType returns require / require-dev / null', function () {
    $repo = makeRemoveRepo($this->root, 'site', require: ['vendor/foo' => '^1.0'], requireDev: ['vendor/dev' => '^1.0']);

    expect(FindReposAction::requireType($repo, 'vendor/foo'))->toBe('require');
    expect(FindReposAction::requireType($repo, 'vendor/dev'))->toBe('require-dev');
    expect(FindReposAction::requireType($repo, 'vendor/missing'))->toBeNull();
});

it('wildcard find tags each matched package with its direct/transitive state', function () {
    makeRemoveRepo(
        $this->root,
        'site',
        require: ['laravel-lang/lang' => '^1.0'],
        requireDev: ['laravel-lang/dev-helper' => '^1.0'],
        extraLockPackages: [['name' => 'laravel-lang/transitive', 'version' => '0.1.0']],
    );

    $matches = FindReposAction::find($this->root, 'laravel-lang/*');

    expect($matches)->toHaveCount(1);
    $byName = collect($matches[0]['matchedPackages'])->keyBy('name');

    expect($byName['laravel-lang/lang']['isDirect'])->toBeTrue();
    expect($byName['laravel-lang/dev-helper']['isDirect'])->toBeTrue();
    expect($byName['laravel-lang/transitive']['isDirect'])->toBeFalse();

    expect(FindReposAction::requireType($matches[0]['path'], 'laravel-lang/lang'))->toBe('require');
    expect(FindReposAction::requireType($matches[0]['path'], 'laravel-lang/dev-helper'))->toBe('require-dev');
    expect(FindReposAction::requireType($matches[0]['path'], 'laravel-lang/transitive'))->toBeNull();
});
