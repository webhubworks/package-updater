<?php

namespace App\Commands;

use App\Actions\LastRunStore;
use App\Actions\OpenInGitKrakenAction;
use App\DataTransferObjects\RepoUpdateResult;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\warning;

class OpenCommand extends Command
{
    protected $signature = 'open
        {--filter= : changed | failed | all (default: every processed repo — both successes and failures, but not skipped)}
        {--yes : Open every repo in the filtered pool without prompting}';

    protected $description = 'Open repos from the most recent update run in GitKraken';

    public function handle(): int
    {
        $data = LastRunStore::loadResults();
        if ($data === null) {
            $this->error('No previous results found at ' . LastRunStore::resultsPath() . '. Run `update` or `update:craft` first.');
            return self::FAILURE;
        }

        $results = array_map(fn ($r) => RepoUpdateResult::fromArray($r), $data['results']);
        $filter = is_string($this->option('filter')) ? trim((string) $this->option('filter')) : '';

        $pool = array_values(array_filter($results, match ($filter) {
            'changed' => fn (RepoUpdateResult $r) => $r->hasUncommittedChanges,
            'failed' => fn (RepoUpdateResult $r) => $r->status === 'failed',
            'all' => fn (RepoUpdateResult $r) => true,
            default => fn (RepoUpdateResult $r) => $r->status !== 'skipped',
        }));

        if (empty($pool)) {
            info('No matching repos to open.');
            return self::SUCCESS;
        }

        if ($this->option('yes')) {
            $paths = array_map(fn ($r) => $r->repoPath, $pool);
        } else {
            $options = [];
            foreach ($pool as $r) {
                $options[$r->repoPath] = basename($r->repoPath) . self::badge($r);
            }
            $selected = multiselect(
                label: sprintf('Open %d repo(s) in GitKraken?', count($pool)),
                options: $options,
                default: array_keys($options),
                hint: 'Space to toggle · Ctrl+A to select/deselect all · Enter to confirm',
                required: false,
            );
            $paths = array_values(array_map('strval', (array) $selected));
        }

        if (empty($paths)) {
            return self::SUCCESS;
        }

        $report = OpenInGitKrakenAction::open($paths);
        info(sprintf('Opened %d repo(s) in GitKraken.', $report['opened']));
        if (! empty($report['failed'])) {
            warning(sprintf('Failed to open %d repo(s):', count($report['failed'])));
            foreach ($report['failed'] as $p) {
                $this->line("  <fg=yellow>! {$p}</>");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Build the inline tags shown next to each repo in the multiselect, so
     * the user can tell at a glance which repos failed vs. which succeeded
     * (committed or not). Also called from UpdateAllCommand::offerOpenPrompt
     * to keep both prompts consistent.
     */
    public static function badge(RepoUpdateResult $r): string
    {
        $tags = [];
        if ($r->status === 'failed') {
            $tags[] = 'failed';
        } elseif ($r->status === 'success') {
            if ($r->committed) {
                $tags[] = $r->pushed ? 'pushed' : 'committed';
            } elseif ($r->hasUncommittedChanges) {
                $tags[] = 'uncommitted';
            }
        }
        if ($r->prepRan && ($r->testsFailed ?? 0) > 0) {
            $tags[] = 'tests';
        }
        if ($r->crawlerFailed) {
            $tags[] = 'crawler';
        }
        if (! empty($r->crawlerServerErrorUrls)) {
            $tags[] = '5xx';
        }

        return $tags ? ' [' . implode(', ', $tags) . ']' : '';
    }
}
