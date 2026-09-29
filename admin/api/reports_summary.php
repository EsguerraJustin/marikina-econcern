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
if (($admin['role'] ?? '') !== 'super_admin') {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

function parse_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return null;
    }
    if ($dt->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

$start = parse_date((string) ($_GET['start'] ?? ''));
$end = parse_date((string) ($_GET['end'] ?? ''));

if (!$start || !$end) {
    $endDt = new DateTimeImmutable('today');
    $startDt = $endDt->sub(new DateInterval('P29D'));
    $start = $startDt->format('Y-m-d');
    $end = $endDt->format('Y-m-d');
}

if ($end < $start) {
    json_response(['ok' => false, 'error' => 'Invalid date range.'], 422);
}

$departmentId = (int) ($_GET['department_id'] ?? 0);

$where = 'c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
$types = 'ss';
$params = [$start, $end];

if ($departmentId > 0) {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = $departmentId;
}

$stmt = $mysqli->prepare(
    'SELECT c.status, COUNT(*) AS cnt
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     GROUP BY c.status'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$totals = [
    'New' => 0,
    'Ongoing' => 0,
    'Acknowledge' => 0,
    'Completed' => 0,
    'Cancelled' => 0,
];
foreach ($rows as $r) {
    $s = (string) ($r['status'] ?? '');
    if (isset($totals[$s])) {
        $totals[$s] = (int) ($r['cnt'] ?? 0);
    }
}

$stmt = $mysqli->prepare(
    'SELECT DATE(c.created_at) AS day, COUNT(*) AS cnt
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     GROUP BY day
     ORDER BY day ASC'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$dailyRows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$dailyMap = [];
foreach ($dailyRows as $r) {
    $day = (string) ($r['day'] ?? '');
    if ($day !== '') {
        $dailyMap[$day] = (int) ($r['cnt'] ?? 0);
    }
}

$labels = [];
$counts = [];
$cursor = new DateTimeImmutable($start);
$endDt = new DateTimeImmutable($end);
while ($cursor <= $endDt) {
    $k = $cursor->format('Y-m-d');
    $labels[] = $k;
    $counts[] = (int) ($dailyMap[$k] ?? 0);
    $cursor = $cursor->add(new DateInterval('P1D'));
}

$resolvedWhere = 't.completed_at >= ? AND t.completed_at < DATE_ADD(?, INTERVAL 1 DAY)';
$resolvedTypes = 'ss';
$resolvedParams = [$start, $end];
if ($departmentId > 0) {
    $resolvedWhere .= ' AND d.id = ?';
    $resolvedTypes .= 'i';
    $resolvedParams[] = $departmentId;
}

$stmt = $mysqli->prepare(
    'SELECT COUNT(*) AS resolved_count, AVG(TIMESTAMPDIFF(SECOND, c.created_at, t.completed_at)) AS avg_seconds
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     JOIN (
        SELECT concern_id, MIN(created_at) AS completed_at
        FROM concern_timeline
        WHERE status = "Completed"
        GROUP BY concern_id
     ) t ON t.concern_id = c.id
     WHERE ' . $resolvedWhere
);
db_prepared_execute($stmt, $resolvedTypes, $resolvedParams);
$res = $stmt->get_result();
$avgRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$resolvedCount = (int) ($avgRow['resolved_count'] ?? 0);
$avgSeconds = $avgRow && $avgRow['avg_seconds'] !== null ? (float) $avgRow['avg_seconds'] : null;
$avgHours = $avgSeconds !== null ? round($avgSeconds / 3600, 2) : null;

$stmt = $mysqli->prepare(
    'SELECT d.name AS department, c.status, COUNT(*) AS cnt
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     GROUP BY d.id, c.status
     ORDER BY d.name ASC'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$deptRows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$deptMap = [];
foreach ($deptRows as $r) {
    $dept = (string) ($r['department'] ?? '');
    $status = (string) ($r['status'] ?? '');
    $cnt = (int) ($r['cnt'] ?? 0);
    if ($dept === '' || !isset($totals[$status])) {
        continue;
    }
    if (!isset($deptMap[$dept])) {
        $deptMap[$dept] = [
            'New' => 0,
            'Ongoing' => 0,
            'Acknowledge' => 0,
            'Completed' => 0,
            'Cancelled' => 0,
        ];
    }
    $deptMap[$dept][$status] = $cnt;
}

$byDepartment = [];
foreach ($deptMap as $dept => $c) {
    $byDepartment[] = ['department' => $dept, 'counts' => $c];
}

json_response([
    'ok' => true,
    'range' => ['start' => $start, 'end' => $end],
    'totals_by_status' => $totals,
    'daily_volume' => ['labels' => $labels, 'counts' => $counts],
    'avg_resolution' => ['resolved_count' => $resolvedCount, 'avg_hours' => $avgHours],
    'by_department' => $byDepartment,
]);
