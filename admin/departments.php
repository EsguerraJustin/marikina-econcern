<?php

declare(strict_types=1);

$pageTitle = 'Departments';
$activeNav = 'departments';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

/* Super Admin only. Render the Forbidden card and bail out BEFORE the page
   scripts run: the old version set http_response_code(403) and then carried
   on, so a department admin received a 403 response that still contained a
   live table plus a JS bundle firing a doomed API request. */
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

?>
<div class="mc-admin-departments-page">

    <!-- ==================== HERO ==================== -->
    <div class="mc-admin-hero">
        <div class="mc-admin-hero-inner">
            <div class="mc-admin-hero-title-row">
                <div class="mc-admin-hero-icon">
                    <i data-lucide="building-2" class="lucide lucide-24"></i>
                </div>
                <div class="mc-admin-hero-title">
                    <h1>Departments</h1>
                    <p>Enable or disable the city departments residents can file concerns against. Department names follow the official Marikina directory.</p>
                </div>
            </div>
            <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
                 alt="Official Seal of the City of Marikina"
                 class="mc-admin-hero-seal" loading="eager" decoding="async">
        </div>
    </div>

    <!-- ==================== KPI TILES ==================== -->
    <div class="mc-admin-kpi-grid">
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--blue"><i data-lucide="building-2" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">Total Departments</p>
            <div class="mc-admin-kpi-value" id="kpiTotal">&mdash;</div>
            <p class="mc-admin-kpi-sub"><i data-lucide="list-checks" class="lucide"></i> Active and inactive combined</p>
        </div>
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--mint"><i data-lucide="circle-check" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">Active</p>
            <div class="mc-admin-kpi-value" id="kpiActive">&mdash;</div>
            <p class="mc-admin-kpi-sub"><i data-lucide="megaphone" class="lucide"></i> Visible to residents</p>
        </div>
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--amber"><i data-lucide="circle-minus" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">Inactive</p>
            <div class="mc-admin-kpi-value" id="kpiInactive">&mdash;</div>
            <p class="mc-admin-kpi-sub"><i data-lucide="eye-off" class="lucide"></i> Hidden from submission form</p>
        </div>
    </div>

    <!-- ==================== TOOLBAR ==================== -->
    <div class="mc-admin-section-card mb-3">
        <div class="mc-admin-section-body">
            <div class="mc-admin-dept-toolbar">
                <div class="mc-admin-dept-search-wrap">
                    <label class="visually-hidden" for="deptSearch">Search departments</label>
                    <i data-lucide="search" class="lucide mc-admin-dept-search-icon" aria-hidden="true"></i>
                    <input class="form-control mc-admin-dept-search-input" id="deptSearch" type="search"
                           placeholder="Search departments&hellip; (Esc to clear)" autocomplete="off">
                </div>
                <div class="mc-admin-dept-chips" id="deptChips" role="group" aria-label="Filter departments by status">
                    <button type="button" class="mc-admin-dept-chip active" data-status="all" aria-pressed="true">All</button>
                    <button type="button" class="mc-admin-dept-chip" data-status="active" aria-pressed="false">Active</button>
                    <button type="button" class="mc-admin-dept-chip" data-status="inactive" aria-pressed="false">Inactive</button>
                </div>
                <button class="btn btn-outline-secondary mc-admin-dept-refresh" type="button" id="refreshBtn">
                    <i data-lucide="refresh-cw" class="lucide lucide-16" aria-hidden="true"></i> Refresh
                </button>
            </div>
            <div class="mc-admin-dept-info">
                <i data-lucide="info" class="lucide" aria-hidden="true"></i>
                <span>Changes save automatically. Inactive departments are hidden from the citizen submission form and stop accepting new concerns.</span>
            </div>
        </div>
    </div>

    <!-- ==================== TABLE ==================== -->
    <div class="mc-admin-table-card">
        <div class="mc-admin-table-head">
            <h3><i data-lucide="building-2" class="lucide" aria-hidden="true"></i> All Departments</h3>
            <span class="text-muted small" id="deptCount"></span>
        </div>
        <div class="mc-admin-table-wrap">
            <table class="table table-hover align-middle mb-0" id="departmentsTable">
                <thead>
                    <tr>
                        <th><span class="mc-th-wrap"><i data-lucide="landmark" class="lucide mc-th-icon" aria-hidden="true"></i>Department</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="activity" class="lucide mc-th-icon" aria-hidden="true"></i>Status</span></th>
                        <th class="text-end"><span class="mc-th-wrap mc-th-wrap--end"><i data-lucide="toggle-right" class="lucide mc-th-icon" aria-hidden="true"></i>Active</span></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="px-3 pb-3" id="deptHint" role="status" aria-live="polite"></div>
    </div>

</div>

