-- BasuraAlert Phase 1 Migration
-- Adds barangay column to users + all BasuraAlert tables + mock seed data
-- Run AFTER schema.sql and auth_security_migration.sql

-- -----------------------------------------------------------------------------
-- 1. Add barangay column to users so BasuraAlert can filter per resident
-- -----------------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN barangay VARCHAR(120) NULL DEFAULT NULL COMMENT 'Resident barangay for BasuraAlert schedule targeting' AFTER mobile,
  ADD KEY idx_users_barangay (barangay);

-- -----------------------------------------------------------------------------
-- 2. Barangay reference table (16 Marikina City barangays + others if needed)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS barangays (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  city VARCHAR(120) NOT NULL DEFAULT 'Marikina City',
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_barangays_name (name),
  KEY idx_barangays_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed 16 Marikina barangays
INSERT INTO barangays (name) VALUES
('Barangka'),
('Calumpang'),
('Concepcion Uno'),
('Concepcion Dos'),
('Fortune'),
('Industrial Valley Complex'),
('Jesus de la Peña'),
('Malanday'),
('Marikina Heights'),
('Nangka'),
('Parang'),
('San Roque'),
('Santa Elena'),
('Santo Niño'),
('Tañong'),
('Tumana')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- -----------------------------------------------------------------------------
-- 3. Collection schedules (regular / recurring / one-time / holiday exceptions)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_collection_schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  barangay_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL COMMENT 'e.g. Monday Biodegradable',
  waste_type ENUM('Biodegradable','Non-Biodegradable','Recyclable','Special','Mixed') NOT NULL DEFAULT 'Mixed',
  schedule_type ENUM('regular','recurring','one_time','exception') NOT NULL DEFAULT 'regular',
  day_of_week TINYINT UNSIGNED NULL COMMENT '1=Sun..7=Sat for regular schedules',
  collection_date DATE NULL COMMENT 'Exact date for one_time/exception entries',
  time_start TIME NULL,
  time_end TIME NULL,
  effective_from DATE NULL,
  effective_to DATE NULL,
  status ENUM('Draft','Published','Archived') NOT NULL DEFAULT 'Draft',
  notes VARCHAR(255) NULL,
  created_by_admin_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sched_barangay (barangay_id),
  KEY idx_sched_status (status),
  KEY idx_sched_type (schedule_type),
  KEY idx_sched_waste (waste_type),
  KEY idx_sched_date (collection_date),
  KEY idx_sched_dow (day_of_week),
  CONSTRAINT fk_ba_sched_barangay
    FOREIGN KEY (barangay_id) REFERENCES barangays (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ba_sched_admin
    FOREIGN KEY (created_by_admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 4. Waste segregation guide
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_waste_guide (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_name VARCHAR(190) NOT NULL,
  category ENUM('Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special') NOT NULL,
  is_accepted TINYINT(1) NOT NULL DEFAULT 1,
  prep_guidance VARCHAR(255) NULL COMMENT 'How to prepare before disposal',
  disposal_guidance VARCHAR(255) NULL COMMENT 'How to dispose',
  status ENUM('Published','Archived') NOT NULL DEFAULT 'Published',
  created_by_admin_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_wg_item (item_name),
  KEY idx_wg_category (category),
  KEY idx_wg_status (status),
  CONSTRAINT fk_ba_wg_admin
    FOREIGN KEY (created_by_admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 5. Announcements / service disruptions / holiday notices
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_announcements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  kind ENUM('Holiday','Disruption','Delay','Cancellation','Resumption','Schedule_Change','General') NOT NULL DEFAULT 'General',
  scope ENUM('all','barangay') NOT NULL DEFAULT 'all',
  target_barangay_id INT UNSIGNED NULL,
  content TEXT NOT NULL,
  effective_date DATE NULL,
  revised_schedule_info VARCHAR(255) NULL COMMENT 'If kind=Schedule_Change, describe new schedule briefly',
  status ENUM('Draft','Published','Archived') NOT NULL DEFAULT 'Draft',
  published_at TIMESTAMP NULL,
  created_by_admin_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ann_kind (kind),
  KEY idx_ann_scope (scope),
  KEY idx_ann_status (status),
  KEY idx_ann_effective (effective_date),
  KEY idx_ann_target_barangay (target_barangay_id),
  CONSTRAINT fk_ba_ann_barangay
    FOREIGN KEY (target_barangay_id) REFERENCES barangays (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ba_ann_admin
    FOREIGN KEY (created_by_admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 6. Notifications (in-app reminders / schedule-change / announcement refs)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL COMMENT 'Resident recipient',
  type ENUM('reminder','schedule_change','announcement','report_submit','report_update','feedback_reply','concern_message','email_mock','sms_mock') NOT NULL,
  title VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  ref_table VARCHAR(60) NULL COMMENT 'Related table e.g. ba_collection_schedules',
  ref_id INT UNSIGNED NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at TIMESTAMP NULL,
  channel ENUM('in_app','email','sms') NOT NULL DEFAULT 'in_app',
  delivery_status ENUM('queued','sent','failed','mock_sent') NOT NULL DEFAULT 'mock_sent',
  delivery_note VARCHAR(255) NULL,
  -- Added 2026-09-27. ba_dispatch_queued_notifications.php (retry/backoff) and
  -- ba_flush_queued_notifications() (sent_at) both read these, but the columns
  -- were only ever created by that script's ad-hoc self-healing ALTERs, so a
  -- database built by the documented `php bin/migrate.php` path lacked them.
  -- Fixed here at the source; database/20260927_notification_delivery_columns.sql
  -- backfills existing databases.
  retry_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dispatch attempts made so far',
  next_attempt_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Backoff gate, NULL means eligible to dispatch now',
  sent_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Set the moment delivery_status becomes sent',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id),
  KEY idx_notif_read (is_read),
  KEY idx_notif_type (type),
  KEY idx_notif_created (created_at),
  KEY idx_notif_ref (ref_table, ref_id),
  CONSTRAINT fk_ba_notif_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 7. Resident reports (issues: missed collection / wrong schedule / improper disposal / unclear ann / notif issue)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_number VARCHAR(20) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'Other_Waste_Concern' COMMENT 'Resident-chosen report category (raw key; label via PHP ba_report_category_options)',
  barangay_id INT UNSIGNED NOT NULL,
  date_of_concern DATE NOT NULL,
  street VARCHAR(190) NULL,
  landmark VARCHAR(190) NULL,
  description TEXT NOT NULL,
  photos_json JSON NULL,
  status ENUM('New','Acknowledged','Ongoing','Completed','Cancelled') NOT NULL DEFAULT 'New',
  assigned_admin_id INT UNSIGNED NULL,
  resolution_note TEXT NULL,
  rating TINYINT UNSIGNED NULL COMMENT 'Optional 1-5 helpfulness rating after resolution',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ba_reports_num (report_number),
  KEY idx_ba_reports_user (user_id),
  KEY idx_ba_reports_status (status),
  KEY idx_ba_reports_category (category),
  KEY idx_ba_reports_barangay (barangay_id),
  KEY idx_ba_reports_assigned (assigned_admin_id),
  CONSTRAINT fk_ba_reports_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ba_reports_barangay
    FOREIGN KEY (barangay_id) REFERENCES barangays (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ba_reports_admin
    FOREIGN KEY (assigned_admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 8. Report status timeline / audit
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_report_timeline (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id INT UNSIGNED NOT NULL,
  status ENUM('New','Acknowledged','Ongoing','Completed','Cancelled') NOT NULL,
  note VARCHAR(255) NULL,
  admin_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ba_rt_report (report_id),
  KEY idx_ba_rt_admin (admin_id),
  CONSTRAINT fk_ba_rt_report
    FOREIGN KEY (report_id) REFERENCES ba_reports (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ba_rt_admin
    FOREIGN KEY (admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 9. Feedback + Contact Us messages (general, not tied to a report)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_feedback (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL COMMENT 'Null if anonymous (but for this project usually logged-in)',
  kind ENUM('Feedback','FAQ_Suggestion','Contact_Us') NOT NULL,
  subject VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  photos_json JSON NULL COMMENT 'Optional uploaded image evidence (relative upload paths, JSON array)',
  reply TEXT NULL,
  replied_by_admin_id INT UNSIGNED NULL,
  replied_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ba_fb_kind (kind),
  KEY idx_ba_fb_user (user_id),
  CONSTRAINT fk_ba_fb_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ba_fb_admin
    FOREIGN KEY (replied_by_admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 10. FAQ entries
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_faqs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question VARCHAR(255) NOT NULL,
  answer TEXT NOT NULL,
  category VARCHAR(60) NULL COMMENT 'Schedules / Segregation / Notifications / Reports / Other',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('Published','Archived') NOT NULL DEFAULT 'Published',
  created_by_admin_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ba_faq_status (status),
  KEY idx_ba_faq_sort (sort_order),
  CONSTRAINT fk_ba_faq_admin
    FOREIGN KEY (created_by_admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 11. Resident notification preferences (simple opt-in + timing)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ba_notification_preferences (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  enable_email_reminders TINYINT(1) NOT NULL DEFAULT 1,
  enable_sms_reminders TINYINT(1) NOT NULL DEFAULT 1,
  enable_in_app_reminders TINYINT(1) NOT NULL DEFAULT 1,
  reminder_hours_before TINYINT UNSIGNED NOT NULL DEFAULT 12 COMMENT 'How many hours before collection to send reminder',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ba_pref_user (user_id),
  CONSTRAINT fk_ba_pref_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- MOCK SEED DATA (Phase 1 — will be replaced by real admin entries + Phase 2 API)
-- =============================================================================
-- Fresh installs have no admins yet, and every seed below carries an admin FK
-- (created_by_admin_id, nullable). Resolve once: real admin id when one exists,
-- NULL on a fresh DB (FK allows it) instead of a hardcoded 1 that would fail.
SET @seed_admin_id = (SELECT id FROM admins ORDER BY id LIMIT 1);

-- --- MOCK: Collection schedules (per barangay — pick subset to seed realistic data) ---
INSERT INTO ba_collection_schedules
  (barangay_id, title, waste_type, schedule_type, day_of_week, time_start, time_end, effective_from, status, notes, created_by_admin_id)
SELECT b.id, 'Monday Biodegradable', 'Biodegradable', 'regular', 2, '06:00:00', '10:00:00', '2026-01-01', 'Published', 'Regular weekly schedule', @seed_admin_id
FROM barangays b WHERE b.name IN ('Barangka','Calumpang','Concepcion Uno','Concepcion Dos','Fortune')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO ba_collection_schedules
  (barangay_id, title, waste_type, schedule_type, day_of_week, time_start, time_end, effective_from, status, notes, created_by_admin_id)
SELECT b.id, 'Wednesday Non-Biodegradable', 'Non-Biodegradable', 'regular', 4, '06:00:00', '10:00:00', '2026-01-01', 'Published', 'Regular weekly schedule', @seed_admin_id
FROM barangays b WHERE b.name IN ('Barangka','Calumpang','Concepcion Uno','Concepcion Dos','Fortune')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO ba_collection_schedules
  (barangay_id, title, waste_type, schedule_type, day_of_week, time_start, time_end, effective_from, status, notes, created_by_admin_id)
SELECT b.id, 'Friday Recyclable', 'Recyclable', 'regular', 6, '07:00:00', '11:00:00', '2026-01-01', 'Published', 'Regular weekly schedule', @seed_admin_id
FROM barangays b WHERE b.name IN ('Industrial Valley Complex','Jesus de la Peña','Malanday','Marikina Heights','Nangka','Parang','San Roque','Santa Elena','Santo Niño','Tañong','Tumana')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO ba_collection_schedules
  (barangay_id, title, waste_type, schedule_type, day_of_week, time_start, time_end, effective_from, status, notes, created_by_admin_id)
SELECT b.id, 'Tuesday Biodegradable', 'Biodegradable', 'regular', 3, '06:00:00', '10:00:00', '2026-01-01', 'Published', 'Regular weekly schedule', @seed_admin_id
FROM barangays b WHERE b.name IN ('Industrial Valley Complex','Jesus de la Peña','Malanday','Marikina Heights','Nangka','Parang','San Roque','Santa Elena','Santo Niño','Tañong','Tumana')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO ba_collection_schedules
  (barangay_id, title, waste_type, schedule_type, day_of_week, time_start, time_end, effective_from, status, notes, created_by_admin_id)
SELECT b.id, 'Thursday Non-Biodegradable', 'Non-Biodegradable', 'regular', 5, '06:00:00', '10:00:00', '2026-01-01', 'Published', 'Regular weekly schedule', @seed_admin_id
FROM barangays b WHERE b.name IN ('Industrial Valley Complex','Jesus de la Peña','Malanday','Marikina Heights','Nangka','Parang','San Roque','Santa Elena','Santo Niño','Tañong','Tumana')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- --- MOCK: Holiday / exception example ---
INSERT INTO ba_collection_schedules
  (barangay_id, title, waste_type, schedule_type, collection_date, time_start, time_end, status, notes, created_by_admin_id)
VALUES
(1, 'Rizal Day Exception — no pickup, moved to next day', 'Mixed', 'exception', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '07:00:00', '11:00:00', 'Published', 'Holiday notice: moved from Monday regular', @seed_admin_id)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- --- MOCK: Waste guide entries ---
INSERT INTO ba_waste_guide (item_name, category, is_accepted, prep_guidance, disposal_guidance, created_by_admin_id) VALUES
('Food scraps (leftovers)', 'Biodegradable', 1, 'Remove packaging, drain excess liquid', 'Place in biodegradable bin on scheduled day', @seed_admin_id),
('Fruit & vegetable peels', 'Biodegradable', 1, 'None required', 'Biodegradable bin', @seed_admin_id),
('Used paper / newspapers', 'Recyclable', 1, 'Flatten, remove plastic windows', 'Recyclable bin or bring to barangay MRF', @seed_admin_id),
('Plastic bottles (PET)', 'Recyclable', 1, 'Rinse clean, flatten if possible', 'Recyclable bin', @seed_admin_id),
('Plastic sando bags', 'Non-Biodegradable', 1, 'Bundle together to avoid litter', 'Non-biodegradable bin', @seed_admin_id),
('Single-use plastic straws / utensils', 'Non-Biodegradable', 1, 'None required', 'Non-biodegradable bin — consider switching to reusable', @seed_admin_id),
('Diapers / sanitary napkins', 'Non-Biodegradable', 1, 'Wrap in newspaper or extra bag', 'Non-biodegradable bin', @seed_admin_id),
('Glass bottles (whole)', 'Recyclable', 1, 'Rinse clean; do not break', 'Recyclable bin / MRF drop-off', @seed_admin_id),
('Broken glass', 'Special', 1, 'Wrap in cardboard or thick paper, label "BROKEN GLASS"', 'Special handling — place at top of non-biodegradable pile', @seed_admin_id),
('Used batteries (AA/AAA/9V)', 'Hazardous', 0, 'Do not throw in household bins', 'Bring to barangay designated E-waste drop-off only', @seed_admin_id),
('Expired medicines', 'Hazardous', 0, 'Keep packaging', 'Bring to City Health office; do not mix with household waste', @seed_admin_id),
('Motor oil / paint / chemicals', 'Hazardous', 0, 'Keep in sealed containers', 'Contact City ENRO for hazardous waste schedule; do not pour in drains', @seed_admin_id),
('E-waste (old phones / chargers)', 'Hazardous', 0, 'None required', 'Bring to E-waste collection schedule or MRF', @seed_admin_id),
('Yard clippings / dry leaves', 'Biodegradable', 1, 'Bundle or bag loosely', 'Biodegradable bin', @seed_admin_id),
('Used cooking oil', 'Special', 1, 'Cool, pour into sealed plastic bottle', 'Drop-off at barangay; do not pour down sink', @seed_admin_id),
('Cigarette butts', 'Non-Biodegradable', 1, 'Wet to extinguish, wrap to prevent odor', 'Non-biodegradable bin', @seed_admin_id),
('Cardboard boxes', 'Recyclable', 1, 'Flatten and tape shut', 'Recyclable bin', @seed_admin_id),
('Aluminum cans', 'Recyclable', 1, 'Rinse clean, crush if possible', 'Recyclable bin', @seed_admin_id)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- --- MOCK: Announcements ---
INSERT INTO ba_announcements (title, kind, scope, content, effective_date, revised_schedule_info, status, published_at, created_by_admin_id) VALUES
('Rizal Day Schedule Adjustment', 'Holiday', 'all', 'In observance of the holiday, Monday biodegradable collection will be moved one day later. Please bring out your biodegradable waste on Tuesday instead.', CURDATE(), 'Monday Biodegradable → Tuesday (next day)', 'Published', CURRENT_TIMESTAMP, @seed_admin_id),
('Heavy Rain Advisory — Possible Delays', 'Delay', 'barangay', 'Heavy rains expected today. Collection teams may run 1–3 hours late in low-lying areas of Marikina Heights and Tumana.', CURDATE(), 'Expect 1–3 hour delay', 'Published', CURRENT_TIMESTAMP, @seed_admin_id),
('New Segregation Policy for Plastic Straws', 'Schedule_Change', 'all', 'Starting next week, single-use plastic straws and utensils must be placed in the Non-Biodegradable bin, separate from recyclable plastics.', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Single-use plastics now classified Non-Biodegradable', 'Published', CURRENT_TIMESTAMP, @seed_admin_id)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- Tie the barangay-scoped announcement to Marikina Heights
UPDATE ba_announcements
SET target_barangay_id = (SELECT id FROM barangays WHERE name = 'Marikina Heights' LIMIT 1)
WHERE title = 'Heavy Rain Advisory — Possible Delays';

-- --- MOCK: FAQs ---
INSERT INTO ba_faqs (question, answer, category, sort_order, created_by_admin_id) VALUES
('What time should I bring out my garbage?', 'Please put your bins out before 6:00 AM on your scheduled collection day, and retrieve empty bins by 6:00 PM.', 'Schedules', 1, @seed_admin_id),
('How do I classify food leftovers?', 'Food leftovers are Biodegradable. Make sure to remove any plastic or foil packaging first.', 'Segregation', 2, @seed_admin_id),
('Where do I throw broken glass?', 'Wrap it in cardboard or thick newspaper, label it "BROKEN GLASS", and place it at the top of your Non-Biodegradable pile so collectors see it.', 'Segregation', 3, @seed_admin_id),
('Can I request a special pickup for large items?', 'BasuraAlert does not support on-demand pickup booking. You may submit a Report an Issue entry and the administrator will forward your request to the barangay for review.', 'Schedules', 4, @seed_admin_id),
('What do I do if my garbage was NOT collected?', '1) Verify your barangay schedule and holiday notices first. 2) If you believe you were missed, use the Report an Issue feature and optionally attach a photo. An administrator will review and respond.', 'Reports', 5, @seed_admin_id),
('Why am I not receiving email reminders?', 'Check your Profile > Notification Preferences and ensure email reminders are enabled. Also check your Spam folder. In Phase 1, reminders are simulated; in Phase 2 they will be sent live via Brevo.', 'Notifications', 6, @seed_admin_id)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- --- MOCK: If users table already has test users, give them barangay + notification prefs ---
-- Set a default barangay for any existing users that have barangay NULL
UPDATE users SET barangay = 'Barangka' WHERE barangay IS NULL OR TRIM(barangay) = '';

-- Create default notification preferences for every existing user
INSERT INTO ba_notification_preferences (user_id, enable_email_reminders, enable_sms_reminders, enable_in_app_reminders, reminder_hours_before)
SELECT u.id, 1, 1, 1, 12
FROM users u
LEFT JOIN ba_notification_preferences p ON p.user_id = u.id
WHERE p.id IS NULL;

-- --- MOCK: Seed a couple of sample notifications for existing users to simulate in-app reminders ---
INSERT INTO ba_notifications (user_id, type, title, message, channel, delivery_status)
SELECT u.id, 'reminder', 'Upcoming Biodegradable Collection', 'Heads up! Biodegradable waste collection is scheduled for tomorrow (Monday, 6:00 AM – 10:00 AM) for your barangay. Please prepare accordingly.', 'in_app', 'mock_sent'
FROM users u
ORDER BY u.id ASC
LIMIT 3;

INSERT INTO ba_notifications (user_id, type, title, message, ref_table, ref_id, channel, delivery_status)
SELECT u.id, 'announcement', 'Holiday: Rizal Day Schedule Adjustment', 'In observance of the holiday, Monday biodegradable collection is moved one day later. Please see the Announcements page for details.', 'ba_announcements', a.id, 'in_app', 'mock_sent'
FROM users u, ba_announcements a
WHERE a.title = 'Rizal Day Schedule Adjustment'
ORDER BY u.id ASC
LIMIT 2;

-- -----------------------------------------------------------------------------
-- 11. Admin-only report internals (idempotent)
-- -----------------------------------------------------------------------------
-- ba_update_report_status() (includes/BasuraAlert/Reports.php:289,306) writes
-- ba_reports.internal_handling_note and ba_report_timeline.is_internal /
-- .internal_note, and ba_generate_report_number() inserts into is_internal.
-- These three columns were previously created only by the ad-hoc web runner
-- _run_basuraalert_migration.php, which bin/migrate.php does not execute, so a
-- database built by the documented path (`composer install && php
-- bin/migrate.php`) was missing them. The status UPDATE would succeed and the
-- timeline INSERT would then throw, and the catch at Reports.php:329 returned
-- false with no logging -- so status changes failed silently.
-- The INFORMATION_SCHEMA guard keeps the statement safe to re-run.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_reports ADD COLUMN internal_handling_note TEXT NULL COMMENT ''[ADMIN ONLY] Private internal handling note, never shown to residents'' AFTER resolution_note',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_reports' AND COLUMN_NAME = 'internal_handling_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_report_timeline ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = admin-only internal timeline entry (hidden from resident)'' AFTER admin_id',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_report_timeline' AND COLUMN_NAME = 'is_internal');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ba_report_timeline ADD COLUMN internal_note TEXT NULL COMMENT ''[ADMIN ONLY] Internal note body, shown only on admin detail view'' AFTER is_internal',
  'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_report_timeline' AND COLUMN_NAME = 'internal_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;