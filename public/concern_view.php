<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$concernId = (int) ($_GET['id'] ?? 0);
if ($concernId <= 0) {
    redirect(app_url('/public/my_concern.php'));
}

$pageTitle = 'Concern Progress';
$activeNav = 'my';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

?>
<div class="mc-concern-view-page">
<!-- ============== PAGE HEADER HERO CARD (NOT FLOATING!) ============== -->
<div class="mc-concern-view-hero mb-4">
    <div class="mc-concern-view-hero-inner">
        <div class="mc-concern-view-hero-title-row">
            <div class="mc-concern-view-hero-icon-wrap">
                <i data-lucide="clipboard-check" class="lucide"></i>
            </div>
            <div class="mc-concern-view-hero-title">
                <h1 class="mb-0">Concern Progress</h1>
                <p class="mb-0 text-muted" style="margin-top:2px;">Track your report status and messages.</p>
            </div>
        </div>
        <div class="mc-concern-view-hero-brand">
            <img class="mc-concern-view-brand-img" src="<?= e(app_base_url() . '/assets/img/Marikina-e-Concern.png') ?>" alt="Marikina e-Concern">
        </div>
        <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/my_concern.php')) ?>">
            <i data-lucide="arrow-left" class="lucide lucide-16 me-1"></i>Back
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="mc-concern-view-detail-card mb-3">
            <div class="mc-detail-head mb-3">
                <i data-lucide="file-text" class="lucide lucide-18"></i>
                <span>Report Details</span>
            </div>
            <div id="detailBox" class="text-muted">Loading...</div>
            <div class="mt-3" id="photosBox"></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="mc-concern-view-timeline-card mb-3">
            <div class="mc-detail-head mb-3">
                <i data-lucide="history" class="lucide lucide-18"></i>
                <span>Timeline</span>
                <button class="btn btn-sm btn-primary ms-auto d-inline-flex align-items-center gap-2 overflow-visible" type="button" id="openChatBtn" style="min-width:132px;padding-inline:16px;">
                    <i data-lucide="message-square" class="lucide lucide-16 me-1"></i>Message
                </button>
            </div>
            <div class="mt-2" id="timelineBox" class="text-muted">Loading...</div>
        </div>
    </div>
</div>
</div>

<div class="modal fade mc-message-modal" id="chatModal" tabindex="-1" aria-labelledby="chatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="mc-message-modal-header">
                <h5 class="mc-message-modal-title" id="chatModalLabel">
                    <i data-lucide="messages-3" class="lucide lucide-18"></i>
                    <span>Messages</span>
                </h5>
                <button type="button" class="mc-message-modal-close" data-bs-dismiss="modal" aria-label="Close">
                    <i data-lucide="x" class="lucide lucide-16"></i>
                </button>
            </div>
            <div class="mc-message-modal-body">
                <div class="mc-chat-bubbles" id="chatBody"></div>
                <div class="mc-chat-input-row">
                    <input class="form-control mc-chat-input" id="chatInput" placeholder="Type your message..." autocomplete="off">
                    <button class="btn btn-primary" id="sendBtn">
                        <i data-lucide="send-horizontal" class="lucide lucide-16 me-1"></i>Send
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$baseUrl = e(app_base_url());
$detailEndpoint = e(app_url('/api/concern.php'));
$timelineEndpoint = e(app_url('/api/concern_timeline.php'));
$messagesEndpoint = e(app_url('/api/concern_messages.php'));
$postMessageEndpoint = e(app_url('/api/post_message.php'));

