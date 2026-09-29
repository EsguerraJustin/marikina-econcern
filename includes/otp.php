<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function _otp_diag(string $event, array $extra = []): void
{
    $line = json_encode([
        'ts' => date('c'),
        'event' => 'otp_' . $event,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'php_cli',
    ] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        $line = date('c') . ' otp_' . $event;
    }
    @error_log($line . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

function generate_otp(int $length = 6): string
{
    if ($length < 4 || $length > 12) {
        $length = 6;
    }
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= (string) random_int(0, 9);
    }
    return $out;
}

function invalidate_active_otps(mysqli $db, int $userId): void
{
    if ($userId <= 0) return;
    $stmt = $db->prepare('UPDATE user_login_otps SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL');
    if (!$stmt) {
        _otp_diag('invalidate_stmt_failed', ['error' => $db->error]);
        return;
    }
    db_prepared_execute($stmt, 'i', [$userId]);
    $stmt->close();
}

function create_login_otp(mysqli $db, int $userId, string $mobileE164): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user_id');
    }
    if (!preg_match('/^\+639\d{9}$/', $mobileE164)) {
        throw new InvalidArgumentException('Invalid PH mobile number (E.164 +639xxxxxxxxx required)');
    }

    $otpPlain = generate_otp(6);
    $otpHash = hash('sha256', $otpPlain);

    $db->begin_transaction();
    try {
        invalidate_active_otps($db, $userId);

        $expiresAtUnix = time() + OTP_LIFETIME_SECONDS;
        $maxAttempts = 5;
        $masked = mask_mobile($mobileE164);

        $stmt = $db->prepare('INSERT INTO user_login_otps (user_id, otp_hash, channel, destination_masked, expires_at, max_attempts) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?)');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'issisi', [
            $userId, $otpHash, 'sms', $masked, $expiresAtUnix, $maxAttempts,
        ]);
        $otpId = (int) $db->insert_id;
        $stmt->close();

        $db->commit();

        $expiresAtStr = date('Y-m-d H:i:s', $expiresAtUnix);
        _otp_diag('created', [
            'otp_id' => $otpId,
            'user_id' => $userId,
            'dest_masked' => $masked,
            'expires_at' => $expiresAtStr,
            'expires_at_unix' => $expiresAtUnix,
            'max_attempts' => $maxAttempts,
        ]);

        return ['id' => $otpId, 'otp_plain' => $otpPlain, 'expires_at' => $expiresAtStr, 'masked_destination' => $masked];
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        _otp_diag('create_failed', ['error' => $e->getMessage(), 'user_id' => $userId]);
        throw new RuntimeException('Failed to create login OTP: ' . $e->getMessage());
    }
}

function verify_login_otp(mysqli $db, int $otpId, string $otpInput): array
{
    if ($otpId <= 0) {
        return ['ok' => false, 'error' => 'Invalid OTP session'];
    }
    if (!preg_match('/^\d{6}$/', $otpInput)) {
        return ['ok' => false, 'error' => 'OTP must be 6 digits'];
    }

    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT id, user_id, otp_hash, UNIX_TIMESTAMP(expires_at) AS expires_at_ts, attempt_count, max_attempts, consumed_at FROM user_login_otps WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'i', [$otpId]);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            $db->rollback();
            _otp_diag('verify_not_found', ['otp_id' => $otpId]);
            return ['ok' => false, 'error' => 'OTP record not found. Please request a new code.'];
        }

        $userId = (int) $row['user_id'];
        $expectedHash = (string) $row['otp_hash'];
        $expiresAtTs = (int) ($row['expires_at_ts'] ?? 0);
        $attemptCount = (int) $row['attempt_count'];
        $maxAttempts = (int) $row['max_attempts'];
        $consumedAt = $row['consumed_at'];

        if ($consumedAt !== null) {
            $db->rollback();
            _otp_diag('verify_already_consumed', ['otp_id' => $otpId, 'user_id' => $userId]);
            return ['ok' => false, 'error' => 'This OTP has already been used. Please request a new code.'];
        }

        if ($expiresAtTs <= 0 || $expiresAtTs < time()) {
            $db->rollback();
            _otp_diag('verify_expired', ['otp_id' => $otpId, 'user_id' => $userId]);
            return ['ok' => false, 'error' => 'This OTP has expired. Please request a new code.'];
        }

        if ($attemptCount >= $maxAttempts) {
            $db->rollback();
            _otp_diag('verify_max_attempts', ['otp_id' => $otpId, 'user_id' => $userId, 'attempts' => $attemptCount]);
            return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
        }

        $inputHash = hash('sha256', $otpInput);
        $hashMatch = hash_equals($expectedHash, $inputHash);

        if (!$hashMatch) {
            $newCount = $attemptCount + 1;
            $stmt = $db->prepare('UPDATE user_login_otps SET attempt_count = ?, expires_at = expires_at WHERE id = ?');
            if (!$stmt) throw new RuntimeException($db->error);
            db_prepared_execute($stmt, 'ii', [$newCount, $otpId]);
            $stmt->close();
            $db->commit();

            $remaining = max(0, $maxAttempts - $newCount);
            _otp_diag('verify_hash_mismatch', [
                'otp_id' => $otpId,
                'user_id' => $userId,
                'attempt' => $newCount,
                'max_attempts' => $maxAttempts,
                'remaining' => $remaining,
            ]);

            $msg = 'Incorrect OTP code.';
            if ($remaining > 0) {
                $msg .= ' ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.';
            } else {
                $msg .= ' Too many attempts. Please request a new code.';
            }
            return ['ok' => false, 'error' => $msg];
        }

        $stmt = $db->prepare('UPDATE user_login_otps SET consumed_at = NOW(), attempt_count = ?, expires_at = expires_at WHERE id = ?');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'ii', [$attemptCount + 1, $otpId]);
        $stmt->close();

        $stmt = $db->prepare('UPDATE users SET last_login_at = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'i', [$userId]);
        $stmt->close();

        $db->commit();

        _otp_diag('verify_ok', ['otp_id' => $otpId, 'user_id' => $userId, 'attempts' => $attemptCount + 1]);
        return ['ok' => true, 'user_id' => $userId];
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        _otp_diag('verify_transaction_failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Server error while verifying OTP: ' . $e->getMessage()];
    }
}

