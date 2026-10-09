<?php
/**
 * FeatureRegistry — central definition of every toggleable module.
 *
 * Each feature declares:
 *   key          unique flag key stored in the feature_flags table
 *   label        human label for the admin UI
 *   category     grouping in the feature-management screen
 *   description  what the module does
 *   default      default state when no row exists yet
 *   depends_on   other flags that must be enabled for this one to work
 *   critical     critical features survive the emergency lockdown kill switch
 *
 * The registry is the single source of truth: the Super Admin UI, the
 * RequireFeature middleware, and the frontend feature map are all generated
 * from it.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class FeatureRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            // --- Account & security -------------------------------------------
            'registration' => [
                'label' => 'Registration & OTP', 'category' => 'Account & security',
                'description' => 'New member sign-up with email/phone OTP verification.',
                'default' => true, 'depends_on' => [], 'critical' => true,
            ],
            'age_check' => [
                'label' => 'Age eligibility checks', 'category' => 'Account & security',
                'description' => 'Enforce the 18+ requirement at registration and in discovery.',
                'default' => true, 'depends_on' => ['registration'], 'critical' => true,
            ],
            'profile_verification' => [
                'label' => 'Profile verification', 'category' => 'Account & security',
                'description' => 'Video verification workflow. When disabled, pending profiles are NOT auto-approved.',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            'discovery' => [
                'label' => 'Profile discovery', 'category' => 'Account & security',
                'description' => 'Match discovery feed (like / pass). Requires approved profiles.',
                'default' => true, 'depends_on' => ['profile_verification'], 'critical' => false,
            ],
            // --- Communication -------------------------------------------------
            'messaging' => [
                'label' => 'Private messaging', 'category' => 'Communication',
                'description' => 'One-to-one chat between matched members. Disabling keeps conversation history.',
                'default' => true, 'depends_on' => ['discovery'], 'critical' => false,
            ],
            'audio_calls' => [
                'label' => 'Audio calls', 'category' => 'Communication',
                'description' => 'WebRTC voice calls between eligible members.',
                'default' => true, 'depends_on' => ['discovery'], 'critical' => false,
            ],
            'video_calls' => [
                'label' => 'Video calls', 'category' => 'Communication',
                'description' => 'WebRTC video calls between eligible members.',
                'default' => true, 'depends_on' => ['discovery'], 'critical' => false,
            ],
            'push_notifications' => [
                'label' => 'Push notifications', 'category' => 'Communication',
                'description' => 'Browser/mobile push notifications (in-app notifications always work).',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            // --- Social community ----------------------------------------------
            'community' => [
                'label' => 'Community feed', 'category' => 'Social community',
                'description' => 'Facebook-style posts, media, reactions and follows. Independent of matchmaking.',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            'community_comments' => [
                'label' => 'Comments & reactions', 'category' => 'Social community',
                'description' => 'Commenting and reactions on community posts.',
                'default' => true, 'depends_on' => ['community'], 'critical' => false,
            ],
            'community_sharing' => [
                'label' => 'External sharing', 'category' => 'Social community',
                'description' => 'Share posts outside the platform (native share + copy link).',
                'default' => true, 'depends_on' => ['community'], 'critical' => false,
            ],
            'community_groups' => [
                'label' => 'Community groups', 'category' => 'Social community',
                'description' => 'Topic groups and interest-based discussions.',
                'default' => false, 'depends_on' => ['community'], 'critical' => false,
            ],
            // --- Business & revenue --------------------------------------------
            'payments' => [
                'label' => 'Subscription plans', 'category' => 'Business & revenue',
                'description' => 'Paid subscription plans. Disabling keeps all payment history.',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            'gateway_paystack' => [
                'label' => 'Paystack', 'category' => 'Business & revenue',
                'description' => 'Enable the Paystack payment gateway.',
                'default' => false, 'depends_on' => ['payments'], 'critical' => false,
            ],
            'gateway_flutterwave' => [
                'label' => 'Flutterwave', 'category' => 'Business & revenue',
                'description' => 'Enable the Flutterwave payment gateway.',
                'default' => false, 'depends_on' => ['payments'], 'critical' => false,
            ],
            'campaigns' => [
                'label' => 'Campaigns', 'category' => 'Business & revenue',
                'description' => 'Promotional campaigns, promo codes and referral programmes.',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            'advertisements' => [
                'label' => 'Advertisements', 'category' => 'Business & revenue',
                'description' => 'Paid ad placements in community feeds and discovery.',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            // --- Administration --------------------------------------------------
            'support_tickets' => [
                'label' => 'Support tickets', 'category' => 'Administration',
                'description' => 'Member support tickets and staff replies.',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            'moderation' => [
                'label' => 'Moderation reports', 'category' => 'Administration',
                'description' => 'User reports, review queues and content removal.',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            'analytics' => [
                'label' => 'Analytics', 'category' => 'Administration',
                'description' => 'System analytics dashboards and reports.',
                'default' => true, 'depends_on' => [], 'critical' => false,
            ],
            'referral_programme' => [
                'label' => 'Referral programme', 'category' => 'Administration',
                'description' => 'Member referral rewards and tracking.',
                'default' => false, 'depends_on' => ['campaigns'], 'critical' => false,
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Categories in display order with their features. */
    public static function byCategory(): array
    {
        $out = [];
        foreach (self::all() as $key => $feature) {
            $out[$feature['category']][$key] = $feature;
        }
        return $out;
    }
}
