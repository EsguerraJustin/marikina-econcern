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

$stmt = $mysqli->prepare('SELECT m.sender, m.message, m.created_at
                          FROM concern_messages m
                          JOIN concerns c ON c.id = m.concern_id
                          WHERE m.concern_id = ? AND c.user_id = ?
                          ORDER BY m.id ASC');
db_prepared_execute($stmt, 'ii', [$id, (int) $user['id']]);
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'messages' => $rows]);
