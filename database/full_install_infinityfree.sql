-- Marikina E-Concern — full install snapshot for InfinityFree phpMyAdmin
-- Generated: 2026-09-28 13:28:34 (Asia/Manila)
-- Source: working local `e_concern` DB structure (exact columns/keys),
-- plus reference data for: departments, concern_types, barangays, ba_waste_guide, ba_faqs, ba_collection_schedules, ba_dropoff_points, ba_dropoff_schedules.
-- Import ONCE into an EMPTY database via phpMyAdmin > Import.
-- Accounts/reports/messages/OTPs/logs are intentionally NOT included.

SET FOREIGN_KEY_CHECKS=0;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `admin_activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `target_type` varchar(30) DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_activity_admin` (`admin_id`,`created_at`),
  KEY `idx_admin_activity_target` (`target_type`,`target_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `admin_login_otps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL,
  `otp_hash` char(64) NOT NULL,
  `channel` enum('sms') NOT NULL DEFAULT 'sms',
  `destination_masked` varchar(30) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `max_attempts` int(10) unsigned NOT NULL DEFAULT 5,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_aotp_admin` (`admin_id`),
  KEY `idx_aotp_exp` (`expires_at`),
  KEY `idx_aotp_cons` (`consumed_at`),
  CONSTRAINT `fk_aotp_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Admin login 2FA SMS OTP challenges';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `admin_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `concern_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(30) NOT NULL,
  `body` varchar(255) NOT NULL,
  `seen` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_admin` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_notif_admin` (`admin_id`,`seen`,`created_at`),
  KEY `idx_admin_notif_concern` (`concern_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `email` varchar(190) NOT NULL,
  `mobile` varchar(20) DEFAULT NULL COMMENT 'PH mobile for SMS OTP (E.164 +639xxxxx or local 09xxxxx)',
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super_admin','department_admin') NOT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `otp_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `failed_login_attempts` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Failed login brute-force counter',
  `locked_until` timestamp NULL DEFAULT NULL COMMENT 'Temporary lock expiry after too many failed attempts',
  `last_login_at` timestamp NULL DEFAULT NULL COMMENT 'Last successful login timestamp',
  `ui_theme` varchar(20) NOT NULL DEFAULT 'light',
  `avatar_public_id` varchar(190) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admins_email` (`email`),
  KEY `idx_admins_role` (`role`),
  KEY `idx_admins_department` (`department_id`),
  KEY `idx_admins_mobile` (`mobile`),
  CONSTRAINT `fk_admins_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL,
  `kind` enum('Holiday','Disruption','Delay','Cancellation','Resumption','Schedule_Change','General') NOT NULL DEFAULT 'General',
  `scope` enum('all','barangay') NOT NULL DEFAULT 'all',
  `target_barangay_id` int(10) unsigned DEFAULT NULL,
  `content` text NOT NULL,
  `effective_date` date DEFAULT NULL,
  `revised_schedule_info` varchar(255) DEFAULT NULL COMMENT 'If kind=Schedule_Change, describe new schedule briefly',
  `status` enum('Draft','Published','Archived') NOT NULL DEFAULT 'Draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by_admin_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ann_kind` (`kind`),
  KEY `idx_ann_scope` (`scope`),
  KEY `idx_ann_status` (`status`),
  KEY `idx_ann_effective` (`effective_date`),
  KEY `idx_ann_target_barangay` (`target_barangay_id`),
  KEY `fk_ba_ann_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_ba_ann_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_ann_barangay` FOREIGN KEY (`target_barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_collection_schedules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `barangay_id` int(10) unsigned NOT NULL,
  `title` varchar(190) NOT NULL COMMENT 'e.g. Monday Biodegradable',
  `waste_type` enum('Biodegradable','Non-Biodegradable','Recyclable','Special','Mixed','Hazardous','Bulky') NOT NULL DEFAULT 'Mixed' COMMENT 'Waste category for this schedule window',
  `schedule_type` enum('regular','recurring','one_time','exception') NOT NULL DEFAULT 'regular',
  `linked_type` enum('none','dropoff') NOT NULL DEFAULT 'none',
  `linked_id` int(10) unsigned DEFAULT NULL,
  `linked_group_uid` varchar(32) DEFAULT NULL,
  `day_of_week` tinyint(3) unsigned DEFAULT NULL COMMENT '1=Sun..7=Sat for regular schedules',
  `collection_date` date DEFAULT NULL COMMENT 'Exact date for one_time/exception entries',
  `time_start` time DEFAULT NULL,
  `time_end` time DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `status` enum('Draft','Published','Archived') NOT NULL DEFAULT 'Draft',
  `notes` varchar(255) DEFAULT NULL,
  `created_by_admin_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_coll_linked_waste` (`linked_type`,`linked_id`,`waste_type`),
  KEY `idx_sched_barangay` (`barangay_id`),
  KEY `idx_sched_status` (`status`),
  KEY `idx_sched_type` (`schedule_type`),
  KEY `idx_sched_waste` (`waste_type`),
  KEY `idx_sched_date` (`collection_date`),
  KEY `idx_sched_dow` (`day_of_week`),
  KEY `fk_ba_sched_admin` (`created_by_admin_id`),
  KEY `idx_coll_linked` (`linked_type`,`linked_id`),
  CONSTRAINT `fk_ba_sched_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_sched_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_dropoff_points` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `barangay_id` int(10) unsigned NOT NULL,
  `spot_name` varchar(255) NOT NULL COMMENT 'Friendly name shown to residents e.g. End of J.P. Rizal St. curb',
  `address` varchar(500) NOT NULL COMMENT 'Full street address / zone for this drop-off',
  `latitude` decimal(10,7) NOT NULL COMMENT 'WGS84 latitude (OSM Nominatim pin)',
  `longitude` decimal(10,7) NOT NULL COMMENT 'WGS84 longitude (OSM Nominatim pin)',
  `place_osm_id` varchar(64) DEFAULT NULL COMMENT 'Optional OSM place id for Nominatim policy audit trail',
  `pickup_type` enum('STREET_END_CURBSIDE','BARANGAY_MRF','SHARED_BIN_CLUSTER','BULKY_DROP_OFF_YARD','HAZARDOUS_SATELLITE','OTHER') NOT NULL DEFAULT 'STREET_END_CURBSIDE',
  `open_24_7` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = schedule-only, 1 = always accessible curb',
  `operation_hours` varchar(255) DEFAULT NULL COMMENT 'Free-text operation hours if not 24/7',
  `notes_public` varchar(1000) DEFAULT NULL COMMENT 'Resident-facing note shown on map popup',
  `reference_photo` varchar(500) DEFAULT NULL COMMENT 'Cloudinary URL (Phase2) or local path (Phase1 mock)',
  `status` enum('DRAFT','PUBLISHED','TEMPORARILY_CLOSED') NOT NULL DEFAULT 'PUBLISHED',
  `accepts_bio` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Accepts Biodegradable waste?',
  `accepts_nonbio` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Accepts Non-biodegradable waste?',
  `accepts_recyclable` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Accepts Recyclable waste?',
  `accepts_hazard` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Accepts Hazardous/Special waste?',
  `accepts_bulky` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Accepts Bulky/Uncollected yard waste?',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by_admin_id` int(10) unsigned DEFAULT NULL,
  `published_by_admin_id` int(10) unsigned DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dropoff_barangay` (`barangay_id`),
  KEY `idx_dropoff_status` (`status`),
  KEY `idx_dropoff_lat_lon` (`latitude`,`longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Official Marikina waste drop-off points shown on the resident map view';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_dropoff_schedules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dropoff_id` int(10) unsigned NOT NULL,
  `day_of_week` tinyint(1) NOT NULL COMMENT '0 Sunday .. 6 Saturday',
  `waste_type` enum('Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special','Bulky') NOT NULL,
  `linked_type` enum('none','collection') NOT NULL DEFAULT 'none',
  `linked_id` int(10) unsigned DEFAULT NULL,
  `time_start` time NOT NULL,
  `time_end` time NOT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dropoff_sched_dropoff` (`dropoff_id`),
  KEY `idx_dropoff_linked` (`linked_type`,`linked_id`),
  CONSTRAINT `fk_ba_sched_dropoff` FOREIGN KEY (`dropoff_id`) REFERENCES `ba_dropoff_points` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Special per-drop-off, per-day collection windows (overrides barangay default)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_faqs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `category` varchar(60) DEFAULT NULL COMMENT 'Schedules / Segregation / Notifications / Reports / Other',
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('Published','Archived') NOT NULL DEFAULT 'Published',
  `created_by_admin_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ba_faq_status` (`status`),
  KEY `idx_ba_faq_sort` (`sort_order`),
  KEY `fk_ba_faq_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_ba_faq_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'Null if anonymous (but for this project usually logged-in)',
  `kind` enum('Feedback','FAQ_Suggestion','Contact_Us') NOT NULL,
  `subject` varchar(190) NOT NULL,
  `message` text NOT NULL,
  `photos_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Optional uploaded image evidence (relative upload paths, JSON array)' CHECK (json_valid(`photos_json`)),
  `reply` text DEFAULT NULL,
  `replied_by_admin_id` int(10) unsigned DEFAULT NULL,
  `replied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ba_fb_kind` (`kind`),
  KEY `idx_ba_fb_user` (`user_id`),
  KEY `fk_ba_fb_admin` (`replied_by_admin_id`),
  CONSTRAINT `fk_ba_fb_admin` FOREIGN KEY (`replied_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_fb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_notification_preferences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `enable_email_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `enable_sms_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `enable_in_app_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `reminder_hours_before` tinyint(3) unsigned NOT NULL DEFAULT 12 COMMENT 'How many hours before collection to send reminder',
  `ev_feedback_reply_sms` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=feedback_reply via channel=sms (1=on, 0=off)',
  `ev_feedback_reply_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=feedback_reply via channel=email (1=on, 0=off)',
  `ev_feedback_reply_in_app` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=feedback_reply via channel=in_app (1=on, 0=off)',
  `ev_report_update_sms` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=report_update via channel=sms (1=on, 0=off)',
  `ev_report_update_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=report_update via channel=email (1=on, 0=off)',
  `ev_report_update_in_app` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=report_update via channel=in_app (1=on, 0=off)',
  `ev_report_submit_sms` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=report_submit via channel=sms (1=on, 0=off)',
  `ev_report_submit_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=report_submit via channel=email (1=on, 0=off)',
  `ev_report_submit_in_app` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=report_submit via channel=in_app (1=on, 0=off)',
  `ev_announcement_sms` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=announcement via channel=sms (1=on, 0=off)',
  `ev_announcement_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=announcement via channel=email (1=on, 0=off)',
  `ev_announcement_in_app` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=announcement via channel=in_app (1=on, 0=off)',
  `ev_schedule_change_sms` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=schedule_change via channel=sms (1=on, 0=off)',
  `ev_schedule_change_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=schedule_change via channel=email (1=on, 0=off)',
  `ev_schedule_change_in_app` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=schedule_change via channel=in_app (1=on, 0=off)',
  `ev_reminder_sms` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=reminder via channel=sms (1=on, 0=off)',
  `ev_reminder_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=reminder via channel=email (1=on, 0=off)',
  `ev_reminder_in_app` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=reminder via channel=in_app (1=on, 0=off)',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ba_pref_user` (`user_id`),
  CONSTRAINT `fk_ba_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT 'Resident recipient',
  `type` enum('reminder','schedule_change','announcement','report_submit','report_update','feedback_reply','concern_message','email_mock','sms_mock') NOT NULL,
  `title` varchar(190) NOT NULL,
  `message` text NOT NULL,
  `ref_table` varchar(60) DEFAULT NULL COMMENT 'Related table e.g. ba_collection_schedules',
  `ref_id` int(10) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `channel` enum('in_app','email','sms') NOT NULL DEFAULT 'in_app',
  `delivery_status` enum('queued','sent','failed','mock_sent') NOT NULL DEFAULT 'mock_sent',
  `delivery_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `retry_count` int(10) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_read` (`is_read`),
  KEY `idx_notif_type` (`type`),
  KEY `idx_notif_created` (`created_at`),
  KEY `idx_notif_ref` (`ref_table`,`ref_id`),
  KEY `idx_notif_dispatch` (`delivery_status`,`channel`,`id`),
  CONSTRAINT `fk_ba_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_report_ratings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL COMMENT '1-5 helpfulness star rating',
  `comment` varchar(1000) DEFAULT NULL,
  `rated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rating_report_user` (`report_id`,`user_id`),
  KEY `idx_rating_report` (`report_id`),
  KEY `idx_rating_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Resident helpfulness rating on closed/rejected reports';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_report_timeline` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int(10) unsigned NOT NULL,
  `status` enum('New','Acknowledged','Ongoing','Completed','Cancelled') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = admin-only internal timeline entry (hidden from resident)',
  `internal_note` text DEFAULT NULL COMMENT '[ADMIN ONLY] Internal note body, shown only on admin detail view',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ba_rt_report` (`report_id`),
  KEY `idx_ba_rt_admin` (`admin_id`),
  CONSTRAINT `fk_ba_rt_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_rt_report` FOREIGN KEY (`report_id`) REFERENCES `ba_reports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `report_number` varchar(20) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'Other_Waste_Concern' COMMENT 'Resident-chosen report category (raw key; label via PHP ba_report_category_options)',
  `barangay_id` int(10) unsigned NOT NULL,
  `date_of_concern` date NOT NULL,
  `street` varchar(190) DEFAULT NULL,
  `landmark` varchar(190) DEFAULT NULL,
  `description` text NOT NULL,
  `photos_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`photos_json`)),
  `status` enum('New','Acknowledged','Ongoing','Completed','Cancelled') NOT NULL DEFAULT 'New',
  `assigned_admin_id` int(10) unsigned DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `internal_handling_note` text DEFAULT NULL COMMENT '[ADMIN ONLY] Private internal handling note, never shown to residents',
  `rating` tinyint(3) unsigned DEFAULT NULL COMMENT 'Optional 1-5 helpfulness rating after resolution',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ba_reports_num` (`report_number`),
  KEY `idx_ba_reports_user` (`user_id`),
  KEY `idx_ba_reports_status` (`status`),
  KEY `idx_ba_reports_category` (`category`),
  KEY `idx_ba_reports_barangay` (`barangay_id`),
  KEY `idx_ba_reports_assigned` (`assigned_admin_id`),
  CONSTRAINT `fk_ba_reports_admin` FOREIGN KEY (`assigned_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_reports_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ba_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ba_waste_guide` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_name` varchar(190) NOT NULL,
  `category` enum('Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special') NOT NULL,
  `is_accepted` tinyint(1) NOT NULL DEFAULT 1,
  `prep_guidance` varchar(255) DEFAULT NULL COMMENT 'How to prepare before disposal',
  `disposal_guidance` varchar(255) DEFAULT NULL COMMENT 'How to dispose',
  `status` enum('Published','Archived') NOT NULL DEFAULT 'Published',
  `created_by_admin_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wg_item` (`item_name`),
  KEY `idx_wg_category` (`category`),
  KEY `idx_wg_status` (`status`),
  KEY `fk_ba_wg_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_ba_wg_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `barangays` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `city` varchar(120) NOT NULL DEFAULT 'Marikina City',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_barangays_name` (`name`),
  KEY `idx_barangays_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `concern_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `concern_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `seen_by_admin` tinyint(1) NOT NULL DEFAULT 0,
  `seen_by_user` tinyint(1) NOT NULL DEFAULT 0,
  `sender` enum('user','department') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_messages_concern` (`concern_id`),
  KEY `idx_msg_unread_admin` (`seen_by_admin`,`concern_id`,`created_at`),
  CONSTRAINT `fk_messages_concern` FOREIGN KEY (`concern_id`) REFERENCES `concerns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `concern_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `concern_id` int(10) unsigned NOT NULL,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `note` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notes_concern` (`concern_id`),
  KEY `idx_notes_admin` (`admin_id`),
  CONSTRAINT `fk_notes_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notes_concern` FOREIGN KEY (`concern_id`) REFERENCES `concerns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `concern_timeline` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `concern_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `status` enum('New','Ongoing','Acknowledge','Completed','Cancelled') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `event_type` varchar(30) NOT NULL DEFAULT 'status_change',
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_timeline_concern` (`concern_id`),
  CONSTRAINT `fk_timeline_concern` FOREIGN KEY (`concern_id`) REFERENCES `concerns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `concern_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` int(10) unsigned NOT NULL,
  `name` varchar(190) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_concern_types_dept_name` (`department_id`,`name`),
  KEY `idx_concern_types_dept` (`department_id`),
  CONSTRAINT `fk_concern_types_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `concerns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `report_number` varchar(20) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `concern_type_id` int(10) unsigned NOT NULL,
  `assigned_admin_id` int(10) unsigned DEFAULT NULL,
  `street` varchar(190) NOT NULL,
  `barangay` varchar(120) NOT NULL,
  `landmark` varchar(190) NOT NULL,
  `description` text NOT NULL,
  `status` enum('New','Ongoing','Acknowledge','Completed','Cancelled') NOT NULL DEFAULT 'New',
  `photos_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`photos_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_concerns_report_number` (`report_number`),
  KEY `idx_concerns_user` (`user_id`),
  KEY `idx_concerns_status` (`status`),
  KEY `idx_concerns_type` (`concern_type_id`),
  KEY `idx_concerns_assigned_admin` (`assigned_admin_id`),
  CONSTRAINT `fk_concerns_assigned_admin` FOREIGN KEY (`assigned_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_concerns_type` FOREIGN KEY (`concern_type_id`) REFERENCES `concern_types` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_concerns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `departments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `login_failures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `realm` enum('citizen','admin') NOT NULL,
  `identifier` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_failures_realm_ident` (`realm`,`identifier`,`failed_at`),
  KEY `idx_login_failures_realm_ip` (`realm`,`ip_address`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Applied migration tracker for bin/migrate.php';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `user_email_verifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 hex of the raw token sent in the email link',
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ev_token_hash` (`token_hash`),
  KEY `idx_ev_user_id` (`user_id`),
  KEY `idx_ev_expires` (`expires_at`),
  KEY `idx_ev_consumed` (`consumed_at`),
  CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `user_login_otps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `otp_hash` char(64) NOT NULL COMMENT 'SHA-256 hex of the 6-digit OTP code',
  `channel` enum('sms') NOT NULL DEFAULT 'sms',
  `destination_masked` varchar(30) NOT NULL COMMENT 'Masked mobile for audit (e.g. +63917******9101)',
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `max_attempts` int(10) unsigned NOT NULL DEFAULT 5,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`),
  KEY `idx_otp_expires` (`expires_at`),
  KEY `idx_otp_consumed` (`consumed_at`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `user_password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT 'FK to users.id',
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the raw 64-hex token emailed to user',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL COMMENT 'Set to NOW() once reset successfully applied; NULL = usable',
  `requested_ip` varchar(45) DEFAULT NULL COMMENT 'Client IPv4/IPv6 the reset was requested from (audit)',
  `consumed_ip` varchar(45) DEFAULT NULL COMMENT 'Client IP that consumed the token (audit)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pwreset_token_hash` (`token_hash`),
  KEY `idx_pwreset_user` (`user_id`),
  KEY `idx_pwreset_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Self-serve password-reset challenge tokens sent via email link';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `mobile` varchar(30) NOT NULL,
  `barangay` varchar(120) DEFAULT NULL COMMENT 'Resident barangay for BasuraAlert schedule targeting',
  `moderator_notes` varchar(500) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `otp_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `failed_login_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `ui_theme` varchar(20) NOT NULL DEFAULT 'light',
  `avatar_public_id` varchar(190) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_email_verified` (`email_verified_at`),
  KEY `idx_users_locked_until` (`locked_until`),
  KEY `idx_users_barangay` (`barangay`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: e_concern
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT  IGNORE INTO `departments` (`id`, `name`, `active`) VALUES (1,'Community Relations Office',1),(2,'Engineering Office',1),(3,'Human Resource Management Office',1),(4,'Marikina City Tourism and Cultural Office',1),(5,'Marikina Sports Center',1),(6,'Office for Senior Citizen\'s Affairs',1),(7,'Office of Public Safety and Security',1),(8,'Office of the Mayor',1),(9,'Parks Development Office',1),(10,'River Parks Authority',1),(11,'School Repair and Maintenance Office',1),(12,'Animal Rescue and Shelter',1),(13,'Business Permit and License Office',1),(14,'City Environmental Management Office',1),(15,'City Health Office',1),(16,'City Public Market Office',1),(17,'City Social Welfare Development',1),(18,'City Treasury Office',1);
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `concern_types`
--

LOCK TABLES `concern_types` WRITE;
/*!40000 ALTER TABLE `concern_types` DISABLE KEYS */;
INSERT  IGNORE INTO `concern_types` (`id`, `department_id`, `name`, `active`) VALUES (1,1,'Community / HOA Concerns',1),(2,2,'Permits',1),(3,2,'Building and Construction',1),(4,2,'Electrical Concerns',1),(5,2,'Cable Bundling',1),(6,2,'Traffic Light Repair',1),(7,2,'Street Light Repair',1),(8,2,'MWC Restoration',1),(9,2,'Street Repair & Sidewalk Maintenance',1),(10,2,'Declogging of Public Canals and Drains',1),(11,3,'Employee Concerns / Feedback',1),(12,4,'Tours and Promotions',1),(13,5,'Schedule',1),(14,5,'Booking',1),(15,5,'Events',1),(16,6,'Issuance of SC ID',1),(17,6,'Birthday Subsidy',1),(18,7,'Illegal Parking/Road Obstructions',1),(19,7,'Illegal Vendors/Ordinance Violations',1),(20,7,'Public Order and Safety',1),(21,7,'Public Transportation',1),(22,7,'Complaint/Concerns',1),(23,7,'Violation Inquiries',1),(24,8,'Other(s): Pls specify',1),(25,9,'Fabrication/Renovation/Repair/Repainting of Park Furniture',1),(26,9,'Parks and Playgrounds Development, Landscaping',1),(27,9,'Park Cleaning/Clearing, Grass Cutting, Soil Leveling',1),(28,9,'Tree Planting/Tree Maintenance',1),(29,9,'Tree Trimming',1),(30,10,'Riverparks Maintenance',1),(31,10,'Photoshoot/Educational Activities',1),(32,10,'Riverpark Restaurants',1),(33,10,'Security & Safety of Park Goers',1),(34,11,'School Repairs and Requests',1),(35,12,'Pet Registration & Vaccination',1),(36,12,'Stray Animals',1),(37,13,'Business Inquiries',1),(38,13,'Permits',1),(39,13,'Inspection',1),(40,14,'Garbage Collection',1),(41,14,'Hakot Kuyagot',1),(42,14,'Tanker Sidewalk Cleaning and Scrubbing',1),(43,14,'Vacant Lot Grass Cutting and Soil Leveling',1),(44,14,'Eco Bricks',1),(45,15,'Permits and Certificates',1),(46,15,'CHO Schedule and Services',1),(47,15,'Health Center Schedule and Services',1),(48,15,'Medical Arts Schedule and Services',1),(49,15,'Animal Bite Treatment Center',1),(50,16,'Consumer Welfare Assistance/Complaints',1),(51,17,'PWD Registration',1),(52,17,'Solo Parents Registration',1),(53,18,'Real Property Tax',1),(54,18,'Business Tax',1),(55,18,'Other Payments',1);
/*!40000 ALTER TABLE `concern_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `barangays`
--

LOCK TABLES `barangays` WRITE;
/*!40000 ALTER TABLE `barangays` DISABLE KEYS */;
INSERT  IGNORE INTO `barangays` (`id`, `name`, `city`, `active`) VALUES (1,'Barangka','Marikina City',1),(2,'Calumpang','Marikina City',1),(3,'Concepcion Uno','Marikina City',1),(4,'Concepcion Dos','Marikina City',1),(5,'Fortune','Marikina City',1),(6,'Industrial Valley Complex','Marikina City',1),(7,'Jesus de la Peña','Marikina City',1),(8,'Malanday','Marikina City',1),(9,'Marikina Heights','Marikina City',1),(10,'Nangka','Marikina City',1),(11,'Parang','Marikina City',1),(12,'San Roque','Marikina City',1),(13,'Santa Elena','Marikina City',1),(14,'Santo Niño','Marikina City',1),(15,'Tañong','Marikina City',1),(16,'Tumana','Marikina City',1);
/*!40000 ALTER TABLE `barangays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `ba_waste_guide`
--

LOCK TABLES `ba_waste_guide` WRITE;
/*!40000 ALTER TABLE `ba_waste_guide` DISABLE KEYS */;
INSERT  IGNORE INTO `ba_waste_guide` (`id`, `item_name`, `category`, `is_accepted`, `prep_guidance`, `disposal_guidance`, `status`, `created_by_admin_id`, `created_at`, `updated_at`) VALUES (3,'Used paper / newspapers','Recyclable',1,'Flatten, remove plastic windows','Recyclable bin or bring to barangay MRF','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(4,'Plastic bottles (PET)','Recyclable',1,'Rinse clean, flatten if possible','Recyclable bin','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(5,'Plastic sando bags','Non-Biodegradable',1,'Bundle together to avoid litter','Non-biodegradable bin','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(6,'Single-use plastic straws / utensils','Non-Biodegradable',1,'None required','Non-biodegradable bin — consider switching to reusable','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(7,'Diapers / sanitary napkins','Non-Biodegradable',1,'Wrap in newspaper or extra bag','Non-biodegradable bin','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(8,'Glass bottles (whole)','Recyclable',1,'Rinse clean; do not break','Recyclable bin / MRF drop-off','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(9,'Broken glass','Special',1,'Wrap in cardboard or thick paper, label \"BROKEN GLASS\"','Special handling — place at top of non-biodegradable pile','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(10,'Used batteries (AA/AAA/9V)','Hazardous',0,'Do not throw in household bins','Bring to barangay designated E-waste drop-off only','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(11,'Expired medicines','Hazardous',0,'Keep packaging','Bring to City Health office; do not mix with household waste','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(12,'Motor oil / paint / chemicals','Hazardous',0,'Keep in sealed containers','Contact City ENRO for hazardous waste schedule; do not pour in drains','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(13,'E-waste (old phones / chargers)','Hazardous',0,'None required','Bring to E-waste collection schedule or MRF','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(14,'Yard clippings / dry leavesssss','Biodegradable',1,'Bundle or bag loosely','Biodegradable bin','Published',1,'2026-08-21 08:52:51','2026-09-07 06:54:50'),(15,'Used cooking oil','Special',1,'Cool, pour into sealed plastic bottle','Drop-off at barangay; do not pour down sink','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(16,'Cigarette butts','Non-Biodegradable',1,'Wet to extinguish, wrap to prevent odor','Non-biodegradable bin','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(17,'Cardboard boxes','Recyclable',1,'Flatten and tape shut','Recyclable bin','Published',1,'2026-08-21 08:52:51','2026-09-28 11:27:49'),(59,'Fruit & vegetable peels','Biodegradable',1,'None required','Biodegradable bin','Archived',1,'2026-09-27 07:59:06','2026-09-28 11:27:49'),(60,'Yard clippings / dry leaves','Biodegradable',1,'Bundle or bag loosely','Biodegradable bin','Published',1,'2026-09-27 07:59:06','2026-09-28 11:27:49'),(61,'Aluminum cans','Recyclable',1,'Rinse clean, crush if possible','Recyclable bin','Published',1,'2026-09-27 07:59:06','2026-09-28 11:27:49'),(76,'Fruit & vegetable peels pooooo','Non-Biodegradable',0,'T1','T2','Published',1,'2026-09-27 13:00:09','2026-09-27 13:00:09'),(77,'V35','Biodegradable',1,'V35','V35','Published',1,'2026-09-28 06:32:45','2026-09-28 06:32:45'),(78,'Food scraps (leftovers)','Biodegradable',1,'Remove packaging, drain excess liquid','Place in biodegradable bin on scheduled day','Published',1,'2026-09-28 11:27:49','2026-09-28 11:27:49');
/*!40000 ALTER TABLE `ba_waste_guide` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `ba_faqs`
--

LOCK TABLES `ba_faqs` WRITE;
/*!40000 ALTER TABLE `ba_faqs` DISABLE KEYS */;
INSERT  IGNORE INTO `ba_faqs` (`id`, `question`, `answer`, `category`, `sort_order`, `status`, `created_by_admin_id`, `created_at`, `updated_at`) VALUES (1,'What time should I bring out my garbage?','Please put your bins out before 6:00 AM on your scheduled collection day, and retrieve empty bins by 6:00 PM.','Schedules',1,'Published',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(2,'How do I classify food leftovers?','Food leftovers are Biodegradable. Make sure to remove any plastic or foil packaging first.','Segregation',2,'Published',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(3,'Where do I throw broken glass?','Wrap it in cardboard or thick newspaper, label it \"BROKEN GLASS\", and place it at the top of your Non-Biodegradable pile so collectors see it.','Segregation',3,'Published',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(4,'Can I request a special pickup for large items?','BasuraAlert does not support on-demand pickup booking. You may submit a Report an Issue entry and the administrator will forward your request to the barangay for review.','Schedules',4,'Published',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(5,'What do I do if my garbage was NOT collected?','1) Verify your barangay schedule and holiday notices first. 2) If you believe you were missed, use the Report an Issue feature and optionally attach a photo. An administrator will review and respond.','Reports',5,'Published',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(6,'Why am I not receiving email reminders?','Check your Profile > Notification Preferences and ensure email reminders are enabled. Also check your Spam folder. In Phase 1, reminders are simulated; in Phase 2 they will be sent live via Brevo.','Notifications',6,'Published',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(7,'What time should I bring out my garbage?','Please put your bins out before 6:00 AM on your scheduled collection day, and retrieve empty bins by 6:00 PM.','Schedules',1,'Published',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(8,'How do I classify food leftovers?','Food leftovers are Biodegradable. Make sure to remove any plastic or foil packaging first.','Segregation',2,'Published',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(9,'Where do I throw broken glass?','Wrap it in cardboard or thick newspaper, label it \"BROKEN GLASS\", and place it at the top of your Non-Biodegradable pile so collectors see it.','Segregation',3,'Published',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(10,'Can I request a special pickup for large items?','BasuraAlert does not support on-demand pickup booking. You may submit a Report an Issue entry and the administrator will forward your request to the barangay for review.','Schedules',4,'Published',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(11,'What do I do if my garbage was NOT collected?','1) Verify your barangay schedule and holiday notices first. 2) If you believe you were missed, use the Report an Issue feature and optionally attach a photo. An administrator will review and respond.','Reports',5,'Published',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(12,'Why am I not receiving email reminders?','Check your Profile > Notification Preferences and ensure email reminders are enabled. Also check your Spam folder. In Phase 1, reminders are simulated; in Phase 2 they will be sent live via Brevo.','Notifications',6,'Published',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(13,'What time should I bring out my garbage?','Please put your bins out before 6:00 AM on your scheduled collection day, and retrieve empty bins by 6:00 PM.','Schedules',1,'Published',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(14,'How do I classify food leftovers?','Food leftovers are Biodegradable. Make sure to remove any plastic or foil packaging first.','Segregation',2,'Published',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(15,'Where do I throw broken glass?','Wrap it in cardboard or thick newspaper, label it \"BROKEN GLASS\", and place it at the top of your Non-Biodegradable pile so collectors see it.','Segregation',3,'Published',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(16,'Can I request a special pickup for large items?','BasuraAlert does not support on-demand pickup booking. You may submit a Report an Issue entry and the administrator will forward your request to the barangay for review.','Schedules',4,'Published',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(17,'What do I do if my garbage was NOT collected?','1) Verify your barangay schedule and holiday notices first. 2) If you believe you were missed, use the Report an Issue feature and optionally attach a photo. An administrator will review and respond.','Reports',5,'Published',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(18,'Why am I not receiving email reminders?','Check your Profile > Notification Preferences and ensure email reminders are enabled. Also check your Spam folder. In Phase 1, reminders are simulated; in Phase 2 they will be sent live via Brevo.','Notifications',6,'Published',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(19,'What time should I bring out my garbage?','Please put your bins out before 6:00 AM on your scheduled collection day, and retrieve empty bins by 6:00 PM.','Schedules',1,'Published',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(20,'How do I classify food leftovers?','Food leftovers are Biodegradable. Make sure to remove any plastic or foil packaging first.','Segregation',2,'Published',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(21,'Where do I throw broken glass?','Wrap it in cardboard or thick newspaper, label it \"BROKEN GLASS\", and place it at the top of your Non-Biodegradable pile so collectors see it.','Segregation',3,'Published',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(22,'Can I request a special pickup for large items?','BasuraAlert does not support on-demand pickup booking. You may submit a Report an Issue entry and the administrator will forward your request to the barangay for review.','Schedules',4,'Published',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(23,'What do I do if my garbage was NOT collected?','1) Verify your barangay schedule and holiday notices first. 2) If you believe you were missed, use the Report an Issue feature and optionally attach a photo. An administrator will review and respond.','Reports',5,'Published',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(24,'Why am I not receiving email reminders?','Check your Profile > Notification Preferences and ensure email reminders are enabled. Also check your Spam folder. In Phase 1, reminders are simulated; in Phase 2 they will be sent live via Brevo.','Notifications',6,'Published',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(25,'saan','dito po','Schedules',1,'Archived',1,'2026-08-21 17:33:46','2026-08-21 17:33:46'),(27,'What time should I bring out my garbage?','Please put your bins out before 6:00 AM on your scheduled collection day, and retrieve empty bins by 6:00 PM.','Schedules',1,'Published',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(28,'How do I classify food leftovers?','Food leftovers are Biodegradable. Make sure to remove any plastic or foil packaging first.','Segregation',2,'Published',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(29,'Where do I throw broken glass?','Wrap it in cardboard or thick newspaper, label it \"BROKEN GLASS\", and place it at the top of your Non-Biodegradable pile so collectors see it.','Segregation',3,'Published',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(30,'Can I request a special pickup for large items?','BasuraAlert does not support on-demand pickup booking. You may submit a Report an Issue entry and the administrator will forward your request to the barangay for review.','Schedules',4,'Published',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(31,'What do I do if my garbage was NOT collected?','1) Verify your barangay schedule and holiday notices first. 2) If you believe you were missed, use the Report an Issue feature and optionally attach a photo. An administrator will review and respond.','Reports',5,'Published',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(32,'Why am I not receiving email reminders?','Check your Profile > Notification Preferences and ensure email reminders are enabled. Also check your Spam folder. In Phase 1, reminders are simulated; in Phase 2 they will be sent live via Brevo.','Notifications',6,'Published',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(33,'What time should I bring out my garbage?','Please put your bins out before 6:00 AM on your scheduled collection day, and retrieve empty bins by 6:00 PM.','Schedules',1,'Published',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(34,'How do I classify food leftovers?','Food leftovers are Biodegradable. Make sure to remove any plastic or foil packaging first.','Segregation',2,'Published',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(35,'Where do I throw broken glass?','Wrap it in cardboard or thick newspaper, label it \"BROKEN GLASS\", and place it at the top of your Non-Biodegradable pile so collectors see it.','Segregation',3,'Published',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(36,'Can I request a special pickup for large items?','BasuraAlert does not support on-demand pickup booking. You may submit a Report an Issue entry and the administrator will forward your request to the barangay for review.','Schedules',4,'Published',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(37,'What do I do if my garbage was NOT collected?','1) Verify your barangay schedule and holiday notices first. 2) If you believe you were missed, use the Report an Issue feature and optionally attach a photo. An administrator will review and respond.','Reports',5,'Published',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(38,'Why am I not receiving email reminders?','Check your Profile > Notification Preferences and ensure email reminders are enabled. Also check your Spam folder. In Phase 1, reminders are simulated; in Phase 2 they will be sent live via Brevo.','Notifications',6,'Published',1,'2026-09-28 11:27:49','2026-09-28 11:27:49');
/*!40000 ALTER TABLE `ba_faqs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `ba_collection_schedules`
--

LOCK TABLES `ba_collection_schedules` WRITE;
/*!40000 ALTER TABLE `ba_collection_schedules` DISABLE KEYS */;
INSERT  IGNORE INTO `ba_collection_schedules` (`id`, `barangay_id`, `title`, `waste_type`, `schedule_type`, `linked_type`, `linked_id`, `linked_group_uid`, `day_of_week`, `collection_date`, `time_start`, `time_end`, `effective_from`, `effective_to`, `status`, `notes`, `created_by_admin_id`, `created_at`, `updated_at`) VALUES (1,1,'Monday Biodegradable','Special','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-09-06 14:43:29'),(2,2,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(3,4,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(4,3,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(5,5,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(8,1,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(9,2,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(10,4,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(11,3,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(12,5,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(15,6,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(16,7,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(17,8,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(18,9,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(19,10,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(20,11,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(21,12,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(22,13,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(23,14,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(24,15,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(25,16,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(30,6,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(31,7,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(32,8,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(33,9,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(34,10,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(35,11,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(36,12,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(37,13,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(38,14,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(39,15,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(40,16,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(45,6,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(46,7,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(47,8,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(48,9,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(49,10,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(50,11,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(51,12,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(52,13,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(53,14,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(54,15,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(55,16,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:52:51','2026-08-21 08:52:51'),(61,1,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(62,2,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(63,4,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(64,3,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(65,5,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(68,1,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(69,2,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(70,4,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(71,3,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(72,5,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(75,6,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(76,7,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(77,8,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(78,9,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(79,10,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(80,11,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(81,12,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(82,13,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(83,14,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(84,15,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(85,16,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(90,6,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(91,7,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(92,8,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(93,9,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(94,10,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(95,11,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(96,12,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(97,13,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(98,14,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(99,15,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(100,16,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(105,6,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(106,7,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(107,8,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(108,9,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(109,10,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(110,11,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(111,12,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(112,13,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(113,14,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(114,15,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(115,16,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 08:56:19','2026-08-21 08:56:19'),(120,1,'Rizal Day Exception — no pickup, moved to next day','Mixed','recurring','none',NULL,NULL,1,'2026-08-23','07:00:00','11:00:00',NULL,NULL,'Published','Holiday notice: moved from Monday regular',1,'2026-08-21 08:56:19','2026-09-06 15:01:44'),(121,1,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(122,2,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(123,4,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(124,3,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(125,5,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(128,1,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(129,2,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(130,4,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(131,3,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(132,5,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(135,6,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(136,7,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(137,8,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(138,9,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(139,10,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(140,11,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(141,12,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(142,13,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(143,14,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(144,15,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(145,16,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(150,6,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(151,7,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(152,8,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(153,9,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(154,10,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(155,11,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(156,12,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(157,13,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(158,14,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(159,15,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(160,16,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(165,6,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(166,7,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(167,8,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(168,9,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(169,10,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(170,11,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(171,12,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(172,13,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(173,14,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(174,15,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(175,16,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 12:36:07','2026-08-21 12:36:07'),(181,1,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(182,2,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(183,4,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(184,3,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(185,5,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(188,1,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(189,2,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(190,4,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(191,3,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(192,5,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(195,6,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(196,7,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(197,8,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(198,9,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(199,10,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(200,11,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(201,12,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(202,13,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(203,14,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(204,15,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(205,16,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(210,6,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(211,7,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(212,8,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(213,9,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(214,10,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(215,11,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(216,12,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(217,13,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(218,14,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(219,15,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(220,16,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(225,6,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(226,7,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(227,8,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(228,9,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(229,10,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(230,11,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(231,12,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(232,13,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(233,14,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(234,15,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(235,16,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 15:54:16','2026-08-21 15:54:16'),(240,1,'Rizal Day Exception — no pickup, moved to next day','Mixed','recurring','none',NULL,NULL,1,'2026-08-23','07:00:00','11:00:00',NULL,NULL,'Published','Holiday notice: moved from Monday regular',1,'2026-08-21 15:54:16','2026-09-06 15:02:16'),(241,1,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(242,2,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(243,4,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(244,3,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(245,5,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(248,1,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(249,2,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(250,4,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(251,3,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(252,5,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(255,6,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(256,7,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(257,8,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(258,9,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(259,10,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(260,11,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(261,12,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(262,13,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(263,14,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(264,15,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(265,16,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(270,6,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(271,7,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(272,8,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(273,9,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(274,10,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(275,11,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(276,12,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(277,13,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(278,14,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(279,15,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(280,16,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(285,6,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(286,7,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(287,8,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(288,9,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(289,10,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(290,11,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(291,12,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(292,13,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(293,14,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(294,15,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(295,16,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-08-21 16:25:16','2026-08-21 16:25:16'),(307,1,'[QA_T3_17f6f26849] Test Spot Beta (Drop-off)','Bulky','regular','none',NULL,NULL,2,NULL,'07:30:00','16:00:00',NULL,NULL,'Published','Auto-generated from Drop-off #210: [QA_T3_17f6f26849] Test Spot Beta',1,'2026-09-06 15:57:44','2026-09-06 16:00:25'),(308,1,'[QA_T3_17f6f26849] Test Spot Beta (Drop-off)','Hazardous','regular','none',NULL,NULL,4,NULL,'09:00:00','12:00:00',NULL,NULL,'Published','Auto-generated from Drop-off #210: [QA_T3_17f6f26849] Test Spot Beta',1,'2026-09-06 15:57:44','2026-09-06 16:00:25'),(316,1,'[QA_T3_79586fd26d] Test Spot Beta (Drop-off)','Bulky','regular','none',NULL,NULL,2,NULL,'07:30:00','16:00:00',NULL,NULL,'Published','Auto-generated from Drop-off #212: [QA_T3_79586fd26d] Test Spot Beta',1,'2026-09-06 16:00:25','2026-09-06 16:02:41'),(317,1,'[QA_T3_79586fd26d] Test Spot Beta (Drop-off)','Hazardous','regular','none',NULL,NULL,4,NULL,'09:00:00','12:00:00',NULL,NULL,'Published','Auto-generated from Drop-off #212: [QA_T3_79586fd26d] Test Spot Beta',1,'2026-09-06 16:00:25','2026-09-06 16:02:41'),(339,2,'New Schedule','Recyclable','regular','none',NULL,NULL,2,NULL,'10:46:00','01:46:00','2026-09-09','2026-09-30','Draft',NULL,1,'2026-09-07 02:46:15','2026-09-07 02:46:15'),(340,1,'New Schedule','Mixed','one_time','none',NULL,NULL,NULL,'2026-09-10','07:56:00','04:52:00','2026-09-09','2026-09-25','Draft',NULL,1,'2026-09-07 06:52:29','2026-09-07 06:52:29'),(341,2,'New Schedule','Biodegradable','one_time','none',NULL,NULL,NULL,'2026-09-10','12:41:00','23:45:00','2026-09-09','2026-09-09','Draft',NULL,1,'2026-09-09 15:41:32','2026-09-09 15:41:32'),(342,2,'New Schedule budots','Mixed','one_time','none',NULL,NULL,NULL,'2026-09-09','12:41:00','23:42:00','2026-09-09','2026-09-09','Published',NULL,1,'2026-09-09 15:42:03','2026-09-09 15:42:03'),(343,1,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(344,2,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(345,4,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(346,3,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(347,5,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(350,1,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(351,2,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(352,4,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(353,3,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(354,5,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(357,6,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(358,7,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(359,8,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(360,9,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(361,10,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(362,11,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(363,12,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(364,13,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(365,14,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(366,15,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(367,16,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(372,6,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(373,7,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(374,8,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(375,9,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(376,10,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(377,11,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(378,12,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(379,13,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(380,14,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(381,15,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(382,16,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(387,6,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(388,7,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(389,8,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(390,9,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(391,10,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(392,11,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(393,12,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(394,13,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(395,14,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(396,15,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(397,16,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-27 07:59:06','2026-09-27 07:59:06'),(402,1,'V1','Mixed','exception','none',NULL,NULL,NULL,'2026-09-29','07:00:00','11:00:00','2026-09-29','2026-09-30','Published','Holiday notice: moved from Monday regular',1,'2026-09-27 07:59:06','2026-09-28 07:11:51'),(403,1,'T1','Biodegradable','one_time','none',NULL,NULL,NULL,'2026-09-30','06:58:00','06:58:00','2026-09-27','2026-09-29','Published',NULL,1,'2026-09-27 12:59:14','2026-09-27 12:59:14'),(404,1,'T2','Mixed','one_time','none',NULL,NULL,NULL,'2026-09-16','07:52:00','08:52:00','2026-09-28','2026-09-28','Draft','T2',1,'2026-09-27 22:53:22','2026-09-27 22:53:22'),(405,1,'V434','Mixed','regular','none',NULL,NULL,1,NULL,'04:59:00','16:59:00','2026-09-28','2026-09-28','Published',NULL,1,'2026-09-28 06:00:12','2026-09-28 06:11:03'),(406,1,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(407,2,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(408,4,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(409,3,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(410,5,'Monday Biodegradable','Biodegradable','regular','none',NULL,NULL,2,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(413,1,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(414,2,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(415,4,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(416,3,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(417,5,'Wednesday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,4,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(420,6,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(421,7,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(422,8,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(423,9,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(424,10,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(425,11,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(426,12,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(427,13,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(428,14,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(429,15,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(430,16,'Friday Recyclable','Recyclable','regular','none',NULL,NULL,6,NULL,'07:00:00','11:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(435,6,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(436,7,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(437,8,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(438,9,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(439,10,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(440,11,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(441,12,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(442,13,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(443,14,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(444,15,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(445,16,'Tuesday Biodegradable','Biodegradable','regular','none',NULL,NULL,3,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(450,6,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(451,7,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(452,8,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(453,9,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(454,10,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(455,11,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(456,12,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(457,13,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(458,14,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(459,15,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(460,16,'Thursday Non-Biodegradable','Non-Biodegradable','regular','none',NULL,NULL,5,NULL,'06:00:00','10:00:00','2026-01-01',NULL,'Published','Regular weekly schedule',1,'2026-09-28 11:27:49','2026-09-28 11:27:49'),(465,1,'Rizal Day Exception — no pickup, moved to next day','Mixed','exception','none',NULL,NULL,NULL,'2026-09-30','07:00:00','11:00:00',NULL,NULL,'Published','Holiday notice: moved from Monday regular',1,'2026-09-28 11:27:49','2026-09-28 11:27:49');
/*!40000 ALTER TABLE `ba_collection_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `ba_dropoff_points`
--

LOCK TABLES `ba_dropoff_points` WRITE;
/*!40000 ALTER TABLE `ba_dropoff_points` DISABLE KEYS */;
INSERT  IGNORE INTO `ba_dropoff_points` (`id`, `barangay_id`, `spot_name`, `address`, `latitude`, `longitude`, `place_osm_id`, `pickup_type`, `open_24_7`, `operation_hours`, `notes_public`, `reference_photo`, `status`, `accepts_bio`, `accepts_nonbio`, `accepts_recyclable`, `accepts_hazard`, `accepts_bulky`, `created_at`, `updated_at`, `created_by_admin_id`, `published_by_admin_id`, `published_at`) VALUES (177,3,'VI','A. Bonifacio Ave. cor. Gen. Ordoñez St., Barangka, Marikina City (end of sidewalk near chapel)',14.6548069,121.0993359,'','BARANGAY_MRF',0,'Mon–Fri: 8:00 AM - 5:00 PM','Biodegradable only on Tue/Thu. Recyclable bins on Sat. Tape all bags shut. Do not leave cardboard here.','https://www.bing.com/images/search?view=detailV2&ccid=7S3Sq0mh&id=A865220F1B4E4D8698978DB120F83F612F4A2287&thid=OIP.7S3Sq0mhzzrCZdCqgWhUUwHaFF&mediaurl=https%3a%2f%2fimg.freepik.com%2fpremium-photo%2fphoto-evening-nature-landscape-with-river-lake-view-beautiful-trees_763111-93160.jpg%3fw%3d2000&exph=1373&expw=2000&q=PHOTO&FORM=IRPRST&ck=2CBCA85D03D0B133B4A96C9259AD8FF9&selectedIndex=1&itb=0&ajaxhist=0&ajaxserp=0','PUBLISHED',1,1,1,0,0,'2026-08-24 13:04:28','2026-09-28 15:15:43',1,1,'2026-08-24 13:04:28'),(178,2,'Calumpang — J.P. Rizal end-curb drop-off','End of J.P. Rizal Ext. cor. A. Mabini St., Calumpang, Marikina City',14.6227532,121.0893212,'','STREET_END_CURBSIDE',1,'24 hours (round the clock)','24/7 accessible curb. Coordinate with Barangay Tanod if bin is full. Bulky items every 1st Saturday of the month only.','','PUBLISHED',1,1,1,0,1,'2026-08-24 13:04:28','2026-09-28 15:44:46',1,1,'2026-08-24 13:04:28'),(179,3,'Concepcion Uno — Barangay Hall MRF','Bayan-Bayanan Ave. cor. Dambana St., Brgy. Concepcion Uno Hall compound',14.6516000,121.1060000,'','BARANGAY_MRF',0,'Mon–Fri 8:00 AM – 5:00 PM ; Sat 8:00 AM – 12:00 NN','Drop-off inside hall compound — register at desk first. Hazardous (batteries, bulbs) accepted Saturdays only.','','PUBLISHED',1,1,1,1,1,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(180,4,'Concepcion Dos — Street-end shared bins','End of Evangelista St. cor. Rosal St., Concepcion Dos',14.6450000,121.1136000,'','SHARED_BIN_CLUSTER',0,'Daily 5:00 AM – 8:00 PM (bins locked overnight)','Separate 3 bins: green=bio, black=nonbio, blue=recycle','','PUBLISHED',1,1,1,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(181,5,'Fortune — SM Cherry Marikina curbside','Marikina-Infanta Hwy side curbside, near Fortune Market entrance',14.6602000,121.1199000,'','STREET_END_CURBSIDE',1,'','Near public market. Clean up any spillage you cause. Glass bottles only in blue bin.','','PUBLISHED',1,1,1,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(182,6,'Industrial Valley — Footbridge underpass bin cluster','Marcos Hwy footbridge underpass near Amang Rodriguez Ave.',14.6160000,121.0913000,'','SHARED_BIN_CLUSTER',1,'','Covered bin cluster (4 bins). Empty schedule Mon-Wed-Fri 05:30','','PUBLISHED',1,1,1,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(183,7,'Jesus Dela Peña — Chapel curb','J.P. Rizal St. cor. Jesus St., Chapel of Our Lady curbside',14.6200000,121.0825000,'','STREET_END_CURBSIDE',0,'Before/after 6:00 AM Daily Mass','Small 40L shared bins. Large items go to barangay MRF on EDSA extension','','PUBLISHED',1,1,0,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(184,8,'Malanday — Sitio Luyang Satellite drop-off','Sitio Luyang corner, Malanday near elementary school gate',14.6423000,121.0795000,'','SHARED_BIN_CLUSTER',1,'','Community-run bin maintenance. Donations for janitor accepted at Sangguniang Kabataan desk.','','PUBLISHED',1,1,1,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(185,9,'Marikina Heights — C&B Circle Mall rear','Bayan-Bayanan Ave. rear service road near C&B Circle Mall loading dock',14.6428000,121.1035000,'','STREET_END_CURBSIDE',0,'Daily 8:00 AM – 10:00 PM (mall hours)','Recyclable cartons & food waste from mall vendors go here. Residents may drop small bags during mall hours.','','PUBLISHED',1,1,1,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(186,10,'Molino — Barangay Covered Court Bulky Yard','P. Burgos St., Barangay Molino covered court side yard',14.6322000,121.0975000,'','BULKY_DROP_OFF_YARD',0,'Sat & Sun only 8:00 AM – 4:00 PM','Bulky items (old furniture, tires, yard trimmings). Schedule truck hauls once monthly — call barangay office +63 (2) 123-4567 for pickup appt.','','PUBLISHED',0,0,0,0,1,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(187,10,'Nangka — Riverbank curbside','Nangka Riverwalk sidewalk end near footbridge to Balubad settlement',14.6677000,121.1094000,'','STREET_END_CURBSIDE',1,'','Do NOT dispose of plastics near the river. Only biodegradable food waste bagged & tagged accepted at this curb.','','PUBLISHED',1,0,0,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(188,11,'Parang — SM City Marikina satellite bins','SM City Marikina basement waste drop, near loadout bay 3',14.6193000,121.0990000,'','SHARED_BIN_CLUSTER',0,'Mall hours 10:00 AM – 10:00 PM daily','Hazardous battery drop bin (expired AA/AAA, laptop, phone) available at concierge desk on GF — 2F drop is regular waste only.','','PUBLISHED',1,1,1,1,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(189,12,'San Roque — Marikina Market curbside','Marikina Public Market, side curbside of wet section near fish vendors',14.6260000,121.0930000,'','STREET_END_CURBSIDE',0,'Market hours: Tue–Sun 4:00 AM – 6:00 PM (closed Mondays)','Market waste (fish scales, vegetable peelings) composted on-site. Residents can drop biodegradable bags during open hours only.','','PUBLISHED',1,1,1,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(190,13,'Santa Elena — City Heritage Zone curb','Marikina Shoe Museum sidewalk cor. Captain Enriquez St.',14.6284000,121.0877000,'','HAZARDOUS_SATELLITE',0,'Tue & Thu 9:00 AM – 3:00 PM','Special waste only: expired medicines (in original packaging), button batteries, CFL bulbs. Seal in zip bag. City Environment Office attends this satellite point.','','PUBLISHED',0,0,0,1,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(191,14,'Santo Niño — LRT Station satellite','Marikina LRT 2 Station sidewalk (Bayan-Bayanan Ave.) east exit',14.6383000,121.0999000,'','SHARED_BIN_CLUSTER',1,'','Commuter-focused small bin station. No bulky items. Cigarette butts go into the red ash can only.','','PUBLISHED',0,1,1,0,0,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(192,15,'Tañong — City Public MRF (Central)','City of Marikina Central Materials Recovery Facility (MRF) Compound, Tañong',14.6166000,121.0880000,'','BARANGAY_MRF',0,'Mon–Sat 7:00 AM – 5:00 PM','FULL city MRF — accepts EVERY waste class. Call ahead (1628 Marikina Hotline) for large commercial loads. Bulky yard & e-waste accepted on-site.','','PUBLISHED',1,1,1,1,1,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(193,8,'Malanday — Bulky Drop-off Yard (satellite)','West Malanday corner, Sitio Guayama Open lot',14.6470000,121.0720000,'','BULKY_DROP_OFF_YARD',0,'Sun only 8:00 AM – 12:00 NN','Yard trimmings, pruned branches, old furniture dismantled only. No drums / construction debris.','','PUBLISHED',0,0,0,0,1,'2026-08-24 13:04:28','2026-08-24 13:04:28',1,1,'2026-08-24 13:04:28'),(197,11,'dyan','SM City Marikina basement waste drop, near loadout bay 3',14.6234697,121.1000758,'','STREET_END_CURBSIDE',0,'Mon–Fri: 8:00 AM - 5:00 PM','','','PUBLISHED',1,1,1,0,0,'2026-08-24 14:46:02','2026-08-31 12:47:50',1,1,'2026-08-31 12:47:50'),(199,12,'kanto','P. Burgos St., Barangay Molino covered court side yard',14.6286915,121.1005430,'','BARANGAY_MRF',0,'Mon–Fri: 8:00 AM - 5:00 PM','','','PUBLISHED',1,1,1,0,0,'2026-08-24 14:50:19','2026-09-28 15:44:55',1,1,'2026-08-24 14:50:59'),(200,9,'dyan','P. Burgos St., Barangay Molino covered court side yard',14.6380650,121.1061796,'','STREET_END_CURBSIDE',0,'Mon–Fri: 8:00 AM - 5:00 PM','','','DRAFT',1,1,1,1,0,'2026-08-24 14:51:13','2026-08-24 14:52:28',1,1,'2026-08-24 14:51:42'),(201,11,'SM City','SM City Marikina basement waste drop, near loadout bay 3',14.6244720,121.0998185,'','STREET_END_CURBSIDE',0,'Mon–Fri: 8:00 AM - 5:00 PM','','','PUBLISHED',1,1,1,0,0,'2026-08-31 12:47:19','2026-09-07 15:13:25',1,1,'2026-09-07 15:13:25'),(204,11,'SA WATER STATION','SM City Marikina basement waste drop, near loadout bay 3',14.6228073,121.0999375,'','SHARED_BIN_CLUSTER',0,'Mon–Fri: 8:00 AM - 5:00 PM','','','PUBLISHED',1,1,1,0,0,'2026-09-01 20:50:45','2026-09-01 20:51:32',1,1,'2026-09-01 20:51:32'),(218,3,'Csfsfsdfsd','Csfsfsdfsd',14.6574697,121.1028445,'','STREET_END_CURBSIDE',0,'Mon–Fri: 8:00 AM - 5:00 PM','','https://img.freepik.com/premium-photo/photo-evening-nature-landscape-with-river-lake-view-beautiful-trees_763111-93160.jpg?w=2000','PUBLISHED',1,1,1,0,0,'2026-09-28 07:18:11','2026-09-28 15:27:55',1,1,'2026-09-28 15:27:55');
/*!40000 ALTER TABLE `ba_dropoff_points` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `ba_dropoff_schedules`
--

LOCK TABLES `ba_dropoff_schedules` WRITE;
/*!40000 ALTER TABLE `ba_dropoff_schedules` DISABLE KEYS */;
INSERT  IGNORE INTO `ba_dropoff_schedules` (`id`, `dropoff_id`, `day_of_week`, `waste_type`, `linked_type`, `linked_id`, `time_start`, `time_end`, `effective_from`, `effective_to`, `created_at`) VALUES (284,179,6,'Hazardous','none',NULL,'08:00:00','12:00:00',NULL,NULL,'2026-08-24 13:04:28'),(285,180,1,'Biodegradable','none',NULL,'06:00:00','08:00:00',NULL,NULL,'2026-08-24 13:04:28'),(286,180,2,'Non-Biodegradable','none',NULL,'06:00:00','08:00:00',NULL,NULL,'2026-08-24 13:04:28'),(287,180,4,'Recyclable','none',NULL,'06:00:00','08:00:00',NULL,NULL,'2026-08-24 13:04:28'),(288,182,1,'Biodegradable','none',NULL,'05:30:00','07:00:00',NULL,NULL,'2026-08-24 13:04:28'),(289,182,3,'Non-Biodegradable','none',NULL,'05:30:00','07:00:00',NULL,NULL,'2026-08-24 13:04:28'),(290,182,5,'Recyclable','none',NULL,'05:30:00','07:00:00',NULL,NULL,'2026-08-24 13:04:28'),(291,184,2,'Biodegradable','none',NULL,'06:30:00','08:00:00',NULL,NULL,'2026-08-24 13:04:28'),(292,184,5,'Non-Biodegradable','none',NULL,'06:30:00','08:00:00',NULL,NULL,'2026-08-24 13:04:28'),(293,186,6,'Bulky','none',NULL,'08:00:00','16:00:00',NULL,NULL,'2026-08-24 13:04:28'),(294,186,0,'Bulky','none',NULL,'08:00:00','16:00:00',NULL,NULL,'2026-08-24 13:04:28'),(295,189,2,'Biodegradable','none',NULL,'05:00:00','08:00:00',NULL,NULL,'2026-08-24 13:04:28'),(296,189,4,'Biodegradable','none',NULL,'05:00:00','08:00:00',NULL,NULL,'2026-08-24 13:04:28'),(297,189,6,'Biodegradable','none',NULL,'05:00:00','08:00:00',NULL,NULL,'2026-08-24 13:04:28'),(298,190,2,'Hazardous','none',NULL,'09:00:00','15:00:00',NULL,NULL,'2026-08-24 13:04:28'),(299,190,4,'Special','none',NULL,'09:00:00','15:00:00',NULL,NULL,'2026-08-24 13:04:28'),(300,192,1,'Biodegradable','none',NULL,'07:00:00','17:00:00',NULL,NULL,'2026-08-24 13:04:28'),(301,192,2,'Non-Biodegradable','none',NULL,'07:00:00','17:00:00',NULL,NULL,'2026-08-24 13:04:28'),(302,192,3,'Recyclable','none',NULL,'07:00:00','17:00:00',NULL,NULL,'2026-08-24 13:04:28'),(303,192,4,'Hazardous','none',NULL,'07:00:00','17:00:00',NULL,NULL,'2026-08-24 13:04:28'),(304,192,5,'Bulky','none',NULL,'07:00:00','17:00:00',NULL,NULL,'2026-08-24 13:04:28'),(305,192,6,'Special','none',NULL,'07:00:00','12:00:00',NULL,NULL,'2026-08-24 13:04:28'),(306,193,0,'Bulky','none',NULL,'08:00:00','12:00:00',NULL,NULL,'2026-08-24 13:04:28');
/*!40000 ALTER TABLE `ba_dropoff_schedules` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-28 19:28:34

SET FOREIGN_KEY_CHECKS=1;
