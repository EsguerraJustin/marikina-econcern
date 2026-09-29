<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$_ba_mysqli = db();
$_ba_user = current_user($_ba_mysqli);
if (!$_ba_user || ba_user_barangay_id($_ba_mysqli, $_ba_user) === null) {
    $_SESSION['flash_error'] = 'Set your barangay first in Profile to view barangay-specific announcements and service alerts.';
    $_back = $_SERVER['REQUEST_URI'] ?? '';
    redirect(app_url('/public/profile.php') . ($_back !== '' ? '?redirect_back=' . urlencode($_back) : ''));
}

$pageTitle = 'Announcements & Alerts';
$activeNav = 'ba_announcements';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$barangayId = ba_user_barangay_id($mysqli, $user);
$items = ba_list_announcements($mysqli, $barangayId > 0 ? $barangayId : null);
$_annTotal = count($items);

$fKind = trim((string) ($_GET['kind'] ?? ''));
$fSearch = trim((string) ($_GET['q'] ?? ''));

$allKinds = ['General','Holiday','Disruption','Delay','Cancellation','Resumption','Schedule_Change'];

$kindClassMap = [
    'Holiday' => 'bg-info',
    'Disruption' => 'bg-danger',
    'Delay' => 'bg-warning text-dark',
    'Cancellation' => 'bg-danger',
    'Resumption' => 'bg-success',
    'Schedule_Change' => 'bg-primary',
    'General' => 'bg-secondary',
];
$_self = e(app_url('/public/ba_announcements.php'));
?>

<div class="mc-ba-schedule-page">

<div class="mc-ba-sched-hero">
    <div class="mc-ba-sched-hero-inner">
        <div class="mc-ba-sched-hero-title-row">
            <div class="mc-ba-sched-hero-icon-wrap">
                <i data-lucide="megaphone" class="lucide lucide-24"></i>
            </div>
            <div class="mc-ba-sched-hero-title">
                <h1 class="mb-0">Announcements &amp; Service Alerts</h1>
                <p class="mb-0 text-muted" style="margin-top:2px;">Holiday notices, schedule changes, service disruptions, and official updates.</p>
            </div>
        </div>
        <div class="mc-ba-sched-hero-brand">
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" width="36" height="36" loading="eager" decoding="async" class="mc-ba-sched-brand-img" style="margin-left:auto;">
        </div>
    </div>
</div>

<form method="GET" class="mc-neo-card mb-4" id="annFilterForm" action="<?= $_self ?>">
    <div class="card-body" style="padding: var(--ba-space-4);">

        <div class="mb-3">
            <label for="annSearch" class="form-label d-flex justify-content-between gap-2 mb-2">
                <span class="fw-semibold"><i data-lucide="search" class="lucide lucide-14"></i> Search announcement</span>
                <span class="small text-muted fw-normal">Press Esc to clear</span>
            </label>
            <div class="mc-ba-sched-search-wrap">
                <i data-lucide="search" class="lucide lucide-20 mc-ba-sched-search-icon"></i>
                <input type="search" id="annSearch" name="q" class="form-control" value="<?= e($fSearch) ?>" placeholder="Search by title, content, or keywords… (instant filter)" autocomplete="off" spellcheck="false">
            </div>
        </div>

        <div class="row mb-3 g-3 align-items-end">
            <div class="col-md-9">
                <label for="annKind" class="form-label">Announcement Kind</label>
                <select id="annKind" name="kind" class="form-select">
                    <option value="">All kinds</option>
                    <?php foreach ($allKinds as $k) : ?>
                        <option value="<?= $k ?>" <?= $fKind === $k ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $k))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i data-lucide="filter" class="lucide lucide-16"></i><span>Apply kind</span>
                </button>
                <?php if ($fKind !== '' || $fSearch !== '') : ?>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/ba_announcements.php')) ?>">
                        <i data-lucide="x-circle" class="lucide lucide-16"></i><span>Reset</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="mc-ba-sched-info" id="annInfo" data-total="<?= $_annTotal ?>">
            <i data-lucide="info" class="lucide lucide-16" style="color:var(--ba-text-muted);"></i>
            <span>Showing <strong id="annShowing"><?= $_annTotal ?></strong> of <strong><?= $_annTotal ?></strong> announcements<?php if ($fSearch !== '' || $fKind !== '') : ?> <span class="text-muted">(filtered via dropdowns)</span><?php endif; ?><span id="annExtra" class="ms-1"></span></span>
        </div>

    </div>
</form>

