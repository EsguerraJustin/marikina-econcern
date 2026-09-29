<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';

$concernId = (int) ($_GET['id'] ?? 0);
if ($concernId <= 0) {
    redirect(app_url('/admin/concerns.php'));
}

$pageTitle = 'Concern Details';
$activeNav = 'concerns';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

?>
<div class="mc-admin-concern_view-page">

<div class="mc-admin-cv-header">
    <a class="mc-admin-cv-back-link" href="<?= e(app_url('/admin/concerns.php')) ?>">
        <i data-lucide="arrow-left" class="lucide" aria-hidden="true"></i>
        <span>Back to Concerns</span>
    </a>
</div>

<div class="mc-admin-cv-title-row">
    <div class="mc-admin-cv-icon-wrap">
        <i data-lucide="clipboard-list" class="lucide" aria-hidden="true"></i>
    </div>
    <div class="mc-admin-cv-title-block">
        <div class="mc-admin-cv-status-badge-wrap" id="titleBadges"></div>
        <h1 id="titleHeading">Concern Details</h1>
        <div class="mc-admin-cv-meta" id="titleMeta"></div>
    </div>
</div>

<div class="mc-admin-cv-grid">

    <div class="mc-admin-cv-main-col">
        <div class="mc-admin-cv-card">
            <div class="mc-admin-cv-section-title">
                <i data-lucide="file-text" class="lucide" aria-hidden="true"></i>
                <span>Report Details</span>
            </div>
            <div class="mc-admin-cv-section-body" id="detailBox">
                <div class="mc-admin-cv-loading">Loading report details&hellip;</div>
            </div>
        </div>

        <div class="mc-admin-cv-card" id="photosCard" hidden>
            <div class="mc-admin-cv-section-title">
                <i data-lucide="image" class="lucide" aria-hidden="true"></i>
                <span>Photos</span>
            </div>
            <div class="mc-admin-cv-section-body">
                <div class="mc-admin-cv-photos-grid" id="photosBox"></div>
            </div>
        </div>

        <div class="mc-admin-cv-card">
            <div class="mc-admin-cv-section-title">
                <i data-lucide="history" class="lucide" aria-hidden="true"></i>
                <span>Timeline</span>
                <span class="ms-auto d-flex gap-2">
                    <button class="btn btn-primary mc-admin-cv-action-btn-sm" type="button" id="openChatBtn">
                        <i data-lucide="message-circle" class="lucide" aria-hidden="true"></i>
                        <span>Message Citizen</span>
                    </button>
                </span>
            </div>
            <div class="mc-admin-cv-section-body" id="timelineBox">
                <div class="mc-admin-cv-loading">Loading timeline&hellip;</div>
            </div>
        </div>
    </div>

    <div class="mc-admin-cv-side-col">

        <div class="mc-admin-cv-sidebar-card">
            <div class="mc-admin-cv-section-title p-0 mb-3" style="background:none;border:0;padding:0;">
                <i data-lucide="user-check" class="lucide" aria-hidden="true"></i>
                <span>Assigned To</span>
            </div>
            <div id="assigneeBox">
                <div class="mc-admin-cv-loading">Loading&hellip;</div>
            </div>
            <div id="assigneeControls" hidden>
                <div class="mc-admin-cv-assign-row" id="assignPickRow" hidden>
                    <select class="form-select" id="assignSelect" aria-label="Assign this concern to"></select>
                    <button class="btn btn-primary" type="button" id="assignSaveBtn">Save</button>
                </div>
                <div class="mc-admin-cv-action-row" id="assignSelfRow" hidden>
                    <button class="btn btn-primary" type="button" id="claimBtn">Assign to me</button>
                    <button class="btn btn-outline-secondary" type="button" id="releaseBtn">Release</button>
                </div>
                <div class="mc-admin-cv-hint" id="assignHint"></div>
            </div>

            <div class="mc-admin-cv-section-title p-0 mb-3 mt-4" style="background:none;border:0;padding:0;">
                <i data-lucide="refresh-cw" class="lucide" aria-hidden="true"></i>
                <span>Update Status</span>
            </div>
            <div class="mc-admin-cv-action-form">
                <div class="mc-admin-cv-field">
                    <label class="form-label" for="statusSelect">Status</label>
                    <select class="form-select" id="statusSelect">
                        <?php foreach (['New', 'Ongoing', 'Acknowledge', 'Completed', 'Cancelled'] as $s) : ?>
                            <option value="<?= e($s) ?>"><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mc-admin-cv-field">
                    <label class="form-label" for="statusNote">Note</label>
                    <textarea class="form-control mc-admin-cv-note" id="statusNote" placeholder="Required note for status change..."></textarea>
                </div>
                <div class="mc-admin-cv-action-row">
                    <button class="btn btn-primary w-100" type="button" id="updateStatusBtn">Update Status</button>
                </div>
            </div>
            <div class="mt-2" id="statusHint"></div>
        </div>

        <div class="mc-admin-cv-sidebar-card">
            <div class="mc-admin-cv-section-title p-0 mb-3" style="background:none;border:0;padding:0;">
                <i data-lucide="lock" class="lucide" aria-hidden="true"></i>
                <span>Internal Notes</span>
            </div>
            <p class="mc-admin-cv-photos-empty mb-3">Only visible to admins.</p>
            <div class="mc-admin-cv-action-form">
                <div class="mc-admin-cv-field">
                    <label class="form-label" for="noteInput">New note</label>
                    <textarea class="form-control mc-admin-cv-note" id="noteInput" placeholder="Write an internal note..."></textarea>
                </div>
                <div class="mc-admin-cv-action-row">
                    <button class="btn btn-outline-primary w-100" type="button" id="addNoteBtn">Add Note</button>
                </div>
            </div>
            <div class="mt-3" id="notesBox">
                <div class="mc-admin-cv-loading">Loading notes&hellip;</div>
            </div>
        </div>

    </div>
