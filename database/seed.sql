-- ============================================================================
-- Seed data: permissions, system roles and their default grants, settings.
-- Run AFTER schema.sql. Safe to re-run (INSERT IGNORE).
-- Feature flags are created by the application's feature registry on first use.
-- Create the first Super Admin with: php bin/create_super_admin.php
-- ============================================================================
USE codealpha_matchcom;

INSERT IGNORE INTO permissions (code, group_name, description) VALUES
 ('users.view',              'Users',        'View permitted user records (masked contact details)'),
 ('users.view_pii',          'Users',        'View unmasked email and phone numbers'),
 ('users.suspend',           'Users',        'Suspend and reinstate member accounts'),
 ('profiles.moderate',       'Profiles',     'Approve or reject profile photos'),
 ('verification.review',     'Verification', 'Open assigned verification cases and view verification media'),
 ('verification.approve',    'Verification', 'Approve verified profiles'),
 ('verification.reject',     'Verification', 'Reject verification with a reason'),
 ('moderation.review',       'Moderation',   'Work the moderation report queue'),
 ('community.moderate',      'Community',    'Hide or remove posts and comments'),
 ('community.announce',      'Community',    'Publish announcements and community rules'),
 ('support.view',            'Support',      'View support tickets assigned to the team'),
 ('support.manage',          'Support',      'Reply to, assign and close support tickets'),
 ('payments.view',           'Payments',     'View transactions, subscriptions and references'),
 ('payments.refund',         'Payments',     'Request authorised refunds'),
 ('payments.refund_approve', 'Payments',     'Approve and process refund requests (maker-checker)'),
 ('payments.reconcile',      'Payments',     'Re-verify transactions and run reconciliation'),
 ('payments.reports',        'Payments',     'Download financial reports'),
 ('payments.configure',      'Payments',     'Configure payment gateways and credentials'),
 ('payments.plans',          'Payments',     'Create and edit subscription plans and pricing'),
 ('campaigns.manage',        'Campaigns',    'Create and schedule campaigns'),
 ('ads.manage',              'Advertising',  'Create advertisers and draft advertisements'),
 ('ads.review',              'Advertising',  'Approve, reject or suspend advertisements'),
 ('features.manage',         'System',       'Toggle system modules'),
 ('settings.manage',         'System',       'Change protected configuration'),
 ('audit.view',              'System',       'View audit logs'),
 ('analytics.view',          'System',       'View system analytics'),
 ('roles.view',              'Access',       'View roles and permissions'),
 ('roles.manage',            'Access',       'Create roles and assign granular permissions'),
 ('staff.manage',            'Access',       'Create, edit, suspend and delete staff accounts');

INSERT IGNORE INTO roles (slug, name, description, is_system) VALUES
 ('super_admin',          'Super Admin',          'Full control of the platform. Bypasses permission checks.', 1),
 ('admin',                'Admin',                'Users, profiles and moderation',                          1),
 ('finance',              'Finance',              'Payments, plans and refunds',                              1),
 ('support',              'Support',              'Tickets, reports and appeals',                             1),
 ('verification_officer', 'Verification Officer', 'Reviews verification videos and decides approvals',         1),
 ('community_moderator',  'Community Moderator',  'Community queues, posts and comments',                     1),
 ('campaign_manager',     'Campaign Manager',     'Campaigns, advertisers and advertisement review',          1);

-- super_admin is granted every permission (also enforced in code).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'super_admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('users.view','users.suspend','moderation.review','community.moderate','profiles.moderate',
  'support.view','roles.view','analytics.view','users.view_pii')
WHERE r.slug = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('payments.view','payments.refund','payments.reconcile','payments.reports','payments.plans','analytics.view')
WHERE r.slug = 'finance';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('support.view','support.manage','users.view','moderation.review')
WHERE r.slug = 'support';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('verification.review','verification.approve','verification.reject','users.view')
WHERE r.slug = 'verification_officer';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('moderation.review','community.moderate','community.announce','users.view')
WHERE r.slug = 'community_moderator';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
 ('campaigns.manage','ads.manage','ads.review','analytics.view')
WHERE r.slug = 'campaign_manager';

-- Protected system settings. Values are JSON. Bounds are enforced in code.
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
 ('app.display_name',            JSON_QUOTE('CodeAlpha Match & Community')),
 ('support.email',               JSON_QUOTE('support@example.com')),
 ('auth.min_age',                '18'),
 ('auth.otp_ttl_minutes',        '10'),
 ('profiles.max_photos',         '6'),
 ('calls.ring_seconds',          '45'),
 ('calls.max_per_hour',          '20'),
 ('verification.retention_days', '30'),
 ('ads.default_frequency_cap',   '3'),
 ('community.rules',             JSON_QUOTE('Be respectful. No harassment, hate, spam, scams, or sexual content involving minors. Do not share other people\'s private information.')),
 ('community.max_post_chars',    '2000');
