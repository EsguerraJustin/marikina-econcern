<?php
declare(strict_types=1);

/**
 * BasuraAlert — Notifications service
 * Extracted from includes/basuraalert.php (auto-split 20 functions)
 * This file is required by includes/basuraalert.php for backwards-compat.
 */

function ba_list_announcements(?mysqli $db, ?int $targetBarangayId = null, string $scope = 'public'): array
{
    if (!($db instanceof mysqli)) return [];
    $where = [];
    $params = [];
    $types = '';
    if ($scope === 'public') {
        $where[] = "a.status = 'Published'";
    }
    if ($targetBarangayId !== null && $targetBarangayId > 0) {
        $where[] = '(a.scope = \'all\' OR (a.scope = \'barangay\' AND a.target_barangay_id = ?))';
        $params[] = $targetBarangayId;
        $types .= 'i';
    }
    $sql = 'SELECT a.*, b.name AS target_barangay_name
            FROM ba_announcements a
            LEFT JOIN barangays b ON b.id = a.target_barangay_id';
    if (count($where) > 0) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.effective_date DESC, a.published_at DESC, a.id DESC';

    if (count($params) === 0) {
        $res = $db->query($sql);
        if (!$res) return [];
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    db_prepared_execute($stmt, $types, $params);
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function ba_list_notifications_for_user(?mysqli $db, int $userId, ?bool $unreadOnly = null, int $limit = 100): array
{
    if (!($db instanceof mysqli)) return [];
    $where = ['n.user_id = ?'];
    $params = [$userId];
    $types = 'i';
    if ($unreadOnly === true) {
        $where[] = 'n.is_read = 0';
    } elseif ($unreadOnly === false) {
        $where[] = 'n.is_read = 1';
    }
    $sql = 'SELECT n.* FROM ba_notifications n WHERE ' . implode(' AND ', $where) . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    db_prepared_execute($stmt, $types, $params);
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function ba_count_unread_notifications(?mysqli $db, int $userId): int
{
    if (!($db instanceof mysqli)) return 0;
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM ba_notifications WHERE user_id = ? AND is_read = 0');
    if (!$stmt) return 0;
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

function ba_mark_notification_read(?mysqli $db, int $notifId, int $userId): void
{
    if (!($db instanceof mysqli)) return;
    $stmt = $db->prepare('UPDATE ba_notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND is_read = 0');
    if (!$stmt) return;
    db_prepared_execute($stmt, 'ii', [$notifId, $userId]);
    $stmt->close();
}

function ba_mark_all_notifications_read(?mysqli $db, int $userId): void
{
    if (!($db instanceof mysqli)) return;
    $stmt = $db->prepare('UPDATE ba_notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND is_read = 0');
    if (!$stmt) return;
    db_prepared_execute($stmt, 'i', [$userId]);
    $stmt->close();
}

function ba_delete_notification(?mysqli $db, int $notifId, int $userId): bool
{
    if (!($db instanceof mysqli) || $notifId <= 0 || $userId <= 0) return false;
    $stmt = $db->prepare('DELETE FROM ba_notifications WHERE id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) return false;
    db_prepared_execute($stmt, 'ii', [$notifId, $userId]);
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}

function ba_delete_all_notifications_for_user(?mysqli $db, int $userId, bool $onlyUnread = false): int
{
    if (!($db instanceof mysqli) || $userId <= 0) return 0;
    $sql = 'DELETE FROM ba_notifications WHERE user_id = ?';
    if ($onlyUnread) $sql .= ' AND is_read = 0';
    $stmt = $db->prepare($sql);
    if (!$stmt) return 0;
    db_prepared_execute($stmt, 'i', [$userId]);
    $deleted = (int) $stmt->affected_rows;
    $stmt->close();
    return max(0, $deleted);
}

function ba_build_notification_email_html(string $title, string $message, string $type = 'notification'): string
{
    $titleE = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $msgE = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $year = date('Y');
    $appName = (defined('APP_NAME') ? (string)APP_NAME : 'BasuraAlert · Marikina e-Concern');
    $typeLabel = ucwords(str_replace('_', ' ', $type));
    $typeE = htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$titleE}</title></head>
<body style="margin:0;padding:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f3f6fb;color:#1f2937;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,.07);margin-top:24px;margin-bottom:24px;">
  <tr style="background:linear-gradient(135deg,#16a34a 0%,#15803d 100%);">
    <td style="padding:24px 28px;">
      <div style="color:#ffffff;font-size:12px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;opacity:.9;margin-bottom:6px;">{$typeE}</div>
      <div style="color:#ffffff;font-size:22px;font-weight:700;line-height:1.2;">{$titleE}</div>
    </td>
  </tr>
  <tr>
    <td style="padding:28px;">
      <div style="background:#ecfdf5;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;margin-bottom:20px;">
        <div style="font-size:13px;font-weight:600;color:#065f46;margin-bottom:2px;">✅ Email delivered</div>
        <div style="font-size:12px;color:#047857;">This notification was dispatched in real time via Brevo SMTP API.</div>
      </div>
      <div style="font-size:15px;color:#374151;line-height:1.6;">{$msgE}</div>
    </td>
  </tr>
  <tr style="background:#f9fafb;border-top:1px solid #e5e7eb;">
    <td style="padding:16px 28px;color:#6b7280;font-size:12px;text-align:center;line-height:1.5;">
      This is an automated message from <strong style="color:#1f2937;">{$appName}</strong>. Please do not reply directly.
      <br>&copy; {$year} · BasuraAlert Module
    </td>
  </tr>
</table>
</body></html>
HTML;
}

function ba_push_notification(?mysqli $db, int $userId, string $type, string $title, string $message, string $channel = 'in_app', array $ref = []): int
{
    if (!($db instanceof mysqli)) return 0;
    $refTable = isset($ref['table']) && is_string($ref['table']) ? $ref['table'] : null;
    $refId = isset($ref['id']) && (int) $ref['id'] > 0 ? (int) $ref['id'] : null;

    $userMobile = null;
    $userEmail = null;
    $userFullName = '';

    if (($channel === 'sms' || $channel === 'email') && $userId > 0) {
        $uStmt = $db->prepare('SELECT mobile, email, first_name, last_name FROM users WHERE id = ? LIMIT 1');
        if ($uStmt) {
            db_prepared_execute($uStmt, 'i', [$userId]);
            $uRes = $uStmt->get_result();
            $uRow = $uRes ? $uRes->fetch_assoc() : null;
            $uStmt->close();
            if ($uRow) {
                $userMobile = !empty($uRow['mobile']) ? (string) $uRow['mobile'] : null;
                $userEmail = !empty($uRow['email']) ? (string) $uRow['email'] : null;
                $userFullName = trim(((string)($uRow['first_name'] ?? '')) . ' ' . ((string)($uRow['last_name'] ?? '')));
            }
        }
    }

    $status = 'queued';
    $note = 'Queued for delivery — will be processed in the background.';

    if ($channel === 'in_app') {
        $status = 'sent';
        $note = 'Delivered to in-app notification center';
    }

    if ($channel === 'sms' && $userMobile !== null) {
        $norm = normalize_ph_mobile($userMobile);
        if ($norm !== false) {
            $smsBody = trim($title) !== '' ? ($title . ': ' . $message) : $message;
            if (strlen($smsBody) > 1530) $smsBody = substr($smsBody, 0, 1527) . '...';
            $status = 'queued';
            $note = 'SMS queued for TextBee delivery to ' . mask_mobile($norm) . ' (non-blocking)';
        }
    }

    if ($channel === 'email' && $userEmail !== null && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        $status = 'queued';
        $note = 'Email queued for Brevo delivery to ' . mask_email($userEmail) . ' (non-blocking)';
    }

    $stmt = $db->prepare('INSERT INTO ba_notifications (user_id, type, title, message, ref_table, ref_id, channel, delivery_status, delivery_note) VALUES (?,?,?,?,?,?,?,?,?)');
    if (!$stmt) return 0;
    db_prepared_execute($stmt, 'isssissss', [$userId, $type, $title, $message, $refTable, $refId, $channel, $status, $note]);
    $newId = (int) ($db->insert_id ?? 0);
    $stmt->close();
    return $newId;
}

/**
 * Drain the SMS/email queue after the response is already on the wire.
 *
 * PROJECT_GUIDE rule 1: a request must never wait on TextBee or Brevo. A
 * producer writes a `queued` row and returns; this sends it afterwards, once
 * fastcgi_finish_request() has flushed the JSON body to the browser.
 *
 * This lived inline in api/basuraalert_actions.php only, so it ran for citizen
 * actions and nothing else. Every admin action that queues a row -- feedback
 * reply, announcement fan-out, report status update -- therefore had nothing to
 * drain it, and the row sat at `queued` until the cron dispatcher ran. Infinity
 * Free's free tier has no cron, so those notifications were never sent at all.
 * Every entry point that can produce a queued row must call this.
 */
function ba_register_notification_flush(?mysqli $db, int $maxPerRun = 10, int $perItemTimeoutMs = 3000): void
{
    if (!($db instanceof mysqli)) return;

    register_shutdown_function(static function () use ($db, $maxPerRun, $perItemTimeoutMs): void {
        // A fatal earlier in the request leaves the connection unusable; the
        // response is already broken, so do not make it worse.
        $lastError = error_get_last();
        if (is_array($lastError) && in_array((int) ($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        @session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        // json_response() already echoed the body and exit()ed, so anything this
        // drain emits lands *after* it. A single notice, warning or fatal
        // appended to valid JSON makes the client's JSON.parse() throw, and the
        // resident sees "Network or server error" for a report that was actually
        // saved. Buffer and discard instead, and catch so a bad row can never
        // take the page down.
        $obLevel = ob_get_level();
        ob_start();
        try {
            @ba_flush_queued_notifications($db, $maxPerRun, $perItemTimeoutMs);
        } catch (Throwable $e) {
            @error_log('[basuraalert] queued-notification flush failed: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../../app_error.log');
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }
    });
}

function ba_flush_queued_notifications(?mysqli $db, int $maxPerRun = 10, int $perItemTimeoutMs = 3000): array
{
    if (!($db instanceof mysqli)) return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    $sent = 0;
    $failed = 0;
    $skipped = 0;

    $perItemSec = (int)ceil(max(1, $perItemTimeoutMs) / 1000);
    $totalMaxSec = max(10, $maxPerRun * $perItemSec);
    @set_time_limit($totalMaxSec);

    $qStmt = $db->prepare('SELECT n.id, n.user_id, n.type, n.title, n.message, n.channel, u.mobile, u.email, u.first_name, u.last_name
        FROM ba_notifications n
        LEFT JOIN users u ON u.id = n.user_id
        WHERE n.delivery_status = ? AND n.channel IN (?,?)
        ORDER BY n.id ASC
        LIMIT ?');
    if (!$qStmt) return ['ok' => false, 'error' => 'prepare failed', 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    $queued = 'queued';
    $chSms = 'sms';
    $chEmail = 'email';
    db_prepared_execute($qStmt, 'sssi', [$queued, $chSms, $chEmail, $maxPerRun]);
    $res = $qStmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $qStmt->close();

    if (count($rows) === 0) {
        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    }

    // sent_at is the real column. delivered_at never existed in any migration or
    // schema, and MySQL resolves columns at PREPARE, so this threw
    // "Unknown column 'delivered_at'" -- from inside register_shutdown_function,
    // i.e. after the JSON response was already sent, which turned a successful
    // report submit into "Network or server error" for the resident.
    $updStmt = $db->prepare('UPDATE ba_notifications SET delivery_status = ?, delivery_note = ?, sent_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1');
    if (!$updStmt) {
        return ['ok' => false, 'error' => 'update prepare failed', 'sent' => 0, 'failed' => 0, 'skipped' => count($rows)];
    }

    foreach ($rows as $row) {
        $nid = (int)($row['id'] ?? 0);
        if ($nid <= 0) { $skipped++; continue; }
        $channel = (string)($row['channel'] ?? 'in_app');
        $title = (string)($row['title'] ?? '');
        $message = (string)($row['message'] ?? '');
        $type = (string)($row['type'] ?? 'notification');
        $mobile = !empty($row['mobile']) ? (string)$row['mobile'] : null;
        $email = !empty($row['email']) ? (string)$row['email'] : null;
        $firstName = (string)($row['first_name'] ?? '');
        $lastName = (string)($row['last_name'] ?? '');

        if ($channel === 'sms') {
            if ($mobile === null || trim($mobile) === '') {
                db_prepared_execute($updStmt, 'ssi', ['failed', 'No mobile number on file', $nid]);
                $failed++;
                continue;
            }
            $norm = normalize_ph_mobile($mobile);
            if ($norm === false) {
                db_prepared_execute($updStmt, 'ssi', ['failed', 'Invalid mobile number format: ' . mask_mobile($mobile), $nid]);
                $failed++;
                continue;
            }
            $smsBody = trim($title) !== '' ? ($title . ': ' . $message) : $message;
            if (strlen($smsBody) > 1530) $smsBody = substr($smsBody, 0, 1527) . '...';
            $smsRes = @send_sms($norm, $smsBody);
            if (is_array($smsRes) && !empty($smsRes['ok'])) {
                $refPart = isset($smsRes['provider_ref']) && is_scalar($smsRes['provider_ref']) ? (' · ref: ' . $smsRes['provider_ref']) : '';
                db_prepared_execute($updStmt, 'ssi', ['sent', 'TextBee live SMS delivered to ' . mask_mobile($norm) . $refPart, $nid]);
                $sent++;
            } else {
                $err = is_array($smsRes) && isset($smsRes['error']) && is_string($smsRes['error']) ? $smsRes['error'] : 'unknown send_sms error';
                db_prepared_execute($updStmt, 'ssi', ['failed', 'TextBee SMS FAILED to ' . mask_mobile($norm) . ': ' . $err, $nid]);
                $failed++;
            }
        } elseif ($channel === 'email') {
            if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                db_prepared_execute($updStmt, 'ssi', ['failed', 'Invalid or missing email address', $nid]);
                $failed++;
                continue;
            }
            $toName = trim($firstName . ' ' . $lastName);
            $tags = ['basuraalert', 'notification', 'type_' . $type];
            $htmlBody = ba_build_notification_email_html($title, $message, $type);
            $emailRes = @ba_brevo_send_transactional($email, $toName, $title, $htmlBody, $tags);
            if (is_array($emailRes) && !empty($emailRes['ok'])) {
                db_prepared_execute($updStmt, 'ssi', ['sent', 'Brevo live email delivered to ' . mask_email($email), $nid]);
                $sent++;
            } else {
                $err = is_array($emailRes) && isset($emailRes['error']) && is_string($emailRes['error']) ? $emailRes['error'] : 'unknown brevo send error';
                db_prepared_execute($updStmt, 'ssi', ['failed', 'Brevo email FAILED to ' . mask_email($email) . ': ' . $err, $nid]);
                $failed++;
            }
        } else {
            $skipped++;
        }
    }

    $updStmt->close();
    return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}

function ba_save_report_rating(?mysqli $db, int $reportId, int $userId, int $rating, ?string $comment = null): bool
{
    if (!($db instanceof mysqli)) return false;
    if ($rating < 1) $rating = 1;
    if ($rating > 5) $rating = 5;
    $chk = $db->prepare('SELECT id FROM ba_report_ratings WHERE report_id = ? AND user_id = ? LIMIT 1');
    if ($chk) {
        db_prepared_execute($chk, 'ii', [$reportId, $userId]);
        $res = $chk->get_result();
        $has = $res ? (bool) $res->fetch_assoc() : false;
        $chk->close();
        if ($has) {
            $stmt = $db->prepare('UPDATE ba_report_ratings SET rating = ?, comment = ?, rated_at = CURRENT_TIMESTAMP WHERE report_id = ? AND user_id = ?');
            if (!$stmt) return false;
            db_prepared_execute($stmt, 'isii', [$rating, $comment, $reportId, $userId]);
            $stmt->close();
            return true;
        }
    }
    $stmt = $db->prepare('INSERT INTO ba_report_ratings (report_id, user_id, rating, comment) VALUES (?,?,?,?)');
    if (!$stmt) return false;
    db_prepared_execute($stmt, 'iiis', [$reportId, $userId, $rating, $comment]);
    $stmt->close();
    return true;
}

function ba_get_report_rating(?mysqli $db, int $reportId): ?array
{
    if (!($db instanceof mysqli)) return null;
    $stmt = $db->prepare('SELECT rating, comment, rated_at FROM ba_report_ratings WHERE report_id = ? ORDER BY rated_at DESC LIMIT 1');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$reportId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

function ba_get_feedback_list(?mysqli $db, ?string $kind = null, ?bool $repliedOnly = null, int $limit = 500): array
{
    if (!($db instanceof mysqli)) return [];
    $where = [];
    $params = [];
    $types = '';
    if ($kind !== null && $kind !== '') {
        $where[] = 'f.kind = ?';
        $params[] = $kind;
        $types .= 's';
    }
    if ($repliedOnly === true) $where[] = 'f.replied_at IS NOT NULL';
    elseif ($repliedOnly === false) $where[] = 'f.replied_at IS NULL';
    $sql = 'SELECT f.*, CONCAT(u.first_name, \' \', u.last_name) user_full_name, u.email user_email, u.mobile user_mobile
            FROM ba_feedback f LEFT JOIN users u ON u.id = f.user_id';
    if (count($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY f.id DESC LIMIT ' . $limit;
    if (count($params) === 0) {
        $res = $db->query($sql);
        if (!$res) return [];
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    db_prepared_execute($stmt, $types, $params);
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function ba_push_notification_via_prefs(?mysqli $db, int $userId, string $type, string $title, string $message, array $ref = []): int
{
    if (!($db instanceof mysqli)) return 0;
    $prefs = ba_get_user_prefs($db, $userId);
    $channels = [];
    foreach (['in_app','email','sms'] as $ch) {
        $globalKey = 'enable_' . $ch . '_reminders';
        if (empty($prefs[$globalKey])) continue;
        $eventKey = 'ev_' . $type . '_' . $ch;
        if (isset($prefs[$eventKey]) && (int)$prefs[$eventKey] !== 1) continue;
        $channels[] = $ch;
    }
    if (count($channels) === 0) $channels[] = 'in_app';
    $last = 0;
    foreach ($channels as $ch) {
        $last = ba_push_notification($db, $userId, $type, $title, $message, $ch, $ref);
    }
    return $last;
}

/**
 * Where a notification should take the citizen when its card is tapped.
 *
 * ref_table / ref_id have been written by ba_push_notification() since the
 * column pair was introduced and are already indexed (idx_notif_ref), but
 * nothing in the application ever rendered or followed them -- a tap could only
 * ever flip the unread dot. That is why an admin's message to a citizen, and
 * equally the pre-existing report_update notifications, left the citizen with no
 * way to reach the thing they were being told about.
 *
 * The mapping lives here rather than in the page script or in JS so the routes
 * stay next to app_url(), and so an unrecognised ref_table degrades to the old
 * mark-read-only behaviour instead of a broken link.
 *
 * @return string empty when the notification has no navigable target
 */
function ba_notification_target_url(?array $notification): string
{
    if (!is_array($notification)) {
        return '';
    }
    $refTable = isset($notification['ref_table']) ? trim((string) $notification['ref_table']) : '';
    $refId = isset($notification['ref_id']) ? (int) $notification['ref_id'] : 0;
    if ($refTable === '' || $refId <= 0) {
        return '';
    }
    switch ($refTable) {
        case 'concerns':
            return app_url('/public/concern_view.php?id=' . $refId);
        case 'ba_reports':
            return app_url('/public/ba_my_reports.php?id=' . $refId);
        case 'ba_announcements':
            return app_url('/public/ba_announcements.php');
        case 'ba_collection_schedules':
            return app_url('/public/ba_schedule.php');
        default:
            // Includes ba_feedback, and anything added later that has no page of
            // its own: no target is strictly better than a wrong one.
            return '';
    }
}

function ba_list_faqs(?mysqli $db, string $q = '', ?string $category = null): array
{
    if (!($db instanceof mysqli)) return [];
    $where = ["f.status = 'Published'"];
    $params = [];
    $types = '';
    if ($q !== '') {
        $where[] = '(f.question LIKE ? OR f.answer LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like;
        $types .= 'ss';
    }
    if ($category !== null && $category !== '') {
        $where[] = 'f.category = ?';
        $params[] = $category;
        $types .= 's';
    }
    $sql = 'SELECT f.* FROM ba_faqs f WHERE ' . implode(' AND ', $where) . ' ORDER BY f.sort_order ASC, f.id ASC';
    if (count($params) === 0) {
        $res = $db->query($sql);
        if (!$res) return [];
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    db_prepared_execute($stmt, $types, $params);
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function ba_create_feedback(?mysqli $db, array $payload): int
{
    if (!($db instanceof mysqli)) return 0;
    $userId = !empty($payload['user_id']) ? (int) $payload['user_id'] : null;
    $kind = (string) $payload['kind'];
    $subject = (string) $payload['subject'];
    $message = (string) $payload['message'];
    $photosJson = isset($payload['photos_json']) ? (string) $payload['photos_json'] : null;
    if ($photosJson !== null && trim($photosJson) === '') $photosJson = null;
    if ($photosJson !== null) {
        $stmt = $db->prepare('INSERT INTO ba_feedback (user_id, kind, subject, message, photos_json) VALUES (?,?,?,?,?)');
        if (!$stmt) return 0;
        $uid = $userId > 0 ? $userId : null;
        db_prepared_execute($stmt, 'issss', [$uid, $kind, $subject, $message, $photosJson]);
    } else {
        $stmt = $db->prepare('INSERT INTO ba_feedback (user_id, kind, subject, message) VALUES (?,?,?,?)');
        if (!$stmt) return 0;
        $uid = $userId > 0 ? $userId : null;
        db_prepared_execute($stmt, 'isss', [$uid, $kind, $subject, $message]);
    }
    $newId = (int) ($db->insert_id ?? 0);
    $stmt->close();
    return $newId;
}

function ba_get_user_prefs(?mysqli $db, int $userId): array
{
    if (!($db instanceof mysqli)) return [
        'enable_email_reminders' => 1,
        'enable_sms_reminders' => 1,
        'enable_in_app_reminders' => 1,
        'reminder_hours_before' => 12,
    ];
    $stmt = $db->prepare('SELECT * FROM ba_notification_preferences WHERE user_id = ? LIMIT 1');
    if (!$stmt) return [
        'enable_email_reminders' => 1,
        'enable_sms_reminders' => 1,
        'enable_in_app_reminders' => 1,
        'reminder_hours_before' => 12,
    ];
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $default = [
        'enable_email_reminders' => 1,
        'enable_sms_reminders' => 1,
        'enable_in_app_reminders' => 1,
        'reminder_hours_before' => 12,
    ];
    foreach (['reminder','schedule_change','announcement','report_submit','report_update','feedback_reply'] as $ev) {
        foreach (['in_app','email','sms'] as $ch) {
            $default['ev_' . $ev . '_' . $ch] = 1;
        }
    }
    if (is_array($row)) {
        return array_replace($default, array_filter($row, static fn($v) => $v !== null));
    }
    return $default;
}

function ba_ensure_user_prefs(?mysqli $db, int $userId): void
{
    if (!($db instanceof mysqli)) return;
    $stmt = $db->prepare('INSERT IGNORE INTO ba_notification_preferences (user_id) VALUES (?)');
    if (!$stmt) return;
    db_prepared_execute($stmt, 'i', [$userId]);
    $stmt->close();
}

function ba_save_user_prefs(?mysqli $db, int $userId, array $payload): bool
{
    if (!($db instanceof mysqli)) return false;
    ba_ensure_user_prefs($db, $userId);
    $updates = [
        'enable_email_reminders'  => !empty($payload['enable_email_reminders']) ? 1 : 0,
        'enable_sms_reminders'    => !empty($payload['enable_sms_reminders']) ? 1 : 0,
        'enable_in_app_reminders' => !empty($payload['enable_in_app_reminders']) ? 1 : 0,
        'reminder_hours_before'   => min(72, max(1, (int) ($payload['reminder_hours_before'] ?? 12))),
    ];
    $eventMap = [];
    foreach (['reminder','schedule_change','announcement','report_submit','report_update','feedback_reply'] as $ev) {
        foreach (['in_app','email','sms'] as $ch) {
            $key = 'ev_' . $ev . '_' . $ch;
            $eventMap[$key] = isset($payload[$key]) ? 1 : 0;
        }
    }
    $all = array_replace($eventMap, $updates);
    $fields = [];
    $params = [];
    $types = '';
    foreach ($all as $col => $val) {
        $fields[] = $col . ' = ?';
        $params[] = $val;
        $types .= is_int($val) ? 'i' : 's';
    }
    $params[] = $userId;
    $types .= 'i';
    $sql = 'UPDATE ba_notification_preferences SET ' . implode(', ', $fields) . ' WHERE user_id = ?';
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    db_prepared_execute($stmt, $types, $params);
    $stmt->close();
    return true;
}

function ba_reminder_due_collections(?mysqli $db, int $hoursBefore = 12): array
{
    if (!($db instanceof mysqli)) return [];
    $today = date('Y-m-d');
    $now = time();
    $schedules = ba_list_schedules($db);
    $due = [];
    foreach ($schedules as $s) {
        $type = (string) ($s['schedule_type'] ?? 'regular');
        $dateStr = null;
        if ($type === 'one_time' || $type === 'exception') {
            if (!empty($s['collection_date']) && strtotime($s['collection_date']) !== false) {
                $dateStr = $s['collection_date'];
            }
        } else {
            $dow = (int) date('N', $now) + 1;
            if ($dow > 7) $dow = 1;
            $targetDow = (int) $s['day_of_week'];
            if ($targetDow < 1 || $targetDow > 7) continue;
            $offset = $targetDow - $dow;
            if ($offset < 0) $offset += 7;
            $dateStr = date('Y-m-d', $now + ($offset * 86400));
        }
        if ($dateStr === null) continue;
        $timeStr = $s['time_start'] ?? '06:00:00';
        $collTs = strtotime($dateStr . ' ' . $timeStr);
        if ($collTs === false) continue;
        $diffHours = ($collTs - $now) / 3600;
        if ($diffHours > 0 && $diffHours <= $hoursBefore) {
            $s['computed_next'] = $dateStr;
            $s['computed_hours_until'] = (int) round($diffHours);
            $due[] = $s;
        }
    }
    return $due;
}

