<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

/* CLI-only, and this replaces the old "no ?confirm=1" gate. That gate was the
   only thing protecting a script which issues ALTER TABLE, and ?confirm=1 was
   enough to satisfy it from anywhere on the internet. There is no legitimate
   reason to run a schema migration from a browser. */
require_maintenance_authorization(null, 'BasuraAlert Migration');

if (PHP_SAPI !== 'cli' && !isset($_GET['confirm'])) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $confirmUrl = app_url('/_run_basuraalert_migration.php') . '?confirm=1';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>BasuraAlert Migration</title></head><body style="font-family:system-ui,Segoe UI,Arial;padding:2rem;max-width:720px;margin:0 auto">';
    echo '<h2>BasuraAlert Phase 1 Migration</h2>';
    echo '<p>This script will (idempotently) create all BasuraAlert tables, add the <code>users.barangay</code> column, and seed mock data.</p>';
    echo '<p>Read the SQL at <code>database/basuraalert_migration.sql</code> before running.</p>';
    echo '<form method="GET" action="', e($confirmUrl), '"><button type="submit" style="padding:.6rem 1.2rem;font-size:1rem;background:#0d6efd;color:#fff;border:0;border-radius:.375rem;cursor:pointer">Run BasuraAlert Migration</button></form>';
    echo '</body></html>';
    exit;
}

