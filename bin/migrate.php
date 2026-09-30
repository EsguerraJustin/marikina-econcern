<?php
declare(strict_types=1);

/**
 * Marikina E-Concern — Unified Migration Runner (Option C)
 * Usage:
 *   php bin/migrate.php              # apply pending migrations
 *   php bin/migrate.php --status     # show applied / pending
 *   php bin/migrate.php --dry-run    # show SQL without executing
 *   php bin/migrate.php --fresh      # drop & re-create from schema.sql (DANGEROUS)
 *
 * Order is defined in PROJECT_GUIDE.md §8 and mirrors production:
 *   1 schema.sql → 2 auth_security → 3 citizen_management → 4 admin → 5 basuraalert
 *   → 6 sync_departments → 7 20260906_tier3 → 8 citizen_archive
 *   → 9 20260927_notification_delivery_columns → 10 20260928_infinityfree_baseline
 *   → 11 20260929_concern_message_notification
 *
 * New migrations MUST be appended to $MIGRATIONS (never inserted mid-list) and
 * MUST be written idempotently: bin/migrate.php skips a whole file by name once
 * it is recorded in the `migrations` table, so editing an already-applied file
 * only helps `--fresh` runs. Guard every DDL statement with an
 * INFORMATION_SCHEMA existence check.
 *
 * Individual legacy runners (_run_basuraalert_migration.php etc) remain for manual web use,
 * but this CLI is the canonical path for local XAMPP and CI.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$MIGRATIONS = [
    'schema'             => 'database/schema.sql',
    'auth_security'      => 'database/auth_security_migration.sql',
    'citizen_management' => 'database/citizen_management_migration.sql',
    'admin'              => 'database/admin_migration.sql',
    'basuraalert'        => 'database/basuraalert_migration.sql',
    'sync_departments'   => 'database/sync_departments.sql',
    'tier3_linkage'      => 'database/20260906_tier3_linkage.sql',
    'citizen_archive'    => 'database/citizen_archive_migration.sql',
    'notif_delivery_cols' => 'database/20260927_notification_delivery_columns.sql',
    'infinityfree_baseline' => 'database/20260928_infinityfree_baseline.sql',
    'concern_message_notification' => 'database/20260929_concern_message_notification.sql',
];

$args = $argv ?? [];
$wantStatus = in_array('--status', $args, true);
$wantDryRun = in_array('--dry-run', $args, true);
$wantFresh  = in_array('--fresh', $args, true);
$wantHelp   = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($wantHelp) {
    echo "Usage: php bin/migrate.php [--status] [--dry-run] [--fresh]\n";
    echo "  --status   Show applied / pending migrations (does not execute)\n";
    echo "  --dry-run  Show SQL that would be executed\n";
    echo "  --fresh    Drop all tables and re-import schema.sql (requires --fresh confirmation)\n";
    exit(0);
}

function split_sql(string $sql): array
{
    $len = strlen($sql);
    $out = []; $buf = ''; $i = 0;
    $inSingle = false; $inDouble = false; $inBacktick = false;
    $inLineComment = false; $inBlockComment = false;
    $hasContent = false;
    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i+1] : '';
        if ($inLineComment) {
            $buf .= $ch;
            if ($ch === "\n" || $ch === "\r") { $inLineComment = false; if (!$hasContent && trim(preg_replace('/--.*$/m', '', $buf) ?? '') === '') $buf=''; }
            $i++; continue;
        }
        if ($inBlockComment) {
            $buf .= $ch;
            if ($ch === '*' && $next === '/') { $buf .= $next; $inBlockComment=false; if (!$hasContent && trim(preg_replace('|/\*.*?\*/|s', '', $buf) ?? '') === '') $buf=''; $i+=2; continue; }
            $i++; continue;
        }
        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($ch==='-' && $next==='-') { $peek=$i+2<$len?$sql[$i+2]:' '; if ($peek===' '||$peek==="\t"||$peek==="\n"||$peek==="\r") { $inLineComment=true; $buf.=$ch; $i++; continue; } }
            if ($ch==='/' && $next==='*') { $inBlockComment=true; $buf.=$ch.$next; $i+=2; continue; }
        }
        if (!ctype_space($ch) && !$inSingle && !$inDouble && !$inBacktick) $hasContent=true;
        if (!$inDouble && !$inBacktick && $ch==="'") { $buf.=$ch; if ($inSingle && $next==="'") { $buf.=$next; $i+=2; continue; } $inSingle=!$inSingle; $i++; continue; }
        if (!$inSingle && !$inBacktick && $ch==='"') { $buf.=$ch; if ($inDouble && $next=='"') { $buf.=$next; $i+=2; continue; } $inDouble=!$inDouble; $i++; continue; }
        if (!$inSingle && !$inDouble && $ch==='`') { $buf.=$ch; $inBacktick=!$inBacktick; $i++; continue; }
        if (!$inSingle && !$inDouble && !$inBacktick && $ch===';') { $buf=trim($buf); if ($buf!=='') $out[]=$buf; $buf=''; $hasContent=false; $i++; continue; }
        $buf.=$ch; $i++;
    }
    $buf=trim($buf); if ($buf!=='') { $stripped=preg_replace('|/\*.*?\*/|s','',$buf)??$buf; $stripped=preg_replace('/--.*$/m','',$stripped); if (trim((string)$stripped)!=='') $out[]=$buf; }
    return $out;
}

