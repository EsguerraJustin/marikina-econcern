<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/sms.php';
require_once __DIR__ . '/../../includes/Avatar.php';

require_api_admin_login();
require_csrf_token();

$mysqli = db();
$admin = current_admin($mysqli);
if (!$admin) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : 'update_mobile';

/* -------------------------------------------------------------------------
   Avatar
   ------------------------------------------------------------------------- */
if ($action === 'upload_avatar' || $action === 'delete_avatar') {
    $adminId = (int) $admin['id'];

    if ($action === 'delete_avatar') {
        $res = mc_avatar_delete($mysqli, 'admins', $adminId);
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => $res['error'] ?: 'Could not remove the photo.'], 500);
        }
        json_response(['ok' => true, 'display_url' => '', 'version' => '', 'public_id' => $res['public_id']]);
    }

    if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
        json_response(['ok' => false, 'error' => 'No photo was selected.'], 422);
    }
    $res = mc_avatar_upload($mysqli, 'admins', $adminId, $_FILES['avatar']);
    if (!$res['ok']) {
        json_response(['ok' => false, 'error' => $res['error'] ?: 'Photo upload failed.'], 422);
    }
    json_response([
        'ok'           => true,
        'display_url'  => $res['display_url'],
        // Echoed so the client can match sibling tiles for the same account and
        // prove the URL it was given is not the one it already has cached.
        'public_id'    => $res['public_id'],
        'version'      => $res['version'],
        'message'      => 'Profile photo updated.',
    ]);
}

/* -------------------------------------------------------------------------
   Identity (name + email + mobile)
   -------------------------------------------------------------------------
   role / department_id / active are deliberately NOT editable here. They stay
   under admin/admin_accounts.php (super-admin only) so privilege escalation is
   never self-service. Validation mirrors that endpoint
   (admin/api/admins.php 'update') so the two paths cannot drift.
   ------------------------------------------------------------------------- */