<?php if (count($items) === 0) : ?>
    <div class="mc-neo-card">
        <div class="card-body text-muted text-center py-6">
            <div class="display-6 opacity-50 mb-2"><i data-lucide="megaphone" class="lucide-14"></i></div>
            <div>No published announcements yet.</div>
            <div class="small mt-1">Official notices from the garbage collection administrator will appear here.</div>
        </div>
    </div>
<?php else : ?>
    <div class="row g-3 mb-3" id="annCards">
    <?php foreach ($items as $a) :
        $kind = (string) ($a['kind'] ?? 'General');
        $class = $kindClassMap[$kind] ?? 'bg-secondary';
        $effective = !empty($a['effective_date']) ? date('F j, Y', strtotime((string) $a['effective_date'])) : null;
        $title = (string) $a['title'];
        $content = (string) $a['content'];
        $revised = (string) ($a['revised_schedule_info'] ?? '');
        $searchHay = trim($title . ' ' . $content . ' ' . $revised);
    ?>
        <div class="col-lg-6"
             data-id="<?= (int) $a['id'] ?>"
             data-kind="<?= e($kind) ?>"
             data-search="<?= e(mb_strtolower($searchHay, 'UTF-8')) ?>"
             data-title="<?= e(mb_strtolower($title, 'UTF-8')) ?>">
            <div class="mc-neo-card mc-ann-card h-100">
                <div class="mc-ann-card-inner">
                    <div class="mc-ann-badges d-flex justify-content-between align-items-center mb-4">
                        <span class="mc-ann-type-badge"><?= e(ucfirst(str_replace('_', ' ', $kind))) ?></span>
                        <?php if (!empty($a['target_barangay_name'])) : ?>
                            <span class="mc-ann-scope-badge">Barangay <?= e((string) $a['target_barangay_name']) ?></span>
                        <?php else : ?>
                            <span class="mc-ann-scope-badge">All Barangays</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="mc-ann-title mb-3"><?= e($title) ?></h3>
                    <?php if ($effective) : ?><div class="mc-ann-dates mb-5">Effective <?= $effective ?> · Published <?= date('F j, Y', strtotime((string) ($a['published_at'] ?? $a['created_at']))) ?></div><?php endif; ?>
                    <div class="mc-ann-content"><?= nl2br(e($content)) ?></div>
                    <?php if (!empty($revised)) : ?>
                        <hr class="mc-ann-divider">
                        <div class="mc-ann-revised">
                            <div class="mc-ann-revised-label">Revised schedule information</div>
                            <div class="mc-ann-revised-value"><?= e($revised) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

</div>

<script>
(function () {
  const $s = document.getElementById('annSearch');
  const $kind = document.getElementById('annKind');
  const $cards = document.getElementById('annCards');
  if (!$s || !$cards) return;
  const $showing = document.getElementById('annShowing');
  const $extra = document.getElementById('annExtra');
  const totalRows = (function(){
    const info = document.getElementById('annInfo');
    const n = parseInt(String(info?.dataset?.total || '0'), 10);
    return !isNaN(n) && n > 0 ? n : $cards.querySelectorAll('[data-id]').length;
  })();
  let t;
  function runFilter() {
    const raw = String($s.value || '').trim();
    const kindSel = String($kind ? ($kind.value || '') : '');
    const cards = $cards.querySelectorAll('[data-id]');
    const kindMatches = kindSel === '' ? null : function(k){ return k === kindSel; };
    const q = raw.toLowerCase();
    const parts = q.split(/\s+/).filter(Boolean);
    let shown = 0;
    cards.forEach(c => {
      const k = String(c.dataset.kind || '');
      let okKind = !kindMatches ? true : kindMatches(k);
      let okSearch = true;
      if (parts.length > 0) {
        const hay = String(c.dataset.search || '').toLowerCase();
        const tit = String(c.dataset.title || '').toLowerCase();
        for (const p of parts) {
          if (hay.indexOf(p) === -1 && tit.indexOf(p) === -1) { okSearch = false; break; }
        }
      }
      const match = okKind && okSearch;
      if (match) { shown++; c.style.display = ''; }
      else { c.style.display = 'none'; }
    });
    if ($showing) $showing.textContent = String(shown);
    if ($extra) {
      if (shown === 0 && (parts.length > 0 || kindMatches)) {
        $extra.innerHTML = '· <strong class="text-danger">No matches.</strong> Press Esc to clear search or change Kind filter.';
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      } else {
        $extra.textContent = parts.length > 0 ? '· ' + shown + ' matched · Esc to clear' : '';
      }
    }
  }
  $s.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runFilter, 80); });
  $s.addEventListener('keydown', (e) => { if (e.key === 'Escape') { $s.value = ''; runFilter(); $s.blur(); } });
  if ($kind) $kind.addEventListener('change', runFilter);
  runFilter();
})();
</script>

<?php
require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
