<?php

namespace App\Actions;

final class FindReposAction
{
    public static function isPattern(string $package): bool
    {
        return str_contains($package, '*');
    }

    /**
     * Returns matches under $reposDir whose composer.lock lists $package
     * (in either packages or packages-dev), along with the locked version
     * and whether $package is a direct dependency (declared in composer.json)
     * or a transitive one.
     *
     * When $package contains a wildcard (e.g. `laravel-lang/*`), every locked
     * package whose name matches the pattern is collected into
     * `matchedPackages`. `version` then carries a human summary like
     * "3 packages" for display, and `isDirect` is true if any matched package
     * is a direct dep.
     *
     * @return list<array{path: string, version: string, isDirect: bool, matchedPackages?: list<array{name: string, version: string, isDirect: bool}>}>
     */
    public static function find(string $reposDir, string $package): array
    {
        $isPattern = self::isPattern($package);
        $matches = [];
        $dirs = self::collectRepoDirs(rtrim($reposDir, '/'));

        foreach ($dirs as $dir) {
            $lock = $dir . '/composer.lock';

            $lockContent = @file_get_contents($lock);
            if ($lockContent === false) {
                continue;
            }

            $lockData = json_decode($lockContent, true);
            if (! is_array($lockData)) {
                continue;
            }

            $packages = array_merge(
                $lockData['packages'] ?? [],
                $lockData['packages-dev'] ?? [],
            );

            $match = $isPattern
                ? self::matchPatternInLock($dir, $packages, $package)
                : self::matchExactInLock($dir, $packages, $package);

            if ($match !== null) {
                $matches[] = $match;
            }
        }

        usort($matches, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $matches;
    }

    /**
     * Collect every locked package whose name matches the wildcard pattern,
     * tagging each with its direct/transitive state. Returns null when no
     * locked package matches.
     *
     * @param  list<array<string, mixed>>  $packages  Merged packages + packages-dev from composer.lock
     * @return array{path: string, version: string, isDirect: bool, matchedPackages: list<array{name: string, version: string, isDirect: bool}>}|null
     */
    private static function matchPatternInLock(string $dir, array $packages, string $pattern): ?array
    {
        $matched = [];
        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? null;
            if (! is_string($name) || ! fnmatch($pattern, $name)) {
                continue;
            }
            $matched[$name] = [
                'name' => $name,
                'version' => (string) ($pkg['version'] ?? 'unknown'),
                'isDirect' => self::isDirectDep($dir, $name),
            ];
        }

        if (empty($matched)) {
            return null;
        }

        ksort($matched);
        $matchedList = array_values($matched);
        $anyDirect = array_reduce($matchedList, fn ($c, $m) => $c || $m['isDirect'], false);
        $count = count($matchedList);

        return [
            'path' => $dir,
            'version' => $count === 1 ? $matchedList[0]['version'] : "{$count} packages",
            'isDirect' => $anyDirect,
            'matchedPackages' => $matchedList,
        ];
    }

    /**
     * Find the first locked entry whose name equals $package and return a
     * single-package match. Returns null when the package isn't in the lock.
     *
     * @param  list<array<string, mixed>>  $packages
     * @return array{path: string, version: string, isDirect: bool}|null
     */
    private static function matchExactInLock(string $dir, array $packages, string $package): ?array
    {
        foreach ($packages as $pkg) {
            if (($pkg['name'] ?? null) !== $package) {
                continue;
            }

            return [
                'path' => $dir,
                'version' => (string) ($pkg['version'] ?? 'unknown'),
                'isDirect' => self::isDirectDep($dir, $package),
            ];
        }

        return null;
    }

    public static function hasPackageInLock(string $repoPath, string $package): bool
    {
        $lock = $repoPath . '/composer.lock';
        if (! is_file($lock)) {
            return false;
        }

        $content = @file_get_contents($lock);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return false;
        }

        foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $pkg) {
            if (($pkg['name'] ?? null) === $package) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk $reposDir and return paths that look like repo roots (contain a
     * composer.lock). Descends into subdirectories that aren't repos
     * themselves, so grouping folders like `~/reps/my7steps/<repo>` work.
     *
     * @return list<string>
     */
    public static function collectRepoDirs(string $reposDir, int $maxDepth = 4): array
    {
        $skip = ['vendor', 'node_modules', '.git', '.idea', '.vscode'];
        $repos = [];

        $walk = function (string $dir, int $depth) use (&$walk, &$repos, $skip, $maxDepth): void {
            if (is_file($dir . '/composer.lock')) {
                if (file_exists($dir . '/.git') && ! self::isCraftPluginRepo($dir)) {
                    $repos[] = $dir;
                }

                return;
            }

            if ($depth >= $maxDepth) {
                return;
            }

            $children = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($children as $child) {
                $name = basename($child);
                if ($name === '' || $name[0] === '.' || in_array($name, $skip, true)) {
                    continue;
                }

                $walk($child, $depth + 1);
            }
        };

        $walk($reposDir, 0);

        return $repos;
    }

    /**
     * Craft plugin source repos themselves declare type=craft-plugin in their
     * own composer.json. They aren't site projects to update — skip them.
     */
    private static function isCraftPluginRepo(string $dir): bool
    {
        $composerJson = $dir . '/composer.json';
        if (! is_file($composerJson)) {
            return false;
        }

        $content = @file_get_contents($composerJson);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return false;
        }

        return ($data['type'] ?? null) === 'craft-plugin';
    }

    private static function isDirectDep(string $repoPath, string $package): bool
    {
        return self::requireType($repoPath, $package) !== null;
    }

    /**
     * Returns 'require' or 'require-dev' when the repo declares $package
     * directly, null when it isn't a direct dep.
     */
    public static function requireType(string $repoPath, string $package): ?string
    {
        $composerJson = $repoPath . '/composer.json';
        if (! is_file($composerJson)) {
            return null;
        }

        $content = @file_get_contents($composerJson);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['require'][$package])) {
            return 'require';
        }
        if (isset($data['require-dev'][$package])) {
            return 'require-dev';
        }

        return null;
    }

    /**
     * Across all matched repos, find packages that directly require $package
     * (i.e. candidate "parent" packages composer would need to update with -W
     * in order to bump a transitive $package).
     *
     * @param  list<array{path: string, version: string, isDirect: bool}>  $matches
     * @return list<array{name: string, repoCount: int}>  sorted by frequency desc
     */
    public static function findParentCandidates(array $matches, string $package): array
    {
        $counts = [];

        foreach ($matches as $match) {
            $lock = $match['path'] . '/composer.lock';
            if (! is_file($lock)) {
                continue;
            }

            $content = @file_get_contents($lock);
            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);
            if (! is_array($data)) {
                continue;
            }

            $packages = array_merge(
                $data['packages'] ?? [],
                $data['packages-dev'] ?? [],
            );

            $repoParents = [];
            foreach ($packages as $pkg) {
                $name = $pkg['name'] ?? null;
                if (! is_string($name) || $name === $package) {
                    continue;
                }

                if (isset($pkg['require'][$package])) {
                    $repoParents[$name] = true;
                }
            }

            foreach (array_keys($repoParents) as $parent) {
                $counts[$parent] = ($counts[$parent] ?? 0) + 1;
            }
        }

        arsort($counts);

        $result = [];
        foreach ($counts as $name => $count) {
            $result[] = ['name' => $name, 'repoCount' => $count];
        }

        return $result;
    }
}
