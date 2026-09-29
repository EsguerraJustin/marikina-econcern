<?php

declare(strict_types=1);

$pageTitle = 'Citizens';
$activeNav = 'citizens';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

if (!$isSuper) {
    ?>
    <div class="mc-admin-section-card">
        <div class="mc-admin-section-body">
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
<div class="mc-admin-citizens-page">

<div class="mc-admin-concerns-hero">
    <div class="mc-admin-concerns-hero-inner">
        <div class="mc-admin-concerns-title-row">
            <div class="mc-admin-concerns-icon-wrap">
                <i data-lucide="users" class="lucide"></i>
            </div>
            <div class="mc-admin-concerns-title">
                <h1>Citizen Accounts</h1>
                <p>Search, view submitted concerns, and enable/disable accounts.</p>
            </div>
        </div>
        <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
             alt="Official Seal of the City of Marikina"
             class="mc-admin-concerns-hero-seal" loading="eager" decoding="async">
    </div>
</div>

<div class="mc-admin-kpi-grid" id="citizensKpis"></div>

<div class="mc-admin-section-card mb-3">
    <div class="mc-admin-section-body">
        <div class="mc-admin-cit-toolbar">
            <div class="mc-admin-cit-field">
                <label class="form-label" for="q">Search</label>
                <div class="mc-admin-cit-search-wrap">
                    <i data-lucide="search" class="lucide mc-admin-cit-search-icon" aria-hidden="true"></i>
                    <input class="form-control mc-admin-cit-search-input" id="q" type="search"
                           placeholder="Name, email, or mobile (press Esc to clear)"
                           autocomplete="off" spellcheck="false">
                </div>
            </div>
            <div class="mc-admin-cit-field">
                <label class="form-label" for="includeInactive">Include Inactive</label>
                <select class="form-select" id="includeInactive">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                </select>
            </div>
            <div class="mc-admin-cit-field">
                <label class="form-label" for="showFilter">Show</label>
                <select class="form-select" id="showFilter">
                    <option value="active">Not archived</option>
                    <option value="archived">Archived only</option>
                    <option value="all">All</option>
                </select>
            </div>
            <div class="mc-admin-cit-field">
                <button class="btn btn-primary mc-admin-cit-submit w-100" type="button" id="searchBtn">
                    <i data-lucide="search" class="lucide lucide-16" aria-hidden="true"></i><span>Search</span>
                </button>
            </div>
        </div>
        <div class="mc-admin-concerns-info">
            <i data-lucide="info" class="lucide" aria-hidden="true"></i>
            <span>Search by name, email, or mobile — use the switch to enable or disable an account.
                  Delete archives an account and keeps its reports on record; Restore brings it back
                  still disabled.</span>
        </div>
    </div>
</div>

<div class="mc-admin-section-card">
    <div class="mc-admin-section-head">
        <h3><i data-lucide="users" class="lucide" aria-hidden="true"></i> <span id="citizensHeading">All Citizens</span></h3>
        <span class="text-muted small" id="citizensCount"></span>
    </div>
    <div class="mc-admin-table-wrap">
        <table class="table table-hover align-middle mb-0" id="citizensTable">
            <thead>
                <tr>
                    <th><span class="mc-th-wrap"><i data-lucide="user" class="lucide mc-th-icon" aria-hidden="true"></i>Name</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="mail" class="lucide mc-th-icon" aria-hidden="true"></i>Email</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="smartphone" class="lucide mc-th-icon" aria-hidden="true"></i>Mobile</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="calendar-days" class="lucide mc-th-icon" aria-hidden="true"></i>Registered</span></th>
                    <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="circle-dot" class="lucide mc-th-icon" aria-hidden="true"></i>Status</span></th>
                    <th class="text-end"><span class="mc-th-wrap mc-th-wrap--end"><i data-lucide="settings" class="lucide mc-th-icon" aria-hidden="true"></i>Actions</span></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="mc-admin-cit-status" id="citizensHint" role="status" aria-live="polite"></div>
</div>

</div>

<!-- Archive confirmation. There is no hard delete: archiving keeps the account
     row and every report it owns, and only blocks sign-in. -->
<div class="modal fade" id="archiveModal" tabindex="-1" aria-hidden="true" aria-labelledby="archiveModalLabel">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="archiveModalLabel">Delete citizen account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="archiveTarget">&mdash;</p>
                <p class="text-muted small mb-0">
                    The account will be archived and will no longer be able to sign in. Their
                    submitted reports, messages and internal notes are <strong>kept on record</strong>
                    and are not deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" type="button" id="archiveConfirmBtn">
                    <i data-lucide="archive" class="lucide lucide-16" aria-hidden="true"></i> Delete account
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$citizensEndpoint = e(app_url('/admin/api/citizens.php'));
$citizenViewUrl = e(app_url('/admin/citizen_view.php'));
$pageScripts = <<<'HTML'
<script>
$(function () {
  const endpoint = "__CITIZENS_ENDPOINT__";
  const viewUrl = "__CITIZEN_VIEW_URL__";

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }

  /* Presentational only: two-letter initials for the row avatar. Letters/digits
     are stripped so the tile can never carry markup from a display name. */
  function initials(name) {
    const parts = String(name == null ? "" : name).trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "?";
    const first = parts[0].charAt(0);
    const second = parts.length > 1 ? parts[parts.length - 1].charAt(0) : "";
    return (first + second).replace(/[^a-z0-9]/gi, "").toUpperCase() || "?";
  }

  /* Builds the Cloudinary display URL for a stored public_id. The id is
     "marikina_concern/uploads/citizen_<id>" and is never user-supplied, but it
     is still escaped and the cloud host is matched before use, so a row that
     somehow held an arbitrary string degrades to the initials tile instead of
     injecting markup. */
  function avatarUrl(publicId) {
    const id = String(publicId == null ? "" : publicId).trim();
    if (!/^marikina_concern\/uploads\/[A-Za-z0-9_\/\-]+$/.test(id)) return "";
    return "https://res.cloudinary.com/" + "__CLOUD_NAME__" +
      "/image/upload/c_thumb,g_face,w_80,h_80,q_auto,f_auto/" + id;
  }

  /* Presentational only: "2026-08-31" -> "Aug 31, 2026". The API already sends
     created_at; this only changes how it is displayed, never which records. */
  function fmtDate(v) {
    var s = String(v == null ? "" : v).slice(0, 10);
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (!m) return s;
    var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
  }

  function emptyRow(title, body) {
    return '<tr class="mc-empty-row"><td colspan="6">' +
             '<div class="mc-admin-empty">' +
               '<div class="mc-admin-empty-icon"><i data-lucide="user-x" class="lucide" aria-hidden="true"></i></div>' +
               '<h4>' + title + '</h4><p>' + body + '</p>' +
             '</div>' +
           '</td></tr>';
  }

  /* Presentational only: counts already in the payload. */
  function renderKpis(rows, visible) {
    const total = rows.length;
    const active = rows.filter(function (r) { return Number(r.active) === 1; }).length;
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - 30);
    const cutoffKey = cutoff.getFullYear() + "-"
                    + String(cutoff.getMonth() + 1).padStart(2, "0") + "-"
                    + String(cutoff.getDate()).padStart(2, "0");
    const recent = rows.filter(function (r) {
      return String(r.created_at || "").slice(0, 10) >= cutoffKey;
    }).length;

    const tiles = [
      { label: "Total Citizens", value: total, icon: "users",         chip: "navy"  },
      { label: "Active",         value: active, icon: "circle-check", chip: "mint"  },
      { label: "Inactive",       value: total - active, icon: "circle-minus", chip: "amber" },
      { label: "New (30 days)",  value: recent, icon: "user-plus",    chip: "sky"   }
    ];

    $("#citizensKpis").html(tiles.map(function (t) {
      return '<div class="mc-admin-kpi-card' + (t.value === 0 ? ' is-zero' : '') + '">' +
               '<div class="mc-admin-kpi-head">' +
                 '<div class="mc-admin-kpi-icon mc-admin-kpi-icon--' + t.chip + '">' +
                   '<i data-lucide="' + t.icon + '" class="lucide" aria-hidden="true"></i>' +
                 '</div>' +
               '</div>' +
               '<p class="mc-admin-kpi-label">' + esc(t.label) + '</p>' +
               '<div class="mc-admin-kpi-value">' + t.value + '</div>' +
             '</div>';
    }).join(""));
    $("#citizensKpis").attr("data-visible", visible);
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  }

  /* All citizens matching the current filter, kept for the KPI counts. */
  let visibleRows = [];

  /* Set by Delete/Restore, shown once the reload that follows has rendered.
     Doing the row removal locally instead would be wrong under "Show: All",
     where an archived row legitimately stays in the list. */
  let flashHint = null;

  function load() {
    $("#citizensHint").removeClass("is-error")
      .html("<div class='text-muted small'>Loading citizens&hellip;</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    const q = ($("#q").val() || "").trim();
    const include_inactive = $("#includeInactive").val() || "0";
    const show = $("#showFilter").val() || "active";
    $("#citizensHeading").text(show === "archived" ? "Archived Citizens" : "All Citizens");
    $.getJSON(endpoint, { q, include_inactive, show, limit: 200 })
      .done(function (res) {
        if (!res || !res.ok) {
          $("#citizensHint").addClass("is-error")
            .html("<div class='small'>Failed to load citizens. Check your connection, then press Search.</div>");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          flashHint = null;
          return;
        }
        const rows = res.citizens || [];
        const $tb = $("#citizensTable tbody").empty();
        $("#citizensCount").text(rows.length + (rows.length === 1 ? " record" : " records"));
        visibleRows = rows;
        renderKpis(rows, rows.length);
        if (rows.length === 0) {
          if (show === "archived" && !q) {
            $tb.append(emptyRow("No archived accounts",
              "Nothing has been archived. Deleting a citizen moves them here, and their reports stay on record."));
          } else {
            $tb.append(emptyRow("No citizens found",
              q ? "No account matches that search. Try a different name, email, or mobile number."
                : "No citizen accounts have been registered yet."));
          }
          if (flashHint) {
            $("#citizensHint").removeClass("is-error").html(flashHint);
            flashHint = null;
          } else {
            $("#citizensHint").html("");
          }
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          return;
        }
        if (flashHint) {
          $("#citizensHint").removeClass("is-error").html(flashHint);
          flashHint = null;
        } else {
          $("#citizensHint").html("<div class='small'>Click any row to open the citizen's full profile.</div>");
        }
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        rows.forEach(r => {
          const name = ((r.first_name || "") + " " + (r.last_name || "")).trim();
          const date = fmtDate(r.created_at);
          const checked = Number(r.active) === 1 ? "checked" : "";
          const mail = r.email || "";
          const mob = r.mobile || "";
          /* Kept so the Edit modal can prefill without a second request. Only
             the fields the modal owns are copied, so an archived row (which has
             no Edit button) cannot be edited out of a stale cached object. */
          citizenRows[r.id] = {
            first_name: r.first_name,
            last_name: r.last_name,
            email: r.email,
            mobile: r.mobile,
            barangay: r.barangay,
            email_verified_at: r.email_verified_at
          };
          /* An archived row has no toggle: the switch writes users.active, which
             is not what decides access once deleted_at is set. */
          const isArchived = !!r.deleted_at;
          const statusCell = isArchived
            ? '<span class="mc-admin-cit-archived">Archived</span>'
            : '<span class="mc-admin-cit-active">'
              + '<span class="mc-admin-switch">'
              +   '<input type="checkbox" id="citizen-active-${r.id}" class="citizen-active" ' + checked
              +   ' aria-label="Enable account for ' + esc(name) + '">'
              +   '<label class="mc-admin-cit-state" for="citizen-active-${r.id}" aria-hidden="true"></label>'
              + '</span></span>';
          const actionCell = isArchived
            ? '<button class="mc-admin-cit-action-btn restore-citizen" type="button"'
              + ' data-id="' + esc(r.id) + '" data-name="' + esc(name) + '"'
              + ' title="Restore the account for ' + esc(name) + '">'
              + '<i data-lucide="archive-restore" class="lucide" aria-hidden="true"></i> Restore</button>'
            : '<button class="mc-admin-cit-action-btn edit-citizen" type="button"'
              + ' data-id="' + esc(r.id) + '" data-name="' + esc(name) + '"'
              + ' title="Edit the information of ' + esc(name) + '">'
              + '<i data-lucide="pencil" class="lucide" aria-hidden="true"></i> Edit</button>'
              + '<button class="mc-admin-cit-action-btn mc-admin-cit-action-btn--danger archive-citizen"'
              + ' type="button" data-id="' + esc(r.id) + '" data-name="' + esc(name) + '"'
              + ' title="Archive the account for ' + esc(name) + '">'
              + '<i data-lucide="archive" class="lucide" aria-hidden="true"></i> Delete</button>';
          /* Real photo when the citizen has uploaded one, initials otherwise.
             mc-avatar lives in assets/css/common.css, shared with the profile
             pages, so this is the same tile the citizen sees on their own
             profile rather than a second look. */
          const avatarCell = r.avatar_public_id
            ? '<span class="mc-avatar mc-avatar--sm">'
              + '<img src="' + esc(avatarUrl(r.avatar_public_id)) + '" alt="" width="40" height="40" loading="lazy" decoding="async">'
              + '</span>'
            : '<span class="mc-avatar mc-avatar--sm mc-avatar--fallback" aria-hidden="true">' + esc(initials(name)) + '</span>';
          $tb.append(`
            <tr role="button" tabindex="0" data-id="${r.id}">
              <td data-label="Name">
                <span class="mc-cell-wrap">
                  ${avatarCell}
                  <span class="fw-semibold mc-truncate" title="${esc(name)}">${esc(name)}</span>
                </span>
              </td>
              <td data-label="Email"><span class="mc-truncate" title="${esc(mail)}">${esc(mail)}</span></td>
              <td data-label="Mobile"><span class="mc-truncate" title="${esc(mob)}">${esc(mob)}</span></td>
              <td data-label="Registered">${esc(date)}</td>
              <td data-label="Status">${statusCell}</td>
              <td data-label="Actions" class="text-end">
                <span class="mc-admin-cit-actions">
                  <a class="mc-admin-cit-action-btn" href="${viewUrl}?id=${encodeURIComponent(r.id)}"
                     title="Open the full profile for ${esc(name)}">
                    <i data-lucide="eye" class="lucide" aria-hidden="true"></i> View
                  </a>
                  ${actionCell}
                </span>
              </td>
            </tr>
          `);
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
        });
      })
      .fail(function () {
        $("#citizensHint").addClass("is-error")
          .html("<div class='small'>Failed to load citizens. Check your connection, then press Search.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      });
  }

  /* Whole row is a link target. Interactive children keep their own behaviour:
     the active switch must toggle without navigating, and the View link
     navigates itself. Same destination as the link either way. */
  function openCitizen(tr) {
    window.location.href = viewUrl + "?id=" + encodeURIComponent($(tr).data("id"));
  }
  $("#citizensTable").on("click", "tr[data-id]", function (e) {
    if ($(e.target).closest(".mc-admin-switch, .mc-admin-cit-action-btn, a, button, input, label").length) return;
    openCitizen(this);
  });
  $("#citizensTable").on("keydown", "tr[data-id]", function (e) {
    if (e.key !== "Enter" && e.key !== " " && e.key !== "Spacebar") return;
    e.preventDefault();
    openCitizen(this);
  });

  $("#searchBtn").on("click", load);
  $("#showFilter").on("change", load);
  $("#includeInactive").on("change", load);
  $("#q").on("keydown", function (e) { if (e.key === "Enter") load(); if(e.key==='Escape'){ $(this).val('').blur(); load(); } });
  $("#q").on("input", function () {
    clearTimeout(window.__adminSearchTimer);
    window.__adminSearchTimer = setTimeout(load, 300);
  });

  $("#citizensTable").on("change", ".citizen-active", function () {
    const $sw = $(this);
    const $tr = $sw.closest("tr");
    const id = $tr.data("id");
    const active = $sw.is(":checked") ? 1 : 0;
    $sw.prop("disabled", true);
    $.post(endpoint, { action: "set_active", id, active })
      .done(function (res) {
        if (!res || !res.ok) {
          $("#citizensHint").addClass("is-error")
            .html("<div class='small'>Failed to update citizen.</div>");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
        }
      })
      .fail(function () {
        $("#citizensHint").addClass("is-error")
          .html("<div class='small'>Failed to update citizen.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      })
      .always(function () {
        $sw.prop("disabled", false);
      });
  });

  /* ---------- Archive (soft delete) ----------
     There is no hard delete. The server sets deleted_at and active = 0, so the
     account leaves this list and can no longer sign in, while the row and every
     report it owns stay on record. */
  const archiveModal = new bootstrap.Modal(document.getElementById("archiveModal"));
  let archiveId = 0;

  function hint(html, isError) {
    $("#citizensHint").toggleClass("is-error", !!isError).html(html);
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  }

  $("#citizensTable").on("click", ".archive-citizen", function () {
    archiveId = Number($(this).data("id")) || 0;
    if (!archiveId) return;
    $("#archiveTarget").text($(this).data("name") || "");
    archiveModal.show();
  });

  $("#archiveConfirmBtn").on("click", function () {
    if (!archiveId) return;
    const $btn = $(this).prop("disabled", true);
    const name = $("#archiveTarget").text();
    $.post(endpoint, { action: "archive", id: archiveId })
      .done(function (res) {
        if (!res || !res.ok) {
          const msg = (res && res.error === "already_archived")
            ? "That account is already archived."
            : ((res && res.error) || "Failed to delete citizen.");
          hint("<div class='small'>" + esc(msg) + "</div>", true);
          return;
        }
        /* Reload rather than splice the row out: under "Show: All" an archived
           row correctly stays in the list, so removing it here would be wrong. */
        const kept = Number(res.kept_concerns || 0);
        flashHint = "<div class='small'>Deleted " + esc(name) + ". "
          + (kept === 0
             ? "No reports were attached."
             : esc(kept) + " report" + (kept === 1 ? "" : "s") + " kept on record.")
          + "</div>";
        archiveModal.hide();
        load();
      })
      .fail(function () {
        hint("<div class='small'>Failed to delete citizen. Check your connection, then try again.</div>", true);
      })
      .always(function () {
        $btn.prop("disabled", false);
      });
  });

  /* ---------- Restore ----------
     The inverse of Delete, minus the access: the server clears deleted_at but
     leaves active = 0, so the account and its reports come back while the
     citizen still cannot sign in until the Active switch is flipped. No confirm
     modal — nothing is destroyed and no access is granted. */
  $("#citizensTable").on("click", ".restore-citizen", function () {
    const id = Number($(this).data("id")) || 0;
    if (!id) return;
    const name = String($(this).data("name") || "");
    const $btn = $(this).prop("disabled", true);
    $.post(endpoint, { action: "restore", id })
      .done(function (res) {
        if (!res || !res.ok) {
          const msg = (res && res.error === "not_archived")
            ? "That account is not archived."
            : ((res && res.error) || "Failed to restore citizen.");
          hint("<div class='small'>" + esc(msg) + "</div>", true);
          return;
        }
        /* A restored account is no longer archived, and it is still disabled, so
           it drops out of the Archived view. Reload so every "Show" mode stays
           truthful. */
        flashHint = "<div class='small'>Restored " + esc(name) + ". The account is back but still disabled"
          + " — turn on the Status switch to let them sign in again.</div>";
        load();
      })
      .fail(function () {
        hint("<div class='small'>Failed to restore citizen. Check your connection, then try again.</div>", true);
      })
      .always(function () {
        $btn.prop("disabled", false);
      });
  });

  /* ---------- Edit identity (super admin) ----------
     Prefills from the row object the list already holds, so opening the modal
     costs no extra request. The save posts to admin/api/citizen.php, which
     writes an admin_activity_log row. */
  const citizenRows = Object.create(null);

  $("#citizensTable").on("click", ".edit-citizen", function () {
    const id = Number($(this).data("id")) || 0;
    if (!id) return;
    const row = citizenRows[id];
    if (!row) return;
    window.__openCitizenEdit({
      endpoint: "__CITIZEN_EDIT_ENDPOINT__",
      modalId: "citizenEditModal",
      id: id,
      citizen: row
    });
  });

  /* The modal asks the list to reload once a save lands, so the row reflects
     the new name/email/mobile without a manual refresh. */
  window.__onCitizenEdited = function (res) {
    if (res && res.marked_unverified) {
      flashHint = "<div class='small'>Saved. That account must confirm its new email address"
        + " before it can be used again.</div>";
    }
    load();
  };

  load();
});
</script>
HTML;
$pageScripts = str_replace(
    ["__CITIZENS_ENDPOINT__", "__CITIZEN_VIEW_URL__", "__CITIZEN_EDIT_ENDPOINT__", "__CLOUD_NAME__"],
    [
        $citizensEndpoint,
        $citizenViewUrl,
        e(app_url('/admin/api/citizen.php')),
        e((string) (defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : '')),
    ],
    $pageScripts
);
$citizenEditModal = (string) ($citizenEditModal ?? 'citizenEditModal');
require __DIR__ . '/../includes/partials/admin_citizen_edit_modal.php';
$pageScripts .= "\n" . '<script src="' . e(app_url('/assets/js/admin-citizen-edit.js')) . '" defer></script>';

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';

