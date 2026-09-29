<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/basuraalert.php';

header('Content-Type: text/plain; charset=utf-8');

/* See ba_dispatch_queued_notifications.php for the reasoning: the "no ?run=1"
   gate below is a usability affordance, not a control. The actual control is
   this line, and it must stay ABOVE the gate. */
require_maintenance_authorization(current_admin(db()), 'BasuraAlert Reminder Runner');

if (PHP_SAPI !== 'cli' && !isset($_GET['run'])) {
    $runUrl = app_url('/ba_run_reminders.php') . '?run=1';
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>BasuraAlert Reminder Runner</title></head><body style="font-family:system-ui,Arial;padding:2rem;max-width:720px;margin:0 auto">';
    echo '<h2>BasuraAlert Phase 2 — Reminder / Notification Scheduler (Live SMS + Live Email)</h2>';
    echo '<p>Run this script (manually or via browser / cron) to scan for collection reminders that are due based on each user\'s <code>reminder_hours_before</code> preference.</p>';
    echo '<ul><li>Phase 2-A: <strong>sms</strong> channel dispatches LIVE via TextBee API (delivery_status = sent / failed), retries via cron dispatcher.</li><li>Phase 2-B: <strong>email</strong> channel dispatches LIVE via Brevo SMTP API (delivery_status = sent / failed), retries via cron dispatcher.</li><li>in_app uses mock_sent immediate delivery to bell.</li></ul>';
    echo '<form method="GET"><button class="btn btn-primary" name="run" value="1">Run Scheduler Now</button></form>';
    echo '<p class="small text-muted mt-3">After the BasuraAlert migration is applied via the installer, you can bookmark this page or run it with cron: <code style="background:#f6f8fa;padding:.2rem .4rem;border-radius:3px">* */6 * * * cd /path/to/project && php ba_run_reminders.php >/dev/null 2>&1</code></p>';
    echo '</body></html>';
    exit;
}

$db = db();
$start = microtime(true);

/* This script pushes email/sms reminder rows and then exits. It is the third
   producer that had no drain, so reminders it queued waited for the cron
   dispatcher that Infinity Free's free tier never runs. */
ba_register_notification_flush($db, 25, 3000);

echo "BasuraAlert Reminder Scheduler (Phase 2: Live TextBee SMS + Live Brevo Email)\n";
echo "Started at: " . date('c') . "\n\n";

$due = ba_reminder_due_collections($db, 72);
echo "Found " . count($due) . " published schedules with an upcoming collection in the next 72 hours.\n";

$totalInApp = 0;
$totalEmail = 0;
$totalSms = 0;
$skippedNoBarangay = 0;
$skippedDupes = 0;

$lookupByBarangay = [];
foreach ($due as $s) {
    $bid = (int) $s['barangay_id'];
    if (!isset($lookupByBarangay[$bid])) $lookupByBarangay[$bid] = [];
    $lookupByBarangay[$bid][] = $s;
}

$barangayNameStmt = $db->prepare('SELECT name FROM barangays WHERE id = ? LIMIT 1');

