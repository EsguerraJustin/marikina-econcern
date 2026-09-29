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

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_id'], 422);
}

$where = 'c.id = ?';
$types = 'i';
$params = [$id];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT c.id, c.report_number, c.created_at, c.status, c.street, c.barangay, c.landmark, c.description, c.photos_json,
            c.assigned_admin_id,
            a.name AS assignee_name, a.role AS assignee_role, ad.name AS assignee_department,
            CONCAT(u.first_name, " ", u.last_name) AS citizen_name,
            u.email AS citizen_email,
            ct.name AS concern_type, d.name AS department
     FROM concerns c
     JOIN users u ON u.id = c.user_id
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     LEFT JOIN admins a ON a.id = c.assigned_admin_id
     LEFT JOIN departments ad ON ad.id = a.department_id
     WHERE ' . $where . '
     LIMIT 1'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

/* Who the caller may hand this concern to.
   Super Admin: every active admin, so they can route freely.
   Department Admin: only themselves, so they can claim but not reassign.
   Returned from the detail endpoint rather than admin/api/admins.php because
   that one is Super-Admin-only and exposes email / mobile / last_login_at. */
$isSuper = ($admin['role'] ?? '') === 'super_admin';
$assignable = [];

$assignSql = 'SELECT a.id, a.name, a.role, a.department_id, d.name AS department_name
              FROM admins a
              LEFT JOIN departments d ON d.id = a.department_id
              WHERE a.active = 1';

if ($isSuper) {
    $astmt = $mysqli->prepare($assignSql . ' ORDER BY a.name ASC');
    $atypes = '';
    $aparams = [];
} else {
    $astmt = $mysqli->prepare($assignSql . ' AND a.id = ? LIMIT 1');
    $atypes = 'i';
    $aparams = [(int) $admin['id']];
}

if ($astmt instanceof mysqli_stmt) {
    /* Always execute, even with no bound params: get_result() on a
       prepared-but-unexecuted statement throws "Commands out of sync" under
       PHP 8.1+ mysqli, which surfaced as an empty 500 and the page's
       "Failed to load report details." */
    db_prepared_execute($astmt, $atypes, $aparams);
    $ares = $astmt->get_result();
    $assignable = $ares ? $ares->fetch_all(MYSQLI_ASSOC) : [];
    $astmt->close();
}

$photos = [];
if (!empty($row['photos_json'])) {
    $decoded = json_decode((string) $row['photos_json'], true);
    if (is_array($decoded)) {
        $photos = $decoded;
    }
}
$row['photos'] = $photos;
unset($row['photos_json']);

json_response([
    'ok' => true,
    'concern' => $row,
    'assignable' => $assignable,
    'can_assign_any' => $isSuper,
]);
