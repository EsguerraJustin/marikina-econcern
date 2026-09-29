<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/sms.php';
require_once __DIR__ . '/../../includes/email_verification.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/Avatar.php';

require_api_admin_login();
require_csrf_token();

$mysqli = db();
$admin = current_admin($mysqli);
if (!$admin) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}
require_super_admin($admin);

$action = isset($_POST['action']) ? (string) $_POST['action'] : 'get';
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 && $action !== 'get') {
    $id = (int) ($_POST['id'] ?? 0);
}
if ($id <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_id'], 422);
}

/* -------------------------------------------------------------------------
   Avatar for the citizen. Same helpers as the self-service paths.
   ------------------------------------------------------------------------- */
if ($action === 'upload_avatar' || $action === 'delete_avatar') {
    if ($action === 'delete_avatar') {
        $res = mc_avatar_delete($mysqli, 'users', $id);
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => $res['error'] ?: 'Could not remove the photo.'], 500);
        }
        json_response(['ok' => true, 'display_url' => '']);
    }
    if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
        json_response(['ok' => false, 'error' => 'No photo was selected.'], 422);
    }
    $res = mc_avatar_upload($mysqli, 'users', $id, $_FILES['avatar']);
    if (!$res['ok']) {
        json_response(['ok' => false, 'error' => $res['error'] ?: 'Photo upload failed.'], 422);
    }
    json_response(['ok' => true, 'display_url' => $res['display_url']]);
}

/* -------------------------------------------------------------------------
   Edit a citizen's identity.

   SCOPE, and why:
   - first_name / last_name / email / mobile / barangay are editable.
   - active, deleted_at, password_hash, moderator_notes and otp_enabled are NOT.
     Archiving already has its own flow with a confirmation modal in
     admin/citizens.php, and moderator_notes is a private admin-only field that
     must not ride along on a generic edit endpoint.

   AUDIT: every real change is written to admin_activity_log via
   mc_admin_activity_log() (includes/helpers.php). That table already existed
   with exactly the right shape and was never written to anywhere, so a
   privileged account editing another person's PII left no trace.

   EMAIL: by default the citizen is marked UNVERIFIED and a fresh verification
   link is sent to the NEW address, so an admin cannot quietly park someone's
   account on an address that person does not control. A super admin who is
   correcting a typo can pass mark_verified=1 to override; the override is
   recorded in the audit log's __note so it is reviewable after the fact.
   ------------------------------------------------------------------------- */
