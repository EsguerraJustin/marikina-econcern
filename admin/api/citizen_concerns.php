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
require_super_admin($admin);

$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_user_id'], 422);
}

$status = trim((string) ($_GET['status'] ?? ''));
$allowed = ['New', 'Ongoing', 'Acknowledge', 'Completed', 'Cancelled'];

$where = 'u.id = ?';
$types = 'i';
$params = [$userId];

if ($status !== '') {
    if (!in_array($status, $allowed, true)) {
        json_response(['ok' => false, 'error' => 'invalid_status'], 422);
    }
    $where .= ' AND c.status = ?';
    $types .= 's';
    $params[] = $status;
}

$stmt = $mysqli->prepare(
    'SELECT c.id, c.report_number, c.status, c.created_at, c.barangay,
            ct.name AS concern_type,
            d.name AS department
     FROM concerns c
     JOIN users u ON u.id = c.user_id
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     ORDER BY c.id DESC'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'concerns' => $rows]);
