<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$pageTitle = 'Waste Segregation Guide';
$activeNav = 'ba_waste';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$category = isset($_GET['category']) ? (string) $_GET['category'] : '';
$items = ba_search_waste_guide($mysqli, $q, $category, 500);
$wgItemCount = count($items);

$wgCurTab = 'guide';

?>

<div class="mc-ba-schedule-page">
<!-- ================= PAGE HERO CARD + TABS EMBEDDED (matches Collection Schedule hero) ================= -->
<div class="mc-ba-sched-hero">
    <div class="mc-ba-sched-hero-inner">
        <div class="mc-ba-sched-hero-title-row">
            <div class="mc-ba-sched-hero-icon-wrap">
                <i data-lucide="recycle" class="lucide lucide-24"></i>
            </div>
            <div class="mc-ba-sched-hero-title">
                <h1 class="mb-0">Waste Segregation Guide</h1>
                <p class="mb-0 text-muted" style="margin-top:2px;">Search how to properly classify and dispose of household waste · <a href="<?= e(app_url('/public/ba_dropoff_map.php')) ?>" class="text-decoration-none"><i data-lucide="map-pin" class="lucide-14"></i> View Drop-off Map</a></p>
            </div>
        </div>
        <div class="mc-ba-sched-hero-brand">
            <div class="mb-2 text-end">
                <div class="btn-group btn-group-sm" role="group" aria-label="View">
                    <a href="<?= e(app_url('/public/ba_waste.php')) ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?>" class="btn btn-outline-secondary<?= $wgCurTab === 'guide' ? ' active' : '' ?>"><i data-lucide="recycle" class="lucide lucide-14 me-1"></i>Waste Segregation Guide</a>
                    <a href="<?= e(app_url('/public/ba_dropoff_map.php')) ?>" class="btn btn-outline-secondary"><i data-lucide="map-pin" class="lucide lucide-14 me-1"></i>Drop-off Points Map</a>
                </div>
            </div>
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" width="36" height="36" loading="eager" decoding="async" class="mc-ba-sched-brand-img" style="margin-left:auto;">
        </div>
    </div>
</div>

<!-- ================= UNIFIED FILTER + SEARCH CARD (Single card matches My Concern reference) ================= -->
<form method="GET" class="mc-neo-card mb-4">
    <div class="card-body" style="padding: var(--ba-space-4);">
        <!-- Row 1: Category filter (only 1 dropdown needed not 3) + Submit -->
        <div class="row mb-4 g-3 align-items-end">
            <div class="col-md-9">
                <label for="catSel" class="form-label">Category</label>
                <select id="catSel" name="category" class="form-select" style="height:48px;">
                    <option value="">All categories</option>
                    <?php foreach (['Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special'] as $c) : ?>
                        <option value="<?= $c ?>" <?= $category === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-fill" style="height:48px;">
                    <i data-lucide="filter" class="lucide lucide-16"></i><span>Apply category</span>
                </button>
                <?php if ($q !== '' || $category !== '') : ?>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/ba_waste.php')) ?>" style="height:48px;">
                        <i data-lucide="x-circle" class="lucide lucide-16"></i><span>Clear</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Row 2: Search input + Refresh (matches My Concern search + refresh layout) -->
        <div class="row g-3">
            <div class="col-md-8">
                <div class="mc-ba-sched-search-wrap">
                    <i data-lucide="search" class="lucide lucide-20 mc-ba-sched-search-icon"></i>
                    <input id="qSearch" name="q" type="search" class="form-control" style="height:48px;" placeholder="Search item (e.g. food scraps, battery, plastic bottle) — press Esc to clear" autocomplete="off" spellcheck="false" value="<?= e($q) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <button type="button" class="btn btn-outline-primary w-100" id="wgRefreshBtn" style="height:48px;">
                    <i data-lucide="refresh-cw" class="lucide lucide-16"></i>
                    <span>Refresh</span>
                </button>
            </div>
        </div>

        <!-- Row 3: Info line + results count (matches My Concern info reference) -->
        <div class="mc-ba-sched-info mt-4" id="pubWgInfo" data-total="<?= $wgItemCount ?>">
            <i data-lucide="info" class="lucide lucide-16" style="color:var(--ba-text-muted);"></i>
            <span>Showing <strong id="pubWgShowing"><?= $wgItemCount ?></strong> of <strong><?= $wgItemCount ?></strong> guide entr<?= $wgItemCount === 1 ? 'y' : 'ies' ?><?php if ($q !== '' || $category !== '') : ?> <span class="text-muted">(filtered)</span><?php endif; ?><span id="pubWgExtra" class="ms-1"></span></span>
        </div>
    </div>
