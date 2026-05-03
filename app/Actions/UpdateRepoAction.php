<?php

namespace App\Actions;

use App\DataTransferObjects\RepoUpdateResult;
use Symfony\Component\Process\Process;

final class UpdateRepoAction
{
    private const BranchCandidates = ['develop', 'staging', 'main', 'master'];

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
     */
    public static function update(
        string $repoPath,
        string $package,
        ?callable $onProgress = null,
        bool $withAllDependencies = false,
        ?string $updatePackage = null,
    ): RepoUpdateResult {
        $updatePackage = $updatePackage ?? $package;

        if ($updatePackage !== $package && self::lockedVersion($repoPath, $updatePackage) === null) {
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

        $pull = self::run(['git', 'pull', '--ff-only'], $repoPath, 600, $onProgress, 'git pull --ff-only');
        if (! $pull->isSuccessful()) {
            return self::fail($repoPath, $branch, 'git pull', $pull);
        }

        $detectedStatus = self::ddevStatus($repoPath);
        $alreadyRunning = $detectedStatus === 'running';
        $startedByUs = false;

        if ($alreadyRunning) {
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
            $startedByUs = true;
        }

        $previousVersion = self::lockedVersion($repoPath, $package);

        $composerArgs = ['ddev', 'composer', 'update', $updatePackage, '--no-audit'];
        if ($withAllDependencies) {
            $composerArgs[] = '-W';
        }

        $update = self::run(
            $composerArgs,
            $repoPath,
            1800,
            $onProgress,
            'ddev composer update ' . $updatePackage . ($withAllDependencies ? ' -W' : ''),
        );
        if (! $update->isSuccessful()) {
            return self::fail($repoPath, $branch, 'ddev composer update', $update);
        }

        if ($startedByUs) {
            self::run(['ddev', 'stop'], $repoPath, 300, $onProgress, 'ddev stop');
        } elseif ($onProgress !== null) {
            $onProgress('step-start', null, 'ddev was already running — leaving it running');
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
        );
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
     * @param  list<string>  $command
     * @param  callable(string, ?string, ?string): void|null  $onProgress
     */
    private static function run(
        array $command,
        string $cwd,
        int $timeout = 120,
        ?callable $onProgress = null,
        string $label = '',
        bool $stream = true,
    ): Process {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);

        if ($stream && $onProgress !== null && $label !== '') {
            $onProgress('step-start', null, $label);
            $process->run(function (string $type, string $buffer) use ($onProgress, $label): void {
                $onProgress($label, $type === Process::ERR ? 'err' : 'out', $buffer);
            });
        } else {
            $process->run();
        }

        return $process;
    }

    private static function fail(string $repoPath, ?string $branch, string $step, Process $process): RepoUpdateResult
    {
        $logPath = self::writeLog($repoPath, $step, $process);
        $excerpt = self::extractError($process);

        $message = "{$step} failed: {$excerpt}";

        $hint = self::hintFor($process);
        if ($hint !== null) {
            $message .= " — hint: {$hint}";
        }

        if ($logPath !== null) {
            $message .= " (log: {$logPath})";
        }

        return RepoUpdateResult::failed($repoPath, $message, $branch, $logPath);
    }

    private static function hintFor(Process $process): ?string
    {
        $combined = $process->getOutput() . "\n" . $process->getErrorOutput();

        $hints = [
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
