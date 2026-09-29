<?php

declare(strict_types=1);

$pageTitle = 'Manage Concerns';
$activeNav = 'concerns';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

?>
<div class="mc-admin-concerns-hero">
    <div class="mc-admin-concerns-hero-inner">
        <div class="mc-admin-concerns-title-row">
            <div class="mc-admin-concerns-icon-wrap">
                <i data-lucide="inbox" class="lucide"></i>
            </div>
            <div class="mc-admin-concerns-title">
                <h1>Manage Concerns</h1>
                <p>Search and update citizen-submitted concerns.</p>
            </div>
        </div>
        <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
             alt="Official Seal of the City of Marikina"
             class="mc-admin-concerns-hero-seal" loading="eager" decoding="async">
    </div>
</div>

<div class="mc-neo-card mb-3">
    <div class="card-body" style="padding: var(--ba-space-4);">
        <div class="mc-admin-concerns-tabs" id="statusTabs" role="tablist" aria-label="Filter by status">
            <?php
            $statusIcons = ['All' => 'layers', 'New' => 'mail-plus', 'Ongoing' => 'loader', 'Acknowledge' => 'eye', 'Completed' => 'check-circle-2', 'Cancelled' => 'ban'];
            foreach (['All', 'New', 'Ongoing', 'Acknowledge', 'Completed', 'Cancelled'] as $t) :
            ?>
                <button type="button" class="mc-admin-concerns-chip <?= $t === 'All' ? 'active' : '' ?>" data-status="<?= e($t) ?>"><i data-lucide="<?= e($statusIcons[$t]) ?>" class="lucide"></i><?= e($t) ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Row 1: Select filters + Filter (matches ba_schedule reference) -->
        <div class="row mb-4 g-3 align-items-end">
            <div class="col-md-3">
                <label for="deptFilter" class="form-label">Department</label>
                <select class="form-select" id="deptFilter" style="height:48px;">
                    <option value="">All departments</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="fromDate" class="form-label">From</label>
                <input class="form-control" id="fromDate" type="date" style="height:48px;" aria-label="From date">
            </div>
            <div class="col-md-3">
                <label for="toDate" class="form-label">To</label>
                <input class="form-control" id="toDate" type="date" style="height:48px;" aria-label="To date">
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button type="button" class="btn btn-primary flex-fill" id="filterBtn" style="height:48px;">
                    <i data-lucide="filter" class="lucide lucide-16"></i><span>Filter</span>
                </button>
            </div>
        </div>

        <!-- Row 2: Search + Refresh (matches ba_schedule reference) -->
        <div class="row g-3">
            <div class="col-md-8">
                <div class="mc-admin-concerns-search-wrap">
                    <i data-lucide="search" class="lucide mc-admin-concerns-search-icon"></i>
                    <input class="form-control mc-admin-concerns-search-input" id="searchBox" type="search" placeholder="Search report #, citizen, concern... (press Esc to clear)" autocomplete="off" spellcheck="false" style="height:48px;">
                </div>
            </div>
            <div class="col-md-4">
                <button type="button" class="btn btn-outline-primary w-100" id="refreshBtn" style="height:48px;">
                    <i data-lucide="refresh-cw" class="lucide lucide-16"></i>
                    <span>Refresh</span>
                </button>
            </div>
        </div>

        <!-- Row 3: Info line (matches ba_schedule reference) -->
        <div class="mc-admin-concerns-info mt-4">
            <i data-lucide="info" class="lucide"></i>
            <span>Filter by status, department, or date — click a row to view details.</span>
        </div>
    </div>
</div>

<div class="mc-admin-section-card">
    <div class="mc-admin-section-head">
        <h3><i data-lucide="clipboard-list" class="lucide"></i> All Concerns</h3>
        <span class="text-muted small" id="concernsCount"></span>
    </div>
    <div class="mc-admin-table-wrap">
        <table class="table table-hover align-middle mb-0" id="concernsTable">
            <thead class="table-light">
                <tr>
                    <th><span class="mc-th-wrap"><i data-lucide="hash" class="lucide mc-th-icon"></i>Report #</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="user" class="lucide mc-th-icon"></i>Citizen</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="message-square" class="lucide mc-th-icon"></i>Concern</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="map-pin" class="lucide mc-th-icon"></i>Barangay</span></th>
                    <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="flag" class="lucide mc-th-icon"></i>Status</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="calendar-days" class="lucide mc-th-icon"></i>Date Submitted</span></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="p-3" id="tableHint"></div>
