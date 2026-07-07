<?php

namespace App\Actions;

use App\DataTransferObjects\RepoUpdateResult;
use Symfony\Component\Process\Process;

final class UpdateRepoAction
{
    private const BranchCandidates = ['develop', 'dev', 'staging', 'stag', 'stage', 'main', 'master', 'prod', 'live'];

    /**
     * The long-lived branches grouped into precedence tiers, lowest first. A
     * branch is "higher" than the checked-out one when it lives in a later
     * tier. Synonyms share a tier (develop/dev, staging/stag/stage, main/master,
     * prod/live) so a second name at the same level is never mistaken for a
     * higher branch. Flattened, this must stay in step with BranchCandidates.
     *
     * @var list<list<string>>
     */
    private const BranchTiers = [
        ['develop', 'dev'],
        ['staging', 'stag', 'stage'],
        ['main', 'master'],
        ['prod', 'live'],
    ];

    /**
     * @param  string  $package         The target package whose version we track in the result.
     * @param  callable(string $step, ?string $type, ?string $chunk): void|null  $onProgress
     *         Called with ('step-start', null, label) before each step, and
     *         (label, 'out'|'err', chunk) for each output chunk.
     * @param  bool  $withAllDependencies  Pass -W to composer.
     * @param  string|null  $updatePackage  The package composer should actually `update`
     *                                      (defaults to $package). Override with a parent
     *                                      package when its constraint blocks $package from
     *                                      reaching the desired version.
     * @param  string|null  $craftCommandLine  When set, the update step runs this shell command
     *                                          (e.g. `ddev craft update commerce --interactive=0`)
     *                                          instead of `ddev composer update`. $package is still
     *                                          used to track the locked version before/after.
     * @param  string|null  $crawlerCommandLine  When non-null, runs this shell command from the
     *                                            repo after `composer prep`. A crawler failure
     *                                            does NOT mark the repo as failed; it surfaces
     *                                            via the crawlerFailed/crawlerLogPath fields.
     * @param  bool  $commit  When true (and craft prints a "Performing N updates:" list, or in
     *                        remove mode), the action stages everything and commits with a title
     *                        that reflects what happened ("Package updates" / "Remove <pkg>").
     * @param  bool  $push  When true, the resulting commit is pushed to its branch — but only when
     *                      the run produced a commit AND no errors occurred (no failing tests, no
     *                      PHPStan errors, no site-crawler failure or 5xx responses). Has no effect
     *                      unless $commit is true.
     * @param  list<array{name: string, dev: bool}>|null  $removeSpec  When set, the action runs
     *                                                                  `composer remove [--dev] <pkgs>`
     *                                                                  (grouped by the dev flag) instead
     *                                                                  of `composer update`. The commit
     *                                                                  message becomes "Remove <pkg>" or
     *                                                                  "Remove N packages" with a body
     *                                                                  listing the removed names.
     * @param  list<string>|null  $sweepPatterns  Optional fnmatch patterns (e.g. `webhubworks/*`).
     *                                            Only honoured for craft-mode runs. After craft
     *                                            update, `composer outdated --format=json` is parsed
     *                                            and any installed package whose name matches a
     *                                            pattern gets `composer update <pkg> -W`. If any
     *                                            package was bumped, migrate/all + project-config/apply
     *                                            re-run so DB and config catch up.
     */
    public static function update(
        string $repoPath,
        string $package,
        ?callable $onProgress = null,
        bool $withAllDependencies = false,
        ?string $updatePackage = null,
        bool $keepDdevRunning = true,
        ?string $craftCommandLine = null,
        ?string $crawlerCommandLine = null,
        bool $commit = false,
        ?array $removeSpec = null,
        ?array $sweepPatterns = null,
        bool $push = false,
    ): RepoUpdateResult {
        $transcriptPath = self::openTranscript($repoPath);
        try {
            $result = self::doUpdate($repoPath, $package, $onProgress, $withAllDependencies, $updatePackage, $keepDdevRunning, $craftCommandLine, $crawlerCommandLine, $commit, $removeSpec, $sweepPatterns, $push);
        } finally {
            self::closeTranscript();
        }

        return $transcriptPath === null ? $result : self::attachTranscript($result, $transcriptPath);
    }

    private static function attachTranscript(RepoUpdateResult $result, string $path): RepoUpdateResult
    {
        $data = $result->toArray();
        $data['transcriptPath'] = $path;
        return RepoUpdateResult::fromArray($data);
    }

