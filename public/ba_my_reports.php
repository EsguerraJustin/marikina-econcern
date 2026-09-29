<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

require_login();

$mysqli = null;
try {
    $mysqli = db();
} catch (Throwable $e) {
    _auth_diag('ba_my_reports_db_connect_failed', ['error' => $e->getMessage()]);
    ensure_session_started();
    $_SESSION['flash_error'] = 'Temporarily unable to load reports due to a database connection issue. Please try again in a moment.';
    redirect(app_url('/public/dashboard.php'));
}

$user = current_user($mysqli);
if (!is_array($user)) {
    logout_user();
    ensure_session_started();
    $_SESSION['flash_error'] = 'Your session is no longer valid. Please log in again.';
    redirect(app_url('/public/login.php'));
}

csrf_check();

$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_report_rating') {
    $id = (int) ($_POST['report_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : null;
    if ($rating < 1 || $rating > 5) $rating = 0;
    if ($id > 0 && $rating >= 1 && $rating <= 5 && $mysqli instanceof mysqli) {
        $report = ba_get_report($mysqli, $id);
        if ($report && (int) $report['user_id'] === $userId) {
            ba_save_report_rating($mysqli, $id, $userId, $rating, $comment);
        }
    }
    redirect(app_url('/public/ba_my_reports.php?id=' . ($id > 0 ? $id : 0)));
}

$pageTitle = 'My Reports';
$activeNav = 'ba_my_reports';

$status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$category = isset($_GET['category']) ? (string) $_GET['category'] : '';
$barangay = isset($_GET['barangay']) ? trim((string) $_GET['barangay']) : '';
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$statusNormalized = ($status !== '') ? ba_normalize_report_status($status) : '';

$_brgys = $mysqli instanceof mysqli ? ba_list_barangays($mysqli) : [];
$_brgyFilter = null;
if ($barangay !== '') {
    $bnum = (int) $barangay;
    if ($bnum > 0) $_brgyFilter = $bnum;
}
$_catMap = ba_report_category_options();
$_catKeys = array_keys($_catMap);
$_catFilter = ($category !== '' && in_array($category, $_catKeys, true)) ? $category : null;

$_statusDbFilter = ($statusNormalized !== '') ? $statusNormalized : null;
$reports = $mysqli instanceof mysqli ? ba_list_reports($mysqli, $userId, $_statusDbFilter, $_brgyFilter, $_catFilter, 1000) : [];
$_repTotal = count($reports);

$actionsEndpoint = e(app_url('/api/basuraalert_actions.php'));
$_self = e(app_url('/public/ba_my_reports.php'));

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$statusBadgeClass = static fn($s) => ba_report_status_badge_class(ba_normalize_report_status((string)$s));

$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$viewReport = null;
$viewTimeline = [];
$viewRating = null;
if ($viewId > 0 && $mysqli instanceof mysqli) {
    $viewReport = ba_get_report($mysqli, $viewId);
    if ($viewReport && (int) $viewReport['user_id'] !== $userId) {
        $viewReport = null;
    }
    if ($viewReport) {
        $viewTimeline = array_values(array_filter(
            ba_get_report_timeline($mysqli, $viewId),
            static fn($t) => empty($t['is_internal'])
        ));
        $viewReport['status'] = ba_normalize_report_status((string) $viewReport['status']);
        $viewRating = ba_get_report_rating($mysqli, $viewId);
    }
}

?>
<?= csrf_header_meta() ?>

<div class="mc-ba-my-reports-page">
<!-- ================= PAGE HERO CARD ================= -->
<div class="mc-ba-my-reports-hero">
    <div class="mc-ba-my-reports-hero-inner">
        <div class="mc-ba-my-reports-hero-title-row">
            <div class="mc-ba-my-reports-hero-icon-wrap">
                <i data-lucide="clipboard-list" class="lucide lucide-24"></i>
            </div>
            <div class="mc-ba-my-reports-hero-title">
                <h1 class="mb-0">My Reports</h1>
                <p class="mb-0 text-muted" style="margin-top:2px;">Track BasuraAlert issue reports and their status</p>
            </div>
        </div>
        <div class="mc-ba-my-reports-hero-brand">
            <a class="btn btn-primary mc-ba-my-reports-new-btn" href="<?= e(app_url('/public/ba_report.php')) ?>">
                <i data-lucide="plus" class="lucide lucide-16"></i><span>New Report</span>
            </a>
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" width="36" height="36" loading="eager" decoding="async" class="mc-ba-my-reports-brand-img" style="margin-left:auto;margin-top:10px;">
        </div>
    </div>
</div>

<?php if ($viewReport) :
    $statusClass = $statusBadgeClass($viewReport['status']);
    $photos = [];
    if (!empty($viewReport['photos_json'])) {
        $decoded = json_decode($viewReport['photos_json'], true);
        if (is_array($decoded)) $photos = $decoded;
    }
?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <div>
                <a class="small text-decoration-none" href="<?= e(app_url('/public/ba_my_reports.php')) ?>">← Back to list</a>
                <div class="h5 fw-bold mb-0 mt-1">Report <?= e((string) $viewReport['report_number']) ?></div>
            </div>
            <span class="badge <?= $statusClass ?>"><?= e(ba_report_status_label((string) $viewReport['status'])) ?></span>
        </div>
        <div class="card-body p-4">
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="small text-muted">Category</div><div class="fw-semibold"><?= e(ba_report_category_label((string) $viewReport['category'])) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Barangay</div><div class="fw-semibold"><?= e((string) ($viewReport['barangay_name'] ?? '')) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Date of Concern</div><div class="fw-semibold"><?= date('F j, Y', strtotime((string) $viewReport['date_of_concern'])) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Submitted</div><div class="fw-semibold"><?= date('M j, Y g:i A', strtotime((string) $viewReport['created_at'])) ?></div></div>
            </div>
            <?php if (!empty($viewReport['street']) || !empty($viewReport['landmark'])) : ?>
                <div class="row g-3 mb-3">
                    <?php if (!empty($viewReport['street'])) : ?>
                        <div class="col-md-6"><div class="small text-muted">Street</div><div><?= e((string) $viewReport['street']) ?></div></div>
                    <?php endif; ?>
                    <?php if (!empty($viewReport['landmark'])) : ?>
                        <div class="col-md-6"><div class="small text-muted">Landmark</div><div><?= e((string) $viewReport['landmark']) ?></div></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="mb-3"><div class="small text-muted mb-1">Description</div><div style="white-space:pre-wrap;line-height:1.65"><?= e((string) $viewReport['description']) ?></div></div>
            <?php if (count($photos) > 0) : ?>
                <div class="mb-3"><div class="small text-muted mb-2">Photos</div><div class="row g-2"><?php foreach ($photos as $ph) : $phUrl = ba_resolve_photo_url((string) $ph); ?><div class="col-6 col-md-3"><a href="<?= e($phUrl) ?>" target="_blank" rel="noopener"><img src="<?= e($phUrl) ?>" class="img-fluid rounded border" alt="report photo"></a></div><?php endforeach; ?></div></div>
            <?php endif; ?>
            <?php if (!empty($viewReport['resolution_note'])) : ?>
                <div class="mr-note mr-note--resolution">
                    <div class="small text-muted">Administrator resolution (public)</div>
                    <div class="fw-semibold"><?= e((string) $viewReport['resolution_note']) ?></div>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <div class="fw-bold mb-2">Activity Timeline (Public updates only)</div>
                <?php if (count($viewTimeline) === 0) : ?>
                    <div class="text-muted small">No timeline entries yet.</div>
                <?php else : ?>
                    <ul class="list-group list-group-flush border rounded">
                    <?php foreach ($viewTimeline as $t) :
                        $tStatus = ba_normalize_report_status((string) ($t['status'] ?? 'New'));
                        $tc = $statusBadgeClass($tStatus);
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge <?= $tc ?> me-2"><?= e(ba_report_status_label($tStatus)) ?></span>
                                <span><?= e((string) ($t['note'] ?? '')) ?></span>
                                <?php if (!empty($t['admin_name'])) : ?><div class="small text-muted">by <?= e((string) $t['admin_name']) ?></div><?php endif; ?>
                            </div>
                            <div class="text-muted small ms-2"><?= date('M j g:i A', strtotime((string) $t['created_at'])) ?></div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if (in_array($viewReport['status'], ['Completed','Rejected'], true) && !is_array($viewRating)) : ?>
                <form method="POST" class="border rounded p-3" id="ratingFormBlock">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_report_rating">
                    <input type="hidden" name="report_id" value="<?= (int) $viewReport['id'] ?>">
                    <div class="fw-semibold small mb-2">Was this resolution helpful? (1–5 stars)</div>
                    <div class="row g-2 mb-3 align-items-center">
                        <div class="col-md-8">
                            <div class="d-flex flex-wrap gap-2" id="ratingStarGroup">
                                <?php for ($r = 1; $r <= 5; $r++) : ?>
                                    <label class="btn btn-outline-warning btn-sm mb-0 rating-star-label" data-rating-value="<?= $r ?>" style="cursor:pointer;">
                                        <input type="radio" name="rating" value="<?= $r ?>" required class="visually-hidden">
                                        <span class="rating-star-span"><?php for ($_s = 1; $_s <= $r; $_s++) : ?><i data-lucide="star" class="lucide-14 text-warning"></i><?php endfor; ?><span class="rating-empty opacity-50"><?php for ($_s = 1; $_s <= 5 - $r; $_s++) : ?><i data-lucide="star" class="lucide-14 text-warning"></i><?php endfor; ?></span></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                            <div class="small text-muted mt-1" id="ratingCaption">Pick a rating between 1 (not helpful) and 5 (very helpful).</div>
                        </div>
                        <div class="col-md-4 d-grid"><button type="submit" class="btn btn-primary btn-sm" id="ratingSubmitBtn" disabled>Submit Rating</button></div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small text-muted" for="rating-comment">Optional comment (max 1000 characters)</label>
                        <textarea id="rating-comment" name="comment" rows="2" maxlength="1000" class="form-control" placeholder="Anything we should know for next time?"></textarea>
                    </div>
                </form>
            <?php elseif (is_array($viewRating)) : ?>
                <div class="mr-note mr-note--rating">
                    <div class="small text-muted">Your helpfulness rating</div>
                    <div class="fw-bold fs-5 text-warning"><?php $_vr = (int) $viewRating['rating']; for ($_s = 1; $_s <= $_vr; $_s++) : ?><i data-lucide="star" class="lucide-14 text-warning"></i><?php endfor; ?><span class="opacity-50"><?php for ($_s = 1; $_s <= 5 - $_vr; $_s++) : ?><i data-lucide="star" class="lucide-14 text-warning"></i><?php endfor; ?></span> <span class="text-muted fs-6 fw-normal ms-2"><?= $_vr ?>/5</span></div>
                    <?php if (!empty($viewRating['comment'])) : ?><div class="small mt-1" style="white-space:pre-wrap">Comment: <?= e((string) $viewRating['comment']) ?></div><?php endif; ?>
                    <div class="small text-muted mt-1">Rated on <?= date('M j, Y g:i A', strtotime((string) $viewRating['rated_at'])) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else : ?>

    <!-- ================= UNIFIED FILTER + SEARCH CARD ================= -->
    <form method="GET" class="mc-neo-card mb-4" id="repFilterForm" action="<?= $_self ?>">
        <div class="card-body" style="padding: var(--ba-space-4);">
            <!-- Row 1: Select filters (Category / Barangay / Status) + Apply -->
            <div class="row mb-4 g-3 align-items-end">
                <div class="col-md-3">
                    <label for="repCat" class="form-label">Category</label>
                    <select class="form-select" name="category" id="repCat">
                        <option value="">All categories</option>
                        <?php foreach ($_catMap as $catKey => $catLabel) : ?>
                            <option value="<?= e($catKey) ?>" <?= $category === $catKey ? 'selected' : '' ?>><?= e($catLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="repBrgy" class="form-label">Barangay</label>
                    <select class="form-select" name="barangay" id="repBrgy">
                        <option value="">All barangays</option>
                        <?php foreach ($_brgys as $b) : ?>
                            <option value="<?= (int) $b['id'] ?>" <?= $_brgyFilter === (int) $b['id'] ? 'selected' : '' ?>><?= e((string) $b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="repStatus" class="form-label">Status</label>
                    <select class="form-select" name="status" id="repStatus">
                        <option value="">All statuses</option>
                        <?php foreach (ba_report_status_options() as $s) : ?>
                            <option value="<?= e($s) ?>" <?= $statusNormalized === $s ? 'selected' : '' ?>><?= e(ba_report_status_label($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i data-lucide="filter" class="lucide lucide-16"></i><span>Apply</span>
                    </button>
                    <?php if ($search . $category . $barangay . $status !== '') : ?>
                        <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/ba_my_reports.php')) ?>">
                            <i data-lucide="x-circle" class="lucide lucide-16"></i><span>Clear</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Row 2: Search -->
            <div class="row g-3">
                <div class="col-md-12">
                    <label for="repSearch" class="form-label d-flex justify-content-between gap-2 mb-1">
                        <span class="fw-semibold small text-muted">Search</span>
                        <span class="small text-muted fw-normal">Press Esc to clear</span>
                    </label>
                    <div class="mc-ba-my-reports-search-wrap">
                        <i data-lucide="search" class="lucide lucide-20 mc-ba-my-reports-search-icon"></i>
                        <input id="repSearch" type="search" name="q" class="form-control" placeholder="Ref # (e.g. RPT-0042) or keywords… (instant filter)" autocomplete="off" spellcheck="false" value="<?= e($search) ?>">
                    </div>
                </div>
            </div>

            <!-- Row 3: Info line -->
            <div class="mc-ba-my-reports-info mt-4" id="repInfoBar" data-total="<?= $_repTotal ?>">
                <i data-lucide="info" class="lucide lucide-16" style="color:var(--ba-text-muted);"></i>
                <span>Showing <strong id="repShowingCount"><?= $_repTotal ?></strong> of <strong><?= $_repTotal ?></strong> reports
                <?php if ($search !== '' || $category !== '' || $barangay !== '' || $status !== '') : ?> <span class="text-muted">(filtered)</span><?php endif; ?>
                <span id="repInfoExtra" class="ms-1"></span></span>
            </div>
        </div>
    </form>

    <!-- ================= REPORTS LIST PURE DIV GRID TABLE ================= -->
    <div class="mc-neo-card">
        <div class="card-body p-0">
            <div class="mc-ba-my-reports-table-wrap mc-stacked-card">
                <div class="mc-ba-my-reports-table">
                    <div class="mc-my-rep-row mc-my-rep-head">
                        <div class="mc-my-rep-cell mc-my-rep-col-ref"><span class="mc-th-wrap"><i data-lucide="hash" class="lucide mc-th-icon"></i>Reference</span></div>
                        <div class="mc-my-rep-cell mc-my-rep-col-cat"><span class="mc-th-wrap"><i data-lucide="tag" class="lucide mc-th-icon"></i>Category</span></div>
                        <div class="mc-my-rep-cell mc-my-rep-col-date"><span class="mc-th-wrap"><i data-lucide="calendar" class="lucide mc-th-icon"></i>Date of Concern</span></div>
                        <div class="mc-my-rep-cell mc-my-rep-col-brgy"><span class="mc-th-wrap"><i data-lucide="map-pin" class="lucide mc-th-icon"></i>Barangay</span></div>
                        <div class="mc-my-rep-cell mc-my-rep-col-status"><span class="mc-th-wrap"><i data-lucide="activity" class="lucide mc-th-icon"></i>Status</span></div>
                        <div class="mc-my-rep-cell mc-my-rep-col-submitted"><span class="mc-th-wrap"><i data-lucide="clock" class="lucide mc-th-icon"></i>Submitted</span></div>
                        <div class="mc-my-rep-cell mc-my-rep-col-action"></div>
                    </div>
                    <?php if (count($reports) === 0) : ?>
                        <div class="mc-my-rep-row">
                            <div class="mc-my-rep-cell mc-ba-sched-empty" style="grid-column:1 / -1">
                                <?php if ($search !== '' || $category !== '' || $barangay !== '' || $status !== '') : ?>
                                    No reports match your filters. <a href="<?= $_self ?>" class="fw-semibold text-decoration-none">Clear filters →</a>
                                <?php else : ?>
                                    No reports yet.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else : ?>
                    <div class="mc-ba-my-reports-tbody" id="repBody">
                        <?php foreach ($reports as $r) :
                            $rStatus = ba_normalize_report_status((string) $r['status']);
                            $rn = (string) $r['report_number'];
                            $desc = (string) ($r['description'] ?? '');
                            $street = (string) ($r['street'] ?? '');
                            $landmark = (string) ($r['landmark'] ?? '');
                            $brgyName = (string) ($r['barangay_name'] ?? '');
                            $catLabel = ba_report_category_label((string) $r['category']);
                            $searchHay = mb_strtolower(trim($rn . ' ' . $desc . ' ' . $street . ' ' . $landmark . ' ' . $catLabel . ' ' . $brgyName), 'UTF-8');
                        ?>
                            <div class="mc-my-rep-row"
                                 data-id="<?= (int) $r['id'] ?>"
                                 data-ref="<?= e(mb_strtolower($rn, 'UTF-8')) ?>"
                                 data-category="<?= e((string) $r['category']) ?>"
                                 data-barangay_id="<?= (int) ($r['barangay_id'] ?? 0) ?>"
                                 data-status="<?= e($rStatus) ?>"
                                 data-search="<?= e($searchHay) ?>">
                                <div class="mc-my-rep-cell mc-my-rep-col-ref" data-label="Reference">
                                    <div class="mc-cell-wrap">
                                        <div class="mc-cell-content">
                                            <a href="<?= e(app_url('/public/ba_my_reports.php?id=' . (int) $r['id'])) ?>" class="fw-semibold text-decoration-none"><?= e($rn) ?></a>
                                        </div>
                                    </div>
                                </div>
                                <div class="mc-my-rep-cell mc-my-rep-col-cat" data-label="Category">
                                    <div class="mc-cell-wrap">
                                        <div class="mc-cell-content"><?= e($catLabel) ?></div>
                                    </div>
                                </div>
                                <div class="mc-my-rep-cell mc-my-rep-col-date" data-label="Date of Concern">
                                    <div class="mc-cell-wrap">
                                        <div class="mc-cell-content"><?= date('M j, Y', strtotime((string) $r['date_of_concern'])) ?></div>
                                    </div>
                                </div>
                                <div class="mc-my-rep-cell mc-my-rep-col-brgy" data-label="Barangay">
                                    <div class="mc-cell-wrap">
                                        <div class="mc-cell-content"><?= e($brgyName) ?></div>
                                    </div>
                                </div>
                                <div class="mc-my-rep-cell mc-my-rep-col-status" data-label="Status">
                                    <div class="mc-cell-wrap">
                                        <div class="mc-cell-content"><span class="badge <?= $statusBadgeClass($rStatus) ?>"><?= e(ba_report_status_label($rStatus)) ?></span></div>
                                    </div>
                                </div>
                                <div class="mc-my-rep-cell mc-my-rep-col-submitted" data-label="Submitted">
                                    <div class="mc-cell-wrap">
                                        <div class="mc-cell-content small text-muted"><?= date('M j, Y', strtotime((string) $r['created_at'])) ?></div>
                                    </div>
                                </div>
                                <div class="mc-my-rep-cell mc-my-rep-col-action">
                                    <div class="mc-cell-wrap" style="justify-content:flex-end;">
                                        <a class="btn btn-sm btn-outline-secondary ba-pub-ref-btn" href="<?= e(app_url('/public/ba_my_reports.php?id=' . (int) $r['id'])) ?>">View</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php if (in_array(($viewReport['status'] ?? ''), ['Completed','Rejected'], true) && !is_array($viewRating)) : ?>
<style>
/* Rule #14: flat fill + 1px border only. The previous rule carried a
   translateY(-1px) hover lift and a 4px amber glow ring; the admin peer
   (admin/ba_reports.css:102) uses filter:brightness() on :active instead. */
#ratingStarGroup .rating-star-label.active,
#ratingStarGroup .rating-star-label:hover {
  background-color: var(--ba-warning-light) !important;
  border-color: var(--ba-warning) !important;
  color: #7A4A10 !important;
  box-shadow: none;
  filter: brightness(.98);
}
#ratingStarGroup .rating-star-label {
  min-height: 40px;
  transition: background-color .15s ease, border-color .15s ease, color .15s ease, filter .15s ease;
}
#ratingStarGroup .rating-star-label .rating-empty {
  transition: opacity .15s ease;
}
#ratingStarGroup .rating-star-label.preview .rating-empty,
#ratingStarGroup .rating-star-label.active .rating-empty,
#ratingStarGroup .rating-star-label:hover .rating-empty {
  opacity: 0.15 !important;
}
</style>
<script>
(function(){
  var group = document.getElementById("ratingStarGroup");
  var caption = document.getElementById("ratingCaption");
  var btn = document.getElementById("ratingSubmitBtn");
  if (!group) return;
  var labels = group.querySelectorAll(".rating-star-label");
  var captions = ["","Not helpful at all","Slightly helpful","Somewhat helpful","Helpful","Very helpful — great job!"];
  function setPreview(val) {
    labels.forEach(function(l){
      var v = parseInt(l.getAttribute("data-rating-value")||"0",10);
      l.classList.toggle("preview", v <= val);
    });
  }
  labels.forEach(function(l){
    l.addEventListener("mouseenter", function(){
      var v = parseInt(l.getAttribute("data-rating-value")||"0",10);
      setPreview(v);
      if (caption) caption.textContent = captions[v] || "";
    });
    l.addEventListener("mouseleave", function(){
      var sel = group.querySelector(".active");
      var v = sel ? parseInt(sel.getAttribute("data-rating-value")||"0",10) : 0;
      setPreview(v);
      if (caption) caption.textContent = v>0 ? (captions[v] || "") : "Pick a rating between 1 (not helpful) and 5 (very helpful).";
    });
    var rad = l.querySelector('input[type="radio"][name="rating"]');
    if (rad) rad.addEventListener("change", function(){
      labels.forEach(function(x){ x.classList.remove("active"); });
      l.classList.add("active");
      var v = parseInt(l.getAttribute("data-rating-value")||"0",10);
      setPreview(v);
      if (btn) btn.disabled = false;
      if (caption) caption.textContent = (captions[v] || "") + (v>0 ? (" — Rating: " + v + "/5. Ready to submit.") : "");
    });
  });
})();
</script>
<?php endif; ?>
</div>

<script>
(function () {
  const $s = document.getElementById('repSearch');
  const $body = document.getElementById('repBody');
  if (!$s || !$body) return;
  const $showing = document.getElementById('repShowingCount');
  const $extra = document.getElementById('repInfoExtra');
  const totalRows = (function(){
    const info = document.getElementById('repInfoBar');
    const n = parseInt(String(info?.dataset?.total || '0'), 10);
    return !isNaN(n) && n > 0 ? n : $body.querySelectorAll('.mc-my-rep-row[data-id]').length;
  })();
  let t;
  function runFilter() {
    const raw = String($s.value || '').trim();
    const rows = $body.querySelectorAll('.mc-my-rep-row[data-id]');
    if (raw.length === 0) {
      rows.forEach(r => r.style.display = '');
      if ($showing) $showing.textContent = String(totalRows);
      if ($extra) $extra.textContent = '';
      return;
    }
    const q = raw.toLowerCase();
    const parts = q.split(/\s+/).filter(Boolean);
    let shown = 0;
    rows.forEach(r => {
      const hay = String(r.dataset.search || '').toLowerCase();
      const ref = String(r.dataset.ref || '').toLowerCase();
      let match = true;
      for (const p of parts) {
        if (hay.indexOf(p) === -1 && ref.indexOf(p) === -1) { match = false; break; }
      }
      if (match) { shown++; r.style.display = ''; }
      else { r.style.display = 'none'; }
    });
    if ($showing) $showing.textContent = String(shown);
    if ($extra) {
      if (shown === 0) $extra.innerHTML = '· <strong class="text-danger">No matches.</strong> Press Esc to clear or adjust filters.';
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      else $extra.textContent = '· matched ' + shown + ' · Esc to clear';
    }
  }
  $s.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runFilter, 80); });
  $s.addEventListener('keydown', (e) => { if (e.key === 'Escape') { $s.value = ''; runFilter(); $s.blur(); } });
  runFilter();
})();
</script>

<?php
$pageScripts = <<<'LUCIDEPOLL'
<script>
(function(){
var __lp=0;
function __renderSafe(){
  if (typeof window.__renderLucide==='function'){try{window.__renderLucide();}catch(_){}return true;}
  if (window.lucide&&typeof window.lucide.createIcons==='function'){try{window.lucide.createIcons({attrs:{width:16,height:16,'stroke-width':2,'fill':'none','stroke-linecap':'round','stroke-linejoin':'round'},nameAttr:'data-lucide'});}catch(_){}return true;}
  return false;
}
function __poll(){if(__renderSafe())return;if(__lp++<30)setTimeout(__poll,100);}
__poll();
window.addEventListener('load',function(){setTimeout(__renderSafe,120);},{once:true});
window.addEventListener('pageshow',function(){setTimeout(__renderSafe,150);});
})();
</script>
LUCIDEPOLL;

require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
