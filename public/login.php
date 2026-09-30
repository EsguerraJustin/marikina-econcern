<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/email_verification.php';
require_once __DIR__ . '/../includes/mailer.php';

if (is_logged_in()) {
    redirect(app_url('/public/dashboard.php'));
}

$mysqli = db();
$errors = [];
$email = '';
$notice = '';
$showResendVerificationLink = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if ($errors === []) {
        $stmt = db_prepare($mysqli, 'SELECT id, password_hash, active, otp_enabled, email_verified_at, failed_login_attempts, locked_until, mobile FROM users WHERE email = ? LIMIT 1');
        if (!($stmt instanceof mysqli_stmt)) {
            $errors[] = 'Temporary database connection error. Please try again in a moment.';
        } else {
            db_prepared_execute($stmt, 's', [$email]);
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            $matchFound = is_array($row);
            $userId = $matchFound ? (int) $row['id'] : 0;
            $hash = $matchFound ? (string) $row['password_hash'] : '';
            $passwordOk = $matchFound && $hash !== '' && password_verify($password, $hash);
            $activeOk = $matchFound && (int) ($row['active'] ?? 0) === 1;

            if ($matchFound && is_user_locked($mysqli, $userId)) {
                $errors[] = 'This account has been temporarily locked due to too many failed login attempts. Please try again later or reset your password.';
            } elseif (!$matchFound || !$passwordOk) {
                /* Drop any challenge left over from an earlier attempt. Without
                   this an abandoned SMS challenge outlives the login that
                   created it: get_active_otp() (includes/otp.php:200) returns the
                   newest unconsumed row for the user, and invalidate_active_otps()
                   only runs when a NEW code is issued, so nothing else ever
                   cleared it. A stale login_otp.php tab would then re-render the
                   code prompt long after the citizen had walked away from it. */
                clear_pending_login_state();
                if (!$matchFound || !$activeOk) {
                    $errors[] = 'Invalid email or password.';
                } else {
                    $errors[] = 'Invalid email or password.';
                    increment_failed_login($mysqli, $userId, 15, 10);
                }
            } elseif (!$activeOk) {
                $errors[] = 'This account has been disabled.';
            } elseif ($row['email_verified_at'] === null) {
                $showResendVerificationLink = true;
                set_pending_verify_state($userId, $email);
                _ev_diag('login_blocked_unverified', [
                    'user_id' => $userId,
                    'email_masked' => mask_email($email),
                ]);
                $resent = false;
                try {
                    $lastTs = last_verification_created_at($mysqli, $userId);
                    if ($lastTs === null || (time() - $lastTs) >= 180) {
                        $firstName = '';
                        $s2 = db_prepare($mysqli, 'SELECT first_name FROM users WHERE id = ? LIMIT 1');
                        if ($s2 instanceof mysqli_stmt) {
                            db_prepared_execute($s2, 'i', [$userId]);
                            $r2 = $s2->get_result();
                            $n = $r2 ? $r2->fetch_assoc() : null;
                            $s2->close();
                            if (is_array($n)) {
                                $firstName = (string) ($n['first_name'] ?? '');
                            }
                        }
                        $rawToken = create_email_verification($mysqli, $userId);
                        $verifyUrl = app_public_url('/public/verify_email.php?token=' . $rawToken);
                        $emailContent = build_verification_email_content($verifyUrl, $firstName);
                        $res = send_email($email, $emailContent['subject'], $emailContent['html'], $emailContent['text']);
                        $resent = !empty($res['ok']);
                    }
                } catch (Throwable $e) {
                    _ev_diag('login_auto_resend_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
                if ($resent) {
                    $notice = 'A new verification email has been sent to ' . e(mask_email($email)) . '. Please check your inbox (and spam folder). If you did not receive it, you may request another email using the link below.';
                } else {
                    $notice = 'Your email address has not been verified yet. Please check your inbox for the verification email we sent earlier, or use the link below to request a new one.';
                }
            } else {
                $otpOn = (int) ($row['otp_enabled'] ?? 1) === 1;
                if (!$otpOn) {
                    clear_pending_login_state();
                    increment_failed_login($mysqli, $userId, 0, 0);
                    $stmt = $mysqli->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?');
                    if ($stmt instanceof mysqli_stmt) {
                        db_prepared_execute($stmt, 'i', [$userId]);
                        $stmt->close();
                    }
                    /* The citizen twin of admin/login.php:90. The admin portal has
                       always logged which way this branch went; the citizen one
                       did not, so "was OTP skipped or demanded?" was unanswerable
                       from app_error.log after the fact -- which is the entire
                       reason the 2026-09-30 report could not be pinned down. */
                    _auth_diag('login_success_otp_disabled', [
                        'user_id' => $userId,
                        'email'   => mask_email($email),
                    ]);
                    login_user($userId);
                    redirect(app_url('/public/dashboard.php'));
                }
                $mobileRaw = (string) ($row['mobile'] ?? '');
                $e164 = normalize_ph_mobile($mobileRaw);
                if ($e164 === false) {
                    $errors[] = 'The mobile number on this account (' . e(mask_mobile($mobileRaw === '' ? '09170000000' : (string) $row['mobile'])) . ') is not a valid Philippine mobile number. Please update your profile or contact support to receive OTP via SMS.';
                } else {
                    try {
                        $otp = create_login_otp($mysqli, $userId, $e164);
                        set_pending_login_state($userId, $otp['id'], $otp['masked_destination']);
                        _auth_diag('login_otp_required', [
                            'user_id'            => $userId,
                            'email'              => mask_email($email),
                            'otp_id'             => (int) $otp['id'],
                            'masked_destination' => (string) $otp['masked_destination'],
                        ]);
                        $smsBody = '[Marikina E-Concern] Your 6-digit login code is ' . $otp['otp_plain'] . '. Valid for 5 minutes. Do not share this code with anyone.';
                        $smsResult = send_sms($e164, $smsBody);
                        if (!$smsResult['ok']) {
                            $_SESSION['pending_login_sms_failed'] = true;
                            if (!empty($smsResult['error']) && is_string($smsResult['error'])) {
                                $_SESSION['pending_login_sms_error'] = trim($smsResult['error']);
                            }
                            if (empty($smsResult['http_status']) || (int) $smsResult['http_status'] === 0) {
                                $_SESSION['pending_login_sms_hint'] = 'TextBee device hint: make sure your device is turned on, the TextBee app is open, mobile data is on, and the device has SMS send permissions.';
                            }
                        }
                        redirect(app_url('/public/login_otp.php'));
                    } catch (Throwable $e) {
                        $errors[] = 'Server error while creating OTP challenge. Please try again later.';
                    }
                }
            }
        }
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="mc-login-page">
    <div class="mc-login-hero">
        <div class="mc-login-hero-inner">

            <section class="mc-login-form-panel" aria-label="Login form">
                <div class="mc-login-form-brand">
                    <img class="mc-login-form-brand-img" width="44" height="44" src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="Official Seal of Marikina City">
                    <div class="mc-login-form-brand-title">
                        <h1>Marikina E-Concern</h1>
                        <p>Citizen Portal · Log in</p>
                    </div>
                </div>

                <h2 class="mc-login-title">Log in to your account</h2>
                <p class="mc-login-subtitle">Please enter your email and password to continue.</p>

                <?php if (isset($_GET['registered']) && $_GET['registered'] === '1') : ?>
                    <div class="alert alert-success">Account created. Please login.</div>
                <?php endif; ?>
                <?php if (isset($_GET['verified']) && $_GET['verified'] === '1') : ?>
                    <div class="alert alert-success">Email verified! You may now log in.</div>
                <?php endif; ?>
                <?php if (isset($_GET['logout']) && $_GET['logout'] === '1') : ?>
                    <div class="alert alert-info">You have been signed out.</div>
                <?php endif; ?>
                <?php if ($notice !== '') : ?>
                    <div class="alert alert-info"><?= e($notice) ?></div>
                <?php endif; ?>
                <?php if ($errors !== []) : ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $err) : ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['flash_error']) && is_string($_SESSION['flash_error']) && $_SESSION['flash_error'] !== '') : ?>
                    <div class="alert alert-warning">
                        <?= e($_SESSION['flash_error']) ?>
                        <?php unset($_SESSION['flash_error']); ?>
                    </div>
                <?php endif; ?>

                <form method="post" id="citizenLoginForm" class="mc-login-form" novalidate>
                    <?= csrf_field() ?>

                    <div class="mc-login-field">
                        <label for="login_email" class="form-label">Email</label>
                        <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                            <i class="lucide mc-login-input-icon" data-lucide="mail"></i>
                            <input type="email" id="login_email" class="form-control mc-login-input" name="email" autocomplete="email" placeholder="Enter your email" value="<?= e($email) ?>" required>
                        </div>
                    </div>

                    <div class="mc-login-field">
                        <label for="login_password" class="form-label">Password</label>
                        <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                            <i class="lucide mc-login-input-icon" data-lucide="lock"></i>
                            <input type="password" id="login_password" class="form-control mc-login-input" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                        </div>
                    </div>

                    <div class="mc-login-options-row">
                        <a class="mc-login-forgot" href="<?= e(app_url('/public/forgot_password.php')) ?>">Forgot password?</a>
                    </div>

                    <button class="btn btn-primary mc-login-submit" type="submit" id="citizenLoginSubmit">
                        <span class="btn-label">Log in</span>
                        <span class="btn-spinner spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
                    </button>

                    <div class="mc-login-divider" aria-hidden="true"><span>OR</span></div>

                    <a class="btn btn-outline-secondary mc-login-alt-btn" href="<?= e(app_url('/public/index.php')) ?>">
                        <i class="lucide" data-lucide="home" style="width:18px;height:18px"></i>
                        Return to homepage
                    </a>
                </form>

