<?php

namespace App\Support;

/**
 * Posts a Block Kit payload to a Slack incoming webhook. Prefers cURL when the
 * extension is available and falls back to a PHP stream POST (allow_url_fopen)
 * otherwise, so it works on hosts without ext-curl. Throws on any transport
 * error or non-2xx response so the caller can surface a warning without
 * aborting the run.
 */
final class SlackNotifier
{
    /** @param array<string, mixed> $payload */
    public static function send(string $webhookUrl, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Could not encode Slack payload: '.json_last_error_msg());
        }

        if (function_exists('curl_init')) {
            self::sendViaCurl($webhookUrl, $json);

            return;
        }

        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            self::sendViaStream($webhookUrl, $json);

            return;
        }

        throw new \RuntimeException('No HTTP transport available: enable ext-curl or allow_url_fopen.');
    }

    private static function sendViaCurl(string $webhookUrl, string $json): void
    {
        $ch = curl_init($webhookUrl);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise cURL for the Slack request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno !== 0) {
            throw new \RuntimeException("Slack request failed: {$error}");
        }

        self::assertOk($status, is_string($body) ? $body : '');
    }

    private static function sendViaStream(string $webhookUrl, string $json): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nContent-Length: ".strlen($json)."\r\n",
                'content' => $json,
                'timeout' => 15,
                'ignore_errors' => true, // return the body on 4xx/5xx instead of false
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($webhookUrl, false, $context);

        // $http_response_header is populated by the stream wrapper in the local
        // scope after a request, even when the body is empty.
        $headers = $http_response_header ?? [];
        if ($body === false && empty($headers)) {
            $err = error_get_last();
            $detail = isset($err['message']) ? ': '.$err['message'] : '';
            throw new \RuntimeException("Slack request failed{$detail}");
        }

        self::assertOk(self::statusFromHeaders($headers), is_string($body) ? $body : '');
    }

    /** @param list<string> $headers */
    private static function statusFromHeaders(array $headers): int
    {
        // The status line (e.g. "HTTP/1.1 200 OK") is the first entry; a
        // redirect chain can prepend earlier ones, so take the last match.
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }

    private static function assertOk(int $status, string $body): void
    {
        if ($status < 200 || $status >= 300) {
            $detail = trim($body) !== '' ? ': '.trim($body) : '';
            throw new \RuntimeException("Slack returned HTTP {$status}{$detail}");
        }
    }
}
