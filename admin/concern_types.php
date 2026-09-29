<?php

declare(strict_types=1);

$pageTitle = 'Concern Types';
$activeNav = 'types';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

/* Super Admin only. Render the Forbidden card and bail out BEFORE the page
   scripts run: the old version set http_response_code(403) and then carried
   on, so a department admin received a 403 response that still contained a
   live table plus a JS bundle firing doomed API requests. */
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
<div class="mc-admin-concern_types-page">

    <!-- ==================== HERO ==================== -->
    <div class="mc-admin-hero">
        <div class="mc-admin-hero-inner">
            <div class="mc-admin-hero-title-row">
                <div class="mc-admin-hero-icon">
                    <i data-lucide="tags" class="lucide lucide-24"></i>
                </div>
                <div class="mc-admin-hero-title">
                    <h1>Concern Types</h1>
                    <p>Enable or disable the concern categories residents can pick per department. The list follows the official per-department categories.</p>
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
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--blue"><i data-lucide="tags" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">Total Types</p>
            <div class="mc-admin-kpi-value" id="kpiTotal">&mdash;</div>
            <p class="mc-admin-kpi-sub"><i data-lucide="list-checks" class="lucide"></i> In the current filter</p>
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
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--red"><i data-lucide="building-2" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">Departments</p>
            <div class="mc-admin-kpi-value" id="kpiDepartments">&mdash;</div>
            <p class="mc-admin-kpi-sub"><i data-lucide="network" class="lucide"></i> With at least one type</p>
        </div>
    </div>

    <!-- ==================== TOOLBAR ==================== -->
    <div class="mc-admin-section-card mb-3">
        <div class="mc-admin-section-body">
            <div class="mc-admin-ct-toolbar">
                <div class="mc-admin-ct-search-wrap">
                    <label class="visually-hidden" for="typeSearch">Search concern types</label>
                    <i data-lucide="search" class="lucide mc-admin-ct-search-icon" aria-hidden="true"></i>
                    <input class="form-control mc-admin-ct-search-input" id="typeSearch" type="search"
                           placeholder="Search concern types&hellip; (Esc to clear)" autocomplete="off">
                </div>
                <div class="mc-admin-ct-dept-select">
                    <label class="visually-hidden" for="deptSelect">Filter by department</label>
                    <select class="form-select" id="deptSelect">
                        <option value="">All departments</option>
                    </select>
                </div>
                <div class="mc-admin-ct-chips" id="typeChips" role="group" aria-label="Filter concern types by status">
                    <button type="button" class="mc-admin-ct-chip active" data-status="all" aria-pressed="true">All</button>
                    <button type="button" class="mc-admin-ct-chip" data-status="active" aria-pressed="false">Active</button>
                    <button type="button" class="mc-admin-ct-chip" data-status="inactive" aria-pressed="false">Inactive</button>
                </div>
                <button class="btn btn-outline-secondary mc-admin-ct-refresh" type="button" id="refreshBtn">
                    <i data-lucide="refresh-cw" class="lucide lucide-16" aria-hidden="true"></i> Refresh
                </button>
            </div>
            <div class="mc-admin-ct-info">
                <i data-lucide="info" class="lucide" aria-hidden="true"></i>
                <span>Changes save automatically. Inactive concern types are hidden from the citizen submission form and can no longer be selected on existing concerns.</span>
            </div>
        </div>
    </div>

    <!-- ==================== TABLE ==================== -->
    <div class="mc-admin-table-card">
        <div class="mc-admin-table-head">
            <h3><i data-lucide="tags" class="lucide" aria-hidden="true"></i> All Concern Types</h3>
            <span class="text-muted small" id="typesCount"></span>
        </div>
        <div class="mc-admin-table-wrap">
            <table class="table table-hover align-middle mb-0" id="typesTable">
                <thead>
                    <tr>
                        <th><span class="mc-th-wrap"><i data-lucide="tag" class="lucide mc-th-icon" aria-hidden="true"></i>Concern Type</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="building-2" class="lucide mc-th-icon" aria-hidden="true"></i>Department</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="activity" class="lucide mc-th-icon" aria-hidden="true"></i>Status</span></th>
                        <th class="text-end"><span class="mc-th-wrap mc-th-wrap--end"><i data-lucide="toggle-right" class="lucide mc-th-icon" aria-hidden="true"></i>Active</span></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="px-3 pb-3" id="typesHint" role="status" aria-live="polite"></div>
    </div>

</div>

