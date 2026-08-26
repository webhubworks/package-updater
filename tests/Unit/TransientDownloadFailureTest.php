<?php

use PackageUpdater\Actions\UpdateRepoAction;

test('detects a corrupt/0-byte dist zip as transient', function () {
    $output = <<<'TXT'
  - Upgrading verbb/formie (2.2.22 => 2.2.25): Extracting archive
    Failed to extract verbb/formie: (9) /usr/bin/unzip -qq /var/www/html/vendor/composer/tmp-bad.zip
  End-of-central-directory signature not found.  Either this file is not a zipfile

In ZipDownloader.php line 255:
  '/var/www/html/vendor/composer/tmp-bad.zip' is a corrupted zip archive (0 bytes), try again.
TXT;

    expect(UpdateRepoAction::isTransientComposerDownloadFailure($output))->toBeTrue();
});

test('detects a failed HTTP download as transient', function () {
    $output = 'The "https://cdn.example/formie.zip" file could not be downloaded (HTTP/2 503)';

    expect(UpdateRepoAction::isTransientComposerDownloadFailure($output))->toBeTrue();
});

test('does not retry a genuine dependency conflict', function () {
    $output = <<<'TXT'
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - craftcms/cms 4.18.5 requires php >=8.0.2 but your php version does not satisfy that requirement.
TXT;

    expect(UpdateRepoAction::isTransientComposerDownloadFailure($output))->toBeFalse();
});

test('does not treat empty output as transient', function () {
    expect(UpdateRepoAction::isTransientComposerDownloadFailure(''))->toBeFalse();
});
