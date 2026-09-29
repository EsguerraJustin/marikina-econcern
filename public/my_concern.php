<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'My Concern';
$activeNav = 'my';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$tabs = ['All', 'New', 'Ongoing', 'Acknowledge', 'Completed', 'Cancelled'];
$tabIcons = [
    'All' => 'layers',
    'New' => 'mail-plus',
    'Ongoing' => 'loader-2',
    'Acknowledge' => 'eye',
    'Completed' => 'check-circle-2',
    'Cancelled' => 'ban'
];

?>
<div class="mc-mycon-page">
<!-- ================= PAGE HERO CARD (Minimalism: 1 CTA + Brand identity) ================= -->
<div class="mc-mycon-hero">
    <div class="mc-mycon-hero-inner">
        <div class="mc-mycon-hero-title-row">
            <div class="mc-mycon-hero-icon-wrap">
                <i data-lucide="clipboard-list" class="lucide lucide-24"></i>
            </div>
            <div class="mc-mycon-hero-title">
                <h1 class="mb-0">My Concern</h1>
                <p class="mb-0 text-muted" style="margin-top:2px;">View and search your submitted reports.</p>
            </div>
        </div>
        <div class="mc-mycon-hero-brand">
            <img src="<?= e(app_url('/assets/img/Marikina-e-Concern.png')) ?>"
                 alt="Marikina e-Concern — Citizen Portal"
                 width="36" height="36" loading="eager" decoding="async"
                 class="mc-mycon-brand-img">
        </div>
    </div>
</div>

<!-- ================= SEARCH + FILTER CARD (Soft Skeu: recessed inputs, raised chips) ================= -->
<div class="mc-mycon-filter-card mc-neo-card">
    <div class="card-body" style="padding: var(--ba-space-4);">
        <!-- Status filter chips (Minimalism: raised → pressed skeu, one accent per selection) -->
        <div class="mc-mycon-tabs" id="statusTabs">
            <?php foreach ($tabs as $t) : ?>
                <button type="button"
                        class="mc-mycon-chip nav-link <?= $t === 'All' ? 'active' : '' ?>"
                        data-status="<?= e($t) ?>">
                    <i data-lucide="<?= e($tabIcons[$t] ?? 'circle') ?>" class="lucide lucide-16"></i>
                    <span><?= e($t) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Search + Refresh row (Soft Skeu: recessed tray input, raised ghost refresh button) -->
        <div class="row mc-mycon-search-row g-2">
            <div class="col-md-8">
                <div class="mc-mycon-search-wrap">
                    <i data-lucide="search" class="lucide lucide-20 mc-mycon-search-icon"></i>
                    <input class="form-control mc-mycon-search-input"
                           id="searchBox"
                           type="search"
                           placeholder="Search report number, location, concern...">
                    <?php /* Note: per SDD §2.4.2 no explicit Clear button — auto-reset on empty */ ?>
                </div>
            </div>
            <div class="col-md-4">
                <button class="ba-btn ba-btn-ghost mc-mycon-refresh-btn w-100" id="refreshBtn">
                    <i data-lucide="refresh-cw" class="lucide lucide-16"></i>
                    <span>Refresh</span>
                </button>
            </div>
        </div>

        <!-- Live result counter (Minimalism: single info line, not extra decoration) -->
        <div class="mc-mycon-search-info" id="searchInfo">
            <i data-lucide="info" class="lucide lucide-16" style="color:var(--ba-text-muted);"></i>
            <span id="searchInfoText">Loading your concerns…</span>
        </div>
    </div>
</div>

