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
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
    if ($note === '') json_response(['ok' => false, 'error' => 'Note is required.'], 422);

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

    $stmt = $mysqli->prepare('INSERT INTO concern_notes (concern_id, admin_id, note) VALUES (?, ?, ?)');
    $saved = db_prepared_execute($stmt, 'iis', [$id, (int) $admin['id'], $note]);
    $stmt->close();

    if (!$saved) {
        json_response(['ok' => false, 'error' => 'save_failed'], 500);
    }

    json_response(['ok' => true]);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_id'], 422);
}

$where = 'n.concern_id = ?';
$types = 'i';
$params = [$id];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT n.note, n.created_at, a.name AS admin_name
     FROM concern_notes n
     LEFT JOIN admins a ON a.id = n.admin_id
     JOIN concerns c ON c.id = n.concern_id
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     ORDER BY n.id DESC'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'notes' => $rows]);
