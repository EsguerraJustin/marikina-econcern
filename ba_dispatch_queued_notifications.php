<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/basuraalert.php';
require_once __DIR__ . '/includes/sms.php';

header('Content-Type: text/plain; charset=utf-8');

/* Authorization comes BEFORE the "no ?run=1" gate below. Previously the only
   thing between the internet and a live SMS/email blast was the presence of a
   query string, so ?run=1 was enough and answered HTTP 200 to an anonymous
   caller. The browser path now needs a logged-in super admin; cron is
   unaffected because PHP_SAPI is 'cli'. */
require_maintenance_authorization(current_admin(db()), 'Queued Notification Dispatcher');

if (PHP_SAPI !== 'cli' && !isset($_GET['run'])) {
    $runUrl = app_url('/ba_dispatch_queued_notifications.php') . '?run=1';
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Phase 2 Queued Notification Dispatcher (SMS + Email)</title></head><body style="font-family:system-ui,Arial;padding:2rem;max-width:760px;margin:0 auto">';
    echo '<h2>Phase 2 · Queued Notification Dispatcher (TextBee SMS + Brevo Email Retry Worker)</h2>';
    echo '<p>Retries all <code>ba_notifications</code> rows where <code>delivery_status IN (\'queued\', \'failed\')</code> up to 3 total attempts with exponential backoff.';
    echo '<ul>';
    echo '<li>Max attempts per row: <strong>3</strong> (incl. the original push attempt)</li>';
    echo '<li>Backoff: attempt 2 = 30s, attempt 3 = 60s, attempt 4 = 300s jitter (based on next_attempt_at col)</li>';
    echo '<li>Channel SMS (TextBee): Phase 2-A live — retries failed/queued SMS rows.</li>';
    echo '<li>Channel Email (Brevo): Phase 2-B live — retries failed/queued Email rows.</li>';
    echo '</ul>';
    echo '<form method="GET"><button class="btn btn-primary" name="run" value="1">Dispatch Queued Notifications Now</button></form>';
    echo '<p class="small text-muted mt-3">Cron every 5 minutes recommended: <code style="background:#f6f8fa;padding:.2rem .4rem;border-radius:3px">*/5 * * * * cd /path/to/project && php ba_dispatch_queued_notifications.php >/dev/null 2>&1</code></p>';
    echo '</body></html>';
    exit;
}

$db = db();
$start = microtime(true);
echo "Phase 2 Queued Dispatcher (TextBee SMS retry + Brevo Email retry worker)\n";
echo "Started at: " . date('c') . "\n\n";

ba_dispatcher_ensure_schema($db);

$MAX_ATTEMPTS = 3;
$BACKOFF_SEC = [1 => 30, 2 => 60, 3 => 300];

