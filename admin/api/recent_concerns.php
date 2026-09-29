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

$limit = 10;

$where = '1=1';
$types = '';
$params = [];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$sql = 'SELECT c.id, c.report_number, c.status, c.created_at, c.barangay,
            CONCAT(u.first_name, " ", u.last_name) AS citizen_name,
            ct.name AS concern_type,
            d.name AS department
        FROM concerns c
        JOIN users u ON u.id = c.user_id
        JOIN concern_types ct ON ct.id = c.concern_type_id
        JOIN departments d ON d.id = ct.department_id
        WHERE ' . $where . '
        ORDER BY c.id DESC
        LIMIT ' . (int) $limit;

$stmt = $mysqli->prepare($sql);
$types !== '' ? db_prepared_execute($stmt, $types, $params) : $stmt->execute();
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'concerns' => $rows]);
