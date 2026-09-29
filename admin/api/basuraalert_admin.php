<?php

declare(strict_types=1);

/* The shutdown drain below keeps running after json_response() has put the body
   on the wire, so it needs the same headroom api/basuraalert_actions.php sets. */
@ignore_user_abort(true);
@set_time_limit(60);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/basuraalert.php';

$_baAdminHandlersStarted = true;
try {

csrf_check();
require_api_admin_login();
require_csrf_token();

$db = db();
$admin = current_admin($db);
if (!$admin) json_response(['ok' => false, 'error' => 'Invalid admin session.'], 401);

/* reply_feedback, save_announcement, push_notification and update_report_status
   all queue sms/email rows through ba_push_notification(). Without this call
   nothing ever sent them and the rows sat at `queued` forever, because the only
   dispatcher left is the cron script and Infinity Free's free tier has no cron. */
ba_register_notification_flush($db);

$adminId = (int) $admin['id'];
$isSuperApi = ($admin['role'] ?? '') === 'super_admin';
$superAdminActions = ['save_waste','delete_waste','save_announcement','delete_announcement','push_notification','save_faq','delete_faq'];
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
if (!$isSuperApi && in_array($action, $superAdminActions, true)) {
    json_response(['ok' => false, 'error' => 'Forbidden — Super Admin permission required for this action.'], 403);
}

function ba_admin_respond(mysqli $db, string $sql, string $types, array $params, string $okMsg): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) return ['ok' => false, 'error' => 'DB error: ' . $db->error];
    db_prepared_execute($stmt, $types, $params);
    $stmt->close();
    return ['ok' => true, 'message' => $okMsg];
}

