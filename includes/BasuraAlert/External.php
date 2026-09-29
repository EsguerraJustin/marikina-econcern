<?php
declare(strict_types=1);

/**
 * BasuraAlert — External service
 * Extracted from includes/basuraalert.php (auto-split 18 functions)
 * This file is required by includes/basuraalert.php for backwards-compat.
 */

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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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
    // An explicit $options['public_id'] wins. Callers that want a STABLE asset id
    // (e.g. a profile avatar, which must be overwritten in place rather than
    // orphaned on every change) pass one; every existing caller omits it and
    // keeps the random-suffix behaviour, which is correct for evidence photos
    // where each upload is a genuinely distinct artifact.
    if (isset($options['public_id']) && is_string($options['public_id']) && $options['public_id'] !== '') {
        $publicId = $options['public_id'];
    } elseif ($publicIdPrefix && strlen($publicIdPrefix) > 0) {
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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

