<?php

use PackageUpdater\Actions\UpdateNpmRepoAction;
use PackageUpdater\Commands\UpdateNpmCommand;
use PackageUpdater\Support\RepoName;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/pu-npm-'.uniqid();
    mkdir($this->root, 0755, true);
});

afterEach(function () {
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
});

function npmPackage(string $name, ?string $fixed, array $extra = []): array
{
    return array_merge([
        'name' => $name,
        'installed_versions' => [],
        'fixed_version' => $fixed,
        'relevance' => 'monitor',
        'direct' => true,
        'dev' => false,
        'fixable' => $fixed !== null,
        'blocked_reason' => $fixed === null ? 'no patch published' : null,
        'advisories' => ['GHSA-aaaa-bbbb-cccc'],
    ], $extra);
}

it('keys a repo by its composer name, else by the lowercased origin owner/repo', function () {
    $craft = $this->root.'/craft';
    mkdir($craft.'/.git', 0755, true);
    file_put_contents($craft.'/composer.json', '{"name": "webhubworks/gruene-mitte"}');
    file_put_contents($craft.'/.git/config', "[remote \"origin\"]\n\turl = git@github.com:webhubworks/other.git\n");

    $node = $this->root.'/node';
    mkdir($node.'/.git', 0755, true);
    file_put_contents($node.'/.git/config', "[remote \"origin\"]\n\turl = git@github.com:Jochen-Gerz/jochengerz-frontend.git\n");

    expect(RepoName::panoptikumKey($craft))->toBe('webhubworks/gruene-mitte')
        ->and(RepoName::panoptikumKey($node))->toBe('jochen-gerz/jochengerz-frontend');
});

it('matches Panoptikum repositories to exactly one local folder', function () {
    $keys = ['/r/a' => 'webhubworks/a', '/r/b1' => 'laravel/laravel', '/r/b2' => 'laravel/laravel'];
    $repositories = [
        ['repository' => 'WebhubWorks/A', 'packages' => [npmPackage('axios', '1.12.0')]],
        ['repository' => 'laravel/laravel', 'packages' => [npmPackage('axios', '1.12.0')]],
        ['repository' => 'webhubworks/missing', 'packages' => [npmPackage('axios', '1.12.0')]],
        ['repository' => 'webhubworks/empty', 'packages' => []],
    ];

    $result = UpdateNpmCommand::matchRepositories(array_keys($keys), $repositories, fn (string $dir) => $keys[$dir]);

    expect(array_column($result['matches'], 'path'))->toBe(['/r/a'])
        ->and($result['unmatched'])->toBe(['laravel/laravel (2 local folders)', 'webhubworks/missing']);
});

it('updates only fixes on the installed caret line and reports the rest', function () {
    $lock = ['axios' => ['0.21.1', '1.6.0'], 'swiper' => ['11.0.0'], 'lodash' => ['4.17.21'], 'qs' => ['6.5.0']];
    $packages = [
        npmPackage('axios', '1.12.0'),
        npmPackage('swiper', '12.1.2'),
        npmPackage('lodash', '4.17.21'),
        npmPackage('qs', null, ['blocked_reason' => 'fix only in 6.14.0, outside the range the requiring package allows']),
        npmPackage('missing', '1.0.0'),
    ];

    $plan = UpdateNpmRepoAction::planUpdates($packages, $lock, ['axios' => true, 'swiper' => true]);

    expect($plan['update'])->toBe(['axios' => '1.12.0'])
        ->and($plan['notes'])->toBe([
            'axios 0.21.1 needs a major update to 1.12.0, skipped',
            'swiper 11.0.0 needs a major update to 12.1.2, skipped',
            'qs: no fix to apply (fix only in 6.14.0, outside the range the requiring package allows)',
            'missing: not in the local package-lock.json',
        ]);
});

it('treats the minor version as the caret line below 1.0', function () {
    expect(UpdateNpmRepoAction::caretLine('0.3.1'))->toBe('0.3')
        ->and(UpdateNpmRepoAction::caretLine('2.9.0'))->toBe('2')
        ->and(UpdateNpmRepoAction::belowFixOnSameLine(['0.3.1', '0.2.9'], '0.3.4'))->toBe(['0.3.1']);
});

