<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdServer;
use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Features;
use App\Core\Notifier;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecureStorage;
use App\Core\Validator;

/**
 * Campaigns, promotional codes, advertisers and advertisements.
 * Advertisers never receive access to verification or personal data. Every advert is labelled
 * "Sponsored" by the client, must pass staff review, and is capped by budget and frequency.
 */
final class CampaignController
{
    private const STATUSES = ['draft', 'scheduled', 'active', 'paused', 'completed', 'cancelled'];
    private const AD_IMAGE = ['image/jpeg', 'image/png', 'image/webp'];
    private const AD_VIDEO = ['video/mp4', 'video/webm'];

    // --------------------------------------------------------------- campaigns
    public function campaigns(Request $r): array
    {
        return ['items' => Database::all(
            'SELECT id, name, objective, budget_minor, currency, promo_code, discount_percent, starts_at, ends_at, status, channels, created_at
             FROM campaigns ORDER BY id DESC LIMIT 100'
        )];
    }

    public function saveCampaign(Request $r): array
    {
        $id = $r->param('id') !== '' ? (int) $r->param('id') : null;
        $name = Validator::str($r->input('name'), 'name', 3, 120);
        $objective = Validator::str($r->input('objective'), 'objective', 0, 255, false);
        $budgetNaira = Validator::int($r->input('budget_naira', 0), 'budget_naira', 0, 100000000);
        $status = Validator::in($r->input('status', 'draft'), 'status', self::STATUSES);
        $promo = $r->input('promo_code') ? strtoupper(Validator::str($r->input('promo_code'), 'promo_code', 3, 32)) : null;
        if ($promo !== null && !preg_match('/^[A-Z0-9_-]+$/', $promo)) {
            Validator::fail('promo_code', 'Promo codes may contain letters, numbers, dashes and underscores.');
        }
        $discount = $r->input('discount_percent') ? Validator::int($r->input('discount_percent'), 'discount_percent', 1, 90) : null;
        $starts = $this->dateInput($r->input('starts_at'), 'starts_at');
        $ends = $this->dateInput($r->input('ends_at'), 'ends_at');
        if ($starts && $ends && $ends <= $starts) {
            Validator::fail('ends_at', 'End date must be after the start date.');
        }
        $channels = is_array($r->input('channels')) ? array_values(array_intersect($r->input('channels'), ['in_app', 'email', 'sms'])) : ['in_app'];
        $audience = is_array($r->input('audience')) ? $r->input('audience') : [];
        $params = [$name, $objective, json_encode($audience, JSON_THROW_ON_ERROR), $budgetNaira * 100, json_encode($channels, JSON_THROW_ON_ERROR),
                   $promo, $discount, $starts, $ends, $status];

        if ($id === null) {
            $newId = Database::insert(
                'INSERT INTO campaigns (name, objective, audience, budget_minor, channels, promo_code, discount_percent, starts_at, ends_at, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$params, $r->userId()]
            );
            Audit::log($r->userId(), 'campaign.created', 'campaign', $newId, null, null, ['name' => $name, 'status' => $status]);
            Response::status(201);
            return ['id' => $newId];
        }
        if (!Database::value('SELECT 1 FROM campaigns WHERE id = ?', [$id])) {
            throw new ApiException(404, 'NOT_FOUND', 'Campaign not found.');
        }
        Database::exec(
            'UPDATE campaigns SET name = ?, objective = ?, audience = ?, budget_minor = ?, channels = ?, promo_code = ?, discount_percent = ?,
             starts_at = ?, ends_at = ?, status = ? WHERE id = ?',
            [...$params, $id]
        );
        Audit::log($r->userId(), 'campaign.updated', 'campaign', $id, null, null, ['status' => $status]);
        return ['id' => $id];
    }

    /** Sends an in-app notification to every active member. Email and SMS need a configured provider. */
    public function sendCampaign(Request $r): array
    {
        $id = (int) $r->param('id');
        $campaign = Database::one('SELECT * FROM campaigns WHERE id = ?', [$id]);
        if ($campaign === null || $campaign['status'] !== 'active') {
            throw new ApiException(409, 'INVALID_STATE', 'Only active campaigns can be sent.');
        }
        $title = Validator::str($r->input('title'), 'title', 3, 160);
        $body = Validator::str($r->input('body'), 'body', 3, 500);
        $sent = Database::exec(
            "INSERT INTO notifications (user_id, type, title, body, data)
             SELECT u.id, 'campaign', ?, ?, ? FROM users u WHERE u.account_type = 'member' AND u.status = 'active'",
            [$title, $body, json_encode(['campaign_id' => $id], JSON_THROW_ON_ERROR)]
        );
        Audit::log($r->userId(), 'campaign.sent', 'campaign', $id, null, null, ['in_app_recipients' => $sent]);
        return ['recipients' => $sent];
    }

