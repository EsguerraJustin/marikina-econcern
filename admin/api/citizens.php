<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_auth.php';

require_api_admin_login();
require_csrf_token();

$mysqli = db();
$admin = current_admin($mysqli);
if (!$admin) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}
require_super_admin($admin);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'set_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['active'] ?? -1);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
        if (!in_array($active, [0, 1], true)) json_response(['ok' => false, 'error' => 'invalid_active'], 422);

        $stmt = $mysqli->prepare('UPDATE users SET active = ? WHERE id = ?');
        $ok = db_prepared_execute($stmt, 'ii', [$active, $id]);
        $stmt->close();
        if (!$ok) json_response(['ok' => false, 'error' => 'update_failed'], 500);
        json_response(['ok' => true]);
    }

    /* Archive = soft delete.
       A hard DELETE FROM users cascades through seven foreign keys and would
       destroy the complaint record itself: concerns -> concern_timeline,
       concern_messages and concern_notes, plus ba_reports, ba_notifications,
       ba_notification_preferences, user_email_verifications and
       user_login_otps. So the row is kept and only flagged.

       active = 0 is set alongside deleted_at so the account is locked out
       immediately by the access gates that already read it (auth.php:129
       current_user, public/login.php, public/forgot_password.php,
       public/resend_verification.php, ba_run_reminders.php) — no change to any
       of those files is needed, and there is no window where an archived
       citizen can still sign in. */
    if ($action === 'archive') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);

        $stmt = $mysqli->prepare('SELECT id, deleted_at FROM users WHERE id = ? LIMIT 1');
        db_prepared_execute($stmt, 'i', [$id]);
        $res = $stmt->get_result();
        $target = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$target) {
            json_response(['ok' => false, 'error' => 'not_found'], 404);
        }
        if ($target['deleted_at'] !== null) {
            json_response(['ok' => false, 'error' => 'already_archived'], 409);
        }

        /* Reported back so the UI can say what was kept on record. */
        $stmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM concerns WHERE user_id = ?');
        db_prepared_execute($stmt, 'i', [$id]);
        $res = $stmt->get_result();
        $keptRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $keptConcerns = (int) ($keptRow['c'] ?? 0);

        $stmt = $mysqli->prepare('UPDATE users SET deleted_at = NOW(), active = 0 WHERE id = ? AND deleted_at IS NULL');
        $ok = db_prepared_execute($stmt, 'i', [$id]);
        $stmt->close();
        if (!$ok) json_response(['ok' => false, 'error' => 'update_failed'], 500);

        json_response(['ok' => true, 'kept_concerns' => $keptConcerns]);
    }

/* Restore = undo an archive.
   deleted_at is cleared but active is deliberately left alone. Archiving forces
   active = 0 so the account is locked out, and restoring does not hand that
   back: the account and all its reports reappear, but the admin has to flip the
   Active switch to let the citizen sign in again. That keeps a spam or fraud
   account from getting working login credentials back with one click. */
    if ($action === 'restore') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);

        $stmt = $mysqli->prepare('SELECT id, deleted_at, active FROM users WHERE id = ? LIMIT 1');
        db_prepared_execute($stmt, 'i', [$id]);
        $res = $stmt->get_result();
        $target = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$target) {
            json_response(['ok' => false, 'error' => 'not_found'], 404);
        }
        if ($target['deleted_at'] === null) {
            json_response(['ok' => false, 'error' => 'not_archived'], 409);
        }

        $stmt = $mysqli->prepare('UPDATE users SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL');
        $ok = db_prepared_execute($stmt, 'i', [$id]);
        $stmt->close();
        if (!$ok) json_response(['ok' => false, 'error' => 'update_failed'], 500);

        json_response(['ok' => true, 'active' => (int) $target['active']]);
    }

    json_response(['ok' => false, 'error' => 'invalid_action'], 422);
}

$q = trim((string) ($_GET['q'] ?? ''));
$includeInactive = (string) ($_GET['include_inactive'] ?? '') === '1';
$show = (string) ($_GET['show'] ?? 'active');
if (!in_array($show, ['active', 'archived', 'all'], true)) {
    $show = 'active';
}
$limit = (int) ($_GET['limit'] ?? 200);
if ($limit <= 0 || $limit > 500) {
    $limit = 200;
}

$where = [];
$types = '';
$params = [];

/* An archived citizen is a different thing from a disabled one: the row and all
   of its reports still exist, the account simply cannot sign in. They are
   filtered by their own "Show" mode rather than by the "Include Inactive"
   toggle, which is about being able to sign in. */
if ($show === 'archived') {
    $where[] = 'u.deleted_at IS NOT NULL';
} elseif ($show === 'all') {
    if (!$includeInactive) {
        $where[] = 'u.active = 1';
    }
} else {
    $where[] = 'u.deleted_at IS NULL';
    if (!$includeInactive) {
        $where[] = 'u.active = 1';
    }
}

if ($q !== '') {
    $where[] = '(u.email LIKE ? OR u.mobile LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
    $types .= 'sssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

// avatar_public_id is carried so the list can render a real photo where one
// exists instead of always falling back to initials. barangay and
// email_verified_at are carried because the inline Edit modal prefills from
// this same response, and it should not need a second round trip per row.
$sql = 'SELECT u.id, u.first_name, u.last_name, u.mobile, u.email, u.active, u.deleted_at, u.created_at,
               u.avatar_public_id, u.barangay, u.email_verified_at
        FROM users u
        WHERE ' . $whereSql . '
        ORDER BY u.id DESC
        LIMIT ' . (int) $limit;

$stmt = $mysqli->prepare($sql);
$types !== '' ? db_prepared_execute($stmt, $types, $params) : $stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

json_response(['ok' => true, 'citizens' => $rows]);