it('checks blocked ranges inclusively, with an open upper end', function () {
    expect(UpdateNpmRepoAction::isBlocked('1.7.11', '1.7.11', '1.7.23'))->toBeTrue()
        ->and(UpdateNpmRepoAction::isBlocked('1.7.23', '1.7.11', '1.7.23'))->toBeTrue()
        ->and(UpdateNpmRepoAction::isBlocked('1.7.10', '1.7.11', '1.7.23'))->toBeFalse()
        ->and(UpdateNpmRepoAction::isBlocked('1.7.24', '1.7.11', '1.7.23'))->toBeFalse()
        ->and(UpdateNpmRepoAction::isBlocked('9.0.0', '1.7.11', null))->toBeTrue();
});

it('reads every installed version per package and diffs two lockfiles', function () {
    $before = UpdateNpmRepoAction::lockVersions(['packages' => [
        '' => ['name' => 'app'],
        'node_modules/@headlessui/vue' => ['version' => '1.7.10'],
        'node_modules/qs' => ['version' => '6.5.0'],
        'node_modules/express/node_modules/qs' => ['version' => '6.11.0'],
        'node_modules/local-lib' => ['resolved' => '../lib', 'link' => true],
    ]]);
    $after = $before;
    $after['@headlessui/vue'] = ['1.7.15'];
    $after['qs'] = ['6.5.5', '6.11.0'];

    expect($before)->toBe(['@headlessui/vue' => ['1.7.10'], 'qs' => ['6.5.0', '6.11.0']])
        ->and(UpdateNpmRepoAction::diffVersions($before, $after))->toBe([
            ['name' => '@headlessui/vue', 'from' => '1.7.10', 'to' => '1.7.15', 'added' => ['1.7.15']],
            ['name' => 'qs', 'from' => '6.5.0', 'to' => '6.5.5', 'added' => ['6.5.5']],
        ]);
});

it('finds blocked versions among the newly installed ones', function () {
    $changes = [['name' => '@headlessui/vue', 'from' => '1.7.10', 'to' => '1.7.15', 'added' => ['1.7.15']]];
    $blocked = [['package' => '@headlessui/vue', 'affected_from' => '1.7.11', 'affected_to' => '1.7.23', 'reason' => 'modals', 'ticket' => 'WBHB-1234']];

    expect(UpdateNpmRepoAction::blockedHits($changes, $blocked))
        ->toBe([['package' => '@headlessui/vue', 'version' => '1.7.15', 'ticket' => 'WBHB-1234']]);
});

it('lists the files that import a changed package or its direct parent', function () {
    $lock = ['packages' => [
        'node_modules/axios' => ['version' => '1.12.0', 'dependencies' => ['form-data' => '^4.0.0']],
        'node_modules/form-data' => ['version' => '4.0.4'],
        'node_modules/js-cookie' => ['version' => '3.0.5'],
    ]];
    $parents = UpdateNpmRepoAction::directParentsOf(['form-data', 'js-cookie'], $lock, ['axios' => true, 'js-cookie' => true]);

    mkdir($this->root.'/resources/js/Pages', 0755, true);
    mkdir($this->root.'/resources/js/node_modules', 0755, true);
    file_put_contents($this->root.'/resources/js/Pages/Login.vue', "<script setup>\nimport Cookies from 'js-cookie'\n</script>");
    file_put_contents($this->root.'/resources/js/http.js', 'const axios = require("axios/dist/node");');
    file_put_contents($this->root.'/resources/js/other.js', "import vue from 'vue'");
    file_put_contents($this->root.'/resources/js/node_modules/skip.js', "import 'js-cookie'");

    expect($parents)->toBe(['axios' => ['form-data'], 'js-cookie' => ['js-cookie']])
        ->and(UpdateNpmRepoAction::importingFiles($this->root, $parents))->toBe([
            'resources/js/Pages/Login.vue' => ['js-cookie'],
            'resources/js/http.js' => ['form-data'],
        ]);
});

it('names the direct parents of transitive packages still below their fix', function () {
    $lock = ['packages' => [
        'node_modules/vue' => ['version' => '3.5.32', 'dependencies' => ['@vue/server-renderer' => '3.5.32']],
        'node_modules/@vue/server-renderer' => ['version' => '3.5.32'],
        'node_modules/axios' => ['version' => '1.15.2', 'dependencies' => ['form-data' => '^4.0.0']],
        'node_modules/form-data' => ['version' => '4.0.5'],
    ]];
    $direct = ['vue' => true, 'axios' => true];
    $toUpdate = ['@vue/server-renderer' => '3.5.42', 'form-data' => '4.0.4', 'axios' => '1.15.1'];

    expect(UpdateNpmRepoAction::heldBackParents($toUpdate, UpdateNpmRepoAction::lockVersions($lock), $direct, $lock))->toBe(['vue']);
});