function otp_can_resend(int $createdAt, int $cooldownSeconds = 120): bool
{
    if ($createdAt <= 0) return true;
    return (time() - $createdAt) >= $cooldownSeconds;
}

define('OTP_RESEND_COOLDOWN_SECONDS', 120);
define('OTP_LIFETIME_SECONDS', 300);

function get_active_otp(mysqli $db, int $userId, bool $includeExpired = false): ?array
{
    if ($userId <= 0) return null;
    $stmt = $db->prepare('SELECT id, destination_masked, expires_at, attempt_count, max_attempts, UNIX_TIMESTAMP(created_at) AS created_at_ts, UNIX_TIMESTAMP(expires_at) AS expires_at_ts FROM user_login_otps WHERE user_id = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row)) return null;
    $createdTs = (int)($row['created_at_ts'] ?? 0);
    $expiresTs = (int)($row['expires_at_ts'] ?? 0);
    if ($createdTs <= 0) $createdTs = (int)strtotime((string)$row['created_at'] ?? 'now');
    if ($expiresTs <= 0) $expiresTs = (int)strtotime((string)$row['expires_at'] ?? 'now');
    $row['created_at_ts'] = $createdTs;
    $row['expires_at_ts'] = $expiresTs;
    if (!$includeExpired && $expiresTs < time()) return null;
    return $row;
}

/* =========================================================
 * ADMIN OTP HELPERS (SMS 2FA for admin/backend portal)
 * Mirrors user OTP logic, operates on table `admin_login_otps`
 * and updates `admins.last_login_at` on verify success.
 * ========================================================= */

function admin_invalidate_active_otps(mysqli $db, int $adminId): void
{
    if ($adminId <= 0) return;
    $stmt = $db->prepare('UPDATE admin_login_otps SET consumed_at = NOW() WHERE admin_id = ? AND consumed_at IS NULL');
    if (!$stmt) {
        _otp_diag('admin_invalidate_stmt_failed', ['error' => $db->error, 'admin_id' => $adminId]);
        return;
    }
    db_prepared_execute($stmt, 'i', [$adminId]);
    $stmt->close();
}

function admin_create_login_otp(mysqli $db, int $adminId, string $mobileE164): array
{
    if ($adminId <= 0) throw new InvalidArgumentException('Invalid admin_id');
    if (!preg_match('/^\+639\d{9}$/', $mobileE164)) throw new InvalidArgumentException('Invalid PH mobile number (E.164 +639xxxxxxxxx required)');

    $otpPlain = generate_otp(6);
    $otpHash = hash('sha256', $otpPlain);

    $db->begin_transaction();
    try {
        admin_invalidate_active_otps($db, $adminId);
        $expiresAtUnix = time() + OTP_LIFETIME_SECONDS;
        $maxAttempts = 5;
        $masked = mask_mobile($mobileE164);
        $stmt = $db->prepare('INSERT INTO admin_login_otps (admin_id, otp_hash, channel, destination_masked, expires_at, max_attempts) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?)');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'issisi', [$adminId, $otpHash, 'sms', $masked, $expiresAtUnix, $maxAttempts]);
        $otpId = (int) $db->insert_id;
        $stmt->close();
        $db->commit();
        $expiresAtStr = date('Y-m-d H:i:s', $expiresAtUnix);
        _otp_diag('admin_created', [
            'otp_id' => $otpId,
            'admin_id' => $adminId,
            'dest_masked' => $masked,
            'expires_at' => $expiresAtStr,
            'expires_at_unix' => $expiresAtUnix,
            'max_attempts' => $maxAttempts,
        ]);
        return ['id' => $otpId, 'otp_plain' => $otpPlain, 'expires_at' => $expiresAtStr, 'masked_destination' => $masked];
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        _otp_diag('admin_create_failed', ['error' => $e->getMessage(), 'admin_id' => $adminId]);
        throw new RuntimeException('Failed to create admin login OTP: ' . $e->getMessage());
    }
}

