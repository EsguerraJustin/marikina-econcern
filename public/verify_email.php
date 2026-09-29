<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email_verification.php';

if (is_logged_in()) {
    redirect(app_url('/public/dashboard.php'));
}

$token = isset($_GET['token']) && is_string($_GET['token']) ? trim((string) $_GET['token']) : '';
$result = null;
$status = 'invalid';

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $mysqli = db();
    $userId = consume_email_verification($mysqli, $token);
    if ($userId !== false) {
        $status = 'verified';
        $result = ['user_id' => $userId];
        $stmt = $mysqli->prepare('SELECT first_name, email FROM users WHERE id = ? LIMIT 1');
        db_prepared_execute($stmt, 'i', [$userId]);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (is_array($row)) {
            $result['first_name'] = (string) ($row['first_name'] ?? '');
            $result['email'] = (string) ($row['email'] ?? '');
        }
        login_user($userId);
    } else {
        $status = 'failed';
    }
}

$pageTitle = 'Email Verification';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <?php if ($status === 'verified') : ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 p-md-5 text-center">
                        <div class="mx-auto mb-4 d-flex align-items-center justify-content-center" style="width:72px;height:72px;border-radius:50%;background:#d1e7dd;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M5 12L10 17L20 7" stroke="#198754" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="fw-bold text-success fs-4 mb-2">Email Verified!</div>
                        <div class="text-muted mb-4">
                            Hello, <strong><?= e($result['first_name'] ?? '') ?></strong>. Your email address has been confirmed and your account is now fully active.
                        </div>
                        <div class="d-grid">
                            <a class="btn btn-primary" href="<?= e(app_url('/public/dashboard.php')) ?>">Continue to Dashboard</a>
                        </div>
                    </div>
                </div>
            <?php elseif ($status === 'failed') : ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 p-md-5 text-center">
                        <div class="mx-auto mb-4 d-flex align-items-center justify-content-center" style="width:72px;height:72px;border-radius:50%;background:#f8d7da;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M12 9V13M12 17H12.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="#dc3545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="fw-bold text-danger fs-4 mb-2">Verification Failed</div>
                        <div class="text-muted mb-4">
                            This verification link is invalid, expired, or has already been used. Please request a new one.
                        </div>
                        <div class="d-grid gap-2">
                            <a class="btn btn-outline-primary" href="<?= e(app_url('/public/resend_verification.php')) ?>">Resend verification email</a>
                            <a class="btn btn-link text-decoration-none" href="<?= e(app_url('/public/login.php')) ?>">Back to Login</a>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 p-md-5 text-center">
                        <div class="fw-bold text-primary fs-4 mb-2">Email Verification</div>
                        <div class="text-muted mb-4">
                            Please check your email for the verification link, or login first.
                        </div>
                        <div class="d-grid gap-2">
                            <a class="btn btn-primary" href="<?= e(app_url('/public/login.php')) ?>">Go to Login</a>
                            <a class="btn btn-outline-primary" href="<?= e(app_url('/public/resend_verification.php')) ?>">Resend verification email</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>
