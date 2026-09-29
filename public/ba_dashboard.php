<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$_ba_mysqli = db();
$_ba_user = current_user($_ba_mysqli);
if (!$_ba_user || ba_user_barangay_id($_ba_mysqli, $_ba_user) === null) {
    $_SESSION['flash_error'] = 'Set your barangay first in Profile before viewing the BasuraAlert Dashboard — schedule information is personalized per barangay.';
    $_back = $_SERVER['REQUEST_URI'] ?? '';
    redirect(app_url('/public/profile.php') . ($_back !== '' ? '?redirect_back=' . urlencode($_back) : ''));
}

$pageTitle = 'BasuraAlert Dashboard';
$activeNav = 'ba_dashboard';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$userId = (int) $user['id'];
$barangayId = ba_user_barangay_id($mysqli, $user);
$nextCollection = $barangayId > 0 ? ba_next_collection_for($mysqli, $barangayId) : null;
$unreadCount = ba_count_unread_notifications($mysqli, $userId);
$announcements = ba_list_announcements($mysqli, $barangayId > 0 ? $barangayId : null);
$recentReports = ba_list_reports($mysqli, $userId, null, null, null, 5);

$_todayStr = date('Y-m-d');
$_todayTs = strtotime($_todayStr);
$_holidayData = ba_fetch_ph_holidays_mock((int)date('Y'));
$_holidayList = !empty($_holidayData['ok']) && is_array($_holidayData['holidays'] ?? null) ? $_holidayData['holidays'] : [];
$_todayHoliday = null;
$_nextHoliday = null;
foreach ($_holidayList as $_h) {
    if (($_h['date'] ?? '') === $_todayStr) { $_todayHoliday = $_h; break; }
}
if ($_todayHoliday === null) {
    foreach ($_holidayList as $_h) {
        $_hd = $_h['date'] ?? '';
        if ($_hd === '' || $_hd < $_todayStr) continue;
        $_diff = (int)round((strtotime($_hd) - $_todayTs) / 86400);
        if ($_diff > 0 && $_diff <= 7) { $_nextHoliday = array_merge($_h, ['days_until' => $_diff]); break; }
    }
}
$_holidaysByMonth = array_fill(1, 12, []);
foreach ($_holidayList as $_h) {
    $_d = $_h['date'] ?? '';
    if ($_d === '' || strlen($_d) < 7) continue;
    $_m = (int)substr($_d, 5, 2);
    if ($_m >= 1 && $_m <= 12) $_holidaysByMonth[$_m][] = $_h;
}
$_monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
$_curYear = (int)date('Y');

$_weatherData = ba_fetch_weather_mock('Marikina', 'PH');
$_weatherOk = !empty($_weatherData['ok']);
$_weatherCurrent = $_weatherOk && is_array($_weatherData['current'] ?? null) ? $_weatherData['current'] : null;
$_weatherForecast = $_weatherOk && is_array($_weatherData['forecast_days'] ?? null) ? $_weatherData['forecast_days'] : [];
$_weatherCity = (string)($_weatherData['city'] ?? 'Marikina');
$_weatherAdvisoryClass = 'text-muted';
$_weatherAdvisoryBadge = '';
if ($_weatherCurrent !== null) {
    $_adv = (string)($_weatherCurrent['advisory'] ?? '');
    if (stripos($_adv, 'ADVISORY:') === 0) {
        if (stripos($_adv, 'Typhoon') !== false || stripos($_adv, 'High winds') !== false) {
            $_weatherAdvisoryClass = 'text-danger fw-semibold';
            $_weatherAdvisoryBadge = '<span class="badge bg-danger mb-1"><i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> SEVERE</span>';
        } elseif (stripos($_adv, 'Thunderstorm') !== false) {
            $_weatherAdvisoryClass = 'text-warning fw-semibold';
            $_weatherAdvisoryBadge = '<span class="badge bg-warning text-dark mb-1"><i data-lucide="cloud-lightning-rain" class="lucide-14"></i> Weather Alert</span>';
        } elseif (stripos($_adv, 'Rain') !== false) {
            $_weatherAdvisoryClass = 'text-info fw-semibold';
            $_weatherAdvisoryBadge = '<span class="badge bg-info mb-1" style="color:#fff!important;"><i data-lucide="cloud-rain" class="lucide-14" style="color:#fff!important;stroke:#fff!important;fill:none!important;"></i> Rain Notice</span>';
        } elseif (stripos($_adv, 'Extreme heat') !== false) {
            $_weatherAdvisoryClass = 'text-warning fw-semibold';
            $_weatherAdvisoryBadge = '<span class="badge bg-warning text-dark mb-1"><i data-lucide="flame" class="lucide-14"></i> Heat Advisory</span>';
        }
    }
}

