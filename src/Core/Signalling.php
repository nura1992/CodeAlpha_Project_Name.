<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Issues short-lived signed tickets that let a call participant open the WebSocket
 * signalling service, and TURN credentials (time-limited, coturn "use-auth-secret" scheme).
 * Ticket format: base64url(json payload) . '.' . base64url(HMAC-SHA256(payload, SIGNALLING_SECRET))
 */
final class Signalling
{
    public static function ticket(int $callId, int $userId): string
    {
        $secret = self::secret();
        $payload = self::b64(json_encode([
            'cid' => $callId,
            'uid' => $userId,
            'exp' => time() + 120,
            'n' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $secret, true));
    }

    public static function iceServers(int $userId): array
    {
        $servers = [];
        $stun = Env::get('STUN_URL');
        if ($stun) {
            $servers[] = ['urls' => [$stun]];
        }
        $turn = Env::get('TURN_URL');
        $turnSecret = Env::get('TURN_SECRET');
        if ($turn && $turnSecret) {
            $expiry = time() + 3600;
            $username = $expiry . ':' . $userId;
            $servers[] = [
                'urls' => [$turn],
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $turnSecret, true)),
            ];
        }
        return $servers;
    }

    private static function secret(): string
    {
        $secret = (string) Env::get('SIGNALLING_SECRET', '');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('SIGNALLING_SECRET must be set to at least 32 characters.');
        }
        return $secret;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
