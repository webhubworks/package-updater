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
        {--no-composer-sweep : Persist an empty sweep allowlist (sweep disabled by default).}
        {--slack-webhook-url= : Set the Slack incoming-webhook URL non-interactively (used by --maintenance runs to post a summary).}
        {--no-slack : Persist an empty Slack webhook (notifications disabled).}';

    protected $description = 'Configure package-updater (repos directory + composer-sweep allowlist + Slack webhook, stored in ~/.config/package-updater)';

    public function handle(): int
    {
        $reposDirHandled = $this->handleReposDirNonInteractive();
        if ($reposDirHandled !== null) {
            $this->handleSweepNonInteractive();
            $slack = $this->handleSlackNonInteractive();
            note('Config file: '.UserConfig::path());

            return $reposDirHandled === self::SUCCESS && $slack !== self::FAILURE
                ? self::SUCCESS
                : self::FAILURE;
        }

        // Pure --composer-sweep / --slack-webhook-url (etc.) without
        // --repos-dir: update just those settings and exit.
        $sweepHandled = $this->handleSweepNonInteractive();
        $slack = $this->handleSlackNonInteractive();
        if ($sweepHandled || $slack !== null) {
            note('Config file: '.UserConfig::path());

            return $slack === self::FAILURE ? self::FAILURE : self::SUCCESS;
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
        $this->promptForSlackWebhook();

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
            ."package matching one of these fnmatch patterns (e.g. webhubworks/*). Useful for\n"
            ."private/Repman plugins and transitive libs that Craft's update check doesn't see.\n"
            .'Leave blank to skip the sweep by default — override per-run with --composer-sweep='
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

    /**
     * Apply --slack-webhook-url / --no-slack non-interactively when set.
     * Returns null when neither flag was passed (so the interactive prompt
     * should run); otherwise the exit code (SUCCESS, or FAILURE on a bad URL).
     */
    private function handleSlackNonInteractive(): ?int
    {
        $cli = $this->option('slack-webhook-url');
        if (is_string($cli) && $cli !== '') {
            $error = self::validateWebhookUrl($cli);
            if ($error !== null) {
                $this->error($error);

                return self::FAILURE;
            }
            UserConfig::setSlackWebhookUrl(trim($cli));
            info('Saved Slack webhook: '.self::maskWebhookUrl(trim($cli)));

            return self::SUCCESS;
        }

        if ($this->option('no-slack')) {
            UserConfig::setSlackWebhookUrl(null);
            info('Saved Slack webhook: (none - notifications disabled)');

            return self::SUCCESS;
        }

        return null;
    }

    private function promptForSlackWebhook(): void
    {
        $current = UserConfig::hasSlackWebhookUrl() ? UserConfig::getSlackWebhookUrl() : null;

        if (UserConfig::hasSlackWebhookUrl()) {
            $display = $current === null ? '(none - notifications disabled)' : self::maskWebhookUrl($current);
            info("Current Slack webhook: {$display}");
        }

        note(
            "A `pu update:craft --maintenance` run can post a summary to Slack when it\n"
            ."finishes. Paste an incoming-webhook URL that writes to the channel you want\n"
            ."(https://hooks.slack.com/services/...). Leave blank to disable notifications.\n"
            .'override per-run later with --slack-webhook-url= or --no-slack.'
        );

        $value = trim((string) text(
            label: 'Slack incoming-webhook URL',
            placeholder: 'https://hooks.slack.com/services/T.../B.../...',
            default: $current ?? '',
            hint: 'Blank = no Slack notifications. Stored in ~/.config/package-updater/config.json.',
            validate: fn (string $v) => trim($v) === '' ? null : self::validateWebhookUrl($v),
        ));

        UserConfig::setSlackWebhookUrl($value);

        $display = $value === '' ? '(none - notifications disabled)' : self::maskWebhookUrl($value);
        info("Saved Slack webhook: {$display}");
    }

    /** Returns an error string when the URL isn't a plausible Slack webhook, else null. */
    private static function validateWebhookUrl(string $url): ?string
    {
        $url = trim($url);
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Enter a valid URL.';
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return 'The webhook URL must use https.';
        }
        if (($parts['host'] ?? '') !== 'hooks.slack.com') {
            return 'Expected a Slack incoming webhook on hooks.slack.com.';
        }

        return null;
    }

    /** Mask the secret token portion of a webhook so it can be echoed safely. */
    private static function maskWebhookUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $tail = substr($url, -4);

        return "{$scheme}://{$host}/…{$tail}";
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