</div>

<?php
$listEndpoint = e(app_url('/admin/api/concerns.php'));
$deptsEndpoint = e(app_url('/admin/api/departments.php'));
$viewUrl = e(app_url('/admin/concern_view.php'));
$pageScripts = <<<'HTML'
<script>
$(function () {
  const listEndpoint = "__LIST_ENDPOINT__";
  const deptsEndpoint = "__DEPTS_ENDPOINT__";
  let currentStatus = "All";

  // Pre-fill filters from URL params so dashboard chart bars can deep-link,
  // e.g. admin/concerns.php?from=2026-09-19&to=2026-09-20 (&status=New optional).
  const urlParams = new URLSearchParams(window.location.search);
  const uFrom = urlParams.get("from");
  const uTo = urlParams.get("to");
  const uStatus = urlParams.get("status");
  if (uFrom) $("#fromDate").val(uFrom);
  if (uTo) $("#toDate").val(uTo);
  if (uStatus && ["New","Ongoing","Acknowledge","Completed","Cancelled"].indexOf(uStatus) !== -1) {
    currentStatus = uStatus;
    $("#statusTabs button[data-status]").removeClass("active");
    $("#statusTabs button[data-status='" + uStatus + "']").addClass("active");
  }

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

  function loadDepartments() {
    return $.getJSON(deptsEndpoint).done(function(res){
      if (!res || !res.ok) return;
      const rows = res.departments || [];
      const $sel = $("#deptFilter");
      rows.forEach(d => {
        $sel.append(`<option value="${d.id}">${esc(d.name)}</option>`);
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      });
      if (res.locked_department_id) {
        $sel.val(String(res.locked_department_id)).prop("disabled", true);
      }
    });
  }

  function load() {
    const q = $("#searchBox").val() || "";
    const department_id = $("#deptFilter").val() || "";
    const from = $("#fromDate").val() || "";
    const to = $("#toDate").val() || "";
    $("#tableHint").html("<div class='text-muted'>Loading...</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();

    $.getJSON(listEndpoint, { status: currentStatus, q, department_id, from, to })
      .done(function (res) {
        if (!res || !res.ok) {
          $("#tableHint").html("<div class='text-danger'>Failed to load concerns.</div>");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          return;
        }
        const rows = res.concerns || [];
        const $tb = $("#concernsTable tbody").empty();
        $("#concernsCount").text(rows.length + (rows.length === 1 ? " record" : " records"));
        if (rows.length === 0) {
          $("#tableHint").html("<div class='text-muted'>No concerns found.</div>");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          return;
        }
        $("#tableHint").html("<div class='text-muted small'>Click a row to view details.</div>");
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
              <td data-label="Date Submitted"><span class="mc-cell-wrap"><i data-lucide="calendar-days" class="lucide mc-cell-icon"></i><span>${esc(date)}</span></span></td>
            </tr>
          `);
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
        });
      })
      .fail(function () {
        $("#tableHint").html("<div class='text-danger'>Failed to load concerns.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      });
  }

  $("#statusTabs").on("click", "button[data-status]", function () {
    $("#statusTabs button").removeClass("active");
    $(this).addClass("active");
    currentStatus = $(this).data("status");
    load();
  });

  $("#filterBtn").on("click", load);
  $("#refreshBtn").on("click", function () {
    $("#searchBox").val("");
    $("#deptFilter").val("");
    $("#fromDate").val("");
    $("#toDate").val("");
    $("#statusTabs button").removeClass("active");
    $("#statusTabs button[data-status='All']").addClass("active");
    currentStatus = "All";
    load();
  });
  $("#searchBox").on("input", function () {
    clearTimeout(window.__adminSearchTimer);
    window.__adminSearchTimer = setTimeout(load, 80);
  });
  $("#searchBox").on("keydown", function(e){ if(e.key==='Escape'){ $(this).val('').blur(); load(); } });

  $("#concernsTable").on("click", "tr[data-id]", function () {
    const id = $(this).data("id");
    window.location.href = "__VIEW_URL__" + "?id=" + encodeURIComponent(id);
  });

  loadDepartments().always(load);
});
</script>
HTML;
$pageScripts = str_replace(
    ["__LIST_ENDPOINT__", "__DEPTS_ENDPOINT__", "__VIEW_URL__"],
    [$listEndpoint, $deptsEndpoint, $viewUrl],
    $pageScripts
);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';

