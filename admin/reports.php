<?php

declare(strict_types=1);

$pageTitle = 'Reports';
$activeNav = 'reports';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

/* Super Admin only (the API enforces this too, via reports_summary.php). */
if (!$isSuper) {
    http_response_code(403);
    ?>
    <div class="mc-neo-card">
        <div class="card-body p-4">
            <div class="fw-bold text-danger">Forbidden</div>
            <div class="text-muted">This page is available to Super Admin only.</div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
    require_once __DIR__ . '/../includes/partials/foot.php';
    exit;
}

$summaryEndpoint    = e(app_url('/admin/api/reports_summary.php'));
$departmentsEndpoint = e(app_url('/admin/api/departments.php?include_inactive=1'));
$exportEndpoint     = e(app_url('/admin/api/reports_export.php'));

$pageScripts = <<<'HTML'
<script>
$(function () {
  const summaryEndpoint = "__SUMMARY_ENDPOINT__";
  const departmentsEndpoint = "__DEPARTMENTS_ENDPOINT__";
  const exportEndpoint = "__EXPORT_ENDPOINT__";

  /* Canonical concern statuses, in the order the DB ENUM declares them.
     Must stay in sync with concerns.status in database/schema.sql — the API
     silently drops any status not in its own list, so a mismatch would quietly
     under-report totals. */
  const STATUSES = ["New", "Ongoing", "Acknowledge", "Completed", "Cancelled"];
  const STATUS_META = {
    "New":        { color: "#3368A0", icon: "mail-plus",        chip: "blue"  },
    "Ongoing":    { color: "#D6932E", icon: "loader",           chip: "amber" },
    "Acknowledge":{ color: "#66A3BF", icon: "eye",              chip: "sky"   },
    "Completed":  { color: "#4A8F7E", icon: "circle-check",     chip: "mint"  },
    "Cancelled":  { color: "#A84444", icon: "ban",              chip: "red"   }
  };

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }
  function icons() { if (typeof window.__renderLucide === 'function') window.__renderLucide(); }

  /* ---------- date helpers (local time, no UTC shift) ---------- */
  function iso(d) {
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return d.getFullYear() + "-" + mm + "-" + dd;
  }
  function parseIso(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ""));
    if (!m) return null;
    const y = +m[1], mo = +m[2], d = +m[3];
    const dt = new Date(y, mo - 1, d);
    /* Reject rolled-over dates. `new Date(2026, 12, 99)` silently becomes
       2027-04-08, so without this round-trip check the client would happily
       send a range the API's strict parse_date() answers 422 for. */
    if (isNaN(dt.getTime())
        || dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) {
      return null;
    }
    return dt;
  }
  function daysBetween(a, b) {
    return Math.round((b - a) / 86400000);
  }
  function shortDate(s) {
    const d = parseIso(s);
    return d ? d.toLocaleDateString(undefined, { month: "short", day: "numeric" }) : s;
  }

  /* ---------- range presets ---------- */
  function applyPreset(kind) {
    const end = new Date();
    let start;
    if (kind === "7") { start = new Date(end); start.setDate(end.getDate() - 6); }
    else if (kind === "month") start = new Date(end.getFullYear(), end.getMonth(), 1);
    else if (kind === "90") { start = new Date(end); start.setDate(end.getDate() - 89); }
    else { start = new Date(end); start.setDate(end.getDate() - 29); }
    $("#startDate").val(iso(start));
    $("#endDate").val(iso(end));
  }

  function markPreset(kind) {
    $(".mc-admin-rp-chip").removeClass("active").attr("aria-pressed", "false");
    $(".mc-admin-rp-chip[data-preset='" + kind + "']").addClass("active").attr("aria-pressed", "true");
  }

  function currentPreset() {
    const s = parseIso($("#startDate").val());
    const e = parseIso($("#endDate").val());
    if (!s || !e) return "";
    const today = new Date(); today.setHours(0, 0, 0, 0);
    if (e.getTime() !== today.getTime()) return "";
    const n = daysBetween(s, e) + 1;
    if (n === 7) return "7";
    if (n === 30) return "30";
    if (n === 90) return "90";
    if (s.getDate() === 1 && s.getMonth() === today.getMonth() && s.getFullYear() === today.getFullYear()) return "month";
    return "";
  }

  function setRangeHint(text, isError) {
    $("#rangeHint").text(text).toggleClass("is-error", !!isError);
  }

  function setLoading(on) {
    $(".mc-admin-rp-panel").toggleClass("is-loading", !!on);
    $("#applyBtn").prop("disabled", !!on);
  }

  function buildQuery() {
    const q = new URLSearchParams();
    q.set("start", $("#startDate").val());
    q.set("end", $("#endDate").val());
    const dept = $("#departmentId").val();
    if (dept && dept !== "0") q.set("department_id", dept);
    return q.toString();
  }

  /* ---------- KPI tiles ---------- */
  function renderKpis(totals, totalAll) {
    const tiles = [{ label: "Total Concerns", value: totalAll, icon: "layers", chip: "navy" }]
      .concat(STATUSES.map(function (s) {
        return { label: s, value: totals[s] || 0, icon: STATUS_META[s].icon, chip: STATUS_META[s].chip };
      }));
    $("#summaryCards").html(tiles.map(function (t) {
      return '' +
        '<div class="mc-admin-kpi-card' + (t.value === 0 ? ' is-zero' : '') + '">' +
          '<div class="mc-admin-kpi-head">' +
            '<div class="mc-admin-kpi-icon mc-admin-kpi-icon--' + t.chip + '">' +
              '<i data-lucide="' + t.icon + '" class="lucide" aria-hidden="true"></i>' +
            '</div>' +
          '</div>' +
          '<p class="mc-admin-kpi-label">' + esc(t.label) + '</p>' +
          '<div class="mc-admin-kpi-value">' + t.value + '</div>' +
        '</div>';
    }).join(""));
    icons();
  }

  /* ---------- Daily volume: hand-rolled SVG bar chart ----------
     The old markup drew 30 empty div slots (the bar was only appended when
     height > 0), so 27 of 30 days rendered as nothing at all. This draws a
     real axis, keeps a visible 1px stub for zero days, and scrolls horizontally
     past ~60 bars so long ranges never compress into slivers. */
  function renderDailyChart(labels, counts) {
    var host = $("#dailyChart");
    host.empty();

    if (!labels.length) { host.html(emptyBlock("No range selected", "Pick a start and end date.")); return; }

    var total = counts.reduce(function (a, b) { return a + (b || 0); }, 0);
    if (total === 0) {
      host.html(emptyBlock("No concerns in this range",
        "Nothing was filed between " + esc(shortDate(labels[0])) + " and " + esc(shortDate(labels[labels.length - 1])) + "."));
      $("#chartPeak").text("");
      return;
    }

    var max = counts.reduce(function (a, b) { return Math.max(a, b || 0); }, 0);
    var plotH = 180, padL = 34, padR = 8, padT = 10, padB = 26;
    var slot = labels.length > 60 ? 14 : 22;
    var barW = Math.max(3, slot - 8);
    var innerW = labels.length * slot;
    var w = padL + innerW + padR;
    var h = padT + plotH + padB;

    // Round the axis up to a friendly step so gridline labels are readable.
    var step = Math.max(1, Math.ceil(max / 4));
    var top = Math.ceil(max / step) * step;

    var svg = ['<svg class="mc-admin-rp-chart" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h +
               '" role="img" aria-label="Daily concern volume from ' + esc(labels[0]) + ' to ' + esc(labels[labels.length - 1]) + '">'];

    // gridlines + y labels
    for (var g = 0; g <= top; g += step) {
      var y = padT + plotH - (g / top) * plotH;
      svg.push('<line class="rp-grid" x1="' + padL + '" y1="' + y.toFixed(1) + '" x2="' + (w - padR) + '" y2="' + y.toFixed(1) + '"></line>');
      svg.push('<text class="rp-axis-text" x="' + (padL - 7) + '" y="' + (y + 3.5).toFixed(1) + '" text-anchor="end">' + g + '</text>');
    }

    // x labels: first, middle, last only (a label per bar is unreadable)
    [0, Math.floor((labels.length - 1) / 2), labels.length - 1]
      .filter(function (v, i, a) { return a.indexOf(v) === i; })
      .forEach(function (i) {
        var x = padL + i * slot + slot / 2;
        var anchor = i === 0 ? "start" : (i === labels.length - 1 ? "end" : "middle");
        svg.push('<text class="rp-axis-text" x="' + x.toFixed(1) + '" y="' + (padT + plotH + 16) + '" text-anchor="' + anchor + '">' +
                 esc(shortDate(labels[i])) + '</text>');
      });

    // bars
    labels.forEach(function (d, i) {
      var v = counts[i] || 0;
      var x = padL + i * slot;
      var bh = v > 0 ? Math.max(3, (v / top) * plotH) : 0;
      var y = padT + plotH - bh;
      // Native tooltip via <title>; the transparent hit rect keeps a 1px
      // zero-day tappable so hovering it still reports the date.
      svg.push('<g><title>' + esc(d) + " · " + v + (v === 1 ? " concern" : " concerns") + '</title>');
      svg.push('<rect class="rp-bar-hit" x="' + x + '" y="' + padT + '" width="' + slot + '" height="' + plotH + '"></rect>');
      if (bh > 0) {
        svg.push('<rect class="rp-bar" x="' + (x + (slot - barW) / 2) + '" y="' + y.toFixed(1) + '" width="' + barW + '" height="' + bh.toFixed(1) + '" rx="2"></rect>');
      } else {
        svg.push('<rect class="rp-zero" x="' + (x + (slot - barW) / 2) + '" y="' + (padT + plotH - 2) + '" width="' + barW + '" height="2" rx="1"></rect>');
      }
      svg.push('</g>');
    });

    svg.push('</svg>');
    host.html(svg.join(""));
    $("#chartPeak").text("Peak " + max + (max === 1 ? " concern" : " concerns") + " · " + total + " total");
    icons();
  }

  function emptyBlock(title, body) {
    return '<div class="mc-admin-empty">' +
             '<div class="mc-admin-empty-icon"><i data-lucide="inbox" class="lucide" aria-hidden="true"></i></div>' +
             '<h4>' + title + '</h4><p>' + body + '</p>' +
           '</div>';
  }

  /* ---------- Status distribution ---------- */
  function renderDistribution(totals, totalAll) {
    var host = $("#statusDist");
    host.empty();
    if (totalAll === 0) {
      host.html(emptyBlock("Nothing to distribute", "No concerns were filed in the selected range."));
      return;
    }
    var segs = STATUSES.filter(function (s) { return (totals[s] || 0) > 0; });
    var bar = '<div class="mc-admin-rp-stack" role="img" aria-label="Status distribution">';
    segs.forEach(function (s) {
      var pct = (totals[s] / totalAll) * 100;
      bar += '<span class="mc-admin-rp-stack-seg" style="width:' + pct.toFixed(2) + '%;background:' + STATUS_META[s].color + '" title="' +
             esc(s) + ': ' + totals[s] + '"></span>';
    });
    bar += '</div>';

    var legend = '<div class="mc-admin-rp-legend">';
    STATUSES.forEach(function (s) {
      var v = totals[s] || 0;
      var pct = totalAll ? Math.round((v / totalAll) * 100) : 0;
      legend += '<div class="mc-admin-rp-legend-row">' +
                  '<span class="mc-admin-rp-swatch" style="background:' + STATUS_META[s].color + '"></span>' +
                  '<span class="mc-admin-rp-legend-name">' + esc(s) + '</span>' +
                  '<span class="mc-admin-rp-legend-val">' + v + ' · ' + pct + '%</span>' +
                '</div>';
    });
    legend += '</div>';

    host.html(bar + legend);
  }

  /* ---------- Average resolution ---------- */
  function renderAvg(avg) {
    var hours = avg && avg.avg_hours != null ? Number(avg.avg_hours) : null;
    var n = avg && avg.resolved_count != null ? Number(avg.resolved_count) : 0;
    if (hours == null) {
      $("#avgHours").text("—");
      $("#avgMeta").text("No completed concerns in this range.");
      return;
    }
    // Under an hour reads better in minutes than "0.4 hrs".
    $("#avgHours").text(hours < 1 ? Math.round(hours * 60) + " min" : hours + " hrs");
    $("#avgMeta").text("Based on " + n + " completed concern" + (n === 1 ? "" : "s") +
                       ", measured from submission to the first Completed entry.");
  }

  /* ---------- Department matrix ---------- */
  function renderDeptTable(byDept) {
    var $tb = $("#deptTable tbody").empty();
    $("#deptTable tfoot").empty();

    if (!byDept || byDept.length === 0) {
      $tb.append('<tr><td colspan="7">' +
        emptyBlock("No concerns in this range",
          "Nothing was filed for the selected dates and department.") + '</td></tr>');
      $("#deptCount").text("0 departments");
      icons();
      return;
    }

    var totals = {};
    STATUSES.forEach(function (s) { totals[s] = 0; });
    var grand = 0;

    byDept.forEach(function (r) {
      var c = r.counts || {};
      var rowTotal = 0;
      STATUSES.forEach(function (s) {
        var v = c[s] || 0;
        totals[s] += v;
        rowTotal += v;
      });
      grand += rowTotal;

      var tds = STATUSES.map(function (s) {
        var v = c[s] || 0;
        return '<td data-label="' + esc(s) + '" class="mc-admin-rp-num' + (v === 0 ? ' mc-admin-rp-num--zero' : '') + '">' + v + '</td>';
      }).join("");

      $tb.append(
        '<tr data-dept="' + esc(r.department) + '">' +
          '<td data-label="Department" class="mc-admin-rp-dept-cell">' +
            '<span class="mc-cell-wrap">' +
              '<span class="mc-admin-rp-avatar"><i data-lucide="building-2" class="lucide" aria-hidden="true"></i></span>' +
              '<span class="fw-semibold">' + esc(r.department) + '</span>' +
            '</span>' +
          '</td>' + tds +
          '<td data-label="Total" class="mc-admin-rp-num mc-admin-rp-num--total">' + rowTotal + '</td>' +
        '</tr>');
    });

    // Column totals — the whole point of a status matrix.
    var footCells = STATUSES.map(function (s) {
      return '<td class="mc-admin-rp-num">' + totals[s] + '</td>';
    }).join("");
    $("#deptTable tfoot").html(
      '<tr class="mc-admin-rp-row-total">' +
        '<td class="mc-admin-rp-dept-cell">All departments</td>' + footCells +
        '<td class="mc-admin-rp-num mc-admin-rp-num--total">' + grand + '</td>' +
      '</tr>');

    $("#deptCount").text(byDept.length + (byDept.length === 1 ? " department" : " departments") +
                         " · " + grand + " concern" + (grand === 1 ? "" : "s"));
    icons();
  }

  /* ---------- load ---------- */
  function loadReport() {
    var start = parseIso($("#startDate").val());
    var end   = parseIso($("#endDate").val());

    // Validate client-side too: the API answers 422 for an inverted range, and
    // the old code fired the request anyway and only showed "Failed to load."
    if (!start || !end) {
      setRangeHint("Enter a valid start and end date.", true);
      return;
    }
    if (end.getTime() < start.getTime()) {
      setRangeHint("The end date must be on or after the start date.", true);
      return;
    }
    // A multi-year range would render thousands of bars; cap it and say so.
    var span = daysBetween(start, end) + 1;
    if (span > 366) {
      setRangeHint("Please narrow the range to 366 days or fewer.", true);
      return;
    }

    setRangeHint("Loading…", false);
    setLoading(true);
    // The export link used to be pointed at the *request* before the response
    // arrived, so a 422 left a dead download link. It is now armed from the
    // range the server actually confirmed.
    $("#exportBtn").attr("aria-disabled", "true");

    $.getJSON(summaryEndpoint + "?" + buildQuery())
      .done(function (res) {
        setLoading(false);
        if (!res || !res.ok) {
          setRangeHint((res && res.error) ? String(res.error) : "Failed to load the report.", true);
          return;
        }

        var totals = (res && res.totals_by_status) ? res.totals_by_status : {};
        var totalAll = STATUSES.reduce(function (a, s) { return a + (totals[s] != null ? totals[s] : 0); }, 0);

        var range = res.range || {};
        $("#startDate").val(range.start || $("#startDate").val());
        $("#endDate").val(range.end || $("#endDate").val());
        markPreset(currentPreset());

        setRangeHint("Range: " + range.start + " to " + range.end +
                     " · " + (totalAll === 0 ? "no concerns" : totalAll + " concern" + (totalAll === 1 ? "" : "s")), false);

        renderKpis(totals, totalAll);

        var dv = (res && res.daily_volume) ? res.daily_volume : { labels: [], counts: [] };
        renderDailyChart(dv.labels || [], dv.counts || []);
        renderDistribution(totals, totalAll);
        renderAvg(res.avg_resolution);
        renderDeptTable(res.by_department || []);

        var q = new URLSearchParams();
        q.set("start", range.start);
        q.set("end", range.end);
        var dept = $("#departmentId").val();
        if (dept && dept !== "0") q.set("department_id", dept);
        $("#exportBtn").attr("href", exportEndpoint + "?" + q.toString()).attr("aria-disabled", "false");
        icons();
      })
      .fail(function (xhr) {
        setLoading(false);
        var msg = "Failed to load the report.";
        if (xhr && xhr.status === 422) msg = "The server rejected that date range.";
        setRangeHint(msg, true);
        icons();
      });
  }

  /* ---------- wiring ---------- */
  function loadDepartments() {
    return $.getJSON(departmentsEndpoint)
      .done(function (res) {
        if (!res || !res.ok) return;
        var $sel = $("#departmentId").empty().append('<option value="0">All departments</option>');
        (res.departments || []).forEach(function (d) {
          if (d.id == null) return;
          var inactive = Number(d.active) === 1 ? "" : " (inactive)";
          $sel.append('<option value="' + esc(d.id) + '">' + esc(d.name) + inactive + "</option>");
        });
        icons();
      });
  }

  applyPreset("30");
  markPreset("30");
  loadDepartments().always(loadReport);

  $("#filters").on("submit", function (e) { e.preventDefault(); loadReport(); });
  $("#departmentId").on("change", loadReport);
  ["#startDate", "#endDate"].forEach(function (sel) {
    $(sel).on("change", function () { markPreset(currentPreset()); loadReport(); });
  });
  $("#refreshBtn").on("click", function () {
    loadDepartments().always(loadReport);
  });
  $(".mc-admin-rp-presets").on("click", ".mc-admin-rp-chip", function () {
    var kind = String($(this).data("preset") || "30");
    applyPreset(kind);
    markPreset(kind);
    loadReport();
  });
  $("#resetBtn").on("click", function () {
    /* setDefaults() existed in the old file but was only ever called once at
       load time and was unreachable from the UI. */
    $("#departmentId").val("0");
    applyPreset("30");
    markPreset("30");
    loadReport();
  });
});
</script>
HTML;

