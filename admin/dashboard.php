<?php

declare(strict_types=1);

$pageTitle = 'Admin Dashboard';
$activeNav = 'dashboard';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';
require_once __DIR__ . '/../includes/basuraalert.php';

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
$_weatherAdvisoryBadge = '';
$_weatherIsSevere = false;
if ($_weatherCurrent !== null) {
    $_adv = (string)($_weatherCurrent['advisory'] ?? '');
    if (stripos($_adv, 'ADVISORY:') === 0) {
        if (stripos($_adv, 'Typhoon') !== false || stripos($_adv, 'High winds') !== false) {
            $_weatherAdvisoryBadge = '<span class="badge bg-danger">SEVERE</span>';
            $_weatherIsSevere = true;
        } elseif (stripos($_adv, 'Thunderstorm') !== false) {
            $_weatherAdvisoryBadge = '<span class="badge bg-warning text-dark">T-STORMS</span>';
        } elseif (stripos($_adv, 'Rain') !== false) {
            $_weatherAdvisoryBadge = '<span class="badge bg-info text-dark">RAIN</span>';
        } elseif (stripos($_adv, 'Extreme heat') !== false) {
            $_weatherAdvisoryBadge = '<span class="badge bg-warning text-dark">HEAT</span>';
        }
    }
}

?>
<div class="mc-admin-hero">
    <div class="mc-admin-hero-inner">
        <div class="mc-admin-hero-title-row">
            <div class="mc-admin-hero-icon" style="color:#fff;">
                <i data-lucide="layout-dashboard" class="lucide lucide-24"></i>
            </div>
            <div class="mc-admin-hero-title">
                <h1>Dashboard</h1>
                <p>Overview of submitted concerns.</p>
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
    $_bannerBg = $_isRegular
        ? 'background: #dc2626;'
        : 'background: #d97706;';
    $_holidayBlock = '<div class="mc-admin-holiday-card text-white" style="' . $_bannerBg . ';width:100%;min-width:0">
        <div class="mc-admin-holiday-card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3" style="min-width:0;width:100%">
                <div style="flex:1 1 60%;min-width:0;overflow-wrap:break-word">
                    <div class="d-flex align-items-center gap-2 mb-2" style="flex-wrap:wrap">
                        <span class="badge ' . $_badgeClass . '" style="border:1px solid rgba(255,255,255,.3)">' . $_hType . '</span>
                        <span class="small opacity-90 fw-semibold"><i data-lucide="party-popper" class="lucide-14"></i> Today · ' . date('F j, Y') . '</span>
                    </div>
                    <div class="h4 fw-bold mb-1" style="font-size:clamp(1.1rem,3.5vw,1.4rem)!important;line-height:1.2!important;overflow-wrap:break-word"><i data-lucide="calendar-days" class="lucide-14"></i> ' . $_hName . '</div>
                    <div class="small opacity-95" style="overflow-wrap:break-word">City / department offices may be closed. Concerns may experience longer-than-usual resolution times on this holiday.</div>
                    ' . $_hNotes . '
                </div>
                <div class="mc-action-row">
                    <a class="btn btn-sm btn-holiday-onbanner" href="' . e(app_url('/admin/concerns.php')) . '"><i data-lucide="clipboard-list" class="lucide-14"></i> Concerns</a>
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
                    <div style="overflow-wrap:break-word"><strong>' . $_hName . '</strong> <span class="text-muted small">(' . $_hType . ')</span> on ' . $_hDate . ' — check and reassign pending concerns before long weekend.</div>
                </div>
            </div>
            <div class="mc-action-row">
                <a class="btn btn-sm btn-outline-warning" href="' . e(app_url('/admin/concerns.php')) . '"><i data-lucide="clipboard-list" class="lucide-14"></i> Concern queue</a>
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
                        <div class="small text-muted" style="overflow-wrap:break-word">No holiday today. Browse ' . $totalHolidays . ' official holidays across 12 months to plan staffing and SLA targets.</div>
                    </div>
                </div>
                <div class="mc-action-row">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#phHolidayCalendarModal">
                        <i data-lucide="calendar-days" class="lucide-14"></i> Holiday Calendar
                    </button>
                    <a class="btn btn-outline-secondary" href="' . e(app_url('/admin/concerns.php')) . '">Concern queue</a>
                </div>
            </div>
        </div>
    </div>';
}
echo $_holidayBlock;
?>

