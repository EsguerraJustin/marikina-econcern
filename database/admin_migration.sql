SET @has_departments := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'departments'
);
SET @col_departments_active := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'departments'
    AND COLUMN_NAME = 'active'
);
SET @sql_departments_active := IF(
  @has_departments = 1 AND @col_departments_active = 0,
  'ALTER TABLE departments ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1',
  IF(
    @has_departments = 1,
    'ALTER TABLE departments MODIFY COLUMN active TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql_departments_active;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_concern_types := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'concern_types'
);
SET @col_concern_types_active := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'concern_types'
    AND COLUMN_NAME = 'active'
);
SET @sql_concern_types_active := IF(
  @has_concern_types = 1 AND @col_concern_types_active = 0,
  'ALTER TABLE concern_types ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1',
  IF(
    @has_concern_types = 1,
    'ALTER TABLE concern_types MODIFY COLUMN active TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql_concern_types_active;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','department_admin') NOT NULL,
  department_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admins_email (email),
  KEY idx_admins_role (role),
  KEY idx_admins_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @fk_admins_department := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'admins'
    AND CONSTRAINT_NAME = 'fk_admins_department'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_admins_department := IF(
  @has_departments = 1 AND @fk_admins_department = 0,
  'ALTER TABLE admins ADD CONSTRAINT fk_admins_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql_fk_admins_department;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_concerns := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'concerns'
);
SET @col_concerns_assigned_admin := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'concerns'
    AND COLUMN_NAME = 'assigned_admin_id'
);
SET @sql_concerns_assigned_admin := IF(
  @has_concerns = 1 AND @col_concerns_assigned_admin = 0,
  'ALTER TABLE concerns ADD COLUMN assigned_admin_id INT UNSIGNED NULL',
  IF(
    @has_concerns = 1,
    'ALTER TABLE concerns MODIFY COLUMN assigned_admin_id INT UNSIGNED NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql_concerns_assigned_admin;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_concerns_assigned_admin := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'concerns'
    AND INDEX_NAME = 'idx_concerns_assigned_admin'
);
SET @sql_idx_concerns_assigned_admin := IF(
  @has_concerns = 1 AND @idx_concerns_assigned_admin = 0,
  'ALTER TABLE concerns ADD KEY idx_concerns_assigned_admin (assigned_admin_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql_idx_concerns_assigned_admin;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_concerns_assigned_admin := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'concerns'
    AND CONSTRAINT_NAME = 'fk_concerns_assigned_admin'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_concerns_assigned_admin := IF(
  @has_concerns = 1 AND @fk_concerns_assigned_admin = 0,
  'ALTER TABLE concerns ADD CONSTRAINT fk_concerns_assigned_admin FOREIGN KEY (assigned_admin_id) REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql_fk_concerns_assigned_admin;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS concern_notes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  concern_id INT UNSIGNED NOT NULL,
  admin_id INT UNSIGNED NULL,
  note TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notes_concern (concern_id),
  KEY idx_notes_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @fk_notes_concern := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'concern_notes'
    AND CONSTRAINT_NAME = 'fk_notes_concern'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_notes_concern := IF(
  @has_concerns = 1 AND @fk_notes_concern = 0,
  'ALTER TABLE concern_notes ADD CONSTRAINT fk_notes_concern FOREIGN KEY (concern_id) REFERENCES concerns (id) ON DELETE CASCADE ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql_fk_notes_concern;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_notes_admin := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'concern_notes'
    AND CONSTRAINT_NAME = 'fk_notes_admin'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_notes_admin := IF(
  @fk_notes_admin = 0,
  'ALTER TABLE concern_notes ADD CONSTRAINT fk_notes_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql_fk_notes_admin;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Profile avatar (Cloudinary). Added 2026-09-27.
-- -----------------------------------------------------------------------------
-- Nullable, so every existing row is unaffected and no backfill is needed.
-- avatar_public_id is the Cloudinary asset id ("<folder>/<name>") and is the
-- source of truth: mc_avatar_url() derives every display variant from it via
-- Cloudinary URL transformation, so the crop/size can be re-tuned later without
-- re-uploading. avatar_url caches the base secure_url for the admin/citizen
-- lists. Both are written together by includes/Avatar.php.
-- Deterministic public_ids (admin_<id>, citizen_<id>) mean a re-upload
-- OVERWRITES the previous asset instead of orphaning it in the account, which
-- is what the previous randomised public_id in ba_cloudinary_upload_file() would
-- have done on every avatar change.
--
-- They are deliberately BARE, with no folder prefix. ba_cloudinary_upload_file()
-- prepends its own marikina_concern/uploads folder to whatever public_id it is
-- given, so passing an already-prefixed id produces a doubled path
-- (marikina_concern/uploads/marikina_concern/uploads/...). Passing folder => ''
-- to suppress that is not an option either: ba_cloudinary_sign() omits empty
-- values from the string-to-sign while Cloudinary still includes `folder=`, so
-- every upload fails with HTTP 401 "Invalid Signature" (both verified against the
-- live API). The stored value therefore contains the folder that Cloudinary
-- actually assigned, and mc_avatar_url() works from that.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE admins ADD COLUMN avatar_public_id VARCHAR(190) NULL AFTER ui_theme',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'avatar_public_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE admins ADD COLUMN avatar_url VARCHAR(500) NULL AFTER avatar_public_id',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'avatar_url');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Privileged-action audit trail. Added 2026-09-27.
-- -----------------------------------------------------------------------------
-- This table already existed in the live schema but was in NO migration file and
-- was never written to by any code, so privileged actions (a super admin editing
-- a citizen's name/email/mobile/barangay via admin/api/citizen.php action=update)
-- left no trace. mc_admin_activity_log() in includes/helpers.php is now its only
-- writer.
--
-- old_value and new_value are JSON objects of field => value, holding the
-- BEFORE and AFTER state respectively, so a reviewer can diff them directly.
-- A "__note" key in new_value records a decision that is not a field change,
-- such as an administrator settling an email verification out of band.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS, so re-running is a no-op.
CREATE TABLE IF NOT EXISTS `admin_activity_log` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT(10) UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(60) NOT NULL,
  `target_type` VARCHAR(30) DEFAULT NULL,
  `target_id`   BIGINT(20) UNSIGNED DEFAULT NULL,
  `old_value`   TEXT DEFAULT NULL,
  `new_value`   TEXT DEFAULT NULL,
  `ip_address`  VARCHAR(45) NOT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_activity_admin` (`admin_id`, `created_at`),
  KEY `idx_admin_activity_target` (`target_type`, `target_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;