it('links GitHub compare views, else the npm versions page', function () {
    expect(UpdateNpmRepoAction::compareLink('git+https://github.com/js-cookie/js-cookie.git', 'js-cookie', '3.0.1', '3.0.5'))
        ->toBe('https://github.com/js-cookie/js-cookie/compare/v3.0.1...v3.0.5')
        ->and(UpdateNpmRepoAction::compareLink('git+ssh://git@github.com/vuejs/core.git#main', 'vue', '3.4.0', '3.4.1'))
        ->toBe('https://github.com/vuejs/core/compare/v3.4.0...v3.4.1')
        ->and(UpdateNpmRepoAction::compareLink(null, '@scope/pkg', '1.0.0', '1.0.1'))
        ->toBe('https://www.npmjs.com/package/@scope/pkg?activeTab=versions');
});

it('writes a commit body with changes, screens, and release notes', function () {
    $body = UpdateNpmRepoAction::commitBody(
        [
            ['name' => 'form-data', 'from' => '4.0.0', 'to' => '4.0.4'],
            ['name' => 'js-cookie', 'from' => '3.0.1', 'to' => '3.0.5'],
        ],
        ['form-data' => npmPackage('form-data', '4.0.4', ['relevance' => 'immediate']), 'js-cookie' => npmPackage('js-cookie', '3.0.5')],
        ['form-data' => '^4.0.4'],
        ['resources/js/Pages/Login.vue' => ['js-cookie']],
        ['js-cookie' => 'https://github.com/js-cookie/js-cookie/compare/v3.0.1...v3.0.5'],
    );

    expect($body)->toBe(implode("\n", [
        '- form-data 4.0.0 -> 4.0.4 (immediate, GHSA-aaaa-bbbb-cccc, override ^4.0.4)',
        '- js-cookie 3.0.1 -> 3.0.5 (monitor, GHSA-aaaa-bbbb-cccc)',
        '',
        'Screens to check:',
        '- resources/js/Pages/Login.vue (js-cookie)',
        '',
        'Release notes:',
        '- js-cookie: https://github.com/js-cookie/js-cookie/compare/v3.0.1...v3.0.5',
    ]));
});

it('adds overrides without reformatting package.json', function () {
    $original = "{\n  \"name\": \"app\",\n  \"scripts\": {},\n  \"dependencies\": {\n    \"axios\": \"^1.6.0\"\n  }\n}\n";

    expect(UpdateNpmRepoAction::withOverrides($original, ['form-data' => '^4.0.4']))->toBe(
        "{\n  \"name\": \"app\",\n  \"scripts\": {},\n  \"dependencies\": {\n    \"axios\": \"^1.6.0\"\n  },\n  \"overrides\": {\n    \"form-data\": \"^4.0.4\"\n  }\n}\n"
    );
});

it('converts only lockfiles without a packages map', function () {
    expect(UpdateNpmRepoAction::needsLockConversion(['lockfileVersion' => 1, 'dependencies' => []]))->toBeTrue()
        ->and(UpdateNpmRepoAction::needsLockConversion(['lockfileVersion' => 3, 'packages' => ['' => []]]))->toBeFalse();
});

it('picks the production build script of Vite and Laravel Mix projects', function () {
    expect(UpdateNpmRepoAction::buildScript(['dev' => 'vite', 'build' => 'vite build']))->toBe('build')
        ->and(UpdateNpmRepoAction::buildScript(['dev' => 'mix', 'production' => 'mix --production', 'prod' => 'npm run production']))->toBe('production')
        ->and(UpdateNpmRepoAction::buildScript(['dev' => 'mix']))->toBeNull();
});