$statusHtml = '';
if (!$barangayId) {
    $profileUrl = e(app_url('/public/profile.php'));
    $statusHtml = '<div class="alert alert-warning"><div class="fw-semibold">Set your barangay first</div><div class="small">BasuraAlert uses your barangay to show your personal collection schedule. <a class="alert-link" href="' . $profileUrl . '">Go to Profile →</a></div></div>';
} elseif (!is_array($nextCollection)) {
    $statusHtml = '<div class="alert alert-info"><div class="fw-semibold">No upcoming collection</div><div class="small">No Published schedule found for your barangay yet. Check back soon or view the full schedule list.</div></div>';
} else {
    $status = ba_collection_status_label((int) $nextCollection['days_until']);
    $nextDate = strtotime((string) $nextCollection['next_date']);
    $wasteType = (string) $nextCollection['waste_type'];
    $wasteMeta = ba_waste_meta($wasteType);
    $wasteBadge = ba_waste_badge($wasteType);
    $wasteTip = '';
    if (!empty($wasteMeta['guidance'])) {
        $wasteTip = '<div class="small mt-2"><span class="fw-semibold text-muted">' . e($wasteMeta['icon'] . ' ' . $wasteMeta['short']) . ':</span> ' . e($wasteMeta['guidance']) . '</div>';
        if (!empty($wasteMeta['examples'])) {
            $wasteTip .= '<div class="small text-muted mt-1"><span class="fw-semibold">Examples:</span> ' . e($wasteMeta['examples']) . '.</div>';
        }
    }
    $statusHtml = '<div class="mc-ba-card mc-ba-card-primary"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2"><div style="flex:1 1 60%;min-width:0"><div class="text-muted small mb-1">Next collection for <strong>' . e($nextCollection['barangay_name'] ?? '') . '</strong></div><div class="display-6 fw-bold text-primary" style="font-size:clamp(1.6rem,5.5vw,2.5rem)!important;line-height:1.1!important">' . date('M j, Y', (int) $nextDate) . '</div><div class="text-muted small">' . date('l', (int) $nextDate) . ' · ' . ba_format_time($nextCollection['time_start'] ?? null) . ' – ' . ba_format_time($nextCollection['time_end'] ?? null) . '</div></div><div class="text-end" style="flex:0 1 auto;min-width:0"><span class="badge bg-' . $status['class'] . ' mb-1 d-inline-flex">' . e($status['text']) . '</span><br>' . $wasteBadge . '</div></div>' . $wasteTip;
    if (!empty($nextCollection['notes'])) {
        $statusHtml .= '<div class="mt-3 small border-top pt-3 text-muted">' . e((string) $nextCollection['notes']) . '</div>';
    }
    $statusHtml .= '<div class="mt-3 mc-action-row"><a class="btn btn-sm btn-outline-primary" href="' . e(app_url('/public/ba_schedule.php')) . '">View full schedule</a><a class="btn btn-sm btn-outline-secondary" href="' . e(app_url('/public/ba_notifications.php')) . '">Notifications ' . ($unreadCount > 0 ? '<span class="badge bg-danger" style="margin-left:6px!important">' . $unreadCount . '</span>' : '') . '</a></div></div></div>';
}

