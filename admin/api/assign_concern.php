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
$targetId = (int) ($_POST['admin_id'] ?? 0);   // 0 === release

if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
if ($targetId < 0) json_response(['ok' => false, 'error' => 'invalid_admin_id'], 422);

$isSuper = ($admin['role'] ?? '') === 'super_admin';
$selfId = (int) $admin['id'];

/* A Department Admin may only claim a report for themselves. Routing someone
   else's work is a Super Admin decision, so the restriction is enforced here
   rather than only in the UI. */
if (!$isSuper && $targetId !== $selfId) {
    json_response(['ok' => false, 'error' => 'You can only assign concerns to yourself.'], 403);
}

/* Department scoping, identical to update_status.php: a Department Admin only
   ever sees concerns belonging to their own department. */
$where = 'c.id = ?';
$types = 'i';
$params = [$id];

if (!$isSuper) {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT c.id, c.status, c.assigned_admin_id, prev.name AS prev_name
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     LEFT JOIN admins prev ON prev.id = c.assigned_admin_id
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

$currentId = (int) ($row['assigned_admin_id'] ?? 0);
$currentName = (string) ($row['prev_name'] ?? '');

if ($currentId === $targetId) {
    json_response([
        'ok' => true,
        'unchanged' => true,
        'assigned_admin_id' => $targetId > 0 ? $targetId : null,
    ]);
}

$targetName = '';
if ($targetId > 0) {
    $stmt = $mysqli->prepare('SELECT name FROM admins WHERE id = ? AND active = 1 LIMIT 1');
    db_prepared_execute($stmt, 'i', [$targetId]);
    $res = $stmt->get_result();
    $target = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$target) {
        json_response(['ok' => false, 'error' => 'That admin does not exist or is inactive.'], 422);
    }
    $targetName = (string) $target['name'];
}

if ($targetId > 0 && $currentId > 0) {
    $note = 'Reassigned from ' . ($currentName !== '' ? $currentName : ('Admin #' . $currentId)) . ' to ' . $targetName;
} elseif ($targetId > 0) {
    $note = 'Assigned to ' . $targetName;
} else {
    $note = $currentName !== '' ? ('Released by ' . $currentName) : 'Released';
}

$mysqli->begin_transaction();
try {
    $stmt = $mysqli->prepare('UPDATE concerns SET assigned_admin_id = ? WHERE id = ?');
    $ok1 = db_prepared_execute($stmt, 'ii', [$targetId > 0 ? $targetId : null, $id]);
    $stmt->close();

    /* status is NOT NULL, so an assignment event still has to carry the
       concern's current status. event_type is what distinguishes it from a
       real status change when the timeline is rendered.

       old_status / new_status are deliberately left NULL here. They are
       varchar status-name columns written by update_status.php to record a
       status transition; putting admin ids into them from this endpoint would
       give one column two incompatible meanings. The human-readable change is
       in note, and the actor is in admin_id. */
    $stmt = $mysqli->prepare(
        'INSERT INTO concern_timeline (concern_id, status, note, event_type, admin_id, old_status, new_status)
         VALUES (?, ?, ?, ?, ?, NULL, NULL)'
    );
    $ok2 = db_prepared_execute($stmt, 'isssi', [
        $id,
        (string) ($row['status'] ?? 'New'),
        $note,
        'assigned',
        $selfId,
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

json_response([
    'ok' => true,
    'assigned_admin_id' => $targetId > 0 ? $targetId : null,
    'assignee_name' => $targetName,
    'note' => $note,
]);
