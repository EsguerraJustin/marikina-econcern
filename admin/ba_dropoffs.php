<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

csrf_check();

$pageTitle = 'Drop-Off Point Locator';
$activeNav = 'ba_dropoffs';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

$endpoint = e(app_url('/admin/api/ba_dropoffs_admin.php'));
$geoEndpoint = e(app_url('/admin/api/ba_geocode_reverse.php'));
echo csrf_header_meta();

$barangays = [];
try {
    $b = $mysqli->query('SELECT id, name FROM barangays ORDER BY name');
    if ($b instanceof mysqli_result) while ($row = $b->fetch_assoc()) $barangays[] = $row;
} catch (Throwable $_) {}

$dropoffs = ba_list_dropoffs($mysqli, ['status' => ['DRAFT','PUBLISHED','TEMPORARILY_CLOSED']], true);

$pickupTypes = ba_dropoff_pickup_types();
$statuses = ba_dropoff_statuses();
$acceptsMeta = ba_dropoff_waste_accepts_list();

// Compact labels for the dense left-hand table ONLY. The full wording is kept
// intact in: the Info form dropdowns (below), the title= tooltip on each badge,
// the edit form and the map popup. Without these, labels like
// "Barangay MRF (Materials Recovery Facility)" (41 chars, prose written for a
// <select>) force a ~300px nowrap column and blow the row height up.
$pickupTypesShort = [
    'STREET_END_CURBSIDE'     => 'Street-end curb',
    'BARANGAY_MRF'            => 'Barangay MRF',
    'SHARED_BIN_CLUSTER'      => 'Shared bins',
    'BULKY_DROP_OFF_YARD'     => 'Bulky / yard',
    'HAZARDOUS_SATELLITE'     => 'Hazardous',
    'OTHER'                   => 'Other',
];
$statusesShort = [
    'DRAFT'               => 'Draft',
    'PUBLISHED'           => 'Published',
    'TEMPORARILY_CLOSED'  => 'Closed',
];

// KPI counters — derived from the already-fetched $dropoffs (no extra query).
$totalDropoffs = count($dropoffs);
$statusCounts = ['DRAFT' => 0, 'PUBLISHED' => 0, 'TEMPORARILY_CLOSED' => 0];
foreach ($dropoffs as $d) {
    $st = (string)($d['status'] ?? '');
    if (isset($statusCounts[$st])) { $statusCounts[$st]++; }
}

$dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$wasteTypes = ['Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special','Bulky'];

function statusBadgeClass(string $s): string {
    return match ($s) {
        'DRAFT' => 'mc-admin-badge--warning',
        'PUBLISHED' => 'mc-admin-badge--success',
        'TEMPORARILY_CLOSED' => 'mc-admin-badge--muted',
        default => 'mc-admin-badge--muted',
    };
}

$daysJs = array_map(static fn($d) => e($d), $dayNames);
$wastesJs = array_map(static fn($w) => e($w), $wasteTypes);

?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<div class="mc-admin-ba_dropoffs-page">

