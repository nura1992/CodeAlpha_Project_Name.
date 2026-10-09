<?php
declare(strict_types=1);

/**
 * API route table. Each entry declares its guards:
 *   'auth' => true      signed-in, active session (CSRF enforced on writes)
 *   'perm' => 'code'    RBAC permission required (implies staff account)
 *   'feature' => 'key'  module must be enabled (see App\Core\Features)
 * Anything without 'auth' is public and must validate all input itself.
 */

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CallController;
use App\Controllers\CampaignController;
use App\Controllers\CommunityController;
use App\Controllers\DiscoveryController;
use App\Controllers\FinanceController;
use App\Controllers\MatchController;
use App\Controllers\MessageController;
use App\Controllers\ModerationController;
use App\Controllers\PaymentController;
use App\Controllers\ProfileController;
use App\Controllers\SafetyController;
use App\Controllers\SupportController;
use App\Controllers\VerificationController;
use App\Core\Router;

$router = new Router();
$A = ['auth' => true];

// ------------------------------------------------------------- account & auth
$router->add('POST', '/api/auth/register', [AuthController::class, 'register']);
$router->add('POST', '/api/auth/otp/resend', [AuthController::class, 'resendOtp']);
$router->add('POST', '/api/auth/otp/verify', [AuthController::class, 'verifyOtp']);
$router->add('POST', '/api/auth/login', [AuthController::class, 'login']);
$router->add('POST', '/api/auth/logout', [AuthController::class, 'logout'], $A);
$router->add('GET', '/api/auth/me', [AuthController::class, 'me']);
$router->add('POST', '/api/auth/password/forgot', [AuthController::class, 'forgotPassword']);
$router->add('POST', '/api/auth/password/reset', [AuthController::class, 'resetPassword']);
$router->add('POST', '/api/auth/password/change', [AuthController::class, 'changePassword'], $A);
$router->add('GET', '/api/account/settings', [AuthController::class, 'settingsShow'], $A);
$router->add('PUT', '/api/account/settings', [AuthController::class, 'updatePrivacy'], $A);
$router->add('POST', '/api/account/delete', [AuthController::class, 'deleteAccount'], $A);

// ------------------------------------------------------------------ profiles
$router->add('GET', '/api/profile', [ProfileController::class, 'show'], $A);
$router->add('PUT', '/api/profile', [ProfileController::class, 'save'], $A);
$router->add('GET', '/api/profiles/{id}', [ProfileController::class, 'publicView'], ['auth' => true, 'feature' => 'matchmaking.discovery']);
$router->add('POST', '/api/profile/photos', [ProfileController::class, 'uploadPhoto'], $A);
$router->add('DELETE', '/api/profile/photos/{id}', [ProfileController::class, 'deletePhoto'], $A);
$router->add('POST', '/api/profile/photos/{id}/primary', [ProfileController::class, 'setPrimaryPhoto'], $A);
$router->add('GET', '/api/media/photos/{id}', [ProfileController::class, 'photoMedia'], $A);

// -------------------------------------------------------------- verification
$router->add('GET', '/api/verification', [VerificationController::class, 'myStatus'], $A);
$router->add('POST', '/api/verification', [VerificationController::class, 'submit'], ['auth' => true, 'feature' => 'account.verification']);
$router->add('GET', '/api/staff/verifications', [VerificationController::class, 'queue'], ['perm' => 'verification.review']);
$router->add('POST', '/api/staff/verifications/{id}/claim', [VerificationController::class, 'claim'], ['perm' => 'verification.review']);
$router->add('POST', '/api/staff/verifications/{id}/media-link', [VerificationController::class, 'mediaLink'], ['perm' => 'verification.review']);
$router->add('GET', '/api/staff/verifications/{id}/media', [VerificationController::class, 'media'], ['perm' => 'verification.review']);
$router->add('POST', '/api/staff/verifications/{id}/approve', [VerificationController::class, 'approve'], ['perm' => 'verification.approve']);
$router->add('POST', '/api/staff/verifications/{id}/reject', [VerificationController::class, 'reject'], ['perm' => 'verification.reject']);