    public function campaignAnalytics(Request $r): array
    {
        $id = (int) $r->param('id');
        $ads = Database::one(
            "SELECT COALESCE(SUM(e.event_type = 'impression'), 0) AS impressions, COALESCE(SUM(e.event_type = 'click'), 0) AS clicks,
                    COALESCE(SUM(e.cost_minor), 0) AS spend_minor
             FROM ad_events e JOIN advertisements a ON a.id = e.advertisement_id WHERE a.campaign_id = ?",
            [$id]
        );
        $conv = Database::one(
            "SELECT COUNT(*) AS conversions, COALESCE(SUM(amount_minor), 0) AS revenue_minor FROM transactions WHERE campaign_id = ? AND status IN ('successful','partially_refunded','refunded')",
            [$id]
        );
        $impressions = (int) $ads['impressions'];
        $clicks = (int) $ads['clicks'];
        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'click_through_rate' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
            'spend_minor' => (int) $ads['spend_minor'],
            'conversions' => (int) $conv['conversions'],
            'revenue_minor' => (int) $conv['revenue_minor'],
        ];
    }

    // ------------------------------------------------------------- advertisers
    public function advertisers(Request $r): array
    {
        return ['items' => Database::all('SELECT id, company_name, contact_email, status, created_at FROM advertisers ORDER BY id DESC LIMIT 200')];
    }

    public function createAdvertiser(Request $r): array
    {
        $name = Validator::str($r->input('company_name'), 'company_name', 2, 160);
        $email = Validator::email($r->input('contact_email'));
        $id = Database::insert("INSERT INTO advertisers (company_name, contact_email, status, created_by) VALUES (?, ?, 'pending', ?)", [$name, $email, $r->userId()]);
        Audit::log($r->userId(), 'advertiser.created', 'advertiser', $id);
        Response::status(201);
        return ['id' => $id, 'status' => 'pending'];
    }

    public function setAdvertiserStatus(Request $r): array
    {
        $status = Validator::in($r->input('status'), 'status', ['approved', 'suspended']);
        $id = (int) $r->param('id');
        Database::exec('UPDATE advertisers SET status = ? WHERE id = ?', [$status, $id]);
        if ($status === 'suspended') {
            Database::exec("UPDATE advertisements SET status = 'suspended' WHERE advertiser_id = ? AND status = 'approved'", [$id]);
        }
        Audit::log($r->userId(), 'advertiser.' . $status, 'advertiser', $id);
        return ['ok' => true];
    }

    // ---------------------------------------------------------- advertisements
    public function ads(Request $r): array
    {
        return ['items' => Database::all(
            'SELECT a.id, a.title, a.body, a.media_type, a.placement, a.status, a.budget_minor, a.spent_minor, a.cpm_minor, a.cpc_minor,
                    a.daily_frequency_cap, a.starts_at, a.ends_at, a.review_note, a.created_by, adv.company_name, a.campaign_id
             FROM advertisements a JOIN advertisers adv ON adv.id = a.advertiser_id ORDER BY a.id DESC LIMIT 200'
        )];
    }

    public function createAd(Request $r): array
    {
        $advertiserId = Validator::int($r->input('advertiser_id'), 'advertiser_id', 1, PHP_INT_MAX);
        if (!Database::value('SELECT 1 FROM advertisers WHERE id = ?', [$advertiserId])) {
            Validator::fail('advertiser_id', 'Choose a valid advertiser.');
        }
        $title = Validator::str($r->input('title'), 'title', 3, 120);
        $body = Validator::str($r->input('body'), 'body', 0, 500, false);
        $mediaType = Validator::in($r->input('media_type', 'text'), 'media_type', ['text', 'image', 'video']);
        $clickUrl = Validator::str($r->input('click_url'), 'click_url', 10, 500);
        $parts = parse_url($clickUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            Validator::fail('click_url', 'The destination must be an https:// address.');
        }
        $placement = Validator::in($r->input('placement', 'community_feed'), 'placement', ['community_feed', 'discovery']);
        $cpm = Validator::int($r->input('cpm_naira', 0), 'cpm_naira', 0, 1000000) * 100;
        $cpc = Validator::int($r->input('cpc_naira', 0), 'cpc_naira', 0, 1000000) * 100;
        $budget = Validator::int($r->input('budget_naira'), 'budget_naira', 1, 100000000) * 100;
        $cap = Validator::int($r->input('daily_frequency_cap', 3), 'daily_frequency_cap', 1, 10);
        $campaignId = $r->input('campaign_id') ? Validator::int($r->input('campaign_id'), 'campaign_id', 1, PHP_INT_MAX) : null;
        $starts = $this->dateInput($r->input('starts_at'), 'starts_at');
        $ends = $this->dateInput($r->input('ends_at'), 'ends_at');

        $key = null;
        $mime = null;
        if ($mediaType !== 'text') {
            $allowed = $mediaType === 'image' ? self::AD_IMAGE : self::AD_VIDEO;
            $file = SecureStorage::inspectUpload($r->files['media'] ?? null, $allowed, $mediaType === 'image' ? 5 * 1048576 : 20 * 1048576, 'media');
            $key = SecureStorage::putFile('ads', $file['tmp']);
            $mime = $file['mime'];
        }
        $id = Database::insert(
            "INSERT INTO advertisements (advertiser_id, campaign_id, title, body, media_type, storage_key, media_mime, click_url, placement,
                cpm_minor, cpc_minor, budget_minor, daily_frequency_cap, starts_at, ends_at, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
            [$advertiserId, $campaignId, $title, $body, $mediaType, $key, $mime, $clickUrl, $placement,
             $cpm, $cpc, $budget, $cap, $starts, $ends, $r->userId()]
        );
        Audit::log($r->userId(), 'ad.created', 'advertisement', $id, null, null, ['budget_minor' => $budget]);
        Response::status(201);
        return ['id' => $id, 'status' => 'draft'];
    }

    public function submitAd(Request $r): array
    {
        $ad = $this->ad((int) $r->param('id'));
        if (!in_array($ad['status'], ['draft', 'rejected'], true)) {
            throw new ApiException(409, 'INVALID_STATE', 'Only drafts can be submitted for review.');
        }
        Database::exec("UPDATE advertisements SET status = 'pending_review', review_note = NULL WHERE id = ?", [$ad['id']]);
        return ['ok' => true, 'status' => 'pending_review'];
    }

    /** Maker-checker: the person who created an advert cannot approve it. */
    public function reviewAd(Request $r): array
    {
        $ad = $this->ad((int) $r->param('id'));
        if ((int) $ad['created_by'] === $r->userId()) {
            throw new ApiException(403, 'MAKER_CHECKER', 'Another reviewer must approve this advertisement.');
        }
        if ($ad['status'] !== 'pending_review') {
            throw new ApiException(409, 'INVALID_STATE', 'This advertisement is not awaiting review.');
        }
        $decision = Validator::in($r->input('decision'), 'decision', ['approve', 'reject']);
        $note = Validator::str($r->input('note'), 'note', $decision === 'reject' ? 10 : 0, 500, $decision === 'reject');
        if ($decision === 'approve') {
            $advertiserOk = Database::value("SELECT 1 FROM advertisers WHERE id = ? AND status = 'approved'", [$ad['advertiser_id']]);
            if (!$advertiserOk) {
                throw new ApiException(422, 'ADVERTISER_NOT_APPROVED', 'The advertiser must be approved before its adverts can run.');
            }
        }
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        Database::exec('UPDATE advertisements SET status = ?, review_note = ?, reviewed_by = ? WHERE id = ?', [$status, $note, $r->userId(), $ad['id']]);
        Audit::log($r->userId(), 'ad.' . $status, 'advertisement', $ad['id'], $note);
        return ['ok' => true, 'status' => $status];
    }

    public function suspendAd(Request $r): array
    {
        $ad = $this->ad((int) $r->param('id'));
        $reason = Validator::str($r->input('reason'), 'reason', 10, 500);
        Database::exec("UPDATE advertisements SET status = 'suspended', review_note = ?, reviewed_by = ? WHERE id = ?", [$reason, $r->userId(), $ad['id']]);
        Audit::log($r->userId(), 'ad.suspended', 'advertisement', $ad['id'], $reason);
        return ['ok' => true];
    }

    public function adMedia(Request $r): ?array
    {
        $ad = Database::one("SELECT * FROM advertisements WHERE id = ? AND status IN ('approved','completed') AND storage_key IS NOT NULL", [(int) $r->param('id')]);
        if ($ad === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Advertisement media not found.');
        }
        Response::binary(SecureStorage::get('ads', $ad['storage_key']), $ad['media_mime'], 'inline', 'ad');
        return null;
    }

    /** Click-through: records the click (and its cost) then redirects to the approved destination. */
    public function click(Request $r): ?array
    {
        Features::require('business.ads');
        RateLimiter::hit('ad:click:' . $r->userId(), 120, 3600);
        $ad = Database::one("SELECT * FROM advertisements WHERE id = ? AND status = 'approved'", [(int) $r->param('id')]);
        if ($ad === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Advertisement not available.');
        }
        AdServer::record((int) $ad['id'], $r->userId(), 'click', (int) $ad['cpc_minor']);
        Response::redirect((string) $ad['click_url']);
        return null;
    }

    private function ad(int $id): array
    {
        $ad = Database::one('SELECT * FROM advertisements WHERE id = ?', [$id]);
        if ($ad === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Advertisement not found.');
        }
        return $ad;
    }

    private function dateInput(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
        if (!$d) {
            Validator::fail($field, "The {$field} must be a valid date and time.");
        }
        return $d->format('Y-m-d H:i:s');
    }
}
