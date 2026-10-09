<?php
declare(strict_types=1);

namespace App\Core;

/** Fixed-window rate limiter backed by MySQL (works without Redis). */
final class RateLimiter
{
    public static function hit(string $bucket, int $max, int $windowSeconds): void
    {
        $key = hash('sha256', $bucket);
        $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
        Database::exec(
            'INSERT INTO rate_limits (bucket_key, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                                     window_start = VALUES(window_start)',
            [$key, $windowStart]
        );
        $hits = (int) Database::value('SELECT hits FROM rate_limits WHERE bucket_key = ?', [$key]);
        if ($hits > $max) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many requests. Please wait a moment and try again.');
        }
    }
}
