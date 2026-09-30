<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/sms.php';

$mysqli = db();

$errors = [];
$notice = '';
$otpInput = '';

/* This is the only page in the application that renders the code prompt, and it
   was the only OTP screen without this guard -- admin/login_otp.php:9-11 has had
   one all along. Without it, any surviving pending challenge served the prompt
   to an already-authenticated citizen. */
if (is_logged_in()) {
    clear_pending_login_state();
    redirect(app_url('/public/dashboard.php'));
}

$pending = get_pending_login();
if (!is_array($pending)) {
    $_SESSION['flash_error'] = 'Your login session has expired or is invalid. Please start over.';
    redirect(app_url('/public/login.php'));
}

$userId = (int) $pending['user_id'];
$otpId = (int) $pending['otp_id'];
$maskedDest = (string) $pending['masked_destination'];

/* Re-read the stored preference rather than trusting the session. A citizen can
   turn 2FA off at any time, including while a challenge is pending, and
   public/login.php:97-108 honours the flag on the next attempt -- so the flag has
   to be honoured here too or the prompt outlives the opt-out. The password has
   already been verified at this point (public/login.php), so completing the
   login is correct, not a bypass. */
$otpPrefStmt = db_prepare($mysqli, 'SELECT otp_enabled FROM users WHERE id = ? LIMIT 1');
if ($otpPrefStmt instanceof mysqli_stmt) {
    db_prepared_execute($otpPrefStmt, 'i', [$userId]);
    $otpPrefRes = $otpPrefStmt->get_result();
    $otpPrefRow = $otpPrefRes ? $otpPrefRes->fetch_assoc() : null;
    $otpPrefStmt->close();
    if (is_array($otpPrefRow) && array_key_exists('otp_enabled', $otpPrefRow)
        && (int) $otpPrefRow['otp_enabled'] !== 1) {
        _auth_diag('login_otp_bypassed_preference_disabled', ['user_id' => $userId]);
        clear_pending_login_state();
        login_user($userId);
        redirect(app_url('/public/dashboard.php'));
    }
}

$activeOtp = get_active_otp($mysqli, $userId, true);
if (!is_array($activeOtp)) {
    clear_pending_login_state();
    $_SESSION['flash_error'] = 'This OTP session has expired. Please start over.';
    redirect(app_url('/public/login.php'));
}
$otpRowStale = (int) $activeOtp['id'] !== $otpId;

$createdAtTs = (int) ($activeOtp['created_at_ts'] ?? 0);
if (isset($activeOtp['expires_at_ts']) && (int) $activeOtp['expires_at_ts'] > 0) {
    $expiresAtTs = (int) $activeOtp['expires_at_ts'];
} else {
    $expiresAtTs = strtotime((string) ($activeOtp['expires_at'] ?? ''));
}
if ($expiresAtTs === false || $expiresAtTs <= 0) {
    $expiresAtTs = $createdAtTs + OTP_LIFETIME_SECONDS;
}
$nowTs = time();
$secondsLeft = max(0, $expiresAtTs - $nowTs);
$isExpired = $secondsLeft <= 0;

$resendCooldown = OTP_RESEND_COOLDOWN_SECONDS;
$canResend = otp_can_resend($createdAtTs, $resendCooldown);
$resendWaitSeconds = 0;
if (!$canResend) {
    $resendWaitSeconds = max(0, $resendCooldown - ($nowTs - $createdAtTs));
}

$maxAttempts = (int) ($activeOtp['max_attempts'] ?? 5);
$attemptCount = (int) ($activeOtp['attempt_count'] ?? 0);
$attemptsLeft = max(0, $maxAttempts - $attemptCount);
$noAttemptsLeft = $attemptsLeft <= 0;

$otpBlocked = $isExpired || $noAttemptsLeft || $otpRowStale;

$blockedReason = '';
if ($otpBlocked) {
    $reasons = [];
    if ($isExpired || $otpRowStale) $reasons[] = 'expired';
    if ($noAttemptsLeft) $reasons[] = 'attempt limit reached';
    $blockedReason = implode(' and ', $reasons);
}

