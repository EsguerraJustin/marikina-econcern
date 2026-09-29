<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$pageTitle = 'BasuraAlert Dashboard';
$activeNav = 'ba_dashboard';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

$endpoint = e(app_url('/admin/api/basuraalert_admin.php'));
echo csrf_header_meta();
$barangays = ba_list_barangays($mysqli);
$categoryOptions = ba_report_category_options();
$statusOptions = ba_report_status_options();

$filterBarangay = isset($_GET['barangay_id']) ? (int) $_GET['barangay_id'] : 0;
$filterCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$filterFrom = isset($_GET['from']) && $_GET['from'] !== '' ? trim((string) $_GET['from']) : null;
$filterTo = isset($_GET['to']) && $_GET['to'] !== '' ? trim((string) $_GET['to']) : null;

$where = [];
$params = [];
$types = '';
$exportQuery = [];
if ($filterBarangay > 0) {
    $where[] = 'r.barangay_id = ?';
    $params[] = $filterBarangay;
    $types .= 'i';
    $exportQuery['barangay_id'] = (string) $filterBarangay;
}
if ($filterCategory !== '' && isset($categoryOptions[$filterCategory])) {
    $where[] = 'r.category = ?';
    $params[] = $filterCategory;
    $types .= 's';
    $exportQuery['category'] = $filterCategory;
}
if ($filterFrom !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $where[] = 'DATE(r.created_at) >= ?';
    $params[] = $filterFrom;
    $types .= 's';
    $exportQuery['from'] = $filterFrom;
}
if ($filterTo !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $where[] = 'DATE(r.created_at) <= ?';
    $params[] = $filterTo;
    $types .= 's';
    $exportQuery['to'] = $filterTo;
}
$whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

// CSV Export must honour the filters currently on screen, otherwise the
// download silently ignores whatever the admin selected.
$exportUrl = app_url('/admin/api/ba_reports_export.php')
    . (count($exportQuery) > 0 ? '?' . http_build_query($exportQuery) : '');

/**
 * These dashboard widgets are wrapped in try/catch so one failing query cannot
 * blank the whole cockpit. Previously the exception was discarded entirely,
 * which made a broken widget render a confident "all clear" message instead.
 * Log it so schema drift shows up in app_error.log rather than silently.
 */
