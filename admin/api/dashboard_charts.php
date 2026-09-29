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

$allowedStatuses = ['New', 'Ongoing', 'Acknowledge', 'Completed', 'Cancelled'];

$where = 'c.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND c.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
$types = '';
$params = [];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

$stmt = $mysqli->prepare(
    'SELECT DATE(c.created_at) AS day, c.status, COUNT(*) AS cnt
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     GROUP BY day, c.status
     ORDER BY day ASC'
);
$types !== '' ? db_prepared_execute($stmt, $types, $params) : $stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$labels = [];
$series = [];
foreach ($allowedStatuses as $s) {
    $series[$s] = [];
}

$start = new DateTimeImmutable('today');
$start = $start->sub(new DateInterval('P29D'));
$map = [];
foreach ($rows as $r) {
    $day = (string) ($r['day'] ?? '');
    $status = (string) ($r['status'] ?? '');
    if ($day === '' || !isset($series[$status])) {
        continue;
    }
    if (!isset($map[$day])) {
        $map[$day] = [];
    }
    $map[$day][$status] = (int) ($r['cnt'] ?? 0);
}

for ($i = 0; $i < 30; $i++) {
    $d = $start->add(new DateInterval('P' . $i . 'D'));
    $key = $d->format('Y-m-d');
    $labels[] = $key;
    foreach ($allowedStatuses as $s) {
        $series[$s][] = (int) ($map[$key][$s] ?? 0);
    }
}

$stmt = $mysqli->prepare(
    'SELECT d.id, d.name, COUNT(*) AS cnt
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     GROUP BY d.id, d.name
     ORDER BY cnt DESC, d.name ASC'
);
$types !== '' ? db_prepared_execute($stmt, $types, $params) : $stmt->execute();
$res = $stmt->get_result();
$deptRows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$deptLabels = [];
$deptCounts = [];
foreach ($deptRows as $r) {
    $deptLabels[] = (string) ($r['name'] ?? '');
    $deptCounts[] = (int) ($r['cnt'] ?? 0);
}

json_response([
    'ok' => true,
    'range' => [
        'start' => $labels[0] ?? null,
        'end' => $labels[29] ?? null,
    ],
    'daily_status' => [
        'labels' => $labels,
        'series' => $series,
    ],
    'department_totals' => [
        'labels' => $deptLabels,
        'counts' => $deptCounts,
    ],
]);