<div class="mc-admin-kpi-grid" id="countCards">
    <?php
    $kpiCards = [
        ['Total', 'blue', 'layers'],
        ['New', 'blue', 'mail-plus'],
        ['Ongoing', 'amber', 'loader-2'],
        ['Acknowledge', 'amber', 'eye'],
        ['Completed', 'mint', 'check-circle-2'],
        ['Cancelled', 'red', 'ban'],
    ];
    foreach ($kpiCards as $kpi) :
        [$status, $variant, $icon] = $kpi;
    ?>
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--<?= e($variant) ?>"><i data-lucide="<?= e($icon) ?>" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label"><?= e($status) ?></p>
            <p class="mc-admin-kpi-value" data-count-status="<?= e($status) ?>">—</p>
        </div>
    <?php endforeach; ?>
</div>

<div class="mc-admin-two-col">
    <div class="mc-admin-section-card align-self-start">
        <div class="mc-admin-section-head">
            <h3><i data-lucide="trending-up" class="lucide"></i> Last 30 Days</h3>
            <span class="text-muted small">Daily volume by status.</span>
        </div>
        <div class="p-4">
            <div id="statusTotalsRow" class="mb-3"></div>
            <div id="statusChart" class="d-flex align-items-end" style="height: 200px;"></div>
            <div id="statusDateAxis" class="mt-3"></div>
        </div>
    </div>
    <div class="d-flex flex-column gap-3">
        <?php
        $_wCardInner = '';
        if ($_weatherCurrent !== null) {
            $_wIcon = $_weatherCurrent['icon'] ?? '<i data-lucide="cloud-sun" class="lucide-14"></i>';
            $_wTemp = (float)($_weatherCurrent['temp_c'] ?? 0);
            $_wFeels = (float)($_weatherCurrent['feels_like_c'] ?? 0);
            $_wHum = (int)($_weatherCurrent['humidity_pct'] ?? 0);
            $_wWind = (float)($_weatherCurrent['wind_kph'] ?? 0);
            $_wCond = e((string)($_weatherCurrent['condition'] ?? 'Clear'));
            $_wAdv = e((string)($_weatherCurrent['advisory'] ?? 'Normal conditions.'));
            $_wCityE = e($_weatherCity);
            $_fcHtml = '';
            foreach ($_weatherForecast as $_fc) {
                $_fcTs = strtotime((string)($_fc['date'] ?? ''));
                $_fcDate = ($_fcTs !== false) ? date('M j', $_fcTs) : '—';
                $_fcMax = (float)($_fc['max_c'] ?? 0);
                $_fcMin = (float)($_fc['min_c'] ?? 0);
                $_fcCondE = e((string)($_fc['condition'] ?? '—'));
                $_fcRain = (int)($_fc['rain_chance_pct'] ?? 0);
                $_fcHtml .= '<div class="mc-admin-weather-day">
                    <div class="small text-muted fw-semibold">' . $_fcDate . '</div>
                    <div class="small fw-semibold text-truncate" title="' . $_fcCondE . '">' . $_fcCondE . '</div>
                    <div class="fw-bold">' . $_fcMax . '° <span class="text-muted fw-normal">/</span> ' . $_fcMin . '°</div>
                    <div class="small text-muted"><i data-lucide="droplets" class="lucide-14"></i> ' . $_fcRain . '% rain</div>
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
                            <div class="h3 fw-bold mb-0 lh-1">' . $_wTemp . '°C</div>
                            <div class="small text-muted">Feels like ' . $_wFeels . '°C · ' . $_wCond . '</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 text-center small mb-2">
                        <div class="flex-grow-1 border rounded p-1"><div class="fw-bold"><i data-lucide="droplets" class="lucide-14"></i> ' . $_wHum . '%</div><div class="text-muted">Humidity</div></div>
                        <div class="flex-grow-1 border rounded p-1"><div class="fw-bold"><i data-lucide="wind" class="lucide-14"></i> ' . $_wWind . '</div><div class="text-muted">Wind km/h</div></div>
                        <div class="flex-grow-1 border rounded p-1"><div class="fw-bold"><i data-lucide="thermometer" class="lucide-14"></i> ' . $_wFeels . '°</div><div class="text-muted">Feels</div></div>
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
                <h3><i data-lucide="building-2" class="lucide"></i> By Department (30 Days)</h3>
                <span class="text-muted small">Total submitted concerns.</span>
            </div>
            <div class="p-4">
                <div id="deptChart"></div>
            </div>
        </div>
    </div>
</div>

<div class="mc-admin-section-card">
    <div class="mc-admin-section-head">
        <h3><i data-lucide="clipboard-list" class="lucide"></i> Recent Concerns</h3>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/admin/concerns.php')) ?>">View All</a>
    </div>
    <div class="mc-admin-table-wrap">
        <table class="table table-hover align-middle mb-0" id="recentTable">
            <thead class="table-light">
                <tr>
                    <th><span class="mc-th-wrap"><i data-lucide="hash" class="lucide mc-th-icon"></i>Report #</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="user" class="lucide mc-th-icon"></i>Citizen</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="message-square" class="lucide mc-th-icon"></i>Concern</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="map-pin" class="lucide mc-th-icon"></i>Barangay</span></th>
                    <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="flag" class="lucide mc-th-icon"></i>Status</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="calendar-days" class="lucide mc-th-icon"></i>Date</span></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="p-3" id="recentHint"></div>
</div>

<?php
$countsEndpoint = e(app_url('/admin/api/dashboard_counts.php'));
$recentEndpoint = e(app_url('/admin/api/recent_concerns.php'));
$chartsEndpoint = e(app_url('/admin/api/dashboard_charts.php'));
$viewUrl = e(app_url('/admin/concern_view.php'));
$concernsUrl = e(app_url('/admin/concerns.php'));
$pageScripts = <<<'HTML'
<script>
$(function () {
  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }

  function badge(status) {
    const map = {
      New: "mc-admin-badge--new",
      Ongoing: "mc-admin-badge--warning",
      Acknowledge: "mc-admin-badge--muted",
      Completed: "mc-admin-badge--success",
      Cancelled: "mc-admin-badge--danger"
    };
    const cls = map[status] || "mc-admin-badge--muted";
    return `<span class="mc-admin-badge ${cls}">${esc(status)}</span>`;
  }

  $.getJSON("__COUNTS_ENDPOINT__")
    .done(function (res) {
      if (!res || !res.ok) return;
      const counts = res.counts || {};
      const total = Object.values(counts).reduce((a, b) => a + (b || 0), 0);
      $("[data-count-status='Total']").text(total);
      ["New","Ongoing","Acknowledge","Completed","Cancelled"].forEach(s => {
        $("[data-count-status='" + s + "']").text(counts[s] != null ? counts[s] : 0);
      });
    });

  function renderCharts(res) {
    const status = (res && res.daily_status) ? res.daily_status : null;
    const dept = (res && res.department_totals) ? res.department_totals : null;

    const colors = {
      New: "#3368A0",
      Ongoing: "#D6932E",
      Acknowledge: "#66A3BF",
      Completed: "#4A8F7E",
      Cancelled: "#A84444"
    };
    const badgeStyles = {
      New: "background-color:rgba(51,104,160,.10);color:#3368A0;border:1px solid rgba(51,104,160,.25);",
      Ongoing: "background-color:rgba(214,147,46,.10);color:#9A6B1F;border:1px solid rgba(214,147,46,.30);",
      Acknowledge: "background-color:rgba(102,163,191,.14);color:#2F6B84;border:1px solid rgba(102,163,191,.35);",
      Completed: "background-color:rgba(74,143,126,.14);color:#35705F;border:1px solid rgba(74,143,126,.30);",
      Cancelled: "background-color:rgba(168,68,68,.10);color:#A84444;border:1px solid rgba(168,68,68,.25);"
    };

    const $chart = $("#statusChart").empty().addClass("mc-chart-wrap");
    const labels = status && status.labels ? status.labels : [];
    const series = status && status.series ? status.series : {};

    const statuses = ["New","Ongoing","Acknowledge","Completed","Cancelled"];

    // Chart tuning constants — adjust freely.
    const CHART_H = 200;              // must match #statusChart inline height
    const TOP_RESERVE = 18;           // px left empty above bars so the tallest bar's value label never clips
    const GRID_LABEL_PAD = 30;        // left padding reserving a lane for gridline value numbers
    const WEEKLY_COL_MIN = 56;        // smallest weekly column width (label text always fits)
    const WEEKLY_COL_MAX = 120;       // widest weekly column width (bars never get absurdly fat)
    const WEEKLY_GAP = 10;            // spacing between weekly columns / axis labels
    const SPARSE_DAILY_THRESHOLD = 8; // fewer non-zero days than this -> weekly view

    // Status totals summary — sum each status's daily series (last 30 days).
    const statusTotals = {};
    statuses.forEach(s => {
      statusTotals[s] = (series[s] || []).reduce((a, b) => a + (b || 0), 0);
    });
    $("#statusTotalsRow").html(
      '<div class="text-muted small mb-2 fw-semibold">Status totals (last 30 days)</div>' +
      '<div class="d-flex flex-wrap gap-2">' +
      statuses.map(s =>
        `<span class="badge" style="${badgeStyles[s]}font-weight:600;">${esc(s)}: ${statusTotals[s]}</span>`
      ).join("") +
      '</div>'
    );

    if (labels.length > 0) {
      const dailyTotals = labels.map((_, i) => statuses.reduce((acc, s) => acc + ((series[s] && series[s][i]) ? series[s][i] : 0), 0));
      const activeDays = dailyTotals.filter(t => t > 0).length;
      const weekly = activeDays < SPARSE_DAILY_THRESHOLD;
      renderStatusChart(weekly);
    }

    function shortDate(ymd) {
      if (!ymd) return "";
      const dt = new Date(ymd + "T00:00:00");
      return isNaN(dt.getTime()) ? ymd : dt.toLocaleDateString(undefined, { month: "short", day: "numeric" });
    }

    function shortRange(start, end) {
      if (!start || !end) return "";
      if (start === end) return shortDate(start);
      const a = shortDate(start);
      const b = shortDate(end);
      const aM = String(start).slice(0, 7);
      const bM = String(end).slice(0, 7);
      if (aM === bM) {
        return a + "-" + String(end).slice(8, 10).replace(/^0/, "");
      }
      return a + "-" + b;
    }

    function renderStatusChart(weekly) {
      const todayIdx = labels.length - 1;
      const periods = [];

      if (weekly) {
        // Week buckets: every 7 days from the first label (last bucket may be short).
        for (let s = 0; s < labels.length; s += 7) {
          const e = Math.min(s + 6, labels.length - 1);
          const segs = {};
          statuses.forEach(st => {
            const arr = series[st] || [];
            let v = 0;
            for (let i = s; i <= e; i++) v += arr[i] || 0;
            segs[st] = v;
          });
          const total = statuses.reduce((a, st) => a + (segs[st] || 0), 0);
          periods.push({ start: labels[s], end: labels[e], segs, total, isToday: todayIdx >= s && todayIdx <= e });
        }
      } else {
        labels.forEach((d, i) => {
          const segs = {};
          statuses.forEach(st => { segs[st] = (series[st] && series[st][i]) ? series[st][i] : 0; });
          const total = statuses.reduce((a, st) => a + (segs[st] || 0), 0);
          periods.push({ start: d, end: d, segs, total, isToday: i === todayIdx });
        });
      }

      const maxTotal = Math.max(1, ...periods.map(p => p.total));
      const plotH = CHART_H - TOP_RESERVE;   // bar/gridline scale height; tallest bar's value label stays on-screen
      const gapPx = weekly ? WEEKLY_GAP : 4;
      let colWidth = weekly ? 44 : 8;
      if (weekly) {
        // Fill the chart's real width responsively (capped so columns never get absurdly fat).
        const n = periods.length || 1;
        const chartW = ($chart[0].clientWidth || 0) - GRID_LABEL_PAD;
        colWidth = Math.max(WEEKLY_COL_MIN, Math.min(WEEKLY_COL_MAX, Math.floor((chartW - gapPx * (n - 1)) / n)));
      }
      $chart.css({ gap: gapPx + "px", paddingLeft: GRID_LABEL_PAD + "px" });

      // Reference gridlines (thin light-gray, behind the bars).
      const gridVals = [0.25, 0.5, 0.75, 1]
        .map(f => Math.round(f * maxTotal))
        .filter(v => v >= 1 && v <= maxTotal);
      const gridSet = [...new Set(gridVals)];
      const $grid = $("<div>").addClass("mc-chart-grid").css({ position: "absolute", left: 0, right: 0, top: 0, height: CHART_H + "px", "z-index": 0 });
      gridSet.forEach(v => {
        const bottomPx = Math.min(plotH, Math.round((v / maxTotal) * plotH));
        $grid.append(
          $("<div>").addClass("mc-chart-gridline").css({ bottom: bottomPx + "px" })
            .append($("<span>").addClass("mc-chart-grid-val").text(v))
        );
      });
      $chart.append($grid);

      periods.forEach(p => {
        const segH = p.total > 0 ? Math.round((p.total / maxTotal) * plotH) : 0;
        const $col = $("<div>")
          .addClass("mc-chart-col" + (p.isToday ? " mc-chart-col--today" : ""))
          .css({ position: "relative", width: colWidth + "px", height: CHART_H + "px", "flex-shrink": 0, cursor: "pointer", "z-index": 1 })
          .attr("data-from", p.start)
          .attr("data-to", p.end);

        // Stacked per-status segments (bottom-up).
        const $stack = $("<div>").addClass("mc-chart-stack").css({ position: "absolute", left: 0, right: 0, bottom: 0, display: "flex", "flex-direction": "column-reverse" });
        statuses.forEach(s => {
          const v = p.segs[s] || 0;
          const h = Math.round((v / maxTotal) * plotH);
          if (h <= 0) return;
          $stack.append($("<div>").css({ height: h + "px", width: "100%", "background-color": colors[s] || "#6c757d" }));
        });
        $col.append($stack);

        // Day/week total value label just above the stacked bars (nonzero only).
        if (p.total > 0) {
          $col.append($("<span>").addClass("mc-chart-val").text(p.total).css({ bottom: (segH + 6) + "px" }));
        } else {
          $col.append($("<i>").addClass("mc-chart-zero"));
        }

        // Hover tooltip: date (or week range) + per-status counts.
        $col.attr("title", (weekly ? shortRange(p.start, p.end) : p.start) + " • " + statuses.map(s => s + ": " + (p.segs[s] || 0)).join(", "));

        // Click -> open the concern list pre-filtered to this bar's date range.
        $col.on("click", function () {
          window.location.href = "__CONCERNS_URL__" + "?from=" + encodeURIComponent(p.start) + "&to=" + encodeURIComponent(p.end);
        });

        $chart.append($col);
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      });

      // Axis labels under the bars, matching the column geometry above.
      const $axis = $("<div>").addClass("d-flex").css({ width: "100%", gap: gapPx + "px" });
      if (weekly) {
        periods.forEach(p => {
          const $l = $("<div>").addClass("mc-chart-axis-l").css({ width: colWidth + "px", "flex-shrink": "0", "text-align": "center", "white-space": "nowrap" });
          if (p.isToday) { $l.text("Today"); $l.addClass("mc-chart-axis-today"); }
          else { $l.text(shortDate(p.start)); }
          $axis.append($l);
        });
      } else {
        const anchorCount = Math.min(periods.length, 7);
        const anchorSet = new Set();
        if (anchorCount > 1) {
          for (let i = 0; i < anchorCount; i++) {
            anchorSet.add(Math.round((i / (anchorCount - 1)) * (periods.length - 1)));
          }
        } else {
          anchorSet.add(0);
        }
        anchorSet.add(periods.length - 1); // always label today
        periods.forEach((p, i) => {
          const $l = $("<div>").addClass("mc-chart-axis-l").css({ width: colWidth + "px", "flex-shrink": "0", "text-align": "center", "white-space": "nowrap" });
          if (anchorSet.has(i)) {
            if (p.isToday) { $l.text("Today"); $l.addClass("mc-chart-axis-today"); }
            else { $l.text(shortDate(p.start)); }
          }
          $axis.append($l);
        });
      }
      $axis.css("paddingLeft", GRID_LABEL_PAD + "px");
      $("#statusDateAxis").html("").append($axis);
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    }

    // Re-render the status chart on window resize (debounced) so the responsive
    // weekly columns keep filling the container's current width.
    if (!window.__mcStatusChartResizeBound) {
      window.__mcStatusChartResizeBound = true;
      const refreshStatus = function () {
        if (!labels.length) return;
        const dailyTotals = labels.map((_, i) => statuses.reduce((acc, s) => acc + ((series[s] && series[s][i]) ? series[s][i] : 0), 0));
        const weekly = dailyTotals.filter(t => t > 0).length < SPARSE_DAILY_THRESHOLD;
        $chart.empty();
        renderStatusChart(weekly);
      };
      let resizeTimer = null;
      $(window).on("resize", function () {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(refreshStatus, 150);
      });
    }

    if (typeof window.__renderLucide === 'function') window.__renderLucide();

    const $dept = $("#deptChart").empty();
    const deptLabels = dept && dept.labels ? dept.labels : [];
    const deptCounts = dept && dept.counts ? dept.counts : [];
    const maxDept = Math.max(1, ...deptCounts);
    if (deptLabels.length === 0) {
      $dept.html("<div class='text-muted'>No data.</div>");
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }
    deptLabels.forEach((name, i) => {
      const c = deptCounts[i] != null ? deptCounts[i] : 0;
      const pct = Math.round((c / maxDept) * 100);
      $dept.append(`
        <div class="mb-2">
          <div class="d-flex justify-content-between small">
            <div class="text-truncate" style="max-width: 70%">${esc(name)}</div>
            <div class="text-muted">${esc(c)}</div>
          </div>
          <div class="progress" style="height: 8px;">
            <div class="progress-bar bg-primary" role="progressbar" style="width: ${pct}%"></div>
          </div>
        </div>
      `);
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    });
  }

  $.getJSON("__CHARTS_ENDPOINT__")
    .done(function (res) {
      if (!res || !res.ok) return;
      renderCharts(res);
    });

  function loadRecent() {
    $("#recentHint").html("<div class='text-muted'>Loading...</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    $.getJSON("__RECENT_ENDPOINT__")
      .done(function (res) {
        if (!res || !res.ok) {
          $("#recentHint").html("<div class='text-danger'>Failed to load recent concerns.</div>");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          return;
        }
        const rows = res.concerns || [];
        const $tb = $("#recentTable tbody").empty();
        if (rows.length === 0) {
          $("#recentHint").html("<div class='text-muted'>No concerns found.</div>");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          return;
        }
        $("#recentHint").html("<div class='text-muted small'>Click a row to view details.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        rows.forEach(r => {
          const date = r.created_at ? String(r.created_at).slice(0, 10) : "";
          $tb.append(`
            <tr role="button" data-id="${r.id}">
              <td data-label="Report #"><span class="mc-cell-wrap"><i data-lucide="hash" class="lucide mc-cell-icon"></i><span class="fw-semibold">${esc(r.report_number)}</span></span></td>
              <td data-label="Citizen"><span class="mc-cell-wrap"><i data-lucide="user" class="lucide mc-cell-icon"></i><span>${esc(r.citizen_name)}</span></span></td>
              <td data-label="Concern"><span class="mc-cell-wrap"><i data-lucide="message-square" class="lucide mc-cell-icon"></i><span><span class="fw-semibold">${esc(r.concern_type)}</span><span class="text-muted small d-block">${esc(r.department)}</span></span></span></td>
              <td data-label="Barangay"><span class="mc-cell-wrap"><i data-lucide="map-pin" class="lucide mc-cell-icon"></i><span>${esc(r.barangay)}</span></span></td>
              <td data-label="Status">${badge(r.status)}</td>
              <td data-label="Date"><span class="mc-cell-wrap"><i data-lucide="calendar-days" class="lucide mc-cell-icon"></i><span>${esc(date)}</span></span></td>
            </tr>
          `);
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
        });
      })
      .fail(function () {
        $("#recentHint").html("<div class='text-danger'>Failed to load recent concerns.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      });
  }

  $("#recentTable").on("click", "tr[data-id]", function () {
    const id = $(this).data("id");
    window.location.href = "__VIEW_URL__" + "?id=" + encodeURIComponent(id);
  });

  loadRecent();
});
</script>
HTML;
$pageScripts = str_replace(
    ["__COUNTS_ENDPOINT__", "__RECENT_ENDPOINT__", "__CHARTS_ENDPOINT__", "__VIEW_URL__", "__CONCERNS_URL__"],
    [$countsEndpoint, $recentEndpoint, $chartsEndpoint, $viewUrl, $concernsUrl],
    $pageScripts
);
?>

<div class="modal fade mh" id="phHolidayCalendarModal" tabindex="-1" aria-labelledby="phHolidayCalendarModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header text-white">
                <div>
                    <h1 class="modal-title" id="phHolidayCalendarModalLabel"><i data-lucide="calendar-range" class="lucide-14"></i> Philippine Official Holidays · <?= (int)$_curYear ?></h1>
                    <div class="small">Regular holidays, special non-working days, and observances — use to plan staffing and SLA targets</div>
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
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/admin/concerns.php')) ?>"><i data-lucide="clipboard-list" class="lucide-14"></i> Concern queue</a>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/admin/ba_schedules.php')) ?>"><i data-lucide="calendar-days" class="lucide-14"></i> Manage schedules</a>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<img src="<?= e(app_url('/assets/img/cityhall.png')) ?>" alt="Marikina City Hall Watermark" style="position:fixed; bottom:20px; right:20px; width:200px; height:auto; opacity:0.2; z-index:0; pointer-events:none;">

<?php
require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
