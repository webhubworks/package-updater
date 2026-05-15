<?php

namespace App\Actions;

final class FindCraftReposAction
{
    public const CraftPackage = 'craftcms/cms';

    /**
     * Find repos under $reposDir that contain a Craft installation
     * (when $handle === "craft") or a specific Craft plugin identified by
     * $handle (matched against composer.lock entries with type="craft-plugin"
     * and extra.handle === $handle).
     *
     * @return list<array{path: string, version: string, package: string}>
     */
    public static function find(string $reposDir, string $handle): array
    {
        $isCraftScope = $handle === 'craft' || $handle === 'all';
        $matches = [];

        foreach (FindReposAction::collectRepoDirs(rtrim($reposDir, '/')) as $dir) {
            $lockData = self::readJson($dir . '/composer.lock');
            if (! is_array($lockData)) {
                continue;
            }

            if ($isCraftScope) {
                $composerData = self::readJson($dir . '/composer.json');
                if (! is_array($composerData)) {
                    continue;
                }

                $required = isset($composerData['require'][self::CraftPackage])
                    || isset($composerData['require-dev'][self::CraftPackage]);
                if (! $required) {
                    continue;
                }

                $version = self::findLockedVersion($lockData, self::CraftPackage);
                if ($version === null) {
                    continue;
                }

                $matches[] = [
                    'path' => $dir,
                    'version' => $version,
                    // For `all`, no single package is tracked — keep the
                    // craftcms/cms version for display, but mark the package
                    // sentinel as 'all' so the runner knows to skip per-package
                    // before/after version lookups.
                    'package' => $handle === 'all' ? 'all' : self::CraftPackage,
                ];
            } else {
                $found = self::findPluginByHandle($lockData, $handle);
                if ($found === null) {
                    continue;
                }

                $matches[] = [
                    'path' => $dir,
                    'version' => $found['version'],
                    'package' => $found['name'],
                ];
            }
        }

        usort($matches, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $lockData
     * @return array{name: string, version: string}|null
     */
    private static function findPluginByHandle(array $lockData, string $handle): ?array
    {
        $packages = array_merge(
            $lockData['packages'] ?? [],
            $lockData['packages-dev'] ?? [],
        );

        foreach ($packages as $pkg) {
            if (($pkg['type'] ?? null) !== 'craft-plugin') {
                continue;
            }
            if (($pkg['extra']['handle'] ?? null) !== $handle) {
                continue;
            }

            return [
                'name' => (string) ($pkg['name'] ?? ''),
                'version' => (string) ($pkg['version'] ?? 'unknown'),
            ];
        }

        return null;
    }

    /** @param  array<string, mixed>  $lockData */
    private static function findLockedVersion(array $lockData, string $package): ?string
    {
        foreach (array_merge($lockData['packages'] ?? [], $lockData['packages-dev'] ?? []) as $pkg) {
            if (($pkg['name'] ?? null) === $package) {
                return isset($pkg['version']) ? (string) $pkg['version'] : null;
            }
        }

        return null;
    }

    private static function readJson(string $path): mixed
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return json_decode($content, true);
    }
}
