<?php
/**
 * Sms — transactional SMS with pluggable transports.
 *
 * Transports:
 *   log  — appends to storage/logs/sms.log (default; used in development)
 *   http — POSTs JSON to a configurable HTTP API endpoint (generic provider)
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Sms
{
    public static function send(string $phone, string $message): bool
    {
        $transport = (string) config('sms.transport', 'log');

        try {
            $dir = (string) config('storage.logs');
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            file_put_contents(
                $dir . '/sms.log',
                json_encode(['to' => $phone, 'message' => $message, 'at' => date('c')], JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            error_log('[CodeAlpha] sms log failure: ' . $e->getMessage());
        }

        if ($transport === 'http') {
            return self::sendHttp($phone, $message);
        }
        return true;
    }

    private static function sendHttp(string $phone, string $message): bool
    {
        $cfg = config('sms.http', []);
        $endpoint = (string) ($cfg['url'] ?? '');
        if ($endpoint === '') {
            return false;
        }
        $payload = json_encode([
            'to'      => $phone,
            'message' => $message,
            'sender'  => (string) ($cfg['sender'] ?? 'CodeAlpha'),
        ]);
        $headers = ['Content-Type: application/json'];
        $token = (string) ($cfg['token'] ?? '');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 10,
        ]]);
        $result = @file_get_contents($endpoint, false, $context);
        return $result !== false;
    }
}
