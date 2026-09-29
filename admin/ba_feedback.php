<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

csrf_check();

$pageTitle = 'Feedback & FAQ';
$activeNav = 'ba_feedback';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

$endpointRaw = app_url('/admin/api/basuraalert_admin.php');
echo csrf_header_meta();

/* ============================================================
   TAB SELECTION
   ?tab=feedback (default) | ?tab=faq
   The only inbound links are admin_shell_start.php and
   ba_dashboard.php, both parameter-free, so this is the single
   supported deep-link surface.
   ============================================================ */
$tab = (isset($_GET['tab']) && $_GET['tab'] === 'faq') ? 'faq' : 'feedback';

/* ============================================================
   DATA
   Both queries list explicit columns (no SELECT *) so the page
   cannot accidentally start rendering a future column, and the
   500-row cap is surfaced in the UI rather than silently applied.
   ============================================================ */
$feedback = [];
try {
    $res = $mysqli->query(
        'SELECT f.id, f.user_id, f.kind, f.subject, f.message, f.photos_json, f.reply, f.replied_at, f.created_at,
                COALESCE(CONCAT(u.first_name, " ", u.last_name), "") AS uname,
                COALESCE(u.email, "") AS uemail,
                COALESCE(u.mobile, "") AS umobile
         FROM ba_feedback f
         LEFT JOIN users u ON u.id = f.user_id
         ORDER BY f.id DESC
         LIMIT 500'
    );
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $feedback[] = $r;
        }
        $res->free();
    }
} catch (Throwable $e) {
    $feedback = [];
}

$faqs = [];
try {
    $res = $mysqli->query(
        'SELECT f.id, f.question, f.answer, f.category, f.sort_order, f.status
         FROM ba_faqs f
         ORDER BY f.sort_order ASC, f.id ASC'
    );
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $faqs[] = $r;
        }
        $res->free();
    }
} catch (Throwable $e) {
    $faqs = [];
}

/* ============================================================
   KPI TOTALS
   Derived from the rows already in memory - no extra round trip.
   ============================================================ */
$fbTotal     = count($feedback);
$fbUnreplied = 0;
$fbWithPhotos = 0;
$kindCounts  = [];
foreach ($feedback as $_f) {
    if (trim((string) ($_f['reply'] ?? '')) === '') {
        $fbUnreplied++;
    }
    $pj = trim((string) ($_f['photos_json'] ?? ''));
    if ($pj !== '' && $pj !== '[]' && $pj !== 'null') {
        $fbWithPhotos++;
    }
    $kk = (string) ($_f['kind'] ?? 'Feedback');
    $kindCounts[$kk] = ($kindCounts[$kk] ?? 0) + 1;
}
$fbReplied = $fbTotal - $fbUnreplied;

$faqTotal     = count($faqs);
$faqPublished = 0;
foreach ($faqs as $_f) {
    if ((string) ($_f['status'] ?? '') === 'Published') {
        $faqPublished++;
    }
}

/* Presentation maps, derived entirely from the existing ENUM columns. */
$kindIcon = [
    'Feedback'       => 'message-square',
    'Contact_Us'     => 'phone',
    'FAQ_Suggestion' => 'lightbulb',
];
$kindBadge = [
    'Feedback'       => 'mc-admin-badge--new',
    'Contact_Us'     => 'mc-admin-badge--muted',
    'FAQ_Suggestion' => 'mc-admin-badge--warning',
];
$kindLabel = [
    'Feedback'       => 'Feedback',
    'Contact_Us'     => 'Contact Us',
    'FAQ_Suggestion' => 'FAQ Suggestion',
];

/* Filter chips for the Kind facet. Seeded from the known ENUM so the
   row is stable, then extended with any unexpected value already in
   the table so nothing becomes unreachable. */
$kindOptions = ['Feedback', 'Contact_Us', 'FAQ_Suggestion'];
foreach (array_keys($kindCounts) as $_k) {
    if (!in_array($_k, $kindOptions, true)) {
        $kindOptions[] = (string) $_k;
    }
}

/* FAQ category options: the documented set plus any value already
   stored, so editing an old row can never silently blank it. */
$faqCategories = ['General', 'Schedules', 'Segregation', 'Notifications', 'Reports', 'Other'];
foreach ($faqs as $_f) {
    $c = trim((string) ($_f['category'] ?? ''));
    if ($c !== '' && !in_array($c, $faqCategories, true)) {
        $faqCategories[] = $c;
    }
}

$selfBase = app_url('/admin/ba_feedback.php');
$tabUrl   = static fn(string $t): string => e($selfBase . '?tab=' . $t);
?>
<div class="mc-admin-ba_feedback-page">

    <!-- ==================== HERO ==================== -->
    <div class="mc-admin-concerns-hero">
        <div class="mc-admin-concerns-hero-inner">
            <div class="mc-admin-concerns-title-row">
                <div class="mc-admin-concerns-icon-wrap">
                    <i data-lucide="message-circle-question" class="lucide"></i>
                </div>
                <div class="mc-admin-concerns-title">
                    <h1>Feedback &amp; FAQ</h1>
                    <p>Reply to resident feedback and contact messages, and maintain the public FAQ entries.</p>
                </div>
            </div>
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>"
                 alt="BasuraAlert module logo" class="ba-fb-hero-seal"
                 loading="eager" decoding="async">
        </div>
    </div>

    <!-- ==================== TABS ==================== -->
    <div class="mc-admin-concerns-tabs" role="tablist" aria-label="Feedback and FAQ sections">
        <a class="mc-admin-concerns-chip<?= $tab === 'feedback' ? ' active' : '' ?>"
           href="<?= $tabUrl('feedback') ?>" role="tab" aria-selected="<?= $tab === 'feedback' ? 'true' : 'false' ?>">
            <i data-lucide="messages-square" class="lucide"></i>
            <span>Feedback / Contact Messages</span>
            <span class="ba-fb-chip-count"><?= $fbUnreplied ?></span>
        </a>
        <a class="mc-admin-concerns-chip<?= $tab === 'faq' ? ' active' : '' ?>"
           href="<?= $tabUrl('faq') ?>" role="tab" aria-selected="<?= $tab === 'faq' ? 'true' : 'false' ?>">
            <i data-lucide="help-circle" class="lucide"></i>
            <span>FAQ Entries</span>
            <span class="ba-fb-chip-count"><?= $faqTotal ?></span>
        </a>
    </div>

