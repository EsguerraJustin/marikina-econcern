<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/email_verification.php';

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

function ba_list_announcements(?mysqli $db, ?int $targetBarangayId = null, string $scope = 'public'): array
{
    if (!($db instanceof mysqli)) return [];
    $where = [];
    $params = [];
    $types = '';
    if ($scope === 'public') {
        $where[] = "a.status = 'Published'";
    }
    if ($targetBarangayId !== null && $targetBarangayId > 0) {
        $where[] = '(a.scope = \'all\' OR (a.scope = \'barangay\' AND a.target_barangay_id = ?))';
        $params[] = $targetBarangayId;
        $types .= 'i';
    }
    $sql = 'SELECT a.*, b.name AS target_barangay_name
            FROM ba_announcements a
            LEFT JOIN barangays b ON b.id = a.target_barangay_id';
    if (count($where) > 0) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.effective_date DESC, a.published_at DESC, a.id DESC';

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

function ba_list_notifications_for_user(?mysqli $db, int $userId, ?bool $unreadOnly = null, int $limit = 100): array
{
    if (!($db instanceof mysqli)) return [];
    $where = ['n.user_id = ?'];
    $params = [$userId];
    $types = 'i';
    if ($unreadOnly === true) {
        $where[] = 'n.is_read = 0';
    } elseif ($unreadOnly === false) {
        $where[] = 'n.is_read = 1';
    }
    $sql = 'SELECT n.* FROM ba_notifications n WHERE ' . implode(' AND ', $where) . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ?';
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

function ba_count_unread_notifications(?mysqli $db, int $userId): int
{
    if (!($db instanceof mysqli)) return 0;
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM ba_notifications WHERE user_id = ? AND is_read = 0');
    if (!$stmt) return 0;
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

function ba_mark_notification_read(?mysqli $db, int $notifId, int $userId): void
{
    if (!($db instanceof mysqli)) return;
    $stmt = $db->prepare('UPDATE ba_notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND is_read = 0');
    if (!$stmt) return;
    db_prepared_execute($stmt, 'ii', [$notifId, $userId]);
    $stmt->close();
}

function ba_mark_all_notifications_read(?mysqli $db, int $userId): void
{
    if (!($db instanceof mysqli)) return;
    $stmt = $db->prepare('UPDATE ba_notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND is_read = 0');
    if (!$stmt) return;
    db_prepared_execute($stmt, 'i', [$userId]);
    $stmt->close();
}

function ba_delete_notification(?mysqli $db, int $notifId, int $userId): bool
{
    if (!($db instanceof mysqli) || $notifId <= 0 || $userId <= 0) return false;
    $stmt = $db->prepare('DELETE FROM ba_notifications WHERE id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) return false;
    db_prepared_execute($stmt, 'ii', [$notifId, $userId]);
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}

function ba_delete_all_notifications_for_user(?mysqli $db, int $userId, bool $onlyUnread = false): int
{
    if (!($db instanceof mysqli) || $userId <= 0) return 0;
    $sql = 'DELETE FROM ba_notifications WHERE user_id = ?';
    if ($onlyUnread) $sql .= ' AND is_read = 0';
    $stmt = $db->prepare($sql);
    if (!$stmt) return 0;
    db_prepared_execute($stmt, 'i', [$userId]);
    $deleted = (int) $stmt->affected_rows;
    $stmt->close();
    return max(0, $deleted);
}

function ba_build_notification_email_html(string $title, string $message, string $type = 'notification'): string
{
    $titleE = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $msgE = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $year = date('Y');
    $appName = (defined('APP_NAME') ? (string)APP_NAME : 'BasuraAlert · Marikina e-Concern');
    $typeLabel = ucwords(str_replace('_', ' ', $type));
    $typeE = htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$titleE}</title></head>
<body style="margin:0;padding:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f3f6fb;color:#1f2937;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,.07);margin-top:24px;margin-bottom:24px;">
  <tr style="background:linear-gradient(135deg,#16a34a 0%,#15803d 100%);">
    <td style="padding:24px 28px;">
      <div style="color:#ffffff;font-size:12px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;opacity:.9;margin-bottom:6px;">{$typeE}</div>
      <div style="color:#ffffff;font-size:22px;font-weight:700;line-height:1.2;">{$titleE}</div>
    </td>
  </tr>
  <tr>
    <td style="padding:28px;">
      <div style="background:#ecfdf5;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;margin-bottom:20px;">
        <div style="font-size:13px;font-weight:600;color:#065f46;margin-bottom:2px;">✅ Email delivered</div>
        <div style="font-size:12px;color:#047857;">This notification was dispatched in real time via Brevo SMTP API.</div>
      </div>
      <div style="font-size:15px;color:#374151;line-height:1.6;">{$msgE}</div>
    </td>
  </tr>
  <tr style="background:#f9fafb;border-top:1px solid #e5e7eb;">
    <td style="padding:16px 28px;color:#6b7280;font-size:12px;text-align:center;line-height:1.5;">
      This is an automated message from <strong style="color:#1f2937;">{$appName}</strong>. Please do not reply directly.
      <br>&copy; {$year} · BasuraAlert Module
    </td>
  </tr>
</table>
</body></html>
HTML;
}

function ba_push_notification(?mysqli $db, int $userId, string $type, string $title, string $message, string $channel = 'in_app', array $ref = []): int
{
    if (!($db instanceof mysqli)) return 0;
    $refTable = isset($ref['table']) && is_string($ref['table']) ? $ref['table'] : null;
    $refId = isset($ref['id']) && (int) $ref['id'] > 0 ? (int) $ref['id'] : null;

    $userMobile = null;
    $userEmail = null;
    $userFullName = '';

    if (($channel === 'sms' || $channel === 'email') && $userId > 0) {
        $uStmt = $db->prepare('SELECT mobile, email, first_name, last_name FROM users WHERE id = ? LIMIT 1');
        if ($uStmt) {
            db_prepared_execute($uStmt, 'i', [$userId]);
            $uRes = $uStmt->get_result();
            $uRow = $uRes ? $uRes->fetch_assoc() : null;
            $uStmt->close();
            if ($uRow) {
                $userMobile = !empty($uRow['mobile']) ? (string) $uRow['mobile'] : null;
                $userEmail = !empty($uRow['email']) ? (string) $uRow['email'] : null;
                $userFullName = trim(((string)($uRow['first_name'] ?? '')) . ' ' . ((string)($uRow['last_name'] ?? '')));
            }
        }
    }

    $status = 'queued';
    $note = 'Queued for delivery — will be processed in the background.';

    if ($channel === 'in_app') {
        $status = 'sent';
        $note = 'Delivered to in-app notification center';
    }

    if ($channel === 'sms' && $userMobile !== null) {
        $norm = normalize_ph_mobile($userMobile);
        if ($norm !== false) {
            $smsBody = trim($title) !== '' ? ($title . ': ' . $message) : $message;
            if (strlen($smsBody) > 1530) $smsBody = substr($smsBody, 0, 1527) . '...';
            $status = 'queued';
            $note = 'SMS queued for TextBee delivery to ' . mask_mobile($norm) . ' (non-blocking)';
        }
    }

    if ($channel === 'email' && $userEmail !== null && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        $status = 'queued';
        $note = 'Email queued for Brevo delivery to ' . mask_email($userEmail) . ' (non-blocking)';
    }

    $stmt = $db->prepare('INSERT INTO ba_notifications (user_id, type, title, message, ref_table, ref_id, channel, delivery_status, delivery_note) VALUES (?,?,?,?,?,?,?,?,?)');
    if (!$stmt) return 0;
    db_prepared_execute($stmt, 'isssissss', [$userId, $type, $title, $message, $refTable, $refId, $channel, $status, $note]);
    $newId = (int) ($db->insert_id ?? 0);
    $stmt->close();
    return $newId;
}

function ba_flush_queued_notifications(?mysqli $db, int $maxPerRun = 10, int $perItemTimeoutMs = 3000): array
{
    if (!($db instanceof mysqli)) return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    $sent = 0;
    $failed = 0;
    $skipped = 0;

    $perItemSec = (int)ceil(max(1, $perItemTimeoutMs) / 1000);
    $totalMaxSec = max(10, $maxPerRun * $perItemSec);
    @set_time_limit($totalMaxSec);

    $qStmt = $db->prepare('SELECT n.id, n.user_id, n.type, n.title, n.message, n.channel, u.mobile, u.email, u.first_name, u.last_name
        FROM ba_notifications n
        LEFT JOIN users u ON u.id = n.user_id
        WHERE n.delivery_status = ? AND n.channel IN (?,?)
        ORDER BY n.id ASC
        LIMIT ?');
    if (!$qStmt) return ['ok' => false, 'error' => 'prepare failed', 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    $queued = 'queued';
    $chSms = 'sms';
    $chEmail = 'email';
    db_prepared_execute($qStmt, 'sssi', [$queued, $chSms, $chEmail, $maxPerRun]);
    $res = $qStmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $qStmt->close();

    if (count($rows) === 0) {
        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    }

    // sent_at, matching includes/BasuraAlert/Notifications.php. delivered_at was
    // never a ba_notifications column and made this prepare() throw.
    $updStmt = $db->prepare('UPDATE ba_notifications SET delivery_status = ?, delivery_note = ?, sent_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1');
    if (!$updStmt) {
        return ['ok' => false, 'error' => 'update prepare failed', 'sent' => 0, 'failed' => 0, 'skipped' => count($rows)];
    }

    foreach ($rows as $row) {
        $nid = (int)($row['id'] ?? 0);
        if ($nid <= 0) { $skipped++; continue; }
        $channel = (string)($row['channel'] ?? 'in_app');
        $title = (string)($row['title'] ?? '');
        $message = (string)($row['message'] ?? '');
        $type = (string)($row['type'] ?? 'notification');
        $mobile = !empty($row['mobile']) ? (string)$row['mobile'] : null;
        $email = !empty($row['email']) ? (string)$row['email'] : null;
        $firstName = (string)($row['first_name'] ?? '');
        $lastName = (string)($row['last_name'] ?? '');

        if ($channel === 'sms') {
            if ($mobile === null || trim($mobile) === '') {
                db_prepared_execute($updStmt, 'ssi', ['failed', 'No mobile number on file', $nid]);
                $failed++;
                continue;
            }
            $norm = normalize_ph_mobile($mobile);
            if ($norm === false) {
                db_prepared_execute($updStmt, 'ssi', ['failed', 'Invalid mobile number format: ' . mask_mobile($mobile), $nid]);
                $failed++;
                continue;
            }
            $smsBody = trim($title) !== '' ? ($title . ': ' . $message) : $message;
            if (strlen($smsBody) > 1530) $smsBody = substr($smsBody, 0, 1527) . '...';
            $smsRes = @send_sms($norm, $smsBody);
            if (is_array($smsRes) && !empty($smsRes['ok'])) {
                $refPart = isset($smsRes['provider_ref']) && is_scalar($smsRes['provider_ref']) ? (' · ref: ' . $smsRes['provider_ref']) : '';
                db_prepared_execute($updStmt, 'ssi', ['sent', 'TextBee live SMS delivered to ' . mask_mobile($norm) . $refPart, $nid]);
                $sent++;
            } else {
                $err = is_array($smsRes) && isset($smsRes['error']) && is_string($smsRes['error']) ? $smsRes['error'] : 'unknown send_sms error';
                db_prepared_execute($updStmt, 'ssi', ['failed', 'TextBee SMS FAILED to ' . mask_mobile($norm) . ': ' . $err, $nid]);
                $failed++;
            }
        } elseif ($channel === 'email') {
            if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                db_prepared_execute($updStmt, 'ssi', ['failed', 'Invalid or missing email address', $nid]);
                $failed++;
                continue;
            }
            $toName = trim($firstName . ' ' . $lastName);
            $tags = ['basuraalert', 'notification', 'type_' . $type];
            $htmlBody = ba_build_notification_email_html($title, $message, $type);
            $emailRes = @ba_brevo_send_transactional($email, $toName, $title, $htmlBody, $tags);
            if (is_array($emailRes) && !empty($emailRes['ok'])) {
                db_prepared_execute($updStmt, 'ssi', ['sent', 'Brevo live email delivered to ' . mask_email($email), $nid]);
                $sent++;
            } else {
                $err = is_array($emailRes) && isset($emailRes['error']) && is_string($emailRes['error']) ? $emailRes['error'] : 'unknown brevo send error';
                db_prepared_execute($updStmt, 'ssi', ['failed', 'Brevo email FAILED to ' . mask_email($email) . ': ' . $err, $nid]);
                $failed++;
            }
        } else {
            $skipped++;
        }
    }

    $updStmt->close();
    return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}

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

function ba_save_report_rating(?mysqli $db, int $reportId, int $userId, int $rating, ?string $comment = null): bool
{
    if (!($db instanceof mysqli)) return false;
    if ($rating < 1) $rating = 1;
    if ($rating > 5) $rating = 5;
    $chk = $db->prepare('SELECT id FROM ba_report_ratings WHERE report_id = ? AND user_id = ? LIMIT 1');
    if ($chk) {
        db_prepared_execute($chk, 'ii', [$reportId, $userId]);
        $res = $chk->get_result();
        $has = $res ? (bool) $res->fetch_assoc() : false;
        $chk->close();
        if ($has) {
            $stmt = $db->prepare('UPDATE ba_report_ratings SET rating = ?, comment = ?, rated_at = CURRENT_TIMESTAMP WHERE report_id = ? AND user_id = ?');
            if (!$stmt) return false;
            db_prepared_execute($stmt, 'isii', [$rating, $comment, $reportId, $userId]);
            $stmt->close();
            return true;
        }
    }
    $stmt = $db->prepare('INSERT INTO ba_report_ratings (report_id, user_id, rating, comment) VALUES (?,?,?,?)');
    if (!$stmt) return false;
    db_prepared_execute($stmt, 'iiis', [$reportId, $userId, $rating, $comment]);
    $stmt->close();
    return true;
}

function ba_get_report_rating(?mysqli $db, int $reportId): ?array
{
    if (!($db instanceof mysqli)) return null;
    $stmt = $db->prepare('SELECT rating, comment, rated_at FROM ba_report_ratings WHERE report_id = ? ORDER BY rated_at DESC LIMIT 1');
    if (!$stmt) return null;
    db_prepared_execute($stmt, 'i', [$reportId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

function ba_get_feedback_list(?mysqli $db, ?string $kind = null, ?bool $repliedOnly = null, int $limit = 500): array
{
    if (!($db instanceof mysqli)) return [];
    $where = [];
    $params = [];
    $types = '';
    if ($kind !== null && $kind !== '') {
        $where[] = 'f.kind = ?';
        $params[] = $kind;
        $types .= 's';
    }
    if ($repliedOnly === true) $where[] = 'f.replied_at IS NOT NULL';
    elseif ($repliedOnly === false) $where[] = 'f.replied_at IS NULL';
    $sql = 'SELECT f.*, CONCAT(u.first_name, \' \', u.last_name) user_full_name, u.email user_email, u.mobile user_mobile
            FROM ba_feedback f LEFT JOIN users u ON u.id = f.user_id';
    if (count($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY f.id DESC LIMIT ' . $limit;
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

function ba_push_notification_via_prefs(?mysqli $db, int $userId, string $type, string $title, string $message, array $ref = []): int
{
    if (!($db instanceof mysqli)) return 0;
    $prefs = ba_get_user_prefs($db, $userId);
    $channels = [];
    foreach (['in_app','email','sms'] as $ch) {
        $globalKey = 'enable_' . $ch . '_reminders';
        if (empty($prefs[$globalKey])) continue;
        $eventKey = 'ev_' . $type . '_' . $ch;
        if (isset($prefs[$eventKey]) && (int)$prefs[$eventKey] !== 1) continue;
        $channels[] = $ch;
    }
    if (count($channels) === 0) $channels[] = 'in_app';
    $last = 0;
    foreach ($channels as $ch) {
        $last = ba_push_notification($db, $userId, $type, $title, $message, $ch, $ref);
    }
    return $last;
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

function ba_list_faqs(?mysqli $db, string $q = '', ?string $category = null): array
{
    if (!($db instanceof mysqli)) return [];
    $where = ["f.status = 'Published'"];
    $params = [];
    $types = '';
    if ($q !== '') {
        $where[] = '(f.question LIKE ? OR f.answer LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like;
        $types .= 'ss';
    }
    if ($category !== null && $category !== '') {
        $where[] = 'f.category = ?';
        $params[] = $category;
        $types .= 's';
    }
    $sql = 'SELECT f.* FROM ba_faqs f WHERE ' . implode(' AND ', $where) . ' ORDER BY f.sort_order ASC, f.id ASC';
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

function ba_create_feedback(?mysqli $db, array $payload): int
{
    if (!($db instanceof mysqli)) return 0;
    $userId = !empty($payload['user_id']) ? (int) $payload['user_id'] : null;
    $kind = (string) $payload['kind'];
    $subject = (string) $payload['subject'];
    $message = (string) $payload['message'];
    $photosJson = isset($payload['photos_json']) ? (string) $payload['photos_json'] : null;
    if ($photosJson !== null && trim($photosJson) === '') $photosJson = null;
    if ($photosJson !== null) {
        $stmt = $db->prepare('INSERT INTO ba_feedback (user_id, kind, subject, message, photos_json) VALUES (?,?,?,?,?)');
        if (!$stmt) return 0;
        $uid = $userId > 0 ? $userId : null;
        db_prepared_execute($stmt, 'issss', [$uid, $kind, $subject, $message, $photosJson]);
    } else {
        $stmt = $db->prepare('INSERT INTO ba_feedback (user_id, kind, subject, message) VALUES (?,?,?,?)');
        if (!$stmt) return 0;
        $uid = $userId > 0 ? $userId : null;
        db_prepared_execute($stmt, 'isss', [$uid, $kind, $subject, $message]);
    }
    $newId = (int) ($db->insert_id ?? 0);
    $stmt->close();
    return $newId;
}

function ba_get_user_prefs(?mysqli $db, int $userId): array
{
    if (!($db instanceof mysqli)) return [
        'enable_email_reminders' => 1,
        'enable_sms_reminders' => 1,
        'enable_in_app_reminders' => 1,
        'reminder_hours_before' => 12,
    ];
    $stmt = $db->prepare('SELECT * FROM ba_notification_preferences WHERE user_id = ? LIMIT 1');
    if (!$stmt) return [
        'enable_email_reminders' => 1,
        'enable_sms_reminders' => 1,
        'enable_in_app_reminders' => 1,
        'reminder_hours_before' => 12,
    ];
    db_prepared_execute($stmt, 'i', [$userId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $default = [
        'enable_email_reminders' => 1,
        'enable_sms_reminders' => 1,
        'enable_in_app_reminders' => 1,
        'reminder_hours_before' => 12,
    ];
    foreach (['reminder','schedule_change','announcement','report_submit','report_update','feedback_reply'] as $ev) {
        foreach (['in_app','email','sms'] as $ch) {
            $default['ev_' . $ev . '_' . $ch] = 1;
        }
    }
    if (is_array($row)) {
        return array_replace($default, array_filter($row, static fn($v) => $v !== null));
    }
    return $default;
}

function ba_ensure_user_prefs(?mysqli $db, int $userId): void
{
    if (!($db instanceof mysqli)) return;
    $stmt = $db->prepare('INSERT IGNORE INTO ba_notification_preferences (user_id) VALUES (?)');
    if (!$stmt) return;
    db_prepared_execute($stmt, 'i', [$userId]);
    $stmt->close();
}

function ba_save_user_prefs(?mysqli $db, int $userId, array $payload): bool
{
    if (!($db instanceof mysqli)) return false;
    ba_ensure_user_prefs($db, $userId);
    $updates = [
        'enable_email_reminders'  => !empty($payload['enable_email_reminders']) ? 1 : 0,
        'enable_sms_reminders'    => !empty($payload['enable_sms_reminders']) ? 1 : 0,
        'enable_in_app_reminders' => !empty($payload['enable_in_app_reminders']) ? 1 : 0,
        'reminder_hours_before'   => min(72, max(1, (int) ($payload['reminder_hours_before'] ?? 12))),
    ];
    $eventMap = [];
    foreach (['reminder','schedule_change','announcement','report_submit','report_update','feedback_reply'] as $ev) {
        foreach (['in_app','email','sms'] as $ch) {
            $key = 'ev_' . $ev . '_' . $ch;
            $eventMap[$key] = isset($payload[$key]) ? 1 : 0;
        }
    }
    $all = array_replace($eventMap, $updates);
    $fields = [];
    $params = [];
    $types = '';
    foreach ($all as $col => $val) {
        $fields[] = $col . ' = ?';
        $params[] = $val;
        $types .= is_int($val) ? 'i' : 's';
    }
    $params[] = $userId;
    $types .= 'i';
    $sql = 'UPDATE ba_notification_preferences SET ' . implode(', ', $fields) . ' WHERE user_id = ?';
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    db_prepared_execute($stmt, $types, $params);
    $stmt->close();
    return true;
}

function ba_reminder_due_collections(?mysqli $db, int $hoursBefore = 12): array
{
    if (!($db instanceof mysqli)) return [];
    $today = date('Y-m-d');
    $now = time();
    $schedules = ba_list_schedules($db);
    $due = [];
    foreach ($schedules as $s) {
        $type = (string) ($s['schedule_type'] ?? 'regular');
        $dateStr = null;
        if ($type === 'one_time' || $type === 'exception') {
            if (!empty($s['collection_date']) && strtotime($s['collection_date']) !== false) {
                $dateStr = $s['collection_date'];
            }
        } else {
            $dow = (int) date('N', $now) + 1;
            if ($dow > 7) $dow = 1;
            $targetDow = (int) $s['day_of_week'];
            if ($targetDow < 1 || $targetDow > 7) continue;
            $offset = $targetDow - $dow;
            if ($offset < 0) $offset += 7;
            $dateStr = date('Y-m-d', $now + ($offset * 86400));
        }
        if ($dateStr === null) continue;
        $timeStr = $s['time_start'] ?? '06:00:00';
        $collTs = strtotime($dateStr . ' ' . $timeStr);
        if ($collTs === false) continue;
        $diffHours = ($collTs - $now) / 3600;
        if ($diffHours > 0 && $diffHours <= $hoursBefore) {
            $s['computed_next'] = $dateStr;
            $s['computed_hours_until'] = (int) round($diffHours);
            $due[] = $s;
        }
    }
    return $due;
}

function ba_storage_fixture_path(string $category, string $name): string
{
    $base = defined('STORAGE_DIR') ? STORAGE_DIR : (__DIR__ . '/../storage');
    $catMap = [
        'weather' => 'mock_weather',
        'holidays' => 'mock_holidays',
        'geocoding' => 'mock_geocoding',
        'images' => 'mock_images',
        'emails' => 'mock_emails',
        'cache' => 'cache',
        'fixtures' => 'fixtures',
    ];
    $sub = $catMap[$category] ?? 'cache';
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $name;
}

function ba_load_json_fixture(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    // Mock fixture JSON may embed dynamic-date placeholders; resolve before decoding.
    $raw = strtr((string)$raw, [
        '__DATE_TODAY__'    => date('Y-m-d'),
        '__DATE_TOMORROW__' => date('Y-m-d', strtotime('+1 day')),
    ]);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function ba_calendarific_fetch_ph_holidays(int $year): array
{
    $apiKey = (string)(defined('CALENDARIFIC_API_KEY') ? CALENDARIFIC_API_KEY : '');
    if ($apiKey === '' || $year < 2000 || $year > 2100) {
        return ['ok' => false, 'error' => ($apiKey === '' ? 'Calendarific API key missing.' : 'Year out of range (2000-2100).'), 'provider' => 'calendarific'];
    }
    $url = 'https://calendarific.com/api/v2/holidays?api_key=' . urlencode($apiKey) . '&country=PH&year=' . $year . '&type=national,local,observance';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Marikina-E-Concern/1.0 (BasuraAlert; PHP-cURL)'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_status' => $code, 'error' => ($err !== '' ? $err : 'Empty response from Calendarific.'), 'provider' => 'calendarific'];
    }
    $data = @json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && isset($data['meta']['error_type']) ? (string)$data['meta']['error_type'] : (is_array($data) && isset($data['error']) ? (string)$data['error'] : 'HTTP ' . $code);
        return ['ok' => false, 'http_status' => $code, 'error' => 'Calendarific API error: ' . $msg, 'provider' => 'calendarific', 'raw' => substr($raw, 0, 400)];
    }
    if (!is_array($data) || !isset($data['response']['holidays']) || !is_array($data['response']['holidays'])) {
        return ['ok' => false, 'http_status' => $code, 'error' => 'Calendarific response missing response.holidays array.', 'provider' => 'calendarific'];
    }
    $out = [];
    foreach ($data['response']['holidays'] as $h) {
        $iso = is_array($h) && isset($h['date']['iso']) ? (string)$h['date']['iso'] : '';
        if ($iso === '' || strlen($iso) < 10) continue;
        $date = substr($iso, 0, 10);
        $name = is_array($h) && isset($h['name']) ? trim((string)$h['name']) : '';
        if ($name === '') continue;
        $rawType = is_array($h) && isset($h['type'][0]) ? (string)$h['type'][0] : '';
        $notes = '';
        if (is_array($h) && isset($h['description']) && is_string($h['description']) && trim($h['description']) !== '') {
            $notes = trim($h['description']);
        }
        $typeMap = [
            'National holiday' => 'Regular Holiday',
            'Regional holiday' => 'Regular Holiday',
            'Local holiday' => 'Special Non-Working Day',
            'Public holiday' => 'Regular Holiday',
            'Observance' => 'Observance',
            'Season' => 'Observance',
            'Christian' => 'Observance',
            'Muslim' => 'Observance',
        ];
        $type = $typeMap[$rawType] ?? ($rawType !== '' ? $rawType : 'Holiday');
        $out[] = ['date' => $date, 'name' => $name, 'type' => $type, 'notes' => $notes];
    }
    usort($out, static function ($a, $b) {
        return strcmp((string)$a['date'], (string)$b['date']);
    });
    return [
        'ok' => true,
        'http_status' => $code,
        'provider' => 'calendarific_live',
        'phase2_live' => true,
        'year' => $year,
        'raw_count' => count($data['response']['holidays']),
        'parsed_count' => count($out),
        'holidays' => $out,
    ];
}

function ba_fetch_ph_holidays_mock(int $year = 0): array
{
    if ($year <= 0) $year = (int) date('Y');
    $fixture = defined('MOCK_HOLIDAY_FIXTURE') ? (string) MOCK_HOLIDAY_FIXTURE : 'split-2026';
    $explicitMockModes = ['empty', 'error-api-down'];
    if (in_array($fixture, $explicitMockModes, true)) {
        if ($fixture === 'error-api-down') {
            return ['ok' => false, 'error' => 'MOCK: Calendarific / Holiday API returned 500 (fixture=error-api-down)', 'fixture' => $fixture];
        }
        if ($fixture === 'empty') {
            return ['ok' => true, 'fixture' => $fixture, 'year' => $year, 'holidays' => []];
        }
    }
    $hasLiveCreds = (defined('CALENDARIFIC_API_KEY') && CALENDARIFIC_API_KEY !== '');
    if ($hasLiveCreds) {
        $live = ba_calendarific_fetch_ph_holidays($year);
        if (!empty($live['ok'])) {
            return [
                'ok' => true,
                'provider' => 'calendarific_live',
                'phase2_live' => true,
                'fixture' => $fixture . '+live_override',
                'year' => $year,
                'holidays' => $live['holidays'],
                'http_status' => (int)($live['http_status'] ?? 200),
                'parsed_count' => (int)($live['parsed_count'] ?? 0),
            ];
        }
    }
    $data = ba_load_json_fixture(ba_storage_fixture_path('holidays', $fixture . '.json'));
    if ($data === null) {
        $holidays = [
            ['date' => $year . '-01-01', 'name' => "New Year's Day", 'type' => 'Regular Holiday'],
            ['date' => $year . '-02-25', 'name' => 'EDSA Revolution Anniversary', 'type' => 'Special Non-Working Day'],
            ['date' => $year . '-04-09', 'name' => 'Araw ng Kagitingan (Day of Valor)', 'type' => 'Regular Holiday'],
            ['date' => $year . '-04-10', 'name' => 'Maundy Thursday (Movable)', 'type' => 'Regular Holiday', 'notes' => 'Movable: verify official proclamation before publishing.'],
            ['date' => $year . '-04-11', 'name' => 'Good Friday (Movable)', 'type' => 'Regular Holiday', 'notes' => 'Movable: verify official proclamation before publishing.'],
            ['date' => $year . '-05-01', 'name' => 'Labor Day', 'type' => 'Regular Holiday'],
            ['date' => $year . '-06-12', 'name' => 'Independence Day', 'type' => 'Regular Holiday'],
            ['date' => $year . '-08-25', 'name' => 'National Heroes Day (last Monday of Aug)', 'type' => 'Regular Holiday', 'notes' => 'Movable: verify exact date.'],
            ['date' => $year . '-11-01', 'name' => "All Saints' Day", 'type' => 'Special Non-Working Day'],
            ['date' => $year . '-11-30', 'name' => 'Bonifacio Day', 'type' => 'Regular Holiday'],
            ['date' => $year . '-12-25', 'name' => 'Christmas Day', 'type' => 'Regular Holiday'],
            ['date' => $year . '-12-30', 'name' => 'Rizal Day', 'type' => 'Regular Holiday'],
            ['date' => $year . '-12-31', 'name' => "New Year's Eve (Last Day of the Year)", 'type' => 'Special Non-Working Day'],
        ];
        $ret = ['ok' => true, 'fixture' => $fixture . '+local_fallback', 'year' => $year, 'holidays' => $holidays];
        if (isset($live) && is_array($live) && isset($live['error'])) $ret['live_failed_reason'] = (string)$live['error'];
        return $ret;
    }
    $data['ok'] = true;
    $data['fixture'] = $fixture . '+json_fallback';
    $data['year'] = $year;
    if (isset($live) && is_array($live) && isset($live['error'])) $data['live_failed_reason'] = (string)$live['error'];
    return $data;
}

function ba_openweather_fetch_current(float $lat, float $lon): array
{
    $apiKey = (string)(defined('OPENWEATHER_API_KEY') ? OPENWEATHER_API_KEY : '');
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'OpenWeather API key missing.', 'provider' => 'openweather'];
    }
    if ($lat < 5.0 || $lat > 20.0 || $lon < 115.0 || $lon > 130.0) {
        return ['ok' => false, 'error' => 'Coords out of PH bounds. Lat must be 5-20, Lon 115-130.', 'provider' => 'openweather'];
    }
    $url = 'https://api.openweathermap.org/data/2.5/weather?lat=' . $lat . '&lon=' . $lon . '&appid=' . urlencode($apiKey) . '&units=metric&lang=en';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Marikina-E-Concern/1.0 (BasuraAlert; weather lookup)'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_status' => $code, 'error' => ($err !== '' ? $err : 'Empty response from OpenWeather.'), 'provider' => 'openweather'];
    }
    $data = @json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && isset($data['message']) ? (string)$data['message'] : 'HTTP ' . $code;
        return ['ok' => false, 'http_status' => $code, 'error' => 'OpenWeather API error: ' . $msg, 'provider' => 'openweather', 'raw' => substr($raw, 0, 400)];
    }
    if (!is_array($data)) {
        return ['ok' => false, 'http_status' => $code, 'error' => 'Invalid JSON from OpenWeather.', 'provider' => 'openweather'];
    }
    $temp_c = isset($data['main']['temp']) ? round((float)$data['main']['temp'], 1) : 0.0;
    $feels_like_c = isset($data['main']['feels_like']) ? round((float)$data['main']['feels_like'], 1) : 0.0;
    $humidity_pct = isset($data['main']['humidity']) ? (int)$data['main']['humidity'] : 0;
    $wind_kph = isset($data['wind']['speed']) ? round((float)$data['wind']['speed'] * 3.6, 1) : 0.0;
    $rawCondition = is_array($data['weather'][0] ?? null) ? trim((string)($data['weather'][0]['main'] ?? 'Unknown')) : 'Unknown';
    $rawDesc = is_array($data['weather'][0] ?? null) ? trim((string)($data['weather'][0]['description'] ?? '')) : '';
    $iconCode = is_array($data['weather'][0] ?? null) ? (string)($data['weather'][0]['icon'] ?? '') : '';
    $iconMap = ['01d' => '☀️', '01n' => '🌙', '02d' => '⛅', '02n' => '☁️', '03d' => '☁️', '03n' => '☁️', '04d' => '☁️', '04n' => '☁️', '09d' => '🌧️', '09n' => '🌧️', '10d' => '🌦️', '10n' => '🌧️', '11d' => '⛈️', '11n' => '⛈️', '13d' => '❄️', '13n' => '❄️', '50d' => '🌫️', '50n' => '🌫️'];
    $icon = $iconMap[$iconCode] ?? '🌤️';
    $advisory = 'Normal collection conditions.';
    if (stripos($rawCondition . ' ' . $rawDesc, 'typhoon') !== false || stripos($rawCondition . ' ' . $rawDesc, 'hurricane') !== false || $wind_kph >= 60) {
        $advisory = 'ADVISORY: High winds detected — outdoor collection crews may stand down; expect schedule delays.';
    } elseif (stripos($rawCondition . ' ' . $rawDesc, 'thunderstorm') !== false) {
        $advisory = 'ADVISORY: Thunderstorms — collection may be delayed for crew safety.';
    } elseif (stripos($rawCondition . ' ' . $rawDesc, 'rain') !== false || stripos($rawCondition . ' ' . $rawDesc, 'drizzle') !== false) {
        $advisory = 'ADVISORY: Rainy conditions — outdoor collection may be delayed.';
    } elseif ($humidity_pct >= 90 && $temp_c >= 32) {
        $advisory = 'ADVISORY: Extreme heat & humidity — extended crew rest breaks recommended.';
    }
    $city = is_array($data) && isset($data['name']) && $data['name'] !== '' ? (string)$data['name'] : null;
    $forecast = [];
    for ($i = 0; $i < 2; $i++) {
        $d = date('Y-m-d', time() + ($i * 86400));
        $maxC = round($temp_c + ($i === 0 ? 2.0 : 1.5), 1);
        $minC = round($temp_c - 4.0, 1);
        $cond = $rawCondition;
        $rain = (stripos($rawCondition, 'Rain') !== false || stripos($rawCondition, 'Thunderstorm') !== false || stripos($rawCondition, 'Drizzle') !== false) ? 80 : 20;
        $forecast[] = ['date' => $d, 'max_c' => $maxC, 'min_c' => $minC, 'condition' => $cond, 'rain_chance_pct' => $rain];
    }
    return [
        'ok' => true,
        'http_status' => $code,
        'provider' => 'openweather_live',
        'phase2_live' => true,
        'lat' => $lat,
        'lon' => $lon,
        'city_resolved' => $city,
        'fetched_at' => date('c'),
        'current' => [
            'temp_c' => $temp_c,
            'feels_like_c' => $feels_like_c,
            'humidity_pct' => $humidity_pct,
            'wind_kph' => $wind_kph,
            'condition' => $rawCondition . ($rawDesc !== '' ? ' — ' . ucfirst($rawDesc) : ''),
            'advisory' => $advisory,
            'icon' => $icon,
        ],
        'forecast_days' => $forecast,
    ];
}

function ba_fetch_weather_mock(?string $city = 'Marikina', ?string $country = 'PH'): array
{
    $fixture = defined('MOCK_WEATHER_FIXTURE') ? (string) MOCK_WEATHER_FIXTURE : 'sunny-2-day';
    $explicitMockModes = ['error-unreachable'];
    if (in_array($fixture, $explicitMockModes, true)) {
        return ['ok' => false, 'error' => 'MOCK: Weather API endpoint unreachable (fixture=error-unreachable)', 'fixture' => $fixture];
    }
    $hasLiveCreds = (defined('OPENWEATHER_API_KEY') && OPENWEATHER_API_KEY !== '');
    if ($hasLiveCreds) {
        $coordDefaults = [
            'Marikina' => ['lat' => 14.6247, 'lon' => 121.0993],
            'Manila'   => ['lat' => 14.5995, 'lon' => 120.9842],
            'Quezon City' => ['lat' => 14.6760, 'lon' => 121.0437],
        ];
        $lookup = is_string($city) ? $city : 'Marikina';
        $c = $coordDefaults[$lookup] ?? $coordDefaults['Marikina'];
        $live = ba_openweather_fetch_current((float)$c['lat'], (float)$c['lon']);
        if (!empty($live['ok'])) {
            return [
                'ok' => true,
                'provider' => 'openweather_live',
                'phase2_live' => true,
                'fixture' => $fixture . '+live_override',
                'city' => (string)($live['city_resolved'] ?? $city ?? 'Marikina'),
                'country' => $country ?? 'PH',
                'fetched_at' => $live['fetched_at'] ?? date('c'),
                'current' => $live['current'],
                'forecast_days' => $live['forecast_days'],
                'http_status' => (int)($live['http_status'] ?? 200),
                'coords' => ['lat' => $c['lat'], 'lon' => $c['lon']],
            ];
        }
    }
    $data = ba_load_json_fixture(ba_storage_fixture_path('weather', $fixture . '.json'));
    if ($data === null) {
        $fallback = [
            'ok' => true,
            'fixture' => $fixture . '+local_fallback',
            'city' => $city ?? 'Marikina',
            'country' => $country ?? 'PH',
            'fetched_at' => date('c'),
            'current' => [
                'temp_c' => $fixture === 'typhoon-signal3' ? 23 : 30,
                'feels_like_c' => $fixture === 'typhoon-signal3' ? 22 : 33,
                'humidity_pct' => $fixture === 'typhoon-signal3' ? 92 : 68,
                'wind_kph' => $fixture === 'typhoon-signal3' ? 68 : 10,
                'condition' => $fixture === 'sunny-2-day' ? 'Partly cloudy' : ($fixture === 'typhoon-signal3' ? 'Typhoon — Signal #3' : 'Heavy rain'),
                'advisory' => $fixture === 'typhoon-signal3'
                    ? 'ADVISORY: Typhoon Signal #3 in effect. Collection crews stand down; expect schedule postponements.'
                    : ($fixture === 'storm-rainy' ? 'ADVISORY: Heavy rain — outdoor collection may be delayed.' : 'Normal collection conditions.'),
                'icon' => $fixture === 'sunny-2-day' ? '☀️' : ($fixture === 'typhoon-signal3' ? '🌀' : '🌧️'),
            ],
            'forecast_days' => [
                [
                    'date' => date('Y-m-d'),
                    'max_c' => $fixture === 'typhoon-signal3' ? 25 : 33,
                    'min_c' => $fixture === 'typhoon-signal3' ? 21 : 25,
                    'condition' => $fixture === 'sunny-2-day' ? 'Sunny' : ($fixture === 'typhoon-signal3' ? 'Typhoon winds' : 'Rain'),
                    'rain_chance_pct' => $fixture === 'sunny-2-day' ? 10 : 90,
                ],
                [
                    'date' => date('Y-m-d', time() + 86400),
                    'max_c' => $fixture === 'typhoon-signal3' ? 26 : 32,
                    'min_c' => $fixture === 'typhoon-signal3' ? 22 : 26,
                    'condition' => $fixture === 'sunny-2-day' ? 'Partly cloudy' : 'Showers',
                    'rain_chance_pct' => $fixture === 'sunny-2-day' ? 30 : 75,
                ],
            ],
        ];
        if (isset($live) && is_array($live) && isset($live['error'])) $fallback['live_failed_reason'] = (string)$live['error'];
        return $fallback;
    }
    $data['ok'] = true;
    $data['fixture'] = $fixture . '+json_fallback';
    if (isset($live) && is_array($live) && isset($live['error'])) $data['live_failed_reason'] = (string)$live['error'];
    return $data;
}

function ba_osm_nominatim_useragent(): string
{
    $from = (defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '') ? (string) MAIL_FROM_ADDRESS : 'admin@localhost';
    return 'Marikina-E-Concern/1.0 (BasuraAlert Submodule; contact=' . $from . ') — OSM Nominatim Usage Policy Compliant';
}

function ba_osm_nominatim_throttle(): void
{
    static $lastRequestAt = null;
    $minIntervalUs = 1100000;
    if ($lastRequestAt !== null) {
        $elapsedUs = (int) ((microtime(true) - $lastRequestAt) * 1000000);
        if ($elapsedUs < $minIntervalUs) {
            usleep($minIntervalUs - $elapsedUs);
        }
    }
    $lastRequestAt = microtime(true);
}

function ba_geocode_search(string $query, string $cityLock = 'Marikina'): array
{
    $q = trim($query);
    if ($q === '') {
        return ['ok' => false, 'error' => 'Empty search query.'];
    }
    $search = $q;
    if ($cityLock !== '' && stripos($search, $cityLock) === false) {
        $search .= ', ' . trim($cityLock) . ', Metro Manila, Philippines';
    }
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $search,
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'limit' => 10,
        'countrycodes' => 'ph',
        'accept-language' => 'en,fil',
    ], '', '&');
    ba_osm_nominatim_throttle();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . ba_osm_nominatim_useragent(),
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_status' => $code, 'error' => ($err !== '' ? $err : 'Empty response from OSM Nominatim.')];
    }
    $arr = @json_decode($raw, true);
    if (!is_array($arr)) {
        return ['ok' => false, 'http_status' => $code, 'error' => 'Nominatim returned non-JSON response.', 'raw' => substr($raw, 0, 300)];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'http_status' => $code, 'error' => 'Nominatim returned HTTP ' . $code . ': ' . (is_array($arr) && isset($arr['error']) ? (string) $arr['error'] : substr($raw, 0, 200))];
    }
    $results = [];
    foreach ($arr as $f) {
        if (!is_array($f)) continue;
        $lat = (float) ($f['lat'] ?? 0);
        $lon = (float) ($f['lon'] ?? 0);
        if ($lat === 0.0 || $lon === 0.0) continue;
        $addr = $f['address'] ?? [];
        $brgy = (string) (($addr['quarter'] ?? $addr['neighbourhood'] ?? $addr['suburb'] ?? $addr['borough'] ?? ''));
        if ($brgy === '' && isset($f['display_name'])) {
            $parts = array_map('trim', explode(',', (string) $f['display_name']));
            foreach ($parts as $p) {
                if (stripos($p, 'barangay') !== false || stripos($p, 'brgy') !== false) { $brgy = trim($p); break; }
            }
        }
        $city = (string) ($addr['city'] ?? $addr['city_district'] ?? $addr['town'] ?? $addr['municipality'] ?? $cityLock);
        $results[] = [
            'display_name' => (string) ($f['display_name'] ?? ''),
            'name' => (string) ($f['name'] ?? ($f['display_name'] ?? '')),
            'barangay' => $brgy,
            'city' => $city,
            'lat' => $lat,
            'lon' => $lon,
            'osm_type' => (string) ($f['osm_type'] ?? $f['type'] ?? 'node'),
            'osm_id' => isset($f['osm_id']) ? (string) $f['osm_id'] : null,
            'class' => isset($f['class']) ? (string) $f['class'] : null,
            'type' => isset($f['type']) ? (string) $f['type'] : null,
            'place_id' => isset($f['place_id']) ? (string) $f['place_id'] : null,
        ];
    }
    return [
        'ok' => true,
        'provider' => 'osm_nominatim_live',
        'http_status' => $code,
        'query' => $query,
        'effective_query' => $search,
        'city_lock' => $cityLock,
        'phase2_live' => true,
        'results' => array_slice($results, 0, 10),
    ];
}

function ba_geocode_reverse(float $lat, float $lon): array
{
    if ($lat < 5 || $lat > 20 || $lon < 115 || $lon > 130) {
        return ['ok' => false, 'error' => 'Coordinates out of Philippines bounds.'];
    }
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => $lat,
        'lon' => $lon,
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'zoom' => 18,
        'accept-language' => 'en,fil',
    ], '', '&');
    ba_osm_nominatim_throttle();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . ba_osm_nominatim_useragent(),
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_status' => $code, 'error' => ($err !== '' ? $err : 'Empty response from OSM Nominatim.')];
    }
    $f = @json_decode($raw, true);
    if (!is_array($f)) {
        return ['ok' => false, 'http_status' => $code, 'error' => 'Nominatim reverse returned non-JSON response.', 'raw' => substr($raw, 0, 300)];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'http_status' => $code, 'error' => 'Nominatim reverse HTTP ' . $code . ': ' . (is_array($f) && isset($f['error']) ? (string) $f['error'] : substr($raw, 0, 200))];
    }
    $addr = $f['address'] ?? [];
    $brgy = (string) (($addr['quarter'] ?? $addr['neighbourhood'] ?? $addr['suburb'] ?? $addr['borough'] ?? ''));
    if ($brgy === '' && isset($f['display_name'])) {
        $parts = array_map('trim', explode(',', (string) $f['display_name']));
        foreach ($parts as $p) {
            if (stripos($p, 'barangay') !== false || stripos($p, 'brgy') !== false) { $brgy = trim($p); break; }
        }
    }
    $city = (string) ($addr['city'] ?? $addr['city_district'] ?? $addr['town'] ?? $addr['municipality'] ?? 'Marikina');
    return [
        'ok' => true,
        'provider' => 'osm_nominatim_live',
        'http_status' => $code,
        'phase2_live' => true,
        'lat' => $lat,
        'lon' => $lon,
        'nearest' => [
            'display_name' => (string) ($f['display_name'] ?? 'Marikina City, Metro Manila, Philippines'),
            'barangay' => $brgy,
            'city' => $city,
            'osm_id' => isset($f['osm_id']) ? (string) $f['osm_id'] : null,
            'place_id' => isset($f['place_id']) ? (string) $f['place_id'] : null,
            'address' => $addr,
        ],
    ];
}

