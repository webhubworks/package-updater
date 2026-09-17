<?php

use PackageUpdater\Actions\UpdateRepoAction;
use Symfony\Component\Process\Process;

/** Run a command in $cwd and fail the test if it errors. */
function runInRepo(array $cmd, string $cwd): void
{
    $p = new Process($cmd, $cwd);
    $p->run();
    expect($p->isSuccessful())->toBeTrue($p->getOutput().$p->getErrorOutput());
}

function headOf(string $cwd): string
{
    $p = new Process(['git', 'rev-parse', 'HEAD'], $cwd);
    $p->run();

    return trim($p->getOutput());
}

/** Invoke the private static UpdateRepoAction::repoUntouched(). */
function callRepoUntouched(string $repoPath, ?string $baselineHead, array $packageUpdates = [], bool $wasReset = false): bool
{
    $m = new ReflectionMethod(UpdateRepoAction::class, 'repoUntouched');

    return $m->invoke(null, $repoPath, $baselineHead, $packageUpdates, $wasReset);
}

beforeEach(function () {
    $this->repo = sys_get_temp_dir().'/pu-untouched-test-'.uniqid();
    mkdir($this->repo);
    runInRepo(['git', 'init', '-q'], $this->repo);
    runInRepo(['git', 'config', 'user.email', 'test@example.com'], $this->repo);
    runInRepo(['git', 'config', 'user.name', 'Test'], $this->repo);
    file_put_contents($this->repo.'/composer.lock', "{\n}\n");
    file_put_contents($this->repo.'/.gitignore', "vendor/\n");
    runInRepo(['git', 'add', '-A'], $this->repo);
    runInRepo(['git', 'commit', '-q', '-m', 'init'], $this->repo);
    $this->head = headOf($this->repo);
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->repo]))->run();
});

test('a run that changed nothing counts as untouched', function () {
    expect(callRepoUntouched($this->repo, $this->head))->toBeTrue();
});

test('a parsed package update vetoes the skip', function () {
    $updates = [['name' => 'craft', 'from' => '5.9.22', 'to' => '5.10.1']];

    expect(callRepoUntouched($this->repo, $this->head, $updates))->toBeFalse();
});

test('a dirty working tree vetoes the skip even with no parsed updates', function () {
    // The backstop for an unparseable update: composer.lock moved, the regex
    // found nothing, and the repo must still be verified.
    file_put_contents($this->repo.'/composer.lock', "{\n  \"bumped\": true\n}\n");

    expect(callRepoUntouched($this->repo, $this->head))->toBeFalse();
});

test('an untracked file vetoes the skip', function () {
    file_put_contents($this->repo.'/stray.txt', 'new');

    expect(callRepoUntouched($this->repo, $this->head))->toBeFalse();
});

test('gitignored paths do not veto the skip', function () {
    mkdir($this->repo.'/vendor');
    file_put_contents($this->repo.'/vendor/autoload.php', '<?php // installed');

    expect(callRepoUntouched($this->repo, $this->head))->toBeTrue();
});

test('a moved HEAD vetoes the skip', function () {
    // Stands in for the `git pull` having brought somebody else's commits in.
    file_put_contents($this->repo.'/template.twig', 'hello');
    runInRepo(['git', 'add', '-A'], $this->repo);
    runInRepo(['git', 'commit', '-q', '-m', 'colleague work'], $this->repo);

    expect(headOf($this->repo))->not->toBe($this->head);
    expect(callRepoUntouched($this->repo, $this->head))->toBeFalse();
});

test('a dirty-repo reset vetoes the skip', function () {
    expect(callRepoUntouched($this->repo, $this->head, [], wasReset: true))->toBeFalse();
});

test('an unknown baseline HEAD vetoes the skip', function () {
    expect(callRepoUntouched($this->repo, null))->toBeFalse();
});
