<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_api_login();
require_csrf_token();

$mysqli = db();
$user = current_user($mysqli);
if (!$user) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}

$status = trim((string) ($_GET['status'] ?? 'All'));
$q = trim((string) ($_GET['q'] ?? ''));

$allowed = ['All', 'New', 'Ongoing', 'Acknowledge', 'Completed', 'Cancelled'];
if (!in_array($status, $allowed, true)) {
    $status = 'All';
}

$sql = 'SELECT c.id, c.report_number, c.created_at, c.status,
               c.street, c.barangay, c.landmark,
               ct.name AS concern_type, d.name AS department
        FROM concerns c
        JOIN concern_types ct ON ct.id = c.concern_type_id
        JOIN departments d ON d.id = ct.department_id
        WHERE c.user_id = ?';

$types = 'i';
$params = [(int) $user['id']];

if ($status !== 'All') {
    $sql .= ' AND c.status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= ' AND (c.report_number LIKE ? OR c.street LIKE ? OR c.barangay LIKE ? OR c.landmark LIKE ? OR c.description LIKE ? OR ct.name LIKE ? OR d.name LIKE ?)';
    $types .= 'sssssss';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

$sql .= ' ORDER BY c.id DESC LIMIT 200';

$stmt = $mysqli->prepare($sql);
db_prepared_execute($stmt, $types, $params);
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'concerns' => $rows]);
