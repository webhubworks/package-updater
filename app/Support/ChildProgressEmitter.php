<?php

namespace App\Support;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Produces the onProgress callback that `*:single` child commands hand to
 * UpdateRepoAction. Each progress event is serialised as a single JSON line
 * on stdout (`{"pu_event":..., "pu_type":..., "pu_payload":...}`) so the
 * parent's parallel runner can read it incrementally and surface the last-N
 * output rows under the spinner.
 *
 * The final result line written by the child still wins in parseChildOutput
 * because it has the `status`/`repoPath`/`message` keys; event lines do not.
 */
final class ChildProgressEmitter
{
    public static function for(OutputInterface $output): Closure
    {
        return static function (string $event, ?string $type, ?string $payload) use ($output): void {
            $encoded = json_encode([
                'pu_event' => $event,
                'pu_type' => $type,
                'pu_payload' => (string) $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $output->writeln($encoded);
            }
        };
    }
}
