<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$pageTitle = 'Resident Reports (Resolution)';
$activeNav = 'ba_reports';

csrf_check();

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

$endpoint = e(app_url('/admin/api/basuraalert_admin.php'));
echo csrf_header_meta();

$statusFilter = isset($_GET['status']) ? (string) $_GET['status'] : '';
$categoryFilter = isset($_GET['category']) ? (string) $_GET['category'] : '';
$barangayFilter = isset($_GET['barangay_id']) ? (int) $_GET['barangay_id'] : 0;

$reports = ba_list_reports(
    $mysqli,
    null,
    $statusFilter !== '' ? $statusFilter : null,
    $barangayFilter > 0 ? $barangayFilter : null,
    $categoryFilter !== '' ? $categoryFilter : null,
    500
);

$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$viewReport = null;
$viewTimeline = [];
if ($viewId > 0) {
    $viewReport = ba_get_report($mysqli, $viewId);
    if ($viewReport) $viewTimeline = ba_get_report_timeline($mysqli, $viewId);
}

$barangays = ba_list_barangays($mysqli);

$summary = [];
try {
    $res = $mysqli->query("SELECT status, COUNT(*) c FROM ba_reports GROUP BY status");
    if ($res) while ($r = $res->fetch_assoc()) {
        $appKey = ba_normalize_report_status((string) $r['status']);
        if (!isset($summary[$appKey])) $summary[$appKey] = 0;
        $summary[$appKey] += (int) $r['c'];
    }
} catch (Throwable $e) {}

$rating = $viewId > 0 ? ba_get_report_rating($mysqli, $viewId) : null;

/* Rating badges for the list queue: one query for all visible reports so the
   table can flag rated items without N+1 lookups. */
$brpRatings = [];
try {
    $brpIds = [];
    foreach ($reports as $brpRow) $brpIds[] = (int) ($brpRow['id'] ?? 0);
    $brpIds = array_values(array_unique(array_filter($brpIds)));
    if ($brpIds !== []) {
        $brpIn = implode(',', $brpIds);
        $brpRes = $mysqli->query('SELECT report_id, rating FROM ba_report_ratings WHERE report_id IN (' . $brpIn . ')');
        if ($brpRes) while ($brpR = $brpRes->fetch_assoc()) {
            $brpRatings[(int) $brpR['report_id']] = (int) $brpR['rating'];
        }
    }
} catch (Throwable $e) {}

/* ---- Reference-design helpers ------------------------------------
   ba_report_status_badge_class() stays UNTOUCHED: public/ba_my_reports.php
   and the resident activity timeline still depend on its Bootstrap classes.
   Every admin surface on this page (list pill, detail header, timeline dot,
   stepper) uses the flat pill map below instead, so both views of the same
   report always look identical. */
$brpPillClass = static fn($s): string => match (ba_normalize_report_status((string) $s)) {
    'New'          => 'mc-admin-badge--new',
    'Acknowledged' => 'mc-admin-badge--warning',
    'In Progress'  => 'mc-admin-badge--new',
    'Completed'    => 'mc-admin-badge--success',
    'Rejected'     => 'mc-admin-badge--danger',
    default        => 'mc-admin-badge--muted',
};
/* KPI tile icon + tint per status. */
$brpKpiIcon = static fn($s): string => match (ba_normalize_report_status((string) $s)) {
    'New'          => 'inbox',
    'Acknowledged' => 'eye',
    'In Progress'  => 'loader',
    'Completed'    => 'check-circle-2',
    'Rejected'     => 'ban',
    default        => 'inbox',
};
$brpKpiTint = static fn($s): string => match (ba_normalize_report_status((string) $s)) {
    'New'          => 'blue',
    'Acknowledged' => 'amber',
    'In Progress'  => 'mint',
    'Completed'    => 'mint',
    'Rejected'     => 'red',
    default        => 'blue',
};
$selfUrl = e(app_url('/admin/ba_reports.php'));
$brpTotal = count($reports);

/* Keep the active list filters alive when opening a report and when coming
   back, so an admin working a filtered queue never loses their place.
   $selfUrl stays filter-free on purpose — it is the GET form action and the
   "Clear filters" target. */
