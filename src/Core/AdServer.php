<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Chooses an approved, in-budget advertisement for a placement while enforcing
 * per-user daily frequency caps. Every ad is labelled as sponsored by the client.
 */
final class AdServer
{
    public static function pick(int $viewerId, string $placement): ?array
    {
        if (!Features::enabled('business.ads')) {
            return null;
        }
        $cap = Settings::getInt('ads.default_frequency_cap', 3);
        $ad = Database::one(
            "SELECT a.* FROM advertisements a
             JOIN advertisers adv ON adv.id = a.advertiser_id AND adv.status = 'approved'
             WHERE a.status = 'approved' AND a.placement = ?
               AND (a.starts_at IS NULL OR a.starts_at <= NOW())
               AND (a.ends_at IS NULL OR a.ends_at > NOW())
               AND a.spent_minor < a.budget_minor
               AND (a.campaign_id IS NULL OR EXISTS (SELECT 1 FROM campaigns c WHERE c.id = a.campaign_id AND c.status = 'active'))
               AND (SELECT COUNT(*) FROM ad_events e WHERE e.advertisement_id = a.id AND e.user_id = ?
                    AND e.event_type = 'impression' AND e.created_at >= CURDATE()) < LEAST(a.daily_frequency_cap, ?)
             ORDER BY RAND() LIMIT 1",
            [$placement, $viewerId, $cap]
        );
        if ($ad === null) {
            return null;
        }
        self::record((int) $ad['id'], $viewerId, 'impression', (int) round(((int) $ad['cpm_minor']) / 1000));
        return [
            'id' => (int) $ad['id'],
            'title' => $ad['title'],
            'body' => $ad['body'],
            'media_type' => $ad['media_type'],
            'media_url' => $ad['storage_key'] ? '/api/media/ads/' . $ad['id'] : null,
            'click_url' => '/api/ads/' . $ad['id'] . '/click',
            'sponsored' => true,
        ];
    }

    /** Records an impression or click and updates spend; completes the ad when its budget is used. */
    public static function record(int $adId, ?int $userId, string $type, int $costMinor): void
    {
        Database::transaction(static function () use ($adId, $userId, $type, $costMinor): void {
            Database::exec('INSERT INTO ad_events (advertisement_id, user_id, event_type, cost_minor) VALUES (?, ?, ?, ?)',
                [$adId, $userId, $type, $costMinor]);
            Database::exec('UPDATE advertisements SET spent_minor = LEAST(budget_minor, spent_minor + ?) WHERE id = ?', [$costMinor, $adId]);
            Database::exec("UPDATE advertisements SET status = 'completed' WHERE id = ? AND spent_minor >= budget_minor", [$adId]);
        });
    }
}