function ba_geocode_search_mock(string $query, string $cityLock = 'Marikina'): array
{
    $mode = defined('MOCK_GEOCODING_MODE') ? (string) MOCK_GEOCODING_MODE : 'valid';
    $explicitMockModes = ['error', 'empty'];
    if (in_array($mode, $explicitMockModes, true)) {
        // keep explicit QA modes
    } else {
        $mode = 'live_nominatim';
    }
    if ($mode === 'error') {
        return ['ok' => false, 'error' => 'MOCK: OSM Nominatim endpoint error (mode=error)', 'mode' => $mode];
    }
    if ($mode === 'live_nominatim') {
        $live = ba_geocode_search($query, $cityLock);
        if (!empty($live['ok'])) return $live;
    }
    if ($mode === 'empty') {
        return ['ok' => true, 'mode' => $mode, 'results' => [], 'note' => 'MOCK: Empty result set — admin must manually enter barangay and address.'];
    }
    $fixture = ba_load_json_fixture(ba_storage_fixture_path('geocoding', 'marikina_streets.json'));
    $results = [];
    $q = mb_strtolower(trim($query));
    if (is_array($fixture) && isset($fixture['features']) && is_array($fixture['features'])) {
        foreach ($fixture['features'] as $f) {
            $hay = mb_strtolower(($f['properties']['display_name'] ?? '') . ' ' . ($f['properties']['name'] ?? ''));
            if ($q === '' || strpos($hay, $q) !== false) {
                $results[] = [
                    'display_name' => (string) ($f['properties']['display_name'] ?? ''),
                    'name' => (string) ($f['properties']['name'] ?? ''),
                    'barangay' => (string) ($f['properties']['barangay'] ?? ''),
                    'city' => (string) ($f['properties']['city'] ?? 'Marikina'),
                    'lat' => (float) ($f['geometry']['coordinates'][1] ?? 14.6247),
                    'lon' => (float) ($f['geometry']['coordinates'][0] ?? 121.0993),
                    'osm_type' => (string) ($f['properties']['type'] ?? 'residential'),
                ];
            }
        }
    }
    if (count($results) === 0) {
        $results[] = [
            'display_name' => trim($query . ', ' . $cityLock . ', Metro Manila, Philippines'),
            'name' => $query,
            'barangay' => '',
            'city' => $cityLock,
            'lat' => 14.6247,
            'lon' => 121.0993,
            'osm_type' => 'approximate',
            'note' => 'MOCK: fallback centroid — confirm barangay with resident before saving.',
        ];
    }
    return ['ok' => true, 'mode' => $mode, 'query' => $query, 'city_lock' => $cityLock, 'results' => array_slice($results, 0, 10)];
}