$smsFailedNotice = '';
$smsFailedHint = '';
if (!empty($_SESSION['pending_login_sms_failed'])) {
    $smsFailedNotice = 'We prepared your verification code but couldn\'t reach your phone via SMS just now. The code displayed in your TextBee device conversation is still valid and you may enter it below, or wait a moment and click Resend.';
    if (!empty($_SESSION['pending_login_sms_error']) && is_string($_SESSION['pending_login_sms_error'])) {
        $smsFailedNotice .= ' Details: ' . trim($_SESSION['pending_login_sms_error']);
    }
    if (!empty($_SESSION['pending_login_sms_hint']) && is_string($_SESSION['pending_login_sms_hint'])) {
        $smsFailedHint = $_SESSION['pending_login_sms_hint'];
    }
    unset($_SESSION['pending_login_sms_failed'], $_SESSION['pending_login_sms_error'], $_SESSION['pending_login_sms_hint']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? (string) $_POST['action'] : 'verify';

    if ($action === 'resend') {
        if (!$canResend) {
            $errors[] = 'Please wait ' . max(1, $resendWaitSeconds) . ' second' . (max(1, $resendWaitSeconds) === 1 ? '' : 's') . ' before requesting a new code.';
        } else {
            $stmt = db_prepare($mysqli, 'SELECT id, mobile, first_name FROM users WHERE id = ? LIMIT 1');
            if (!($stmt instanceof mysqli_stmt)) {
                $errors[] = 'Temporary database connection error. Please try again in a moment.';
            } else {
            db_prepared_execute($stmt, 'i', [$userId]);
            $res = $stmt->get_result();
            $userRow = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!is_array($userRow)) {
                clear_pending_login_state();
                $errors[] = 'Account not found. Please start over.';
            } else {
                $e164 = normalize_ph_mobile((string) ($userRow['mobile'] ?? ''));
                if ($e164 === false) {
                    $errors[] = 'Mobile number on account is invalid. Please contact support.';
                } else {
                    try {
                        $newOtp = create_login_otp($mysqli, $userId, $e164);
                        $smsBody = '[Marikina E-Concern] Your 6-digit login code is ' . $newOtp['otp_plain'] . '. Valid for 5 minutes. Do not share this code with anyone.';
                        $smsResult = send_sms($e164, $smsBody);
                        if (!$smsResult['ok']) {
                            $errDetail = '';
                            if (!empty($smsResult['error']) && is_string($smsResult['error'])) {
                                $errDetail = ' — ' . trim($smsResult['error']);
                            }
                            $errors[] = 'Failed to send new OTP via SMS' . $errDetail . '. Please try again in a moment.';
                            if (empty($smsResult['http_status']) || (int) $smsResult['http_status'] === 0) {
                                $errors[] = 'TextBee device hint: make sure your phone is turned on, the TextBee app is open in the foreground, mobile data is active, and the device is not in Airplane mode.';
                            }
                        } else {
                            set_pending_login_state($userId, $newOtp['id'], $newOtp['masked_destination']);
                            redirect(app_url('/public/login_otp.php?resent=1'));
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Server error. Please try again later.';
                    }
                }
            }
            }
        }
    } else {
        if ($otpBlocked) {
            if ($isExpired || $otpRowStale) {
                $errors[] = 'This verification code has expired. Please request a new code.';
            } else {
                $errors[] = 'Too many incorrect attempts. Please request a new code.';
            }
        } else {
            $otpDigits = [];
            for ($i = 1; $i <= 6; $i++) {
                $d = isset($_POST['otp_' . $i]) ? trim((string) $_POST['otp_' . $i]) : '';
                if ($d === '' && isset($_POST['otp_code']) && is_string($_POST['otp_code'])) {
                    $d = substr(str_pad(preg_replace('/\D+/', '', (string) $_POST['otp_code']) ?? '', 6, '0', STR_PAD_RIGHT), $i - 1, 1);
                }
                $otpDigits[] = $d;
            }
            $otpInput = implode('', $otpDigits);
            if (isset($_POST['otp_code']) && is_string($_POST['otp_code'])) {
                $fromCode = preg_replace('/\D+/', '', (string) $_POST['otp_code']);
                if (strlen((string) $fromCode) === 6) {
                    $otpInput = (string) $fromCode;
                }
            }

            if (!preg_match('/^\d{6}$/', $otpInput)) {
                $errors[] = 'Please enter all 6 digits of the verification code.';
            } else {
                $verifyResult = verify_login_otp($mysqli, $otpId, $otpInput);
                if ($verifyResult['ok'] && isset($verifyResult['user_id']) && (int) $verifyResult['user_id'] === $userId) {
                    login_user($userId);
                    redirect(app_url('/public/dashboard.php'));
                } else {
                    $errors[] = $verifyResult['error'] ?? 'Incorrect verification code.';
                    $recheckOtp = get_active_otp($mysqli, $userId, true);
                    if (is_array($recheckOtp)) {
                        $attemptCount = (int) ($recheckOtp['attempt_count'] ?? 0);
                        $maxAttempts = (int) ($recheckOtp['max_attempts'] ?? 5);
                        $attemptsLeft = max(0, $maxAttempts - $attemptCount);
                        $noAttemptsLeft = $attemptsLeft <= 0;
                        if (isset($recheckOtp['expires_at_ts']) && (int) $recheckOtp['expires_at_ts'] > 0) {
                            $secondsLeft = max(0, (int) $recheckOtp['expires_at_ts'] - time());
                        } else {
                            $recheckExpires = strtotime((string) ($recheckOtp['expires_at'] ?? ''));
                            $secondsLeft = $recheckExpires !== false ? max(0, $recheckExpires - time()) : 0;
                        }
                        $isExpired = $secondsLeft <= 0;
                        $otpBlocked = $isExpired || $noAttemptsLeft;
                    }
                }
            }
        }
    }
}

$pageTitle = 'Enter Verification Code';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-5 col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <div>
                            <div class="fw-bold text-primary fs-4">Verification Code</div>
                            <div class="text-muted">Enter the 6-digit code sent to your mobile</div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/public/login.php')) ?>">Cancel</a>
                    </div>

                    <div class="alert alert-info small" role="alert">
                        <strong>Code sent to:</strong> <span class="font-monospace"><?= e($maskedDest) ?></span>
                    </div>

                    <?php if ($smsFailedNotice !== '') : ?>
                        <div class="alert alert-warning small" role="alert">
                            <div><strong>SMS delivery unavailable just now</strong></div>
                            <div class="mb-1"><?= e($smsFailedNotice) ?></div>
                            <?php if ($smsFailedHint !== '') : ?>
                                <div class="text-muted small"><?= e($smsFailedHint) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['resent']) && $_GET['resent'] === '1') : ?>
                        <div class="alert alert-success">A new verification code has been sent.</div>
                    <?php endif; ?>

                    <?php if (isset($_GET['expired']) && $_GET['expired'] === '1') : ?>
                        <div class="alert alert-warning">Your previous verification code expired. A new code has been sent — please enter the new code.</div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['flash_error']) && is_string($_SESSION['flash_error']) && $_SESSION['flash_error'] !== '') : ?>
                        <div class="alert alert-warning">
                            <?= e($_SESSION['flash_error']) ?>
                            <?php unset($_SESSION['flash_error']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($notice !== '') : ?>
                        <div class="alert alert-success"><?= e($notice) ?></div>
                    <?php endif; ?>

                    <?php if ($otpBlocked) : ?>
                        <div class="alert alert-danger" id="otpBlockedBanner" role="alert">
                            <div class="mb-1"><strong>This code cannot be used (<?= e($blockedReason) ?>).</strong></div>
                            <div>Please request a new verification code using the <em>Resend code</em> button below.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($errors !== []) : ?>
                        <div class="alert alert-danger" id="otpFormErrors" role="alert">
                            <ul class="mb-0">
                                <?php foreach ($errors as $err) : ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" id="otpForm" autocomplete="one-time-code" inputmode="numeric">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="otp_code" id="otp_code">

                        <fieldset id="otpFieldset"<?= $otpBlocked ? ' disabled' : '' ?>>
                            <div class="mc-otp-digits" role="group" aria-label="Enter 6-digit verification code">
                                <?php for ($i = 1; $i <= 6; $i++) : ?>
                                    <label for="otp_<?= $i ?>" class="visually-hidden">Digit <?= $i ?></label>
                                    <input
                                        id="otp_<?= $i ?>"
                                        class="form-control mc-otp-digit text-center"
                                        name="otp_<?= $i ?>"
                                        inputmode="numeric"
                                        pattern="[0-9]*"
                                        maxlength="1"
                                        autocomplete="one-time-code"
                                        required
                                        aria-label="Digit <?= $i ?> of 6"
                                        <?= $otpBlocked ? ' disabled' : '' ?>
                                    >
                                <?php endfor; ?>
                            </div>
                        </fieldset>

                        <div class="mb-3 d-flex justify-content-between align-items-center small">
                            <div class="text-muted">
                                <span id="attemptsLeftInfo">
                                    <?php if ($attemptsLeft <= 2) : ?>
                                        <span class="<?= $noAttemptsLeft ? 'text-danger fw-semibold' : 'text-danger fw-semibold' ?>"><?= $attemptsLeft ?> attempt<?= $attemptsLeft === 1 ? '' : 's' ?> left.</span>
                                    <?php else : ?>
                                        <?= $attemptsLeft ?> attempts left.
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="text-muted" id="countdownInfo">
                                <?php if ($isExpired) : ?>
                                    <span class="text-danger fw-semibold">Code expired.</span>
                                <?php else : ?>
                                    <span>Code expires in </span><span id="countdownTimer"><?= (int) floor($secondsLeft / 60) ?>:<?= str_pad((string) ($secondsLeft % 60), 2, '0', STR_PAD_LEFT) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-grid mb-3">
                            <button class="btn <?= $otpBlocked ? 'btn-outline-secondary' : 'btn-primary' ?>" type="submit" id="otpSubmitBtn"<?= $otpBlocked ? ' disabled aria-disabled="true"' : '' ?>>Verify &amp; Continue</button>
                        </div>
                    </form>

                    <form method="post" id="resendForm" class="text-center border-top pt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend">
                        <div class="small text-muted mb-2">Didn't receive the code?</div>
                        <button class="btn btn-link text-decoration-none" type="submit" id="resendBtn"<?= !$canResend ? ' disabled' : '' ?>>
                            Resend code<?= !$canResend ? ' (in ' . max(1, $resendWaitSeconds) . 's)' : '' ?>
                        </button>
                        <div class="small text-muted mt-1" id="resendCooldownInfo"<?= $canResend ? ' style="display:none;"' : '' ?>>You may request a new code once the 2-minute cooldown completes.</div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$initialSeconds = (int) $secondsLeft;
$initialCooldownSeconds = (int) max(0, $resendWaitSeconds);
$initiallyBlocked = $otpBlocked ? 'true' : 'false';
$initiallyNoAttempts = $noAttemptsLeft ? 'true' : 'false';
$initiallyExpired = $isExpired ? 'true' : 'false';
$resendCooldownMs = $resendCooldown * 1000;

$pageScripts = <<<SCRIPTBLOCK
<script>
(function () {
  var totalSeconds = {$initialSeconds};
  var cooldownSeconds = {$initialCooldownSeconds};
  var pendingUserId = {$userId};
  var initiallyBlocked = {$initiallyBlocked};
  var initiallyNoAttempts = {$initiallyNoAttempts};
  var initiallyExpired = {$initiallyExpired};
  var RESEND_COOLDOWN_MS = {$resendCooldownMs};
SCRIPTBLOCK;

$pageScripts .= <<<'JSBLOCK'
    var inputs = [];
    for (var i = 1; i <= 6; i++) {
        var el = document.getElementById('otp_' + i);
        if (el) inputs.push(el);
    }
    var codeField = document.getElementById('otp_code');
    var submitBtn = document.getElementById('otpSubmitBtn');
    var resendBtn = document.getElementById('resendBtn');
    var verifyForm = document.getElementById('otpForm');
    var resendForm = document.getElementById('resendForm');
    var timerEl = document.getElementById('countdownTimer');
    var countdownInfo = document.getElementById('countdownInfo');
    var attemptsInfo = document.getElementById('attemptsLeftInfo');
    var otpFieldset = document.getElementById('otpFieldset');
    var resendCooldownInfo = document.getElementById('resendCooldownInfo');
    var blockedBanner = document.getElementById('otpBlockedBanner');
    var formErrors = document.getElementById('otpFormErrors');
    var expiredFired = false;
    var noAttemptsFired = initiallyNoAttempts;

    function pad(n) { return (n < 10 ? '0' : '') + String(n); }

    function setSubmitting(btn, text) {
        if (!btn) return false;
        if (btn.dataset.submitting === '1') return false;
        btn.dataset.submitting = '1';
        btn.disabled = true;
        var oldHtml = btn.innerHTML;
        if (oldHtml && oldHtml !== '') {
            btn.dataset.origHtml = oldHtml;
        } else {
            btn.dataset.origText = btn.textContent;
        }
        btn.textContent = text;
        return true;
    }

    function combine() {
        var v = '';
        for (var i = 0; i < inputs.length; i++) v += (inputs[i].value || '').slice(-1);
        if (codeField) codeField.value = v;
        return v;
    }

    function focusInputAt(idx) {
        if (!inputs[idx]) return;
        inputs[idx].focus();
        try { inputs[idx].select(); } catch (_) {}
    }

    inputs.forEach(function (inp, idx) {
        inp.addEventListener('focus', function () { try { inp.select(); } catch (_) {} });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace') {
                if (inp.value === '' && idx > 0) {
                    e.preventDefault();
                    focusInputAt(idx - 1);
                    return;
                }
            }
            if (e.key === 'ArrowLeft' && idx > 0) {
                e.preventDefault();
                focusInputAt(idx - 1);
                return;
            }
            if (e.key === 'ArrowRight' && idx < inputs.length - 1) {
                e.preventDefault();
                focusInputAt(idx + 1);
                return;
            }
        });
        inp.addEventListener('input', function () {
            var val = (inp.value || '').replace(/\D+/g, '');
            if (val.length <= 1) {
                inp.value = val;
            } else {
                inp.value = val.slice(-1);
                var remaining = val.slice(0, -1);
                var putAt = idx - 1;
                for (var j = remaining.length - 1; j >= 0 && putAt >= 0; j--) {
                    if (inputs[putAt]) { inputs[putAt].value = remaining.charAt(j); }
                    putAt--;
                }
            }
            combine();
            if (inp.value !== '' && idx < inputs.length - 1) {
                focusInputAt(idx + 1);
            }
            if (combine().length === 6) {
                if (submitBtn) submitBtn.focus();
                if (verifyForm && submitBtn && submitBtn.dataset.submitting !== '1' && !submitBtn.disabled) {
                    setTimeout(function () {
                        try { verifyForm.requestSubmit(); }
                        catch (_) { verifyForm.submit(); }
                    }, 120);
                }
            }
        });
    });

    window.addEventListener('paste', function (e) {
        try {
            var txt = (e.clipboardData || window.clipboardData).getData('text');
            if (!txt) return;
            var digits = txt.replace(/\D+/g, '');
            if (digits.length >= 6 && inputs.length) {
                for (var k = 0; k < 6 && k < inputs.length; k++) {
                    inputs[k].value = digits.charAt(k);
                }
                combine();
                e.preventDefault();
                if (submitBtn) submitBtn.focus();
                if (verifyForm && submitBtn && submitBtn.dataset.submitting !== '1' && !submitBtn.disabled) {
                    setTimeout(function () {
                        try { verifyForm.requestSubmit(); }
                        catch (_) { verifyForm.submit(); }
                    }, 120);
                }
            }
        } catch (_) {}
    });

    if (verifyForm) {
        verifyForm.addEventListener('submit', function (ev) {
            combine();
            if (expiredFired || noAttemptsFired) {
                ev.preventDefault();
                ev.stopPropagation();
                if (submitBtn) submitBtn.disabled = true;
                ensureBlockedBanner();
                return false;
            }
            if (submitBtn && submitBtn.dataset.submitting === '1') {
                ev.preventDefault();
                ev.stopPropagation();
                return false;
            }
            if (!setSubmitting(submitBtn, 'Verifying…')) {
                ev.preventDefault();
                ev.stopPropagation();
                return false;
            }
        });
    }
    if (resendForm && resendBtn) {
        resendForm.addEventListener('submit', function (ev) {
            if (resendBtn.dataset.submitting === '1') {
                ev.preventDefault();
                ev.stopPropagation();
                return false;
            }
            if (!setSubmitting(resendBtn, 'Sending…')) {
                ev.preventDefault();
                ev.stopPropagation();
                return false;
            }
        });
    }

    function ensureBlockedBanner() {
        var reasons = [];
        if (expiredFired) reasons.push('expired');
        if (noAttemptsFired) reasons.push('attempt limit reached');
        var combined = reasons.join(' and ');
        if (!blockedBanner) {
            var newBanner = document.createElement('div');
            newBanner.className = 'alert alert-danger';
            newBanner.id = 'otpBlockedBanner';
            newBanner.setAttribute('role', 'alert');
            newBanner.innerHTML = '<div class="mb-1"><strong>This code cannot be used (' + combined + ').</strong></div><div>Please request a new verification code using the <em>Resend code</em> button below.</div>';
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
            var insertBefore = formErrors || verifyForm;
            if (insertBefore && insertBefore.parentNode) insertBefore.parentNode.insertBefore(newBanner, insertBefore);
            blockedBanner = newBanner;
        } else {
            blockedBanner.querySelector('strong').textContent = 'This code cannot be used (' + combined + ').';
        }
        if (countdownInfo) {
            countdownInfo.innerHTML = '<span class="text-danger fw-semibold">Code has expired.</span>';
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
        }
        if (attemptsInfo) {
            attemptsInfo.innerHTML = '<span class="text-danger fw-semibold">0 attempts left.</span>';
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
        }
        if (otpFieldset) otpFieldset.disabled = true;
        inputs.forEach(function (inp) { try { inp.disabled = true; } catch (_) {} });
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-disabled', 'true');
            submitBtn.classList.remove('btn-primary');
            submitBtn.classList.add('btn-outline-secondary');
        }
    }

    var LS_KEY_EXPIRE = 'otp_expire_u_' + pendingUserId;
    var LS_KEY_COOLDOWN = 'otp_resend_cd_u_' + pendingUserId;
    var nowTs = Date.now();
    var storedExpire = parseInt(localStorage.getItem(LS_KEY_EXPIRE) || '0', 10);
    var storedCooldown = parseInt(localStorage.getItem(LS_KEY_COOLDOWN) || '0', 10);
    var deadline;
    if (initiallyExpired || totalSeconds <= 0) {
        deadline = nowTs;
        try { localStorage.removeItem(LS_KEY_EXPIRE); } catch (_) {}
    } else if (storedExpire > nowTs && Math.abs((storedExpire - nowTs) - totalSeconds * 1000) < 30000) {
        deadline = storedExpire;
    } else {
        deadline = nowTs + Math.max(0, totalSeconds) * 1000;
        try { localStorage.setItem(LS_KEY_EXPIRE, String(deadline)); } catch (_) {}
    }
    var resendDeadline;
    if (cooldownSeconds === 0) {
        resendDeadline = nowTs;
        try { localStorage.removeItem(LS_KEY_COOLDOWN); } catch (_) {}
    } else if (storedCooldown > nowTs && storedCooldown < nowTs + RESEND_COOLDOWN_MS + 2000 && Math.abs((storedCooldown - nowTs) - cooldownSeconds * 1000) < 20000) {
        resendDeadline = storedCooldown;
    } else {
        resendDeadline = nowTs + Math.max(0, cooldownSeconds) * 1000;
        try { localStorage.setItem(LS_KEY_COOLDOWN, String(resendDeadline)); } catch (_) {}
    }

    function onExpire() {
        if (expiredFired) return;
        expiredFired = true;
        try { localStorage.removeItem(LS_KEY_EXPIRE); } catch (_) {}
        ensureBlockedBanner();
    }

    function onNoAttemptsLeft() {
        if (noAttemptsFired) return;
        noAttemptsFired = true;
        ensureBlockedBanner();
    }

    if (initiallyBlocked) {
        ensureBlockedBanner();
    }

    function tick() {
        var now = Date.now();
        var remainMs = deadline - now;
        var remain = Math.max(0, Math.round(remainMs / 1000));
        if (timerEl) {
            var mins = Math.floor(remain / 60);
            var secs = remain % 60;
            timerEl.textContent = String(mins) + ':' + pad(secs);
            if (remain <= 30 && remain > 0) {
                timerEl.classList.add('text-warning');
                timerEl.classList.remove('text-danger');
            } else if (remain <= 0) {
                timerEl.classList.remove('text-warning');
                timerEl.classList.add('text-danger');
            } else {
                timerEl.classList.remove('text-warning');
                timerEl.classList.remove('text-danger');
            }
        }
        if (remainMs <= 0) {
            onExpire();
        }
        var cdMs = resendDeadline - now;
        var cd = Math.max(0, Math.round(cdMs / 1000));
        if (resendBtn && resendBtn.dataset.submitting !== '1') {
            if (cd <= 0) {
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend code';
                if (resendCooldownInfo) resendCooldownInfo.style.display = 'none';
                try { localStorage.removeItem(LS_KEY_COOLDOWN); } catch (_) {}
            } else {
                resendBtn.disabled = true;
                resendBtn.textContent = 'Resend code (in ' + cd + 's)';
                if (resendCooldownInfo) resendCooldownInfo.style.display = '';
            }
        }
        return remainMs <= 0;
    }

    if (noAttemptsFired) {
        onNoAttemptsLeft();
    }

    var done = tick();
    if (!done) {
        var timerId = setInterval(function () {
            var finished = tick();
            if (finished && timerId) clearInterval(timerId);
        }, 1000);
    }
})();
</script>
JSBLOCK;

require_once __DIR__ . '/../includes/partials/foot.php';
?>