if ($action === 'save_schedule') {
    $id = (int) ($_POST['id'] ?? 0);
    $barangayId = (int) ($_POST['barangay_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $wasteType = (string) ($_POST['waste_type'] ?? 'Mixed');
    $scheduleType = (string) ($_POST['schedule_type'] ?? 'regular');
    $dow = isset($_POST['day_of_week']) && $_POST['day_of_week'] !== '' ? (int) $_POST['day_of_week'] : null;
    $collDate = !empty($_POST['collection_date']) ? trim((string) $_POST['collection_date']) : null;
    $ts = !empty($_POST['time_start']) ? trim((string) $_POST['time_start']) : null;
    $te = !empty($_POST['time_end']) ? trim((string) $_POST['time_end']) : null;
    $effFrom = !empty($_POST['effective_from']) ? trim((string) $_POST['effective_from']) : null;
    $effTo = !empty($_POST['effective_to']) ? trim((string) $_POST['effective_to']) : null;
    $status = (string) ($_POST['status'] ?? 'Draft');
    $notes = !empty($_POST['notes']) ? trim((string) $_POST['notes']) : null;
    $source = in_array(($_POST['source'] ?? 'curbside'), ['curbside','dropoff'], true) ? (string) $_POST['source'] : 'curbside';
    $dropoffRangeId = !empty($_POST['dropoff_range_id']) ? (int) $_POST['dropoff_range_id'] : 0;

    if ($dropoffRangeId <= 0 && $source === 'dropoff') {
        json_response(['ok' => false, 'error' => 'When Source=Drop-off: pick a specific range row from the drop-off schedule list.']);
    }

    if ($source === 'dropoff' && $dropoffRangeId > 0) {
        $stmt = $db->prepare('
            SELECT ds.id, ds.day_of_week AS ds_dow, ds.waste_type AS ds_wt, ds.time_start AS ds_ts, ds.time_end AS ds_te,
                   ds.effective_from AS ds_ff, ds.effective_to AS ds_tt,
                   d.id AS dropoff_id, d.barangay_id AS dropoff_barangay, d.spot_name AS dropoff_name, d.status AS dropoff_status
            FROM ba_dropoff_schedules ds INNER JOIN ba_dropoff_points d ON d.id = ds.dropoff_id
            WHERE ds.id = ? LIMIT 1
        ');
        if (!$stmt) json_response(['ok' => false, 'error' => $db->error]);
        db_prepared_execute($stmt, 'i', [$dropoffRangeId]);
        $res = $stmt->get_result();
        $rangeRow = $res ? ($res->fetch_assoc() ?: null) : null;
        $stmt->close();
        if (!$rangeRow) json_response(['ok' => false, 'error' => 'Drop-off range row not found.']);
        $dsDow = (int) $rangeRow['ds_dow'];
        if ($dsDow < 0 || $dsDow > 6) json_response(['ok' => false, 'error' => 'Invalid drop-off day_of_week range.']);
        $collDow = $dsDow + 1;
        if ($collDow > 7) $collDow = 7;
        $barangayId = (int) $rangeRow['dropoff_barangay'];
        $wasteType = (string) $rangeRow['ds_wt'];
        $scheduleType = 'regular';
        $dow = $collDow;
        $collDate = null;
        $ts = (string) $rangeRow['ds_ts'];
        $te = (string) $rangeRow['ds_te'];
        $effFrom = $rangeRow['ds_ff'] !== null && $rangeRow['ds_ff'] !== '' ? (string) $rangeRow['ds_ff'] : null;
        $effTo = $rangeRow['ds_tt'] !== null && $rangeRow['ds_tt'] !== '' ? (string) $rangeRow['ds_tt'] : null;
        $dropoffName = (string) $rangeRow['dropoff_name'];
        if ($title === '' || strcasecmp($title, 'New Schedule') === 0) {
            $title = $dropoffName . ' (Drop-off)';
            if (strlen($title) > 120) $title = substr($title, 0, 117) . '...';
        }
    }

    if ($barangayId <= 0 || $title === '') json_response(['ok' => false, 'error' => 'Barangay and title are required.']);
    if (!in_array($wasteType, ['Biodegradable','Non-Biodegradable','Recyclable','Special','Mixed','Hazardous','Bulky'], true)) json_response(['ok' => false, 'error' => 'Invalid waste type.']);
    if (!in_array($scheduleType, ['regular','recurring','one_time','exception'], true)) json_response(['ok' => false, 'error' => 'Invalid schedule type.']);
    if (!in_array($status, ['Draft','Published','Archived'], true)) json_response(['ok' => false, 'error' => 'Invalid status.']);
    if (in_array($scheduleType, ['one_time','exception'], true)) {
        if ($collDate === null || !strtotime($collDate)) json_response(['ok' => false, 'error' => 'Exact collection date required for one-time/exception entries.']);
    } else {
        if ($dow === null || $dow < 1 || $dow > 7) json_response(['ok' => false, 'error' => 'Day of week required for regular/recurring schedules.']);
    }

    /* Checked after the drop-off range copy above, so hours copied from a range
       row are held to the same rule as hand-typed ones. */
    $windowError = ba_validate_collection_window($ts, $te);
    if ($windowError !== null) json_response(['ok' => false, 'error' => $windowError]);

    if ($id > 0) {
        try {
            $db->begin_transaction();
            $existing = null;
            $es = $db->prepare('SELECT linked_type, linked_id FROM ba_collection_schedules WHERE id = ? LIMIT 1');
            if ($es) {
                db_prepared_execute($es, 'i', [$id]);
                $eres = $es->get_result();
                if ($eres) $existing = $eres->fetch_assoc() ?: null;
                $es->close();
            }
            if ($existing && $existing['linked_type'] === 'dropoff' && $source !== 'dropoff') {
                ba_unlink_bidirectional($db, $id, null);
            }
            $setExtra = '';
            $paramsExtra = [];
            $typesExtra = '';
            if ($source === 'dropoff') {
                $setExtra = ', linked_type = ?, linked_id = ?';
                $paramsExtra = ['dropoff', (int) ($rangeRow['dropoff_id'] ?? 0)];
                $typesExtra = 'si';
            } else {
                $setExtra = ', linked_type = \'none\', linked_id = NULL, linked_group_uid = NULL';
            }
            $sql = 'UPDATE ba_collection_schedules SET barangay_id=?, title=?, waste_type=?, schedule_type=?, day_of_week=?, collection_date=?, time_start=?, time_end=?, effective_from=?, effective_to=?, status=?, notes=?, created_by_admin_id=COALESCE(created_by_admin_id, ?)' . $setExtra . ' WHERE id=?';
            $types = 'isssisssssssi' . $typesExtra . 'i';
            $params = [$barangayId, $title, $wasteType, $scheduleType, $dow, $collDate, $ts, $te, $effFrom, $effTo, $status, $notes, $adminId];
            $params = array_merge($params, $paramsExtra, [$id]);
            $stmt = $db->prepare($sql);
            if (!$stmt) throw new mysqli_sql_exception($db->error);
            db_prepared_execute($stmt, $types, $params);
            $stmt->close();
            if ($source === 'dropoff' && isset($rangeRow) && is_array($rangeRow)) {
                $updRev = $db->prepare('UPDATE ba_dropoff_schedules SET linked_type=\'collection\', linked_id=? WHERE id=? LIMIT 1');
                if ($updRev) { db_prepared_execute($updRev, 'ii', [$id, $dropoffRangeId]); $updRev->close(); }
            }
            $db->commit();
            $res = ['ok' => true, 'message' => 'Schedule updated.'];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $_) {}
            $err = (string) $e->getMessage();
            if (stripos($err, 'duplicate') !== false && stripos($err, 'uniq_coll_linked_waste') !== false) {
                $res = ['ok' => false, 'error' => 'A linked schedule already exists for this drop-off range + waste. Use Schedule Type filter to find the existing row.'];
            } else {
                $res = ['ok' => false, 'error' => substr($err, 0, 200)];
            }
        }
    } else {
        try {
            $db->begin_transaction();
            $cols = 'barangay_id, title, waste_type, schedule_type, day_of_week, collection_date, time_start, time_end, effective_from, effective_to, status, notes, created_by_admin_id';
            $placeholders = '?,?,?,?,?,?,?,?,?,?,?,?,?';
            $types = 'isssisssssssi';
            $params = [$barangayId, $title, $wasteType, $scheduleType, $dow, $collDate, $ts, $te, $effFrom, $effTo, $status, $notes, $adminId];
            if ($source === 'dropoff') {
                $cols .= ', linked_type, linked_id';
                $placeholders .= ',?,?';
                $types .= 'si';
                $params[] = 'dropoff';
                $params[] = (int) ($rangeRow['dropoff_id'] ?? 0);
            }
            $stmt = $db->prepare("INSERT INTO ba_collection_schedules ({$cols}) VALUES ({$placeholders})");
            if (!$stmt) throw new mysqli_sql_exception($db->error);
            db_prepared_execute($stmt, $types, $params);
            $stmt->close();
            $newId = (int) $db->insert_id;
            if ($source === 'dropoff' && $newId > 0 && isset($rangeRow)) {
                $updRev = $db->prepare('UPDATE ba_dropoff_schedules SET linked_type=\'collection\', linked_id=? WHERE id=? LIMIT 1');
                if ($updRev) { db_prepared_execute($updRev, 'ii', [$newId, $dropoffRangeId]); $updRev->close(); }
            }
            $db->commit();
            $res = ['ok' => true, 'message' => 'Schedule created.', 'id' => $newId];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $_) {}
            $err = (string) $e->getMessage();
            if (stripos($err, 'duplicate') !== false && stripos($err, 'uniq_coll_linked_waste') !== false) {
                $res = ['ok' => false, 'error' => 'A linked schedule already exists for this drop-off range + waste. Use Schedule Type filter to find the existing row.'];
            } else {
                $res = ['ok' => false, 'error' => substr($err, 0, 200)];
            }
        }
    }
    json_response($res);
}

if ($action === 'delete_schedule') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid id.']);
    try {
        $db->begin_transaction();
        ba_unlink_bidirectional($db, $id, null);
        $stmt = db_bind_and_execute($db, 'DELETE FROM ba_collection_schedules WHERE id=? LIMIT 1', 'i', [$id]);
        $db->commit();
        json_response(['ok' => $stmt !== false, 'message' => $stmt !== false ? 'Schedule deleted.' : 'Delete failed: ' . $db->error]);
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        json_response(['ok' => false, 'error' => substr((string)$e->getMessage(), 0, 200)]);
    }
}