$sqlFile = __DIR__ . '/database/basuraalert_migration.sql';
if (!is_file($sqlFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Migration file not found: ' . $sqlFile);
}

$rawSql = (string) file_get_contents($sqlFile);

$db = db();

$barangayColExists = false;
$barangayIdxExists = false;
$baFaqsUpdatedAtExists = false;
$reportsInternalNoteExists = false;
$timelineIsInternalExists = false;
$timelineInternalNoteExists = false;
$baReportRatingsExists = false;
$baDropoffPointsExists = false;
$baDropoffSchedulesExists = false;
$authPasswordResetsExists = false;
$feedbackPhotosJsonExists = false;
try {
    $colCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'barangay' LIMIT 1");
    if ($colCheck instanceof mysqli_result) {
        $r = $colCheck->fetch_assoc();
        $barangayColExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $idxCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_barangay' LIMIT 1");
    if ($idxCheck instanceof mysqli_result) {
        $r = $idxCheck->fetch_assoc();
        $barangayIdxExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $faqColCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_faqs' AND COLUMN_NAME = 'updated_at' LIMIT 1");
    if ($faqColCheck instanceof mysqli_result) {
        $r = $faqColCheck->fetch_assoc();
        $baFaqsUpdatedAtExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $rColCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_reports' AND COLUMN_NAME = 'internal_handling_note' LIMIT 1");
    if ($rColCheck instanceof mysqli_result) {
        $r = $rColCheck->fetch_assoc();
        $reportsInternalNoteExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $tIntCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_report_timeline' AND COLUMN_NAME = 'is_internal' LIMIT 1");
    if ($tIntCheck instanceof mysqli_result) {
        $r = $tIntCheck->fetch_assoc();
        $timelineIsInternalExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $tNoteCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_report_timeline' AND COLUMN_NAME = 'internal_note' LIMIT 1");
    if ($tNoteCheck instanceof mysqli_result) {
        $r = $tNoteCheck->fetch_assoc();
        $timelineInternalNoteExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $ratingsTblCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_report_ratings' LIMIT 1");
    if ($ratingsTblCheck instanceof mysqli_result) {
        $r = $ratingsTblCheck->fetch_assoc();
        $baReportRatingsExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $dropoffPointsCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_dropoff_points' LIMIT 1");
    if ($dropoffPointsCheck instanceof mysqli_result) {
        $r = $dropoffPointsCheck->fetch_assoc();
        $baDropoffPointsExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $dropoffSchedulesCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_dropoff_schedules' LIMIT 1");
    if ($dropoffSchedulesCheck instanceof mysqli_result) {
        $r = $dropoffSchedulesCheck->fetch_assoc();
        $baDropoffSchedulesExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $authPwResetCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_password_resets' LIMIT 1");
    if ($authPwResetCheck instanceof mysqli_result) {
        $r = $authPwResetCheck->fetch_assoc();
        $authPasswordResetsExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
    $fbPhotosCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_feedback' AND COLUMN_NAME = 'photos_json' LIMIT 1");
    if ($fbPhotosCheck instanceof mysqli_result) {
        $r = $fbPhotosCheck->fetch_assoc();
        $feedbackPhotosJsonExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
} catch (Throwable $_) {
}

$alterUsersColumnOnly = null;
$alterUsersIndexOnly = null;
$alterFaqsUpdatedAt = null;
$alterReportsInternalNote = null;
$alterTimelineIsInternal = null;
$alterTimelineInternalNote = null;
$createReportRatings = null;
$createDropoffPoints = null;
$createDropoffSchedules = null;
$createAuthPasswordResets = null;
$alterFeedbackPhotosJson = null;
$alterPrefsEvColumns = [];

if (!$barangayColExists) {
    $alterUsersColumnOnly = "ALTER TABLE users ADD COLUMN barangay VARCHAR(120) NULL DEFAULT NULL COMMENT 'Resident barangay for BasuraAlert schedule targeting' AFTER mobile";
}
if (!$barangayIdxExists) {
    $alterUsersIndexOnly = "ALTER TABLE users ADD KEY idx_users_barangay (barangay)";
}
if (!$baFaqsUpdatedAtExists) {
    $tblExists = false;
    try {
        $tCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_faqs' LIMIT 1");
        if ($tCheck instanceof mysqli_result) {
            $r = $tCheck->fetch_assoc();
            $tblExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
        }
    } catch (Throwable $_) {
    }
    if ($tblExists) {
        $alterFaqsUpdatedAt = "ALTER TABLE ba_faqs ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at";
    }
}

$baReportsExists = false;
try {
    $tCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_reports' LIMIT 1");
    if ($tCheck instanceof mysqli_result) {
        $r = $tCheck->fetch_assoc();
        $baReportsExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
} catch (Throwable $_) {
}
if ($baReportsExists && !$reportsInternalNoteExists) {
    $alterReportsInternalNote = "ALTER TABLE ba_reports ADD COLUMN internal_handling_note TEXT NULL COMMENT '[ADMIN ONLY] Private internal handling note, never shown to residents' AFTER resolution_note";
}

$baTimelineExists = false;
try {
    $tCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_report_timeline' LIMIT 1");
    if ($tCheck instanceof mysqli_result) {
        $r = $tCheck->fetch_assoc();
        $baTimelineExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
} catch (Throwable $_) {
}
if ($baTimelineExists) {
    if (!$timelineIsInternalExists) {
        $alterTimelineIsInternal = "ALTER TABLE ba_report_timeline ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = admin-only internal timeline entry (hidden from resident)' AFTER admin_id";
    }
    if (!$timelineInternalNoteExists) {
        $alterTimelineInternalNote = "ALTER TABLE ba_report_timeline ADD COLUMN internal_note TEXT NULL COMMENT '[ADMIN ONLY] Internal note body, shown only on admin detail view' AFTER is_internal";
    }
}

if (!$baReportRatingsExists) {
    $createReportRatings = "CREATE TABLE ba_report_ratings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        user_id INT NOT NULL,
        rating TINYINT NOT NULL COMMENT '1-5 helpfulness star rating',
        comment VARCHAR(1000) NULL DEFAULT NULL,
        rated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rating_report (report_id),
        KEY idx_rating_user (user_id),
        UNIQUE KEY uk_rating_report_user (report_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Resident helpfulness rating on closed/rejected reports'";
}

if (!$baDropoffPointsExists) {
    $createDropoffPoints = 'CREATE TABLE ba_dropoff_points (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        barangay_id INT UNSIGNED NOT NULL,
        spot_name VARCHAR(255) NOT NULL COMMENT "Friendly name shown to residents e.g. End of J.P. Rizal St. curb",
        address VARCHAR(500) NOT NULL COMMENT "Full street address / zone for this drop-off",
        latitude DECIMAL(10,7) NOT NULL COMMENT "WGS84 latitude (OSM Nominatim pin)",
        longitude DECIMAL(10,7) NOT NULL COMMENT "WGS84 longitude (OSM Nominatim pin)",
        place_osm_id VARCHAR(64) NULL DEFAULT NULL COMMENT "Optional OSM place id for Nominatim policy audit trail",
        pickup_type ENUM("STREET_END_CURBSIDE","BARANGAY_MRF","SHARED_BIN_CLUSTER","BULKY_DROP_OFF_YARD","HAZARDOUS_SATELLITE","OTHER") NOT NULL DEFAULT "STREET_END_CURBSIDE",
        open_24_7 TINYINT(1) NOT NULL DEFAULT 0 COMMENT "0 = schedule-only, 1 = always accessible curb",
        operation_hours VARCHAR(255) NULL DEFAULT NULL COMMENT "Free-text operation hours if not 24/7",
        notes_public VARCHAR(1000) NULL DEFAULT NULL COMMENT "Resident-facing note shown on map popup",
        reference_photo VARCHAR(500) NULL DEFAULT NULL COMMENT "Cloudinary URL (Phase2) or local path (Phase1 mock)",
        status ENUM("DRAFT","PUBLISHED","TEMPORARILY_CLOSED") NOT NULL DEFAULT "PUBLISHED",
        accepts_bio TINYINT(1) NOT NULL DEFAULT 1 COMMENT "Accepts Biodegradable waste?",
        accepts_nonbio TINYINT(1) NOT NULL DEFAULT 1 COMMENT "Accepts Non-biodegradable waste?",
        accepts_recyclable TINYINT(1) NOT NULL DEFAULT 1 COMMENT "Accepts Recyclable waste?",
        accepts_hazard TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Accepts Hazardous/Special waste?",
        accepts_bulky TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Accepts Bulky/Uncollected yard waste?",
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
        published_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
        published_at DATETIME NULL DEFAULT NULL,
        INDEX idx_dropoff_barangay (barangay_id),
        INDEX idx_dropoff_status (status),
        INDEX idx_dropoff_lat_lon (latitude, longitude)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Official Marikina waste drop-off points shown on the resident map view"';
}

if (!$baDropoffSchedulesExists) {
    $createDropoffSchedules = 'CREATE TABLE ba_dropoff_schedules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dropoff_id INT UNSIGNED NOT NULL,
        day_of_week TINYINT(1) NOT NULL COMMENT "0 Sunday .. 6 Saturday",
        waste_type ENUM("Biodegradable","Non-Biodegradable","Recyclable","Hazardous","Special","Bulky") NOT NULL,
        time_start TIME NOT NULL,
        time_end TIME NOT NULL,
        effective_from DATE NULL DEFAULT NULL,
        effective_to DATE NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dropoff_sched_dropoff (dropoff_id),
        CONSTRAINT fk_ba_sched_dropoff FOREIGN KEY (dropoff_id) REFERENCES ba_dropoff_points(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Special per-drop-off, per-day collection windows (overrides barangay default)"';
}

if (!$authPasswordResetsExists) {
    $createAuthPasswordResets = 'CREATE TABLE user_password_resets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL COMMENT "FK to users.id",
        token_hash CHAR(64) NOT NULL COMMENT "SHA-256 of the raw 64-hex token emailed to user",
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL DEFAULT NULL COMMENT "Set to NOW() once reset successfully applied; NULL = usable",
        requested_ip VARCHAR(45) NULL DEFAULT NULL COMMENT "Client IPv4/IPv6 the reset was requested from (audit)",
        consumed_ip VARCHAR(45) NULL DEFAULT NULL COMMENT "Client IP that consumed the token (audit)",
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_pwreset_token_hash (token_hash),
        INDEX idx_pwreset_user (user_id),
        INDEX idx_pwreset_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Self-serve password-reset challenge tokens sent via email link"';
}

$baFeedbackExists = false;
try {
    $tCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_feedback' LIMIT 1");
    if ($tCheck instanceof mysqli_result) {
        $r = $tCheck->fetch_assoc();
        $baFeedbackExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
} catch (Throwable $_) {
}
if ($baFeedbackExists && !$feedbackPhotosJsonExists) {
    $alterFeedbackPhotosJson = "ALTER TABLE ba_feedback ADD COLUMN photos_json JSON NULL COMMENT 'Optional uploaded image evidence (relative upload paths, JSON array)' AFTER message";
}

$baPrefsExists = false;
try {
    $tCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' LIMIT 1");
    if ($tCheck instanceof mysqli_result) {
        $r = $tCheck->fetch_assoc();
        $baPrefsExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
} catch (Throwable $_) {
}
if ($baPrefsExists) {
    $eventTypes = ['reminder','schedule_change','announcement','report_submit','report_update','feedback_reply'];
    $channels = ['in_app','email','sms'];
    foreach ($eventTypes as $ev) {
        foreach ($channels as $ch) {
            $colName = 'ev_' . $ev . '_' . $ch;
            $exists = false;
            try {
                $cCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_notification_preferences' AND COLUMN_NAME = '" . $db->real_escape_string($colName) . "' LIMIT 1");
                if ($cCheck instanceof mysqli_result) {
                    $r = $cCheck->fetch_assoc();
                    $exists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
                }
            } catch (Throwable $_) {
            }
            if (!$exists) {
                $alterPrefsEvColumns[] = "ALTER TABLE ba_notification_preferences ADD COLUMN " . $colName . " TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Override: send event=$ev via channel=$ch (1=on, 0=off)' AFTER reminder_hours_before";
            }
        }
    }
}

$statements = [];
if ($alterUsersColumnOnly !== null) {
    $statements[] = $alterUsersColumnOnly;
}
if ($alterUsersIndexOnly !== null) {
    $statements[] = $alterUsersIndexOnly;
}
if ($alterFaqsUpdatedAt !== null) {
    $statements[] = $alterFaqsUpdatedAt;
}
if ($alterReportsInternalNote !== null) {
    $statements[] = $alterReportsInternalNote;
}
if ($alterTimelineIsInternal !== null) {
    $statements[] = $alterTimelineIsInternal;
}
if ($alterTimelineInternalNote !== null) {
    $statements[] = $alterTimelineInternalNote;
}
if ($createReportRatings !== null) {
    $statements[] = $createReportRatings;
}
if ($createDropoffPoints !== null) {
    $statements[] = $createDropoffPoints;
}
if ($createDropoffSchedules !== null) {
    $statements[] = $createDropoffSchedules;
}
if ($createAuthPasswordResets !== null) {
    $statements[] = $createAuthPasswordResets;
}
if ($alterFeedbackPhotosJson !== null) {
    $statements[] = $alterFeedbackPhotosJson;
}
foreach ($alterPrefsEvColumns as $alterPref) {
    $statements[] = $alterPref;
}

function _split_sql_statements(string $sql): array
{
    $len = strlen($sql);
    $out = [];
    $buf = '';
    $i = 0;
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;
    $bufHasNonComment = false;
    while ($i < $len) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';
        if ($inLineComment) {
            $buf .= $ch;
            if ($ch === "\n" || $ch === "\r") {
                $inLineComment = false;
                if (!$bufHasNonComment && trim(str_replace(["\r", "\n"], '', preg_replace('/--.*$/m', '', $buf) ?? '')) === '') {
                    $buf = '';
                }
            }
            $i++;
            continue;
        }
        if ($inBlockComment) {
            $buf .= $ch;
            if ($ch === '*' && $next === '/') {
                $buf .= $next;
                $inBlockComment = false;
                if (!$bufHasNonComment && trim(preg_replace('|/\*.*?\*/|s', '', $buf) ?? '') === '') {
                    $buf = '';
                }
                $i += 2;
                continue;
            }
            $i++;
            continue;
        }
        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($ch === '-' && $next === '-') {
                $peek = ($i + 2 < $len) ? $sql[$i + 2] : ' ';
                if ($peek === ' ' || $peek === "\t" || $peek === "\n" || $peek === "\r") {
                    $inLineComment = true;
                    $buf .= $ch;
                    $i++;
                    continue;
                }
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $buf .= $ch . $next;
                $i += 2;
                continue;
            }
        }
        if (!$inSingle && !$inDouble && !$inBacktick && !ctype_space($ch)) {
            $bufHasNonComment = true;
        }
        if (!$inDouble && !$inBacktick && $ch === "'") {
            $buf .= $ch;
            if ($inSingle && $next === "'") {
                $buf .= $next;
                $i += 2;
                continue;
            }
            $inSingle = !$inSingle;
            $i++;
            continue;
        }
        if (!$inSingle && !$inBacktick && $ch === '"') {
            $buf .= $ch;
            if ($inDouble && $next === '"') {
                $buf .= $next;
                $i += 2;
                continue;
            }
            $inDouble = !$inDouble;
            $i++;
            continue;
        }
        if (!$inSingle && !$inDouble && $ch === '`') {
            $buf .= $ch;
            $inBacktick = !$inBacktick;
            $i++;
            continue;
        }
        if (!$inSingle && !$inDouble && !$inBacktick && $ch === ';') {
            $buf = trim($buf);
            if ($buf !== '') {
                $out[] = $buf;
            }
            $buf = '';
            $bufHasNonComment = false;
            $i++;
            continue;
        }
        $buf .= $ch;
        $i++;
    }
    $buf = trim($buf);
    if ($buf !== '') {
        $stripped = preg_replace('|/\*.*?\*/|s', '', $buf) ?? $buf;
        $stripped = preg_replace('/--.*$/m', '', $stripped);
        if (trim((string) $stripped) !== '') {
            $out[] = $buf;
        }
    }
    return $out;
}

$tokens = _split_sql_statements($rawSql);

foreach ($tokens as $s) {
    $stripped = preg_replace('|/\*.*?\*/|s', '', $s) ?? $s;
    $stripped = preg_replace('/--.*$/m', '', (string) $stripped);
    $stripped = trim((string) $stripped);
    if ($stripped === '') {
        continue;
    }
    if (stripos($stripped, 'ALTER TABLE users') === 0 && (stripos($stripped, 'ADD COLUMN barangay') !== false || stripos($stripped, 'ADD KEY idx_users_barangay') !== false)) {
        continue;
    }
    $statements[] = $s;
}

$ok = 0;
$fail = 0;
$errors = [];

foreach ($statements as $i => $stmt) {
    try {
        $res = $db->query($stmt);
        if ($res === false) {
            $fail++;
            $errors[] = sprintf('Statement #%d failed: %s', $i + 1, $db->error);
        } else {
            $ok++;
        }
    } catch (mysqli_sql_exception $e) {
        $fail++;
        $errors[] = sprintf('Statement #%d exception: %s', $i + 1, $e->getMessage());
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "BasuraAlert migration complete.\n";
echo "Successful statements: {$ok}\n";
echo "Failed statements: {$fail}\n";
if (count($errors) > 0) {
    echo "\nErrors (most are ignorable for idempotent re-runs — e.g. Duplicate column/key names):\n";
    foreach ($errors as $e) {
        echo " - {$e}\n";
    }
}
echo "\nDone. You may now open the BasuraAlert module from the client portal or admin portal.";
