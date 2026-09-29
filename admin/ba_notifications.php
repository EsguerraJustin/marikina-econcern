<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$pageTitle = 'Push Notifications';
$activeNav = 'ba_notifications';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

$endpoint = e(app_url('/admin/api/basuraalert_admin.php'));
echo csrf_header_meta();
$barangays = ba_list_barangays($mysqli);

$recent = [];
try {
    $res = $mysqli->query('SELECT n.*, u.email, u.mobile, CONCAT(u.first_name, " ", u.last_name) AS uname FROM ba_notifications n LEFT JOIN users u ON u.id = n.user_id ORDER BY n.id DESC LIMIT 50');
    if ($res) while ($r = $res->fetch_assoc()) $recent[] = $r;
} catch (Throwable $e) {}

// All 50 rows are already fetched above, so "Load more" is a client-side reveal
// (see the $pageScripts block at the bottom) — no extra query, no new endpoint.
$recentInitial = 15;   // cards visible on first paint
$recentStep    = 15;   // cards revealed per click

// 'barangay' was initialised here but never queried and never rendered.
$counts = ['all' => 0, 'sent' => 0, 'queued' => 0, 'failed' => 0];
try {
    $res = $mysqli->query("SELECT COUNT(*) c FROM ba_notifications"); if ($res && ($r = $res->fetch_assoc())) $counts['all'] = (int) $r['c'];
    $res = $mysqli->query("SELECT COUNT(*) c FROM ba_notifications WHERE delivery_status IN ('sent','mock_sent')"); if ($res && ($r = $res->fetch_assoc())) $counts['sent'] = (int) $r['c'];
    $res = $mysqli->query("SELECT COUNT(*) c FROM ba_notifications WHERE delivery_status = 'queued'"); if ($res && ($r = $res->fetch_assoc())) $counts['queued'] = (int) $r['c'];
    $res = $mysqli->query("SELECT COUNT(*) c FROM ba_notifications WHERE delivery_status = 'failed'"); if ($res && ($r = $res->fetch_assoc())) $counts['failed'] = (int) $r['c'];
} catch (Throwable $e) {}

function ba_delivery_status_label(string $s): string {
    $map = ['sent' => 'Delivered', 'mock_sent' => 'Delivered', 'queued' => 'Queued', 'failed' => 'Failed'];
    return $map[$s] ?? ucfirst($s);
}
function ba_delivery_status_badge(string $s): string {
    // Design-system badges: tinted background + !important colour. The old
    // version returned ' text-success' / ' text-warning' / ' text-danger' to sit
    // on `bg-light`, which is amber-on-cream and green-on-cream at caption
    // size — genuinely hard to read.
    if ($s === 'sent' || $s === 'mock_sent') return 'mc-admin-badge--success';
    if ($s === 'queued') return 'mc-admin-badge--warning';
    if ($s === 'failed') return 'mc-admin-badge--danger';
    return 'mc-admin-badge--muted';
}
function ba_delivery_accent(string $s): string {
    // Left accent bar, so failures are findable by scanning the row edge
    // instead of reading every pill.
    if ($s === 'sent' || $s === 'mock_sent') return 'mc-admin-notif--sent';
    if ($s === 'queued') return 'mc-admin-notif--queued';
    if ($s === 'failed') return 'mc-admin-notif--failed';
    return 'mc-admin-notif--unknown';
}
function ba_delivery_outcome_icon(string $s): string {
    if ($s === 'sent' || $s === 'mock_sent') return 'check-circle-2';
    if ($s === 'queued') return 'clock';
    if ($s === 'failed') return 'circle-alert';
    return 'info';
}
/* Tones the leading status tile. Kept separate from ba_delivery_status_badge()
   because the badge returns a full `mc-admin-badge--*` class while the tile
   needs the short `mc-admin-notif-icon--*` suffix defined in this page's CSS. */
