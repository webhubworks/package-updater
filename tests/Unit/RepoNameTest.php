<?php

use PackageUpdater\Support\RepoName;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/pu-name-'.uniqid();
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

function makeRepoWithRemote(string $root, string $name, ?string $originUrl, string $extra = ''): string
{
    $repo = $root.'/'.$name;
    mkdir($repo.'/.git', 0755, true);
    $config = "[core]\n\trepositoryformatversion = 0\n";
    if ($originUrl !== null) {
        $config .= "[remote \"origin\"]\n\turl = {$originUrl}\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n";
    }
    file_put_contents($repo.'/.git/config', $config.$extra);

    return $repo;
}

it('reads vendor/repo from an scp-style origin remote', function () {
    $repo = makeRepoWithRemote($this->root, 'alpla', 'git@github.com:webhubworks/alpla.git');

    expect(RepoName::for($repo))->toBe('webhubworks/alpla');
});

it('reads vendor/repo from an https origin remote', function () {
    $repo = makeRepoWithRemote($this->root, 'arriba', 'https://github.com/webhubworks/arriba.git');

    expect(RepoName::for($repo))->toBe('webhubworks/arriba');
});

it('collapses nested groups to the owning group and repo', function () {
    $repo = makeRepoWithRemote($this->root, 'site', 'git@gitlab.com:acme/team/web/site.git');

    expect(RepoName::for($repo))->toBe('web/site');
});

it('ignores other remotes when picking the url', function () {
    $repo = makeRepoWithRemote(
        $this->root,
        'botschaft',
        'git@github.com:webhubworks/botschaft.git',
        "[remote \"upstream\"]\n\turl = git@github.com:other/fork.git\n",
    );

    expect(RepoName::for($repo))->toBe('webhubworks/botschaft');
});

it('falls back to the directory name without an origin remote', function () {
    $repo = makeRepoWithRemote($this->root, 'local-only', null);

    expect(RepoName::for($repo))->toBe('local-only');
});

it('falls back to the directory name when the repo has no git dir', function () {
    $repo = $this->root.'/plain';
    mkdir($repo, 0755, true);

    expect(RepoName::for($repo))->toBe('plain');
});

it('follows a .git file pointing at a submodule git dir', function () {
    $repo = $this->root.'/child';
    mkdir($repo, 0755, true);
    mkdir($this->root.'/.git/modules/child', 0755, true);
    file_put_contents(
        $this->root.'/.git/modules/child/config',
        "[remote \"origin\"]\n\turl = git@github.com:webhubworks/child.git\n",
    );
    file_put_contents($repo.'/.git', "gitdir: ../.git/modules/child\n");

    expect(RepoName::for($repo))->toBe('webhubworks/child');
});
