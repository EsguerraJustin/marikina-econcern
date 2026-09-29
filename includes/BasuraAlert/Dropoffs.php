<?php
declare(strict_types=1);

/**
 * BasuraAlert — Dropoffs service
 * Extracted from includes/basuraalert.php (auto-split 9 functions)
 * This file is required by includes/basuraalert.php for backwards-compat.
 */

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

