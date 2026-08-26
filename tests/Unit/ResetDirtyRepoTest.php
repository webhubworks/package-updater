<?php

use PackageUpdater\Actions\UpdateRepoAction;
use Symfony\Component\Process\Process;

/** Run a git (or shell) command in $cwd and fail the test if it errors. */
function git(array $cmd, string $cwd): void
{
    $p = new Process($cmd, $cwd);
    $p->run();
    expect($p->isSuccessful())->toBeTrue($p->getOutput().$p->getErrorOutput());
}

function statusPorcelain(string $cwd): string
{
    $p = new Process(['git', 'status', '--porcelain'], $cwd);
    $p->run();

    return trim($p->getOutput());
}

/** Invoke the private static UpdateRepoAction::resetDirtyRepo(). */
function callReset(string $repoPath): mixed
{
    $m = new ReflectionMethod(UpdateRepoAction::class, 'resetDirtyRepo');

    return $m->invoke(null, $repoPath, null);
}

beforeEach(function () {
    $this->repo = sys_get_temp_dir().'/pu-reset-test-'.uniqid();
    mkdir($this->repo);
    git(['git', 'init', '-q'], $this->repo);
    git(['git', 'config', 'user.email', 'test@example.com'], $this->repo);
    git(['git', 'config', 'user.name', 'Test'], $this->repo);
    file_put_contents($this->repo.'/composer.json', "{\n}\n");
    file_put_contents($this->repo.'/.gitignore', "vendor/\n");
    git(['git', 'add', '-A'], $this->repo);
    git(['git', 'commit', '-q', '-m', 'init'], $this->repo);
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->repo]))->run();
});

test('reset reverts modified tracked files and removes untracked ones', function () {
    // Modify a tracked file, add an untracked file and an untracked dir.
    file_put_contents($this->repo.'/composer.json', "{\n  \"dirty\": true\n}\n");
    file_put_contents($this->repo.'/leftover.txt', 'stray');
    mkdir($this->repo.'/config');
    file_put_contents($this->repo.'/config/new.yaml', "foo: bar\n");

    expect(statusPorcelain($this->repo))->not->toBe('');

    $result = callReset($this->repo);

    expect($result)->toBeNull();
    expect(statusPorcelain($this->repo))->toBe('');
    expect(file_get_contents($this->repo.'/composer.json'))->toBe("{\n}\n");
    expect(file_exists($this->repo.'/leftover.txt'))->toBeFalse();
    expect(is_dir($this->repo.'/config'))->toBeFalse();
});

test('reset leaves gitignored paths untouched', function () {
    mkdir($this->repo.'/vendor');
    file_put_contents($this->repo.'/vendor/autoload.php', '<?php // keep me');
    // Also dirty a tracked file so there is something to reset.
    file_put_contents($this->repo.'/composer.json', "{\n  \"dirty\": true\n}\n");

    $result = callReset($this->repo);

    expect($result)->toBeNull();
    expect(statusPorcelain($this->repo))->toBe('');
    expect(file_exists($this->repo.'/vendor/autoload.php'))->toBeTrue();
});

test('reset on an already-clean repo is a no-op success', function () {
    expect(statusPorcelain($this->repo))->toBe('');

    $result = callReset($this->repo);

    expect($result)->toBeNull();
    expect(statusPorcelain($this->repo))->toBe('');
});
