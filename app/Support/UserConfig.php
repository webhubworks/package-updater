<?php

namespace App\Support;

/**
 * Persists user-level settings (currently just the repos directory) to a
 * single JSON file outside the package install dir, so the value survives
 * `composer global update`/reinstall and works regardless of where the user
 * installed the tool.
 *
 * Location follows the XDG Base Directory spec:
 *   $XDG_CONFIG_HOME/package-updater/config.json
 *   (falls back to ~/.config/package-updater/config.json)
 */
final class UserConfig
{
    public static function path(): string
    {
        $configHome = getenv('XDG_CONFIG_HOME');
        if (! is_string($configHome) || trim($configHome) === '') {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
            $configHome = rtrim((string) $home, '/').'/.config';
        }

        return rtrim($configHome, '/').'/package-updater/config.json';
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** @return array<string, mixed> */
    public static function load(): array
    {
        if (! self::exists()) {
            return [];
        }
        $content = @file_get_contents(self::path());
        if ($content === false) {
            return [];
        }
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    public static function save(array $data): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Could not create config directory: {$dir}");
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Could not encode config JSON.');
        }
        if (@file_put_contents($path, $json."\n") === false) {
            throw new \RuntimeException("Could not write config file: {$path}");
        }
    }

    public static function getReposDir(): ?string
    {
        $value = self::load()['repos_dir'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function setReposDir(string $reposDir): void
    {
        $data = self::load();
        $data['repos_dir'] = $reposDir;
        self::save($data);
    }

    /**
     * True once the user has answered the sweep-allowlist prompt at least
     * once (the saved value may be an empty list — "no sweep ever" — which
     * we still treat as "configured", so we don't keep re-prompting).
     */
    public static function hasSweepAllowlist(): bool
    {
        return array_key_exists('composer_sweep_allowlist', self::load());
    }

    /** @return list<string> */
    public static function getSweepAllowlist(): array
    {
        $value = self::load()['composer_sweep_allowlist'] ?? [];
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $value),
            fn (string $p) => $p !== '',
        ));
    }

    /** @param list<string> $patterns */
    public static function setSweepAllowlist(array $patterns): void
    {
        $data = self::load();
        $data['composer_sweep_allowlist'] = array_values(array_filter(
            array_map('strval', $patterns),
            fn (string $p) => $p !== '',
        ));
        self::save($data);
    }

    /**
     * True once the user has answered the Slack-webhook prompt at least once
     * (the saved value may be an empty string ("notifications disabled"),
     * which we still treat as "configured", so we don't keep re-prompting).
     */
    public static function hasSlackWebhookUrl(): bool
    {
        return array_key_exists('slack_webhook_url', self::load());
    }

    public static function getSlackWebhookUrl(): ?string
    {
        $value = self::load()['slack_webhook_url'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Pass null or an empty string to disable Slack notifications. */
    public static function setSlackWebhookUrl(?string $url): void
    {
        $data = self::load();
        $data['slack_webhook_url'] = is_string($url) ? trim($url) : '';
        self::save($data);
    }
}
