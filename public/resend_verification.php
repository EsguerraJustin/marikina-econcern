<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email_verification.php';
require_once __DIR__ . '/../includes/mailer.php';

if (is_logged_in()) {
    redirect(app_url('/public/dashboard.php'));
}

$errors = [];
$notice = '';
$email = '';
$pendingState = get_pending_verify_state();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($errors === []) {
        $mysqli = db();
        $stmt = $mysqli->prepare('SELECT id, first_name, email, email_verified_at, active FROM users WHERE email = ? LIMIT 1');
        db_prepared_execute($stmt, 's', [$email]);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            $notice = 'If this email is registered, a new verification link has been sent. Please check your inbox.';
        } elseif (!(int) ($row['active'] ?? 0)) {
            $errors[] = 'This account has been disabled. Please contact support.';
        } elseif ($row['email_verified_at'] !== null) {
            $notice = 'This email is already verified. You may now log in.';
        } else {
            $userId = (int) $row['id'];
            $firstName = (string) $row['first_name'];
            $userEmail = (string) $row['email'];

            $lastTs = last_verification_created_at($mysqli, $userId);
            $minInterval = 60;
            if ($lastTs !== null && (time() - $lastTs) < $minInterval) {
                $wait = $minInterval - (time() - $lastTs);
                $errors[] = 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before requesting another verification email.';
            } else {
                try {
                    $rawToken = create_email_verification($mysqli, $userId);
                    $verifyUrl = app_public_url('/public/verify_email.php?token=' . $rawToken);
                    $emailContent = build_verification_email_content($verifyUrl, $firstName);
                    $mailResult = send_email($userEmail, $emailContent['subject'], $emailContent['html'], $emailContent['text']);
                    if (!$mailResult['ok']) {
                        _ev_diag('resend_mail_failed', [
                            'user_id' => $userId,
                            'email_masked' => mask_email($userEmail),
                            'mail_error' => $mailResult['error'] ?? 'unknown',
                        ]);
                    }
                    set_pending_verify_state($userId, $userEmail);
                    $notice = 'If this email is registered, a new verification link has been sent. Please check your inbox.';
                } catch (Throwable $e) {
                    $errors[] = 'Server error while sending verification email. Please try again later.';
                }
            }
        }
    }
} elseif (is_array($pendingState) && ($pendingState['user_id'] ?? 0) > 0 && ($pendingState['email'] ?? '') !== '') {
    $email = (string) $pendingState['email'];
}

$pageTitle = 'Resend Verification Email';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <div class="fw-bold text-primary fs-4 mb-2">Resend Verification Email</div>
                    <div class="text-muted mb-4">Enter your registered email address and we will send a fresh verification link.</div>

                    <?php if ($notice !== '') : ?>
                        <div class="alert alert-success"><?= e($notice) ?></div>
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

                    <?php if ($notice === '') : ?>
                        <form method="post" id="resendForm">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label for="resend_email" class="form-label">Email Address</label>
                                <input type="email" id="resend_email" class="form-control" name="email" autocomplete="email" value="<?= e($email) ?>" required placeholder="you@example.com">
                            </div>
                            <div class="d-grid">
                                <button class="btn btn-primary" type="submit" id="resendSubmit">Send verification link</button>
                            </div>
                        </form>
<script>
(function () {
    var form = document.getElementById('resendForm');
    var btn = document.getElementById('resendSubmit');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        if (btn.dataset.submitting === '1') return false;
        btn.dataset.submitting = '1';
        btn.disabled = true;
        btn.textContent = 'Sending…';
    });
})();
</script>
                    <?php endif; ?>

                    <div class="mt-4 text-center">
                        <a class="btn btn-link text-decoration-none" href="<?= e(app_url('/public/login.php')) ?>">Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>
