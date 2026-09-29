<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/sms.php';

if (is_admin_logged_in()) {
    redirect(app_url('/admin/dashboard.php'));
}

$pageTitle = 'Admin Login';

function _login_admin_diag(string $event, array $extra = []): void
{
    $line = json_encode([
        'ts' => date('c'),
        'event' => $event,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'php_cli',
    ] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) $line = date('c') . ' ' . $event;
    @error_log($line . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

$errors = [];
$identity = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $identity = trim((string) ($_POST['identity'] ?? ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $identityForLog = $identity === '' ? '' : $identity;

    if ($identity === '') {
        $errors[] = 'Email or name is required.';
        _login_admin_diag('login_admin_validation_missing_identity');
    } elseif ($password === '') {
        $errors[] = 'Password is required.';
        _login_admin_diag('login_admin_validation_missing_password', ['identity' => $identityForLog]);
    } else {
        $mysqli = db();
        $stmt = db_prepare($mysqli, 'SELECT id, email, name, password_hash, active, otp_enabled, role, failed_login_attempts, locked_until, mobile FROM admins WHERE email = ? OR name = ? LIMIT 1');
        if (!($stmt instanceof mysqli_stmt)) {
            $errors[] = 'Temporary database connection error. Please try again in a moment.';
            _login_admin_diag('login_admin_stmt_prepare_failed', ['identity' => $identityForLog, 'error' => $mysqli->error]);
        } else {
            db_prepared_execute($stmt, 'ss', [$identity, $identity]);
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            $matchFound = is_array($row);
            $adminId = $matchFound ? (int) $row['id'] : 0;
            $hash = $matchFound ? (string) $row['password_hash'] : '';
            $passwordOk = $matchFound && $hash !== '' && password_verify($password, $hash);
            $activeOk = $matchFound && (int) ($row['active'] ?? 0) === 1;

            if ($matchFound && is_admin_locked($mysqli, $adminId)) {
                $errors[] = 'This account has been temporarily locked due to too many failed login attempts. Please try again later or contact a super admin.';
            } elseif (!$matchFound || !$passwordOk) {
                if (!$matchFound || !$activeOk) {
                    $errors[] = 'Invalid credentials.';
                } else {
                    $errors[] = 'Invalid credentials.';
                    increment_admin_failed_login($mysqli, $adminId, 15, 10);
                }
                _login_admin_diag('login_admin_failed', [
                    'identity' => $identityForLog,
                    'match_found' => $matchFound,
                    'matched_email' => $matchFound ? (string) ($row['email'] ?? '') : null,
                    'matched_name' => $matchFound ? (string) ($row['name'] ?? '') : null,
                    'active_ok' => $activeOk,
                    'password_verify_passed' => $passwordOk,
                ]);
            } elseif (!$activeOk) {
                $errors[] = 'This account has been disabled. Please contact a super admin.';
            } else {
                $otpOn = (int) ($row['otp_enabled'] ?? 1) === 1;
                if (!$otpOn) {
                    clear_pending_admin_login_state();
                    increment_admin_failed_login($mysqli, $adminId, 0, 0);
                    $stmt = $mysqli->prepare('UPDATE admins SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?');
                    if ($stmt instanceof mysqli_stmt) {
                        db_prepared_execute($stmt, 'i', [$adminId]);
                        $stmt->close();
                    }
                    login_admin($adminId);
                    _login_admin_diag('login_admin_success_otp_disabled', [
                        'admin_id' => $adminId,
                        'matched_email' => (string) ($row['email'] ?? ''),
                    ]);
                    redirect(app_url('/admin/dashboard.php'));
                }
                $mobileRaw = (string) ($row['mobile'] ?? '');
                $e164 = normalize_ph_mobile($mobileRaw);
                if ($e164 === false) {
                    $displayMobile = $mobileRaw === '' ? '(none on file)' : (string) $row['mobile'];
                    $errors[] = 'The mobile number on this account (' . e(mask_mobile($displayMobile)) . ') is not a valid Philippine mobile number. Please update your profile or contact a SUPER ADMIN to add a valid Philippine number for SMS OTP delivery.';
                } else {
                    try {
                        $otp = admin_create_login_otp($mysqli, $adminId, $e164);
                        set_pending_admin_login_state($adminId, $otp['id'], $otp['masked_destination']);
                        $smsBody = '[Marikina E-Concern Admin] Your 6-digit login code is ' . $otp['otp_plain'] . '. Valid for 5 minutes. Do not share this code with anyone.';
                        $smsResult = send_sms($e164, $smsBody);
                        if (!$smsResult['ok']) {
                            $_SESSION['pending_admin_login_sms_failed'] = true;
                            if (!empty($smsResult['error']) && is_string($smsResult['error'])) {
                                $_SESSION['pending_admin_login_sms_error'] = trim($smsResult['error']);
                            }
                            if (empty($smsResult['http_status']) || (int) $smsResult['http_status'] === 0) {
                                $_SESSION['pending_admin_login_sms_hint'] = 'TextBee device hint: make sure your device is turned on, the TextBee app is open, mobile data is on, and the device has SMS send permissions.';
                            }
                        }
                        redirect(app_url('/admin/login_otp.php'));
                    } catch (Throwable $e) {
                        $errors[] = 'Server error while creating OTP challenge. Please try again later.';
                        _login_admin_diag('login_admin_otp_create_failed', [
                            'admin_id' => $adminId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/partials/head.php';

?>
<main class="mc-admin-login-page" aria-label="Admin Login">
    <div class="mc-admin-login-hero" role="presentation">
        <div class="mc-admin-login-hero-inner">

            <section class="mc-admin-login-form-panel" aria-label="Admin login form">
                <div class="mc-admin-login-form-brand">
                    <img class="mc-admin-login-form-brand-img" width="44" height="44" src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="Official Seal of Marikina City">
                    <div class="mc-admin-login-form-brand-title">
                        <h1>Marikina E-Concern</h1>
                        <p>Admin Portal · Staff Login</p>
                    </div>
                </div>

                <h2 class="mc-admin-login-title">Log in to your admin account</h2>
                <p class="mc-admin-login-subtitle">Authorized LGU personnel only. Secure staff portal with OTP verification.</p>

                <?php if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1') : ?>
                    <div class="alert alert-info mc-admin-login-alert" role="alert">You have been signed out.</div>
                <?php endif; ?>

                <?php if ($notice !== '') : ?>
                    <div class="alert alert-info mc-admin-login-alert" role="alert"><?= e($notice) ?></div>
                <?php endif; ?>

                <?php if ($errors !== []) : ?>
                    <div class="alert alert-danger mc-admin-login-alert" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $err) : ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['flash_error']) && is_string($_SESSION['flash_error']) && $_SESSION['flash_error'] !== '') : ?>
                    <div class="alert alert-warning mc-admin-login-alert" role="alert">
                        <?= e($_SESSION['flash_error']) ?>
                        <?php unset($_SESSION['flash_error']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['flash_success']) && is_string($_SESSION['flash_success']) && $_SESSION['flash_success'] !== '') : ?>
                    <div class="alert alert-success mc-admin-login-alert" role="alert">
                        <?= e($_SESSION['flash_success']) ?>
                        <?php unset($_SESSION['flash_success']); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="mc-admin-login-form" id="adminLoginForm" novalidate>
                    <?= csrf_field() ?>

                    <div class="mc-admin-login-field">
                        <label for="admin_identity" class="form-label">Email / Name</label>
                        <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                            <i class="lucide mc-admin-login-input-icon" data-lucide="user-round" aria-hidden="true"></i>
                            <input id="admin_identity" class="mc-admin-login-input form-control" type="text" name="identity" autocomplete="username" placeholder="Enter your email or staff name" value="<?= e($identity) ?>" required>
                        </div>
                    </div>

                    <div class="mc-admin-login-field">
                        <label for="admin_password" class="form-label">Password</label>
                        <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                            <i class="lucide mc-admin-login-input-icon" data-lucide="lock" aria-hidden="true"></i>
                            <input id="admin_password" class="mc-admin-login-input form-control" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                        </div>
                    </div>

                    <button class="mc-admin-login-submit btn btn-primary" type="submit" id="adminLoginSubmit">
                        <span class="btn-label">Log in</span>
                        <span class="btn-spinner spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
                    </button>

                    <div class="mc-admin-login-divider" role="separator" aria-label="Other actions">OR</div>

                    <a class="mc-admin-login-alt-btn btn btn-outline-secondary" href="<?= e(app_url('/public/index.php')) ?>">
                        <i class="lucide" data-lucide="home" aria-hidden="true"></i>
                        Return to homepage
                    </a>
                </form>
<script>
(function () {
    var form = document.getElementById('adminLoginForm');
    var btn = document.getElementById('adminLoginSubmit');
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

                <div class="mc-admin-login-alt-row">
                    <span>Citizen portal:</span>
                    <a class="mc-admin-login-alt-link" href="<?= e(app_url('/public/login.php')) ?>">Login as resident</a>
                </div>

                <div class="mc-admin-login-footer-terms">
                    <p>Restricted staff access. By logging in you agree to the <a href="#">City IT Acceptable Use Policy</a> and <a href="#">Data Privacy Act (RA 10173)</a>. All sessions are audit-logged.</p>
                </div>
            </section>

            <aside class="mc-admin-login-image-panel" aria-label="About Marikina E-Concern Admin Portal">
                <div class="mc-admin-login-brand-head">
                    <img class="mc-admin-login-brand-seal" width="48" height="48" src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="Marikina City Seal">
                    <div>
                        <div class="mc-admin-login-brand-name">Lungsod ng Marikina</div>
                        <div class="mc-admin-login-brand-sub">City Government · Operations Hub</div>
                    </div>
                </div>

                <h3 class="mc-admin-login-hero-headline">
                    Managing city services<br>for every barangay
                </h3>

                <ul class="mc-admin-login-hero-bullets" role="list">
                    <li><i class="lucide" data-lucide="check-circle-2" aria-hidden="true"></i> Concern triage & assignment</li>
                    <li><i class="lucide" data-lucide="check-circle-2" aria-hidden="true"></i> Waste collection & route dashboards</li>
                    <li><i class="lucide" data-lucide="check-circle-2" aria-hidden="true"></i> Barangay reports & analytics</li>
                </ul>

                <div class="mc-admin-login-hero-photo" aria-hidden="true">
                    <img width="520" height="390" src="<?= e(app_url('/assets/img/cityhall.png')) ?>" alt="Marikina City Hall">
                </div>
            </aside>

        </div>
    </div>
</main>

<?php
require_once __DIR__ . '/../includes/partials/foot.php';