$listStmt = $db->prepare("
    SELECT n.id, n.user_id, n.type, n.title, n.message, n.delivery_status, n.delivery_note,
           n.retry_count, n.next_attempt_at, u.mobile
    FROM ba_notifications n
    LEFT JOIN users u ON u.id = n.user_id
    WHERE n.channel = 'sms'
      AND n.delivery_status IN ('queued','failed')
      AND (n.next_attempt_at IS NULL OR n.next_attempt_at <= CURRENT_TIMESTAMP)
    ORDER BY n.id ASC
    LIMIT 200
");
if (!$listStmt) {
    echo "ERROR prepare SMS listStmt: {$db->error}\n";
    exit(1);
}
db_prepared_execute($listStmt, '', []);
$res = $listStmt->get_result();
$rows = [];
while ($r = $res ? $res->fetch_assoc() : null) $rows[] = $r;
$listStmt->close();
$total = count($rows);
echo "Found {$total} SMS row(s) ready for retry.\n";

$sent = 0;
$failed = 0;
$exhausted = 0;
$skippedNoMobile = 0;

foreach ($rows as $row) {
    $notifId = (int) $row['id'];
    $attemptSoFar = (int) ($row['retry_count'] ?? 0) + 1;
    $userId = (int) $row['user_id'];
    $mobileRaw = (string) ($row['mobile'] ?? '');
    $norm = $mobileRaw !== '' ? normalize_ph_mobile($mobileRaw) : false;
    if ($norm === false) {
        $skippedNoMobile++;
        $upd = $db->prepare('UPDATE ba_notifications SET delivery_status=\'failed\', delivery_note=CONCAT(COALESCE(delivery_note,\'\'), ?), retry_count=? WHERE id=? LIMIT 1');
        if ($upd) {
            $note = ' | retry#' . $attemptSoFar . ' FAILED: invalid/no valid PH mobile (user_id=' . $userId . '). GIVE UP — exhausted.';
            db_prepared_execute($upd, 'sii', [$note, min($attemptSoFar, $MAX_ATTEMPTS + 1), $notifId]);
            $upd->close();
        }
        $exhausted++;
        continue;
    }

    if ($attemptSoFar > $MAX_ATTEMPTS) {
        $upd = $db->prepare('UPDATE ba_notifications SET delivery_status=\'failed\', delivery_note=CONCAT(COALESCE(delivery_note,\'\'), ?) WHERE id=? LIMIT 1');
        if ($upd) {
            $note = ' | GIVE UP after ' . $attemptSoFar . ' attempts exhausted.';
            db_prepared_execute($upd, 'si', [$note, $notifId]);
            $upd->close();
        }
        $exhausted++;
        continue;
    }

    $title = (string) $row['title'];
    $msg = (string) $row['message'];
    $smsBody = trim($title) !== '' ? ($title . ': ' . $msg) : $msg;
    if (strlen($smsBody) > 1530) $smsBody = substr($smsBody, 0, 1527) . '...';
    $smsRes = send_sms($norm, $smsBody);
    $ok = is_array($smsRes) && !empty($smsRes['ok']);
    $refPart = (is_array($smsRes) && isset($smsRes['provider_ref']) && is_scalar($smsRes['provider_ref'])) ? (' · ref: ' . $smsRes['provider_ref']) : '';
    $http = (is_array($smsRes) && isset($smsRes['http_status'])) ? (int) $smsRes['http_status'] : 0;
    $err = (is_array($smsRes) && isset($smsRes['error']) && is_string($smsRes['error'])) ? $smsRes['error'] : 'unknown send_sms error';
    $masked = mask_mobile($norm);

    $nextAttempt = null;
    if (!$ok && ($attemptSoFar + 1) <= $MAX_ATTEMPTS) {
        $delaySec = (int) ($BACKOFF_SEC[$attemptSoFar] ?? 60);
        $jitter = random_int(0, min(20, (int) ceil($delaySec / 3)));
        $nextAttempt = date('Y-m-d H:i:s', time() + $delaySec + $jitter);
    }

    if ($ok) {
        $note = 'TextBee SMS delivered to ' . $masked;
        $upd = $db->prepare('UPDATE ba_notifications SET delivery_status=\'sent\', delivery_note=?, retry_count=?, sent_at=CURRENT_TIMESTAMP, next_attempt_at=NULL WHERE id=? LIMIT 1');
        if ($upd) {
            db_prepared_execute($upd, 'sii', [$note, $attemptSoFar, $notifId]);
            $upd->close();
        }
        $sent++;
    } else {
        $status = 'failed';
        $note = 'TextBee SMS FAILED to ' . $masked . ': ' . $err . ($nextAttempt !== null ? '' : ' · GIVING UP');
        $upd = $db->prepare('UPDATE ba_notifications SET delivery_status=?, delivery_note=?, retry_count=?, next_attempt_at=? WHERE id=? LIMIT 1');
        if ($upd) {
            db_prepared_execute($upd, 'ssisi', [$status, $note, $attemptSoFar, $nextAttempt, $notifId]);
            $upd->close();
        }
        if ($nextAttempt === null) $exhausted++; else $failed++;
    }
    echo "  SMS [{$notifId}] attempt {$attemptSoFar}/{$MAX_ATTEMPTS} -> " . ($ok ? "SENT {$masked}" : "FAIL {$masked} err={$err}") . "\n";
}

echo "\n--- SMS Summary ---\n";
echo "  Sent successfully (retries): {$sent}\n";
echo "  Failed this pass (pending next retry): {$failed}\n";
echo "  Exhausted — gave up after {$MAX_ATTEMPTS} attempts / no valid mobile: {$exhausted}\n";
echo "  Skipped invalid mobile (marked failed + exhausted): {$skippedNoMobile}\n";

echo "\n--- Email (Brevo) Processing ---\n";

$emailListStmt = $db->prepare("
    SELECT n.id, n.user_id, n.type, n.title, n.message, n.delivery_status, n.delivery_note,
           n.retry_count, n.next_attempt_at, u.email, u.first_name, u.last_name
    FROM ba_notifications n
    LEFT JOIN users u ON u.id = n.user_id
    WHERE n.channel = 'email'
      AND n.delivery_status IN ('queued','failed')
      AND (n.next_attempt_at IS NULL OR n.next_attempt_at <= CURRENT_TIMESTAMP)
    ORDER BY n.id ASC
    LIMIT 200
");
if (!$emailListStmt) {
    echo "ERROR prepare Email listStmt: {$db->error}\n";
    $emailRows = [];
} else {
    db_prepared_execute($emailListStmt, '', []);
    $emailRes = $emailListStmt->get_result();
    $emailRows = [];
    while ($r = $emailRes ? $emailRes->fetch_assoc() : null) $emailRows[] = $r;
    $emailListStmt->close();
}
$emailTotal = count($emailRows);
echo "Found {$emailTotal} Email row(s) ready for retry.\n";

$emailSent = 0;
$emailFailed = 0;
$emailExhausted = 0;
$emailSkippedNoValidEmail = 0;

foreach ($emailRows as $row) {
    $notifId = (int) $row['id'];
    $attemptSoFar = (int) ($row['retry_count'] ?? 0) + 1;
    $userId = (int) $row['user_id'];
    $emailRaw = (string) ($row['email'] ?? '');
    $firstName = (string) ($row['first_name'] ?? '');
    $lastName = (string) ($row['last_name'] ?? '');
    $toName = trim($firstName . ' ' . $lastName);
    $emailOk = $emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
    if (!$emailOk) {
        $emailSkippedNoValidEmail++;
        $upd = $db->prepare('UPDATE ba_notifications SET delivery_status=\'failed\', delivery_note=CONCAT(COALESCE(delivery_note,\'\'), ?), retry_count=? WHERE id=? LIMIT 1');
        if ($upd) {
            $note = ' | retry#' . $attemptSoFar . ' FAILED: invalid/no valid email address (user_id=' . $userId . '). GIVE UP — exhausted.';
            db_prepared_execute($upd, 'sii', [$note, min($attemptSoFar, $MAX_ATTEMPTS + 1), $notifId]);
            $upd->close();
        }
        $emailExhausted++;
        continue;
    }

    if ($attemptSoFar > $MAX_ATTEMPTS) {
        $upd = $db->prepare('UPDATE ba_notifications SET delivery_status=\'failed\', delivery_note=CONCAT(COALESCE(delivery_note,\'\'), ?) WHERE id=? LIMIT 1');
        if ($upd) {
            $note = ' | GIVE UP after ' . $attemptSoFar . ' attempts exhausted.';
            db_prepared_execute($upd, 'si', [$note, $notifId]);
            $upd->close();
        }
        $emailExhausted++;
        continue;
    }

    $title = (string) $row['title'];
    $msg = (string) $row['message'];
    $type = (string) $row['type'];
    $tags = ['basuraalert', 'notification', 'type_' . $type, 'dispatcher_retry'];
    $htmlBody = ba_build_notification_email_html($title, $msg, $type);
    $emailRes = ba_brevo_send_transactional($emailRaw, $toName, $title, $htmlBody, $tags);
    $ok = is_array($emailRes) && !empty($emailRes['ok']);
    $http = (is_array($emailRes) && isset($emailRes['http_status'])) ? (int) $emailRes['http_status'] : 0;
    $msgId = (is_array($emailRes) && isset($emailRes['message_id']) && is_scalar($emailRes['message_id'])) ? (string) $emailRes['message_id'] : '';
    $err = (is_array($emailRes) && isset($emailRes['error']) && is_string($emailRes['error'])) ? $emailRes['error'] : 'unknown brevo send error';
    $maskedEmail = mask_email($emailRaw);

    $nextAttempt = null;
    if (!$ok && ($attemptSoFar + 1) <= $MAX_ATTEMPTS) {
        $delaySec = (int) ($BACKOFF_SEC[$attemptSoFar] ?? 60);
        $jitter = random_int(0, min(20, (int) ceil($delaySec / 3)));
        $nextAttempt = date('Y-m-d H:i:s', time() + $delaySec + $jitter);
    }

    if ($ok) {
        $note = 'Brevo email delivered to ' . $maskedEmail;
        $upd = $db->prepare('UPDATE ba_notifications SET delivery_status=\'sent\', delivery_note=?, retry_count=?, sent_at=CURRENT_TIMESTAMP, next_attempt_at=NULL WHERE id=? LIMIT 1');
        if ($upd) {
            db_prepared_execute($upd, 'sii', [$note, $attemptSoFar, $notifId]);
            $upd->close();
        }
        $emailSent++;
    } else {
        $status = 'failed';
        $note = 'Brevo email FAILED to ' . $maskedEmail . ': ' . $err . ($nextAttempt !== null ? '' : ' · GIVING UP');
        $upd = $db->prepare('UPDATE ba_notifications SET delivery_status=?, delivery_note=?, retry_count=?, next_attempt_at=? WHERE id=? LIMIT 1');
        if ($upd) {
            db_prepared_execute($upd, 'ssisi', [$status, $note, $attemptSoFar, $nextAttempt, $notifId]);
            $upd->close();
        }
        if ($nextAttempt === null) $emailExhausted++; else $emailFailed++;
    }
    echo "  EMAIL [{$notifId}] attempt {$attemptSoFar}/{$MAX_ATTEMPTS} -> " . ($ok ? "SENT {$maskedEmail}" : "FAIL {$maskedEmail} err={$err}") . "\n";
}

echo "\n--- Email Summary ---\n";
echo "  Sent successfully (retries): {$emailSent}\n";
echo "  Failed this pass (pending next retry): {$emailFailed}\n";
echo "  Exhausted — gave up after {$MAX_ATTEMPTS} attempts / no valid email: {$emailExhausted}\n";
echo "  Skipped invalid/empty email (marked failed + exhausted): {$emailSkippedNoValidEmail}\n";

echo "\n=== OVERALL SUMMARY ===\n";
echo "  SMS:   sent={$sent}  failed(pending)={$failed}  exhausted={$exhausted}  skipped(bad#)={$skippedNoMobile}\n";
echo "  EMAIL: sent={$emailSent}  failed(pending)={$emailFailed}  exhausted={$emailExhausted}  skipped(bad@)={$emailSkippedNoValidEmail}\n";
echo "\nDone in " . round(microtime(true) - $start, 3) . "s.\n";

function ba_dispatcher_ensure_schema(mysqli $db): void
{
    $cols = [];
    $res = $db->query('DESCRIBE ba_notifications');
    if (!$res) return;
    while ($r = $res->fetch_assoc()) $cols[strtolower((string)$r['Field'])] = $r;

    if (!isset($cols['retry_count'])) {
        $db->query('ALTER TABLE ba_notifications ADD COLUMN retry_count INT UNSIGNED NOT NULL DEFAULT 0');
        echo "ALTER: added retry_count column.\n";
    }
    if (!isset($cols['next_attempt_at'])) {
        $db->query('ALTER TABLE ba_notifications ADD COLUMN next_attempt_at TIMESTAMP NULL DEFAULT NULL');
        echo "ALTER: added next_attempt_at column.\n";
    }
    if (!isset($cols['sent_at'])) {
        $db->query('ALTER TABLE ba_notifications ADD COLUMN sent_at TIMESTAMP NULL DEFAULT NULL');
        echo "ALTER: added sent_at column.\n";
    }
    $statusVals = [];
    if (isset($cols['delivery_status']['Type'])) {
        if (preg_match_all("/'([^']+)'/", (string)$cols['delivery_status']['Type'], $m)) {
            $statusVals = $m[1];
        }
    }
}
