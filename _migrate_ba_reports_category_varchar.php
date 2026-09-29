<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

/* This script had NO guard at all - a plain GET ran a live schema migration and
   answered HTTP 200 to anyone. Now it is CLI-only, like every other maintenance
   script in the root. */
require_maintenance_authorization(null, 'ba_reports.category migration');

$db = db();

$headerSent = PHP_SAPI === 'cli';
if (!$headerSent) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
}

echo "BasuraAlert — ba_reports.category ENUM→VARCHAR migration\n";
echo "========================================================\n\n";

$baReportsExists = false;
try {
    $tCheck = $db->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_reports' LIMIT 1");
    if ($tCheck instanceof mysqli_result) {
        $r = $tCheck->fetch_assoc();
        $baReportsExists = is_array($r) && (int) ($r['c'] ?? 0) > 0;
    }
} catch (Throwable $_) {
}

if (!$baReportsExists) {
    echo "[SKIP] ba_reports table does not exist yet — no migration needed.\n";
    exit(0);
}

$colTypeCheck = null;
try {
    $colCheck = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ba_reports' AND COLUMN_NAME = 'category'
        LIMIT 1");
    if ($colCheck instanceof mysqli_result) {
        $r = $colCheck->fetch_assoc();
        if (is_array($r)) $colTypeCheck = $r;
    }
} catch (Throwable $e) {
    echo "[WARN] Could not inspect ba_reports.category column: " . $e->getMessage() . "\n";
}

if ($colTypeCheck !== null) {
    echo "[INFO] Current ba_reports.category COLUMN_TYPE = " . ($colTypeCheck['COLUMN_TYPE'] ?? 'null') . "\n";
    echo "[INFO] IS_NULLABLE = " . ($colTypeCheck['IS_NULLABLE'] ?? 'null') . " | DEFAULT = " . var_export($colTypeCheck['COLUMN_DEFAULT'], true) . "\n\n";
}

$alterSql = "ALTER TABLE ba_reports MODIFY COLUMN category VARCHAR(60) NOT NULL DEFAULT 'Other_Waste_Concern' COMMENT 'Resident-chosen report category (raw key; label via PHP ba_report_category_options)'";
$migrateEmptySql = "UPDATE ba_reports SET category = 'Other_Waste_Concern' WHERE category = '' OR category IS NULL";

echo "[RUN] " . $alterSql . "\n";
try {
    $ok = $db->query($alterSql);
    echo ($ok ? "[OK] Alter applied.\n" : "[FAIL] Alter failed: " . $db->error . "\n");
} catch (Throwable $e) {
    echo "[FAIL] Alter exception: " . $e->getMessage() . "\n";
}

echo "\n[RUN] Clean up empty/NULL categories -> fallback Other_Waste_Concern\n";
try {
    $cleaned = 0;
    $res = $db->query($migrateEmptySql);
    if ($res === true) {
        $cleaned = (int) $db->affected_rows;
    }
    echo "[OK] Rows cleaned: {$cleaned}\n";
} catch (Throwable $e) {
    echo "[WARN] Clean failed: " . $e->getMessage() . "\n";
}

$countCheck = null;
try {
    $cRes = $db->query("SELECT category, COUNT(*) AS c FROM ba_reports GROUP BY category ORDER BY c DESC");
    if ($cRes instanceof mysqli_result) {
        $countCheck = [];
        while ($row = $cRes->fetch_assoc()) $countCheck[] = $row;
    }
} catch (Throwable $_) {
}
if ($countCheck !== null && count($countCheck) > 0) {
    echo "\n[INFO] Current ba_reports category distribution:\n";
    foreach ($countCheck as $row) {
        echo "  - " . var_export($row['category'], true) . " : " . (int)$row['c'] . "\n";
    }
}

echo "\nDone.\n";
