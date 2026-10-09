<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Delivers OTPs and in-app notifications.
 * OTP_DRIVER=log writes codes to storage/logs (development ONLY).
 * OTP_DRIVER=mail sends email via PHP mail(); plug an SMS gateway into sendSms() for phone OTPs.
 * Web Push delivery hooks in via the comm.push module (subscriptions table not yet included).
 */
final class Notifier
{
    public static function sendOtp(string $destination, string $channel, string $code, string $purpose): void
    {
        $driver = Env::get('OTP_DRIVER', 'log');
        $masked = $channel === 'email'
            ? preg_replace('/(^.).*(@.*$)/', '$1***$2', $destination)
            : substr($destination, 0, 4) . '****' . substr($destination, -2);
        $message = "Your verification code is {$code}. It expires in " . Settings::getInt('auth.otp_ttl_minutes', 10) . ' minutes. Never share it.';

        if ($driver === 'mail' && $channel === 'email') {
            $from = Env::get('MAIL_FROM', 'no-reply@example.com');
            @mail($destination, 'Your verification code', $message, "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8");
            return;
        }
        if ($channel === 'sms' && $driver !== 'log') {
            self::sendSms($destination, $message);
            return;
        }
        // Development fallback. Never enable in production.
        $dir = SecureStorage::root() . '/logs';
        file_put_contents($dir . '/otp-dev.log', date('c') . " [{$purpose}] {$masked}: {$code}\n", FILE_APPEND | LOCK_EX);
    }

    private static function sendSms(string $to, string $message): void
    {
        // Integration point: call your SMS provider's API here with credentials from config/.env.
        throw new \RuntimeException('No SMS provider is configured. Set OTP_DRIVER=log for development or implement sendSms().');
    }

    /** @param array<string,mixed> $data */
    public static function notify(int $userId, string $type, string $title, ?string $body = null, array $data = []): void
    {
        $column = match ($type) {
            'message' => 'notify_messages',
            'call' => 'notify_calls',
            'community', 'reaction', 'comment', 'mention', 'follow', 'share' => 'notify_community',
            default => null,
        };
        if ($column !== null) {
            $enabled = Database::value("SELECT $column FROM user_settings WHERE user_id = ?", [$userId]);
            if ($enabled !== null && !(bool) $enabled) {
                return;
            }
        }
        Database::exec(
            'INSERT INTO notifications (user_id, type, title, body, data) VALUES (?, ?, ?, ?, ?)',
            [$userId, $type, mb_substr($title, 0, 160), $body === null ? null : mb_substr($body, 0, 500),
             json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]
        );
    }
}
