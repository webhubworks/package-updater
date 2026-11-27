<?php

use PackageUpdater\Actions\UpdateRepoAction;
use PackageUpdater\DataTransferObjects\RepoUpdateResult;

/**
 * Drives the real sweep against a throwaway composer project. A fake `ddev`
 * on PATH runs host composer for `ddev composer ...`, logs every call, and
 * no-ops `ddev php craft ...`. With FAKE_FAIL set, a composer update naming
 * that package breaks composer.lock and fails, like a half-finished update.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/pu-sweep-'.uniqid();
    $this->repo = $this->root.'/repo';
    $this->log = $this->root.'/ddev.log';
    mkdir($this->root.'/bin', 0755, true);
    mkdir($this->repo.'/storage/logs', 0755, true);

    file_put_contents($this->root.'/bin/ddev', <<<'SH'
#!/bin/sh
echo "$@" >> "$DDEV_LOG"
if [ "$1" = "composer" ]; then
    shift
    if [ -n "$FAKE_FAIL" ] && [ "$1" = "update" ] && echo "$@" | grep -q -- "$FAKE_FAIL"; then
        echo '{"broken": true}' > composer.lock
        echo "Your requirements could not be resolved to an installable set of packages." >&2
        exit 2
    fi
    exec composer "$@"
fi
exit 0
SH);
    chmod($this->root.'/bin/ddev', 0755);

    foreach (['webhubworks/plugin', 'vendor/vulnerable'] as $name) {
        $dir = $this->root.'/pkgs/'.basename($name);
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/composer.json', json_encode(['name' => $name, 'version' => '1.0.0']));
    }
    file_put_contents($this->repo.'/composer.json', json_encode([
        'repositories' => [
            ['packagist.org' => false],
            ['type' => 'path', 'url' => '../pkgs/plugin', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => '../pkgs/vulnerable', 'options' => ['symlink' => false]],
        ],
        'require' => ['webhubworks/plugin' => '^1.0', 'vendor/vulnerable' => '^1.0'],
    ]));
    exec('composer update --no-interaction --no-audit -q -d '.escapeshellarg($this->repo), result_code: $code);
    expect($code)->toBe(0);

    // Both packages get a patch release the sweep should pick up.
    foreach (['plugin', 'vulnerable'] as $dir) {
        $file = $this->root.'/pkgs/'.$dir.'/composer.json';
        file_put_contents($file, str_replace('1.0.0', '1.0.1', (string) file_get_contents($file)));
    }

    $this->path = getenv('PATH');
    sweepEnv('PATH', $this->root.'/bin:'.$this->path);
    sweepEnv('DDEV_LOG', $this->log);
});

afterEach(function () {
    sweepEnv('PATH', $this->path);
    sweepEnv('DDEV_LOG', null);
    sweepEnv('FAKE_FAIL', null);
    exec('rm -rf '.escapeshellarg($this->root));
});

/** Symfony Process builds the child env from $_SERVER and $_ENV, so putenv alone doesn't reach it. */
function sweepEnv(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);

        return;
    }
    putenv("{$name}={$value}");
    $_SERVER[$name] = $_ENV[$name] = $value;
}

function sweep(string $repo, array $patterns, array $securityPackages): array|RepoUpdateResult
{
    return (new ReflectionMethod(UpdateRepoAction::class, 'runComposerSweep'))
        ->invoke(null, $repo, 'main', $patterns, $securityPackages, null);
}

it('sweeps the patterns with -W and the Panoptikum packages without it', function () {
    $result = sweep($this->repo, ['webhubworks/*'], ['vendor/vulnerable']);

    expect($result)->toBe([
        'updates' => [
            ['name' => 'webhubworks/plugin', 'from' => '1.0.0', 'to' => '1.0.1', 'origin' => 'sweep'],
            ['name' => 'vendor/vulnerable', 'from' => '1.0.0', 'to' => '1.0.1', 'origin' => 'panoptikum'],
        ],
        'notes' => [],
    ]);

    $calls = file($this->log, FILE_IGNORE_NEW_LINES);
    expect($calls)->toContain('composer update --no-audit --no-interaction -W webhubworks/*')
        ->toContain('composer update --no-audit --no-interaction vendor/vulnerable')
        ->toContain('php craft migrate/all')
        ->toContain('php craft project-config/apply');
});

it('rolls a failed Panoptikum update back and keeps the sweep', function () {
    sweepEnv('FAKE_FAIL', 'vendor/vulnerable');

    $result = sweep($this->repo, ['webhubworks/*'], ['vendor/vulnerable']);

    expect($result['updates'])->toBe([
        ['name' => 'webhubworks/plugin', 'from' => '1.0.0', 'to' => '1.0.1', 'origin' => 'sweep'],
    ])
        ->and($result['notes'])->toHaveCount(1)
        ->and($result['notes'][0])->toContain('Panoptikum security update rolled back (vendor/vulnerable)');

    $lock = json_decode((string) file_get_contents($this->repo.'/composer.lock'), true);
    $versions = array_column($lock['packages'], 'version', 'name');
    expect($versions)->toBe(['vendor/vulnerable' => '1.0.0', 'webhubworks/plugin' => '1.0.1'])
        ->and(file($this->log, FILE_IGNORE_NEW_LINES))->toContain('composer install --no-interaction');
});

it('skips the post-sweep steps when nothing moved', function () {
    $result = sweep($this->repo, ['nothing/*'], []);

    expect($result)->toBe(['updates' => [], 'notes' => []])
        ->and(file($this->log, FILE_IGNORE_NEW_LINES))->not->toContain('php craft migrate/all');
});