<?php if ($tab === 'feedback') : ?>

    <!-- ==================== KPI TILES ==================== -->
    <div class="mc-admin-kpi-grid">
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--blue"><i data-lucide="messages-square" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">Total Messages</p>
            <div class="mc-admin-kpi-value" id="kpiTotal"><?= $fbTotal ?></div>
            <p class="mc-admin-kpi-sub"><i data-lucide="inbox" class="lucide"></i> Most recent <?= $fbTotal ?> shown</p>
        </div>
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--amber"><i data-lucide="circle-alert" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">Awaiting Reply</p>
            <div class="mc-admin-kpi-value" id="kpiUnreplied"><?= $fbUnreplied ?></div>
            <p class="mc-admin-kpi-sub"><i data-lucide="clock" class="lucide"></i> Needs an admin response</p>
        </div>
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--mint"><i data-lucide="circle-check" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">Answered</p>
            <div class="mc-admin-kpi-value" id="kpiReplied"><?= $fbReplied ?></div>
            <p class="mc-admin-kpi-sub"><i data-lucide="check" class="lucide"></i> Resident already notified</p>
        </div>
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--blue"><i data-lucide="image" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label">With Photo Evidence</p>
            <div class="mc-admin-kpi-value" id="kpiPhotos"><?= $fbWithPhotos ?></div>
            <p class="mc-admin-kpi-sub"><i data-lucide="camera" class="lucide"></i> Attached by the resident</p>
        </div>
    </div>

    <!-- ==================== TOOLBAR ==================== -->
    <div class="mc-admin-section-card mb-3">
        <div class="mc-admin-section-body">
            <div class="ba-fb-toolbar">
                <div class="ba-fb-search">
                    <i data-lucide="search" class="lucide-search"></i>
                    <input type="search" id="fbSearch" class="form-control"
                           placeholder="Search subject, sender, or message text…"
                           autocomplete="off" aria-label="Search feedback messages">
                    <button class="ba-fb-search-clear" type="button" id="fbSearchClear" aria-label="Clear search">
                        <i data-lucide="x" class="lucide-14"></i>
                    </button>
                </div>
                <div class="ba-fb-chip-group" role="group" aria-label="Filter by type">
                    <button class="ba-fb-chip" type="button" data-facet="kind" data-value="all" aria-pressed="true">
                        <i data-lucide="layers" class="lucide"></i><span>All Types</span>
                    </button>
                    <?php foreach ($kindOptions as $_ko) : ?>
                        <button class="ba-fb-chip" type="button" data-facet="kind" data-value="<?= e((string) $_ko) ?>" aria-pressed="false">
                            <i data-lucide="<?= e($kindIcon[(string) $_ko] ?? 'message-square') ?>" class="lucide"></i>
                            <span><?= e($kindLabel[(string) $_ko] ?? ucfirst(str_replace('_', ' ', (string) $_ko))) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="ba-fb-chip-group" role="group" aria-label="Filter by reply status">
                    <button class="ba-fb-chip" type="button" data-facet="status" data-value="all" aria-pressed="true">All</button>
                    <button class="ba-fb-chip" type="button" data-facet="status" data-value="unreplied" aria-pressed="false">
                        <i data-lucide="circle-alert" class="lucide"></i><span>Awaiting Reply</span>
                    </button>
                    <button class="ba-fb-chip" type="button" data-facet="status" data-value="replied" aria-pressed="false">
                        <i data-lucide="circle-check" class="lucide"></i><span>Answered</span>
                    </button>
                </div>
            </div>
            <p class="ba-fb-result-line" id="fbResultLine" role="status" aria-live="polite">
                <i data-lucide="list-filter" class="lucide-14"></i>
                <span>Showing <strong id="fbShowing"><?= $fbTotal ?></strong> of <strong><?= $fbTotal ?></strong> messages</span>
            </p>
        </div>
    </div>

    <!-- ==================== MASTER / DETAIL ==================== -->
    <div class="ba-fb-layout">

        <!-- ---------- Message list ---------- -->
        <div>
            <?php if ($fbTotal === 0) : ?>
                <div class="mc-admin-section-card">
                    <div class="mc-admin-empty">
                        <div class="mc-admin-empty-icon"><i data-lucide="message-square-off" class="lucide"></i></div>
                        <h4>No feedback yet</h4>
                        <p>Resident messages sent from the app's Feedback and Contact Us forms will appear here.</p>
                    </div>
                </div>
            <?php else : ?>
                <div class="ba-fb-list" id="fbList" role="listbox" aria-label="Resident feedback messages">
                <?php
                $fbRow = 0;
                foreach ($feedback as $_f) :
                    $fbRow++;
                    $kind     = (string) ($_f['kind'] ?? 'Feedback');
                    $subject  = (string) ($_f['subject'] ?? '');
                    $senderNm = trim((string) ($_f['uname'] ?? ''));
                    $senderNm = $senderNm !== '' ? $senderNm : 'Anonymous';
                    $uEmail   = (string) ($_f['uemail'] ?? '');
                    $uMobile  = (string) ($_f['umobile'] ?? '');
                    $message  = (string) ($_f['message'] ?? '');
                    $replyTxt = (string) ($_f['reply'] ?? '');
                    $replied  = trim($replyTxt) !== '';
                    $created  = date('M j, Y g:i A', strtotime((string) ($_f['created_at'] ?? 'now')));
                    $replAt   = !empty($_f['replied_at']) ? date('M j, Y g:i A', strtotime((string) $_f['replied_at'])) : '';

                    /* photos_json is resident-supplied: keep only plain
                       strings so the render loop can never hit a nested
                       array and fatal inside ba_resolve_photo_url(). */
                    $photos = [];
                    if (!empty($_f['photos_json'])) {
                        $decoded = json_decode((string) $_f['photos_json'], true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $ph) {
                                if (!is_string($ph)) {
                                    continue;
                                }
                                $phUrl = ba_resolve_photo_url($ph);
                                if ($phUrl !== '') {
                                    $photos[] = $phUrl;
                                }
                            }
                        }
                    }

                    /* One JSON blob instead of a dozen data-* attributes:
                       the JS reads it and writes with textContent, so no
                       server value is ever concatenated into innerHTML. */
                    $fbPayload = (string) json_encode([
                        'id'        => (int) $_f['id'],
                        'kind'      => $kind,
                        'kindLabel' => $kindLabel[$kind] ?? ucfirst(str_replace('_', ' ', $kind)),
                        'subject'   => $subject,
                        'uname'     => $senderNm,
                        'uemail'    => $uEmail,
                        'umobile'   => $uMobile,
                        'created'   => $created,
                        'reply'     => $replyTxt,
                        'repliedAt' => $replAt,
                    ], JSON_UNESCAPED_UNICODE);

                    $fbHaystack = mb_strtolower(implode(' ', [$subject, $senderNm, $uEmail, $message, $kind]));
                ?>
                    <article class="ba-fb-item"
                             data-id="<?= (int) $_f['id'] ?>"
                             data-kind="<?= e($kind) ?>"
                             data-status="<?= $replied ? 'replied' : 'unreplied' ?>"
                             data-search="<?= e($fbHaystack) ?>"
                             data-payload="<?= e($fbPayload) ?>"
                             role="option" tabindex="0" aria-selected="false">
                        <div class="ba-fb-item-head">
                            <div>
                                <div class="ba-fb-badges">
                                    <span class="mc-admin-badge <?= e($kindBadge[$kind] ?? 'mc-admin-badge--muted') ?>">
                                        <i data-lucide="<?= e($kindIcon[$kind] ?? 'message-square') ?>" class="lucide"></i>
                                        <?= e($kindLabel[$kind] ?? ucfirst(str_replace('_', ' ', $kind))) ?>
                                    </span>
                                    <span class="mc-admin-badge js-fb-status <?= $replied ? 'mc-admin-badge--success' : 'mc-admin-badge--warning' ?>">
                                        <i data-lucide="<?= $replied ? 'circle-check' : 'circle-alert' ?>" class="lucide"></i>
                                        <span class="js-fb-status-text"><?= $replied ? 'Answered' : 'Awaiting Reply' ?></span>
                                    </span>
                                    <?php if (count($photos) > 0) : ?>
                                        <span class="mc-admin-badge mc-admin-badge--muted" title="<?= count($photos) ?> photo(s) attached">
                                            <i data-lucide="camera" class="lucide"></i><?= count($photos) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="mc-admin-badge mc-admin-badge--new js-fb-replying">
                                        <i data-lucide="pencil" class="lucide"></i> Replying
                                    </span>
                                </div>
                                <h3 class="ba-fb-subject"><?= e($subject) ?></h3>
                            </div>
                            <span class="ba-fb-when"><i data-lucide="clock" class="lucide-14"></i> <?= e($created) ?></span>
                        </div>

                        <p class="ba-fb-sender">
                            <i data-lucide="user" class="lucide-14"></i>
                            <strong><?= e($senderNm) ?></strong>
                            <?php if ($uEmail !== '') : ?><span class="ba-fb-sender-sep">&middot;</span><?= e($uEmail) ?><?php endif; ?>
                            <?php if ($uMobile !== '') : ?><span class="ba-fb-sender-sep">&middot;</span><?= e($uMobile) ?><?php endif; ?>
                        </p>

                        <p class="ba-fb-body"><?= e($message) ?></p>

                        <?php if (count($photos) > 0) : ?>
                            <div class="ba-fb-photos">
                                <p class="ba-fb-photos-label">Attached photos</p>
                                <div class="ba-fb-photo-grid">
                                    <?php foreach ($photos as $phUrl) : ?>
                                        <a class="ba-fb-photo js-fb-photo-link" href="<?= e($phUrl) ?>"
                                           target="_blank" rel="noopener"
                                           aria-label="Open attached photo <?= $fbRow ?> in a new tab">
                                            <img src="<?= e($phUrl) ?>" alt="Photo attached to feedback message <?= $fbRow ?>"
                                                 loading="lazy" decoding="async">
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="js-fb-reply-slot">
                        <?php if ($replied) : ?>
                            <blockquote class="ba-fb-reply-quote">
                                <p class="ba-fb-reply-quote-head">
                                    <i data-lucide="badge-check" class="lucide"></i>
                                    Admin reply<?= $replAt !== '' ? ' &middot; ' . e($replAt) : '' ?>
                                </p>
                                <p class="ba-fb-reply-quote-body"><?= e($replyTxt) ?></p>
                            </blockquote>
                        <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>

                <div class="mc-admin-empty" id="fbZeroState" hidden>
                    <div class="mc-admin-empty-icon"><i data-lucide="search-x" class="lucide"></i></div>
                    <h4>No messages match your filters</h4>
                    <p>Try a different search term, or switch the type and status filters back to <strong>All</strong>.</p>
                </div>

                <?php if ($fbTotal >= 500) : ?>
                    <p class="mc-admin-concerns-info mt-3">
                        <i data-lucide="info" class="lucide"></i>
                        <span>Showing the 500 most recent messages. Older messages are not loaded on this page.</span>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- ---------- Reply panel ---------- -->
        <aside class="ba-fb-reply-panel" id="fbReplyPanel" aria-label="Reply to feedback message">
            <div class="ba-fb-reply-sheet-grip" aria-hidden="true"></div>
            <div class="ba-fb-reply-head">
                <h2><i data-lucide="send" class="lucide"></i> Reply to Message</h2>
                <button class="btn-close ba-fb-reply-close" type="button" id="fbSheetClose" aria-label="Close reply panel"></button>
            </div>
            <div class="ba-fb-reply-body">
                <form id="fbReplyForm" onsubmit="event.preventDefault(); window.__baFbReply ? window.__baFbReply() : null;">
                    <input type="hidden" name="action" value="reply_feedback">
                    <input type="hidden" name="id" id="fbReplyId" value="0">
                    <?= csrf_field() ?>

                    <div class="ba-fb-ctx is-empty" id="fbCtx">
                        <div class="ba-fb-ctx-empty" id="fbCtxEmpty">
                            <i data-lucide="mouse-pointer-click" class="lucide"></i>
                            <span>Select a message to start writing your reply.</span>
                        </div>
                        <div id="fbCtxFilled" hidden>
                            <div class="ba-fb-badges">
                                <span class="mc-admin-badge mc-admin-badge--new" id="fbCtxKind"></span>
                            </div>
                            <p class="ba-fb-ctx-name" id="fbCtxName"></p>
                            <p class="ba-fb-ctx-subject" id="fbCtxSubject"></p>
                            <p class="ba-fb-ctx-meta" id="fbCtxMeta"></p>
                        </div>
                    </div>

                    <div class="mc-admin-form-group">
                        <label class="form-label" for="fbReplyBody">Reply <span class="text-danger">*</span></label>
                        <textarea class="form-control mc-admin-form-textarea" id="fbReplyBody" name="reply" rows="6"
                                  placeholder="Type your response to the resident…"></textarea>
                        <div class="form-text" id="fbReplyCount">0 characters</div>
                    </div>

                    <div id="fbReplyAlert" role="status" aria-live="polite"></div>

                    <div class="ba-fb-reply-actions">
                        <button class="btn btn-outline-secondary" type="button" id="fbClearSel">Clear selection</button>
                        <button class="btn btn-primary" type="submit" id="fbSendBtn">Send Reply</button>
                    </div>

                    <p class="ba-fb-hint">
                        <i data-lucide="info" class="lucide"></i>
                        <span>The reply is stored and pushed as an in-app notification to the resident, plus email and SMS based on their notification preferences.</span>
                    </p>
                </form>
            </div>
        </aside>
    </div>
    <div class="ba-fb-reply-backdrop" id="fbBackdrop" aria-hidden="true"></div>

    <?php
    $pageScripts = <<<'HTML'
