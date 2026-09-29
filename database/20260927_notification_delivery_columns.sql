-- ba_notifications delivery-tracking columns — 2026-09-27
-- Idempotent: re-runnable, guarded by INFORMATION_SCHEMA.COLUMNS existence checks.
--
-- Why this file exists
-- --------------------
-- ba_notifications is written by two independent dispatchers that were never
-- reconciled:
--
--   1. ba_flush_queued_notifications()  (includes/BasuraAlert/Notifications.php,
--      called from api/basuraalert_actions.php's register_shutdown_function)
--      -> writes sent_at on success.
--
--   2. ba_dispatch_queued_notifications.php  (cron/maintenance entry point)
--      -> SELECTs retry_count and next_attempt_at, writes both plus sent_at.
--
-- None of those three columns appear in the original CREATE TABLE in
-- database/basuraalert_migration.sql. They only ever existed because
-- ba_dispatch_queued_notifications.php carries its own self-healing
-- `if (!isset($cols['retry_count'])) ALTER TABLE ...` block, which runs only when
-- that one script is invoked. A database built by the documented path
-- (`php bin/migrate.php`) never had them.
--
-- The failure this caused was silent and severe. ba_flush_queued_notifications()
-- referenced a fourth, entirely non-existent column name (delivered_at), so
-- mysqli::prepare() threw mysqli_sql_exception "Unknown column 'delivered_at' in
-- 'field list'". MySQL resolves columns at PREPARE time, so the throw happened
-- before a single SMS or email was attempted. Because the flush runs inside
-- register_shutdown_function -- after json_response() had already echoed the
-- body and exit()ed -- the fatal text was appended to the already-sent JSON,
-- making the client's JSON.parse() fail. A resident whose report saved
-- successfully was shown "Network or server error. Please try again."
--
-- Fixing the code to use sent_at is not enough on its own: on a freshly migrated
-- database sent_at would be missing too, and the same fatal would return. So the
-- columns are declared in the CREATE TABLE as well (see
-- database/basuraalert_migration.sql section 6), which makes this file's guards
-- no-ops on a fresh install and lets them backfill an existing one.
--
-- Run manually if the migrations tracker is not in use:
--   php bin/migrate.php
--   -- or paste the four statements below into phpMyAdmin.

-- 1) ba_notifications: retry_count
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notifications ADD COLUMN retry_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Dispatch attempts made so far'' AFTER delivery_note',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notifications' AND COLUMN_NAME = 'retry_count');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) ba_notifications: next_attempt_at
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notifications ADD COLUMN next_attempt_at TIMESTAMP NULL DEFAULT NULL COMMENT ''Backoff gate, NULL means eligible to dispatch now'' AFTER retry_count',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notifications' AND COLUMN_NAME = 'next_attempt_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) ba_notifications: sent_at
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_notifications ADD COLUMN sent_at TIMESTAMP NULL DEFAULT NULL COMMENT ''Set the moment delivery_status becomes sent'' AFTER next_attempt_at',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notifications' AND COLUMN_NAME = 'sent_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Dispatcher hot path: WHERE delivery_status = ? AND channel IN (?,?)
--    ORDER BY id ASC. Left off the queue table's own index set before this fix
--    existed; harmless to add, and keeps a fresh install and a backfilled one
--    on the same footing.
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notifications' AND INDEX_NAME = 'idx_notif_dispatch');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE ba_notifications ADD INDEX idx_notif_dispatch (delivery_status, channel, id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