if ($action === 'list_dropoff_ranges') {
    $dropoffId = (int) ($_POST['dropoff_id'] ?? 0);
    if ($dropoffId <= 0) json_response(['ok' => true, 'ranges' => []]);
    $dowNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    $rows = [];
    $stmt = $db->prepare("SELECT id, day_of_week, waste_type, time_start, time_end, effective_from, effective_to, linked_type, linked_id FROM ba_dropoff_schedules WHERE dropoff_id = ? ORDER BY day_of_week, time_start");
    if (!$stmt) json_response(['ok' => false, 'error' => $db->error]);
    db_prepared_execute($stmt, 'i', [$dropoffId]);
    $res = $stmt->get_result();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $dow = (int) $r['day_of_week'];
            $dowName = isset($dowNames[$dow]) ? $dowNames[$dow] : '?';
            $wt = (string) $r['waste_type'];
            $ts = substr((string) $r['time_start'], 0, 5);
            $te = substr((string) $r['time_end'], 0, 5);
            $label = "{$dowName} {$ts}–{$te} · {$wt}";
            if (!empty($r['effective_from']) || !empty($r['effective_to'])) {
                $ef = $r['effective_from'] ?: '∞';
                $et = $r['effective_to'] ?: '∞';
                $label .= " (valid {$ef}→{$et})";
            }
            if (($r['linked_type'] ?? '') === 'collection' && !empty($r['linked_id'])) {
                $label .= ' · <i data-lucide="link" class="lucide-14"></i> linked to Schedule #' . (int) $r['linked_id'];
            }
            $rows[] = [
                'id' => (int) $r['id'],
                'label' => $label,
                'day_of_week' => $dow,
                'waste_type' => $wt,
                'time_start' => (string) $r['time_start'],
                'time_end' => (string) $r['time_end'],
                'effective_from' => $r['effective_from'],
                'effective_to' => $r['effective_to'],
                'linked_type' => (string) ($r['linked_type'] ?? ''),
                'linked_id' => (int) ($r['linked_id'] ?? 0),
            ];
        }
    }
    $stmt->close();
    json_response(['ok' => true, 'ranges' => $rows]);
}

