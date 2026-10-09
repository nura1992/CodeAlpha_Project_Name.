<?php
/**
 * MarketingService — campaigns and advertisements.
 *
 * Campaigns promote the platform (email/SMS/in-app, promo codes, referral
 * offers) with scheduling, budgets and analytics. Advertisements are paid
 * placements injected into community feeds and discovery, with an approval
 * workflow, frequency caps and impression/click tracking. Paid content is
 * always labelled; advertisers never get access to verification data or
 * sensitive personal details.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Uploader;

final class MarketingService
{
    // ------------------------------------------------------------------
    // Campaigns
    // ------------------------------------------------------------------

    public static function campaigns(?string $status = null): array
    {
        $where = '1=1';
        $params = [];
        if ($status) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        $rows = Database::all("SELECT * FROM campaigns WHERE $where ORDER BY created_at DESC", $params);
        return array_map(static function ($row) {
            $stats = self::campaignStats((int) $row['id']);
            return [
                'id' => (int) $row['id'], 'name' => $row['name'], 'objective' => $row['objective'],
                'audience' => json_decode((string) ($row['audience'] ?? ''), true) ?: [],
                'budget' => $row['budget'] !== null ? (float) $row['budget'] : null,
                'currency' => $row['currency'],
                'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'],
                'status' => $row['status'], 'promo_code' => $row['promo_code'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ] + $stats;
        }, $rows);
    }

    public static function campaign(int $id): ?array
    {
        $row = Database::one('SELECT * FROM campaigns WHERE id = :id', ['id' => $id]);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'], 'name' => $row['name'], 'objective' => $row['objective'],
            'audience' => json_decode((string) ($row['audience'] ?? ''), true) ?: [],
            'budget' => $row['budget'] !== null ? (float) $row['budget'] : null,
            'currency' => $row['currency'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'],
            'status' => $row['status'], 'promo_code' => $row['promo_code'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ] + self::campaignStats((int) $row['id']);
    }

    public static function createCampaign(array $input, int $staffId): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Database::insert('campaigns', [
            'name'       => substr(trim((string) $input['name']), 0, 150),
            'objective'  => substr((string) ($input['objective'] ?? ''), 0, 100) ?: null,
            'audience'   => json_encode($input['audience'] ?? [], JSON_UNESCAPED_SLASHES),
            'budget'     => isset($input['budget']) && $input['budget'] !== '' ? (float) $input['budget'] : null,
            'currency'   => substr((string) ($input['currency'] ?? 'NGN'), 0, 3),
            'starts_at'  => !empty($input['starts_at']) ? date('Y-m-d H:i:s', strtotime((string) $input['starts_at'])) : null,
            'ends_at'    => !empty($input['ends_at']) ? date('Y-m-d H:i:s', strtotime((string) $input['ends_at'])) : null,
            'status'     => in_array($input['status'] ?? '', ['draft', 'active', 'paused', 'completed'], true) ? $input['status'] : 'draft',
            'promo_code' => !empty($input['promo_code']) ? substr(strtoupper(trim((string) $input['promo_code'])), 0, 50) : null,
            'created_by' => $staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function updateCampaign(int $id, array $input, int $staffId): void
    {
        Database::update('campaigns', [
            'name'       => substr(trim((string) $input['name']), 0, 150),
            'objective'  => substr((string) ($input['objective'] ?? ''), 0, 100) ?: null,
            'audience'   => json_encode($input['audience'] ?? [], JSON_UNESCAPED_SLASHES),
            'budget'     => isset($input['budget']) && $input['budget'] !== '' ? (float) $input['budget'] : null,
            'currency'   => substr((string) ($input['currency'] ?? 'NGN'), 0, 3),
            'starts_at'  => !empty($input['starts_at']) ? date('Y-m-d H:i:s', strtotime((string) $input['starts_at'])) : null,
            'ends_at'    => !empty($input['ends_at']) ? date('Y-m-d H:i:s', strtotime((string) $input['ends_at'])) : null,
            'status'     => in_array($input['status'] ?? '', ['draft', 'active', 'paused', 'completed'], true) ? $input['status'] : 'draft',
            'promo_code' => !empty($input['promo_code']) ? substr(strtoupper(trim((string) $input['promo_code'])), 0, 50) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        AuditLog::record($staffId, 'campaign.updated', 'campaign', (string) $id);
    }

    public static function campaignStats(int $campaignId): array
    {
        $redemptions = (int) Database::scalar('SELECT COUNT(*) FROM campaign_redemptions WHERE campaign_id = :id', ['id' => $campaignId]);
        $adClicks = (int) Database::scalar(
            'SELECT COUNT(*) FROM ad_events e JOIN advertisements a ON a.id = e.ad_id WHERE a.campaign_id = :id AND e.event = \'click\'',
            ['id' => $campaignId]
        );
        $adImpressions = (int) Database::scalar(
            'SELECT COUNT(*) FROM ad_events e JOIN advertisements a ON a.id = e.ad_id WHERE a.campaign_id = :id AND e.event = \'impression\'',
            ['id' => $campaignId]
        );
        $spend = (float) Database::scalar(
            'SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
              WHERE t.status = \'success\' AND t.created_at >= COALESCE((SELECT created_at FROM campaigns WHERE id = :id), t.created_at)',
            ['id' => $campaignId]
        );
        return [
            'redemptions' => $redemptions,
            'ad_clicks' => $adClicks,
            'ad_impressions' => $adImpressions,
            'ctr' => $adImpressions > 0 ? round($adClicks / $adImpressions * 100, 2) : 0,
            'spend' => $spend,
        ];
    }

    // ------------------------------------------------------------------
    // Advertisements
    // ------------------------------------------------------------------

    public static function createAdvert(array $input, int $staffId, ?array $file = null): int
    {
        $type = in_array($input['type'] ?? '', ['image', 'video', 'text'], true) ? $input['type'] : 'text';
        $mediaPath = null;
        if ($file && in_array($type, ['image', 'video'], true)) {
            $stored = Uploader::storePublic($file, $type, 'ad');
            $mediaPath = substr($stored['path'], strlen('/uploads/'));
        }
        $now = date('Y-m-d H:i:s');
        return (int) Database::insert('advertisements', [
            'campaign_id'     => !empty($input['campaign_id']) ? (int) $input['campaign_id'] : null,
            'advertiser_name' => substr(trim((string) $input['advertiser_name']), 0, 150),
            'type'            => $type,
            'headline'        => substr((string) ($input['headline'] ?? ''), 0, 255) ?: null,
            'body'            => substr((string) ($input['body'] ?? ''), 0, 2000) ?: null,
            'media_path'      => $mediaPath,
            'cta_text'        => substr((string) ($input['cta_text'] ?? ''), 0, 100) ?: null,
            'cta_url'         => substr((string) ($input['cta_url'] ?? ''), 0, 255) ?: null,
            'placement'       => in_array($input['placement'] ?? '', ['community_feed', 'discovery'], true) ? $input['placement'] : 'community_feed',
            'status'          => 'pending',
            'daily_budget'    => isset($input['daily_budget']) && $input['daily_budget'] !== '' ? (float) $input['daily_budget'] : null,
            'currency'        => substr((string) ($input['currency'] ?? 'NGN'), 0, 3),
            'frequency_cap'   => max(1, (int) ($input['frequency_cap'] ?? 3)),
            'created_by'      => $staffId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public static function reviewAdvert(int $id, string $decision, int $staffId, string $notes = ''): void
    {
        $status = match ($decision) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'suspend' => 'suspended',
            default => throw new \InvalidArgumentException('Invalid decision.'),
        };
        Database::update('advertisements', [
            'status'       => $status,
            'reviewed_by'  => $staffId,
            'review_notes' => $notes !== '' ? substr($notes, 0, 1000) : null,
            'updated_at'   => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        AuditLog::record($staffId, 'advertisement.reviewed', 'advertisement', (string) $id, ['decision' => $decision]);
    }

    public static function adverts(?string $status = null): array
    {
        $where = '1=1';
        $params = [];
        if ($status) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        return Database::all("SELECT * FROM advertisements WHERE $where ORDER BY created_at DESC", $params);
    }

    /**
     * Serve an approved advert for a placement, honouring the per-user
     * frequency cap (max impressions per user per day).
     *
     * @return array|null the advert view, or null when nothing is eligible
     */
    public static function serveAd(?int $userId, string $placement, string $ip, string $userAgent): ?array
    {
        $rows = Database::all(
            "SELECT * FROM advertisements
              WHERE status = 'approved' AND placement = :placement
              ORDER BY RAND() LIMIT 20",
            ['placement' => $placement]
        );
        // RAND() is MySQL; SQLite uses RANDOM().
        if (Database::isSqlite()) {
            $rows = Database::all(
                "SELECT * FROM advertisements
                  WHERE status = 'approved' AND placement = :placement
                  ORDER BY RANDOM() LIMIT 20",
                ['placement' => $placement]
            );
        }
        foreach ($rows as $row) {
            if ($userId && (int) $row['frequency_cap'] > 0) {
                $today = (int) Database::scalar(
                    "SELECT COUNT(*) FROM ad_events
                      WHERE ad_id = :id AND event = 'impression'
                        AND created_at >= :today" . ($userId ? ' AND user_id = :uid' : ''),
                    ['id' => $row['id'], 'today' => date('Y-m-d 00:00:00')] + ($userId ? ['uid' => $userId] : [])
                );
                if ($today >= (int) $row['frequency_cap']) {
                    continue;
                }
            }
            // Record the impression immediately.
            Database::insert('ad_events', [
                'ad_id'      => $row['id'],
                'user_id'    => $userId,
                'event'      => 'impression',
                'ip_hash'    => hash('sha256', $ip),
                'user_agent' => substr($userAgent, 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return self::adView((int) $row['id'], $row);
        }
        return null;
    }

    public static function recordClick(int $adId, ?int $userId, string $ip, string $userAgent): void
    {
        Database::insert('ad_events', [
            'ad_id'      => $adId,
            'user_id'    => $userId,
            'event'      => 'click',
            'ip_hash'    => hash('sha256', $ip),
            'user_agent' => substr($userAgent, 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function adStats(int $adId): array
    {
        $impressions = (int) Database::scalar("SELECT COUNT(*) FROM ad_events WHERE ad_id = :id AND event = 'impression'", ['id' => $adId]);
        $clicks = (int) Database::scalar("SELECT COUNT(*) FROM ad_events WHERE ad_id = :id AND event = 'click'", ['id' => $adId]);
        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0,
        ];
    }

    public static function adView(int $adId, ?array $row = null): array
    {
        $row = $row ?? Database::one('SELECT * FROM advertisements WHERE id = :id', ['id' => $adId]);
        if (!$row) {
            return [];
        }
        return [
            'id'              => (int) $row['id'],
            'advertiser_name' => $row['advertiser_name'],
            'type'            => $row['type'],
            'headline'        => $row['headline'],
            'body'            => $row['body'],
            'media_url'       => $row['media_path'] ? '/uploads/' . $row['media_path'] : null,
            'cta_text'        => $row['cta_text'] ?: 'Learn more',
            'cta_url'         => $row['cta_url'],
            'is_ad'           => true, // Paid content must be clearly labelled.
        ] + self::adStats($adId);
    }
}