function ensure_migrations_table(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        batch INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Applied migration tracker for bin/migrate.php'");
}

function get_applied(mysqli $db): array
{
    $applied = [];
    $res = $db->query("SELECT name FROM migrations ORDER BY id ASC");
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) $applied[] = (string)$row['name'];
        $res->free();
    }
    return $applied;
}

$projectRoot = dirname(__DIR__);

if ($wantFresh) {
    echo "⚠️  --fresh will DROP ALL TABLES in `" . DB_NAME . "` and re-import schema.sql\n";
    echo "Type 'yes' to confirm: ";
    $handle = fopen("php://stdin", "r");
    $line = $handle ? trim(fgets($handle) ?: '') : '';
    if ($handle) fclose($handle);
    if ($line !== 'yes') { echo "Aborted.\n"; exit(1); }
    $db = db();
    $db->query("SET FOREIGN_KEY_CHECKS=0");
    $res = $db->query("SHOW TABLES");
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_array()) {
            $tbl = (string)($row[0] ?? '');
            if ($tbl !== '') $db->query("DROP TABLE IF EXISTS `".$db->real_escape_string($tbl)."`");
        }
        $res->free();
    }
    $db->query("SET FOREIGN_KEY_CHECKS=1");
    echo "Dropped existing tables. Will re-apply migrations from scratch.\n";
    // Clear migrations tracker
    $db->query("DROP TABLE IF EXISTS migrations");
}

$db = db();
ensure_migrations_table($db);
$applied = $wantFresh ? [] : get_applied($db);
$batch = 1;
if (!$wantFresh) {
    $r = $db->query("SELECT COALESCE(MAX(batch),0) AS mx FROM migrations");
    if ($r instanceof mysqli_result) { $row=$r->fetch_assoc(); $batch = (int)($row['mx'] ?? 0)+1; $r->free(); }
}

if ($wantStatus) {
    echo "Migrations status (batch {$batch} next):\n";
    foreach ($MIGRATIONS as $name => $rel) {
        $status = in_array($name, $applied, true) ? "applied" : "pending";
        $exists = is_file($projectRoot . '/' . $rel) ? "ok" : "MISSING";
        echo sprintf("  %-20s %-8s [%s] %s\n", $name, $status, $exists, $rel);
    }
    exit(0);
}