function ba_delivery_status_tone(string $s): string {
    if ($s === 'sent' || $s === 'mock_sent') return 'success';
    if ($s === 'queued') return 'warning';
    if ($s === 'failed') return 'danger';
    return 'info';
}
function ba_channel_label(string $c): string {
    $map = ['in_app' => 'In-App', 'sms' => 'SMS', 'email' => 'Email'];
    return $map[$c] ?? ucfirst($c);
}
function ba_sanitize_delivery_note(?string $note): string {
    if ($note === null || trim($note) === '') return '';
    $n = $note;
    $n = preg_replace('/·?\s*http_status\s*=\s*\d+/i', '', $n);
    $n = preg_replace('/·?\s*message_id\s*:\s*[^·]+/i', '', $n);
    $n = preg_replace('/·?\s*ref\s*:\s*[^·]+/i', '', $n);
    $n = preg_replace('/·?\s*next_attempt\s*=\s*[^·]+/i', '', $n);
    $n = preg_replace('/·?\s*EXHAUSTED\s+after\s+\d+\s+tries/i', '', $n);
    $n = preg_replace('/\bPhase\s*\d+(?:\s*[-–—]\s*[A-Z])?\b\s*[-–—:.]?\s*/i', '', $n);
    $n = preg_replace('/\(re-?queue by cron dispatcher for retry\)/i', '(retry available)', $n);
    $n = preg_replace('/\(retry available\)\s*$/i', '', $n);
    $n = preg_replace('/\blive\b\s+/i', '', $n);
    $n = preg_replace('/retry\s+#\d+\s+(OK|FAILED)\s+(via|to)\s+/i', '', $n);
    $n = preg_replace('/\s*·\s*$/u', '', $n);
    $n = preg_replace('/^\s*·\s*/u', '', $n);
    $n = preg_replace('/\s{2,}/', ' ', $n);
    $n = trim($n, " \t\n\r\0\x0B·-–—:");
    return $n;
}

?><div class="mc-admin-ba_notifications-page">

<div class="mc-admin-hero">
    <div class="mc-admin-hero-inner">
        <div class="mc-admin-hero-title-row">
            <div class="mc-admin-hero-icon">
                <i data-lucide="bell-ring" class="lucide lucide-24"></i>
            </div>
            <div class="mc-admin-hero-title">
                <h1>Push Notifications</h1>
                <p>Broadcast alerts to residents and review the delivery status of recent BasuraAlert notifications.</p>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if (!$isSuper) : ?>
                <span class="small text-white-50 fst-italic"><i data-lucide="triangle-alert" class="lucide-14"></i> View-only — only Super Admins can push notifications.</span>
            <?php endif; ?>
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" class="mc-admin-hero-seal">
        </div>
    </div>
</div>

