<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

require_login();

$mysqli = null;
try {
    $mysqli = db();
} catch (Throwable $e) {
    _auth_diag('ba_notifications_db_connect_failed', ['error' => $e->getMessage()]);
    $_SESSION['flash_error'] = 'Temporarily unable to load notifications due to a database connection issue. Please try again in a moment.';
    redirect(app_url('/public/dashboard.php'));
}

$user = current_user($mysqli);
if (!is_array($user)) {
    logout_user();
    $_SESSION['flash_error'] = 'Your session is no longer valid. Please log in again.';
    redirect(app_url('/public/login.php'));
}

$pageTitle = 'Notifications';
$activeNav = 'ba_notifications';

$userId = (int) $user['id'];
$unreadOnly = isset($_GET['unread']) ? (bool) $_GET['unread'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['mark_all'])) {
        try {
            ba_mark_all_notifications_read($mysqli, $userId);
        } catch (Throwable $e) {
            _auth_diag('ba_notifications_mark_all_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            $_SESSION['flash_error'] = 'Could not mark all notifications as read right now. Please try again.';
        }
        redirect(app_url('/public/ba_notifications.php'));
    }
}

try {
    $notifications = ba_list_notifications_for_user($mysqli, $userId, $unreadOnly, 200);
} catch (Throwable $e) {
    _auth_diag('ba_notifications_list_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
    $notifications = [];
    $_SESSION['flash_error'] = 'Could not load notifications right now. Please try again in a moment.';
}

try {
    $unreadCount = ba_count_unread_notifications($mysqli, $userId);
} catch (Throwable $e) {
    _auth_diag('ba_notifications_count_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
    $unreadCount = 0;
}

ob_start();
csrf_header_meta();
$csrfMetaHtml = (string) ob_get_clean();

ob_start();
csrf_field();
$csrfFieldHtml = (string) ob_get_clean();

$csrfTokenValue = '';
if (function_exists('csrf_token')) {
    $csrfTokenValue = (string) csrf_token();
} elseif (isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) {
    $csrfTokenValue = $_SESSION['csrf_token'];
}

require_once __DIR__ . '/../includes/partials/head.php';
echo $csrfMetaHtml;
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$actionsEndpoint = e(app_url('/api/basuraalert_actions.php'));
$csrf = $csrfFieldHtml;
$visibleCount = count($notifications);
?>

<div class="mc-ba-schedule-page">

<div class="mc-ba-sched-hero">
    <div class="mc-ba-sched-hero-inner">
        <div class="mc-ba-sched-hero-title-row">
            <div class="mc-ba-sched-hero-icon-wrap">
                <i data-lucide="bell-ring" class="lucide lucide-24"></i>
            </div>
            <div class="mc-ba-sched-hero-title">
                <h1 class="mb-0">Notifications &amp; Reminders</h1>
                <p class="mb-0 text-muted" style="margin-top:2px;">
                    <span id="baNotifUnreadCount"><?= $unreadCount ?></span> unread
                    · <span id="baNotifVisibleCount"><?= $visibleCount ?></span> shown
                    <?php if ($unreadOnly) : ?> · <span class="badge bg-warning text-dark">Showing unread only</span><?php endif; ?>
                </p>
            </div>
        </div>
        <div class="mc-ba-sched-hero-brand mc-notif-hero-actions">
            <div class="mc-notif-tabs mb-2 d-flex gap-2 align-items-center justify-content-md-end flex-wrap">
                <a class="btn btn-outline-secondary mc-notif-tab<?= !$unreadOnly ? ' active' : '' ?>" href="<?= e(app_url('/public/ba_notifications.php')) ?>">All</a>
                <a class="btn btn-outline-secondary mc-notif-tab<?= $unreadOnly ? ' active' : '' ?>" href="<?= e(app_url('/public/ba_notifications.php?unread=1')) ?>">Unread</a>
            </div>
            <div class="d-flex gap-2 align-items-center justify-content-md-end flex-wrap">
                <?php if ($unreadCount > 0) : ?>
                    <form method="POST" onsubmit="return confirm('Mark all as read?');" class="d-inline m-0"><?= csrf_field() ?><input type="hidden" name="mark_all" value="1"><button type="submit" class="btn btn-primary mc-notif-cta"><i data-lucide="check-check" class="lucide lucide-16"></i><span>Mark all read</span></button></form>
                <?php endif; ?>
                <?php if ($visibleCount > 0) : ?>
                    <button type="button" id="baDelAllBtn" class="btn btn-outline-danger mc-notif-cta-danger" data-only-unread="<?= $unreadOnly ? '1' : '0' ?>" data-count="<?= $visibleCount ?>">
                        <i data-lucide="trash-2" class="lucide lucide-16"></i><span>Delete <?= $unreadOnly ? 'unread' : 'all' ?> (<?= $visibleCount ?>)</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="mc-notification-cards-wrap mb-4" id="baNotifListWrap">
    <?php if (count($notifications) === 0) : ?>
        <div class="mc-neo-card text-muted text-center py-5" id="baNotifEmptyState">
            <div class="display-6 opacity-50 mb-2"><i data-lucide="bell" class="lucide-24"></i></div>
            <div id="baNotifEmptyHead" class="fw-semibold">No notifications yet.</div>
            <div class="small mt-1 text-muted">Collection reminders and schedule-change alerts will appear here.</div>
        </div>
    <?php else : ?>
        <div class="mc-notif-cards-list" id="baNotifList">
        <?php foreach ($notifications as $n) :
            $isUnread = empty($n['is_read']);
            $typeRaw = (string) ($n['type'] ?? 'notice');
            $typeSafe = e($typeRaw);
            $typeDisplay = e(ucwords(str_replace('_', ' ', $typeRaw)));
            $nid = (int) $n['id'];
        ?>
            <div class="mc-notif-card list-group-item list-group-item-action<?= $isUnread ? ' ba-notif-unread' : ' mc-notif-row-read' ?>" data-id="<?= $nid ?>" data-is-unread="<?= $isUnread ? '1' : '0' ?>">
                <div class="mc-notif-icon-wrap mc-notif-icon--<?= $typeSafe ?>">
                    <?php
                        switch ($typeRaw) {
                            case 'report_update':
                                $ico = 'clipboard-check'; break;
                            case 'schedule_change':
                                $ico = 'calendar-clock'; break;
                            case 'reminder':
                                $ico = 'bell-ring'; break;
                            case 'announcement':
                                $ico = 'megaphone'; break;
                            case 'report_submit':
                                $ico = 'file-up'; break;
                            case 'service_disruption':
                                $ico = 'alert-triangle'; break;
                            default:
                                $ico = 'bell';
                        }
                    ?>
                    <i data-lucide="<?= $ico ?>" class="lucide lucide-22 mc-notif-icon-svg"></i>
                    <?php if ($isUnread) : ?><span class="mc-notif-unread-mini-dot" title="Unread"></span><?php endif; ?>
                </div>
                <div class="mc-notif-card-main flex-grow-1 min-width-0">
                    <div class="mc-notif-card-top d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge mc-notif-type-badge"><?= $typeDisplay ?></span>
                            <span class="mc-notif-unread-pill">New</span>
                        </div>
                        <div class="d-flex align-items-center gap-3 flex-shrink-0">
                            <div class="mc-notif-time"><?= date('M j g:i A', strtotime((string) $n['created_at'])) ?></div>
                            <button type="button" class="ba-del-notif btn btn-outline-danger mc-notif-del" data-id="<?= $nid ?>" title="Delete notification" aria-label="Delete notification">
                                <i data-lucide="trash-2" class="lucide lucide-14"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mc-notif-title mb-2"><?= e((string) $n['title']) ?></div>
                    <div class="mc-notif-message mb-2"><?= e((string) $n['message']) ?></div>
                    <div class="mc-notif-meta">
                        Channel: <strong><?= e((string) ($n['channel'] ?? 'in_app')) ?></strong>
                        · Delivery: <span class="mc-notif-delivery-badge"><?= e((string) ($n['delivery_status'] ?? '')) ?></span>
                        <?php if (!empty($n['delivery_note'])) : ?>· <span><?= e((string) $n['delivery_note']) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>

<?php
$pageScripts = <<<'HTML'
<style>
.ba-del-notif.is-deleting {
  position: relative;
  color: transparent !important;
  pointer-events: none;
  opacity: 0.85;
}
.ba-del-notif.is-deleting::after,
#baDelAllBtn.is-deleting::after {
  content: "";
  position: absolute;
  inset: 0;
  margin: auto;
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: baSpinner 0.75s linear infinite;
  color: var(--ba-danger) !important;
}
#baDelAllBtn.is-deleting {
  position: relative;
  color: transparent !important;
  border-color: transparent !important;
  background-color: var(--ba-danger-light) !important;
  pointer-events: none;
  min-width: 130px;
}
@keyframes baSpinner {
  to { transform: rotate(360deg); }
}
#baNotifList.ba-notif-bulk-delete .list-group-item {
  transition: opacity .22s ease, transform .22s ease, max-height .28s ease, padding .28s ease, margin .28s ease, border .28s ease;
}
</style>
<script>
(function () {
  const endpoint = "__ENDPOINT__";
  const csrfToken = "__CSRFTOKEN__";
  const initialOnlyUnread = __ONLY_UNREAD__;
  function escapeHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" })[c];
    });
  }
  function updateCounters(deltaVisible, deltaUnread) {
    const vEl = document.getElementById("baNotifVisibleCount");
    const uEl = document.getElementById("baNotifUnreadCount");
    if (vEl) {
      const cur = parseInt(vEl.textContent || "0", 10);
      const next = Math.max(0, cur + (isFinite(deltaVisible) ? deltaVisible : 0));
      vEl.textContent = String(next);
    }
    if (uEl && isFinite(deltaUnread)) {
      const cur = parseInt(uEl.textContent || "0", 10);
      const next = Math.max(0, cur + deltaUnread);
      uEl.textContent = String(next);
    }
    const delAll = document.getElementById("baDelAllBtn");
    if (delAll) {
      const vCountEl = document.getElementById("baNotifVisibleCount");
      const vc = vCountEl ? parseInt(vCountEl.textContent || "0", 10) : 0;
      if (vc <= 0) { delAll.style.display = "none"; }
      else {
        const scope = initialOnlyUnread ? "unread" : "all";
        const spanIconI = delAll.querySelector("i");
        const spanTxt = delAll.querySelector("span");
        if (spanTxt) spanTxt.textContent = "Delete " + scope + " (" + vc + ")";
        else delAll.textContent = "Delete " + scope + " (" + vc + ")";
        delAll.setAttribute("data-count", String(vc));
      }
    }
  }
  function maybeShowEmpty(successMsg) {
    const list = document.getElementById("baNotifList");
    const wrap = document.getElementById("baNotifListWrap");
    if (!wrap) return;
    const remainingRows = list ? list.querySelectorAll(".list-group-item") : [];
    if (!remainingRows || remainingRows.length === 0) {
      let emptyEl = document.getElementById("baNotifEmptyState");
      if (!emptyEl) {
        emptyEl = document.createElement("div");
        emptyEl.id = "baNotifEmptyState";
        emptyEl.className = "text-muted text-center py-6";
        emptyEl.innerHTML = '<div class="display-6 opacity-50 mb-2"><i data-lucide="bell" class="lucide-14"></i></div><div id="baNotifEmptyHead">All clear — no notifications.</div><div class="small mt-1" id="baNotifEmptySub">New reminders, announcements, and report updates will appear here.</div>';
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        wrap.appendChild(emptyEl);
      }
      const hd = document.getElementById("baNotifEmptyHead");
      const sb = document.getElementById("baNotifEmptySub");
      if (successMsg && hd) { hd.textContent = String(successMsg); if (sb) sb.textContent = "Deleted notifications cannot be recovered."; }
    }
  }
  function wireDeleteAll() {
    const btn = document.getElementById("baDelAllBtn");
    if (!btn) return;
    btn.addEventListener("click", function (ev) {
      if (ev) { ev.preventDefault(); ev.stopPropagation(); }
      if (btn.classList.contains("is-deleting")) return;
      const onlyUnread = (btn.getAttribute("data-only-unread") || "0") === "1";
      const count = parseInt(btn.getAttribute("data-count") || "0", 10);
      if (count <= 0) { alert("No notifications to delete here."); return; }
      const scope = onlyUnread ? "ONLY the " + count + " UNREAD notification" + (count === 1 ? "" : "s") + " currently shown" : "ALL " + count + " notification" + (count === 1 ? "" : "s");
      const msg = "Are you sure you want to delete " + scope + "?\n\nThis cannot be undone — there is no trash or undo.\n\nType 'DELETE' in the next prompt to confirm.";
      if (!window.confirm(msg)) return;
      const typed = window.prompt("Type DELETE (all caps) to confirm deleting " + count + " " + (onlyUnread ? "unread " : "") + "notification" + (count === 1 ? "" : "s") + ":", "");
      if (typed !== "DELETE") return;
      btn.classList.add("is-deleting");
      btn.setAttribute("aria-busy", "true");
      $.ajax({
        url: endpoint,
        method: "POST",
        data: { action: "delete_all_notifications", only_unread: onlyUnread ? "1" : "0", csrf_token: csrfToken },
        dataType: "json",
        timeout: 60000
      }).done(function (r) {
        if (r && r.ok) {
          const list = document.getElementById("baNotifList");
          const rows = list ? list.querySelectorAll(".list-group-item") : [];
          let removedUnread = 0;
          rows.forEach(function (row) {
            if (onlyUnread) {
              const isU = (row.getAttribute("data-is-unread") || "0") === "1";
              if (!isU) return;
              if (isU) removedUnread++;
            } else {
              if ((row.getAttribute("data-is-unread") || "0") === "1") removedUnread++;
            }
          });
          const deletedCount = isFinite(parseInt(String(r.deleted_count || "0"), 10)) ? parseInt(String(r.deleted_count || "0"), 10) : (onlyUnread ? removedUnread : rows.length);
          const delVisible = onlyUnread ? removedUnread : rows.length;
          updateCounters(-delVisible, -removedUnread);
          if (list) {
            list.classList.add("ba-notif-bulk-delete");
            if (onlyUnread) {
              rows.forEach(function (row) {
                const isU = (row.getAttribute("data-is-unread") || "0") === "1";
                if (!isU) return;
                row.style.transition = "opacity .18s ease";
                row.style.opacity = "0";
                setTimeout(function () {
                  if (!row || !row.parentNode) return;
                  row.style.transition = "max-height,padding,margin,border .2s ease";
                  row.style.overflow = "hidden";
                  row.style.maxHeight = row.offsetHeight + "px";
                  requestAnimationFrame(function () {
                    row.style.maxHeight = "0";
                    row.style.padding = "0";
                    row.style.margin = "0";
                    row.style.borderWidth = "0";
                    setTimeout(function () { if (row && row.parentNode) row.parentNode.removeChild(row); maybeShowEmpty(r && r.message ? r.message : null); }, 220);
                  });
                }, 160);
              });
            } else {
              rows.forEach(function (row) {
                row.style.opacity = "0";
              });
              setTimeout(function () {
                if (list && list.parentNode) list.parentNode.removeChild(list);
                maybeShowEmpty(r && r.message ? r.message : null);
              }, 220);
            }
          }
        } else {
          btn.classList.remove("is-deleting");
          btn.removeAttribute("aria-busy");
          const err = (r && (r.error || r.hint))
            ? (r.error ? r.error : "") + (r.error && r.hint ? " · " : "") + (r.hint ? r.hint : "")
            : "Failed to delete notifications.";
          alert(err);
        }
      }).fail(function (jqXHR) {
        btn.classList.remove("is-deleting");
        btn.removeAttribute("aria-busy");
        let msg = "Network error while deleting notifications.";
        try {
          if (jqXHR && jqXHR.responseText) {
            const d = JSON.parse(jqXHR.responseText);
            if (d && (d.error || d.hint)) {
              msg = (d.error || "") + (d.error && d.hint ? " · " : "") + (d.hint || "");
            } else if (jqXHR.status) {
              msg = msg + " (HTTP " + jqXHR.status + ")";
            }
          } else if (jqXHR && jqXHR.status) {
            msg = msg + " (HTTP " + jqXHR.status + ")";
          }
        } catch (ignore) {}
        alert(msg);
      });
    });
  }
  wireDeleteAll();
  function isDeleteBtnEl(el) {
    return !!(el && el.nodeType === 1 && (el.classList.contains("ba-del-notif") || el.closest(".ba-del-notif")));
  }
  function getDeleteBtn(el) {
    if (!el || el.nodeType !== 1) return null;
    if (el.classList.contains("ba-del-notif")) return el;
    return el.closest ? el.closest(".ba-del-notif") : null;
  }
  function markRowRead(row) {
    if (!row) return;
    const id = parseInt(row.getAttribute("data-id") || "0", 10);
    if (!id || !row.classList.contains("ba-notif-unread")) return;
    $.ajax({
      url: endpoint,
      method: "POST",
      data: { action: "mark_notifications_read", id: id, csrf_token: csrfToken },
      dataType: "json",
      timeout: 15000
    }).done(function () {
      row.classList.remove("ba-notif-unread");
      row.classList.add("mc-notif-row-read");
      const dot = row.querySelector(".mc-notif-unread-mini-dot");
      if (dot) dot.remove();
      const uEl = document.getElementById("baNotifUnreadCount");
      if (uEl) {
        const cur = parseInt(uEl.textContent || "0", 10);
        uEl.textContent = String(Math.max(0, cur - 1));
      }
    });
  }
  document.addEventListener("click", function (ev) {
    const btn = getDeleteBtn(ev && ev.target);
    if (btn) {
      if (ev) { ev.preventDefault(); ev.stopPropagation(); }
      if (btn.classList.contains("is-deleting")) return;
      const id = parseInt(btn.getAttribute("data-id") || "0", 10);
      if (!id) return;
      const row = btn.closest(".list-group-item");
      if (!window.confirm("Delete this notification? This cannot be undone.")) return;
      btn.classList.add("is-deleting");
      btn.setAttribute("aria-busy", "true");
      $.ajax({
        url: endpoint,
        method: "POST",
        data: { action: "delete_notification", id: id, csrf_token: csrfToken },
        dataType: "json",
        timeout: 20000
      }).done(function (r) {
        if (r && r.ok) {
          if (row) {
            const wasUnread = (row.getAttribute("data-is-unread") || "0") === "1";
            updateCounters(-1, wasUnread ? -1 : 0);
            row.style.transition = "opacity .18s ease";
            row.style.opacity = "0";
            setTimeout(function () {
              if (!row || !row.parentNode) return;
              row.style.transition = "max-height,padding,margin,border .18s ease";
              row.style.overflow = "hidden";
              row.style.maxHeight = row.offsetHeight + "px";
              requestAnimationFrame(function () {
                row.style.maxHeight = "0";
                row.style.paddingTop = "0";
                row.style.paddingBottom = "0";
                row.style.marginTop = "0";
                row.style.marginBottom = "0";
                row.style.borderWidth = "0";
                setTimeout(function () {
                  if (row && row.parentNode) row.parentNode.removeChild(row);
                  maybeShowEmpty();
                }, 200);
              });
            }, 160);
          }
        } else {
          btn.classList.remove("is-deleting");
          btn.removeAttribute("aria-busy");
          const err = (r && (r.error || r.hint))
            ? (r.error ? r.error : "") + (r.error && r.hint ? " · " : "") + (r.hint ? r.hint : "")
            : "Failed to delete notification.";
          alert(err);
        }
      }).fail(function (jqXHR) {
        btn.classList.remove("is-deleting");
        btn.removeAttribute("aria-busy");
        let msg = "Network error while deleting notification.";
        try {
          if (jqXHR && jqXHR.responseText) {
            const d = JSON.parse(jqXHR.responseText);
            if (d && (d.error || d.hint)) {
              msg = (d.error || "") + (d.error && d.hint ? " · " : "") + (d.hint || "");
            } else if (jqXHR.status) {
              msg = msg + " (HTTP " + jqXHR.status + ")";
            }
          } else if (jqXHR && jqXHR.status) {
            msg = msg + " (HTTP " + jqXHR.status + ")";
          }
        } catch (ignore) {}
        alert(msg);
      });
      return;
    }
    const row = ev && ev.target ? ev.target.closest(".ba-notif-unread") : null;
    if (!row) return;
    if (ev && ev.target && isDeleteBtnEl(ev.target)) return;
    markRowRead(row);
  });
})();
</script>
HTML;
$pageScripts = str_replace("__ENDPOINT__", $actionsEndpoint, $pageScripts);
$pageScripts = str_replace("__CSRFTOKEN__", $csrfTokenValue, $pageScripts);
$pageScripts = str_replace("__ONLY_UNREAD__", $unreadOnly ? 'true' : 'false', $pageScripts);

require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
