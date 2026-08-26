<?php

namespace PackageUpdater\Actions;

final class LastRunStore
{
    /**
     * Persist the resolved command name + arguments + options so that the
     * `retry` command can replay it non-interactively.
     *
     * @param  array<string, scalar|null>  $arguments
     * @param  array<string, scalar|bool|null>  $options
     */
    public static function save(string $command, array $arguments, array $options): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
        }

        $payload = [
            'command' => $command,
            'arguments' => $arguments,
            'options' => $options,
            'timestamp' => date('c'),
        ];

        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{command: string, arguments: array<string, mixed>, options: array<string, mixed>, timestamp: ?string}|null
     */
    public static function load(): ?array
    {
        $path = self::path();
        if (! is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['command']) || ! is_string($data['command'])) {
            return null;
        }

        return [
            'command' => $data['command'],
            'arguments' => is_array($data['arguments'] ?? null) ? $data['arguments'] : [],
            'options' => is_array($data['options'] ?? null) ? $data['options'] : [],
            'timestamp' => is_string($data['timestamp'] ?? null) ? $data['timestamp'] : null,
        ];
    }

    public static function path(): string
    {
        return base_path('logs/last-run.json');
    }

    /**
     * Persist the per-repo results of the most recent run so post-run tools
     * (e.g. `open`) can act on them later without re-executing anything.
     *
     * @param  list<array<string, mixed>>  $results  RepoUpdateResult::toArray() values
     */
    public static function saveResults(string $command, array $results): void
    {
        $path = self::resultsPath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
        }

        @file_put_contents($path, json_encode([
            'command' => $command,
            'results' => $results,
            'timestamp' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{command: string, results: list<array<string, mixed>>, timestamp: ?string}|null
     */
    public static function loadResults(): ?array
    {
        $path = self::resultsPath();
        if (! is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['command']) || ! is_array($data['results'] ?? null)) {
            return null;
        }

        return [
            'command' => (string) $data['command'],
            'results' => array_values($data['results']),
            'timestamp' => is_string($data['timestamp'] ?? null) ? $data['timestamp'] : null,
        ];
    }

    public static function resultsPath(): string
    {
        return base_path('logs/last-results.json');
    }
}
