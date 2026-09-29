<?php

declare(strict_types=1);

/**
 * Integration status — read-only health check for the third-party APIs.
 *
 * InfinityFree's free tier has no SSH and no CLI, so there is otherwise no way
 * to confirm on the host that Brevo, TextBee, Cloudinary, OpenWeather and
 * Calendarific are actually answering. This page is that check, and it only ever
 * performs cheap read-only requests: no SMS is sent, no email is sent and
 * nothing is uploaded to Cloudinary. Each provider has at least one endpoint
 * that validates credentials without consuming a paid or rate-limited quota
 * unit, and those are the ones used here.
 *
 * Admin-gated. It reports configuration, cache state and the most recent
 * diagnostic lines from app_error.log.
 *
 * Brevo (GET /v3/account) and Cloudinary (GET /config) are validated on every
 * load because neither endpoint is rate-limited. OpenWeather and Calendarific
 * ARE metered, so they are only called with an explicit ?probe=1 - by default
 * the page reports the key's presence and the cache state, which answers
 * "is it working" without spending quota.
 */

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

require_admin_login();

$pageTitle = 'Integration Status';
$activeNav = '';

function _status_http(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [
        'ok' => $raw !== false && $raw !== '' && $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $err,
        'body' => is_string($raw) ? substr($raw, 0, 300) : '',
    ];
}

/** A provider is "configured" when the constant exists and is non-empty. */
function _status_configured(string $constant): bool
{
    return defined($constant) && (string) constant($constant) !== '';
}

$checks = [];

/* ---------------------------------------------------------------- Brevo
   GET /v3/account authenticates and returns plan info without sending mail. */
$brevo = ['configured' => _status_configured('BREVO_API_KEY'), 'probe' => null, 'detail' => null];
if ($brevo['configured']) {
    $r = _status_http('https://api.brevo.com/v3/account', [
        'accept: application/json',
        'api-key: ' . (string) BREVO_API_KEY,
    ]);
    $brevo['probe'] = $r;
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        $brevo['detail'] = is_array($d)
            ? (($d['companyName'] ?? 'account') . ' — sender ' . ($d['email'] ?? 'n/a'))
            : null;
    } else {
        $brevo['detail'] = 'HTTP ' . $r['status'] . ($r['error'] !== '' ? ' · ' . $r['error'] : '');
    }
}
$checks[] = ['name' => 'Brevo (email)', 'key' => 'BREVO_API_KEY', 'state' => $brevo, 'ok' => $brevo['probe']['ok'] ?? null];

/* --------------------------------------------------------------- TextBee
   The gateway has no unauthenticated health endpoint, so only local
   configuration is asserted here. Actual delivery is proven by the sms_*
   lines in app_error.log, which are summarised below. */
$sms = [
    'configured' => _status_configured('SMS_TEXTBEE_API_KEY'),
    'device' => _status_configured('SMS_TEXTBEE_DEVICE_ID') ? (string) SMS_TEXTBEE_DEVICE_ID : null,
    'endpoint' => defined('SMS_TEXTBEE_ENDPOINT') ? (string) SMS_TEXTBEE_ENDPOINT : null,
];
$checks[] = ['name' => 'TextBee (SMS)', 'key' => 'SMS_TEXTBEE_API_KEY', 'state' => $sms, 'ok' => $sms['configured'] ? true : false];

/* ------------------------------------------------------------ Cloudinary
   The ping endpoint is unauthenticated and always 401s, so it proves nothing.
   /config with HTTP Basic auth validates the key and secret together without
   creating an asset. */
$cld = [
    'configured' => _status_configured('CLOUDINARY_CLOUD_NAME') && _status_configured('CLOUDINARY_API_SECRET'),
    'probe' => null,
    'detail' => null,
];
if ($cld['configured']) {
    $r = _status_http('https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . '/config', [
        'Authorization: Basic ' . base64_encode(CLOUDINARY_API_KEY . ':' . CLOUDINARY_API_SECRET),
    ]);
    $cld['probe'] = $r;
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        $cld['detail'] = 'cloud "' . (is_array($d) ? ($d['cloud_name'] ?? '?') : '?') . '" — key + secret valid';
    } else {
        $cld['detail'] = 'HTTP ' . $r['status'] . ($r['error'] !== '' ? ' · ' . $r['error'] : '');
    }
}
$checks[] = ['name' => 'Cloudinary (images)', 'key' => 'CLOUDINARY_API_*', 'state' => $cld, 'ok' => $cld['probe']['ok'] ?? null];