<div class="mc-admin-kpi-grid">
    <?php
    $notifKpis = [
        ['label' => 'Total notifications', 'variant' => 'blue',  'icon' => 'layers',          'count' => $counts['all'],    'sub' => 'all delivery attempts'],
        ['label' => 'Delivered',        'variant' => 'mint',  'icon' => 'check-circle-2',  'count' => $counts['sent'],   'sub' => 'in-app, SMS and email'],
        ['label' => 'Queued',           'variant' => 'amber', 'icon' => 'clock',           'count' => $counts['queued'], 'sub' => 'awaiting dispatch'],
        ['label' => 'Send failed',      'variant' => 'red',   'icon' => 'circle-alert',    'count' => $counts['failed'], 'sub' => 'needs attention'],
    ];
    foreach ($notifKpis as $k) :
        $pct = $counts['all'] > 0 ? round(100 * ($k['count'] / $counts['all']), 1) : 0;
    ?>
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--<?= e($k['variant']) ?>"><i data-lucide="<?= e($k['icon']) ?>" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label"><?= e($k['label']) ?></p>
            <p class="mc-admin-kpi-value"><?= (int) $k['count'] ?></p>
            <p class="mc-admin-kpi-sub"><?= e((string) $pct) ?>% of total &middot; <?= e($k['sub']) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="mc-admin-two-col">
    <div class="mc-admin-section-card">
        <div class="mc-admin-section-head">
            <h3><i data-lucide="send" class="lucide"></i> Push a Notification</h3>
            <span class="text-muted small">Queued, then dispatched.</span>
        </div>
        <div class="p-4">
                <?php if ($isSuper) :
                    $userOptions = [];
                    try {
                        $ures = $mysqli->query('SELECT id, email, first_name, last_name, mobile FROM users ORDER BY id DESC LIMIT 100');
                        if ($ures) while ($u = $ures->fetch_assoc()) $userOptions[] = $u;
                    } catch (Throwable $e) {}
                ?>
                    <form id="pushForm" method="POST" onsubmit="event.preventDefault(); window.__baPushSubmit ? window.__baPushSubmit() : null;">
                        <input type="hidden" name="action" value="push_notification">
                        <?= csrf_field() ?>
                        <div id="formAlert"></div>
                        <div class="mb-3"><label class="form-label">Recipient audience *</label>
                            <select id="audience" name="target_kind" class="form-select mc-admin-form-select" required>
                                <option value="all">All active users</option>
                                <option value="barangay">Specific barangay</option>
                                <option value="user">Single user</option>
                            </select>
                        </div>
                        <div class="mb-3 d-none" id="audBarangay"><label class="form-label">Target barangay *</label>
                            <select class="form-select mc-admin-form-select" name="target_barangay_id"><option value="">— Select —</option><?php foreach ($barangays as $b) : ?><option value="<?= (int) $b['id'] ?>"><?= e((string) $b['name']) ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="mb-3 d-none" id="audUser"><label class="form-label">Target recipient *</label>
                            <input class="form-control mc-admin-form-control" type="text" name="target_user_id" id="targetUserInput" list="userDatalist" placeholder="Enter email address (or user ID number)…" autocomplete="off">
                            <datalist id="userDatalist">
                                <?php foreach ($userOptions as $uo) :
                                    $name = trim(($uo['first_name'] ?? '') . ' ' . ($uo['last_name'] ?? ''));
                                    $email = (string)($uo['email'] ?? '');
                                    $id = (int)($uo['id']);
                                ?>
                                    <?php if ($email !== '') : ?><option value="<?= e($email) ?>"><?= e($name !== '' ? ($name . ' — ' . $email) : $email) ?> (ID <?= $id ?>)</option><?php endif; ?>
                                    <option value="<?= $id ?>">ID <?= $id ?> — <?= e($name !== '' ? $name : 'no-name') ?><?= $email !== '' ? (' (' . e($email) . ')') : '' ?></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="small text-muted mt-1">Type or pick an <strong>email address</strong> (unique, safest). You may also enter a numeric <strong>user ID</strong>. No name-based search to avoid sending to the wrong person.</div>
                        </div>
                        <div class="mb-3"><label class="form-label">Type</label>
                            <select class="form-select mc-admin-form-select" name="type">
                                <option value="reminder">Collection Reminder</option>
                                <option value="schedule_change">Schedule Change Notice</option>
                                <option value="announcement">General Announcement</option>
                                <option value="report_submit">Report Submit Confirmation</option>
                                <option value="report_update">Report Status Update</option>
                                <option value="feedback_reply">Feedback / Contact Reply</option>
                            </select>
                        </div>
                        <div class="mb-3"><label class="form-label">Title *</label><input class="form-control mc-admin-form-control" type="text" name="title" maxlength="190" required></div>
                        <div class="mb-3"><label class="form-label">Message *</label><textarea class="form-control mc-admin-form-control" rows="4" name="message" required></textarea></div>
                        <div class="mb-3">
                            <label class="form-label d-block">Delivery channels</label>
                            <div class="mc-admin-channel-row">
                                <div class="form-check"><input class="form-check-input" type="checkbox" id="chIn" name="channels[]" value="in_app" checked><label class="form-check-label" for="chIn">In-app notification center</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" id="chSms" name="channels[]" value="sms" checked><label class="form-check-label" for="chSms">SMS (TextBee)</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" id="chEm" name="channels[]" value="email"><label class="form-check-label" for="chEm">Email (Brevo)</label></div>
                            </div>
                        </div>
                        <div class="d-grid">
                            <button class="btn btn-primary" type="submit" id="pushBtn"><i data-lucide="send" class="lucide-14"></i> Push Notification</button>
                        </div>
                    </form>
                <?php else : ?>
                    <div class="alert alert-info mb-0">
                        <div class="fw-semibold">Super Admin permission required</div>
                        <div class="small text-muted mt-1">Push notifications can only be sent by Super Admin accounts. Please ask a Super Admin if you need to broadcast an alert.</div>
                    </div>
                <?php endif; ?>
        </div>
    </div>
    <div class="mc-admin-section-card">
            <div class="mc-admin-section-head">
                <h3><i data-lucide="list" class="lucide"></i> Recent Notifications</h3>
                <span class="text-muted small">Latest 50</span>
            </div>
            <div class="p-4">
                <?php if (count($recent) === 0) : ?>
                    <div class="mc-admin-empty">
                        <div class="mc-admin-empty-icon"><i data-lucide="bell-off" class="lucide-24"></i></div>
                        <h4>No notifications yet</h4>
                        <p>Push a broadcast using the form, or wait for residents to submit reports.</p>
                    </div>
                <?php else : ?>
                    <?php /* The overflow rows are hidden by CSS, which only the
                            Load more script can undo. Without JS they would stay
                            invisible forever, so reveal them all up front. */ ?>
                    <noscript><style>.mc-admin-notif.is-overflow{display:block}</style></noscript>
                    <div class="mc-admin-notif-list">
                    <?php foreach ($recent as $rIdx => $n) :
                        $rawStatus   = (string)($n['delivery_status'] ?? '');
                        $statusLabel = ba_delivery_status_label($rawStatus);
                        $statusClass = ba_delivery_status_badge($rawStatus);
                        $accentClass = ba_delivery_accent($rawStatus);
                        $outcomeIcon = ba_delivery_outcome_icon($rawStatus);
                        $statusTone  = ba_delivery_status_tone($rawStatus);
                        // Rows past the first $recentInitial are rendered but
                        // hidden by `.mc-admin-notif.is-overflow` until the
                        // Load more button reveals them.
                        $isOverflow  = $rIdx >= $recentInitial;
                        $chLabel     = ba_channel_label((string)($n['channel'] ?? ''));
                        $cleanNote   = ba_sanitize_delivery_note($n['delivery_note'] ?? null);
                        // 9 of the latest 50 rows have an empty `type`; rendering
                        // a pill for them produced an empty grey lozenge.
                        $typeLabel   = trim((string)($n['type'] ?? ''));
                        $typeLabel   = $typeLabel !== '' ? ucwords(str_replace('_', ' ', $typeLabel)) : '';
                        // Kept as separate values so each can be its own chip
                        // instead of one long "To: name (email · mobile)" run-on.
                        $rcptName    = trim((string)($n['uname'] ?? '')) !== '' ? (string)$n['uname'] : 'Unknown user';
                        $rcptEmail   = trim((string)($n['email']  ?? ''));
                        $rcptMobile  = trim((string)($n['mobile'] ?? ''));
                        $createdRaw  = (string)($n['created_at'] ?? '');
                        $createdTs   = strtotime($createdRaw);
                        $msg         = (string)($n['message'] ?? '');
                    ?>
                        <article class="mc-admin-notif <?= e($accentClass) ?><?= $isOverflow ? ' is-overflow' : '' ?>">
                            <div class="mc-admin-notif-icon mc-admin-notif-icon--<?= e($statusTone) ?>" aria-hidden="true">
                                <i data-lucide="<?= e($outcomeIcon) ?>" class="lucide"></i>
                            </div>
                            <div class="mc-admin-notif-body">
                                <div class="mc-admin-notif-head">
                                    <div class="mc-admin-notif-badges">
                                        <?php if ($typeLabel !== '') : ?>
                                            <span class="mc-admin-badge mc-admin-badge--muted"><?= e($typeLabel) ?></span>
                                        <?php endif; ?>
                                        <span class="mc-admin-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
                                        <span class="mc-admin-badge mc-admin-badge--new"><?= e($chLabel) ?></span>
                                    </div>
                                    <time class="mc-admin-notif-when" datetime="<?= $createdTs !== false ? date('c', $createdTs) : '' ?>"><?= $createdTs !== false ? date('M j, g:i A', $createdTs) : '' ?></time>
                                </div>
                                <h4 class="mc-admin-notif-title"><?= e((string) $n['title']) ?></h4>
                                <p class="mc-admin-notif-msg" title="<?= e($msg) ?>"><?= e($msg) ?></p>
                                <div class="mc-admin-notif-foot">
                                    <div class="mc-admin-notif-chips">
                                        <span class="mc-admin-notif-chip"><i data-lucide="user" class="lucide"></i><?= e($rcptName) ?></span>
                                        <?php if ($rcptEmail !== '') : ?>
                                            <span class="mc-admin-notif-chip"><i data-lucide="mail" class="lucide"></i><?= e($rcptEmail) ?></span>
                                        <?php endif; ?>
                                        <?php if ($rcptMobile !== '') : ?>
                                            <span class="mc-admin-notif-chip"><i data-lucide="smartphone" class="lucide"></i><?= e($rcptMobile) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mc-admin-notif-outcome">
                                        <i data-lucide="<?= e($outcomeIcon) ?>" class="lucide"></i>
                                        <span><?= $cleanNote !== '' ? e($cleanNote) : e($statusLabel) ?></span>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                    <?php if (count($recent) > $recentInitial) : ?>
                        <div class="mc-admin-loadmore-wrap">
                            <button type="button" class="btn btn-outline-primary mc-admin-loadmore" data-step="<?= (int) $recentStep ?>">
                                <i data-lucide="chevrons-down" class="lucide lucide-16"></i> Load more
                            </button>
                            <span class="mc-admin-loadmore-count small text-muted"></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
    </div>
