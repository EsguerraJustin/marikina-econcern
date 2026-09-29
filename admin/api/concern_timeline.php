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

$where = 't.concern_id = ?';
$types = 'i';
$params = [$id];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT t.status, t.note, t.created_at, t.event_type, t.admin_id,
            t.old_status, t.new_status,
            a.name AS admin_name
     FROM concern_timeline t
     JOIN concerns c ON c.id = t.concern_id
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     LEFT JOIN admins a ON a.id = t.admin_id
     WHERE ' . $where . '
     ORDER BY t.id ASC'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'timeline' => $rows]);