function admin_verify_login_otp(mysqli $db, int $otpId, string $otpInput): array
{
    if ($otpId <= 0) return ['ok' => false, 'error' => 'Invalid OTP session'];
    if (!preg_match('/^\d{6}$/', $otpInput)) return ['ok' => false, 'error' => 'OTP must be 6 digits'];

    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT id, admin_id, otp_hash, UNIX_TIMESTAMP(expires_at) AS expires_at_ts, attempt_count, max_attempts, consumed_at FROM admin_login_otps WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'i', [$otpId]);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            $db->rollback();
            _otp_diag('admin_verify_not_found', ['otp_id' => $otpId]);
            return ['ok' => false, 'error' => 'OTP record not found. Please request a new code.'];
        }
        $adminId = (int) $row['admin_id'];
        $expectedHash = (string) $row['otp_hash'];
        $expiresAtTs = (int) ($row['expires_at_ts'] ?? 0);
        $attemptCount = (int) $row['attempt_count'];
        $maxAttempts = (int) $row['max_attempts'];
        $consumedAt = $row['consumed_at'];

        if ($consumedAt !== null) {
            $db->rollback();
            _otp_diag('admin_verify_already_consumed', ['otp_id' => $otpId, 'admin_id' => $adminId]);
            return ['ok' => false, 'error' => 'This OTP has already been used. Please request a new code.'];
        }
        if ($expiresAtTs <= 0 || $expiresAtTs < time()) {
            $db->rollback();
            _otp_diag('admin_verify_expired', ['otp_id' => $otpId, 'admin_id' => $adminId]);
            return ['ok' => false, 'error' => 'This OTP has expired. Please request a new code.'];
        }
        if ($attemptCount >= $maxAttempts) {
            $db->rollback();
            _otp_diag('admin_verify_max_attempts', ['otp_id' => $otpId, 'admin_id' => $adminId, 'attempts' => $attemptCount]);
            return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
        }

        $inputHash = hash('sha256', $otpInput);
        $hashMatch = hash_equals($expectedHash, $inputHash);

        if (!$hashMatch) {
            $newCount = $attemptCount + 1;
            $stmt = $db->prepare('UPDATE admin_login_otps SET attempt_count = ?, expires_at = expires_at WHERE id = ?');
            if (!$stmt) throw new RuntimeException($db->error);
            db_prepared_execute($stmt, 'ii', [$newCount, $otpId]);
            $stmt->close();
            $db->commit();
            $remaining = max(0, $maxAttempts - $newCount);
            _otp_diag('admin_verify_hash_mismatch', ['otp_id' => $otpId, 'admin_id' => $adminId, 'attempt' => $newCount, 'max_attempts' => $maxAttempts, 'remaining' => $remaining]);
            $msg = 'Incorrect OTP code.';
            if ($remaining > 0) $msg .= ' ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.';
            else $msg .= ' Too many attempts. Please request a new code.';
            return ['ok' => false, 'error' => $msg];
        }

        $stmt = $db->prepare('UPDATE admin_login_otps SET consumed_at = NOW(), attempt_count = ?, expires_at = expires_at WHERE id = ?');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'ii', [$attemptCount + 1, $otpId]);
        $stmt->close();

        $stmt = $db->prepare('UPDATE admins SET last_login_at = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
        if (!$stmt) throw new RuntimeException($db->error);
        db_prepared_execute($stmt, 'i', [$adminId]);
        $stmt->close();

        $db->commit();
        _otp_diag('admin_verify_ok', ['otp_id' => $otpId, 'admin_id' => $adminId, 'attempts' => $attemptCount + 1]);
        return ['ok' => true, 'admin_id' => $adminId];
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        _otp_diag('admin_verify_transaction_failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Server error while verifying OTP: ' . $e->getMessage()];
    }
}

function admin_get_active_otp(mysqli $db, int $adminId, bool $includeExpired = false): ?array
{
    if ($adminId <= 0) return null;
    $stmt = $db->prepare('SELECT id, destination_masked, expires_at, attempt_count, max_attempts, UNIX_TIMESTAMP(created_at) AS created_at_ts, UNIX_TIMESTAMP(expires_at) AS expires_at_ts FROM admin_login_otps WHERE admin_id = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$adminId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row)) return null;
    $createdTs = (int)($row['created_at_ts'] ?? 0);
    $expiresTs = (int)($row['expires_at_ts'] ?? 0);
    if ($createdTs <= 0) $createdTs = (int)strtotime((string)$row['created_at'] ?? 'now');
    if ($expiresTs <= 0) $expiresTs = (int)strtotime((string)$row['expires_at'] ?? 'now');
    $row['created_at_ts'] = $createdTs;
    $row['expires_at_ts'] = $expiresTs;
    if (!$includeExpired && $expiresTs < time()) return null;
    return $row;
}