    private static function doUpdate(
        string $repoPath,
        string $package,
        ?callable $onProgress,
        bool $withAllDependencies,
        ?string $updatePackage,
        bool $keepDdevRunning,
        ?string $craftCommandLine,
        ?string $crawlerCommandLine,
        bool $commit,
        ?array $removeSpec = null,
        ?array $sweepPatterns = null,
        bool $push = false,
    ): RepoUpdateResult {
        $updatePackage = $updatePackage ?? $package;

        if ($removeSpec === null && $craftCommandLine === null && $updatePackage !== $package && self::lockedVersion($repoPath, $updatePackage) === null) {
            return RepoUpdateResult::skipped(
                $repoPath,
                "{$updatePackage} not present in composer.lock",
            );
        }
        $status = self::run(['git', 'status', '--porcelain'], $repoPath, 120, $onProgress, 'git status', stream: false);
        if (! $status->isSuccessful()) {
            return self::fail($repoPath, null, 'git status', $status);
        }

        if (trim($status->getOutput()) !== '') {
            return RepoUpdateResult::skipped($repoPath, 'uncommitted changes', hasUncommittedChanges: true);
        }

        $branch = self::pickBranch($repoPath);
        if ($branch === null) {
            return RepoUpdateResult::failed($repoPath, 'no develop/staging/main/master branch found');
        }

        $checkout = self::run(['git', 'checkout', $branch], $repoPath, 120, $onProgress, "git checkout {$branch}");
        if (! $checkout->isSuccessful()) {
            return self::fail($repoPath, $branch, "git checkout {$branch}", $checkout);
        }

        $pull = self::run(['git', 'pull', '--ff-only', 'origin', $branch], $repoPath, 600, $onProgress, "git pull --ff-only origin {$branch}");
        if (! $pull->isSuccessful()) {
            if (self::isMissingRemoteRef($pull)) {
                return self::diagnoseMissingRemoteBranch($repoPath, $branch, $pull, $onProgress);
            }

            return self::fail($repoPath, $branch, 'git pull', $pull);
        }

        $aheadFailure = self::guardAgainstHigherBranchAhead($repoPath, $branch, $onProgress);
        if ($aheadFailure !== null) {
            return $aheadFailure;
        }

        $detectedStatus = self::ddevStatus($repoPath);
        $ddevWasAlreadyRunning = $detectedStatus === 'running';

        if ($ddevWasAlreadyRunning) {
            if ($onProgress !== null) {
                $onProgress('step-start', null, 'ddev already running — skipping start');
            }
        } else {
            if ($onProgress !== null) {
                $onProgress('step-start', null, 'ddev status: ' . ($detectedStatus ?? 'unknown'));
            }
            $start = self::runDdevStart($repoPath, $onProgress);
            if (! $start->isSuccessful()) {
                return self::fail($repoPath, $branch, 'ddev start', $start);
            }
        }

        if ($craftCommandLine !== null) {
            // Always sync deps / migrations / project config before our craft
            // update layers more changes on top.
            $syncSteps = [
                [['ddev', 'composer', 'install'], 'ddev composer install'],
                [['ddev', 'php', 'craft', 'migrate/all'], 'ddev php craft migrate/all'],
                [['ddev', 'php', 'craft', 'project-config/apply'], 'ddev php craft project-config/apply'],
            ];
            foreach ($syncSteps as [$args, $label]) {
                $proc = self::run($args, $repoPath, 1800, $onProgress, $label);
                if (! $proc->isSuccessful()) {
                    return self::fail($repoPath, $branch, $label, $proc);
                }
            }
        }

        $previousVersion = self::lockedVersion($repoPath, $package);

        $packageStep = $removeSpec !== null
            ? self::runRemoveStep($repoPath, $branch, $removeSpec, $onProgress)
            : self::runUpdateStep($repoPath, $branch, $updatePackage, $withAllDependencies, $craftCommandLine, $onProgress);

        if ($packageStep instanceof RepoUpdateResult) {
            return $packageStep;
        }
        $packageUpdates = $packageStep;

        // Composer sweep: after `craft update` finishes, pick up updates Craft
        // doesn't know about (private/Repman plugins, transitive libs of Craft
        // plugins) and bump them via `composer update -W` + post-migrate.
        if ($craftCommandLine !== null && ! empty($sweepPatterns)) {
            $sweepStep = self::runComposerSweep($repoPath, $branch, $sweepPatterns, $onProgress);
            if ($sweepStep instanceof RepoUpdateResult) {
                return $sweepStep;
            }
            foreach ($sweepStep as $u) {
                $packageUpdates[] = $u;
            }
        }

        $prepRan = false;
        $testsFailed = null;
        $testsSummary = null;
        $phpstanErrors = null;
        $prepLogPath = null;
        $prepHadFailures = false;

        if (self::hasComposerScript($repoPath, 'prep')) {
            $prepRan = true;
            // Prep typically invokes phpstan/pest, which buffer their output
            // when they don't see a TTY — without a pty the streaming callback
            // gets nothing until the run finishes. setPty makes them flush
            // per line so the progress rows render live.
            $prep = self::run(
                ['ddev', 'composer', 'prep'],
                $repoPath,
                3600,
                $onProgress,
                'ddev composer prep',
                usePty: Process::isPtySupported(),
            );

            $outcome = self::summarizePrep($prep);
            $testsFailed = $outcome['testsFailed'];
            $testsSummary = $outcome['testsSummary'];
            $phpstanErrors = $outcome['phpstanErrors'];

            if ($outcome['hasFailures']) {
                $prepHadFailures = true;
                $prepLogPath = self::writeLog($repoPath, 'composer-prep', $prep);
                if ($testsSummary === null) {
                    $testsSummary = self::prepFailureSummary($outcome['prepStepFailures'], $prep);
                }
            }
        }

        $crawlerRan = false;
        $crawlerFailed = false;
        $crawlerLogPath = null;
        $crawlerServerErrorUrls = [];

        if ($crawlerCommandLine !== null && $crawlerCommandLine !== '') {
            $crawlerRan = true;
            $crawler = self::run($crawlerCommandLine, $repoPath, 3600, $onProgress, $crawlerCommandLine);

            $crawlerCombined = $crawler->getOutput() . "\n" . $crawler->getErrorOutput();
            $crawlerServerErrorUrls = self::parseCrawlerServerErrors($crawlerCombined);

            if (! $crawler->isSuccessful()) {
                $crawlerFailed = true;
            }
            if ($crawlerFailed || ! empty($crawlerServerErrorUrls)) {
                $crawlerLogPath = self::writeLog($repoPath, 'site-crawler', $crawler);
            }
        }

        if (! $keepDdevRunning && ! $ddevWasAlreadyRunning) {
            self::run(['ddev', 'stop'], $repoPath, 300, $onProgress, 'ddev stop');
        } elseif ($onProgress !== null) {
            $onProgress('step-start', null, $ddevWasAlreadyRunning
                ? 'leaving ddev running (was already running before this run)'
                : 'leaving ddev running');
        }

        $installedVersion = self::lockedVersion($repoPath, $package);

        $committed = false;
        if ($commit) {
            if ($removeSpec !== null) {
                $committed = self::commitRemovals($repoPath, $removeSpec, $onProgress);
            } else {
                $committed = self::commitPackageUpdates($repoPath, $packageUpdates, $onProgress);
            }
        }

        // Push only a clean run: a commit was actually produced and nothing in
        // the run flagged an error (failing tests, PHPStan errors, a crawler
        // failure or 5xx response). Anything broken stays local for review.
        $pushed = false;
        if ($push && $committed) {
            $runHadErrors = $prepHadFailures
                || ($testsFailed ?? 0) > 0
                || ($phpstanErrors ?? 0) > 0
                || $crawlerFailed
                || ! empty($crawlerServerErrorUrls);

            if (! $runHadErrors) {
                $pushed = self::pushBranch($repoPath, $branch, $onProgress);
            } elseif ($onProgress !== null) {
                $onProgress('step-start', null, 'skipping push — run reported errors');
            }
        }

        $afterStatus = self::run(['git', 'status', '--porcelain'], $repoPath, 60, null, '', stream: false);
        $hasUncommittedChanges = trim($afterStatus->getOutput()) !== '';

        return RepoUpdateResult::success(
            $repoPath,
            $branch,
            $hasUncommittedChanges,
            $previousVersion,
            $installedVersion,
            $prepRan,
            $testsFailed,
            $testsSummary,
            $phpstanErrors,
            $prepLogPath,
            $crawlerRan,
            $crawlerFailed,
            $crawlerLogPath,
            $crawlerServerErrorUrls,
            packageUpdates: $packageUpdates,
            committed: $committed,
            pushed: $pushed,
        );
    }

    /**
     * Run the composer/craft update step. Returns the parsed package-updates
     * list on success, or a failure RepoUpdateResult to short-circuit doUpdate.
     *
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     * @return list<array{name: string, from: string, to: string}>|RepoUpdateResult
     */
    private static function runUpdateStep(
        string $repoPath,
        string $branch,
        string $updatePackage,
        bool $withAllDependencies,
        ?string $craftCommandLine,
        ?callable $onProgress,
    ): array|RepoUpdateResult {
        if ($craftCommandLine !== null) {
            $updateCommand = $craftCommandLine;
            $updateLabel = $craftCommandLine;
            $failStep = 'ddev craft update';
        } else {
            $updateCommand = ['ddev', 'composer', 'update', $updatePackage, '--no-audit'];
            if ($withAllDependencies) {
                $updateCommand[] = '-W';
            }
            $updateLabel = 'ddev composer update ' . $updatePackage . ($withAllDependencies ? ' -W' : '');
            $failStep = 'ddev composer update';
        }

        $update = self::run($updateCommand, $repoPath, 1800, $onProgress, $updateLabel);
        if (! $update->isSuccessful()) {
            return self::fail($repoPath, $branch, $failStep, $update);
        }

        $combined = $update->getOutput() . "\n" . $update->getErrorOutput();

        return $craftCommandLine !== null
            ? self::parseCraftUpdates($combined)
            : self::parseComposerUpdates($combined);
    }

