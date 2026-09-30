-- ba_notifications.type: add the 'concern_message' value — 2026-09-30
-- Idempotent: re-runnable, guarded by an INFORMATION_SCHEMA.COLUMNS check on
-- the column's own definition.
--
-- Why this file exists
-- --------------------
-- An admin sent a citizen a message about a submitted concern from the
-- "Message Citizen" dialog on admin/concern_view.php:
--
--   "Hello! Your concern has been resolved. Thank you for using our system."
--
-- and asked the citizen to check the Notifications tab. Nothing appeared. The
-- citizen had to open the concern manually to discover an admin had replied.
--
-- The cause was not a bug in the notification service -- it was that nothing
-- called it. admin/api/post_message.php performed exactly one statement:
--
--   INSERT INTO concern_messages (concern_id, sender, message)
--   VALUES (?, "department", ?)
--
-- and required only includes/admin_auth.php, so ba_push_notification() was not
-- even in scope.
--
-- The notification this migration unblocks is an ENUM value, not a column.
-- ba_notifications.type was declared as
--
--   ENUM('reminder','schedule_change','announcement','report_submit',
--        'report_update','feedback_reply','email_mock','sms_mock')
--
-- and none of those describe a department message. MySQL resolves an ENUM at
-- INSERT time, so without this change the notification INSERT fails outright.
--
-- MODIFY COLUMN here is additive: it only appends a permitted value, it never
-- renumbers or drops an existing one, so every stored row keeps the value it
-- already has. Still take a phpMyAdmin Export before running it.
--
-- Declared in the CREATE TABLE as well (database/basuraalert_migration.sql and
-- the mysqldump copy database/full_install_infinityfree.sql) so that this file's
-- guard is a no-op on a fresh install and only backfills an existing database.
--
-- Run manually if the migrations tracker is not in use:
--   php bin/migrate.php
--   -- or paste the statement below into phpMyAdmin. InfinityFree's free tier
--   -- has no SSH or PHP CLI (docs/DEPLOY_INFINITYFREE.md:24), so the hosted
--   -- database is normally updated through phpMyAdmin by hand.

SET @sql = (SELECT IF(COLUMN_TYPE NOT LIKE '%concern_message%',
  'ALTER TABLE ba_notifications MODIFY COLUMN `type` ENUM(''reminder'',''schedule_change'',''announcement'',''report_submit'',''report_update'',''feedback_reply'',''concern_message'',''email_mock'',''sms_mock'') NOT NULL',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notifications' AND COLUMN_NAME = 'type');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