<script>
(function () {
    var form = document.getElementById('citizenLoginForm');
    var btn = document.getElementById('citizenLoginSubmit');
    if (!form || !btn) return;
    form.addEventListener('submit', function (ev) {
        if (btn.dataset.submitting === '1') {
            ev.preventDefault();
            ev.stopPropagation();
            return false;
        }
        if (!form.checkValidity()) {
            return;
        }
        btn.dataset.submitting = '1';
        btn.disabled = true;
        var label = btn.querySelector('.btn-label');
        var spinner = btn.querySelector('.btn-spinner');
        if (label) label.textContent = 'Please wait…';
        if (spinner) spinner.classList.remove('d-none');
    });
    if (window.performance && window.performance.navigation && window.performance.navigation.type === 1) {
        if (btn) btn.dataset.submitting = '';
    }
})();
</script>
<?php if ($showResendVerificationLink) : ?>
<script>
window.__loginState = window.__loginState || {};
window.__loginState.showResendVerification = true;
</script>
<?php endif; ?>

                <div class="mc-login-alt-row">
                    No account yet? <a class="mc-login-alt-link" href="<?= e(app_url('/public/register.php')) ?>">Create an account</a>
<?php if ($showResendVerificationLink) : ?>
                    <span class="text-muted mx-2">·</span>
                    <span id="resendVerificationLinkWrap" class="d-none">
                        <a href="<?= e(app_url('/public/resend_verification.php')) ?>">Resend verification email</a>
                    </span>
