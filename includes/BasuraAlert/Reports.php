<?php
declare(strict_types=1);

/**
 * BasuraAlert — Reports service
 * Extracted from includes/basuraalert.php (auto-split 13 functions)
 * This file is required by includes/basuraalert.php for backwards-compat.
 */

function ba_generate_report_number(?mysqli $db): string
{
    if (!($db instanceof mysqli)) {
        $yy = date('y');
        $prefix = 'BA-' . $yy . '-';
        return $prefix . str_pad('1', 5, '0', STR_PAD_LEFT);
    }
    $yy = date('y');
    $prefix = 'BA-' . $yy . '-';
    $stmt = $db->prepare('SELECT report_number FROM ba_reports WHERE report_number LIKE CONCAT(?, "%") ORDER BY CAST(SUBSTRING(report_number, 7) AS UNSIGNED) DESC LIMIT 1');
    if (!$stmt) return $prefix . str_pad('1', 5, '0', STR_PAD_LEFT);
    db_prepared_execute($stmt, 's', [$prefix]);
    $res = $stmt->get_result();
    $last = $res ? ($res->fetch_assoc()['report_number'] ?? null) : null;
    $stmt->close();
    $seq = 1;
    if (is_string($last) && preg_match('/^BA-\d{2}-(\d{5})$/', $last, $m)) $seq = ((int) $m[1]) + 1;
    return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
}

function ba_create_report(?mysqli $db, array $payload): array
{
    if (!($db instanceof mysqli)) {
        return ['ok' => false, 'error' => 'Database connection unavailable. Please try again.'];
    }
    $userId = (int) $payload['user_id'];
    $barangayId = (int) $payload['barangay_id'];
    $category = (string) $payload['category'];
    $validCategories = array_keys(ba_report_category_options());
    if (!in_array($category, $validCategories, true)) {
        return ['ok' => false, 'error' => 'Invalid report category.'];
    }
    $date = (string) $payload['date_of_concern'];
    $street = $payload['street'] ?? null;
    $landmark = $payload['landmark'] ?? null;
    $description = (string) $payload['description'];
    $photos = isset($payload['photos_json']) && is_array($payload['photos_json']) ? json_encode($payload['photos_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $initDbStatus = ba_db_status('New');
    try {
        $tmpRand = function_exists('random_bytes')
            ? bin2hex(random_bytes(5))
            : substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 10);
        $placeholderRN = 'BA-TMP-' . $tmpRand;

        $db->begin_transaction();

        $stmt = $db->prepare('INSERT INTO ba_reports (report_number, user_id, category, barangay_id, date_of_concern, street, landmark, description, photos_json, status) VALUES (?,?,?,?,?,?,?,?,?, \'' . $initDbStatus . '\')');
        if (!$stmt) throw new Exception($db->error);
        db_prepared_execute($stmt, 'sisssssss', [$placeholderRN, $userId, $category, $barangayId, $date, $street, $landmark, $description, $photos]);
        $newId = (int) ($db->insert_id ?? 0);
        $stmt->close();
        if ($newId <= 0) throw new Exception('Failed to create report (no insert id).');

        $yy = date('y');
        $reportNumber = 'BA-' . $yy . '-' . str_pad((string) $newId, 5, '0', STR_PAD_LEFT);

        $ustmt = $db->prepare('UPDATE ba_reports SET report_number = ? WHERE id = ?');
        if (!$ustmt) throw new Exception($db->error);
        db_prepared_execute($ustmt, 'si', [$reportNumber, $newId]);
        $ustmt->close();

        $tstmt = $db->prepare('INSERT INTO ba_report_timeline (report_id, status, note) VALUES (?, \'' . $initDbStatus . '\', \'Report submitted by resident\')');
        if ($tstmt) {
            db_prepared_execute($tstmt, 'i', [$newId]);
            $tstmt->close();
        }

        $db->commit();
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false && stripos($msg, 'uniq_ba_reports_num') !== false) {
            return ['ok' => false, 'error' => 'A report number conflict occurred. Please click Submit again to retry.'];
        }
        return ['ok' => false, 'error' => 'Failed to create report: ' . (strlen($msg) < 160 ? $msg : 'Please try again momentarily.')];
    }

    $adminMsg = 'Thank you for submitting your BasuraAlert issue report (reference ' . $reportNumber . '). An administrator will review and respond.';
    ba_push_notification_via_prefs($db, $userId, 'report_submit', 'Report Submitted', $adminMsg, ['table' => 'ba_reports', 'id' => $newId]);

    return ['ok' => true, 'report_id' => $newId, 'report_number' => $reportNumber];
}

