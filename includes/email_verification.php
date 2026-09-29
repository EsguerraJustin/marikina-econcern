<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function _ev_diag(string $event, array $extra = []): void
{
    $line = json_encode([
        'ts' => date('c'),
        'event' => 'email_verification_' . $event,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'php_cli',
    ] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        $line = date('c') . ' email_verification_' . $event;
    }
    @error_log($line . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

function invalidate_old_verifications(mysqli $db, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    $stmt = $db->prepare('UPDATE user_email_verifications SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL');
    if (!$stmt) {
        _ev_diag('invalidate_stmt_failed', ['error' => $db->error, 'user_id' => $userId]);
        return;
    }
    db_prepared_execute($stmt, 'i', [$userId]);
    $stmt->close();
}

function create_email_verification(mysqli $db, int $userId): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user_id for email verification');
    }

    $rawBytes = random_bytes(32);
    $rawToken = bin2hex($rawBytes);
    $tokenHash = hash('sha256', $rawToken);

    invalidate_old_verifications($db, $userId);

    $expiresAt = date('Y-m-d H:i:s', time() + 86400);
    $stmt = $db->prepare('INSERT INTO user_email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    if (!$stmt) {
        _ev_diag('create_stmt_failed', ['error' => $db->error, 'user_id' => $userId]);
        throw new RuntimeException('Failed to create email verification: ' . $db->error);
    }
    $ok = db_prepared_execute($stmt, 'iss', [$userId, $tokenHash, $expiresAt]);
    $stmt->close();

    if (!$ok) {
        throw new RuntimeException('Failed to insert email verification record');
    }

    _ev_diag('created', ['user_id' => $userId, 'expires_at' => $expiresAt]);
    return $rawToken;
}

function consume_email_verification(mysqli $db, string $rawToken): int|false
{
    if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        _ev_diag('consume_invalid_format', ['len' => strlen($rawToken)]);
        return false;
    }

    $tokenHash = hash('sha256', $rawToken);

    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT id, user_id, expires_at, consumed_at FROM user_email_verifications WHERE token_hash = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        db_prepared_execute($stmt, 's', [$tokenHash]);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            _ev_diag('consume_not_found', ['token_hash_prefix' => substr($tokenHash, 0, 12)]);
            $db->rollback();
            return false;
        }

        $verifId = (int) $row['id'];
        $userId = (int) $row['user_id'];
        $expiresAt = (string) $row['expires_at'];
        $consumedAt = $row['consumed_at'];

        if ($consumedAt !== null) {
            _ev_diag('consume_already_used', ['id' => $verifId, 'user_id' => $userId]);
            $db->rollback();
            return false;
        }

        if (strtotime($expiresAt) < time()) {
            _ev_diag('consume_expired', ['id' => $verifId, 'user_id' => $userId, 'expires_at' => $expiresAt]);
            $db->rollback();
            return false;
        }

        $stmt = $db->prepare('UPDATE user_email_verifications SET consumed_at = NOW() WHERE id = ?');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'i', [$verifId]);
        $stmt->close();

        $stmt = $db->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'i', [$userId]);
        $affected = $stmt->affected_rows;
        $stmt->close();

        $db->commit();

        _ev_diag('consumed_ok', [
            'verif_id' => $verifId,
            'user_id' => $userId,
            'email_verified_at_set' => $affected > 0 ? 1 : 0,
        ]);

        return $userId;
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        _ev_diag('consume_transaction_failed', ['error' => $e->getMessage()]);
        return false;
    }
}

function is_user_email_verified(mysqli $db, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT email_verified_at FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        _ev_diag('check_stmt_failed', ['error' => $db->error]);
        return false;
    }
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return false;
    }
    return $row['email_verified_at'] !== null;
}