if ($action === 'update_identity') {
    $adminId = (int) $admin['id'];

    $name   = trim((string) ($_POST['name'] ?? ''));
    $email  = trim((string) ($_POST['email'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));

    if ($name === '') {
        json_response(['ok' => false, 'error' => 'Name is required.'], 422);
    }
    if (mb_strlen($name) > 190) {
        json_response(['ok' => false, 'error' => 'Name is too long (max 190 characters).'], 422);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
    }
    $email = mb_strtolower($email);

    // admins.mobile is nullable, so an empty value is legal and means "no OTP".
    $mobileValue = null;
    if ($mobile !== '') {
        $e164 = normalize_ph_mobile($mobile);
        if ($e164 === false) {
            json_response(['ok' => false, 'error' => 'Invalid Philippine mobile number. Use format: 09XXXXXXXXX or +639XXXXXXXXX'], 422);
        }
        $mobileValue = $e164;
    }

    $currentEmail = (string) ($admin['email'] ?? '');
    $emailChanged = (mb_strtolower($currentEmail) !== $email);

    // Email is a login identifier (admin/login.php matches on email OR name) and
    // is where password-reset links are sent, so changing it requires proving
    // you still know the password. Same pattern as api/change_password.php.
    if ($emailChanged) {
        $pw = (string) ($_POST['current_password'] ?? '');
        if ($pw === '') {
            json_response(['ok' => false, 'error' => 'Enter your current password to change your email address.', 'requires_password' => true], 422);
        }
        // current_admin() deliberately does not select password_hash (it is passed
        // around widely and must never end up in a response), so fetch it here,
        // scoped to this admin's own id.
        $hashStmt = $mysqli->prepare('SELECT password_hash FROM admins WHERE id = ? LIMIT 1');
        $hash = '';
        if ($hashStmt) {
            db_prepared_execute($hashStmt, 'i', [$adminId]);
            $hashRes = $hashStmt->get_result();
            $hashRow = $hashRes ? $hashRes->fetch_assoc() : null;
            $hashStmt->close();
            $hash = $hashRow ? (string) ($hashRow['password_hash'] ?? '') : '';
        }
        if ($hash === '' || !password_verify($pw, $hash)) {
            json_response(['ok' => false, 'error' => 'Your current password is incorrect.', 'requires_password' => true], 403);
        }
    }

    // Uniqueness. uniq_admins_email is a real UNIQUE index, but check first so
    // the user gets a readable message instead of a driver error.
    if ($emailChanged || $name !== (string) ($admin['name'] ?? '')) {
        $chk = $mysqli->prepare('SELECT id FROM admins WHERE email = ? AND id <> ? LIMIT 1');
        if ($chk) {
            db_prepared_execute($chk, 'si', [$email, $adminId]);
            $res = $chk->get_result();
            $dupe = $res ? $res->fetch_assoc() : null;
            $chk->close();
            if ($dupe) {
                json_response(['ok' => false, 'error' => 'That email address is already used by another administrator.'], 409);
            }
        }
    }

    $stmt = $mysqli->prepare('UPDATE admins SET name = ?, email = ?, mobile = ? WHERE id = ?');
    if (!$stmt) {
        json_response(['ok' => false, 'error' => 'Database error. Please try again.'], 500);
    }
    $ok = db_prepared_execute($stmt, 'sssi', [$name, $email, $mobileValue, $adminId]);
    $stmt->close();

    if (!$ok) {
        json_response(['ok' => false, 'error' => 'Update failed. Please try again.'], 500);
    }

    json_response([
        'ok'           => true,
        'name'         => $name,
        'email'        => $email,
        // Lets the page repaint the initials tile in place instead of
        // re-deriving the rule in JS and drifting from mc_avatar_initials().
        'initials'     => mc_avatar_initials($name),
        // The CANONICAL value, not the mask. The client writes this back into
        // the editable mobile input, and a masked "+6391****4567" would fail
        // normalize_ph_mobile() on the very next save.
        'mobile'       => $mobileValue,
        'masked_mobile'=> $mobileValue !== null ? mask_mobile($mobileValue) : '',
        'email_changed'=> $emailChanged,
        'message'      => $emailChanged
            ? 'Profile updated. You will now sign in with ' . $email . '.'
            : 'Profile updated.',
    ]);
}

if ($action === 'toggle_otp') {
    $otpEnabled = isset($_POST['otp_enabled']) ? (int) $_POST['otp_enabled'] : 0;
    $otpEnabled = $otpEnabled === 1 ? 1 : 0;

    $stmt = $mysqli->prepare('UPDATE admins SET otp_enabled = ? WHERE id = ?');
    $ok = db_prepared_execute($stmt, 'ii', [$otpEnabled, (int) $admin['id']]);
    $stmt->close();

    if (!$ok) {
        json_response(['ok' => false, 'error' => 'Failed to update OTP preference.'], 500);
    }

    // Echo the persisted state back so the client can reconcile its UI without
    // re-rendering from a possibly-stale page. masked_mobile is empty when 2FA
    // is on but no valid number is on file, which the UI surfaces as a distinct
    // "Enabled, but no valid mobile number" state rather than a false "Protected".
    $mobileNow = (string) ($admin['mobile'] ?? '');
    $mobileOk  = $mobileNow !== '' && normalize_ph_mobile($mobileNow) !== false;

    json_response([
        'ok'             => true,
        'otp_enabled'    => $otpEnabled,
        'masked_mobile'  => ($otpEnabled && $mobileOk) ? mask_mobile($mobileNow) : '',
        'message'        => $otpEnabled === 1
            ? 'OTP enabled. Next login will ask for SMS code.'
            : 'OTP disabled. Next login will proceed with email + password only.',
    ]);
}

$mobile = trim((string) ($_POST['mobile'] ?? ''));

if ($mobile === '') {
    json_response(['ok' => false, 'error' => 'Mobile number is required for SMS OTP.'], 422);
}

$e164 = normalize_ph_mobile($mobile);
if ($e164 === false) {
    json_response(['ok' => false, 'error' => 'Invalid Philippine mobile number. Use format: 09XXXXXXXXX or +639XXXXXXXXX'], 422);
}

$stmt = $mysqli->prepare('UPDATE admins SET mobile = ? WHERE id = ?');
$ok = db_prepared_execute($stmt, 'si', [$e164, (int) $admin['id']]);
$stmt->close();

if (!$ok) {
    json_response(['ok' => false, 'error' => 'Update failed.'], 500);
}

json_response(['ok' => true, 'mobile' => $e164, 'masked' => mask_mobile($e164)]);