$pageScripts = <<<'HTML'
<script>
$(function () {
  const id = __ID__;
  const base = "__BASE__";
  const detailEndpoint = "__DETAIL_ENDPOINT__";
  const timelineEndpoint = "__TIMELINE_ENDPOINT__";
  const messagesEndpoint = "__MESSAGES_ENDPOINT__";
  const postMessageEndpoint = "__POST_MESSAGE_ENDPOINT__";

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }

  function statusBadge(status) {
    const map = {
      New:       { cls: "mc-status-badge mc-status-new",       icon: "mail-plus" },
      Ongoing:   { cls: "mc-status-badge mc-status-ongoing",   icon: "loader-2" },
      Acknowledge:{cls: "mc-status-badge mc-status-ack",       icon: "eye" },
      Completed: { cls: "mc-status-badge mc-status-completed", icon: "check-circle-2" },
      Cancelled: { cls: "mc-status-badge mc-status-cancelled", icon: "ban" }
    };
    const m = map[status] || { cls: "mc-status-badge mc-status-muted", icon: "circle-help" };
    return `<span class="${m.cls}"><i data-lucide="${m.icon}" class="lucide"></i><span>${esc(status)}</span></span>`;
  }

  function loadDetail() {
    $.getJSON(detailEndpoint, { id }).done(function(res){
      if (!res || !res.ok) {
        $("#detailBox").html("<div class='text-danger'>Report not found.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        return;
      }
      const c = res.concern;
      const date = c.created_at ? String(c.created_at).slice(0, 19).replace("T"," ") : "";
      $("#detailBox").html(`
        <div class="mc-detail-row">
          <div class="mc-detail-label">Report Number</div>
          <div class="mc-detail-value">${esc(c.report_number)}</div>
        </div>
        <div class="mc-detail-row">
          <div class="mc-detail-label">Date</div>
          <div class="mc-detail-value">${esc(date)}</div>
        </div>
        <div class="mc-detail-row">
          <div class="mc-detail-label">Concern</div>
          <div class="mc-detail-value">${esc(c.concern_type)}</div>
        </div>
        <div class="mc-detail-row">
          <div class="mc-detail-label">Department</div>
          <div class="mc-detail-value">${esc(c.department)}</div>
        </div>
        <div class="mc-detail-row">
          <div class="mc-detail-label">Status</div>
          <div class="mc-detail-value mc-detail-value--badge">${statusBadge(c.status)}</div>
        </div>
        <div class="mc-detail-block mt-3">
          <div class="mc-detail-block-title">
            <i data-lucide="map-pin" class="lucide"></i>
            <span>Location</span>
          </div>
          <div class="mc-detail-block-text">
            <div class="fw-semibold">${esc(c.street)}, ${esc(c.barangay)}</div>
            <div class="text-muted mt-1"><span class="text-muted small">Landmark:</span> ${esc(c.landmark)}</div>
          </div>
        </div>
        <div class="mc-detail-block mt-3">
          <div class="mc-detail-block-title">
            <i data-lucide="file-text" class="lucide"></i>
            <span>Description</span>
          </div>
          <div class="mc-detail-block-text">${esc(c.description)}</div>
        </div>
      `);
      if (typeof window.__renderLucide === 'function') window.__renderLucide();

      const photos = c.photos || [];
      if (photos.length === 0) {
        $("#photosBox").empty();
        return;
      }
      const items = photos.map(p => {
        const src = (p.startsWith("http") ? p : base + p);
        return `<a class="mc-photo-thumb" href="${esc(src)}" target="_blank" rel="noopener"><img src="${esc(src)}" alt="Evidence photo" loading="lazy" decoding="async"></a>`;
      }).join("");
      $("#photosBox").html(`<div class="mc-detail-block-title mb-2"><i data-lucide="images" class="lucide"></i><span>Photos</span></div><div class="mc-photo-grid">${items}</div>`);
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    })
    .fail(function () {
      $("#detailBox").html("<div class='text-danger'>Failed to load report details.</div>");
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    });
  }

  function loadTimeline() {
    $.getJSON(timelineEndpoint, { id }).done(function(res){
      if (!res || !res.ok) {
        $("#timelineBox").html("<div class='text-danger'>Failed to load timeline.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        return;
      }
      const rows = res.timeline || [];
      if (rows.length === 0) {
        $("#timelineBox").html("<div class='text-muted'>No updates yet.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        return;
      }
      const iconMap = {
        New: "mail-plus",
        Ongoing: "loader-2",
        Acknowledge: "eye",
        Completed: "check-circle-2",
        Cancelled: "ban"
      };
      const html = rows.map(r => {
        const d = r.created_at ? String(r.created_at).slice(0, 19).replace("T"," ") : "";
        const icon = iconMap[r.status] || "circle";
        return `<div class="mc-tl-item">
          <div class="mc-tl-icon"><i data-lucide="${icon}" class="lucide"></i></div>
          <div class="mc-tl-body">
            <div class="mc-tl-status">${esc(r.status)}</div>
            <div class="mc-tl-meta">${esc(d)}${r.note ? " · " + esc(r.note) : ""}</div>
          </div>
        </div>`;
      }).join("");
      $("#timelineBox").html(html);
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    })
    .fail(function () {
      $("#timelineBox").html("<div class='text-danger'>Failed to load timeline.</div>");
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    });
  }

  function renderChat(rows) {
    const html = rows.map(r => {
      const mine = r.sender === "user";
      const d = r.created_at ? String(r.created_at).slice(11, 19) : "";
      const roleLabel = mine ? "You" : "Admin";
      const roleIcon = mine ? "user" : "shield-check";
      const bubbleCls = mine ? "bg-primary text-white" : "bg-light border";
      const metaCls = mine ? "text-white-50" : "text-muted";
      const contentAlign = mine ? "align-items:flex-end" : "align-items:flex-start";
      return `<div class="d-flex ${mine ? "justify-content-end" : "justify-content-start"}" style="gap:12px;margin-bottom:18px;">
        ${mine ? "" : `<div class="mc-chat-avatar mc-chat-avatar-admin"><i data-lucide="${roleIcon}" class="lucide lucide-16"></i></div>`}
        <div style="max-width:calc(100% - 52px);display:flex;flex-direction:column;${contentAlign};gap:6px;">
          <div class="small ${metaCls}" style="font-weight:600;display:flex;align-items:center;gap:6px;">
            ${mine ? `<span>${roleLabel}</span><div class="mc-chat-avatar mc-chat-avatar-me" style="width:28px;height:28px;margin:0;"><i data-lucide="${roleIcon}" class="lucide lucide-16"></i></div>` : `<span>${roleLabel}</span>`}
          </div>
          <div class="p-3 rounded ${bubbleCls}" style="max-width:280px;">
            <div style="margin-bottom:6px;">${esc(r.message)}</div>
            <div class="small ${metaCls} text-end">${esc(d)}</div>
          </div>
        </div>
      </div>`;
    }).join("");
    $("#chatBody").html(html || "<div class='text-muted'>No messages yet.</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    const el = document.getElementById("chatBody");
    el.scrollTop = el.scrollHeight;
  }

  function loadMessages() {
    return $.getJSON(messagesEndpoint, { id }).done(function(res){
      if (!res || !res.ok) {
        renderChat([]);
        return;
      }
      renderChat(res.messages || []);
    });
  }

  const modal = new bootstrap.Modal(document.getElementById("chatModal"));

  $("#openChatBtn").on("click", function () {
    modal.show();
    loadMessages();
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
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

  loadDetail();
  loadTimeline();
});
</script>
HTML;

$pageScripts = str_replace(
    ["__ID__", "__BASE__", "__DETAIL_ENDPOINT__", "__TIMELINE_ENDPOINT__", "__MESSAGES_ENDPOINT__", "__POST_MESSAGE_ENDPOINT__"],
    [(string) $concernId, $baseUrl, $detailEndpoint, $timelineEndpoint, $messagesEndpoint, $postMessageEndpoint],
    $pageScripts
);

require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