<!-- ================= TABLE / LIST CARD (Minimalism: flat resting → hover lift skeu) ================= -->
<div class="mc-neo-card mc-mycon-table-card">
    <div class="mc-list-card-head">
        <h3><i data-lucide="clipboard-list" class="lucide"></i> Your Concerns</h3>
        <div class="mc-list-card-head-end">
            <span class="mc-list-card-count" id="myconListCount">0</span>
        </div>
    </div>
    <div class="table-responsive mc-mycon-table-wrap mc-stacked-card">
        <table class="table table-hover mb-0" id="concernsTable">
            <thead class="table-light">
                <tr>
                    <th>
                        <span class="mc-th-wrap">
                            <i data-lucide="hash" class="lucide mc-th-icon"></i>
                            Report #
                        </span>
                    </th>
                    <th>
                        <span class="mc-th-wrap">
                            <i data-lucide="calendar" class="lucide mc-th-icon"></i>
                            Date
                        </span>
                    </th>
                    <th>
                        <span class="mc-th-wrap">
                            <i data-lucide="file-text" class="lucide mc-th-icon"></i>
                            Concern
                        </span>
                    </th>
                    <th>
                        <span class="mc-th-wrap">
                            <i data-lucide="map-pin" class="lucide mc-th-icon"></i>
                            Location
                        </span>
                    </th>
                    <th>
                        <span class="mc-th-wrap">
                            <i data-lucide="activity" class="lucide mc-th-icon"></i>
                            Status
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody id="concernsTbody"></tbody>
        </table>
    </div>
    <?php /* No .p-* utility here: Bootstrap's padding utilities are !important and
             would beat the #tableHint footer rule in the sheet. */ ?>
    <div id="tableHint"></div>
</div>

