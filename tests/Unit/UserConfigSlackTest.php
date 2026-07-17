<?php

use App\Support\UserConfig;

beforeEach(function () {
    $this->configHome = sys_get_temp_dir().'/pu-config-test-'.uniqid();
    putenv('XDG_CONFIG_HOME='.$this->configHome);
});

afterEach(function () {
    if (is_file(UserConfig::path())) {
        unlink(UserConfig::path());
    }
    putenv('XDG_CONFIG_HOME');
});

test('slack webhook is unconfigured until it is set', function () {
    expect(UserConfig::hasSlackWebhookUrl())->toBeFalse();
    expect(UserConfig::getSlackWebhookUrl())->toBeNull();
});

test('a saved webhook round-trips', function () {
    UserConfig::setSlackWebhookUrl('https://hooks.slack.com/services/T1/B1/tok');

    expect(UserConfig::hasSlackWebhookUrl())->toBeTrue();
    expect(UserConfig::getSlackWebhookUrl())->toBe('https://hooks.slack.com/services/T1/B1/tok');
});

test('an empty webhook reads as configured-but-disabled', function () {
    // Storing null/empty means "the user answered, notifications off" — we
    // must not keep re-prompting, so hasSlackWebhookUrl() stays true while
    // getSlackWebhookUrl() returns null.
    UserConfig::setSlackWebhookUrl(null);

    expect(UserConfig::hasSlackWebhookUrl())->toBeTrue();
    expect(UserConfig::getSlackWebhookUrl())->toBeNull();
});

test('the webhook is trimmed on save', function () {
    UserConfig::setSlackWebhookUrl('  https://hooks.slack.com/services/T1/B1/tok  ');

    expect(UserConfig::getSlackWebhookUrl())->toBe('https://hooks.slack.com/services/T1/B1/tok');
});

test('setting the webhook preserves other config keys', function () {
    UserConfig::setReposDir('/some/reps');
    UserConfig::setSlackWebhookUrl('https://hooks.slack.com/services/T1/B1/tok');

    expect(UserConfig::getReposDir())->toBe('/some/reps');
    expect(UserConfig::getSlackWebhookUrl())->toBe('https://hooks.slack.com/services/T1/B1/tok');
});