    /**
     * Run `composer remove [--dev] <pkgs>` once per dev-mode group. Returns an
     * empty package-updates list on success (the commit message lists the
     * removed names, not version diffs), or a failure RepoUpdateResult.
     *
     * @param  list<array{name: string, dev: bool}>  $removeSpec
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     * @return list<array{name: string, from: string, to: string}>|RepoUpdateResult
     */
    private static function runRemoveStep(
        string $repoPath,
        string $branch,
        array $removeSpec,
        ?callable $onProgress,
    ): array|RepoUpdateResult {
        foreach (self::groupRemoveSpec($removeSpec) as [$dev, $names]) {
            $cmd = ['ddev', 'composer', 'remove', '--no-audit', '--no-interaction'];
            if ($dev) {
                $cmd[] = '--dev';
            }
            foreach ($names as $n) {
                $cmd[] = $n;
            }
            $label = 'ddev composer remove' . ($dev ? ' --dev' : '') . ' ' . implode(' ', $names);
            $proc = self::run($cmd, $repoPath, 1800, $onProgress, $label);
            if (! $proc->isSuccessful()) {
                return self::fail($repoPath, $branch, 'ddev composer remove', $proc);
            }
        }

        return [];
    }

    /**
     * Run `composer outdated --format=json`, filter the result by the given
     * fnmatch patterns (e.g. `webhubworks/*`), then `composer update -W` the
     * matches in one call. When at least one package was bumped, re-run
     * `migrate/all` + `project-config/apply` so DB/config catch up with code.
     *
     * Returns the list of `{name, from, to, origin}` updates (origin=sweep) on
     * success, an empty list when nothing matched, or a failure
     * RepoUpdateResult to short-circuit doUpdate.
     *
     * @param  list<string>  $patterns
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     * @return list<array{name: string, from: string, to: string, origin: string}>|RepoUpdateResult
     */
    private static function runComposerSweep(
        string $repoPath,
        string $branch,
        array $patterns,
        ?callable $onProgress,
    ): array|RepoUpdateResult {
        $outdated = self::run(
            ['ddev', 'composer', 'outdated', '--format=json', '--no-interaction'],
            $repoPath,
            600,
            $onProgress,
            'ddev composer outdated (sweep)',
        );
        if (! $outdated->isSuccessful()) {
            return self::fail($repoPath, $branch, 'ddev composer outdated', $outdated);
        }

        $matches = self::filterOutdatedByPatterns($outdated->getOutput(), $patterns);
        if (empty($matches)) {
            return [];
        }

        $names = array_map(fn (array $m) => $m['name'], $matches);
        $updateCmd = ['ddev', 'composer', 'update', '--no-audit', '--no-interaction', '-W', ...$names];
        $update = self::run(
            $updateCmd,
            $repoPath,
            1800,
            $onProgress,
            'ddev composer update -W (sweep) ' . implode(' ', $names),
        );
        if (! $update->isSuccessful()) {
            return self::fail($repoPath, $branch, 'ddev composer update (sweep)', $update);
        }

        $postSteps = [
            [['ddev', 'php', 'craft', 'migrate/all'], 'ddev php craft migrate/all (post-sweep)'],
            [['ddev', 'php', 'craft', 'project-config/apply'], 'ddev php craft project-config/apply (post-sweep)'],
        ];
        foreach ($postSteps as [$args, $stepLabel]) {
            $proc = self::run($args, $repoPath, 1800, $onProgress, $stepLabel);
            if (! $proc->isSuccessful()) {
                return self::fail($repoPath, $branch, $stepLabel, $proc);
            }
        }

        return $matches;
    }

    /**
     * Parse `composer outdated --format=json` output and return the entries
     * whose name matches any of the given fnmatch patterns. Composer's
     * `outdated` output already excludes up-to-date packages, so the caller
     * doesn't need to version-compare.
     *
     * @param  list<string>  $patterns
     * @return list<array{name: string, from: string, to: string, origin: string}>
     */
    public static function filterOutdatedByPatterns(string $jsonOutput, array $patterns): array
    {
        $data = json_decode($jsonOutput, true);
        if (! is_array($data)) {
            return [];
        }
        $installed = $data['installed'] ?? [];
        if (! is_array($installed)) {
            return [];
        }

        $matches = [];
        foreach ($installed as $pkg) {
            if (! is_array($pkg)) {
                continue;
            }
            $name = (string) ($pkg['name'] ?? '');
            $version = (string) ($pkg['version'] ?? '');
            $latest = (string) ($pkg['latest'] ?? '');
            if ($name === '' || $version === '' || $latest === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $name)) {
                    $matches[] = [
                        'name' => $name,
                        'from' => $version,
                        'to' => $latest,
                        'origin' => 'sweep',
                    ];
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Stage + commit every working-tree change with title "Package updates"
     * and a body listing each parsed name/from/to update. The list comes from
     * parseCraftUpdates (craft path) or parseComposerUpdates (composer path).
     *
     * The start-of-run dirty check guarantees the working tree was clean when
     * we began, so every change here originated from this run (ddev side
     * effects, craft/composer update, composer prep). We commit all of it,
     * even when the parsed update list is empty — otherwise an unparseable
     * header (a craft variant the regex doesn't recognise, an unexpected
     * composer output shape) leaves the repo "dirty" for no real reason.
     * Returns true on a successful commit, false on any failure (non-fatal
     * — repo just stays dirty for manual review).
     *
     * @param  list<array{name: string, from: string, to: string}>  $updates
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     */
    private static function commitPackageUpdates(string $repoPath, array $updates, ?callable $onProgress): bool
    {
        $status = self::run(['git', 'status', '--porcelain'], $repoPath, 60, null, '', stream: false);
        if (! $status->isSuccessful() || trim($status->getOutput()) === '') {
            return false;
        }

        $add = self::run(['git', 'add', '-A'], $repoPath, 120, $onProgress, 'git add -A');
        if (! $add->isSuccessful()) {
            return false;
        }

        $body = empty($updates)
            ? '(no update list parsed - see diff for details)'
            : implode("\n", array_map(
                fn (array $u) => sprintf(
                    '- %s %s => %s%s',
                    $u['name'],
                    $u['from'],
                    $u['to'],
                    ($u['origin'] ?? 'craft') === 'sweep' ? '  (via composer sweep)' : '',
                ),
                $updates,
            ));

        $commit = self::run(
            ['git', 'commit', '-m', 'Package updates', '-m', $body],
            $repoPath,
            120,
            $onProgress,
            'git commit',
        );

        return $commit->isSuccessful();
    }

    /**
     * Push the current branch to origin. Called only after a successful commit
     * on an error-free run. Returns true when the push succeeds; false on any
     * failure (non-fatal — the commit stays local for a manual push).
     *
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     */
    private static function pushBranch(string $repoPath, string $branch, ?callable $onProgress): bool
    {
        $push = self::run(
            ['git', 'push', 'origin', $branch],
            $repoPath,
            600,
            $onProgress,
            "git push origin {$branch}",
        );

        return $push->isSuccessful();
    }

    /**
     * Stage + commit a `composer remove` change with title "Remove <pkg>"
     * (or "Remove N packages" for multiple) and a bullet body listing each
     * removed name. Returns true on a successful commit; false when there
     * is nothing to commit or git fails.
     *
     * @param  list<array{name: string, dev: bool}>  $removeSpec
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     */
    private static function commitRemovals(string $repoPath, array $removeSpec, ?callable $onProgress): bool
    {
        $status = self::run(['git', 'status', '--porcelain'], $repoPath, 60, null, '', stream: false);
        if (! $status->isSuccessful() || trim($status->getOutput()) === '') {
            return false;
        }

        $add = self::run(['git', 'add', '-A'], $repoPath, 120, $onProgress, 'git add -A');
        if (! $add->isSuccessful()) {
            return false;
        }

        $names = array_map(fn (array $p) => $p['name'], $removeSpec);
        $title = count($names) === 1
            ? "Remove {$names[0]}"
            : sprintf('Remove %d packages', count($names));
        $body = implode("\n", array_map(
            fn (array $p) => '- ' . $p['name'] . ($p['dev'] ? ' (dev)' : ''),
            $removeSpec,
        ));

        $commit = self::run(
            ['git', 'commit', '-m', $title, '-m', $body],
            $repoPath,
            120,
            $onProgress,
            'git commit',
        );

        return $commit->isSuccessful();
    }

    /**
     * Group a remove spec into [dev, [names...]] pairs so composer can be
     * called once for each --dev mode. Prod packages come first so the
     * working tree shows them before dev removals when the commit lands.
     *
     * @param  list<array{name: string, dev: bool}>  $spec
     * @return list<array{0: bool, 1: list<string>}>
     */
    private static function groupRemoveSpec(array $spec): array
    {
        $prod = [];
        $dev = [];
        foreach ($spec as $entry) {
            if ($entry['dev']) {
                $dev[] = $entry['name'];
            } else {
                $prod[] = $entry['name'];
            }
        }

        $groups = [];
        if (! empty($prod)) {
            $groups[] = [false, $prod];
        }
        if (! empty($dev)) {
            $groups[] = [true, $dev];
        }

        return $groups;
    }

    /**
     * Parses Craft's `Performing N updates:` block from `php craft update`
     * output and returns the listed packages.
     *
     * The block looks like:
     *
     *     Performing 3 updates:
     *
     *         - craft 5.9.22 => 5.10.1
     *         - ckeditor 4.11.3 => 5.5.0
     *         - typografy 5.0.2 => 5.0.3
     *
     * We only collect lines inside that block — composer's own
     * `Upgrading vendor/pkg (a => b)` lines (with parentheses) appear later
     * and are deliberately ignored.
     *
     * @return list<array{name: string, from: string, to: string}>
     */
    public static function parseCraftUpdates(string $output): array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $lines = preg_split("/\r\n|\r|\n/", $stripped) ?: [];

        $updates = [];
        $inBlock = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // The count may be a digit ("Performing 3 updates:") or an English
            // word ("Performing one update:") — accept either.
            if (preg_match('/^Performing\s+\S+\s+updates?:\s*$/i', $trimmed)) {
                $inBlock = true;
                continue;
            }
            if (! $inBlock) {
                continue;
            }

            if ($trimmed === '') {
                if (! empty($updates)) {
                    break;
                }
                continue;
            }

            if (preg_match('/^-\s+(\S+)\s+(\S+)\s+=>\s+(\S+)\s*$/', $trimmed, $m)) {
                $updates[] = ['name' => $m[1], 'from' => $m[2], 'to' => $m[3]];
                continue;
            }

            // Non-blank, non-matching line ends the block.
            break;
        }

        return $updates;
    }

