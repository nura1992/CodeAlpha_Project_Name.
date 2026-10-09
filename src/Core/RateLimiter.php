<?php
/**
 * RateLimiter — simple fixed-window rate limiting backed by the rate_limits
 * table, so limits are shared across requests and (with MySQL) across
 * processes.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class RateLimiter
{
    /**
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public static function hit(string $bucket, int $max, int $windowSeconds): array
    {
        $now = time();
        $windowStart = (int) floor($now / $windowSeconds) * $windowSeconds;
        $expires = date('Y-m-d H:i:s', $windowStart + $windowSeconds + 5);

        // FOR UPDATE is MySQL-only; SQLite serialises writers itself.
        $forUpdate = Database::isSqlite() ? '' : ' FOR UPDATE';
        Database::transaction(static function () use ($bucket, $windowStart, $expires, $forUpdate) {
            $row = Database::one(
                'SELECT * FROM rate_limits WHERE bucket = :bucket AND window_start = :ws' . $forUpdate,
                ['bucket' => $bucket, 'ws' => $windowStart]
            );
            if ($row) {
                Database::query(
                    'UPDATE rate_limits SET hits = hits + 1 WHERE id = :id',
                    ['id' => $row['id']]
                );
            } else {
                Database::insert('rate_limits', [
                    'bucket'       => $bucket,
                    'window_start' => $windowStart,
                    'hits'         => 1,
                    'expires_at'   => $expires,
                ]);
            }
        });

        $hits = (int) Database::scalar(
            'SELECT hits FROM rate_limits WHERE bucket = :bucket AND window_start = :ws',
            ['bucket' => $bucket, 'ws' => $windowStart]
        );

        // Opportunistic cleanup of expired buckets.
        if (random_int(1, 50) === 1) {
            Database::delete('rate_limits', 'expires_at < :now', ['now' => date('Y-m-d H:i:s', $now)]);
        }

        $remaining = max(0, $max - $hits);
        return [
            'allowed'     => $hits <= $max,
            'remaining'   => $remaining,
            'retry_after' => $hits > $max ? ($windowStart + $windowSeconds - $now) : 0,
        ];
    }

    public static function tooManyRequests(array $result): never
    {
        header('Retry-After: ' . $result['retry_after']);
        Response::error('Too many requests. Please try again later.', 429, ['code' => 'rate_limited']);
    }
}
