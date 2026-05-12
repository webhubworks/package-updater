<?php

namespace App\Actions;

use App\DataTransferObjects\RepoUpdateResult;
use Symfony\Component\Process\Process;

final class UpdateRepoAction
{
    private const BranchCandidates = ['develop', 'dev', 'staging', 'stag', 'stage', 'main', 'master', 'prod', 'live'];

    /**
     * @param  callable(string $step, ?string $type, ?string $chunk): void|null  $onProgress
     *         Called with ('step-start', null, label) before each step, and
     *         (label, 'out'|'err', chunk) for each output chunk.
     */
    /**
     * @param  string  $package         The target package whose version we track in the result
     * @param  string|null  $updatePackage  The package composer should actually `update`
     *                                      (defaults to $package). Override with a parent
     *                                      package when its constraint blocks $package from
     *                                      reaching the desired version.
     * @param  bool  $withAllDependencies  Pass -W to composer.
     * @param  string|null  $craftCommandLine  When set, the update step runs this shell command
     *                                          (e.g. `ddev craft update commerce --interactive=0`)
     *                                          instead of `ddev composer update`. $package is still
     *                                          used to track the locked version before/after.
     * @param  string|null  $crawlerCommandLine  When non-null, runs this shell command from the
     *                                            repo after `composer prep`. A crawler failure
     *                                            does NOT mark the repo as failed; it surfaces
     *                                            via the crawlerFailed/crawlerLogPath fields.
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
    ): RepoUpdateResult {
        $transcriptPath = self::openTranscript($repoPath);
        try {
            $result = self::doUpdate($repoPath, $package, $onProgress, $withAllDependencies, $updatePackage, $keepDdevRunning, $craftCommandLine, $crawlerCommandLine);
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
    ): RepoUpdateResult {
        $updatePackage = $updatePackage ?? $package;

        if ($craftCommandLine === null && $updatePackage !== $package && self::lockedVersion($repoPath, $updatePackage) === null) {
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
            return RepoUpdateResult::skipped($repoPath, 'uncommitted changes');
        }

        $branch = self::pickBranch($repoPath);
        if ($branch === null) {
            return RepoUpdateResult::failed($repoPath, 'no develop/staging/main/master branch found');
        }

        $checkout = self::run(['git', 'checkout', $branch], $repoPath, 120, $onProgress, "git checkout {$branch}");
        if (! $checkout->isSuccessful()) {
            return self::fail($repoPath, $branch, "git checkout {$branch}", $checkout);
        }

        $preHead = self::headSha($repoPath);
        $pull = self::run(['git', 'pull', '--ff-only'], $repoPath, 600, $onProgress, 'git pull --ff-only');
        if (! $pull->isSuccessful()) {
            return self::fail($repoPath, $branch, 'git pull', $pull);
        }
        $postHead = self::headSha($repoPath);
        $pulledChanges = $preHead !== null && $postHead !== null && $preHead !== $postHead;

        $detectedStatus = self::ddevStatus($repoPath);

        if ($detectedStatus === 'running') {
            if ($onProgress !== null) {
                $onProgress('step-start', null, 'ddev already running — skipping start');
            }
        } else {
            if ($onProgress !== null) {
                $onProgress('step-start', null, 'ddev status: ' . ($detectedStatus ?? 'unknown'));
            }
            $start = self::run(['ddev', 'start'], $repoPath, 900, $onProgress, 'ddev start');
            if (! $start->isSuccessful()) {
                return self::fail($repoPath, $branch, 'ddev start', $start);
            }
        }

        if ($craftCommandLine !== null && $pulledChanges) {
            // Pulled in commits from the remote — sync deps / migrations / project
            // config before we run our own craft update on top.
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

        $prepRan = false;
        $testsFailed = null;
        $testsSummary = null;
        $prepLogPath = null;

        if (self::hasComposerScript($repoPath, 'prep')) {
            $prepRan = true;
            $prep = self::run(
                ['ddev', 'composer', 'prep'],
                $repoPath,
                3600,
                $onProgress,
                'ddev composer prep',
            );

            $combinedOutput = $prep->getOutput() . "\n" . $prep->getErrorOutput();
            $stats = self::parseTestSummary($combinedOutput);
            if ($stats !== null) {
                $testsFailed = $stats['failed'];
                $testsSummary = $stats['summary'];
            }

            $hasFailures = ($testsFailed !== null && $testsFailed > 0) || ! $prep->isSuccessful();
            if ($hasFailures) {
                $prepLogPath = self::writeLog($repoPath, 'composer-prep', $prep);
                if ($testsSummary === null) {
                    $testsSummary = $prep->isSuccessful()
                        ? 'prep ran but no test summary detected'
                        : 'prep exited non-zero (no test summary detected)';
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

        if (! $keepDdevRunning) {
            self::run(['ddev', 'stop'], $repoPath, 300, $onProgress, 'ddev stop');
        } elseif ($onProgress !== null) {
            $onProgress('step-start', null, 'leaving ddev running');
        }

        $installedVersion = self::lockedVersion($repoPath, $package);
        $afterStatus = self::run(['git', 'status', '--porcelain', 'composer.lock'], $repoPath, 60, null, '', stream: false);
        $hasLockChange = trim($afterStatus->getOutput()) !== '';

        return RepoUpdateResult::success(
            $repoPath,
            $branch,
            $hasLockChange,
            $previousVersion,
            $installedVersion,
            $prepRan,
            $testsFailed,
            $testsSummary,
            $prepLogPath,
            $crawlerRan,
            $crawlerFailed,
            $crawlerLogPath,
            $crawlerServerErrorUrls,
        );
    }

    private static function hasComposerScript(string $repoPath, string $scriptName): bool
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

    private static function headSha(string $repoPath): ?string
    {
        $proc = self::run(['git', 'rev-parse', 'HEAD'], $repoPath, 30, null, '', stream: false);
        if (! $proc->isSuccessful()) {
            return null;
        }
        $sha = trim($proc->getOutput());

        return $sha !== '' ? $sha : null;
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
    ): Process {
        self::transcriptStep($label, $command);

        $process = is_string($command)
            ? Process::fromShellCommandline($command, $cwd)
            : new Process($command, $cwd);
        $process->setTimeout($timeout);

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

    /** @var resource|null */
    private static $transcriptHandle = null;

    private static function openTranscript(string $repoPath): ?string
    {
        $dir = dirname(__DIR__, 2) . '/logs/transcripts';
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
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
        $dir = dirname(__DIR__, 2) . '/logs';
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
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
