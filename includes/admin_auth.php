<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function is_admin_logged_in(): bool
{
    ensure_session_started();
    return isset($_SESSION['admin_id']);
}

function require_admin_login(): void
{
    if (!is_admin_logged_in()) {
        redirect(app_url('/admin/login.php'));
    }
}

function require_api_admin_login(): void
{
    if (!is_admin_logged_in()) {
        json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function current_admin(mysqli $mysqli): ?array
{
    if (!is_admin_logged_in()) {
        return null;
    }

    /* a.avatar_public_id is part of the admin's own identity, not an optional
       extra. admin/profile.php feeds it to includes/partials/avatar.php, so
       omitting it made the initials tile render on every server-side load: the
       upload POST saved the column, but nothing ever read it back, so a photo
       appeared to vanish on refresh. admin/api/citizens.php and
       admin/api/citizen.php already selected this column explicitly, which is
       why the admin could see a citizen's photo in the list but never their own.

       a.avatar_url is read for the Cloudinary version segment ONLY, never for
       display - mc_avatar_url() derives the delivery URL from the public_id, and
       the version is what makes that URL change when the photo is replaced. */
    $stmt = $mysqli->prepare('SELECT a.id, a.name, a.email, a.mobile, a.role, a.department_id, a.active, a.otp_enabled, a.avatar_public_id, a.avatar_url, a.created_at, a.last_login_at, d.name AS department_name
        FROM admins a
        LEFT JOIN departments d ON d.id = a.department_id
        WHERE a.id = ? LIMIT 1');
    $adminId = (int) $_SESSION['admin_id'];
    db_prepared_execute($stmt, 'i', [$adminId]);
    $result = $stmt->get_result();
    $admin = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($admin)) {
        return null;
    }

    if (!(int) ($admin['active'] ?? 0)) {
        return null;
    }

    return $admin;
}

function login_admin(int $adminId): void
{
    ensure_session_started();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $adminId;
}

function logout_admin(): void
{
    ensure_session_started();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function require_super_admin(array $admin): void
{
    if (($admin['role'] ?? '') !== 'super_admin') {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }
}

/* =========================================================================
 * Admin Login Step 2 (OTP) pending session state
 * Separate $_SESSION keys from citizen (user) pending_login to avoid
 * collisions when an admin happens to also be logged in as a resident.
 * ========================================================================= */

function set_pending_admin_login_state(int $adminId, int $otpId, string $maskedDestination): void
{
    ensure_session_started();
    $_SESSION['pending_admin_id'] = $adminId;
    $_SESSION['pending_admin_login_otp_id'] = $otpId;
    $_SESSION['pending_admin_login_masked'] = $maskedDestination;
    $_SESSION['pending_admin_login_started_at'] = time();
}

function is_pending_admin_login(): bool
{
    ensure_session_started();
    return isset($_SESSION['pending_admin_id'])
        && (int) $_SESSION['pending_admin_id'] > 0
        && isset($_SESSION['pending_admin_login_otp_id'])
        && (int) $_SESSION['pending_admin_login_otp_id'] > 0;
}

function require_pending_admin_login(): void
{
    if (!is_pending_admin_login()) {
        redirect(app_url('/admin/login.php'));
    }
}

function get_pending_admin_login(): ?array
{
    if (!is_pending_admin_login()) return null;
    return [
        'admin_id' => (int) $_SESSION['pending_admin_id'],
        'otp_id'   => (int) $_SESSION['pending_admin_login_otp_id'],
        'masked_destination' => (string) ($_SESSION['pending_admin_login_masked'] ?? ''),
        'started_at_ts' => (int) ($_SESSION['pending_admin_login_started_at'] ?? time()),
    ];
}

function clear_pending_admin_login_state(): void
{
    ensure_session_started();
    unset($_SESSION['pending_admin_id'],
          $_SESSION['pending_admin_login_otp_id'],
          $_SESSION['pending_admin_login_masked'],
          $_SESSION['pending_admin_login_started_at'],
          $_SESSION['pending_admin_login_sms_failed'],
          $_SESSION['pending_admin_login_sms_error'],
          $_SESSION['pending_admin_login_sms_hint']);
}

function increment_admin_failed_login(mysqli $db, int $adminId, int $maxAttempts = 15, int $lockoutMinutes = 10): void
{
    if ($adminId <= 0) return;
    $stmt = $db->prepare('UPDATE admins SET failed_login_attempts = failed_login_attempts + 1, locked_until = CASE WHEN failed_login_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE) ELSE locked_until END WHERE id = ?');
    if (!$stmt) return;
    db_prepared_execute($stmt, 'iii', [$maxAttempts, $lockoutMinutes, $adminId]);
    $stmt->close();
}

function is_admin_locked(mysqli $db, int $adminId): bool
{
    if ($adminId <= 0) return false;
    $stmt = $db->prepare('SELECT locked_until FROM admins WHERE id = ? LIMIT 1');
    if (!$stmt) return false;
    db_prepared_execute($stmt, 'i', [$adminId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row) || $row['locked_until'] === null) return false;
    $untilTs = strtotime((string) $row['locked_until']);
    if ($untilTs < time()) {
        $stmt = $db->prepare('UPDATE admins SET locked_until = NULL, failed_login_attempts = 0 WHERE id = ?');
        if ($stmt) {
            db_prepared_execute($stmt, 'i', [$adminId]);
            $stmt->close();
        }
        return false;
    }
    return true;
}