<?php
$endpoint = e(app_url('/admin/api/departments.php'));
$pageScripts = <<<'HTML'
<script>
$(function () {
  const endpoint = "__ENDPOINT__";

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }
  function icons() { if (typeof window.__renderLucide === 'function') window.__renderLucide(); }

  /* Last successful payload. Search + status filter run against this cache so
     typing never re-hits the API. */
  let allRows = [];
  let query = "";
  let statusFilter = "all";

  function statusBadge(isActive) {
    return isActive
      ? '<span class="mc-admin-badge mc-admin-badge--success"><i data-lucide="circle-check" class="lucide" aria-hidden="true"></i>Active</span>'
      : '<span class="mc-admin-badge mc-admin-badge--muted"><i data-lucide="circle-minus" class="lucide" aria-hidden="true"></i>Inactive</span>';
  }

  function matches(r) {
    if (statusFilter === "active" && Number(r.active) !== 1) return false;
    if (statusFilter === "inactive" && Number(r.active) === 1) return false;
    if (!query) return true;
    return String(r.name == null ? "" : r.name).toLowerCase().indexOf(query) !== -1;
  }

  function setKpis(rows) {
    const total = rows.length;
    const active = rows.filter(function (r) { return Number(r.active) === 1; }).length;
    $("#kpiTotal").text(total);
    $("#kpiActive").text(active);
    $("#kpiInactive").text(total - active);
  }

  function setCount(shown) {
    const total = allRows.length;
    $("#deptCount").text(
      shown === total
        ? total + (total === 1 ? " department" : " departments")
        : shown + " of " + total + " departments"
    );
  }

  function render() {
    const $tb = $("#departmentsTable tbody").empty();
    const rows = allRows.filter(matches);
    setCount(rows.length);

    if (rows.length === 0) {
      $tb.append(
        '<tr class="mc-empty-row"><td colspan="3">' +
          '<div class="mc-admin-empty">' +
            '<div class="mc-admin-empty-icon"><i data-lucide="search-x" class="lucide-24" aria-hidden="true"></i></div>' +
            '<h4>No matching departments</h4>' +
            '<p>Clear the search box or pick a different status filter.</p>' +
          '</div>' +
        '</td></tr>'
      );
      icons();
      return;
    }

    rows.forEach(function (r) {
      const isActive = Number(r.active) === 1;
      $tb.append(`
            <tr data-id="${r.id}">
              <td data-label="Department">
                <span class="mc-cell-wrap">
                  <span class="mc-admin-dept-avatar"><i data-lucide="landmark" class="lucide" aria-hidden="true"></i></span>
                  <span class="fw-semibold">${esc(r.name)}</span>
                </span>
              </td>
              <td data-label="Status">${statusBadge(isActive)}</td>
              <td data-label="Active" class="mc-admin-dept-active-col">
                <span class="mc-admin-switch">
                  <input type="checkbox" class="dept-active" role="switch"
                         data-id="${r.id}" aria-label="Enable ${esc(r.name)}"
                         ${isActive ? "checked" : ""}>
                </span>
              </td>
            </tr>
      `);
    });
    icons();
  }

  function setHint(html) { $("#deptHint").html(html); }

  function load() {
    setHint("<div class='text-muted small'>Loading departments&hellip;</div>");
    icons();

    $.getJSON(endpoint, { include_inactive: 1 })
      .done(function (res) {
        if (!res || !res.ok) {
          setHint("<div class='text-danger small'>Failed to load departments. Check your connection, then press Refresh.</div>");
          icons();
          return;
        }
        allRows = res.departments || [];
        setKpis(allRows);
        render();
        setHint("<div class='text-muted small'>Changes save automatically.</div>");
        icons();
      })
      .fail(function () {
        setHint("<div class='text-danger small'>Failed to load departments. Check your connection, then press Refresh.</div>");
        icons();
      });
  }

  /* Optimistic toggle. On failure the switch is put back where it was, because
     the old version left it in the new position while the database still held
     the old value. */
  $("#departmentsTable").on("change", ".dept-active", function () {
    const $input = $(this);
    const id = $input.data("id");
    const next = $input.is(":checked") ? 1 : 0;
    const prev = next === 1 ? 0 : 1;

    $input.prop("disabled", true);
    $.post(endpoint, { action: "set_active", id: id, active: next })
      .done(function (res) {
        if (!res || !res.ok) {
          $input.prop("checked", prev === 1);
          setHint("<div class='text-danger small'>Could not save that change, so the switch was put back.</div>");
          icons();
          return;
        }
        const row = allRows.filter(function (r) { return Number(r.id) === Number(id); })[0];
        if (row) row.active = next;
        setKpis(allRows);
        $input.closest("td").html(statusBadge(next === 1));
        setHint("<div class='text-muted small'>Saved.</div>");
        icons();
      })
      .fail(function () {
        $input.prop("checked", prev === 1);
        setHint("<div class='text-danger small'>Could not save that change, so the switch was put back.</div>");
        icons();
      })
      .always(function () {
        $input.prop("disabled", false);
      });
  });

  /* Debounced so a fast typist does not re-render the table on every keypress. */
  let searchTimer = null;
  $("#deptSearch").on("input", function () {
    const v = String($(this).val() || "").trim().toLowerCase();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { query = v; render(); }, 180);
  });
  $("#deptSearch").on("keydown", function (e) {
    if (e.key === "Escape") { e.preventDefault(); $(this).val("").trigger("input"); }
  });

  $("#deptChips").on("click", ".mc-admin-dept-chip", function () {
    const $btn = $(this);
    statusFilter = String($btn.data("status") || "all");
    $btn.addClass("active").attr("aria-pressed", "true");
    $btn.siblings().removeClass("active").attr("aria-pressed", "false");
    render();
  });

  $("#refreshBtn").on("click", load);

  load();
});
</script>
HTML;
$pageScripts = str_replace('__ENDPOINT__', $endpoint, $pageScripts);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