if ($action === 'unlink_schedule') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid id.']);
    $ok = ba_unlink_bidirectional($db, $id, null);
    json_response(['ok' => $ok, 'message' => $ok ? 'Schedule unlinked. Schedule hours now editable as a regular curbside row.' : 'Unlink failed.']);
}

if ($action === 'save_waste') {
    $id = (int) ($_POST['id'] ?? 0);
    $item = trim((string) ($_POST['item_name'] ?? ''));
    $cat = (string) ($_POST['category'] ?? 'Biodegradable');
    $accepted = isset($_POST['is_accepted']) ? 1 : 0;
    $prep = !empty($_POST['prep_guidance']) ? trim((string) $_POST['prep_guidance']) : null;
    $disp = !empty($_POST['disposal_guidance']) ? trim((string) $_POST['disposal_guidance']) : null;
    $status = (string) ($_POST['status'] ?? 'Published');
    if ($item === '' || strlen($item) > 190) json_response(['ok' => false, 'error' => 'Item name required (max 190).']);
    if (!in_array($cat, ['Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special'], true)) json_response(['ok' => false, 'error' => 'Invalid category.']);
    if (!in_array($status, ['Published','Archived'], true)) json_response(['ok' => false, 'error' => 'Invalid status.']);

    if ($id > 0) {
        json_response(ba_admin_respond($db, 'UPDATE ba_waste_guide SET item_name=?, category=?, is_accepted=?, prep_guidance=?, disposal_guidance=?, status=?, created_by_admin_id=COALESCE(created_by_admin_id,?) WHERE id=?', 'ssissssi', [$item, $cat, $accepted, $prep, $disp, $status, $adminId, $id], 'Guide entry updated.'));
    }
    $stmt = $db->prepare('INSERT INTO ba_waste_guide (item_name, category, is_accepted, prep_guidance, disposal_guidance, status, created_by_admin_id) VALUES (?,?,?,?,?,?,?)');
    if (!$stmt) json_response(['ok' => false, 'error' => $db->error]);
    db_prepared_execute($stmt, 'ssisssi', [$item, $cat, $accepted, $prep, $disp, $status, $adminId]);
    $stmt->close();
    json_response(['ok' => true, 'message' => 'Guide entry created.', 'id' => (int) $db->insert_id]);
}

if ($action === 'delete_waste') {
    $id = (int) ($_POST['id'] ?? 0);
    json_response(ba_admin_respond($db, 'DELETE FROM ba_waste_guide WHERE id=?', 'i', [$id], 'Guide entry deleted.'));
}

