<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * AES-256-GCM encryption at rest and HMAC helpers.
 * Keys are derived per purpose from APP_KEY so a leaked key for one purpose
 * (e.g. OTP HMAC) cannot decrypt another (e.g. verification videos).
 * Blob format: 'v1' | iv(12) | tag(16) | ciphertext
 */
final class Crypto
{
    private static function key(string $purpose): string
    {
        return hash_hmac('sha256', 'codealpha|' . $purpose, Env::appKey(), true);
    }

    public static function encrypt(string $plain, string $purpose): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return 'v1' . $iv . $tag . $cipher;
    }

    public static function decrypt(string $blob, string $purpose): string
    {
        if (strlen($blob) < 2 + 12 + 16 || substr($blob, 0, 2) !== 'v1') {
            throw new RuntimeException('Unsupported ciphertext format.');
        }
        $iv = substr($blob, 2, 12);
        $tag = substr($blob, 14, 16);
        $cipher = substr($blob, 30);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed: data is corrupt or the key changed.');
        }
        return $plain;
    }

    public static function hmac(string $data, string $purpose): string
    {
        return hash_hmac('sha256', $data, self::key('hmac|' . $purpose));
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
