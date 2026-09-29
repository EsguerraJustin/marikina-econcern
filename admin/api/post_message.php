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
$message = trim((string) ($_POST['message'] ?? ''));

if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
if ($message === '') json_response(['ok' => false, 'error' => 'Message is required.'], 422);

$where = 'c.id = ?';
$types = 'i';
$params = [$id];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT c.id
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     LIMIT 1'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$ok = $res ? (bool) $res->fetch_assoc() : false;
$stmt->close();

if (!$ok) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

$stmt = $mysqli->prepare('INSERT INTO concern_messages (concern_id, sender, message) VALUES (?, "department", ?)');
$saved = db_prepared_execute($stmt, 'is', [$id, $message]);
$stmt->close();

if (!$saved) {
    json_response(['ok' => false, 'error' => 'send_failed'], 500);
}

json_response(['ok' => true]);
