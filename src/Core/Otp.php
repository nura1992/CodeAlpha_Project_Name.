<?php
declare(strict_types=1);

namespace App\Core;

/** One-time codes for registration and password reset. Codes are stored as keyed HMACs. */
final class Otp
{
    private const MAX_ATTEMPTS = 5;

    public static function issue(string $destination, string $channel, string $purpose, ?int $userId): void
    {
        RateLimiter::hit('otp:' . $purpose . ':' . hash('sha256', $destination), 3, 600);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::transaction(static function () use ($destination, $channel, $purpose, $userId, $code): void {
            Database::exec(
                'UPDATE otp_codes SET consumed_at = NOW() WHERE destination = ? AND purpose = ? AND consumed_at IS NULL',
                [$destination, $purpose]
            );
            Database::exec(
                'INSERT INTO otp_codes (user_id, destination, channel, purpose, code_hash, expires_at)
                 VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)',
                [$userId, $destination, $channel, $purpose, Crypto::hmac($code, 'otp'),
                 Settings::getInt('auth.otp_ttl_minutes', 10)]
            );
        });
        Notifier::sendOtp($destination, $channel, $code, $purpose);
    }

    /** Returns the matched OTP row when the code is valid, otherwise null. */
    public static function verify(string $destination, string $purpose, string $code): ?array
    {
        $row = Database::one(
            'SELECT * FROM otp_codes WHERE destination = ? AND purpose = ? AND consumed_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [$destination, $purpose]
        );
        if ($row === null || strtotime($row['expires_at'] . ' UTC') < time() || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return null;
        }
        Database::exec('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        if (!hash_equals((string) $row['code_hash'], Crypto::hmac(trim($code), 'otp'))) {
            return null;
        }
        Database::exec('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?', [$row['id']]);
        return $row;
    }
}
