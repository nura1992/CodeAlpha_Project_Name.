<?php
/**
 * PageController — page routes (HTML views) for public and member pages.
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Core\Request;
use CodeAlpha\Core\Response;
use CodeAlpha\Core\Settings;
use CodeAlpha\Services\CommunityService;
use CodeAlpha\Services\MatchingService;
use CodeAlpha\Services\MessagingService;
use CodeAlpha\Services\PaymentService;
use CodeAlpha\Services\SupportService;

final class PageController extends Controller
{
    public function home(): never
    {
        if (Auth::check()) {
            $user = Auth::user();
            if (Auth::isStaff()) {
                Response::redirect('/staff');
            }
            Response::redirect('/community');
        }
        $this->view('pages/home', [
            'features' => FeatureFlags::clientMap(),
        ], 'guest');
    }

    public function terms(): never
    {
        $this->view('pages/static', [
            'title' => 'Terms of Service',
            'body'  => Settings::get('terms_of_service', 'Terms of Service will be published here.'),
        ], 'guest');
    }

    public function privacy(): never
    {
        $this->view('pages/static', [
            'title' => 'Privacy Policy',
            'body'  => Settings::get('privacy_policy', 'Privacy Policy will be published here.'),
        ], 'guest');
    }

    public function guidelines(): never
    {
        $this->view('pages/static', [
            'title' => 'Community Guidelines',
            'body'  => Settings::get('community_guidelines', 'Be respectful. No harassment, hate speech, nudity or scams. Verification media is reviewed by staff and never shared publicly.'),
        ], 'guest');
    }

    /** Development inbox: shows logged emails/SMS (debug mode only). */
    public function devInbox(): never
    {
        if (!config('app.debug', false)) {
            Response::notFound();
        }
        $mail = [];
        $sms = [];
        $push = [];
        foreach (['mail', 'sms', 'push'] as $channel) {
            $file = rtrim((string) config('storage.logs'), '/') . '/' . $channel . '.log';
            if (!is_file($file)) {
                continue;
            }
            $lines = array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
            foreach (array_slice($lines, 0, 50) as $line) {
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) continue;
                if ($channel === 'mail') $mail[] = $decoded;
                elseif ($channel === 'sms') $sms[] = $decoded;
                else $push[] = $decoded;
            }
        }
        $this->view('pages/dev-inbox', ['mail' => $mail, 'sms' => $sms, 'push' => $push], 'guest');
    }

    // ------------------------------------------------------------------
    // Member pages
    // ------------------------------------------------------------------

    public function discover(): never
    {
        $user = Auth::user();
        MatchingService::heartbeat((int) $user['id']);
        $this->view('user/discover', [
            'verification_status' => $user['verification_status'] ?? 'unverified',
        ]);
    }

    public function matches(): never
    {
        $this->view('user/matches');
    }

    public function favorites(): never
    {
        $this->view('user/favorites');
    }

    public function chat(array $params): never
    {
        $this->view('user/chat', ['conversation_id' => (int) ($params['id'] ?? 0)]);
    }

    public function calls(): never
    {
        $this->view('user/calls');
    }

    public function community(): never
    {
        $this->view('user/community');
    }

    public function communityPost(array $params): never
    {
        $post = CommunityService::getPost((int) Auth::id(), (int) $params['id']);
        if (!$post || $post['status'] !== 'published') {
            Response::notFound('Post not found.');
        }
        $this->view('user/community-post', ['post' => $post]);
    }

    public function communityMember(array $params): never
    {
        $memberId = (int) $params['id'];
        $member = Database::one('SELECT u.id, u.uuid, p.display_name, p.bio, p.location_city, p.location_country, p.verified_at FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = :id AND u.deleted_at IS NULL', ['id' => $memberId]);
        if (!$member) {
            Response::notFound('Member not found.');
        }
        $avatar = Database::scalar(
            "SELECT path FROM profile_photos WHERE user_id = :id AND is_avatar = 1 AND moderation_status = 'approved' ORDER BY position ASC LIMIT 1",
            ['id' => $memberId]
        );
        $member['avatar'] = $avatar ? '/uploads/' . $avatar : null;
        $member['is_following'] = CommunityService::isFollowing((int) Auth::id(), $memberId);
        $member['posts'] = CommunityService::postsByMember((int) Auth::id(), $memberId);
        $this->view('user/community-member', ['member' => $member]);
    }

    public function communitySearch(): never
    {
        $this->view('user/community-search');
    }

    public function communitySaved(): never
    {
        $this->view('user/community-saved');
    }

    public function profile(): never
    {
        $user = Auth::user();
        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $user['id']]);
        $photos = Database::all(
            "SELECT * FROM profile_photos WHERE user_id = :id ORDER BY is_avatar DESC, position ASC",
            ['id' => $user['id']]
        );
        $verification = Database::one(
            'SELECT * FROM verification_requests WHERE user_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $user['id']]
        );
        $this->view('user/profile', [
            'profile' => $profile,
            'photos' => $photos,
            'verification' => $verification,
        ]);
    }

    public function profileView(array $params): never
    {
        $viewer = Auth::user();
        $targetId = (int) $params['id'];
        if ($targetId === (int) $viewer['id']) {
            Response::redirect('/profile');
        }
        $target = Database::one('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $targetId]);
        if (!$target) {
            Response::notFound('Profile not found.');
        }
        $isStaff = (bool) can('users.view');
        $full = MatchingService::fullProfile((int) $viewer['id'], $target, $isStaff);
        if (!$full) {
            // Not eligible (wrong gender, blocked, not approved) — do not
            // reveal the profile's existence.
            Response::notFound('Profile not found.');
        }
        $this->view('user/profile-view', ['profile' => $full, 'is_staff_view' => $isStaff]);
    }

    public function verify(): never
    {
        $user = Auth::user();
        $requests = Database::all(
            'SELECT * FROM verification_requests WHERE user_id = :id ORDER BY id DESC LIMIT 10',
            ['id' => $user['id']]
        );
        $this->view('user/verify', [
            'verification_status' => $user['verification_status'] ?? 'unverified',
            'requests' => $requests,
        ]);
    }

    public function settings(): never
    {
        $user = Auth::user();
        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $user['id']]);
        $this->view('user/settings', ['profile' => $profile]);
    }

    public function notifications(): never
    {
        $this->view('user/notifications');
    }

    public function subscribe(): never
    {
        $this->view('user/subscribe', [
            'plans' => PaymentService::plans(),
            'subscription' => PaymentService::activeSubscription((int) Auth::id()),
            'gateways' => PaymentService::publicConfig(),
            'history' => PaymentService::subscriptionHistory((int) Auth::id()),
        ]);
    }

    /** Payment return page — verification happens server-side, never here. */
    public function paymentReturn(): never
    {
        $reference = $this->request->query('reference', '');
        $this->view('user/payment-return', ['reference' => $reference]);
    }

    public function support(): never
    {
        $this->view('user/support', [
            'tickets' => SupportService::ticketsForUser((int) Auth::id()),
        ]);
    }

    public function supportTicket(array $params): never
    {
        $ticket = SupportService::ticket((int) Auth::id(), (int) $params['id']);
        if (!$ticket) {
            Response::notFound('Ticket not found.');
        }
        $this->view('user/support-ticket', [
            'ticket' => $ticket,
            'messages' => SupportService::ticketMessages((int) $ticket['id']),
        ]);
    }
}
