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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($admin['role'] ?? '') !== 'super_admin') {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'set_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['active'] ?? -1);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
        if (!in_array($active, [0, 1], true)) json_response(['ok' => false, 'error' => 'invalid_active'], 422);

        $stmt = $mysqli->prepare('UPDATE departments SET active = ? WHERE id = ?');
        $ok = db_prepared_execute($stmt, 'ii', [$active, $id]);
        $stmt->close();

        if (!$ok) {
            json_response(['ok' => false, 'error' => 'update_failed'], 500);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'invalid_action'], 422);
}

$includeInactive = (string) ($_GET['include_inactive'] ?? '') === '1';
$where = '';
$types = '';
$params = [];

$lockedDepartmentId = null;
if (($admin['role'] ?? '') === 'department_admin') {
    $lockedDepartmentId = (int) ($admin['department_id'] ?? 0);
    $where = 'd.active = 1 AND d.id = ?';
    $types .= 'i';
    $params[] = $lockedDepartmentId;
} elseif (($admin['role'] ?? '') === 'super_admin' && $includeInactive) {
    $where = '1=1';
} else {
    $where = 'd.active = 1';
}

$select = (($admin['role'] ?? '') === 'super_admin' && $includeInactive)
    ? 'SELECT d.id, d.name, d.active FROM departments d WHERE ' . $where . ' ORDER BY d.name ASC'
    : 'SELECT d.id, d.name FROM departments d WHERE ' . $where . ' ORDER BY d.name ASC';

$stmt = $mysqli->prepare($select);
$types !== '' ? db_prepared_execute($stmt, $types, $params) : $stmt->execute();
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$payload = ['ok' => true, 'departments' => $rows];
if ($lockedDepartmentId) {
    $payload['locked_department_id'] = $lockedDepartmentId;
}

json_response($payload);
