<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_auth.php';

require_api_admin_login();
require_csrf_token();

$mysqli = db();
$admin = current_admin($mysqli);
if (!$admin) {
    http_response_code(401);
    exit;
}
if (($admin['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit;
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
    http_response_code(422);
    exit;
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

$sql = 'SELECT c.report_number,
            CONCAT(u.first_name, " ", u.last_name) AS citizen_name,
            d.name AS department,
            ct.name AS concern_type,
            c.barangay,
            c.status,
            c.created_at,
            t.completed_at,
            CASE WHEN t.completed_at IS NULL THEN NULL ELSE ROUND(TIMESTAMPDIFF(SECOND, c.created_at, t.completed_at) / 3600, 2) END AS resolution_hours
        FROM concerns c
        JOIN users u ON u.id = c.user_id
        JOIN concern_types ct ON ct.id = c.concern_type_id
        JOIN departments d ON d.id = ct.department_id
        LEFT JOIN (
            SELECT concern_id, MIN(created_at) AS completed_at
            FROM concern_timeline
            WHERE status = "Completed"
            GROUP BY concern_id
        ) t ON t.concern_id = c.id
        WHERE ' . $where . '
        ORDER BY c.id DESC';

$stmt = $mysqli->prepare($sql);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();

$filename = 'reports_' . $start . '_to_' . $end . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'wb');
fputcsv($out, ['report_number', 'citizen_name', 'department', 'concern_type', 'barangay', 'status', 'created_at', 'completed_at', 'resolution_hours']);

if ($res) {
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['report_number'] ?? '',
            $row['citizen_name'] ?? '',
            $row['department'] ?? '',
            $row['concern_type'] ?? '',
            $row['barangay'] ?? '',
            $row['status'] ?? '',
            $row['created_at'] ?? '',
            $row['completed_at'] ?? '',
            $row['resolution_hours'] ?? '',
        ]);
    }
}

fclose($out);
$stmt->close();
exit;
