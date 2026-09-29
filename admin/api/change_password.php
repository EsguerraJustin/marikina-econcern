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

$current = (string) ($_POST['current_password'] ?? '');
$next = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

if ($current === '' || $next === '' || $confirm === '') {
    json_response(['ok' => false, 'error' => 'All password fields are required.'], 422);
}

if ($next !== $confirm) {
    json_response(['ok' => false, 'error' => 'New passwords do not match.'], 422);
}

$pwErrors = validate_password_rules($next);
if ($pwErrors !== []) {
    json_response(['ok' => false, 'error' => implode(' ', $pwErrors)], 422);
}

$stmt = $mysqli->prepare('SELECT password_hash FROM admins WHERE id = ? LIMIT 1');
db_prepared_execute($stmt, 'i', [(int) $admin['id']]);
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row || !password_verify($current, (string) $row['password_hash'])) {
    json_response(['ok' => false, 'error' => 'Current password is incorrect.'], 422);
}

$hash = password_hash($next, PASSWORD_DEFAULT);
$stmt = $mysqli->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
$ok = db_prepared_execute($stmt, 'si', [$hash, (int) $admin['id']]);
$stmt->close();

if (!$ok) {
    json_response(['ok' => false, 'error' => 'Update failed.'], 500);
}

json_response(['ok' => true]);