if ($action === 'save_announcement') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $kind = (string) ($_POST['kind'] ?? 'General');
    $scope = (string) ($_POST['scope'] ?? 'all');
    $target = !empty($_POST['target_barangay_id']) ? (int) $_POST['target_barangay_id'] : null;
    $content = trim((string) ($_POST['content'] ?? ''));
    $effective = !empty($_POST['effective_date']) ? trim((string) $_POST['effective_date']) : null;
    $revised = !empty($_POST['revised_schedule_info']) ? trim((string) $_POST['revised_schedule_info']) : null;
    $status = (string) ($_POST['status'] ?? 'Draft');
    if ($title === '' || $content === '') json_response(['ok' => false, 'error' => 'Title and content are required.']);
    if (!in_array($kind, ['Holiday','Disruption','Delay','Cancellation','Resumption','Schedule_Change','General'], true)) json_response(['ok' => false, 'error' => 'Invalid kind.']);
    if (!in_array($scope, ['all','barangay'], true)) json_response(['ok' => false, 'error' => 'Invalid scope.']);
    if ($scope === 'barangay' && (!$target || $target <= 0)) json_response(['ok' => false, 'error' => 'Target barangay required.']);
    if (!in_array($status, ['Draft','Published','Archived'], true)) json_response(['ok' => false, 'error' => 'Invalid status.']);

    if ($id > 0) {
        $r = ba_admin_respond($db, 'UPDATE ba_announcements SET title=?, kind=?, scope=?, target_barangay_id=?, content=?, effective_date=?, revised_schedule_info=?, status=?, published_at=CASE WHEN ?=\'Published\' THEN COALESCE(published_at, CURRENT_TIMESTAMP) ELSE published_at END, created_by_admin_id=COALESCE(created_by_admin_id,?) WHERE id=?', 'sssisssssii', [$title, $kind, $scope, $target, $content, $effective, $revised, $status, $status, $adminId, $id], 'Announcement updated.');
        if ($r['ok'] && $status === 'Published') {
            $nameFilter = null;
            if ($scope === 'barangay' && $target > 0) {
                $stmt = $db->prepare('SELECT name FROM barangays WHERE id=? LIMIT 1');
                if ($stmt) { db_prepared_execute($stmt, 'i', [$target]); $res = $stmt->get_result(); $rr = $res ? $res->fetch_assoc() : null; $stmt->close(); if ($rr) $nameFilter = $rr['name']; }
            }
            $sql = 'SELECT u.id, u.mobile, u.email FROM users u WHERE COALESCE(u.barangay, \'\') <> \'\'';
            $params = []; $types = '';
            if ($nameFilter !== null) { $sql .= ' AND u.barangay = ?'; $params[] = $nameFilter; $types = 's'; }
            $q = $db->prepare($sql);
            $publishStats = ['users_total' => 0, 'sent_in_app' => 0, 'sent_sms' => 0, 'sent_email' => 0, 'skip_no_email' => 0, 'skip_no_mobile' => 0, 'skip_prefs_off' => 0, 'sms_fail' => 0, 'email_fail' => 0, 'notif_ids' => []];
            if ($q) {
                if ($types !== '') db_prepared_execute($q, $types, $params); else db_prepared_execute($q, '', []);
                $res = $q->get_result();
                if ($res) {
                    $users = [];
                    while ($u = $res->fetch_assoc()) $users[] = $u;
                    $publishStats['users_total'] = count($users);
                    $smsAvailable = (string)(defined('TEXTBEE_API_KEY') ? TEXTBEE_API_KEY : '') !== '';
                    $emailAvailable = (string)(defined('BREVO_API_KEY') ? BREVO_API_KEY : '') !== '' && (string)(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '') !== '' && strpos((string)(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : ''), '@') !== false;
                    foreach ($users as $u) {
                        $uid = (int)$u['id'];
                        $hasMobile = !empty($u['mobile']) && normalize_ph_mobile((string)$u['mobile']) !== false;
                        $hasEmail = !empty($u['email']) && filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL);
                        $prefs = ba_get_user_prefs($db, $uid);
                        $channels = [];
                        foreach (['in_app','email','sms'] as $ch) {
                            $globalKey = 'enable_' . $ch . '_reminders';
                            if (empty($prefs[$globalKey])) continue;
                            $eventKey = 'ev_announcement_' . $ch;
                            if (isset($prefs[$eventKey]) && (int)$prefs[$eventKey] !== 1) continue;
                            if ($ch === 'sms' && !$hasMobile) { $publishStats['skip_no_mobile']++; continue; }
                            if ($ch === 'email' && !$hasEmail) { $publishStats['skip_no_email']++; continue; }
                            $channels[] = $ch;
                        }
                        if (count($channels) === 0) { $publishStats['skip_prefs_off']++; $channels[] = 'in_app'; }
                        $lastCh = '';
                        foreach ($channels as $ch) {
                            $nid = ba_push_notification($db, $uid, 'announcement', 'Announcement: ' . $title, $content, $ch, ['table' => 'ba_announcements', 'id' => $id]);
                            if ($nid > 0) $publishStats['notif_ids'][] = $nid;
                            $lastCh = $ch;
                        }
                        if (in_array('in_app', $channels, true)) $publishStats['sent_in_app']++;
                        if (in_array('sms', $channels, true)) {
                            if (!$smsAvailable) { $publishStats['sms_fail']++; }
                            else { $publishStats['sent_sms']++; }
                        }
                        if (in_array('email', $channels, true)) {
                            if (!$emailAvailable) { $publishStats['email_fail']++; }
                            else { $publishStats['sent_email']++; }
                        }
                    }
                }
                $q->close();
            }
            $r['publish_stats'] = $publishStats;
            if (session_status() === PHP_SESSION_NONE) @session_start();
            $_SESSION['ba_announce_publish_stats'] = $publishStats;
        }
        json_response($r);
    }
    $stmt = $db->prepare('INSERT INTO ba_announcements (title, kind, scope, target_barangay_id, content, effective_date, revised_schedule_info, status, published_at, created_by_admin_id) VALUES (?,?,?,?,?,?,?, ?, CASE WHEN ?=\'Published\' THEN CURRENT_TIMESTAMP ELSE NULL END, ?)');
    if (!$stmt) json_response(['ok' => false, 'error' => $db->error]);
    db_prepared_execute($stmt, 'sssissssis', [$title, $kind, $scope, $target, $content, $effective, $revised, $status, $status, $adminId]);
    $stmt->close();
    $newId = (int) $db->insert_id;
    $publishStats = null;
    if ($status === 'Published') {
        $nameFilter = null;
        if ($scope === 'barangay' && $target > 0) {
            $stmt = $db->prepare('SELECT name FROM barangays WHERE id=? LIMIT 1');
            if ($stmt) { db_prepared_execute($stmt, 'i', [$target]); $res = $stmt->get_result(); $rr = $res ? $res->fetch_assoc() : null; $stmt->close(); if ($rr) $nameFilter = $rr['name']; }
        }
        $sql = 'SELECT u.id, u.mobile, u.email FROM users u WHERE COALESCE(u.barangay, \'\') <> \'\'';
        $params = []; $types = '';
        if ($nameFilter !== null) { $sql .= ' AND u.barangay = ?'; $params[] = $nameFilter; $types = 's'; }
        $q = $db->prepare($sql);
        $publishStats = ['users_total' => 0, 'sent_in_app' => 0, 'sent_sms' => 0, 'sent_email' => 0, 'skip_no_email' => 0, 'skip_no_mobile' => 0, 'skip_prefs_off' => 0, 'sms_fail' => 0, 'email_fail' => 0, 'notif_ids' => []];
        if ($q) {
            if ($types !== '') db_prepared_execute($q, $types, $params); else db_prepared_execute($q, '', []);
            $res = $q->get_result();
            if ($res) {
                $users = [];
                while ($u = $res->fetch_assoc()) $users[] = $u;
                $publishStats['users_total'] = count($users);
                $smsAvailable = (string)(defined('TEXTBEE_API_KEY') ? TEXTBEE_API_KEY : '') !== '';
                $emailAvailable = (string)(defined('BREVO_API_KEY') ? BREVO_API_KEY : '') !== '' && (string)(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '') !== '' && strpos((string)(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : ''), '@') !== false;
                foreach ($users as $u) {
                    $uid = (int)$u['id'];
                    $hasMobile = !empty($u['mobile']) && normalize_ph_mobile((string)$u['mobile']) !== false;
                    $hasEmail = !empty($u['email']) && filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL);
                    $prefs = ba_get_user_prefs($db, $uid);
                    $channels = [];
                    foreach (['in_app','email','sms'] as $ch) {
                        $globalKey = 'enable_' . $ch . '_reminders';
                        if (empty($prefs[$globalKey])) continue;
                        $eventKey = 'ev_announcement_' . $ch;
                        if (isset($prefs[$eventKey]) && (int)$prefs[$eventKey] !== 1) continue;
                        if ($ch === 'sms' && !$hasMobile) { $publishStats['skip_no_mobile']++; continue; }
                        if ($ch === 'email' && !$hasEmail) { $publishStats['skip_no_email']++; continue; }
                        $channels[] = $ch;
                    }
                    if (count($channels) === 0) { $publishStats['skip_prefs_off']++; $channels[] = 'in_app'; }
                    foreach ($channels as $ch) {
                        $nid = ba_push_notification($db, $uid, 'announcement', 'Announcement: ' . $title, $content, $ch, ['table' => 'ba_announcements', 'id' => $newId]);
                        if ($nid > 0) $publishStats['notif_ids'][] = $nid;
                    }
                    if (in_array('in_app', $channels, true)) $publishStats['sent_in_app']++;
                    if (in_array('sms', $channels, true)) {
                        if (!$smsAvailable) $publishStats['sms_fail']++;
                        else $publishStats['sent_sms']++;
                    }
                    if (in_array('email', $channels, true)) {
                        if (!$emailAvailable) $publishStats['email_fail']++;
                        else $publishStats['sent_email']++;
                    }
                }
            }
            $q->close();
        }
    }
    $out = ['ok' => true, 'message' => 'Announcement created.', 'id' => $newId];
    if ($publishStats !== null) {
        $out['publish_stats'] = $publishStats;
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION['ba_announce_publish_stats'] = $publishStats;
    }
    json_response($out);
}