<?php if (isset($_SESSION['flash_error']) && $_SESSION['flash_error'] !== '') : ?>
    <div class="alert alert-warning mb-3"><?= e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_success']) && $_SESSION['flash_success'] !== '') : ?>
    <div class="alert alert-success mb-3"><?= e($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>

<div class="mc-admin-hero">
    <div class="mc-admin-hero-inner">
        <div class="mc-admin-hero-title-row">
            <div class="mc-admin-hero-icon">
                <i data-lucide="map-pin" class="lucide"></i>
            </div>
            <div class="mc-admin-hero-title">
                <h1>Drop-Off Point Locator</h1>
                <p>Publish official Marikina waste drop-off pins. Drag the pin on the map, fill in the details, then save.</p>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($isSuper) : ?>
                <button class="btn btn-outline-success" id="btnImportSeed" type="button"><i data-lucide="sprout" class="lucide-14"></i> Import 17 Marikina seed pins</button>
                <button class="btn btn-primary" id="btnNew" type="button"><i data-lucide="plus" class="lucide-14"></i> New Drop-off</button>
            <?php else : ?>
                <span class="small text-white-50 fst-italic"><i data-lucide="triangle-alert" class="lucide-14"></i> View-only mode — please ask a Super Admin for changes.</span>
            <?php endif; ?>
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" class="mc-admin-hero-seal">
        </div>
    </div>
</div>

<div class="mc-admin-kpi-grid">
    <?php
    $kpiTiles = [
        ['label' => 'Total drop-off points', 'variant' => 'blue',   'icon' => 'layers',        'count' => $totalDropoffs,                    'sub' => 'across all barangays'],
        ['label' => 'Published',            'variant' => 'mint',   'icon' => 'check-circle-2','count' => $statusCounts['PUBLISHED'],          'sub' => 'live for residents'],
        ['label' => 'Draft',                'variant' => 'amber',  'icon' => 'pencil-line',   'count' => $statusCounts['DRAFT'],              'sub' => 'hidden from residents'],
        ['label' => 'Temporarily closed',   'variant' => 'red',    'icon' => 'circle-slash',   'count' => $statusCounts['TEMPORARILY_CLOSED'], 'sub' => 'temporarily offline'],
    ];
    foreach ($kpiTiles as $t) :
        $pct = $totalDropoffs > 0 ? round(100 * ($t['count'] / $totalDropoffs), 1) : 0;
    ?>
        <div class="mc-admin-kpi-card">
            <div class="mc-admin-kpi-head">
                <div class="mc-admin-kpi-icon mc-admin-kpi-icon--<?= e($t['variant']) ?>"><i data-lucide="<?= e($t['icon']) ?>" class="lucide"></i></div>
            </div>
            <p class="mc-admin-kpi-label"><?= e($t['label']) ?></p>
            <p class="mc-admin-kpi-value"><?= (int) $t['count'] ?></p>
            <p class="mc-admin-kpi-sub"><?= e((string) $pct) ?>% of total &middot; <?= e($t['sub']) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-7 col-lg-6">
        <div class="mc-admin-table-card">
            <div class="mc-admin-table-head">
                <h3><i data-lucide="list-filter" class="lucide"></i> Drop-off points</h3>
                <span class="text-muted small"><?= (int) $totalDropoffs ?> total</span>
            </div>
            <div class="p-3">
                <div class="mc-admin-toolbar mb-0">
                    <div class="mc-admin-search">
                        <i data-lucide="search" class="lucide-search"></i>
                        <input class="form-control" id="fQ" placeholder="Search spot / address" aria-label="Search spot or address">
                    </div>
                    <select class="form-select mc-admin-form-select" id="filterBarangay" aria-label="Filter by barangay">
                        <option value="">All barangays</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" id="btnFilter" type="button"><i data-lucide="list-filter" class="lucide-14"></i> Filter</button>
                </div>

                <div class="mc-admin-table-wrap mt-3">
                    <?php if ($totalDropoffs === 0) : ?>
                        <div class="mc-admin-empty">
                            <div class="mc-admin-empty-icon"><i data-lucide="map-pin-off" class="lucide-24"></i></div>
                            <h4>No drop-off points yet</h4>
                            <p>Import the 17 Marikina seed pins or create your first drop-off point to get started.</p>
                        </div>
                    <?php else : ?>
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="bg-light sticky-top">
                                <tr>
                                    <th>Landmark</th>
                                    <th>Barangay</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="listBody">
                                <?php foreach ($dropoffs as $d): ?>
                                    <tr data-id="<?= (int)$d['id'] ?>" data-status="<?= e((string)$d['status']) ?>">
                                        <td data-label="Landmark">
                                            <div class="fw-semibold"><?= e($d['spot_name']) ?></div>
                                            <div class="small text-muted text-truncate mc-admin-dropoff-addr"><?= e($d['address']) ?></div>
                                        </td>
                                        <td data-label="Barangay"><?= e($d['barangay_name'] ?? '-') ?></td>
                                        <td data-label="Type"><span class="mc-admin-badge mc-admin-badge--muted mc-admin-dropoff-type" title="<?= e($pickupTypes[$d['pickup_type']] ?? $d['pickup_type']) ?>"><?= e($pickupTypesShort[$d['pickup_type']] ?? $d['pickup_type']) ?></span></td>
                                        <td data-label="Status"><span class="mc-admin-badge <?= statusBadgeClass($d['status']) ?>" title="<?= e($statuses[$d['status']] ?? $d['status']) ?>"><?= e($statusesShort[$d['status']] ?? $d['status']) ?></span></td>
                                        <td data-label="Actions" class="mc-admin-actions-cell">
                                            <?php if ($isSuper) : ?>
                                                <div class="mc-admin-actions">
                                                    <button type="button" class="btn btn-sm btn-outline-primary btnEdit">Edit</button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btnDel mc-admin-btn-icon" title="Delete drop-off point" aria-label="Delete drop-off point"><i data-lucide="x" class="lucide-14"></i></button>
                                                </div>
                                            <?php else : ?>
                                                <span class="mc-admin-badge mc-admin-badge--muted">View-only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-5 col-lg-6">
        <div class="mc-admin-map-section">
            <div class="mc-admin-map-head">
                <h2><i data-lucide="map" class="lucide"></i> Map &amp; drop-off details</h2>
                <div class="mc-admin-map-legend">
                    <span class="mc-admin-legend-item"><span class="mc-admin-legend-dot active"></span> Live</span>
                    <span class="mc-admin-legend-item"><span class="mc-admin-legend-dot maintenance"></span> Maintenance</span>
                    <span class="mc-admin-legend-item"><span class="mc-admin-legend-dot closed"></span> Closed</span>
                </div>
            </div>
            <div class="p-0">
                <div id="stepBanner" class="mb-2 small d-none">
                    <div class="border rounded p-2 bg-light d-flex align-items-start justify-content-between gap-2">
                        <div class="flex-grow-1 mc-admin-step-banner-row">
                            <span class="mc-admin-step-banner-label">Step-by-step creation</span>
                            <span id="stepBannerText">Starting — click "+ New Drop-off" to begin.</span>
                        </div>
                        <button type="button" id="btnExitStepMode" class="btn btn-sm btn-outline-secondary border-0 lh-1 py-1 px-2" title="Cancel / Exit step workflow (discard unsaved changes)">
                            <i data-lucide="x" class="lucide-14"></i>
                            <span class="visually-hidden">Cancel / Exit</span>
                        </button>
                    </div>
                </div>
                <ul id="stepTabNav" class="nav nav-tabs small d-none" role="tablist">
                    <li class="nav-item"><button class="nav-link active step-nav" data-step="tabPin" data-bs-toggle="tab" data-bs-target="#tabPin"><i data-lucide="map-pin" class="lucide"></i>1. Map Pin</button></li>
                    <li class="nav-item"><button class="nav-link step-nav" data-step="tabInfo" data-bs-toggle="tab" data-bs-target="#tabInfo"><i data-lucide="clipboard-list" class="lucide"></i>2. Info</button></li>
                    <li class="nav-item"><button class="nav-link step-nav" data-step="tabWaste" data-bs-toggle="tab" data-bs-target="#tabWaste"><i data-lucide="recycle" class="lucide"></i>3. Waste Accepted</button></li>
                </ul>
            </div>
            <div class="px-3 pb-3">
                <form id="saveForm" autocomplete="off" onsubmit="return false;">
                    <input type="hidden" id="fId" name="id" value="0">
                    <input type="hidden" id="fLat" name="latitude" value="14.6247">
                    <input type="hidden" id="fLon" name="longitude" value="121.0993">
                    <input type="hidden" id="fOsm" name="place_osm_id" value="">

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tabPin">
                            <div id="mapInstrText" class="mc-admin-map-pick-hint d-none"><i data-lucide="map-pin" class="lucide-14"></i><span>Click anywhere on the Marikina map to drop a pin. Drag it to the exact street-end curb. Pan is locked to Marikina City borders. <em>(Only one pin allowed — placing a new pin moves the existing marker automatically.)</em></span></div>
                            <div id="adminMap" class="mc-admin-dropoff-map"></div>
                            <div id="wrapStepMap" class="d-flex gap-2 mt-3 border-top pt-3 align-items-center flex-wrap">
                                <button type="button" id="btnSaveStepMap" class="btn btn-success" disabled><i data-lucide="save" class="lucide-14"></i> Save Step 1 · Pin Location</button>
                                <small class="text-muted ms-2">After placing / dragging the pin, click Save to persist it and unlock Step 2 (Info). Until you Save, the pin position is not written to the database.</small>
                                <span id="stepMapMsg" class="ms-auto small"></span>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tabInfo">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Barangay <span class="text-danger">*</span></label>
                                    <select id="fBarangay" name="barangay_id" class="form-select mc-admin-form-select" required>
                                        <option value="">Choose barangay…</option>
                                        <?php foreach ($barangays as $b): ?>
                                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Pickup / Facility type <span class="text-danger">*</span></label>
                                    <select id="fType" name="pickup_type" class="form-select mc-admin-form-select" required>
                                        <option value="">Choose facility type…</option>
                                        <?php foreach ($pickupTypes as $k => $v): ?>
                                            <option value="<?= e($k) ?>"><?= e($v) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Landmark <span class="text-danger">*</span></label>
                                    <input type="text" id="fName" name="spot_name" maxlength="255" class="form-control mc-admin-form-control" placeholder="e.g. LRT-2 Marikina Station, J.P. Rizal St. end-curb near Sto. Niño bridge, SM City Marikina basement bay 3">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Full street address <span class="text-danger">*</span></label>
                                    <textarea id="fAddress" name="address" rows="2" maxlength="500" class="form-control mc-admin-form-textarea" placeholder="Address / zone / corner streets"></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Status <span class="text-danger">*</span></label>
                                    <select id="fStatus" name="status" class="form-select mc-admin-form-select" required>
                                        <option value="">Choose status…</option>
                                        <?php foreach ($statuses as $k => $v): ?>
                                            <option value="<?= e($k) ?>"><?= e($v) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Hours & Days of Operation <small class="text-muted">(all dropdowns below — no typing; build custom ranges below)</small></label>
                                    <div class="card border-secondary-subtle">
                                        <div class="card-body p-2">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-4">
                                                    <label class="form-label small mb-1">Day(s) / Range</label>
                                                    <select class="form-select form-select-sm mc-admin-form-select" id="customH_day">
                                                        <option value="Daily">Daily (all 7 days)</option>
                                                        <option value="Mon–Fri" selected>Weekdays (Mon–Fri)</option>
                                                        <option value="Sat–Sun">Weekends (Sat–Sun)</option>
                                                        <option value="Monday">Monday only</option>
                                                        <option value="Tuesday">Tuesday only</option>
                                                        <option value="Wednesday">Wednesday only</option>
                                                        <option value="Thursday">Thursday only</option>
                                                        <option value="Friday">Friday only</option>
                                                        <option value="Saturday">Saturday only</option>
                                                        <option value="Sunday">Sunday only</option>
                                                        <option value="Mon,Wed,Fri">Mon / Wed / Fri (alternate)</option>
                                                        <option value="Tue,Thu">Tue / Thu (alternate)</option>
                                                        <option value="Tue–Sun">Tue–Sun (closed Mondays)</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small mb-1">Start time</label>
                                                    <select class="form-select form-select-sm mc-admin-form-select" id="customH_start">
                                                        <option value="6:00 AM">6:00 AM</option>
                                                        <option value="7:00 AM">7:00 AM</option>
                                                        <option value="8:00 AM" selected>8:00 AM</option>
                                                        <option value="8:30 AM">8:30 AM</option>
                                                        <option value="9:00 AM">9:00 AM</option>
                                                        <option value="10:00 AM">10:00 AM</option>
                                                        <option value="12:00 NN">12:00 NN (noon)</option>
                                                        <option value="1:00 PM">1:00 PM</option>
                                                        <option value="2:00 PM">2:00 PM</option>
                                                        <option value="5:00 PM">5:00 PM</option>
                                                        <option value="4:00 AM">4:00 AM (early)</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small mb-1">End time</label>
                                                    <select class="form-select form-select-sm mc-admin-form-select" id="customH_end">
                                                        <option value="12:00 NN">12:00 NN (noon)</option>
                                                        <option value="3:00 PM">3:00 PM</option>
                                                        <option value="5:00 PM" selected>5:00 PM</option>
                                                        <option value="6:00 PM">6:00 PM</option>
                                                        <option value="7:00 PM">7:00 PM</option>
                                                        <option value="8:00 AM">8:00 AM (short morning)</option>
                                                        <option value="10:00 AM">10:00 AM (short morning)</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 d-flex gap-1">
                                                    <button type="button" id="customH_add" class="btn btn-sm btn-primary w-100">+ Add range</button>
                                                </div>
                                            </div>
                                            <div class="mt-2 small">
                                                <span class="text-muted">Current Hours & Days string (built automatically):</span>
                                                <div id="customH_preview" class="fw-medium bg-light border rounded p-2 mt-1" style="min-height:2rem;white-space:pre-wrap;word-break:break-word">(empty)</div>
                                            </div>
                                            <div class="mt-2 d-flex gap-1 align-items-center justify-content-between flex-wrap">
                                                <button type="button" id="customH_clear" class="btn btn-sm btn-outline-danger">Clear all custom ranges</button>
                                                <div class="small text-muted ms-2 me-auto" id="hoursSummaryLabel"></div>
                                                <button type="button" id="customH_undo" class="btn btn-sm btn-outline-secondary ms-auto" disabled><i data-lucide="undo-2" class="lucide-14"></i> Undo last add</button>
                                            </div>
                                        </div>
                                    </div>
                                    <small class="text-muted mt-1 d-block mc-admin-help-text">All selections are dropdown only. Multiple windows separated with <strong>;</strong> (auto-built by "+ Add range"). Random letters / malformed times are <em>impossible</em> to enter here. Days selection is derived from the hours text automatically so Days and Hours always match, never contradictory.</small>
                                </div>
                                <input type="hidden" id="fDays" value="">
                                <input type="hidden" id="fHours" name="operation_hours" maxlength="255" value="">
                                <input type="hidden" id="fOpen24" name="open_24_7" value="0">
                                <div class="col-md-12">
                                    <label class="form-label">Public note (shown on popup)</label>
                                    <textarea id="fNotes" name="notes_public" rows="3" maxlength="1000" class="form-control mc-admin-form-textarea" placeholder="Resident-facing notes: tape bags shut, no cardboard accepted here, etc."></textarea>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Reference photo URL <small class="text-muted">(Cloudinary CDN URL or local file path — shown on the resident map popup)</small></label>
                                    <input type="text" id="fPhoto" name="reference_photo" maxlength="500" class="form-control mc-admin-form-control" placeholder="https://…">
                                    <small class="text-muted d-block mt-1">Paste a direct image link (ends in .jpg/.png, or a Cloudinary URL) — not a Bing/Google search results page.</small>
                                    <img id="fPhotoPreview" class="mt-2 rounded border d-none" style="max-width:100%;max-height:140px;object-fit:cover" alt="Reference photo preview">
                                </div>
                            </div>
                            <div id="wrapStepInfo" class="d-flex gap-2 mt-4 border-top pt-3 align-items-center flex-wrap">
                                <button type="button" id="btnSaveStepInfo" class="btn btn-success" disabled><i data-lucide="save" class="lucide-14"></i> Save Step 2 · Info Details</button>
                                <small class="text-muted ms-2 mc-admin-help-text">Fill every required field (* above) with valid values, then Save to persist Info data and unlock Step 3 (Waste Accepted) so you can finalize the new drop-off.</small>
                                <span id="stepInfoMsg" class="ms-auto small"></span>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tabWaste">
                            <div class="small text-muted mb-3">Tick each waste class accepted at this drop-off. At least one must be ticked to finalize.</div>
                            <div class="row g-2">
                                <?php foreach ($acceptsMeta as $col => $meta): ?>
                                    <div class="col-md-6">
                                        <div class="border rounded p-3 h-100">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="<?= e($col) ?>" name="<?= e($col) ?>" <?= $col !== 'accepts_hazard' && $col !== 'accepts_bulky' ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-semibold" for="<?= e($col) ?>">
                                                    <span class="badge <?= e($meta['badge']) ?> me-2">•</span><?= e($meta['label']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div id="wrapStepFinalize" class="mt-4 border-top pt-4">
                                <span id="stepWasteMsg" class="small d-block mb-3"></span>
                                <div class="d-flex justify-content-end">
                                    <button type="button" id="btnFinalizeDropoff" class="btn btn-primary fw-bold" disabled>
                                        <i data-lucide="check" class="lucide-14"></i> Create Drop-off Point (New Drop-off) · Step 3
                                    </button>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted mc-admin-help-text">This finishes the 3-step workflow — the blue Finalize button above saves everything, including waste acceptance.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="wrapLegacyActions" class="ba-form-actions mt-4 border-top pt-3 d-none">
                        <button type="submit" id="btnSave" class="btn btn-primary">Save</button>
                        <button type="button" id="btnReset" class="btn btn-outline-secondary">Reset form</button>
                        <span id="saveMsg" class="small"></span>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

</div><!-- /mc-admin-ba_dropoffs-page -->

<script>
(function(){
    const DROP = <?= json_encode($dropoffs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const ENDPOINT = <?= json_encode($endpoint) ?>;
    const GEO_ENDPOINT = <?= json_encode($geoEndpoint) ?>;
    const CSRF_NAME = <?= json_encode((string) csrf_token_name()) ?>;
    const CSRF_VAL  = <?= json_encode((string) csrf_token()) ?>;
    const DAY_NAMES = <?= json_encode($dayNames) ?>;
    const WASTE_TYPES = <?= json_encode($wastesJs) ?>;
    // Expose select debug helpers on window before execution so tests are stable
    window.__ba = { DROP, ENDPOINT, GEO_ENDPOINT, CSRF_NAME, CSRF_VAL, DAY_NAMES, WASTE_TYPES };

    // ==================== MAP ====================
    const MARIKINA_CENTER = [14.6247, 121.0993];
    // Official Marikina City bounding envelope (tight city limits). Outside this rectangle
    // is definitively Quezon City / Pasig / Antipolo / San Mateo and therefore disallowed.
    const MARIKINA_OFFICIAL_BOUNDS = L.latLngBounds([14.5968, 121.0596], [14.6926, 121.1441]);
    // Larger panning boundary used by Leaflet map (user can see surroundings but cannot drop pin
    // outside MARIKINA_OFFICIAL_BOUNDS):
    const MAP_MAX_BOUNDS = L.latLngBounds([14.570, 121.045], [14.700, 121.145]);
    const map = L.map('adminMap', { center: MARIKINA_CENTER, zoom: 14, maxBounds: MAP_MAX_BOUNDS, maxBoundsViscosity: 1.0 });
    window.adminMap = map;
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Leaflet rendered inside Bootstrap nav-tab container initially has zero-height layout
    // because #tabPin was display:none during L.map init. Force layout once both DOM + tab are painted,
    // and re-invalidate whenever the user switches to Map Pin tab. Without this the map area shows
    // blank white tiles (user-visible "empty map area" bug).
    function refitMap() {
        try {
            map.invalidateSize({ animate: false, pan: false });
            map.setZoom(map.getZoom(), { animate: false });
        } catch (_) {}
    }
    document.addEventListener('DOMContentLoaded', () => { setTimeout(refitMap, 0); setTimeout(refitMap, 200); setTimeout(refitMap, 800); });
    window.addEventListener('load', () => { setTimeout(refitMap, 0); setTimeout(refitMap, 400); });
    window.addEventListener('resize', refitMap);
    document.querySelectorAll('button[data-bs-target="#tabPin"], a[data-bs-target="#tabPin"], button.nav-link').forEach(t => {
        t.addEventListener('shown.bs.tab', refitMap);
        t.addEventListener('click', () => setTimeout(refitMap, 50));
    });

    const pinColor = (t) => ({STREET_END_CURBSIDE:'#2563eb', BARANGAY_MRF:'#059669', SHARED_BIN_CLUSTER:'#0891b2', BULKY_DROP_OFF_YARD:'#475569', HAZARDOUS_SATELLITE:'#dc2626', OTHER:'#c026d3'}[t]||'#2563eb');
    const existing = L.layerGroup().addTo(map);
    function redrawExisting() {
        existing.clearLayers();
        DROP.forEach(d => {
            const color = pinColor(d.pickup_type);
            const m = L.circleMarker([d.latitude, d.longitude], {radius: 7, weight: 2, color: '#ffffff', fillColor: color, fillOpacity: (d.status==='PUBLISHED'?0.9:0.35)}).addTo(existing);
            m.bindPopup(
                '<div class="mc-admin-popup-card">' +
                    '<div class="mc-admin-popup-title">' + escapeHtml(d.spot_name) + '</div>' +
                    '<div class="mc-admin-popup-addr">' + escapeHtml(d.barangay_name||'') + '</div>' +
                    '<div class="d-flex flex-wrap gap-1 mb-2">' +
                        '<span class="mc-admin-badge mc-admin-badge--muted">' + escapeHtml(d.pickup_type.replace(/_/g,' ').toLowerCase()) + '</span>' +
                        (d.status==='DRAFT'
                            ? '<span class="mc-admin-badge mc-admin-badge--warning">DRAFT</span>'
                            : d.status==='TEMPORARILY_CLOSED'
                                ? '<span class="mc-admin-badge mc-admin-badge--danger">CLOSED</span>'
                                : '<span class="mc-admin-badge mc-admin-badge--success">LIVE</span>') +
                    '</div>' +
                    (d.operation_hours ? '<div class="mc-admin-popup-hours">' + escapeHtml(d.operation_hours) + '</div>' : '') +
                '</div>'
            );
            m.on('dblclick', () => loadIntoForm(d));
        });
    }
    redrawExisting();

    let dragPin = L.marker(MARIKINA_CENTER, {draggable: true, title:'Drag me to drop-off location'}).addTo(map);
    window.dragPin = dragPin;
    let _lastValidLatLng = L.latLng(MARIKINA_CENTER[0], MARIKINA_CENTER[1]);
    let _lastPinAttempted = false;
    let _lastGeocodeFailed = false;
    /* True only while the form is a blank, never-yet-edited form. emptyForm()
       sets it (and it is ALSO the page's initialiser, so the page loads in
       this state); every deliberate admin action clears it. The "Still missing
       or invalid: ..." list is a reaction to an attempt, so it is suppressed
       while this is true — a blank form that nobody has touched yet has not
       missed anything. setSaveButtonEnabled() is deliberately NOT gated: Save
       must stay disabled either way, because the submit gate re-runs the same
       validateThreeRequired(). Only the scolding text is conditional. */
    let _isPristineForm = true;
    function markFormTouched() { _isPristineForm = false; }
    const GEOFENCE_ERROR_MSG = 'This location is beyond the allowed dropoff point. Please select a location within Marikina.';
    function isInsideMarikina(latlng) {
        if (!MARIKINA_OFFICIAL_BOUNDS.contains(latlng)) return false;
        // 2nd tier: use haversine distance against the nearest 17 Marikina barangay seed centroid
        // as a proxy for the city shape (bounding envelope has corner slivers in QC/Pasig riverside).
        // If the closest known Marikina barangay centroid is > 4200 m away → outside Marikina city.
        let best2 = Infinity;
        const known = DROP.map(d => [Number(d.latitude), Number(d.longitude)]);
        const lat0 = Number(latlng.lat), lon0 = Number(latlng.lng);
        const cosLat = Math.cos(lat0 * Math.PI / 180.0);
        for (let i = 0; i < known.length; i++) {
            const dy = (known[i][0] - lat0) * 111320.0;
            const dx = (known[i][1] - lon0) * 111320.0 * cosLat;
            const d2 = dy*dy + dx*dx;
            if (d2 < best2) best2 = d2;
        }
        const LIMIT_M = 4200.0;
        return Math.sqrt(best2) <= LIMIT_M;
    }
    function showGeofenceError(latlng) {
        const saveMsg = document.getElementById('saveMsg');
        saveMsg.innerHTML = `<span class="text-danger small fw-semibold"><i data-lucide="triangle-alert" class="lucide-14 text-danger"></i> ${escapeHtml(GEOFENCE_ERROR_MSG)}</span>`;
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
    }
    function setSaveButtonEnabled() {
        const btn = document.getElementById('btnSave');
        if (!btn) return;
        const isStepMode = (typeof window.__workflowMode !== 'undefined') ? (window.__workflowMode === 'step_create' || window.__workflowMode === 'step_edit') : false;
        const issues = validateThreeRequired();
        if (isStepMode) {
            btn.disabled = true;
            btn.classList.add('opacity-50');
            btn.classList.add('cursor-not-allowed');
            btn.setAttribute('title', 'Step creation/edit mode active — use the 3 per-step "Save Step X" buttons at the bottom of each tab, NOT this single bottom Save.');
        } else {
            btn.disabled = issues.length > 0;
            btn.classList.toggle('opacity-50', issues.length > 0);
            btn.classList.toggle('cursor-not-allowed', issues.length > 0);
            btn.removeAttribute('title');
        }
    }
    function refreshSaveBannerFromValidation(pinJustSucceededMsgHtml) {
        const saveMsg = document.getElementById('saveMsg');
        if (pinJustSucceededMsgHtml && pinJustSucceededMsgHtml.length) {
            saveMsg.innerHTML = pinJustSucceededMsgHtml;
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
            return;
        }
        const issues = validateThreeRequired();
        if (issues.length === 0) {
            saveMsg.innerHTML = '<span class="text-success small"><i data-lucide="check" class="lucide-14 text-success"></i> All required fields filled. You can Save.</span>';
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
        } else if (_isPristineForm) {
            // Blank, untouched form (fresh page load, or just after Reset):
            // report nothing rather than enumerate what is not filled in yet.
            // .ba-form-actions > #saveMsg:empty collapses the row entirely.
            saveMsg.textContent = '';
        } else {
            saveMsg.innerHTML = `<span class="text-warning small">Still missing or invalid: ${issues.map(renderIssue).join(' ; ')}</span>`;
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
        }
    }
    /* Rendered via renderIssue(), so the <strong> tags are intentional markup.
       Deliberately short: this sits inline beside the Save button, and the
       previous ~470-character version wrapped the entire action row and crushed
       both buttons into narrow pills. */
    const HOURS_FORMAT_HINT = 'Operation Hours needs <strong>Day(s): Start - End</strong> — two clock times with a dash between them, e.g. <strong>Mon–Fri: 8:00 AM - 5:00 PM</strong> or <strong>09:00-17:00</strong>. A lone time, or text with no time range at all, is not accepted. Tip: the preset buttons at the top of the Hours & Days builder fill this in correctly in one click.';
    function isValidHoursFormat(s) {
        const v = String(s ?? '').trim();
        if (v === '') return true;
        const clean = v.replace(/[–—]/g, '-').replace(/\s+/g, ' ').trim();
        // ========== STRONG VALIDATION (Day + Time Range required) ==========
        // Rule #1: Must contain a REAL TIME RANGE — a start time, dash separator,
        // then an end time. Single clock time without a range is REJECTED (this is the
        // key change to enforce the "(Day - Time : Time)" pattern the user requested).
        const timeRangeRe = /(?:^|[^0-9])\s*\d{1,2}(?::\d{2})?\s*(?:[AaPp][Mm]?|NN)?\s*[-–](?:to|until)?\s*\d{1,2}(?::\d{2})?\s*(?:[AaPp][Mm]?|NN)?(?:$|[^0-9])/;
        const hasTimeRange = timeRangeRe.test(clean);
        if (!hasTimeRange) return false;
        // Rule #2: After stripping all day tokens, time tokens, common words, and
        // punctuation, the remaining text must NOT be purely alphabetic garbage (no
        // digits). Example: "Before/after 6:00 AM Daily Mass" → after stripping tokens
        // it still has "eforeafterailyass" all letters + NO digits → rejected.
        const stripWordsRe = /\b(Sun|Mon|Tue|Wed|Thu|Fri|Sat|Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Daily|Every\s*day|24\s*\/?\s*7|24\s*hrs|AM|PM|NN|PH|Holiday|Holidays|to|until|before|after|open|closed|hrs?|hours?|only|mall|market|mass|bins?|locked|overnight|before|after|daily)\b/gi;
        let stripped = clean.replace(stripWordsRe, '');
        stripped = stripped.replace(/[^A-Za-z0-9]/g, '');
        const looksLikePureLetters = stripped !== '' && !/[0-9]/.test(stripped);
        if (looksLikePureLetters) return false;
        // Rule #3: Structural shape check. The string may be preceded by optional day
        // tokens (Sun–Mon, Tue-Thu, etc.) + separator (colon/dash) then the time range
        // cluster(s) delimited by semicolons or commas.
        const dayTokenRe = '(Sun|Mon|Tue|Wed|Thu|Fri|Sat|Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday)';
        const dayClusterRe = dayTokenRe + '(?:\\s*[-–/]\\s*' + dayTokenRe + '|\\s*[,\\s&/]+\\s*' + dayTokenRe + ')*';
        const timeTokenRe = '\\d{1,2}(?::\\d{2})?\\s*(?:[AaPp][Mm]?|NN)?';
        const singleRangeRe = timeTokenRe + '\\s*[-–](?:to|until)?\\s*' + timeTokenRe;
        const shapeRe = new RegExp(
          '^(?:\\s*' + dayClusterRe + '\\s*[:：\\s]\\s*)?'  // optional leading day tokens + separator
          + '(?:' + singleRangeRe + ')'                       // required time range
          + '(?:\\s*[;,/]\\s*(?:\\s*' + dayClusterRe + '\\s*[:：\\s]\\s*)?' + singleRangeRe + ')*\\s*$',  // more ranges with optional day prefixes
          'i'
        );
        if (shapeRe.test(clean)) return true;
        // Fallback: if shapeRe didn't match (unusual punctuation/arrangement) but we
        // already have a real time range AND no pure-letter garbage → accept as lenient pass.
        return hasTimeRange && !looksLikePureLetters;
    }
    function parseDaysFromHoursText(txt) {
        const raw = String(txt ?? '').trim();
        if (!raw) return [];
        const DAY_SHORT = {sun:0,mon:1,tue:2,wed:3,thu:4,fri:5,sat:6};
        const DAY_LONG  = {sunday:0,monday:1,tuesday:2,wednesday:3,thursday:4,friday:5,saturday:6};
        const idxOf = (name) => {
            const n = String(name).toLowerCase();
            if (DAY_SHORT.hasOwnProperty(n)) return DAY_SHORT[n];
            if (DAY_LONG.hasOwnProperty(n))  return DAY_LONG[n];
            return -1;
        };
        const NORM_DASH = (s) => String(s ?? '').replace(/[\u2013\u2014\u2010\u2011\u2012\uFFFD\uFE58\uFE63\uFF0D]/g,'-');
        // Keywords that mean "all 7 days"
        if (/\b(daily|every\s*day|24\s*\/?\s*7|24\s*hours?\s*(?:\(?round\s*the\s*clock\)?)?|open\s*24|overnight)\b/i.test(NORM_DASH(raw))) {
            return [0,1,2,3,4,5,6];
        }
        const added = new Set();
        const removed = new Set();
        const clauses = NORM_DASH(raw).split(/\s*[;,.／、]\s*/);
        clauses.forEach(clause => {
            if (!clause) return;
            // Negation detection: sentence starts with "Closed / No / Not / Except on / Except"
            let negate = false;
            let work = String(clause).replace(/\s+/g,' ').trim();
            const negMatch = work.match(/^(Closed|No\b|Not\b|Except|Except\s+on|Exclude|Skip|Shut)\s+(.+)$/i);
            if (negMatch) { negate = true; work = negMatch[2].trim(); }
            // Strip trailing time tokens / colons to isolate day tokens only
            work = work.replace(/(\s*\d{1,2}(?::\d{2})?\s*[AaPp][Mm]?\b|\s*\d{1,2}(?::\d{2})?\s*NN\b|\s*:\s*$|[()[\]{}]|\b(?:to|until|before|after|around|about)\b)/gi,' ').trim();
            // Keyword "Daily" / "All day" / "Everyday" inside clause → all 7 days (unless negated)
            if (/\b(daily|every\s*(?:single\s*)?day|everyday|all\s*day(?:s)?|week|everyday|7\s*days?|alldays)\b/i.test(work)) {
                for (let i=0;i<7;i++){ negate ? removed.add(i) : added.add(i); }
                return;
            }
            // Weekday-only keyword → Mon-Fri = 1-5
            if (/\b(weekdays?|business\s*days?|work\s*days?|school\s*days?)\b/i.test(work)) {
                [1,2,3,4,5].forEach(i => negate ? removed.add(i) : added.add(i));
            }
            // Weekend keyword → Sat/Sun = 0,6
            if (/\b(weekends?|sat(?:urday)?\s*[&,\-]?\s*sun(?:day)?)\b/i.test(work)) {
                [0,6].forEach(i => negate ? removed.add(i) : added.add(i));
            }
            // Extract day-name tokens, ranges, and comma-separated lists
            const tokenRe = /(Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sun|Mon|Tue|Wed|Thu|Fri|Sat)\s*(?:\s*[-\u2013\u2014\uFFFD]\s*(Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sun|Mon|Tue|Wed|Thu|Fri|Sat))?/gi;
            let m;
            while ((m = tokenRe.exec(work)) !== null) {
                const a = idxOf(m[1]);
                if (a < 0) continue;
                if (m[2]) {
                    const b = idxOf(m[2]);
                    if (b < 0) { negate ? removed.add(a) : added.add(a); continue; }
                    if (a <= b) { for (let i=a;i<=b;i++) negate ? removed.add(i) : added.add(i); }
                    else { for (let i=a;i<7;i++) negate ? removed.add(i) : added.add(i); for (let i=0;i<=b;i++) negate ? removed.add(i) : added.add(i); }
                } else {
                    negate ? removed.add(a) : added.add(a);
                }
            }
        });
        // Apply negations AFTER positive adds (so "Closed Mondays" truly removes Monday even if another clause said "Daily")
        const out = [];
        for (let i=0;i<7;i++){ if (added.has(i) && !removed.has(i)) out.push(i); }
        return out.sort((a,b)=>a-b);
    }
    function operationHoursStringPrefixedWithDays(rawHours, daysArr) {
        const hours = String(rawHours ?? '').trim();
        if (!hours) return hours;
        const want = (Array.isArray(daysArr) ? daysArr.slice().sort((a,b)=>a-b) : []).filter(i => i>=0 && i<=6);
        if (want.length === 0) return hours;
        // Build a human day label from want indices
        const SHORT = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        const label = (() => {
            if (want.length === 7) return 'Daily';
            if (want.length === 5 && want.join(',') === '1,2,3,4,5') return 'Mon–Fri';
            if (want.length === 2 && want.join(',') === '0,6') return 'Sat–Sun';
            // Check for a single contiguous range
            let isRange = true;
            for (let i=1;i<want.length;i++){ if (want[i] !== want[i-1]+1){ isRange=false; break; } }
            if (isRange) return SHORT[want[0]] + '–' + SHORT[want[want.length-1]];
            return want.map(i => SHORT[i]).join(',');
        })();
        // For each semicolon clause: if it already has a day prefix, leave it; otherwise prepend label
        return hours.split(/\s*;\s*/).map(part => {
            const p = String(part || '').trim();
            if (!p) return '';
            // If clause starts with Closed / No / Except / 24 hours / Daily etc → keep as-is
            if (/^(Closed|No\b|Not\b|Except|24\s*hours|Daily|Before|After|Around)\b/i.test(p)) return p;
            // If clause already has a day token at the start → don't double-prefix
            if (/^(Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sun|Mon|Tue|Wed|Thu|Fri|Sat|Daily|Weekdays?|Weekends?)\b/i.test(p)) return p;
            return label + ': ' + p;
        }).filter(s => s && s.trim()).join('; ');
    }
    // ============================================================
    // Hours & Days of Operation (unified single builder — no presets)
    // ============================================================
    // NOTE: Preset buttons / templates removed per user request ("Mb, remove the presets").
    // Only custom builder remains: 3 <select> dropdowns + Add / Clear / Undo.
    // No presets = no 1-click template fills. Every hour/day entry is hand-built by the admin.
    function detectHoursPresetKeyFromText() { return null; }
    // Simple summary helper (no more preset matching): show "X range(s)" + truncated hours.
    function updatePresetHighlightAndSummary() {
        const fH = document.getElementById('fHours');
        const text = String(fH?.value ?? '').trim();
        const summaryEl = document.getElementById('hoursSummaryLabel');
        if (!summaryEl) return;
        if (!text) { summaryEl.textContent = '(blank - no hours set)'; return; }
        const parts = text.split(/\s*;\s*/).filter(s => s && s.trim());
        const trimmed = text.length <= 56 ? text : text.slice(0, 53) + '...';
        summaryEl.textContent = parts.length + ' range(s): ' + trimmed;
    }
    // Preset apply no-ops (kept for backward compat with any caller that still uses these names):
    function applyHoursPresetTemplate() { /* no-op — presets removed */ }
    function applyHoursPreset(presetVal) {
        if (presetVal === '__NONE__' || presetVal === '' || presetVal == null) {
            __customHistory.push(__customRanges.slice());
            __customRanges.length = 0;
            renderCustomHours();
            return;
        }
        const fH = document.getElementById('fHours');
        if (fH) { fH.value = String(presetVal); syncCustomHoursFromHidden(); }
    }
    function normalizeHoursTextForPresetMatch(t) {
        return String(t ?? '').replace(/[\u2013\u2014\u2010\u2011\u2012\uFFFD\uFE58\uFE63\uFF0D]/g, '-').replace(/\s+/g, ' ').trim().toLowerCase();
    }
    function applyHoursTextAutoPreset(text) {
        const raw = String(text ?? '').trim();
        const fH = document.getElementById('fHours');
        if (fH) fH.value = raw;
        if (!raw) {
            __customHistory.push(__customRanges.slice());
            __customRanges.length = 0;
            renderCustomHours();
            return;
        }
        // No presets: always split raw string by semicolons into custom chip rows.
        syncCustomHoursFromHidden();
    }
    // Selected operation day indices: DERIVED from the hours text using parseDaysFromHoursText().
    function selectedOperationDayIndexes() {
        const fH = document.getElementById('fHours');
        const raw = String(fH?.value ?? '').trim();
        if (!raw) return [];
        return parseDaysFromHoursText(raw);
    }
    // Write derived day indexes (0-6 csv) into hidden #fDays, and derived open_24_7 boolean.
    function writeOperationDaysHidden() {
        const daysHidden = document.getElementById('fDays');
        if (daysHidden) daysHidden.value = selectedOperationDayIndexes().map(String).join(',');
        const open24Hidden = document.getElementById('fOpen24');
        if (open24Hidden) {
            const fH = document.getElementById('fHours');
            const raw = String(fH?.value ?? '').trim();
            const looks247 = !!raw && /24\s*hours\s*\(?round\s*the\s*clock\)?|24\s*\/?\s*7|24\s*hrs?\s*every\s*day|daily.*24|24.*daily/.test(raw);
            open24Hidden.value = looks247 ? '1' : '0';
        }
    }
    // Legacy applyDaysToChips - rewrite hours with day-prefix using operationHoursStringPrefixedWithDays().
    function applyDaysToChips(daysArr) {
        if (!daysArr || daysArr.length === 0) return;
        const fH = document.getElementById('fHours');
        const raw = String(fH?.value ?? '').trim();
        const updated = operationHoursStringPrefixedWithDays(raw, daysArr);
        fH.value = updated;
        syncCustomHoursFromHidden();
    }
    function validateThreeRequired() {
        const issues = [];
        const bSel = document.querySelector('#saveForm #fBarangay');
        const brgyEmpty = !bSel || !String(bSel.value || '').trim();
        if (brgyEmpty) {
            if (_lastPinAttempted) issues.push('Geocoding could not detect a valid Barangay for the pinned coordinates. Please select the correct Barangay from the dropdown manually.');
            else issues.push('Please select a Barangay (pin a location on the map or choose from the dropdown).');
        }
        const fN = document.getElementById('fName');
        const spot = String((fN && fN.value) ? fN.value : '').trim();
        if (!spot) issues.push('Please type the Landmark (hint shown in the box — e.g. front of Barangay outpost, LRT exit, chapel curb).');
        const fA = document.querySelector('#saveForm #fAddress');
        const addrEmpty = !fA || !String(fA.value || '').trim();
        if (addrEmpty) {
            if (_lastPinAttempted) issues.push('Geocoding could not detect a valid street address for the pinned coordinates. Please type the Full Street Address manually in the box provided.');
            else issues.push('Please fill in the Full Street Address (auto-filled from map pin).');
        }
        if (_lastGeocodeFailed && (brgyEmpty || addrEmpty)) {
            issues.unshift(htmlIssue('<i data-lucide="triangle-alert" class="lucide-14 text-danger"></i> Reverse geocoding for this pin did not return a complete address. You must fill in the missing Barangay and/or Full Street Address fields manually before you can save.'));
        }
        const fLt = document.getElementById('fLat'); const fLn = document.getElementById('fLon');
        if (fLt && fLn) {
            const lat = parseFloat(fLt.value), lon = parseFloat(fLn.value);
            if (isNaN(lat) || isNaN(lon) || !isInsideMarikina(L.latLng(lat, lon))) {
                issues.unshift('Pinned coordinates are not valid or are outside Marikina. Re-pin inside the city.');
            }
        }
        const open24 = String(document.getElementById('fOpen24')?.value ?? '0') === '1';
        if (!open24) {
            const fH = document.getElementById('fHours');
            const rawHours = fH ? String(fH.value || '').trim() : '';
            if (!isValidHoursFormat(rawHours)) issues.push(htmlIssue(HOURS_FORMAT_HINT));
            const daysArr = selectedOperationDayIndexes();
            if (daysArr.length === 0 && rawHours === '') {
                // Allow fully blank hours+days; means "no hours set" (still valid save)
            }
        }
        return issues;
    }
    function updatePinFromLatLng(latlng, opts) {
        opts = opts || {};
        if (!isInsideMarikina(latlng)) {
            try { dragPin.setLatLng(_lastValidLatLng); } catch (_) {}
            showGeofenceError(latlng);
            return false;
        }
        _lastPinAttempted = true;
        _lastGeocodeFailed = false;
        _isPristineForm = false; // dropping or dragging the pin is an attempt
        _lastValidLatLng = L.latLng(Number(latlng.lat), Number(latlng.lng));
        document.getElementById('fLat').value = latlng.lat.toFixed(7);
        document.getElementById('fLon').value = latlng.lng.toFixed(7);
        setSaveButtonEnabled();
        try { renderStepWorkflowUI(); } catch(_) {}
        reverseGeocodeAndAutofill(latlng);
        return true;
    }
    let geoAbort = null;
    async function reverseGeocodeAndAutofill(latlng) {
        try {
            if (geoAbort) geoAbort.abort();
        } catch (_) {}
        const saveMsg = document.getElementById('saveMsg');
        saveMsg.innerHTML = '<span class="text-muted small">Looking up landmark & barangay for pinned coordinates…</span>';
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        geoAbort = new AbortController();
        const sep = GEO_ENDPOINT.indexOf('?') >= 0 ? '&' : '?';
        const url = `${GEO_ENDPOINT}${sep}lat=${encodeURIComponent(Number(latlng.lat).toFixed(7))}&lng=${encodeURIComponent(Number(latlng.lng).toFixed(7))}&${encodeURIComponent(CSRF_NAME)}=${encodeURIComponent(CSRF_VAL)}`;
        try {
            const resp = await fetch(url, { signal: geoAbort.signal, method: 'GET', headers: { 'Accept': 'application/json' } });
            if (!resp.ok) {
                let txt = '';
                try { txt = await resp.text(); } catch(_) {}
                let parsed = null;
                try { parsed = JSON.parse(txt); } catch(_) {}
                if (parsed && parsed.error) throw new Error(parsed.error);
                if (resp.status === 403) throw new Error(txt ? txt : 'Session expired; please log in again.');
                throw new Error('HTTP ' + resp.status);
            }
            const r = await resp.json();
            if (!r || !r.ok) throw new Error(r?.error || 'Reverse lookup failed');
            const sel = 'matched_barangay';
            if (r[sel] && Number(r[sel].id) > 0) {
                const bSel = document.querySelector('#saveForm select#fBarangay');
                if (bSel) {
                    const wanted = String(r[sel].id);
                    let ok = false;
                    for (let i = 0; i < bSel.options.length; i++) if (String(bSel.options[i].value) === wanted) { bSel.selectedIndex = i; ok = true; break; }
                    if (!ok) for (let i = 0; i < bSel.options.length; i++) if (String(bSel.options[i].text || '').trim() === String(r[sel].name || '').trim()) { bSel.selectedIndex = i; ok = true; break; }
                    try { bSel.dispatchEvent(new Event('change', {bubbles:true})); } catch(_){}
                }
            }
            if (r.landmark && typeof r.landmark === 'string') {
                const el = document.getElementById('fName');
                if (el) {
                    const suggested = r.landmark.slice(0, 180);
                    el.placeholder = suggested ? `${suggested} (suggestion — replace with actual landmark)` : (typeof LANDMARK_DEFAULT_PLACEHOLDER !== 'undefined' ? LANDMARK_DEFAULT_PLACEHOLDER : '');
                }
            }
            if (r.address && typeof r.address === 'string') {
                const el = document.querySelector('#saveForm #fAddress');
                if (el && (!el.value || (el.dataset && el.dataset.autoFilled === '1'))) {
                    el.value = r.address.slice(0, 500);
                    if (el.dataset) el.dataset.autoFilled = '1';
                }
            }
            if (r.place_osm_id || r.reverse_geocode?.place_id) {
                const el = document.getElementById('fOsm');
                if (el) el.value = r.place_osm_id || r.reverse_geocode.place_id || '';
            }
            const bSelAfter = document.querySelector('#saveForm #fBarangay');
            const brgySet = !!(bSelAfter && String(bSelAfter.value || '').trim());
            const fAAfter = document.querySelector('#saveForm #fAddress');
            const addrSet = !!(fAAfter && String(fAAfter.value || '').trim());
            _lastGeocodeFailed = !brgySet || !addrSet;
            setSaveButtonEnabled();
            const parts = [];
            if (r[sel]) parts.push(`Barangay auto-detected: <b>${escapeHtml(r[sel].name)}</b>`);
            if (r.nearest_seed && r.nearest_distance_meters != null) parts.push(`Nearest known drop-off: ${escapeHtml(String(r.nearest_distance_meters))} m away`);
            if (r.nearest_seed) parts.push(`(<span class="text-muted">${escapeHtml(r.nearest_seed.barangay_name || '')}</span>)`);
            const issues = validateThreeRequired();
            if (_lastGeocodeFailed) {
                saveMsg.innerHTML = `<span class="text-danger small fw-semibold"><i data-lucide="triangle-alert" class="lucide-14 text-danger"></i> Reverse geocoding did not return a complete address (missing Barangay and/or Street Address). Please fill the highlighted fields manually before saving.</span><br><span class="text-warning small">Still missing: ${issues.map(renderIssue).join(' ; ')}</span>`;
                if (typeof window.__renderLucide === 'function') window.__renderLucide();
            } else if (issues.length) {
                refreshSaveBannerFromValidation();
            } else {
                saveMsg.innerHTML = parts.length ? `<span class="text-success small">Auto-filled from map pin. ${parts.join(' · ')}</span>` : '<span class="text-success small">Pin updated.</span>';
                if (typeof window.__renderLucide === 'function') window.__renderLucide();
            }
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            const msg = String(e?.message || e);
            // Server already formatted the exact geofence error text; surface verbatim.
            saveMsg.innerHTML = `<span class="text-danger small">${escapeHtml(msg)}</span>`;
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
        }
    }
    ['fAddress'].forEach(id => {
        const el = document.querySelector('#saveForm #'+id);
        if (!el) return;
        el.addEventListener('input', () => { if (el.dataset) delete el.dataset.autoFilled; markFormTouched(); setSaveButtonEnabled(); refreshSaveBannerFromValidation(); });
    });
    ['fName'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', () => { markFormTouched(); setSaveButtonEnabled(); refreshSaveBannerFromValidation(); });
    });
    ['fBarangay'].forEach(id => {
        const el = document.querySelector('#saveForm #'+id);
        if (!el) return;
        el.addEventListener('change', () => { markFormTouched(); setSaveButtonEnabled(); refreshSaveBannerFromValidation(); });
    });
    // ============ Custom Operation Hours builder (100% dropdowns — no free text typing, NO PRESETS) ============
    // We maintain __customRanges[] as an array of "Day(s): Start - End" strings; the hidden
    // #fHours input holds the same array joined by '; '.
    const __customRanges = [];   // mutable array
    const __customHistory = [];  // stack for Undo last add
    function renderCustomHours() {
        const hidden = document.getElementById('fHours');
        const preview = document.getElementById('customH_preview');
        const undoBtn = document.getElementById('customH_undo');
        if (hidden) hidden.value = __customRanges.join('; ');
        if (preview) preview.textContent = __customRanges.length ? __customRanges.join(';\n') : '(empty)';
        if (undoBtn) undoBtn.disabled = __customHistory.length === 0;
        // After changing fHours via builder: auto-derive fDays + open24 hidden fields
        // so save form submit reads consistent values. This replaces the old manual
        // checkbox/hours radio listeners and keeps days/hours perfectly in sync.
        writeOperationDaysHidden();
        // Update small summary label (no more preset highlight — presets removed).
        updatePresetHighlightAndSummary();
        setSaveButtonEnabled();
        refreshSaveBannerFromValidation();
    }
    (function wireCustomHoursBuilder(){
        const addBtn = document.getElementById('customH_add');
        const clrBtn = document.getElementById('customH_clear');
        const undoBtn = document.getElementById('customH_undo');
        const daySel = document.getElementById('customH_day');
        const stSel  = document.getElementById('customH_start');
        const enSel  = document.getElementById('customH_end');
        if (!addBtn || !clrBtn || !undoBtn || !daySel || !stSel || !enSel) return;
        const buildRangeStr = () => {
            const d = String(daySel.value || '').trim();
            const s = String(stSel.value || '').trim();
            const e = String(enSel.value || '').trim();
            if (!s || !e) return '';
            return `${d}: ${s} - ${e}`;
        };
        addBtn.addEventListener('click', () => {
            const str = buildRangeStr();
            if (!str) return;
            __customHistory.push(__customRanges.slice());
            __customRanges.push(str);
            renderCustomHours();
        });
        clrBtn.addEventListener('click', () => {
            __customHistory.push(__customRanges.slice());
            __customRanges.length = 0;
            renderCustomHours();
        });
        undoBtn.addEventListener('click', () => {
            const prev = __customHistory.pop();
            if (!prev) return;
            __customRanges.length = 0;
            prev.forEach(r => __customRanges.push(r));
            renderCustomHours();
        });
    })();
    function syncCustomHoursFromHidden() {
        const hidden = document.getElementById('fHours');
        if (!hidden) return;
        const txt = String(hidden.value || '').trim();
        if (!txt) { __customRanges.length = 0; __customHistory.length = 0; renderCustomHours(); return; }
        const parts = txt.split(/\s*;\s*/).filter(s => s && s.trim());
        if (parts.length) {
            __customHistory.push(__customRanges.slice());
            __customRanges.length = 0;
            parts.forEach(p => __customRanges.push(p.trim()));
            renderCustomHours();
        }
    }
    // When admin clicks into "2. Info" tab, auto-focus the Landmark input so they can start
    // typing the custom landmark immediately (e.g. "front of barangay outpost").
    document.querySelectorAll('button[data-bs-target="#tabInfo"], a[data-bs-target="#tabInfo"]').forEach(t => {
        t.addEventListener('shown.bs.tab', () => {
            const fn = document.getElementById('fName');
            if (fn) { try { fn.focus(); if (fn.select) fn.select(); } catch (_) {} }
        });
    });
    // Guard dragend: if admin drags pin out of Marikina, snap it back + error.
    dragPin.on('dragend', e => {
        const ll = e.target.getLatLng();
        if (!isInsideMarikina(ll)) {
            try { e.target.setLatLng(_lastValidLatLng); } catch (_) {}
            showGeofenceError(ll);
            return;
        }
        updatePinFromLatLng(ll);
    });
    // Guard map click: block entirely if click lands outside Marikina — do NOT move the pin.
    map.on('click', e => {
        if (!isInsideMarikina(e.latlng)) {
            showGeofenceError(e.latlng);
            return;
        }
        dragPin.setLatLng(e.latlng);
        updatePinFromLatLng(e.latlng);
    });
    window.reverseGeocodeAndAutofill = reverseGeocodeAndAutofill;
    window.updatePinFromLatLng = updatePinFromLatLng;
    window.isInsideMarikina = isInsideMarikina;
    window.validateThreeRequired = validateThreeRequired;
    setSaveButtonEnabled();

    // ==================== FORM ====================
    const LANDMARK_DEFAULT_PLACEHOLDER = 'e.g. LRT-2 Marikina Station south exit, J.P. Rizal St. end-curb near Sto. Niño bridge, SM City Marikina basement bay 3, front of Barangay outpost, waiting shed near 7-Eleven';
    function emptyForm() {
        _lastPinAttempted = false;
        _lastGeocodeFailed = false;
        _isPristineForm = true;
        document.getElementById('fId').value = 0;
        document.getElementById('fLat').value = MARIKINA_CENTER[0];
        document.getElementById('fLon').value = MARIKINA_CENTER[1];
        document.getElementById('fOsm').value = '';
        const fBrgy = document.querySelector('#saveForm #fBarangay');
        if (fBrgy) fBrgy.value = '';
        const fTypeEl = document.getElementById('fType');
        if (fTypeEl) {
            fTypeEl.value = '';
            if (fTypeEl.options && fTypeEl.options[0]) fTypeEl.selectedIndex = 0;
        }
        document.getElementById('fName').value = '';
        document.getElementById('fName').placeholder = LANDMARK_DEFAULT_PLACEHOLDER;
        const fAddr = document.querySelector('#saveForm #fAddress');
        if (fAddr) { fAddr.value = ''; if (fAddr.dataset) delete fAddr.dataset.autoFilled; }
        const fStatusEl = document.getElementById('fStatus');
        if (fStatusEl) {
            fStatusEl.value = '';
            if (fStatusEl.options && fStatusEl.options[0]) fStatusEl.selectedIndex = 0;
        }
        applyHoursTextAutoPreset('');
        writeOperationDaysHidden();
        document.getElementById('fNotes').value = '';
        document.getElementById('fPhoto').value = '';
        ['accepts_bio','accepts_nonbio','accepts_recyclable'].forEach(c => document.getElementById(c).checked = true);
        ['accepts_hazard','accepts_bulky'].forEach(c => document.getElementById(c).checked = false);
        const schedBodyEl = document.getElementById('schedBody');
        if (schedBodyEl) { schedBodyEl.innerHTML = ''; if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
        _lastValidLatLng = L.latLng(MARIKINA_CENTER[0], MARIKINA_CENTER[1]);
        dragPin.setLatLng(MARIKINA_CENTER);
        map.setView(MARIKINA_CENTER, 14);
        setSaveButtonEnabled();
        /* No refreshSaveBannerFromValidation() here. emptyForm() is the page's
           initialiser as well as the Reset handler, so asking the validator for
           a report painted the "Still missing or invalid: ..." triage list on a
           blank form before the admin had done anything. The banner now clears
           instead. Note applyHoursTextAutoPreset('') above still routes through
           renderCustomHours() -> refreshSaveBannerFromValidation(); that is
           harmless because _isPristineForm was set before it, so it takes the
           clear-and-return branch. */
        const resetMsg = document.getElementById('saveMsg');
        if (resetMsg) resetMsg.textContent = '';
    }

    function loadIntoForm(d) {
        _lastPinAttempted = false;
        _lastGeocodeFailed = false;
        _isPristineForm = false; // an existing record is being edited, not a blank form
        document.getElementById('fId').value = d.id;
        document.getElementById('fLat').value = Number(d.latitude).toFixed(7);
        document.getElementById('fLon').value = Number(d.longitude).toFixed(7);
        document.getElementById('fOsm').value = d.place_osm_id || '';
        const fBrgy = document.querySelector('#saveForm #fBarangay');
        if (fBrgy) fBrgy.value = d.barangay_id || '';
        document.getElementById('fType').value = d.pickup_type;
        document.getElementById('fName').value = d.spot_name || '';
        const fAddr = document.querySelector('#saveForm #fAddress');
        if (fAddr) { fAddr.value = d.address || ''; if (fAddr.dataset) delete fAddr.dataset.autoFilled; }
        document.getElementById('fStatus').value = d.status;
        // If DB open_24_7=1 but stored hours string is blank, force the 24/7 preset chip in the builder.
        const existingHours = (d.open_24_7 && !d.operation_hours) ? '24 hours (round the clock)' : (d.operation_hours || '');
        applyHoursTextAutoPreset(existingHours);
        writeOperationDaysHidden();
        document.getElementById('fNotes').value = d.notes_public || '';
        document.getElementById('fPhoto').value = d.reference_photo || '';
        try { updatePhotoPreview(); } catch(_) {}
        Object.keys(<?= json_encode($acceptsMeta) ?>).forEach(col => {
            const el = document.getElementById(col);
            if (el) el.checked = !!d[col];
        });
        const tbody2 = document.getElementById('schedBody');
        if (tbody2) {
            tbody2.innerHTML = '';
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
            (d.schedules||[]).forEach(row => { try { addSchedRow(row); } catch(_) {} });
        }
        const schedVMsg = document.getElementById('schedValidationMsg');
        if (schedVMsg) renderScheduleValidationMsg({ ok: true });
        try {
            const ll = L.latLng(Number(d.latitude), Number(d.longitude));
            if (isInsideMarikina(ll)) {
                _lastValidLatLng = ll;
            }
        } catch(_) {}
        dragPin.setLatLng([Number(d.latitude), Number(d.longitude)]);
        map.setView([Number(d.latitude), Number(d.longitude)], 16, {animate:true});
        document.getElementById('saveMsg').textContent = '';
        setSaveButtonEnabled();
        refreshSaveBannerFromValidation();
    }

    // ============================================================
    // Schedules (4. Schedules tab) — date + time range + waste block
    // ============================================================
    // Asia/Manila has NO DST since 1990 so local wall-clock time is
    // always UTC+8. We treat <input type=time> and <input type=date>
    // as pure local values (no timezone conversion on the wire).
    function pad2(n){ return String(n).length===1 ? '0'+n : String(n); }
    function formatDateForInput(v) {
        // Accepts "YYYY-MM-DD" (DB), ISO8601 with timezone, or nullish.
        // Returns "YYYY-MM-DD" suitable for <input type=date value> or "".
        if (!v) return '';
        const s = String(v).trim();
        if (!s) return '';
        const m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (m) return `${m[1]}-${pad2(m[2])}-${pad2(m[3])}`;
        try {
            const d = new Date(s);
            if (isNaN(d.valueOf())) return '';
            return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
        } catch (_) { return ''; }
    }
    function validateSchedulesRows(opts) {
        // Returns { ok: true, rows: [...] } or { ok: false, issues: [str,...], rowErrors: {rowIdx:[str]} }
        opts = opts || {};
        const tbody = document.getElementById('schedBody');
        if (!tbody) return { ok: true, rows: [] };
        const trs = Array.from(tbody.querySelectorAll('tr'));
        const rows = [];
        const issues = [];
        const rowErrors = {};
        trs.forEach((tr, i) => {
            const errs = [];
            const dowEl = tr.querySelector('.schedDow');
            const wtEl  = tr.querySelector('.schedWaste');
            const fromEl= tr.querySelector('.schedFrom');
            const toEl  = tr.querySelector('.schedTo');
            const t1El  = tr.querySelector('.schedStart');
            const t2El  = tr.querySelector('.schedEnd');
            if (!dowEl || !wtEl || !t1El || !t2El) { errs.push('missing column inputs'); rowErrors[i] = errs; return; }
            const dow = parseInt(dowEl.value, 10);
            if (isNaN(dow) || dow < 0 || dow > 6) errs.push('invalid day-of-week');
            const wt = String(wtEl.value || '').trim();
            if (!wt) errs.push('waste type blank');
            const fromRaw = String(fromEl ? fromEl.value || '' : '').trim();
            const toRaw   = String(toEl   ? toEl.value   || '' : '').trim();
            if (fromEl && fromEl.validity && !fromEl.validity.valid) errs.push('Effective From has invalid date format (use the date picker or YYYY-MM-DD format)');
            if (toEl   && toEl.validity   && !toEl.validity.valid)   errs.push('Effective To has invalid date format (use the date picker or YYYY-MM-DD format)');
            const dateRe = /^\d{4}-\d{2}-\d{2}$/;
            if (fromRaw && !dateRe.test(fromRaw)) errs.push('Effective From date must be YYYY-MM-DD');
            if (toRaw   && !dateRe.test(toRaw))   errs.push('Effective To date must be YYYY-MM-DD');
            if (fromRaw && toRaw && dateRe.test(fromRaw) && dateRe.test(toRaw) && fromRaw > toRaw) errs.push('Effective From date must be before or equal to To date');
            const t1Raw = String(t1El.value || '').trim();
            const t2Raw = String(t2El.value || '').trim();
            const timeRe = /^\d{2}:\d{2}$/;
            if (!t1Raw || !timeRe.test(t1Raw)) errs.push('Start time is required (HH:MM)');
            if (!t2Raw || !timeRe.test(t2Raw)) errs.push('End time is required (HH:MM)');
            if (timeRe.test(t1Raw) && timeRe.test(t2Raw) && !(t1Raw < t2Raw)) errs.push('Start time must be strictly earlier than End time on the same day');
            if (errs.length) { rowErrors[i] = errs; issues.push(`Row ${i+1}: ${errs.join(', ')}`); return; }
            rows.push({
                day_of_week: dow,
                waste_type:  wt,
                effective_from: fromRaw || null,
                effective_to:   toRaw   || null,
                time_start:  `${t1Raw}:00`,
                time_end:    `${t2Raw}:00`,
            });
        });
        if (issues.length) return { ok: false, issues, rowErrors, rows };
        return { ok: true, rows, issues:[], rowErrors: {} };
    }
    function renderScheduleValidationMsg(result) {
        const box = document.getElementById('schedValidationMsg'); if (!box) return;
        if (!result || result.ok) { box.innerHTML = ''; if (typeof window.__renderLucide === 'function') window.__renderLucide(); return; }
        box.innerHTML = `<span class="text-danger small">${(result.issues||[]).map(escapeHtml).map(s => '• ' + s).join('<br>')}</span>`;
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
    }
    function addSchedRow(row) {
        row = row || null;
        const tbody = document.getElementById('schedBody');
        if (!tbody) return; // 4. Schedules tab removed in current layout; safe no-op
        const tr = document.createElement('tr');
        const effFromVal = formatDateForInput(row ? row.effective_from || '' : '');
        const effToVal   = formatDateForInput(row ? row.effective_to   || '' : '');
        const timeStartVal = row ? (row.time_start || '').slice(0,5) || '' : '06:00';
        const timeEndVal   = row ? (row.time_end   || '').slice(0,5) || '' : '08:00';
        // Build 30-minute increment time select options (00:00 to 23:30 = 48 options)
        // Always keep HH:MM 24h format exactly — matches /^\d{2}:\d{2}$/ timeRe in validator.
        let timeOpts = '';
        for (let h = 0; h < 24; h++) {
            for (let m = 0; m < 60; m += 30) {
                const opt = `${pad2(h)}:${pad2(m)}`;
                timeOpts += `<option value="${opt}">${opt}</option>`;
            }
        }
        tr.innerHTML = `<td><select class="form-select schedDow">
            ${DAY_NAMES.map((d,i) => `<option value="${i}"${row && Number(row.day_of_week)===i?' selected':''}>${d}</option>`).join('')}
        </select></td>
        <td><select class="form-select schedWaste">
            ${WASTE_TYPES.map(w => `<option${row && String(row.waste_type||'')===String(w)?' selected':''}>${w}</option>`).join('')}
        </select></td>
        <td><input type="date" class="form-control schedFrom" pattern="\\d{4}-\\d{2}-\\d{2}" minlength="10" maxlength="10" value="${effFromVal}" title="Effective From (optional — blank = recurring every week). Format: YYYY-MM-DD. Use the calendar icon date picker." readonly onclick="try{this.showPicker&&this.showPicker()}catch(_){this.removeAttribute('readonly');setTimeout(()=>this.setAttribute('readonly','readonly'),150);}"></td>
        <td><input type="date" class="form-control schedTo" pattern="\\d{4}-\\d{2}-\\d{2}" minlength="10" maxlength="10" value="${effToVal}" title="Effective To (optional — blank = open-ended). Format: YYYY-MM-DD. Use the calendar icon date picker." readonly onclick="try{this.showPicker&&this.showPicker()}catch(_){this.removeAttribute('readonly');setTimeout(()=>this.setAttribute('readonly','readonly'),150);}"></td>
        <td><select class="form-select schedStart">${timeOpts.replace(`value="${timeStartVal}"`, `value="${timeStartVal}" selected`)}</select></td>
        <td><select class="form-select schedEnd">${timeOpts.replace(`value="${timeEndVal}"`, `value="${timeEndVal}" selected`)}</select></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btnDelSched" title="Delete row"><i data-lucide="x" class="lucide-14"></i></button></td>`;
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        tbody.appendChild(tr);
        tr.querySelector('.btnDelSched').addEventListener('click', () => { tr.remove(); renderScheduleValidationMsg(validateSchedulesRows()); });
        // Date inputs: suppress any keystrokes so only the browser date picker can set values.
        // This guarantees pure calendar selection — no manual typed garbage, copy paste, or weird locales.
        tr.querySelectorAll('.schedFrom, .schedTo').forEach(inp => {
            inp.addEventListener('keydown', ev => ev.preventDefault());
            inp.addEventListener('paste', ev => ev.preventDefault());
            inp.addEventListener('drop', ev => ev.preventDefault());
        });
        // Real-time validation messages on any change
        ['change','input'].forEach(evName => {
            tr.querySelectorAll('input,select').forEach(el => {
                el.addEventListener(evName, () => renderScheduleValidationMsg(validateSchedulesRows()));
            });
        });
    }

    const btnAddSchedEl = document.getElementById('btnAddSched');
    if (btnAddSchedEl) btnAddSchedEl.addEventListener('click', () => addSchedRow(null));
    // Optional-chained from here down: btnNew / btnImportSeed only render for a
    // super admin, and #listBody only renders when at least one drop-off
    // exists. A bare .addEventListener on a missing element throws a TypeError
    // that aborts the whole IIFE opened above, which left the page rendered
    // but completely inert for every non-super admin and on a fresh install.
    /* #btnReset is wired ONCE, at the second handler further down this block,
       which is a strict superset of this one (it also exits step mode and
       deletes an orphan draft). Having both meant every Reset click ran
       emptyForm() twice — two map.setView calls and two full form wipes — and
       the clean result depended on this handler's trailing textContent=''
       running before the other one re-painted. emptyForm() owns the reset. */
    document.getElementById('btnNew')?.addEventListener('click', () => {
        if (document.getElementById('schedValidationMsg')) renderScheduleValidationMsg({ok:true});
    });

    document.getElementById('listBody')?.addEventListener('click', async (e) => {
        if (e.target.closest && e.target.closest('.btnEdit')) return;
        const tr = e.target.closest('tr'); if (!tr) return;
        const id = parseInt(tr.dataset.id,10);
        const d = DROP.find(x => x.id === id);
        if (e.target.classList.contains('btnDel')) {
            if (!confirm('Delete this drop-off point? This action cannot be undone.')) return;
            const fd = new FormData();
            fd.append(CSRF_NAME, CSRF_VAL); fd.append('action','delete'); fd.append('id', String(id));
            const r = await fetch(ENDPOINT, {method:'POST', body:fd}).then(x=>x.json());
            if (r.ok) { tr.remove(); const idx = DROP.findIndex(x=>x.id===id); if (idx>=0) DROP.splice(idx,1); redrawExisting(); document.getElementById('saveMsg').innerHTML = '<span class="text-success">Deleted <i data-lucide="check" class="lucide-14 text-success"></i></span>'; if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
            else { alert('Delete failed: '+ (r.error||'')); }
            return;
        }
        if (d) loadIntoForm(d);
    });

    document.getElementById('btnImportSeed')?.addEventListener('click', async () => {
        if (!confirm('Import 17 seed demo pins for all 16 Marikina barangays? Coordinates are APPROXIMATE — you must drag each pin to the real curb location before publishing. Status PUBLISHED by default.')) return;
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_VAL); fd.append('action','import_seed');
        const r = await fetch(ENDPOINT, {method:'POST', body:fd}).then(x=>x.json());
        const m = document.getElementById('saveMsg');
        if (r.ok) {
            m.innerHTML = `<span class="text-success">Seed import: ${r.created}/${r.total} imported <i data-lucide="check" class="lucide-14 text-success"></i></span>` + (r.skipped?` <span class="text-warning">skipped=${r.skipped}</span>`:'') + (r.failed?.length?`<br><span class="text-danger">${r.failed.map(escapeHtml).join('<br>')}</span>`:'');
            if (typeof window.__renderLucide === 'function') window.__renderLucide();
            setTimeout(()=>location.reload(), 1800);
        } else { m.innerHTML = `<span class="text-danger">Import error: ${escapeHtml(r.error||'')}</span>`; if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
    });

    document.getElementById('btnFilter').addEventListener('click', () => {
        const q = document.getElementById('fQ').value.trim().toLowerCase();
        const bid = document.getElementById('filterBarangay').value;
        document.querySelectorAll('#listBody tr').forEach(tr => {
            const text = tr.textContent.toLowerCase();
            const b = tr.closest('tr');
            const match = (!q || text.includes(q)) && (!bid || String(b.children[1].textContent).trim() === String(document.querySelector('#filterBarangay option[value="'+bid+'"]')?.textContent || '').trim());
            tr.style.display = match ? '' : 'none';
        });
    });

    // ============== Table row Edit button (LEFT LIST) click → 3-step EDIT MODE =============
    // User rule: ONLY this button (Edit in left table) starts edit mode on map pin / info.
    // Double-click marker on MAP does NOT start step_edit mode (per user requirement "NO").
    // For Published rows → show mandatory confirm() warning before allowing to move/update pin location.
    document.getElementById('listBody')?.addEventListener('click', (ev) => {
        const btnEdit = ev.target && ev.target.closest && ev.target.closest('.btnEdit');
        if (!btnEdit) return;
        ev.preventDefault(); ev.stopPropagation();
        const tr = btnEdit.closest('tr[data-id]');
        if (!tr) return;
        const rowId = parseInt(String(tr.getAttribute('data-id') || '0'), 10);
        if (!rowId || rowId <= 0) return;
        // Read the authoritative DB status from the row's data-status attribute.
        // (Previously this read the FIRST `td > span.badge` in the row, which is the
        //  pickup-TYPE column, not Status — so labelToStatus() always fell through to
        //  'DRAFT' and the Published-visibility warning below never fired.)
        const rowStatus = String(tr.getAttribute('data-status') || '').trim().toUpperCase();
        // Confirm for PUBLISHED rows (LIVE to residents) before editing location
        if (rowStatus === 'PUBLISHED') {
            const ok = window.confirm(
                'WARNING: This Published drop-off location is currently VISIBLE to ALL Marikina residents on the public map.\n\n' +
                'Moving its pin location or changing its details will IMMEDIATELY update what residents see.\n\n' +
                'Are you sure you want to edit this Published drop-off point? (You must re-save Step 1 Pin, Step 2 Info, Step 3 Waste Accepted in order.)'
            );
            if (!ok) return;
        }
        // Find the matching DROP[] entry object from page-rendered global so we have lat/lng
        const dObj = (DROP && Array.isArray(DROP)) ? DROP.find(x => parseInt(x && x.id || '0',10) === rowId) : null;
        if (!dObj) return;
        // Load the form with data first (original loadIntoForm)
        __origLoadIntoForm(dObj);
        // Now enter step_edit mode with original status + coords
        const origLat = parseFloat(dObj.latitude || dObj.lat || 0);
        const origLon = parseFloat(dObj.longitude || dObj.lng || dObj.lon || 0);
        enterStepEditMode(rowId, rowStatus, isFinite(origLat)?origLat:null, isFinite(origLon)?origLon:null);
        // Also center the map on the pin so admin can clearly see current location and drag it
        try {
            if (window.adminMap && window.dragPin) {
                window.adminMap.setView([parseFloat(document.getElementById('fLat').value), parseFloat(document.getElementById('fLon').value)], Math.max(window.adminMap.getZoom()||15, 16));
                if (typeof window.dragPin.openPopup === 'function') {
                    try { window.dragPin.closePopup(); } catch(_) {}
                }
            }
        } catch(_) {}
    });

    // =============================================================
    // STEP-BY-STEP WORKFLOW STATE MACHINE (Map Pin → Info → Waste)
    // Mode legend:
    //   'legacy_edit' = editing existing saved record (OLD single-Save at bottom — deprecated, kept for safe fallbacks only, no longer used)
    //   'step_create'  = new DRAFT being built step-by-step (3 per-tab Save buttons)
    //   'step_edit'    = EXISTING saved record (DRAFT or PUBLISHED) being edited step-by-step (3 per-tab Save buttons, updates existing row)
    // =============================================================
    let __workflowMode = 'legacy_edit'; // 'step_create' | 'step_edit' | 'legacy_edit'
    let __draftId = 0; // for step_create = DRAFT id; for step_edit = EXISTING row id (reuse variable naming for safe compatibility)
    let __editingOriginalStatus = null; // only for step_edit: 'DRAFT' | 'PUBLISHED' | 'TEMPORARILY_CLOSED' saved at time Edit clicked
    let __originalPinCoords = null; // only for step_edit: {lat, lon} at time Edit clicked (original saved location), so canSaveStepMap requires pin was moved away
    const __stepUnlocked = { tabPin: true, tabInfo: false, tabWaste: false };
    const __stepDone = { tabPin: false, tabInfo: false };
    const __isStepMode = () => __workflowMode === 'step_create' || __workflowMode === 'step_edit';
    // Expose state to outer helper functions (setSaveButtonEnabled, etc.)
    window.__workflowMode = __workflowMode;
    window.__draftId = __draftId;
    window.__stepUnlocked = __stepUnlocked;
    window.__stepDone = __stepDone;
    // Also expose a helper to re-sync globals on every mode change (called by enter*Mode setters)
    function _syncStepGlobals() {
      window.__workflowMode = __workflowMode;
      window.__draftId = __draftId;
      window.__cancelStepWorkflow = cancelStepWorkflow;
    }

    function setStepBannerHtml(html) {
        const el = document.getElementById('stepBannerText');
        if (el) { el.innerHTML = html; if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
    }
    function setStepMsg(which, txtCls, txt, isHtml) {
        const map = {stepMap:'stepMapMsg', stepInfo:'stepInfoMsg', stepWaste:'stepWasteMsg', save:'saveMsg'};
        const id = map[which]; if (!id) return;
        const el = document.getElementById(id); if (!el) return;
        el.innerHTML = txt ? `<span class="${txtCls||''}">${isHtml ? txt : escapeHtml(txt)}</span>` : '';
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
    }
    function tabNavEl(stepId){ return document.querySelector(`.step-nav[data-step="${stepId}"]`); }
    function modeBannerCta() {
        if (__workflowMode === 'step_create') return 'Create (New Drop-off)';
        if (__workflowMode === 'step_edit') {
            const tag = __editingOriginalStatus === 'PUBLISHED'
                ? '<span class="badge bg-success text-white ms-2 small">PUBLIC · LIVE TO RESIDENTS</span>'
                : (__editingOriginalStatus === 'TEMPORARILY_CLOSED'
                    ? '<span class="badge bg-warning text-dark ms-2 small">TEMPORARILY CLOSED</span>'
                    : '<span class="badge bg-secondary text-white ms-2 small">DRAFT</span>');
            return `Update Existing Drop-off ${tag}`;
        }
        return '';
    }
    function modeBannerHeader() {
        // Rendered as a chip inside .mc-admin-stepchips, so no trailing ": ".
        if (__workflowMode === 'step_create') {
            return '<span class="mc-admin-stepchip mc-admin-stepchip--head"><i data-lucide="list-plus" class="lucide"></i> Step mode</span>';
        }
        if (__workflowMode === 'step_edit') {
            const kind = __editingOriginalStatus === 'PUBLISHED' ? 'LIVE PUBLISHED DROP-OFF'
                       : __editingOriginalStatus === 'TEMPORARILY_CLOSED' ? 'TEMPORARILY CLOSED DROP-OFF'
                       : 'DRAFT DROP-OFF';
            const tone = __editingOriginalStatus === 'PUBLISHED' ? 'success'
                       : (__editingOriginalStatus === 'TEMPORARILY_CLOSED' ? 'warning' : 'secondary');
            return `<span class="mc-admin-stepchip mc-admin-stepchip--head text-${tone}"><i data-lucide="pencil" class="lucide"></i> EDITING EXISTING ${kind}</span>`;
        }
        return '';
    }
    function stepMapButtonLabel() {
        return __workflowMode === 'step_edit' ? '<i data-lucide="save" class="lucide-14"></i> Save Step 1 · Update Pin Location (Move marker on map first, then save here)' : '<i data-lucide="save" class="lucide-14"></i> Save Step 1 · Pin Location';
    }
    function stepInfoButtonLabel() {
        return __workflowMode === 'step_edit' ? '<i data-lucide="save" class="lucide-14"></i> Save Step 2 · Update Info Details' : '<i data-lucide="save" class="lucide-14"></i> Save Step 2 · Info Details';
    }
    function stepFinalizeButtonLabel() {
        return __workflowMode === 'step_edit'
            ? '<i data-lucide="check" class="lucide-14 text-success"></i> Update Drop-off Point · Step 3'
            : '<i data-lucide="check" class="lucide-14 text-success"></i> Create Drop-off Point · Step 3';
    }
    function renderStepWorkflowUI() {
        const banner = document.getElementById('stepBanner');
        const btnSaveBottom = document.getElementById('btnSave');
        const btnStepMap = document.getElementById('btnSaveStepMap');
        const btnStepInfo = document.getElementById('btnSaveStepInfo');
        const btnFinalize = document.getElementById('btnFinalizeDropoff');
        // Update per-step button labels so user clearly sees "Create" vs "Update"
        if (btnStepMap) { btnStepMap.innerHTML = stepMapButtonLabel(); if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
        if (btnStepInfo) { btnStepInfo.innerHTML = stepInfoButtonLabel(); if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
        if (btnFinalize) { btnFinalize.innerHTML = stepFinalizeButtonLabel(); if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
        // Per-tab step buttons are ONLY visible during step_create OR step_edit modes; hide completely in legacy_edit
        if (btnStepMap) {
            if (__isStepMode()) btnStepMap.classList.remove('d-none'); else btnStepMap.classList.add('d-none');
        }
        if (btnStepInfo) {
            if (__isStepMode()) btnStepInfo.classList.remove('d-none'); else btnStepInfo.classList.add('d-none');
        }
        if (btnFinalize) {
            if (__isStepMode()) btnFinalize.classList.remove('d-none'); else btnFinalize.classList.add('d-none');
        }
        const wrapMap = document.getElementById('wrapStepMap');
        if (wrapMap) { if (__isStepMode()) wrapMap.classList.remove('d-none'); else wrapMap.classList.add('d-none'); }
        const wrapInfo = document.getElementById('wrapStepInfo');
        if (wrapInfo) { if (__isStepMode()) wrapInfo.classList.remove('d-none'); else wrapInfo.classList.add('d-none'); }
        const wrapFinal = document.getElementById('wrapStepFinalize');
        if (wrapFinal) { if (__isStepMode()) wrapFinal.classList.remove('d-none'); else wrapFinal.classList.add('d-none'); }
        const step = __workflowMode;
        if (banner) {
            if (__isStepMode()) banner.classList.remove('d-none'); else banner.classList.add('d-none');
        }
        const stepTabNav = document.getElementById('stepTabNav');
        if (stepTabNav) {
            if (__isStepMode()) stepTabNav.classList.remove('d-none'); else stepTabNav.classList.add('d-none');
        }
        const mapInstr = document.getElementById('mapInstrText');
        if (mapInstr) { if (__isStepMode()) mapInstr.classList.remove('d-none'); else mapInstr.classList.add('d-none'); }
        const wrapLegacy = document.getElementById('wrapLegacyActions');
        if (wrapLegacy) { if (__isStepMode()) wrapLegacy.classList.add('d-none'); else wrapLegacy.classList.remove('d-none'); }
        if (btnSaveBottom) {
            if (__isStepMode()) {
                btnSaveBottom.disabled = true;
                btnSaveBottom.classList.add('opacity-50');
                btnSaveBottom.title = 'Step-by-step mode in progress — use the 3 individual Save buttons at the BOTTOM OF EACH TAB PANE (Step 1 Map Pin → Step 2 Info → Step 3 Waste Accepted). Do NOT use this single bottom Save button.';
            } else {
                /* Legacy mode: defer to setSaveButtonEnabled() rather than
                   force-enabling. This used to hard-code disabled = false, and
                   because renderStepWorkflowUI() runs LAST in the init sequence
                   (it is also called by revalidateStepInfo/WasteLive), it
                   overrode the disable that setSaveButtonEnabled() had just
                   applied. Result on page load: Save rendered clickable with
                   "Still missing or invalid: ..." printed directly above it.
                   setSaveButtonEnabled() reads the same validateThreeRequired()
                   the submit gate uses, so the button now matches what clicking
                   it would actually do. No recursion — it does not call back. */
                setSaveButtonEnabled();
            }
        }
        ['tabPin','tabInfo','tabWaste'].forEach(sid => {
            const el = tabNavEl(sid); if (!el) return;
            el.classList.remove('btn-outline-secondary','disabled','opacity-50','bg-success-subtle','fw-semibold','border-primary','text-success');
            el.removeAttribute('aria-disabled');
            el.style.cursor = '';
            if (!__isStepMode()) return; // legacy_edit = all tabs unlocked, no decorations
            const locked = !__stepUnlocked[sid];
            if (locked) {
                el.classList.add('disabled','opacity-50');
                el.setAttribute('aria-disabled','true');
                el.style.cursor = 'not-allowed';
            } else {
                el.classList.remove('disabled','opacity-50');
                el.removeAttribute('aria-disabled');
                el.style.cursor = 'pointer';
            }
            if (__stepDone[sid]) { el.classList.add('text-success','fw-semibold'); }
        });
        if (__isStepMode()) {
            // Each step is its own chip so the banner wraps as discrete units
            // instead of folding into an unreadable run-on blob at 50% width.
            const chip = (sid, label) => {
                const locked = !__stepUnlocked[sid];
                const done = !!__stepDone[sid];
                const cls = ['mc-admin-stepchip', done ? 'is-done' : '', locked ? '' : 'is-active']
                    .filter(Boolean).join(' ');
                return `<span class="${cls}">` +
                    (locked ? '<i data-lucide="lock" class="lucide"></i>' : '') +
                    `${label}` +
                    (done ? ' <i data-lucide="check" class="lucide"></i>' : '') +
                    `</span>`;
            };
            const arrow = '<span class="mc-admin-steparrow"><i data-lucide="arrow-right" class="lucide"></i></span>';
            // Three stacked lines: (1) head chip, (2) steps 1-3 on ONE nowrap
            // line, (3) the CTA. Steps+CTA cannot share a line — together they
            // need ~733px but the 50% column only offers ~615px at 1310px.
            setStepBannerHtml(
                '<span class="mc-admin-stepchips">' +
                `<span class="mc-admin-step-head">${modeBannerHeader()}</span>` +
                '<span class="mc-admin-stepgroup">' +
                chip('tabPin', '1. Map Pin') + arrow +
                chip('tabInfo', '2. Info') + arrow +
                chip('tabWaste', '3. Waste Accepted') +
                '</span>' +
                `<span class="mc-admin-stepcta"><span class="mc-admin-steparrow"><i data-lucide="arrow-right" class="lucide"></i></span>${modeBannerCta()}</span>` +
                '</span>'
            );
        }
        // enable/disable per-step save buttons using validator functions
        if (btnStepMap) btnStepMap.disabled = !__isStepMode() || !canSaveStepMap();
        if (btnStepInfo) btnStepInfo.disabled = !__isStepMode() || !__stepDone.tabPin || !__stepUnlocked.tabInfo || !canSaveStepInfo();
        if (btnFinalize) {
            const canFinal = __isStepMode() && __stepDone.tabInfo && __stepUnlocked.tabWaste && canSaveStepWaste();
            btnFinalize.disabled = !canFinal;
        }
    }
    function showOnlyLowestUnlockedStep() {
        if (!__isStepMode()) return;
        let stepShow = 'tabPin';
        if (__stepUnlocked.tabWaste && __stepDone.tabInfo) stepShow = 'tabWaste';
        else if (__stepUnlocked.tabInfo && __stepDone.tabPin) stepShow = 'tabInfo';
        switchBootstrapTab(stepShow);
    }
    function switchBootstrapTab(targetStepId) {
        const el = tabNavEl(targetStepId);
        if (!el) return;
        try { (new bootstrap.Tab(el)).show(); } catch(_) {}
    }
    function enterStepCreateMode(draftId) {
        __workflowMode = 'step_create';
        __draftId = parseInt(draftId,10) || 0;
        _syncStepGlobals();
        __stepUnlocked.tabPin = true; __stepUnlocked.tabInfo = false; __stepUnlocked.tabWaste = false;
        __stepDone.tabPin = false; __stepDone.tabInfo = false;
        document.getElementById('fId').value = String(__draftId);
        // reset form to default minimal empty draft state — EVERYTHING starts blank, user must fill
        const fBrgy = document.querySelector('#saveForm #fBarangay'); if (fBrgy) fBrgy.value = '';
        const fTypeEl = document.getElementById('fType'); if (fTypeEl) { fTypeEl.value = ''; if (fTypeEl.options?.[0]) fTypeEl.selectedIndex = 0; }
        document.getElementById('fName').value = '';
        const fAddr = document.querySelector('#saveForm #fAddress'); if (fAddr) { fAddr.value=''; if (fAddr.dataset) delete fAddr.dataset.autoFilled; }
        const fStatusEl = document.getElementById('fStatus'); if (fStatusEl) { fStatusEl.value = ''; if (fStatusEl.options?.[0]) fStatusEl.selectedIndex = 0; }
        applyHoursTextAutoPreset(''); writeOperationDaysHidden();
        document.getElementById('fNotes').value = '';
        document.getElementById('fPhoto').value = '';
        ['accepts_bio','accepts_nonbio','accepts_recyclable'].forEach(c => document.getElementById(c).checked = true);
        ['accepts_hazard','accepts_bulky'].forEach(c => document.getElementById(c).checked = false);
        const schedBodyEl = document.getElementById('schedBody');
        if (schedBodyEl) { schedBodyEl.innerHTML = ''; if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
        document.getElementById('saveMsg').innerHTML = '';
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        setStepMsg('stepMap','', ''); setStepMsg('stepInfo','', ''); setStepMsg('stepWaste','', '');
        if (document.getElementById('schedValidationMsg')) renderScheduleValidationMsg({ ok: true });
        renderStepWorkflowUI();
        switchBootstrapTab('tabPin');
        setSaveButtonEnabled();
        refreshSaveBannerFromValidation();
    }
    function enterLegacyEditMode(savedId) {
        __workflowMode = 'legacy_edit';
        __draftId = 0;
        __editingOriginalStatus = null;
        __originalPinCoords = null;
        _syncStepGlobals();
        __stepUnlocked.tabPin = true; __stepUnlocked.tabInfo = true; __stepUnlocked.tabWaste = true;
        __stepDone.tabPin = false; __stepDone.tabInfo = false;
        document.getElementById('fId').value = String(parseInt(savedId,10) || 0);
        setStepMsg('stepMap','', ''); setStepMsg('stepInfo','', ''); setStepMsg('stepWaste','', '');
        renderStepWorkflowUI();
        setSaveButtonEnabled();
    }
    function enterStepEditMode(savedId, originalStatus, origLat, origLon) {
        __workflowMode = 'step_edit';
        __draftId = parseInt(savedId,10) || 0;
        __editingOriginalStatus = (originalStatus && ['DRAFT','PUBLISHED','TEMPORARILY_CLOSED'].includes(originalStatus)) ? originalStatus : 'DRAFT';
        __originalPinCoords = (origLat != null && origLon != null) ? { lat: parseFloat(origLat), lon: parseFloat(origLon) } : null;
        _syncStepGlobals();
        __stepUnlocked.tabPin = true; __stepUnlocked.tabInfo = false; __stepUnlocked.tabWaste = false;
        __stepDone.tabPin = false; __stepDone.tabInfo = false;
        document.getElementById('fId').value = String(__draftId);
        // Pre-set fStatus in the Info tab to the original status (not empty)
        const fStatusEl = document.getElementById('fStatus');
        if (fStatusEl) {
            const hasOpt = [...fStatusEl.options].some(o => String(o.value) === String(__editingOriginalStatus));
            if (hasOpt) fStatusEl.value = String(__editingOriginalStatus);
        }
        setStepMsg('stepMap','', ''); setStepMsg('stepInfo','', ''); setStepMsg('stepWaste','', '');
        if (document.getElementById('schedValidationMsg')) renderScheduleValidationMsg({ ok: true });
        renderStepWorkflowUI();
        showOnlyLowestUnlockedStep();
        setSaveButtonEnabled();
        refreshSaveBannerFromValidation();
    }
    function cancelStepWorkflow() {
        if (!__isStepMode()) return;
        // ALWAYS show confirm (per user req — never silent exit)
        const isCreate = __workflowMode === 'step_create';
        const mode = isCreate
            ? 'Creating a NEW Drop-off point (step-by-step mode)'
            : 'EDITING an existing Drop-off point (step-by-step UPDATE mode)';
        const lines = [];
        lines.push('YOU ARE ABOUT TO EXIT STEP-BY-STEP WORKFLOW');
        lines.push('');
        lines.push('Current mode: '+mode);
        lines.push('');
        lines.push('WARNING: ANY UNSAVED CHANGES / MODIFICATIONS YOU MADE THAT HAVE NOT YET BEEN PERSISTED BY ALL 3 STEP SAVE BUTTONS (Step 1 Pin → Step 2 Info → Step 3 Finalize) WILL BE PERMANENTLY LOST AND CANNOT BE RETRIEVED!');
        lines.push('');
        lines.push('To continue editing: click "Cancel" on this dialog.');
        lines.push('To EXIT AND ABANDON ALL UNSAVED WORK: click "OK".');
        const ok = confirm(lines.join('\n'));
        if (!ok) return;
        // If step_create: also try to delete the orphan DRAFT row so DB doesn't accumulate garbage
        if (isCreate && __draftId > 0) {
            try {
                const fd = new FormData();
                fd.append(CSRF_NAME, CSRF_VAL);
                fd.append('action','delete');
                fd.append('id', String(__draftId));
                fetch(ENDPOINT, {method:'POST', body:fd}).catch(()=>{});
            } catch(_) {}
        }
        __workflowMode = '';
        __draftId = 0;
        __editingOriginalStatus = null;
        __originalPinCoords = null;
        __stepUnlocked.tabPin = true; __stepUnlocked.tabInfo = true; __stepUnlocked.tabWaste = true;
        __stepDone.tabPin = false; __stepDone.tabInfo = false;
        _syncStepGlobals();
        document.querySelectorAll('.nav-link.step-nav').forEach(n => { try { n.classList.remove('disabled'); } catch(_) {} });
        setStepMsg('stepMap','', ''); setStepMsg('stepInfo','', ''); setStepMsg('stepWaste','', ''); setStepMsg('save','', '');
        emptyForm();
        renderStepWorkflowUI();
        setSaveButtonEnabled();
        refreshSaveBannerFromValidation();
        switchBootstrapTab('tabPin');
        const saveMsg = document.getElementById('saveMsg');
        if (saveMsg) { saveMsg.innerHTML = '<span class="text-secondary small">Step workflow exited. You can freely browse: filter rows, click any row to edit, or start a new drop-off.</span>'; if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
    }
    document.getElementById('btnExitStepMode')?.addEventListener('click', cancelStepWorkflow);
    function canSaveStepMap() {
        if (!__isStepMode()) return false;
        const f = id => document.getElementById(id);
        const latS = String(f('fLat')?.value ?? '').trim();
        const lonS = String(f('fLon')?.value ?? '').trim();
        if (!latS || !lonS) return false;
        const lat = parseFloat(latS), lon = parseFloat(lonS);
        if (!isFinite(lat) || !isFinite(lon)) return false;
        if (__workflowMode === 'step_edit' && __originalPinCoords) {
            // Existing row: require pin was MOVED away from its original saved coords
            const oLat = parseFloat(__originalPinCoords.lat);
            const oLon = parseFloat(__originalPinCoords.lon);
            const moved = (Math.abs(lat - oLat) > 1e-6) || (Math.abs(lon - oLon) > 1e-6);
            if (!moved) {
                // Also allow if admin explicitly clicked/dropped anywhere (_lastPinAttempted true) even if coords same (edge case)
                if (!_lastPinAttempted) return false;
            }
        } else {
            // step_create: require pin placed away from Marikina default center
            const atDefault = Math.abs(lat - MARIKINA_CENTER[0]) < 1e-6 && Math.abs(lon - MARIKINA_CENTER[1]) < 1e-6;
            if (atDefault && !_lastPinAttempted) return false;
        }
        try {
            if (!isInsideMarikina(L.latLng(lat, lon))) return false;
        } catch(_) { return false; }
        return true;
    }
    function canSaveStepInfo() {
        if (!__isStepMode()) return false;
        const issues = [];
        const f = id => document.getElementById(id);

        const brgy = parseInt(document.querySelector('#saveForm #fBarangay')?.value || '0', 10);
        if (!brgy || brgy <= 0) issues.push('barangay');

        const typeVal = String(document.querySelector('#saveForm #fType')?.value || '').trim();
        if (!typeVal) issues.push('type');

        const spot = String(f('fName')?.value || '').trim();
        if (spot.length < 2) issues.push('landmark');

        const addr = String(document.querySelector('#saveForm #fAddress')?.value || '').trim();
        if (addr.length < 5) issues.push('address');

        const statusVal = String(document.querySelector('#saveForm #fStatus')?.value || '').trim();
        if (!statusVal) issues.push('status');

        const open24 = String(f('fOpen24')?.value ?? '0') === '1';
        const hoursStr = String(f('fHours')?.value || '').trim();
        if (!open24 && !hoursStr) issues.push('hours');

        return issues.length === 0;
    }

    function revalidateStepInfoLive() {
        renderStepWorkflowUI();
        const m = document.getElementById('stepInfoMsg');
        if (!m) return;
        if (__workflowMode !== 'step_create' || !__stepUnlocked.tabInfo) { m.innerHTML=''; return; }
        if (canSaveStepInfo()) { m.innerHTML='<span class="text-success small fw-semibold"><i data-lucide="check" class="lucide-14 text-success"></i> All Info fields are filled and valid — click <i data-lucide="save" class="lucide-14"></i> Save Step 2 · Info Details to persist and unlock Step 3 (Waste Accepted).</span>'; return; }
        const missing=[];
        const brgy = parseInt(document.querySelector('#saveForm #fBarangay')?.value || '0', 10);
        if (!brgy || brgy<=0) missing.push('Barangay (*)');
        const typeVal = String(document.querySelector('#saveForm #fType')?.value || '').trim();
        if (!typeVal) missing.push('Pickup / Facility type (*)');
        const spot = String(document.getElementById('fName')?.value || '').trim();
        if (spot.length < 2) missing.push('Landmark (*)');
        const addr = String(document.querySelector('#saveForm #fAddress')?.value || '').trim();
        if (addr.length < 5) missing.push('Full street address (*)');
        const statusVal = String(document.querySelector('#saveForm #fStatus')?.value || '').trim();
        if (!statusVal) missing.push('Status');
        const open24 = String(document.getElementById('fOpen24')?.value ?? '0') === '1';
        const hoursStr = String(document.getElementById('fHours')?.value || '').trim();
        if (!open24 && !hoursStr) missing.push('Hours & Days of Operation (either turn ON the Open 24/7 chip, or use + Add range below to add at least one custom hours range)');
        m.innerHTML = `<span class="text-danger small fw-semibold"><i data-lucide="x-circle" class="lucide-14"></i> Missing required fields before Step 2 save: ${missing.map(escapeHtml).join('  •  ')}</span>`;
    }
    function canSaveStepWaste() {
        if (!__isStepMode()) return false;
        const cols = ['accepts_bio','accepts_nonbio','accepts_recyclable','accepts_hazard','accepts_bulky'];
        const n = cols.filter(id => !!document.getElementById(id)?.checked).length;
        return n >= 1;
    }

    // Tab blocker (LAYER 1 — Bootstrap hide.bs.tab event): prevents switching tabs BY ANY METHOD when target is locked during step_create OR step_edit.
    // (This is more reliable than click listeners alone — it also blocks keyboard Enter/Space, direct API calls like bootstrap.Tab.getOrCreateInstance(el).show(), URL hash activation, etc.)
    document.querySelectorAll('.nav-link.step-nav').forEach(navBtn => {
        navBtn.addEventListener('hide.bs.tab', (e) => {
            if (!__isStepMode()) return;
            const sid = String(navBtn.getAttribute('data-step') || '');
            if (!sid) return;
            // If navigating AWAY from an unlocked tab, we need to check TARGET tab. Bootstrap gives us target in relatedTarget.
            // For hide.bs.tab on a tab, `e.target` is the tab being hidden, `e.relatedTarget` is the tab BEING SHOWN.
            const targetBtn = e.relatedTarget;
            if (!targetBtn) return;
            const targetSid = String(targetBtn.getAttribute('data-step') || '');
            if (!targetSid) return;
            if (!(targetSid in __stepUnlocked)) return; // not a managed step tab (e.g. leftover — shouldn't happen)
            const targetUnlocked = !!__stepUnlocked[targetSid];
            if (!targetUnlocked) {
                e.preventDefault();
                e.stopPropagation();
                const msg = {
                    tabInfo:  '<i data-lucide="x-circle" class="lucide-14"></i> Step 1 (Map Pin) is REQUIRED first — place/drag a pin on the map then click <i data-lucide="save" class="lucide-14"></i> Save Step 1 · '+(__workflowMode==='step_edit'?'Update Pin Location':'Pin Location')+' at the bottom of the Map Pin pane. You cannot open Step 2 Info without a saved pin location.',
                    tabWaste: '<i data-lucide="x-circle" class="lucide-14"></i> Step 2 (Info Details) is REQUIRED first — fill every required field (*) then click <i data-lucide="save" class="lucide-14"></i> Save Step 2 · '+(__workflowMode==='step_edit'?'Update Info Details':'Info Details')+' at the bottom of the Info pane. You cannot open Step 3 Waste Accepted until Info is completely filled and saved.',
                }[targetSid] || '<i data-lucide="triangle-alert" class="lucide-14 text-danger"></i> This step is currently LOCKED. Finish and save the earlier steps first.';
                setStepMsg('save','text-danger small fw-medium', msg);
                const bannerBefore = document.getElementById('stepBannerText')?.innerHTML || '';
                setTimeout(()=>{ const sb = document.getElementById('stepBannerText'); if (sb) sb.innerHTML = bannerBefore + ' <span class="text-danger fw-semibold">' + escapeHtml(msg) + '</span>'; }, 0);
                renderStepWorkflowUI();
            }
        });
    });

    // Tab blocker (LAYER 2 — click listener): shows friendly error and prevents default on button click (visual feedback + direct click prevent).
    document.querySelectorAll('.step-nav').forEach(navBtn => {
        navBtn.addEventListener('click', (e) => {
            if (!__isStepMode()) return;
            const sid = String(navBtn.getAttribute('data-step') || '');
            if (!sid) return;
            const unlocked = !!__stepUnlocked[sid];
            if (!unlocked) {
                e.preventDefault(); e.stopPropagation();
                const msg = {
                    tabInfo:  '<i data-lucide="x-circle" class="lucide-14"></i> Step 1 (Map Pin) is REQUIRED first — place/drag a pin on the map then click <i data-lucide="save" class="lucide-14"></i> Save Step 1 · '+(__workflowMode==='step_edit'?'Update Pin Location':'Pin Location')+' at the bottom of the Map Pin pane. You cannot open Step 2 Info without a saved pin location.',
                    tabWaste: '<i data-lucide="x-circle" class="lucide-14"></i> Step 2 (Info Details) is REQUIRED first — fill every required field (*) then click <i data-lucide="save" class="lucide-14"></i> Save Step 2 · '+(__workflowMode==='step_edit'?'Update Info Details':'Info Details')+' at the bottom of the Info pane. You cannot open Step 3 Waste Accepted until Info is completely filled and saved.',
                    tabPin:   'Map Pin is always the current active step — finish Step 1 first.',
                }[sid] || '<i data-lucide="triangle-alert" class="lucide-14 text-danger"></i> This step is currently LOCKED. Finish and save the earlier steps first.';
                setStepMsg('save','text-danger small fw-medium', msg);
                const bannerBefore = document.getElementById('stepBannerText')?.innerHTML || '';
                setTimeout(()=>{ const sb = document.getElementById('stepBannerText'); if (sb) sb.innerHTML = bannerBefore + ' <span class="text-danger fw-semibold">' + escapeHtml(msg) + '</span>'; }, 0);
                renderStepWorkflowUI();
            } else {
                setStepMsg('save','', '');
            }
        });
    });

    // ========= btnNew: START THE 3-STEP FLOW =========
    document.getElementById('btnNew')?.addEventListener('click', async () => {
        if (document.getElementById('schedValidationMsg')) renderScheduleValidationMsg({ok:true});
        // Confirm if they're mid-step and want to abandon
        if (__workflowMode === 'step_create' && __draftId > 0 && !(__stepDone.tabPin && __stepDone.tabInfo)) {
            const ok = confirm('Abandon the current in-progress new drop-off? Unsaved step progress will be lost permanently.');
            if (!ok) return;
            // best-effort delete the stale draft so DB doesn't accumulate empty drafts
            try {
                const fd = new FormData();
                fd.append(CSRF_NAME, CSRF_VAL); fd.append('action','delete'); fd.append('id', String(__draftId));
                fetch(ENDPOINT, {method:'POST', body:fd}).catch(()=>{});
            } catch(_) {}
        }
        const m = document.getElementById('saveMsg');
        m.innerHTML = '<span class="text-muted small">Creating empty DRAFT and entering step-by-step creation mode…</span>';
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_VAL); fd.append('action','create_draft');
        const r = await fetch(ENDPOINT, {method:'POST', body:fd}).then(x=>x.json()).catch(err => ({ok:false,error:String(err)}));
        if (!r.ok) { m.innerHTML = `<span class="text-danger">Failed to start new drop-off: ${escapeHtml(r.error||'Unknown error')}</span>`; return; }
        enterStepCreateMode(r.id);
        m.innerHTML = `<span class="text-success small fw-semibold">Started 3-step creation mode. You are on Step 1 — drop or drag the pin, then click Save Step 1.</span>`;
    });

    // Reset btn: also kill step mode, go back to legacy edit empty
    document.getElementById('btnReset')?.addEventListener('click', () => {
        if (__workflowMode === 'step_create' && __draftId > 0) {
            try {
                const fd = new FormData();
                fd.append(CSRF_NAME, CSRF_VAL); fd.append('action','delete'); fd.append('id', String(__draftId));
                fetch(ENDPOINT, {method:'POST', body:fd}).catch(()=>{});
            } catch(_) {}
        }
        enterLegacyEditMode(0);
        emptyForm(); // clears #saveMsg and marks the form pristine itself
        if (document.getElementById('schedValidationMsg')) renderScheduleValidationMsg({ok:true});
    });

    // Load (edit on existing row) → EXIT step mode, return to legacy single-Save mode.
    // Wrap the hoisted loadIntoForm function so both local internal calls (dblclick, list Edit click)
    // AND any external window.loadIntoForm callers get the mode-switching behavior.
    const __origLoadIntoForm = loadIntoForm;
    loadIntoForm = function __wrappedLoadIntoForm(d) {
        const res = __origLoadIntoForm.apply(this, arguments);
        const v = parseInt(d?.id || '0', 10);
        if (v > 0) {
            const isOurStepDraft = (__workflowMode === 'step_create') && (v === __draftId);
            if (!isOurStepDraft) {
                enterLegacyEditMode(v);
            }
        }
        return res;
    };
    window.loadIntoForm = loadIntoForm;
    try { _baExport('loadIntoForm', loadIntoForm); } catch(_) {}

    // ============== PER-STEP SAVE HANDLERS (BOTH step_create AND step_edit share same buttons; action chosen by mode) ==============
    document.getElementById('btnSaveStepMap').addEventListener('click', async () => {
        if (!canSaveStepMap()) return;
        const isCreate = __workflowMode === 'step_create';
        const stepLabel = isCreate ? 'step 1 done — Step 2 (Info) now unlocked.' : 'Step 1 UPDATE saved — Step 2 (Info) now unlocked.';
        setStepMsg('stepMap','', (isCreate ? 'Saving pin location…' : 'Saving updated pin location…'));
        const f = id => document.getElementById(id);
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_VAL); fd.append('action', isCreate ? 'save_step_map' : 'edit_save_map');
        fd.append('id', String(__draftId));
        fd.append('latitude', String(parseFloat(f('fLat')?.value||'0') || '0'));
        fd.append('longitude', String(parseFloat(f('fLon')?.value||'0') || '0'));
        fd.append('place_osm_id', String(f('fOsm')?.value || ''));
        const r = await fetch(ENDPOINT, {method:'POST', body:fd}).then(x=>x.json()).catch(err=>({ok:false,error:String(err)}));
        if (!r.ok) { setStepMsg('stepMap','text-danger', '<i data-lucide="x-circle" class="lucide-14"></i> ' + escapeHtml(r.error || 'Save failed'), true); return; }
        __stepDone.tabPin = true;
        __stepUnlocked.tabInfo = true;
        // For step_edit: pin location now updated; reset original coords to NEW location so subsequent same-location clicks no longer trigger "must move pin"
        if (!isCreate) {
            const nc = (r.new_coords && typeof r.new_coords === 'object') ? r.new_coords : { lat: parseFloat(f('fLat').value||'0'), lng: parseFloat(f('fLon').value||'0') };
            __originalPinCoords = { lat: isFinite(parseFloat(nc.lat)) ? parseFloat(nc.lat) : parseFloat(nc.latitude), lon: isFinite(parseFloat(nc.lng)) ? parseFloat(nc.lng) : parseFloat(nc.longitude) };
        }
        renderStepWorkflowUI();
        setStepMsg('stepMap','text-success fw-semibold', '<i data-lucide="check" class="lucide-14 text-success"></i> Pin saved (' + escapeHtml(stepLabel) + ')', true);
        // Also: run the auto-geocode-reverse to pre-populate barangay + address on pin-save so user Info tab is prefilled
        try { await reverseGeocodeAndAutofill(); } catch(_) {}
        setTimeout(() => switchBootstrapTab('tabInfo'), 250);
        setTimeout(() => { renderStepWorkflowUI(); revalidateStepInfoLive(); }, 600);
    });

    function revalidateStepInfoLive() {
        renderStepWorkflowUI();
        const m = document.getElementById('stepInfoMsg');
        if (!m) return;
        if (!__isStepMode() || !__stepUnlocked.tabInfo) { m.innerHTML=''; return; }
        if (canSaveStepInfo()) {
            m.innerHTML = (__workflowMode === 'step_edit')
                ? '<span class="text-success small fw-semibold"><i data-lucide="check" class="lucide-14 text-success"></i> All Info fields valid — click <i data-lucide="save" class="lucide-14"></i> Save Step 2 · Update Info Details to persist and unlock Step 3 (Waste Accepted).</span>'
                : '<span class="text-success small fw-semibold"><i data-lucide="check" class="lucide-14 text-success"></i> All Info required fields are filled and valid — click <i data-lucide="save" class="lucide-14"></i> Save Step 2 · Info Details to persist and unlock Step 3 (Waste Accepted).</span>';
            return;
        }
        const missing=[];
        const brgy = parseInt(document.querySelector('#saveForm #fBarangay')?.value || '0', 10);
        if (!brgy || brgy<=0) missing.push('Barangay (*)');
        const typeVal = String(document.querySelector('#saveForm #fType')?.value || '').trim();
        if (!typeVal) missing.push('Pickup / Facility type (*)');
        const spot = String(document.getElementById('fName')?.value || '').trim();
        if (spot.length < 2) missing.push('Landmark (*)');
        const addr = String(document.querySelector('#saveForm #fAddress')?.value || '').trim();
        if (addr.length < 5) missing.push('Full street address (*)');
        const statusVal = String(document.querySelector('#saveForm #fStatus')?.value || '').trim();
        if (!statusVal) missing.push('Status (*)');
        const open24 = String(document.getElementById('fOpen24')?.value ?? '0') === '1';
        const hoursStr = String(document.getElementById('fHours')?.value || '').trim();
        if (!open24 && hoursStr === '') missing.push('Hours & Days of Operation (either turn ON the Open 24/7 chip, OR use + Add range to add at least one custom hours range)');
        m.innerHTML = `<span class="text-danger small fw-semibold"><i data-lucide="x-circle" class="lucide-14"></i> Missing required fields before Step 2 save: ${missing.map(escapeHtml).join('  •  ')}</span>`;
    }
    function revalidateStepWasteLive() {
        renderStepWorkflowUI();
        const m = document.getElementById('stepWasteMsg'); if (!m) return;
        if (!__isStepMode() || !__stepUnlocked.tabWaste) { m.innerHTML=''; return; }
        if (canSaveStepWaste()) {
            m.innerHTML = (__workflowMode === 'step_edit')
                ? '<span class="text-success small"><i data-lucide="check" class="lucide-14 text-success"></i> Waste acceptance configured — click the blue Update Drop-off Point button below to SAVE ALL CHANGES (Step 3 finalize).</span>'
                : '<span class="text-success small"><i data-lucide="check" class="lucide-14 text-success"></i> Waste acceptance is configured — click the blue Create Drop-off Point button below to finalize.</span>';
            return;
        }
        m.innerHTML = '<span class="text-danger small fw-semibold"><i data-lucide="triangle-alert" class="lucide-14 text-danger"></i> Tick at least one waste checkbox before finalizing (Bio / Non-bio / Recyclable / Hazardous / Bulky).</span>';
    }
    ['#saveForm #fBarangay','#saveForm #fType','#saveForm #fName','#saveForm #fAddress','#saveForm #fStatus','#saveForm #fNotes','#saveForm #fPhoto'].forEach(sel => {
        const el = document.querySelector(sel); if (!el) return;
        el.addEventListener('change', () => { markFormTouched(); revalidateStepInfoLive(); setSaveButtonEnabled(); refreshSaveBannerFromValidation(); });
        el.addEventListener('input',  () => { markFormTouched(); revalidateStepInfoLive(); setSaveButtonEnabled(); refreshSaveBannerFromValidation(); });
    });
    document.getElementById('customH_add')?.addEventListener('click', () => { markFormTouched(); revalidateStepInfoLive(); });
    document.getElementById('customH_clear')?.addEventListener('click', () => { markFormTouched(); revalidateStepInfoLive(); });
    document.getElementById('customH_undo')?.addEventListener('click', () => { markFormTouched(); revalidateStepInfoLive(); });
    ['accepts_bio','accepts_nonbio','accepts_recyclable','accepts_hazard','accepts_bulky'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', revalidateStepWasteLive);
    });
    // Leaflet drag / pin move → refresh Save Step 1 Map button state live
    dragPin.on('moveend', () => {
        _lastPinAttempted = true;
        try { setSaveButtonEnabled(); } catch(_) {}
        renderStepWorkflowUI();
    });
    map.on('click', () => {
        _lastPinAttempted = true;
        setTimeout(() => { try { setSaveButtonEnabled(); } catch(_) {} renderStepWorkflowUI(); }, 50);
    });

    document.getElementById('btnSaveStepInfo').addEventListener('click', async () => {
        if (!canSaveStepInfo()) { revalidateStepInfoLive(); return; }
        const isCreate = __workflowMode === 'step_create';
        setStepMsg('stepInfo','', (isCreate ? 'Saving Info details…' : 'Saving Info UPDATE…'));
        writeOperationDaysHidden();
        const f = id => document.getElementById(id);
        const open24 = String(f('fOpen24')?.value ?? '0') === '1';
        let rawHours = f('fHours').value || '';
        if (open24 && !rawHours) rawHours = '24 hours (round the clock)';
        const daysArr = selectedOperationDayIndexes();
        const hoursToSave = operationHoursStringPrefixedWithDays(rawHours, daysArr);
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_VAL); fd.append('action', (isCreate ? 'save_step_info' : 'edit_save_info'));
        fd.append('id', String(__draftId));
        fd.append('barangay_id', String(parseInt(document.querySelector('#saveForm #fBarangay')?.value||'0',10) || '0'));
        fd.append('spot_name', String(f('fName')?.value || ''));
        fd.append('address', String(document.querySelector('#saveForm #fAddress')?.value || ''));
        fd.append('pickup_type', String(f('fType')?.value || ''));
        fd.append('status', String(f('fStatus')?.value || ''));
        fd.append('operation_hours', hoursToSave);
        if (open24) fd.append('open_24_7', '1');
        fd.append('notes_public', String(f('fNotes')?.value || ''));
        fd.append('reference_photo', String(f('fPhoto')?.value || ''));
        fd.append('place_osm_id', String(f('fOsm')?.value || ''));
        const r = await fetch(ENDPOINT, {method:'POST', body:fd}).then(x=>x.json()).catch(err=>({ok:false,error:String(err)}));
        if (!r.ok) { setStepMsg('stepInfo','text-danger', '<i data-lucide="x-circle" class="lucide-14"></i> ' + escapeHtml(r.error || 'Save step 2 failed'), true); return; }
        __stepDone.tabInfo = true;
        __stepUnlocked.tabWaste = true;
        renderStepWorkflowUI();
        revalidateStepWasteLive();
        setStepMsg('stepInfo','text-success fw-semibold', isCreate
            ? '<i data-lucide="check" class="lucide-14 text-success"></i> Info saved (step 2 done) — Step 3 Waste Accepted now unlocked. Click "Create Drop-off Point (New Drop-off) · Step 3" to finalize.'
            : '<i data-lucide="check" class="lucide-14 text-success"></i> Info UPDATE saved (step 2 done) — Step 3 Waste Accepted now unlocked. Click the blue "Update Drop-off Point · Step 3" button below to finalize and save ALL changes.', true);
        setTimeout(() => switchBootstrapTab('tabWaste'), 250);
        setTimeout(() => { renderStepWorkflowUI(); revalidateStepWasteLive(); }, 400);
    });

    document.getElementById('btnFinalizeDropoff').addEventListener('click', async () => {
        if (!canSaveStepWaste()) { revalidateStepWasteLive(); return; }
        const isCreate = __workflowMode === 'step_create';
        setStepMsg('stepWaste','', isCreate ? 'Finalizing — creating new drop-off point entry…' : 'Finalizing — applying ALL UPDATES to drop-off point…');
        writeOperationDaysHidden();
        const schedResult = validateSchedulesRows();
        renderScheduleValidationMsg(schedResult);
        if (!schedResult.ok) { setStepMsg('stepWaste','text-danger', '<i data-lucide="x-circle" class="lucide-14"></i> Fix schedule table errors first: ' + escapeHtml(schedResult.issues?.[0] || 'invalid rows'), true); return; }
        const schedules = schedResult.rows || [];
        const f = id => document.getElementById(id);
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_VAL); fd.append('action', isCreate ? 'save_finalize' : 'edit_finalize');
        fd.append('id', String(__draftId));
        fd.append('status', String(f('fStatus')?.value || (isCreate ? 'PUBLISHED' : String(__editingOriginalStatus || 'PUBLISHED'))));
        ['accepts_bio','accepts_nonbio','accepts_recyclable','accepts_hazard','accepts_bulky'].forEach(id => { if (f(id)?.checked) fd.append(id, '1'); });
        fd.append('schedules_json', JSON.stringify(schedules));
        const r = await fetch(ENDPOINT, {method:'POST', body:fd}).then(x=>x.json()).catch(err=>({ok:false,error:String(err)}));
        if (!r.ok) { setStepMsg('stepWaste','text-danger', '<i data-lucide="x-circle" class="lucide-14"></i> Finalize failed: ' + escapeHtml(r.error || 'unknown error'), true); return; }
        setStepMsg('stepWaste','text-success fw-semibold', isCreate
            ? `<i data-lucide="check" class="lucide-14 text-success"></i> Drop-off point created successfully. Reloading page…`
            : `<i data-lucide="check" class="lucide-14 text-success"></i> Drop-off point UPDATED successfully. Reloading page…`, true);
        const savem = document.getElementById('saveMsg');
        if (savem) savem.innerHTML = isCreate
            ? `<span class="text-success fw-semibold">Saved <i data-lucide="check" class="lucide-14 text-success"></i> — New drop-off point created successfully.</span>`
            : `<span class="text-success fw-semibold">Saved <i data-lucide="check" class="lucide-14 text-success"></i> — Drop-off point UPDATED. Map pin location, Info details, and Waste Accepted selections are all saved now.</span>`;
        setTimeout(() => location.reload(), 1100);
    });

    // init state
    renderStepWorkflowUI();

    function updatePhotoPreview() {
        const inp = document.getElementById('fPhoto');
        const img = document.getElementById('fPhotoPreview');
        if (!inp || !img) return;
        const v = String(inp.value || '').trim();
        if (!v) { img.classList.add('d-none'); img.removeAttribute('src'); return; }
        img.src = v;
        img.classList.remove('d-none');
    }
    window.updatePhotoPreview = updatePhotoPreview;
    document.getElementById('fPhoto')?.addEventListener('input', updatePhotoPreview);
    document.getElementById('fPhotoPreview')?.addEventListener('error', function() { this.classList.add('d-none'); });
    try { updatePhotoPreview(); } catch(_) {}

    document.getElementById('saveForm').addEventListener('submit', async () => {
        const issues = validateThreeRequired();
        const m = document.getElementById('saveMsg');
        // ============= SCHEDULES VALIDATION GATE =============
        const schedResult = validateSchedulesRows();
        renderScheduleValidationMsg(schedResult);
        if (!schedResult.ok) {
            issues.push(...(schedResult.issues || []));
        }
        const schedules = schedResult.rows || [];
        // ======================================================
        if (issues.length) {
            m.innerHTML = `<span class="text-danger small fw-semibold">Cannot save yet: ${issues.map(renderIssue).map(s => '• ' + s).join('<br>')}</span>`;
            return;
        }
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_VAL); fd.append('action','save');
        const f = (id) => document.getElementById(id);
        writeOperationDaysHidden();
        const open24 = String(f('fOpen24')?.value ?? '0') === '1';
        let rawHours = f('fHours').value || '';
        if (open24 && !rawHours) rawHours = '24 hours (round the clock)';
        const daysArr = selectedOperationDayIndexes();
        const hoursToSave = operationHoursStringPrefixedWithDays(rawHours, daysArr);
        const fieldMap = {
            id: 'fId',
            barangay_id: 'fBarangay',
            spot_name: 'fName',
            address: 'fAddress',
            latitude: 'fLat',
            longitude: 'fLon',
            place_osm_id: 'fOsm',
            pickup_type: 'fType',
            operation_hours: null,
            notes_public: 'fNotes',
            reference_photo: 'fPhoto',
            status: 'fStatus',
        };
        Object.keys(fieldMap).forEach(k => {
            const htmlId = fieldMap[k];
            if (k === 'operation_hours') { fd.append(k, hoursToSave); return; }
            if (k === 'barangay_id') fd.append(k, document.querySelector('#saveForm #'+htmlId).value || '');
            else if (k === 'address') fd.append(k, document.querySelector('#saveForm #'+htmlId).value || '');
            else fd.append(k, htmlId && f(htmlId) ? f(htmlId).value : '');
        });
        // open_24_7 hidden value is "1"/"0"; only append 1 if truthy.
        const fOpenVal = String(f('fOpen24')?.value ?? '0') === '1';
        if (fOpenVal) fd.append('open_24_7', '1');
        ['accepts_bio','accepts_nonbio','accepts_recyclable','accepts_hazard','accepts_bulky'].forEach(k => { if (f(k)?.checked) fd.append(k, '1'); });
        fd.append('schedules_json', JSON.stringify(schedules));
        const r = await fetch(ENDPOINT, {method:'POST', body:fd}).then(x=>x.json());
        if (r.ok) {
            m.innerHTML = `<span class="text-success">Saved <i data-lucide="check" class="lucide-14 text-success"></i> — Drop-off point saved.</span>`;
            setTimeout(()=>location.reload(), 800);
        } else {
            m.innerHTML = `<span class="text-danger">${escapeHtml(r.error||'Save failed')}</span>`;
        }
    });

    function escapeHtml(s){return String(s??'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

    /* Issue messages are rendered into #saveMsg with innerHTML, so the default
       has to be escape -- validateSchedulesRows() interpolates row data into its
       messages. But two messages in validateThreeRequired() are author-controlled
       constants that legitimately carry markup (a <strong> in the hours hint, a
       Lucide icon). Marking those explicitly is what lets them render instead of
       showing their own tags as text, without opening the door for anything else.
       Anything NOT wrapped in htmlIssue() is escaped. */
    function htmlIssue(html){ return { __html: String(html ?? '') }; }
    function renderIssue(issue){
        return (issue && typeof issue === 'object' && issue.__html !== undefined)
            ? issue.__html
            : escapeHtml(issue);
    }

    /* Both arms used to call emptyForm() — an if/else with identical bodies.
       One call, and it seeds the form blank in either case. */
    emptyForm();

    if (DROP.length > 0 && typeof selectMapMarkerById === 'function') {
        // nothing: on legacy load, selection happens via table click handler below (preserves prior behavior)
    }

    // End-of-IIFE initial tab guard for step_create mode: ensure only the lowest unlocked step tab is visible.
    if (__workflowMode === 'step_create') showOnlyLowestUnlockedStep();

    // Double-check: render UI so all buttons/banners match init state.
    renderStepWorkflowUI();
    revalidateStepInfoLive();
    revalidateStepWasteLive();

    // End-of-IIFE re-expose: ensure debug helpers exist in window scope regardless
    // of earlier IIFE hoisting quirks (required by QA harness).
    window.adminMap = map;
    window.dragPin = dragPin;
    window.reverseGeocodeAndAutofill = reverseGeocodeAndAutofill;
    window.updatePinFromLatLng = updatePinFromLatLng;
    window.isInsideMarikina = isInsideMarikina;
    window.validateThreeRequired = validateThreeRequired;
    window.setSaveButtonEnabled = setSaveButtonEnabled;
    window.showGeofenceError = showGeofenceError;
    window.emptyForm = emptyForm;
    window.loadIntoForm = loadIntoForm;
    window.__baSetGeoSentinels = function (pinAttempted, geoFailed) {
        _lastPinAttempted = !!pinAttempted;
        _lastGeocodeFailed = !!geoFailed;
        if (_lastPinAttempted) markFormTouched();
        setSaveButtonEnabled();
    };
    window.isPristineForm = function () { return _isPristineForm; };
    var _ba = window.__ba || (window.__ba = {});
    function _baExport(name, value) { try { _ba[name] = value; } catch (e) { /* skip circular / throwing exports */ } }
    _baExport('reverseGeocodeAndAutofill', reverseGeocodeAndAutofill);
    _baExport('updatePinFromLatLng', updatePinFromLatLng);
    _baExport('isInsideMarikina', isInsideMarikina);
    _baExport('validateThreeRequired', validateThreeRequired);
    _baExport('setSaveButtonEnabled', setSaveButtonEnabled);
    _baExport('showGeofenceError', showGeofenceError);
    _baExport('emptyForm', emptyForm);
    _baExport('loadIntoForm', loadIntoForm);
    _baExport('isValidHoursFormat', isValidHoursFormat);
    _baExport('HOURS_FORMAT_HINT', HOURS_FORMAT_HINT);
    _baExport('parseDaysFromHoursText', parseDaysFromHoursText);
    _baExport('operationHoursStringPrefixedWithDays', operationHoursStringPrefixedWithDays);
    _baExport('applyHoursPreset', applyHoursPreset);
    _baExport('applyHoursPresetTemplate', applyHoursPresetTemplate);
    _baExport('detectHoursPresetKeyFromText', detectHoursPresetKeyFromText);
    _baExport('updatePresetHighlightAndSummary', updatePresetHighlightAndSummary);
    _baExport('applyHoursTextAutoPreset', applyHoursTextAutoPreset);
    _baExport('selectedOperationDayIndexes', selectedOperationDayIndexes);
    _baExport('writeOperationDaysHidden', writeOperationDaysHidden);
    _baExport('addSchedRow', addSchedRow);
    _baExport('validateSchedulesRows', validateSchedulesRows);
    _baExport('renderScheduleValidationMsg', renderScheduleValidationMsg);
    _baExport('formatDateForInput', formatDateForInput);
    _baExport('pad2', pad2);
    _baExport('renderCustomHours', renderCustomHours);
    _baExport('syncCustomHoursFromHidden', syncCustomHoursFromHidden);
    _baExport('getCustomRanges', function(){ return typeof __customRanges !== 'undefined' ? __customRanges.slice() : []; });
    _baExport('getCustomHistory', function(){ return typeof __customHistory !== 'undefined' ? __customHistory.slice() : []; });
})();
</script>

<?php require_once __DIR__ . '/../includes/partials/admin_shell_end.php'; ?>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>
