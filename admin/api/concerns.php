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

$status = (string) ($_GET['status'] ?? 'All');
$q = trim((string) ($_GET['q'] ?? ''));
$departmentId = (int) ($_GET['department_id'] ?? 0);
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');

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

if ($status !== '' && $status !== 'All') {
    $where[] = 'c.status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($from !== '') {
    $where[] = 'DATE(c.created_at) >= ?';
    $types .= 's';
    $params[] = $from;
}

if ($to !== '') {
    $where[] = 'DATE(c.created_at) <= ?';
    $types .= 's';
    $params[] = $to;
}

if ($q !== '') {
    $where[] = '(c.report_number LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ? OR ct.name LIKE ? OR d.name LIKE ?)';
    $types .= 'ssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = 'SELECT c.id, c.report_number, c.status, c.created_at, c.barangay,
            CONCAT(u.first_name, " ", u.last_name) AS citizen_name,
            ct.name AS concern_type,
            d.name AS department
        FROM concerns c
        JOIN users u ON u.id = c.user_id
        JOIN concern_types ct ON ct.id = c.concern_type_id
        JOIN departments d ON d.id = ct.department_id
        ' . $whereSql . '
        ORDER BY c.id DESC
        LIMIT 200';

$stmt = $mysqli->prepare($sql);
$types !== '' ? db_prepared_execute($stmt, $types, $params) : $stmt->execute();
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'concerns' => $rows]);
