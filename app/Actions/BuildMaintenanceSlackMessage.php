<?php

namespace App\Actions;

use App\DataTransferObjects\RepoUpdateResult;

/**
 * Turns a finished `update:craft --maintenance` run into a Slack Block Kit
 * payload. Pure and side-effect free so it can be unit-tested; the actual
 * POST lives in SlackNotifier.
 *
 * Repos are sorted into three buckets that mirror the CLI summary:
 *   A. updated, committed AND pushed (fully shipped)
 *   B. updated but NOT fully pushed (uncommitted, or committed with the push
 *      held back by failing tests / PHPStan / a site-crawler issue)
 *   C. failed or skipped (couldn't be processed)
 *
 * Success repos that had nothing to update are counted as "already up to
 * date" and reported as a single footer count rather than listed.
 */
final class BuildMaintenanceSlackMessage
{
    /** Keep each section's text comfortably under Slack's 3000-char limit. */
    private const MAX_SECTION_CHARS = 2800;

    /**
     * Sender name and icon for the message. Only legacy custom incoming
     * webhooks honor these overrides; app-based webhooks post under the
     * Slack app's own name and ignore them.
     */
    private const SENDER_NAME = 'Hulk';

    private const SENDER_ICON = ':muscle:';

    /**
     * @param  list<RepoUpdateResult>  $results
     * @return array<string, mixed>
     */
    public static function build(array $results, string $handle, ?string $reposDir, string $timestamp): array
    {
        /** @var list<string> $pushed */
        $pushed = [];
        /** @var list<string> $needsReview */
        $needsReview = [];
        /** @var list<string> $failed */
        $failed = [];
        $upToDate = 0;

        foreach ($results as $r) {
            $name = basename($r->repoPath);

            if ($r->status === 'failed') {
                $failed[] = sprintf('• *%s* - %s', $name, self::firstLine($r->message));

                continue;
            }

            if ($r->status === 'skipped') {
                $failed[] = sprintf('• *%s* - skipped: %s', $name, self::firstLine($r->message));

                continue;
            }

            $hasChanges = $r->committed || $r->hasUncommittedChanges || ! empty($r->packageUpdates);
            if (! $hasChanges) {
                $upToDate++;

                continue;
            }

            if ($r->committed && $r->pushed) {
                $count = count($r->packageUpdates);
                $pushed[] = sprintf('• *%s* - %d update%s', $name, $count, $count === 1 ? '' : 's');

                continue;
            }

            $needsReview[] = sprintf('• *%s* - %s', $name, self::reviewReason($r));
        }

        $total = count($results);
        $summary = sprintf(
            '%d pushed, %d need review, %d failed',
            count($pushed),
            count($needsReview),
            count($failed),
        );

        $context = sprintf('%s | handle `%s`', $timestamp, $handle);
        if ($reposDir !== null && $reposDir !== '') {
            $context .= ' | '.$reposDir;
        }

        $countsLine = sprintf('*%d* repo%s processed - %s', $total, $total === 1 ? '' : 's', $summary);
        if ($upToDate > 0) {
            $countsLine .= sprintf(' | %d already up to date', $upToDate);
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => '📦 Craft maintenance summary', 'emoji' => true],
            ],
            [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => $context]],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $countsLine],
            ],
            ['type' => 'divider'],
        ];

        array_push($blocks, ...self::sectionBlocks(sprintf('✅ *Updated, committed & pushed* (%d)', count($pushed)), $pushed));
        array_push($blocks, ...self::sectionBlocks(sprintf('⚠️ *Updated but not pushed* (%d)', count($needsReview)), $needsReview));
        array_push($blocks, ...self::sectionBlocks(sprintf('❌ *Failed / other issues* (%d)', count($failed)), $failed));

        return [
            'username' => self::SENDER_NAME,
            'icon_emoji' => self::SENDER_ICON,
            'text' => sprintf('Craft maintenance summary: %s', $summary),
            'blocks' => $blocks,
        ];
    }

    /**
     * Build the human-readable reason a successful repo landed in the
     * "needs review" bucket: its commit/push state plus any quality-gate
     * issues that held the push back.
     */
    private static function reviewReason(RepoUpdateResult $r): string
    {
        $state = $r->committed ? 'committed, not pushed' : 'uncommitted changes';

        $issues = [];
        if (($r->testsFailed ?? 0) > 0) {
            $issues[] = sprintf('%d test%s failed', $r->testsFailed, $r->testsFailed === 1 ? '' : 's');
        }
        if (($r->phpstanErrors ?? 0) > 0) {
            $issues[] = sprintf('PHPStan: %d', $r->phpstanErrors);
        }
        if (! empty($r->crawlerServerErrorUrls)) {
            $n = count($r->crawlerServerErrorUrls);
            $issues[] = sprintf('%d URL%s returned 5xx', $n, $n === 1 ? '' : 's');
        } elseif ($r->crawlerFailed) {
            $issues[] = 'site-crawler failed';
        }

        return empty($issues) ? $state : sprintf('%s (%s)', $state, implode(', ', $issues));
    }

    /**
     * Render a bucket as one or more Slack section blocks. The heading sits on
     * the first block; overflow lines spill into additional, heading-less
     * section blocks so no single text field exceeds Slack's limit.
     *
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private static function sectionBlocks(string $heading, array $lines): array
    {
        if (empty($lines)) {
            return [[
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $heading."\n_none_"],
            ]];
        }

        $blocks = [];
        $current = $heading;
        foreach ($lines as $line) {
            $candidate = $current."\n".$line;
            if (mb_strlen($candidate) > self::MAX_SECTION_CHARS && $current !== $heading) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $current]];
                $current = $line;

                continue;
            }
            $current = $candidate;
        }
        $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $current]];

        return $blocks;
    }

    private static function firstLine(string $message): string
    {
        $line = trim((string) (preg_split('/\r\n|\r|\n/', $message)[0] ?? ''));

        return mb_strlen($line) > 180 ? mb_substr($line, 0, 179).'…' : $line;
    }
}