<?php
$listEndpoint = e(app_url('/api/concerns.php'));
$viewUrl = e(app_url('/public/concern_view.php'));
$pageScripts = <<<'HTML'
<script>
$(function () {
  const listEndpoint = "__LIST_ENDPOINT__";
  let currentStatus = "All";

  /* -------- Lucide status icon map per §2.4.1 table SDD -------- */
  const statusMeta = {
    New:       { cls: "mc-status-badge mc-status-new",       icon: "mail-plus" },
    Ongoing:   { cls: "mc-status-badge mc-status-ongoing",   icon: "loader-2" },
    Acknowledge:{cls: "mc-status-badge mc-status-ack",       icon: "eye" },
    Completed: { cls: "mc-status-badge mc-status-completed", icon: "check-circle-2" },
    Cancelled: { cls: "mc-status-badge mc-status-cancelled", icon: "ban" }
  };
  const defaultStatus = { cls: "mc-status-badge mc-status-muted", icon: "circle-help" };

  function escHtml(v) {
    return $("<div>").text(String(v ?? "")).html();
  }

  function badge(status) {
    const m = statusMeta[status] || defaultStatus;
    return `<span class="${m.cls}">
        <i data-lucide="${m.icon}" class="lucide lucide-14" style="width:14px;height:14px;"></i>
        <span>${escHtml(status)}</span>
    </span>`;
  }

  function refreshLucide() {
    if (typeof window.__renderLucide === 'function') {
      window.__renderLucide();
    } else if (window.lucide) {
      window.lucide.createIcons({
        attrs: { 'stroke-width': 2 },
        nameAttr: 'data-lucide'
      });
    }
  }

  function load() {
    const q = $("#searchBox").val() || "";
    $("#tableHint").html("<div class='text-muted'>Loading…</div>");
    $("#searchInfoText").text("Loading your concerns…");

    $.getJSON(listEndpoint, { status: currentStatus, q })
      .done(function (res) {
        if (!res || !res.ok) {
          $("#tableHint").html("<div class='text-danger'>Failed to load concerns.</div>");
          $("#searchInfoText").text("Error loading. Tap Refresh to try again.");
          return;
        }
        const rows = res.concerns || [];
        const $tb = $("#concernsTbody").empty();
        $("#myconListCount").text(rows.length);

        if (rows.length === 0) {
          $("#tableHint").html("");
          $("#searchInfoText").text(q
            ? `No results found for “${escHtml(q)}”. Clear the search to see all.`
            : `No ${currentStatus === "All" ? "" : escHtml(currentStatus) + " "}concerns yet.`
          );
          $tb.append(`
            <tr>
              <td colspan="5">
                <div class="ba-empty-state" style="margin: var(--ba-space-4) 0 0;">
                  <i data-lucide="inbox" class="lucide lucide-48"></i>
                  <div class="ba-empty-state-title">No concerns found</div>
                  <p class="ba-empty-state-sub">Your submitted concerns will appear here. Tap the refresh button to check for updates.</p>
                </div>
              </td>
            </tr>
          `);
          refreshLucide();
          return;
        }

        $("#searchInfoText").text(`Showing ${rows.length} ${rows.length === 1 ? "concern" : "concerns"}${q ? ` · filtered by “${escHtml(q)}”` : ""}`);
        $("#tableHint").html("<div class='text-muted small'>Click a row to view full details.</div>");

        rows.forEach(r => {
          const loc = `${r.street ?? ""}${r.street && r.barangay ? ", " : ""}${r.barangay ?? ""}`;
          const date = r.created_at ? String(r.created_at).slice(0, 10) : "";
          $tb.append(`
            <tr role="button" data-id="${escHtml(r.id)}" tabindex="0">
              <td data-label="Report #">
                <div class="mc-cell-wrap">
                  <i data-lucide="hash" class="lucide mc-cell-icon"></i>
                  <div class="mc-cell-content mc-cell-content-now">${escHtml(r.report_number)}</div>
                </div>
              </td>
              <td data-label="Date">
                <div class="mc-cell-wrap">
                  <i data-lucide="calendar-days" class="lucide mc-cell-icon"></i>
                  <div class="mc-cell-content mc-mycon-cell-lead">${escHtml(date)}</div>
                </div>
              </td>
              <td data-label="Concern">
                <div class="mc-cell-wrap">
                  <i data-lucide="file-text" class="lucide mc-cell-icon"></i>
                  <div class="mc-cell-content">
                    <div class="mc-concern-title">${escHtml(r.concern_type)}</div>
                    <div class="mc-concern-dept">
                      <i data-lucide="building-2" class="lucide mc-mycon-dept-icon"></i>
                      <span>${escHtml(r.department)}</span>
                    </div>
                  </div>
                </div>
              </td>
              <td data-label="Location">
                <div class="mc-cell-wrap">
                  <i data-lucide="map-pin" class="lucide mc-cell-icon"></i>
                  <div class="mc-cell-content">
                    <div>${escHtml(loc)}</div>
                    ${r.landmark ? `<div class="text-muted small mc-mycon-landmark">${escHtml(r.landmark)}</div>` : ""}
                  </div>
                </div>
              </td>
              <td data-label="Status">
                <div class="mc-mycon-status">${badge(r.status)}</div>
              </td>
            </tr>
          `);
        });

        refreshLucide();
      })
      .fail(function () {
        $("#tableHint").html("<div class='text-danger'>Failed to load concerns.</div>");
        $("#searchInfoText").text("Network error. Tap Refresh to retry.");
      });
  }

  /* -------- Status chip click (skeu active handled by CSS .active) -------- */
  $("#statusTabs").on("click", "button[data-status]", function () {
    $("#statusTabs button").removeClass("active");
    $(this).addClass("active");
    currentStatus = $(this).data("status");
    load();
  });

  $("#refreshBtn").on("click", function () {
    const $icon = $(this).find('[data-lucide="refresh-cw"], .lucide-refresh-cw').first();
    $icon.css("animation", "none").offset();
    $icon.css("animation", "mc-spin .8s ease-in-out");
    load();
  });

  /* -------- Debounced search (80ms per SDD rule §EngConventions Search Engine) -------- */
  $("#searchBox").on("input", function () {
    clearTimeout(window.__concernSearchTimer);
    window.__concernSearchTimer = setTimeout(load, 80);
  });

  /* -------- ESC key to clear + blur (SDD EngConventions) -------- */
  $("#searchBox").on("keydown", function (e) {
    if (e.key === "Escape") {
      $(this).val("").blur();
      load();
    }
  });

  /* -------- Row click to view -------- */
  $("#concernsTable").on("click", "tr[data-id]", function () {
    const id = $(this).data("id");
    window.location.href = "__VIEW_URL__" + "?id=" + encodeURIComponent(id);
  });
  /* -------- Keyboard Enter on focused row -------- */
  $("#concernsTable").on("keydown", "tr[data-id]", function (e) {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      $(this).trigger("click");
    }
  });

  load();
});
</script>
<style>
@keyframes mc-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
HTML;
$pageScripts = str_replace(["__LIST_ENDPOINT__", "__VIEW_URL__"], [$listEndpoint, $viewUrl], $pageScripts);
?>
</div>
<?php
require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
