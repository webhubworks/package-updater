<?php

namespace PackageUpdater\Support;

/**
 * Resolves a repo path to a "vendor/repo" display name, taken from the
 * origin remote in the repo's own git config:
 *
 *   git@github.com:webhubworks/alpla.git   -> webhubworks/alpla
 *   https://github.com/webhubworks/alpla   -> webhubworks/alpla
 *
 * The remote is used rather than composer.json's "name" because projects
 * bootstrapped from a starter often keep the template's name (e.g.
 * craftcms/craft), which would render the same label for many repos.
 * Falls back to the directory name when no origin remote can be read.
 *
 * Results are cached per path: the label is re-rendered on every spinner
 * tick of the live block, so resolution must touch the filesystem at most
 * once per repo.
 */
final class RepoName
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function for(string $repoPath): string
    {
        $repoPath = rtrim($repoPath, '/');

        return self::$cache[$repoPath] ??= self::resolve($repoPath);
    }

    private static function resolve(string $repoPath): string
    {
        $fallback = basename($repoPath);
        $url = self::originUrl($repoPath);
        if ($url === null) {
            return $fallback;
        }

        // Trim a trailing slash and the .git suffix, then take the last two
        // path segments. Splitting on both ":" and "/" handles scp-style
        // (git@host:vendor/repo) and URL-style remotes alike, and keeping
        // only the last two segments collapses nested groups (GitLab
        // subgroups) down to the owning group plus the repo.
        $url = rtrim(trim($url), '/');
        $url = preg_replace('/\.git$/', '', $url) ?? $url;
        $segments = array_values(array_filter(preg_split('#[:/]#', $url) ?: [], fn ($s) => $s !== ''));

        if (count($segments) < 2) {
            return $fallback;
        }

        return implode('/', array_slice($segments, -2));
    }

    private static function originUrl(string $repoPath): ?string
    {
        $gitDir = self::gitDir($repoPath);
        if ($gitDir === null) {
            return null;
        }

        if (! is_file($gitDir.'/config')) {
            return null;
        }

        $config = @file_get_contents($gitDir.'/config');
        if ($config === false) {
            return null;
        }

        // Isolate the [remote "origin"] section (up to the next section
        // header or end of file) so we don't pick up another remote's url.
        if (! preg_match('/^\[remote "origin"\](?<body>.*?)(?=^\[|\z)/ms', $config, $section)) {
            return null;
        }

        if (! preg_match('/^\s*url\s*=\s*(?<url>\S.*)$/m', $section['body'], $match)) {
            return null;
        }

        return trim($match['url']);
    }

    /**
     * The repo's git directory. Usually `<repo>/.git`, but for submodules and
     * linked worktrees `.git` is a file pointing at the real directory.
     */
    private static function gitDir(string $repoPath): ?string
    {
        $gitPath = $repoPath.'/.git';

        if (is_dir($gitPath)) {
            return $gitPath;
        }

        if (! is_file($gitPath)) {
            return null;
        }

        $pointer = @file_get_contents($gitPath);
        if ($pointer === false || ! preg_match('/^gitdir:\s*(?<dir>\S.*)$/m', $pointer, $match)) {
            return null;
        }

        $dir = rtrim(trim($match['dir']), '/');
        $dir = str_starts_with($dir, '/') ? $dir : $repoPath.'/'.$dir;

        // A linked worktree's gitdir holds no config of its own; the shared
        // one lives in the common dir it points at.
        if (is_file($dir.'/commondir')) {
            $commonDir = trim((string) @file_get_contents($dir.'/commondir'));
            if ($commonDir !== '') {
                $commonDir = rtrim($commonDir, '/');
                $dir = str_starts_with($commonDir, '/') ? $commonDir : $dir.'/'.$commonDir;
            }
        }

        return $dir;
    }
}
