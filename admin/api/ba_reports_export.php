<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/basuraalert.php';

require_api_admin_login();
require_csrf_token();

$db = db();
$admin = current_admin($db);
if (!$admin) {
    http_response_code(401);
    exit;
}

$filterBarangay = isset($_GET['barangay_id']) ? (int) $_GET['barangay_id'] : 0;
$filterCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$filterFrom = !empty($_GET['from']) ? trim((string) $_GET['from']) : null;
$filterTo = !empty($_GET['to']) ? trim((string) $_GET['to']) : null;

$categoryOptions = ba_report_category_options();

$where = [];
$params = [];
$types = '';
if ($filterBarangay > 0) {
    $where[] = 'r.barangay_id = ?';
    $params[] = $filterBarangay;
    $types .= 'i';
}
if ($filterCategory !== '' && isset($categoryOptions[$filterCategory])) {
    $where[] = 'r.category = ?';
    $params[] = $filterCategory;
    $types .= 's';
}
if ($filterFrom !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $where[] = 'DATE(r.created_at) >= ?';
    $params[] = $filterFrom;
    $types .= 's';
}
if ($filterTo !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $where[] = 'DATE(r.created_at) <= ?';
    $params[] = $filterTo;
    $types .= 's';
}
$whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

// NOTE: ba_reports has no `title` column. The resident-facing reference is
// `report_number` and the free-text body is `description`.
$sql = "SELECT r.id, r.report_number, r.category, r.date_of_concern,
               r.street, r.landmark, r.description, r.status, r.resolution_note,
               CONCAT(u.first_name, ' ', u.last_name) AS uname, u.email, u.mobile,
               b.name AS report_barangay,
               r.created_at, r.updated_at
        FROM ba_reports r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN barangays b ON b.id = r.barangay_id
        {$whereSql}
        ORDER BY r.id DESC";

/**
 * Abort the export with a readable message instead of a PHP fatal/stack trace.
 */
function ba_export_abort(string $detail): void
{
    @error_log('[ba_reports_export] ' . $detail . PHP_EOL, 3, __DIR__ . '/../../app_error.log');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo "BasuraAlert CSV export failed.\n\n";
    echo $detail . "\n";
    exit;
}

$res = false;
$stmt = null;
try {
    // NOTE: db_prepare() must be inside the try. On PHP 8.1+ mysqli throws on
    // prepare failure and the `@` inside db_prepare() does NOT suppress it.
    $stmt = db_prepare($db, $sql);
    if (!$stmt instanceof mysqli_stmt) {
        throw new RuntimeException('prepare failed: ' . ($db->error ?? 'unknown error'));
    }
    // Always go through db_prepared_execute(): with no params it still runs
    // $stmt->execute(), which is required before get_result().
    db_prepared_execute($stmt, $types, $params);
    $res = $stmt->get_result();
} catch (Throwable $e) {
    if ($stmt instanceof mysqli_stmt) {
        try { $stmt->close(); } catch (Throwable $_) {}
    }
    ba_export_abort('Database error while reading reports: ' . $e->getMessage());
}
$stmt->close();

$filename = 'basuraalert_reports_' . date('Y_m_d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'wb');
fputcsv($out, [
    'Report ID', 'Report number', 'Description', 'Category (display)', 'Status (normalized)',
    'Barangay', 'Date of concern', 'Street', 'Landmark',
    'Resident', 'Email', 'Mobile',
    'Resolution note (public)', 'Created at', 'Updated at'
]);

if ($res) {
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            (int) ($row['id'] ?? 0),
            (string) ($row['report_number'] ?? ''),
            (string) ($row['description'] ?? ''),
            ba_report_category_label((string) ($row['category'] ?? '')),
            ba_normalize_report_status((string) ($row['status'] ?? '')),
            (string) ($row['report_barangay'] ?? ''),
            (string) ($row['date_of_concern'] ?? ''),
            (string) ($row['street'] ?? ''),
            (string) ($row['landmark'] ?? ''),
            (string) ($row['uname'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['mobile'] ?? ''),
            (string) ($row['resolution_note'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        ]);
    }
}

fclose($out);
exit;
