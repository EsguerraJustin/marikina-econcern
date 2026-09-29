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
$notice = '';
$email = '';
$sentBanner = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }

    if ($errors === []) {
        $stmt = db_prepare($mysqli, 'SELECT id, active, email_verified_at, first_name FROM users WHERE email = ? LIMIT 1');
        if (!($stmt instanceof mysqli_stmt)) {
            $errors[] = 'Temporary database connection error. Please try again in a moment.';
        } else {
            db_prepared_execute($stmt, 's', [$email]);
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            $matchFound = is_array($row);
            $userId = $matchFound ? (int) $row['id'] : 0;
            $activeOk = $matchFound && (int) ($row['active'] ?? 0) === 1;
            $emailVerified = $matchFound && $row['email_verified_at'] !== null;
            $firstName = $matchFound ? (string) ($row['first_name'] ?? '') : '';

            if (!$matchFound || !$activeOk || !$emailVerified) {
                $sentBanner = true;
                $notice = 'If an active, verified account exists for this email, a password reset link has been sent. Please check your inbox (and spam folder). The link will expire in 1 hour.';
            } else {
                $lastTs = last_password_reset_created_at($mysqli, $userId);
                $cooldown = 60;
                if ($lastTs !== null && (time() - $lastTs) < $cooldown) {
                    $waitSec = $cooldown - (time() - $lastTs);
                    $notice = 'A password reset link was already sent recently. Please wait ' . $waitSec . ' more seconds before requesting another, or check your spam folder.';
                    $sentBanner = true;
                } else {
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $rawToken = create_password_reset($mysqli, $userId, $ip);
                        $resetUrl = app_public_url('/public/reset_password.php?token=' . $rawToken);
                        $content = build_password_reset_email_content($resetUrl, $firstName, 60);
                        $res = send_email($email, $content['subject'], $content['html'], $content['text']);
                        $sentBanner = true;
                        if (empty($res['ok'])) {
                            $notice = 'If an active, verified account exists for this email, a password reset link has been sent. Please check your inbox (and spam folder).';
                            if (!empty($res['error']) && is_string($res['error'])) {
                                $_SESSION['flash_error'] = 'Delivery system note: ' . mb_substr($res['error'], 0, 180);
                            }
                        } else {
                            $notice = 'Password reset link sent! Check your inbox (and spam folder). The link expires in 1 hour.';
                            if (!empty($res['provider_ref']) && is_string($res['provider_ref'])) {
                                $notice .= ' <span class="text-muted small">[Delivery ref: ' . e(mb_substr($res['provider_ref'], 1, 36)) . ']</span>';
                            }
                            $_SESSION['flash_last_reset_email'] = $email;
                        }
                    } catch (Throwable $e) {
                        $sentBanner = true;
                        $notice = 'If an active, verified account exists for this email, a password reset link has been sent. Please check your inbox (and spam folder).';
                    }
                }
            }
        }
    }
}

$pageTitle = 'Forgot Password';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <div class="fw-bold text-primary fs-4">Reset Password</div>
                            <div class="text-muted">Enter your email to receive a reset link</div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/public/login.php')) ?>">Back to Login</a>
                    </div>

                    <?php if ($sentBanner && $notice !== '') : ?>
                        <div class="alert alert-info"><?= $notice ?></div>
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

                    <?php if (!$sentBanner) : ?>
                        <form method="post" novalidate>
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label for="fp_email" class="form-label">Email address</label>
                                <input type="email" id="fp_email" class="form-control" name="email" autocomplete="email" value="<?= e($email) ?>" required>
                                <div class="form-text">We'll send a one-time reset link if the email matches an active, verified account.</div>
                            </div>
                            <div class="d-grid">
                                <button class="btn btn-primary" type="submit">Send Reset Link</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="d-grid mt-2">
                            <a class="btn btn-outline-primary" href="<?= e(app_url('/public/login.php')) ?>">Return to Login</a>
                        </div>
                    <?php endif; ?>

                    <div class="mt-4 text-center text-muted small">
                        Remembered your password? <a href="<?= e(app_url('/public/login.php')) ?>">Sign in</a>
                        <span class="mx-2">·</span>
                        No account? <a href="<?= e(app_url('/public/register.php')) ?>">Create one</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>