function ba_geocode_reverse_mock(float $lat, float $lon): array
{
    $mode = defined('MOCK_GEOCODING_MODE') ? (string) MOCK_GEOCODING_MODE : 'valid';
    $explicitMockModes = ['error'];
    if (in_array($mode, $explicitMockModes, true)) {
        // keep explicit QA modes
    } else {
        $mode = 'live_nominatim';
    }
    if ($mode === 'live_nominatim') {
        $live = ba_geocode_reverse($lat, $lon);
        if (!empty($live['ok'])) return $live;
    }
    if ($mode === 'error') {
        return ['ok' => false, 'error' => 'MOCK: OSM Nominatim reverse error (mode=error)', 'mode' => $mode];
    }
    return [
        'ok' => true,
        'mode' => $mode,
        'lat' => $lat,
        'lon' => $lon,
        'nearest' => [
            'display_name' => 'Approximate location within Marikina City, Metro Manila, Philippines',
            'barangay' => 'Barangka',
            'city' => 'Marikina',
            'note' => 'MOCK: reverse lookup centroid — always verify with the resident before saving.',
        ],
    ];
}

function ba_cloudinary_sign(array $params): string
{
    $secret = (string) (defined('CLOUDINARY_API_SECRET') ? CLOUDINARY_API_SECRET : '');
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        if (is_array($v)) {
            $parts[] = $k . '=' . implode(',', $v);
        } else {
            $parts[] = $k . '=' . (string) $v;
        }
    }
    $serialized = implode('&', $parts) . $secret;
    return hash('sha256', $serialized);
}