/* ------------------------------------------------------------ OpenWeather
   Gated behind ?probe=1 for the same reason as Calendarific. The free tier is
   1,000 calls/day and /weather is a metered call, so probing it on every admin
   page view spends quota to answer a question the cache state below already
   answers for free. */
$ow = [
    'configured' => _status_configured('OPENWEATHER_API_KEY'),
    'probe' => null,
    'detail' => null,
];
$wantsProbe = isset($_GET['probe']) && $_GET['probe'] === '1';
if ($ow['configured'] && $wantsProbe) {
    $r = _status_http('https://api.openweathermap.org/data/2.5/weather?lat=14.6247&lon=121.0993&appid=' . urlencode((string) OPENWEATHER_API_KEY) . '&units=metric&lang=en');
    $ow['probe'] = $r;
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        $ow['detail'] = is_array($d)
            ? ($d['name'] ?? '?') . ' — ' . round((float) ($d['main']['temp'] ?? 0), 1) . '°C, ' . ($d['weather'][0]['main'] ?? '?')
            : null;
    } else {
        $d = json_decode($r['body'], true);
        $ow['detail'] = 'HTTP ' . $r['status'] . (is_array($d) && isset($d['message']) ? ' — ' . $d['message'] : '');
    }
} elseif ($ow['configured']) {
    $ow['detail'] = 'Not probed (each call spends free-tier quota). Open with ?probe=1 to force one.';
}
$checks[] = ['name' => 'OpenWeather', 'key' => 'OPENWEATHER_API_KEY', 'state' => $ow, 'ok' => $ow['probe']['ok'] ?? null];

/* ----------------------------------------------------------- Calendarific
   A request here consumes one of the ~500 monthly free-tier calls, so the
   cached result is reported instead by default. Pass ?probe=1 to spend one to
   confirm the key is still valid. */
$cal = [
    'configured' => _status_configured('CALENDARIFIC_API_KEY'),
    'probe' => null,
    'detail' => null,
];
if ($cal['configured'] && $wantsProbe) {
    $r = _status_http('https://calendarific.com/api/v2/holidays?api_key=' . urlencode((string) CALENDARIFIC_API_KEY) . '&country=PH&year=' . (int) date('Y') . '&type=national');
    $cal['probe'] = $r;
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        $cal['detail'] = 'HTTP 200 — ' . count(is_array($d) && isset($d['response']['holidays']) ? $d['response']['holidays'] : []) . ' holidays returned';
    } else {
        $d = json_decode($r['body'], true);
        $cal['detail'] = 'HTTP ' . $r['status'] . (is_array($d) && isset($d['meta']['error_type']) ? ' — ' . $d['meta']['error_type'] : '');
    }
} elseif ($cal['configured']) {
    $cal['detail'] = 'Not probed (each call spends scarce free-tier quota). Open with ?probe=1 to force one.';
}
$checks[] = ['name' => 'Calendarific (holidays)', 'key' => 'CALENDARIFIC_API_KEY', 'state' => $cal, 'ok' => $cal['probe']['ok'] ?? null];

/* ------------------------------------------------------------------ Cache */
$year = (int) date('Y');
$cacheItems = [
    ['OpenWeather current', ba_cache_inspect('openweather_current_14_6247_121_0993'), defined('OPENWEATHER_CURRENT_CACHE_TTL') ? (int) OPENWEATHER_CURRENT_CACHE_TTL : 600],
    ['OpenWeather forecast', ba_cache_inspect('openweather_forecast_14_6247_121_0993'), defined('OPENWEATHER_FORECAST_CACHE_TTL') ? (int) OPENWEATHER_FORECAST_CACHE_TTL : 1800],
    ['Calendarific holidays', ba_cache_inspect('calendarific_ph_' . $year), defined('CALENDARIFIC_CACHE_TTL') ? (int) CALENDARIFIC_CACHE_TTL : 86400],
    ['Calendarific backoff', ba_cache_inspect('calendarific_ph_' . $year . '_fail'), defined('CALENDARIFIC_FAIL_TTL') ? (int) CALENDARIFIC_FAIL_TTL : 300],
];

/* --------------------------------------------------- Recent log diagnostics */
$recentEvents = [];
$logPath = dirname(__DIR__) . '/app_error.log';
if (is_file($logPath) && is_readable($logPath)) {
    $handle = @fopen($logPath, 'r');
    if ($handle !== false) {
        $lines = [];
        // Read the tail only; the file grows without bound and a full read of a
        // multi-megabyte log on a page load is exactly the kind of thing that
        // times out on shared hosting.
        fseek($handle, max(0, (int) filesize($logPath) - 262144));
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed !== '' && str_contains($trimmed, '"event"')) {
                $lines[] = $trimmed;
            }
        }
        fclose($handle);
        foreach (array_slice($lines, -60) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['event'])) {
                $recentEvents[] = $decoded;
            }
        }
    }
}