$_calBtn = '<button type="button" class="btn btn-sm ' . ($_todayHoliday !== null ? 'btn-holiday-onbanner' : 'btn-outline-primary') . '" data-bs-toggle="modal" data-bs-target="#phHolidayCalendarModal">
    <i data-lucide="calendar-days" class="lucide-14"></i> Holiday Calendar
</button>';
if ($_todayHoliday !== null) {
    $_hName = e((string)$_todayHoliday['name']);
    $_hType = e((string)($_todayHoliday['type'] ?? 'Holiday'));
    $_hNotes = !empty($_todayHoliday['notes']) ? '<div class="small mt-1 opacity-90">' . e(mb_substr((string)$_todayHoliday['notes'], 0, 180)) . '</div>' : '';
    $_isRegular = stripos((string)($_todayHoliday['type'] ?? ''), 'Regular') !== false;
    $_badgeClass = $_isRegular ? 'bg-danger' : 'bg-warning text-dark';
    $_bannerBg = $_isRegular
        ? 'background: #dc2626;'
        : 'background: #d97706;';
    $_holidayBlock = '<div class="mc-ba-holiday-card text-white" style="' . $_bannerBg . ';width:100%;min-width:0">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3" style="min-width:0;width:100%">
                <div style="flex:1 1 60%;min-width:0;overflow-wrap:break-word">
                    <div class="d-flex align-items-center gap-2 mb-2" style="flex-wrap:wrap">
                        <span class="badge ' . $_badgeClass . '" style="border:1px solid rgba(255,255,255,.3)">' . $_hType . '</span>
                        <span class="small opacity-90 fw-semibold"><i data-lucide="party-popper" class="lucide-14"></i> Today · ' . date('F j, Y') . '</span>
                    </div>
                    <div class="h4 fw-bold mb-1" style="font-size:clamp(1.1rem,3.5vw,1.4rem)!important;line-height:1.2!important;overflow-wrap:break-word"><i data-lucide="calendar-days" class="lucide-14"></i> ' . $_hName . '</div>
                    <div class="small opacity-95" style="overflow-wrap:break-word">Collection schedule may be suspended or adjusted. Check announcements for barangay-level updates.</div>
                    ' . $_hNotes . '
                </div>
                <div class="mc-action-row">
                    <a class="btn btn-sm btn-holiday-onbanner" href="' . e(app_url('/public/ba_announcements.php')) . '">Check announcements →</a>
                    ' . $_calBtn . '
                </div>
            </div>
        </div>
    </div>';
} elseif ($_nextHoliday !== null) {
    $_hName = e((string)$_nextHoliday['name']);
    $_hType = e((string)($_nextHoliday['type'] ?? 'Holiday'));
    $_hTs = strtotime((string)($_nextHoliday['date'] ?? ''));
    $_hDate = ($_hTs !== false) ? date('M j, Y', $_hTs) : '—';
    $_hDays = (int)($_nextHoliday['days_until'] ?? 0);
    $_hDaysText = $_hDays === 1 ? 'Tomorrow' : ('In ' . $_hDays . ' days');
    $_holidayBlock = '<div class="mc-ba-holiday-card" role="alert" style="background:#fffbeb;border:1px solid #F59E0B;width:100%;min-width:0;overflow-wrap:break-word">
        <div class="card-body d-grid d-sm-block gap-3">
            <div class="d-flex flex-wrap align-items-start gap-2 mb-sm-2" style="min-width:0;width:100%">
                <div style="flex:1 1 100%;min-width:0;overflow-wrap:break-word">
                    <span class="fw-semibold d-inline-block mb-1"><i data-lucide="calendar-days" class="lucide-14"></i> Upcoming Holiday · <span class="badge bg-warning text-dark border">' . $_hDaysText . '</span></span>
                    <div style="overflow-wrap:break-word"><strong>' . $_hName . '</strong> <span class="text-muted small">(' . $_hType . ')</span> on ' . $_hDate . ' — collection may be rescheduled.</div>
                </div>
            </div>
            <div class="mc-action-row">
                <a class="btn btn-sm btn-outline-warning" href="' . e(app_url('/public/ba_schedule.php')) . '">View schedule →</a>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#phHolidayCalendarModal"><i data-lucide="calendar-days" class="lucide-14"></i> Full calendar</button>
            </div>
        </div>
    </div>';
} else {
    $totalHolidays = count($_holidayList);
    $_holidayBlock = '<div class="mc-ba-holiday-card" style="width:100%;min-width:0">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3" style="width:100%;min-width:0">
                <div class="d-flex flex-wrap align-items-center gap-3" style="flex:1 1 60%;min-width:0">
                    <div class="display-6 flex-shrink-0"><i data-lucide="calendar-range" class="lucide-14"></i></div>
                    <div style="min-width:0;overflow-wrap:break-word">
                        <div class="h6 fw-bold mb-1" style="overflow-wrap:break-word">Philippine Holidays ' . (int)$_curYear . '</div>
                        <div class="small text-muted" style="overflow-wrap:break-word">No holiday today. Browse the official list of ' . $totalHolidays . ' holidays across all 12 months.</div>
                    </div>
                </div>
                <div class="mc-action-row">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#phHolidayCalendarModal">
                        <i data-lucide="calendar-days" class="lucide-14"></i> Holiday Calendar
                    </button>
                    <a class="btn btn-outline-secondary" href="' . e(app_url('/public/ba_schedule.php')) . '">My schedule</a>
                </div>
            </div>
        </div>
    </div>';
}