function ba_cloudinary_upload_file(string $localPath, string $publicIdPrefix = 'marikina_concern', array $options = []): array
{
    $cloud = (string) (defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : '');
    $apiKey = (string) (defined('CLOUDINARY_API_KEY') ? CLOUDINARY_API_KEY : '');
    $secret = (string) (defined('CLOUDINARY_API_SECRET') ? CLOUDINARY_API_SECRET : '');
    $base = (string) (defined('CLOUDINARY_BASE_URL') ? CLOUDINARY_BASE_URL : '');
    if ($cloud === '' || $apiKey === '' || $secret === '' || $base === '' || !is_file($localPath)) {
        return ['ok' => false, 'error' => 'Cloudinary credentials missing or file not found.'];
    }
    $timestamp = time();
    $publicId = null;
    if ($publicIdPrefix && strlen($publicIdPrefix) > 0) {
        $publicId = $publicIdPrefix . '_' . substr(bin2hex(random_bytes(5)), 0, 10);
    }
    $params = [
        'timestamp' => $timestamp,
        'folder' => $options['folder'] ?? 'marikina_concern/uploads',
        'overwrite' => filter_var($options['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
        'type' => $options['upload_type'] ?? 'upload',
    ];
    if ($publicId !== null) $params['public_id'] = $publicId;
    $params['signature'] = ba_cloudinary_sign($params);
    $params['api_key'] = $apiKey;
    $fields = [];
    foreach ($params as $k => $v) $fields[$k] = $v;
    $ch = curl_init();
    if (PHP_VERSION_ID >= 50500 && class_exists('CURLFile', false)) {
        $fields['file'] = new CURLFile($localPath);
    } else {
        $fields['file'] = '@' . $localPath;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $base . '/image/upload',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_status' => $code, 'error' => ($err !== '' ? $err : 'Empty response from Cloudinary.')];
    }
    $data = @json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http_status' => $code, 'error' => 'Cloudinary returned non-JSON response: ' . substr($raw, 0, 200)];
    }
    if (isset($data['error']) && is_array($data['error'])) {
        return ['ok' => false, 'http_status' => $code, 'error' => (string) ($data['error']['message'] ?? 'Cloudinary upload error.')];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'http_status' => $code, 'error' => 'Cloudinary upload failed: HTTP ' . $code . ' - ' . substr($raw, 0, 200)];
    }
    return [
        'ok' => true,
        'http_status' => $code,
        'provider' => 'cloudinary',
        'provider_public_id' => (string) ($data['public_id'] ?? ''),
        'bytes' => (int) ($data['bytes'] ?? 0),
        'width' => isset($data['width']) ? (int) $data['width'] : null,
        'height' => isset($data['height']) ? (int) $data['height'] : null,
        'mime' => (string) ($data['resource_type'] ?? 'image') . '/' . ((string) ($data['format'] ?? 'jpg')),
        'format' => (string) ($data['format'] ?? ''),
        'secure_url' => (string) ($data['secure_url'] ?? ''),
        'public_id' => (string) ($data['public_id'] ?? ''),
        'version' => isset($data['version']) ? (string) $data['version'] : null,
        'signature' => (string) ($data['signature'] ?? ''),
    ];
}

