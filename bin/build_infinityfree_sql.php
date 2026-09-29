<?php
declare(strict_types=1);

/**
 * Build a single phpMyAdmin-importable SQL file for InfinityFree (no SSH/CLI there).
 *
 * Why a snapshot and not database/*.sql concatenation?
 * The 9-file chain in bin/migrate.php cannot reproduce a working DB from zero:
 *  - citizen_management_migration.sql has a bare ALTER (duplicate `active`)
 *    and `ADD ... AFTER ui_theme` before ui_theme exists on users,
 *  - ba_dropoff_points / ba_dropoff_schedules / ba_report_ratings /
 *    user_password_resets are created only by the inline SQL in
 *    _run_basuraalert_migration.php (not in any database/*.sql file),
 *  - users.otp_enabled / admins.otp_enabled / users.moderator_notes /
 *    users.ui_theme / login_failures / admin_notifications were added ad-hoc
 *    and have no versioned migration at all.
 * A snapshot of the WORKING local DB is therefore exact by construction.
 *
 * Usage (PowerShell):
 *   C:\xampp\php\php.exe bin\build_infinityfree_sql.php
 * Output: database\full_install_infinityfree.sql
 *   - CREATE TABLE IF NOT EXISTS for every table (no AUTO_INCREMENT seeds)
 *   - reference data only for: departments, concern_types, barangays,
 *     ba_waste_guide, ba_faqs, ba_collection_schedules,
 *     ba_dropoff_points, ba_dropoff_schedules
 *   - NO accounts, reports, messages, OTPs, logs (fresh deploy starts clean)
 */

$projectRoot = dirname(__DIR__);

$candidates = [
    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    dirname('C:\\xampp\\php\\php.exe') . '\\..\\mysql\\bin\\mysqldump.exe',
    'mysqldump',
];
$dumpExe = null;
foreach ($candidates as $c) {
    $probe = $c === 'mysqldump' ? 'mysqldump' : $c;
    if ($c !== 'mysqldump' && !is_file($c)) {
        continue;
    }
    $dumpExe = $probe;
    break;
}
if ($dumpExe === null) {
    fwrite(STDERR, "mysqldump not found. Install XAMPP MySQL client tools.\n");
    exit(1);
}

$referenceTables = [
    'departments',
    'concern_types',
    'barangays',
    'ba_waste_guide',
    'ba_faqs',
    'ba_collection_schedules',
    'ba_dropoff_points',
    'ba_dropoff_schedules',
];

$tmpStruct = tempnam(sys_get_temp_dir(), 'mc_struct') . '.sql';
$tmpData = tempnam(sys_get_temp_dir(), 'mc_data') . '.sql';

$run = static function (string $cmd, string $label): void {
    $code = 0;
    $out = [];
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, $label . " failed:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
};

// 1) Structure of ALL tables, no data.
$run(
    '"' . $dumpExe . '" -u root --no-data --skip-add-drop-table --skip-comments e_concern --result-file="' . $tmpStruct . '"',
    'structure dump'
);
// 2) Data for reference tables only.
$run(
    '"' . $dumpExe . '" -u root --no-create-info --skip-triggers --complete-insert --insert-ignore e_concern '
        . implode(' ', $referenceTables) . ' --result-file="' . $tmpData . '"',
    'reference data dump'
);

$struct = (string) file_get_contents($tmpStruct);
$data = (string) file_get_contents($tmpData);
@unlink($tmpStruct);
@unlink($tmpData);

// Make re-import safe: IF NOT EXISTS + strip AUTO_INCREMENT table options.
$struct = preg_replace('/CREATE TABLE `/', 'CREATE TABLE IF NOT EXISTS `', $struct);
$struct = preg_replace('/ AUTO_INCREMENT=\d+/', '', $struct);

$out = "-- Marikina E-Concern — full install snapshot for InfinityFree phpMyAdmin\n";
$out .= "-- Generated: " . date('Y-m-d H:i:s') . " (Asia/Manila)\n";
$out .= "-- Source: working local `e_concern` DB structure (exact columns/keys),\n";
$out .= "-- plus reference data for: " . implode(', ', $referenceTables) . ".\n";
$out .= "-- Import ONCE into an EMPTY database via phpMyAdmin > Import.\n";
$out .= "-- Accounts/reports/messages/OTPs/logs are intentionally NOT included.\n\n";
$out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
$out .= rtrim($struct) . "\n\n";
$out .= rtrim($data) . "\n\n";
$out .= "SET FOREIGN_KEY_CHECKS=1;\n";

$dest = $projectRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'full_install_infinityfree.sql';
file_put_contents($dest, $out);
echo "Wrote: database/full_install_infinityfree.sql (" . number_format(strlen($out)) . " bytes)\n";
echo "Reference tables: " . implode(', ', $referenceTables) . "\n";
echo "Done. Import via InfinityFree Control Panel > phpMyAdmin > Import.\n";