$pageScripts = str_replace(
    ['__SUMMARY_ENDPOINT__', '__DEPARTMENTS_ENDPOINT__', '__EXPORT_ENDPOINT__'],
    [$summaryEndpoint, $departmentsEndpoint, $exportEndpoint],
    $pageScripts
);

?>
<div class="mc-admin-reports-page">

    <!-- ==================== HERO ==================== -->
    <div class="mc-admin-hero">
        <div class="mc-admin-hero-inner">
            <div class="mc-admin-hero-title-row">
                <div class="mc-admin-hero-icon">
                    <i data-lucide="bar-chart-3" class="lucide lucide-24"></i>
                </div>
                <div class="mc-admin-hero-title">
                    <h1>Reports &amp; Analytics</h1>
                    <p>Concern volume, status mix, and resolution times across every department. Filter a date range, then export the matching rows to CSV.</p>
                </div>
            </div>
            <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
                 alt="Official Seal of the City of Marikina"
                 class="mc-admin-hero-seal" loading="eager" decoding="async">
        </div>
    </div>

    <!-- ==================== FILTERS ==================== -->
    <div class="mc-admin-section-card mb-3">
        <div class="mc-admin-section-body">
            <form id="filters" novalidate>
                <div class="mc-admin-rp-fields">
                    <div class="mc-admin-rp-field">
                        <label class="form-label" for="startDate">Start date</label>
                        <input type="date" class="form-control" id="startDate" required>
                    </div>
                    <div class="mc-admin-rp-field">
                        <label class="form-label" for="endDate">End date</label>
                        <input type="date" class="form-control" id="endDate" required>
                    </div>
                    <div class="mc-admin-rp-field">
                        <label class="form-label" for="departmentId">Department</label>
                        <select class="form-select" id="departmentId">
                            <option value="0">All departments</option>
                        </select>
                    </div>
                    <div class="mc-admin-rp-field">
                        <button class="btn btn-primary mc-admin-rp-apply" type="submit" id="applyBtn">
                            <i data-lucide="filter" class="lucide lucide-16" aria-hidden="true"></i> Apply
                        </button>
                    </div>
                </div>

                <div class="mc-admin-rp-presets" role="group" aria-label="Quick date ranges">
                    <button type="button" class="mc-admin-rp-chip" data-preset="7" aria-pressed="false">Last 7 days</button>
                    <button type="button" class="mc-admin-rp-chip active" data-preset="30" aria-pressed="true">Last 30 days</button>
                    <button type="button" class="mc-admin-rp-chip" data-preset="90" aria-pressed="false">Last 90 days</button>
                    <button type="button" class="mc-admin-rp-chip" data-preset="month" aria-pressed="false">This month</button>
                </div>
            </form>

            <div class="mc-admin-rp-actions">
                <div class="mc-admin-rp-btns">
                    <a class="btn btn-primary mc-admin-rp-export" id="exportBtn" href="#" aria-disabled="true">
                        <i data-lucide="download" class="lucide lucide-16" aria-hidden="true"></i> Export CSV
                    </a>
                    <button class="btn btn-outline-secondary mc-admin-rp-reset" type="button" id="resetBtn">
                        <i data-lucide="rotate-ccw" class="lucide lucide-16" aria-hidden="true"></i> Reset
                    </button>
                    <button class="btn btn-outline-secondary mc-admin-rp-reset" type="button" id="refreshBtn">
                        <i data-lucide="refresh-cw" class="lucide lucide-16" aria-hidden="true"></i> Refresh
                    </button>
                </div>
                <div class="mc-admin-rp-range" id="rangeHint" role="status" aria-live="polite"></div>
            </div>
        </div>
    </div>

    <!-- ==================== KPI TILES ==================== -->
    <div class="mc-admin-kpi-grid" id="summaryCards"></div>

    <!-- ==================== CHART + DISTRIBUTION ==================== -->
    <div class="mc-admin-rp-split">
        <div class="mc-admin-rp-panel">
            <div class="mc-admin-rp-panel-head">
                <h3 class="mc-admin-rp-panel-title"><i data-lucide="bar-chart-3" class="lucide" aria-hidden="true"></i> Daily volume</h3>
                <span class="mc-admin-rp-peak" id="chartPeak"></span>
            </div>
            <p class="mc-admin-rp-panel-sub">Concerns created per day. Hover a bar for the exact count.</p>
            <div class="mc-admin-rp-chart-scroll">
                <div id="dailyChart"></div>
            </div>
        </div>

        <div class="mc-admin-rp-panel">
            <div class="mc-admin-rp-panel-head">
                <h3 class="mc-admin-rp-panel-title"><i data-lucide="chart-pie" class="lucide" aria-hidden="true"></i> Status mix</h3>
            </div>
            <p class="mc-admin-rp-panel-sub">Share of concerns by current status.</p>
            <div id="statusDist"></div>

            <div class="mc-admin-rp-avg">
                <p class="mc-admin-kpi-label">Average resolution time</p>
                <div class="mc-admin-rp-avg-value" id="avgHours">&mdash;</div>
                <p class="mc-admin-rp-avg-note" id="avgMeta"></p>
            </div>
        </div>
    </div>

    <!-- ==================== DEPARTMENT MATRIX ==================== -->
    <div class="mc-admin-table-card">
        <div class="mc-admin-table-head">
            <h3><i data-lucide="table" class="lucide" aria-hidden="true"></i> Status breakdown per department</h3>
            <span class="text-muted small" id="deptCount"></span>
        </div>
        <div class="mc-admin-table-wrap">
            <table class="table table-hover align-middle mb-0" id="deptTable">
                <thead>
                    <tr>
                        <th><span class="mc-th-wrap"><i data-lucide="building-2" class="lucide mc-th-icon" aria-hidden="true"></i>Department</span></th>
                        <th class="mc-admin-rp-num"><span class="mc-th-wrap mc-th-wrap--end">New</span></th>
                        <th class="mc-admin-rp-num"><span class="mc-th-wrap mc-th-wrap--end">Ongoing</span></th>
                        <th class="mc-admin-rp-num"><span class="mc-th-wrap mc-th-wrap--end">Acknowledge</span></th>
                        <th class="mc-admin-rp-num"><span class="mc-th-wrap mc-th-wrap--end">Completed</span></th>
                        <th class="mc-admin-rp-num"><span class="mc-th-wrap mc-th-wrap--end">Cancelled</span></th>
                        <th class="mc-admin-rp-num"><span class="mc-th-wrap mc-th-wrap--end">Total</span></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot></tfoot>
            </table>
        </div>
    </div>

</div>

<?php
require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