function ba_store_image_mock(array $uploadedFile): array
{
    $mode = defined('MOCK_IMAGE_STORAGE_MODE') ? (string) MOCK_IMAGE_STORAGE_MODE : 'local_disk';
    $explicitMockModes = ['fail_upload', 'quota_exceeded', 'slow_upload'];
    if (in_array($mode, $explicitMockModes, true)) {
        // keep explicit test modes
    } else {
        $hasCloudCreds = (defined('CLOUDINARY_CLOUD_NAME') && defined('CLOUDINARY_API_KEY') && defined('CLOUDINARY_API_SECRET')
            && CLOUDINARY_CLOUD_NAME !== '' && CLOUDINARY_API_KEY !== '' && CLOUDINARY_API_SECRET !== '');
        $mode = $hasCloudCreds ? 'cloudinary' : 'local_disk';
    }
    if ($mode === 'fail_upload') {
        return ['ok' => false, 'error' => 'MOCK: Cloudinary upload failed — network error (mode=fail_upload)', 'mode' => $mode];
    }
    if ($mode === 'quota_exceeded') {
        return ['ok' => false, 'error' => 'MOCK: Cloudinary storage quota exceeded (mode=quota_exceeded)', 'mode' => $mode];
    }
    if ($mode === 'slow_upload') {
        usleep(1500000);
    }
    $tmp = (string) ($uploadedFile['tmp_name'] ?? '');
    $orig = (string) ($uploadedFile['name'] ?? 'evidence_' . date('YmdHis') . '.jpg');
    $size = (int) ($uploadedFile['size'] ?? 0);
    $err = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload PHP error code ' . $err, 'mode' => $mode];
    }
    if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
        return ['ok' => false, 'error' => 'No uploaded file found.', 'mode' => $mode];
    }
    $id = 'ba_evidence_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(6)), 0, 8);
    $ext = strtolower((string) pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) $ext = 'jpg';
    $relDir = 'mock_images';
    $fullDir = ba_storage_fixture_path('images', '');
    if (!is_dir($fullDir)) @mkdir($fullDir, 0755, true);
    $fileName = $id . '.' . $ext;
    $dest = $fullDir . $fileName;
    $moved = false;
    if (is_uploaded_file($tmp)) {
        $moved = @move_uploaded_file($tmp, $dest);
    }
    if (!$moved) {
        if (@copy($tmp, $dest)) {
            $moved = true;
        } else {
            $content = @file_get_contents($tmp);
            if ($content !== false) @file_put_contents($dest, $content);
            $moved = is_file($dest);
        }
    }
    if (!$moved) {
        return ['ok' => false, 'error' => 'MOCK: failed to persist file to storage/' . $relDir . '.', 'mode' => $mode];
    }
    if ($mode === 'local_disk') {
        $url = app_url('/storage/' . $relDir . '/' . rawurlencode($fileName));
        return [
            'ok' => true,
            'mode' => $mode,
            'provider_public_id' => $id,
            'original_name' => $orig,
            'bytes' => $size,
            'mime' => (string) ($uploadedFile['type'] ?? ('image/' . $ext)),
            'width' => null,
            'height' => null,
            'secure_url' => $url,
            'local_path' => $dest,
        ];
    }
    $upload = ba_cloudinary_upload_file($dest, $id, ['folder' => 'marikina_concern/evidence']);
    if ($upload['ok']) {
        return [
            'ok' => true,
            'mode' => 'cloudinary',
            'provider_public_id' => (string) ($upload['provider_public_id'] ?? $id),
            'original_name' => $orig,
            'bytes' => (int) ($upload['bytes'] ?? $size),
            'mime' => (string) ($upload['mime'] ?? ($uploadedFile['type'] ?? ('image/' . $ext))),
            'width' => $upload['width'] ?? null,
            'height' => $upload['height'] ?? null,
            'secure_url' => (string) ($upload['secure_url'] ?? ''),
            'local_path' => $dest,
            'provider' => 'cloudinary',
            'signature' => $upload['signature'] ?? null,
            'http_status' => $upload['http_status'] ?? null,
            'phase2_live' => true,
            'note' => 'Phase 2 L1 Cloudinary live upload — delivered via CDN secure_url. Local file kept as fallback.',
        ];
    }
    $url = app_url('/storage/' . $relDir . '/' . rawurlencode($fileName));
    return [
        'ok' => true,
        'mode' => 'local_disk',
        'provider_public_id' => $id,
        'original_name' => $orig,
        'bytes' => $size,
        'mime' => (string) ($uploadedFile['type'] ?? ('image/' . $ext)),
        'width' => null,
        'height' => null,
        'secure_url' => $url,
        'local_path' => $dest,
        'note' => 'Cloudinary failed, fell back to local storage. Error: ' . ((string) ($upload['error'] ?? 'unknown')) . '.',
    ];
}

