<?php

namespace App\Commands;

use App\Support\UserConfig;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class SetupCommand extends Command
{
    protected $signature = 'setup
        {--repos-dir= : Set the repos directory non-interactively and exit}
        {--composer-sweep=* : Set the composer-sweep allowlist non-interactively (repeatable, fnmatch patterns).}
        {--no-composer-sweep : Persist an empty sweep allowlist (sweep disabled by default).}';

    protected $description = 'Configure package-updater (repos directory + composer-sweep allowlist, stored in ~/.config/package-updater)';

    public function handle(): int
    {
        $reposDirHandled = $this->handleReposDirNonInteractive();
        if ($reposDirHandled !== null) {
            $this->handleSweepNonInteractive();
            note('Config file: '.UserConfig::path());
            return $reposDirHandled;
        }

        // Pure --composer-sweep / --no-composer-sweep without --repos-dir:
        // update just the sweep allowlist and exit.
        if ($this->handleSweepNonInteractive()) {
            note('Config file: '.UserConfig::path());
            return self::SUCCESS;
        }

        $currentRepos = UserConfig::getReposDir();
        if ($currentRepos !== null) {
            info("Current repos directory: {$currentRepos}");
        } else {
            info('Welcome to package-updater. Let\'s configure where your local repos live.');
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '~';
        $default = $currentRepos ?? rtrim((string) $home, '/').'/reps';

        $value = text(
            label: 'Path to the directory containing your local repos',
            placeholder: $default,
            default: $default,
            required: true,
            validate: function (string $value): ?string {
                $resolved = $this->resolvePath($value);
                if (! is_dir($resolved)) {
                    return "Not a directory: {$resolved}";
                }
                return null;
            },
            hint: 'package-updater walks this directory looking for repos that require the chosen composer package.',
        );

        $resolved = $this->resolvePath($value);
        UserConfig::setReposDir($resolved);

        info("Saved repos directory: {$resolved}");

        $this->promptForSweepAllowlist();

        note('Config file: '.UserConfig::path());

        return self::SUCCESS;
    }

    /**
     * Apply --repos-dir non-interactively when set. Returns null when the
     * flag wasn't passed (so the interactive flow should run); otherwise
     * returns the exit code (SUCCESS/FAILURE).
     */
    private function handleReposDirNonInteractive(): ?int
    {
        $cli = $this->option('repos-dir');
        if (! is_string($cli) || $cli === '') {
            return null;
        }

        $resolved = $this->resolvePath($cli);
        if (! is_dir($resolved)) {
            $this->error("Not a directory: {$resolved}");
            return self::FAILURE;
        }
        UserConfig::setReposDir($resolved);
        info("Saved repos directory: {$resolved}");

        return self::SUCCESS;
    }

    /**
     * Apply --composer-sweep / --no-composer-sweep non-interactively when
     * set. Returns true when one of the flags was provided (caller should
     * skip the interactive sweep prompt).
     */
    private function handleSweepNonInteractive(): bool
    {
        $cli = array_values(array_filter(
            array_map('strval', (array) $this->option('composer-sweep')),
            fn ($p) => $p !== '',
        ));

        if (! empty($cli)) {
            UserConfig::setSweepAllowlist($cli);
            info('Saved composer sweep allowlist: '.implode(', ', $cli));
            return true;
        }

        if ($this->option('no-composer-sweep')) {
            UserConfig::setSweepAllowlist([]);
            info('Saved composer sweep allowlist: (none — sweep disabled by default)');
            return true;
        }

        return false;
    }

    private function promptForSweepAllowlist(): void
    {
        $current = UserConfig::hasSweepAllowlist() ? UserConfig::getSweepAllowlist() : null;

        if ($current !== null) {
            $display = empty($current) ? '(none — sweep disabled by default)' : implode(', ', $current);
            info("Current composer sweep allowlist: {$display}");
        }

        note(
            "After `pu update:craft`, the composer sweep can run `composer update -W` for any\n"
            . "package matching one of these fnmatch patterns (e.g. webhubworks/*). Useful for\n"
            . "private/Repman plugins and transitive libs that Craft's update check doesn't see.\n"
            . "Leave blank to skip the sweep by default — override per-run with --composer-sweep="
        );

        $default = $current !== null ? implode(', ', $current) : '';

        $value = text(
            label: 'Composer sweep allowlist',
            placeholder: 'e.g. webhubworks/* or webhubworks/panoptikum-cell (comma- or space-separated; blank = no sweep)',
            default: $default,
            hint: 'Override per-run with --composer-sweep= (repeatable) or --no-composer-sweep.',
        );

        $patterns = array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', (string) $value) ?: []),
            fn ($p) => $p !== '',
        ));

        UserConfig::setSweepAllowlist($patterns);

        $display = empty($patterns) ? '(none — sweep disabled by default)' : implode(', ', $patterns);
        info("Saved composer sweep allowlist: {$display}");
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, '~/') || $path === '~') {
            $home = $_SERVER['HOME'] ?? getenv('HOME');
            if (is_string($home) && $home !== '') {
                $path = $home.substr($path, 1);
            }
        }

        return rtrim($path, '/');
    }
}
