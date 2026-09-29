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

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_id'], 422);
}

$stmt = $mysqli->prepare('SELECT c.id, c.report_number, c.created_at, c.status,
                                 c.street, c.barangay, c.landmark, c.description, c.photos_json,
                                 ct.name AS concern_type, d.name AS department
                          FROM concerns c
                          JOIN concern_types ct ON ct.id = c.concern_type_id
                          JOIN departments d ON d.id = ct.department_id
                          WHERE c.id = ? AND c.user_id = ? LIMIT 1');
db_prepared_execute($stmt, 'ii', [$id, (int) $user['id']]);
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

$photos = [];
if (!empty($row['photos_json'])) {
    $decoded = json_decode((string) $row['photos_json'], true);
    if (is_array($decoded)) {
        $photos = $decoded;
    }
}

$row['photos'] = $photos;
unset($row['photos_json']);

json_response(['ok' => true, 'concern' => $row]);
