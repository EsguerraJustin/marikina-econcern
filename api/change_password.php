<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../includes/auth.php';

function _chpw_diag(string $event, array $extra = []): void
{
    $logPath = __DIR__ . '/../app_error.log';
    $line = json_encode([
        'ts' => date('c'),
        'event' => 'change_password_' . $event,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '(cli)',
    ] + $extra, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @error_log($line, 3, $logPath);
}

require_api_login();
require_csrf_token();

$mysqli = db();
$user = current_user($mysqli);
if (!$user) {
    ob_end_clean();
    json_response(['ok' => false, 'error' => 'Session expired. Please log in again.'], 401);
}
$userId = (int) $user['id'];

$current = (string) ($_POST['current_password'] ?? '');
$next = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

if ($current === '' || $next === '' || $confirm === '') {
    ob_end_clean();
    _chpw_diag('validation_missing_fields', ['user_id' => $userId]);
    json_response(['ok' => false, 'error' => 'All password fields are required.'], 422);
}

if ($next !== $confirm) {
    ob_end_clean();
    _chpw_diag('validation_mismatch', ['user_id' => $userId]);
    json_response(['ok' => false, 'error' => 'New password and confirmation do not match.'], 422);
}

$pwErrors = validate_password_rules($next);
if ($pwErrors !== []) {
    ob_end_clean();
    _chpw_diag('validation_rules', ['user_id' => $userId, 'errors' => $pwErrors]);
    json_response(['ok' => false, 'error' => implode(' ', $pwErrors)], 422);
}

if ($current === $next) {
    ob_end_clean();
    _chpw_diag('validation_same_as_old', ['user_id' => $userId]);
    json_response(['ok' => false, 'error' => 'New password must be different from your current password.'], 422);
}

$stmt = $mysqli->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
if (!$stmt) {
    ob_end_clean();
    _chpw_diag('stmt_prep_old_hash', ['user_id' => $userId, 'error' => $mysqli->error]);
    json_response(['ok' => false, 'error' => 'Server error preparing query.', 'hint' => 'prepare_failed'], 500);
}
db_prepared_execute($stmt, 'i', [$userId]);
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    ob_end_clean();
    _chpw_diag('user_not_found', ['user_id' => $userId]);
    json_response(['ok' => false, 'error' => 'Account not found.'], 404);
}

$oldHash = (string) $row['password_hash'];
if (!password_verify($current, $oldHash)) {
    ob_end_clean();
    _chpw_diag('current_password_wrong', ['user_id' => $userId]);
    json_response(['ok' => false, 'error' => 'Current password is incorrect. Please check and try again.'], 422);
}

$hash = password_hash($next, PASSWORD_DEFAULT);
if ($hash === false || $hash === '') {
    ob_end_clean();
    _chpw_diag('password_hash_failed', ['user_id' => $userId, 'algo' => PASSWORD_DEFAULT]);
    json_response(['ok' => false, 'error' => 'Server could not secure the new password.', 'hint' => 'password_hash_failed'], 500);
}

try {
    $stmt = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('update_prepare_failed: ' . $mysqli->error);
    }
    $ok = db_prepared_execute($stmt, 'si', [$hash, $userId]);
    $affected = $mysqli->affected_rows;
    $stmt->close();
    if (!$ok) {
        throw new RuntimeException('update_execute_failed');
    }
    if ($affected !== 1 && $affected !== 0) {
        throw new RuntimeException('update_affected_rows_unexpected: ' . var_export($affected, true));
    }
} catch (Throwable $e) {
    ob_end_clean();
    _chpw_diag('update_exception', ['user_id' => $userId, 'msg' => $e->getMessage()]);
    json_response(['ok' => false, 'error' => 'Password update failed. Please try again.', 'hint' => $e->getMessage()], 500);
}

$stmt = $mysqli->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
db_prepared_execute($stmt, 'i', [$userId]);
$result = $stmt->get_result();
$verifyRow = $result ? $result->fetch_assoc() : null;
$stmt->close();
$storedHash = $verifyRow ? (string) $verifyRow['password_hash'] : '';

if ($storedHash === '' || !password_verify($next, $storedHash)) {
    ob_end_clean();
    _chpw_diag('post_update_verify_failed', [
        'user_id' => $userId,
        'new_hash_alg' => password_get_info($hash)['algoName'] ?? '?',
        'stored_same_as_sent' => hash_equals($hash, $storedHash),
        'stored_hash_len' => strlen($storedHash),
        'sent_hash_len' => strlen($hash),
    ]);
    json_response(['ok' => false, 'error' => 'Password was not saved correctly. Please try again.', 'hint' => 'post_update_verify_failed: stored hash does not verify new password'], 500);
}

_chpw_diag('success', [
    'user_id' => $userId,
    'algo' => password_get_info($storedHash)['algoName'] ?? '?',
]);

ob_end_clean();
json_response(['ok' => true, 'message' => 'Password updated successfully. You will need to log in again with your new password.']);
