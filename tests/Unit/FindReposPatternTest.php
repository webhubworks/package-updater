<?php

use App\Actions\FindReposAction;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/pu-find-' . uniqid();
    mkdir($this->root, 0755, true);
});

afterEach(function () {
    if (is_dir($this->root)) {
        $rrmdir = function (string $dir) use (&$rrmdir): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                is_dir($path) ? $rrmdir($path) : unlink($path);
            }
            rmdir($dir);
        };
        $rrmdir($this->root);
    }
});

function makeRepo(string $root, string $name, array $lockPackages, array $composerRequire = []): string
{
    $repo = $root . '/' . $name;
    mkdir($repo . '/.git', 0755, true);
    file_put_contents($repo . '/composer.json', json_encode([
        'name' => 'acme/' . $name,
        'require' => $composerRequire,
    ]));
    file_put_contents($repo . '/composer.lock', json_encode([
        'packages' => $lockPackages,
        'packages-dev' => [],
    ]));
    return $repo;
}

it('isPattern detects wildcards', function () {
    expect(FindReposAction::isPattern('vendor/foo'))->toBeFalse();
    expect(FindReposAction::isPattern('laravel-lang/*'))->toBeTrue();
    expect(FindReposAction::isPattern('*-bundle'))->toBeTrue();
});

it('matches all locked packages under a wildcard and reports counts and directness', function () {
    makeRepo($this->root, 'site-a', [
        ['name' => 'laravel-lang/lang', 'version' => '1.0.0'],
        ['name' => 'laravel-lang/common', 'version' => '2.0.0'],
        ['name' => 'other/pkg', 'version' => '9.9.9'],
    ], composerRequire: ['laravel-lang/lang' => '^1.0']);

    makeRepo($this->root, 'site-b', [
        ['name' => 'unrelated/foo', 'version' => '1.0.0'],
    ]);

    makeRepo($this->root, 'site-c', [
        ['name' => 'laravel-lang/lang', 'version' => '1.2.3'],
    ]);

    $matches = FindReposAction::find($this->root, 'laravel-lang/*');

    expect($matches)->toHaveCount(2);

    $a = collect($matches)->firstWhere('path', $this->root . '/site-a');
    expect($a['version'])->toBe('2 packages');
    expect($a['isDirect'])->toBeTrue();
    expect($a['matchedPackages'])->toHaveCount(2);
    expect(array_column($a['matchedPackages'], 'name'))
        ->toBe(['laravel-lang/common', 'laravel-lang/lang']);

    $c = collect($matches)->firstWhere('path', $this->root . '/site-c');
    expect($c['version'])->toBe('1.2.3');
    expect($c['matchedPackages'])->toHaveCount(1);
});

it('non-wildcard package still matches exactly and omits matchedPackages', function () {
    makeRepo($this->root, 'site', [
        ['name' => 'vendor/foo', 'version' => '1.0.0'],
        ['name' => 'vendor/foobar', 'version' => '2.0.0'],
    ]);

    $matches = FindReposAction::find($this->root, 'vendor/foo');

    expect($matches)->toHaveCount(1);
    expect($matches[0]['version'])->toBe('1.0.0');
    expect($matches[0])->not->toHaveKey('matchedPackages');
});
