-- InfinityFree baseline (2026-09-28).
-- Ensures every table/column the PHP code reads actually exists on a FRESH install.
-- Context: several objects were added ad-hoc over time (legacy web runner
-- _run_basuraalert_migration.php inline SQL, QA harnesses, manual phpMyAdmin
-- tweaks) and never got a versioned migration, so bin/migrate.php alone could
-- not reproduce the working DB. This file closes that gap. Every statement is
-- idempotent (IF NOT EXISTS / INFORMATION_SCHEMA guards), so applying it to
-- the existing dev DB is a no-op.
-- New objects MUST continue to be appended as new files, never inserted mid-list.

-- -------------------------------------------------------------
-- 1) Tables previously created only by _run_basuraalert_migration.php
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_report_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT NOT NULL COMMENT '1-5 helpfulness star rating',
    comment VARCHAR(1000) NULL DEFAULT NULL,
    rated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rating_report (report_id),
    KEY idx_rating_user (user_id),
    UNIQUE KEY uk_rating_report_user (report_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Resident helpfulness rating on closed/rejected reports';

CREATE TABLE IF NOT EXISTS ba_dropoff_points (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT UNSIGNED NOT NULL,
    spot_name VARCHAR(255) NOT NULL COMMENT 'Friendly name shown to residents e.g. End of J.P. Rizal St. curb',
    address VARCHAR(500) NOT NULL COMMENT 'Full street address / zone for this drop-off',
    latitude DECIMAL(10,7) NOT NULL COMMENT 'WGS84 latitude (OSM Nominatim pin)',
    longitude DECIMAL(10,7) NOT NULL COMMENT 'WGS84 longitude (OSM Nominatim pin)',
    place_osm_id VARCHAR(64) NULL DEFAULT NULL COMMENT 'Optional OSM place id for Nominatim policy audit trail',
    pickup_type ENUM('STREET_END_CURBSIDE','BARANGAY_MRF','SHARED_BIN_CLUSTER','BULKY_DROP_OFF_YARD','HAZARDOUS_SATELLITE','OTHER') NOT NULL DEFAULT 'STREET_END_CURBSIDE',
    open_24_7 TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = schedule-only, 1 = always accessible curb',
    operation_hours VARCHAR(255) NULL DEFAULT NULL COMMENT 'Free-text operation hours if not 24/7',
    notes_public VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Resident-facing note shown on map popup',
    reference_photo VARCHAR(500) NULL DEFAULT NULL COMMENT 'Cloudinary URL (Phase2) or local path (Phase1 mock)',
    status ENUM('DRAFT','PUBLISHED','TEMPORARILY_CLOSED') NOT NULL DEFAULT 'PUBLISHED',
    accepts_bio TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Accepts Biodegradable waste?',
    accepts_nonbio TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Accepts Non-biodegradable waste?',
    accepts_recyclable TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Accepts Recyclable waste?',
    accepts_hazard TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Accepts Hazardous/Special waste?',
    accepts_bulky TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Accepts Bulky/Uncollected yard waste?',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
    published_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
    published_at DATETIME NULL DEFAULT NULL,
    INDEX idx_dropoff_barangay (barangay_id),
    INDEX idx_dropoff_status (status),
    INDEX idx_dropoff_lat_lon (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Official Marikina waste drop-off points shown on the resident map view';

-- Includes the tier3 linkage columns (linked_type/linked_id/idx) so the
-- 20260906_tier3_linkage guards are no-ops on fresh installs.
CREATE TABLE IF NOT EXISTS ba_dropoff_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dropoff_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT(1) NOT NULL COMMENT '0 Sunday .. 6 Saturday',
    waste_type ENUM('Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special','Bulky') NOT NULL,
    linked_type ENUM('none','collection') NOT NULL DEFAULT 'none',
    linked_id INT UNSIGNED NULL DEFAULT NULL,
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    effective_from DATE NULL DEFAULT NULL,
    effective_to DATE NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dropoff_sched_dropoff (dropoff_id),
    INDEX idx_dropoff_linked (linked_type, linked_id),
    CONSTRAINT fk_ba_sched_dropoff FOREIGN KEY (dropoff_id) REFERENCES ba_dropoff_points(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Special per-drop-off, per-day collection windows (overrides barangay default)';

CREATE TABLE IF NOT EXISTS user_password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'FK to users.id',
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the raw 64-hex token emailed to user',
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL DEFAULT NULL COMMENT 'Set to NOW() once reset successfully applied; NULL = usable',
    requested_ip VARCHAR(45) NULL DEFAULT NULL COMMENT 'Client IPv4/IPv6 the reset was requested from (audit)',
    consumed_ip VARCHAR(45) NULL DEFAULT NULL COMMENT 'Client IP that consumed the token (audit)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pwreset_token_hash (token_hash),
    INDEX idx_pwreset_user (user_id),
    INDEX idx_pwreset_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Self-serve password-reset challenge tokens sent via email link';

-- -------------------------------------------------------------
-- 2) Brute-force audit + admin inbox (no versioned migration existed)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_failures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    realm ENUM('citizen','admin') NOT NULL,
    identifier VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_failures_realm_ident (realm, identifier, failed_at),
    KEY idx_login_failures_realm_ip (realm, ip_address, failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NULL DEFAULT NULL,
    concern_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(30) NOT NULL,
    body VARCHAR(255) NOT NULL,
    seen TINYINT(1) NOT NULL DEFAULT 0,
    created_by_admin INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_notif_admin (admin_id, seen, created_at),
    KEY idx_admin_notif_concern (concern_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- 3) Ad-hoc user/admin columns (OTP toggle, citizen notes, theme)
-- -------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN otp_enabled TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'otp_enabled');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN moderator_notes VARCHAR(500) NULL DEFAULT NULL',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'moderator_notes');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN ui_theme VARCHAR(20) NOT NULL DEFAULT ''light''',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ui_theme');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE admins ADD COLUMN otp_enabled TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'otp_enabled');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- -------------------------------------------------------------
-- 4) Message/timeline read-receipt + event columns (code reads them,
--    but no versioned migration ever created them; previously added
--    ad-hoc via QA harnesses / manual tweaks).
-- -------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_messages ADD COLUMN user_id INT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_messages' AND COLUMN_NAME = 'user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_messages ADD COLUMN seen_by_admin TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_messages' AND COLUMN_NAME = 'seen_by_admin');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_messages ADD COLUMN seen_by_user TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_messages' AND COLUMN_NAME = 'seen_by_user');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_timeline ADD COLUMN user_id INT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_timeline' AND COLUMN_NAME = 'user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_timeline ADD COLUMN admin_id INT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_timeline' AND COLUMN_NAME = 'admin_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_timeline ADD COLUMN event_type VARCHAR(30) NOT NULL DEFAULT ''status_change''',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_timeline' AND COLUMN_NAME = 'event_type');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_timeline ADD COLUMN old_status VARCHAR(30) NULL DEFAULT NULL',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_timeline' AND COLUMN_NAME = 'old_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_timeline ADD COLUMN new_status VARCHAR(30) NULL DEFAULT NULL',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_timeline' AND COLUMN_NAME = 'new_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE concern_messages ADD INDEX idx_msg_unread_admin (seen_by_admin, concern_id, created_at)',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_messages' AND INDEX_NAME = 'idx_msg_unread_admin');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Notification preference overrides (legacy runner added these inline;
-- the versioned basuraalert_migration.sql never did).
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_reminder_in_app TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_reminder_in_app');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_reminder_email TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_reminder_email');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_reminder_sms TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_reminder_sms');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_schedule_change_in_app TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_schedule_change_in_app');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_schedule_change_email TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_schedule_change_email');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_schedule_change_sms TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_schedule_change_sms');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_announcement_in_app TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_announcement_in_app');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_announcement_email TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_announcement_email');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_announcement_sms TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_announcement_sms');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_report_submit_in_app TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_report_submit_in_app');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_report_submit_email TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_report_submit_email');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_report_submit_sms TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_report_submit_sms');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_report_update_in_app TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_report_update_in_app');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_report_update_email TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_report_update_email');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_report_update_sms TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_report_update_sms');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_feedback_reply_in_app TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_feedback_reply_in_app');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_feedback_reply_email TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_feedback_reply_email');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notification_preferences ADD COLUMN ev_feedback_reply_sms TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = 'ev_feedback_reply_sms');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
