<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email_verification.php';
require_once __DIR__ . '/../includes/mailer.php';

if (is_logged_in()) {
    redirect(app_url('/public/dashboard.php'));
}

$mysqli = db();

$errors = [];
$values = [
    'first_name' => '',
    'last_name' => '',
    'mobile' => '',
    'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $values['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
    $values['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
    $values['mobile'] = trim((string) ($_POST['mobile'] ?? ''));
    $values['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $agree = (string) ($_POST['agree'] ?? '');

    if ($values['first_name'] === '') $errors[] = 'First name is required.';
    if ($values['last_name'] === '') $errors[] = 'Last name is required.';
    if ($values['mobile'] === '') $errors[] = 'Mobile number is required.';
    if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if ($agree !== '1') $errors[] = 'You must agree to the Data Privacy consent to continue.';

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    $pwErrors = validate_password_rules($password);
    $errors = array_merge($errors, $pwErrors);

    if ($errors === []) {
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        db_prepared_execute($stmt, 's', [$values['email']]);
        $exists = $stmt->get_result();
        $existing = $exists ? $exists->fetch_assoc() : null;
        $stmt->close();

        if ($existing) {
            $errors[] = 'Email is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $mysqli->begin_transaction();
            try {
                $stmt = $mysqli->prepare('INSERT INTO users (first_name, last_name, mobile, email, password_hash) VALUES (?, ?, ?, ?, ?)');
                $ok = db_prepared_execute($stmt, 'sssss', [$values['first_name'], $values['last_name'], $values['mobile'], $values['email'], $hash]);
                $userId = (int) $mysqli->insert_id;
                $stmt->close();
                if (!$ok || $userId <= 0) {
                    throw new RuntimeException('INSERT failed');
                }

                $rawToken = create_email_verification($mysqli, $userId);
                $verifyUrl = app_public_url('/public/verify_email.php?token=' . $rawToken);
                $emailContent = build_verification_email_content($verifyUrl, $values['first_name']);
                $mailResult = send_email($values['email'], $emailContent['subject'], $emailContent['html'], $emailContent['text']);

                if (!$mailResult['ok']) {
                    _ev_diag('register_mail_failed', [
                        'user_id' => $userId,
                        'email_masked' => mask_email($values['email']),
                        'mail_error' => $mailResult['error'] ?? 'unknown',
                    ]);
                }

                $mysqli->commit();

                set_pending_verify_state($userId, $values['email']);
                redirect(app_url('/public/verify_notice.php'));
            } catch (Throwable $e) {
                try { $mysqli->rollback(); } catch (Throwable $_) {}
                _ev_diag('register_transaction_failed', ['error' => $e->getMessage()]);
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create an Account';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="mc-register-page">
    <div class="mc-register-hero">
        <div class="mc-register-hero-inner">

            <section class="mc-register-form-panel" aria-label="Create account form">
                <div class="mc-register-form-brand">
                    <img class="mc-register-form-brand-img" width="44" height="44" src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="Official Seal of Marikina City">
                    <div class="mc-register-form-brand-title">
                        <h1>Marikina E-Concern</h1>
                        <p>Citizen Portal · Sign up</p>
                    </div>
                </div>

                <h2 class="mc-register-title">Create your account</h2>
                <p class="mc-register-subtitle">Fill out your details to get started — takes less than 60 seconds.</p>

                <?php if ($errors !== []) : ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $err) : ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" class="mc-register-form" data-password-meter="#password">
                    <?= csrf_field() ?>

                    <div class="mc-register-form-row-2">
                        <div class="mc-register-field">
                            <label for="reg_first_name" class="form-label">First Name</label>
                            <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                                <i class="lucide mc-register-input-icon" data-lucide="user"></i>
                                <input id="reg_first_name" class="form-control mc-register-input" name="first_name" autocomplete="given-name" placeholder="Juan" value="<?= e($values['first_name']) ?>" required>
                            </div>
                        </div>
                        <div class="mc-register-field">
                            <label for="reg_last_name" class="form-label">Last Name</label>
                            <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                                <i class="lucide mc-register-input-icon" data-lucide="user-round"></i>
                                <input id="reg_last_name" class="form-control mc-register-input" name="last_name" autocomplete="family-name" placeholder="Dela Cruz" value="<?= e($values['last_name']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="mc-register-form-row-2">
                        <div class="mc-register-field">
                            <label for="reg_mobile" class="form-label">Mobile Number</label>
                            <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                                <i class="lucide mc-register-input-icon" data-lucide="phone"></i>
                                <input id="reg_mobile" class="form-control mc-register-input" name="mobile" autocomplete="tel" placeholder="0917 000 0000" value="<?= e($values['mobile']) ?>" required>
                            </div>
                        </div>
                        <div class="mc-register-field">
                            <label for="reg_email" class="form-label">Email</label>
                            <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                                <i class="lucide mc-register-input-icon" data-lucide="mail"></i>
                                <input type="email" id="reg_email" class="form-control mc-register-input" name="email" autocomplete="email" placeholder="you@example.com" value="<?= e($values['email']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="mc-register-form-row-2">
                        <div class="mc-register-field">
                            <label for="password" class="form-label">Password</label>
                            <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                                <i class="lucide mc-register-input-icon" data-lucide="lock"></i>
                                <input type="password" class="form-control mc-register-input" id="password" name="password" autocomplete="new-password" placeholder="Create a strong password" required>
                            </div>
                            <div class="mc-password-meter password-meter mt-2" aria-hidden="true">
                                <span></span><span></span><span></span><span></span>
                            </div>
                        </div>
                        <div class="mc-register-field">
                            <label for="reg_confirm_password" class="form-label">Confirm Password</label>
                            <div style="position:relative;height:48px;width:100%;margin-top:6px;">
                                <i class="lucide mc-register-input-icon" data-lucide="shield-check"></i>
                                <input type="password" class="form-control mc-register-input" id="reg_confirm_password" name="confirm_password" autocomplete="new-password" placeholder="Repeat password" required>
                            </div>
                        </div>
                    </div>

                    <label class="mc-register-terms">
                        <input type="checkbox" value="1" id="agree" name="agree" required>
                        <span>I agree to the <a href="#">Data Privacy consent (RA 10173)</a> and understand that false reports are subject to penalties.</span>
                    </label>

                    <button class="btn btn-primary mc-register-submit" type="submit" id="registerSubmit">Create Account</button>

                    <div class="mc-register-divider" aria-hidden="true"><span>OR</span></div>

                    <a class="btn btn-outline-secondary mc-register-alt-btn" href="<?= e(app_url('/public/index.php')) ?>">
                        <i class="lucide" data-lucide="home" style="width:18px;height:18px"></i>
                        Return to homepage
                    </a>
                </form>

<script>
(function () {
    var form = document.querySelector('#reg_first_name')?.closest('form');
    var btn = document.getElementById('registerSubmit');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        if (btn.dataset.submitting === '1') return false;
        btn.dataset.submitting = '1';
        btn.disabled = true;
        btn.textContent = 'Creating account…';
    });
})();
</script>

                <div class="mc-register-alt-row">
                    Already have an account? <a class="mc-register-alt-link" href="<?= e(app_url('/public/login.php')) ?>">Log in</a>
                </div>

                <p class="mc-register-footer-terms">We'll never share your information with third parties. All data is encrypted at rest and in transit.</p>
            </section>

            <aside class="mc-register-image-panel" aria-label="About Marikina E-Concern">
                <div class="mc-register-brand-head">
                    <img class="mc-register-brand-seal" width="48" height="48" src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="Marikina City Seal">
                    <div>
                        <div class="mc-register-brand-name">Lungsod ng Marikina</div>
                        <div class="mc-register-brand-sub">Shoe Capital since 1630</div>
                    </div>
                </div>

                <h3 class="mc-register-hero-headline">
                    Building a cleaner,<br>more connected city
                </h3>

                <div class="mc-register-hero-photo" aria-hidden="true">
                    <img width="520" height="390" src="<?= e(app_url('/assets/img/cityhall.png')) ?>" alt="Marikina City Hall">
                </div>
            </aside>

        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>