function ba_dash_log(string $widget, Throwable $e): void
{
    @error_log('[ba_dashboard] ' . $widget . ' query failed: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

$statusCounts = [];
foreach ($statusOptions as $s) {
    $statusCounts[$s] = 0;
}
try {
    $sql = "SELECT r.status, COUNT(*) c FROM ba_reports r {$whereSql} GROUP BY r.status";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        if ($types !== '') db_prepared_execute($stmt, $types, $params); else db_prepared_execute($stmt, '', []);
        $res = $stmt->get_result();
        if ($res) while ($row = $res->fetch_assoc()) {
            $norm = ba_normalize_report_status((string) $row['status']);
            $statusCounts[$norm] = ($statusCounts[$norm] ?? 0) + (int) $row['c'];
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    ba_dash_log('status counts', $e);
}
$totalReports = (int) array_sum($statusCounts);

$dropoffCounts = ['PUBLISHED' => 0, 'DRAFT' => 0, 'TEMPORARILY_CLOSED' => 0, 'total' => 0];
try {
    $sqlDrop = "SELECT status, COUNT(*) c FROM ba_dropoff_points GROUP BY status";
    $res = $mysqli->query($sqlDrop);
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $c = (int) $row['c'];
            $dropoffCounts[$row['status']] = $c;
            $dropoffCounts['total'] += $c;
        }
    }
} catch (Throwable $e) {
    ba_dash_log('dropoff counts', $e);
}

$pending24h = [];
try {
    $cutoff = date('Y-m-d H:i:s', time() - 86400);
    $pendingWhere = $whereSql !== '' ? $whereSql . ' AND ' : 'WHERE ';
    $sql2 = "SELECT r.id, r.report_number, r.description, r.status, r.created_at,
                    b.name AS report_barangay,
                    CONCAT(u.first_name,' ',u.last_name) AS uname
             FROM ba_reports r
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN barangays b ON b.id = r.barangay_id
             {$pendingWhere} r.created_at < ?
             AND r.status NOT IN ('Completed','Cancelled')
             ORDER BY r.created_at ASC LIMIT 50";
    $stmt = $mysqli->prepare($sql2);
    if ($stmt) {
        $p2 = $params;
        $t2 = $types . 's';
        $p2[] = $cutoff;
        db_prepared_execute($stmt, $t2, $p2);
        $res = $stmt->get_result();
        if ($res) while ($row = $res->fetch_assoc()) $pending24h[] = $row;
        $stmt->close();
    }
} catch (Throwable $e) {
    ba_dash_log('pending >24h', $e);
}

$topCategories = [];
try {
    $sql3 = "SELECT r.category, COUNT(*) c FROM ba_reports r {$whereSql} GROUP BY r.category ORDER BY c DESC LIMIT 3";
    $stmt = $mysqli->prepare($sql3);
    if ($stmt) {
        if ($types !== '') db_prepared_execute($stmt, $types, $params); else db_prepared_execute($stmt, '', []);
        $res = $stmt->get_result();
        if ($res) while ($row = $res->fetch_assoc()) {
            $label = ba_report_category_label((string) $row['category']);
            $topCategories[] = ['label' => $label, 'count' => (int) $row['c'], 'key' => (string) $row['category']];
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    ba_dash_log('top categories', $e);
}

$topBarangays = [];
try {
    $sql4 = "SELECT b.name AS barangay, COUNT(*) c FROM ba_reports r LEFT JOIN barangays b ON b.id = r.barangay_id {$whereSql} GROUP BY b.name ORDER BY c DESC LIMIT 3";
    $stmt = $mysqli->prepare($sql4);
    if ($stmt) {
        if ($types !== '') db_prepared_execute($stmt, $types, $params); else db_prepared_execute($stmt, '', []);
        $res = $stmt->get_result();
        if ($res) while ($row = $res->fetch_assoc()) $topBarangays[] = ['label' => (string) $row['barangay'], 'count' => (int) $row['c']];
        $stmt->close();
    }
} catch (Throwable $e) {
    ba_dash_log('top barangays', $e);
}

$resolutionMean = null;
$resolutionMedian = null;
try {
    $whereClosed = $whereSql !== '' ? $whereSql . ' AND ' : 'WHERE ';
    $sql5 = "SELECT r.id, TIMESTAMPDIFF(HOUR, r.created_at, r.updated_at) hrs FROM ba_reports r {$whereClosed} r.status IN ('Completed','Cancelled')";
    $stmt = $mysqli->prepare($sql5);
    if ($stmt) {
        if ($types !== '') db_prepared_execute($stmt, $types, $params); else db_prepared_execute($stmt, '', []);
        $res = $stmt->get_result();
        $vals = [];
        if ($res) while ($row = $res->fetch_assoc()) $vals[] = max(0, (int) $row['hrs']);
        $stmt->close();
        if (count($vals) > 0) {
            $resolutionMean = (float) array_sum($vals) / (float) count($vals);
            sort($vals, SORT_NUMERIC);
            $mid = (int) floor(count($vals) / 2);
            $resolutionMedian = (count($vals) % 2) ? (float) $vals[$mid] : ((float) $vals[$mid - 1] + (float) $vals[$mid]) / 2.0;
        }
    }
} catch (Throwable $e) {
    ba_dash_log('resolution time', $e);
}

function badgeClassForStatus(string $s): string
{
    $map = [
        'New' => 'bg-secondary',
        'Acknowledged' => 'bg-info text-dark',
        'In Progress' => 'bg-warning text-dark',
        'Completed' => 'bg-success',
        'Rejected' => 'bg-danger',
    ];
    return $map[$s] ?? 'bg-secondary';
}

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
$_weatherIsSevere = false;
if ($_weatherCurrent !== null) {
    $_adv = (string)($_weatherCurrent['advisory'] ?? '');
    if (stripos($_adv, 'ADVISORY:') === 0) {
        if (stripos($_adv, 'Typhoon') !== false || stripos($_adv, 'High winds') !== false) {
            $_weatherAdvisoryClass = 'text-danger fw-semibold';
            $_weatherAdvisoryBadge = '<span class="badge bg-danger">SEVERE</span>';
            $_weatherIsSevere = true;
        } elseif (stripos($_adv, 'Thunderstorm') !== false) {
            $_weatherAdvisoryClass = 'text-warning fw-semibold';
            $_weatherAdvisoryBadge = '<span class="badge bg-warning text-dark">T-STORMS</span>';
        } elseif (stripos($_adv, 'Rain') !== false) {
            $_weatherAdvisoryClass = 'text-info fw-semibold';
            $_weatherAdvisoryBadge = '<span class="badge bg-info text-dark">RAIN</span>';
        } elseif (stripos($_adv, 'Extreme heat') !== false) {
            $_weatherAdvisoryClass = 'text-warning fw-semibold';
            $_weatherAdvisoryBadge = '<span class="badge bg-warning text-dark">HEAT</span>';
        }
    }
}
?>
<div class="mc-admin-ba_dashboard-page">

<div class="mc-admin-hero">
    <div class="mc-admin-hero-inner">
        <div class="mc-admin-hero-title-row">
            <div class="mc-admin-hero-icon">
                <i data-lucide="layout-dashboard" class="lucide lucide-24"></i>
            </div>
            <div class="mc-admin-hero-title">
                <h1>BasuraAlert Dashboard</h1>
                <p>Central operations dashboard: resident reports, drop-offs, schedules, and system health</p>
            </div>
        </div>
    </div>
</div>

<div class="mc-admin-section-card mb-3">
    <div class="mc-admin-section-head">
        <h3><i data-lucide="filter" class="lucide"></i> Filters</h3>
        <span class="text-muted small">Filtered range applies to counts below.</span>
    </div>
    <div class="p-4">
<div class="row g-2 align-items-end">
    <div class="col-md-2">
        <label class="form-label small">Barangay</label>
        <select class="form-select form-select-sm" id="fBarangay">
            <option value="0">— All barangays —</option>
            <?php foreach ($barangays as $b) : ?><option value="<?= (int) $b['id'] ?>" <?= $filterBarangay === (int) $b['id'] ? 'selected' : '' ?>><?= e((string) $b['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small">Report category</label>
        <select class="form-select form-select-sm" id="fCategory">
            <option value="">— All categories —</option>
            <?php foreach ($categoryOptions as $k => $label) : ?><option value="<?= e($k) ?>" <?= $filterCategory === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small">From date</label>
        <input class="form-control form-control-sm" type="date" id="fFrom" value="<?= $filterFrom !== null ? e($filterFrom) : '' ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small">To date</label>
        <input class="form-control form-control-sm" type="date" id="fTo" value="<?= $filterTo !== null ? e($filterTo) : '' ?>">
    </div>
    <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1" id="applyFilterBtn">Apply filters</button>
        <a href="<?= e($exportUrl) ?>" class="btn btn-sm btn-outline-success flex-grow-1" target="_blank" rel="noopener"><i data-lucide="download" class="lucide-14"></i> CSV Export</a>
    </div>
</div>
    </div>
</div>

<?php
$_holidayBlock = '';
$_calBtn = '<button type="button" class="btn btn-sm ' . ($_todayHoliday !== null ? 'btn-holiday-onbanner' : 'btn-outline-primary') . '" data-bs-toggle="modal" data-bs-target="#phHolidayCalendarModal">
    <i data-lucide="calendar-days" class="lucide-14"></i> Full calendar
</button>';
if ($_todayHoliday !== null) {
    $_hName = e((string)$_todayHoliday['name']);
    $_hType = e((string)($_todayHoliday['type'] ?? 'Holiday'));
    $_hNotes = !empty($_todayHoliday['notes']) ? '<div class="small mt-1 opacity-90">' . e(mb_substr((string)$_todayHoliday['notes'], 0, 180)) . '</div>' : '';
    $_isRegular = stripos((string)($_todayHoliday['type'] ?? ''), 'Regular') !== false;
    $_badgeClass = $_isRegular ? 'bg-danger' : 'bg-warning text-dark';
    $_bannerClass = $_isRegular ? 'mc-admin-holiday-card--regular' : 'mc-admin-holiday-card--special';
    $_holidayBlock = '<div class="mc-admin-holiday-card ' . $_bannerClass . ' text-white" style="width:100%;min-width:0">
        <div class="mc-admin-holiday-card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3" style="min-width:0;width:100%">
                <div style="flex:1 1 60%;min-width:0;overflow-wrap:break-word">
                    <div class="d-flex align-items-center gap-2 mb-2" style="flex-wrap:wrap">
                        <span class="badge ' . $_badgeClass . '" style="border:1px solid rgba(255,255,255,.3)">' . $_hType . '</span>
                        <span class="small opacity-90 fw-semibold"><i data-lucide="party-popper" class="lucide-14"></i> Today · ' . date('F j, Y') . '</span>
                    </div>
                    <div class="h4 fw-bold mb-1" style="font-size:clamp(1.1rem,3.5vw,1.4rem)!important;line-height:1.2!important;overflow-wrap:break-word"><i data-lucide="calendar-days" class="lucide-14"></i> ' . $_hName . '</div>
                    <div class="small opacity-95" style="overflow-wrap:break-word">Collection schedule may be suspended. Post an announcement and check resident reports for missed-collection spikes.</div>
                    ' . $_hNotes . '
                </div>
                <div class="mc-action-row">
                    <a class="btn btn-sm btn-holiday-onbanner" href="' . e(app_url('/admin/ba_schedules.php')) . '"><i data-lucide="calendar-days" class="lucide-14"></i> Schedules</a>
                    <a class="btn btn-sm btn-holiday-onbanner" href="' . e(app_url('/admin/ba_announcements.php')) . '"><i data-lucide="megaphone" class="lucide-14"></i> Post notice</a>
                    ' . $_calBtn . '
                </div>
            </div>
        </div>
    </div>';
} elseif ($_nextHoliday !== null) {
    $_hName = e((string)$_nextHoliday['name']);
    $_hType = e((string)($_nextHoliday['type'] ?? 'Holiday'));
    $_hDate = date('M j, Y', strtotime((string)$_nextHoliday['date']));
    $_hDays = (int)($_nextHoliday['days_until'] ?? 0);
    $_hDaysText = $_hDays === 1 ? 'Tomorrow' : ('In ' . $_hDays . ' days');
    $_holidayBlock = '<div class="mc-admin-holiday-card" role="alert" style="background:#fffbeb;border:1px solid #F59E0B;width:100%;min-width:0;overflow-wrap:break-word">
        <div class="mc-admin-holiday-card-body d-grid d-sm-block gap-3">
            <div class="d-flex flex-wrap align-items-start gap-2 mb-sm-2" style="min-width:0;width:100%">
                <div style="flex:1 1 100%;min-width:0;overflow-wrap:break-word">
                    <div class="fw-semibold d-inline-block mb-1"><i data-lucide="calendar-days" class="lucide-14"></i> Upcoming Holiday · <span class="badge bg-warning text-dark border">' . $_hDaysText . '</span></div>
                    <div style="overflow-wrap:break-word"><strong>' . $_hName . '</strong> <span class="text-muted small">(' . $_hType . ')</span> on ' . $_hDate . ' — prepare schedule exceptions and resident announcements.</div>
                </div>
            </div>
            <div class="mc-action-row">
                <a class="btn btn-sm btn-outline-warning" href="' . e(app_url('/admin/ba_schedules.php')) . '"><i data-lucide="calendar-days" class="lucide-14"></i> Manage schedule</a>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#phHolidayCalendarModal"><i data-lucide="calendar-days" class="lucide-14"></i> Full calendar</button>
            </div>
        </div>
    </div>';
} else {
    $totalHolidays = count($_holidayList);
    $_holidayBlock = '<div class="mc-admin-holiday-card" style="width:100%;min-width:0">
        <div class="mc-admin-holiday-card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3" style="width:100%;min-width:0">
                <div class="d-flex flex-wrap align-items-center gap-3" style="flex:1 1 60%;min-width:0">
                    <div class="display-6 flex-shrink-0"><i data-lucide="calendar-range" class="lucide-14"></i></div>
                    <div style="min-width:0;overflow-wrap:break-word">
                        <div class="h6 fw-bold mb-1" style="overflow-wrap:break-word">Philippine Holidays ' . (int)$_curYear . '</div>
                        <div class="small text-muted" style="overflow-wrap:break-word">No holiday today. Review the official list of ' . $totalHolidays . ' holidays across all 12 months to plan schedule exceptions.</div>
                    </div>
                </div>
                <div class="mc-action-row">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#phHolidayCalendarModal">
                        <i data-lucide="calendar-days" class="lucide-14"></i> Holiday Calendar
                    </button>
                    <a class="btn btn-outline-secondary" href="' . e(app_url('/admin/ba_schedules.php')) . '">Manage schedules</a>
                </div>
            </div>
        </div>
    </div>';
}
echo $_holidayBlock;
?>

<div class="mc-admin-kpi-grid">
    <?php
    $kpiMeta = [
        'New' => ['blue', 'mail-plus'],
        // was 'amber' — same as 'In Progress' directly below it, so two
        // adjacent tiles looked identical. Blue breaks the run.
        'Acknowledged' => ['blue', 'eye'],
        'In Progress' => ['amber', 'loader-2'],
        'Completed' => ['mint', 'check-circle-2'],
        'Rejected' => ['red', 'ban'],
    ];
    ?>
    <div class="mc-admin-kpi-card">
        <div class="mc-admin-kpi-head">
            <div class="mc-admin-kpi-icon mc-admin-kpi-icon--blue"><i data-lucide="layers" class="lucide"></i></div>
        </div>
        <p class="mc-admin-kpi-label">Total</p>
        <p class="mc-admin-kpi-value"><?= $totalReports ?></p>
        <p class="mc-admin-kpi-sub">In this filtered range</p>
    </div>
    <?php foreach ($statusOptions as $s) :
        [$variant, $icon] = $kpiMeta[$s] ?? ['blue', 'layers'];
        $pct = $totalReports > 0 ? round(100 * (($statusCounts[$s] ?? 0) / $totalReports), 1) : 0;
    ?>
    <div class="mc-admin-kpi-card">
        <div class="mc-admin-kpi-head">
            <div class="mc-admin-kpi-icon mc-admin-kpi-icon--<?= e($variant) ?>"><i data-lucide="<?= e($icon) ?>" class="lucide"></i></div>
        </div>
        <p class="mc-admin-kpi-label"><?= e($s) ?></p>
        <p class="mc-admin-kpi-value"><?= (int) ($statusCounts[$s] ?? 0) ?></p>
        <p class="mc-admin-kpi-sub"><?= e((string)$pct) ?>% of total</p>
    </div>
    <?php endforeach; ?>
    <div class="mc-admin-kpi-card">
        <div class="mc-admin-kpi-head">
            <div class="mc-admin-kpi-icon mc-admin-kpi-icon--mint"><i data-lucide="map-pin" class="lucide"></i></div>
        </div>
        <p class="mc-admin-kpi-label">Drop-off points</p>
        <p class="mc-admin-kpi-value"><?= (int) ($dropoffCounts['total'] ?? 0) ?></p>
        <p class="mc-admin-kpi-sub"><a class="stretched-link text-decoration-none text-reset" href="<?= e(app_url('/admin/ba_dropoffs.php')) ?>"><?= (int) ($dropoffCounts['PUBLISHED'] ?? 0) ?> live<?= (($dropoffCounts['DRAFT'] ?? 0) > 0 ? ' · ' . (int)($dropoffCounts['DRAFT'] ?? 0) . ' draft' : '') ?><?= (($dropoffCounts['TEMPORARILY_CLOSED'] ?? 0) > 0 ? ' · ' . (int)($dropoffCounts['TEMPORARILY_CLOSED'] ?? 0) . ' closed' : '') ?></a></p>
    </div>
</div>

<div class="mc-admin-two-col">
    <div class="d-flex flex-column gap-3">
        <div class="mc-admin-section-card">
            <div class="mc-admin-section-head">
                <h3><i data-lucide="timer" class="lucide"></i> Resolution time</h3>
                <span class="text-muted small">Closed reports.</span>
            </div>
            <div class="p-4">
                <?php if ($resolutionMean === null) : ?>
                    <div class="text-muted small py-3">No closed reports yet.</div>
                <?php else : ?>
                    <div class="row text-center">
                        <div class="col-6 border-end">
                            <div class="small text-muted">Mean</div>
                            <div class="h4 fw-bold mb-0"><?= number_format($resolutionMean, 1) ?></div>
                            <div class="small text-muted">hrs</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Median</div>
                            <div class="h4 fw-bold mb-0"><?= number_format($resolutionMedian, 1) ?></div>
                            <div class="small text-muted">hrs</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="mc-admin-section-card">
            <div class="mc-admin-section-head">
                <h3><i data-lucide="flame" class="lucide"></i> Top categories</h3>
                <span class="text-muted small">Most reported.</span>
            </div>
            <div class="p-4">
                <?php if (count($topCategories) === 0) : ?>
                    <div class="text-muted small py-3">No data yet.</div>
                <?php else : ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($topCategories as $idx => $tc) : ?>
                            <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge bg-light text-dark border me-1">#<?= $idx + 1 ?></span>
                                    <small class="fw-semibold"><?= e($tc['label']) ?></small>
                                </div>
                                <span class="fw-bold"><?= (int) $tc['count'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="d-flex flex-column gap-3">
        <?php
        $_wCardInner = '';
        if ($_weatherCurrent !== null) {
            $_wIcon = $_weatherCurrent['icon'] ?? '<i data-lucide="cloud-sun" class="lucide-14"></i>';
            // null (field genuinely absent upstream) renders as an em dash, not
            // 0. Same treatment as public/ba_dashboard.php.
            $_wTemp = isset($_weatherCurrent['temp_c']) && $_weatherCurrent['temp_c'] !== null ? (float) $_weatherCurrent['temp_c'] : null;
            $_wFeels = isset($_weatherCurrent['feels_like_c']) && $_weatherCurrent['feels_like_c'] !== null
                ? (float) $_weatherCurrent['feels_like_c']
                : (isset($_weatherCurrent['temp_c']) && $_weatherCurrent['temp_c'] !== null ? (float) $_weatherCurrent['temp_c'] : null);
            $_wHum = isset($_weatherCurrent['humidity_pct']) && $_weatherCurrent['humidity_pct'] !== null ? (int) $_weatherCurrent['humidity_pct'] : null;
            $_wWind = isset($_weatherCurrent['wind_kph']) && $_weatherCurrent['wind_kph'] !== null ? (float) $_weatherCurrent['wind_kph'] : null;
            $_wTempD = $_wTemp !== null ? $_wTemp . '°C' : '—';
            $_wFeelsD = $_wFeels !== null ? $_wFeels . '°C' : '—';
            $_wHumD = $_wHum !== null ? $_wHum . '%' : '—';
            $_wWindD = $_wWind !== null ? (string) $_wWind : '—';
            $_wCond = e((string)($_weatherCurrent['condition'] ?? 'Clear'));
            $_wAdv = e((string)($_weatherCurrent['advisory'] ?? 'Normal conditions.'));
            $_wCityE = e($_weatherCity);
            $_fcHtml = '';
            foreach ($_weatherForecast as $_fc) {
                $_fcTs = strtotime((string)($_fc['date'] ?? ''));
                $_fcDate = ($_fcTs !== false) ? date('M j', $_fcTs) : '—';
                // Same null treatment as public/ba_dashboard.php: a field the
                // provider omitted must render as a gap, never as 0.
                $_fcMax = isset($_fc['max_c']) && $_fc['max_c'] !== null ? (float) $_fc['max_c'] : null;
                $_fcMin = isset($_fc['min_c']) && $_fc['min_c'] !== null ? (float) $_fc['min_c'] : null;
                $_fcCondRaw = isset($_fc['condition']) && is_string($_fc['condition']) && $_fc['condition'] !== '' ? (string) $_fc['condition'] : null;
                $_fcCondE = ($_fcCondRaw === null || $_fcCondRaw === 'Atmosphere') ? '—' : e($_fcCondRaw);
                $_fcRain = isset($_fc['rain_chance_pct']) && $_fc['rain_chance_pct'] !== null ? (int) $_fc['rain_chance_pct'] : null;
                $_fcTempHtml = ($_fcMax !== null && $_fcMin !== null)
                    ? $_fcMax . '° <span class="text-muted fw-normal">/</span> ' . $_fcMin . '°'
                    : '<span class="text-muted">—</span>';
                $_fcRainHtml = $_fcRain !== null ? $_fcRain . '% rain' : '—';
                $_isTodayPht = (string)($_fc['date'] ?? '') === gmdate('Y-m-d', time() + 28800);
                $_fcLabel = $_fcDate . ($_isTodayPht || !empty($_fc['partial']) ? ' · rest of day' : '');
                $_fcHtml .= '<div class="mc-admin-weather-day">
                    <div class="small text-muted fw-semibold">' . e($_fcLabel) . '</div>
                    <div class="small fw-semibold text-truncate" title="' . $_fcCondE . '">' . $_fcCondE . '</div>
                    <div class="fw-bold">' . $_fcTempHtml . '</div>
                    <div class="small text-muted"><i data-lucide="droplets" class="lucide-14"></i> ' . $_fcRainHtml . '</div>
                </div>';
            }
            $_wCardInner = '<div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="cloud-sun" class="lucide"></i> Weather · ' . $_wCityE . '</h3>
                    ' . ($_weatherAdvisoryBadge !== '' ? $_weatherAdvisoryBadge : '<span class="text-muted small">Current conditions.</span>') . '
                </div>
                <div class="p-4">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="fs-2">' . $_wIcon . '</div>
                        <div>
                            <div class="h3 fw-bold mb-0 lh-1">' . $_wTempD . '</div>
                            <div class="small text-muted">Feels like ' . $_wFeelsD . ' · ' . $_wCond . '</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 text-center small mb-2">
                        <div class="flex-grow-1 border rounded p-1"><div class="fw-bold"><i data-lucide="droplets" class="lucide-14"></i> ' . $_wHumD . '</div><div class="text-muted">Humidity</div></div>
                        <div class="flex-grow-1 border rounded p-1"><div class="fw-bold"><i data-lucide="wind" class="lucide-14"></i> ' . $_wWind . '</div><div class="text-muted">Wind km/h</div></div>
                        <div class="flex-grow-1 border rounded p-1"><div class="fw-bold"><i data-lucide="thermometer" class="lucide-14"></i> ' . ($_wFeels !== null ? $_wFeels . '°' : '—') . '</div><div class="text-muted">Feels</div></div>
                    </div>
                    <div class="small border-top pt-2">' . $_wAdv . '</div>
                    ' . ($_fcHtml !== '' ? '<div class="fw-bold small mt-3 mb-2 text-muted">2-Day Outlook</div><div class="mc-admin-weather-forecast">' . $_fcHtml . '</div>' : '') . '
                </div>
            </div>';
        } else {
            $_wErr = isset($_weatherData['error']) ? e(mb_substr((string)$_weatherData['error'], 0, 90)) : 'Weather data unavailable.';
            $_wCardInner = '<div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="cloud-sun" class="lucide"></i> Weather · Marikina</h3>
                    <span class="text-muted small">Current conditions.</span>
                </div>
                <div class="p-4">
                    <div class="small text-muted py-2">' . $_wErr . '</div>
                </div>
            </div>';
        }
        echo $_wCardInner;
        ?>
        <div class="mc-admin-section-card">
            <div class="mc-admin-section-head">
                <h3><i data-lucide="map-pin" class="lucide"></i> Top barangays</h3>
                <span class="text-muted small">Highest volume.</span>
            </div>
            <div class="p-4">
                <?php if (count($topBarangays) === 0) : ?>
                    <div class="text-muted small py-3">No data yet.</div>
                <?php else : ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($topBarangays as $idx => $tb) : ?>
                            <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge bg-light text-dark border me-1">#<?= $idx + 1 ?></span>
                                    <small class="fw-semibold"><?= e($tb['label']) ?></small>
                                </div>
                                <span class="fw-bold"><?= (int) $tb['count'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="mc-admin-section-card mb-3">
    <div class="mc-admin-section-head">
        <h3><i data-lucide="triangle-alert" class="lucide"></i> Pending reports older than 24 hours</h3>
        <span class="badge bg-danger"><?= count($pending24h) ?> item<?= count($pending24h) !== 1 ? 's' : '' ?></span>
    </div>
    <?php if (count($pending24h) === 0) : ?>
        <div class="p-4">
            <div class="text-success small py-3"><i data-lucide="check" class="lucide-14 text-success"></i> No stale pending reports — well done!</div>
        </div>
    <?php else : ?>
        <div class="mc-admin-table-wrap">
            <table class="table table-hover align-middle mb-0" id="pendingTable">
                <thead class="table-light">
                    <tr>
                        <th><span class="mc-th-wrap"><i data-lucide="hash" class="lucide mc-th-icon"></i>ID</span></th>
                        <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="flag" class="lucide mc-th-icon"></i>Status</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="message-square" class="lucide mc-th-icon"></i>Report</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="user" class="lucide mc-th-icon"></i>Resident</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="map-pin" class="lucide mc-th-icon"></i>Barangay</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="timer" class="lucide mc-th-icon"></i>Age</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending24h as $p) : ?>
                    <?php $ageH = (int) floor((time() - strtotime((string) $p['created_at'])) / 3600); ?>
                    <tr>
                        <td data-label="ID"><span class="mc-cell-wrap"><i data-lucide="hash" class="lucide mc-cell-icon"></i><a href="<?= e(app_url('/admin/ba_reports.php?id=' . (int) $p['id'])) ?>" class="fw-bold">#<?= (int) $p['id'] ?></a></span></td>
                        <td data-label="Status"><span class="badge <?= badgeClassForStatus(ba_normalize_report_status((string) $p['status'])) ?>"><?= e(ba_normalize_report_status((string) $p['status'])) ?></span></td>
                        <td data-label="Report"><span class="mc-cell-wrap"><i data-lucide="message-square" class="lucide mc-cell-icon"></i><span><span class="fw-semibold"><?= e((string) ($p['report_number'] ?? '—')) ?></span><?php $pd = (string) ($p['description'] ?? ''); ?><?= $pd !== '' ? '<div class="small text-muted">' . e(mb_substr($pd, 0, 60)) . (mb_strlen($pd) > 60 ? '…' : '') . '</div>' : '' ?></span></span></td>
                        <td data-label="Resident"><span class="mc-cell-wrap"><i data-lucide="user" class="lucide mc-cell-icon"></i><span><?= e((string) ($p['uname'] ?? 'Unknown')) ?></span></span></td>
                        <td data-label="Barangay"><span class="mc-cell-wrap"><i data-lucide="map-pin" class="lucide mc-cell-icon"></i><span><?= e((string) ($p['report_barangay'] ?? '—')) ?></span></span></td>
                        <td data-label="Age"><span class="mc-cell-wrap"><i data-lucide="timer" class="lucide mc-cell-icon"></i><span class="text-danger fw-bold"><?= $ageH ?>h</span></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="mc-admin-section-card">
    <div class="mc-admin-section-head">
        <h3><i data-lucide="compass" class="lucide"></i> Getting started</h3>
        <span class="text-muted small">Quick jumps to Admin BasuraAlert sections.</span>
    </div>
    <div class="p-4">
        <div class="row g-2">
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/admin/ba_reports.php')) ?>"><i data-lucide="clipboard-list" class="lucide-14"></i> Reports Manager</a></div>
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/admin/ba_schedules.php')) ?>"><i data-lucide="calendar-days" class="lucide-14"></i> Schedules</a></div>
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/admin/ba_announcements.php')) ?>"><i data-lucide="megaphone" class="lucide-14"></i> Announcements</a></div>
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/admin/ba_notifications.php')) ?>"><i data-lucide="bell" class="lucide-14"></i> Push Notifications</a></div>
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/admin/ba_waste.php')) ?>"><i data-lucide="recycle" class="lucide-14"></i> Waste Guide</a></div>
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/admin/ba_dropoffs.php')) ?>"><i data-lucide="map-pin" class="lucide-14"></i> Drop-off Points</a></div>
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/admin/ba_feedback.php')) ?>"><i data-lucide="message-square" class="lucide-14"></i> Feedback / FAQ</a></div>
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/admin/ba_dashboard.php')) ?>"><i data-lucide="layout-dashboard" class="lucide-14"></i> Dashboard (here)</a></div>
            <div class="col-lg-3 col-6"><a class="btn btn-outline-secondary w-100" href="<?= e(app_url('/public/ba_dashboard.php')) ?>" target="_blank" rel="noopener"><i data-lucide="eye" class="lucide-14"></i> Resident view</a></div>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<'HTML'
<script>
(function(){
  function refresh(){
    const b=parseInt(document.getElementById("fBarangay").value||"0",10)||0;
    const c=String(document.getElementById("fCategory").value||"");
    const f=String(document.getElementById("fFrom").value||"");
    const t=String(document.getElementById("fTo").value||"");
    const p=new URLSearchParams();
    if(b>0)p.set("barangay_id",String(b));
    if(c!=="")p.set("category",c);
    if(f!=="")p.set("from",f);
    if(t!=="")p.set("to",t);
    const qs=p.toString();
    location.href=location.pathname+(qs!==""?"?"+qs:"");
  }
  document.getElementById("applyFilterBtn").addEventListener("click",refresh);
  document.getElementById("fBarangay").addEventListener("change",function(){if(event.detail===0)return;});
})();
</script>
HTML;
?>

<div class="modal fade mh" id="phHolidayCalendarModal" tabindex="-1" aria-labelledby="phHolidayCalendarModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header text-white">
                <div>
                    <h1 class="modal-title" id="phHolidayCalendarModalLabel"><i data-lucide="calendar-range" class="lucide-14"></i> Philippine Official Holidays · <?= (int)$_curYear ?></h1>
                    <div class="small">Regular holidays, special non-working days, and observances — use to plan schedule exceptions and resident announcements</div>
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
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/admin/ba_schedules.php')) ?>"><i data-lucide="calendar-days" class="lucide-14"></i> Manage schedules</a>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/admin/ba_announcements.php')) ?>"><i data-lucide="megaphone" class="lucide-14"></i> Announcements</a>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- /.mc-admin-ba_dashboard-page -->

<img src="<?= e(app_url('/assets/img/cityhall.png')) ?>" alt="Marikina City Hall Watermark" style="position:fixed; bottom:20px; right:20px; width:200px; height:auto; opacity:0.2; z-index:0; pointer-events:none;">

<?php
require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