function ba_brevo_send_transactional(string $toEmail, string $toName, string $subject, string $htmlBody, array $tags = []): array
{
    $apiKey = (string) (defined('BREVO_API_KEY') ? BREVO_API_KEY : '');
    if ($apiKey === '' || $toEmail === '' || strpos($toEmail, '@') === false) {
        return ['ok' => false, 'error' => 'Brevo API key missing or invalid recipient email.'];
    }
    $fromEmail = (string) (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'no-reply@localhost');
    $fromName = (string) (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Marikina E-Concern');
    $payload = [
        'sender' => ['name' => $fromName, 'email' => $fromEmail],
        'to' => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject' => $subject,
        'htmlContent' => $htmlBody,
    ];
    if ($tags !== []) $payload['tags'] = array_values(array_map('strval', $tags));
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_status' => $code, 'error' => ($err !== '' ? $err : 'Empty response from Brevo.')];
    }
    $data = @json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'Brevo API returned HTTP ' . $code;
        return ['ok' => false, 'http_status' => $code, 'error' => $msg, 'raw' => (is_string($raw) ? substr($raw, 0, 300) : null)];
    }
    return [
        'ok' => true,
        'http_status' => $code,
        'provider' => 'brevo_live',
        'message_id' => (string) (is_array($data) ? ($data['messageId'] ?? '') : ''),
        'payload' => $payload,
        'response' => is_array($data) ? $data : ['raw' => $raw],
        'sent_at' => date('c'),
        'phase2_live' => true,
    ];
}

function ba_brevo_send_transactional_mock(string $toEmail, string $toName, string $subject, string $htmlBody, array $tags = []): array
{
    $failPct = defined('MOCK_EMAIL_FAIL_PCT') ? (int) MOCK_EMAIL_FAIL_PCT : 0;
    if ($failPct < 0) $failPct = 0;
    if ($failPct > 100) $failPct = 100;
    if (mt_rand(1, 100) <= $failPct) {
        return ['ok' => false, 'error' => 'MOCK: Brevo SMTP failure — MOCK_EMAIL_FAIL_PCT=' . $failPct . '% — configured by config.php constant.', 'provider' => 'brevo_mock'];
    }
    $liveResult = null;
    $hasLiveCreds = (defined('BREVO_API_KEY') && BREVO_API_KEY !== '' && defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '' && strpos(MAIL_FROM_ADDRESS, '@') !== false);
    if ($hasLiveCreds && $toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $liveResult = ba_brevo_send_transactional($toEmail, $toName, $subject, $htmlBody, $tags);
        if (!empty($liveResult['ok'])) {
            $dir = ba_storage_fixture_path('emails', '');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $id = (string) ($liveResult['message_id'] ?? ('email_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 6)));
            $payload = [
                'provider' => 'brevo_live',
                'message_id' => $id,
                'sent_at' => date('c'),
                'to' => ['email' => $toEmail, 'name' => $toName],
                'from' => ['address' => defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'no-reply@localhost', 'name' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'BasuraAlert'],
                'subject' => $subject,
                'tags' => $tags,
                'html_body' => $htmlBody,
                'http_status' => $liveResult['http_status'] ?? null,
                'delivered_via_mock' => false,
                'phase2_live' => true,
                'note' => 'Phase 2 L4 Brevo live delivery — email actually dispatched via Brevo v3/smtp/email API.',
            ];
            @file_put_contents($dir . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $id) . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return ['ok' => true, 'provider' => 'brevo_live', 'message_id' => $id, 'storage_path' => $dir . $id . '.json', 'http_status' => $liveResult['http_status'] ?? null, 'live_response' => $liveResult];
        }
    }
    $dir = ba_storage_fixture_path('emails', '');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $id = 'email_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $payload = [
        'provider' => 'brevo_mock_fallback',
        'message_id' => $id,
        'queued_at' => date('c'),
        'to' => ['email' => $toEmail, 'name' => $toName],
        'from' => ['address' => defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'no-reply@localhost', 'name' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'BasuraAlert'],
        'subject' => $subject,
        'tags' => $tags,
        'html_body' => $htmlBody,
        'delivered_via_mock' => true,
        'live_failed_reason' => (is_array($liveResult) && isset($liveResult['error'])) ? (string) $liveResult['error'] : 'No live credentials configured.',
        'note' => 'Brevo live delivery failed or disabled; message saved locally as fixture fallback.',
    ];
    @file_put_contents($dir . $id . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $ret = ['ok' => true, 'provider' => 'brevo_mock_fallback', 'message_id' => $id, 'storage_path' => $dir . $id . '.json'];
    if (is_array($liveResult) && isset($liveResult['error'])) $ret['fallback_reason'] = (string) $liveResult['error'];
    return $ret;
}

function ba_textbee_send_otp_mock(string $toMobile, string $otpCode, string $template = 'login_otp'): array
{
    $failPct = defined('MOCK_SMS_FAIL_PCT') ? (int) MOCK_SMS_FAIL_PCT : 0;
    if ($failPct < 0) $failPct = 0;
    if ($failPct > 100) $failPct = 100;
    $templates = [
        'login_otp' => 'BasuraAlert OTP: ' . $otpCode . ' — use this to log in. Never share it. Valid 5 minutes.',
        'report_confirm' => 'BasuraAlert: Report #' . $otpCode . ' received. Track it at My Reports.',
        'sched_change' => 'BasuraAlert: Schedule update — ' . $otpCode,
    ];
    $body = $templates[$template] ?? ('BasuraAlert: ' . $otpCode);
    if (mt_rand(1, 100) <= $failPct) {
        return ['ok' => false, 'error' => 'MOCK: TextBee failure — MOCK_SMS_FAIL_PCT=' . $failPct . '%.', 'provider' => 'textbee_mock'];
    }
    $dir = ba_storage_fixture_path('emails', '');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $id = 'sms_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $payload = [
        'provider' => 'textbee_mock',
        'sms_id' => $id,
        'sent_at' => date('c'),
        'to_mobile' => $toMobile,
        'template' => $template,
        'body' => $body,
        'device_id' => defined('SMS_TEXTBEE_DEVICE_ID') ? SMS_TEXTBEE_DEVICE_ID : 'mock_device',
        'delivered_via_mock' => true,
        'phase2_swap_hint' => 'Call TextBee endpoint ' . (defined('SMS_TEXTBEE_ENDPOINT') ? SMS_TEXTBEE_ENDPOINT : '') . ' with body/mobile/device to activate Phase 2.',
    ];
    @file_put_contents($dir . $id . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return ['ok' => true, 'provider' => 'textbee_mock', 'sms_id' => $id, 'body' => $body, 'storage_path' => $dir . $id . '.json'];
}

function ba_dropoff_pickup_types(): array {
    return [
        'STREET_END_CURBSIDE'     => 'Street-end curbside drop-off',
        'BARANGAY_MRF'            => 'Barangay MRF (Materials Recovery Facility)',
        'SHARED_BIN_CLUSTER'      => 'Shared bin cluster (multi-building)',
        'BULKY_DROP_OFF_YARD'     => 'Bulky / yard-waste drop-off yard',
        'HAZARDOUS_SATELLITE'     => 'Hazardous / special waste satellite point',
        'OTHER'                   => 'Other (see public notes)',
    ];
}

function ba_dropoff_statuses(): array {
    return [
        'DRAFT'               => 'Draft (hidden from residents)',
        'PUBLISHED'           => 'Published (shown on map)',
        'TEMPORARILY_CLOSED'  => 'Temporarily closed',
    ];
}

function ba_dropoff_waste_accepts_list(): array {
    return [
        'accepts_bio'        => ['label' => 'Biodegradable',         'badge' => 'bg-success'],
        'accepts_nonbio'     => ['label' => 'Non-biodegradable',     'badge' => 'bg-warning text-dark'],
        'accepts_recyclable' => ['label' => 'Recyclable',            'badge' => 'bg-info'],
        'accepts_hazard'     => ['label' => 'Hazardous / Special',   'badge' => 'bg-danger'],
        'accepts_bulky'      => ['label' => 'Bulky / Yard waste',    'badge' => 'bg-secondary'],
    ];
}

function ba_list_dropoffs(mysqli $mysqli, array $filters = [], bool $includeSchedules = true): array {
    $where = ['1=1'];
    $params = [];
    $types = '';
    $barangayId = isset($filters['barangay_id']) ? (int) $filters['barangay_id'] : 0;
    if ($barangayId > 0) { $where[] = 'd.barangay_id = ?'; $types .= 'i'; $params[] = $barangayId; }
    if (!empty($filters['status'])) {
        $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
        $qs = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "d.status IN ($qs)";
        foreach ($statuses as $s) { $types .= 's'; $params[] = (string) $s; }
    } else {
        $where[] = "d.status = 'PUBLISHED'";
    }
    foreach (ba_dropoff_waste_accepts_list() as $col => $meta) {
        if (array_key_exists($col, $filters)) {
            $want = !empty($filters[$col]) ? 1 : 0;
            $where[] = "d.$col = " . $want;
        }
    }
    if (!empty($filters['open_24_7'])) { $where[] = 'd.open_24_7 = 1'; }
    if (!empty($filters['pickup_type'])) { $where[] = 'd.pickup_type = ?'; $types .= 's'; $params[] = (string) $filters['pickup_type']; }
    if (!empty($filters['q'])) {
        $where[] = '(d.spot_name LIKE ? OR d.address LIKE ? OR d.notes_public LIKE ?)';
        $needle = '%' . $mysqli->real_escape_string((string) $filters['q']) . '%';
        $types .= 'sss'; $params[] = $needle; $params[] = $needle; $params[] = $needle;
    }
    $sql = "SELECT d.*, b.name AS barangay_name FROM ba_dropoff_points d LEFT JOIN barangays b ON b.id = d.barangay_id WHERE " . implode(' AND ', $where) . " ORDER BY d.barangay_id, d.pickup_type, d.id";
    if ($types === '') { $res = $mysqli->query($sql); } else { $stmt = db_bind_and_execute($mysqli, $sql, $types, $params); $res = $stmt ? $stmt->get_result() : false; }
    $out = []; if (!($res instanceof mysqli_result)) return $out;
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int) $r['id'];
        $r['barangay_id'] = (int) $r['barangay_id'];
        $r['latitude'] = (float) $r['latitude'];
        $r['longitude'] = (float) $r['longitude'];
        $r['open_24_7'] = (int) ($r['open_24_7'] ?? 0) === 1;
        foreach (array_keys(ba_dropoff_waste_accepts_list()) as $col) {
            $r[$col] = (int) ($r[$col] ?? 0) === 1;
        }
        if ($includeSchedules) {
            $s = $mysqli->query("SELECT * FROM ba_dropoff_schedules WHERE dropoff_id = " . $r['id'] . " ORDER BY day_of_week, time_start");
            $r['schedules'] = [];
            if ($s instanceof mysqli_result) { while ($row = $s->fetch_assoc()) { $r['schedules'][] = $row; } }
        }
        $out[] = $r;
    }
    return $out;
}