$_weatherCard = '';
if ($_weatherCurrent !== null) {
    $_wIcon = $_weatherCurrent['icon'] ?? '<i data-lucide="cloud-sun" class="lucide-14"></i>';
    $_wTemp = (float)($_weatherCurrent['temp_c'] ?? 0);
    $_wFeels = (float)($_weatherCurrent['feels_like_c'] ?? 0);
    $_wHum = (int)($_weatherCurrent['humidity_pct'] ?? 0);
    $_wWind = (float)($_weatherCurrent['wind_kph'] ?? 0);
    $_wCond = e((string)($_weatherCurrent['condition'] ?? 'Clear'));
    $_wAdv = e((string)($_weatherCurrent['advisory'] ?? 'Normal collection conditions.'));
    $_wCityE = e($_weatherCity);
    $_fcHtml = '';
    foreach ($_weatherForecast as $_fc) {
        $_fcTs = strtotime((string)($_fc['date'] ?? ''));
        $_fcDate = ($_fcTs !== false) ? date('M j', $_fcTs) : '—';
        $_fcMax = (float)($_fc['max_c'] ?? 0);
        $_fcMin = (float)($_fc['min_c'] ?? 0);
        $_fcCondE = e((string)($_fc['condition'] ?? ''));
        $_fcRain = (int)($_fc['rain_chance_pct'] ?? 0);
        $_fcHtml .= '<div class="mc-ba-forecast-day">
            <div class="mc-ba-forecast-date">' . $_fcDate . '</div>
            <div class="mc-ba-forecast-cond">' . $_fcCondE . '</div>
            <div class="mc-ba-forecast-temp"><span class="mc-ba-temp-hi">' . $_fcMax . '°</span> / <span class="mc-ba-temp-lo">' . $_fcMin . '°</span></div>
            <div class="mc-ba-forecast-rain"><i data-lucide="droplets" class="lucide-14"></i> ' . $_fcRain . '% rain</div>
        </div>';
    }
    $_weatherCard = '<div class="mc-ba-card mc-ba-weather-card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="text-muted small fw-semibold mb-1"><i data-lucide="map-pin" class="lucide-14"></i> Weather · ' . $_wCityE . '</div>
                    ' . $_weatherAdvisoryBadge . '
                </div>
                <div class="text-muted small">' . date('g:i A') . '</div>
            </div>
            <div class="mc-ba-weather-now">
                <div class="mc-ba-weather-icon">' . $_wIcon . '</div>
                <div>
                    <div class="mc-ba-weather-temp">' . $_wTemp . '°C</div>
                    <div class="mc-ba-weather-cond">Feels like ' . $_wFeels . '°C · ' . $_wCond . '</div>
                </div>
            </div>
            <div class="mc-ba-weather-meta">
                <div class="mc-ba-weather-stat"><strong><i data-lucide="droplets" class="lucide-14"></i> ' . $_wHum . '%</strong><span>Humidity</span></div>
                <div class="mc-ba-weather-stat"><strong><i data-lucide="wind" class="lucide-14"></i> ' . $_wWind . '</strong><span>Wind km/h</span></div>
                <div class="mc-ba-weather-stat"><strong><i data-lucide="thermometer" class="lucide-14"></i> ' . $_wFeels . '°</strong><span>Feels</span></div>
            </div>
            <div class="' . $_weatherAdvisoryClass . ' small border-top pt-2 mb-3">' . $_wAdv . '</div>
            <div class="fw-bold small mb-2 text-muted">2-Day Outlook</div>
            <div class="mc-ba-forecast">' . $_fcHtml . '</div>
        </div>
    </div>';
} elseif (!$_weatherOk) {
    $_weatherErr = isset($_weatherData['error']) ? e(mb_substr((string)$_weatherData['error'], 0, 120)) : 'Weather data temporarily unavailable.';
    $_weatherCard = '<div class="mc-ba-card mc-ba-weather-card">
        <div class="card-body">
            <div class="d-flex gap-2 align-items-start">
                <div class="text-muted"><i data-lucide="cloud-sun" class="lucide-14"></i></div>
                <div>
                    <div class="fw-bold small">Weather</div>
                    <div class="small text-muted">' . $_weatherErr . '</div>
                </div>
            </div>
        </div>
    </div>';
}