$brpFilterQs = [];
if ($statusFilter !== '')   $brpFilterQs[] = 'status=' . rawurlencode($statusFilter);
if ($categoryFilter !== '') $brpFilterQs[] = 'category=' . rawurlencode($categoryFilter);
if ($barangayFilter > 0)    $brpFilterQs[] = 'barangay_id=' . $barangayFilter;
$brpListUrl = app_url('/admin/ba_reports.php') . ($brpFilterQs ? '?' . implode('&', $brpFilterQs) : '');
$brpListUrlEsc = e($brpListUrl);
$brpResolveUrl = static function (int $id) use ($brpListUrl): string {
    return e($brpListUrl . (str_contains($brpListUrl, '?') ? '&' : '?') . 'id=' . $id);
};
?>
<?php if ($viewReport) :
    $viewReport['status'] = ba_normalize_report_status((string) $viewReport['status']);
    $viewStatus       = (string) $viewReport['status'];
    $viewCategory     = ba_report_category_label((string) $viewReport['category']);
    $viewBarangay     = trim((string) ($viewReport['barangay_name'] ?? ''));
    $viewSubmitted    = (string) $viewReport['created_at'];
    $viewDateConcern  = (string) $viewReport['date_of_concern'];
    $viewStreet       = trim((string) ($viewReport['street'] ?? ''));
    $viewLandmark     = trim((string) ($viewReport['landmark'] ?? ''));
    $viewName         = trim((string) ($viewReport['user_full_name'] ?? ''));
    $viewMobile       = trim((string) ($viewReport['user_mobile'] ?? ''));
    $viewEmail        = trim((string) ($viewReport['user_email'] ?? ''));
    $viewResolution   = trim((string) ($viewReport['resolution_note'] ?? ''));
    $viewInternal     = trim((string) ($viewReport['internal_handling_note'] ?? ''));
    $photos = [];
    if (!empty($viewReport['photos_json'])) {
        $d = json_decode($viewReport['photos_json'], true);
        // photos_json is resident-supplied: keep only plain strings so the
        // render loop below can never hit a nested array/object.
        if (is_array($d)) $photos = array_values(array_filter($d, 'is_string'));
    }