// ------------------------------------------------------ discovery & matching
$D = ['auth' => true, 'feature' => 'matchmaking.discovery'];
$router->add('GET', '/api/discovery', [DiscoveryController::class, 'index'], $D);
$router->add('POST', '/api/discovery/actions', [DiscoveryController::class, 'act'], $D);
$router->add('GET', '/api/favourites', [DiscoveryController::class, 'favouritesIndex'], $D);
$router->add('POST', '/api/favourites', [DiscoveryController::class, 'favouriteAdd'], $D);
$router->add('DELETE', '/api/favourites/{id}', [DiscoveryController::class, 'favouriteRemove'], $D);
$router->add('GET', '/api/matches', [MatchController::class, 'index'], $D);
$router->add('POST', '/api/matches/{id}/unmatch', [MatchController::class, 'unmatch'], $D);

// ------------------------------------------------------------- safety
$router->add('GET', '/api/blocks', [SafetyController::class, 'blocksIndex'], $A);
$router->add('POST', '/api/blocks', [SafetyController::class, 'block'], $A);
$router->add('DELETE', '/api/blocks/{id}', [SafetyController::class, 'unblock'], $A);
$router->add('POST', '/api/reports', [SafetyController::class, 'report'], $A);

// ------------------------------------------------------- messaging & calls
$M = ['auth' => true, 'feature' => 'comm.messaging'];
$router->add('GET', '/api/conversations', [MessageController::class, 'conversations'], $M);
$router->add('GET', '/api/conversations/{id}/messages', [MessageController::class, 'messages'], $M);
$router->add('POST', '/api/conversations/{id}/messages', [MessageController::class, 'send'], $M);
$router->add('POST', '/api/conversations/{id}/read', [MessageController::class, 'markRead'], $M);
$router->add('GET', '/api/media/messages/{id}', [MessageController::class, 'media'], $A);

$router->add('POST', '/api/calls', [CallController::class, 'create'], $A);
$router->add('GET', '/api/calls', [CallController::class, 'history'], $A);
$router->add('GET', '/api/calls/{id}', [CallController::class, 'show'], $A);
$router->add('GET', '/api/calls/{id}/ticket', [CallController::class, 'ticket'], $A);
$router->add('POST', '/api/calls/{id}/accept', [CallController::class, 'accept'], $A);
$router->add('POST', '/api/calls/{id}/decline', [CallController::class, 'decline'], $A);
$router->add('POST', '/api/calls/{id}/end', [CallController::class, 'end'], $A);

