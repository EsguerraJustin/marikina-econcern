-- Auth Security Migration
-- Adds email verification and login OTP tables
-- Run this file ONCE after ensuring schema.sql has been applied

ALTER TABLE users
  ADD COLUMN email_verified_at TIMESTAMP NULL DEFAULT NULL AFTER active,
  ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER email_verified_at,
  ADD COLUMN failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at,
  ADD COLUMN locked_until TIMESTAMP NULL DEFAULT NULL AFTER failed_login_attempts,
  ADD KEY idx_users_email_verified (email_verified_at),
  ADD KEY idx_users_locked_until (locked_until);

CREATE TABLE IF NOT EXISTS user_email_verifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the raw token sent in the email link',
  expires_at TIMESTAMP NOT NULL,
  consumed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ev_token_hash (token_hash),
  KEY idx_ev_user_id (user_id),
  KEY idx_ev_expires (expires_at),
  KEY idx_ev_consumed (consumed_at),
  CONSTRAINT fk_ev_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_login_otps (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  otp_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the 6-digit OTP code',
  channel ENUM('sms') NOT NULL DEFAULT 'sms',
  destination_masked VARCHAR(30) NOT NULL COMMENT 'Masked mobile for audit (e.g. +63917******9101)',
  expires_at TIMESTAMP NOT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  consumed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otp_user (user_id),
  KEY idx_otp_expires (expires_at),
  KEY idx_otp_consumed (consumed_at),
  CONSTRAINT fk_otp_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_login_otps (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  otp_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the 6-digit OTP code',
  channel ENUM('sms') NOT NULL DEFAULT 'sms',
  destination_masked VARCHAR(30) NOT NULL COMMENT 'Masked mobile for audit (e.g. +63917******9101)',
  expires_at TIMESTAMP NOT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  consumed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aotp_admin (admin_id),
  KEY idx_aotp_expires (expires_at),
  KEY idx_aotp_consumed (consumed_at),
  CONSTRAINT fk_aotp_admin
    FOREIGN KEY (admin_id) REFERENCES admins (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Admin login 2FA SMS OTP challenges';
