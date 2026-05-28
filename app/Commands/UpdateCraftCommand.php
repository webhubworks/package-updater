<?php

namespace App\Commands;

use App\Actions\FindCraftReposAction;
use App\Actions\LastRunStore;
use App\Actions\UpdateRepoAction;
use App\Support\UserConfig;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class UpdateCraftCommand extends UpdateAllCommand
{
    protected $signature = 'update:craft
        {handle? : Craft plugin handle, "craft" to update Craft itself, or "all" to update every Craft package}
        {--reps-dir= : Directory containing repos (default: ~/reps)}
        {--parallel= : Number of repos to update concurrently (default: prompt; 1 = sequential)}
        {--dry-run : List matching repos with their currently locked version and exit}
        {--repo=* : Process only the specified repo path(s); can be passed multiple times. Skips the interactive repo selection.}
        {--filter-name= : Keep only repos whose composer.json "name" contains this substring}
        {--no-ssh-auth : Skip the initial `ddev auth ssh` step}
        {--target-version= : Skip repos already at this version of the matched package}
        {--stop-ddev : Stop the ddev project in each repo after a successful update (default: keep running)}
        {--craft-command= : Full shell command to run in each repo (skips the editable-command prompt). Defaults to `ddev php craft update <handle> --interactive=0 --with-expired --minor-only --backup=1`.}
        {--commit : After a successful run, commit "Package updates" with a body listing what changed (skips the prompt)}
        {--no-commit : Skip the end-of-run commit step (skips the prompt)}
        {--crawl-repo=* : After composer prep, run the site-crawler only in these repo path(s). Skips the interactive crawler-selection prompt.}
        {--no-crawl : Skip the site-crawler step entirely.}
        {--crawler-command= : Full shell command to run as the crawler (skips the editable-command prompt). Defaults to `site-crawler crawl:ddev --exclude="assets,variant,index.php,downloads,actions,.pdf"`.}
        {--open : After the run, open every repo with uncommitted changes in GitKraken (skips the prompt)}
        {--no-open : Skip the end-of-run "open in GitKraken" prompt entirely}
        {--composer-sweep=* : fnmatch patterns (e.g. webhubworks/*). After craft update, `composer outdated` is parsed and any installed package matching a pattern is bumped via `composer update -W` + migrate/all + project-config/apply. Repeatable; overrides the stored default.}
        {--no-composer-sweep : Disable the composer sweep step for this run (overrides the stored default).}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Run `ddev php craft update <handle>` across local repos containing the given Craft plugin (or Craft itself)';

    public function handle(): int
    {
        // Resolve the repos dir BEFORE any other prompt so the first-run
        // setup flow runs before we start asking about plugins.
        $reposDir = $this->resolveReposDir();
        if ($reposDir === null) {
            return self::FAILURE;
        }

        if (! is_dir($reposDir)) {
            $this->error("Repos directory not found: {$reposDir}");
            return self::FAILURE;
        }

        $handle = (string) ($this->argument('handle') ?: text(
            label: 'Which Craft plugin should be updated? (handle, "craft" for Craft itself, or "all" for every Craft package)',
            placeholder: 'commerce',
            required: true,
        ));
        $handle = trim($handle);

        info(match ($handle) {
            'craft' => "Scanning {$reposDir} for repos that require craftcms/cms...",
            'all' => "Scanning {$reposDir} for Craft repos to update every Craft package in...",
            default => "Scanning {$reposDir} for repos with craft plugin handle \"{$handle}\"...",
        });

        $matches = FindCraftReposAction::find($reposDir, $handle);

        if (empty($matches)) {
            warning(match ($handle) {
                'craft', 'all' => "No repositories under {$reposDir} require craftcms/cms.",
                default => "No repositories under {$reposDir} have craft plugin handle \"{$handle}\".",
            });
            return self::SUCCESS;
        }

        $totalFound = count($matches);
        $matches = $this->applyNameFilter($matches);

        if (empty($matches)) {
            warning(sprintf(
                "No repos remain after --filter-name=%s (started with %d).",
                (string) $this->option('filter-name'),
                $totalFound,
            ));
            return self::SUCCESS;
        }

        info(sprintf(
            'Found %d repositor%s%s.',
            count($matches),
            count($matches) === 1 ? 'y' : 'ies',
            count($matches) !== $totalFound ? sprintf(' (filtered from %d by name)', $totalFound) : '',
        ));

        if ($this->option('dry-run')) {
            table(
                ['Repo', 'Package', 'Locked version'],
                array_map(fn ($m) => [basename($m['path']), $m['package'], $m['version']], $matches),
            );
            note('Dry run — no changes were made. Note: versions reflect each repo\'s current local composer.lock and may be stale.');
            return self::SUCCESS;
        }

        // `all` doesn't track a single package, so a target version has nothing
        // meaningful to filter against — skip the prompt and the filter.
        $rawTarget = $handle === 'all' ? null : $this->resolveTargetVersion();
        [$matches, $preSkipped, $targetVersion] = $this->applyTargetVersionFilter($matches, $rawTarget);

        if (empty($matches)) {
            $this->printSummary($preSkipped, $targetVersion);
            return self::SUCCESS;
        }

        $matches = $this->resolveRepoSelection($matches);
        if (empty($matches)) {
            info('No repos selected — exiting.');
            return self::SUCCESS;
        }

        $parallel = $this->resolveParallel();
        $keepDdevRunning = $this->resolveKeepDdevRunning();
        $commit = $this->resolveCommit();
        $crawlPaths = $this->resolveCrawlSelection($matches);
        $crawlSet = array_flip($crawlPaths);

        $mode = $parallel === 1 ? 'sequentially' : "with {$parallel} workers in parallel";
        $defaultCommand = "ddev php craft update {$handle} --interactive=0 --with-expired --minor-only --backup=1";

        $craftOption = $this->option('craft-command');
        if (is_string($craftOption) && $craftOption !== '') {
            $craftCommandLine = trim($craftOption);
        } elseif ($this->option('yes')) {
            $craftCommandLine = $defaultCommand;
        } else {
            note(sprintf('Will run in %d repo(s) %s.', count($matches), $mode));
            $craftCommandLine = trim((string) text(
                label: 'Do you want to run the following command?',
                default: $defaultCommand,
                required: true,
                hint: 'Edit if needed, then press Enter to run it in each repo.',
            ));
            if ($craftCommandLine === '') {
                return self::SUCCESS;
            }
        }

        $crawlerCommandLine = ! empty($crawlPaths) ? $this->resolveCrawlerCommand() : null;
        if ($crawlerCommandLine === '') {
            $crawlerCommandLine = null;
        }

        $sweepPatterns = $this->resolveSweepPatterns();

        LastRunStore::save('update:craft', ['handle' => $handle], [
            'reps-dir' => $reposDir,
            'parallel' => (string) $parallel,
            'target-version' => $rawTarget,
            'filter-name' => $this->option('filter-name') ?: null,
            'repo' => array_map(fn ($m) => $m['path'], $matches),
            'stop-ddev' => ! $keepDdevRunning,
            'no-ssh-auth' => (bool) $this->option('no-ssh-auth'),
            'craft-command' => $craftCommandLine,
            'crawl-repo' => $crawlPaths,
            'no-crawl' => empty($crawlPaths),
            'crawler-command' => $crawlerCommandLine,
            'composer-sweep' => $sweepPatterns,
            'no-composer-sweep' => empty($sweepPatterns),
            'commit' => $commit,
            'no-commit' => ! $commit,
            'yes' => true,
        ]);

        if (! $this->option('no-ssh-auth')) {
            $this->ensureDdevSshAuth();
        }

        $packagesByPath = [];
        foreach ($matches as $m) {
            $packagesByPath[$m['path']] = $m['package'];
        }

        $updater = fn (string $repo, callable $onProgress) => UpdateRepoAction::update(
            repoPath: $repo,
            package: $packagesByPath[$repo],
            onProgress: $onProgress,
            keepDdevRunning: $keepDdevRunning,
            craftCommandLine: $craftCommandLine,
            crawlerCommandLine: isset($crawlSet[$repo]) ? $crawlerCommandLine : null,
            commit: $commit,
            sweepPatterns: $sweepPatterns,
        );

        $buildCmd = function (string $repo, string $php, string $binary) use ($packagesByPath, $craftCommandLine, $keepDdevRunning, $crawlSet, $crawlerCommandLine, $commit, $sweepPatterns): array {
            $cmd = [$php, $binary, 'update:single', $repo, $packagesByPath[$repo], '--craft-command=' . $craftCommandLine];
            if (! $keepDdevRunning) {
                $cmd[] = '--stop-ddev';
            }
            if (isset($crawlSet[$repo]) && $crawlerCommandLine !== null) {
                $cmd[] = '--crawler-command=' . $crawlerCommandLine;
            }
            if ($commit) {
                $cmd[] = '--commit';
            }
            foreach ($sweepPatterns as $pattern) {
                $cmd[] = '--composer-sweep=' . $pattern;
            }
            return $cmd;
        };

        $repos = array_map(fn ($m) => $m['path'], $matches);
        $pathOf = fn (string $repo): string => $repo;

        $results = $parallel === 1
            ? $this->runSequential($repos, $pathOf, $updater)
            : $this->runParallel($repos, $parallel, $pathOf, $buildCmd);

        $allResults = array_merge($preSkipped, $results);
        $this->printSummary($allResults, $targetVersion);
        LastRunStore::saveResults('update:craft', array_map(fn ($r) => $r->toArray(), $allResults));
        $this->offerOpenPrompt($allResults);

        return self::SUCCESS;
    }

    /**
     * Decide whether to auto-commit the resulting changes in each repo with
     * title "Package updates" and a body listing the parsed updates. Asked
     * once up-front (like keep-ddev). Precedence:
     *   1. --no-commit  (returns false)
     *   2. --commit     (returns true)
     *   3. --yes        (defaults to true)
     *   4. confirm()    (default: yes)
     */
    protected function resolveCommit(): bool
    {
        if ($this->option('no-commit')) {
            return false;
        }
        if ($this->option('commit')) {
            return true;
        }
        if ($this->option('yes')) {
            return true;
        }

        return confirm(
            label: 'Commit the package updates in each repo? (title: "Package updates", body: parsed update list)',
            default: true,
            hint: 'Runs `git add -A` and `git commit` after the update completes — only fires when craft printed a "Performing N updates:" list.',
        );
    }

    /**
     * Resolve which of the selected repos should also run the post-prep
     * site-crawler. Precedence:
     *   1. --no-crawl       (returns [])
     *   2. --crawl-repo=... (uses those paths)
     *   3. --yes            (defaults to all selected repos)
     *   4. multiselect      (default: all selected; Ctrl+A toggles)
     *
     * @param  list<array{path: string, version: string, package: string}>  $matches
     * @return list<string>
     */
    protected function resolveCrawlSelection(array $matches): array
    {
        if ($this->option('no-crawl')) {
            return [];
        }

        $cli = array_values(array_filter(
            array_map('strval', (array) $this->option('crawl-repo')),
            fn ($p) => $p !== '',
        ));
        if (! empty($cli)) {
            return $cli;
        }

        $allPaths = array_map(fn ($m) => $m['path'], $matches);

        if ($this->option('yes')) {
            return $allPaths;
        }

        $options = [];
        foreach ($matches as $m) {
            $options[$m['path']] = basename($m['path']);
        }

        $selected = multiselect(
            label: 'Run site-crawler crawl:ddev in which of these repos? (after composer prep)',
            options: $options,
            default: array_keys($options),
            hint: 'Space to toggle · Ctrl+A to select/deselect all · Enter to confirm',
            required: false,
        );

        return array_values(array_map('strval', (array) $selected));
    }

    /**
     * Resolve which fnmatch patterns the post-craft composer sweep should
     * use. Precedence:
     *   1. --no-composer-sweep            (returns [])
     *   2. --composer-sweep=...           (uses those, repeatable)
     *   3. UserConfig::hasSweepAllowlist  (uses the saved list — may be [])
     *   4. --yes                           (returns [] non-interactively)
     *   5. first-time text() prompt        (saves answer via UserConfig)
     *
     * An empty list is a valid, persisted answer — once saved, the prompt
     * never fires again until the user runs `pu setup`.
     *
     * @return list<string>
     */
    protected function resolveSweepPatterns(): array
    {
        if ($this->option('no-composer-sweep')) {
            return [];
        }

        $cli = array_values(array_filter(
            array_map('strval', (array) $this->option('composer-sweep')),
            fn ($p) => $p !== '',
        ));
        if (! empty($cli)) {
            return $cli;
        }

        if (UserConfig::hasSweepAllowlist()) {
            return UserConfig::getSweepAllowlist();
        }

        if ($this->option('yes')) {
            return [];
        }

        info('First-time setup: composer sweep allowlist is not configured.');
        note(
            "After `craft update` finishes, the sweep can run `composer update -W` for any\n"
            . "package matching one of these fnmatch patterns. Useful for private/Repman\n"
            . "plugins and transitive libs that Craft's update check doesn't know about.\n"
            . "Leave blank to skip the sweep — this preference is saved to ~/.config/package-updater/config.json."
        );

        $value = text(
            label: 'Composer sweep allowlist',
            placeholder: 'e.g. webhubworks/* or webhubworks/panoptikum-cell (comma- or space-separated; blank = no sweep)',
            default: '',
            hint: 'Saved as your default; override per-run with --composer-sweep= or --no-composer-sweep.',
        );

        $patterns = array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', (string) $value) ?: []),
            fn ($p) => $p !== '',
        ));

        UserConfig::setSweepAllowlist($patterns);

        return $patterns;
    }

    /**
     * Resolve the crawler shell command line. Only invoked when at least one
     * repo is in the crawl set. Precedence:
     *   1. --crawler-command=...  (uses that value)
     *   2. --yes                   (uses the built-in default)
     *   3. text() prompt           (default pre-filled, fully editable)
     */
    protected function resolveCrawlerCommand(): string
    {
        $default = 'site-crawler crawl:ddev --exclude="assets,variant,index.php,downloads,actions,.pdf"';

        $cli = $this->option('crawler-command');
        if (is_string($cli) && $cli !== '') {
            return trim($cli);
        }

        if ($this->option('yes')) {
            return $default;
        }

        return trim((string) text(
            label: 'Do you want to run this site-crawler command for the selected repo(s)?',
            default: $default,
            required: true,
            hint: 'Edit if needed, then press Enter to run it after composer prep.',
        ));
    }
}