$ok = 0; $fail = 0; $skipped = 0;
foreach ($MIGRATIONS as $name => $rel) {
    $path = $projectRoot . '/' . $rel;
    if (!is_file($path)) {
        echo "✗ {$name}: file missing {$rel}\n";
        $fail++;
        continue;
    }
    if (!$wantFresh && in_array($name, $applied, true)) {
        echo "→ {$name}: already applied, skipping\n";
        $skipped++;
        continue;
    }
    $raw = (string)file_get_contents($path);
    if (trim($raw) === '' || trim($raw) === '68') { // citizen_management is stub
        echo "→ {$name}: empty/stub, marking as applied\n";
        $stmt = $db->prepare("INSERT INTO migrations (name, batch) VALUES (?, ?)");
        if ($stmt) { $stmt->bind_param('si', $name, $batch); $stmt->execute(); $stmt->close(); }
        $ok++;
        continue;
    }
    if ($wantDryRun) {
        echo "— {$name}: dry-run would execute {$rel} (" . strlen($raw) . " bytes)\n";
        $stmts = split_sql($raw);
        echo "  statements: " . count($stmts) . " (first 200 chars): " . substr(trim($stmts[0] ?? ''), 0, 200) . "\n";
        continue;
    }
    echo "▶ {$name}: applying {$rel} ... ";
    $stmts = split_sql($raw);
    $allOk = true;
    foreach ($stmts as $idx => $sql) {
        $trimmed = trim(preg_replace('|/\*.*?\*/|s', '', preg_replace('/--.*$/m', '', $sql) ?? $sql) ?? '');
        if ($trimmed === '') continue;
        // PHP 8.1+ mysqli throws mysqli_sql_exception on error instead of
        // returning false, so the ignorable-error check must catch exceptions
        // too — otherwise a harmless re-run duplicate aborts the whole file.
        try {
            $res = $db->query($sql);
        } catch (mysqli_sql_exception $e) {
            $res = false;
            $err = $e->getMessage();
            $ignorable = stripos($err, 'Duplicate column') !== false
                || stripos($err, 'Duplicate key') !== false
                || stripos($err, 'already exists') !== false
                || stripos($err, 'Duplicate entry') !== false;
            if ($ignorable) {
                echo "\n  ⚠ statement " . ($idx+1) . " ignorable: " . substr($err, 0, 120) . "\n  ";
                continue;
            }
            echo "FAILED at statement " . ($idx+1) . ": " . $err . "\n";
            echo "  SQL: " . substr($sql, 0, 300) . "\n";
            $allOk = false;
            $fail++;
            break;
        }
        if ($res === false) {
            $err = $db->error;
            // Idempotent: ignore duplicate column / key errors on re-run
            $ignorable = stripos($err, 'Duplicate column') !== false
                || stripos($err, 'Duplicate key') !== false
                || stripos($err, 'already exists') !== false
                || stripos($err, 'Duplicate entry') !== false;
            if ($ignorable) {
                echo "\n  ⚠ statement " . ($idx+1) . " ignorable: " . substr($err, 0, 120) . "\n  ";
                continue;
            }
            echo "FAILED at statement " . ($idx+1) . ": " . $err . "\n";
            echo "  SQL: " . substr($sql, 0, 300) . "\n";
            $allOk = false;
            $fail++;
            break;
        }
        // Free the PRIMARY result before draining any extras.
        // The dynamic-DDL idiom used by database/basuraalert_migration.sql
        // section 11 and database/20260906_tier3_linkage.sql
        //   SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ...', 'SELECT 1') ...);
        //   PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        // returns a live mysqli_result from EXECUTE on a re-run (@sql becomes
        // 'SELECT 1'). The old code drained only *extra* results via
        // more_results()/next_result() and never freed that primary one.
        // includes/db.php already ships db_drain() for exactly this case; this
        // is the same hygiene applied locally. Note: I could not reproduce a
        // mysqli 2014 "Commands out of sync" from the old code path, because
        // DEALLOCATE PREPARE appears to drain implicitly — so treat this as
        // defensive correctness, not a confirmed bug fix.
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        // Drain multi-result
        while ($db->more_results() && $db->next_result()) {
            $r = $db->store_result();
            if ($r instanceof mysqli_result) $r->free();
        }
    }
    if ($allOk) {
        $stmt = $db->prepare("INSERT INTO migrations (name, batch) VALUES (?, ?)");
        if ($stmt) { $stmt->bind_param('si', $name, $batch); $stmt->execute(); $stmt->close(); }
        echo "done (" . count($stmts) . " statements)\n";
        $ok++;
    }
}

echo "\nSummary: {$ok} applied, {$skipped} skipped, {$fail} failed\n";
if ($fail > 0) exit(1);
echo "Done.\n";
