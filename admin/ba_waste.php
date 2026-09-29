<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$pageTitle = 'Waste Segregation Guide (Admin)';
$activeNav = 'ba_waste';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

$endpoint = e(app_url('/admin/api/basuraalert_admin.php'));
echo csrf_header_meta();

$filterSearch = trim((string) ($_GET['search'] ?? ''));
$filterCategory = trim((string) ($_GET['category'] ?? ''));
$filterAccepted = trim((string) ($_GET['accepted'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

$where = [];
$types = '';
$params = [];

if ($filterSearch !== '') {
    $where[] = 'LOWER(w.item_name) LIKE ?';
    $types .= 's';
    $params[] = '%' . mb_strtolower($filterSearch, 'UTF-8') . '%';
}
if ($filterCategory !== '') {
    $where[] = 'w.category = ?';
    $types .= 's';
    $params[] = $filterCategory;
}
if ($filterAccepted !== '') {
    $where[] = 'w.is_accepted = ?';
    $types .= 'i';
    $params[] = (int) ($filterAccepted === '1');
}
if ($filterStatus !== '') {
    $where[] = 'w.status = ?';
    $types .= 's';
    $params[] = $filterStatus;
}

$sql = 'SELECT w.* FROM ba_waste_guide w'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY w.category ASC, w.item_name ASC';

$items = [];
try {
    if ($types === '') {
        $res = $mysqli->query($sql);
        if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;
    } else {
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            db_prepared_execute($stmt, $types, $params);
            $res = $stmt->get_result();
            if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;
            $stmt->close();
        }
    }
} catch (Throwable $e) {}

$self = e(app_url('/admin/ba_waste.php'));
$_wgTotal = count($items);
$_wgFiltered = ($filterSearch !== '' || $filterCategory !== '' || $filterAccepted !== '' || $filterStatus !== '');

/* Badge colour maps — mirror admin/ba_schedules.php so the two pages read as one system. */
$wgCategoryBadge = static fn(string $c): string => match ($c) {
    'Biodegradable'    => 'mc-admin-badge--success',
    'Recyclable'       => 'mc-admin-badge--new',
    'Special'          => 'mc-admin-badge--warning',
    'Hazardous'        => 'mc-admin-badge--danger',
    'Non-Biodegradable'=> 'mc-admin-badge--muted',
    default            => 'mc-admin-badge--muted',
};
$wgStatusBadge = static fn(string $s): string => match ($s) {
    'Published' => 'mc-admin-badge--success',
    'Archived'  => 'mc-admin-badge--muted',
    default     => 'mc-admin-badge--muted',
};

?>
<div class="mc-admin-concerns-hero">
    <div class="mc-admin-concerns-hero-inner">
        <div class="mc-admin-concerns-title-row">
            <div class="mc-admin-concerns-icon-wrap">
                <i data-lucide="leaf" class="lucide"></i>
            </div>
            <div class="mc-admin-concerns-title">
                <h1>Waste Segregation Guide</h1>
                <p>Manage the master list of items and their classification</p>
            </div>
        </div>
        <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
             alt="Official Seal of the City of Marikina"
             class="mc-admin-concerns-hero-seal" loading="eager" decoding="async">
    </div>
</div>

<div class="mc-neo-card mb-3">
    <div class="card-body" style="padding: var(--ba-space-4);">
        <!-- Row 1: Select filters + Filter (matches concerns.php / ba_schedules.php reference) -->
        <form method="GET" action="<?= $self ?>" class="mb-0" id="wgFilterForm">
            <div class="row mb-4 g-3 align-items-end">
                <div class="col-md-3">
                    <label for="wgCategory" class="form-label">Category</label>
                    <select class="form-select" id="wgCategory" name="category" style="height:48px;">
                        <option value="">All categories</option>
                        <?php foreach (['Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special'] as $c) : ?>
                            <option value="<?= $c ?>" <?= $filterCategory === $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="wgAccepted" class="form-label">Accepted in collection</label>
                    <select class="form-select" id="wgAccepted" name="accepted" style="height:48px;">
                        <option value="">All items</option>
                        <option value="1" <?= $filterAccepted === '1' ? 'selected' : '' ?>>Accepted only</option>
                        <option value="0" <?= $filterAccepted === '0' ? 'selected' : '' ?>>Not accepted only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="wgStatus" class="form-label">Status</label>
                    <select class="form-select" id="wgStatus" name="status" style="height:48px;">
                        <option value="">All statuses</option>
                        <option value="Published" <?= $filterStatus === 'Published' ? 'selected' : '' ?>>Published</option>
                        <option value="Archived" <?= $filterStatus === 'Archived' ? 'selected' : '' ?>>Archived</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2 align-items-end">
                    <button class="btn btn-primary flex-fill" type="submit" style="height:48px;"><i data-lucide="filter" class="lucide lucide-16"></i><span>Filter</span></button>
                </div>
            </div>
        </form>

        <!-- Row 2: Search + Refresh / Clear -->
        <div class="row g-3">
            <div class="col-md-8">
                <div class="mc-admin-concerns-search-wrap">
                    <i data-lucide="search" class="lucide mc-admin-concerns-search-icon"></i>
                    <input id="wgSearch" type="search" name="search" form="wgFilterForm" class="form-control mc-admin-concerns-search-input" placeholder="Item name (e.g. plastic, lata, balat)… (press Esc to clear)" autocomplete="off" spellcheck="false" style="height:48px;">
                </div>
                <div id="wgSearchInfo" class="small text-muted mt-1"></div>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="button" class="btn btn-outline-primary flex-fill" id="wgRefreshBtn" style="height:48px;">
                    <i data-lucide="refresh-cw" class="lucide lucide-16"></i>
                    <span>Refresh</span>
                </button>
                <a class="btn btn-outline-secondary flex-fill" href="<?= $self ?>" style="height:48px;display:inline-flex;align-items:center;justify-content:center;">Clear</a>
            </div>
        </div>

        <div class="mc-admin-concerns-info mt-4">
            <i data-lucide="info" class="lucide"></i>
            <span>Filter by category, acceptance, or status — the search box filters item names as you type.</span>
        </div>
    </div>
</div>

<div class="mc-admin-section-card">
    <div class="mc-admin-section-head">
        <h3><i data-lucide="recycle" class="lucide"></i> Waste Guide</h3>
        <div class="mc-wg-head-actions">
            <span class="text-muted small" id="wgCount" data-total="<?= (int) $_wgTotal ?>"><?= (int) $_wgTotal . ((int) $_wgTotal === 1 ? ' record' : ' records') ?></span>
            <?php if ($isSuper) : ?>
                <button class="btn btn-primary wg-new-btn" type="button" data-bs-toggle="modal" data-bs-target="#editor" data-mode="new"><i data-lucide="plus" class="lucide lucide-16"></i><span>New Entry</span></button>
            <?php else : ?>
                <span class="mc-admin-badge mc-admin-badge--muted"><i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> View-only</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-3 pb-0" id="listAlert"></div>

    <div class="mc-admin-table-wrap">
        <table class="table table-hover align-middle mb-0" id="wgTable">
            <thead class="table-light">
                <tr>
                    <th><span class="mc-th-wrap"><i data-lucide="package" class="lucide mc-th-icon"></i>Item</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="recycle" class="lucide mc-th-icon"></i>Category</span></th>
                    <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="check-circle-2" class="lucide mc-th-icon"></i>Accepted</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="list-checks" class="lucide mc-th-icon"></i>Prepare</span></th>
                    <th><span class="mc-th-wrap"><i data-lucide="trash-2" class="lucide mc-th-icon"></i>Dispose</span></th>
                    <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="flag" class="lucide mc-th-icon"></i>Status</span></th>
                    <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="settings" class="lucide mc-th-icon"></i>Actions</span></th>
                </tr>
            </thead>
            <tbody id="wgBody">
                <?php if ($_wgTotal === 0) : ?>
                    <tr class="wg-empty"><td colspan="7" class="text-center text-muted py-5">
                        <?php if ($_wgFiltered) : ?>
                            <i data-lucide="search-x" class="lucide-24"></i>
                            <div class="mt-2 mb-1 fw-semibold">No entries match your filters</div>
                            <div class="small">Try a different category, or <a href="<?= $self ?>" class="fw-semibold">clear the filters</a>.</div>
                        <?php else : ?>
                            <i data-lucide="leaf" class="lucide-24"></i>
                            <div class="mt-2 mb-1 fw-semibold">No waste guide entries yet</div>
                            <div class="small">Create the first one with the “New Entry” button above.</div>
                        <?php endif; ?>
                    </td></tr>
                <?php else : foreach ($items as $w) :
                    $_cat = (string) $w['category'];
                    $_st  = (string) $w['status'];
                    $_acc = !empty($w['is_accepted']);
                    $_prep = (string) ($w['prep_guidance'] ?? '');
                    $_disp = (string) ($w['disposal_guidance'] ?? '');
                ?>
                    <tr data-id="<?= (int) $w['id'] ?>"
                        data-item_name="<?= e((string) $w['item_name']) ?>"
                        data-category="<?= e($_cat) ?>"
                        data-is_accepted="<?= $_acc ? '1' : '0' ?>"
                        data-prep_guidance="<?= e($_prep) ?>"
                        data-disposal_guidance="<?= e($_disp) ?>"
                        data-status="<?= e($_st) ?>">
                        <td data-label="Item"><span class="mc-cell-wrap"><i data-lucide="package" class="lucide mc-cell-icon"></i><span class="fw-semibold"><?= e((string) $w['item_name']) ?></span></span></td>
                        <td data-label="Category"><span class="mc-cell-wrap"><i data-lucide="recycle" class="lucide mc-cell-icon"></i><span><span class="mc-admin-badge <?= $wgCategoryBadge($_cat) ?>"><?= e($_cat !== '' ? $_cat : '—') ?></span></span></span></td>
                        <td data-label="Accepted" class="mc-col-center"><span class="mc-admin-badge <?= $_acc ? 'mc-admin-badge--success' : 'mc-admin-badge--danger' ?>"><?= $_acc ? 'Accepted' : 'Not accepted' ?></span></td>
                        <td data-label="Prepare"><span class="mc-cell-wrap"><i data-lucide="list-checks" class="lucide mc-cell-icon"></i><span class="small text-muted"><?= $_prep !== '' ? e($_prep) : '—' ?></span></span></td>
                        <td data-label="Dispose"><span class="mc-cell-wrap"><i data-lucide="trash-2" class="lucide mc-cell-icon"></i><span class="small text-muted"><?= $_disp !== '' ? e($_disp) : '—' ?></span></span></td>
                        <td data-label="Status" class="mc-col-center"><span class="mc-admin-badge <?= $wgStatusBadge($_st) ?>"><?= e($_st) ?></span></td>
                        <td data-label="Actions" class="mc-col-actions">
                            <?php if ($isSuper) : ?>
                                <span class="mc-admin-concerns-actions wg-actions">
                                    <button class="wg-action-btn ba-edit-btn" type="button" data-bs-toggle="modal" data-bs-target="#editor" data-mode="edit"><i data-lucide="pencil" class="lucide"></i><span>Edit</span></button>
                                    <button class="wg-action-btn wg-action-btn--danger ba-del-btn" type="button"><i data-lucide="trash-2" class="lucide"></i><span>Delete</span></button>
                                </span>
                            <?php else : ?>
                                <span class="mc-admin-badge mc-admin-badge--muted">View-only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="p-3" id="wgHint"><div class="text-muted small">Filter by category, acceptance, or status — the search box filters item names as you type.</div></div>
</div>

<div class="modal fade mc-editor-dialog mc-editor-dialog--narrow" tabindex="-1" id="editor" aria-hidden="true" aria-labelledby="wgLbl">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="editorForm" onsubmit="event.preventDefault(); window.__baWgSave ? window.__baWgSave() : null;">
                <input type="hidden" name="action" value="save_waste">
                <input type="hidden" id="fId" name="id" value="0">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="wgLbl">New Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Item name *</label><input type="text" class="form-control" id="fName" name="item_name" maxlength="190" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label">Category *</label>
                            <select class="form-select" id="fCat" name="category" required>
                                <?php foreach (['Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special'] as $c) : ?>
                                    <option value="<?= $c ?>"><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label">Status</label>
                            <select class="form-select" id="fStatus" name="status">
                                <option value="Published">Published</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="fAcc" name="is_accepted" checked>
                            <label class="form-check-label" for="fAcc">Accepted in household collection</label>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">Preparation guidance</label><textarea class="form-control" id="fPrep" name="prep_guidance" rows="2" maxlength="255"></textarea></div>
                    <div class="mb-3"><label class="form-label">Disposal guidance</label><textarea class="form-control" id="fDisp" name="disposal_guidance" rows="3" maxlength="255"></textarea></div>
                </div>
                <div class="modal-footer border-top p-3">
                    <div id="editorAlert"></div>
                    <button class="btn btn-primary" type="submit" id="saveBtn">Save</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<'HTML'
<script>
(function () {
  const $s = document.getElementById('wgSearch');
  if (!$s) return;
  const $tbody = document.getElementById('wgBody');
  const $count = document.getElementById('wgCount');
  const $info = document.getElementById('wgSearchInfo');
  const total = parseInt(String($count?.dataset?.total || '0'), 10) || 0;
  function syncCount(shown) {
    if (!$count) return;
    if (!total) { $count.textContent = ''; return; }
    $count.textContent = (shown != null && shown !== total ? shown + ' of ' + total : total)
      + (total === 1 ? ' record' : ' records');
  }
  let t;
  function runFilter() {
    const raw = String($s.value || '').trim();
    const rows = $tbody ? $tbody.querySelectorAll('tr[data-id]') : [];
    if (raw.length === 0) {
      rows.forEach(r => r.style.display = '');
      syncCount(rows.length || total);
      if ($info) $info.textContent = '';
      return;
    }
    const q = raw.toLowerCase();
    const parts = q.split(/\s+/).filter(Boolean);
    let shown = 0;
    rows.forEach(r => {
      const name = String(r.dataset.item_name || '').toLowerCase();
      let match = true;
      for (const p of parts) { if (name.indexOf(p) === -1) { match = false; break; } }
      if (match) { shown++; r.style.display = ''; }
      else { r.style.display = 'none'; }
    });
    syncCount(shown);
    if ($info) {
      if (shown === 0) { $info.innerHTML = '· <strong class="text-danger">No matches in Item Name.</strong> Press Esc to clear.'; if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
      else $info.textContent = '· matched ' + shown + ' · Esc to clear';
    }
  }
  $s.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runFilter, 80); });
  $s.addEventListener('keydown', (e) => { if (e.key === 'Escape') { $s.value = ''; runFilter(); $s.blur(); } });
  runFilter();
  // Reference parity with concerns.php / ba_schedules.php: Refresh clears the client search.
  const $refresh = document.getElementById('wgRefreshBtn');
  if ($refresh) {
    $refresh.addEventListener('click', () => { $s.value = ''; runFilter(); $s.focus(); });
  }
})();
(function(){
  const ENDPOINT="__ENDPOINT__";
  function csrf(){var m=document.querySelector('meta[name="csrf-token"]');return m?String(m.content||""):"";}
  function eH(s){return String(s).replace(/[&<>"']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c];});}
  function setA(t,type,msg){const el=document.querySelector(t);if(!el)return;const c=type==="success"?"alert-success":type==="error"?"alert-danger":"alert-info";el.innerHTML='<div class="alert '+c+' small mb-0">'+eH(String(msg||""))+'</div>';if (typeof window.__renderLucide === 'function') window.__renderLucide();}
  const editor=document.getElementById("editor");
  if(editor)editor.addEventListener("show.bs.modal",function(ev){
    const b=ev.relatedTarget;const mode=b&&b.dataset?(b.dataset.mode||"edit"):"edit";
    const row=b&&b.closest&&b.closest("tr[data-id]")?b.closest("tr[data-id]"):null;
    const id=(mode==="new"||!row)?0:parseInt(row.getAttribute("data-id")||"0",10);
    document.getElementById("wgLbl").textContent=id>0?"Edit Entry":"New Entry";
    const f=(n,d="")=>row?String(row.getAttribute("data-"+n)||""):d;
    document.getElementById("fId").value=id;
    document.getElementById("fName").value=f("item_name");
    document.getElementById("fCat").value=f("category")||"Biodegradable";
    document.getElementById("fStatus").value=f("status")||"Published";
    document.getElementById("fAcc").checked=!!(f("is_accepted")==="1");
    document.getElementById("fPrep").value=f("prep_guidance");
    document.getElementById("fDisp").value=f("disposal_guidance");
    document.getElementById("editorAlert").innerHTML="";if (typeof window.__renderLucide === 'function') window.__renderLucide();
  });
  window.__baWgSave=function(){
    const b=document.getElementById("saveBtn");if(!b||b.dataset.submitting==="1")return;
    b.dataset.submitting="1";b.disabled=true;const ot=b.textContent;b.textContent="Saving…";
    $.ajax({url:ENDPOINT,method:"POST",data:$("#editorForm").serialize()+"&csrf_token="+encodeURIComponent(csrf()),dataType:"json",timeout:60000})
      .done(function(r){if(r&&r.ok){setA("#editorAlert","success",(r.message||"Saved")+" Reloading…");setTimeout(function(){location.reload();},500);}
        else{setA("#editorAlert","error",r&&r.error?r.error:"Save failed.");b.dataset.submitting="0";b.disabled=false;b.textContent=ot;}})
      .fail(function(){setA("#editorAlert","error","Network error.");b.dataset.submitting="0";b.disabled=false;b.textContent=ot;});
  };
  document.addEventListener("click",function(ev){
    const t=ev.target;
    // Use closest() so the click still registers when the target is the
    // Lucide <svg>/<path> rendered inside the Delete button.
    if(t&&t.closest&&t.closest(".ba-del-btn")){
      const row=t.closest("tr[data-id]");if(!row)return;const id=parseInt(row.getAttribute("data-id")||"0",10);if(!id)return;
      if(!confirm("Delete this waste guide entry?"))return;
      $.ajax({url:ENDPOINT,method:"POST",data:{action:"delete_waste",id,csrf_token:csrf()},dataType:"json",timeout:30000})
        .done(function(r){if(r&&r.ok){setA("#listAlert","success",r.message||"Deleted.");setTimeout(function(){location.reload();},400);}
          else setA("#listAlert","error",r&&r.error?r.error:"Delete failed.");})
        .fail(function(){setA("#listAlert","error","Network error.");});
    }});
})();
</script>
HTML;
$pageScripts = str_replace("__ENDPOINT__", $endpoint, $pageScripts);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
