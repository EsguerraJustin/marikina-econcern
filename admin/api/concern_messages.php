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

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_id'], 422);
}

$where = 'm.concern_id = ?';
$types = 'i';
$params = [$id];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT m.sender, m.message, m.created_at
     FROM concern_messages m
     JOIN concerns c ON c.id = m.concern_id
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     ORDER BY m.id ASC'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'messages' => $rows]);