    /**
     * Parses composer's update list from `composer update` output, reading only
     * the `  - Upgrading vendor/pkg (a => b)` / `Downgrading` lines inside the
     * "Lock file operations" block.
     *
     * That block is the one — and only — thing that reflects what actually
     * changed in composer.lock, which is exactly what the commit captures. The
     * later "Package operations" block (composer installing/upgrading the
     * vendor tree from the lock) is deliberately ignored: when a repo's
     * composer.lock is already ahead of its installed vendor dir — e.g. right
     * after the `git pull` at the start of a run — that block lists every
     * package whose *installed* version moves to catch up to the lock, which
     * balloons into dozens of unrelated packages that the commit never touches.
     *
     * Newly-installed packages (no `=>`) are skipped because the "from => to"
     * shape is what the commit body expects.
     *
     * @return list<array{name: string, from: string, to: string}>
     */
    public static function parseComposerUpdates(string $output): array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $lines = preg_split("/\r\n|\r|\n/", $stripped) ?: [];

        $sawLockHeader = false;
        $inLockSection = false;
        $updates = [];
        $seen = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^Lock file operations:/i', $trimmed)) {
                $sawLockHeader = true;
                $inLockSection = true;
                continue;
            }

            // The lock block ends as soon as composer writes the lock or starts
            // installing/upgrading the vendor tree from it.
            if ($inLockSection && preg_match('/^(Writing lock file|Package operations:|Installing dependencies)/i', $trimmed)) {
                $inLockSection = false;
                continue;
            }

            if (! $inLockSection) {
                continue;
            }

            if (! preg_match('/^-\s+(?:Upgrading|Downgrading)\s+(\S+)\s+\(([^)]+?)\s+=>\s+([^)]+?)\)/', $trimmed, $m)) {
                continue;
            }
            $name = $m[1];
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $updates[] = ['name' => $name, 'from' => trim($m[2]), 'to' => trim($m[3])];
        }

        // Fallback for output without a "Lock file operations" header (very old
        // or unusual composer): parse every Upgrading/Downgrading line, deduped.
        if (! $sawLockHeader) {
            foreach ($lines as $line) {
                if (! preg_match('/^-\s+(?:Upgrading|Downgrading)\s+(\S+)\s+\(([^)]+?)\s+=>\s+([^)]+?)\)/', trim($line), $m)) {
                    continue;
                }
                if (isset($seen[$m[1]])) {
                    continue;
                }
                $seen[$m[1]] = true;
                $updates[] = ['name' => $m[1], 'from' => trim($m[2]), 'to' => trim($m[3])];
            }
        }

        return $updates;
    }

    public static function hasComposerScript(string $repoPath, string $scriptName): bool
    {
        $composerJson = $repoPath . '/composer.json';
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

        return isset($data['scripts'][$scriptName]);
    }

    /**
     * Parses a Pest- or PHPUnit-style test summary from prep output.
     *
     * Handles:
     *   Pest:           "Tests:    3 todos, 1 skipped, 374 passed (2161 assertions)"
     *   Pest failures:  "Tests:    2 failed, 374 passed (2161 assertions)"
     *   PHPUnit fail:   "Tests: 774, Assertions: 3434, Errors: 0, Failures: 1, Skipped: 6."
     *   PHPUnit pass:   "OK (774 tests, 3434 assertions)"
     *
     * @return array{failed: int, summary: string}|null
     */
    public static function parseTestSummary(string $output): ?array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $lines = preg_split("/\r\n|\r|\n/", $stripped);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/^Tests:\s+(.+)$/', $line, $m)) {
                $summary = rtrim(trim($m[1]), '.');
                $pestFailed = preg_match('/(\d+)\s+failed/i', $summary, $f) ? (int) $f[1] : 0;
                $phpunitFailures = preg_match('/Failures?:\s*(\d+)/i', $summary, $f2) ? (int) $f2[1] : 0;
                $phpunitErrors = preg_match('/Errors?:\s*(\d+)/i', $summary, $e) ? (int) $e[1] : 0;

                return [
                    'failed' => $pestFailed + $phpunitFailures + $phpunitErrors,
                    'summary' => $summary,
                ];
            }
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/^OK\s+\(.*?\)$/i', $line)) {
                return ['failed' => 0, 'summary' => $line];
            }
        }

        return null;
    }

    /**
     * Extracts the individual failing tests from Pest prep output.
     *
     * Pest prints one block per failure:
     *   FAILED  Tests\Feature\PriceRangeFilterTest > it filters products ...
     *   Failed asserting that actual size 2 matches expected size 1.
     *   at tests/Feature/PriceRangeFilterTest.php:77
     *
     * The "FAILED" line carries the test class + description; the following
     * "at <file>:<line>" line carries the location. We pair them so the user
     * sees exactly which test in which file broke, without opening the log.
     *
     * @return list<array{name: string, at: ?string}>
     */
    public static function parseFailedTests(string $output): array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $lines = preg_split("/\r\n|\r|\n/", $stripped) ?: [];

        $failures = [];
        $current = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^FAILED\s+(.+)$/', $trimmed, $m)) {
                if ($current !== null) {
                    $failures[] = $current;
                }
                $current = ['name' => trim($m[1]), 'at' => null];

                continue;
            }

            if ($current !== null && $current['at'] === null && preg_match('/^at\s+(\S+:\d+)$/', $trimmed, $m)) {
                $current['at'] = $m[1];
            }
        }

        if ($current !== null) {
            $failures[] = $current;
        }

        return $failures;
    }

    /**
     * Detects prep steps that crashed even though the overall `composer prep`
     * process exited 0. webhub's prep script runs each step and continues past
     * a failure, printing a marker like:
     *
     *   > Running: php -d memory_limit=-1 artisan test --parallel ...
     *   ... fatal error output ...
     *     (Command exited with code 1, continuing...)
     *
     * Without this, a step that aborts before producing a parseable summary
     * (e.g. paratest dying on a container binding, phpstan rejecting its
     * config) is invisible to the test/phpstan parsers and to the exit code —
     * pu would report "no test summary detected" and treat the run as clean.
     * We pair each "continuing" marker with the most recent "> Running:"
     * command and pull a short error excerpt from the lines in between.
     *
     * @return list<array{command: string, error: ?string}>
     */
    public static function parsePrepStepFailures(string $output): array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $lines = preg_split("/\r\n|\r|\n/", $stripped) ?: [];

        $failures = [];
        $currentCommand = null;
        $block = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^>\s*Running:\s*(.+)$/', $trimmed, $m)) {
                $currentCommand = trim($m[1]);
                $block = [];

                continue;
            }

            if (preg_match('/^\(Command exited with code (\d+), continuing\.\.\.\)$/', $trimmed)) {
                $failures[] = [
                    'command' => $currentCommand ?? '(unknown step)',
                    'error' => self::prepStepError($block),
                ];
                $currentCommand = null;
                $block = [];

                continue;
            }

            if ($currentCommand !== null && $trimmed !== '') {
                $block[] = $trimmed;
            }
        }

        return $failures;
    }

    /**
     * Picks the most informative line from a crashed prep step's output. Symfony
     * Console renders fatal errors as an "In <File> line N:" header followed by
     * the message, so we prefer the message after that header; otherwise we fall
     * back to the first line that reads like an error, then to the first line.
     *
     * @param  list<string>  $block
     */
    private static function prepStepError(array $block): ?string
    {
        foreach ($block as $i => $line) {
            if (preg_match('/^In\s+\S+\s+line\s+\d+:/i', $line)) {
                for ($j = $i + 1; $j < count($block); $j++) {
                    if ($block[$j] !== '') {
                        return $block[$j];
                    }
                }
            }
        }

        $signalPatterns = [
            '/not instantiable/i',
            '/invalid configuration/i',
            '/\bexception\b/i',
            '/\bfatal\b/i',
            '/\berror\b/i',
            '/is not defined/i',
            '/could not/i',
            '/failed to/i',
            '/unable to/i',
        ];

        foreach ($block as $line) {
            foreach ($signalPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    return $line;
                }
            }
        }

        return $block[0] ?? null;
    }

    /**
     * Builds the one-line summary shown when prep produced no test summary but
     * still failed — naming the crashed step(s) and their error so the parallel
     * `update:all` table points straight at the cause instead of a vague "no
     * test summary detected".
     *
     * @param  list<array{command: string, error: ?string}>  $stepFailures
     */
    private static function prepFailureSummary(array $stepFailures, Process $prep): string
    {
        if ($stepFailures === []) {
            return $prep->isSuccessful()
                ? 'prep ran but no test summary detected'
                : 'prep exited non-zero (no test summary detected)';
        }

        if (count($stepFailures) === 1) {
            $f = $stepFailures[0];
            $cmd = self::shortenCommand($f['command']);

            return $f['error'] !== null
                ? "prep step failed: {$cmd} — {$f['error']}"
                : "prep step failed: {$cmd}";
        }

        $cmds = implode(', ', array_map(
            fn (array $f): string => self::shortenCommand($f['command']),
            $stepFailures,
        ));

        return count($stepFailures) . " prep steps failed: {$cmds}";
    }

    private static function shortenCommand(string $command, int $max = 60): string
    {
        $command = trim($command);

        return strlen($command) <= $max ? $command : rtrim(substr($command, 0, $max - 1)) . '…';
    }

    /**
     * Combines the test-summary and phpstan-error parsers into a single
     * verdict for `composer prep` output. `hasFailures` is true when either
     * parser found failures, when a prep step crashed but was swallowed by the
     * script's "continue on error" behaviour, or when the process itself exited
     * non-zero (covers prep scripts that swallow phpstan's non-zero exit, and
     * the inverse case where no parseable summary is present at all).
     *
     * @return array{testsFailed: ?int, testsSummary: ?string, failedTests: list<array{name: string, at: ?string}>, phpstanErrors: ?int, prepStepFailures: list<array{command: string, error: ?string}>, hasFailures: bool}
     */
    public static function summarizePrep(Process $prep): array
    {
        $combined = $prep->getOutput() . "\n" . $prep->getErrorOutput();
        $stats = self::parseTestSummary($combined);
        $phpstanErrors = self::parsePhpstanErrors($combined);
        $failedTests = self::parseFailedTests($combined);
        $prepStepFailures = self::parsePrepStepFailures($combined);

        $testsFailed = $stats['failed'] ?? null;
        $testsSummary = $stats['summary'] ?? null;

        $hasFailures = ($testsFailed !== null && $testsFailed > 0)
            || ($phpstanErrors !== null && $phpstanErrors > 0)
            || $prepStepFailures !== []
            || ! $prep->isSuccessful();

        return [
            'testsFailed' => $testsFailed,
            'testsSummary' => $testsSummary,
            'failedTests' => $failedTests,
            'phpstanErrors' => $phpstanErrors,
            'prepStepFailures' => $prepStepFailures,
            'hasFailures' => $hasFailures,
        ];
    }

    /**
     * Parses PHPStan's Symfony-block error summary from prep output.
     * Returns the error count when phpstan ran and reported errors;
     * returns null when no phpstan summary is detectable (phpstan didn't
     * run, or it ran clean — both indistinguishable here, and both mean
     * "nothing to warn about").
     *
     * Matches:
     *   "[ERROR] Found 9 errors"
     *   "[ERROR] Found 1 error"
     */
    public static function parsePhpstanErrors(string $output): ?int
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        if (preg_match('/\[ERROR\]\s+Found\s+(\d+)\s+errors?\b/i', $stripped, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Scan site-crawler output for the "Failed requests" table and return
     * the URLs whose status is 5xx (server errors). The crawler typically
     * exits 0 even when individual requests fail, so this is how we detect
     * regressions that the user needs to investigate.
     *
     * @return list<string>
     */
    public static function parseCrawlerServerErrors(string $output): array
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $lines = preg_split("/\r\n|\r|\n/", $stripped) ?: [];

        $urls = [];
        $inFailedTable = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^Failed requests:\s*$/i', $trimmed)) {
                $inFailedTable = true;
                continue;
            }
            if (! $inFailedTable) {
                continue;
            }

            // Borders / blanks: stay in the table mode but ignore the line.
            if ($trimmed === '' || ! str_starts_with($trimmed, '|')) {
                continue;
            }

            $cols = array_map('trim', explode('|', trim($trimmed, '|')));
            if (count($cols) < 2) {
                continue;
            }
            [$url, $status] = [$cols[0], $cols[1]];
            if (strcasecmp($status, 'Status') === 0) {
                continue;
            }
            if (preg_match('/^5\d\d$/', $status)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private static function lockedVersion(string $repoPath, string $package): ?string
    {
        $lock = $repoPath . '/composer.lock';
        if (! is_file($lock)) {
            return null;
        }

        $content = @file_get_contents($lock);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }

        foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $pkg) {
            if (($pkg['name'] ?? null) === $package) {
                return isset($pkg['version']) ? (string) $pkg['version'] : null;
            }
        }

        return null;
    }

    private static function ddevStatus(string $repoPath): ?string
    {
        $describe = self::run(['ddev', 'describe', '-j'], $repoPath, 30, null, '', stream: false);
        if (! $describe->isSuccessful()) {
            return null;
        }

        $data = json_decode($describe->getOutput(), true);
        $status = $data['raw']['status'] ?? null;

        return is_string($status) ? strtolower($status) : null;
    }

    private static function isMissingRemoteRef(Process $process): bool
    {
        return stripos(
            $process->getOutput() . "\n" . $process->getErrorOutput(),
            "couldn't find remote ref",
        ) !== false;
    }

    /**
     * The target branch no longer exists on origin - typically renamed or
     * deleted server-side, with a stale local tracking ref hiding the fact.
     * Prune so the local view matches reality, then fail with the list of
     * branches that DO exist so the user can check the right one out.
     *
     * We deliberately do not auto-switch to a similarly named branch: picking
     * a replacement by name is a guess, and silently moving the branch would
     * change which branch the package update gets committed to.
     */
    private static function diagnoseMissingRemoteBranch(string $repoPath, string $branch, Process $pull, ?callable $onProgress): RepoUpdateResult
    {
        // Non-destructive: --prune only drops stale remote-tracking refs
        // (local caches of server branches); it never touches local branches
        // or the working tree.
        self::run(['git', 'fetch', '--prune', 'origin'], $repoPath, 120, $onProgress, 'git fetch --prune origin', stream: false);

        // ls-remote queries the server directly, so it reflects the live
        // branches even if the tracking refs were stale a moment ago.
        $live = self::run(['git', 'ls-remote', '--heads', 'origin'], $repoPath, 120, null, '', stream: false);
        $branches = [];
        if ($live->isSuccessful()) {
            foreach (explode("\n", trim($live->getOutput())) as $line) {
                if (preg_match('#refs/heads/(.+)$#', trim($line), $m)) {
                    $branches[] = $m[1];
                }
            }
        }

        $logPath = self::writeLog($repoPath, 'git-pull', $pull);

        $message = "git pull failed: branch '{$branch}' no longer exists on origin (likely renamed or deleted).";
        $message .= $branches !== []
            ? ' Live branches: ' . implode(', ', $branches) . '. Check one out (`git checkout <branch>`) and re-run.'
            : ' Run `git fetch --prune` and check out a branch that still exists, then re-run.';

        if ($logPath !== null) {
            $message .= " (log: {$logPath})";
        }

        return RepoUpdateResult::failed($repoPath, $message, $branch, $logPath);
    }

    /**
     * After the checked-out lowest branch is up to date with its own origin,
     * guard against a repo where a HIGHER branch carries commits this branch
     * doesn't have — e.g. a teammate who commits (or pushes) straight to
     * main/master. Running the update on a branch that's behind would base the
     * commit on a stale tree and silently drop whatever already lives upstream,
     * so we abort and tell the user which branch is ahead and by how much.
     *
     * We fetch first so the remote-tracking refs reflect what teammates pushed,
     * then compare HEAD against both the local and origin ref of each higher
     * candidate (whichever is further ahead wins). Returns a failed
     * RepoUpdateResult when a higher branch is ahead, or null when the branch is
     * current with — or ahead of — every higher branch.
     *
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     */
    private static function guardAgainstHigherBranchAhead(string $repoPath, string $branch, ?callable $onProgress): ?RepoUpdateResult
    {
        $higher = self::higherBranchesFor($branch);
        if ($higher === []) {
            return null;
        }

        // Refresh remote-tracking refs so a branch pushed elsewhere (the common
        // "dev works on main" case) is visible. Best-effort: an offline or
        // failed fetch just means we compare against whatever refs we already
        // have — the earlier `git pull` proves origin is reachable anyway.
        self::run(['git', 'fetch', 'origin'], $repoPath, 300, $onProgress, 'git fetch origin', stream: false);

        $ahead = [];
        foreach ($higher as $candidate) {
            $count = self::commitsAhead($repoPath, $candidate);
            if ($count > 0) {
                $ahead[] = "{$candidate} (+{$count})";
            }
        }

        if ($ahead === []) {
            return null;
        }

        $message = sprintf(
            "aborted: '%s' is behind a higher branch — %s %s commits '%s' doesn't have. "
            . "A teammate likely worked on that branch directly. Merge it down into '%s' "
            . '(or run the update on that branch instead), then re-run.',
            $branch,
            implode(', ', $ahead),
            count($ahead) === 1 ? 'has' : 'have',
            $branch,
            $branch,
        );

        return RepoUpdateResult::failed($repoPath, $message, $branch);
    }

    /**
     * Given the checked-out branch, return every candidate branch name in a
     * strictly higher precedence tier (e.g. for `develop`: staging/stag/stage,
     * main/master, prod/live). Returns an empty list when the branch is already
     * the top tier or isn't a known long-lived branch.
     *
     * @return list<string>
     */
    public static function higherBranchesFor(string $branch): array
    {
        $tierIndex = null;
        foreach (self::BranchTiers as $i => $tier) {
            if (in_array($branch, $tier, true)) {
                $tierIndex = $i;
                break;
            }
        }

        if ($tierIndex === null) {
            return [];
        }

        $higher = [];
        foreach (self::BranchTiers as $i => $tier) {
            if ($i > $tierIndex) {
                foreach ($tier as $name) {
                    $higher[] = $name;
                }
            }
        }

        return $higher;
    }

    /**
     * Number of commits reachable from the given candidate branch but not from
     * HEAD — i.e. how far that branch is *ahead* of the checked-out branch. We
     * check both the local head and the origin remote-tracking ref and take the
     * larger count, so a branch that's ahead in either place is caught.
     * Non-existent refs and git failures contribute 0.
     */
    private static function commitsAhead(string $repoPath, string $candidate): int
    {
        $refs = ["refs/heads/{$candidate}", "refs/remotes/origin/{$candidate}"];

        $max = 0;
        foreach ($refs as $ref) {
            $exists = self::run(['git', 'rev-parse', '--verify', '--quiet', $ref], $repoPath, 30, null, '', stream: false);
            if (! $exists->isSuccessful()) {
                continue;
            }

            $revList = self::run(['git', 'rev-list', '--count', "HEAD..{$ref}"], $repoPath, 60, null, '', stream: false);
            if (! $revList->isSuccessful()) {
                continue;
            }

            $count = (int) trim($revList->getOutput());
            if ($count > $max) {
                $max = $count;
            }
        }

        return $max;
    }

    private static function pickBranch(string $repoPath): ?string
    {
        foreach (self::BranchCandidates as $candidate) {
            $local = self::run(['git', 'rev-parse', '--verify', '--quiet', "refs/heads/{$candidate}"], $repoPath, 30, null, '', stream: false);
            if ($local->isSuccessful()) {
                return $candidate;
            }

            $remote = self::run(['git', 'rev-parse', '--verify', '--quiet', "refs/remotes/origin/{$candidate}"], $repoPath, 30, null, '', stream: false);
            if ($remote->isSuccessful()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>|string  $command  An array of args, or a shell command line
     *                                        (the latter is run via Process::fromShellCommandline).
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     */
    private static function run(
        array|string $command,
        string $cwd,
        int $timeout = 120,
        ?callable $onProgress = null,
        string $label = '',
        bool $stream = true,
        bool $usePty = false,
    ): Process {
        self::transcriptStep($label, $command);

        $process = is_string($command)
            ? Process::fromShellCommandline($command, $cwd)
            : new Process($command, $cwd);
        $process->setTimeout($timeout);
        if ($usePty) {
            $process->setPty(true);
        }

        // Force git to be non-interactive. A repo with an HTTPS remote (or a key
        // that needs a passphrase) makes `git pull`/`git push` invoke a credential
        // helper that pops a GUI dialog. That helper inherits this Process's
        // stdout/stderr pipe, so even after git prints "fatal: User cancelled
        // dialog." and exits, the pipe never hits EOF and run() blocks until the
        // command timeout fires - hanging the whole (parallel) run. These vars
        // make git fail fast instead, surfacing as a normal error with a hint.
        $process->setEnv([
            'GIT_TERMINAL_PROMPT' => '0',                // no terminal credential/host-key prompt
            'GCM_INTERACTIVE' => 'never',                // Git Credential Manager: never show a GUI dialog
            'GIT_SSH_COMMAND' => 'ssh -oBatchMode=yes',  // SSH never prompts for a passphrase/host key
        ]);

        if ($stream) {
            // Stream chunks even when no $onProgress is set (e.g. inside
            // update:single subprocesses) so the sudo-prompt detector can
            // kill ddev before it hangs on a Password: prompt.
            if ($onProgress !== null && $label !== '') {
                $onProgress('step-start', null, $label);
            }
            $sudoDetected = false;
            $process->run(function (string $type, string $buffer) use ($onProgress, $label, $process, &$sudoDetected): void {
                self::transcriptAppend($type === Process::ERR ? "[stderr] {$buffer}" : $buffer);

                if ($onProgress !== null && $label !== '') {
                    $onProgress($label, $type === Process::ERR ? 'err' : 'out', $buffer);
                }

                if (! $sudoDetected && self::isSudoPrompt($buffer)) {
                    $sudoDetected = true;
                    // Kill ddev fast so the sudo prompt doesn't hang the run.
                    // The killed process surfaces as a failure; hintFor() turns
                    // the trigger phrase in the output into a user-facing hint.
                    @$process->stop(0.5);
                }
            });
        } else {
            $process->run();
            self::transcriptAppend($process->getOutput());
            if ($process->getErrorOutput() !== '') {
                self::transcriptAppend("[stderr] " . $process->getErrorOutput());
            }
        }

        self::transcriptExit($process->getExitCode());

        return $process;
    }

    private static function isSudoPrompt(string $chunk): bool
    {
        return stripos($chunk, 'needs to run with administrative privileges') !== false
            || stripos($chunk, 'may need to enter your password for sudo') !== false;
    }

    /**
     * Run `ddev start` with two safety nets:
     *   1. A host-wide flock so only one worker at a time is in the start
     *      window — protects the shared singletons (ddev-router, ddev-ssh-agent,
     *      docker network, host-port bindings) from concurrent reconfiguration.
     *   2. A retry for known-transient failures (router not ready, host port
     *      already allocated, docker timeouts). Between retries we `ddev stop`
     *      so the next attempt isn't fighting its own half-started container.
     *
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     */
    private static function runDdevStart(string $repoPath, ?callable $onProgress): Process
    {
        $maxAttempts = 3;
        $process = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $label = $attempt === 1
                ? 'ddev start'
                : "ddev start (retry {$attempt}/{$maxAttempts})";

            $process = self::withDdevStartLock(
                $onProgress,
                fn () => self::run(['ddev', 'start'], $repoPath, 900, $onProgress, $label),
            );

            if ($process->isSuccessful()) {
                return $process;
            }

            if ($attempt === $maxAttempts || ! self::isTransientDdevStartFailure($process)) {
                return $process;
            }

            if ($onProgress !== null) {
                $onProgress('step-start', null, 'ddev start hit a transient error - running `ddev stop` and retrying');
            }

            // Stop first so the retry isn't racing against its own half-started
            // containers. The stop result is intentionally ignored - if it
            // fails, the next start attempt will surface the real problem.
            self::run(['ddev', 'stop'], $repoPath, 120, $onProgress, 'ddev stop (cleanup before retry)');

            sleep(3);
        }

        return $process;
    }

    /**
     * Serialize the wrapped call across processes via flock on a host-wide
     * file. Other workers that hit the lock get a one-line `step-start` so the
     * spinner row doesn't silently sit idle.
     *
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     * @param  callable(): Process  $fn
     */
    private static function withDdevStartLock(?callable $onProgress, callable $fn): Process
    {
        $lockPath = sys_get_temp_dir() . '/package-updater-ddev-start.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return $fn();
        }

        try {
            if (! @flock($handle, LOCK_EX | LOCK_NB)) {
                if ($onProgress !== null) {
                    $onProgress('step-start', null, 'waiting for another worker to finish `ddev start`...');
                }
                @flock($handle, LOCK_EX);
            }
            return $fn();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Signatures that point at concurrency / first-start races rather than
     * structural problems (docker down, sudo, db mismatch, ...). Only these
     * should trigger a retry - everything else surfaces via hintFor() with a
     * user-actionable message.
     */
    private static function isTransientDdevStartFailure(Process $process): bool
    {
        $combined = $process->getOutput() . "\n" . $process->getErrorOutput();

        $signatures = [
            'port is already allocated',
            'bind: address already in use',
            'ddev-router failed to become ready',
            'cannot start service ddev-router',
            'context deadline exceeded',
        ];

        foreach ($signatures as $needle) {
            if (stripos($combined, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @var resource|null */
    private static $transcriptHandle = null;

    /**
     * Resolve a directory for this repo's logs. Prefer the repo's own
     * `storage/logs` (Laravel/Craft convention — keeps the log next to the code
     * that produced it, and is gitignored there, so it never lands in the
     * update commit). Fall back to a per-user dotdir when the repo isn't
     * Laravel/Craft shaped so we don't pollute its working tree. The optional
     * $sub is appended and created.
     */
    private static function logDir(string $repoPath, string $sub = ''): ?string
    {
        $repoLogs = $repoPath . '/storage/logs';
        if (is_dir($repoLogs) && is_writable($repoLogs)) {
            $base = $repoLogs;
        } else {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: null;
            if ($home === null) {
                return null;
            }
            $base = $home . '/.pu-update/logs';
        }

        $dir = $sub === '' ? $base : $base . '/' . $sub;
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        return $dir;
    }

    private static function openTranscript(string $repoPath): ?string
    {
        $dir = self::logDir($repoPath, 'transcripts');
        if ($dir === null) {
            return null;
        }
        $slug = preg_replace('/[^a-z0-9]+/i', '-', basename($repoPath));
        $file = $dir . '/' . trim((string) $slug, '-') . '-' . date('Ymd-His') . '.log';
        $handle = @fopen($file, 'w');
        if ($handle === false) {
            return null;
        }
        self::$transcriptHandle = $handle;
        @fwrite($handle, "# Repo: {$repoPath}\n# Started: " . date('c') . "\n");
        return $file;
    }

    private static function closeTranscript(): void
    {
        if (self::$transcriptHandle === null) {
            return;
        }
        @fwrite(self::$transcriptHandle, "\n# Finished: " . date('c') . "\n");
        @fclose(self::$transcriptHandle);
        self::$transcriptHandle = null;
    }

    /** @param list<string>|string $command */
    private static function transcriptStep(string $label, array|string $command): void
    {
        if (self::$transcriptHandle === null) {
            return;
        }
        $cmdStr = is_array($command) ? implode(' ', $command) : $command;
        $header = $label !== '' ? $label : $cmdStr;
        @fwrite(self::$transcriptHandle, "\n========== STEP: {$header} ==========\n");
        @fwrite(self::$transcriptHandle, "\$ {$cmdStr}\n");
    }

    private static function transcriptAppend(string $text): void
    {
        if (self::$transcriptHandle === null || $text === '') {
            return;
        }
        @fwrite(self::$transcriptHandle, $text);
    }

    private static function transcriptExit(?int $code): void
    {
        if (self::$transcriptHandle === null) {
            return;
        }
        @fwrite(self::$transcriptHandle, "\n(exit: " . ($code ?? '?') . ")\n");
    }

    private static function fail(string $repoPath, ?string $branch, string $step, Process $process): RepoUpdateResult
    {
        $logPath = self::writeLog($repoPath, $step, $process);
        $hint = self::hintFor($process);

        // When we have a hint, it already explains the failure cleanly — show
        // that instead of the raw upstream output excerpt. The full excerpt
        // remains in the log file for anyone who wants the gory details.
        $message = $hint !== null
            ? "{$step} failed: {$hint}"
            : "{$step} failed: " . self::extractError($process);

        if ($logPath !== null) {
            $message .= " (log: {$logPath})";
        }

        return RepoUpdateResult::failed($repoPath, $message, $branch, $logPath);
    }

    private static function hintFor(Process $process): ?string
    {
        $combined = $process->getOutput() . "\n" . $process->getErrorOutput();

        $hints = [
            'needs to run with administrative privileges'
                => 'ddev needs sudo to add a hostname to /etc/hosts. Run `ddev start` manually in this repo once to enter your password, then re-run `package-updater retry`.',

            'may need to enter your password for sudo'
                => 'ddev needs sudo to add a hostname to /etc/hosts. Run `ddev start` manually in this repo once to enter your password, then re-run `package-updater retry`.',

            'configured database type does not match the current actual database'
                => 'database type mismatch — run `ddev delete --omit-snapshot` then `ddev restart` in this repo',

            'permission denied (publickey)'
                => 'SSH auth failed — load the right key into the host agent (`ssh-add ~/.ssh/<key>`); composer-side issues need `ddev auth ssh`',

            'could not read username'
                => 'git auth failed on an HTTPS remote — cache credentials (`git credential approve`) or switch the remote to SSH (`git remote set-url origin git@<host>:<path>.git`)',

            'authentication failed'
                => 'git auth failed on an HTTPS remote — cache credentials (`git credential approve`) or switch the remote to SSH (`git remote set-url origin git@<host>:<path>.git`)',

            'user cancelled dialog'
                => 'git auth failed on an HTTPS remote — cache credentials (`git credential approve`) or switch the remote to SSH (`git remote set-url origin git@<host>:<path>.git`)',

            'terminal prompts disabled'
                => 'git needs credentials it cannot get non-interactively — cache them (`git credential approve`) or switch the remote to SSH (`git remote set-url origin git@<host>:<path>.git`)',

            'cannot connect to the docker daemon'
                => 'Docker is not running — start Docker Desktop',

            'is the docker daemon running'
                => 'Docker is not running — start Docker Desktop',

            'port is already allocated'
                => 'port conflict — run `ddev poweroff` (frees all ddev-bound ports) and retry, or use `lsof -i :<port>` to find the offender',

            'bind: address already in use'
                => 'port conflict — run `ddev poweroff` and retry, or `lsof -i :<port>` to find the process holding it',

            'your local changes to the following files would be overwritten by merge'
                => 'local changes block git pull — should have been caught by the dirty-check; if not, commit or stash manually',

            "couldn't find remote ref"
                => 'target branch no longer exists on origin (likely renamed/deleted) — `git fetch --prune`, then check out a branch that still exists',

            'refusing to merge unrelated histories'
                => 'branch divergence — needs manual `git pull --allow-unrelated-histories` or a rebase',

            'your requirements could not be resolved to an installable set of packages'
                => 'composer constraint conflict — try `--update-package=<parent>` to bump a parent constraint, or inspect with `composer why-not <pkg> <version>`',
        ];

        foreach ($hints as $needle => $hint) {
            if (stripos($combined, $needle) !== false) {
                return $hint;
            }
        }

        if (preg_match('/https?:\/\/\S+.*?\b(401|403)\b/i', $combined)) {
            return 'composer repository auth failed (401/403) — check `auth.json` (or run `composer config --global --auth http-basic.<host> <user> <token>`)';
        }

        return null;
    }

    private static function writeLog(string $repoPath, string $step, Process $process): ?string
    {
        $dir = self::logDir($repoPath);
        if ($dir === null) {
            return null;
        }

        $slug = preg_replace('/[^a-z0-9]+/i', '-', basename($repoPath) . '-' . $step);
        $file = $dir . '/' . trim((string) $slug, '-') . '-' . date('Ymd-His') . '.log';

        $contents = '# Command: ' . $process->getCommandLine() . "\n"
            . "# CWD: {$repoPath}\n"
            . '# Exit: ' . $process->getExitCode() . "\n"
            . "\n--- STDOUT ---\n" . $process->getOutput()
            . "\n--- STDERR ---\n" . $process->getErrorOutput();

        return @file_put_contents($file, $contents) === false ? null : $file;
    }

    private static function extractError(Process $process): string
    {
        $combined = trim($process->getErrorOutput()) ?: trim($process->getOutput());
        if ($combined === '') {
            return 'no output (exit ' . $process->getExitCode() . ')';
        }

        $lines = array_values(array_filter(
            array_map(fn ($l) => trim((string) $l), explode("\n", $combined)),
            fn ($l) => $l !== '',
        ));

        $signalPatterns = [
            '/permission denied/i',
            '/^fatal:/i',
            '/^error:/i',
            '/could not/i',
            '/failed to/i',
            '/not found/i',
            '/unable to/i',
            '/in [^ ]+\.php line \d+:/i',
        ];

        foreach ($lines as $i => $line) {
            foreach ($signalPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $end = min(count($lines) - 1, $i + 2);
                    return implode(' | ', array_slice($lines, $i, $end - $i + 1));
                }
            }
        }

        $tail = array_slice($lines, -3);
        return implode(' | ', $tail);
    }
}