// ---------------------------------------------------------------- community
$C = ['auth' => true, 'feature' => 'social.feed'];
$router->add('GET', '/api/community/feed', [CommunityController::class, 'feed'], $C);
$router->add('POST', '/api/community/posts', [CommunityController::class, 'create'], $C);
$router->add('GET', '/api/community/posts/{id}', [CommunityController::class, 'show'], $C);
$router->add('DELETE', '/api/community/posts/{id}', [CommunityController::class, 'delete'], $A);
$router->add('GET', '/api/community/posts/{id}/media', [CommunityController::class, 'media'], $C);
$router->add('POST', '/api/community/posts/{id}/reactions', [CommunityController::class, 'react'], ['auth' => true, 'feature' => 'social.reactions']);
$router->add('DELETE', '/api/community/posts/{id}/reactions', [CommunityController::class, 'unreact'], $A);
$router->add('GET', '/api/community/posts/{id}/comments', [CommunityController::class, 'comments'], ['auth' => true, 'feature' => 'social.reactions']);
$router->add('POST', '/api/community/posts/{id}/comments', [CommunityController::class, 'comment'], ['auth' => true, 'feature' => 'social.reactions']);
$router->add('DELETE', '/api/community/comments/{id}', [CommunityController::class, 'deleteComment'], $A);
$router->add('POST', '/api/community/posts/{id}/share', [CommunityController::class, 'share'], ['auth' => true, 'feature' => 'social.sharing']);
$router->add('GET', '/api/community/shared/{token}', [CommunityController::class, 'sharedView'], ['feature' => 'social.sharing']);
$router->add('POST', '/api/community/posts/{id}/save', [CommunityController::class, 'save'], $C);
$router->add('DELETE', '/api/community/posts/{id}/save', [CommunityController::class, 'unsave'], $C);
$router->add('GET', '/api/community/saved', [CommunityController::class, 'saved'], $C);
$router->add('POST', '/api/community/follows', [CommunityController::class, 'follow'], $C);
$router->add('DELETE', '/api/community/follows/{id}', [CommunityController::class, 'unfollow'], $C);
$router->add('GET', '/api/community/trending', [CommunityController::class, 'trending'], $C);
$router->add('GET', '/api/community/search', [CommunityController::class, 'search'], $C);
$router->add('GET', '/api/community/rules', [CommunityController::class, 'rules'], $A);
$router->add('GET', '/api/notifications', [CommunityController::class, 'notifications'], $A);
$router->add('POST', '/api/notifications/read', [CommunityController::class, 'markNotificationsRead'], $A);

// ---------------------------------------------------------- ads (public serving)
$router->add('GET', '/api/ads/{id}/click', [CampaignController::class, 'click'], ['auth' => true, 'feature' => 'business.ads']);
$router->add('GET', '/api/media/ads/{id}', [CampaignController::class, 'adMedia'], $A);

// ----------------------------------------------------------------- payments
$P = ['auth' => true, 'feature' => 'business.plans'];
$router->add('GET', '/api/plans', [PaymentController::class, 'plans'], $P);
$router->add('POST', '/api/payments/checkout', [PaymentController::class, 'checkout'], $P);
$router->add('GET', '/api/payments/verify/{reference}', [PaymentController::class, 'verify'], $A);
$router->add('GET', '/api/payments/history', [PaymentController::class, 'history'], $A);
// Public by design: authenticated by signature, not by session. Processed even if payments are disabled.
$router->add('POST', '/api/webhooks/{gateway}', [PaymentController::class, 'webhook']);

// ------------------------------------------------------------------ support
$S = ['auth' => true, 'feature' => 'admin.support'];
$router->add('POST', '/api/support/tickets', [SupportController::class, 'create'], $S);
$router->add('GET', '/api/support/tickets', [SupportController::class, 'mine'], $S);
$router->add('GET', '/api/support/tickets/{id}', [SupportController::class, 'showMine'], $S);
$router->add('POST', '/api/support/tickets/{id}/messages', [SupportController::class, 'replyMine'], $S);

// ------------------------------------------------------ staff: support & moderation
$router->add('GET', '/api/staff/support/tickets', [SupportController::class, 'queue'], ['perm' => 'support.view']);
$router->add('GET', '/api/staff/support/tickets/{id}', [SupportController::class, 'showStaff'], ['perm' => 'support.view']);
$router->add('POST', '/api/staff/support/tickets/{id}/assign', [SupportController::class, 'assign'], ['perm' => 'support.manage']);
$router->add('POST', '/api/staff/support/tickets/{id}/messages', [SupportController::class, 'replyStaff'], ['perm' => 'support.manage']);
$router->add('POST', '/api/staff/support/tickets/{id}/close', [SupportController::class, 'close'], ['perm' => 'support.manage']);
$router->add('POST', '/api/staff/support/tickets/{id}/uphold-appeal', [SupportController::class, 'upholdAppeal'], ['perm' => 'support.view']);