</div><!-- /.mc-admin-two-col -->

</div><!-- /.mc-admin-ba_notifications-page -->

<?php
$pageScripts = <<<'HTML'
<script>
(function(){
  const ENDPOINT="__ENDPOINT__";
  function csrf(){var m=document.querySelector('meta[name="csrf-token"]');return m?String(m.content||""):"";}
  function eH(s){return String(s).replace(/[&<>"']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c];});}
  function setA(t,type,msg){const el=document.querySelector(t);if(!el)return;const c=type==="success"?"alert-success":type==="error"?"alert-danger":"alert-info";el.innerHTML='<div class="alert '+c+' small mb-0">'+eH(String(msg||""))+'</div>';if (typeof window.__renderLucide === 'function') window.__renderLucide();}
  const aud=document.getElementById("audience");const bWrap=document.getElementById("audBarangay");const uWrap=document.getElementById("audUser");
  function syncAud(){const v=aud.value;bWrap.classList.toggle("d-none",v!=="barangay");uWrap.classList.toggle("d-none",v!=="user");}
  if(aud)aud.addEventListener("change",syncAud);syncAud();
  /* ---- Recent Notifications: reveal the pre-rendered overflow cards ----
     All 50 rows ship in the HTML; only the first 15 are visible. No fetch,
     so there is nothing to race, retry or keep in sync with a second render. */
  (function(){
    const btn=document.querySelector(".mc-admin-loadmore");
    if(!btn)return;
    const pending=Array.prototype.slice.call(document.querySelectorAll(".mc-admin-notif.is-overflow"));
    if(!pending.length){btn.remove();return;}
    const step=parseInt(btn.getAttribute("data-step")||"15",10)||15;
    const out=document.querySelector(".mc-admin-loadmore-count");
    const total=document.querySelectorAll(".mc-admin-notif").length;
    let shown=total-pending.length;
    function sync(){
      if(out)out.textContent="Showing "+shown+" of "+total;
      if(shown>=total)btn.remove();
    }
    btn.addEventListener("click",function(){
      pending.splice(0,step).forEach(function(el){el.classList.remove("is-overflow");});
      shown=total-pending.length;
      sync();
      if(typeof window.__renderLucide==="function")window.__renderLucide();
    });
    sync();
  })();
  window.__baPushSubmit=function(){
    const b=document.getElementById("pushBtn");if(!b||b.dataset.submitting==="1")return;
    b.dataset.submitting="1";b.disabled=true;const ot=b.textContent;b.textContent="Pushing…";
    $.ajax({url:ENDPOINT,method:"POST",data:$("#pushForm").serialize()+"&csrf_token="+encodeURIComponent(csrf()),dataType:"json",timeout:120000})
      .done(function(r){if(r&&r.ok){setA("#formAlert","success",(r.message||"Done")+" Reloading…");setTimeout(function(){location.reload();},700);}
        else{setA("#formAlert","error",r&&r.error?r.error:"Push failed.");b.dataset.submitting="0";b.disabled=false;b.textContent=ot;}})
      .fail(function(){setA("#formAlert","error","Network error.");b.dataset.submitting="0";b.disabled=false;b.textContent=ot;});
  };
})();
</script>
HTML;
$pageScripts = str_replace("__ENDPOINT__", $endpoint, $pageScripts);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