</form>

<!-- ================= WASTE GUIDE LIST CARD (Pure Div Grid shared row NO native tables) ================= -->
<div class="mc-list-card mb-4">
    <div class="mc-list-card-head">
        <h3><i data-lucide="recycle" class="lucide"></i> Waste Segregation Guide</h3>
        <div class="mc-list-card-head-end">
            <span class="mc-list-card-count" id="wgListCount"><?= $wgItemCount ?></span>
        </div>
    </div>
    <div class="mc-ba-schedule-table-wrap mc-neo-card mc-stacked-card">
        <div class="mc-ba-schedule-table">
        <!-- HEADER ROW (SAME shared row class, SAME column widths — math guaranteed alignment) -->
        <div class="mc-ba-sched-row mc-wg-row mc-ba-sched-head">
            <div class="mc-ba-sched-cell mc-wg-col-item"><div class="mc-th-wrap"><i data-lucide="package" class="lucide mc-th-icon"></i>Item</div></div>
            <div class="mc-ba-sched-cell mc-wg-col-cat"><div class="mc-th-wrap"><i data-lucide="layers" class="lucide mc-th-icon"></i>Category</div></div>
            <div class="mc-ba-sched-cell mc-wg-col-acc"><div class="mc-th-wrap"><i data-lucide="check-circle-2" class="lucide mc-th-icon"></i>Accepted</div></div>
            <div class="mc-ba-sched-cell mc-wg-col-prep"><div class="mc-th-wrap"><i data-lucide="spray-can" class="lucide mc-th-icon"></i>How to prepare</div></div>
            <div class="mc-ba-sched-cell mc-wg-col-disp"><div class="mc-th-wrap"><i data-lucide="trash-2" class="lucide mc-th-icon"></i>How to dispose</div></div>
        </div>
        <!-- BODY ROWS -->
        <div class="mc-ba-schedule-tbody">
            <?php if ($wgItemCount === 0) : ?>
                <div class="mc-ba-sched-row mc-wg-row">
                    <div class="mc-ba-sched-empty"><i data-lucide="search-x" class="lucide mb-2" style="width:28px;height:28px;color:#A0AEC0;"></i><br>No items match your search.<br><span class="small text-muted">Try simpler keywords (e.g. "plastic" or "paper").</span></div>
                </div>
            <?php else : ?>
                <?php foreach ($items as $it) :
                    $catSlug = strtolower((string) $it['category']);
                    $catSlug = preg_replace('/[^a-z0-9]+/', '-', $catSlug) ?? '';
                    $catSlug = trim($catSlug, '-');
                    $isAccepted = !empty($it['is_accepted']);
                    $accClass = $isAccepted ? 'mc-wg-acc-yes' : 'mc-wg-acc-no';
                    $accText = $isAccepted ? 'Accepted' : 'NOT accepted';
                ?>
                    <div class="mc-ba-sched-row mc-wg-row" data-id="<?= (int) $it['id'] ?>" data-item_name="<?= e((string) $it['item_name']) ?>">
                        <div class="mc-ba-sched-cell mc-wg-col-item" data-label="Item">
                            <div class="mc-cell-wrap">
                                <i data-lucide="package" class="lucide mc-cell-icon"></i>
                                <div class="mc-cell-content"><strong class="mc-wg-item-name"><?= e((string) $it['item_name']) ?></strong></div>
                            </div>
                        </div>
                        <div class="mc-ba-sched-cell mc-wg-col-cat" data-label="Category">
                            <div class="mc-cell-wrap" style="gap:0;">
                                <div class="mc-cell-content"><span class="badge border mc-wg-badge mc-wg-cat-<?= e($catSlug) ?>"><i data-lucide="layers" class="lucide" style="width:12px;height:12px;flex-shrink:0;"></i> <?= e((string) $it['category']) ?></span></div>
                            </div>
                        </div>
                        <div class="mc-ba-sched-cell mc-wg-col-acc" data-label="Accepted">
                            <div class="mc-cell-wrap mc-wg-center">
                                <div class="mc-cell-content"><span class="badge border mc-wg-badge <?= $accClass ?>"><?= $accText ?></span></div>
                            </div>
                        </div>
                        <div class="mc-ba-sched-cell mc-wg-col-prep" data-label="How to prepare">
                            <div class="mc-cell-wrap">
                                <i data-lucide="spray-can" class="lucide mc-cell-icon"></i>
                                <div class="mc-cell-content"><?= !empty($it['prep_guidance']) ? e((string) $it['prep_guidance']) : '<span class="text-muted">—</span>' ?></div>
                            </div>
                        </div>
                        <div class="mc-ba-sched-cell mc-wg-col-disp" data-label="How to dispose">
                            <div class="mc-cell-wrap">
                                <i data-lucide="trash-2" class="lucide mc-cell-icon"></i>
                                <div class="mc-cell-content"><?= !empty($it['disposal_guidance']) ? e((string) $it['disposal_guidance']) : '<span class="text-muted">—</span>' ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
  const $s = document.getElementById('qSearch');
  if (!$s) return;
  const $rows = document.querySelectorAll('.mc-wg-row[data-id][data-item_name]');
  const $showing = document.getElementById('pubWgShowing');
  const $extra = document.getElementById('pubWgExtra');
  const $info = document.getElementById('pubWgInfo');
  const total = (function(){
    const n = parseInt(String($info?.dataset?.total || '0'), 10);
    return !isNaN(n) && n > 0 ? n : $rows.length;
  })();
  const $headCount = document.getElementById('wgListCount');
  let t;
  function runFilter() {
    const raw = String($s.value || '').trim();
    if (raw.length === 0) {
      $rows.forEach(r => r.style.display = '');
      if ($showing) $showing.textContent = String($rows.length || total);
      if ($headCount) $headCount.textContent = String($rows.length || total);
      if ($extra) $extra.textContent = '';
      return;
    }
    const q = raw.toLowerCase();
    const parts = q.split(/\s+/).filter(Boolean);
    let shown = 0;
    $rows.forEach(r => {
      const name = String(r.dataset.item_name || '').toLowerCase();
      let match = true;
      for (const p of parts) { if (name.indexOf(p) === -1) { match = false; break; } }
      if (match) { shown++; r.style.display = ''; }
      else { r.style.display = 'none'; }
    });
    if ($showing) $showing.textContent = String(shown);
    if ($headCount) $headCount.textContent = String(shown);
    if ($extra) {
      if (shown === 0) $extra.innerHTML = '· <i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> <strong class="text-danger">No matches</strong> in item name. Press Esc to clear.';
      else $extra.textContent = '· matched ' + shown + ' (press Esc to clear)';
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    }
  }
  $s.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runFilter, 80); });
  $s.addEventListener('keydown', (e) => { if (e.key === 'Escape') { $s.value = ''; runFilter(); $s.blur(); } });
  const $refreshBtn = document.getElementById('wgRefreshBtn');
  if ($refreshBtn) {
    $refreshBtn.addEventListener('click', () => { window.location.reload(); });
  }
  runFilter();
})();
</script>

<?php
require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
