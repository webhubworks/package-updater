<?php

namespace App\Commands;

use App\Actions\FindCraftReposAction;
use App\Actions\UpdateRepoAction;
use App\DataTransferObjects\RepoUpdateResult;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class UpdateCraftCommand extends UpdateCommand
{
    protected $signature = 'update:craft
        {handle? : Craft plugin handle, or "craft" to update Craft itself}
        {--reps-dir= : Directory containing repos (default: ~/reps)}
        {--parallel= : Number of repos to update concurrently (default: prompt; 1 = sequential)}
        {--dry-run : List matching repos with their currently locked version and exit}
        {--limit= : Process at most N repos (after sorting alphabetically)}
        {--no-ssh-auth : Skip the initial `ddev auth ssh` step}
        {--target-version= : Skip repos already at this version of the matched package}
        {--stop-ddev : Stop the ddev project in each repo after a successful update (default: keep running)}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Run `ddev craft update <handle>` across local repos containing the given Craft plugin (or Craft itself)';

    public function handle(): int
    {
        $handle = (string) ($this->argument('handle') ?: text(
            label: 'Which Craft plugin should be updated? (handle, or "craft" for Craft itself)',
            placeholder: 'commerce',
            required: true,
        ));
        $handle = trim($handle);

        $reposDir = $this->option('reps-dir') ?: config('package-updater.repos_dir');
        $reposDir = rtrim((string) $reposDir, '/');

        if (! is_dir($reposDir)) {
            $this->error("Repos directory not found: {$reposDir}");
            return self::FAILURE;
        }

        info($handle === 'craft'
            ? "Scanning {$reposDir} for repos that require craftcms/cms..."
            : "Scanning {$reposDir} for repos with craft plugin handle \"{$handle}\"...");

        $matches = FindCraftReposAction::find($reposDir, $handle);

        if (empty($matches)) {
            warning($handle === 'craft'
                ? "No repositories under {$reposDir} require craftcms/cms."
                : "No repositories under {$reposDir} have craft plugin handle \"{$handle}\".");
            return self::SUCCESS;
        }

        $totalFound = count($matches);
        info(sprintf('Found %d repositor%s.', $totalFound, $totalFound === 1 ? 'y' : 'ies'));

        $cliLimit = $this->option('limit');
        if ($cliLimit !== null && $cliLimit !== '') {
            $cliLimit = max(1, (int) $cliLimit);
            if ($cliLimit < count($matches)) {
                $matches = array_slice($matches, 0, $cliLimit);
            }
        }

        if ($this->option('dry-run')) {
            table(
                ['Repo', 'Package', 'Locked version'],
                array_map(fn ($m) => [basename($m['path']), $m['package'], $m['version']], $matches),
            );
            note('Dry run — no changes were made. Note: versions reflect each repo\'s current local composer.lock and may be stale.');
            return self::SUCCESS;
        }

        $targetVersion = $this->resolveTargetVersion();

        /** @var list<RepoUpdateResult> $preSkipped */
        $preSkipped = [];
        if ($targetVersion !== null) {
            $remaining = [];
            foreach ($matches as $m) {
                if (self::versionsEqual($m['version'], $targetVersion)) {
                    $preSkipped[] = RepoUpdateResult::skipped(
                        $m['path'],
                        "already at {$targetVersion}",
                    );
                    continue;
                }
                $remaining[] = $m;
            }
            $matches = $remaining;

            info(sprintf(
                '%d repo(s) already at %s — will skip. %d remain to update.',
                count($preSkipped),
                $targetVersion,
                count($matches),
            ));
        }

        if (empty($matches)) {
            $this->printSummary($preSkipped, $targetVersion);
            return self::SUCCESS;
        }

        $parallel = $this->resolveParallel();

        if ($cliLimit === null || $cliLimit === '') {
            $promptedLimit = $this->promptLimit(count($matches));
            if ($promptedLimit !== null && $promptedLimit < count($matches)) {
                $matches = array_slice($matches, 0, $promptedLimit);
                info("Limiting to first {$promptedLimit} repositor" . ($promptedLimit === 1 ? 'y' : 'ies') . '.');
            }
        }

        $keepDdevRunning = $this->resolveKeepDdevRunning();

        $mode = $parallel === 1 ? 'sequentially' : "with {$parallel} workers in parallel";
        $defaultCommand = "ddev craft update {$handle} --interactive=0 --with-expired --minor-only --backup=1";

        if ($this->option('yes')) {
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
        );

        $buildCmd = function (string $repo, string $php, string $binary) use ($packagesByPath, $craftCommandLine, $keepDdevRunning): array {
            $cmd = [$php, $binary, 'update:single', $repo, $packagesByPath[$repo], '--craft-command=' . $craftCommandLine];
            if (! $keepDdevRunning) {
                $cmd[] = '--stop-ddev';
            }
            return $cmd;
        };

        $repos = array_map(fn ($m) => $m['path'], $matches);

        $results = $parallel === 1
            ? $this->runSequential($repos, $updater)
            : $this->runParallel($repos, $parallel, $buildCmd);

        $this->printSummary(array_merge($preSkipped, $results), $targetVersion);

        return self::SUCCESS;
    }
}
