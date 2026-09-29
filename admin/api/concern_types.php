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

        $stmt = $mysqli->prepare('UPDATE concern_types SET active = ? WHERE id = ?');
        $ok = db_prepared_execute($stmt, 'ii', [$active, $id]);
        $stmt->close();

        if (!$ok) {
            json_response(['ok' => false, 'error' => 'update_failed'], 500);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'invalid_action'], 422);
}

$departmentId = (int) ($_GET['department_id'] ?? 0);
$includeInactive = (string) ($_GET['include_inactive'] ?? '') === '1';

$where = [];
$types = '';
$params = [];

if (($admin['role'] ?? '') === 'department_admin') {
    $where[] = 'd.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
} elseif ($departmentId > 0) {
    $where[] = 'd.id = ?';
    $types .= 'i';
    $params[] = $departmentId;
}

if (!$includeInactive) {
    $where[] = 'ct.active = 1 AND d.active = 1';
}

$whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = 'SELECT ct.id, ct.name, ct.active, d.id AS department_id, d.name AS department
        FROM concern_types ct
        JOIN departments d ON d.id = ct.department_id
        ' . $whereSql . '
        ORDER BY d.name ASC, ct.name ASC';

$stmt = $mysqli->prepare($sql);
$types !== '' ? db_prepared_execute($stmt, $types, $params) : $stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'types' => $rows]);
