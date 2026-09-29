<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/basuraalert.php';
require_once __DIR__ . '/../includes/email_verification.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/Avatar.php';

csrf_check();
require_api_login();
require_csrf_token();

$db = db();
$user = current_user($db);
if (!$user) {
    json_response(['ok' => false, 'error' => 'User not found.'], 404);
}

$userId = (int) $user['id'];
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';

/* -------------------------------------------------------------------------
   Avatar
   ------------------------------------------------------------------------- */
if ($action === 'upload_avatar' || $action === 'delete_avatar') {
    if ($action === 'delete_avatar') {
        $res = mc_avatar_delete($db, 'users', $userId);
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => $res['error'] ?: 'Could not remove the photo.'], 500);
        }
        json_response(['ok' => true, 'display_url' => '', 'version' => '', 'public_id' => $res['public_id']]);
    }

    if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
        json_response(['ok' => false, 'error' => 'No photo was selected.'], 422);
    }
    $res = mc_avatar_upload($db, 'users', $userId, $_FILES['avatar']);
    if (!$res['ok']) {
        json_response(['ok' => false, 'error' => $res['error'] ?: 'Photo upload failed.'], 422);
    }
    // 'public_id' and 'version' are echoed so the client can repaint its other
    // tiles for the same account and can tell a genuinely new asset from the
    // cached copy of the old one. See mc_avatar_url().
    json_response([
        'ok'          => true,
        'display_url' => $res['display_url'],
        'public_id'   => $res['public_id'],
        'version'     => $res['version'],
        'message'     => 'Profile photo updated.',
    ]);
}

/* -------------------------------------------------------------------------
   Identity (first_name + last_name + email + mobile)

   SECURITY: on an email change we MUST clear email_verified_at. That column IS
   the verification invariant (email_verification.php sets it with
   "AND email_verified_at IS NULL" and is_user_email_verified() reads it). If it
   survived an email change the account would remain "verified" on an address
   the account holder may no longer control, which is an account-takeover path.
   public/login.php routes an unverified account to the verify screen, so the
   citizen keeps their password but must confirm the new address before using
   the app - the same flow as registration.

   moderator_notes is a private admin-only field and is deliberately absent here.
   ------------------------------------------------------------------------- */
if ($action === 'update_identity') {
    $first = trim((string) ($_POST['first_name'] ?? ''));
    $last  = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));

    if ($first === '') json_response(['ok' => false, 'error' => 'First name is required.'], 422);
    if ($last === '')  json_response(['ok' => false, 'error' => 'Last name is required.'], 422);
    if (mb_strlen($first) > 100 || mb_strlen($last) > 100) {
        json_response(['ok' => false, 'error' => 'Names are too long (max 100 characters each).'], 422);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
    }
    $email = mb_strtolower($email);

    // users.mobile is NOT NULL with no default, so it must always be written.
    $e164 = normalize_ph_mobile($mobile);
    if ($e164 === false) {
        json_response(['ok' => false, 'error' => 'Enter a valid Philippine mobile number (09XXXXXXXXX). It is required for SMS OTP.'], 422);
    }

    $currentEmail = mb_strtolower((string) ($user['email'] ?? ''));
    $emailChanged = ($currentEmail !== $email);

    // Email is the sign-in address and the destination of password-reset links,
    // so changing it requires the current password (same rule as the admin twin).
    if ($emailChanged) {
        $pw = (string) ($_POST['current_password'] ?? '');
        if ($pw === '') {
            json_response(['ok' => false, 'error' => 'Enter your current password to change your email address.', 'requires_password' => true], 422);
        }
        // current_user() does not select password_hash (it is passed around and
        // must never reach a response), so fetch it here, scoped to this user.
        $h = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $hash = '';
        if ($h) {
            db_prepared_execute($h, 'i', [$userId]);
            $hr = $h->get_result();
            $hrow = $hr ? $hr->fetch_assoc() : null;
            $h->close();
            $hash = $hrow ? (string) ($hrow['password_hash'] ?? '') : '';
        }
        if ($hash === '' || !password_verify($pw, $hash)) {
            json_response(['ok' => false, 'error' => 'Your current password is incorrect.', 'requires_password' => true], 403);
        }
    }

    // uniq_users_email is a real UNIQUE index; check first for a readable error.
    if ($emailChanged) {
        $c = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        if ($c) {
            db_prepared_execute($c, 'si', [$email, $userId]);
            $cr = $c->get_result();
            $dupe = $cr ? $cr->fetch_assoc() : null;
            $c->close();
            if ($dupe) {
                json_response(['ok' => false, 'error' => 'That email address is already registered to another account.'], 409);
            }
        }
    }

    // Clearing email_verified_at and re-issuing a verification are one step: a
    // row that is unverified but has no pending token would strand the citizen.
    $needsVerification = false;
    if ($emailChanged) {
        invalidate_old_verifications($db, $userId);
        $needsVerification = true;
    }

    $sql = 'UPDATE users SET first_name = ?, last_name = ?, email = ?, mobile = ?'
         . ($needsVerification ? ', email_verified_at = NULL' : '')
         . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        json_response(['ok' => false, 'error' => 'Database error. Please try again.'], 500);
    }
    $ok = db_prepared_execute($stmt, 'ssssi', [$first, $last, $email, $e164, $userId]);
    $stmt->close();
    if (!$ok) {
        json_response(['ok' => false, 'error' => 'Update failed. Please try again.'], 500);
    }

    $verifyUrl = '';
    if ($needsVerification) {
        try {
            // Respect the same 60s cooldown as public/resend_verification.php so
            // an email change cannot be used to hammer the mail provider.
            $lastTs = last_verification_created_at($db, $userId);
            if ($lastTs === null || (time() - $lastTs) >= 60) {
                $rawToken = create_email_verification($db, $userId);
                $link = app_public_url('/public/verify_email.php?token=' . urlencode($rawToken));
                $content = build_verification_email_content($link, $first);
                $sent = send_email($email, $content['subject'], $content['html'], $content['text']);
                $verifyUrl = (!empty($sent['ok'])) ? $link : '';
            }
        } catch (Throwable $e) {
            // The address is already changed and marked unverified; the citizen
            // can request a new link from public/resend_verification.php.
            @error_log('[profile_update_identity] verification mail failed: ' . $e->getMessage(), 3, STORAGE_DIR . '/../app_error.log');
        }
    }

    json_response([
        'ok'                => true,
        'first_name'        => $first,
        'last_name'         => $last,
        'email'             => $email,
        // Canonical E.164, not the mask: the client writes this back into the
        // editable mobile input and a masked value fails re-validation.
        'mobile'            => $e164,
        'masked_mobile'     => mask_mobile($e164),
        'email_changed'     => $emailChanged,
        'needs_verification'=> $needsVerification,
        'verify_url'        => $verifyUrl,
        'message'           => $needsVerification
            ? 'Profile updated. We sent a verification link to ' . $email . '. Please confirm it to keep using your account.'
            : 'Profile updated.',
    ]);
}

