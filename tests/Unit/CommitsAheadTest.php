<?php

use PackageUpdater\Actions\UpdateRepoAction;
use Symfony\Component\Process\Process;

/** Run a command in $cwd and fail the test if it errors. */
function run(array $cmd, string $cwd): void
{
    $p = new Process($cmd, $cwd);
    $p->run();
    expect($p->isSuccessful())->toBeTrue(implode(' ', $cmd).': '.$p->getOutput().$p->getErrorOutput());
}

function commit(string $cwd, string $file, string $contents, string $message): void
{
    file_put_contents($cwd.'/'.$file, $contents);
    run(['git', 'add', '-A'], $cwd);
    run(['git', 'commit', '-q', '-m', $message], $cwd);
}

/** Invoke the private static UpdateRepoAction::commitsAhead(). */
function aheadCount(string $repoPath, string $candidate): int
{
    $m = new ReflectionMethod(UpdateRepoAction::class, 'commitsAhead');

    return $m->invoke(null, $repoPath, $candidate)['count'];
}

/** Invoke the private static UpdateRepoAction::guardAgainstHigherBranchAhead(). */
function guard(string $repoPath, string $branch = 'develop', ?string $defaultBranch = 'main'): mixed
{
    $m = new ReflectionMethod(UpdateRepoAction::class, 'guardAgainstHigherBranchAhead');

    return $m->invoke(null, $repoPath, $branch, $defaultBranch, null);
}

function headSha(string $cwd): string
{
    $p = new Process(['git', 'rev-parse', 'HEAD'], $cwd);
    $p->run();

    return trim($p->getOutput());
}

beforeEach(function () {
    $this->repo = sys_get_temp_dir().'/pu-ahead-test-'.uniqid();
    mkdir($this->repo);
    run(['git', 'init', '-q', '-b', 'main'], $this->repo);
    run(['git', 'config', 'user.email', 'test@example.com'], $this->repo);
    run(['git', 'config', 'user.name', 'Test'], $this->repo);
    commit($this->repo, 'composer.json', "{\n}\n", 'init');

    // The shape every test starts from: develop carries a feature main lacks.
    run(['git', 'checkout', '-q', '-b', 'develop'], $this->repo);
    commit($this->repo, 'feature.php', "<?php // feature\n", 'add feature');
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->repo]))->run();
});

test('a merge of develop into main leaves develop with nothing to catch up on', function () {
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    run(['git', 'merge', '--no-ff', '-q', 'develop', '-m', 'Merge develop into main'], $this->repo);
    run(['git', 'checkout', '-q', 'develop'], $this->repo);

    // main is literally one commit ahead - the merge commit - but that commit
    // is develop's own content, so there is nothing to merge down.
    expect(aheadCount($this->repo, 'main'))->toBe(0);
});

test('a merge of develop into main stays harmless once develop moves on', function () {
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    run(['git', 'merge', '--no-ff', '-q', 'develop', '-m', 'Merge develop into main'], $this->repo);
    run(['git', 'checkout', '-q', 'develop'], $this->repo);
    commit($this->repo, 'later.php', "<?php // later\n", 'work after the merge');

    expect(aheadCount($this->repo, 'main'))->toBe(0);
});

test('a squash merge of develop into main is not something to catch up on either', function () {
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    run(['git', 'merge', '--squash', '-q', 'develop'], $this->repo);
    run(['git', 'commit', '-q', '-m', 'Squashed develop'], $this->repo);
    run(['git', 'checkout', '-q', 'develop'], $this->repo);

    expect(aheadCount($this->repo, 'main'))->toBe(0);
});

test('a hotfix committed straight to main counts as ahead', function () {
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    commit($this->repo, 'hotfix.php', "<?php // hotfix\n", 'hotfix on main');
    run(['git', 'checkout', '-q', 'develop'], $this->repo);

    expect(aheadCount($this->repo, 'main'))->toBe(1);
});

