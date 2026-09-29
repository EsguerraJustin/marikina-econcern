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

$where = '1=1';
$types = '';
$params = [];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT c.status, COUNT(*) AS cnt
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     GROUP BY c.status'
);
$types !== '' ? db_prepared_execute($stmt, $types, $params) : $stmt->execute();
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$counts = [
    'New' => 0,
    'Ongoing' => 0,
    'Acknowledge' => 0,
    'Completed' => 0,
    'Cancelled' => 0,
];

foreach ($rows as $r) {
    $status = (string) ($r['status'] ?? '');
    if (isset($counts[$status])) {
        $counts[$status] = (int) $r['cnt'];
    }
}

json_response(['ok' => true, 'counts' => $counts]);