?>
<?= csrf_header_meta() ?>

<div class="mc-ba-dashboard-page">

<div class="mc-ba-dash-hero">
    <div class="mc-ba-dash-hero-inner">
        <div class="mc-ba-dash-hero-title-row">
            <div class="mc-ba-dash-hero-icon-wrap">
                <i data-lucide="layout-dashboard" class="lucide"></i>
            </div>
            <div class="mc-ba-dash-hero-title">
                <h1>BasuraAlert Dashboard</h1>
                <p>Garbage collection schedule and updates for your barangay</p>
            </div>
        </div>
    </div>
</div>

<div class="mc-ba-dashboard-grid">
    <div class="mc-ba-dashboard-stack">
        <?php echo $_holidayBlock; ?>
        <?= $statusHtml ?>
        <div class="mc-ba-card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <div class="mc-ba-row-title">Announcements & Service Alerts</div>
                    <a class="small flex-shrink-0" href="<?= e(app_url('/public/ba_announcements.php')) ?>">View all →</a>
                </div>
                <hr>
                <?php if (count($announcements) === 0) : ?>
                    <div class="text-muted small">No published announcements yet.</div>
                <?php else : ?>
                    <?php foreach (array_slice($announcements, 0, 5) as $ann) : ?>
                        <div class="mc-ba-announce-item">
                            <div class="mc-ba-announce-head">
                                <div class="mc-ba-announce-title"><span class="badge bg-warning text-dark me-1"><?= e((string) $ann['kind']) ?></span><?= e((string) $ann['title']) ?></div>
                                <div class="mc-ba-announce-meta"><?= !empty($ann['effective_date']) ? date('M j, Y', strtotime((string) $ann['effective_date'])) : date('M j, Y', strtotime((string) $ann['created_at'])) ?></div>
                            </div>
                            <div class="mc-ba-announce-excerpt"><?= e(mb_substr((string) $ann['content'], 0, 220)) ?><?= strlen((string) $ann['content']) > 220 ? '…' : '' ?></div>
                            <?php if (!empty($ann['target_barangay_name'])) : ?><div class="small mt-1"><span class="badge bg-secondary">Barangay <?= e((string) $ann['target_barangay_name']) ?></span></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="mc-ba-dashboard-stack">
        <?= $_weatherCard ?>
        <div class="mc-ba-card mc-ba-card-secondary">
            <div class="card-body">
                <div class="fw-bold">Quick Stats</div>
                <div class="mc-ba-stats-grid">
                    <div class="mc-ba-stat"><strong><?= $unreadCount ?></strong><span>Unread Notices</span></div>
                    <div class="mc-ba-stat"><strong><?= count($recentReports) ?></strong><span>My Reports</span></div>
                </div>
                <div class="mt-3 d-grid gap-2">
                    <a class="btn btn-primary" href="<?= e(app_url('/public/ba_report.php')) ?>">Report an Issue</a>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/ba_announcements.php')) ?>">Announcements & Alerts</a>
                </div>
            </div>
        </div>
        <div class="mc-ba-card">
            <div class="card-body">
                <div class="mc-ba-row-title mb-3">My Recent Reports</div>
                <?php if (count($recentReports) === 0) : ?>
                    <div class="text-muted small">No reports yet. You can report a missed collection, improper disposal, or other issue here.</div>
                    <div class="d-grid mt-3"><a class="btn btn-outline-primary btn-sm" href="<?= e(app_url('/public/ba_report.php')) ?>">Report an Issue</a></div>
                <?php else : ?>
                    <?php foreach ($recentReports as $rep) :
                        $statusClass = $rep['status'] === 'Completed' ? 'bg-success' : ($rep['status'] === 'Ongoing' ? 'bg-primary' : ($rep['status'] === 'Acknowledged' ? 'bg-info text-dark' : ($rep['status'] === 'Cancelled' ? 'bg-secondary' : 'bg-warning text-dark')));
                    ?>
                        <div class="mc-ba-report-item">
                            <div class="mc-ba-report-head">
                                <div class="mc-ba-report-no"><a href="<?= e(app_url('/public/ba_my_reports.php')) ?>"><?= e((string) $rep['report_number']) ?></a></div>
                                <span class="badge <?= $statusClass ?>"><?= e((string) $rep['status']) ?></span>
                            </div>
                            <div class="mc-ba-report-meta"><?= e((string) $rep['category']) ?> · <?= date('M j', strtotime((string) $rep['date_of_concern'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="d-grid mt-2"><a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/public/ba_my_reports.php')) ?>">View all my reports</a></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div><!-- /mc-ba-dashboard-page -->

<div class="modal fade mh" id="phHolidayCalendarModal" tabindex="-1" aria-labelledby="phHolidayCalendarModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header text-white">
                <div>
                    <h1 class="modal-title" id="phHolidayCalendarModalLabel"><i data-lucide="calendar-range" class="lucide-14"></i> Philippine Official Holidays · <?= (int)$_curYear ?></h1>
                    <div class="small">Regular holidays, special non-working days, and observances — sourced from Calendarific API</div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mhl">
                    <span class="badge bg-danger">Regular Holiday</span>
                    <span class="badge bg-warning text-dark">Special Non-Working Day</span>
                    <span class="badge bg-secondary">Observance</span>
                    <span class="badge bg-info mht" style="color:#fff!important;">Today: <?= date('F j, Y') ?></span>
                </div>
                <div class="mhg">
                    <?php for ($_m = 1; $_m <= 12; $_m++) :
                        $_monthHols = $_holidaysByMonth[$_m] ?? [];
                        $_isCurMonth = (int)date('n') === $_m;
                    ?>
                        <div class="mhm">
                            <div class="mhmh <?= $_isCurMonth ? 'c' : '' ?>">
                                <div class="d-flex align-items-center" style="gap:8px;">
                                    <span class="mhmh-n"><?= e($_monthNames[$_m]) ?></span>
                                    <?php if ($_isCurMonth) : ?><span class="badge bg-primary" style="padding:3px 8px;font-size:.66rem;">Now</span><?php endif; ?>
                                </div>
                                <span class="mhmh-c"><?= count($_monthHols) ?></span>
                            </div>
                            <div class="mhmb">
                                <?php if (count($_monthHols) === 0) : ?>
                                    <div class="mhmb-e">No holidays</div>
                                <?php else : ?>
                                    <?php foreach ($_monthHols as $_mh) :
                                        $_mhDate = (string)($_mh['date'] ?? '');
                                        $_mhTs = strtotime($_mhDate);
                                        $_mhDay = (int)substr($_mhDate, 8, 2);
                                        $_mhName = e((string)($_mh['name'] ?? 'Unknown'));
                                        $_mhType = (string)($_mh['type'] ?? 'Holiday');
                                        $_mhIsToday = $_mhDate === $_todayStr;
                                        $_mhIsPast = $_mhDate < $_todayStr;
                                        $_mhBadge = match (true) {
                                            stripos($_mhType, 'Regular') !== false => 'bg-danger',
                                            stripos($_mhType, 'Special') !== false || stripos($_mhType, 'Non-Working') !== false => 'bg-warning text-dark',
                                            default => 'bg-secondary',
                                        };
                                        $_rowClass = 'mhe' . ($_mhIsToday ? ' t' : '') . ($_mhIsPast && !$_mhIsToday ? ' p' : '');
                                    ?>
                                        <div class="<?= $_rowClass ?>">
                                            <div class="mhd <?= $_mhIsToday ? 'td' : '' ?>">
                                                <div class="mhd-d"><?= e(strtoupper($_mhTs !== false ? date('D', $_mhTs) : '—')) ?></div>
                                                <div class="mhd-dy"><?= $_mhDay ?></div>
                                            </div>
                                            <div class="mhi">
                                                <div class="mhi-n"><?= $_mhName ?></div>
                                                <div class="mhi-b">
                                                    <span class="badge <?= $_mhBadge ?>"><?= e($_mhType) ?></span>
                                                    <?php if ($_mhIsToday) : ?><span class="badge bg-primary">Today</span><?php endif; ?>
                                                    <?php if ($_mhIsPast && !$_mhIsToday) : ?><span class="badge bg-light text-dark border">Passed</span><?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="modal-footer">
                <div class="mhn">Data from Calendarific (national, local, and observance types). Exact dates for movable holidays (Maundy Thu, Good Fri, National Heroes Day, Eid holidays) are official as published.</div>
                <div class="mha">
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/ba_schedule.php')) ?>"><i data-lucide="calendar-days" class="lucide-14"></i> My collection schedule</a>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<img src="<?= e(app_url('/assets/img/cityhall.png')) ?>" alt="Marikina City Hall Watermark" style="position:fixed; bottom:10px; right:10px; max-width:38vw; width:clamp(100px,22vw,200px); height:auto; opacity:0.15; z-index:0; pointer-events:none;">
<script>
(function(){
  function __renderSafe(){
    if (typeof window.__renderLucide === 'function') {
      window.__renderLucide();
      return true;
    }
    try { if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') { lucide.createIcons({nameAttr:'data-lucide',attrs:{width:16,height:16}}); return true; } } catch(e) {}
    return false;
  }
  function __poll(){
    var retries = 0;
    var iv = setInterval(function(){
      retries++;
      if (__renderSafe()) { clearInterval(iv); return; }
      if (retries >= 30) clearInterval(iv);
    }, 100);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', __poll, {once:true});
  else __poll();
  window.addEventListener('pageshow', function(e){ if (e.persisted) setTimeout(__poll, 120); }, {passive:true});
})();
</script>
<?php
require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