</div>
</div>

<div class="modal fade" id="chatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Messages</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body mc-admin-cv-chat-body" id="chatBody"></div>
            <div class="modal-footer mc-admin-cv-chat-footer">
                <input class="form-control" id="chatInput" placeholder="Type your message...">
                <button class="btn btn-primary" id="sendBtn">Send</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 mc-admin-cv-photo-modal">
                <img id="photoModalImg" src="" alt="Photo">
            </div>
        </div>
    </div>
</div>

<?php
$baseUrl = e(app_base_url());
$detailEndpoint = e(app_url('/admin/api/concern.php'));
$timelineEndpoint = e(app_url('/admin/api/concern_timeline.php'));
$messagesEndpoint = e(app_url('/admin/api/concern_messages.php'));
$postMessageEndpoint = e(app_url('/admin/api/post_message.php'));
$notesEndpoint = e(app_url('/admin/api/concern_notes.php'));
$statusEndpoint = e(app_url('/admin/api/update_status.php'));
$assignEndpoint = e(app_url('/admin/api/assign_concern.php'));

$pageScripts = <<<'HTML'
<script>
$(function () {
  const id = __ID__;
  const base = "__BASE__";
  const detailEndpoint = "__DETAIL_ENDPOINT__";
  const timelineEndpoint = "__TIMELINE_ENDPOINT__";
  const messagesEndpoint = "__MESSAGES_ENDPOINT__";
  const postMessageEndpoint = "__POST_MESSAGE_ENDPOINT__";
  const notesEndpoint = "__NOTES_ENDPOINT__";
  const statusEndpoint = "__STATUS_ENDPOINT__";
  const assignEndpoint = "__ASSIGN_ENDPOINT__";

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }

  /* Presentational only: "2026-09-08 13:54:49" -> "Sep 8, 2026, 1:54 PM". */
  function fmtDateTime(v) {
    var s = String(v == null ? "" : v).replace("T", " ").trim();
    var m = /^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})/.exec(s);
    if (!m) return s;
    var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]));
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" })
         + ", " + d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
  }

  function fmtTime(v) {
    var s = String(v == null ? "" : v);
    var m = /(\d{2}):(\d{2})/.exec(s);
    if (!m) return s;
    var d = new Date(2000, 0, 1, Number(m[1]), Number(m[2]));
    if (isNaN(d.getTime())) return s;
    return d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
  }

  /* Presentational only: two-letter initials, letters/digits stripped. */
  function initials(name) {
    var parts = String(name == null ? "" : name).trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "?";
    var first = parts[0].charAt(0);
    var second = parts.length > 1 ? parts[parts.length - 1].charAt(0) : "";
    return (first + second).replace(/[^a-z0-9]/gi, "").toUpperCase() || "?";
  }

  function roleLabel(role) {
    var r = String(role == null ? "" : role);
    if (r === "super_admin") return "Super Admin";
    if (r === "department_admin") return "Department Admin";
    return r;
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
  var PILL_ICON = {
    "New": "inbox",
    "Acknowledge": "check",
    "Ongoing": "clock",
    "Completed": "circle-check",
    "Cancelled": "circle-x"
  };
  function pill(status) {
    var key = String(status == null ? "" : status);
    return '<span class="mc-admin-cv-badge ' + (PILL[key] || "mc-admin-cv-badge--new") + '">'
         + '<i data-lucide="' + (PILL_ICON[key] || "dot") + '" class="lucide" aria-hidden="true"></i>'
         + esc(key) + '</span>';
  }

  function metaItem(icon, value) {
    return '<span class="mc-admin-cv-meta-item">'
         + '<i data-lucide="' + icon + '" class="lucide" aria-hidden="true"></i>'
         + '<span title="' + esc(value) + '">' + esc(value) + '</span>'
         + '</span>';
  }

  function detailItem(label, value, wide) {
    return '<div class="mc-admin-cv-detail-item' + (wide ? " mc-admin-cv-detail-item--wide" : "") + '">'
         + '<span class="mc-admin-cv-detail-label">' + esc(label) + '</span>'
         + '<span class="mc-admin-cv-detail-value">' + esc(value) + '</span>'
         + '</div>';
  }

  function emptyBlock(icon, text) {
    return '<div class="mc-admin-cv-empty">'
         + '<div class="mc-admin-cv-empty-icon"><i data-lucide="' + icon + '" class="lucide" aria-hidden="true"></i></div>'
         + '<p>' + esc(text) + '</p>'
         + '</div>';
  }

  function fail(target, msg) {
    $(target).html('<div class="mc-admin-cv-loading is-error">' + esc(msg) + '</div>');
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  }

  /* Assignment controls.
     Super Admin gets a picker of every active admin. Department Admin gets
     Claim / Release for themselves only — the server enforces the same rule in
     admin/api/assign_concern.php, this is just the matching affordance. */
  function renderAssignControls(res, c) {
    const canAssignAny = res.can_assign_any === true;
    const list = res.assignable || [];
    const current = Number(c.assigned_admin_id || 0);
    $("#assigneeControls").removeAttr("hidden");

    if (canAssignAny && list.length > 0) {
      const $sel = $("#assignSelect").empty();
      $sel.append('<option value="0"' + (current === 0 ? " selected" : "") + '>Unassigned</option>');
      list.forEach(function (a) {
        $sel.append('<option value="' + esc(a.id) + '"'
          + (Number(a.id) === current ? " selected" : "") + '>'
          + esc(a.name) + " (" + esc(roleLabel(a.role)) + ")</option>");
      });
      $("#assignPickRow").removeAttr("hidden");
      $("#assignSelfRow").attr("hidden", "hidden");
      $("#assignHint").text("Assignment is recorded in the timeline.");
      return;
    }

    /* Department Admin: assignable is just themselves, so index 0 is "me". */
    $("#assignPickRow").attr("hidden", "hidden");
    const me = list.length > 0 ? Number(list[0].id) : 0;
    if (me > 0) {
      selfAdminId = me;
      $("#assignSelfRow").removeAttr("hidden");
      const mine = current === me;
      $("#claimBtn").toggleClass("d-none", mine);
      $("#releaseBtn").toggleClass("d-none", !mine);
      $("#assignHint").text(mine
        ? "You claimed this report."
        : "You can claim this report for yourself.");
    } else {
      $("#assignSelfRow").attr("hidden", "hidden");
      $("#assignHint").text("");
    }
  }

  let selfAdminId = 0;

  function doAssign(adminId) {
    $("#assignHint").removeClass("is-error is-success").text("Saving assignment&hellip;");
    const $btns = $("#assignSaveBtn, #claimBtn, #releaseBtn").prop("disabled", true);
    return $.post(assignEndpoint, { id, admin_id: adminId })
      .done(function (res) {
        if (!res || !res.ok) {
          $("#assignHint").addClass("is-error").text((res && res.error) ? String(res.error) : "Failed to update assignment.");
          return;
        }
        $("#assignHint").addClass("is-success").text(res.unchanged ? "No change." : "Assignment updated.");
        loadDetail();
        loadTimeline();
      })
      .fail(function (xhr) {
        let msg = "Failed to update assignment.";
        try {
          const body = JSON.parse(xhr.responseText);
          if (body && body.error) msg = String(body.error);
        } catch (e) { /* keep default */ }
        $("#assignHint").addClass("is-error").text(msg);
      })
      .always(function () {
        $("#assignSaveBtn, #claimBtn, #releaseBtn").prop("disabled", false);
      });
  }

  $("#assignSaveBtn").on("click", function () {
    doAssign(Number($("#assignSelect").val() || 0));
  });
  $("#claimBtn").on("click", function () { doAssign(selfAdminId); });
  $("#releaseBtn").on("click", function () { doAssign(0); });

  function loadDetail() {
    $.getJSON(detailEndpoint, { id }).done(function(res){
      if (!res || !res.ok) { fail("#detailBox", "Report not found."); return; }
      const c = res.concern;
      const when = fmtDateTime(c.created_at);
      $("#statusSelect").val(c.status || "New");

      /* Title block */
      $("#titleBadges").html(pill(c.status)
        + (c.report_number ? '<span class="mc-admin-cv-ref">' + esc(c.report_number) + '</span>' : ""));
      $("#titleHeading").text(c.concern_type || "Concern Details");
      $("#titleMeta").html(
        metaItem("building-2", c.department || "")
        + metaItem("map-pin", c.barangay || "")
        + metaItem("calendar-days", when)
      );

      /* Assignee. concerns.assigned_admin_id is written only by
         admin/api/assign_concern.php — a status change no longer claims the
         report — so NULL genuinely means nobody has assigned it. */
      const aName = c.assignee_name || "";
      const aRole = roleLabel(c.assignee_role);
      const aDept = c.assignee_department || "";
      $("#assigneeBox").html(
        '<div class="mc-admin-cv-assignee-row' + (aName ? "" : " is-unassigned") + '">'
        +   '<span class="mc-admin-cv-assignee-avatar">'
        +     (aName ? esc(initials(aName)) : '<i data-lucide="user-x" class="lucide" aria-hidden="true"></i>')
        +   '</span>'
        +   '<span class="mc-admin-cv-assignee-info">'
        +     '<span class="mc-admin-cv-assignee-name">' + (aName ? esc(aName) : "Not assigned") + '</span>'
        +     '<span class="mc-admin-cv-assignee-role">'
        +       (aName ? esc(aRole) + (aDept ? " &middot; " + esc(aDept) : "") : "No one has been assigned yet")
        +     '</span>'
        +   '</span>'
        + '</div>'
      );

      renderAssignControls(res, c);

      /* Details */
      const loc = [c.street, c.barangay].filter(Boolean).join(", ");
      let html = '<div class="mc-admin-cv-details-grid">'
        + detailItem("Report Number", c.report_number || "")
        + detailItem("Date Submitted", when)
        + detailItem("Citizen", c.citizen_name || "")
        + detailItem("Email", c.citizen_email || "")
        + detailItem("Concern Type", c.concern_type || "")
        + detailItem("Department", c.department || "")
        + detailItem("Location", loc || "—", true)
        + detailItem("Landmark", c.landmark || "—")
        + '</div>';
      if (c.description) {
        html += '<h4 class="mc-admin-cv-detail-label mt-4 mb-2">Description</h4>'
             + '<p class="mc-admin-cv-description">' + esc(c.description) + '</p>';
      }
      $("#detailBox").html(html);

      /* Photos */
      const photos = c.photos || [];
      if (photos.length === 0) {
        $("#photosBox").empty();
        $("#photosCard").attr("hidden", true);
        return;
      }
      $("#photosCard").removeAttr("hidden");
      $("#photosBox").html(photos.map(function (p) {
        const src = (String(p).indexOf("http") === 0) ? p : base + p;
        return '<a class="mc-admin-cv-photo-thumb" href="#" data-src="' + esc(src) + '">'
             + '<img src="' + esc(src) + '" alt="Concern photo" loading="lazy"></a>';
      }).join(""));
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    }).fail(function () { fail("#detailBox", "Failed to load report details."); });
  }

  function loadTimeline() {
    $.getJSON(timelineEndpoint, { id }).done(function(res){
      if (!res || !res.ok) { fail("#timelineBox", "Failed to load timeline."); return; }
      const rows = res.timeline || [];
      if (rows.length === 0) {
        $("#timelineBox").html(emptyBlock("history", "No updates yet."));
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        return;
      }
      $("#timelineBox").html('<div class="mc-admin-cv-timeline-wrap">' + rows.map(function (r) {
        const type = String(r.event_type == null ? "status_change" : r.event_type);
        const key = String(r.status == null ? "" : r.status);
        const actor = String(r.admin_name == null ? "" : r.admin_name);
        const when = fmtDateTime(r.created_at);

        if (type === "submitted") {
          /* Filed by the citizen — there is deliberately no admin actor. */
          return '<div class="mc-admin-cv-timeline-item pending">'
            + '<span class="mc-admin-cv-timeline-badge"><i data-lucide="send" class="lucide" aria-hidden="true"></i></span>'
            + '<div class="mc-admin-cv-timeline-content">'
            +   '<div class="mc-admin-cv-timeline-head">'
            +     '<span class="mc-admin-cv-timeline-title">Submitted</span>'
            +     '<span class="mc-admin-cv-timeline-time"><i data-lucide="clock" class="lucide" aria-hidden="true"></i>' + esc(when) + '</span>'
            +   '</div>'
            +   (r.note && r.note !== "Submitted" ? '<p class="mc-admin-cv-timeline-body">' + esc(r.note) + '</p>' : "")
            + '</div></div>';
        }

        if (type === "assigned") {
          /* An assignment event, not a status change. admin_id holds whoever
             performed the assign/release, not who is assigned. */
          return '<div class="mc-admin-cv-timeline-item pending">'
            + '<span class="mc-admin-cv-timeline-badge"><i data-lucide="user-check" class="lucide" aria-hidden="true"></i></span>'
            + '<div class="mc-admin-cv-timeline-content">'
            +   '<div class="mc-admin-cv-timeline-head">'
            +     '<span class="mc-admin-cv-timeline-title">Assignment</span>'
            +     '<span class="mc-admin-cv-timeline-time"><i data-lucide="clock" class="lucide" aria-hidden="true"></i>' + esc(when) + '</span>'
            +   '</div>'
            +   (r.note ? '<p class="mc-admin-cv-timeline-body">' + esc(r.note) + '</p>' : "")
            +   (actor ? timelineAuthor(actor) : "")
            + '</div></div>';
        }

        const state = key === "Completed" ? "completed" : "pending";
        const from = String(r.old_status == null ? "" : r.old_status);
        const transition = (from && from !== key)
          ? '<div class="mc-admin-cv-timeline-transition">' + esc(from)
            + '<i data-lucide="arrow-right" class="lucide" aria-hidden="true"></i>' + esc(key) + '</div>'
          : "";
        return '<div class="mc-admin-cv-timeline-item ' + state + '">'
          + '<span class="mc-admin-cv-timeline-badge">'
          +   '<i data-lucide="' + (PILL_ICON[key] || "dot") + '" class="lucide" aria-hidden="true"></i>'
          + '</span>'
          + '<div class="mc-admin-cv-timeline-content">'
          +   '<div class="mc-admin-cv-timeline-head">'
          +     '<span class="mc-admin-cv-timeline-title">' + esc(key) + '</span>'
          +     '<span class="mc-admin-cv-timeline-time"><i data-lucide="clock" class="lucide" aria-hidden="true"></i>' + esc(when) + '</span>'
          +   '</div>'
          +   transition
          +   (r.note ? '<p class="mc-admin-cv-timeline-body">' + esc(r.note) + '</p>' : "")
          /* Populated for every status change from Phase 2 onwards; the 7
             historical rows were backfilled, the 13 submission rows have no
             admin because a citizen filed them. */
          +   (actor ? timelineAuthor(actor) : "")
          + '</div></div>';
      }).join("") + '</div>');
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    }).fail(function () { fail("#timelineBox", "Failed to load timeline."); });
  }

  function timelineAuthor(name) {
    return '<div class="mc-admin-cv-timeline-author">'
      + '<span class="mc-admin-cv-timeline-avatar">' + esc(initials(name)) + '</span>'
      + '<span class="mc-admin-cv-timeline-author-name">' + esc(name) + '</span>'
      + '</div>';
  }

  function renderChat(rows) {
    if (!rows || rows.length === 0) {
      $("#chatBody").html(emptyBlock("message-circle", "No messages yet."));
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }
    $("#chatBody").html(rows.map(function (r) {
      const mine = r.sender === "department";
      const roleLabelText = mine ? "You (Admin)" : "Client";
      const roleIcon = mine ? "shield-check" : "user";
      return '<div class="mc-admin-cv-chat-row' + (mine ? " is-mine" : "") + '">'
        + (mine ? "" : '<span class="mc-chat-avatar mc-chat-avatar-admin"><i data-lucide="' + roleIcon + '" class="lucide lucide-16"></i></span>')
        + '<div class="mc-admin-cv-chat-col">'
        +   '<span class="mc-admin-cv-chat-role">'
        +     '<span>' + esc(roleLabelText) + '</span>'
        +     (mine ? '<span class="mc-chat-avatar mc-chat-avatar-me mc-chat-avatar--sm"><i data-lucide="' + roleIcon + '" class="lucide lucide-16"></i></span>' : "")
        +   '</span>'
        +   '<div class="mc-admin-cv-chat-bubble ' + (mine ? "is-mine" : "is-theirs") + '">'
        +     esc(r.message)
        +     '<span class="mc-admin-cv-chat-time">' + esc(fmtTime(r.created_at)) + '</span>'
        +   '</div>'
        + '</div>'
        + '</div>';
    }).join(""));
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    const el = document.getElementById("chatBody");
    el.scrollTop = el.scrollHeight;
  }

  function loadMessages() {
    return $.getJSON(messagesEndpoint, { id }).done(function(res){
      if (!res || !res.ok) { renderChat([]); return; }
      renderChat(res.messages || []);
    });
  }

  function loadNotes() {
    $("#notesBox").html('<div class="mc-admin-cv-loading">Loading notes&hellip;</div>');
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    return $.getJSON(notesEndpoint, { id }).done(function(res){
      if (!res || !res.ok) { fail("#notesBox", "Failed to load notes."); return; }
      const rows = res.notes || [];
      if (rows.length === 0) {
        $("#notesBox").html(emptyBlock("notebook-pen", "No notes yet."));
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        return;
      }
      $("#notesBox").html(rows.map(function (n) {
        return '<div class="mc-admin-cv-note-item">'
          + '<p class="mc-admin-cv-note-text">' + esc(n.note) + '</p>'
          + '<span class="mc-admin-cv-note-meta">'
          +   '<i data-lucide="user" class="lucide" aria-hidden="true"></i>'
          +   esc(n.admin_name || "Admin") + ' &middot; ' + esc(fmtDateTime(n.created_at))
          + '</span>'
          + '</div>';
      }).join(""));
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    }).fail(function () { fail("#notesBox", "Failed to load notes."); });
  }

  const chatModal = new bootstrap.Modal(document.getElementById("chatModal"));
  const photoModal = new bootstrap.Modal(document.getElementById("photoModal"));

  $("#openChatBtn").on("click", function () {
    chatModal.show();
    loadMessages();
  });

  $("#sendBtn").on("click", function () {
    const msg = ($("#chatInput").val() || "").trim();
    if (!msg) return;
    $("#sendBtn").prop("disabled", true);
    $.post(postMessageEndpoint, { id, message: msg })
      .done(function (res) {
        if (res && res.ok) {
          $("#chatInput").val("");
          loadMessages();
        }
      })
      .always(function () {
        $("#sendBtn").prop("disabled", false);
      });
  });

  $("#chatInput").on("keypress", function(e){
    if (e.which === 13) {
      e.preventDefault();
      $("#sendBtn").click();
    }
  });

  $("#updateStatusBtn").on("click", function () {
    const status = $("#statusSelect").val();
    const note = ($("#statusNote").val() || "").trim();
    $("#statusHint").empty();
    $("#updateStatusBtn").prop("disabled", true);
    $.post(statusEndpoint, { id, status, note })
      .done(function (res) {
        if (!res || !res.ok) {
          $("#statusHint").html("<div class='mc-admin-cv-hint is-error'>Failed to update status.</div>");
          return;
        }
        $("#statusNote").val("");
        $("#statusHint").html("<div class='mc-admin-cv-hint is-success'>Status updated.</div>");
        loadDetail();
        loadTimeline();
      })
      .fail(function (xhr) {
        let msg = "Failed to update status.";
        if (xhr && xhr.status === 422) {
          msg = "Note is required.";
        } else if (xhr && xhr.status === 404) {
          /* Department Admins are scoped server-side, so a concern outside their
             department is reported as not_found rather than forbidden. */
          msg = "This report is not in your department.";
        }
        $("#statusHint").html("<div class='mc-admin-cv-hint is-error'>" + esc(msg) + "</div>");
      })
      .always(function () {
        $("#updateStatusBtn").prop("disabled", false);
      });
  });

  $("#addNoteBtn").on("click", function () {
    const note = ($("#noteInput").val() || "").trim();
    if (!note) return;
    $("#addNoteBtn").prop("disabled", true);
    $.post(notesEndpoint, { id, note })
      .done(function (res) {
        if (res && res.ok) {
          $("#noteInput").val("");
          loadNotes();
        }
      })
      .always(function () {
        $("#addNoteBtn").prop("disabled", false);
      });
  });

  $("#photosBox").on("click", ".mc-admin-cv-photo-thumb", function (e) {
    e.preventDefault();
    const src = $(this).data("src");
    $("#photoModalImg").attr("src", src || "");
    photoModal.show();
  });

  loadDetail();
  loadTimeline();
  loadNotes();
});
</script>
HTML;

$pageScripts = str_replace(
    ["__ID__", "__BASE__", "__DETAIL_ENDPOINT__", "__TIMELINE_ENDPOINT__", "__MESSAGES_ENDPOINT__", "__POST_MESSAGE_ENDPOINT__", "__NOTES_ENDPOINT__", "__STATUS_ENDPOINT__", "__ASSIGN_ENDPOINT__"],
    [(string) $concernId, $baseUrl, $detailEndpoint, $timelineEndpoint, $messagesEndpoint, $postMessageEndpoint, $notesEndpoint, $statusEndpoint, $assignEndpoint],
    $pageScripts
);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