if ($action === 'save_barangay') {
    $barangay = isset($_POST['barangay']) ? trim((string) $_POST['barangay']) : '';
    if ($barangay === '') {
        json_response(['ok' => false, 'error' => 'Please select or enter your barangay.']);
    }
    if (strlen($barangay) > 120) {
        json_response(['ok' => false, 'error' => 'Barangay name is too long.']);
    }
    $stmt = $db->prepare('UPDATE users SET barangay = ? WHERE id = ?');
    if (!$stmt) {
        json_response(['ok' => false, 'error' => 'Database error.']);
    }
    db_prepared_execute($stmt, 'si', [$barangay, $userId]);
    $stmt->close();
    ba_ensure_user_prefs($db, $userId);
    json_response(['ok' => true, 'message' => 'Barangay saved. Please refresh to see updated BasuraAlert schedule information.']);
}

if ($action === 'save_notification_prefs') {
    $payload = [
        'enable_email_reminders' => isset($_POST['enable_email_reminders']) ? 1 : 0,
        'enable_sms_reminders' => isset($_POST['enable_sms_reminders']) ? 1 : 0,
        'enable_in_app_reminders' => isset($_POST['enable_in_app_reminders']) ? 1 : 0,
        'reminder_hours_before' => (int) ($_POST['reminder_hours_before'] ?? 12),
    ];
    $ok = ba_save_user_prefs($db, $userId, $payload);
    json_response(['ok' => $ok, 'message' => $ok ? 'Notification preferences saved.' : 'Failed to save preferences.']);
}

if ($action === 'save_otp_pref') {
    $otpEnabled = isset($_POST['otp_enabled']) ? (int) $_POST['otp_enabled'] : 0;
    $otpEnabled = $otpEnabled === 1 ? 1 : 0;

    $stmt = $db->prepare('UPDATE users SET otp_enabled = ? WHERE id = ?');
    if (!$stmt) {
        json_response(['ok' => false, 'error' => 'Database error.']);
    }
    $ok = db_prepared_execute($stmt, 'ii', [$otpEnabled, $userId]);
    $stmt->close();

    if (!$ok) {
        json_response(['ok' => false, 'error' => 'Failed to update OTP preference.']);
    }

    // Echo the persisted state back so the client can reconcile its UI without
    // re-rendering from a possibly-stale page. See the admin twin in
    // admin/api/update_profile.php for the rationale.
    $mobileNow = (string) ($user['mobile'] ?? '');
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

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
