-- SeeToSee Communications Control Center — additive migration
-- Apply only after backing up the existing database. This migration does not
-- alter Stripe, PreFix, NDA, WebBook, Portal, booth, or WebRTC tables.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS communications_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL,
  kind VARCHAR(32) NOT NULL,
  title VARCHAR(200) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  body_text MEDIUMTEXT NOT NULL,
  sender_email VARCHAR(254) NOT NULL,
  audience_label VARCHAR(128) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  created_by BIGINT UNSIGNED NULL,
  sent_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  confirmed_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  pending_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_communications_campaign_public (public_id),
  KEY ix_communications_campaign_kind_created (kind, created_at),
  KEY ix_communications_campaign_status (status, created_at),
  CONSTRAINT fk_communications_campaign_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communications_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  recipient_email VARCHAR(254) NOT NULL,
  recipient_name VARCHAR(120) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  error_message VARCHAR(1000) NULL,
  sent_at DATETIME(6) NULL,
  confirmed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY ix_communications_delivery_campaign_status (campaign_id, status),
  KEY ix_communications_delivery_user (user_id, created_at),
  CONSTRAINT fk_communications_delivery_campaign FOREIGN KEY (campaign_id) REFERENCES communications_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_communications_delivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communications_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  delivery_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  used_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_communications_receipt_delivery (delivery_id),
  UNIQUE KEY uq_communications_receipt_token (token_hash),
  KEY ix_communications_receipt_expiry (expires_at, used_at),
  CONSTRAINT fk_communications_receipt_delivery FOREIGN KEY (delivery_id) REFERENCES communications_deliveries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communications_schedule (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  day_of_week TINYINT UNSIGNED NOT NULL DEFAULT 1,
  send_hour_utc TINYINT UNSIGNED NOT NULL DEFAULT 14,
  last_run_at DATETIME(6) NULL,
  next_run_at DATETIME(6) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_communications_schedule_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO communications_schedule (id, enabled, day_of_week, send_hour_utc)
VALUES (1, 0, 1, 14)
ON DUPLICATE KEY UPDATE id = VALUES(id);

INSERT IGNORE INTO schema_migrations (version) VALUES ('V27_002_communications_control_center');

SET FOREIGN_KEY_CHECKS = 1;
