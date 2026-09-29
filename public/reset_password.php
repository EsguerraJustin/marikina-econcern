<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email_verification.php';

if (is_logged_in()) {
    redirect(app_url('/public/dashboard.php'));
}

$mysqli = db();
$errors = [];
$notice = '';
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$tokenValid = false;
$completed = false;

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $errors[] = 'Invalid or expired password reset link. Please request a new one.';
} else {
    $tokenHash = hash('sha256', $token);
    $stmt = db_prepare($mysqli, 'SELECT pr.id, pr.user_id, pr.expires_at, pr.consumed_at, u.first_name
        FROM user_password_resets pr
        LEFT JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ? LIMIT 1');
    if ($stmt instanceof mysqli_stmt) {
        db_prepared_execute($stmt, 's', [$tokenHash]);
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            $errors[] = 'Invalid or expired password reset link. Please request a new one.';
        } elseif ($row['consumed_at'] !== null) {
            $errors[] = 'This password reset link has already been used. Please request a new one.';
        } elseif (strtotime((string) $row['expires_at']) < time()) {
            $errors[] = 'This password reset link has expired. Please request a new one.';
        } else {
            $tokenValid = true;
        }
    } else {
        $errors[] = 'Temporary database connection error. Please try again in a moment.';
    }
}

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pwd1 = (string) ($_POST['password'] ?? '');
    $pwd2 = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($pwd1) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($pwd1 !== $pwd2) {
        $errors[] = 'Passwords do not match.';
    }

    if ($errors === []) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $result = consume_password_reset($mysqli, $token, $pwd1, $ip);
        if (!empty($result['ok'])) {
            $completed = true;
            $notice = 'Password updated successfully! You may now log in with your new password.';
            $tokenValid = false;
        } else {
            $errors[] = !empty($result['error']) && is_string($result['error']) ? $result['error'] : 'Server error while resetting password. Please try again later.';
        }
    }
}

$pageTitle = 'Set New Password';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <div class="fw-bold text-primary fs-4">Set New Password</div>
                            <div class="text-muted">Choose a new, strong password for your account</div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/public/login.php')) ?>">Back to Login</a>
                    </div>

                    <?php if ($notice !== '') : ?>
                        <div class="alert alert-<?= $completed ? 'success' : 'info' ?>">
                            <?= e($notice) ?>
                            <?php if ($completed) : ?>
                                <div class="mt-3 d-grid">
                                    <a class="btn btn-primary" href="<?= e(app_url('/public/login.php')) ?>">Proceed to Login</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($errors !== []) : ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $err) : ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (!$completed) : ?>
                                <div class="mt-3 d-grid">
                                    <a class="btn btn-outline-danger" href="<?= e(app_url('/public/forgot_password.php')) ?>">Request a new reset link</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($tokenValid && !$completed) : ?>
                        <form method="post" id="resetPwdForm" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="token" value="<?= e($token) ?>">
                            <div class="mb-3">
                                <label for="rp_pwd1" class="form-label">New password</label>
                                <input type="password" id="rp_pwd1" class="form-control" name="password" autocomplete="new-password" minlength="8" required>
                                <div class="form-text">At least 8 characters. Mix letters, numbers, and symbols for best security.</div>
                            </div>
                            <div class="mb-3">
                                <label for="rp_pwd2" class="form-label">Confirm new password</label>
                                <input type="password" id="rp_pwd2" class="form-control" name="password_confirm" autocomplete="new-password" minlength="8" required>
                            </div>
                            <div class="d-grid">
                                <button class="btn btn-primary" type="submit">Update Password & Sign In</button>
                            </div>
                        </form>
                        <script>
                        (function () {
                            var f = document.getElementById('resetPwdForm');
                            if (!f) return;
                            f.addEventListener('submit', function (ev) {
                                var p1 = document.getElementById('rp_pwd1');
                                var p2 = document.getElementById('rp_pwd2');
                                if (!p1 || !p2) return;
                                if (p1.value.length < 8) { ev.preventDefault(); alert('Password must be at least 8 characters.'); return; }
                                if (p1.value !== p2.value) { ev.preventDefault(); alert('Passwords do not match.'); return; }
                            });
                        })();
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>
