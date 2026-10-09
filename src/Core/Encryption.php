<?php
/**
 * Encryption — AES-256-CBC authenticated encryption for secrets at rest
 * (payment gateway credentials, webhook secrets, TURN passwords).
 *
 * The key is derived from config('app.key') using HKDF-SHA256 so any 32-byte
 * (or base64) app key works. Each encryption uses a random IV and stores an
 * HMAC-SHA256 authentication tag, giving us authenticated encryption.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

use RuntimeException;

final class Encryption
{
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $raw = (string) config('app.key', '');
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            $raw = $decoded === false ? substr($raw, 7) : $decoded;
        }
        if (strlen($raw) < 16) {
            throw new RuntimeException('APP_KEY must be at least 16 bytes. Generate one with: php -r "echo \'base64:\'.base64_encode(random_bytes(32));"');
        }
        self::$key = hash_hkdf('sha256', $raw, 32, 'codealpha-encryption');
        return self::$key;
    }

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }
        $payload = $iv . $cipher;
        $tag = hash_hmac('sha256', $payload, self::key(), true);
        return 'v1:' . base64_encode($tag . $payload);
    }

    public static function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, 'v1:')) {
            throw new RuntimeException('Unsupported encrypted payload.');
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < 48) {
            throw new RuntimeException('Corrupt encrypted payload.');
        }
        $tag = substr($raw, 0, 32);
        $body = substr($raw, 32);
        $expected = hash_hmac('sha256', $body, self::key(), true);
        if (!hash_equals($expected, $tag)) {
            throw new RuntimeException('Encrypted payload failed authentication.');
        }
        $iv = substr($body, 0, 16);
        $cipher = substr($body, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed.');
        }
        return $plain;
    }

    /** Encrypt if not empty; returns null for empty input. */
    public static function encryptOrNull(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        return self::encrypt($plaintext);
    }

    public static function decryptOrNull(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        return self::decrypt($payload);
    }
}
