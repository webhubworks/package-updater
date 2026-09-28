<?php

use PackageUpdater\Actions\UpdateRepoAction;
use PackageUpdater\DataTransferObjects\RepoUpdateResult;
use Symfony\Component\Process\Process;

/** Run a git command in $cwd and fail the test if it errors. */
function divergedGit(array $args, string $cwd): string
{
    $p = new Process(['git', ...$args], $cwd);
    $p->run();
    expect($p->isSuccessful())->toBeTrue('git '.implode(' ', $args).': '.$p->getOutput().$p->getErrorOutput());

    return trim($p->getOutput());
}

function divergedClone(string $remote, string $into): void
{
    divergedGit(['clone', '-q', $remote, $into], dirname($into));
    divergedGit(['config', 'user.email', 'test@example.com'], $into);
    divergedGit(['config', 'user.name', 'Test'], $into);
}

function divergedCommit(string $cwd, string $file, string $message): void
{
    file_put_contents($cwd.'/'.$file, $message."\n");
    divergedGit(['add', '-A'], $cwd);
    divergedGit(['commit', '-q', '-m', $message], $cwd);
}

/** Fetch, then invoke the private static UpdateRepoAction::resetDivergedBranch(). */
function divergedReset(string $repoPath): string|RepoUpdateResult|null
{
    divergedGit(['fetch', '-q', 'origin'], $repoPath);
    $m = new ReflectionMethod(UpdateRepoAction::class, 'resetDivergedBranch');

    return $m->invoke(null, $repoPath, 'main', null);
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/pu-diverged-test-'.uniqid();
    mkdir($this->root);
    $this->remote = $this->root.'/remote.git';
    $this->local = $this->root.'/local';
    $this->colleague = $this->root.'/colleague';

    divergedGit(['init', '-q', '--bare', '-b', 'main', $this->remote], $this->root);
    divergedClone($this->remote, $this->colleague);
    divergedCommit($this->colleague, 'composer.json', 'init');
    divergedGit(['push', '-q', 'origin', 'main'], $this->colleague);
    divergedClone($this->remote, $this->local);
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->root]))->run();
});

test('parses the left and right counts', function () {
    $m = new ReflectionMethod(UpdateRepoAction::class, 'parseLeftRightCount');

    expect($m->invoke(null, "2\t3\n"))->toBe(['local' => 2, 'remote' => 3]);
    expect($m->invoke(null, "0\t0\n"))->toBe(['local' => 0, 'remote' => 0]);
    expect($m->invoke(null, ''))->toBeNull();
    expect($m->invoke(null, "fatal: bad revision\n"))->toBeNull();
});

test('a branch level with origin is left alone', function () {
    expect(divergedReset($this->local))->toBeNull();
});

test('a branch only behind origin is left to the fast-forward pull', function () {
    divergedCommit($this->colleague, 'upstream.txt', 'upstream work');
    divergedGit(['push', '-q', 'origin', 'main'], $this->colleague);
    $head = divergedGit(['rev-parse', 'HEAD'], $this->local);

    expect(divergedReset($this->local))->toBeNull();
    expect(divergedGit(['rev-parse', 'HEAD'], $this->local))->toBe($head);
});

test('a branch only ahead of origin keeps its local commit', function () {
    divergedCommit($this->local, 'local.txt', 'unpushed update');
    $head = divergedGit(['rev-parse', 'HEAD'], $this->local);

    expect(divergedReset($this->local))->toBeNull();
    expect(divergedGit(['rev-parse', 'HEAD'], $this->local))->toBe($head);
});

test('a diverged branch is reset to origin and reports what it discarded', function () {
    divergedCommit($this->local, 'local.txt', 'unpushed update');
    divergedCommit($this->colleague, 'upstream.txt', 'upstream work');
    divergedCommit($this->colleague, 'upstream2.txt', 'more upstream work');
    divergedGit(['push', '-q', 'origin', 'main'], $this->colleague);

    expect(divergedReset($this->local))->toBe('discarded 1 local commit');
    expect(divergedGit(['rev-parse', 'HEAD'], $this->local))
        ->toBe(divergedGit(['rev-parse', 'origin/main'], $this->local));
    expect(is_file($this->local.'/local.txt'))->toBeFalse();
    expect(is_file($this->local.'/upstream2.txt'))->toBeTrue();
});

test('the discarded count is pluralised', function () {
    divergedCommit($this->local, 'a.txt', 'unpushed one');
    divergedCommit($this->local, 'b.txt', 'unpushed two');
    divergedCommit($this->colleague, 'upstream.txt', 'upstream work');
    divergedGit(['push', '-q', 'origin', 'main'], $this->colleague);

    expect(divergedReset($this->local))->toBe('discarded 2 local commits');
});