<?php endif; ?>
                </div>
<script>
(function () {
    function revealResendLink() {
        var state = window.__loginState || {};
        if (!state.showResendVerification) return;
        var wrap = document.getElementById('resendVerificationLinkWrap');
        if (wrap) wrap.classList.remove('d-none');
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', revealResendLink);
    } else {
        revealResendLink();
    }
})();
</script>

                <p class="mc-login-footer-terms">By logging in, you agree to the <a href="#">Data Privacy Act (RA 10173)</a> and understand that false reports are subject to penalties.</p>
            </section>

            <aside class="mc-login-image-panel" aria-label="About Marikina E-Concern">
                <div class="mc-login-brand-head">
                    <img class="mc-login-brand-seal" width="48" height="48" src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="Marikina City Seal">
                    <div>
                        <div class="mc-login-brand-name">Lungsod ng Marikina</div>
                        <div class="mc-login-brand-sub">City Government Portal</div>
                    </div>
                </div>

                <h3 class="mc-login-hero-headline">
                    Empowering<br>Marikina citizens
                </h3>

                <div class="mc-login-hero-photo" aria-hidden="true">
                    <img width="520" height="390" src="<?= e(app_url('/assets/img/cityhall.png')) ?>" alt="Marikina City Hall">
                </div>
            </aside>

        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>