function ba_get_dropoff(mysqli $mysqli, int $id, bool $includeSchedules = true): ?array {
    $stmt = db_bind_and_execute($mysqli, "SELECT d.*, b.name AS barangay_name FROM ba_dropoff_points d LEFT JOIN barangays b ON b.id = d.barangay_id WHERE d.id = ? LIMIT 1", 'i', [$id]);
    $res = $stmt ? $stmt->get_result() : false;
    if (!($res instanceof mysqli_result) || $res->num_rows === 0) return null;
    $r = $res->fetch_assoc();
    $r['id'] = (int) $r['id'];
    $r['barangay_id'] = (int) $r['barangay_id'];
    $r['latitude'] = (float) $r['latitude'];
    $r['longitude'] = (float) $r['longitude'];
    $r['open_24_7'] = (int) ($r['open_24_7'] ?? 0) === 1;
    foreach (array_keys(ba_dropoff_waste_accepts_list()) as $col) { $r[$col] = (int) ($r[$col] ?? 0) === 1; }
    if ($includeSchedules) {
        $s = $mysqli->query("SELECT * FROM ba_dropoff_schedules WHERE dropoff_id = " . $r['id'] . " ORDER BY day_of_week, time_start");
        $r['schedules'] = [];
        if ($s instanceof mysqli_result) { while ($row = $s->fetch_assoc()) { $r['schedules'][] = $row; } }
    }
    return $r;
}