<script>
(function(){
  var CFG = __CONFIG__;

  function csrfToken(){
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? String(m.getAttribute('content') || '') : '';
  }
  function esc(s){
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function(c){
      return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c];
    });
  }
  function setAlert(el, type, msg){
    if(!el) return;
    var cls = type === "success" ? "alert-success" : (type === "error" ? "alert-danger" : "alert-info");
    el.className = "alert " + cls + " mb-3";
    el.textContent = String(msg || "");
    el.hidden = false;
  }
  function clearAlert(el){ if(el){ el.hidden = true; el.textContent = ""; } }
  function renderIcons(){ if(typeof window.__renderLucide === "function"){ try{ window.__renderLucide(); }catch(e){} } }

  var panel    = document.getElementById("fbReplyPanel");
  var backdrop = document.getElementById("fbBackdrop");
  var form     = document.getElementById("fbReplyForm");
  var idEl     = document.getElementById("fbReplyId");
  var bodyEl   = document.getElementById("fbReplyBody");
  var countEl  = document.getElementById("fbReplyCount");
  var alertEl  = document.getElementById("fbReplyAlert");
  var sendBtn  = document.getElementById("fbSendBtn");
  var clearBtn = document.getElementById("fbClearSel");
  var ctx      = document.getElementById("fbCtx");
  var ctxEmpty = document.getElementById("fbCtxEmpty");
  var ctxFill  = document.getElementById("fbCtxFilled");
  var ctxKind  = document.getElementById("fbCtxKind");
  var ctxName  = document.getElementById("fbCtxName");
  var ctxSubj  = document.getElementById("fbCtxSubject");
  var ctxMeta  = document.getElementById("fbCtxMeta");

  var items    = Array.prototype.slice.call(document.querySelectorAll(".ba-fb-item"));
  var list     = document.getElementById("fbList");
  var zero     = document.getElementById("fbZeroState");
  var showing  = document.getElementById("fbShowing");
  var kpiUnrep = document.getElementById("kpiUnreplied");
  var kpiRepl  = document.getElementById("kpiReplied");
  var searchEl = document.getElementById("fbSearch");
  var searchClr= document.getElementById("fbSearchClear");

  var selected   = null;
  var facetState = { kind: "all", status: "all" };
  var isSheet    = window.matchMedia && window.matchMedia("(max-width: 991.98px)").matches;

  /* ---- mobile bottom-sheet open/close ---- */
  function openSheet(){
    if(!panel) return;
    panel.classList.add("is-open");
    if(backdrop) backdrop.classList.add("is-open");
    document.body.classList.add("ba-fb-sheet-open");
  }
  function closeSheet(){
    if(!panel) return;
    panel.classList.remove("is-open");
    if(backdrop) backdrop.classList.remove("is-open");
    document.body.classList.remove("ba-fb-sheet-open");
  }
  if(backdrop) backdrop.addEventListener("click", function(){ closeSheet(); if(bodyEl) bodyEl.focus(); });
  var sheetClose = document.getElementById("fbSheetClose");
  if(sheetClose) sheetClose.addEventListener("click", function(){ closeSheet(); });

  /* ---- build one meta chip safely (no innerHTML with server data) ---- */
  function metaItem(icon, text){
    var wrap = document.createElement("span");
    wrap.className = "ba-fb-ctx-meta-item";
    if(icon){
      var i = document.createElement("i");
      i.setAttribute("data-lucide", icon);
      i.className = "lucide lucide-14";
      wrap.appendChild(i);
    }
    var s = document.createElement("span");
    s.textContent = String(text);
    wrap.appendChild(s);
    return wrap;
  }

  function payloadOf(el){
    try { return JSON.parse(String(el.getAttribute("data-payload") || "{}")); }
    catch(e){ return {}; }
  }

  function paintContext(p){
    if(!ctx) return;
    if(!p || !p.id){ ctx.className = "ba-fb-ctx is-empty"; ctxEmpty.hidden = false; ctxFill.hidden = true; return; }
    ctx.className = "ba-fb-ctx is-filled";
    ctxEmpty.hidden = true;
    ctxFill.hidden  = false;

    ctxKind.textContent = String(p.kindLabel || p.kind || "Feedback");
    ctxName.textContent = String(p.uname || "Anonymous");
    ctxSubj.textContent = String(p.subject || "");

    ctxMeta.textContent = "";
    if(p.created) ctxMeta.appendChild(metaItem("clock", p.created));
    if(p.uemail)  ctxMeta.appendChild(metaItem("mail", p.uemail));
    if(p.umobile) ctxMeta.appendChild(metaItem("smartphone", p.umobile));
    if(!ctxMeta.childNodes.length) ctxMeta.appendChild(metaItem("user-round", "No contact details on file"));
    renderIcons();
  }

  function selectItem(el, focusBody){
    if(!el) return;
    if(selected && selected !== el){
      selected.classList.remove("is-selected");
      selected.setAttribute("aria-selected", "false");
    }
    selected = el;
    el.classList.add("is-selected");
    el.setAttribute("aria-selected", "true");

    var p = payloadOf(el);
    if(idEl)   idEl.value = String(p.id || 0);
    if(bodyEl) bodyEl.value = String(p.reply || "");
    updateCount();
    paintContext(p);
    clearAlert(alertEl);
    renderIcons();

    if(isSheet){ openSheet(); }
    else if(focusBody && bodyEl){ try{ bodyEl.focus(); }catch(e){} }
  }

  function clearSelection(){
    if(selected){
      selected.classList.remove("is-selected");
      selected.setAttribute("aria-selected", "false");
    }
    selected = null;
    if(idEl)   idEl.value = "0";
    if(bodyEl) bodyEl.value = "";
    updateCount();
    paintContext(null);
    closeSheet();
  }

  function updateCount(){
    if(!countEl) return;
    var n = bodyEl ? String(bodyEl.value || "").length : 0;
    countEl.textContent = n === 1 ? "1 character" : n + " characters";
  }
  if(bodyEl) bodyEl.addEventListener("input", updateCount);

  /* ---- selection: mouse + keyboard ---- */
  items.forEach(function(el){
    el.addEventListener("click", function(ev){
      /* Clicking a photo link must open the photo, not select the card. */
      if(ev.target && ev.target.closest && ev.target.closest(".js-fb-photo-link")) return;
      selectItem(el, false);
    });
    el.addEventListener("keydown", function(ev){
      if(ev.key === "Enter" || ev.key === " " || ev.key === "Spacebar"){
        ev.preventDefault();
        selectItem(el, true);
      }
    });
  });
  if(clearBtn) clearBtn.addEventListener("click", clearSelection);

  /* ---- filtering ---- */
  function applyFilters(){
    var q = searchEl ? String(searchEl.value || "").trim().toLowerCase() : "";
    if(searchClr) searchClr.classList.toggle("is-visible", q !== "");
    var visible = 0;
    items.forEach(function(el){
      var okKind   = facetState.kind === "all"   || el.getAttribute("data-kind")   === facetState.kind;
      var okStatus = facetState.status === "all" || el.getAttribute("data-status") === facetState.status;
      var okSearch = q === "" || String(el.getAttribute("data-search") || "").indexOf(q) !== -1;
      var show = okKind && okStatus && okSearch;
      el.hidden = !show;
      if(show) visible++;
      if(!show && selected === el){ clearSelection(); }
    });
    if(showing) showing.textContent = String(visible);
    if(zero)   zero.hidden = !(items.length > 0 && visible === 0);
    if(list)   list.hidden = (items.length > 0 && visible === 0);
  }
  if(searchEl){
    var t = null;
    searchEl.addEventListener("input", function(){ clearTimeout(t); t = setTimeout(applyFilters, 90); });
    searchEl.addEventListener("keydown", function(ev){
      if(ev.key === "Escape"){ searchEl.value = ""; applyFilters(); searchEl.blur(); }
    });
  }
  if(searchClr) searchClr.addEventListener("click", function(){
    if(searchEl) searchEl.value = "";
    applyFilters();
    if(searchEl) searchEl.focus();
  });
  Array.prototype.forEach.call(document.querySelectorAll(".ba-fb-chip[data-facet]"), function(chip){
    chip.addEventListener("click", function(){
      var facet = chip.getAttribute("data-facet");
      var value = chip.getAttribute("data-value") || "all";
      facetState[facet] = value;
      Array.prototype.forEach.call(document.querySelectorAll('.ba-fb-chip[data-facet="' + facet + '"]'), function(sib){
        sib.setAttribute("aria-pressed", sib === chip ? "true" : "false");
      });
      applyFilters();
    });
  });

  /* ---- reply submit (AJAX via the existing reply_feedback action) ---- */
  window.__baFbReply = function(){
    if(!sendBtn || sendBtn.dataset.submitting === "1") return;
    var id = parseInt(idEl ? String(idEl.value || "0") : "0", 10) || 0;
    var txt = bodyEl ? String(bodyEl.value || "").trim() : "";

    /* Distinguish "no message picked" from "empty reply" - the legacy
       form collapsed both into a misleading "Reply content required." */
    if(id <= 0){ setAlert(alertEl, "error", "Select a message from the list first, then write your reply."); return; }
    if(txt === ""){ setAlert(alertEl, "error", "Write your reply before sending."); if(bodyEl) bodyEl.focus(); return; }

    sendBtn.dataset.submitting = "1";
    sendBtn.disabled = true;
    var original = sendBtn.textContent;
    sendBtn.textContent = "Sending…";

    $.ajax({
      url: CFG.endpoint,
      method: "POST",
      data: $("#fbReplyForm").serialize(),
      dataType: "json",
      timeout: 60000
    })
    .done(function(r){
      if(r && r.ok){
        setAlert(alertEl, "success", (r.message || "Reply sent.") + " The resident has been notified.");
        applyReplyToRow(id, txt, String(r.replied_at || ""));
        if(kpiUnrep) kpiUnrep.textContent = String(Math.max(0, (parseInt(kpiUnrep.textContent,10)||0) - 1));
        if(kpiRepl)  kpiRepl.textContent  = String((parseInt(kpiRepl.textContent,10)||0) + 1);
        renderIcons();
        return;
      }
      setAlert(alertEl, "error", (r && r.error) ? r.error : "Could not save the reply.");
      sendBtn.dataset.submitting = "0";
      sendBtn.disabled = false;
      sendBtn.textContent = original;
    })
    .fail(function(){
      setAlert(alertEl, "error", "Network error. The reply was not sent - please try again.");
      sendBtn.dataset.submitting = "0";
      sendBtn.disabled = false;
      sendBtn.textContent = original;
    });
  };

  /* Patch a card in place so scroll position and selection survive. */
  function applyReplyToRow(id, text, when){
    var row = document.querySelector('.ba-fb-item[data-id="' + id + '"]');
    if(!row) return;
    row.setAttribute("data-status", "replied");

    var badge = row.querySelector(".js-fb-status");
    if(badge){
      badge.className = "mc-admin-badge js-fb-status mc-admin-badge--success";
      var i = badge.querySelector("i");
      if(i){ i.setAttribute("data-lucide", "circle-check"); }
      var lbl = badge.querySelector(".js-fb-status-text");
      if(lbl) lbl.textContent = "Answered";
      else badge.appendChild(document.createTextNode("Answered"));
    }

    var slot = row.querySelector(".js-fb-reply-slot");
    if(slot && !slot.firstElementChild){
      var quote = document.createElement("blockquote");
      quote.className = "ba-fb-reply-quote";

      var head = document.createElement("p");
      head.className = "ba-fb-reply-quote-head";
      var hi = document.createElement("i");
      hi.setAttribute("data-lucide", "badge-check");
      hi.className = "lucide";
      head.appendChild(hi);
      head.appendChild(document.createTextNode("Admin reply" + (when ? " · " + when : "")));

      var body = document.createElement("p");
      body.className = "ba-fb-reply-quote-body";
      body.textContent = String(text);

      quote.appendChild(head);
      quote.appendChild(body);
      slot.appendChild(quote);
    }

    var p = payloadOf(row);
    p.reply = String(text);
    row.setAttribute("data-payload", JSON.stringify(p));

    applyFilters();
  }

  window.addEventListener("resize", function(){
    isSheet = window.matchMedia && window.matchMedia("(max-width: 991.98px)").matches;
    if(!isSheet) closeSheet();
  });

  updateCount();
  applyFilters();
  renderIcons();
})();
</script>
HTML;
    $pageScripts = (string) str_replace('__CONFIG__', (string) json_encode([
        'endpoint' => $endpointRaw,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $pageScripts);
    ?>

<?php else : ?>

    <!-- ==================== FAQ TAB ==================== -->
    <div class="mc-admin-section-card">
        <div class="mc-admin-section-head">
            <h3><i data-lucide="help-circle" class="lucide"></i> FAQ Entries</h3>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if ($isSuper) : ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#faqEditor" data-mode="new">
                        <i data-lucide="plus" class="lucide-14"></i> New FAQ
                    </button>
                <?php else : ?>
                    <span class="small text-muted fst-italic">
                        <i data-lucide="triangle-alert" class="lucide-14" style="color:var(--ba-warning);"></i>
                        View-only &mdash; only Super Admins can add or edit FAQs.
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="mc-admin-section-body">
            <div id="faqListAlert" role="status" aria-live="polite"></div>

            <?php if ($faqTotal === 0) : ?>
                <div class="mc-admin-empty">
                    <div class="mc-admin-empty-icon"><i data-lucide="help-circle" class="lucide"></i></div>
                    <h4>No FAQ entries yet</h4>
                    <p>Use <strong>New FAQ</strong> to publish the first question residents will see on the public FAQ page.</p>
                </div>
            <?php else : ?>
                <div class="ba-fb-toolbar">
                    <div class="ba-fb-search">
                        <i data-lucide="search" class="lucide-search"></i>
                        <input type="search" id="adminFaqSearch" class="form-control"
                               placeholder="Search questions, answers, or categories… (press Esc to clear)"
                               autocomplete="off" aria-label="Search FAQ entries">
                        <button class="ba-fb-search-clear" type="button" id="adminFaqSearchClear" aria-label="Clear search">
                            <i data-lucide="x" class="lucide-14"></i>
                        </button>
                    </div>
                </div>

                <p class="ba-fb-result-line" id="adminFaqInfoBar" role="status" aria-live="polite">
                    <i data-lucide="list-filter" class="lucide-14"></i>
                    <span>Showing <strong id="adminFaqShowing"><?= $faqTotal ?></strong> of <strong><?= $faqTotal ?></strong> FAQs</span>
                    <span id="adminFaqInfoExtra" class="ms-1"></span>
                </p>

                <div class="ba-fb-faq-list" id="adminFaqListContainer">
                <?php
                $iFaq = 0;
                foreach ($faqs as $_f) :
                    $iFaq++;
                    $fNum      = (int) $_f['id'];
                    $fQuestion = (string) ($_f['question'] ?? '');
                    $fAnswer   = (string) ($_f['answer'] ?? '');
                    $fCat      = (string) ($_f['category'] ?? '');
                    $fStatus   = (string) ($_f['status'] ?? 'Published');
                    $fHaystack = mb_strtolower(implode(' ', [$fQuestion, $fAnswer, $fCat, $fStatus]));
                ?>
                    <div class="ba-fb-faq-row"
                         data-id="<?= $fNum ?>"
                         data-num="<?= $iFaq ?>"
                         data-question="<?= e($fQuestion) ?>"
                         data-answer="<?= e($fAnswer) ?>"
                         data-category="<?= e($fCat) ?>"
                         data-status="<?= e($fStatus) ?>"
                         data-search="<?= e($fHaystack) ?>">
                        <div class="ba-fb-faq-main">
                            <div class="ba-fb-faq-meta">
                                <span class="ba-fb-faq-num"><i data-lucide="hash" class="lucide"></i> FAQ #<?= $iFaq ?></span>
                                <?php if ($fCat !== '') : ?>
                                    <span class="mc-admin-badge mc-admin-badge--muted"><?= e($fCat) ?></span>
                                <?php endif; ?>
                                <span class="mc-admin-badge <?= $fStatus === 'Published' ? 'mc-admin-badge--success' : 'mc-admin-badge--muted' ?>">
                                    <i data-lucide="<?= $fStatus === 'Published' ? 'circle-check' : 'archive' ?>" class="lucide"></i>
                                    <?= e($fStatus) ?>
                                </span>
                            </div>
                            <h4 class="ba-fb-faq-q"><?= e($fQuestion) ?></h4>
                            <p class="ba-fb-faq-a">
                                <?= e(mb_strlen($fAnswer) > 200 ? mb_substr($fAnswer, 0, 200) . '…' : $fAnswer) ?>
                            </p>
                        </div>
                        <div class="ba-fb-faq-side">
                            <?php if ($isSuper) : ?>
                                <div class="ba-fb-faq-actions">
                                    <button class="btn btn-sm btn-outline-primary faq-edit-btn" type="button"
                                            data-bs-toggle="modal" data-bs-target="#faqEditor" data-mode="edit"
                                            aria-label="Edit FAQ #<?= $iFaq ?>">
                                        <i data-lucide="pencil" class="lucide-14"></i> Edit
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger faq-del-btn" type="button"
                                            data-bs-label="<?= e(mb_strimwidth($fQuestion, 0, 60, '…')) ?>"
                                            aria-label="Delete FAQ #<?= $iFaq ?>">
                                        <i data-lucide="trash-2" class="lucide-14"></i> Delete
                                    </button>
                                </div>
                            <?php else : ?>
                                <span class="mc-admin-badge mc-admin-badge--muted">View-only</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <div class="mc-admin-empty" id="adminFaqZeroState" hidden>
                    <div class="mc-admin-empty-icon"><i data-lucide="search-x" class="lucide"></i></div>
                    <h4>No FAQs match your search</h4>
                    <p>Try a different keyword, or clear the search box to see all <?= $faqTotal ?> entries.</p>
                </div>
            <?php endif; ?>

            <?php if ($faqTotal > 0) : ?>
                <p class="mc-admin-concerns-info mt-3">
                    <i data-lucide="info" class="lucide"></i>
                    <span><strong><?= $faqPublished ?></strong> of <strong><?= $faqTotal ?></strong> FAQs are published. Archived entries stay hidden from residents.</span>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== FAQ EDITOR ====================
         Was a Bootstrap offcanvas-end drawer (--bs-offcanvas-width:540px).
         A drawer pins itself to one screen edge, so on a phone it was a
         full-height panel with a sliver of the FAQ list beside it and
         nothing centred — and its footer floated mid-panel above a dead zone.
         Now the shared .mc-editor-dialog centred modal, the same treatment
         ba_schedules / ba_waste / ba_announcements already use. -->
    <div class="modal fade mc-editor-dialog mc-editor-dialog--narrow" tabindex="-1" id="faqEditor" aria-hidden="true" aria-labelledby="faqLbl">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
        <form id="faqForm" onsubmit="event.preventDefault(); window.__baFaqSave ? window.__baFaqSave() : null;">
            <input type="hidden" name="action" value="save_faq">
            <input type="hidden" id="faqId" name="id" value="0">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="faqLbl">New FAQ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="faqQ">Question <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="faqQ" name="question"
                           maxlength="255" required placeholder="e.g. What time is collection on Fridays?">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="faqA">Answer <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="faqA" name="answer" rows="7" required
                              placeholder="Write the answer residents will read on the public FAQ page."></textarea>
                </div>
                <div class="row g-3 mb-0">
                    <div class="col-8">
                        <label class="form-label" for="faqCategory">Category</label>
                        <select class="form-select" id="faqCategory" name="category">
                            <option value="">&mdash; General &mdash;</option>
                            <?php foreach ($faqCategories as $_cat) : ?>
                                <option value="<?= e((string) $_cat) ?>"><?= e((string) $_cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label" for="faqStatus">Status</label>
                        <select class="form-select" id="faqStatus" name="status">
                            <option value="Published">Published</option>
                            <option value="Archived">Archived</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div id="faqEditorAlert" role="status" aria-live="polite"></div>
                <button class="btn btn-primary" type="submit" id="faqSaveBtn">Save</button>
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
        </div>
      </div>
    </div>

    <?php
    $pageScripts = <<<'HTML'
<script>
(function(){
  var CFG = __CONFIG__;

  function csrfToken(){
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? String(m.getAttribute('content') || '') : '';
  }
  function setAlert(sel, type, msg){
    var el = document.querySelector(sel);
    if(!el) return;
    el.className = type === "success" ? "alert alert-success mb-0"
                : (type === "error" ? "alert alert-danger mb-0" : "alert alert-info mb-0");
    el.textContent = String(msg || "");
  }
  function clearAlert(sel){
    var el = document.querySelector(sel);
    if(el){ el.textContent = ""; }
  }

  /* ---- editor prefill (works for both New and Edit) ---- */
  var editor = document.getElementById("faqEditor");
  if(editor) editor.addEventListener("show.bs.modal", function(ev){
    var trigger = ev.relatedTarget;
    var mode    = (trigger && trigger.dataset) ? (trigger.dataset.mode || "edit") : "edit";
    var row     = (trigger && trigger.closest) ? trigger.closest(".ba-fb-faq-row") : null;
    var id      = (mode === "new" || !row) ? 0 : (parseInt(row.getAttribute("data-id") || "0", 10) || 0);
    var get     = function(n){ return row ? String(row.getAttribute("data-" + n) || "") : ""; };

    document.getElementById("faqLbl").textContent = id > 0 ? "Edit FAQ" : "New FAQ";
    document.getElementById("faqId").value = String(id);
    document.getElementById("faqQ").value   = get("question");
    document.getElementById("faqA").value   = get("answer");
    document.getElementById("faqCategory").value = get("category");
    document.getElementById("faqStatus").value   = get("status") || "Published";
    clearAlert("#faqEditorAlert");

    if(typeof window.__renderLucide === "function"){ try{ window.__renderLucide(); }catch(e){} }
  });

  window.__baFaqSave = function(){
    var b = document.getElementById("faqSaveBtn");
    if(!b || b.dataset.submitting === "1") return;
    b.dataset.submitting = "1";
    b.disabled = true;
    var original = b.textContent;
    b.textContent = "Saving…";

    $.ajax({
      url: CFG.endpoint,
      method: "POST",
      data: $("#faqForm").serialize(),
      dataType: "json",
      timeout: 60000
    })
    .done(function(r){
      if(r && r.ok){
        setAlert("#faqEditorAlert", "success", (r.message || "Saved") + " Reloading…");
        setTimeout(function(){ location.reload(); }, 550);
        return;
      }
      setAlert("#faqEditorAlert", "error", (r && r.error) ? r.error : "Save failed.");
      b.dataset.submitting = "0"; b.disabled = false; b.textContent = original;
    })
    .fail(function(){
      setAlert("#faqEditorAlert", "error", "Network error. Nothing was saved.");
      b.dataset.submitting = "0"; b.disabled = false; b.textContent = original;
    });
  };

  /* ---- client-side search ---- */
  (function(){
    var input   = document.getElementById("adminFaqSearch");
    var clear   = document.getElementById("adminFaqSearchClear");
    var list    = document.getElementById("adminFaqListContainer");
    var showing = document.getElementById("adminFaqShowing");
    var zero    = document.getElementById("adminFaqZeroState");
    var extra   = document.getElementById("adminFaqInfoExtra");
    if(!input || !list) return;

    var rows = Array.prototype.slice.call(list.querySelectorAll(".ba-fb-faq-row"));
    var total = rows.length;

    function run(){
      var q = String(input.value || "").trim().toLowerCase();
      if(clear) clear.classList.toggle("is-visible", q !== "");
      var n = 0;
      rows.forEach(function(row){
        var hit = q === "" || String(row.getAttribute("data-search") || "").indexOf(q) !== -1;
        row.hidden = !hit;
        if(hit) n++;
      });
      if(showing) showing.textContent = String(n);
      if(zero)    zero.hidden = !(n === 0 && q !== "");
      if(list)    list.hidden = (n === 0 && q !== "");
      if(extra)   extra.textContent = "";
    }

    var t = null;
    input.addEventListener("input", function(){ clearTimeout(t); t = setTimeout(run, 90); });
    input.addEventListener("keydown", function(ev){
      if(ev.key === "Escape"){ input.value = ""; run(); input.blur(); }
    });
    if(clear) clear.addEventListener("click", function(){ input.value = ""; run(); input.focus(); });
    run();
  })();

  /* ---- delete ----
     Use closest(), not ev.target.classList: the button now contains a
     Lucide <i> that createIcons() swaps for an <svg>, so a click landing
     on the icon itself would never match the button's class. */
  document.addEventListener("click", function(ev){
    var delBtn = (ev.target && ev.target.closest) ? ev.target.closest(".faq-del-btn") : null;
    if(!delBtn) return;
    var row = delBtn.closest(".ba-fb-faq-row");
    if(!row) return;
    var id = parseInt(row.getAttribute("data-id") || "0", 10);
    if(!id) return;
    var label = delBtn.getAttribute("data-bs-label") || "this FAQ";
    if(!confirm('Delete this FAQ?\n\n"' + label + '"\n\nIt will be removed for residents immediately. This cannot be undone.')) return;

    delBtn.disabled = true;
    $.ajax({
      url: CFG.endpoint,
      method: "POST",
      data: { action: "delete_faq", id: id, csrf_token: csrfToken() },
      dataType: "json",
      timeout: 30000
    })
    .done(function(r){
      if(r && r.ok){
        setAlert("#faqListAlert", "success", r.message || "Deleted.");
        setTimeout(function(){ location.reload(); }, 450);
        return;
      }
      setAlert("#faqListAlert", "error", (r && r.error) ? r.error : "Delete failed.");
      delBtn.disabled = false;
    })
    .fail(function(){
      setAlert("#faqListAlert", "error", "Network error. Nothing was deleted.");
      delBtn.disabled = false;
    });
  });
})();
</script>
HTML;
    $pageScripts = (string) str_replace('__CONFIG__', (string) json_encode([
        'endpoint' => $endpointRaw,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $pageScripts);
    ?>

<?php endif; ?>

</div><!-- /.mc-admin-ba_feedback-page -->

<?php
require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
