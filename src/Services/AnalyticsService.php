<?php
/**
 * AnalyticsService — system analytics for the dashboards.
 *
 * All queries are read-only aggregates. Sensitive data (verification media,
 * message bodies, payment secrets) is never included.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\Database;

final class AnalyticsService
{
    public static function overview(): array
    {
        $since24h = date('Y-m-d H:i:s', time() - 86400);
        $since7d = date('Y-m-d H:i:s', time() - 7 * 86400);
        return [
            'users' => [
                'total'         => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
                'active'        => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'active' AND deleted_at IS NULL"),
                'suspended'     => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'suspended'"),
                'staff'         => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE is_staff = 1 AND deleted_at IS NULL'),
                'new_24h'       => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE created_at >= :d', ['d' => $since24h]),
                'new_7d'        => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE created_at >= :d', ['d' => $since7d]),
                'verified'      => (int) Database::scalar("SELECT COUNT(*) FROM profiles WHERE verification_status = 'approved'"),
                'pending_verification' => (int) Database::scalar("SELECT COUNT(*) FROM profiles WHERE verification_status = 'pending'"),
            ],
            'matching' => [
                'matches_active'  => (int) Database::scalar("SELECT COUNT(*) FROM matches WHERE status = 'active'"),
                'likes_24h'       => (int) Database::scalar('SELECT COUNT(*) FROM likes WHERE created_at >= :d', ['d' => $since24h]),
                'messages_24h'    => (int) Database::scalar('SELECT COUNT(*) FROM messages WHERE created_at >= :d', ['d' => $since24h]),
                'calls_24h'       => (int) Database::scalar('SELECT COUNT(*) FROM calls WHERE created_at >= :d', ['d' => $since24h]),
            ],
            'community' => [
                'posts'        => (int) Database::scalar("SELECT COUNT(*) FROM community_posts WHERE status = 'published'"),
                'posts_24h'    => (int) Database::scalar("SELECT COUNT(*) FROM community_posts WHERE created_at >= :d", ['d' => $since24h]),
                'comments_24h' => (int) Database::scalar('SELECT COUNT(*) FROM post_comments WHERE created_at >= :d', ['d' => $since24h]),
                'reports_open' => (int) Database::scalar("SELECT COUNT(*) FROM user_reports WHERE status IN ('open','in_review')"),
            ],
            'revenue' => [
                'transactions_success' => (int) Database::scalar("SELECT COUNT(*) FROM transactions WHERE status = 'success'"),
                'revenue_total'        => (float) Database::scalar("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE status = 'success'"),
                'revenue_30d'          => (float) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE status = \'success\' AND created_at >= :d', ['d' => date('Y-m-d H:i:s', time() - 30 * 86400)]),
                'active_subscriptions' => (int) Database::scalar("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'"),
                'refunds_total'        => (float) Database::scalar("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE status = 'success'"),
            ],
            'support' => [
                'tickets_open' => (int) Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')"),
            ],
            'ads' => [
                'impressions_24h' => (int) Database::scalar("SELECT COUNT(*) FROM ad_events WHERE event = 'impression' AND created_at >= :d", ['d' => $since24h]),
                'clicks_24h'      => (int) Database::scalar("SELECT COUNT(*) FROM ad_events WHERE event = 'click' AND created_at >= :d", ['d' => $since24h]),
            ],
        ];
    }

    /** Daily sign-ups for the last 30 days (for charts). */
    public static function signupsPerDay(int $days = 30): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', time() - $i * 86400);
            $out[] = [
                'date'  => $day,
                'count' => (int) Database::scalar(
                    'SELECT COUNT(*) FROM users WHERE created_at >= :d AND created_at < :d2',
                    ['d' => $day . ' 00:00:00', 'd2' => $day . ' 23:59:59']
                ),
            ];
        }
        return $out;
    }

    /** Revenue per day for the last 30 days. */
    public static function revenuePerDay(int $days = 30): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', time() - $i * 86400);
            $out[] = [
                'date'  => $day,
                'total' => (float) Database::scalar(
                    "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE status = 'success' AND created_at >= :d AND created_at < :d2",
                    ['d' => $day . ' 00:00:00', 'd2' => $day . ' 23:59:59']
                ),
            ];
        }
        return $out;
    }

    /** Gender distribution of members. */
    public static function genderDistribution(): array
    {
        return Database::all(
            "SELECT gender, COUNT(*) AS n FROM users WHERE deleted_at IS NULL AND gender IS NOT NULL GROUP BY gender"
        );
    }

    /** Verification funnel. */
    public static function verificationFunnel(): array
    {
        return Database::all(
            'SELECT verification_status, COUNT(*) AS n FROM profiles GROUP BY verification_status'
        );
    }
}
