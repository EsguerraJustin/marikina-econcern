-- Tier 3 Dropoff ↔ Schedule Full Linkage — 2026-09-06
-- Idempotent: safely re-runnable, column/index existence checks via IGNORE on 1060/1061/1091 errors.

-- -------------------------------------------------------------
-- 1) ba_collection_schedules: pointer columns + indexes
-- -------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE ba_collection_schedules ADD COLUMN linked_type ENUM(''none'',''dropoff'') NOT NULL DEFAULT ''none'' AFTER schedule_type',
    'SELECT 1')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_collection_schedules' AND COLUMN_NAME = 'linked_type');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE ba_collection_schedules ADD COLUMN linked_id INT UNSIGNED NULL DEFAULT NULL AFTER linked_type',
    'SELECT 1')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_collection_schedules' AND COLUMN_NAME = 'linked_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE ba_collection_schedules ADD COLUMN linked_group_uid VARCHAR(32) NULL DEFAULT NULL AFTER linked_id',
    'SELECT 1')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_collection_schedules' AND COLUMN_NAME = 'linked_group_uid');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_collection_schedules' AND INDEX_NAME = 'idx_coll_linked');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE ba_collection_schedules ADD INDEX idx_coll_linked (linked_type, linked_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_collection_schedules' AND INDEX_NAME = 'uniq_coll_linked_waste');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE ba_collection_schedules ADD UNIQUE KEY uniq_coll_linked_waste (linked_type, linked_id, waste_type)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 2) ba_dropoff_schedules: pointer columns + indexes
-- NOTE 2026-09-28: the table itself is created by 20260928_infinityfree_baseline
-- (appended later in bin/migrate.php order), so on fresh installs it may not
-- exist yet here. @tbl_exists makes each guard a no-op in that case instead of
-- a fatal 1146 that would halt the whole migration run.
-- -------------------------------------------------------------
SET @tbl_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_dropoff_schedules');
SET @sql = (SELECT IF(@tbl_exists = 0 OR COUNT(*) > 0,
    'SELECT 1',
    'ALTER TABLE ba_dropoff_schedules ADD COLUMN linked_type ENUM(''none'',''collection'') NOT NULL DEFAULT ''none'' AFTER waste_type')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_dropoff_schedules' AND COLUMN_NAME = 'linked_type');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(@tbl_exists = 0 OR COUNT(*) > 0,
    'SELECT 1',
    'ALTER TABLE ba_dropoff_schedules ADD COLUMN linked_id INT UNSIGNED NULL DEFAULT NULL AFTER linked_type')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_dropoff_schedules' AND COLUMN_NAME = 'linked_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_dropoff_schedules' AND INDEX_NAME = 'idx_dropoff_linked');
SET @sql = IF(@tbl_exists = 0 OR @idx_exists > 0,
    'SELECT 1',
    'ALTER TABLE ba_dropoff_schedules ADD INDEX idx_dropoff_linked (linked_type, linked_id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
