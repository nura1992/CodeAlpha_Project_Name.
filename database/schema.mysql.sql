-- ============================================================================
-- CodeAlpha — Matchmaking & Community Platform
-- Authoritative MySQL schema (MySQL 8.0+ / MariaDB 10.4+)
--
-- The installer (bin/install.php) can translate this file to SQLite for local
-- development when no MySQL server is available. Production always uses MySQL.
--
-- Conventions:
--   * Every table has a surrogate `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
--     except join tables which use composite primary keys.
--   * Indexes are declared as standalone CREATE INDEX statements so the file
--     stays portable across MySQL and SQLite.
--   * Timestamps are DATETIME/TIMESTAMP with DEFAULT CURRENT_TIMESTAMP.
--   * Media files are NEVER stored in the database — only paths and metadata.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Accounts & authentication
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(32) NULL,
    password_hash VARCHAR(255) NOT NULL,
    gender ENUM('male','female') NULL,
    birthdate DATE NULL,
    status ENUM('active','suspended','pending_deletion','deleted') NOT NULL DEFAULT 'active',
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME NULL,
    phone_verified_at DATETIME NULL,
    failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX users_uuid_unique ON users (uuid);
CREATE UNIQUE INDEX users_email_unique ON users (email);
CREATE UNIQUE INDEX users_phone_unique ON users (phone);
CREATE INDEX users_status_idx ON users (status);
CREATE INDEX users_gender_idx ON users (gender);
CREATE INDEX users_staff_idx ON users (is_staff);

CREATE TABLE IF NOT EXISTS otps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    channel ENUM('email','phone') NOT NULL,
    destination VARCHAR(190) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose ENUM('registration','login','password_reset','account_recovery') NOT NULL DEFAULT 'registration',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otps_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX otps_destination_idx ON otps (destination, purpose);
CREATE INDEX otps_user_idx ON otps (user_id);
CREATE INDEX otps_expires_idx ON otps (expires_at);

CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX password_resets_user_idx ON password_resets (user_id);
CREATE INDEX password_resets_expires_idx ON password_resets (expires_at);

CREATE TABLE IF NOT EXISTS rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(150) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX rate_limits_bucket_window ON rate_limits (bucket, window_start);
CREATE INDEX rate_limits_expires_idx ON rate_limits (expires_at);

-- ----------------------------------------------------------------------------
-- 2. RBAC — roles, permissions, staff assignments
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    dashboard ENUM('super_admin','admin','finance','support') NULL,
    is_super TINYINT(1) NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX roles_slug_unique ON roles (slug);

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'general',
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX permissions_slug_unique ON permissions (slug);
CREATE INDEX permissions_category_idx ON permissions (category);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX user_roles_role_idx ON user_roles (role_id);

-- ----------------------------------------------------------------------------
-- 3. Matchmaking profiles & media
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS profiles (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    display_name VARCHAR(100) NULL,
    bio TEXT NULL,
    interests JSON NULL,
    location_country VARCHAR(100) NULL,
    location_city VARCHAR(100) NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    relationship_intention ENUM('casual','relationship','marriage','friendship') NULL,
    min_age INT UNSIGNED NOT NULL DEFAULT 18,
    max_age INT UNSIGNED NOT NULL DEFAULT 99,
    height_cm INT UNSIGNED NULL,
    education VARCHAR(100) NULL,
    occupation VARCHAR(100) NULL,
    religion VARCHAR(100) NULL,
    smoking ENUM('no','socially','yes') NULL,
    drinking ENUM('no','socially','yes') NULL,
    verified_at DATETIME NULL,
    verification_status ENUM('unverified','pending','approved','rejected') NOT NULL DEFAULT 'unverified',
    discoverable TINYINT(1) NOT NULL DEFAULT 0,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    profile_score INT UNSIGNED NOT NULL DEFAULT 0,
    privacy JSON NULL,
    notification_settings JSON NULL,
    last_active_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX profiles_verification_idx ON profiles (verification_status);
CREATE INDEX profiles_discoverable_idx ON profiles (discoverable);
CREATE INDEX profiles_last_active_idx ON profiles (last_active_at);

CREATE TABLE IF NOT EXISTS profile_photos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    path VARCHAR(255) NOT NULL,
    mime VARCHAR(60) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    is_avatar TINYINT(1) NOT NULL DEFAULT 0,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    moderation_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_photos_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX profile_photos_user_idx ON profile_photos (user_id, position);

-- ----------------------------------------------------------------------------
-- 4. Video verification
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS verification_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    media_path VARCHAR(255) NOT NULL,
    mime VARCHAR(60) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds INT UNSIGNED NULL,
    status ENUM('submitted','under_review','approved','rejected') NOT NULL DEFAULT 'submitted',
    reviewer_id BIGINT UNSIGNED NULL,
    review_reason VARCHAR(255) NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_verification_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_verification_requests_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX verification_requests_status_idx ON verification_requests (status, submitted_at);
CREATE INDEX verification_requests_user_idx ON verification_requests (user_id);

CREATE TABLE IF NOT EXISTS verification_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('approved','rejected') NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_verification_reviews_request FOREIGN KEY (request_id) REFERENCES verification_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_verification_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX verification_reviews_request_idx ON verification_reviews (request_id);

-- ----------------------------------------------------------------------------
-- 5. Matching engine — likes, passes, favourites, matches, blocks
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS likes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NOT NULL,
    action ENUM('like','pass') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_likes_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX likes_pair_unique ON likes (user_id, target_user_id);
CREATE INDEX likes_target_idx ON likes (target_user_id, action);

CREATE TABLE IF NOT EXISTS favourites (
    user_id BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, target_user_id),
    CONSTRAINT fk_favourites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_favourites_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_one_id BIGINT UNSIGNED NOT NULL,
    user_two_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','unmatched') NOT NULL DEFAULT 'active',
    unmatched_by BIGINT UNSIGNED NULL,
    unmatched_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_matches_user_one FOREIGN KEY (user_one_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_user_two FOREIGN KEY (user_two_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_unmatched_by FOREIGN KEY (unmatched_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX matches_pair_unique ON matches (user_one_id, user_two_id);
CREATE INDEX matches_user_one_idx ON matches (user_one_id, status);
CREATE INDEX matches_user_two_idx ON matches (user_two_id, status);

CREATE TABLE IF NOT EXISTS user_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blocker_id BIGINT UNSIGNED NOT NULL,
    blocked_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX user_blocks_pair_unique ON user_blocks (blocker_id, blocked_id);
CREATE INDEX user_blocks_blocked_idx ON user_blocks (blocked_id);

-- ----------------------------------------------------------------------------
-- 6. Conversations & messages
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at DATETIME NULL,
    CONSTRAINT fk_conversations_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_members (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME NULL,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_conversation_members_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX conversation_members_user_idx ON conversation_members (user_id);

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    type ENUM('text','image','voice','system') NOT NULL DEFAULT 'text',
    body TEXT NULL,
    media_path VARCHAR(255) NULL,
    media_mime VARCHAR(60) NULL,
    media_duration INT UNSIGNED NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    edited_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX messages_conversation_idx ON messages (conversation_id, created_at);
CREATE INDEX messages_sender_idx ON messages (sender_id);

-- ----------------------------------------------------------------------------
-- 7. Audio / video calls
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS calls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    caller_id BIGINT UNSIGNED NOT NULL,
    callee_id BIGINT UNSIGNED NOT NULL,
    type ENUM('audio','video') NOT NULL DEFAULT 'audio',
    status ENUM('ringing','accepted','declined','missed','ended','cancelled','failed') NOT NULL DEFAULT 'ringing',
    started_at DATETIME NULL,
    answered_at DATETIME NULL,
    ended_at DATETIME NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    end_reason VARCHAR(60) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_calls_caller FOREIGN KEY (caller_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_calls_callee FOREIGN KEY (callee_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX calls_caller_idx ON calls (caller_id, created_at);
CREATE INDEX calls_callee_idx ON calls (callee_id, created_at);
CREATE INDEX calls_status_idx ON calls (status);

CREATE TABLE IF NOT EXISTS call_signals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('offer','answer','ice','accept','decline','cancel','end') NOT NULL,
    payload JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_call_signals_call FOREIGN KEY (call_id) REFERENCES calls (id) ON DELETE CASCADE,
    CONSTRAINT fk_call_signals_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX call_signals_call_idx ON call_signals (call_id, created_at);

CREATE TABLE IF NOT EXISTS call_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blocker_id BIGINT UNSIGNED NOT NULL,
    blocked_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_call_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_call_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX call_blocks_pair_unique ON call_blocks (blocker_id, blocked_id);
CREATE INDEX call_blocks_blocked_idx ON call_blocks (blocked_id);

-- ----------------------------------------------------------------------------
-- 8. Community (Facebook-inspired social feed)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS community_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('text','image','video','link') NOT NULL DEFAULT 'text',
    body TEXT NULL,
    media_path VARCHAR(255) NULL,
    media_mime VARCHAR(60) NULL,
    link_url VARCHAR(255) NULL,
    visibility ENUM('public','followers') NOT NULL DEFAULT 'public',
    topic VARCHAR(100) NULL,
    hashtags JSON NULL,
    is_announcement TINYINT(1) NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('published','hidden','removed') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_community_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX community_posts_user_idx ON community_posts (user_id, created_at);
CREATE INDEX community_posts_status_idx ON community_posts (status, created_at);
CREATE INDEX community_posts_topic_idx ON community_posts (topic);

CREATE TABLE IF NOT EXISTS post_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    status ENUM('published','hidden','removed') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_comments_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_comments_parent FOREIGN KEY (parent_id) REFERENCES post_comments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX post_comments_post_idx ON post_comments (post_id, created_at);
CREATE INDEX post_comments_user_idx ON post_comments (user_id);

CREATE TABLE IF NOT EXISTS post_reactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NULL,
    comment_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('like','love','haha','wow','sad','angry') NOT NULL DEFAULT 'like',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_reactions_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_reactions_comment FOREIGN KEY (comment_id) REFERENCES post_comments (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_reactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX post_reactions_post_idx ON post_reactions (post_id);
CREATE INDEX post_reactions_comment_idx ON post_reactions (comment_id);
CREATE INDEX post_reactions_user_idx ON post_reactions (user_id);

CREATE TABLE IF NOT EXISTS post_shares (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('internal','copy_link','whatsapp','facebook','twitter','other') NOT NULL DEFAULT 'internal',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_shares_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_shares_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX post_shares_post_idx ON post_shares (post_id);

CREATE TABLE IF NOT EXISTS saved_posts (
    user_id BIGINT UNSIGNED NOT NULL,
    post_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id),
    CONSTRAINT fk_saved_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_saved_posts_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS follows (
    follower_id BIGINT UNSIGNED NOT NULL,
    followee_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, followee_id),
    CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_follows_followee FOREIGN KEY (followee_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX follows_followee_idx ON follows (followee_id);

-- ----------------------------------------------------------------------------
-- 9. Safety — reports & moderation
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reporter_id BIGINT UNSIGNED NOT NULL,
    reported_user_id BIGINT UNSIGNED NULL,
    post_id BIGINT UNSIGNED NULL,
    comment_id BIGINT UNSIGNED NULL,
    type ENUM('spam','harassment','nudity','fake_profile','scam','underage','impersonation','hate_speech','other') NOT NULL DEFAULT 'other',
    reason VARCHAR(255) NOT NULL,
    details TEXT NULL,
    status ENUM('open','in_review','resolved','dismissed') NOT NULL DEFAULT 'open',
    handled_by BIGINT UNSIGNED NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    CONSTRAINT fk_user_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_reports_user FOREIGN KEY (reported_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_reports_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_reports_comment FOREIGN KEY (comment_id) REFERENCES post_comments (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_reports_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX user_reports_status_idx ON user_reports (status, created_at);
CREATE INDEX user_reports_reported_user_idx ON user_reports (reported_user_id);
CREATE INDEX user_reports_post_idx ON user_reports (post_id);

-- ----------------------------------------------------------------------------
-- 10. Feature flags (Super Admin module toggles)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS feature_flags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    flag_key VARCHAR(100) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_feature_flags_actor FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX feature_flags_key_unique ON feature_flags (flag_key);

-- ----------------------------------------------------------------------------
-- 11. Payments — gateways, transactions, refunds, plans, subscriptions
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS payment_gateways (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name ENUM('paystack','flutterwave') NOT NULL,
    public_key VARCHAR(255) NULL,
    secret_key_enc TEXT NULL,
    webhook_secret_enc TEXT NULL,
    mode ENUM('test','live') NOT NULL DEFAULT 'test',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    config JSON NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_gateways_actor FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX payment_gateways_name_unique ON payment_gateways (name);

-- subscription_plans is created before transactions because
-- transactions.plan_id carries a foreign key to it.
CREATE TABLE IF NOT EXISTS subscription_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    interval ENUM('month','year') NOT NULL DEFAULT 'month',
    features JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX subscription_plans_slug_unique ON subscription_plans (slug);

CREATE TABLE IF NOT EXISTS transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    gateway VARCHAR(50) NOT NULL,
    reference VARCHAR(100) NOT NULL,
    gateway_reference VARCHAR(100) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    status ENUM('pending','success','failed','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    purpose ENUM('subscription','one_time') NOT NULL DEFAULT 'subscription',
    plan_id BIGINT UNSIGNED NULL,
    gateway_response JSON NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX transactions_reference_unique ON transactions (reference);
CREATE INDEX transactions_user_idx ON transactions (user_id, created_at);
CREATE INDEX transactions_status_idx ON transactions (status);
CREATE INDEX transactions_gateway_idx ON transactions (gateway, status);

CREATE TABLE IF NOT EXISTS refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    reason VARCHAR(255) NOT NULL,
    status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    gateway_reference VARCHAR(100) NULL,
    processed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_refunds_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
    CONSTRAINT fk_refunds_processor FOREIGN KEY (processed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX refunds_transaction_idx ON refunds (transaction_id);

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','cancelled','expired','past_due') NOT NULL DEFAULT 'active',
    gateway VARCHAR(50) NULL,
    transaction_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    current_period_end DATETIME NOT NULL,
    cancelled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX subscriptions_user_idx ON subscriptions (user_id, status);
CREATE INDEX subscriptions_period_end_idx ON subscriptions (current_period_end);

-- ----------------------------------------------------------------------------
-- 12. Campaigns & advertisements
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    objective VARCHAR(100) NULL,
    audience JSON NULL,
    budget DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status ENUM('draft','active','paused','completed') NOT NULL DEFAULT 'draft',
    promo_code VARCHAR(50) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_campaigns_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX campaigns_promo_code_unique ON campaigns (promo_code);
CREATE INDEX campaigns_status_idx ON campaigns (status, starts_at);

CREATE TABLE IF NOT EXISTS campaign_redemptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    redeemed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_campaign_redemptions_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_redemptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX campaign_redemptions_unique ON campaign_redemptions (campaign_id, user_id);
CREATE INDEX campaign_redemptions_user_idx ON campaign_redemptions (user_id);

CREATE TABLE IF NOT EXISTS advertisements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NULL,
    advertiser_name VARCHAR(150) NOT NULL,
    type ENUM('image','video','text') NOT NULL DEFAULT 'text',
    headline VARCHAR(255) NULL,
    body TEXT NULL,
    media_path VARCHAR(255) NULL,
    cta_text VARCHAR(100) NULL,
    cta_url VARCHAR(255) NULL,
    placement ENUM('community_feed','discovery') NOT NULL DEFAULT 'community_feed',
    status ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
    daily_budget DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    frequency_cap INT UNSIGNED NOT NULL DEFAULT 3,
    created_by BIGINT UNSIGNED NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    review_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_advertisements_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_advertisements_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_advertisements_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX advertisements_status_idx ON advertisements (status, placement);
CREATE INDEX advertisements_campaign_idx ON advertisements (campaign_id);

CREATE TABLE IF NOT EXISTS ad_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ad_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    event ENUM('impression','click') NOT NULL,
    ip_hash CHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ad_events_ad FOREIGN KEY (ad_id) REFERENCES advertisements (id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ad_events_ad_idx ON ad_events (ad_id, event, created_at);
CREATE INDEX ad_events_user_idx ON ad_events (user_id, event, created_at);

-- ----------------------------------------------------------------------------
-- 13. Support — tickets, messages, appeals are handled via tickets
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(200) NOT NULL,
    category ENUM('account','payment','verification','community','safety','other') NOT NULL DEFAULT 'other',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    CONSTRAINT fk_support_tickets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX support_tickets_user_idx ON support_tickets (user_id);
CREATE INDEX support_tickets_status_idx ON support_tickets (status, created_at);

CREATE TABLE IF NOT EXISTS support_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_messages_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_support_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX support_messages_ticket_idx ON support_messages (ticket_id, created_at);

-- ----------------------------------------------------------------------------
-- 14. Notifications & web push
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(60) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NULL,
    data JSON NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX notifications_user_idx ON notifications (user_id, read_at, created_at);
CREATE INDEX notifications_type_idx ON notifications (type);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    keys JSON NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX push_subscriptions_user_idx ON push_subscriptions (user_id);

-- ----------------------------------------------------------------------------
-- 15. Audit log & system settings
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NULL,
    actor_type ENUM('user','staff','system') NOT NULL DEFAULT 'user',
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(60) NULL,
    target_id VARCHAR(64) NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX audit_logs_actor_idx ON audit_logs (actor_id, created_at);
CREATE INDEX audit_logs_action_idx ON audit_logs (action, created_at);
CREATE INDEX audit_logs_target_idx ON audit_logs (target_type, target_id);
CREATE INDEX audit_logs_created_idx ON audit_logs (created_at);

CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    value JSON NULL,
    is_protected TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_actor FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX settings_key_unique ON settings (setting_key);

SET FOREIGN_KEY_CHECKS = 1;
