<?php
/**
 * Route definitions for CodeAlpha.
 *
 * Conventions:
 *   - GET/POST page routes render views; /api/* routes return JSON.
 *   - Staff routes are grouped by dashboard area and protected by
 *     StaffMiddleware (dashboard scoping) + PermissionMiddleware (RBAC).
 *   - Feature toggles are enforced server-side by FeatureMiddleware.
 *   - The gender visibility rule is enforced inside MatchingService, never
 *     in JavaScript.
 */

declare(strict_types=1);

use CodeAlpha\Core\Router;
use CodeAlpha\Http\Middleware\AuthMiddleware;
use CodeAlpha\Http\Middleware\CsrfMiddleware;
use CodeAlpha\Http\Middleware\FeatureMiddleware;
use CodeAlpha\Http\Middleware\PermissionMiddleware;
use CodeAlpha\Http\Middleware\StaffMiddleware;
use CodeAlpha\Http\Middleware\VerifiedMiddleware;

return static function (Router $r): void {

    // -----------------------------------------------------------------------
    // Public pages
    // -----------------------------------------------------------------------
    $r->get('/', 'PageController@home', [], 'home');
    $r->get('/terms', 'PageController@terms');
    $r->get('/privacy', 'PageController@privacy');
    $r->get('/guidelines', 'PageController@guidelines');
    $r->get('/login', 'AuthController@showLogin', [], 'login');
    $r->post('/login', 'AuthController@login', [CsrfMiddleware::class]);
    $r->get('/register', 'AuthController@showRegister');
    $r->post('/register', 'AuthController@register', [CsrfMiddleware::class]);
    $r->get('/verify-otp', 'AuthController@showVerifyOtp');
    $r->post('/verify-otp', 'AuthController@verifyOtp', [CsrfMiddleware::class]);
    $r->get('/forgot-password', 'AuthController@showForgot');
    $r->post('/forgot-password', 'AuthController@forgot', [CsrfMiddleware::class]);
    $r->get('/reset-password', 'AuthController@showReset');
    $r->post('/reset-password', 'AuthController@reset', [CsrfMiddleware::class]);
    $r->get('/logout', 'AuthController@logout');
    $r->get('/dev/inbox', 'PageController@devInbox');
    $r->get('/mock-checkout', 'PaymentController@mockCheckout');

    // -----------------------------------------------------------------------
    // Auth & account API
    // -----------------------------------------------------------------------
    // Login/register are public and do not require CSRF (no session yet).
    // Logout and other auth actions that require a session use CSRF.
    $r->group('/api/auth', [], static function ($api): void {
        $api->post('/register', 'AuthController@apiRegister');
        $api->post('/verify-otp', 'AuthController@apiVerifyOtp');
        $api->post('/resend-otp', 'AuthController@apiResendOtp');
        $api->post('/login', 'AuthController@apiLogin');
        $api->post('/logout', 'AuthController@apiLogout', [CsrfMiddleware::class, AuthMiddleware::class]);
        $api->post('/forgot-password', 'AuthController@apiForgot');
        $api->post('/reset-password', 'AuthController@apiReset');
    });
    $r->get('/api/auth/me', 'AuthController@apiMe', [AuthMiddleware::class]);

    // -----------------------------------------------------------------------
    // Profile API (member)
    // -----------------------------------------------------------------------
    $r->group('/api/profile', [AuthMiddleware::class, CsrfMiddleware::class], static function ($api): void {
        $api->get('/', 'ProfileController@apiMe');
        $api->put('/', 'ProfileController@apiUpdate');
        $api->post('/photos', 'ProfileController@apiUploadPhoto');
        $api->delete('/photos/{id}', 'ProfileController@apiDeletePhoto');
        $api->post('/photos/{id}/avatar', 'ProfileController@apiSetAvatar');
        $api->get('/verification', 'VerificationController@apiStatus');
        $api->post('/verification', 'VerificationController@apiSubmit', [FeatureMiddleware::class . ':profile_verification']);
    });
    $r->delete('/api/account', 'ProfileController@apiDeleteAccount', [AuthMiddleware::class, CsrfMiddleware::class]);

    // -----------------------------------------------------------------------
    // Discovery / matching API — verified members only, feature-gated
    // -----------------------------------------------------------------------
    $matchMw = [AuthMiddleware::class, VerifiedMiddleware::class, FeatureMiddleware::class . ':discovery'];
    $r->get('/api/discovery', 'DiscoveryController@apiDiscover', $matchMw);
    $r->post('/api/discovery/like', 'DiscoveryController@apiLike', array_merge($matchMw, [CsrfMiddleware::class]));
    $r->post('/api/discovery/pass', 'DiscoveryController@apiPass', array_merge($matchMw, [CsrfMiddleware::class]));
    $r->post('/api/discovery/favourite', 'DiscoveryController@apiFavourite', array_merge($matchMw, [CsrfMiddleware::class]));
    $r->delete('/api/discovery/favourite/{user_id}', 'DiscoveryController@apiUnfavourite', array_merge($matchMw, [CsrfMiddleware::class]));
    $r->get('/api/matches', 'DiscoveryController@apiMatches', $matchMw);
    $r->get('/api/favourites', 'DiscoveryController@apiFavourites', $matchMw);
    $r->post('/api/matches/{id}/unmatch', 'DiscoveryController@apiUnmatch', array_merge($matchMw, [CsrfMiddleware::class]));
    $r->get('/api/users/{id}', 'DiscoveryController@apiShow', $matchMw);
    $r->post('/api/users/{id}/block', 'SafetyController@apiBlock', array_merge($matchMw, [CsrfMiddleware::class]));
    $r->delete('/api/users/{id}/block', 'SafetyController@apiUnblock', array_merge($matchMw, [CsrfMiddleware::class]));
    $r->post('/api/users/{id}/report', 'SafetyController@apiReport', array_merge([AuthMiddleware::class, CsrfMiddleware::class, FeatureMiddleware::class . ':moderation']));

    // -----------------------------------------------------------------------
    // Messaging API
    // -----------------------------------------------------------------------
    $msgMw = [AuthMiddleware::class, FeatureMiddleware::class . ':messaging'];
    $r->get('/api/conversations', 'MessageController@apiConversations', $msgMw);
    $r->get('/api/conversations/{id}/messages', 'MessageController@apiMessages', $msgMw);
    $r->post('/api/conversations/{id}/messages', 'MessageController@apiSend', array_merge($msgMw, [CsrfMiddleware::class]));
    $r->post('/api/conversations/{id}/read', 'MessageController@apiRead', array_merge($msgMw, [CsrfMiddleware::class]));
    $r->get('/api/messages/poll', 'MessageController@apiPoll', $msgMw);
    $r->get('/media/chat/{message_id}', 'MediaController@chat', [AuthMiddleware::class]);

    // -----------------------------------------------------------------------
    // Calls API
    // -----------------------------------------------------------------------
    $callMw = [AuthMiddleware::class, VerifiedMiddleware::class, FeatureMiddleware::class . ':audio_calls'];
    $r->get('/api/calls', 'CallController@apiHistory', [AuthMiddleware::class, VerifiedMiddleware::class]);
    $r->post('/api/calls', 'CallController@apiInitiate', array_merge($callMw, [CsrfMiddleware::class]));
    $r->post('/api/calls/{id}/accept', 'CallController@apiAccept', array_merge($callMw, [CsrfMiddleware::class]));
    $r->post('/api/calls/{id}/decline', 'CallController@apiDecline', array_merge($callMw, [CsrfMiddleware::class]));
    $r->post('/api/calls/{id}/end', 'CallController@apiEnd', array_merge($callMw, [CsrfMiddleware::class]));
    $r->post('/api/calls/{id}/signal', 'CallController@apiSignal', array_merge($callMw, [CsrfMiddleware::class]));
    $r->get('/api/calls/{id}/signals', 'CallController@apiSignals', $callMw);
    $r->post('/api/calls/{id}/signal-token', 'CallController@apiSignalToken', array_merge($callMw, [CsrfMiddleware::class]));
    $r->post('/api/calls/block', 'CallController@apiBlock', [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->delete('/api/calls/block/{user_id}', 'CallController@apiUnblock', [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->get('/api/calls/blocked', 'CallController@apiBlocked', [AuthMiddleware::class]);

    // -----------------------------------------------------------------------
    // Community API
    // -----------------------------------------------------------------------
    $commMw = [AuthMiddleware::class, FeatureMiddleware::class . ':community'];
    $r->get('/api/community/feed', 'CommunityController@apiFeed', $commMw);
    $r->post('/api/community/posts', 'CommunityController@apiCreate', array_merge($commMw, [CsrfMiddleware::class]));
    $r->get('/api/community/posts/{id}', 'CommunityController@apiShow', $commMw);
    $r->put('/api/community/posts/{id}', 'CommunityController@apiUpdate', array_merge($commMw, [CsrfMiddleware::class]));
    $r->delete('/api/community/posts/{id}', 'CommunityController@apiDelete', array_merge($commMw, [CsrfMiddleware::class]));
    $r->post('/api/community/posts/{id}/reactions', 'CommunityController@apiReact', array_merge($commMw, [CsrfMiddleware::class, FeatureMiddleware::class . ':community_comments']));
    $r->delete('/api/community/posts/{id}/reactions', 'CommunityController@apiUnreact', array_merge($commMw, [CsrfMiddleware::class]));
    $r->get('/api/community/posts/{id}/comments', 'CommunityController@apiComments', $commMw);
    $r->post('/api/community/posts/{id}/comments', 'CommunityController@apiComment', array_merge($commMw, [CsrfMiddleware::class, FeatureMiddleware::class . ':community_comments']));
    $r->post('/api/community/comments/{id}/reactions', 'CommunityController@apiReactComment', array_merge($commMw, [CsrfMiddleware::class, FeatureMiddleware::class . ':community_comments']));
    $r->delete('/api/community/comments/{id}/reactions', 'CommunityController@apiUnreactComment', array_merge($commMw, [CsrfMiddleware::class]));
    $r->post('/api/community/posts/{id}/share', 'CommunityController@apiShare', array_merge($commMw, [CsrfMiddleware::class, FeatureMiddleware::class . ':community_sharing']));
    $r->post('/api/community/posts/{id}/save', 'CommunityController@apiSave', array_merge($commMw, [CsrfMiddleware::class]));
    $r->delete('/api/community/posts/{id}/save', 'CommunityController@apiUnsave', array_merge($commMw, [CsrfMiddleware::class]));
    $r->get('/api/community/saved', 'CommunityController@apiSaved', $commMw);
    $r->post('/api/community/users/{id}/follow', 'CommunityController@apiFollow', array_merge($commMw, [CsrfMiddleware::class]));
    $r->delete('/api/community/users/{id}/follow', 'CommunityController@apiUnfollow', array_merge($commMw, [CsrfMiddleware::class]));
    $r->get('/api/community/users/{id}', 'CommunityController@apiMember', $commMw);
    $r->get('/api/community/trending', 'CommunityController@apiTrending', $commMw);
    $r->get('/api/community/search', 'CommunityController@apiSearch', $commMw);
    $r->get('/api/community/topics', 'CommunityController@apiTopics', $commMw);
    $r->post('/api/community/posts/{id}/report', 'CommunityController@apiReport', array_merge($commMw, [CsrfMiddleware::class, FeatureMiddleware::class . ':moderation']));
    $r->post('/api/community/comments/{id}/report', 'CommunityController@apiReportComment', array_merge($commMw, [CsrfMiddleware::class, FeatureMiddleware::class . ':moderation']));

    // -----------------------------------------------------------------------
    // Payments API & webhooks
    // -----------------------------------------------------------------------
    $payMw = [AuthMiddleware::class, FeatureMiddleware::class . ':payments'];
    $r->get('/api/payments/plans', 'PaymentController@apiPlans', [AuthMiddleware::class]);
    $r->post('/api/payments/initialize', 'PaymentController@apiInitialize', array_merge($payMw, [CsrfMiddleware::class]));
    $r->get('/api/payments/verify', 'PaymentController@apiVerify', $payMw);
    $r->get('/api/payments/history', 'PaymentController@apiHistory', $payMw);
    $r->get('/api/payments/subscription', 'PaymentController@apiSubscription', $payMw);
    $r->post('/api/payments/cancel-subscription', 'PaymentController@apiCancelSubscription', array_merge($payMw, [CsrfMiddleware::class]));
    $r->post('/api/webhooks/paystack', 'WebhookController@paystack');
    $r->post('/api/webhooks/flutterwave', 'WebhookController@flutterwave');
    $r->get('/api/payments/config', 'PaymentController@apiConfig');

    // -----------------------------------------------------------------------
    // Notifications & push
    // -----------------------------------------------------------------------
    $r->get('/api/notifications', 'NotificationController@apiList', [AuthMiddleware::class]);
    $r->post('/api/notifications/read-all', 'NotificationController@apiReadAll', [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->post('/api/notifications/{id}/read', 'NotificationController@apiRead', [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->get('/api/notifications/unread-count', 'NotificationController@apiUnreadCount', [AuthMiddleware::class]);
    $r->post('/api/push/subscribe', 'NotificationController@apiPushSubscribe', [AuthMiddleware::class, CsrfMiddleware::class, FeatureMiddleware::class . ':push_notifications']);
    $r->delete('/api/push/subscribe', 'NotificationController@apiPushUnsubscribe', [AuthMiddleware::class, CsrfMiddleware::class]);

    // -----------------------------------------------------------------------
    // Support API
    // -----------------------------------------------------------------------
    $supMw = [AuthMiddleware::class, FeatureMiddleware::class . ':support_tickets'];
    $r->get('/api/support/tickets', 'SupportController@apiTickets', $supMw);
    $r->post('/api/support/tickets', 'SupportController@apiCreateTicket', array_merge($supMw, [CsrfMiddleware::class]));
    $r->get('/api/support/tickets/{id}', 'SupportController@apiTicket', $supMw);
    $r->post('/api/support/tickets/{id}/messages', 'SupportController@apiTicketMessage', array_merge($supMw, [CsrfMiddleware::class]));
    $r->post('/api/support/appeals', 'SupportController@apiAppeal', array_merge([AuthMiddleware::class, CsrfMiddleware::class]));

    // -----------------------------------------------------------------------
    // Advertisement serving (frequency-capped)
    // -----------------------------------------------------------------------
    $r->get('/api/ads/serve', 'AdvertController@apiServe', [AuthMiddleware::class, FeatureMiddleware::class . ':advertisements']);
    $r->post('/api/ads/{id}/impression', 'AdvertController@apiImpression', [FeatureMiddleware::class . ':advertisements']);
    $r->get('/api/ads/{id}/click', 'AdvertController@apiClick', [FeatureMiddleware::class . ':advertisements']);

    // -----------------------------------------------------------------------
    // Member pages
    // -----------------------------------------------------------------------
    $r->get('/discover', 'PageController@discover', [AuthMiddleware::class, VerifiedMiddleware::class, FeatureMiddleware::class . ':discovery']);
    $r->get('/matches', 'PageController@matches', [AuthMiddleware::class, VerifiedMiddleware::class, FeatureMiddleware::class . ':discovery']);
    $r->get('/favorites', 'PageController@favorites', [AuthMiddleware::class, VerifiedMiddleware::class, FeatureMiddleware::class . ':discovery']);
    $r->get('/chat', 'PageController@chat', [AuthMiddleware::class, FeatureMiddleware::class . ':messaging']);
    $r->get('/chat/{id}', 'PageController@chat', [AuthMiddleware::class, FeatureMiddleware::class . ':messaging']);
    $r->get('/calls', 'PageController@calls', [AuthMiddleware::class, VerifiedMiddleware::class]);
    $r->get('/community', 'PageController@community', [AuthMiddleware::class, FeatureMiddleware::class . ':community']);
    $r->get('/community/post/{id}', 'PageController@communityPost', [AuthMiddleware::class, FeatureMiddleware::class . ':community']);
    $r->get('/community/member/{id}', 'PageController@communityMember', [AuthMiddleware::class, FeatureMiddleware::class . ':community']);
    $r->get('/community/search', 'PageController@communitySearch', [AuthMiddleware::class, FeatureMiddleware::class . ':community']);
    $r->get('/community/saved', 'PageController@communitySaved', [AuthMiddleware::class, FeatureMiddleware::class . ':community']);
    $r->get('/profile', 'PageController@profile', [AuthMiddleware::class]);
    $r->get('/profile/{id}', 'PageController@profileView', [AuthMiddleware::class, VerifiedMiddleware::class, FeatureMiddleware::class . ':discovery']);
    $r->get('/verify', 'PageController@verify', [AuthMiddleware::class]);
    $r->get('/settings', 'PageController@settings', [AuthMiddleware::class]);
    $r->get('/notifications', 'PageController@notifications', [AuthMiddleware::class]);
    $r->get('/subscribe', 'PageController@subscribe', [AuthMiddleware::class, FeatureMiddleware::class . ':payments']);
    $r->get('/payment/return', 'PageController@paymentReturn', [AuthMiddleware::class]);
    $r->get('/support', 'PageController@support', [AuthMiddleware::class, FeatureMiddleware::class . ':support_tickets']);
    $r->get('/support/ticket/{id}', 'PageController@supportTicket', [AuthMiddleware::class, FeatureMiddleware::class . ':support_tickets']);

    // -----------------------------------------------------------------------
    // Staff authentication
    // -----------------------------------------------------------------------
    $r->get('/staff/login', 'Staff\StaffAuthController@showLogin');
    $r->post('/staff/login', 'Staff\StaffAuthController@login', [CsrfMiddleware::class]);
    $r->get('/staff/logout', 'Staff\StaffAuthController@logout');
    $r->get('/staff', 'Staff\StaffAuthController@home', [StaffMiddleware::class]);

    // -----------------------------------------------------------------------
    // Super Admin area
    // -----------------------------------------------------------------------
    $super = [StaffMiddleware::class . ':super_admin'];
    $r->group('/staff/super', $super, static function ($g): void {
        $g->get('/', 'Staff\SuperDashboardController@index');
        // Staff accounts
        $g->get('/staff', 'Staff\SuperStaffController@index', [PermissionMiddleware::class . ':staff.manage']);
        $g->get('/staff/create', 'Staff\SuperStaffController@create', [PermissionMiddleware::class . ':staff.manage']);
        $g->post('/staff', 'Staff\SuperStaffController@store', [PermissionMiddleware::class . ':staff.manage', CsrfMiddleware::class]);
        $g->get('/staff/{id}/edit', 'Staff\SuperStaffController@edit', [PermissionMiddleware::class . ':staff.manage']);
        $g->post('/staff/{id}', 'Staff\SuperStaffController@update', [PermissionMiddleware::class . ':staff.manage', CsrfMiddleware::class]);
        $g->post('/staff/{id}/suspend', 'Staff\SuperStaffController@suspend', [PermissionMiddleware::class . ':staff.manage', CsrfMiddleware::class]);
        $g->post('/staff/{id}/activate', 'Staff\SuperStaffController@activate', [PermissionMiddleware::class . ':staff.manage', CsrfMiddleware::class]);
        $g->post('/staff/{id}/delete', 'Staff\SuperStaffController@delete', [PermissionMiddleware::class . ':staff.manage', CsrfMiddleware::class]);
        $g->post('/staff/{id}/roles', 'Staff\SuperStaffController@assignRoles', [PermissionMiddleware::class . ':staff.manage', CsrfMiddleware::class]);
        // Roles & permissions
        $g->get('/roles', 'Staff\SuperRoleController@index', [PermissionMiddleware::class . ':roles.manage']);
        $g->get('/roles/create', 'Staff\SuperRoleController@create', [PermissionMiddleware::class . ':roles.manage']);
        $g->post('/roles', 'Staff\SuperRoleController@store', [PermissionMiddleware::class . ':roles.manage', CsrfMiddleware::class]);
        $g->get('/roles/{id}/edit', 'Staff\SuperRoleController@edit', [PermissionMiddleware::class . ':roles.manage']);
        $g->post('/roles/{id}', 'Staff\SuperRoleController@update', [PermissionMiddleware::class . ':roles.manage', CsrfMiddleware::class]);
        $g->delete('/roles/{id}', 'Staff\SuperRoleController@delete', [PermissionMiddleware::class . ':roles.manage', CsrfMiddleware::class]);
        // Feature management
        $g->get('/features', 'Staff\SuperFeatureController@index', [PermissionMiddleware::class . ':features.manage']);
        $g->post('/features/toggle', 'Staff\SuperFeatureController@toggle', [PermissionMiddleware::class . ':features.manage', CsrfMiddleware::class]);
        $g->post('/features/lockdown', 'Staff\SuperFeatureController@lockdown', [PermissionMiddleware::class . ':features.manage', CsrfMiddleware::class]);
        // Settings
        $g->get('/settings', 'Staff\SuperSettingsController@index', [PermissionMiddleware::class . ':settings.manage']);
        $g->post('/settings', 'Staff\SuperSettingsController@update', [PermissionMiddleware::class . ':settings.manage', CsrfMiddleware::class]);
        // Gateways & plans
        $g->get('/gateways', 'Staff\SuperGatewayController@index', [PermissionMiddleware::class . ':payments.configure']);
        $g->post('/gateways', 'Staff\SuperGatewayController@update', [PermissionMiddleware::class . ':payments.configure', CsrfMiddleware::class]);
        $g->get('/plans', 'Staff\SuperPlanController@index', [PermissionMiddleware::class . ':payments.configure']);
        $g->post('/plans', 'Staff\SuperPlanController@store', [PermissionMiddleware::class . ':payments.configure', CsrfMiddleware::class]);
        $g->post('/plans/{id}', 'Staff\SuperPlanController@update', [PermissionMiddleware::class . ':payments.configure', CsrfMiddleware::class]);
        $g->post('/plans/{id}/toggle', 'Staff\SuperPlanController@toggle', [PermissionMiddleware::class . ':payments.configure', CsrfMiddleware::class]);
        // Campaigns
        $g->get('/campaigns', 'Staff\SuperCampaignController@index', [PermissionMiddleware::class . ':campaigns.manage']);
        $g->get('/campaigns/create', 'Staff\SuperCampaignController@create', [PermissionMiddleware::class . ':campaigns.manage']);
        $g->post('/campaigns', 'Staff\SuperCampaignController@store', [PermissionMiddleware::class . ':campaigns.manage', CsrfMiddleware::class]);
        $g->get('/campaigns/{id}', 'Staff\SuperCampaignController@show', [PermissionMiddleware::class . ':campaigns.manage']);
        $g->post('/campaigns/{id}', 'Staff\SuperCampaignController@update', [PermissionMiddleware::class . ':campaigns.manage', CsrfMiddleware::class]);
        // Advertisements
        $g->get('/advertisements', 'Staff\SuperAdvertController@index', [PermissionMiddleware::class . ':advertisements.manage']);
        $g->get('/advertisements/create', 'Staff\SuperAdvertController@create', [PermissionMiddleware::class . ':advertisements.manage']);
        $g->post('/advertisements', 'Staff\SuperAdvertController@store', [PermissionMiddleware::class . ':advertisements.manage', CsrfMiddleware::class]);
        $g->post('/advertisements/{id}/review', 'Staff\SuperAdvertController@review', [PermissionMiddleware::class . ':advertisements.manage', CsrfMiddleware::class]);
        // Audit & analytics
        $g->get('/audit', 'Staff\SuperAuditController@index', [PermissionMiddleware::class . ':audit.view']);
        $g->get('/analytics', 'Staff\SuperAnalyticsController@index', [PermissionMiddleware::class . ':analytics.view']);
    });

    // -----------------------------------------------------------------------
    // Admin area (users, verification, moderation, community)
    // -----------------------------------------------------------------------
    $admin = [StaffMiddleware::class . ':admin'];
    $r->group('/staff/admin', $admin, static function ($g): void {
        $g->get('/', 'Staff\AdminDashboardController@index', [PermissionMiddleware::class . ':dashboard.admin']);
        $g->get('/users', 'Staff\AdminUserController@index', [PermissionMiddleware::class . ':users.view']);
        $g->get('/users/{id}', 'Staff\AdminUserController@show', [PermissionMiddleware::class . ':users.view']);
        $g->post('/users/{id}/suspend', 'Staff\AdminUserController@suspend', [PermissionMiddleware::class . ':users.suspend', CsrfMiddleware::class]);
        $g->post('/users/{id}/activate', 'Staff\AdminUserController@activate', [PermissionMiddleware::class . ':users.suspend', CsrfMiddleware::class]);
        $g->get('/verification', 'Staff\AdminVerificationController@index', [PermissionMiddleware::class . ':verification.review']);
        $g->get('/verification/{id}', 'Staff\AdminVerificationController@show', [PermissionMiddleware::class . ':verification.review']);
        $g->post('/verification/{id}/approve', 'Staff\AdminVerificationController@approve', [PermissionMiddleware::class . ':verification.approve', CsrfMiddleware::class]);
        $g->post('/verification/{id}/reject', 'Staff\AdminVerificationController@reject', [PermissionMiddleware::class . ':verification.reject', CsrfMiddleware::class]);
        $g->get('/media/verification/{id}', 'Staff\AdminMediaController@verification', [PermissionMiddleware::class . ':verification.review']);
        $g->get('/moderation', 'Staff\AdminModerationController@index', [PermissionMiddleware::class . ':moderation.review']);
        $g->post('/moderation/{id}/resolve', 'Staff\AdminModerationController@resolve', [PermissionMiddleware::class . ':moderation.action', CsrfMiddleware::class]);
        $g->post('/moderation/{id}/dismiss', 'Staff\AdminModerationController@dismiss', [PermissionMiddleware::class . ':moderation.action', CsrfMiddleware::class]);
        $g->post('/moderation/{id}/action', 'Staff\AdminModerationController@action', [PermissionMiddleware::class . ':moderation.action', CsrfMiddleware::class]);
        $g->get('/community', 'Staff\AdminCommunityController@index', [PermissionMiddleware::class . ':community.manage']);
        $g->post('/community/posts/{id}/remove', 'Staff\AdminCommunityController@removePost', [PermissionMiddleware::class . ':community.manage', CsrfMiddleware::class]);
        $g->post('/community/posts/{id}/restore', 'Staff\AdminCommunityController@restorePost', [PermissionMiddleware::class . ':community.manage', CsrfMiddleware::class]);
        $g->post('/community/posts/{id}/pin', 'Staff\AdminCommunityController@pinPost', [PermissionMiddleware::class . ':community.manage', CsrfMiddleware::class]);
        $g->post('/community/announcement', 'Staff\AdminCommunityController@announcement', [PermissionMiddleware::class . ':community.manage', CsrfMiddleware::class]);
        $g->post('/community/guidelines', 'Staff\AdminCommunityController@guidelines', [PermissionMiddleware::class . ':community.manage', CsrfMiddleware::class]);
    });

    // -----------------------------------------------------------------------
    // Finance area
    // -----------------------------------------------------------------------
    $finance = [StaffMiddleware::class . ':finance', PermissionMiddleware::class . ':payments.view'];
    $r->group('/staff/finance', $finance, static function ($g): void {
        $g->get('/', 'Staff\FinanceDashboardController@index');
        $g->get('/transactions', 'Staff\FinanceTransactionController@index', [PermissionMiddleware::class . ':payments.view']);
        $g->get('/transactions/{id}', 'Staff\FinanceTransactionController@show', [PermissionMiddleware::class . ':payments.view']);
        $g->post('/transactions/{id}/refund', 'Staff\FinanceTransactionController@refund', [PermissionMiddleware::class . ':payments.refund', CsrfMiddleware::class]);
        $g->get('/subscriptions', 'Staff\FinanceDashboardController@subscriptions', [PermissionMiddleware::class . ':payments.view']);
        $g->get('/reports', 'Staff\FinanceReportController@index', [PermissionMiddleware::class . ':payments.reports']);
        $g->get('/reports/export', 'Staff\FinanceReportController@export', [PermissionMiddleware::class . ':payments.reports']);
    });

    // -----------------------------------------------------------------------
    // Support area
    // -----------------------------------------------------------------------
    $support = [StaffMiddleware::class . ':support'];
    $r->group('/staff/support', $support, static function ($g): void {
        $g->get('/', 'Staff\SupportDashboardController@index', [PermissionMiddleware::class . ':support.tickets']);
        $g->get('/tickets', 'Staff\SupportTicketController@index', [PermissionMiddleware::class . ':support.tickets']);
        $g->get('/tickets/{id}', 'Staff\SupportTicketController@show', [PermissionMiddleware::class . ':support.tickets']);
        $g->post('/tickets/{id}/reply', 'Staff\SupportTicketController@reply', [PermissionMiddleware::class . ':support.tickets', CsrfMiddleware::class]);
        $g->post('/tickets/{id}/status', 'Staff\SupportTicketController@status', [PermissionMiddleware::class . ':support.tickets', CsrfMiddleware::class]);
        $g->get('/appeals', 'Staff\SupportAppealController@index', [PermissionMiddleware::class . ':support.appeals']);
        $g->post('/appeals/{id}/resolve', 'Staff\SupportAppealController@resolve', [PermissionMiddleware::class . ':support.appeals', CsrfMiddleware::class]);
        $g->get('/reports', 'Staff\SupportReportController@index', [PermissionMiddleware::class . ':support.reports']);
        $g->post('/reports/escalate', 'Staff\SupportReportController@escalate', [PermissionMiddleware::class . ':support.reports', CsrfMiddleware::class]);
    });

    // -----------------------------------------------------------------------
    // Static assets & service worker are served directly by the web server.
    // -----------------------------------------------------------------------
};