$MOD = ['perm' => 'moderation.review', 'feature' => 'admin.moderation'];
$router->add('GET', '/api/staff/reports', [ModerationController::class, 'reports'], $MOD);
$router->add('POST', '/api/staff/reports/{id}/claim', [ModerationController::class, 'claimReport'], $MOD);
$router->add('POST', '/api/staff/reports/{id}/resolve', [ModerationController::class, 'resolveReport'], $MOD);
$router->add('GET', '/api/staff/users', [ModerationController::class, 'users'], ['perm' => 'users.view']);
$router->add('GET', '/api/staff/users/{id}', [ModerationController::class, 'userDetail'], ['perm' => 'users.view']);
$router->add('POST', '/api/staff/users/{id}/suspend', [ModerationController::class, 'suspendUser'], ['perm' => 'users.suspend']);
$router->add('POST', '/api/staff/users/{id}/restore', [ModerationController::class, 'restoreUser'], ['perm' => 'users.suspend']);
$router->add('GET', '/api/staff/photos/pending', [ModerationController::class, 'pendingPhotos'], ['perm' => 'profiles.moderate']);
$router->add('GET', '/api/staff/photos/{id}/media', [ModerationController::class, 'photoMedia'], ['perm' => 'profiles.moderate']);
$router->add('POST', '/api/staff/photos/{id}/review', [ModerationController::class, 'reviewPhoto'], ['perm' => 'profiles.moderate']);

// ------------------------------------------------------ staff: super admin
$router->add('GET', '/api/staff/accounts', [AdminController::class, 'staffAccounts'], ['perm' => 'staff.manage']);
$router->add('POST', '/api/staff/accounts', [AdminController::class, 'createStaff'], ['perm' => 'staff.manage']);
$router->add('PUT', '/api/staff/accounts/{id}', [AdminController::class, 'updateStaff'], ['perm' => 'staff.manage']);
$router->add('POST', '/api/staff/accounts/{id}/suspend', [AdminController::class, 'suspendStaff'], ['perm' => 'staff.manage']);
$router->add('POST', '/api/staff/accounts/{id}/restore', [AdminController::class, 'restoreStaff'], ['perm' => 'staff.manage']);
$router->add('DELETE', '/api/staff/accounts/{id}', [AdminController::class, 'deleteStaff'], ['perm' => 'staff.manage']);
$router->add('GET', '/api/staff/roles', [AdminController::class, 'roles'], ['perm' => 'roles.view']);
$router->add('GET', '/api/staff/permissions', [AdminController::class, 'permissions'], ['perm' => 'roles.view']);
$router->add('POST', '/api/staff/roles', [AdminController::class, 'createRole'], ['perm' => 'roles.manage']);
$router->add('PUT', '/api/staff/roles/{id}', [AdminController::class, 'updateRole'], ['perm' => 'roles.manage']);
$router->add('DELETE', '/api/staff/roles/{id}', [AdminController::class, 'deleteRole'], ['perm' => 'roles.manage']);
$router->add('GET', '/api/staff/features', [AdminController::class, 'features'], ['perm' => 'features.manage']);
$router->add('PUT', '/api/staff/features/{key}', [AdminController::class, 'toggleFeature'], ['perm' => 'features.manage']);
$router->add('POST', '/api/staff/features/enable-all', [AdminController::class, 'enableAllFeatures'], ['perm' => 'features.manage']);
$router->add('GET', '/api/staff/settings', [AdminController::class, 'settings'], ['perm' => 'settings.manage']);
$router->add('PUT', '/api/staff/settings/{key}', [AdminController::class, 'updateSetting'], ['perm' => 'settings.manage']);
$router->add('GET', '/api/staff/audit-logs', [AdminController::class, 'auditLogs'], ['perm' => 'audit.view']);
$router->add('GET', '/api/staff/analytics', [AdminController::class, 'analytics'], ['perm' => 'analytics.view', 'feature' => 'admin.analytics']);
$router->add('GET', '/api/staff/announcements', [AdminController::class, 'announcements'], ['perm' => 'community.announce']);
$router->add('POST', '/api/staff/announcements', [AdminController::class, 'createAnnouncement'], ['perm' => 'community.announce']);

