<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_auth.php';

require_api_admin_login();
require_csrf_token();

$mysqli = db();
$admin = current_admin($mysqli);
if (!$admin) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}

$id = (int) ($_POST['id'] ?? 0);
$status = trim((string) ($_POST['status'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));

if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);

$allowed = ['New', 'Ongoing', 'Acknowledge', 'Completed', 'Cancelled'];
if (!in_array($status, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'invalid_status'], 422);
}
if ($note === '') {
    json_response(['ok' => false, 'error' => 'Note is required.'], 422);
}

$where = 'c.id = ?';
$types = 'i';
$params = [$id];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT c.id, c.status, c.assigned_admin_id
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     LIMIT 1'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

$mysqli->begin_transaction();
try {
    // Status changes no longer assign the report. Concerns.assigned_admin_id is
    // written only by admin/api/assign_concern.php, so NULL honestly means
    // "nobody has assigned this" instead of "nobody has actioned this yet".
    $stmt = $mysqli->prepare('UPDATE concerns SET status = ? WHERE id = ?');
    $ok1 = db_prepared_execute($stmt, 'si', [$status, $id]);
    $stmt->close();

    // $row['status'] is the status before this change — the SELECT above already
    // fetches it. Recording it makes the timeline able to show old -> new.
    $stmt = $mysqli->prepare(
        'INSERT INTO concern_timeline (concern_id, status, note, event_type, admin_id, old_status, new_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    // Bind order matches the column list exactly:
    //   concern_id i | status s | note s | event_type s | admin_id i | old_status s | new_status s
    $ok2 = db_prepared_execute($stmt, 'isssiss', [
        $id,
        $status,
        $note,
        'status_change',
        (int) $admin['id'],
        (string) ($row['status'] ?? ''),
        $status,
    ]);
    $stmt->close();

    if (!$ok1 || !$ok2) {
        $mysqli->rollback();
        json_response(['ok' => false, 'error' => 'update_failed'], 500);
    }

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    json_response(['ok' => false, 'error' => 'update_failed'], 500);
}

json_response(['ok' => true]);