function ba_save_dropoff(mysqli $mysqli, array $payload, ?int $adminId = null, bool $skipIfDuplicateSpot = false): array {
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    $barangayId = (int) ($payload['barangay_id'] ?? 0);
    if ($barangayId <= 0) return ['ok' => false, 'error' => 'Barangay is required.'];
    $spotName = trim((string) ($payload['spot_name'] ?? ''));
    if ($spotName === '' || strlen($spotName) > 255) return ['ok' => false, 'error' => 'Spot name is required (max 255 chars).'];
    if ($id <= 0 && $skipIfDuplicateSpot) {
        $dupNormal = static function (string $s): string {
            $s = mb_strtolower(trim($s));
            if (class_exists('Normalizer', false)) {
                $n = Normalizer::normalize($s, Normalizer::FORM_D);
                if (is_string($n) && $n !== '') $s = (string) preg_replace('/[\p{Mn}\p{Me}]+/u', '', $n);
            }
            $s = strtr($s, [
                'ñ' => 'n', 'Ñ' => 'N', 'ń' => 'n', 'ê' => 'e', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
                'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ô' => 'o', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
                'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'û' => 'u', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
                '–' => ' ', '-' => ' ', '\'' => ' ', '’' => ' ', '"' => ' ',
            ]);
            $s = (string) preg_replace('/[^a-z0-9]+/', ' ', (string)$s);
            return trim((string) preg_replace('/\s+/', ' ', (string)$s));
        };
        $needle = $dupNormal($spotName);
        $bres = $mysqli->query("SELECT id, spot_name FROM ba_dropoff_points WHERE barangay_id = {$barangayId}");
        if ($bres instanceof mysqli_result) {
            $foundId = 0;
            while ($d = $bres->fetch_assoc()) {
                $cand = $dupNormal((string) $d['spot_name']);
                if ($cand === $needle) { $foundId = (int) $d['id']; break; }
            }
            if ($foundId > 0) return ['ok' => true, 'id' => $foundId, 'skipped' => true, 'reason' => 'duplicate'];
        }
    }
    $address = trim((string) ($payload['address'] ?? ''));
    if ($address === '' || strlen($address) > 500) return ['ok' => false, 'error' => 'Address is required (max 500 chars).'];
    $lat = (float) ($payload['latitude'] ?? 0);
    $lon = (float) ($payload['longitude'] ?? 0);
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) return ['ok' => false, 'error' => 'Invalid map coordinates (pin required).'];
    if ($lat === 0.0 && $lon === 0.0) return ['ok' => false, 'error' => 'Please drop a pin on the map.'];
    $pickupTypes = array_keys(ba_dropoff_pickup_types());
    $pickupType = (string) ($payload['pickup_type'] ?? 'STREET_END_CURBSIDE');
    if (!in_array($pickupType, $pickupTypes, true)) return ['ok' => false, 'error' => 'Invalid pickup type.'];
    $statuses = array_keys(ba_dropoff_statuses());
    $status = (string) ($payload['status'] ?? 'PUBLISHED');
    if (!in_array($status, $statuses, true)) return ['ok' => false, 'error' => 'Invalid status.'];
    $open247 = !empty($payload['open_24_7']) ? 1 : 0;
    $operationHours = trim((string) ($payload['operation_hours'] ?? ''));
    if (strlen($operationHours) > 255) $operationHours = substr($operationHours, 0, 255);
    $notesPublic = trim((string) ($payload['notes_public'] ?? ''));
    if (strlen($notesPublic) > 1000) $notesPublic = substr($notesPublic, 0, 1000);
    $refPhoto = trim((string) ($payload['reference_photo'] ?? ''));
    if (strlen($refPhoto) > 500) $refPhoto = substr($refPhoto, 0, 500);
    if ($refPhoto !== '') {
        if (!preg_match('#^(https?://|//|/|storage/)#i', $refPhoto)) return ['ok' => false, 'error' => 'Reference photo must be an https:// image URL or a local file path.'];
        if (preg_match('#(bing\.com/images/search|google\.[^/]+/search|/images/search\?|view=detailV2)#i', $refPhoto)) return ['ok' => false, 'error' => 'That looks like a search results page, not an image. Open the image and copy the direct image address (a .jpg/.png link or Cloudinary URL).'];
    }
    $osmId = trim((string) ($payload['place_osm_id'] ?? ''));
    if (strlen($osmId) > 64) $osmId = substr($osmId, 0, 64);
    $accepts = [];
    foreach (array_keys(ba_dropoff_waste_accepts_list()) as $col) { $accepts[$col] = !empty($payload[$col]) ? 1 : 0; }
    $now = date('Y-m-d H:i:s');
    $publishedAt = null; $publishedBy = null;
    $existing = null;
    if ($id > 0) {
        $e = ba_get_dropoff($mysqli, $id, false);
        if (is_array($e)) $existing = $e;
    }
    if ($id > 0 && $existing !== null) {
        $wasPublished = $existing['status'] === 'PUBLISHED';
        if ($status === 'PUBLISHED' && !$wasPublished) { $publishedAt = $now; $publishedBy = (int) $adminId; }
        else if ($wasPublished && $existing['published_at']) { $publishedAt = $existing['published_at']; $publishedBy = (int) ($existing['published_by_admin_id'] ?? 0); }
        $sql = "UPDATE ba_dropoff_points SET barangay_id=?, spot_name=?, address=?, latitude=?, longitude=?, place_osm_id=?, pickup_type=?, open_24_7=?, operation_hours=?, notes_public=?, reference_photo=?, status=?, accepts_bio=?, accepts_nonbio=?, accepts_recyclable=?, accepts_hazard=?, accepts_bulky=?" . ($publishedAt !== null ? ", published_at=?, published_by_admin_id=?" : "") . " WHERE id=? LIMIT 1";
        $types = 'issddssissssiiiii' . ($publishedAt !== null ? 'si' : '') . 'i';
        $params = [$barangayId, $spotName, $address, $lat, $lon, $osmId, $pickupType, $open247, $operationHours, $notesPublic, $refPhoto, $status, $accepts['accepts_bio'], $accepts['accepts_nonbio'], $accepts['accepts_recyclable'], $accepts['accepts_hazard'], $accepts['accepts_bulky']];
        if ($publishedAt !== null) { $params[] = $publishedAt; $params[] = $publishedBy; }
        $params[] = $id;
        $ok = db_bind_exec_bool($mysqli, $sql, $types, $params);
        if (!$ok) return ['ok' => false, 'error' => 'Update failed: ' . $mysqli->error];
    } else {
        if ($status === 'PUBLISHED') { $publishedAt = $now; $publishedBy = (int) $adminId; }
        $sql = "INSERT INTO ba_dropoff_points (barangay_id, spot_name, address, latitude, longitude, place_osm_id, pickup_type, open_24_7, operation_hours, notes_public, reference_photo, status, accepts_bio, accepts_nonbio, accepts_recyclable, accepts_hazard, accepts_bulky, created_by_admin_id, published_by_admin_id, published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $types = 'issddssissssiiiiiiis';
        $params = [$barangayId, $spotName, $address, $lat, $lon, $osmId, $pickupType, $open247, $operationHours, $notesPublic, $refPhoto, $status, $accepts['accepts_bio'], $accepts['accepts_nonbio'], $accepts['accepts_recyclable'], $accepts['accepts_hazard'], $accepts['accepts_bulky'], (int) $adminId, (int) $publishedBy, $publishedAt];
        $ok = db_bind_exec_bool($mysqli, $sql, $types, $params);
        if (!$ok) return ['ok' => false, 'error' => 'Insert failed: ' . $mysqli->error];
        $id = (int) $mysqli->insert_id;
    }
    $mysqli->query("DELETE FROM ba_dropoff_schedules WHERE dropoff_id = " . $id);
    $schedules = is_array($payload['schedules'] ?? null) ? $payload['schedules'] : [];
    $insertedScheduleIds = [];
    foreach ($schedules as $row) {
        $dow = (int) ($row['day_of_week'] ?? -1);
        if ($dow < 0 || $dow > 6) continue;
        $wt = (string) ($row['waste_type'] ?? '');
        $validWastes = ['Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special','Bulky'];
        if (!in_array($wt, $validWastes, true)) continue;
        $ts = (string) ($row['time_start'] ?? '');
        $te = (string) ($row['time_end'] ?? '');
        if ($ts === '' || $te === '') continue;
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $ts) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $te)) continue;
        $effFrom = null;
        $effTo   = null;
        if (!empty($row['effective_from']) && is_string($row['effective_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['effective_from'])) {
            $effFrom = $row['effective_from'];
        }
        if (!empty($row['effective_to']) && is_string($row['effective_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['effective_to'])) {
            $effTo = $row['effective_to'];
        }
        $stmt = db_bind_and_execute($mysqli, "INSERT INTO ba_dropoff_schedules (dropoff_id, day_of_week, waste_type, time_start, time_end, effective_from, effective_to) VALUES (?,?,?,?,?,?,?)", 'iisssss', [$id, $dow, $wt, $ts, $te, $effFrom, $effTo]);
        if (!$stmt) return ['ok' => false, 'error' => 'Schedule insert failed: ' . $mysqli->error];
        $dsId = (int) ($mysqli->insert_id ?: 0);
        if ($dsId > 0) $insertedScheduleIds[$dsId] = ['dow' => $dow, 'wt' => $wt, 'ts' => $ts, 'te' => $te, 'effFrom' => $effFrom, 'effTo' => $effTo];
    }

    $linkedSchedulesCreated = 0;
    $linkedSchedulesSkipped = 0;
    $linkedSchedulesErrors = [];
    if ($status === 'PUBLISHED' && count($insertedScheduleIds) > 0 && $barangayId > 0) {
        $titleBase = $spotName . ' (Drop-off)';
        if (strlen($titleBase) > 120) $titleBase = substr($titleBase, 0, 117) . '...)';
        $groupUid = substr(bin2hex(random_bytes(16)), 0, 32);
        $notesPrefix = 'Auto-generated from Drop-off #' . $id . ': ' . $spotName;
        if (strlen($notesPrefix) > 255) $notesPrefix = substr($notesPrefix, 0, 255);

        $collStatus = 'Published';
        $sqlIns = "INSERT INTO ba_collection_schedules (barangay_id, title, waste_type, schedule_type, day_of_week, collection_date, time_start, time_end, effective_from, effective_to, status, notes, created_by_admin_id, linked_type, linked_id, linked_group_uid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        foreach ($insertedScheduleIds as $dsId => $srow) {
            $collDow = $srow['dow'] + 1;
            if ($collDow > 7) $collDow = 7;
            try {
                $stmt = db_bind_and_execute($mysqli, $sqlIns, 'isssisssssssissi', [
                    $barangayId, $titleBase, $srow['wt'], 'regular',
                    $collDow, null, $srow['ts'], $srow['te'],
                    $srow['effFrom'], $srow['effTo'], $collStatus, $notesPrefix,
                    (int) $adminId,
                    'dropoff', $id, $groupUid
                ]);
                if (!$stmt) {
                    $err = (string) ($mysqli->error ?: 'unknown');
                    if (stripos($err, 'uniq_coll_linked_waste') !== false || stripos($err, 'duplicate') !== false) {
                        $linkedSchedulesSkipped++;
                        continue;
                    }
                    $linkedSchedulesErrors[] = $err;
                    continue;
                }
                $collId = (int) ($mysqli->insert_id ?: 0);
                if ($collId > 0) {
                    $upd = db_bind_and_execute($mysqli, "UPDATE ba_dropoff_schedules SET linked_type='collection', linked_id=? WHERE id=? LIMIT 1", 'ii', [$collId, $dsId]);
                    if ($upd !== false) $linkedSchedulesCreated++;
                    else $linkedSchedulesErrors[] = 'failed to set reverse pointer ds#' . $dsId;
                }
            } catch (Throwable $e) {
                $msg = (string) $e->getMessage();
                if (stripos($msg, 'Duplicate') !== false && stripos($msg, 'uniq_coll_linked_waste') !== false) {
                    $linkedSchedulesSkipped++;
                    continue;
                }
                $linkedSchedulesErrors[] = substr($msg, 0, 120);
            }
        }
    }

    $result = ['ok' => true, 'id' => $id];
    if ($linkedSchedulesCreated > 0 || $linkedSchedulesSkipped > 0 || count($linkedSchedulesErrors) > 0) {
        $result['linked_schedules'] = [
            'created' => $linkedSchedulesCreated,
            'skipped_duplicate' => $linkedSchedulesSkipped,
            'errors' => $linkedSchedulesErrors,
        ];
    }
    return $result;
}

function ba_delete_dropoff(mysqli $mysqli, int $id): bool {
    $id = (int) $id;
    if ($id <= 0) return false;
    try {
        $mysqli->begin_transaction();
        $linkedRows = [];
        $res = $mysqli->query("SELECT id, linked_id FROM ba_dropoff_schedules WHERE dropoff_id = {$id} AND linked_type = 'collection' AND linked_id IS NOT NULL");
        if ($res instanceof mysqli_result) {
            while (($r = $res->fetch_assoc())) $linkedRows[] = (int) ($r['linked_id'] ?? 0);
            $res->free();
        }
        foreach ($linkedRows as $collId) {
            if ($collId > 0) {
                $upd = $mysqli->prepare("UPDATE ba_collection_schedules SET linked_type='none', linked_id=NULL, linked_group_uid=NULL WHERE id=? LIMIT 1");
                if ($upd) { $upd->bind_param('i', $collId); $upd->execute(); $upd->close(); }
            }
        }
        $updFwd = $mysqli->prepare("UPDATE ba_collection_schedules SET linked_type='none', linked_id=NULL, linked_group_uid=NULL WHERE linked_type='dropoff' AND linked_id = ?");
        if ($updFwd) { $updFwd->bind_param('i', $id); $updFwd->execute(); $updFwd->close(); }
        $stmt = db_bind_and_execute($mysqli, "DELETE FROM ba_dropoff_points WHERE id = ? LIMIT 1", 'i', [$id]);
        $mysqli->commit();
        return $stmt !== false;
    } catch (Throwable $e) {
        try { $mysqli->rollback(); } catch (Throwable $_) {}
        return false;
    }
}

function ba_nearest_dropoffs(mysqli $mysqli, float $lat, float $lon, array $filters = [], int $limit = 3): array {
    $raw = ba_list_dropoffs($mysqli, $filters, true);
    $R = 6371.0088;
    $enriched = [];
    foreach ($raw as $r) {
        $dLat = deg2rad($r['latitude'] - $lat);
        $dLon = deg2rad($r['longitude'] - $lon);
        $a = sin($dLat/2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($r['latitude'])) * sin($dLon/2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $r['distance_km'] = round($R * $c, 3);
        $r['distance_m']  = (int) round($R * $c * 1000);
        $enriched[] = $r;
    }
    usort($enriched, static function ($a, $b) { return $a['distance_km'] <=> $b['distance_km']; });
    return array_slice($enriched, 0, $limit);
}

function ba_import_dropoff_seed(mysqli $mysqli, string $fixtureName = 'ba_dropoff_points_seed_marikina_16barangays.json', ?int $adminId = null): array {
    $path = ba_storage_fixture_path('fixtures', $fixtureName);
    if (!is_file($path)) {
        $altPath = rtrim(defined('STORAGE_DIR') ? STORAGE_DIR : (__DIR__ . '/../storage'), '/\\') . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . $fixtureName;
        if (!is_file($altPath)) return ['ok' => false, 'error' => 'Seed fixture not found at ' . $path];
        $path = $altPath;
    }
    $raw = file_get_contents($path);
    if ($raw === false) return ['ok' => false, 'error' => 'Seed fixture not readable.'];
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['dropoffs']) || !is_array($json['dropoffs'])) return ['ok' => false, 'error' => 'Seed fixture JSON is invalid (missing dropoffs[] array).'];
    $normal = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        if (class_exists('Normalizer', false)) {
            $n = Normalizer::normalize($s, Normalizer::FORM_D);
            if (is_string($n) && $n !== '') $s = (string) preg_replace('/[\p{Mn}\p{Me}]+/u', '', $n);
        }
        $s = strtr($s, [
            'ñ' => 'n', 'Ñ' => 'N', 'ń' => 'n', 'ê' => 'e', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
            'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ô' => 'o', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
            'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'û' => 'u', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
            '–' => ' ', '-' => ' ', '\'' => ' ', '’' => ' ', '"' => ' ',
        ]);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', (string)$s);
        return trim((string) preg_replace('/\s+/', ' ', (string)$s));
    };
    $aliasMap = [
        'industrial valley' => 'industrial valley complex',
        'industrial valley complex' => 'industrial valley complex',
        'jesus dela pena' => 'jesus de la pena',
        'jesus de la pena' => 'jesus de la pena',
        'jesus dela pena chapel curb' => 'jesus de la pena',
        'molino' => 'nangka',
        'molino 1' => 'nangka',
        'molino 2' => 'nangka',
        'molino i' => 'nangka',
        'molino ii' => 'nangka',
    ];
    $barangaysMap = [];
    $bres = $mysqli->query("SELECT id, name FROM barangays");
    $dbNames = [];
    if ($bres instanceof mysqli_result) {
        while ($br = $bres->fetch_assoc()) {
            $n = (string) $br['name'];
            $dbNames[] = $n;
            $k = $normal($n);
            $barangaysMap[$k] = (int) $br['id'];
            if (isset($aliasMap[$k])) $barangaysMap[$aliasMap[$k]] = (int) $br['id'];
            if (str_contains($k, 'molino') && !isset($barangaysMap['molino'])) $barangaysMap['molino'] = (int) $br['id'];
        }
    }
    // Reverse map aliases: e.g. fixture "molino" -> match any DB barangay with "molino" in name
    foreach ($aliasMap as $aliasK => $canonK) {
        if (!isset($barangaysMap[$aliasK]) && isset($barangaysMap[$canonK])) {
            $barangaysMap[$aliasK] = $barangaysMap[$canonK];
        }
    }
    $created = 0; $skipped = 0; $failed = [];
    foreach ($json['dropoffs'] as $row) {
        $bname = trim((string) ($row['barangay_name'] ?? ''));
        $bkey = $normal($bname);
        $bid = $barangaysMap[$bkey] ?? 0;
        if ($bid <= 0) {
            foreach (array_keys($barangaysMap) as $candKey) {
                if ($candKey === '' || $bkey === '') continue;
                if (str_contains($candKey, $bkey) || str_contains($bkey, $candKey)) { $bid = $barangaysMap[$candKey]; break; }
                similar_text($candKey, $bkey, $pct); if ($pct > 82) { $bid = $barangaysMap[$candKey]; break; }
            }
        }
        if ($bid <= 0) {
            $failed[] = $row['spot_name'] . " — barangay '$bname' not in DB. DB barangays: " . implode(', ', $dbNames) . ".";
            $skipped++;
            continue;
        }
        $row['barangay_id'] = $bid;
        $res = ba_save_dropoff($mysqli, $row, $adminId, true);
        if (!empty($res['skipped'])) $skipped++;
        else if (!empty($res['ok'])) $created++;
        else { $failed[] = $row['spot_name'] . ' — ' . ($res['error'] ?? 'unknown'); }
    }
    return ['ok' => true, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'total' => count($json['dropoffs'])];
}

