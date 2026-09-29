-- users.active predates this file (schema.sql already defines it); guard keeps
-- fresh installs and re-runs safe per the bin/migrate.php idempotency rule.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'active');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Profile avatar (Cloudinary). Added 2026-09-27.
-- -----------------------------------------------------------------------------
-- See the note in database/admin_migration.sql. Same rationale: nullable,
-- public_id is the source of truth, deterministic id so a re-upload overwrites.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN avatar_public_id VARCHAR(190) NULL',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_public_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL AFTER avatar_public_id',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_url');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;