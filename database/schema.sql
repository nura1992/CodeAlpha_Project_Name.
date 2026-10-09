-- ============================================================================
-- CodeAlpha Match & Community Platform — MySQL 8.0+ schema
-- Engine: InnoDB, charset utf8mb4. All money is stored in minor units (kobo).
-- Append-only tables (audit_logs, transaction_events, verification_decisions)
-- are protected by triggers that reject UPDATE and DELETE.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS codealpha_matchcom
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE codealpha_matchcom;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- accounts --
CREATE TABLE IF NOT EXISTS users (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(30) NULL,
  email                VARCHAR(190) NULL,
  phone                VARCHAR(20) NULL,
  password_hash        VARCHAR(255) NULL,
  gender               ENUM('male','female','other') NULL,
  date_of_birth        DATE NULL,
  account_type         ENUM('member','staff') NOT NULL DEFAULT 'member',
  status               ENUM('unverified','active','suspended','deleted') NOT NULL DEFAULT 'unverified',
  suspension_reason    VARCHAR(500) NULL,
  email_verified_at    DATETIME NULL,
  phone_verified_at    DATETIME NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  failed_logins        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until         DATETIME NULL,
  last_login_at        DATETIME NULL,
  deleted_at           DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_discovery (account_type, status, gender),
  KEY idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_settings (
  user_id            BIGINT UNSIGNED PRIMARY KEY,
  notify_messages    TINYINT(1) NOT NULL DEFAULT 1,
  notify_calls       TINYINT(1) NOT NULL DEFAULT 1,
  notify_community   TINYINT(1) NOT NULL DEFAULT 1,
  allow_calls        TINYINT(1) NOT NULL DEFAULT 1,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL,
  csrf_token   CHAR(64) NOT NULL,
  user_agent   VARCHAR(255) NULL,
  ip_address   VARCHAR(45) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME NOT NULL,
  revoked_at   DATETIME NULL,
  UNIQUE KEY uq_sessions_token (token_hash),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS otp_codes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NULL,
  destination  VARCHAR(190) NOT NULL,
  channel      ENUM('email','sms') NOT NULL,
  purpose      ENUM('register','login','reset') NOT NULL,
  code_hash    CHAR(64) NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at   DATETIME NOT NULL,
  consumed_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_otp_lookup (destination, purpose, consumed_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------------- RBAC --
CREATE TABLE IF NOT EXISTS roles (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug         VARCHAR(60) NOT NULL,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  is_system    TINYINT(1) NOT NULL DEFAULT 0,
  created_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(80) NOT NULL,
  group_name   VARCHAR(60) NOT NULL,
  description  VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id      BIGINT UNSIGNED NOT NULL,
  role_id      INT UNSIGNED NOT NULL,
  assigned_by  BIGINT UNSIGNED NULL,
  assigned_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- profiles --
CREATE TABLE IF NOT EXISTS profiles (
  user_id                   BIGINT UNSIGNED PRIMARY KEY,
  display_name              VARCHAR(60) NOT NULL,
  bio                       VARCHAR(1000) NULL,
  city                      VARCHAR(100) NULL,
  state                     VARCHAR(100) NULL,
  country                   CHAR(2) NOT NULL DEFAULT 'NG',
  latitude                  DECIMAL(9,6) NULL,
  longitude                 DECIMAL(9,6) NULL,
  relationship_intent       ENUM('serious','marriage','friendship','dating','unsure') NOT NULL DEFAULT 'unsure',
  looking_for_gender        ENUM('male','female','everyone') NOT NULL DEFAULT 'everyone',
  age_pref_min             TINYINT UNSIGNED NOT NULL DEFAULT 18,
  age_pref_max             TINYINT UNSIGNED NOT NULL DEFAULT 99,
  approval_status           ENUM('draft','pending','approved','rejected','suspended') NOT NULL DEFAULT 'draft',
  rejection_reason          VARCHAR(500) NULL,
  approved_at               DATETIME NULL,
  approved_by               BIGINT UNSIGNED NULL,
  reverification_required   TINYINT(1) NOT NULL DEFAULT 0,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_profiles_discovery (approval_status, looking_for_gender, age_pref_min, age_pref_max),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_profiles_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS interests (
  id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_interests_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profile_interests (
  user_id      BIGINT UNSIGNED NOT NULL,
  interest_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, interest_id),
  CONSTRAINT fk_pi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_interest FOREIGN KEY (interest_id) REFERENCES interests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profile_photos (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            BIGINT UNSIGNED NOT NULL,
  storage_key        CHAR(48) NOT NULL,
  mime               VARCHAR(60) NOT NULL,
  size_bytes         INT UNSIGNED NOT NULL,
  sha256             CHAR(64) NOT NULL,
  moderation_status  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  is_primary         TINYINT(1) NOT NULL DEFAULT 0,
  rejection_reason   VARCHAR(500) NULL,
  reviewed_by        BIGINT UNSIGNED NULL,
  reviewed_at        DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  UNIQUE KEY uq_photo_storage (storage_key),
  KEY idx_photos_user (user_id, moderation_status),
  CONSTRAINT fk_photo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------- verification --
CREATE TABLE IF NOT EXISTS verification_requests (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id               BIGINT UNSIGNED NOT NULL,
  status                ENUM('submitted','in_review','approved','rejected','superseded') NOT NULL DEFAULT 'submitted',
  storage_key           CHAR(48) NULL,
  video_mime            VARCHAR(60) NULL,
  video_size_bytes      INT UNSIGNED NULL,
  video_sha256          CHAR(64) NULL,
  assigned_reviewer_id  BIGINT UNSIGNED NULL,
  assigned_at           DATETIME NULL,
  decided_by            BIGINT UNSIGNED NULL,
  decided_at            DATETIME NULL,
  decision_reason       VARCHAR(500) NULL,
  retention_until       DATETIME NULL,
  media_deleted_at      DATETIME NULL,
  submitted_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_verification_storage (storage_key),
  KEY idx_verification_queue (status, submitted_at),
  KEY idx_verification_user (user_id),
  CONSTRAINT fk_vr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vr_reviewer FOREIGN KEY (assigned_reviewer_id) REFERENCES users(id),
  CONSTRAINT fk_vr_decider FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS verification_decisions (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  verification_request_id   BIGINT UNSIGNED NOT NULL,
  reviewer_id               BIGINT UNSIGNED NOT NULL,
  action                    ENUM('claim','view_media','approve','reject','media_deleted') NOT NULL,
  from_status               VARCHAR(20) NULL,
  to_status                 VARCHAR(20) NULL,
  reason                    VARCHAR(500) NULL,
  ip_address                VARCHAR(45) NULL,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vd_request (verification_request_id),
  CONSTRAINT fk_vd_request FOREIGN KEY (verification_request_id) REFERENCES verification_requests(id),
  CONSTRAINT fk_vd_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------ matching & messages --
CREATE TABLE IF NOT EXISTS likes (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_user_id  BIGINT UNSIGNED NOT NULL,
  to_user_id    BIGINT UNSIGNED NOT NULL,
  action        ENUM('like','pass') NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_likes_pair (from_user_id, to_user_id),
  KEY idx_likes_target (to_user_id, action),
  CONSTRAINT fk_likes_from FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_likes_to FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favourites (
  user_id            BIGINT UNSIGNED NOT NULL,
  favourite_user_id  BIGINT UNSIGNED NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, favourite_user_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_target FOREIGN KEY (favourite_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blocks (
  blocker_id  BIGINT UNSIGNED NOT NULL,
  blocked_id  BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_id, blocked_id),
  KEY idx_blocks_reverse (blocked_id, blocker_id),
  CONSTRAINT fk_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS matches (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_low_id   BIGINT UNSIGNED NOT NULL,
  user_high_id  BIGINT UNSIGNED NOT NULL,
  status        ENUM('active','unmatched','blocked') NOT NULL DEFAULT 'active',
  ended_by      BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at      DATETIME NULL,
  UNIQUE KEY uq_matches_pair (user_low_id, user_high_id),
  KEY idx_matches_high (user_high_id, status),
  CONSTRAINT fk_matches_low FOREIGN KEY (user_low_id) REFERENCES users(id),
  CONSTRAINT fk_matches_high FOREIGN KEY (user_high_id) REFERENCES users(id),
  CONSTRAINT chk_matches_order CHECK (user_low_id < user_high_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversations (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id         BIGINT UNSIGNED NULL UNIQUE,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at  DATETIME NULL,
  KEY idx_conversations_last (last_message_at),
  CONSTRAINT fk_conv_match FOREIGN KEY (match_id) REFERENCES matches(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_members (
  conversation_id        BIGINT UNSIGNED NOT NULL,
  user_id                BIGINT UNSIGNED NOT NULL,
  last_read_message_id   BIGINT UNSIGNED NULL,
  muted                  TINYINT(1) NOT NULL DEFAULT 0,
  joined_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_cm_user (user_id),
  CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id          BIGINT UNSIGNED NOT NULL,
  sender_id                BIGINT UNSIGNED NOT NULL,
  client_uuid              CHAR(36) NULL,
  type                     ENUM('text','image','voice') NOT NULL DEFAULT 'text',
  body                     TEXT NULL,
  storage_key              CHAR(48) NULL,
  media_mime               VARCHAR(60) NULL,
  media_size_bytes         INT UNSIGNED NULL,
  media_duration_seconds   SMALLINT UNSIGNED NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at               DATETIME NULL,
  UNIQUE KEY uq_messages_client (sender_id, client_uuid),
  UNIQUE KEY uq_messages_storage (storage_key),
  KEY idx_messages_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_receipts (
  message_id    BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  delivered_at  DATETIME NULL,
  read_at       DATETIME NULL,
  PRIMARY KEY (message_id, user_id),
  CONSTRAINT fk_mr_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS calls (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  caller_id         BIGINT UNSIGNED NOT NULL,
  callee_id         BIGINT UNSIGNED NOT NULL,
  conversation_id   BIGINT UNSIGNED NULL,
  media             ENUM('audio','video') NOT NULL,
  status            ENUM('ringing','accepted','ended','missed','declined','cancelled','failed') NOT NULL DEFAULT 'ringing',
  end_reason        VARCHAR(40) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_at       DATETIME NULL,
  ended_at          DATETIME NULL,
  duration_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_calls_callee (callee_id, status, created_at),
  KEY idx_calls_caller (caller_id, created_at),
  CONSTRAINT fk_calls_caller FOREIGN KEY (caller_id) REFERENCES users(id),
  CONSTRAINT fk_calls_callee FOREIGN KEY (callee_id) REFERENCES users(id),
  CONSTRAINT fk_calls_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------ safety & moderation --
CREATE TABLE IF NOT EXISTS user_reports (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id     BIGINT UNSIGNED NOT NULL,
  target_type     ENUM('user','post','comment','message','call') NOT NULL,
  target_id       BIGINT UNSIGNED NOT NULL,
  reason          ENUM('harassment','fake_profile','scam','nudity','hate','spam','underage','threat','other') NOT NULL,
  details         VARCHAR(2000) NULL,
  queue           ENUM('moderation','support') NOT NULL DEFAULT 'moderation',
  status          ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
  assigned_to     BIGINT UNSIGNED NULL,
  action_taken    VARCHAR(40) NULL,
  resolution_note VARCHAR(1000) NULL,
  resolved_by     BIGINT UNSIGNED NULL,
  resolved_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reports_queue (queue, status, created_at),
  KEY idx_reports_target (target_type, target_id),
  CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------- community --
CREATE TABLE IF NOT EXISTS community_posts (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  author_id        BIGINT UNSIGNED NOT NULL,
  body             TEXT NULL,
  visibility       ENUM('public','followers','private') NOT NULL DEFAULT 'public',
  storage_key      CHAR(48) NULL,
  media_type       ENUM('image','video') NULL,
  media_mime       VARCHAR(60) NULL,
  media_size_bytes INT UNSIGNED NULL,
  shared_post_id   BIGINT UNSIGNED NULL,
  share_token      CHAR(32) NULL,
  status           ENUM('published','hidden','removed') NOT NULL DEFAULT 'published',
  reaction_count   INT UNSIGNED NOT NULL DEFAULT 0,
  comment_count    INT UNSIGNED NOT NULL DEFAULT 0,
  share_count      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at       DATETIME NULL,
  UNIQUE KEY uq_posts_storage (storage_key),
  UNIQUE KEY uq_posts_share_token (share_token),
  KEY idx_posts_feed (status, visibility, created_at),
  KEY idx_posts_author (author_id, created_at),
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_posts_shared FOREIGN KEY (shared_post_id) REFERENCES community_posts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hashtags (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tag         VARCHAR(50) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hashtags_tag (tag)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS post_hashtags (
  post_id     BIGINT UNSIGNED NOT NULL,
  hashtag_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, hashtag_id),
  KEY idx_ph_tag (hashtag_id),
  CONSTRAINT fk_ph_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ph_tag FOREIGN KEY (hashtag_id) REFERENCES hashtags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS post_comments (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id     BIGINT UNSIGNED NOT NULL,
  author_id   BIGINT UNSIGNED NOT NULL,
  parent_id   BIGINT UNSIGNED NULL,
  body        VARCHAR(1000) NOT NULL,
  status      ENUM('published','hidden','removed') NOT NULL DEFAULT 'published',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_comments_post (post_id, id),
  CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_author FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES post_comments(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS post_reactions (
  post_id     BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  reaction    ENUM('like','love','haha','wow','sad','angry') NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  CONSTRAINT fk_pr_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS post_shares (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id     BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  channel     ENUM('internal','link','native') NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_shares_post (post_id),
  CONSTRAINT fk_shares_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_shares_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS post_saves (
  user_id     BIGINT UNSIGNED NOT NULL,
  post_id     BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, post_id),
  CONSTRAINT fk_saves_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_saves_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS follows (
  follower_id  BIGINT UNSIGNED NOT NULL,
  followee_id  BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (follower_id, followee_id),
  KEY idx_follows_followee (followee_id),
  CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_follows_followee FOREIGN KEY (followee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  type        VARCHAR(40) NOT NULL,
  title       VARCHAR(160) NOT NULL,
  body        VARCHAR(500) NULL,
  data        JSON NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user (user_id, read_at, id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(160) NOT NULL,
  body          TEXT NOT NULL,
  status        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  published_at  DATETIME NULL,
  created_by    BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_announce_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------ platform config --
CREATE TABLE IF NOT EXISTS feature_flags (
  module_key  VARCHAR(80) NOT NULL PRIMARY KEY,
  enabled     TINYINT(1) NOT NULL DEFAULT 1,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key    VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value  JSON NOT NULL,
  updated_by     BIGINT UNSIGNED NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key    CHAR(64) NOT NULL PRIMARY KEY,
  window_start  INT UNSIGNED NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ----------------------------------------------------------------- payments --
CREATE TABLE IF NOT EXISTS payment_gateways (
  code                      ENUM('paystack','flutterwave') NOT NULL PRIMARY KEY,
  is_enabled                TINYINT(1) NOT NULL DEFAULT 0,
  mode                      ENUM('test','live') NOT NULL DEFAULT 'test',
  public_key                VARCHAR(200) NULL,
  secret_key_encrypted      VARBINARY(1024) NULL,
  webhook_secret_encrypted  VARBINARY(1024) NULL,
  updated_by                BIGINT UNSIGNED NULL,
  updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_plans (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(40) NOT NULL,
  name            VARCHAR(100) NOT NULL,
  description     VARCHAR(500) NULL,
  amount_minor    INT UNSIGNED NOT NULL,
  currency        CHAR(3) NOT NULL DEFAULT 'NGN',
  billing_period  ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  entitlements    JSON NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS campaigns (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(120) NOT NULL,
  objective         VARCHAR(255) NULL,
  audience          JSON NULL,
  budget_minor      INT UNSIGNED NOT NULL DEFAULT 0,
  currency          CHAR(3) NOT NULL DEFAULT 'NGN',
  channels          JSON NULL,
  promo_code        VARCHAR(32) NULL,
  discount_percent  TINYINT UNSIGNED NULL,
  starts_at         DATETIME NULL,
  ends_at           DATETIME NULL,
  status            ENUM('draft','scheduled','active','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  created_by        BIGINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_campaign_promo (promo_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference            VARCHAR(64) NOT NULL,
  user_id              BIGINT UNSIGNED NOT NULL,
  plan_id              INT UNSIGNED NULL,
  campaign_id          INT UNSIGNED NULL,
  gateway              ENUM('paystack','flutterwave') NOT NULL,
  gateway_reference    VARCHAR(120) NULL,
  gateway_transaction_id VARCHAR(64) NULL,
  amount_minor         INT UNSIGNED NOT NULL,
  currency             CHAR(3) NOT NULL DEFAULT 'NGN',
  refunded_minor       INT UNSIGNED NOT NULL DEFAULT 0,
  status               ENUM('pending','successful','failed','abandoned','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  checkout_url         VARCHAR(500) NULL,
  verified_at          DATETIME NULL,
  metadata             JSON NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tx_reference (reference),
  UNIQUE KEY uq_tx_gateway_ref (gateway, gateway_reference),
  KEY idx_tx_user (user_id, created_at),
  KEY idx_tx_status (status, created_at),
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_tx_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
  CONSTRAINT fk_tx_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transaction_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id  BIGINT UNSIGNED NOT NULL,
  event_type      VARCHAR(60) NOT NULL,
  source          ENUM('system','webhook','verify','admin','gateway') NOT NULL,
  actor_id        BIGINT UNSIGNED NULL,
  status_before   VARCHAR(30) NULL,
  status_after    VARCHAR(30) NULL,
  payload         JSON NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tx_events (transaction_id, id),
  CONSTRAINT fk_txe_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webhook_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway         ENUM('paystack','flutterwave') NOT NULL,
  event_hash      CHAR(64) NOT NULL,
  event_type      VARCHAR(80) NULL,
  signature_valid TINYINT(1) NOT NULL DEFAULT 0,
  payload         JSON NULL,
  received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at    DATETIME NULL,
  UNIQUE KEY uq_webhook_event (gateway, event_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS refunds (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id           BIGINT UNSIGNED NOT NULL,
  amount_minor             INT UNSIGNED NOT NULL,
  reason                   VARCHAR(500) NOT NULL,
  status                   ENUM('requested','processed','rejected','failed') NOT NULL DEFAULT 'requested',
  requested_by            BIGINT UNSIGNED NOT NULL,
  decided_by               BIGINT UNSIGNED NULL,
  gateway_refund_reference VARCHAR(120) NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at               DATETIME NULL,
  KEY idx_refunds_tx (transaction_id),
  CONSTRAINT fk_refunds_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_refunds_req FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_refunds_dec FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  plan_id         INT UNSIGNED NOT NULL,
  transaction_id  BIGINT UNSIGNED NOT NULL UNIQUE,
  status          ENUM('active','expired','cancelled','refunded') NOT NULL DEFAULT 'active',
  starts_at       DATETIME NOT NULL,
  ends_at         DATETIME NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_subs_user (user_id, status, ends_at),
  CONSTRAINT fk_subs_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_subs_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
  CONSTRAINT fk_subs_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- ads --
CREATE TABLE IF NOT EXISTS advertisers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_name   VARCHAR(160) NOT NULL,
  contact_email  VARCHAR(190) NOT NULL,
  status         ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
  created_by     BIGINT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS advertisements (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  advertiser_id         INT UNSIGNED NOT NULL,
  campaign_id           INT UNSIGNED NULL,
  title                 VARCHAR(120) NOT NULL,
  body                  VARCHAR(500) NULL,
  media_type            ENUM('text','image','video') NOT NULL DEFAULT 'text',
  storage_key           CHAR(48) NULL,
  media_mime            VARCHAR(60) NULL,
  click_url             VARCHAR(500) NOT NULL,
  placement             ENUM('community_feed','discovery') NOT NULL DEFAULT 'community_feed',
  cpm_minor             INT UNSIGNED NOT NULL DEFAULT 0,
  cpc_minor             INT UNSIGNED NOT NULL DEFAULT 0,
  budget_minor          INT UNSIGNED NOT NULL DEFAULT 0,
  spent_minor           INT UNSIGNED NOT NULL DEFAULT 0,
  daily_frequency_cap   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  status                ENUM('draft','pending_review','approved','rejected','paused','suspended','completed') NOT NULL DEFAULT 'draft',
  review_note           VARCHAR(500) NULL,
  starts_at             DATETIME NULL,
  ends_at               DATETIME NULL,
  created_by            BIGINT UNSIGNED NOT NULL,
  reviewed_by           BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ads_storage (storage_key),
  KEY idx_ads_serving (status, placement, starts_at, ends_at),
  CONSTRAINT fk_ads_advertiser FOREIGN KEY (advertiser_id) REFERENCES advertisers(id),
  CONSTRAINT fk_ads_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
  CONSTRAINT fk_ads_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_ads_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ad_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  advertisement_id BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  event_type      ENUM('impression','click') NOT NULL,
  cost_minor      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ad_events_ad (advertisement_id, event_type, created_at),
  KEY idx_ad_events_user (user_id, advertisement_id, event_type, created_at),
  CONSTRAINT fk_ae_ad FOREIGN KEY (advertisement_id) REFERENCES advertisements(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- support --
CREATE TABLE IF NOT EXISTS support_tickets (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             BIGINT UNSIGNED NOT NULL,
  category            ENUM('general','account_recovery','appeal','safety','payments') NOT NULL DEFAULT 'general',
  subject             VARCHAR(160) NOT NULL,
  status              ENUM('open','awaiting_user','resolved','closed') NOT NULL DEFAULT 'open',
  priority            ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  assigned_to         BIGINT UNSIGNED NULL,
  appeal_target_type  VARCHAR(20) NULL,
  appeal_target_id    BIGINT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tickets_status (status, created_at),
  CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_tickets_assignee FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_ticket_messages (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id   BIGINT UNSIGNED NOT NULL,
  author_id   BIGINT UNSIGNED NOT NULL,
  is_staff    TINYINT(1) NOT NULL DEFAULT 0,
  body        TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket_msgs (ticket_id, id),
  CONSTRAINT fk_tmsg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tmsg_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------ audit trail --
CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id     BIGINT UNSIGNED NULL,
  actor_ip     VARCHAR(45) NULL,
  action       VARCHAR(80) NOT NULL,
  target_type  VARCHAR(40) NULL,
  target_id    VARCHAR(64) NULL,
  reason       VARCHAR(500) NULL,
  before_data  JSON NULL,
  after_data   JSON NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_actor (actor_id, created_at),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_target (target_type, target_id)
) ENGINE=InnoDB;

-- Append-only enforcement -----------------------------------------------------
DELIMITER //
CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
CREATE TRIGGER trg_tx_events_no_update BEFORE UPDATE ON transaction_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'transaction_events is append-only';
CREATE TRIGGER trg_tx_events_no_delete BEFORE DELETE ON transaction_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'transaction_events is append-only';
CREATE TRIGGER trg_vd_no_update BEFORE UPDATE ON verification_decisions FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'verification_decisions is append-only';
CREATE TRIGGER trg_vd_no_delete BEFORE DELETE ON verification_decisions FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'verification_decisions is append-only';
DELIMITER ;