<?php
$deptsEndpoint = e(app_url('/admin/api/departments.php'));
$typesEndpoint = e(app_url('/admin/api/concern_types.php'));
$pageScripts = <<<'HTML'
<script>
$(function () {
  const deptsEndpoint = "__DEPTS_ENDPOINT__";
  const typesEndpoint = "__TYPES_ENDPOINT__";

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }
  function icons() { if (typeof window.__renderLucide === 'function') window.__renderLucide(); }

  /* Last successful payload for the current department filter. Search + status
     filter run against this cache so typing never re-hits the API. */
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
    const haystack = (String(r.name == null ? "" : r.name) + " " +
                      String(r.department == null ? "" : r.department)).toLowerCase();
    return haystack.indexOf(query) !== -1;
  }

  function setKpis(rows) {
    const total = rows.length;
    const active = rows.filter(function (r) { return Number(r.active) === 1; }).length;
    const deptIds = {};
    rows.forEach(function (r) { deptIds[r.department_id] = true; });
    $("#kpiTotal").text(total);
    $("#kpiActive").text(active);
    $("#kpiInactive").text(total - active);
    $("#kpiDepartments").text(Object.keys(deptIds).length);
  }

  function setCount(shown) {
    const total = allRows.length;
    $("#typesCount").text(
      shown === total
        ? total + (total === 1 ? " concern type" : " concern types")
        : shown + " of " + total + " concern types"
    );
  }

  function render() {
    const $tb = $("#typesTable tbody").empty();
    const rows = allRows.filter(matches);
    setCount(rows.length);

    if (rows.length === 0) {
      $tb.append(
        '<tr class="mc-empty-row"><td colspan="4">' +
          '<div class="mc-admin-empty">' +
            '<div class="mc-admin-empty-icon"><i data-lucide="search-x" class="lucide-24" aria-hidden="true"></i></div>' +
            '<h4>No matching concern types</h4>' +
            '<p>Clear the search box, pick a different department, or choose another status filter.</p>' +
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
              <td data-label="Concern Type">
                <span class="mc-cell-wrap">
                  <span class="mc-admin-ct-avatar"><i data-lucide="tag" class="lucide" aria-hidden="true"></i></span>
                  <span class="fw-semibold">${esc(r.name)}</span>
                </span>
              </td>
              <td data-label="Department">${esc(r.department)}</td>
              <td data-label="Status">${statusBadge(isActive)}</td>
              <td data-label="Active" class="mc-admin-ct-active-col">
                <span class="mc-admin-switch">
                  <input type="checkbox" class="type-active" role="switch"
                         data-id="${r.id}" aria-label="Enable ${esc(r.name)}"
                         ${isActive ? "checked" : ""}>
                </span>
              </td>
            </tr>
      `);
    });
    icons();
  }

  function setHint(html) { $("#typesHint").html(html); }

  function loadDepartments() {
    return $.getJSON(deptsEndpoint, { include_inactive: 1 }).done(function (res) {
      if (!res || !res.ok) return;
      const rows = res.departments || [];
      /* Rebuild from scratch so a Refresh cannot stack duplicate options. */
      const $sel = $("#deptSelect").empty().append('<option value="">All departments</option>');
      rows.forEach(function (d) {
        const inactive = Number(d.active) === 1 ? "" : " (Inactive)";
        $sel.append('<option value="' + d.id + '">' + esc(d.name) + inactive + "</option>");
      });
      icons();
    });
  }

  function loadTypes() {
    const department_id = $("#deptSelect").val() || "";
    setHint("<div class='text-muted small'>Loading concern types&hellip;</div>");
    icons();

    $.getJSON(typesEndpoint, { include_inactive: 1, department_id: department_id })
      .done(function (res) {
        if (!res || !res.ok) {
          setHint("<div class='text-danger small'>Failed to load concern types. Check your connection, then press Refresh.</div>");
          icons();
          return;
        }
        allRows = res.types || [];
        setKpis(allRows);
        render();
        setHint("<div class='text-muted small'>Changes save automatically.</div>");
        icons();
      })
      .fail(function () {
        setHint("<div class='text-danger small'>Failed to load concern types. Check your connection, then press Refresh.</div>");
        icons();
      });
  }

  /* Optimistic toggle. On failure the switch is put back where it was, because
     the old version left it in the new position while the database still held
     the old value. */
  $("#typesTable").on("change", ".type-active", function () {
    const $input = $(this);
    const id = $input.data("id");
    const next = $input.is(":checked") ? 1 : 0;
    const prev = next === 1 ? 0 : 1;

    $input.prop("disabled", true);
    $.post(typesEndpoint, { action: "set_active", id: id, active: next })
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
  $("#typeSearch").on("input", function () {
    const v = String($(this).val() || "").trim().toLowerCase();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { query = v; render(); }, 180);
  });
  $("#typeSearch").on("keydown", function (e) {
    if (e.key === "Escape") { e.preventDefault(); $(this).val("").trigger("input"); }
  });

  $("#typeChips").on("click", ".mc-admin-ct-chip", function () {
    const $btn = $(this);
    statusFilter = String($btn.data("status") || "all");
    $btn.addClass("active").attr("aria-pressed", "true");
    $btn.siblings().removeClass("active").attr("aria-pressed", "false");
    render();
  });

  $("#deptSelect").on("change", loadTypes);
  $("#refreshBtn").on("click", function () {
    loadDepartments().always(loadTypes);
  });

  loadDepartments().always(loadTypes);
});
</script>
HTML;
$pageScripts = str_replace(
    ["__DEPTS_ENDPOINT__", "__TYPES_ENDPOINT__"],
    [$deptsEndpoint, $typesEndpoint],
    $pageScripts
);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
