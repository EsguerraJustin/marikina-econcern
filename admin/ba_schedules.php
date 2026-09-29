<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$pageTitle = 'BasuraAlert Schedules';
$activeNav = 'ba_schedules';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

$barangays = ba_list_barangays($mysqli);
$filterBarangay = isset($_GET['barangay_id']) ? (int) $_GET['barangay_id'] : 0;
$filterWaste = isset($_GET['waste_type']) ? (string) $_GET['waste_type'] : '';
$filterType = isset($_GET['schedule_type']) ? (string) $_GET['schedule_type'] : '';
$filterStatus = isset($_GET['status']) ? (string) $_GET['status'] : '';

$allDropoffs = [];
try {
    $dres = $mysqli->query("SELECT d.id, d.barangay_id, d.spot_name FROM ba_dropoff_points d WHERE d.status = 'PUBLISHED' ORDER BY d.barangay_id, d.spot_name");
    if ($dres instanceof mysqli_result) {
        while ($dr = $dres->fetch_assoc()) $allDropoffs[] = $dr;
        $dres->free();
    }
} catch (Throwable $_) {}

$rawSchedules = ba_list_schedules($mysqli, $filterBarangay > 0 ? $filterBarangay : null, $filterStatus !== '' ? $filterStatus : null);
$schedules = [];
foreach ($rawSchedules as $s) {
    $st = (string) ($s['schedule_type'] ?? '');
    if ($filterWaste !== '' && ($s['waste_type'] ?? '') !== $filterWaste) continue;
    if ($filterType !== '') {
        if ($filterType === 'regular') {
            if ($st !== 'regular' && $st !== 'recurring') continue;
        } elseif ($st !== $filterType) continue;
    }
    $schedules[] = $s;
}

$endpoint = e(app_url('/admin/api/basuraalert_admin.php'));
$csrf = csrf_field();
$csrfMeta = csrf_header_meta();

echo $csrfMeta;

$baStatusBadge = static fn($s) => match ($s) {
    'Published' => 'mc-admin-badge--success',
    'Draft' => 'mc-admin-badge--warning',
    'Archived' => 'mc-admin-badge--muted',
    default => 'mc-admin-badge--muted',
};
$baTypeBadge = static fn($t) => match ($t) {
    'exception' => 'mc-admin-badge--danger',
    'one_time' => 'mc-admin-badge--warning',
    'recurring' => 'mc-admin-badge--muted',
    'regular' => 'mc-admin-badge--muted',
    default => 'mc-admin-badge--muted',
};
$baWasteBadge = static fn($w) => match ($w) {
    'Biodegradable' => 'mc-admin-badge--success',
    'Non-Biodegradable' => 'mc-admin-badge--muted',
    'Recyclable' => 'mc-admin-badge--new',
    'Special' => 'mc-admin-badge--warning',
    'Mixed' => 'mc-admin-badge--new',
    'Hazardous' => 'mc-admin-badge--danger',
    default => 'mc-admin-badge--muted',
};
$baWasteLabel = static function ($w): string {
    static $labels = [
        'Biodegradable' => 'Biodegradable (Wet)',
        'Non-Biodegradable' => 'Non-Biodegradable (Dry)',
        'Recyclable' => 'Recyclable (Clean/Dry)',
        'Special' => 'Special (Bulky)',
        'Mixed' => 'Mixed (All Types)',
        'Hazardous' => 'Hazardous (Toxic/Special)',
    ];
    return $labels[$w] ?? ($w !== '' ? (string) $w : '—');
};

