<?php

declare(strict_types=1);

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

    $lat = null;
    $lon = null;
    if (isset($_GET['lat'], $_GET['lng'])) {
        $lat = (float)$_GET['lat'];
        $lon = (float)$_GET['lng'];
    } elseif (isset($_GET['lat'], $_GET['lon'])) {
        $lat = (float)$_GET['lat'];
        $lon = (float)$_GET['lon'];
    }
    if ($lat === null || !is_finite($lat) || $lat < 5 || $lat > 21 || !is_finite($lon) || $lon < 116 || $lon > 128) {
        http_response_code(400);
        json_response(['ok' => false, 'error' => 'Invalid coordinates. Pass lat (5..21) and lng/long (116..128) within Philippines.']);
    }

    $GEOFENCE_ERROR_MARIKINA = 'This location is beyond the allowed dropoff point. Please select a location within Marikina.';
    $MARIKINA_SW_LAT = 14.5968;
    $MARIKINA_SW_LON = 121.0596;
    $MARIKINA_NE_LAT = 14.6926;
    $MARIKINA_NE_LON = 121.1441;
    $insideBBox = ($lat >= $MARIKINA_SW_LAT && $lat <= $MARIKINA_NE_LAT && $lon >= $MARIKINA_SW_LON && $lon <= $MARIKINA_NE_LON);
    if (!$insideBBox) {
        http_response_code(422);
        json_response(['ok' => false, 'error' => $GEOFENCE_ERROR_MARIKINA, 'geofence' => 'bbox_rejected']);
    }
    // 2nd tier server-side check: haversine distance to nearest Marikina barangay seed centroid
    // (<= 4200 m) to eliminate corner slivers in the bounding rectangle that actually belong
    // to Quezon City / Pasig / Antipolo / San Mateo (outside official Marikina limits).
    $seedPath = ba_storage_fixture_path('fixtures', 'ba_dropoff_points_seed_marikina_16barangays.json');
    $nearestAnywhere = INF;
    if (is_file($seedPath)) {
        $seed = @json_decode(@file_get_contents($seedPath), true);
        if (is_array($seed) && !empty($seed['dropoffs'])) {
            foreach ($seed['dropoffs'] as $d) {
                $dlat = (float)($d['latitude'] ?? 0);
                $dlon = (float)($d['longitude'] ?? 0);
                if (!$dlat || !$dlon) continue;
                $dy = ($dlat - $lat) * 111320.0;
                $dx = ($dlon - $lon) * 111320.0 * cos(deg2rad($lat));
                $dist = sqrt($dy * $dy + $dx * $dx);
                if ($dist < $nearestAnywhere) $nearestAnywhere = $dist;
            }
        }
    }
    if ($nearestAnywhere > 4200.0) {
        http_response_code(422);
        json_response(['ok' => false, 'error' => $GEOFENCE_ERROR_MARIKINA, 'geofence' => 'distance_rejected', 'nearest_meters' => round($nearestAnywhere, 0)]);
    }

    $mysqli = db();

    $brgys = [];
    $tryPoly = true;
    try {
        $bres = $mysqli->query('SELECT id, name, ST_AsText(boundary_poly) AS wkt FROM barangays WHERE boundary_poly IS NOT NULL AND ST_IsValid(boundary_poly) LIMIT 1');
        if ($bres instanceof mysqli_result) {
            while ($row = $bres->fetch_assoc()) {
                if (!empty($row['wkt'])) $brgys[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'wkt' => (string)$row['wkt']];
            }
            $bres->free();
            $bres = $mysqli->query('SELECT id, name, ST_AsText(boundary_poly) AS wkt FROM barangays WHERE boundary_poly IS NOT NULL AND ST_IsValid(boundary_poly)');
            if ($bres instanceof mysqli_result) {
                $brgys = [];
                while ($row = $bres->fetch_assoc()) {
                    if (!empty($row['wkt'])) $brgys[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'wkt' => (string)$row['wkt']];
                }
            }
        }
    } catch (Throwable $_) {
        $tryPoly = false;
        $brgys = [];
    }
    $matchedBarangayId = null;
    $matchedBarangayName = null;
    if ($tryPoly && count($brgys) > 0) {
        $point = "POINT($lon $lat)";
        foreach ($brgys as $b) {
            try {
                $wktEsc = $mysqli->real_escape_string($b['wkt']);
                $sql = "SELECT ST_Contains(ST_GeomFromText('{$wktEsc}'), ST_GeomFromText('{$point}')) AS inside";
                $r = $mysqli->query($sql);
                if ($r instanceof mysqli_result) {
                    $x = $r->fetch_assoc();
                    if (!empty($x['inside'])) {
                        $matchedBarangayId = $b['id'];
                        $matchedBarangayName = $b['name'];
                        break;
                    }
                }
            } catch (Throwable $_) {}
        }
    }

    $nearestSeed = null;
    $nearestDistMeters = INF;
    $seedPath = ba_storage_fixture_path('fixtures', 'ba_dropoff_points_seed_marikina_16barangays.json');
    if (is_file($seedPath)) {
        $seed = @json_decode(@file_get_contents($seedPath), true);
        if (is_array($seed) && !empty($seed['dropoffs'])) {
            foreach ($seed['dropoffs'] as $d) {
                $dlat = (float)($d['latitude'] ?? 0);
                $dlon = (float)($d['longitude'] ?? 0);
                if (!$dlat || !$dlon) continue;
                $dy = ($dlat - $lat) * 111320.0;
                $dx = ($dlon - $lon) * 111320.0 * cos(deg2rad($lat));
                $dist = sqrt($dy * $dy + $dx * $dx);
                if ($dist < $nearestDistMeters) {
                    $nearestDistMeters = $dist;
                    $nearestSeed = [
                        'barangay_name' => (string)($d['barangay_name'] ?? ''),
                        'landmark' => (string)($d['spot_name'] ?? ''),
                        'address' => (string)($d['address'] ?? ''),
                        'distance_meters' => round($dist, 0),
                    ];
                }
            }
        }
    }

    if ($matchedBarangayName === null && is_array($nearestSeed) && !empty($nearestSeed['barangay_name'])) {
        $b = ba_get_barangay_by_name($mysqli, $nearestSeed['barangay_name']);
        if (is_array($b)) {
            $matchedBarangayId = (int)$b['id'];
            $matchedBarangayName = (string)$b['name'];
        }
    }

    $nom = null;
    $mock = ba_geocode_reverse_mock($lat, $lon);
    if (!empty($mock['ok']) && !empty($mock['nearest'])) {
        $nom = [
            'display_name' => (string)($mock['nearest']['display_name'] ?? ''),
            'address' => [
                'barangay' => (string)($mock['nearest']['barangay'] ?? ''),
                'city' => (string)($mock['nearest']['city'] ?? ''),
                'note' => (string)($mock['nearest']['note'] ?? ''),
            ],
            'provider' => 'mock_local_fixture',
        ];
        if ($matchedBarangayName === null && !empty($mock['nearest']['barangay'])) {
            $b = ba_get_barangay_by_name($mysqli, (string)$mock['nearest']['barangay']);
            if (is_array($b)) { $matchedBarangayId = (int)$b['id']; $matchedBarangayName = (string)$b['name']; }
        }
    }

    $landmark = '';
    $address = '';
    // ALWAYS start with nearest-seed's REAL fixture address first (corner / MRF / curb texts
    // from the seeded JSON). Never go directly to the generic "Approximate location within
    // Marikina City" display_name produced by ba_geocode_reverse_mock — that string is a
    // useless last-resort fallback and upsets users because it contains no street detail.
    if (is_array($nearestSeed) && !empty($nearestSeed['address'])) {
        $address = $nearestSeed['address'];
    }
    if (is_array($nearestSeed) && !empty($nearestSeed['landmark'])) {
        $landmark = $nearestSeed['landmark'];
    }
    // Only if the nearest seed fixture was empty (shouldn't happen with our seed) fall back
    // to the mock Nominatim display_name, but SKIP any display_name that starts with the
    // useless generic "Approximate location within Marikina City" string.
    if ($address === '' && is_array($nom) && !empty($nom['display_name']) && stripos((string)$nom['display_name'], 'Approximate location within Marikina') !== 0) {
        $address = (string)$nom['display_name'];
    }
    if ($landmark === '' && is_array($nom) && !empty($nom['display_name']) && stripos((string)$nom['display_name'], 'Approximate location within Marikina') !== 0) {
        $parts = array_filter([
            trim(explode(',', (string)($nom['display_name'] ?? ''), 2)[0] ?? ''),
        ]);
        $landmark = implode(' ', $parts);
    }
    // Final catch-all: if seed for some reason still empty, compose a concrete street
    // descriptor from the matched barangay + pinned coordinates (never the generic phrase).
    if ($address === '' && ($matchedBarangayName || is_array($nearestSeed))) {
        $bname = $matchedBarangayName ?? ($nearestSeed['barangay_name'] ?? 'Marikina City');
        $latStr = number_format($lat, 6, '.', '');
        $lonStr = number_format($lon, 6, '.', '');
        $address = trim("Near {$latStr}, {$lonStr} — {$bname}, Marikina City, Metro Manila, Philippines");
    }
    if ($landmark === '' && ($matchedBarangayName || is_array($nearestSeed))) {
        $landmark = trim(($matchedBarangayName ?? ($nearestSeed['barangay_name'] ?? '')) . ' drop-off point');
    }

    json_response([
        'ok' => true,
        'lat' => $lat,
        'lng' => $lon,
        'matched_barangay' => $matchedBarangayId === null ? null : ['id' => $matchedBarangayId, 'name' => $matchedBarangayName],
        'nearest_seed' => $nearestSeed,
        'nearest_distance_meters' => is_finite($nearestDistMeters) ? round($nearestDistMeters, 0) : null,
        'reverse_geocode' => $nom,
        'landmark' => $landmark,
        'address' => $address,
    ]);
} catch (Throwable $e) {
    $logLine = sprintf(
        '[ba_geocode_reverse_admin] %s: %s @ %s:%d',
        get_class($e),
        $e->getMessage(),
        basename((string) $e->getFile()),
        $e->getLine()
    );
    @file_put_contents(dirname(__DIR__, 2) . '/app_error.log', date('c') . ' ' . $logLine . PHP_EOL, FILE_APPEND);
    $hint = null;
    if (ini_get('display_errors')) $hint = $e->getMessage() . ' | ' . $e->getTraceAsString();
    http_response_code(500);
    json_response(['ok' => false, 'error' => 'Reverse geocode failed.', 'hint' => $hint]);
}
