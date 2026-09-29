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

$id = (int) ($_POST['id'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));

if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
if ($message === '') json_response(['ok' => false, 'error' => 'Message is required.'], 422);

$stmt = $mysqli->prepare('SELECT id FROM concerns WHERE id = ? AND user_id = ? LIMIT 1');
db_prepared_execute($stmt, 'ii', [$id, (int) $user['id']]);
$result = $stmt->get_result();
$ok = $result ? (bool) $result->fetch_assoc() : false;
$stmt->close();

if (!$ok) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

$stmt = $mysqli->prepare('INSERT INTO concern_messages (concern_id, sender, message) VALUES (?, "user", ?)');
$saved = db_prepared_execute($stmt, 'is', [$id, $message]);
$stmt->close();

if (!$saved) {
    json_response(['ok' => false, 'error' => 'send_failed'], 500);
}

json_response(['ok' => true]);