// ------------------------------------------------------------- staff: finance
$router->add('GET', '/api/staff/transactions', [FinanceController::class, 'transactions'], ['perm' => 'payments.view']);
$router->add('GET', '/api/staff/transactions/{id}', [FinanceController::class, 'transaction'], ['perm' => 'payments.view']);
$router->add('POST', '/api/staff/transactions/{id}/reverify', [FinanceController::class, 'reverify'], ['perm' => 'payments.reconcile']);
$router->add('POST', '/api/staff/transactions/{id}/refunds', [FinanceController::class, 'requestRefund'], ['perm' => 'payments.refund']);
$router->add('POST', '/api/staff/refunds/{id}/approve', [FinanceController::class, 'approveRefund'], ['perm' => 'payments.refund_approve']);
$router->add('POST', '/api/staff/refunds/{id}/reject', [FinanceController::class, 'rejectRefund'], ['perm' => 'payments.refund_approve']);
$router->add('GET', '/api/staff/reconciliation', [FinanceController::class, 'reconciliation'], ['perm' => 'payments.reconcile']);
$router->add('GET', '/api/staff/reports/payments.csv', [FinanceController::class, 'reportCsv'], ['perm' => 'payments.reports']);
$router->add('GET', '/api/staff/gateways', [FinanceController::class, 'gateways'], ['perm' => 'payments.configure']);
$router->add('PUT', '/api/staff/gateways/{code}', [FinanceController::class, 'updateGateway'], ['perm' => 'payments.configure']);
$router->add('GET', '/api/staff/plans', [FinanceController::class, 'plans'], ['perm' => 'payments.view']);
$router->add('POST', '/api/staff/plans', [FinanceController::class, 'savePlan'], ['perm' => 'payments.plans']);
$router->add('PUT', '/api/staff/plans/{id}', [FinanceController::class, 'savePlan'], ['perm' => 'payments.plans']);

// ------------------------------------------------------ staff: campaigns & ads
$CM = ['perm' => 'campaigns.manage', 'feature' => 'business.campaigns'];
$router->add('GET', '/api/staff/campaigns', [CampaignController::class, 'campaigns'], $CM);
$router->add('POST', '/api/staff/campaigns', [CampaignController::class, 'saveCampaign'], $CM);
$router->add('PUT', '/api/staff/campaigns/{id}', [CampaignController::class, 'saveCampaign'], $CM);
$router->add('POST', '/api/staff/campaigns/{id}/send', [CampaignController::class, 'sendCampaign'], $CM);
$router->add('GET', '/api/staff/campaigns/{id}/analytics', [CampaignController::class, 'campaignAnalytics'], $CM);
$router->add('GET', '/api/staff/advertisers', [CampaignController::class, 'advertisers'], ['perm' => 'ads.manage']);
$router->add('POST', '/api/staff/advertisers', [CampaignController::class, 'createAdvertiser'], ['perm' => 'ads.manage']);
$router->add('POST', '/api/staff/advertisers/{id}/status', [CampaignController::class, 'setAdvertiserStatus'], ['perm' => 'ads.review']);
$AD = ['perm' => 'ads.manage', 'feature' => 'business.ads'];
$router->add('GET', '/api/staff/ads', [CampaignController::class, 'ads'], $AD);
$router->add('POST', '/api/staff/ads', [CampaignController::class, 'createAd'], $AD);
$router->add('POST', '/api/staff/ads/{id}/submit', [CampaignController::class, 'submitAd'], $AD);
$router->add('POST', '/api/staff/ads/{id}/review', [CampaignController::class, 'reviewAd'], ['perm' => 'ads.review']);
$router->add('POST', '/api/staff/ads/{id}/suspend', [CampaignController::class, 'suspendAd'], ['perm' => 'ads.review']);

return $router;
