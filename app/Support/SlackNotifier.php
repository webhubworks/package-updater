<?php

namespace App\Support;

/**
 * Posts a Block Kit payload to a Slack incoming webhook. Uses cURL directly so
 * it works in the built PHAR without depending on the (optional) HTTP client
 * facade. Throws on any transport error or non-2xx response so the caller can
 * surface a warning without aborting the run.
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

        if ($status < 200 || $status >= 300) {
            $detail = is_string($body) && $body !== '' ? ': '.trim($body) : '';
            throw new \RuntimeException("Slack returned HTTP {$status}{$detail}");
        }
    }
}