foreach ($lookupByBarangay as $bid => $scheds) {
    $bname = null;
    if ($barangayNameStmt) {
        db_prepared_execute($barangayNameStmt, 'i', [$bid]);
        $res = $barangayNameStmt->get_result();
        $r = $res ? $res->fetch_assoc() : null;
        if ($r) $bname = (string) $r['name'];
    }
    if ($bname === null) {
        echo "  - Skipping barangay id={$bid}: no name found.\n";
        continue;
    }

    $usersStmt = $db->prepare('SELECT u.id, u.email, u.mobile, u.first_name, u.barangay,
                                      p.enable_email_reminders, p.enable_sms_reminders, p.enable_in_app_reminders,
                                      p.reminder_hours_before
                               FROM users u
                               LEFT JOIN ba_notification_preferences p ON p.user_id = u.id
                               WHERE u.active = 1 AND u.barangay = ?');
    if (!$usersStmt) {
        echo "  - DB error: " . $db->error . "\n";
        continue;
    }
    db_prepared_execute($usersStmt, 's', [$bname]);
    $res = $usersStmt->get_result();
    if (!$res) { $usersStmt->close(); continue; }

    while ($user = $res->fetch_assoc()) {
        $userId = (int) $user['id'];
        $hoursBefore = (int) ($user['reminder_hours_before'] ?? 12);
        if ($hoursBefore < 1) $hoursBefore = 12;

        foreach ($scheds as $s) {
            $hoursUntil = (int) ($s['computed_hours_until'] ?? 999);
            if ($hoursUntil > $hoursBefore) continue;
            if ($hoursUntil <= 0) continue;

            $dateStr = (string) $s['computed_next'];
            $waste = (string) $s['waste_type'];
            $wasteLabel = ba_waste_label($waste);
            $title = (string) $s['title'];
            $timeRange = ba_format_time($s['time_start'] ?? null) . ' – ' . ba_format_time($s['time_end'] ?? null);
            $msg = "Reminder: {$wasteLabel} for {$bname} is {$dateStr} ({$timeRange}). ";
            if (!empty($s['notes'])) $msg .= "Note: {$s['notes']}";

            $dedupKey = md5("reminder|{$userId}|{$bid}|{$s['id']}|{$dateStr}");
            $existsStmt = $db->prepare('SELECT id FROM ba_notifications WHERE user_id = ? AND MD5(CONCAT(type,"-",COALESCE(ref_table,""),"-",COALESCE(ref_id,0),"-",title,"-",SUBSTRING(message,1,80))) = ? LIMIT 1');
            $alreadySent = false;
            if ($existsStmt) {
                $fingerprint = md5("reminder-ba_collection_schedules-{$s['id']}-Reminder: {$title}-" . substr($msg, 0, 80));
                db_prepared_execute($existsStmt, 'is', [$userId, $fingerprint]);
                $exRes = $existsStmt->get_result();
                if ($exRes && $exRes->fetch_assoc()) $alreadySent = true;
                $existsStmt->close();
            }
            if ($alreadySent) {
                $skippedDupes++;
                continue;
            }

            $firstName = (string) ($user['first_name'] ?? 'Resident');
            $fullMsg = "Hi {$firstName}! {$msg}";

            if (!empty($user['enable_in_app_reminders'])) {
                ba_push_notification($db, $userId, 'reminder', "Reminder: {$title}", $fullMsg, 'in_app', ['table' => 'ba_collection_schedules', 'id' => (int) $s['id']]);
                $totalInApp++;
            }
            if (!empty($user['enable_email_reminders'])) {
                $newId = ba_push_notification($db, $userId, 'reminder', "Reminder: {$title}", $fullMsg, 'email', ['table' => 'ba_collection_schedules', 'id' => (int) $s['id']]);
                if ($newId > 0) $totalEmail++;
            }
            if (!empty($user['enable_sms_reminders'])) {
                ba_push_notification($db, $userId, 'reminder', "Reminder: {$title}", $fullMsg, 'sms', ['table' => 'ba_collection_schedules', 'id' => (int) $s['id']]);
                $totalSms++;
            }
        }
    }
    $usersStmt->close();
}
if ($barangayNameStmt) $barangayNameStmt->close();

echo "\nSummary:\n";
echo "  In-app notifications pushed: {$totalInApp}\n";
echo "  Email dispatched via Brevo (check ba_notifications for sent/failed status): {$totalEmail}\n";
echo "  SMS dispatched via TextBee (check ba_notifications for sent/failed status): {$totalSms}\n";
if ($skippedDupes > 0) echo "  Skipped duplicates (already delivered): {$skippedDupes}\n";
echo "\nDone in " . round(microtime(true) - $start, 3) . "s.\n";
