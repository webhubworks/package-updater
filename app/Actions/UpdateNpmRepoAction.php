<?php

namespace PackageUpdater\Actions;

use PackageUpdater\DataTransferObjects\RepoUpdateResult;
use Symfony\Component\Process\Process;

/**
 * Applies the npm fixes Panoptikum lists for one repo (`pu update:npm`):
 * updates only those packages, within the allowed range and only to
 * releases older than a week, checks the result (clean install, build,
 * browser tests, crawl), and commits the lockfile.
 *
 * Branch handling, transcripts, logs, and push come from UpdateRepoAction,
 * so both flows treat branches, `.nopu`, and dirty trees the same way.
 */
final class UpdateNpmRepoAction
{
    public const MinReleaseAgeDays = 7;

    public const CommitTitle = 'npm security updates';

    /** Folders scanned for imports when listing the screens to check. */
    private const ScreenDirs = ['resources/js', 'resources/views', 'src', 'pages', 'components', 'layouts', 'app'];

    private const ScreenExtensions = ['vue', 'js', 'ts', 'jsx', 'tsx', 'mjs', 'twig'];

    /** Screen files listed in the run notes. The commit body lists all of them. */
    private const MaxScreenNotes = 10;

    /**
     * @param  list<array<string, mixed>>  $packages  This repo's `packages` from the npm-updates endpoint.
     * @param  list<array<string, mixed>>  $blockedVersions  The endpoint's `blocked_versions`.
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     */
    public static function update(
        string $repoPath,
        array $packages,
        array $blockedVersions,
        ?callable $onProgress = null,
        bool $keepDdevRunning = true,
        ?string $crawlerCommandLine = null,
        bool $commit = false,
        bool $push = false,
        bool $resetIfDirty = false,
        bool $resetIfDiverged = false,
    ): RepoUpdateResult {
        $transcriptPath = UpdateRepoAction::openTranscript($repoPath);
        try {
            $result = self::doUpdate($repoPath, $packages, $blockedVersions, $onProgress, $keepDdevRunning, $crawlerCommandLine, $commit, $push, $resetIfDirty, $resetIfDiverged);
        } finally {
            UpdateRepoAction::closeTranscript();
        }

        return $transcriptPath === null ? $result : UpdateRepoAction::attachTranscript($result, $transcriptPath);
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @param  list<array<string, mixed>>  $blockedVersions
     */
    private static function doUpdate(
        string $repoPath,
        array $packages,
        array $blockedVersions,
        ?callable $onProgress,
        bool $keepDdevRunning,
        ?string $crawlerCommandLine,
        bool $commit,
        bool $push,
        bool $resetIfDirty,
        bool $resetIfDiverged,
    ): RepoUpdateResult {
        if (is_file($repoPath.'/yarn.lock')) {
            return RepoUpdateResult::skipped($repoPath, 'yarn.lock present');
        }

        $prepared = UpdateRepoAction::prepareBranch($repoPath, $onProgress, $resetIfDirty, $resetIfDiverged);
        if ($prepared instanceof RepoUpdateResult) {
            return $prepared;
        }
        $branch = $prepared['branch'];
        $divergedReset = $prepared['divergedReset'];

        $lockPath = $repoPath.'/package-lock.json';
        $packageJsonPath = $repoPath.'/package.json';
        $originalLock = @file_get_contents($lockPath);
        $originalPackageJson = @file_get_contents($packageJsonPath);
        $lockBefore = is_string($originalLock) ? json_decode($originalLock, true) : null;
        if (! is_array($lockBefore) || ! is_string($originalPackageJson) || ! is_array($lockBefore['packages'] ?? null)) {
            return RepoUpdateResult::failed($repoPath, 'package.json or a package-lock.json with lockfileVersion 2 or later is missing', $branch);
        }

        $versionsBefore = self::lockVersions($lockBefore);
        $direct = self::directDependencies((array) json_decode($originalPackageJson, true));
        ['update' => $toUpdate, 'notes' => $notes] = self::planUpdates($packages, $versionsBefore, $direct);

        if ($toUpdate === []) {
            if ($onProgress !== null) {
                $onProgress('step-start', null, 'nothing to update within the allowed ranges');
            }

            return RepoUpdateResult::success($repoPath, $branch, false, divergedReset: $divergedReset, notes: $notes);
        }

        $useDdev = is_file($repoPath.'/.ddev/config.yaml');
        $npm = $useDdev ? ['ddev', 'npm'] : ['npm'];
        $npmFlags = ['--ignore-scripts', '--before='.date('Y-m-d', strtotime('-'.self::MinReleaseAgeDays.' days')), '--no-audit', '--no-fund'];

        $ddevWasAlreadyRunning = false;
        if ($useDdev) {
            $ddevWasAlreadyRunning = UpdateRepoAction::ddevStatus($repoPath) === 'running';
            if (! $ddevWasAlreadyRunning) {
                $start = UpdateRepoAction::runDdevStart($repoPath, $onProgress);
                if (! $start->isSuccessful()) {
                    return UpdateRepoAction::fail($repoPath, $branch, 'ddev start', $start);
                }
            }
        }
        $stopDdev = function () use ($useDdev, $keepDdevRunning, $ddevWasAlreadyRunning, $repoPath, $onProgress): void {
            if ($useDdev && ! $keepDdevRunning && ! $ddevWasAlreadyRunning) {
                UpdateRepoAction::run(['ddev', 'stop'], $repoPath, 300, $onProgress, 'ddev stop');
            }
        };
        $restore = function () use ($lockPath, $packageJsonPath, $originalLock, $originalPackageJson): void {
            file_put_contents($lockPath, $originalLock);
            file_put_contents($packageJsonPath, $originalPackageJson);
        };

        // Update, add overrides for transitive fixes a parent holds back, then
        // check the result against the blocked versions. A blocked candidate is
        // dropped and the update rebuilt from the original files without it.
        $overrides = [];
        $versionsAfter = $versionsBefore;
        while ($toUpdate !== []) {
            $restore();
            $overrides = [];

            $update = UpdateRepoAction::run([...$npm, 'update', ...array_keys($toUpdate), ...$npmFlags], $repoPath, 1800, $onProgress, implode(' ', [...$npm, 'update', ...array_keys($toUpdate)]));
            if (! $update->isSuccessful()) {
                $stopDdev();

                return UpdateRepoAction::fail($repoPath, $branch, 'npm update', $update);
            }

            $versionsAfter = self::lockVersions((array) json_decode((string) file_get_contents($lockPath), true));
            foreach ($toUpdate as $name => $fixed) {
                if (! isset($direct[$name]) && self::belowFixOnSameLine($versionsAfter[$name] ?? [], $fixed) !== []) {
                    $overrides[$name] = '^'.$fixed;
                }
            }

            if ($overrides !== []) {
                $updatedLock = (string) file_get_contents($lockPath);
                $updatedPackageJson = (string) file_get_contents($packageJsonPath);
                file_put_contents($packageJsonPath, self::withOverrides($updatedPackageJson, $overrides));
                $install = UpdateRepoAction::run([...$npm, 'install', ...$npmFlags], $repoPath, 1800, $onProgress, implode(' ', [...$npm, 'install']).' (apply overrides)');
                if ($install->isSuccessful()) {
                    $versionsAfter = self::lockVersions((array) json_decode((string) file_get_contents($lockPath), true));
                } else {
                    // Usually a fix younger than the release-age window. Keep what npm update reached.
                    file_put_contents($lockPath, $updatedLock);
                    file_put_contents($packageJsonPath, $updatedPackageJson);
                    $notes[] = 'overrides '.implode(', ', array_keys($overrides)).' could not be installed, left out (see transcript)';
                    $overrides = [];
                }
            }

            $hits = self::blockedHits(self::diffVersions($versionsBefore, $versionsAfter), $blockedVersions);
            if ($hits === []) {
                break;
            }

            foreach ($hits as $hit) {
                $notes[] = "{$hit['package']} {$hit['version']} is blocked by {$hit['ticket']}, reverted";
                if (isset($toUpdate[$hit['package']])) {
                    unset($toUpdate[$hit['package']]);

                    continue;
                }
                // A blocked version that came in as a side effect can't be
                // traced to one candidate, so the whole update is dropped.
                $notes[] = 'the update pulled in a blocked version of a package it did not target, nothing updated';
                $toUpdate = [];

                break;
            }
        }

        $changes = $toUpdate === [] ? [] : self::diffVersions($versionsBefore, $versionsAfter);
        if ($changes === []) {
            $restore();
            $stopDdev();

            return RepoUpdateResult::success($repoPath, $branch, false, divergedReset: $divergedReset, notes: $notes);
        }

        foreach ($toUpdate as $name => $fixed) {
            $stillBelow = self::belowFix($versionsAfter[$name] ?? [], $fixed);
            if ($stillBelow !== []) {
                $notes[] = sprintf('%s still below %s (%s): the fix is younger than %d days, or package.json pins it', $name, $fixed, implode(', ', $stillBelow), self::MinReleaseAgeDays);
            }
        }

        $gate = self::runGate($repoPath, $branch, $npm, $useDdev, $crawlerCommandLine, $onProgress);
        $stopDdev();
        if ($gate instanceof RepoUpdateResult) {
            return $gate;
        }

        $lockAfter = (array) json_decode((string) file_get_contents($lockPath), true);
        $targeted = array_values(array_intersect(array_keys($toUpdate), array_column($changes, 'name')));
        $screens = self::importingFiles($repoPath, self::directParentsOf($targeted, $lockAfter, $direct));
        $links = self::releaseLinks($repoPath, $npm, $changes, $direct, $versionsBefore, $versionsAfter);

        foreach (array_slice(array_keys($screens), 0, self::MaxScreenNotes) as $file) {
            $notes[] = "check {$file} (".implode(', ', $screens[$file]).')';
        }
        if (count($screens) > self::MaxScreenNotes) {
            $notes[] = sprintf('and %d more files to check, see the commit body', count($screens) - self::MaxScreenNotes);
        }

        $committed = false;
        if ($commit) {
            $files = $overrides === [] ? ['package-lock.json'] : ['package-lock.json', 'package.json'];
            $add = UpdateRepoAction::run(['git', 'add', '--', ...$files], $repoPath, 120, $onProgress, 'git add '.implode(' ', $files));
            if ($add->isSuccessful()) {
                $body = self::commitBody($changes, self::packagesByName($packages), $overrides, $screens, $links);
                $committed = UpdateRepoAction::run(['git', 'commit', '-m', self::CommitTitle, '-m', $body], $repoPath, 120, $onProgress, 'git commit')->isSuccessful();
            }
        }

        $runHadErrors = $gate['testsFailed'] > 0 || $gate['testsBroken'] || $gate['crawlerFailed'] || $gate['crawlerServerErrorUrls'] !== [];
        $pushed = false;
        if ($push && $committed) {
            if (! $runHadErrors) {
                $pushed = UpdateRepoAction::pushBranch($repoPath, $branch, $onProgress);
            } elseif ($onProgress !== null) {
                $onProgress('step-start', null, 'skipping push, the run reported errors');
            }
        }

        $status = UpdateRepoAction::run(['git', 'status', '--porcelain'], $repoPath, 60, null, '', stream: false);

        return RepoUpdateResult::success(
            $repoPath,
            $branch,
            trim($status->getOutput()) !== '',
            prepRan: $gate['testsRan'],
            testsFailed: $gate['testsRan'] ? $gate['testsFailed'] : null,
            testsSummary: $gate['testsSummary'],
            prepLogPath: $gate['testsLogPath'],
            crawlerRan: $gate['crawlerRan'],
            crawlerFailed: $gate['crawlerFailed'],
            crawlerLogPath: $gate['crawlerLogPath'],
            crawlerServerErrorUrls: $gate['crawlerServerErrorUrls'],
            packageUpdates: array_map(fn (array $c) => ['name' => $c['name'], 'from' => $c['from'], 'to' => $c['to'], 'origin' => 'npm'], $changes),
            committed: $committed,
            pushed: $pushed,
            divergedReset: $divergedReset,
            notes: $notes,
        );
    }

    /**
     * Clean install, build, browser tests, crawl. A failed install or build
     * fails the repo. Test and crawler problems are reported and hold back
     * the push.
     *
     * @param  list<string>  $npm
     * @return RepoUpdateResult|array{testsRan: bool, testsFailed: int, testsBroken: bool, testsSummary: ?string, testsLogPath: ?string, crawlerRan: bool, crawlerFailed: bool, crawlerLogPath: ?string, crawlerServerErrorUrls: list<string>}
     */
    private static function runGate(string $repoPath, string $branch, array $npm, bool $useDdev, ?string $crawlerCommandLine, ?callable $onProgress): RepoUpdateResult|array
    {
        $ci = UpdateRepoAction::run([...$npm, 'ci', '--ignore-scripts', '--no-audit', '--no-fund'], $repoPath, 1800, $onProgress, implode(' ', [...$npm, 'ci', '--ignore-scripts']));
        if (! $ci->isSuccessful()) {
            return UpdateRepoAction::fail($repoPath, $branch, 'npm ci', $ci);
        }

        $scripts = (array) (json_decode((string) @file_get_contents($repoPath.'/package.json'), true)['scripts'] ?? []);
        if (isset($scripts['build'])) {
            $build = UpdateRepoAction::run([...$npm, 'run', 'build'], $repoPath, 1800, $onProgress, implode(' ', [...$npm, 'run', 'build']));
            if (! $build->isSuccessful()) {
                return UpdateRepoAction::fail($repoPath, $branch, 'npm run build (with --ignore-scripts installs)', $build);
            }
        }

        $gate = [
            'testsRan' => false, 'testsFailed' => 0, 'testsBroken' => false, 'testsSummary' => null, 'testsLogPath' => null,
            'crawlerRan' => false, 'crawlerFailed' => false, 'crawlerLogPath' => null, 'crawlerServerErrorUrls' => [],
        ];

        if ($useDdev && is_file($repoPath.'/.ddev/commands/web/browser-test')) {
            $tests = UpdateRepoAction::run(['ddev', 'browser-test'], $repoPath, 1800, $onProgress, 'ddev browser-test', usePty: Process::isPtySupported());
            $summary = UpdateRepoAction::parseTestSummary($tests->getOutput()."\n".$tests->getErrorOutput());
            $gate['testsRan'] = true;
            $gate['testsFailed'] = $summary['failed'] ?? 0;
            $gate['testsBroken'] = ! $tests->isSuccessful();
            $gate['testsSummary'] = $summary['summary'] ?? ($tests->isSuccessful() ? null : 'browser-test exited '.$tests->getExitCode());
            if (! $tests->isSuccessful()) {
                $gate['testsLogPath'] = UpdateRepoAction::writeLog($repoPath, 'browser-test', $tests);
            }
        }

        if ($useDdev && $crawlerCommandLine !== null && $crawlerCommandLine !== '') {
            $crawler = UpdateRepoAction::run($crawlerCommandLine, $repoPath, 3600, $onProgress, $crawlerCommandLine);
            $gate['crawlerRan'] = true;
            $gate['crawlerFailed'] = ! $crawler->isSuccessful();
            $gate['crawlerServerErrorUrls'] = UpdateRepoAction::parseCrawlerServerErrors($crawler->getOutput()."\n".$crawler->getErrorOutput());
            if ($gate['crawlerFailed'] || $gate['crawlerServerErrorUrls'] !== []) {
                $gate['crawlerLogPath'] = UpdateRepoAction::writeLog($repoPath, 'site-crawler', $crawler);
            }
        }

        return $gate;
    }

    /**
     * Decide per endpoint package what this run does. Only installed versions
     * below the fix on the fix's own caret line get updated. A fix on a later
     * line is a major update, and a package Panoptikum can't fix is only
     * reported.
     *
     * @param  list<array<string, mixed>>  $packages
     * @param  array<string, list<string>>  $lockVersions
     * @param  array<string, true>  $direct
     * @return array{update: array<string, string>, notes: list<string>}
     */
    public static function planUpdates(array $packages, array $lockVersions, array $direct): array
    {
        $update = [];
        $notes = [];

        foreach ($packages as $package) {
            $name = strtolower((string) ($package['name'] ?? ''));
            $fixed = $package['fixed_version'] ?? null;
            if ($name === '') {
                continue;
            }

            if (! ($package['fixable'] ?? false) || ! is_string($fixed) || $fixed === '') {
                $notes[] = sprintf('%s: no fix to apply (%s)', $name, $package['blocked_reason'] ?? 'not fixable');

                continue;
            }

            $installed = $lockVersions[$name] ?? [];
            if ($installed === []) {
                $notes[] = "{$name}: not in the local package-lock.json";

                continue;
            }

            $below = self::belowFix($installed, $fixed);
            $sameLine = self::belowFixOnSameLine($installed, $fixed);
            $otherLine = array_values(array_diff($below, $sameLine));

            if ($otherLine !== []) {
                $notes[] = sprintf('%s %s needs a major update to %s, skipped', $name, implode(', ', $otherLine), $fixed);
            }
            if ($sameLine !== []) {
                $update[$name] = $fixed;
            }
        }

        return ['update' => $update, 'notes' => $notes];
    }

    /**
     * The release line a caret range stays on: the major version, or the
     * minor version below 1.0 (`^0.3.1` allows 0.3.x only).
     */
    public static function caretLine(string $version): string
    {
        $parts = explode('.', ltrim($version, 'v'));

        return $parts[0] === '0' ? '0.'.($parts[1] ?? '0') : $parts[0];
    }

    /**
     * @param  list<string>  $versions
     * @return list<string>
     */
    public static function belowFix(array $versions, string $fixed): array
    {
        return array_values(array_filter($versions, fn (string $v) => version_compare($v, $fixed, '<')));
    }

    /**
     * @param  list<string>  $versions
     * @return list<string>
     */
    public static function belowFixOnSameLine(array $versions, string $fixed): array
    {
        return array_values(array_filter(self::belowFix($versions, $fixed), fn (string $v) => self::caretLine($v) === self::caretLine($fixed)));
    }

    public static function isBlocked(string $version, string $from, ?string $to): bool
    {
        return version_compare($version, $from, '>=') && ($to === null || $to === '' || version_compare($version, $to, '<='));
    }

    /**
     * Every installed version per package name, from a lockfile v2 or v3.
     * Nested installs (`node_modules/a/node_modules/b`) count for `b`.
     *
     * shortcut: names are lowercased to match Panoptikum, so a legacy
     * mixed-case package name won't update; handle it if one ever shows up.
     *
     * @param  array<string, mixed>  $lock
     * @return array<string, list<string>>
     */
    public static function lockVersions(array $lock): array
    {
        $versions = [];
        foreach ((array) ($lock['packages'] ?? []) as $path => $entry) {
            $position = strrpos((string) $path, 'node_modules/');
            if ($position === false || ! is_array($entry) || ! isset($entry['version']) || ($entry['link'] ?? false)) {
                continue;
            }
            $name = strtolower(substr((string) $path, $position + strlen('node_modules/')));
            $versions[$name][] = (string) $entry['version'];
        }

        foreach ($versions as $name => $list) {
            $list = array_values(array_unique($list));
            usort($list, 'version_compare');
            $versions[$name] = $list;
        }
        ksort($versions);

        return $versions;
    }

    /**
     * @param  array<string, list<string>>  $before
     * @param  array<string, list<string>>  $after
     * @return list<array{name: string, from: string, to: string, added: list<string>}>
     */
    public static function diffVersions(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $name) {
            $old = $before[$name] ?? [];
            $new = $after[$name] ?? [];
            $removed = array_values(array_diff($old, $new));
            $added = array_values(array_diff($new, $old));
            if ($removed === [] && $added === []) {
                continue;
            }
            $changes[] = [
                'name' => (string) $name,
                'from' => $removed === [] ? '(new)' : implode(', ', $removed),
                'to' => $added === [] ? '(removed)' : implode(', ', $added),
                'added' => $added,
            ];
        }
        usort($changes, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $changes;
    }

    /**
     * @param  list<array{name: string, added: list<string>}>  $changes
     * @param  list<array<string, mixed>>  $blockedVersions
     * @return list<array{package: string, version: string, ticket: string}>
     */
    public static function blockedHits(array $changes, array $blockedVersions): array
    {
        $hits = [];
        foreach ($changes as $change) {
            foreach ($blockedVersions as $block) {
                if (strtolower((string) ($block['package'] ?? '')) !== $change['name']) {
                    continue;
                }
                foreach ($change['added'] as $version) {
                    if (self::isBlocked($version, (string) $block['affected_from'], $block['affected_to'] ?? null)) {
                        $hits[] = ['package' => $change['name'], 'version' => $version, 'ticket' => (string) ($block['ticket'] ?? '')];
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $packageJson
     * @return array<string, true>
     */
    public static function directDependencies(array $packageJson): array
    {
        $names = [];
        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $key) {
            foreach (array_keys((array) ($packageJson[$key] ?? [])) as $name) {
                $names[strtolower((string) $name)] = true;
            }
        }

        return $names;
    }

    /**
     * The direct dependencies that pull in each package: the package itself
     * when it's direct, otherwise the direct packages above it in the tree.
     *
     * @param  list<string>  $packages
     * @param  array<string, mixed>  $lock
     * @param  array<string, true>  $direct
     * @return array<string, list<string>> direct package => the changed packages it stands for
     */
    public static function directParentsOf(array $packages, array $lock, array $direct): array
    {
        $parentsOf = [];
        foreach ((array) ($lock['packages'] ?? []) as $path => $entry) {
            $position = strrpos((string) $path, 'node_modules/');
            if ($position === false || ! is_array($entry)) {
                continue;
            }
            $owner = strtolower(substr((string) $path, $position + strlen('node_modules/')));
            foreach (['dependencies', 'optionalDependencies', 'peerDependencies'] as $key) {
                foreach (array_keys((array) ($entry[$key] ?? [])) as $dependency) {
                    $parentsOf[strtolower((string) $dependency)][$owner] = true;
                }
            }
        }

        $result = [];
        foreach ($packages as $package) {
            $queue = [$package];
            $seen = [];
            while ($queue !== []) {
                $name = array_shift($queue);
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                if (isset($direct[$name])) {
                    $result[$name][] = $package;

                    continue;
                }
                foreach (array_keys($parentsOf[$name] ?? []) as $parent) {
                    $queue[] = $parent;
                }
            }
        }

        return array_map(fn (array $list) => array_values(array_unique($list)), $result);
    }

    /**
     * Source files that import one of the given direct packages, as
     * "file => the changed packages behind it". A transitive package is
     * listed under the files that import its direct parent.
     *
     * @param  array<string, list<string>>  $directParents  From directParentsOf().
     * @return array<string, list<string>>
     */
    public static function importingFiles(string $repoPath, array $directParents): array
    {
        if ($directParents === []) {
            return [];
        }

        $alternatives = implode('|', array_map(fn (string $name) => preg_quote($name, '#'), array_keys($directParents)));
        $pattern = '#(?:from\s*|import\s*\(?\s*|require\s*\(\s*)[\'"]('.$alternatives.')(?:/[^\'"]*)?[\'"]#';

        $files = [];
        foreach (self::ScreenDirs as $dir) {
            if (! is_dir($repoPath.'/'.$dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($repoPath.'/'.$dir, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $file) => ! in_array($file->getFilename(), ['node_modules', 'vendor', 'build', 'dist', '.nuxt', '.output'], true),
            ));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), self::ScreenExtensions, true)) {
                    continue;
                }
                if (! preg_match_all($pattern, (string) @file_get_contents($file->getPathname()), $matches)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(rtrim($repoPath, '/')) + 1);
                $changed = [];
                foreach (array_unique($matches[1]) as $direct) {
                    array_push($changed, ...$directParents[$direct]);
                }
                $files[$relative] = array_values(array_unique($changed));
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * A link to what changed between two releases: the GitHub compare view
     * when the package's repository is on GitHub, otherwise its npm page.
     *
     * shortcut: assumes tags named `v<version>`, the npm convention; a repo
     * that tags differently gets a dead compare link, then read its releases.
     */
    public static function compareLink(?string $repositoryUrl, string $name, string $from, string $to): string
    {
        if ($repositoryUrl !== null && preg_match('~github\.com[/:]([^/]+)/([^/#]+?)(?:\.git)?(?:[/#].*)?$~', $repositoryUrl, $m)) {
            return "https://github.com/{$m[1]}/{$m[2]}/compare/v{$from}...v{$to}";
        }

        return "https://www.npmjs.com/package/{$name}?activeTab=versions";
    }

    /**
     * @param  list<array{name: string, from: string, to: string}>  $changes
     * @param  array<string, array<string, mixed>>  $planned  Endpoint packages by name.
     * @param  array<string, string>  $overrides
     * @param  array<string, list<string>>  $screens
     * @param  array<string, string>  $links
     */
    public static function commitBody(array $changes, array $planned, array $overrides, array $screens, array $links): string
    {
        $lines = [];
        foreach ($changes as $change) {
            $line = "- {$change['name']} {$change['from']} -> {$change['to']}";
            $package = $planned[$change['name']] ?? null;
            if ($package !== null) {
                $details = array_filter([
                    (string) ($package['relevance'] ?? ''),
                    implode(', ', (array) ($package['advisories'] ?? [])),
                    isset($overrides[$change['name']]) ? 'override '.$overrides[$change['name']] : '',
                ]);
                $line .= ' ('.implode(', ', $details).')';
            }
            $lines[] = $line;
        }

        if ($screens !== []) {
            $lines[] = '';
            $lines[] = 'Screens to check:';
            foreach ($screens as $file => $packages) {
                $lines[] = "- {$file} (".implode(', ', $packages).')';
            }
        }

        if ($links !== []) {
            $lines[] = '';
            $lines[] = 'Release notes:';
            foreach ($links as $name => $link) {
                $lines[] = "- {$name}: {$link}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Add overrides to package.json without reformatting the rest: objects
     * stay objects and the file keeps its indentation and final newline.
     *
     * @param  array<string, string>  $overrides
     */
    public static function withOverrides(string $packageJson, array $overrides): string
    {
        $data = json_decode($packageJson);
        if (! $data instanceof \stdClass) {
            throw new \RuntimeException('package.json is not a JSON object');
        }
        if (! isset($data->overrides) || ! $data->overrides instanceof \stdClass) {
            $data->overrides = new \stdClass;
        }
        foreach ($overrides as $name => $range) {
            $data->overrides->{$name} = $range;
        }

        $indent = preg_match('/^([ \t]+)"/m', $packageJson, $m) ? $m[1] : '  ';
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = (string) preg_replace_callback('/^( {4})+/m', fn (array $s) => str_repeat($indent, intdiv(strlen($s[0]), 4)), $json);

        return $json.(str_ends_with($packageJson, "\n") ? "\n" : '');
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return array<string, array<string, mixed>>
     */
    private static function packagesByName(array $packages): array
    {
        $byName = [];
        foreach ($packages as $package) {
            $byName[strtolower((string) ($package['name'] ?? ''))] = $package;
        }

        return $byName;
    }

    /**
     * Compare links for the direct dependencies whose top-level version
     * changed. The repository URL comes from `npm view`.
     *
     * @param  list<string>  $npm
     * @param  list<array{name: string}>  $changes
     * @param  array<string, true>  $direct
     * @param  array<string, list<string>>  $before
     * @param  array<string, list<string>>  $after
     * @return array<string, string>
     */
    private static function releaseLinks(string $repoPath, array $npm, array $changes, array $direct, array $before, array $after): array
    {
        $links = [];
        foreach ($changes as $change) {
            $name = $change['name'];
            // A direct dependency has one top-level install; when nested copies
            // exist too, the highest version stands in for it.
            $from = ($before[$name] ?? []) === [] ? null : end($before[$name]);
            $to = ($after[$name] ?? []) === [] ? null : end($after[$name]);
            if (! isset($direct[$name]) || $from === null || $to === null || $from === $to) {
                continue;
            }
            $view = UpdateRepoAction::run([...$npm, 'view', $name, 'repository.url'], $repoPath, 60, null, '', stream: false);
            $url = trim($view->getOutput());
            $links[$name] = self::compareLink($view->isSuccessful() && $url !== '' ? $url : null, $name, $from, $to);
        }

        return $links;
    }
}
