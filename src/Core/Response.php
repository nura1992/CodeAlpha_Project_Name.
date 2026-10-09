<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function status(int $code): void
    {
        http_response_code($code);
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function error(ApiException $e): void
    {
        self::json([
            'error' => [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => $e->details,
            ],
        ], $e->status);
    }

    /** Stream a binary body (decrypted media). Headers must be set by the caller. */
    public static function binary(string $bytes, string $mime, string $disposition = 'inline', string $filename = 'file'): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: ' . $disposition . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        echo $bytes;
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
    }
}
