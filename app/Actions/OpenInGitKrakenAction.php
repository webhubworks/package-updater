<?php

namespace App\Actions;

use Symfony\Component\Process\Process;

final class OpenInGitKrakenAction
{
    /**
     * Open each repo as its own GitKraken tab via the gitkraken:// URL scheme
     * (macOS-only — uses `open`). Best-effort: failures are reported and
     * skipped, never thrown.
     *
     * @param  list<string>  $repoPaths
     * @return array{opened: int, failed: list<string>}
     */
    public static function open(array $repoPaths): array
    {
        $opened = 0;
        $failed = [];

        foreach ($repoPaths as $path) {
            $url = 'gitkraken://repo/path/' . $path;
            $process = new Process(['open', $url]);
            $process->setTimeout(15);

            try {
                $process->run();
            } catch (\Throwable) {
                $failed[] = $path;
                continue;
            }

            if (! $process->isSuccessful()) {
                $failed[] = $path;
                continue;
            }

            $opened++;
        }

        return ['opened' => $opened, 'failed' => $failed];
    }
}
