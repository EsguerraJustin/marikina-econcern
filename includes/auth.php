<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

function ensure_session_started(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $cookiePath = rtrim(str_replace(' ', '%20', (string) (defined('APP_BASE_URL') ? APP_BASE_URL : '')), '/') . '/';
    if ($cookiePath === '/') {
        $cookiePath = '/';
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!is_dir(session_save_path()) || !is_writable(session_save_path())) {
        $tmp = sys_get_temp_dir();
        if (is_dir($tmp) && is_writable($tmp)) {
            session_save_path($tmp);
        }
    }

    session_start();
}

function is_logged_in(): bool
{
    ensure_session_started();
    return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect(app_url('/public/login.php'));
    }
}

function require_api_login(): void
{
    if (!is_logged_in()) {
        json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function _auth_diag(string $event, array $extra = []): void
{
    $line = json_encode([
        'ts'   => date('Y-m-d H:i:s'),
        'mod'  => 'auth',
        'ev'   => $event,
        'data' => $extra,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        $line = '{"mod":"auth","ev":"' . $event . '"}';
    }
    @error_log($line . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

function current_user(mysqli $mysqli): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    static $cachedUser = null;
    static $cachedUid = 0;
    $userId = (int) $_SESSION['user_id'];
    if ($cachedUid === $userId && is_array($cachedUser)) {
        return $cachedUser;
    }

    /* 'full' carries avatar_public_id because public/profile.php renders the
       photo from it; without the column the key was simply absent, `?? ''`
       swallowed it, and every server-side load showed the initials tile instead
       (the upload saved fine, so the photo looked like it vanished on refresh).
       avatar_url is read for the Cloudinary version segment ONLY, never for
       display - mc_avatar_url() derives the delivery URL from the public_id, and
       the version is what makes that URL change when the photo is replaced.
       'legacy' deliberately does NOT carry either: this fallback exists for a
       users table predating newer columns, and the two arrived in different
       migrations (avatar in citizen_management_migration.sql, barangay in
       basuraalert_migration.sql). Naming the column in both would make a
       pre-avatar schema throw on both statements, current_user() would return
       null, and the citizen would be logged out on every page. Leaving it out of
       'legacy' degrades to the old initials-only behaviour instead. */
    $selects = [
        'full'   => 'SELECT id, first_name, last_name, mobile, barangay, email, active, otp_enabled, avatar_public_id, avatar_url, email_verified_at, created_at FROM users WHERE id = ? LIMIT 1',
        'legacy' => 'SELECT id, first_name, last_name, mobile, email, active, otp_enabled, email_verified_at, created_at FROM users WHERE id = ? LIMIT 1',
    ];

    $user = null;
    $used = null;
    foreach ($selects as $kind => $sql) {
        $stmt = db_prepare($mysqli, $sql);
        if (!($stmt instanceof mysqli_stmt)) {
            continue;
        }
        try {
            db_prepared_execute($stmt, 'i', [$userId]);
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
        } catch (mysqli_sql_exception $e) {
            $row = null;
            $msg = $e->getMessage();
            if (stripos($msg, 'Unknown column') !== false && $kind === 'full') {
                // 'full' is the only statement naming the newer optional columns
                // (barangay, avatar_public_id), so either one can trigger this.
                _auth_diag('current_user_optional_column_missing_retry', ['user_id' => $userId, 'error' => $msg]);
            } else {
                _auth_diag('current_user_sql_exception', ['user_id' => $userId, 'kind' => $kind, 'error' => $msg]);
            }
        } finally {
            if ($stmt instanceof mysqli_stmt) {
                try { $stmt->close(); } catch (Throwable $_) {}
            }
        }
        if (is_array($row)) {
            $user = $row;
            $used = $kind;
            break;
        }
    }

    if (!is_array($user)) {
        $cachedUser = null;
        $cachedUid = 0;
        return null;
    }

    if (!(int) ($user['active'] ?? 0)) {
        $cachedUser = null;
        $cachedUid = 0;
        return null;
    }

    if (!isset($user['barangay']) || $user['barangay'] === null) {
        $user['barangay'] = null;
        if ($used === 'legacy') {
            _auth_diag('current_user_legacy_schema', [
                'user_id' => $userId,
                'hint'    => 'Run BasuraAlert migration: _run_basuraalert_migration.php?confirm=1 to add users.barangay column.',
            ]);
        }
    }

    $cachedUser = $user;
    $cachedUid = $userId;
    return $user;
}

function login_user(int $userId): void
{
    ensure_session_started();
    clear_pending_login_state();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function logout_user(): void
{
    ensure_session_started();
    clear_pending_login_state();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function set_pending_login_state(int $userId, int $otpId, string $maskedDestination): void
{
    ensure_session_started();
    $_SESSION['pending_user_id'] = $userId;
    $_SESSION['pending_login_otp_id'] = $otpId;
    $_SESSION['pending_login_masked'] = $maskedDestination;
    $_SESSION['pending_login_started_at'] = time();
}

function is_pending_login(): bool
{
    ensure_session_started();
    return isset($_SESSION['pending_user_id'])
        && (int) $_SESSION['pending_user_id'] > 0
        && isset($_SESSION['pending_login_otp_id'])
        && (int) $_SESSION['pending_login_otp_id'] > 0;
}

function require_pending_login(): void
{
    if (!is_pending_login()) {
        redirect(app_url('/public/login.php'));
    }
}

function get_pending_login(): ?array
{
    if (!is_pending_login()) return null;
    return [
        'user_id' => (int) $_SESSION['pending_user_id'],
        'otp_id' => (int) $_SESSION['pending_login_otp_id'],
        'masked_destination' => (string) ($_SESSION['pending_login_masked'] ?? ''),
        'started_at' => (int) ($_SESSION['pending_login_started_at'] ?? 0),
    ];
}

function clear_pending_login_state(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset(
        $_SESSION['pending_user_id'],
        $_SESSION['pending_login_otp_id'],
        $_SESSION['pending_login_masked'],
        $_SESSION['pending_login_started_at'],
        $_SESSION['pending_verify_user_id'],
        $_SESSION['pending_verify_email']
    );
}

function set_pending_verify_state(int $userId, string $email): void
{
    ensure_session_started();
    $_SESSION['pending_verify_user_id'] = $userId;
    $_SESSION['pending_verify_email'] = $email;
}

function get_pending_verify_state(): ?array
{
    ensure_session_started();
    if (!isset($_SESSION['pending_verify_user_id']) || (int) $_SESSION['pending_verify_user_id'] <= 0) {
        return null;
    }
    return [
        'user_id' => (int) $_SESSION['pending_verify_user_id'],
        'email' => (string) ($_SESSION['pending_verify_email'] ?? ''),
    ];
}

function increment_failed_login(mysqli $db, int $userId, int $lockoutMinutes = 15, int $maxAttempts = 10): void
{
    if ($userId <= 0) return;
    $stmt = $db->prepare('UPDATE users SET failed_login_attempts = failed_login_attempts + 1, locked_until = CASE WHEN failed_login_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE) ELSE locked_until END WHERE id = ?');
    if (!$stmt) return;
    db_prepared_execute($stmt, 'iii', [$maxAttempts, $lockoutMinutes, $userId]);
    $stmt->close();
}

function is_user_locked(mysqli $db, int $userId): bool
{
    if ($userId <= 0) return false;
    $stmt = $db->prepare('SELECT locked_until FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) return false;
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row) || $row['locked_until'] === null) return false;
    $untilTs = strtotime((string) $row['locked_until']);
    if ($untilTs < time()) {
        $stmt = $db->prepare('UPDATE users SET locked_until = NULL, failed_login_attempts = 0 WHERE id = ?');
        if ($stmt) {
            db_prepared_execute($stmt, 'i', [$userId]);
            $stmt->close();
        }
        return false;
    }
    return true;
}