it('accepts a lockfile conversion only when no installed version changes', function () {
    $legacy = ['lockfileVersion' => 1, 'dependencies' => [
        'axios' => ['version' => '1.7.9', 'dependencies' => ['follow-redirects' => ['version' => '1.15.9']]],
        'follow-redirects' => ['version' => '1.5.10'],
        'my-fork' => ['version' => 'git+ssh://git@github.com/acme/my-fork.git#abc'],
    ]];
    $converted = ['lockfileVersion' => 2, 'packages' => [
        '' => [],
        'node_modules/axios' => ['version' => '1.7.9'],
        'node_modules/axios/node_modules/follow-redirects' => ['version' => '1.15.9'],
        'node_modules/follow-redirects' => ['version' => '1.5.10'],
        'node_modules/my-fork' => ['version' => '2.0.0'],
    ]];

    expect(UpdateNpmRepoAction::conversionChanges($legacy, $converted))->toBe([])
        ->and(UpdateNpmRepoAction::conversionChanges(
            ['dependencies' => ['axios' => ['version' => '1.7.9']]],
            ['packages' => ['node_modules/axios' => ['version' => '1.7.9']]],
        ))->toBe([]);

    $converted['packages']['node_modules/axios/node_modules/follow-redirects']['version'] = '1.16.3';
    expect(UpdateNpmRepoAction::conversionChanges($legacy, $converted))->toBe(['follow-redirects']);
});

it('reads the Node version from .nvmrc and finds the matching nvm install', function () {
    $repo = $this->root;
    $nvm = $repo.'/nvm';
    foreach (['v10.22.0', 'v22.1.0', 'v22.14.0', 'v8.17.0'] as $dir) {
        mkdir($nvm.'/versions/node/'.$dir, 0777, true);
    }

    file_put_contents($repo.'/.nvmrc', "v10.22.0\n");
    expect(UpdateNpmRepoAction::nvmrcVersion($repo))->toBe('10.22.0');

    file_put_contents($repo.'/.nvmrc', 'lts/*');
    expect(UpdateNpmRepoAction::nvmrcVersion($repo))->toBeNull()
        ->and(UpdateNpmRepoAction::installedNodeDir($nvm, '10.22.0'))->toBe($nvm.'/versions/node/v10.22.0')
        ->and(UpdateNpmRepoAction::installedNodeDir($nvm, '22'))->toBe($nvm.'/versions/node/v22.14.0')
        ->and(UpdateNpmRepoAction::installedNodeDir($nvm, '1'))->toBeNull()
        ->and(UpdateNpmRepoAction::installedNodeDir($nvm, '12'))->toBeNull();
});

it('updates with the .nvmrc npm only from npm 8.3, which applies overrides', function () {
    expect(UpdateNpmRepoAction::supportsOverrides("11.6.2\n"))->toBeTrue()
        ->and(UpdateNpmRepoAction::supportsOverrides('8.3.0'))->toBeTrue()
        ->and(UpdateNpmRepoAction::supportsOverrides("  installing : node-v24.22.2\n10.9.4\n"))->toBeTrue()
        ->and(UpdateNpmRepoAction::supportsOverrides('8.1.2'))->toBeFalse()
        ->and(UpdateNpmRepoAction::supportsOverrides("6.14.8\n"))->toBeFalse()
        ->and(UpdateNpmRepoAction::supportsOverrides(''))->toBeFalse();
});

it('names which copy of a package a note is about', function () {
    $lock = ['lockfileVersion' => 2, 'packages' => [
        '' => ['dependencies' => ['axios' => '^1.7.8'], 'devDependencies' => ['browser-sync' => '^2.0.0']],
        'node_modules/axios' => ['version' => '1.7.9'],
        'node_modules/browser-sync' => ['version' => '2.29.3', 'dev' => true, 'dependencies' => ['localtunnel' => '^1.9.2']],
        'node_modules/localtunnel' => ['version' => '1.9.2', 'dev' => true, 'dependencies' => ['axios' => '0.19.0']],
        'node_modules/localtunnel/node_modules/axios' => ['version' => '0.19.0', 'dev' => true],
    ]];
    $direct = ['axios' => true, 'browser-sync' => true];

    expect(UpdateNpmRepoAction::describeCopies('axios', ['0.19.0', '1.7.9'], $lock, $direct))
        ->toBe('0.19.0 (dev, via browser-sync > localtunnel), 1.7.9');

    $plan = UpdateNpmRepoAction::planUpdates(
        [['name' => 'axios', 'fixed_version' => '1.8.2', 'fixable' => true]],
        UpdateNpmRepoAction::lockVersions($lock),
        $direct,
        $lock,
    );

    expect($plan['update'])->toBe(['axios' => '1.8.2'])
        ->and($plan['notes'])->toBe(['axios 0.19.0 (dev, via browser-sync > localtunnel) needs a major update to 1.8.2, skipped']);
});
