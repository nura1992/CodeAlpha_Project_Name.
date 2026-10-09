<?php
/**
 * OtpService — one-time passcodes for registration, login, password reset
 * and account recovery.
 *
 * Codes are 6 digits, stored only as bcrypt hashes, expire after 10 minutes,
 * and are limited to 5 verification attempts. Resending is rate limited.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\Database;
use CodeAlpha\Core\Mailer;
use CodeAlpha\Core\Sms;

final class OtpService
{
    private const TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    /**
     * Create and send an OTP.
     *
     * @return array{code:string, otp_id:int} the plain code (for dev display/logging only)
     */
    public static function issue(?int $userId, string $channel, string $destination, string $purpose): array
    {
        self::purgeExpired();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $id = Database::insert('otps', [
            'user_id'     => $userId,
            'channel'     => $channel,
            'destination' => $destination,
            'code_hash'   => password_hash($code, PASSWORD_BCRYPT),
            'purpose'     => $purpose,
            'attempts'    => 0,
            'expires_at'  => date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $appName = (string) config('app.name', 'CodeAlpha');
        $body = 'Your ' . $appName . ' verification code is ' . $code . '. It expires in '
            . self::TTL_MINUTES . ' minutes. If you did not request this code, ignore this message.';
        if ($channel === 'email') {
            Mailer::send($destination, $appName . ' verification code', Mailer::layout('Your verification code', '<p>' . nl2br(e($body)) . '</p>'), $body);
        } else {
            Sms::send($destination, $body);
        }

        return ['code' => $code, 'otp_id' => (int) $id];
    }

    /**
     * Verify a code. Returns the OTP row when valid, null otherwise.
     * Consumes the code on success.
     */
    public static function verify(?int $userId, string $destination, string $purpose, string $code): ?array
    {
        $otp = Database::one(
            'SELECT * FROM otps
              WHERE destination = :dest AND purpose = :purpose AND consumed_at IS NULL AND expires_at > :now
              ORDER BY id DESC LIMIT 1',
            ['dest' => $destination, 'purpose' => $purpose, 'now' => date('Y-m-d H:i:s')]
        );
        if (!$otp) {
            return null;
        }
        if ((int) $otp['attempts'] >= self::MAX_ATTEMPTS) {
            return null;
        }
        if (!password_verify($code, (string) $otp['code_hash'])) {
            Database::update('otps', ['attempts' => (int) $otp['attempts'] + 1], 'id = :id', ['id' => $otp['id']]);
            return null;
        }
        Database::update('otps', [
            'verified_at' => date('Y-m-d H:i:s'),
            'consumed_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $otp['id']]);
        return $otp;
    }

    /** Whether a fresh OTP may be (re)sent — rate limiting hook for callers. */
    public static function canResend(string $destination, string $purpose, int $cooldownSeconds = 60): bool
    {
        $recent = Database::one(
            'SELECT created_at FROM otps WHERE destination = :dest AND purpose = :purpose ORDER BY id DESC LIMIT 1',
            ['dest' => $destination, 'purpose' => $purpose]
        );
        if (!$recent) {
            return true;
        }
        return strtotime((string) $recent['created_at']) < (time() - $cooldownSeconds);
    }

    private static function purgeExpired(): void
    {
        Database::delete('otps', 'expires_at < :now', ['now' => date('Y-m-d H:i:s', time() - 3600)]);
    }
}
