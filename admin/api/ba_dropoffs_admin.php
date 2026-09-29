<?php

declare(strict_types=1);

$_baDropoffHandlersStarted = true;

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/basuraalert.php';

try {
    require_csrf_token();
    require_admin_login();

    $action = isset($_POST['action']) ? (string) $_POST['action'] : (string) ($_GET['action'] ?? 'list');
    $mysqli = db();
    $adminId = null;
    $admin = current_admin($mysqli);
    if (is_array($admin) && isset($admin['id'])) $adminId = (int) $admin['id'];
    $isSuperDropoff = ($admin['role'] ?? '') === 'super_admin';
    $superAdminDropoffActions = ['save','delete','import_seed','create_draft','save_step_map','save_step_info','save_finalize','edit_save_map','edit_save_info','edit_finalize'];
    if (!$isSuperDropoff && in_array($action, $superAdminDropoffActions, true)) {
        json_response(['ok' => false, 'error' => 'Forbidden — Super Admin permission required to create/edit/delete drop-off points.'], 403);
    }

    $GEOFENCE_ERR = 'This location is beyond the allowed dropoff point. Please select a location within Marikina.';
    $MAR_LAT_SW = 14.5968; $MAR_LON_SW = 121.0596;
    $MAR_LAT_NE = 14.6926; $MAR_LON_NE = 121.1441;
    $MAR_DIST_LIMIT_M = 4200.0;
    function ba_coord_within_marikina(?float $lat, ?float $lon, mysqli $db, &$nearestMeters = null): bool {
        global $MAR_LAT_SW, $MAR_LON_SW, $MAR_LAT_NE, $MAR_LON_NE, $MAR_DIST_LIMIT_M;
        if ($lat === null || $lon === null || !is_finite($lat) || !is_finite($lon)) return false;
        if ($lat < $MAR_LAT_SW || $lat > $MAR_LAT_NE || $lon < $MAR_LON_SW || $lon > $MAR_LON_NE) return false;
        $seedPath = ba_storage_fixture_path('fixtures', 'ba_dropoff_points_seed_marikina_16barangays.json');
        $best = INF;
        if (is_file($seedPath)) {
            $seed = @json_decode(@file_get_contents($seedPath), true);
            if (is_array($seed) && !empty($seed['dropoffs'])) foreach ($seed['dropoffs'] as $d) {
                $dlat = (float)($d['latitude'] ?? 0);
                $dlon = (float)($d['longitude'] ?? 0);
                if (!$dlat || !$dlon) continue;
                $dy = ($dlat - $lat) * 111320.0;
                $dx = ($dlon - $lon) * 111320.0 * cos(deg2rad($lat));
                $dist = sqrt($dy * $dy + $dx * $dx);
                if ($dist < $best) $best = $dist;
            }
        }
        $nearestMeters = is_finite($best) ? (int)round($best) : null;
        return $best <= $MAR_DIST_LIMIT_M;
    }

    if ($action === 'list') {
        $filters = [
            'status' => ['DRAFT','PUBLISHED','TEMPORARILY_CLOSED'],
        ];
        if (!empty($_GET['barangay_id'])) $filters['barangay_id'] = (int) $_GET['barangay_id'];
        if (!empty($_GET['q'])) $filters['q'] = (string) $_GET['q'];
        if (!empty($_GET['pickup_type'])) $filters['pickup_type'] = (string) $_GET['pickup_type'];
        $rows = ba_list_dropoffs($mysqli, $filters, true);
        json_response(['ok' => true, 'dropoffs' => $rows]);
    }

    if ($action === 'save') {
        $payload = [
            'id'                 => isset($_POST['id']) ? (int) $_POST['id'] : 0,
            'barangay_id'        => (int) ($_POST['barangay_id'] ?? 0),
            'spot_name'          => (string) ($_POST['spot_name'] ?? ''),
            'address'            => (string) ($_POST['address'] ?? ''),
            'latitude'           => (float) ($_POST['latitude'] ?? 0),
            'longitude'          => (float) ($_POST['longitude'] ?? 0),
            'place_osm_id'       => (string) ($_POST['place_osm_id'] ?? ''),
            'pickup_type'        => (string) ($_POST['pickup_type'] ?? 'STREET_END_CURBSIDE'),
            'open_24_7'          => isset($_POST['open_24_7']) ? 1 : 0,
            'operation_hours'    => (string) ($_POST['operation_hours'] ?? ''),
            'notes_public'       => (string) ($_POST['notes_public'] ?? ''),
            'reference_photo'    => (string) ($_POST['reference_photo'] ?? ''),
            'status'             => (string) ($_POST['status'] ?? 'PUBLISHED'),
            'accepts_bio'        => isset($_POST['accepts_bio']) ? 1 : 0,
            'accepts_nonbio'     => isset($_POST['accepts_nonbio']) ? 1 : 0,
            'accepts_recyclable' => isset($_POST['accepts_recyclable']) ? 1 : 0,
            'accepts_hazard'     => isset($_POST['accepts_hazard']) ? 1 : 0,
            'accepts_bulky'      => isset($_POST['accepts_bulky']) ? 1 : 0,
            'schedules'          => [],
    ];
        $schedRaw = json_decode((string) ($_POST['schedules_json'] ?? '[]'), true);
    $schedForValidation = is_array($schedRaw) ? $schedRaw : [];
    // DRY: use shared validator (Option B)
    $validation = ba_validate_dropoff_schedules($schedForValidation);
    if (!$validation['ok']) {
        json_response(['ok' => false, 'error' => $validation['error']]);
    }
    // JSON decode error (non-array and not empty) — treat as error if raw was not array but input was not empty
    if ($schedRaw !== null && !is_array($schedRaw) && trim((string)($_POST['schedules_json'] ?? '')) !== '' && trim((string)($_POST['schedules_json'] ?? '')) !== '[]') {
        json_response(['ok' => false, 'error' => 'Invalid schedules_json: must be a JSON array.']);
    }
    $payload['schedules'] = $validation['cleaned'];

        $missing = [];
        if ($payload['barangay_id'] <= 0) $missing[] = 'Barangay is required (select a value from the dropdown or pin on the map).';
        if (trim($payload['spot_name']) === '') $missing[] = 'Landmark is required (non-empty; enter landmark text).';
        if (trim($payload['address']) === '') $missing[] = 'Full Street Address is required (auto-filled from map pin).';
        if ($missing) {
            json_response(['ok' => false, 'error' => 'Missing required fields: ' . implode(' ', $missing)]);
        }
        $nearestMeters = null;
        if (!ba_coord_within_marikina((float)$payload['latitude'], (float)$payload['longitude'], $mysqli, $nearestMeters)) {
            json_response(['ok' => false, 'error' => $GEOFENCE_ERR, 'nearest_meters' => $nearestMeters]);
        }
        // Server-side strict Operation Hours validation (mirror of client isValidHoursFormat):
        // Blank + Open24/7 accepted. For non-blank custom hours, NOW enforces the user's
        // explicit "(Day - Time : Time)" pattern by REQUIRING a REAL TIME RANGE
        // (start time + dash separator + end time). Single clock only, pure-alpha garbage,
        // or free-form phrases without a range are all REJECTED on server same as browser.
                $opErr = ba_validate_operation_hours((string)$payload['operation_hours'], (bool)$payload['open_24_7']);
        if ($opErr !== null) {
            json_response(['ok' => false, 'error' => $opErr]);
        }
        $res = ba_save_dropoff($mysqli, $payload, $adminId);
        json_response($res);
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid drop-off id.']);
        $ok = ba_delete_dropoff($mysqli, $id);
        json_response(['ok' => $ok, 'error' => $ok ? null : 'Delete failed.']);
    }

    if ($action === 'import_seed') {
        $res = ba_import_dropoff_seed($mysqli, 'ba_dropoff_points_seed_marikina_16barangays.json', $adminId);
        json_response($res);
    }

    if ($action === 'create_draft') {
        $defaultLat = 14.6247;
        $defaultLon = 121.0993;
        $nearestMeters = null;
        if (!ba_coord_within_marikina($defaultLat, $defaultLon, $mysqli, $nearestMeters)) {
            json_response(['ok' => false, 'error' => 'Default pin location not within Marikina.']);
        }
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO ba_dropoff_points (barangay_id, spot_name, address, latitude, longitude, place_osm_id, pickup_type, open_24_7, operation_hours, notes_public, reference_photo, status, accepts_bio, accepts_nonbio, accepts_recyclable, accepts_hazard, accepts_bulky, created_by_admin_id, published_at, published_by_admin_id) VALUES (0, '', '', ?, ?, '', 'STREET_END_CURBSIDE', 0, '', '', '', 'DRAFT', 1, 1, 1, 0, 0, ?, NULL, NULL)";
        $ok = db_bind_exec_bool($mysqli, $sql, 'ddi', [$defaultLat, $defaultLon, (int)$adminId]);
        if (!$ok) { json_response(['ok' => false, 'error' => 'Insert draft failed: ' . $mysqli->error]); }
        $newId = (int) $mysqli->insert_id;
        json_response(['ok' => true, 'id' => $newId, 'status' => 'DRAFT']);
    }

    if ($action === 'save_step_map') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid drop-off id.']);
        $existing = ba_get_dropoff($mysqli, $id, false);
        if (!$existing || $existing['status'] !== 'DRAFT') {
            json_response(['ok' => false, 'error' => 'Step-save allowed only on DRAFT rows.']);
        }
        $lat = (float) ($_POST['latitude'] ?? 0);
        $lon = (float) ($_POST['longitude'] ?? 0);
        $osmId = trim((string) ($_POST['place_osm_id'] ?? ''));
        if (strlen($osmId) > 64) $osmId = substr($osmId, 0, 64);
        if ($lat === 0.0 && $lon === 0.0) json_response(['ok' => false, 'error' => 'Please place / drag a pin on the Marikina map before saving step 1.']);
        $nearestMeters = null;
        if (!ba_coord_within_marikina($lat, $lon, $mysqli, $nearestMeters)) {
            json_response(['ok' => false, 'error' => $GEOFENCE_ERR, 'nearest_meters' => $nearestMeters]);
        }
        $ok = db_bind_exec_bool($mysqli, "UPDATE ba_dropoff_points SET latitude=?, longitude=?, place_osm_id=? WHERE id=? AND status='DRAFT' LIMIT 1", 'ddsi', [$lat, $lon, $osmId, $id]);
        if (!$ok) json_response(['ok' => false, 'error' => 'Save step 1 (Pin) failed: ' . $mysqli->error]);
        json_response(['ok' => true, 'id' => $id, 'step_saved' => 'map']);
    }

    if ($action === 'save_step_info') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid drop-off id.']);
        $existing = ba_get_dropoff($mysqli, $id, false);
        if (!$existing || $existing['status'] !== 'DRAFT') {
            json_response(['ok' => false, 'error' => 'Step-save allowed only on DRAFT rows.']);
        }
        $pickupTypeVal = (string) ($_POST['pickup_type'] ?? '');
        $statusVal = (string) ($_POST['status'] ?? '');
        $open24 = isset($_POST['open_24_7']) ? 1 : 0;
        $hoursVal = trim((string) ($_POST['operation_hours'] ?? ''));
        $payload = [
            'id'                 => $id,
            'barangay_id'        => (int) ($_POST['barangay_id'] ?? 0),
            'spot_name'          => trim((string) ($_POST['spot_name'] ?? '')),
            'address'            => trim((string) ($_POST['address'] ?? '')),
            'latitude'           => (float) ($existing['latitude'] ?? 0),
            'longitude'          => (float) ($existing['longitude'] ?? 0),
            'place_osm_id'       => trim((string) ($_POST['place_osm_id'] ?? ($existing['place_osm_id'] ?? ''))),
            'pickup_type'        => $pickupTypeVal,
            'open_24_7'          => $open24,
            'operation_hours'    => $hoursVal,
            'notes_public'       => trim((string) ($_POST['notes_public'] ?? '')),
            'reference_photo'    => trim((string) ($_POST['reference_photo'] ?? '')),
            'status'             => 'DRAFT',
            'accepts_bio'        => (int) ($existing['accepts_bio'] ?? 1),
            'accepts_nonbio'     => (int) ($existing['accepts_nonbio'] ?? 1),
            'accepts_recyclable' => (int) ($existing['accepts_recyclable'] ?? 1),
            'accepts_hazard'     => (int) ($existing['accepts_hazard'] ?? 0),
            'accepts_bulky'      => (int) ($existing['accepts_bulky'] ?? 0),
            'schedules'          => [],
        ];
        $validPickups = array_keys(ba_dropoff_pickup_types());
        $validStatuses = array_keys(ba_dropoff_statuses());
        $missing = [];
        if ($payload['barangay_id'] <= 0) $missing[] = 'Barangay (select from dropdown or place map pin so it auto-populates).';
        if (!in_array($pickupTypeVal, $validPickups, true)) $missing[] = 'Pickup / Facility type — select a valid type from the dropdown.';
        if ($payload['spot_name'] === '') $missing[] = 'Landmark / Spot name (non-empty; e.g. "J.P. Rizal end-curb near bridge").';
        if ($payload['address'] === '') $missing[] = 'Full Street Address (auto-filled from pin; should not be blank).';
        if (!in_array($statusVal, $validStatuses, true)) $missing[] = 'Status — select a valid status from the dropdown (DRAFT, PUBLISHED, or TEMPORARILY_CLOSED).';
        if (!$open24 && $hoursVal === '') $missing[] = 'Hours & Days of Operation — either turn ON the Open 24/7 chip, OR use "+ Add range" below the Hours card to build at least one custom hours range (Day range + Start time + End time).';
        if ($missing) json_response(['ok' => false, 'error' => 'Missing required Info fields (EVERYTHING must be filled in Step 2 before you can save Info): ' . implode(' ', $missing)]);
        $nearestMeters = null;
        if (!ba_coord_within_marikina((float)$payload['latitude'], (float)$payload['longitude'], $mysqli, $nearestMeters)) {
            json_response(['ok' => false, 'error' => $GEOFENCE_ERR, 'nearest_meters' => $nearestMeters]);
        }
                $opErr = ba_validate_operation_hours((string)$payload['operation_hours'], (bool)$payload['open_24_7']);
        if ($opErr !== null) {
            json_response(['ok' => false, 'error' => $opErr]);
        }
        $res = ba_save_dropoff($mysqli, $payload, $adminId);
        if (!$res || !($res['ok'] ?? false)) json_response($res);
        json_response(['ok' => true, 'id' => $id, 'step_saved' => 'info']);
    }

    if ($action === 'save_finalize') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid drop-off id.']);
        $existing = ba_get_dropoff($mysqli, $id, false);
        if (!$existing) json_response(['ok' => false, 'error' => 'Drop-off not found.']);
        if ($existing['status'] !== 'DRAFT') {
            json_response(['ok' => false, 'error' => 'Finalize only allowed on DRAFT records (current status: ' . e($existing['status']) . ').']);
        }
        $payload = [
            'id'                 => $id,
            'barangay_id'        => (int) ($existing['barangay_id'] ?? 0),
            'spot_name'          => (string) ($existing['spot_name'] ?? ''),
            'address'            => (string) ($existing['address'] ?? ''),
            'latitude'           => (float) ($existing['latitude'] ?? 0),
            'longitude'          => (float) ($existing['longitude'] ?? 0),
            'place_osm_id'       => (string) ($existing['place_osm_id'] ?? ''),
            'pickup_type'        => (string) ($existing['pickup_type'] ?? ''),
            'open_24_7'          => (int) ($existing['open_24_7'] ?? 0),
            'operation_hours'    => (string) ($existing['operation_hours'] ?? ''),
            'notes_public'       => (string) ($existing['notes_public'] ?? ''),
            'reference_photo'    => (string) ($existing['reference_photo'] ?? ''),
            'status'             => (string) ($_POST['status'] ?? 'PUBLISHED'),
            'accepts_bio'        => isset($_POST['accepts_bio']) ? 1 : 0,
            'accepts_nonbio'     => isset($_POST['accepts_nonbio']) ? 1 : 0,
            'accepts_recyclable' => isset($_POST['accepts_recyclable']) ? 1 : 0,
            'accepts_hazard'     => isset($_POST['accepts_hazard']) ? 1 : 0,
            'accepts_bulky'      => isset($_POST['accepts_bulky']) ? 1 : 0,
            'schedules'          => [],
        ];
        $validPickups = array_keys(ba_dropoff_pickup_types());
        $validStatuses = array_keys(ba_dropoff_statuses());
        if (!in_array($payload['status'], $validStatuses, true)) {
            json_response(['ok' => false, 'error' => 'Invalid status: ' . e($payload['status'])]);
        }
        $acceptsAny = ($payload['accepts_bio'] + $payload['accepts_nonbio'] + $payload['accepts_recyclable'] + $payload['accepts_hazard'] + $payload['accepts_bulky']) > 0;
        if (!$acceptsAny) json_response(['ok' => false, 'error' => '<i data-lucide="x-circle" class="lucide-14"></i> Step 3 (Waste Accepted) EVERYTHING required: At least one Waste Accepted checkbox must be ticked before finalizing (Bio / Non-bio / Recyclable / Hazardous / Bulky).']);
        $missing = [];
        if ($payload['barangay_id'] <= 0) $missing[] = 'Barangay is required (save Step 2 Info first — Info MUST be completely filled).';
        if (!in_array($payload['pickup_type'], $validPickups, true)) $missing[] = 'Pickup / Facility type is required (save Step 2 Info first).';
        if (trim($payload['spot_name']) === '') $missing[] = 'Landmark is required (save Step 2 Info first).';
        if (trim($payload['address']) === '') $missing[] = 'Full address is required (save Step 2 Info first).';
        if (!(int)$payload['open_24_7'] && trim((string)$payload['operation_hours']) === '') $missing[] = 'Hours & Days of Operation are required (either Open 24/7 OR at least one custom hours range added in Step 2 Info).';
        if ($missing) json_response(['ok' => false, 'error' => '<i data-lucide="x-circle" class="lucide-14"></i> Step 2 Info is NOT COMPLETELY FILLED: ' . implode(' ', $missing) . ' Go back to Step 2 Info and click <i data-lucide="save" class="lucide-14"></i> Save Step 2 · Info Details first, then return here.']);
            $schedRaw = json_decode((string) ($_POST['schedules_json'] ?? '[]'), true);
    $schedForValidation = is_array($schedRaw) ? $schedRaw : [];
    // DRY: use shared validator (Option B)
    $validation = ba_validate_dropoff_schedules($schedForValidation);
    if (!$validation['ok']) {
        json_response(['ok' => false, 'error' => $validation['error']]);
    }
    // JSON decode error (non-array and not empty) — treat as error if raw was not array but input was not empty
    if ($schedRaw !== null && !is_array($schedRaw) && trim((string)($_POST['schedules_json'] ?? '')) !== '' && trim((string)($_POST['schedules_json'] ?? '')) !== '[]') {
        json_response(['ok' => false, 'error' => 'Invalid schedules_json: must be a JSON array.']);
    }
    $payload['schedules'] = $validation['cleaned'];
        $res = ba_save_dropoff($mysqli, $payload, $adminId);
        json_response($res);
    }

    // =============================================================
    // STEP EDIT MODE — 3 new endpoints for existing saved rows.
    // (step_create endpoints above require DRAFT status; these work on DRAFT / PUBLISHED / TEMPORARILY_CLOSED.)
    // =============================================================
    if ($action === 'edit_save_map') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid drop-off id.']);
        $existing = ba_get_dropoff($mysqli, $id, false);
        if (!$existing) json_response(['ok' => false, 'error' => 'Drop-off not found.']);
        $allowedStatuses = ['DRAFT', 'PUBLISHED', 'TEMPORARILY_CLOSED'];
        if (!in_array((string)($existing['status'] ?? ''), $allowedStatuses, true)) {
            json_response(['ok' => false, 'error' => 'Location edit only allowed for DRAFT, PUBLISHED, or TEMPORARILY_CLOSED rows (current status: ' . e($existing['status']) . ').']);
        }
        $lat = (float) ($_POST['latitude'] ?? 0);
        $lon = (float) ($_POST['longitude'] ?? 0);
        $osmId = trim((string) ($_POST['place_osm_id'] ?? ''));
        if ($lat === 0.0 || $lon === 0.0) json_response(['ok' => false, 'error' => 'Map pin is required — place/drag a pin on the Marikina map (cannot save at [0,0]).']);
        $nearestMeters = null;
        if (!ba_coord_within_marikina($lat, $lon, $mysqli, $nearestMeters)) {
            json_response(['ok' => false, 'error' => $GEOFENCE_ERR, 'nearest_meters' => $nearestMeters]);
        }
        // Only update pin location fields (don't touch other columns — Info step handles them)
        $stmt = $mysqli->prepare("UPDATE ba_dropoff_points SET latitude = ?, longitude = ?, place_osm_id = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        if (!$stmt) json_response(['ok' => false, 'error' => 'DB prepare failed (edit_save_map).']);
        $stmt->bind_param('ddsi', $lat, $lon, $osmId, $id);
        $stmt->execute();
        $rows = $stmt->affected_rows;
        $stmt->close();
        if ($rows < 0) json_response(['ok' => false, 'error' => 'DB update failed (edit_save_map affected_rows negative).']);
        json_response(['ok' => true, 'id' => $id, 'step_saved' => 'map_edit', 'updated_rows' => $rows, 'new_coords' => ['lat' => $lat, 'lng' => $lon]]);
    }

    if ($action === 'edit_save_info') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid drop-off id.']);
        $existing = ba_get_dropoff($mysqli, $id, false);
        if (!$existing) json_response(['ok' => false, 'error' => 'Drop-off not found.']);
        $allowedStatuses = ['DRAFT', 'PUBLISHED', 'TEMPORARILY_CLOSED'];
        if (!in_array((string)($existing['status'] ?? ''), $allowedStatuses, true)) {
            json_response(['ok' => false, 'error' => 'Info edit only allowed for DRAFT, PUBLISHED, or TEMPORARILY_CLOSED rows (current status: ' . e($existing['status']) . ').']);
        }
        $pickupTypeVal = (string) ($_POST['pickup_type'] ?? '');
        $statusVal = (string) ($_POST['status'] ?? '');
        $open24 = isset($_POST['open_24_7']) ? 1 : 0;
        $hoursVal = trim((string) ($_POST['operation_hours'] ?? ''));
        $payload = [
            'id'                 => $id,
            'barangay_id'        => (int) ($_POST['barangay_id'] ?? 0),
            'spot_name'          => trim((string) ($_POST['spot_name'] ?? '')),
            'address'            => trim((string) ($_POST['address'] ?? '')),
            'latitude'           => (float) ($existing['latitude'] ?? 0),
            'longitude'          => (float) ($existing['longitude'] ?? 0),
            'place_osm_id'       => trim((string) ($_POST['place_osm_id'] ?? ($existing['place_osm_id'] ?? ''))),
            'pickup_type'        => $pickupTypeVal,
            'open_24_7'          => $open24,
            'operation_hours'    => $hoursVal,
            'notes_public'       => trim((string) ($_POST['notes_public'] ?? '')),
            'reference_photo'    => trim((string) ($_POST['reference_photo'] ?? '')),
            // For step edit: preserve ORIGINAL existing status UNLESS admin explicitly selected a valid status in Step 2 Info tab dropdown
            'status'             => (in_array($statusVal, $allowedStatuses, true) ? $statusVal : (string)($existing['status'] ?? 'DRAFT')),
            'accepts_bio'        => (int) ($existing['accepts_bio'] ?? 1),
            'accepts_nonbio'     => (int) ($existing['accepts_nonbio'] ?? 1),
            'accepts_recyclable' => (int) ($existing['accepts_recyclable'] ?? 1),
            'accepts_hazard'     => (int) ($existing['accepts_hazard'] ?? 0),
            'accepts_bulky'      => (int) ($existing['accepts_bulky'] ?? 0),
            'schedules'          => [],
        ];
        $validPickups = array_keys(ba_dropoff_pickup_types());
        $missing = [];
        if ($payload['barangay_id'] <= 0) $missing[] = 'Barangay (select from dropdown or place map pin so it auto-populates).';
        if (!in_array($pickupTypeVal, $validPickups, true)) $missing[] = 'Pickup / Facility type — select a valid type from the dropdown.';
        if ($payload['spot_name'] === '') $missing[] = 'Landmark / Spot name (non-empty; e.g. "J.P. Rizal end-curb near bridge").';
        if ($payload['address'] === '') $missing[] = 'Full Street Address (auto-filled from pin; should not be blank).';
        if (!in_array($payload['status'], $allowedStatuses, true)) $missing[] = 'Status — select a valid status from the dropdown (DRAFT, PUBLISHED, or TEMPORARILY_CLOSED).';
        if (!$open24 && $hoursVal === '') $missing[] = 'Hours & Days of Operation — either turn ON the Open 24/7 chip, OR use "+ Add range" below the Hours card to build at least one custom hours range (Day range + Start time + End time).';
        if ($missing) json_response(['ok' => false, 'error' => 'Missing required Info fields (EVERYTHING must be filled in Step 2 before you can save Info): ' . implode(' ', $missing)]);
        $nearestMeters = null;
        if (!ba_coord_within_marikina((float)$payload['latitude'], (float)$payload['longitude'], $mysqli, $nearestMeters)) {
            json_response(['ok' => false, 'error' => $GEOFENCE_ERR, 'nearest_meters' => $nearestMeters]);
        }
                $opErr = ba_validate_operation_hours((string)$payload['operation_hours'], (bool)$payload['open_24_7']);
        if ($opErr !== null) {
            json_response(['ok' => false, 'error' => $opErr]);
        }
        $res = ba_save_dropoff($mysqli, $payload, $adminId);
        if (!$res || !($res['ok'] ?? false)) json_response($res);
        json_response(['ok' => true, 'id' => $id, 'step_saved' => 'info_edit', 'status_used' => $payload['status']]);
    }

    if ($action === 'edit_finalize') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid drop-off id.']);
        $existing = ba_get_dropoff($mysqli, $id, false);
        if (!$existing) json_response(['ok' => false, 'error' => 'Drop-off not found.']);
        $allowedStatuses = ['DRAFT', 'PUBLISHED', 'TEMPORARILY_CLOSED'];
        if (!in_array((string)($existing['status'] ?? ''), $allowedStatuses, true)) {
            json_response(['ok' => false, 'error' => 'Finalize edit only allowed for DRAFT, PUBLISHED, or TEMPORARILY_CLOSED rows (current status: ' . e($existing['status']) . ').']);
        }
        $finalStatus = (string) ($_POST['status'] ?? '');
        if (!in_array($finalStatus, $allowedStatuses, true)) $finalStatus = (string)($existing['status'] ?? 'PUBLISHED');
        $payload = [
            'id'                 => $id,
            'barangay_id'        => (int) ($existing['barangay_id'] ?? 0),
            'spot_name'          => (string) ($existing['spot_name'] ?? ''),
            'address'            => (string) ($existing['address'] ?? ''),
            'latitude'           => (float) ($existing['latitude'] ?? 0),
            'longitude'          => (float) ($existing['longitude'] ?? 0),
            'place_osm_id'       => (string) ($existing['place_osm_id'] ?? ''),
            'pickup_type'        => (string) ($existing['pickup_type'] ?? 'STREET_END_CURBSIDE'),
            'open_24_7'          => (int) ($existing['open_24_7'] ?? 0),
            'operation_hours'    => (string) ($existing['operation_hours'] ?? ''),
            'notes_public'       => (string) ($existing['notes_public'] ?? ''),
            'reference_photo'    => (string) ($existing['reference_photo'] ?? ''),
            'status'             => $finalStatus,
            'accepts_bio'        => isset($_POST['accepts_bio']) ? 1 : 0,
            'accepts_nonbio'     => isset($_POST['accepts_nonbio']) ? 1 : 0,
            'accepts_recyclable' => isset($_POST['accepts_recyclable']) ? 1 : 0,
            'accepts_hazard'     => isset($_POST['accepts_hazard']) ? 1 : 0,
            'accepts_bulky'      => isset($_POST['accepts_bulky']) ? 1 : 0,
            'schedules'          => [],
        ];
        $acceptsAny = ($payload['accepts_bio'] + $payload['accepts_nonbio'] + $payload['accepts_recyclable'] + $payload['accepts_hazard'] + $payload['accepts_bulky']) > 0;
        if (!$acceptsAny) json_response(['ok' => false, 'error' => '<i data-lucide="x-circle" class="lucide-14"></i> Step 3 (Waste Accepted) EVERYTHING required: At least one Waste Accepted checkbox must be ticked before finalizing (Bio / Non-bio / Recyclable / Hazardous / Bulky).']);
        $validPickups = array_keys(ba_dropoff_pickup_types());
        $missing = [];
        if ($payload['barangay_id'] <= 0) $missing[] = 'Barangay is required (go back and re-save Step 2 Info first — Info MUST be completely filled before you can save Step 3).';
        if (!in_array($payload['pickup_type'], $validPickups, true)) $missing[] = 'Pickup / Facility type is required (re-save Step 2 Info first).';
        if (trim($payload['spot_name']) === '') $missing[] = 'Landmark is required (re-save Step 2 Info first).';
        if (trim($payload['address']) === '') $missing[] = 'Full address is required (re-save Step 2 Info first).';
        if (!(int)$payload['open_24_7'] && trim((string)$payload['operation_hours']) === '') $missing[] = 'Hours & Days of Operation are required (either Open 24/7 OR at least one custom hours range added in Step 2 Info).';
        if ($missing) json_response(['ok' => false, 'error' => '<i data-lucide="x-circle" class="lucide-14"></i> Step 2 Info is NOT COMPLETELY FILLED (go back & re-save Step 2): ' . implode(' ', $missing)]);
        $res = ba_save_dropoff($mysqli, $payload, $adminId);
        json_response($res);
    }

    json_response(['ok' => false, 'error' => 'Unknown action: ' . $action]);
} catch (Throwable $e) {
    $logLine = sprintf(
        '[basuraalert_dropoffs_admin] %s: %s @ %s:%d',
        get_class($e),
        $e->getMessage(),
        basename((string) $e->getFile()),
        $e->getLine()
    );
    $logPath = dirname(__DIR__, 2) . '/app_error.log';
    @file_put_contents($logPath, date('c') . ' ' . $logLine . PHP_EOL, FILE_APPEND);

    $hint = null;
    $cls = get_class($e);
    if ($cls === InvalidArgumentException::class || $cls === mysqli_sql_exception::class || $cls === RuntimeException::class) {
        $hint = $e->getMessage();
    }
    if (ini_get('display_errors')) {
        $hint = ($hint ?? '') . ' | trace=' . $e->getTraceAsString();
    }
    http_response_code(500);
    json_response(['ok' => false, 'error' => 'Unexpected error. Please try again.', 'hint' => $hint]);
}