test('a hotfix branch merged into main counts as ahead', function () {
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    run(['git', 'checkout', '-q', '-b', 'hotfix'], $this->repo);
    commit($this->repo, 'hotfix.php', "<?php // hotfix\n", 'hotfix');
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    run(['git', 'merge', '--no-ff', '-q', 'hotfix', '-m', 'Merge hotfix into main'], $this->repo);
    run(['git', 'checkout', '-q', 'develop'], $this->repo);

    // The hotfix commit and the merge commit both sit on main.
    expect(aheadCount($this->repo, 'main'))->toBe(2);
});

test('a file edited while merging develop into main counts as ahead', function () {
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    (new Process(['git', 'merge', '--no-ff', '--no-commit', 'develop'], $this->repo))->run();
    // Whatever was resolved by hand lives only on main until it's merged down.
    file_put_contents($this->repo.'/composer.json', "{\n  \"resolved\": true\n}\n");
    run(['git', 'add', '-A'], $this->repo);
    run(['git', 'commit', '-q', '-m', 'Merge develop into main'], $this->repo);
    run(['git', 'checkout', '-q', 'develop'], $this->repo);

    expect(aheadCount($this->repo, 'main'))->toBe(1);
});

test('a branch that never diverged is not ahead', function () {
    expect(aheadCount($this->repo, 'main'))->toBe(0);
});

test('a branch that does not exist is not ahead', function () {
    expect(aheadCount($this->repo, 'staging'))->toBe(0);
});

test('a hotfix on main is merged down when the branch can fast-forward to it', function () {
    // develop is fully merged into main, so it has no commits of its own.
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    run(['git', 'merge', '--ff-only', '-q', 'develop'], $this->repo);
    commit($this->repo, 'hotfix.php', "<?php // hotfix\n", 'hotfix on main');
    $mainHead = headSha($this->repo);
    run(['git', 'checkout', '-q', 'develop'], $this->repo);

    expect(guard($this->repo))->toBeNull();
    expect(headSha($this->repo))->toBe($mainHead);
    expect(file_exists($this->repo.'/hotfix.php'))->toBeTrue();
});

test('a merge-down of develop into main moves nothing at all', function () {
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    run(['git', 'merge', '--no-ff', '-q', 'develop', '-m', 'Merge develop into main'], $this->repo);
    run(['git', 'checkout', '-q', 'develop'], $this->repo);
    $before = headSha($this->repo);

    // The merge commit is fast-forwardable, but it carries nothing develop is
    // missing, so the branch stays put and the run still counts as unchanged.
    expect(guard($this->repo))->toBeNull();
    expect(headSha($this->repo))->toBe($before);
});

test('a branch with its own commits still aborts instead of being moved', function () {
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    commit($this->repo, 'hotfix.php', "<?php // hotfix\n", 'hotfix on main');
    run(['git', 'checkout', '-q', 'develop'], $this->repo);
    $before = headSha($this->repo);

    $result = guard($this->repo);

    expect($result)->not->toBeNull();
    expect($result->status)->toBe('failed');
    expect($result->message)->toContain('is behind a higher branch: main (+1');
    expect($result->message)->toContain('Merge it down');
    // An aborted repo is left exactly as it was found.
    expect(headSha($this->repo))->toBe($before);
    expect(file_exists($this->repo.'/hotfix.php'))->toBeFalse();
});

test('a branch caught up by the fast-forward reports nothing to merge down', function () {
    // staging carries a release commit, main carries staging plus a hotfix,
    // and develop has nothing of its own: both tiers fast-forward in order.
    run(['git', 'checkout', '-q', '-b', 'staging'], $this->repo);
    commit($this->repo, 'release.php', "<?php // release\n", 'release prep');
    run(['git', 'checkout', '-q', 'main'], $this->repo);
    run(['git', 'merge', '--ff-only', '-q', 'staging'], $this->repo);
    commit($this->repo, 'hotfix.php', "<?php // hotfix\n", 'hotfix on main');
    $mainHead = headSha($this->repo);
    run(['git', 'checkout', '-q', 'develop'], $this->repo);

    expect(guard($this->repo))->toBeNull();
    expect(headSha($this->repo))->toBe($mainHead);
});
