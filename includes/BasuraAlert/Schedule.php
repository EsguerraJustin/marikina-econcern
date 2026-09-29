<?php
declare(strict_types=1);

/**
 * BasuraAlert — Schedule service
 * Extracted from includes/basuraalert.php (auto-split 15 functions)
 * This file is required by includes/basuraalert.php for backwards-compat.
 */

function ba_list_barangays(?mysqli $db, bool $activeOnly = true): array
{
    if (!($db instanceof mysqli)) return [];
    $sql = 'SELECT id, name, city, active FROM barangays';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY name ASC';
    $res = $db->query($sql);
    if (!$res) return [];
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function ba_get_barangay_by_name(?mysqli $db, string $name): ?array
{
    if (!($db instanceof mysqli)) return null;
    $stmt = $db->prepare('SELECT id, name, city, active FROM barangays WHERE name = ? LIMIT 1');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 's', [$name]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

function ba_user_barangay_id(?mysqli $db, ?array $user): ?int
{
    if (!($db instanceof mysqli) || !is_array($user) || empty($user['barangay'])) {
        return null;
    }
    $bar = ba_get_barangay_by_name($db, (string) $user['barangay']);
    return is_array($bar) ? (int) $bar['id'] : null;
}

function ba_day_name(int $dow): string
{
    static $names = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];
    return $names[$dow] ?? '—';
}

function ba_format_time(?string $t): string
{
    if ($t === null || $t === '') return '—';
    $ts = strtotime($t);
    if ($ts === false) return e($t);
    return date('g:i A', $ts);
}

function ba_waste_meta(string $wasteType): array
{
    static $map = [
        'Biodegradable' => [
            'label'    => 'Biodegradable (Wet)',
            'short'    => 'Wet waste',
            'examples' => 'Food scraps, fruit/veg peels, fish bones, coffee grounds, yard clippings, dry leaves',
            'guidance' => 'Remove packaging first; drain excess liquid before placing in the biodegradable bin.',
            'badge'    => 'bg-success',
            'icon'     => '🍃',
        ],
        'Non-Biodegradable' => [
            'label'    => 'Non-Biodegradable (Dry)',
            'short'    => 'Dry residual',
            'examples' => 'Candy wrappers, sando bags, diapers/sanitary napkins, styrofoam, cigarette butts',
            'guidance' => 'Wrap sharp/biohazard items in newspaper before disposal; keep dry to avoid odor.',
            'badge'    => 'bg-secondary',
            'icon'     => '🗑️',
        ],
        'Recyclable' => [
            'label'    => 'Recyclable (Clean/Dry)',
            'short'    => 'Clean recyclables',
            'examples' => 'PET bottles, paper/cardboard, aluminum/tin cans, clean plastic containers',
            'guidance' => 'Rinse and dry first; remove labels/caps if possible. No food residue allowed.',
            'badge'    => 'bg-info text-dark',
            'icon'     => '♻️',
        ],
        'Hazardous' => [
            'label'    => 'Hazardous (Toxic/Special)',
            'short'    => 'Hazardous waste',
            'examples' => 'Batteries, fluorescent bulbs, paint cans, chemicals, needles/medical waste',
            'guidance' => 'Do NOT mix with other bins. Hand over separately at the barangay hazardous drop-off.',
            'badge'    => 'bg-danger',
            'icon'     => '⚠️',
        ],
        'Special' => [
            'label'    => 'Special (Bulky)',
            'short'    => 'Bulky pickup',
            'examples' => 'Furniture, appliances, e-waste, construction debris, old mattresses',
            'guidance' => 'Schedule this in advance with the barangay. Do not leave on sidewalk without approval.',
            'badge'    => 'bg-primary',
            'icon'     => '🛋️',
        ],
        'Mixed' => [
            'label'    => 'Mixed (All Types)',
            'short'    => 'All-in-one pickup',
            'examples' => 'Bio + Non-bio + Recyclable are all accepted in a single load on this run',
            'guidance' => 'No segregation required on this run. Place all bins out together at the curb.',
            'badge'    => 'bg-primary',
            'icon'     => '🚛',
        ],
    ];
    $key = $wasteType;
    if (!isset($map[$key])) {
        $closest = null;
        foreach (array_keys($map) as $candidate) {
            if (strcasecmp($candidate, $key) === 0) { $closest = $candidate; break; }
        }
        if ($closest === null) {
            return [
                'label'    => $wasteType !== '' ? $wasteType : 'Unspecified',
                'short'    => $wasteType !== '' ? $wasteType : '—',
                'examples' => '',
                'guidance' => '',
                'badge'    => 'bg-primary',
                'icon'     => '🚛',
                'raw'      => $wasteType,
            ];
        }
        $key = $closest;
    }
    return array_merge($map[$key], ['raw' => $wasteType]);
}

function ba_waste_label(string $wasteType): string
{
    return ba_waste_meta($wasteType)['label'];
}

function ba_waste_badge(string $wasteType, bool $includeIcon = true, ?string $customClass = null): string
{
    $m = ba_waste_meta($wasteType);
    $class = $customClass !== null ? $customClass : $m['badge'];
    $prefix = $includeIcon && !empty($m['icon']) ? $m['icon'] . ' ' : '';
    return '<span class="badge ' . $class . '">' . $prefix . e($m['label']) . '</span>';
}

function ba_list_schedules(?mysqli $db, ?int $barangayId = null, ?string $status = 'Published'): array
{
    if (!($db instanceof mysqli)) return [];
    $where = [];
    $params = [];
    $types = '';
    if ($barangayId !== null && $barangayId > 0) {
        $where[] = 's.barangay_id = ?';
        $params[] = $barangayId;
        $types .= 'i';
    }
    if ($status !== null && $status !== '') {
        $where[] = 's.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    $sql = 'SELECT s.*, b.name AS barangay_name,
                   d.id AS __dropoff_id, d.spot_name AS __dropoff_name,
                   d.latitude AS __dropoff_lat, d.longitude AS __dropoff_lng
            FROM ba_collection_schedules s
            LEFT JOIN barangays b ON b.id = s.barangay_id
            LEFT JOIN ba_dropoff_points d ON (s.linked_type = \'dropoff\' AND d.id = s.linked_id)';
    if (count($where) > 0) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY
        CASE s.schedule_type WHEN \'exception\' THEN 0 WHEN \'one_time\' THEN 1 ELSE 2 END ASC,
        s.collection_date DESC, s.day_of_week ASC, s.time_start ASC';

    if (count($params) === 0) {
        $res = $db->query($sql);
        if (!$res) return [];
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    db_prepared_execute($stmt, $types, $params);
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function _ba_next_window_core(array $candidates, int $asof_ts, int $dow, string $today): ?array
{
    $best = null;
    $bestDiff = null;

    foreach ($candidates as $c) {
        $type = (string) ($c['schedule_type'] ?? 'regular');
        $dateStr = null;

        if ($type === 'one_time' || $type === 'exception') {
            if (!empty($c['collection_date']) && $c['collection_date'] >= $today) {
                $dateStr = $c['collection_date'];
            }
        } else {
            $targetDow = (int) ($c['day_of_week'] ?? 0);
            if ($targetDow < 1 || $targetDow > 7) continue;

            $offset = $targetDow - $dow;
            if ($offset < 0) $offset += 7;
            $nextDate = date('Y-m-d', $asof_ts + ($offset * 86400));
            $dateStr = $nextDate;
        }

        if ($dateStr === null) continue;
        $diff = (int) round(((int) strtotime($dateStr) - $asof_ts) / 86400);
        if ($diff < 0) continue;

        if ($bestDiff === null || $diff < $bestDiff || ($diff === $bestDiff && $type !== 'regular')) {
            $best = $c;
            $best['next_date'] = $dateStr;
            $bestDiff = $diff;
        }
    }

    if ($best === null) return null;
    $best['days_until'] = $bestDiff ?? 0;
    return $best;
}

function ba_next_collection_for(?mysqli $db, int $barangayId, ?string $asOf = null): ?array
{
    if (!($db instanceof mysqli)) return null;
    if ($asOf === null) $asOf = date('Y-m-d');
    $asof_ts = strtotime($asOf);
    if ($asof_ts === false) $asof_ts = time();
    $dow = (int) date('N', $asof_ts) + 1;
    if ($dow > 7) $dow = 1;
    $today = date('Y-m-d', $asof_ts);

    $candidates = [];
    $stmt = $db->prepare('
        SELECT s.id, s.title, s.waste_type, s.schedule_type, s.day_of_week, s.collection_date,
               s.time_start, s.time_end, s.notes, s.status, b.name AS barangay_name
        FROM ba_collection_schedules s
        LEFT JOIN barangays b ON b.id = s.barangay_id
        WHERE s.barangay_id = ? AND s.status = \'Published\'
    ');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$barangayId]);
    $res = $stmt->get_result();
    if ($res) while ($r = $res->fetch_assoc()) $candidates[] = $r;
    $stmt->close();

    return _ba_next_window_core($candidates, $asof_ts, $dow, $today);
}

function ba_next_open_window(?mysqli $db, int $dropoffId, ?string $asOf = null): ?array
{
    if (!($db instanceof mysqli)) return null;
    if ($asOf === null) $asOf = date('Y-m-d');
    $asof_ts = strtotime($asOf);
    if ($asof_ts === false) $asof_ts = time();
    $dow = (int) date('N', $asof_ts) + 1;
    if ($dow > 7) $dow = 1;
    $today = date('Y-m-d', $asof_ts);

    $candidates = [];
    $stmt = $db->prepare('
        SELECT ds.id, d.spot_name AS title, ds.waste_type, \'regular\' AS schedule_type,
               (ds.day_of_week + 1) AS day_of_week, NULL AS collection_date,
               ds.time_start, ds.time_end, \'Drop-off hours\' AS notes,
               \'Published\' AS status, d.barangay_id
        FROM ba_dropoff_schedules ds
        INNER JOIN ba_dropoff_points d ON d.id = ds.dropoff_id
        WHERE ds.dropoff_id = ? AND d.status = \'PUBLISHED\'
    ');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$dropoffId]);
    $res = $stmt->get_result();
    if ($res) while ($r = $res->fetch_assoc()) $candidates[] = $r;
    $stmt->close();

    return _ba_next_window_core($candidates, $asof_ts, $dow, $today);
}

function ba_unlink_bidirectional(?mysqli $db, ?int $collectionSchedId = null, ?int $dropoffSchedId = null): bool
{
    if (!($db instanceof mysqli)) return false;
    if ($collectionSchedId === null && $dropoffSchedId === null) return false;

    try {
        $db->begin_transaction();

        if ($collectionSchedId !== null) {
            $collRow = null;
            $stmt = $db->prepare('SELECT linked_type, linked_id FROM ba_collection_schedules WHERE id = ? LIMIT 1');
            if ($stmt) {
                db_prepared_execute($stmt, 'i', [$collectionSchedId]);
                $res = $stmt->get_result();
                if ($res) $collRow = $res->fetch_assoc() ?: null;
                $stmt->close();
            }
            $upd = $db->prepare('UPDATE ba_collection_schedules SET linked_type = \'none\', linked_id = NULL, linked_group_uid = NULL WHERE id = ? LIMIT 1');
            if ($upd) { db_prepared_execute($upd, 'i', [$collectionSchedId]); $upd->close(); }
            if ($collRow && $collRow['linked_type'] === 'dropoff' && !empty($collRow['linked_id'])) {
                $dropoffPointId = (int) $collRow['linked_id'];
                $stmt2 = $db->prepare('UPDATE ba_dropoff_schedules SET linked_type = \'none\', linked_id = NULL WHERE dropoff_id = ? AND linked_type = \'collection\' AND linked_id = ?');
                if ($stmt2) { db_prepared_execute($stmt2, 'ii', [$dropoffPointId, $collectionSchedId]); $stmt2->close(); }
            }
        }

        if ($dropoffSchedId !== null) {
            $dsRow = null;
            $stmt = $db->prepare('SELECT linked_type, linked_id FROM ba_dropoff_schedules WHERE id = ? LIMIT 1');
            if ($stmt) {
                db_prepared_execute($stmt, 'i', [$dropoffSchedId]);
                $res = $stmt->get_result();
                if ($res) $dsRow = $res->fetch_assoc() ?: null;
                $stmt->close();
            }
            $upd = $db->prepare('UPDATE ba_dropoff_schedules SET linked_type = \'none\', linked_id = NULL WHERE id = ? LIMIT 1');
            if ($upd) { db_prepared_execute($upd, 'i', [$dropoffSchedId]); $upd->close(); }
            if ($dsRow && $dsRow['linked_type'] === 'collection' && !empty($dsRow['linked_id'])) {
                $targetCollId = (int) $dsRow['linked_id'];
                $stmt2 = $db->prepare('UPDATE ba_collection_schedules SET linked_type = \'none\', linked_id = NULL, linked_group_uid = NULL WHERE id = ? LIMIT 1');
                if ($stmt2) { db_prepared_execute($stmt2, 'i', [$targetCollId]); $stmt2->close(); }
            }
        }

        $db->commit();
        return true;
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        return false;
    }
}

function ba_collection_status_label(int $daysUntil): array
{
    if ($daysUntil === 0) return ['class' => 'success', 'text' => 'Collection Today'];
    if ($daysUntil === 1) return ['class' => 'warning', 'text' => 'Collection Tomorrow'];
    if ($daysUntil < 7) return ['class' => 'info', 'text' => sprintf('In %d days', $daysUntil)];
    return ['class' => 'secondary', 'text' => sprintf('In %d days', $daysUntil)];
}

function ba_search_waste_guide(?mysqli $db, string $q = '', ?string $category = null, int $limit = 100): array
{
    if (!($db instanceof mysqli)) return [];
    $where = [];
    $params = [];
    $types = '';
    $where[] = "w.status = 'Published'";
    if ($q !== '') {
        $where[] = '(w.item_name LIKE ? OR w.disposal_guidance LIKE ? OR w.prep_guidance LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'sss';
    }
    if ($category !== null && $category !== '') {
        $where[] = 'w.category = ?';
        $params[] = $category;
        $types .= 's';
    }
    $sql = 'SELECT w.* FROM ba_waste_guide w WHERE ' . implode(' AND ', $where) . ' ORDER BY w.item_name ASC LIMIT ?';
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

