<?php

use PackageUpdater\Actions\FindReposAction;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/pu-key-'.uniqid();
    mkdir($this->root, 0755, true);

    foreach (['acme/shop' => 'shop', 'acme/shop-api' => 'group/shop-api'] as $name => $folder) {
        mkdir("{$this->root}/{$folder}/.git", 0755, true);
        file_put_contents("{$this->root}/{$folder}/composer.json", json_encode(['name' => $name]));
        file_put_contents("{$this->root}/{$folder}/composer.lock", '{}');
    }
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

it('finds the repo whose Panoptikum key matches exactly, ignoring case', function () {
    expect(FindReposAction::findByPanoptikumKey($this->root, 'Acme/Shop'))->toBe(["{$this->root}/shop"])
        ->and(FindReposAction::findByPanoptikumKey($this->root, 'acme/shop-api'))->toBe(["{$this->root}/group/shop-api"])
        ->and(FindReposAction::findByPanoptikumKey($this->root, 'acme/missing'))->toBe([]);
});
