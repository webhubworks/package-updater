<?php

use PackageUpdater\Actions\UpdateRepoAction;
use Symfony\Component\Process\Process;

it('runs a ddev craft command with the container auth.json in COMPOSER_AUTH', function () {
    expect(UpdateRepoAction::withContainerComposerAuth('ddev php craft update all --interactive=0 --backup=1'))
        ->toBe("ddev exec 'COMPOSER_AUTH=\"$(cat ~/.composer/auth.json 2>/dev/null)\" php craft update all --interactive=0 --backup=1'");
});

it('leaves any other command line alone', function (string $line) {
    expect(UpdateRepoAction::withContainerComposerAuth($line))->toBe($line);
})->with(['ddev craft update all', 'php craft update all', 'false']);

it('hands the file contents to the command as COMPOSER_AUTH', function () {
    $home = sys_get_temp_dir().'/pu-auth-'.bin2hex(random_bytes(4));
    mkdir("{$home}/.composer", 0700, true);
    file_put_contents("{$home}/.composer/auth.json", '{"http-basic":{"example.com":{"username":"u","password":"p"}}}');

    // Run the inner command the way `ddev exec` would, with craft swapped for a probe that prints its env.
    $inner = substr(UpdateRepoAction::withContainerComposerAuth('ddev php craft update all'), strlen('ddev exec '));
    $probe = Process::fromShellCommandline('bash -c '.str_replace('php craft update all', 'printenv COMPOSER_AUTH', $inner), null, ['HOME' => $home]);
    $probe->run();

    expect(trim($probe->getOutput()))->toBe('{"http-basic":{"example.com":{"username":"u","password":"p"}}}');

    unlink("{$home}/.composer/auth.json");
    rmdir("{$home}/.composer");
    rmdir($home);
});
