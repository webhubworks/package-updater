<?php

use PackageUpdater\Actions\UpdateRepoAction;
use Symfony\Component\Process\Process;

/** Run a git command in $cwd and fail the test if it errors. */
function markerGit(array $args, string $cwd): string
{
    $p = new Process(['git', ...$args], $cwd);
    $p->run();
    expect($p->isSuccessful())->toBeTrue('git '.implode(' ', $args).': '.$p->getOutput().$p->getErrorOutput());

    return $p->getOutput();
}

function markerClone(string $remote, string $into): void
{
    markerGit(['clone', '-q', $remote, $into], dirname($into));
    markerGit(['config', 'user.email', 'test@example.com'], $into);
    markerGit(['config', 'user.name', 'Test'], $into);
}

function markerCommit(string $cwd, string $file, ?string $contents, string $message): void
{
    if ($contents === null) {
        markerGit(['rm', '-q', $file], $cwd);
    } else {
        file_put_contents($cwd.'/'.$file, $contents);
        markerGit(['add', '-A'], $cwd);
    }
    markerGit(['commit', '-q', '-m', $message], $cwd);
}

/** Invoke the private static UpdateRepoAction::isExcluded() after a fetch, as doUpdate() does. */
function markerExcluded(string $repoPath, string $branch = 'main'): bool
{
    markerGit(['fetch', '-q', '--prune', 'origin'], $repoPath);
    $m = new ReflectionMethod(UpdateRepoAction::class, 'isExcluded');

    return $m->invoke(null, $repoPath, $branch);
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/pu-marker-test-'.uniqid();
    mkdir($this->root);
    $this->remote = $this->root.'/remote.git';
    $this->local = $this->root.'/local';
    $this->colleague = $this->root.'/colleague';

    markerGit(['init', '-q', '--bare', '-b', 'main', $this->remote], $this->root);
    markerClone($this->remote, $this->colleague);
    markerCommit($this->colleague, 'composer.json', "{\n}\n", 'init');
    markerGit(['push', '-q', 'origin', 'main'], $this->colleague);
    markerClone($this->remote, $this->local);

    // Keep the run's transcript out of the real ~/.pu-update.
    $this->home = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->root;
});

afterEach(function () {
    $_SERVER['HOME'] = $this->home;
    (new Process(['rm', '-rf', $this->root]))->run();
});

test('a repo without the marker is not excluded', function () {
    expect(markerExcluded($this->local))->toBeFalse();
});

test('a marker a colleague pushed after our last pull excludes the repo', function () {
    markerCommit($this->colleague, UpdateRepoAction::ExcludeMarker, '', 'pause updates');
    markerGit(['push', '-q', 'origin', 'main'], $this->colleague);

    expect(is_file($this->local.'/.nopu'))->toBeFalse();
    expect(markerExcluded($this->local))->toBeTrue();
});

test('a committed marker removed upstream no longer excludes the stale checkout', function () {
    markerCommit($this->colleague, UpdateRepoAction::ExcludeMarker, '', 'pause updates');
    markerGit(['push', '-q', 'origin', 'main'], $this->colleague);
    markerGit(['pull', '-q', 'origin', 'main'], $this->local);

    markerCommit($this->colleague, UpdateRepoAction::ExcludeMarker, null, 'resume updates');
    markerGit(['push', '-q', 'origin', 'main'], $this->colleague);

    expect(is_file($this->local.'/.nopu'))->toBeTrue();
    expect(markerExcluded($this->local))->toBeFalse();
});

test('an untracked marker in the working tree excludes the repo on this machine only', function () {
    touch($this->local.'/.nopu');

    expect(markerExcluded($this->local))->toBeTrue();
});

test('an excluded dirty repo is left untouched even in maintenance mode', function () {
    markerCommit($this->colleague, UpdateRepoAction::ExcludeMarker, '', 'pause updates');
    markerCommit($this->colleague, 'upstream.txt', "new\n", 'upstream work');
    markerGit(['push', '-q', 'origin', 'main'], $this->colleague);
    file_put_contents($this->local.'/composer.json', "{\"dirty\": true}\n");
    file_put_contents($this->local.'/scratch.txt', "untracked\n");
    $headBefore = trim(markerGit(['rev-parse', 'HEAD'], $this->local));

    $result = UpdateRepoAction::update(
        repoPath: $this->local,
        package: 'craftcms/cms',
        craftCommandLine: 'false',
        resetIfDirty: true,
        resetIfDiverged: true,
    );

    expect($result->status)->toBe('skipped');
    expect($result->excluded)->toBeTrue();
    expect($result->message)->toBe('excluded via .nopu');
    expect(file_get_contents($this->local.'/composer.json'))->toBe("{\"dirty\": true}\n");
    expect(is_file($this->local.'/scratch.txt'))->toBeTrue();
    expect(trim(markerGit(['rev-parse', 'HEAD'], $this->local)))->toBe($headBefore);
});
