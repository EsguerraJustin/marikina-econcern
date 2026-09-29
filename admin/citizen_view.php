<?php

declare(strict_types=1);

$pageTitle = 'Citizen Details';
$activeNav = 'citizens';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

if (!$isSuper) {
    ?>
    <div class="mc-admin-cv-notice">
        <div class="fw-bold text-danger">Forbidden</div>
        <div class="text-muted">This page is available to Super Admin only.</div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
    require_once __DIR__ . '/../includes/partials/foot.php';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    ?>
    <div class="mc-admin-cv-notice">
        <div class="alert alert-danger mb-0">Invalid citizen.</div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
    require_once __DIR__ . '/../includes/partials/foot.php';
    exit;
}

$citizenEndpoint = e(app_url('/admin/api/citizen.php'));
$concernsEndpoint = e(app_url('/admin/api/citizen_concerns.php'));
$viewConcernUrl = e(app_url('/admin/concern_view.php'));

$pageScripts = <<<'HTML'
<script>
$(function () {
  const citizenId = __CITIZEN_ID__;
  const citizenEndpoint = "__CITIZEN_ENDPOINT__";
  const concernsEndpoint = "__CONCERNS_ENDPOINT__";
  const viewConcernUrl = "__VIEW_CONCERN_URL__";

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }

  /* Presentational only. Letters/digits are stripped so the tile can never
     carry markup out of a display name. Mirrors admin/citizens.php. */
  function initials(name) {
    const parts = String(name == null ? "" : name).trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "?";
    const first = parts[0].charAt(0);
    const second = parts.length > 1 ? parts[parts.length - 1].charAt(0) : "";
    return (first + second).replace(/[^a-z0-9]/gi, "").toUpperCase() || "?";
  }

  /* Presentational only: "2026-09-08" -> "Sep 8, 2026". */
  function fmtDate(v) {
    var s = String(v == null ? "" : v).slice(0, 10);
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (!m) return s;
    var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
  }

  /* Flat pills. Replaces the Bootstrap badge bg-* set, which rendered
     New / Acknowledge / Ongoing / Completed in three visual languages. */
  var PILL = {
    "New": "mc-admin-cv-badge--new",
    "Acknowledge": "mc-admin-cv-badge--acknowledge",
    "Ongoing": "mc-admin-cv-badge--ongoing",
    "Completed": "mc-admin-cv-badge--completed",
    "Cancelled": "mc-admin-cv-badge--cancelled"
  };
  function pill(status) {
    var key = String(status == null ? "" : status);
    return '<span class="mc-admin-cv-badge ' + (PILL[key] || "mc-admin-cv-badge--new") + '">'
         + '<i data-lucide="' + (key === "Completed" ? "circle-check" : "dot") + '" class="lucide" aria-hidden="true"></i>'
         + esc(key) + '</span>';
  }

  function metaRow(icon, label, value) {
    return '<div class="mc-admin-cv-meta-item">'
         +   '<span class="mc-admin-cv-meta-icon"><i data-lucide="' + icon + '" class="lucide" aria-hidden="true"></i></span>'
         +   '<span class="mc-admin-cv-meta-content">'
         +     '<span class="mc-admin-cv-meta-label">' + esc(label) + '</span>'
         +     '<span class="mc-admin-cv-meta-value" title="' + esc(value) + '">' + esc(value) + '</span>'
         +   '</span>'
         + '</div>';
  }

  function fail(target, msg) {
    $(target).addClass("is-error").html("<div class='small'>" + esc(msg) + "</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  }

  /* Fields available from admin/api/citizen.php: id, first_name, last_name,
     mobile, email, barangay, active, created_at, avatar_public_id,
     email_verified_at. Nothing else is rendered here. */
  let currentCitizen = null;

  /* Builds the Cloudinary display URL for a stored public_id. The id is
     "marikina_concern/uploads/citizen_<id>" and is never user-supplied, but it
     is still validated against that shape and escaped, so a row that somehow
     held an arbitrary string degrades to the initials tile instead of injecting
     markup. */
  function avatarUrl(publicId) {
    const id = String(publicId == null ? "" : publicId).trim();
    if (!/^marikina_concern\/uploads\/[A-Za-z0-9_\/\-]+$/.test(id)) return "";
    return "https://res.cloudinary.com/" + "__CLOUD_NAME__" +
      "/image/upload/c_thumb,g_face,w_160,h_160,q_auto,f_auto/" + id;
  }

  function loadCitizen() {
    $("#citizenCard").html("<div class='mc-admin-cv-loading'>Loading profile&hellip;</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    $.getJSON(citizenEndpoint, { id: citizenId })
      .done(function (res) {
        if (!res || !res.ok) {
          $("#citizenCard").html("<div class='mc-admin-cv-loading text-danger'>Failed to load citizen.</div>");
          return;
        }
        const c = res.citizen || {};
        currentCitizen = c;
        const name = ((c.first_name || "") + " " + (c.last_name || "")).trim();
        const isActive = Number(c.active) === 1;
        const mail = c.email || "";
        const mob = c.mobile || "";
        const reg = fmtDate(c.created_at);
        const photo = avatarUrl(c.avatar_public_id);

        const avatar = photo
          ? '<span class="mc-avatar mc-avatar--xl">'
            + '<img src="' + esc(photo) + '" alt="" width="128" height="128" loading="lazy" decoding="async">'
            + '</span>'
          : '<span class="mc-avatar mc-avatar--xl mc-avatar--fallback" aria-hidden="true">'
            + esc(initials(name)) + '</span>';

        const verified = c.email_verified_at
          ? '<span class="mc-admin-cv-badge mc-admin-cv-badge--completed">'
            + '<i data-lucide="badge-check" class="lucide" aria-hidden="true"></i> Email verified</span>'
          : '<span class="mc-admin-cv-badge mc-admin-cv-badge--cancelled">'
            + '<i data-lucide="badge-alert" class="lucide" aria-hidden="true"></i> Email not verified</span>';

        $("#citizenCard").html(
          '<div class="mc-admin-cv-avatar-wrap">'
          +  avatar
          + '</div>'
          + '<h2 class="mc-admin-cv-fullname">' + esc(name) + '</h2>'
          + '<p class="mc-admin-cv-citizen-id">Citizen #' + esc(c.id) + '</p>'
          + '<div class="mc-admin-cv-status-row">'
          +  '<span class="mc-admin-cv-status-badge ' + (isActive ? "active" : "inactive") + '">'
          +    '<i data-lucide="' + (isActive ? "circle-check" : "circle-slash") + '" class="lucide" aria-hidden="true"></i>'
          +    (isActive ? "Active" : "Inactive")
          +  '</span>'
          +  verified
          + '</div>'
          + '<button class="btn btn-outline-primary btn-sm mc-admin-cv-edit-btn" type="button" id="editCitizenBtn">'
          +  '<i data-lucide="pencil" class="lucide lucide-16" aria-hidden="true"></i> Edit information</button>'
          + '<hr class="mc-admin-cv-profile-divider">'
          + '<div class="mc-admin-cv-profile-meta">'
          +  metaRow("mail", "Email", mail)
          +  metaRow("smartphone", "Mobile", mob)
          +  (c.barangay ? metaRow("map-pin", "Barangay", c.barangay) : "")
          +  metaRow("calendar-days", "Registered", reg)
          + '</div>'
        );
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      })
      .fail(function () {
        $("#citizenCard").html("<div class='mc-admin-cv-loading text-danger'>Failed to load citizen.</div>");
      });
  }

  /* ---------- Edit identity ----------
     Delegated on the card because the card's HTML is replaced on every load. */
  $("#citizenCard").on("click", "#editCitizenBtn", function () {
    if (!currentCitizen) return;
    window.__openCitizenEdit({
      endpoint: citizenEndpoint,
      modalId: "citizenEditModal",
      id: citizenId,
      citizen: currentCitizen
    });
  });

  /* Refresh the sidebar card in place so the new name/email/verification state
     is visible without a full page reload. */
  window.__onCitizenEdited = function () {
    loadCitizen();
  };

  /* Counts are tallied from the rows already returned by the request below.
     No extra call, and the API is untouched. */
  function renderStats(rows) {
    var by = { "New": 0, "Ongoing": 0, "Acknowledge": 0, "Completed": 0, "Cancelled": 0 };
    rows.forEach(function (r) {
      var s = String(r.status == null ? "" : r.status);
      if (Object.prototype.hasOwnProperty.call(by, s)) by[s] += 1;
    });
    var tiles = [
      { label: "Total",     value: rows.length, tone: "" },
      { label: "New",       value: by["New"],   tone: "warning" },
      { label: "Completed", value: by["Completed"], tone: "success" }
    ];
    $("#citizenStats").html(tiles.map(function (t) {
      return '<div class="mc-admin-cv-stat-box">'
           +  '<div class="mc-admin-cv-stat-num ' + t.tone + '">' + t.value + '</div>'
           +  '<div class="mc-admin-cv-stat-label">' + esc(t.label) + '</div>'
           + '</div>';
    }).join(""));
  }

  function emptyRow(title, body) {
    return '<tr class="mc-empty-row"><td colspan="5">'
         +  '<div class="mc-admin-cv-empty">'
         +    '<div class="mc-admin-cv-empty-icon"><i data-lucide="inbox" class="lucide" aria-hidden="true"></i></div>'
         +    '<h4>' + esc(title) + '</h4><p>' + esc(body) + '</p>'
         +  '</div>'
         + '</td></tr>';
  }

  function loadConcerns() {
    const status = $("#statusFilter").val() || "";
    $("#concernsHint").removeClass("is-error").html("<div class='small'>Loading concerns&hellip;</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    $.getJSON(concernsEndpoint, { user_id: citizenId, status })
      .done(function (res) {
        if (!res || !res.ok) { fail("#concernsHint", "Failed to load concerns."); return; }
        const rows = res.concerns || [];
        const $tb = $("#concernsTable tbody").empty();
        renderStats(rows);
        $("#concernsCount").text(rows.length + (rows.length === 1 ? " record" : " records"));
        if (rows.length === 0) {
          $tb.append(emptyRow(
            status ? "No " + status.toLowerCase() + " concerns" : "No concerns submitted",
            status ? "This citizen has no concerns with that status."
                   : "This citizen has not submitted any concerns yet."
          ));
          $("#concernsHint").html("");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          return;
        }
        $("#concernsHint").html("<div class='small'>Click any row to open the concern.</div>");
        rows.forEach(function (r) {
          const rn = r.report_number || "";
          const dept = r.department || "";
          $tb.append(
            '<tr role="button" tabindex="0" data-id="' + esc(r.id) + '">'
            + '<td data-label="Report #"><span class="mc-admin-cv-ref">' + esc(rn) + '</span></td>'
            + '<td data-label="Concern">'
            +   '<span class="fw-semibold">' + esc(r.concern_type) + '</span>'
            +   '<span class="mc-admin-cv-cell-sub" title="' + esc(dept) + '">' + esc(dept) + '</span>'
            + '</td>'
            + '<td data-label="Barangay">' + esc(r.barangay) + '</td>'
            + '<td data-label="Status">' + pill(r.status) + '</td>'
            + '<td data-label="Date">' + esc(fmtDate(r.created_at)) + '</td>'
            + '</tr>'
          );
        });
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      })
      .fail(function () { fail("#concernsHint", "Failed to load concerns."); });
  }

  $("#statusFilter").on("change", loadConcerns);
  $("#concernsTable").on("click", "tr[data-id]", function () {
    window.location.href = viewConcernUrl + "?id=" + encodeURIComponent($(this).data("id"));
  });
  $("#concernsTable").on("keydown", "tr[data-id]", function (e) {
    if (e.key !== "Enter" && e.key !== " " && e.key !== "Spacebar") return;
    e.preventDefault();
    window.location.href = viewConcernUrl + "?id=" + encodeURIComponent($(this).data("id"));
  });

  loadCitizen();
  loadConcerns();
});
</script>
HTML;

$pageScripts = str_replace(
    ['__CITIZEN_ID__', '__CITIZEN_ENDPOINT__', '__CONCERNS_ENDPOINT__', '__VIEW_CONCERN_URL__', '__CLOUD_NAME__'],
    [
        (string) ((int) $id),
        $citizenEndpoint,
        $concernsEndpoint,
        $viewConcernUrl,
        e((string) (defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : '')),
    ],
    $pageScripts
);
$citizenEditModal = 'citizenEditModal';
require __DIR__ . '/../includes/partials/admin_citizen_edit_modal.php';
$pageScripts .= "\n" . '<script src="' . e(app_url('/assets/js/admin-citizen-edit.js')) . '" defer></script>';

?>
<div class="mc-admin-citizen_view-page">

<div class="mc-admin-cv-header">
    <a class="mc-admin-cv-back-link" href="<?= e(app_url('/admin/citizens.php')) ?>">
        <i data-lucide="arrow-left" class="lucide" aria-hidden="true"></i>
        <span>Back to Citizens</span>
    </a>
</div>

<div class="mc-admin-cv-grid">

    <aside class="mc-admin-cv-sidebar">
        <div class="mc-admin-cv-profile-card" id="citizenCard">
            <div class="mc-admin-cv-loading">Loading profile&hellip;</div>
        </div>

        <div class="mc-admin-cv-stats-card">
            <p class="mc-admin-cv-stats-title">Concern Summary</p>
            <div class="mc-admin-cv-stats-grid" id="citizenStats"></div>
        </div>
    </aside>

    <div class="mc-admin-cv-main">
        <div class="mc-admin-cv-section-card">
            <div class="mc-admin-cv-section-title">
                <h2><i data-lucide="clipboard-list" class="lucide" aria-hidden="true"></i> Submitted Concerns</h2>
                <div class="mc-admin-cv-section-tools">
                    <span class="text-muted small" id="citizensCount"></span>
                    <select class="form-select mc-admin-cv-filter" id="statusFilter" aria-label="Filter by status">
                        <option value="">All Status</option>
                        <option value="New">New</option>
                        <option value="Ongoing">Ongoing</option>
                        <option value="Acknowledge">Acknowledge</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="mc-admin-cv-table-wrap">
                <table class="table table-hover align-middle mb-0 mc-admin-cv-table" id="concernsTable">
                    <thead>
                        <tr>
                            <th><span class="mc-admin-cv-th-wrap"><i data-lucide="hash" class="lucide mc-admin-cv-th-icon" aria-hidden="true"></i>Report #</span></th>
                            <th><span class="mc-admin-cv-th-wrap"><i data-lucide="message-square" class="lucide mc-admin-cv-th-icon" aria-hidden="true"></i>Concern</span></th>
                            <th><span class="mc-admin-cv-th-wrap"><i data-lucide="map-pin" class="lucide mc-admin-cv-th-icon" aria-hidden="true"></i>Barangay</span></th>
                            <th><span class="mc-admin-cv-th-wrap"><i data-lucide="activity" class="lucide mc-admin-cv-th-icon" aria-hidden="true"></i>Status</span></th>
                            <th><span class="mc-admin-cv-th-wrap"><i data-lucide="calendar-days" class="lucide mc-admin-cv-th-icon" aria-hidden="true"></i>Date</span></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="mc-admin-cv-hint" id="concernsHint" role="status" aria-live="polite"></div>
        </div>
    </div>

</div>
</div>

<?php
require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