$counts = [];
foreach ($recentEvents as $ev) {
    $key = (string) ($ev['event'] ?? '');
    if ($key !== '') {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
}
ksort($counts);

function _status_badge($ok, $configured) {
    if (!$configured) {
        return ['label' => 'Not configured', 'class' => 'badge bg-secondary'];
    }
    if ($ok === null) {
        return ['label' => 'Configured', 'class' => 'badge bg-secondary'];
    }
    return $ok
        ? ['label' => 'Live', 'class' => 'badge bg-success']
        : ['label' => 'Failing', 'class' => 'badge bg-danger'];
}

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';
?>

<?= csrf_header_meta() ?>

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Integration Status</h1>
            <p class="text-muted small mb-0">Read-only health check. This page never sends an SMS, sends an email, or uploads a file.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/admin/dashboard.php')) ?>">Dashboard</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('/admin/integration_status.php')) ?>">Refresh</a>
        </div>
    </div>

    <?php foreach ($checks as $check) :
        $badge = _status_badge($check['ok'], !empty($check['state']['configured']));
        ?>
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div style="min-width:220px">
                    <div class="fw-bold"><?= e($check['name']) ?> <span class="badge bg-light text-dark border ms-1"><?= e($check['key']) ?></span></div>
                    <?php if (!empty($check['state']['detail'])) : ?>
                        <div class="small text-muted mt-1"><?= e((string) $check['state']['detail']) ?></div>
                    <?php endif; ?>
                    <?php if (isset($check['state']['probe']) && is_array($check['state']['probe']) && $check['state']['probe']['status'] !== 0) : ?>
                        <div class="small text-muted mt-1">HTTP <?= (int) $check['state']['probe']['status'] ?></div>
                    <?php endif; ?>
                </div>
                <span class="<?= e($badge['class']) ?>"><?= e($badge['label']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card mb-3">
        <div class="card-header fw-bold">Cache state <span class="text-muted fw-normal small">storage/cache/ — written on demand, not served over HTTP</span></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Entry</th><th>State</th><th>Age</th><th>Size</th><th>TTL</th></tr></thead>
                <tbody>
                <?php foreach ($cacheItems as $item) :
                    $info = $item[1];
                    $ttl = $item[2];
                    $fresh = $info['exists'] && $info['valid'] && $info['age'] !== null && $info['age'] < $ttl;
                    ?>
                    <tr>
                        <td><?= e($item[0]) ?></td>
                        <td>
                            <?php if (!$info['exists']) : ?>
                                <span class="badge bg-light text-dark border">Absent — will be created on next page view</span>
                            <?php elseif (!$info['valid']) : ?>
                                <span class="badge bg-danger">Corrupt — will be refetched</span>
                            <?php elseif ($fresh) : ?>
                                <span class="badge bg-success">Fresh</span>
                            <?php else : ?>
                                <span class="badge bg-warning text-dark">Stale — will refresh</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $info['age'] === null ? '—' : e($info['age'] . 's') ?></td>
                        <td><?= $info['size'] === null ? '—' : e((string) $info['size'] . ' B') ?></td>
                        <td class="text-muted"><?= e((string) $ttl . 's') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header fw-bold">Recent integration events <span class="text-muted fw-normal small">tail of app_error.log</span></div>
        <div class="card-body">
            <?php if ($counts === []) : ?>
                <p class="text-muted small mb-0">No integration events logged yet. Load a dashboard that reads weather or holidays, then refresh this page.</p>
            <?php else : ?>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach ($counts as $event => $n) : ?>
                        <span class="badge bg-light text-dark border"><?= e($event) ?> &times;<?= (int) $n ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm font-monospace mb-0" style="font-size:.78rem">
                        <thead><tr><th>Time</th><th>Event</th><th>Detail</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($recentEvents, -12) as $ev) :
                            $detail = $ev;
                            unset($detail['event'], $detail['ts'], $detail['remote_ip']);
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= e(substr((string) ($ev['ts'] ?? ''), 11, 8)) ?></td>
                                <td class="text-nowrap"><?= e((string) ($ev['event'] ?? '')) ?></td>
                                <td><?= e(mb_substr(json_encode($detail, JSON_UNESCAPED_SLASHES) ?: '', 0, 160)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/partials/admin_shell_end.php'; ?>
