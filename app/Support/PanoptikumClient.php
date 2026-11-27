<?php

namespace PackageUpdater\Support;

/**
 * Reads the work lists from Panoptikum's `GET /api/v1/npm-updates` and
 * `GET /api/v1/composer-updates`, authenticated with the bearer token saved
 * by `pu setup`. Uses a PHP stream (allow_url_fopen) so it needs no
 * ext-curl, like the Slack fallback.
 */
final class PanoptikumClient
{
    /**
     * @return array{repositories: list<array<string, mixed>>, blocked_versions: list<array<string, mixed>>}
     */
    public static function npmUpdates(string $baseUrl, string $token): array
    {
        $data = self::get($baseUrl, $token, 'npm-updates');

        return [
            'repositories' => array_values($data['repositories']),
            'blocked_versions' => array_values(is_array($data['blocked_versions'] ?? null) ? $data['blocked_versions'] : []),
        ];
    }

    /**
     * @return list<array{repository: string, cells: list<string>, packages: list<string>}>
     */
    public static function composerUpdates(string $baseUrl, string $token): array
    {
        return array_values(self::get($baseUrl, $token, 'composer-updates')['repositories']);
    }

    /**
     * @return array{repositories: array<mixed>}&array<string, mixed>
     */
    private static function get(string $baseUrl, string $token, string $endpoint): array
    {
        $url = rtrim($baseUrl, '/').'/api/v1/'.$endpoint;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        if ($body === false && $headers === []) {
            $error = error_get_last()['message'] ?? 'no response';

            throw new \RuntimeException("Panoptikum request to {$url} failed: {$error}");
        }

        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        $data = json_decode((string) $body, true);
        if ($status !== 200 || ! is_array($data) || ! is_array($data['repositories'] ?? null)) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : trim((string) $body);

            throw new \RuntimeException("Panoptikum answered HTTP {$status} for {$url}: ".mb_substr($message, 0, 300));
        }

        return $data;
    }
}
