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

$stmt = $mysqli->prepare('SELECT t.status, t.note, t.created_at
                          FROM concern_timeline t
                          JOIN concerns c ON c.id = t.concern_id
                          WHERE t.concern_id = ? AND c.user_id = ?
                          ORDER BY t.id ASC');
db_prepared_execute($stmt, 'ii', [$id, (int) $user['id']]);
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'timeline' => $rows]);