function ba_list_reports(?mysqli $db, ?int $userId = null, ?string $status = null, ?int $barangayId = null, ?string $category = null, int $limit = 500): array
{
    if (!($db instanceof mysqli)) return [];
    $where = [];
    $params = [];
    $types = '';
    if ($userId !== null && $userId > 0) {
        $where[] = 'r.user_id = ?';
        $params[] = $userId;
        $types .= 'i';
    }
    if ($status !== null && $status !== '') {
        $where[] = 'r.status = ?';
        $params[] = ba_db_status($status);
        $types .= 's';
    }
    if ($barangayId !== null && $barangayId > 0) {
        $where[] = 'r.barangay_id = ?';
        $params[] = $barangayId;
        $types .= 'i';
    }
    if ($category !== null && $category !== '') {
        $where[] = 'r.category = ?';
        $params[] = $category;
        $types .= 's';
    }
    $sql = 'SELECT r.*, b.name AS barangay_name,
                   CONCAT(u.first_name, \' \', u.last_name) AS user_full_name,
                   u.mobile AS user_mobile, u.email AS user_email
            FROM ba_reports r
            LEFT JOIN barangays b ON b.id = r.barangay_id
            LEFT JOIN users u ON u.id = r.user_id';
    if (count($where) > 0) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY r.id DESC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    db_prepared_execute($stmt, $types, $params);
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function ba_get_report(?mysqli $db, int $reportId): ?array
{
    if (!($db instanceof mysqli)) return null;
    $stmt = $db->prepare('SELECT r.*, b.name AS barangay_name,
                                 CONCAT(u.first_name, \' \', u.last_name) AS user_full_name,
                                 u.mobile AS user_mobile, u.email AS user_email
                          FROM ba_reports r
                          LEFT JOIN barangays b ON b.id = r.barangay_id
                          LEFT JOIN users u ON u.id = r.user_id
                          WHERE r.id = ? LIMIT 1');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$reportId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

function ba_get_report_timeline(?mysqli $db, int $reportId): array
{
    if (!($db instanceof mysqli)) return [];
    $stmt = $db->prepare('SELECT t.*, CONCAT(a.name) AS admin_name
                          FROM ba_report_timeline t
                          LEFT JOIN admins a ON a.id = t.admin_id
                          WHERE t.report_id = ?
                          ORDER BY t.id ASC');
    if (!$stmt) return [];
    db_prepared_execute($stmt, 'i', [$reportId]);
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function ba_normalize_report_status(string $status): string
{
    $map = [
        'In Progress' => 'In Progress',
        'In_Progress' => 'In Progress',
        'Ongoing'     => 'In Progress',
        'Rejected'    => 'Rejected',
        'Cancelled'   => 'Rejected',
        'Canceled'    => 'Rejected',
        'Submitted'   => 'New',
        'Acknowledged'=> 'Acknowledged',
        'Completed'   => 'Completed',
        'New'         => 'New',
    ];
    $normalized = $map[$status] ?? null;
    if ($normalized !== null) return $normalized;
    foreach ($map as $from => $to) {
        if (strcasecmp($from, $status) === 0) return $to;
    }
    return in_array($status, ['New','Acknowledged','In Progress','Completed','Rejected'], true) ? $status : 'New';
}

function ba_db_status(string $appStatus): string
{
    $appStatus = trim($appStatus);
    $map = [
        'In Progress' => 'Ongoing',
        'In_Progress' => 'Ongoing',
        'Rejected'    => 'Cancelled',
        'Cancelled'   => 'Cancelled',
        'Canceled'    => 'Cancelled',
        'Ongoing'     => 'Ongoing',
        'Acknowledged'=> 'Acknowledged',
        'Completed'   => 'Completed',
        'Submitted'   => 'New',
        'New'         => 'New',
    ];
    $mapped = $map[$appStatus] ?? null;
    if ($mapped !== null) return $mapped;
    foreach ($map as $from => $to) {
        if (strcasecmp($from, $appStatus) === 0) return $to;
    }
    $enumSafe = ['New','Acknowledged','Ongoing','Completed','Cancelled'];
    if (in_array($appStatus, $enumSafe, true)) return $appStatus;
    return 'New';
}

function ba_report_status_options(): array
{
    return ['New','Acknowledged','In Progress','Completed','Rejected'];
}

function ba_report_status_label(string $status): string
{
    $labels = [
        'New'         => 'New (Submitted)',
        'Acknowledged'=> 'Acknowledged',
        'In Progress' => 'In Progress',
        'Completed'   => 'Completed',
        'Rejected'    => 'Rejected (Cancelled)',
    ];
    return $labels[$status] ?? $status;
}

function ba_report_status_badge_class(string $status): string
{
    return match($status){
        'Completed'   => 'bg-success',
        'In Progress' => 'bg-primary',
        'Acknowledged'=> 'bg-info text-dark',
        'Rejected'    => 'bg-secondary',
        default       => 'bg-warning text-dark'
    };
}

function ba_report_category_options(): array
{
    return [
        'Missed_Collection'          => 'Missed scheduled collection',
        'Overflowing_Bins'           => 'Overflowing garbage bins',
        'Illegal_Dumping'            => 'Illegal dumping or improper disposal',
        'Littered_Streets'           => 'Littered streets or public areas',
        'Broken_Bins'                => 'Broken or damaged garbage bins',
        'Uncollected_Bulky_Waste'    => 'Uncollected yard or bulky waste',
        'Other_Waste_Concern'        => 'Other waste concern',
    ];
}

function ba_report_category_label(string $category): string
{
    $opts = ba_report_category_options();
    return $opts[$category] ?? (mb_strlen($category) ? ucwords(str_replace('_', ' ', $category)) : 'Unspecified');
}

function ba_update_report_status(?mysqli $db, int $reportId, string $newStatus, ?int $adminId = null, ?string $note = null, ?string $resolutionNote = null, ?string $internalNote = null): bool
{
    if (!($db instanceof mysqli)) return false;
    $newStatus = ba_normalize_report_status($newStatus);
    if (in_array($newStatus, ['Completed','Rejected'], true) && ($resolutionNote === null || trim($resolutionNote) === '')) {
        return false;
    }
    $dbStatus = ba_db_status($newStatus);
    $db->begin_transaction();
    try {
        $fields = ['status = ?'];
        $types = 's';
        $params = [$dbStatus];
        if ($resolutionNote !== null && $resolutionNote !== '') {
            $fields[] = 'resolution_note = ?';
            $params[] = $resolutionNote;
            $types .= 's';
        }
        if ($internalNote !== null && $internalNote !== '') {
            $fields[] = 'internal_handling_note = ?';
            $params[] = $internalNote;
            $types .= 's';
        }
        if ($adminId !== null && $adminId > 0) {
            $fields[] = 'assigned_admin_id = ?';
            $params[] = $adminId;
            $types .= 'i';
        }
        $params[] = $reportId;
        $types .= 'i';
        $sql = 'UPDATE ba_reports SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new Exception($db->error);
        db_prepared_execute($stmt, $types, $params);
        $stmt->close();

        $tstmt = $db->prepare('INSERT INTO ba_report_timeline (report_id, status, note, admin_id, is_internal, internal_note) VALUES (?,?,?,?,?,?)');
        if (!$tstmt) throw new Exception($db->error);
        $publicNote = trim((string) ($note ?? 'Status updated'));
        $privTrim = trim((string) ($internalNote ?? ''));
        $isInternal = $privTrim !== '' ? 1 : 0;
        $adminBind = $adminId > 0 ? $adminId : null;
        db_prepared_execute($tstmt, 'isssis', [$reportId, $dbStatus, $publicNote, $adminBind, $isInternal, $privTrim !== '' ? $privTrim : null]);
        $tstmt->close();

        $report = ba_get_report($db, $reportId);
        if (is_array($report) && (int) $report['user_id'] > 0) {
            ba_push_notification_via_prefs(
                $db,
                (int) $report['user_id'],
                'report_update',
                'Report Update: ' . ($report['report_number'] ?? ''),
                'Your report status is now ' . ba_report_status_label($newStatus) . ($publicNote ? ' — ' . $publicNote : '') . ($resolutionNote ? ' Resolution: ' . $resolutionNote : ''),
                ['table' => 'ba_reports', 'id' => $reportId]
            );
        }

        $db->commit();
        return true;
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) { }
        return false;
    }
}