if ($action === 'update') {
    $first    = trim((string) ($_POST['first_name'] ?? ''));
    $last     = trim((string) ($_POST['last_name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $mobile   = trim((string) ($_POST['mobile'] ?? ''));
    $barangay = trim((string) ($_POST['barangay'] ?? ''));
    $markVerified = !empty($_POST['mark_verified']);

    if ($first === '') json_response(['ok' => false, 'error' => 'First name is required.'], 422);
    if ($last === '')  json_response(['ok' => false, 'error' => 'Last name is required.'], 422);
    if (mb_strlen($first) > 100 || mb_strlen($last) > 100) {
        json_response(['ok' => false, 'error' => 'Names are too long (max 100 characters each).'], 422);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
    }
    $email = mb_strtolower($email);
    if (mb_strlen($barangay) > 120) {
        json_response(['ok' => false, 'error' => 'Barangay name is too long (max 120 characters).'], 422);
    }
    // users.mobile is NOT NULL with no default, so it must always be written.
    $e164 = normalize_ph_mobile($mobile);
    if ($e164 === false) {
        json_response(['ok' => false, 'error' => 'Enter a valid Philippine mobile number (09XXXXXXXXX).'], 422);
    }

    $cur = $mysqli->prepare('SELECT first_name, last_name, email, mobile, barangay, email_verified_at FROM users WHERE id = ? LIMIT 1');
    if (!$cur) json_response(['ok' => false, 'error' => 'Database error.'], 500);
    db_prepared_execute($cur, 'i', [$id]);
    $cr = $cur->get_result();
    $before = $cr ? $cr->fetch_assoc() : null;
    $cur->close();
    if (!is_array($before)) {
        json_response(['ok' => false, 'error' => 'Citizen not found.'], 404);
    }

    $emailChanged = (mb_strtolower((string) $before['email']) !== $email);
    if ($emailChanged) {
        $c = $mysqli->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        if ($c) {
            db_prepared_execute($c, 'si', [$email, $id]);
            $cres = $c->get_result();
            $dupe = $cres ? $cres->fetch_assoc() : null;
            $c->close();
            if ($dupe) {
                json_response(['ok' => false, 'error' => 'That email address is already registered to another account.'], 409);
            }
        }
    }

    $forceUnverified = ($emailChanged && !$markVerified);

    // mark_verified must actually MARK, not merely decline to unmark. Skipping
    // the unmark on its own is a no-op: a row that is already unverified (from
    // an earlier admin edit, or because the citizen never confirmed) would stay
    // unverified and public/login.php would keep routing them to the verify
    // screen. The override is deliberately INDEPENDENT of whether the address
    // changed, so a super admin can also clear an account that is already stuck
    // on the verify screen without having to re-type the same address.
    $markVerifiedNow = ($markVerified && empty($before['email_verified_at']));

    $sql = 'UPDATE users SET first_name = ?, last_name = ?, email = ?, mobile = ?, barangay = ?'
         . ($forceUnverified ? ', email_verified_at = NULL' : '')
         . ($markVerifiedNow ? ', email_verified_at = NOW()' : '')
         . ' WHERE id = ?';
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) json_response(['ok' => false, 'error' => 'Database error.'], 500);
    // 5 strings (first, last, email, mobile, barangay) then the int id.
    $ok = db_prepared_execute($stmt, 'sssssi', [$first, $last, $email, $e164, $barangay, $id]);
    $stmt->close();
    if (!$ok) {
        json_response(['ok' => false, 'error' => 'Update failed. Please try again.'], 500);
    }

    // ---- audit trail ----
    $after = ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'mobile' => $e164, 'barangay' => $barangay];
    $changed = [];
    foreach ($after as $k => $v) {
        $old = (string) ($before[$k] ?? '');
        if ($old !== (string) $v) {
            $changed[$k] = ['from' => $old, 'to' => (string) $v];
        }
    }
    if ($markVerifiedNow) {
        // A verification override with no accompanying field change would
        // otherwise leave no trace at all.
        $changed['email_verified_at'] = ['from' => 'NULL', 'to' => date('Y-m-d H:i:s')];
    }
    if ($changed) {
        // The note records that verification was settled out-of-band, because a
        // reviewer seeing an address change and no "requires verification" mail
        // would otherwise have no way to tell a deliberate override from a bug.
        $note = $markVerifiedNow
            ? 'super admin stamped email_verified_at out-of-band; no verification email was sent'
            : null;
        mc_admin_activity_log($mysqli, (int) $admin['id'], 'citizen_identity_update', 'user', $id, $changed, $note);
    }

    // ---- notify the citizen when the address changed ----
    $verifyUrl = '';
    if ($emailChanged) {
        try {
            // Always drop tokens minted for the old address; they must not remain
            // usable to verify the new one.
            invalidate_old_verifications($mysqli, $id);
            if ($forceUnverified) {
                $lastTs = last_verification_created_at($mysqli, $id);
                if ($lastTs === null || (time() - $lastTs) >= 60) {
                    $rawToken = create_email_verification($mysqli, $id);
                    $link = app_public_url('/public/verify_email.php?token=' . urlencode($rawToken));
                    $content = build_verification_email_content($link, $first);
                    $sent = send_email($email, $content['subject'], $content['html'], $content['text']);
                    $verifyUrl = (!empty($sent['ok'])) ? $link : '';
                }
            }
        } catch (Throwable $e) {
            // The address is already changed and marked unverified; the citizen
            // can request a new link from public/resend_verification.php.
            @error_log('[citizen_update] verification mail failed: ' . $e->getMessage(), 3, STORAGE_DIR . '/../app_error.log');
        }
    }

    json_response([
        'ok'                => true,
        'citizen'           => $after,
        'email_changed'     => $emailChanged,
        'marked_unverified' => $forceUnverified,
        'verify_url'        => $verifyUrl,
        'changed_fields'    => array_keys($changed),
        'message'           => $forceUnverified
            ? 'Citizen updated. Their account now requires verification at ' . $email . '.'
            : 'Citizen updated.',
    ]);
}

$stmt = $mysqli->prepare('SELECT id, first_name, last_name, mobile, email, barangay, active, deleted_at, created_at, avatar_public_id FROM users WHERE id = ? LIMIT 1');
db_prepared_execute($stmt, 'i', [$id]);
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

json_response(['ok' => true, 'citizen' => $row]);