$self = app_url('/admin/ba_schedules.php');
function ba_filter_href(string $self, array $over, array $keep): string
{
    $q = [];
    foreach ($keep as $k) { if (isset($_GET[$k]) && $_GET[$k] !== '') $q[$k] = $_GET[$k]; }
    foreach ($over as $k => $v) {
        if ($v === '' || $v === null || $v === 0) unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $self . ($qs !== '' ? ('?' . $qs) : '');
}

?>
<div class="mc-admin-concerns-hero">
    <div class="mc-admin-concerns-hero-inner">
        <div class="mc-admin-concerns-title-row">
            <div class="mc-admin-concerns-icon-wrap">
                <i data-lucide="calendar-days" class="lucide"></i>
            </div>
            <div class="mc-admin-concerns-title">
                <h1>Collection Schedules</h1>
                <p>Create, edit, publish, or delete garbage-collection schedules for any barangay</p>
            </div>
        </div>
        <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
             alt="Official Seal of the City of Marikina"
             class="mc-admin-concerns-hero-seal" loading="eager" decoding="async">
    </div>
</div>

<div class="mc-neo-card mb-3">
    <div class="card-body" style="padding: var(--ba-space-4);">
        <div class="mc-admin-concerns-tabs" id="statusTabs" role="tablist" aria-label="Filter by status">
            <?php
            $schedStatusIcons = ['All' => 'layers', 'Published' => 'check-circle-2', 'Draft' => 'file-text', 'Archived' => 'archive'];
            foreach (['All', 'Published', 'Draft', 'Archived'] as $t) :
                $isActive = ($t === 'All' && $filterStatus === '') || ($t !== 'All' && $filterStatus === $t);
            ?>
                <button type="button" class="mc-admin-concerns-chip <?= $isActive ? 'active' : '' ?>" data-status="<?= $t === 'All' ? '' : e($t) ?>"><i data-lucide="<?= e($schedStatusIcons[$t]) ?>" class="lucide"></i><?= e($t) ?></button>
            <?php endforeach; ?>
        </div>

        <form method="GET" action="<?= e($self) ?>" class="mb-0" id="schedFilterForm">
            <input type="hidden" name="status" id="statusHidden" value="<?= e($filterStatus) ?>">
            <!-- Row 1: Select filters + Filter (matches concerns.php reference) -->
            <div class="row mb-4 g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Waste type</label>
                    <select class="form-select" name="waste_type" style="height:48px;">
                        <option value="">All types</option>
                        <?php foreach (['Biodegradable','Non-Biodegradable','Recyclable','Special','Mixed'] as $w) : ?>
                            <option value="<?= $w ?>" <?= $filterWaste === $w ? 'selected' : '' ?>><?= $w ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Barangay</label>
                    <select class="form-select" name="barangay_id" style="height:48px;">
                        <option value="0">All barangays</option>
                        <?php foreach ($barangays as $b) : ?>
                            <option value="<?= (int) $b['id'] ?>" <?= $filterBarangay === (int) $b['id'] ? 'selected' : '' ?>><?= e((string) $b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Schedule type</label>
                    <select class="form-select" name="schedule_type" style="height:48px;">
                        <option value="">All types</option>
                        <option value="regular" <?= $filterType === 'regular' ? 'selected' : '' ?>>Weekly / Recurring</option>
                        <option value="one_time" <?= $filterType === 'one_time' ? 'selected' : '' ?>>One-time</option>
                        <option value="exception" <?= $filterType === 'exception' ? 'selected' : '' ?>>Exception / holiday override</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2 align-items-end">
                    <button class="btn btn-primary flex-fill" type="submit" style="height:48px;"><i data-lucide="filter" class="lucide lucide-16"></i><span>Filter</span></button>
                </div>
            </div>
        </form>

        <!-- Row 2: Search + Refresh (matches concerns.php reference) -->
        <div class="row g-3">
            <div class="col-md-8">
                <div class="mc-admin-concerns-search-wrap">
                    <i data-lucide="search" class="lucide mc-admin-concerns-search-icon"></i>
                    <input id="schedSearch" type="search" class="form-control mc-admin-concerns-search-input" placeholder="Ref # (e.g. 23 or #023) or schedule title keywords… (press Esc to clear)" autocomplete="off" spellcheck="false" style="height:48px;">
                </div>
                <div id="schedSearchInfo" class="small text-muted mt-1"></div>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="button" class="btn btn-outline-primary flex-fill" id="schedRefreshBtn" style="height:48px;">
                    <i data-lucide="refresh-cw" class="lucide lucide-16"></i>
                    <span>Refresh</span>
                </button>
                <a class="btn btn-outline-secondary flex-fill" href="<?= e($self) ?>" style="height:48px;display:inline-flex;align-items:center;justify-content:center;">Clear</a>
            </div>
        </div>

        <div class="mc-admin-concerns-info mt-4">
            <i data-lucide="info" class="lucide"></i>
            <span>Filter by status, waste, barangay, or type — click Ref # to copy.</span>
        </div>
    </div>
</div>

<div class="mc-admin-section-card">
    <div class="mc-admin-section-head">
        <h3><i data-lucide="calendar-days" class="lucide"></i> Schedules</h3>
        <div class="mc-sched-head-actions">
            <span class="text-muted small" id="schedHeadCount"></span>
            <?php if ($isSuper) : ?>
                <button class="btn btn-primary sched-new-btn" type="button" data-bs-toggle="modal" data-bs-target="#editor" data-mode="new"><i data-lucide="plus" class="lucide lucide-16"></i><span>New Schedule</span></button>
            <?php else : ?>
                <span class="mc-admin-badge mc-admin-badge--muted"><i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> View-only</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-3 pb-0" id="listAlert"></div>

        <?php
            $_schedTotal = count($schedules);
            $_maxId = 0;
            foreach ($schedules as $_s) { if (!empty($_s['id']) && (int) $_s['id'] > $_maxId) { $_maxId = (int) $_s['id']; } }
            $_refPad = max(2, strlen((string) $_maxId));
        ?>
        <div class="mc-admin-table-wrap">
            <table class="table table-hover align-middle mb-0" id="schedTable">
                <thead class="table-light">
                    <tr>
                        <th><span class="mc-th-wrap"><i data-lucide="hash" class="lucide mc-th-icon"></i>Ref #</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="message-square" class="lucide mc-th-icon"></i>Title</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="map-pin" class="lucide mc-th-icon"></i>Barangay</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="recycle" class="lucide mc-th-icon"></i>Waste</span></th>
                        <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="tag" class="lucide mc-th-icon"></i>Type</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="clock" class="lucide mc-th-icon"></i>When</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="calendar-days" class="lucide mc-th-icon"></i>Effective</span></th>
                        <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="flag" class="lucide mc-th-icon"></i>Status</span></th>
                        <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="settings" class="lucide mc-th-icon"></i>Actions</span></th>
                    </tr>
                </thead>
                <tbody id="schedBody">
                    <?php if ($_schedTotal === 0) : ?>
                        <tr class="sched-empty"><td colspan="9" class="text-center text-muted py-5"><i data-lucide="calendar-days" class="lucide-24"></i><div class="mt-2 mb-1 fw-semibold">No schedules yet</div><div class="small">Create the first one with the “New Schedule” button above.</div></td></tr>
                    <?php else : foreach ($schedules as $s) :
                        $when = in_array($s['schedule_type'], ['one_time','exception'], true)
                            ? (!empty($s['collection_date']) ? date('M j, Y', strtotime($s['collection_date'])) : '—')
                            : ba_day_name((int) ($s['day_of_week'] ?? 0));
                        $time = ba_format_time($s['time_start'] ?? null) . ' – ' . ba_format_time($s['time_end'] ?? null);
                        $linked = ($s['linked_type'] ?? '') === 'dropoff' && !empty($s['linked_id']);
                        $_sid = (int) ($s['id'] ?? 0);
                        $_refStr = str_pad((string) $_sid, $_refPad, '0', STR_PAD_LEFT);
                        $_ef = !empty($s['effective_from']) ? (string) $s['effective_from'] : '';
                        $_et = !empty($s['effective_to']) ? (string) $s['effective_to'] : '';
                        $_efDt = $_ef ? date_create($_ef) : false;
                        $_etDt = $_et ? date_create($_et) : false;
                        if ($_ef === '' && $_et === '') {
                            $effectiveMain = '';
                            $effectiveSub = '';
                            $effectiveIsIndef = true;
                        } elseif ($_ef !== '' && $_et === '') {
                            $effectiveMain = $_efDt ? date_format($_efDt, 'M j, Y') : $_ef;
                            $effectiveSub = 'From';
                            $effectiveIsIndef = false;
                        } elseif ($_ef === '' && $_et !== '') {
                            $effectiveMain = $_etDt ? date_format($_etDt, 'M j, Y') : $_et;
                            $effectiveSub = 'Until';
                            $effectiveIsIndef = false;
                        } else {
                            $sameYear = $_efDt && $_etDt && date_format($_efDt, 'Y') === date_format($_etDt, 'Y');
                            if ($sameYear) {
                                $effectiveMain = ($_efDt ? date_format($_efDt, 'M j') : $_ef)
                                    . ' — '
                                    . ($_etDt ? date_format($_etDt, 'M j') : $_et);
                                $effectiveSub = $_efDt ? date_format($_efDt, 'Y') : '';
                            } else {
                                $effectiveMain = ($_efDt ? date_format($_efDt, 'M j, Y') : $_ef)
                                    . ' — '
                                    . ($_etDt ? date_format($_etDt, 'M j, Y') : $_et);
                                $effectiveSub = '';
                            }
                            $effectiveIsIndef = false;
                        }
                    ?>
                        <tr data-id="<?= (int) $s['id'] ?>"
                            data-title="<?= e((string) $s['title']) ?>"
                            data-barangay_id="<?= (int) $s['barangay_id'] ?>"
                            data-waste_type="<?= e((string) $s['waste_type']) ?>"
                            data-schedule_type="<?= e((string) $s['schedule_type']) ?>"
                            data-day_of_week="<?= (int) ($s['day_of_week'] ?? 0) ?>"
                            data-collection_date="<?= e((string) ($s['collection_date'] ?? '')) ?>"
                            data-time_start="<?= e((string) ($s['time_start'] ?? '')) ?>"
                            data-time_end="<?= e((string) ($s['time_end'] ?? '')) ?>"
                            data-effective_from="<?= e((string) ($s['effective_from'] ?? '')) ?>"
                            data-effective_to="<?= e((string) ($s['effective_to'] ?? '')) ?>"
                            data-status="<?= e((string) $s['status']) ?>"
                            data-notes="<?= e((string) ($s['notes'] ?? '')) ?>"
                            data-linked_type="<?= e((string) ($s['linked_type'] ?? '')) ?>"
                            data-linked_id="<?= (int) ($s['linked_id'] ?? 0) ?>"
                            data-ref_padded="<?= e($_refStr) ?>">
                            <td data-label="Ref #">
                                <span class="mc-cell-wrap"><i data-lucide="hash" class="lucide mc-cell-icon"></i><span class="fw-semibold"><button type="button" class="sched-ref ba-ref-btn" title="Click to copy ref &amp; search">#<?= $_refStr ?></button></span></span>
                            </td>
                            <td data-label="Title"><span class="mc-cell-wrap"><i data-lucide="message-square" class="lucide mc-cell-icon"></i><span><span class="fw-semibold"><?= e((string) $s['title']) ?> <?php if ($linked) : ?><span class="mc-admin-badge mc-admin-badge--new ms-1">Drop-off #<?= (int) $s['linked_id'] ?></span><?php endif; ?></span><?php if (!empty($s['notes'])) : ?><span class="text-muted small d-block"><?= e(mb_substr($s['notes'], 0, 80)) ?></span><?php endif; ?></span></span></td>
                            <td data-label="Barangay"><span class="mc-cell-wrap"><i data-lucide="map-pin" class="lucide mc-cell-icon"></i><span><?= e((string) ($s['barangay_name'] ?? '')) ?></span></span></td>
                            <td data-label="Waste"><span class="mc-cell-wrap"><i data-lucide="recycle" class="lucide mc-cell-icon"></i><span><span class="mc-admin-badge <?= $baWasteBadge((string) $s['waste_type']) ?>"><?= e($baWasteLabel((string) $s['waste_type'])) ?></span></span></span></td>
                            <td data-label="Type" class="mc-col-center"><span class="mc-admin-badge <?= $baTypeBadge($s['schedule_type']) ?>"><?= e(ucfirst(str_replace('_', ' ', $s['schedule_type']))) ?></span></td>
                            <td data-label="When"><span class="mc-cell-wrap"><i data-lucide="clock" class="lucide mc-cell-icon"></i><span><span class="fw-semibold"><?= e($when) ?></span><span class="small text-muted d-block"><?= e($time) ?></span></span></span></td>
                            <td data-label="Effective"><span class="mc-cell-wrap"><i data-lucide="calendar-days" class="lucide mc-cell-icon"></i><span><?php if (!empty($effectiveIsIndef)) : ?><span class="mc-admin-badge mc-admin-badge--muted">Indefinite</span><?php else : ?><span class="fw-semibold"><?= e($effectiveMain) ?></span><?php if ($effectiveSub !== '') : ?><span class="small text-muted d-block"><?= e($effectiveSub) ?></span><?php endif; ?><?php endif; ?></span></span></td>
                            <td data-label="Status" class="mc-col-center"><span class="mc-admin-badge <?= $baStatusBadge($s['status']) ?>"><?= e((string) $s['status']) ?></span></td>
                            <td data-label="Actions" class="mc-col-actions">
                                <?php if ($isSuper) : ?>
                                    <span class="mc-admin-concerns-actions sched-actions">
                                        <button class="sched-action-btn ba-edit-btn" type="button" data-bs-toggle="modal" data-bs-target="#editor" data-mode="edit"><i data-lucide="pencil" class="lucide"></i><span>Edit</span></button>
                                        <button class="sched-action-btn sched-action-btn--danger ba-del-btn" type="button"><i data-lucide="trash-2" class="lucide"></i><span>Delete</span></button>
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
    <div class="p-3" id="schedHint"><div class="text-muted small">Filter by status, waste, barangay, or type — click Ref # to copy.</div></div>
</div>

<div class="modal fade mc-editor-dialog mc-editor-dialog--wide" tabindex="-1" id="editor" aria-hidden="true" aria-labelledby="editorLabel">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="editorForm" method="POST" onsubmit="event.preventDefault(); window.__baScheduleSave ? window.__baScheduleSave() : null;">
        <?= $csrf ?>
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" id="fId" name="id" value="0">
        <input type="hidden" id="fSource" name="source" value="curbside">
        <input type="hidden" id="fDropoffRangeId" name="dropoff_range_id" value="0">
        <div class="modal-header border-bottom">
            <h5 class="modal-title" id="editorLabel">New Schedule</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label d-block">Source</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="fSourceRadio" id="srcCurbside" value="curbside" checked>
                        <label class="form-check-label small" for="srcCurbside"><i data-lucide="truck" class="lucide-14"></i> Curbside truck route</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="fSourceRadio" id="srcDropoff" value="dropoff">
                        <label class="form-check-label small" for="srcDropoff"><i data-lucide="map-pin" class="lucide-14"></i> Drop-off point (copy hours from drop-off schedule ranges)</label>
                    </div>
                </div>
            </div>
            <div id="dropoffSourceWrap" class="mb-3 d-none">
                <div class="card border bg-light p-3 small">
                    <div class="mb-2">
                        <label class="form-label mb-1 fw-semibold">Select drop-off point</label>
                        <select id="fDropoffSelect" class="form-select form-select-sm">
                            <option value="">— Select a drop-off point first —</option>
                            <?php
                            $curBId = 0;
                            foreach ($allDropoffs as $d) :
                                if ((int) $d['barangay_id'] !== $curBId) :
                                    if ($curBId > 0) echo '</optgroup>';
                                    $bName = '';
                                    foreach ($barangays as $b) { if ((int) $b['id'] === (int) $d['barangay_id']) { $bName = (string) $b['name']; break; } }
                                    echo '<optgroup label="Brgy. ' . e($bName) . '">';
                                    $curBId = (int) $d['barangay_id'];
                                endif;
                                ?>
                                <option value="<?= (int) $d['id'] ?>"><?= e((string) $d['spot_name']) ?> (ID <?= (int) $d['id'] ?>)</option>
                            <?php endforeach;
                            if ($curBId > 0) echo '</optgroup>';
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-1 fw-semibold">Pick a range row</label>
                        <select id="fDropoffRangeSelect" class="form-select form-select-sm" disabled>
                            <option value="">Select a drop-off first above</option>
                        </select>
                        <div id="fDropoffRangePreview" class="text-muted mt-2 small fst-italic"></div>
                    </div>
                    <div class="mt-2 text-muted small">
                        <i data-lucide="lightbulb" class="lucide-14"></i> Hours, waste type, and effective range will be <strong>copied from the drop-off</strong>
                        (you can still edit the Title, Status, Notes manually after save).
                    </div>
                </div>
            </div>
            <div id="curbsideFieldsWrap">
                <div class="mb-3"><label class="form-label">Barangay *</label>
                    <select class="form-select" id="fBarangay" name="barangay_id" required>
                        <option value="">— Select —</option>
                        <?php foreach ($barangays as $b) : ?>
                            <option value="<?= (int) $b['id'] ?>"><?= e((string) $b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label">Title *</label><input type="text" class="form-control" id="fTitle" name="title" maxlength="190" required></div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label">Waste type *</label>
                        <select class="form-select" id="fWaste" name="waste_type" required>
                            <?php foreach (['Biodegradable','Non-Biodegradable','Recyclable','Special','Mixed'] as $w) : ?>
                                <option value="<?= $w ?>"><?= $w ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label">Schedule type *</label>
                        <select class="form-select" id="fSchedType" name="schedule_type" required>
                            <option value="regular">Weekly / Recurring</option>
                            <option value="one_time">One-time</option>
                            <option value="exception">Exception / holiday override</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3" id="dowWrap">
                    <div class="col-12"><label class="form-label">Day of week *</label>
                        <select class="form-select" id="fDow" name="day_of_week">
                            <option value="">— Select —</option>
                            <?php for ($d = 1; $d <= 7; $d++) : ?>
                                <option value="<?= $d ?>"><?= ba_day_name($d) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3 d-none" id="dateWrap">
                    <div class="col-12"><label class="form-label">Exact date *</label><input type="date" class="form-control" id="fCollDate" name="collection_date"></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label">Start time</label><input type="time" class="form-control" id="fTs" name="time_start"></div>
                    <div class="col-6"><label class="form-label">End time</label><input type="time" class="form-control" id="fTe" name="time_end">
                        <div class="form-text">Must be later than the start time — a window cannot be zero-length or cross midnight.</div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label">Effective from</label><input type="date" class="form-control" id="fEf" name="effective_from"></div>
                    <div class="col-6"><label class="form-label">Effective to</label><input type="date" class="form-control" id="fEt" name="effective_to"></div>
                </div>
            </div>
            <div class="mb-3"><label class="form-label">Status</label>
                <select class="form-select" id="fStatus" name="status">
                    <option value="Draft">Draft</option>
                    <option value="Published">Published</option>
                    <option value="Archived">Archived</option>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" id="fNotes" name="notes" rows="3" maxlength="255"></textarea></div>
            <div id="linkedBadgeInfo" class="mb-3 d-none">
                <div class="card bg-info bg-opacity-10 border-info p-3 small">
                    <div class="fw-semibold text-info-emphasis mb-1"><i data-lucide="link" class="lucide-14"></i> This schedule is currently linked to a Drop-off point.</div>
                    <div id="linkedBadgeDetail" class="text-muted small mb-2"></div>
                    <button type="button" id="btnUnlink" class="btn btn-sm btn-outline-info">Unlink (make it editable as a regular curbside schedule)</button>
                </div>
            </div>
        </div>
        <div class="modal-footer border-top p-3">
            <div id="editorAlert" role="status" aria-live="polite"></div>
            <button type="submit" class="btn btn-primary" id="saveBtn"><strong>Save Schedule</strong></button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel (Esc)</button>
            <div class="small text-muted text-center pt-1">Tip: press <kbd>Enter</kbd> anywhere to save, <kbd>Esc</kbd> to close.</div>
        </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<'HTML'
<script>
(function () {
  const $s = document.getElementById('schedSearch');
  if (!$s) return;
  const $info = document.getElementById('schedSearchInfo');
  const $tbody = document.getElementById('schedBody');
  const $showing = document.getElementById('schedShowingCount');
  const $totalWrap = document.getElementById('schedTotalCount');
  const $headCount = document.getElementById('schedHeadCount');
  function syncHead(total, shown) {
    if (!$headCount) return;
    if (!total) { $headCount.textContent = ''; return; }
    $headCount.textContent = (shown != null && shown !== total ? shown + ' of ' + total : total) + (total === 1 ? ' record' : ' records');
  }
  let t;
  function runFilter() {
    const rows0 = $tbody.querySelectorAll('tr[data-id]');
    const total = ($totalWrap ? parseInt(String($totalWrap.dataset.total || '0'), 10) : 0) || rows0.length || 0;
    const raw = String($s.value || '').trim();
    if (raw.length === 0) {
      const rows = $tbody.querySelectorAll('tr[data-id]');
      rows.forEach(r => r.style.display = '');
      if ($showing) $showing.textContent = String(total);
      syncHead(total, total);
      if ($info) $info.textContent = '';
      return;
    }
    const q = raw.toLowerCase();
    const qDigits = (raw.match(/\d+/g) || []).join('');
    const hasDigits = qDigits.length > 0;
    const rows = $tbody.querySelectorAll('tr[data-id]');
    let shown = 0;
    rows.forEach(r => {
      const id = String(r.dataset.id || '');
      const padded = String(r.dataset.ref_padded || '').toLowerCase();
      const title = String(r.dataset.title || '').toLowerCase();
      let match = false;
      if (hasDigits && (id === qDigits || padded.includes(qDigits))) match = true;
      if (!match) {
        const parts = q.split(/\s+/).filter(Boolean);
        let allHit = true;
        for (const p of parts) {
          if (title.includes(p) || padded.includes(p) || id.includes(p)) {
            continue;
          }
          allHit = false;
          break;
        }
        if (allHit && parts.length) match = true;
      }
      if (match) {
        shown++;
        r.style.display = '';
      } else {
        r.style.display = 'none';
      }
    });
    if ($showing) $showing.textContent = String(shown);
    syncHead(total, shown);
    if ($info) {
      if (shown === 0) {
        $info.innerHTML = '<i data-lucide="triangle-alert" class="lucide-14 text-danger"></i> <strong class="text-danger">No matches</strong> for &quot;' + q.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '&quot; in Ref # or Title.';
      } else {
        const pct = total ? Math.round((shown/total) * 100) : 0;
        $info.textContent = 'Matched ' + shown + ' / ' + total + ' rows (' + pct + '%). Press Esc to clear.';
      }
    }
  }
  $s.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(runFilter, 80);
  });
  $s.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { $s.value = ''; runFilter(); $s.blur(); }
  });
  document.querySelectorAll('.ba-ref-btn').forEach(btn => {
    btn.title = 'Search this ref: ' + btn.textContent.trim();
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const ref = String(btn.textContent || '').replace(/\s+/g, '').replace(/^#/, '');
      $s.value = '#' + ref;
      runFilter();
      $s.focus();
      try { $s.setSelectionRange($s.value.length, $s.value.length); } catch(_) {}
    });
  });
  runFilter();
  // Reference parity with concerns.php: Refresh clears client search (matches Refresh btn).
  const $refreshBtn = document.getElementById('schedRefreshBtn');
  if ($refreshBtn) {
    $refreshBtn.addEventListener('click', () => {
      $s.value = '';
      runFilter();
      $s.focus();
    });
  }
})();
// Reference parity with concerns.php statusTabs: chips drive server-side status filter.
(function () {
  const $tabs = document.getElementById('statusTabs');
  const $form = document.getElementById('schedFilterForm');
  const $hidden = document.getElementById('statusHidden');
  if (!$tabs || !$form || !$hidden) return;
  $tabs.addEventListener('click', (e) => {
    const btn = e.target && e.target.closest ? e.target.closest('button[data-status]') : null;
    if (!btn) return;
    $tabs.querySelectorAll('button[data-status]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    $hidden.value = btn.getAttribute('data-status') || '';
    $form.submit();
  });
})();
(function () {
  const endpoint = "__ENDPOINT__";
  function csrf() { const m = document.querySelector('meta[name="csrf-token"]'); return m ? String(m.content||"") : ""; }
  function escapeHtml(s){return String(s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c]));}
  function __normalizeDate(raw) {
    if (raw === null || raw === undefined) return "";
    let s = String(raw).trim();
    if (s.length === 0) return "";
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    if (/^\d{4}-\d{2}-\d{2}[\sT]/.test(s)) return s.substr(0, 10);
    if (/^\d{1,2}\/\d{1,2}\/\d{2,4}$/.test(s)) {
      const parts = s.split("/");
      let mm = parts[0]; if (mm.length === 1) mm = "0" + mm;
      let dd = parts[1]; if (dd.length === 1) dd = "0" + dd;
      let yy = parts[2]; if (yy.length === 2) yy = "20" + yy;
      return yy + "-" + mm + "-" + dd;
    }
    if (/^\d{1,2}-\d{1,2}-\d{2,4}$/.test(s)) {
      const parts = s.split("-");
      let mm = parts[0]; if (mm.length === 1) mm = "0" + mm;
      let dd = parts[1]; if (dd.length === 1) dd = "0" + dd;
      let yy = parts[2]; if (yy.length === 2) yy = "20" + yy;
      return yy + "-" + mm + "-" + dd;
    }
    const d = new Date(s);
    if (isNaN(d.getTime())) return "";
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return yyyy + "-" + mm + "-" + dd;
  }
  function setAlert(target, type, text) {
    const el = document.querySelector(target);
    if (!el) return;
    const cls = type === "success" ? "alert-success" : (type === "error" ? "alert-danger" : "alert-info");
    el.innerHTML = `<div class="alert ${cls} small mb-0">${escapeHtml(String(text||""))}</div>`;
  }

  const fType = document.getElementById("fSchedType");
  const dowWrap = document.getElementById("dowWrap");
  const dateWrap = document.getElementById("dateWrap");
  function refreshTypeVisibility() {
    if (!fType) return;
    const v = fType.value;
    const isExact = v === "one_time" || v === "exception";
    dowWrap.classList.toggle("d-none", isExact);
    dateWrap.classList.toggle("d-none", !isExact);
  }
  if (fType) fType.addEventListener("change", refreshTypeVisibility);
  refreshTypeVisibility();

  const rCurbside = document.getElementById("srcCurbside");
  const rDropoff = document.getElementById("srcDropoff");
  const hSource = document.getElementById("fSource");
  const dropoffWrap = document.getElementById("dropoffSourceWrap");
  const curbsideWrap = document.getElementById("curbsideFieldsWrap");
  const fBarangay = document.getElementById("fBarangay");
  const fTitle = document.getElementById("fTitle");
  const fWaste = document.getElementById("fWaste");
  const fDow = document.getElementById("fDow");
  const fCollDate = document.getElementById("fCollDate");
  const fTs = document.getElementById("fTs");
  const fTe = document.getElementById("fTe");
  const fEf = document.getElementById("fEf");
  const fEt = document.getElementById("fEt");
  const fDropoffSel = document.getElementById("fDropoffSelect");
  const fDropoffRangeSel = document.getElementById("fDropoffRangeSelect");
  const fDropoffRangeId = document.getElementById("fDropoffRangeId");
  const fDropoffRangePreview = document.getElementById("fDropoffRangePreview");
  const linkedBadgeInfo = document.getElementById("linkedBadgeInfo");
  const linkedBadgeDetail = document.getElementById("linkedBadgeDetail");
  const btnUnlink = document.getElementById("btnUnlink");

  function refreshSourceVisibility() {
    const isCurbside = !rDropoff || rCurbside.checked;
    const isDropoff = rDropoff && rDropoff.checked;
    dropoffWrap.classList.toggle("d-none", !isDropoff);
    if (curbsideWrap) {
      const fields = curbsideWrap.querySelectorAll('input, select, textarea');
      fields.forEach(function (f) { if (f.id !== 'fTitle') f.disabled = isDropoff; });
      if (fBarangay) fBarangay.disabled = isDropoff;
      if (fWaste) fWaste.disabled = isDropoff;
      if (fType) fType.disabled = isDropoff;
      if (fDow) fDow.disabled = isDropoff;
      if (fCollDate) fCollDate.disabled = isDropoff;
      if (fTs) fTs.disabled = isDropoff;
      if (fTe) fTe.disabled = isDropoff;
      if (fEf) fEf.disabled = isDropoff;
      if (fEt) fEt.disabled = isDropoff;
    }
    hSource.value = isCurbside ? 'curbside' : 'dropoff';
  }
  if (rCurbside) rCurbside.addEventListener('change', refreshSourceVisibility);
  if (rDropoff) rDropoff.addEventListener('change', refreshSourceVisibility);

  function loadRanges(dropoffId) {
    if (!dropoffId || !Number(dropoffId)) {
      fDropoffRangeSel.innerHTML = '<option value="">Select a drop-off first above</option>';
      fDropoffRangeSel.disabled = true;
      fDropoffRangeId.value = '0';
      fDropoffRangePreview.textContent = '';
      return;
    }
    fDropoffRangeSel.innerHTML = '<option value="">Loading…</option>';
    fDropoffRangeSel.disabled = true;
    $.ajax({url: endpoint, method: 'POST', data: {action: 'list_dropoff_ranges', dropoff_id: parseInt(dropoffId,10), csrf_token: csrf()}, dataType: 'json', timeout: 30000})
      .done(function(r){
        if (!r || !r.ok) { fDropoffRangeSel.innerHTML = '<option value="">Load failed</option>'; fDropoffRangeSel.disabled = true; return; }
        const list = Array.isArray(r.ranges) ? r.ranges : [];
        if (list.length === 0) {
          fDropoffRangeSel.innerHTML = '<option value="">No schedule ranges on this drop-off</option>';
          fDropoffRangeSel.disabled = true;
        } else {
          let html = '<option value="">— Pick a range row —</option>';
          list.forEach(function (row) {
            html += `<option value="${Number(row.id)}" data-wt="${escapeHtml(row.waste_type||'')}" data-dow="${Number(row.day_of_week)}" data-ts="${escapeHtml(row.time_start||'')}" data-te="${escapeHtml(row.time_end||'')}" data-ef="${escapeHtml(row.effective_from||'')}" data-et="${escapeHtml(row.effective_to||'')}" data-linked-type="${escapeHtml(row.linked_type||'')}" data-linked-id="${Number(row.linked_id||0)}">${escapeHtml(row.label)}</option>`;
          });
          fDropoffRangeSel.innerHTML = html;
          fDropoffRangeSel.disabled = false;
        }
      })
      .fail(function(){ fDropoffRangeSel.innerHTML = '<option value="">Network error</option>'; fDropoffRangeSel.disabled = true; });
  }
  if (fDropoffSel) fDropoffSel.addEventListener('change', function () {
    const id = fDropoffSel.value; loadRanges(id); fDropoffRangeId.value = '0'; fDropoffRangePreview.textContent = '';
  });
  if (fDropoffRangeSel) fDropoffRangeSel.addEventListener('change', function () {
    fDropoffRangeId.value = fDropoffRangeSel.value || '0';
    const opt = fDropoffRangeSel.selectedOptions && fDropoffRangeSel.selectedOptions[0] ? fDropoffRangeSel.selectedOptions[0] : null;
    if (opt) {
      const wt = opt.getAttribute('data-wt') || '';
      const dowN = parseInt(opt.getAttribute('data-dow')||'-1', 10);
      const dowMap = {0:'Sunday',1:'Monday',2:'Tuesday',3:'Wednesday',4:'Thursday',5:'Friday',6:'Saturday'};
      const dowName = dowMap[dowN] || '?';
      const ts = (opt.getAttribute('data-ts')||'').slice(0,5);
      const te = (opt.getAttribute('data-te')||'').slice(0,5);
      fDropoffRangePreview.textContent = `Will copy: ${dowName} ${ts}–${te} · ${wt}`;
    } else {
      fDropoffRangePreview.textContent = '';
    }
  });

  const editor = document.getElementById("editor");
  if (editor) {
    editor.addEventListener("show.bs.modal", function (e) {
      const btn = e.relatedTarget;
      const mode = btn && btn.dataset ? (btn.dataset.mode || "edit") : "edit";
      const row = btn && btn.closest && btn.closest("tr[data-id]") ? btn.closest("tr[data-id]") : null;
      const id = (mode === "new" || !row) ? 0 : parseInt(row.getAttribute("data-id")||"0", 10);
      document.getElementById("editorLabel").textContent = id > 0 ? "Edit Schedule" : "New Schedule";
      const f = (n, def="") => row ? String(row.getAttribute("data-"+n) || "") : def;
      document.getElementById("fId").value = id;
      fBarangay.value = f("barangay_id");
      fTitle.value = f("title") || (id ? "" : "New Schedule");
      fWaste.value = f("waste_type") || "Mixed";
      const rawSchedType = f("schedule_type") || "regular";
      fType.value = (rawSchedType === "recurring") ? "regular" : rawSchedType;
      refreshTypeVisibility();
      fDow.value = f("day_of_week");
      fCollDate.value = __normalizeDate(f("collection_date"));
      fTs.value = f("time_start");
      fTe.value = f("time_end");
      syncEndTimeFloor();
      fEf.value = __normalizeDate(f("effective_from"));
      fEt.value = __normalizeDate(f("effective_to"));
      (function () {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, "0");
        const dd = String(today.getDate()).padStart(2, "0");
        const todayIso = yyyy + "-" + mm + "-" + dd;
        if (id === 0) {
          fEf.min = todayIso;
          if (!fEf.value || fEf.value < todayIso) fEf.value = todayIso;
        } else {
          fEf.min = "";
        }
        fEt.min = fEf.value || fEf.min || "";
        if (fEf.value && fEt.value && fEt.value < fEf.value) {
          fEt.value = fEf.value;
        }
      })();
      document.getElementById("fStatus").value = f("status") || "Draft";
      document.getElementById("fNotes").value = f("notes");
      const ltype = f("linked_type");
      const lid = parseInt(f("linked_id")||"0",10);
      const isLinked = ltype === "dropoff" && lid > 0;
      if (linkedBadgeInfo) {
        if (isLinked) {
          linkedBadgeInfo.classList.remove('d-none');
          linkedBadgeDetail.textContent = 'Linked to Drop-off point #' + lid + '. Hours/values below are read-only; unlink first to edit them.';
        } else {
          linkedBadgeInfo.classList.add('d-none');
          linkedBadgeDetail.textContent = '';
        }
      }
      if (id > 0 && isLinked) {
        if (rCurbside) rCurbside.checked = true;
        if (rDropoff) rDropoff.checked = false;
        if (fDropoffSel) fDropoffSel.value = '';
        loadRanges(0);
      } else {
        if (rCurbside) rCurbside.checked = true;
        if (rDropoff) rDropoff.checked = false;
        if (fDropoffSel) fDropoffSel.value = '';
        loadRanges(0);
      }
      refreshSourceVisibility();
      document.getElementById("editorAlert").innerHTML = "";
    });
  }

  if (fEf && fEt) {
    fEf.addEventListener("input", function () {
      fEt.min = fEf.value || fEf.min || "";
      if (fEf.value && fEt.value && fEt.value < fEf.value) {
        fEt.value = fEf.value;
      }
    });
  }

  /* The window is same-day: ba_collection_schedules has no end-date column, so
     the only invalid cases are an end that is equal to or earlier than the
     start. `min` stops the native picker offering those; saveSchedule() still
     re-checks, because `min` is advisory and bypassable. */
  function timeToMinutes(value) {
    const m = /^(\d{1,2}):(\d{2})/.exec(String(value || ""));
    return m ? (parseInt(m[1], 10) * 60) + parseInt(m[2], 10) : null;
  }
  function syncEndTimeFloor() {
    if (!fTs || !fTe) return;
    fTe.min = fTs.value || "";
  }
  if (fTs) {
    fTs.addEventListener("change", syncEndTimeFloor);
    fTs.addEventListener("input", syncEndTimeFloor);
  }

  if (btnUnlink) {
    btnUnlink.addEventListener('click', function () {
      const id = parseInt(document.getElementById("fId").value || "0", 10);
      if (!id) return;
      if (!confirm('Unlink this schedule from its drop-off? Schedule hours will become editable as a regular curbside route.')) return;
      btnUnlink.disabled = true; const ot = btnUnlink.textContent; btnUnlink.textContent = 'Unlinking…';
      $.ajax({url: endpoint, method:'POST', data:{action:'unlink_schedule', id:id, csrf_token:csrf()}, dataType:'json', timeout:30000})
        .done(function(r){
          if (r && r.ok) {
            setAlert("#editorAlert", "success", (r.message||"Unlinked") + " Refreshing…");
            setTimeout(function(){location.reload();}, 600);
          } else {
            setAlert("#editorAlert","error",(r && r.error) ? r.error : "Unlink failed.");
            btnUnlink.disabled=false; btnUnlink.textContent=ot;
          }
        })
        .fail(function(){ setAlert("#editorAlert","error","Network error."); btnUnlink.disabled=false; btnUnlink.textContent=ot; });
    });
  }

  window.__baScheduleSave = function () {
    const btn = document.getElementById("saveBtn");
    if (!btn || btn.dataset.submitting === "1") return;
    if (hSource.value === "dropoff") {
      const rid = parseInt(fDropoffRangeId.value||"0",10);
      if (!rid) { setAlert("#editorAlert","error","When Source=Drop-off, pick a range row from the dropdown."); return; }
    }
    const idVal = parseInt(document.getElementById("fId").value||"0",10);
    if (fEf && fEf.value && fEf.min && idVal === 0 && fEf.value < fEf.min) {
      setAlert("#editorAlert","error","Effective From cannot be earlier than today on new schedules.");
      return;
    }
    if (fEf && fEt && fEf.value && fEt.value && fEt.value < fEf.value) {
      setAlert("#editorAlert","error","Effective To cannot be earlier than Effective From.");
      return;
    }
    if (fTs && fTe && fTs.value && fTe.value) {
      const startsAt = timeToMinutes(fTs.value);
      const endsAt = timeToMinutes(fTe.value);
      if (startsAt === null || endsAt === null) {
        setAlert("#editorAlert","error","Start time and End time must both be a valid time of day.");
        return;
      }
      if (endsAt <= startsAt) {
        setAlert("#editorAlert","error","End time must be later than Start time — a collection window cannot be zero-length or cross midnight.");
        return;
      }
    } else if ((fTs && fTs.value) !== (fTe && fTe.value)) {
      setAlert("#editorAlert","error","Start time and End time must both be filled in — a collection window needs both.");
      return;
    }
    btn.dataset.submitting = "1"; btn.disabled = true; const ot = btn.textContent; btn.textContent = "Saving…";
    $.ajax({
      url: endpoint, method:"POST",
      data: $("#editorForm").serialize() + "&csrf_token=" + encodeURIComponent(csrf()),
      dataType:"json", timeout: 60000
    }).done(function(r){
      if (r && r.ok) { setAlert("#editorAlert","success", (r.message||"Saved")+" Refreshing…"); setTimeout(function(){location.reload();}, 600); }
      else { setAlert("#editorAlert","error", (r && r.error) ? r.error : "Save failed."); btn.dataset.submitting="0"; btn.disabled=false; btn.textContent=ot; }
    }).fail(function(jqXHR,textStatus,errorThrown){ 
      const status = jqXHR && jqXHR.status ? jqXHR.status : 0;
      let msg = null;
      try { const r = "responseJSON" in jqXHR ? jqXHR.responseJSON : JSON.parse(jqXHR.responseText||""); if (r && r.error) msg = String(r.error); } catch(e){}
      if (!msg) {
        const body = String(jqXHR && jqXHR.responseText ? jqXHR.responseText : '').slice(0,300);
        if (status === 401) msg = "Session expired. Log in again then retry.";
        else if (status === 403) msg = "Permission denied — need Super Admin role for this action.";
        else if (status === 500) msg = "Server error (500): "+(body||"check error log");
        else if (textStatus === "parsererror") msg = "Invalid response (parsererror): "+(body||"empty");
        else if (textStatus === "timeout") msg = "Request timed out after 60s — try again.";
        else if (status === 0) msg = "Connection refused — check if server is running.";
        else msg = "AJAX fail status="+status+" type="+textStatus+(errorThrown?(" err="+errorThrown):"");
      }
      setAlert("#editorAlert","error",msg); btn.dataset.submitting="0"; btn.disabled=false; btn.textContent=ot; });
  };

  document.addEventListener("click", function(e){
    // Use closest() so the click still registers when the target is the
    // Lucide <svg>/<path> rendered inside the Delete button.
    const t = e.target;
    if (t && t.closest && t.closest(".ba-del-btn")) {
      const delBtn = t.closest(".ba-del-btn");
      const row = delBtn.closest("tr[data-id]"); if (!row) return;
      const id = parseInt(row.getAttribute("data-id")||"0",10); if (!id) return;
      if (!confirm("Delete this schedule? It will be permanently removed.")) return;
      $.ajax({url:endpoint,method:"POST",data:{action:"delete_schedule",id,csrf_token:csrf()},dataType:"json",timeout:30000}).done(function(r){
        if (r && r.ok) { setAlert("#listAlert","success",r.message||"Deleted."); setTimeout(function(){location.reload();},400); }
        else setAlert("#listAlert","error",(r && r.error)?r.error:"Delete failed.");
      }).fail(function(){setAlert("#listAlert","error","Network error.");});
    }
  });
})();
</script>
HTML;
$pageScripts = str_replace("__ENDPOINT__", $endpoint, $pageScripts);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