if ($action === 'delete_announcement') {
    $id = (int) ($_POST['id'] ?? 0);
    json_response(ba_admin_respond($db, 'DELETE FROM ba_announcements WHERE id=?', 'i', [$id], 'Announcement deleted.'));
}

if ($action === 'update_report_status') {
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $newStatus = (string) ($_POST['status'] ?? '');
    $note = !empty($_POST['note']) ? trim((string) $_POST['note']) : null;
    $resolutionNote = !empty($_POST['resolution_note']) ? trim((string) $_POST['resolution_note']) : null;
    $internalNote = isset($_POST['internal_note']) ? trim((string) $_POST['internal_note']) : null;
    if ($reportId <= 0) json_response(['ok' => false, 'error' => 'Invalid report id.']);
    $allowed = ba_report_status_options();
    $newStatusN = ba_normalize_report_status($newStatus);
    if (!in_array($newStatusN, $allowed, true)) json_response(['ok' => false, 'error' => 'Invalid status. Allowed: ' . implode(', ', $allowed)]);
    if (in_array($newStatusN, ['Completed','Rejected'], true) && ($resolutionNote === null || $resolutionNote === '')) {
        json_response(['ok' => false, 'error' => 'Resolution note is required before closing a report as Completed or Rejected.']);
    }
    $ok = ba_update_report_status($db, $reportId, $newStatusN, $adminId, $note, $resolutionNote, $internalNote);
    json_response(['ok' => $ok, 'message' => $ok ? 'Status updated. Resident notified per saved preferences.' : 'Failed to update status.']);
}