function last_verification_created_at(mysqli $db, int $userId): ?int
{
    if ($userId <= 0) return null;
    $stmt = $db->prepare('SELECT UNIX_TIMESTAMP(created_at) AS ts FROM user_email_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row) || !isset($row['ts'])) return null;
    return (int) $row['ts'];
}

function mask_email(string $email): string
{
    $atPos = strpos($email, '@');
    if ($atPos === false) {
        return '***';
    }
    $local = substr($email, 0, $atPos);
    $domain = substr($email, $atPos + 1);
    $localLen = strlen($local);
    if ($localLen <= 2) {
        $maskedLocal = $local[0] . str_repeat('*', max(1, $localLen - 1));
    } else {
        $showFirst = max(1, min(3, (int) floor($localLen / 4)));
        $showLast = max(1, min(2, (int) floor($localLen / 5)));
        $stars = str_repeat('*', max(2, $localLen - $showFirst - $showLast));
        $maskedLocal = substr($local, 0, $showFirst) . $stars . substr($local, -$showLast);
    }
    return $maskedLocal . '@' . $domain;
}

function build_verification_email_content(string $verifyUrl, string $userFirstName): array
{
    $escapedUrl = htmlspecialchars($verifyUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $firstName = htmlspecialchars($userFirstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $year = (int) date('Y');

    $html = <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Verify your email</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;background:#f8f9fa;">
  <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:28px 32px;border:1px solid #e9ecef;">
    <div style="font-weight:700;font-size:20px;color:#0d6efd;margin-bottom:16px;">Marikina E-Concern</div>
    <p style="font-size:15px;color:#212529;line-height:1.5;">Hello, <strong>{$firstName}</strong>!</p>
    <p style="font-size:15px;color:#212529;line-height:1.5;">Thank you for creating an account. Please verify your email address by clicking the button below. This link will expire in 24 hours.</p>
    <p style="margin:24px 0;text-align:center;">
      <a href="{$escapedUrl}" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">Verify Email Address</a>
    </p>
    <p style="font-size:13px;color:#6c757d;word-break:break-all;">If the button does not work, copy and paste this link into your browser:<br>{$escapedUrl}</p>
    <p style="font-size:13px;color:#6c757d;">If you did not create this account, please ignore this email.</p>
    <hr style="border:none;border-top:1px solid #e9ecef;margin:24px 0;">
    <p style="font-size:12px;color:#6c757d;margin:0;">© {$year} Marikina E-Concern. City Government of Marikina.</p>
  </div>
</body>
</html>
HTML;

    $text = <<<TEXT
Marikina E-Concern

Hello, {$userFirstName}!

Thank you for creating an account. Please verify your email address by opening the link below. This link will expire in 24 hours.

{$verifyUrl}

If you did not create this account, please ignore this email.

© {$year} Marikina E-Concern
TEXT;

    return ['subject' => 'Verify your email address for Marikina E-Concern', 'html' => $html, 'text' => $text];
}

function _pwr_diag(string $event, array $extra = []): void
{
    $line = json_encode([
        'ts' => date('c'),
        'event' => 'password_reset_' . $event,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'php_cli',
    ] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        $line = date('c') . ' password_reset_' . $event;
    }
    @error_log($line . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

function invalidate_active_password_resets(mysqli $db, int $userId): void
{
    if ($userId <= 0) return;
    $stmt = $db->prepare('UPDATE user_password_resets SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL');
    if (!$stmt) {
        _pwr_diag('invalidate_stmt_failed', ['error' => $db->error, 'user_id' => $userId]);
        return;
    }
    db_prepared_execute($stmt, 'i', [$userId]);
    $stmt->close();
}

function last_password_reset_created_at(mysqli $db, int $userId): ?int
{
    if ($userId <= 0) return null;
    $stmt = $db->prepare('SELECT UNIX_TIMESTAMP(created_at) AS ts FROM user_password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row) || !isset($row['ts'])) return null;
    return (int) $row['ts'];
}

function create_password_reset(mysqli $db, int $userId, string $requestedIp = ''): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user_id for password reset');
    }
    $rawBytes = random_bytes(32);
    $rawToken = bin2hex($rawBytes);
    $tokenHash = hash('sha256', $rawToken);

    invalidate_active_password_resets($db, $userId);

    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $stmt = $db->prepare('INSERT INTO user_password_resets (user_id, token_hash, expires_at, requested_ip) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        _pwr_diag('create_stmt_failed', ['error' => $db->error, 'user_id' => $userId]);
        throw new RuntimeException('Failed to create password reset: ' . $db->error);
    }
    $ok = db_prepared_execute($stmt, 'isss', [$userId, $tokenHash, $expiresAt, $requestedIp]);
    $stmt->close();
    if (!$ok) throw new RuntimeException('Failed to insert password reset record');

    _pwr_diag('created', ['user_id' => $userId, 'expires_at' => $expiresAt]);
    return $rawToken;
}

function consume_password_reset(mysqli $db, string $rawToken, string $newPassword, string $consumedIp = ''): array
{
    if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        _pwr_diag('consume_invalid_format', ['len' => strlen($rawToken)]);
        return ['ok' => false, 'error' => 'Invalid or expired password reset link.'];
    }
    if ($newPassword === '' || strlen($newPassword) < 8) {
        return ['ok' => false, 'error' => 'New password must be at least 8 characters.'];
    }
    $tokenHash = hash('sha256', $rawToken);

    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT id, user_id, expires_at, consumed_at FROM user_password_resets WHERE token_hash = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 's', [$tokenHash]);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            _pwr_diag('consume_not_found', ['token_hash_prefix' => substr($tokenHash, 0, 12)]);
            $db->rollback();
            return ['ok' => false, 'error' => 'Invalid or expired password reset link.'];
        }
        $resetId = (int) $row['id'];
        $userId = (int) $row['user_id'];
        $expiresAt = (string) $row['expires_at'];
        $consumedAt = $row['consumed_at'];

        if ($consumedAt !== null) {
            _pwr_diag('consume_already_used', ['id' => $resetId, 'user_id' => $userId]);
            $db->rollback();
            return ['ok' => false, 'error' => 'This password reset link has already been used.'];
        }
        if (strtotime($expiresAt) < time()) {
            _pwr_diag('consume_expired', ['id' => $resetId, 'user_id' => $userId, 'expires_at' => $expiresAt]);
            $db->rollback();
            return ['ok' => false, 'error' => 'This password reset link has expired.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newHash) || $newHash === '') {
            $db->rollback();
            _pwr_diag('hash_failed', ['user_id' => $userId, 'algo' => PASSWORD_DEFAULT]);
            return ['ok' => false, 'error' => 'Server failed to secure the new password.'];
        }

        $stmt = $db->prepare('UPDATE user_password_resets SET consumed_at = NOW(), consumed_ip = ? WHERE id = ?');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'si', [$consumedIp, $resetId]);
        $stmt->close();

        $stmt = $db->prepare('UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'si', [$newHash, $userId]);
        $stmt->close();

        $db->commit();
        _pwr_diag('consumed_ok', ['reset_id' => $resetId, 'user_id' => $userId]);

        @error_log(json_encode([
            'ts' => date('c'), 'event' => 'change_password_success',
            'remote_ip' => $consumedIp ?: 'php_cli', 'user_id' => $userId, 'algo' => PASSWORD_DEFAULT,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, 3, __DIR__ . '/../app_error.log');

        return ['ok' => true, 'user_id' => $userId];
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        _pwr_diag('consume_transaction_failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Server error while applying new password. Please try again later.'];
    }
}

function build_password_reset_email_content(string $resetUrl, string $userFirstName, int $ttlMinutes = 60): array
{
    $escapedUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $firstName = htmlspecialchars($userFirstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $year = (int) date('Y');
    $ttl = (int) $ttlMinutes;

    $html = <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Reset your password</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;background:#f8f9fa;">
  <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:28px 32px;border:1px solid #e9ecef;">
    <div style="font-weight:700;font-size:20px;color:#0d6efd;margin-bottom:16px;">Marikina E-Concern</div>
    <p style="font-size:15px;color:#212529;line-height:1.5;">Hello, <strong>{$firstName}</strong>!</p>
    <p style="font-size:15px;color:#212529;line-height:1.5;">We received a request to reset the password for your account. Click the button below to choose a new password. This link will expire in {$ttl} minutes.</p>
    <p style="margin:24px 0;text-align:center;">
      <a href="{$escapedUrl}" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">Reset Password</a>
    </p>
    <p style="font-size:13px;color:#6c757d;word-break:break-all;">If the button does not work, copy and paste this link into your browser:<br>{$escapedUrl}</p>
    <p style="font-size:13px;color:#6c757d;">If you did not request this password reset, please ignore this email — your password will not be changed.</p>
    <hr style="border:none;border-top:1px solid #e9ecef;margin:24px 0;">
    <p style="font-size:12px;color:#6c757d;margin:0;">© {$year} Marikina E-Concern. City Government of Marikina.</p>
  </div>
</body>
</html>
HTML;

    $text = <<<TEXT
Marikina E-Concern

Hello, {$userFirstName}!

We received a request to reset the password for your account. Open the link below to choose a new password. This link will expire in {$ttl} minutes.

{$resetUrl}

If you did not request this password reset, please ignore this email — your password will not be changed.

© {$year} Marikina E-Concern
TEXT;

    return ['subject' => 'Reset your password for Marikina E-Concern', 'html' => $html, 'text' => $text];
}
