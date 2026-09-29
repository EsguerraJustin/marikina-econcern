<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/sms.php';

require_api_admin_login();
require_csrf_token();

$mysqli = db();
$admin = current_admin($mysqli);
if (!$admin) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}
if (($admin['role'] ?? '') !== 'super_admin') {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

function valid_role(string $role): bool
{
    return in_array($role, ['super_admin', 'department_admin'], true);
}

/**
 * Guards that stop a Super Admin from locking every Super Admin out of the system.
 *
 * Disabling accounts is a legitimate action, but "disable the only account that can
 * undo a disable" is unrecoverable through the UI: current_admin() returns null for
 * inactive admins, and this endpoint itself requires an active super admin. These
 * checks therefore have to live server-side — the UI toggle alone is not enough.
 */
function count_other_active_super_admins(mysqli $mysqli, int $excludeId): int
{
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM admins WHERE role = 'super_admin' AND active = 1 AND id <> ?");
    if (!$stmt) {
        return 1; // fail safe: assume another super admin exists rather than blocking a legit action
    }
    db_prepared_execute($stmt, 'i', [$excludeId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

function fetch_admin_role(mysqli $mysqli, int $id): string
{
    $stmt = $mysqli->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    db_prepared_execute($stmt, 'i', [$id]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? (string) ($row['role'] ?? '') : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'set_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['active'] ?? -1);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
        if (!in_array($active, [0, 1], true)) json_response(['ok' => false, 'error' => 'invalid_active'], 422);

        // Never allow an admin to disable their own account.
        if ($active === 0 && $id === (int) $admin['id']) {
            json_response(['ok' => false, 'error' => 'You cannot disable your own account.'], 422);
        }

        // Never allow the last active Super Admin to be disabled.
        if ($active === 0
            && fetch_admin_role($mysqli, $id) === 'super_admin'
            && count_other_active_super_admins($mysqli, $id) === 0) {
            json_response(['ok' => false, 'error' => 'This is the only active Super Admin. Promote another Super Admin before disabling it.'], 422);
        }

        $stmt = $mysqli->prepare('UPDATE admins SET active = ? WHERE id = ?');
        $ok = db_prepared_execute($stmt, 'ii', [$active, $id]);
        $stmt->close();
        if (!$ok) json_response(['ok' => false, 'error' => 'update_failed'], 500);
        json_response(['ok' => true]);
    }

    if ($action === 'set_password') {
        $id = (int) ($_POST['id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
        if ($password === '') json_response(['ok' => false, 'error' => 'Password is required.'], 422);
        if (strlen($password) < 8) json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 422);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
        $ok = db_prepared_execute($stmt, 'si', [$hash, $id]);
        $stmt->close();
        if (!$ok) json_response(['ok' => false, 'error' => 'update_failed'], 500);
        json_response(['ok' => true]);
    }

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $role = (string) ($_POST['role'] ?? '');
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $active = (int) ($_POST['active'] ?? 1);

        if ($name === '') json_response(['ok' => false, 'error' => 'Name is required.'], 422);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['ok' => false, 'error' => 'Valid email is required.'], 422);
        if ($password === '') json_response(['ok' => false, 'error' => 'Password is required.'], 422);
        if (strlen($password) < 8) json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 422);
        if (!valid_role($role)) json_response(['ok' => false, 'error' => 'Invalid role.'], 422);
        if (!in_array($active, [0, 1], true)) json_response(['ok' => false, 'error' => 'Invalid active value.'], 422);

        $mobileValue = null;
        if ($mobile !== '') {
            $e164 = normalize_ph_mobile($mobile);
            if ($e164 === false) {
                json_response(['ok' => false, 'error' => 'Invalid Philippine mobile number for SMS OTP. Use format: 09XXXXXXXXX or +639XXXXXXXXX'], 422);
            }
            $mobileValue = $e164;
        }

        $deptValue = null;
        if ($role === 'department_admin') {
            if ($departmentId <= 0) json_response(['ok' => false, 'error' => 'Department is required for Department Admin.'], 422);
            $deptValue = $departmentId;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare('INSERT INTO admins (name, email, mobile, password_hash, role, department_id, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $deptParam = $deptValue === null ? null : (int) $deptValue;
        $ok = db_prepared_execute($stmt, 'sssssii', [$name, $email, $mobileValue, $hash, $role, $deptParam, $active]);
        $stmt->close();

        if (!$ok) {
            if ((int) $mysqli->errno === 1062) {
                json_response(['ok' => false, 'error' => 'Email already exists.'], 409);
            }
            json_response(['ok' => false, 'error' => 'create_failed'], 500);
        }
        json_response(['ok' => true, 'id' => (int) $mysqli->insert_id]);
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $role = (string) ($_POST['role'] ?? '');
        $departmentId = (int) ($_POST['department_id'] ?? 0);

        if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
        if ($name === '') json_response(['ok' => false, 'error' => 'Name is required.'], 422);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['ok' => false, 'error' => 'Valid email is required.'], 422);
        if (!valid_role($role)) json_response(['ok' => false, 'error' => 'Invalid role.'], 422);

        // Demoting the last active Super Admin would lock everyone out just as
        // effectively as disabling it, so it needs the same guard.
        if ($role !== 'super_admin'
            && fetch_admin_role($mysqli, $id) === 'super_admin'
            && count_other_active_super_admins($mysqli, $id) === 0) {
            json_response(['ok' => false, 'error' => 'This is the only active Super Admin. Promote another Super Admin before changing its role.'], 422);
        }

        $mobileValue = null;
        if ($mobile !== '') {
            $e164 = normalize_ph_mobile($mobile);
            if ($e164 === false) {
                json_response(['ok' => false, 'error' => 'Invalid Philippine mobile number for SMS OTP. Use format: 09XXXXXXXXX or +639XXXXXXXXX'], 422);
            }
            $mobileValue = $e164;
        }

        $deptValue = null;
        if ($role === 'department_admin') {
            if ($departmentId <= 0) json_response(['ok' => false, 'error' => 'Department is required for Department Admin.'], 422);
            $deptValue = $departmentId;
        }

        $stmt = $mysqli->prepare('UPDATE admins SET name = ?, email = ?, mobile = ?, role = ?, department_id = ? WHERE id = ?');
        $deptParam = $deptValue === null ? null : (int) $deptValue;
        $ok = db_prepared_execute($stmt, 'ssssii', [$name, $email, $mobileValue, $role, $deptParam, $id]);
        $stmt->close();

        if (!$ok) {
            if ((int) $mysqli->errno === 1062) {
                json_response(['ok' => false, 'error' => 'Email already exists.'], 409);
            }
            json_response(['ok' => false, 'error' => 'update_failed'], 500);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'invalid_action'], 422);
}

$stmt = $mysqli->prepare('SELECT a.id, a.name, a.email, a.mobile, a.role, a.department_id, a.active, a.created_at, a.last_login_at, d.name AS department_name
                          FROM admins a
                          LEFT JOIN departments d ON d.id = a.department_id
                          ORDER BY a.id DESC');
$stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'admins' => $rows]);