if ($action === 'push_notification') {
    $targetKind = (string) ($_POST['target_kind'] ?? 'all');
    $barangayId = !empty($_POST['target_barangay_id']) ? (int) $_POST['target_barangay_id'] : 0;
    $targetUserRaw = trim((string) ($_POST['target_user_id'] ?? ''));
    $type = in_array(($_POST['type'] ?? 'schedule_change'), ['reminder','schedule_change','announcement','report_submit','report_update','feedback_reply'], true) ? (string) $_POST['type'] : 'schedule_change';
    $title = trim((string) ($_POST['title'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $channels = isset($_POST['channels']) && is_array($_POST['channels']) ? $_POST['channels'] : ['in_app'];
    if ($title === '' || $message === '') json_response(['ok' => false, 'error' => 'Title and message required.']);

    $users = [];
    if ($targetKind === 'user' && $targetUserRaw !== '') {
        if (ctype_digit($targetUserRaw)) {
            $uid = (int) $targetUserRaw;
            $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            if ($stmt) { db_prepared_execute($stmt, 'i', [$uid]); $res = $stmt->get_result(); $r = $res ? $res->fetch_assoc() : null; $stmt->close(); if ($r) $users[] = (int) $r['id']; }
        } elseif (filter_var($targetUserRaw, FILTER_VALIDATE_EMAIL)) {
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            if ($stmt) { db_prepared_execute($stmt, 's', [$targetUserRaw]); $res = $stmt->get_result(); $r = $res ? $res->fetch_assoc() : null; $stmt->close(); if ($r) $users[] = (int) $r['id']; }
        }
        if (count($users) === 0) json_response(['ok' => false, 'error' => 'No user found. Enter a valid email address (unique) or a numeric user ID. Name or mobile searches are disabled to prevent sending to the wrong person.']);
    } elseif ($targetKind === 'barangay' && $barangayId > 0) {
        $stmt = $db->prepare('SELECT name FROM barangays WHERE id=? LIMIT 1');
        $name = null;
        if ($stmt) { db_prepared_execute($stmt, 'i', [$barangayId]); $res = $stmt->get_result(); $r = $res ? $res->fetch_assoc() : null; $stmt->close(); if ($r) $name = $r['name']; }
        if ($name) {
            $q = $db->prepare('SELECT id FROM users WHERE barangay = ?');
            if ($q) {
                db_prepared_execute($q, 's', [$name]);
                $res = $q->get_result();
                if ($res) while ($u = $res->fetch_assoc()) $users[] = (int) $u['id'];
                $q->close();
            }
        }
    } else {
        $res = $db->query('SELECT id FROM users WHERE active = 1');
        if ($res) while ($u = $res->fetch_assoc()) $users[] = (int) $u['id'];
    }

    $sentCount = 0;
    foreach ($users as $uid) {
        foreach ($channels as $ch) {
            if (!in_array($ch, ['in_app','email','sms'], true)) continue;
            ba_push_notification($db, (int) $uid, $type, $title, $message, $ch);
            $sentCount++;
        }
    }
    json_response(['ok' => true, 'message' => "Pushed {$sentCount} notification(s) across " . count($users) . " recipient(s)."]);
}

if ($action === 'reply_feedback') {
    $id = (int) ($_POST['id'] ?? 0);
    $reply = trim((string) ($_POST['reply'] ?? ''));
    if ($id <= 0 || $reply === '') json_response(['ok' => false, 'error' => 'Reply required.']);
    $stmt = $db->prepare('UPDATE ba_feedback SET reply=?, replied_by_admin_id=?, replied_at=CURRENT_TIMESTAMP WHERE id=?');
    if (!$stmt) json_response(['ok' => false, 'error' => $db->error]);
    db_prepared_execute($stmt, 'sii', [$reply, $adminId, $id]);
    $stmt->close();
    $fbRes = $db->query('SELECT user_id, subject FROM ba_feedback WHERE id = ' . (int) $id . ' LIMIT 1');
    if ($fbRes && ($fb = $fbRes->fetch_assoc()) && (int) $fb['user_id'] > 0) {
        ba_push_notification_via_prefs($db, (int) $fb['user_id'], 'feedback_reply', 'Reply: ' . $fb['subject'], $reply, ['table' => 'ba_feedback', 'id' => $id]);
    }
    json_response(['ok' => true, 'message' => 'Reply sent.']);
}

if ($action === 'save_faq') {
    $id = (int) ($_POST['id'] ?? 0);
    $q = trim((string) ($_POST['question'] ?? ''));
    $a = trim((string) ($_POST['answer'] ?? ''));
    $category = !empty($_POST['category']) ? trim((string) $_POST['category']) : null;
    $status = (string) ($_POST['status'] ?? 'Published');
    if ($q === '' || $a === '') json_response(['ok' => false, 'error' => 'Question and answer required.']);
    if ($id > 0) {
        json_response(ba_admin_respond($db, 'UPDATE ba_faqs SET question=?, answer=?, category=?, status=?, created_by_admin_id=COALESCE(created_by_admin_id,?) WHERE id=?', 'ssssii', [$q, $a, $category, $status, $adminId, $id], 'FAQ updated.'));
    }
    $sortRes = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS mx FROM ba_faqs');
    $sortVal = 1;
    if ($sortRes && ($r = $sortRes->fetch_assoc())) $sortVal = (int) ($r['mx'] ?? 0) + 1;
    $sortRes && $sortRes->free();
    $stmt = $db->prepare('INSERT INTO ba_faqs (question, answer, category, sort_order, status, created_by_admin_id) VALUES (?,?,?,?,?,?)');
    if (!$stmt) json_response(['ok' => false, 'error' => $db->error]);
    db_prepared_execute($stmt, 'sssisi', [$q, $a, $category, $sortVal, $status, $adminId]);
    $stmt->close();
    json_response(['ok' => true, 'message' => 'FAQ created.', 'id' => (int) $db->insert_id, 'order' => $sortVal]);
}

if ($action === 'delete_faq') {
    $id = (int) ($_POST['id'] ?? 0);
    json_response(ba_admin_respond($db, 'DELETE FROM ba_faqs WHERE id=?', 'i', [$id], 'FAQ deleted.'));
}

json_response(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);

} catch (Throwable $e) {
    $err = 'Server error while processing your admin request. Please try again in a moment.';
    $hint = '';
    $eClass = get_class($e);
    $eMsg = $e->getMessage();
    $eFile = basename((string) $e->getFile());
    $eLine = $e->getLine();
    @error_log('[basuraalert_admin] uncaught ' . $eClass . ': ' . $eMsg . ' @ ' . $eFile . ':' . $eLine . PHP_EOL, 3, __DIR__ . '/../../app_error.log');
    if ($e instanceof InvalidArgumentException || $eClass === 'InvalidArgumentException'
        || $e instanceof mysqli_sql_exception || stripos($eClass, 'mysqli') !== false
        || $e instanceof RuntimeException) {
        $hint = $eMsg;
    }
    if (ini_get('display_errors') && (string) ini_get('display_errors') !== '' && strcasecmp((string) ini_get('display_errors'), 'off') !== 0 && strcasecmp((string) ini_get('display_errors'), '0') !== 0) {
        $hint = $hint !== '' ? $hint . ' · ' : '';
        $hint .= $eClass . ' @ ' . $eFile . ':' . $eLine;
    }
    json_response(['ok' => false, 'error' => $err, 'hint' => $hint], 500);
}
