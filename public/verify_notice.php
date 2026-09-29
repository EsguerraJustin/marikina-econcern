<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email_verification.php';

$verifyState = get_pending_verify_state();
$emailForDisplay = '';
if (is_array($verifyState) && isset($verifyState['email']) && $verifyState['email'] !== '') {
    $emailForDisplay = mask_email((string) $verifyState['email']);
} elseif (isset($_GET['email']) && is_string($_GET['email'])) {
    $rawEmail = strtolower(trim((string) $_GET['email']));
    if (filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
        $emailForDisplay = mask_email($rawEmail);
    }
}

$pageTitle = 'Verify Your Email';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5 text-center">
                    <div class="mx-auto mb-4 d-flex align-items-center justify-content-center" style="width:72px;height:72px;border-radius:50%;background:#cfe2ff;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M4 4H20C20.5523 4 21 4.44772 21 5V19C21 19.5523 20.5523 20 20 20H4C3.44772 20 3 19.5523 3 19V5C3 4.44772 3.44772 4 4 4Z" stroke="#0d6efd" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M3 7L12 13L21 7" stroke="#0d6efd" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <div class="fw-bold text-primary fs-4 mb-2">Verify Your Email Address</div>
                    <div class="text-muted mb-3">We've sent a verification link to your email.</div>

                    <?php if ($emailForDisplay !== '') : ?>
                        <div class="alert alert-info text-start small">
                            <strong>Sent to:</strong> <span class="font-monospace"><?= e($emailForDisplay) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['resent']) && $_GET['resent'] === '1') : ?>
                        <div class="alert alert-success small">
                            A fresh verification email has been sent. Please check your inbox (and spam folder).
                        </div>
                    <?php elseif (isset($_GET['unverified']) && $_GET['unverified'] === '1') : ?>
                        <div class="alert alert-warning small">
                            Your account has not been verified yet. Please confirm your email address before logging in.
                        </div>
                    <?php endif; ?>

                    <ol class="text-start text-muted ps-4 mb-4" style="line-height:1.8;">
                        <li>Open your email inbox and find the message from <strong><?= e(MAIL_FROM_NAME) ?></strong>.</li>
                        <li>Click the <strong>Verify Email Address</strong> button in the email.</li>
                        <li>You will be automatically signed in and redirected to your dashboard.</li>
                    </ol>

                    <div class="alert alert-warning text-start small mb-4">
                        <strong>Can't find the email?</strong>
                        <ul class="mb-0 mt-1">
                            <li>Check your Spam or Junk folder.</li>
                            <li>Wait a few minutes — email delivery can sometimes be delayed.</li>
                            <li>If you still do not receive it, use the resend link below.</li>
                        </ul>
                    </div>

                    <div class="d-grid gap-2">
                        <?php if (is_array($verifyState) && ($verifyState['user_id'] ?? 0) > 0) : ?>
                            <a class="btn btn-outline-primary" href="<?= e(app_url('/public/resend_verification.php')) ?>">Resend verification email</a>
                        <?php else : ?>
                            <a class="btn btn-outline-primary" href="<?= e(app_url('/public/resend_verification.php')) ?>">Resend verification email (enter email)</a>
                        <?php endif; ?>
                        <a class="btn btn-link text-decoration-none" href="<?= e(app_url('/public/login.php')) ?>">Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>