?>

    <!-- ==================== DETAIL HEADER ==================== -->
    <div class="mc-brp-d-header">
        <div class="mc-brp-d-header-top">
            <a class="mc-brp-d-back" href="<?= $brpListUrlEsc ?>">
                <i data-lucide="arrow-left" class="lucide"></i><span>Back to Resident Reports</span>
            </a>
            <span class="mc-admin-badge <?= $brpPillClass($viewStatus) ?>" title="<?= e(ba_report_status_label($viewStatus)) ?>"><?= e(ba_report_status_label($viewStatus)) ?></span>
            <?php if (is_array($rating)) : ?>
                <a class="mc-admin-badge mc-admin-badge--warning" href="#resident-feedback" title="Resident rated <?= (int) $rating['rating'] ?> out of 5"><i data-lucide="star" class="lucide"></i><?= (int) $rating['rating'] ?>/5 rated</a>
            <?php endif; ?>
        </div>

        <div class="mc-admin-concerns-title-row">
            <div class="mc-admin-concerns-icon-wrap">
                <i data-lucide="clipboard-list" class="lucide"></i>
            </div>
            <div class="mc-admin-concerns-title">
                <div class="mc-brp-d-refline">
                    <span class="brp-ref"><?= e((string) $viewReport['report_number']) ?></span>
                    <?php if ($viewBarangay !== '') : ?>
                        <span class="mc-brp-d-tag"><i data-lucide="map-pin" class="lucide"></i><?= e($viewBarangay) ?></span>
                    <?php endif; ?>
                </div>
                <h1><?= e($viewCategory) ?></h1>
                <p>Submitted <?= e(date('F j, Y g:i A', strtotime($viewSubmitted))) ?></p>
            </div>
        </div>
        
        <!-- Mandatory resolution flow: New → Acknowledged → In Progress → Completed / Rejected -->
        <ol class="mc-brp-d-stepper<?= $viewStatus === 'Rejected' ? ' mc-brp-d-stepper--rejected' : '' ?>" aria-label="Mandatory resolution flow">
        <?php
        $brpFlow    = ['New' => 'inbox', 'Acknowledged' => 'eye', 'In Progress' => 'loader', 'Completed' => 'check-circle-2'];
        $brpFlowPos = ['New' => 0, 'Acknowledged' => 1, 'In Progress' => 2, 'Completed' => 3, 'Rejected' => 3];
        $brpPos     = $brpFlowPos[$viewStatus] ?? 0;
        $brpLastIdx = count($brpFlow) - 1;
        foreach ($brpFlow as $brpStep => $brpIcon) :
            $brpI     = (int) array_search($brpStep, array_keys($brpFlow), true);
            $brpState = $brpI < $brpPos ? 'is-done' : ($brpI === $brpPos ? 'is-current' : 'is-todo');
            $brpLabel = ($brpI === $brpLastIdx && $viewStatus === 'Rejected') ? 'Rejected' : ba_report_status_label($brpStep);
        ?>
            <li class="mc-brp-d-step <?= $brpState ?>"<?= $brpState === 'is-current' ? ' aria-current="step"' : '' ?>>
                <span class="mc-brp-d-step-dot"><i data-lucide="<?= e($brpIcon) ?>" class="lucide"></i></span>
                <span class="mc-brp-d-step-label"><?= e($brpLabel) ?></span>
            </li>
        <?php endforeach; ?>
        </ol>
    </div>

    <div class="mc-brp-d-grid">

        <!-- ==================== MAIN COLUMN ==================== -->
        <div class="mc-brp-d-main">

            <div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="clipboard-list" class="lucide"></i> Report Details</h3>
                    <div class="mc-brp-head-actions"><span class="text-muted small">Ref <?= e((string) $viewReport['report_number']) ?></span></div>
                </div>
                <div class="mc-brp-d-body">
                    <div class="mc-brp-d-facts">
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Category</span>
                            <span class="mc-brp-d-fact-value"><?= e($viewCategory) ?></span>
                        </div>
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Barangay</span>
                            <span class="mc-brp-d-fact-value"><?= e($viewBarangay !== '' ? $viewBarangay : '—') ?></span>
                        </div>
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Date of concern</span>
                            <span class="mc-brp-d-fact-value"><?= e(date('F j, Y', strtotime($viewDateConcern))) ?></span>
                        </div>
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Submitted</span>
                            <span class="mc-brp-d-fact-value"><?= e(date('F j, Y g:i A', strtotime($viewSubmitted))) ?></span>
                        </div>
                        <?php if ($viewStreet !== '') : ?>
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Street</span>
                            <span class="mc-brp-d-fact-value"><?= e($viewStreet) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($viewLandmark !== '') : ?>
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Landmark</span>
                            <span class="mc-brp-d-fact-value"><?= e($viewLandmark) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Submitter</span>
                            <span class="mc-brp-d-fact-value"><?= e($viewName !== '' ? $viewName : '—') ?></span>
                        </div>
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Mobile</span>
                            <span class="mc-brp-d-fact-value"><?= e($viewMobile !== '' ? $viewMobile : '—') ?></span>
                        </div>
                        <div class="mc-brp-d-fact">
                            <span class="mc-brp-d-fact-label">Email</span>
                            <span class="mc-brp-d-fact-value"><?= e($viewEmail !== '' ? $viewEmail : '—') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="align-left" class="lucide"></i> Description</h3>
                </div>
                <div class="mc-brp-d-body">
                    <div class="mc-brp-d-desc"><?= e((string) $viewReport['description']) ?></div>
                </div>
            </div>

            <?php if (count($photos) > 0) : ?>
            <div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="camera" class="lucide"></i> Photo Evidence</h3>
                    <div class="mc-brp-head-actions"><span class="text-muted small"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span></div>
                </div>
                <div class="mc-brp-d-body">
                    <div class="mc-brp-d-photos">
                        <?php foreach ($photos as $ph) :
                            $phUrl = ba_resolve_photo_url((string) $ph);
                            if ($phUrl === '') continue;
                        ?>
                            <a class="mc-brp-d-photo" href="<?= e($phUrl) ?>" target="_blank" rel="noopener" aria-label="Open photo evidence in a new tab">
                                <img src="<?= e($phUrl) ?>" alt="Photo evidence for report <?= e((string) $viewReport['report_number']) ?>" loading="lazy" decoding="async">
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (is_array($rating)) : ?>
            <div class="mc-admin-section-card" id="resident-feedback">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="star" class="lucide"></i> Resident Feedback</h3>
                    <div class="mc-brp-head-actions"><span class="text-muted small">Rated <?= e(date('M j, Y g:i A', strtotime((string) ($rating['rated_at'] ?? $viewSubmitted)))) ?></span></div>
                </div>
                <div class="mc-brp-d-body">
                    <div class="mc-brp-d-stars" role="img" aria-label="<?= (int) $rating['rating'] ?> out of 5 stars">
                        <?php for ($_si = 0; $_si < (int) $rating['rating']; $_si++) : ?><i data-lucide="star" class="lucide"></i><?php endfor; ?>
                        <?php for ($_si = 0; $_si < 5 - (int) $rating['rating']; $_si++) : ?><i data-lucide="star" class="lucide is-empty"></i><?php endfor; ?>
                        <span class="mc-brp-d-rating-num"><?= (int) $rating['rating'] ?>/5</span>
                    </div>
                    <p class="mc-brp-d-rating-label">How helpful was this resolution to the resident?</p>
                    <?php if (!empty($rating['comment'])) : ?>
                        <div class="mc-brp-d-desc mc-brp-d-desc--sm"><?= e((string) $rating['comment']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="history" class="lucide"></i> Public Activity Timeline</h3>
                    <div class="mc-brp-head-actions"><span class="text-muted small"><?= count($viewTimeline) ?> update<?= count($viewTimeline) === 1 ? '' : 's' ?></span></div>
                </div>
                <div class="mc-brp-d-body">
                    <?php if (count($viewTimeline) === 0) : ?>
                        <div class="brp-empty text-center text-muted">
                            <i data-lucide="history" class="lucide-24"></i>
                            <div class="mt-2 mb-1 fw-semibold">No timeline entries yet</div>
                            <div class="small">The first entry is created automatically when the status is saved.</div>
                        </div>
                    <?php else : ?>
                        <ol class="mc-brp-d-timeline">
                        <?php foreach ($viewTimeline as $t) :
                            $tStatus   = ba_normalize_report_status((string) $t['status']);
                            $tInternal = !empty($t['is_internal']);
                            $tDotKey   = e(str_replace(' ', '-', strtolower($tStatus)));
                        ?>
                            <li class="mc-brp-d-tl-item<?= $tInternal ? ' mc-brp-d-tl-item--internal' : '' ?>">
                                <span class="mc-brp-d-tl-dot mc-brp-d-tl-dot--<?= $tDotKey ?>">
                                    <i data-lucide="<?= $tInternal ? 'lock' : e($brpKpiIcon($tStatus)) ?>" class="lucide"></i>
                                </span>
                                <div class="mc-brp-d-tl-card">
                                    <div class="mc-brp-d-tl-head">
                                        <span class="mc-admin-badge <?= $brpPillClass($tStatus) ?>"><?= e(ba_report_status_label($tStatus)) ?></span>
                                        <span class="mc-brp-d-tl-meta"><i data-lucide="clock" class="lucide"></i><?= e(date('M j, Y g:i A', strtotime((string) $t['created_at']))) ?></span>
                                    </div>
                                    <?php if (!empty($t['note'])) : ?>
                                        <p class="mc-brp-d-tl-note"><?= e((string) $t['note']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($tInternal && !empty($t['internal_note'])) : ?>
                                        <div class="mc-brp-d-tl-internal">
                                            <i data-lucide="lock" class="lucide"></i>
                                            <span><strong>Internal only</strong> <?= e((string) $t['internal_note']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($t['admin_name'])) : ?>
                                        <div class="mc-brp-d-tl-by"><i data-lucide="user-round" class="lucide"></i>by <?= e((string) $t['admin_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ==================== SIDE COLUMN (primary actions) ==================== -->
        <aside class="mc-brp-d-side">

            <div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="settings-2" class="lucide"></i> Update Status &amp; Notes</h3>
                </div>
                <div class="mc-brp-d-body">
                    <form method="POST" id="statusForm" onsubmit="event.preventDefault(); window.__baStatusSubmit ? window.__baStatusSubmit() : null;" class="mc-brp-d-form">
                        <input type="hidden" name="action" value="update_report_status">
                        <input type="hidden" name="report_id" value="<?= (int) $viewReport['id'] ?>">
                        <?= csrf_field() ?>

                        <div class="mc-brp-d-field">
                            <label class="form-label" for="statusSelect">New status <span class="mc-brp-d-req">*</span></label>
                            <select class="form-select" id="statusSelect" name="status" required>
                                <?php foreach (ba_report_status_options() as $s) : ?>
                                    <option value="<?= e($s) ?>" <?= $viewStatus === $s ? 'selected' : '' ?>><?= e(ba_report_status_label($s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mc-brp-d-field">
                            <label class="form-label" for="noteInput"><i data-lucide="clipboard-list" class="lucide"></i> Timeline note <span class="mc-brp-d-req">*</span></label>
                            <input class="form-control" id="noteInput" type="text" name="note" maxlength="255" required placeholder="Short 1-liner for THIS status step">
                            <div class="mc-brp-d-hint">Public — residents see this in their activity feed.</div>
                        </div>

                        <div class="mc-brp-d-field">
                            <label class="form-label" for="brpInternalNote"><i data-lucide="lock" class="lucide"></i> Internal handling note</label>
                            <textarea class="form-control" id="brpInternalNote" name="internal_note" rows="2" maxlength="3000" placeholder="Escalation history, phone calls made, barangay contact names…"><?= e($viewInternal) ?></textarea>
                            <div class="mc-brp-d-hint">Private — residents will never see this.</div>
                        </div>

                        <div class="mc-brp-d-field">
                            <label class="form-label" for="resolutionNote"><i data-lucide="flag" class="lucide"></i> Final closure note</label>
                            <textarea class="form-control" id="resolutionNote" name="resolution_note" rows="3" maxlength="3000" placeholder="Formal public response explaining what was done to solve this…"><?= e($viewResolution) ?></textarea>
                            <div class="mc-brp-d-hint"><span class="mc-brp-d-req">Required</span> before closing as Completed or Rejected. <span id="autoFillHint" class="mc-brp-d-autofill"></span></div>
                        </div>

                        <div id="statusAlert" class="mc-brp-d-alert" role="status" aria-live="polite"></div>

                        <button type="submit" class="btn btn-primary mc-brp-d-submit" id="stBtn">Save Status &amp; Notify Resident</button>
                    </form>
                </div>
            </div>

            <?php if ($viewResolution !== '') : ?>
            <div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="badge-check" class="lucide"></i> Resolution Note</h3>
                </div>
                <div class="mc-brp-d-body">
                    <div class="mc-brp-d-note mc-brp-d-note--public">
                        <span class="mc-brp-d-note-tag"><i data-lucide="users" class="lucide"></i> Shared with the resident</span>
                        <div class="mc-brp-d-note-text"><?= e($viewResolution) ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($viewInternal !== '') : ?>
            <div class="mc-admin-section-card">
                <div class="mc-admin-section-head">
                    <h3><i data-lucide="lock" class="lucide"></i> Internal Handling Note</h3>
                </div>
                <div class="mc-brp-d-body">
                    <div class="mc-brp-d-note mc-brp-d-note--internal">
                        <span class="mc-brp-d-note-tag"><i data-lucide="shield-alert" class="lucide"></i> Admin only — never shown to residents</span>
                        <div class="mc-brp-d-note-text"><?= e($viewInternal) ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </aside>
    </div>

<?php elseif ($viewId > 0) : ?>
    <!-- ==================== NOT FOUND ==================== -->
    <div class="mc-brp-d-header">
        <div class="mc-brp-d-header-top">
            <a class="mc-brp-d-back" href="<?= e(app_url('/admin/ba_reports.php')) ?>">
                <i data-lucide="arrow-left" class="lucide"></i><span>Back to Resident Reports</span>
            </a>
        </div>
        <div class="mc-admin-concerns-title-row">
            <div class="mc-admin-concerns-icon-wrap">
                <i data-lucide="search-x" class="lucide"></i>
            </div>
            <div class="mc-admin-concerns-title">
                <h1>Report not found</h1>
                <p>No resident report matches this reference. It may have been deleted, or the link is out of date.</p>
            </div>
        </div>
    </div>
    <div class="mc-admin-section-card">
        <div class="mc-brp-d-body">
            <div class="brp-empty text-center text-muted">
                <i data-lucide="file-question" class="lucide-24"></i>
                <div class="mt-2 mb-3 fw-semibold">Nothing to show for report #<?= (int) $viewId ?></div>
                <a class="btn btn-primary" href="<?= e(app_url('/admin/ba_reports.php')) ?>"><i data-lucide="arrow-left" class="lucide"></i><span>Back to all reports</span></a>
            </div>
        </div>
    </div>

<?php else : ?>
    <div class="mc-admin-concerns-hero">
        <div class="mc-admin-concerns-hero-inner">
            <div class="mc-admin-concerns-title-row">
                <div class="mc-admin-concerns-icon-wrap">
                    <i data-lucide="clipboard-list" class="lucide"></i>
                </div>
                <div class="mc-admin-concerns-title">
                    <h1>Resident Reports</h1>
                    <p>Review, investigate, update, and resolve resident-submitted BasuraAlert issue reports</p>
                </div>
            </div>
            <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
                 alt="Official Seal of the City of Marikina"
                 class="mc-admin-concerns-hero-seal" loading="eager" decoding="async">
        </div>
    </div>
    <div class="mc-admin-kpi-grid">
        <?php foreach (ba_report_status_options() as $sRaw) :
            $s = ba_normalize_report_status($sRaw);
            $count = 0; foreach ($summary as $kRaw => $v) if (ba_normalize_report_status((string)$kRaw) === $s) $count += (int)$v;
        ?>
            <div class="mc-admin-kpi-card">
                <div class="mc-admin-kpi-head">
                    <div class="mc-admin-kpi-icon mc-admin-kpi-icon--<?= e($brpKpiTint($s)) ?>"><i data-lucide="<?= e($brpKpiIcon($s)) ?>" class="lucide"></i></div>
                </div>
                <p class="mc-admin-kpi-label"><?= e(ba_report_status_label($s)) ?></p>
                <div class="mc-admin-kpi-value"><?= $count ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="mc-neo-card mb-3">
        <div class="card-body" style="padding: var(--ba-space-4);">
            <!-- Select filters + Filter / Clear (matches concerns.php / ba_schedules.php reference) -->
            <form method="GET" action="<?= $selfUrl ?>" class="mb-0" id="brpFilterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="brpStatus" class="form-label">Status</label>
                        <select class="form-select" id="brpStatus" name="status" style="height:48px;">
                            <option value="">All statuses</option>
                            <?php foreach (ba_report_status_options() as $s) : ?><option value="<?= e($s) ?>" <?= ba_normalize_report_status($statusFilter) === $s ? 'selected' : '' ?>><?= e(ba_report_status_label($s)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="brpCategory" class="form-label">Category</label>
                        <select class="form-select" id="brpCategory" name="category" style="height:48px;">
                            <option value="">All categories</option>
                            <?php foreach (ba_report_category_options() as $val => $lbl) : ?><option value="<?= e($val) ?>" <?= $categoryFilter === $val ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="brpBarangay" class="form-label">Barangay</label>
                        <select class="form-select" id="brpBarangay" name="barangay_id" style="height:48px;">
                            <option value="0">All barangays</option>
                            <?php foreach ($barangays as $b) : ?><option value="<?= (int) $b['id'] ?>" <?= $barangayFilter === (int) $b['id'] ? 'selected' : '' ?>><?= e((string) $b['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2 align-items-end">
                        <button class="btn btn-primary flex-fill" type="submit" style="height:48px;"><i data-lucide="filter" class="lucide lucide-16"></i><span>Filter</span></button>
                        <a class="btn btn-outline-secondary flex-fill" href="<?= $selfUrl ?>" style="height:48px;display:inline-flex;align-items:center;justify-content:center;">Clear</a>
                    </div>
                </div>
            </form>

            <div class="mc-admin-concerns-info mt-4">
                <i data-lucide="info" class="lucide"></i>
                <span>Filter by status, category, or barangay — use <strong>Resolve</strong> to open a report and update its status.</span>
            </div>
        </div>
    </div>

    <div class="mc-admin-section-card">
        <div class="mc-admin-section-head">
            <h3><i data-lucide="inbox" class="lucide"></i> Resident Reports</h3>
            <div class="mc-brp-head-actions">
                <span class="text-muted small"><?= (int) $brpTotal . ((int) $brpTotal === 1 ? ' record' : ' records') ?></span>
            </div>
        </div>
        <div class="mc-admin-table-wrap">
            <?php if ((int) $brpTotal === 0) : ?>
                <div class="brp-empty text-center text-muted">
                    <i data-lucide="search-x" class="lucide-24"></i>
                    <div class="mt-2 mb-1 fw-semibold">No reports match your filters</div>
                    <div class="small">Try a different status, category, or barangay, or <a href="<?= $selfUrl ?>" class="fw-semibold">clear the filters</a>.</div>
                </div>
            <?php else : ?>
                    <table class="table table-hover align-middle mb-0" id="baReportsTable">
                    <thead class="table-light">
                        <tr>
                            <th><span class="mc-th-wrap"><i data-lucide="hash" class="lucide mc-th-icon"></i>Ref</span></th>
                            <th><span class="mc-th-wrap"><i data-lucide="tags" class="lucide mc-th-icon"></i>Category</span></th>
                            <th><span class="mc-th-wrap"><i data-lucide="user" class="lucide mc-th-icon"></i>Submitter</span></th>
                            <th><span class="mc-th-wrap"><i data-lucide="map-pin" class="lucide mc-th-icon"></i>Barangay</span></th>
                            <th><span class="mc-th-wrap"><i data-lucide="calendar-days" class="lucide mc-th-icon"></i>Date of Concern</span></th>
                            <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="flag" class="lucide mc-th-icon"></i>Status</span></th>
                            <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="settings" class="lucide mc-th-icon"></i>Actions</span></th>
                        </tr>
                    </thead>
                        <tbody>
                        <?php foreach ($reports as $r) : ?>
                            <tr>
                                <td data-label="Ref"><span class="mc-cell-wrap"><i data-lucide="hash" class="lucide mc-cell-icon"></i><span class="fw-semibold"><a class="brp-ref" href="<?= $brpResolveUrl((int) $r['id']) ?>" title="Open report <?= e((string) $r['report_number']) ?>"><?= e((string) $r['report_number']) ?></a><?php if (!empty($r['photos_json']) && $r['photos_json'] !== '[]' && $r['photos_json'] !== 'null') : ?><span class="mc-admin-badge mc-admin-badge--new ms-1" title="Has photos"><i data-lucide="camera" class="lucide"></i></span><?php endif; ?></span></span></td>
                                <td data-label="Category"><span class="mc-cell-wrap"><i data-lucide="tags" class="lucide mc-cell-icon"></i><span><?= e(ba_report_category_label((string) $r['category'])) ?></span></span></td>
                                <td data-label="Submitter"><span class="mc-cell-wrap"><i data-lucide="user" class="lucide mc-cell-icon"></i><span><?= e((string) ($r['user_full_name'] ?? '')) ?></span></span></td>
                                <td data-label="Barangay"><span class="mc-cell-wrap"><i data-lucide="map-pin" class="lucide mc-cell-icon"></i><span><?= e((string) ($r['barangay_name'] ?? '')) ?></span></span></td>
                                <td data-label="Date of Concern"><span class="mc-cell-wrap"><i data-lucide="calendar-days" class="lucide mc-cell-icon"></i><span class="fw-semibold"><?= e(date('M j, Y', strtotime((string) $r['date_of_concern']))) ?></span></span></td>
                                <td data-label="Status" class="mc-col-center"><span class="mc-admin-badge <?= $brpPillClass((string) $r['status']) ?>" title="<?= e(ba_report_status_label(ba_normalize_report_status((string) $r['status']))) ?>"><?= e(ba_normalize_report_status((string) $r['status'])) ?></span><?php if (isset($brpRatings[(int) $r['id']])) : ?><span class="mc-admin-badge mc-admin-badge--warning ms-1" title="Resident rated <?= (int) $brpRatings[(int) $r['id']] ?>/5"><i data-lucide="star" class="lucide"></i><?= (int) $brpRatings[(int) $r['id']] ?>/5</span><?php endif; ?></td>
                                <td data-label="Actions" class="mc-col-actions">
                                    <a class="brp-action-btn" href="<?= $brpResolveUrl((int) $r['id']) ?>"><i data-lucide="wrench" class="lucide"></i><span>Resolve</span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
            <?php endif; ?>
        </div>
        <div class="p-3"><div class="text-muted small">Showing up to 500 most recent reports. Use the filters above to narrow the list.</div></div>
    </div>
<?php endif; ?>

<?php
$pageScripts = <<<'HTML'
<script>
(function(){
  const ENDPOINT="__ENDPOINT__";
  function csrf(){var m=document.querySelector('meta[name="csrf-token"]');return m?String(m.content||""):"";}
  function eH(s){return String(s).replace(/[&<>"']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c];});}
  function setA(t,type,msg){const el=document.querySelector(t);if(!el)return;const c=type==="success"?"alert-success":type==="error"?"alert-danger":"alert-info";el.innerHTML='<div class="alert '+c+' mb-0">'+eH(String(msg||""))+'</div>';}
  window.__baStatusSubmit=function(){
    const b=document.getElementById("stBtn");if(!b||b.dataset.submitting==="1")return;
    b.dataset.submitting="1";b.disabled=true;const ot=b.textContent;b.textContent="Saving…";
    $.ajax({url:ENDPOINT,method:"POST",data:$("#statusForm").serialize()+"&csrf_token="+encodeURIComponent(csrf()),dataType:"json",timeout:60000})
      .done(function(r){if(r&&r.ok){setA("#statusAlert","success",(r.message||"Saved")+" Reloading…");setTimeout(function(){location.reload();},500);}
        else{setA("#statusAlert","error",r&&r.error?r.error:"Save failed.");b.dataset.submitting="0";b.disabled=false;b.textContent=ot;}})
      .fail(function(){setA("#statusAlert","error","Network error.");b.dataset.submitting="0";b.disabled=false;b.textContent=ot;});
  };
  const ss=document.getElementById("statusSelect");
  const ni=document.getElementById("noteInput");
  const rn=document.getElementById("resolutionNote");
  const af=document.getElementById("autoFillHint");
  /* The hint carries a Lucide <i> tag, so it MUST go in via innerHTML and be
     followed by a re-render. Assigning it to textContent printed the raw
     markup as literal text and the check icon never appeared. */
  function showAutoFill(){
    if(!af)return;
    af.style.display="inline";
    af.innerHTML='<i data-lucide="check" class="lucide"></i> Auto-filled from the Timeline note above \\u2014 you can still edit it before saving.';
    if(typeof window.__renderLucide==="function")window.__renderLucide();
  }
  function hideAutoFill(){if(af)af.style.display="none";}
  function statusChanged(){
    const s=String(ss&&ss.value||"").trim();
    const terminal=["Completed","Rejected"];
    if(terminal.indexOf(s)>=0){
      const noteText=String(ni&&ni.value||"").trim();
      const resText=String(rn&&rn.value||"").trim();
      if(noteText!==""&&resText===""&&rn){
        rn.value=noteText;
        showAutoFill();
      }else{hideAutoFill();}
    }else{hideAutoFill();}
  }
  if(ss){
    ss.addEventListener("change",statusChanged);
    if(ni)ni.addEventListener("blur",statusChanged);
    statusChanged();
  }
})();
</script>
HTML;
$pageScripts = str_replace("__ENDPOINT__", $endpoint, $pageScripts);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
