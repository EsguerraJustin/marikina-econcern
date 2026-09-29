<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$_ba_mysqli = db();
$_ba_user = current_user($_ba_mysqli);

$pageTitle = 'Waste Drop-off Map';
$activeNav = 'ba_dropoff_map';

$userBarangayId = null;
if (is_array($_ba_user) && isset($_ba_user['id'])) $userBarangayId = ba_user_barangay_id($_ba_mysqli, $_ba_user);

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$barangays = [];
try {
    $bres = $_ba_mysqli->query('SELECT id, name FROM barangays ORDER BY name');
    if ($bres instanceof mysqli_result) while ($row = $bres->fetch_assoc()) $barangays[] = $row;
} catch (Throwable $_) {}

$filters = ['status' => 'Published'];
$barangayRaw = $_GET['barangay_id'] ?? null;
$userTouchedBarangay = $barangayRaw !== null;
$selBarangay = $userTouchedBarangay ? (int)$barangayRaw : 0;
if ($selBarangay > 0) { $filters['barangay_id'] = $selBarangay; }
else if (!$userTouchedBarangay && $userBarangayId !== null) { $selBarangay = (int)$userBarangayId; $filters['barangay_id'] = $selBarangay; }
$wasteFilter = $_GET['waste'] ?? '';
$wasteFilterMap = ['bio'=>'accepts_bio','nonbio'=>'accepts_nonbio','recyclable'=>'accepts_recyclable','hazard'=>'accepts_hazard','bulky'=>'accepts_bulky'];
if (is_string($wasteFilter) && isset($wasteFilterMap[$wasteFilter])) {
    $filters[$wasteFilterMap[$wasteFilter]] = 1;
}
$openOnly24 = !empty($_GET['open24']) ? 1 : 0;
if ($openOnly24) { $filters['open_24_7'] = 1; }

$dropoffs = ba_list_dropoffs($_ba_mysqli, $filters, true);
$totalDropoffs = ba_list_dropoffs($_ba_mysqli, ['status' => 'Published'], false);

$acceptsMeta = ba_dropoff_waste_accepts_list();
$pickupTypes = ba_dropoff_pickup_types();
$dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

$wasteFilterOptions = [
    ''            => ['label' => 'All types', 'color' => '#6b7280'],
    'bio'         => $acceptsMeta['accepts_bio'] + ['color' => '#16a34a'],
    'nonbio'      => $acceptsMeta['accepts_nonbio'] + ['color' => '#ca8a04'],
    'recyclable'  => $acceptsMeta['accepts_recyclable'] + ['color' => '#0284c7'],
    'hazard'      => $acceptsMeta['accepts_hazard'] + ['color' => '#dc2626'],
    'bulky'       => $acceptsMeta['accepts_bulky'] + ['color' => '#475569'],
];

function pinColorByDominant(array $d): string {
    if (!empty($d['accepts_hazard'])) return '#dc2626';
    if (!empty($d['accepts_bulky']))  return '#475569';
    if (!empty($d['accepts_recyclable']) && empty($d['accepts_bio']) && empty($d['accepts_nonbio'])) return '#0284c7';
    if (!empty($d['accepts_bio']) && empty($d['accepts_nonbio'])) return '#16a34a';
    return '#2563eb';
}

$center = [14.6247, 121.0993];
if ($selBarangay > 0) {
    // Prefer to center on the first pin in user's barangay (if any)
    foreach ($dropoffs as $d) {
        if ((int)$d['barangay_id'] === $selBarangay) {
            $center = [$d['latitude'], $d['longitude']]; break;
        }
    }
}

$jsonOpts = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
$dropoffsJs = [];
foreach ($dropoffs as $d) {
    $badges = [];
    foreach ($acceptsMeta as $col => $meta) { if (!empty($d[$col])) $badges[] = ['label'=>$meta['label'],'cls'=>$meta['badge']]; }
    $schedRows = [];
    foreach (($d['schedules'] ?? []) as $row) {
        $ef = !empty($row['effective_from']) && is_string($row['effective_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['effective_from'])
            ? $row['effective_from'] : null;
        $et = !empty($row['effective_to']) && is_string($row['effective_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['effective_to'])
            ? $row['effective_to'] : null;
        // Render friendly human date range (no DST concern for Asia/Manila):
        $dateBadgeText = null;
        if ($ef !== null && $et !== null)       $dateBadgeText = 'Only active '.$ef.' to '.$et;
        elseif ($ef !== null && $et === null)   $dateBadgeText = 'Starts '.$ef.' (open-ended)';
        elseif ($ef === null && $et !== null)   $dateBadgeText = 'Until '.$et.' only';
        $schedRows[] = [
            'day'   => $dayNames[(int)($row['day_of_week'] ?? 0)],
            'waste' => $row['waste_type'],
            'start' => substr((string)($row['time_start'] ?? ''), 0, 5),
            'end'   => substr((string)($row['time_end']   ?? ''), 0, 5),
            'effective_from' => $ef,
            'effective_to'   => $et,
            'date_label'     => $dateBadgeText,
        ];
    }
    $nextOpen = null;
    $nextOpenText = '';
    try {
        $nw = ba_next_open_window($_ba_mysqli, (int)$d['id']);
        if (is_array($nw)) {
            $nd = (string)($nw['next_date'] ?? '');
            $nts = (string)($nw['time_start'] ?? '');
            $nte = (string)($nw['time_end'] ?? '');
            $nwt = (string)($nw['waste_type'] ?? '');
            $dayLabel = $nd !== '' ? date('D, M j', strtotime($nd)) : '';
            $timeRange = (substr($nts,0,5) ?: '??:??') . ' – ' . (substr($nte,0,5) ?: '??:??');
            $nextOpenText = trim($dayLabel . ' ' . $timeRange);
            if ($nwt !== '') $nextOpenText .= ' · accepts ' . $nwt;
            $nextOpen = ['label' => $nextOpenText, 'days_until' => (int)($nw['days_until'] ?? 0)];
        }
    } catch (Throwable $_) {}
    $calHref = '';
    $brgy = (int)$d['barangay_id'];
    if ($brgy > 0) {
        $calHref = app_url('/public/ba_schedule.php?view=calendar&barangay=' . $brgy . '&schedule_type=regular');
    }
    $dropoffsJs[] = [
        'id' => (int)$d['id'],
        'name' => $d['spot_name'],
        'address' => $d['address'],
        'barangay' => $d['barangay_name'] ?? '',
        'barangay_id' => (int)$d['barangay_id'],
        'lat' => (float)$d['latitude'],
        'lon' => (float)$d['longitude'],
        'type' => $d['pickup_type'],
        'typeLabel' => $pickupTypes[$d['pickup_type']] ?? $d['pickup_type'],
        'open_24_7' => !empty($d['open_24_7']),
        'hours' => (string)($d['operation_hours'] ?? ''),
        'notes' => (string)($d['notes_public'] ?? ''),
        'photo' => (string)($d['reference_photo'] ?? ''),
        'color' => pinColorByDominant($d),
        'badges' => $badges,
        'schedules' => $schedRows,
        'next_open' => $nextOpen,
        'calendar_href' => $calHref,
        'status' => $d['status'],
    ];
}

?>

<div class="mc-ba-schedule-page">

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<div class="mc-ba-sched-hero">
    <div class="mc-ba-sched-hero-inner">
        <div class="mc-ba-sched-hero-title-row">
            <div class="mc-ba-sched-hero-icon-wrap">
                <i data-lucide="map-pin" class="lucide lucide-24"></i>
            </div>
            <div class="mc-ba-sched-hero-title">
                <h1 class="mb-0">Waste Drop-off Points Map</h1>
                <p class="mb-0 text-muted" style="margin-top:2px;">Find the nearest official waste collection points in Marikina City. Tap a pin to see accepted waste types, hours, and instructions.</p>
            </div>
        </div>
        <div class="mc-ba-sched-hero-brand">
            <div class="mb-2 text-end">
                <button class="btn btn-outline-secondary btn-sm" id="btnMyLoc" type="button"><i data-lucide="map-pin" class="lucide lucide-14 me-1"></i>Use my location &amp; show nearest 3</button>
            </div>
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" width="36" height="36" loading="eager" decoding="async" class="mc-ba-sched-brand-img" style="margin-left:auto;">
        </div>
    </div>
</div>

<form method="GET" class="mc-neo-card mb-4">
    <div class="card-body" style="padding: var(--ba-space-4);">
        <div class="row mb-4 g-3 align-items-end">
            <div class="col-md-4">
                <label for="fBarangay" class="form-label">Barangay</label>
                <select id="fBarangay" class="form-select" name="barangay_id" style="height:48px;">
                    <option value="" <?= $userTouchedBarangay && $selBarangay === 0 ? 'selected' : '' ?>>All Marikina barangays</option>
                    <?php foreach ($barangays as $b): $sel = (int)$b['id'] === $selBarangay; ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="fWaste" class="form-label">Waste filter</label>
                <select id="fWaste" class="form-select" name="waste" style="height:48px;">
                    <?php foreach ($wasteFilterOptions as $k => $v): $sel = (string)$k === (string)$wasteFilter; ?>
                        <option value="<?= e($k) ?>" <?= $sel ? 'selected' : '' ?>><?= e($v['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">24/7 open locations</label>
                <div class="form-check" style="min-height:48px;display:flex;align-items:center;gap:10px;padding:0 12px;border:1px solid var(--ba-border);border-radius:8px;background:#fff;">
                    <input class="form-check-input" type="checkbox" name="open24" id="open24" value="1" <?= $openOnly24 ? 'checked' : '' ?> style="margin:0;">
                    <div>
                        <label class="form-check-label fw-semibold" for="open24" style="font-size:.85rem;">Show only drop-offs open 24/7</label>
                        <div class="small text-muted" style="font-size:.75rem;">Bins / curbs with no schedule window (always accessible)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill" style="height:48px;">
                    <i data-lucide="filter" class="lucide lucide-16 me-1"></i><span>Filter map</span>
                </button>
                <a class="btn btn-outline-secondary flex-fill" href="<?= e(app_url('/public/ba_dropoff_map.php')) ?>" style="height:48px;">
                    <i data-lucide="x-circle" class="lucide lucide-16 me-1"></i><span>Reset</span>
                </a>
            </div>
        </div>
        <div class="mc-ba-sched-info mt-4">
            <i data-lucide="info" class="lucide lucide-16" style="color:var(--ba-text-muted);"></i>
            <span>Showing <strong><?= count($dropoffs) ?></strong> of <strong><?= count($totalDropoffs) ?></strong> drop-off point<?= count($totalDropoffs) === 1 ? '' : 's' ?><?php if ($selBarangay > 0 || $wasteFilter !== '' || $openOnly24) : ?> <span class="text-muted">(filtered via dropdowns)</span><?php endif; ?></span>
        </div>
    </div>
</form>

<div class="row g-3">
    <div class="col-lg-8 order-lg-8 order-2">
        <div class="mc-neo-card">
            <div class="card-body p-0">
                <div id="residentMap" style="height:72vh;border-radius:0.375rem"></div>
            </div>
            <div class="card-footer bg-white small">
                <span class="me-3"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#16a34a;margin-right:6px"></span> Biodegradable</span>
                <span class="me-3"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#2563eb;margin-right:6px"></span> Mixed</span>
                <span class="me-3"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#0284c7;margin-right:6px"></span> Recyclable</span>
                <span class="me-3"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc2626;margin-right:6px"></span> Hazardous</span>
                <span class="me-3"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#475569;margin-right:6px"></span> Bulky</span>
                <span class="float-end text-muted">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a></span>
            </div>
        </div>
    </div>
    <div class="col-lg-4 order-lg-2 order-1" id="sideCol">
        <div class="mc-neo-card mb-3" id="nearestCard" style="display:none">
            <div class="card-header bg-white small fw-semibold"><i data-lucide="compass" class="lucide-14"></i> Nearest to you</div>
            <div class="card-body p-2" id="nearestBody"></div>
        </div>
        <div class="mc-list-card">
            <div class="mc-list-card-head">
                <h3><i data-lucide="map-pin" class="lucide"></i> List of drop-offs</h3>
                <div class="mc-list-card-head-end">
                    <span class="mc-list-card-count"><?= count($dropoffs) ?></span>
                </div>
            </div>
            <div class="mc-ba-schedule-table-wrap mc-neo-card">
            <div class="mc-ba-schedule-table mc-stacked-card">
                <div class="mc-ba-sched-row mc-dropoff-row mc-ba-sched-head">
                    <div class="mc-ba-sched-cell mc-dropoff-col-spot"><div class="mc-th-wrap"><i data-lucide="map-pin" class="lucide mc-th-icon"></i>Spot / Address</div></div>
                    <div class="mc-ba-sched-cell mc-dropoff-col-hours"><div class="mc-th-wrap"><i data-lucide="clock" class="lucide mc-th-icon"></i>Hours</div></div>
                </div>
                <div class="mc-ba-schedule-tbody mc-dropoff-scroll">
                <?php if (count($dropoffs) === 0): ?>
                    <div class="mc-ba-sched-row mc-dropoff-row">
                        <div class="mc-ba-sched-empty"><i data-lucide="search-x" class="lucide mb-2" style="width:28px;height:28px;color:#A0AEC0;"></i><br>No drop-offs match your filter.<br><span class="small text-muted">Try adjusting the dropdowns above.</span></div>
                    </div>
                <?php else: ?>
                    <?php foreach ($dropoffs as $d): ?>
                        <div class="mc-ba-sched-row mc-dropoff-row" role="button" tabindex="0" data-id="<?= (int)$d['id'] ?>" style="cursor:pointer;">
                            <div class="mc-ba-sched-cell mc-dropoff-col-spot" data-label="Spot / Address">
                                <div class="mc-cell-wrap">
                                    <i data-lucide="map-pin" class="lucide mc-cell-icon"></i>
                                    <div class="mc-cell-content">
                                        <strong class="mc-dropoff-spot-name"><?= e($d['spot_name']) ?></strong>
                                        <div class="mc-ba-sched-secondary"><?= e($d['barangay_name'] ?? '') ?></div>
                                        <div class="mc-dropoff-addr"><?= e($d['address']) ?></div>
                                        <div class="mc-dropoff-accept">
                                        <?php
                                        $dropPill = ['accepts_bio' => 'mc-drop-bio', 'accepts_nonbio' => 'mc-drop-nonbio', 'accepts_recyclable' => 'mc-drop-rec', 'accepts_hazard' => 'mc-drop-haz', 'accepts_bulky' => 'mc-drop-bulky'];
                                        foreach ($acceptsMeta as $col => $meta): ?>
                                            <?php if (!empty($d[$col])): ?><span class="badge mc-drop-pill <?= $dropPill[$col] ?? '' ?>"><?= e($meta['label']) ?></span><?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php /* The 24/7 badge used to be printed HERE as well as in the
                                                 Hours cell below, so every 24/7 drop-off said "24/7"
                                                 twice. This row is the waste-acceptance row, and
                                                 open_24_7 is not an acceptance flag — the Hours cell
                                                 is its labelled home. */ ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mc-ba-sched-cell mc-dropoff-col-hours" data-label="Hours">
                                <div class="mc-cell-wrap">
                                    <i data-lucide="clock" class="lucide mc-cell-icon"></i>
                                    <div class="mc-cell-content">
                                        <div class="mc-dropoff-hours"><?= !empty($d['open_24_7']) ? '24/7' : (e($d['operation_hours'] ?: ($d['schedules'] ? (count($d['schedules']) . ' block(s)') : '—'))) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.leaflet-popup-content-wrapper { border-radius: 0.75rem; max-width: calc(100vw - 24px); }
/* Leaflet ships .leaflet-container{overflow:hidden}, so a popup taller than the
   72vh map is clipped with no way to reach what is below the fold. bindPopup()
   was only ever given a maxWidth, so Leaflet had no maxHeight to measure
   against and never applied its own scrolled class. popupOptions() now derives
   one from map.getSize(), and this rule makes the content box the scroll region
   so it works regardless of which class Leaflet toggles. */
.leaflet-popup-content {
  margin: 12px 14px;
  min-width: 240px;
  max-width: 320px;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
}
.badge-pin { display:inline-block; padding:1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; color: #fff; margin-right: 4px; margin-bottom: 2px; }

/* Hours of operation. Was a 3-column <table> with 105px + 115px fixed widths
   inside a ~296px content box, which is what wrapped "06:00 - 08:00" onto two
   lines. Flex instead: the time range can no longer wrap, and the waste badge
   is the only thing allowed to shrink or break. */
.mc-drop-pop-hrs { margin-top: 8px; font-size: .8rem; }
.mc-drop-pop-hr {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 6px 0; border-top: 1px solid #EDF1F6;
}
.mc-drop-pop-hr-day { flex: 0 1 auto; min-width: 62px; font-weight: 600; }
.mc-drop-pop-hr-note {
  font-weight: 400; font-style: italic; font-size: .68rem;
  color: var(--ba-text-muted); margin-top: 2px; overflow-wrap: break-word;
}
.mc-drop-pop-hr-waste { flex: 1 1 auto; min-width: 0; }
.mc-drop-pop-hr-waste .badge { white-space: normal; text-align: left; }
.mc-drop-pop-hr-time {
  flex: 0 0 auto; margin-left: auto; white-space: nowrap;
  font-variant-numeric: tabular-nums; color: var(--ba-text-muted);
}
</style>

<script>
(function(){
    var __lucidePollTries = 0;
    function renderLucideSafe(){
        // Priority 1: use foot.php renderAll (exposed as __renderLucide) since it includes same attrs + error swallows
        if (typeof window.__renderLucide === 'function') {
            try { window.__renderLucide(); } catch(_) {}
            return true;
        }
        // Fallback if user loaded lucide already
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            try {
                window.lucide.createIcons({
                    attrs: { width: 16, height: 16, 'stroke-width': 2, 'fill': 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' },
                    nameAttr: 'data-lucide'
                });
            } catch(_) {}
            return true;
        }
        return false;
    }
    // Poll every 100ms until __renderLucide exists (appears after foot.php loads + runs)
    function pollLucide() {
        if (renderLucideSafe()) return;
        if (__lucidePollTries++ < 30) setTimeout(pollLucide, 100);
    }
    pollLucide();
    // Safety: render after load + pageshow (100ms delay ensure all scripts done)
    window.addEventListener('load', function(){ setTimeout(renderLucideSafe, 100); }, { once: true });
    window.addEventListener('pageshow', function(){ setTimeout(renderLucideSafe, 150); });

    const DROPS = <?= json_encode($dropoffsJs, $jsonOpts) ?>;
    const CENTER = <?= json_encode($center) ?>;
    const BOUNDS = [[14.570,121.045],[14.700,121.145]];
    const map = L.map('residentMap', { center: CENTER, zoom: 14, maxBounds: BOUNDS, maxBoundsViscosity: 1.0 });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const markers = {};
    let userCircle = null;

    /* Bounds have to come from the live map size, not fixed constants: the
       container is 72vh on every breakpoint, and a pin on a 6-window drop-off
       produces far more content than a 2-window one. */
    function popupOptions() {
        const size = map.getSize();
        return {
            minWidth: 240,
            maxWidth: Math.min(360, Math.max(240, size.x - 32)),
            maxHeight: Math.max(220, size.y - 96),
            autoPan: true,
            autoPanPaddingTopLeft: [16, 16],
            autoPanPaddingBottomRight: [16, 16],
            closeButton: true
        };
    }

    function bindDropPopup(marker, drop) {
        marker.bindPopup(buildPopup(drop), popupOptions());
        // Re-derive on every open: the map can be resized, rotated or the
        // viewport changed since the marker was bound.
        marker.on('popupopen', function () {
            const popup = marker.getPopup();
            const next = popupOptions();
            popup.options.maxWidth = next.maxWidth;
            popup.options.maxHeight = next.maxHeight;
            popup.update();
            setTimeout(renderLucideSafe, 0);
        });
    }

    DROPS.forEach(d => {
        const m = L.circleMarker([d.lat, d.lon], {radius: 8, weight: 2, color: '#ffffff', fillColor: d.color, fillOpacity: 0.92}).addTo(map);
        bindDropPopup(m, d);
        m.on('click', () => scrollRow(d.id));
        markers[d.id] = m;
    });

    if (DROPS.length > 0) {
        const fg = L.featureGroup(Object.values(markers));
        try { map.fitBounds(fg.getBounds().pad(0.1), {maxZoom: 15}); } catch (_) {}
    }

    function buildPopup(d) {
        const badges = d.badges.map(b => '<span class="badge-pin badge ' + b.cls + '">' + b.label + '</span>').join('');
        const open24 = d.open_24_7 ? '<span class="badge bg-success me-1 mb-1">24/7 open</span> ' : '';
        let nextOpenLine = '';
        if (!d.open_24_7 && d.next_open && d.next_open.label) {
            const du = Number(d.next_open.days_until) || 0;
            const whenText = du === 0 ? 'Today' : (du === 1 ? 'Tomorrow' : ('In ' + du + ' days'));
            nextOpenLine = '<div class="small mt-2 bg-primary bg-opacity-10 text-primary rounded p-2 fw-semibold" title="Next open window per unified schedule engine"><i data-lucide="clock" class="lucide-14"></i> Next open: ' + escapeHtml(d.next_open.label) + ' <span class="text-muted fw-normal small">(' + escapeHtml(whenText) + ')</span></div>';
        }
        let sched = '';
        if (d.schedules && d.schedules.length) {
            let rows = '';
            d.schedules.forEach(s => {
                const note = s.date_label
                    ? '<div class="mc-drop-pop-hr-note"><i data-lucide="calendar-days" class="lucide-14"></i> ' + escapeHtml(s.date_label) + '</div>'
                    : '';
                rows += '<div class="mc-drop-pop-hr">'
                      + '<div class="mc-drop-pop-hr-day">' + escapeHtml(s.day) + note + '</div>'
                      + '<div class="mc-drop-pop-hr-waste"><span class="badge bg-light text-dark">' + escapeHtml(s.waste) + '</span></div>'
                      + '<div class="mc-drop-pop-hr-time">' + escapeHtml(s.start) + ' &ndash; ' + escapeHtml(s.end) + '</div>'
                      + '</div>';
            });
            sched = '<div class="mc-drop-pop-hrs"><div class="fw-semibold mb-1">Hours of operation</div>' + rows + '</div>';
        } else if (d.hours) {
            sched = '<div class="small mt-2"><div class="fw-semibold">Hours</div>' + escapeHtml(d.hours) + '</div>';
        }
        const photo = d.photo ? '<div class="mt-2"><img src="' + escapeAttr(d.photo) + '" style="width:100%;max-height:140px;object-fit:cover;border-radius:0.5rem;border:1px solid #eee" alt="Reference" loading="lazy" onerror="this.closest(\'div\').style.display=\'none\'"></div>' : '';
        const notes = d.notes ? '<div class="small mt-2 bg-light rounded p-2"><div class="fw-semibold">Reminder</div>' + escapeHtml(d.notes) + '</div>' : '';
        const calLink = d.calendar_href ? '<div class="small mt-2 pt-2 border-top"><a class="text-decoration-none" target="_blank" rel="noopener" href="' + escapeAttr(d.calendar_href) + '"><i data-lucide="calendar-days" class="lucide-14"></i> Show linked collection windows in calendar →</a></div>' : '';
        const sub = '<div class="small text-muted mb-2">' + escapeHtml(d.barangay || '') + (d.typeLabel ? ' · ' + escapeHtml(d.typeLabel) : '') + '</div>';
        return '<div><div class="fw-bold mb-1">' + escapeHtml(d.name) + '</div>' + sub + (badges ? '<div class="mb-1">' + badges + '</div>' : '') + open24 + nextOpenLine + sched + photo + notes + calLink + '</div>';
    }

    function scrollRow(id) {
        const tr = document.querySelector('#sideCol .mc-dropoff-row[data-id="' + id + '"]');
        if (tr) { tr.scrollIntoView({block:'center', behavior:'smooth'}); tr.style.background = '#fff7cd'; setTimeout(function(){ tr.style.background = ''; }, 1800); }
    }

    document.querySelectorAll('#sideCol .mc-dropoff-row[data-id]').forEach(tr => {
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', function() {
            const id = parseInt(tr.dataset.id, 10);
            const m = markers[id]; if (!m) return;
            map.setView(m.getLatLng(), 17, {animate: true});
            setTimeout(function(){ m.openPopup(); }, 250);
        });
    });

    document.getElementById('btnMyLoc').addEventListener('click', function() {
        if (!('geolocation' in navigator)) { alert('Your browser does not support location services.'); return; }
        document.getElementById('btnMyLoc').disabled = true;
        navigator.geolocation.getCurrentPosition(function(pos) {
            document.getElementById('btnMyLoc').disabled = false;
            const lat = pos.coords.latitude;
            const lon = pos.coords.longitude;
            if (userCircle) map.removeLayer(userCircle);
            userCircle = L.layerGroup().addTo(map);
            const me = L.circleMarker([lat, lon], {radius: 10, color: '#111827', fillColor: '#6366f1', fillOpacity: 0.95, weight: 2}).addTo(userCircle);
            me.bindTooltip('You are here', {permanent:false, direction:'top'});
            const acc = L.circle([lat, lon], {radius: Math.min(250, pos.coords.accuracy || 50), color: '#6366f1', fillColor: '#6366f1', fillOpacity: 0.08, weight: 1}).addTo(userCircle);
            const R = 6371008.8;
            const enriched = DROPS.map(function(d) {
                const dLat = (d.lat - lat) * Math.PI / 180;
                const dLon = (d.lon - lon) * Math.PI / 180;
                const a = Math.sin(dLat/2)**2 + Math.cos(lat*Math.PI/180)*Math.cos(d.lat*Math.PI/180)*Math.sin(dLon/2)**2;
                const c = 2*Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                return {d: d, meters: Math.round(R*c)};
            }).sort(function(a,b){ return a.meters - b.meters; }).slice(0,3);
            const card = document.getElementById('nearestCard');
            card.style.display = '';
            const body = document.getElementById('nearestBody');
            let html = '';
            enriched.forEach(function(pair, idx) {
                const d = pair.d; const m = pair.meters;
                html += '<div class="d-flex align-items-start p-2 border rounded mb-1 ' + (idx===0?'bg-success bg-opacity-10':'') + '">'
                     + '<div class="fw-bold text-secondary me-2">' + (idx+1) + '.</div>'
                     + '<div class="flex-grow-1">'
                     + '<div class="fw-semibold">' + escapeHtml(d.name) + '</div>'
                     + '<div class="small text-muted">' + escapeHtml(d.barangay) + ' · ' + (m < 1000 ? (m + ' m away') : ((m/1000).toFixed(2) + ' km away')) + '</div>'
                     + '<button type="button" class="btn btn-sm btn-outline-primary mt-1" data-jump="' + d.id + '">Show on map →</button>'
                     + '</div></div>';
            });
            body.innerHTML = html;
            setTimeout(renderLucideSafe, 20);
            body.querySelectorAll('[data-jump]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    const id = parseInt(btn.dataset.jump,10);
                    const mm = markers[id]; if (!mm) return;
                    map.setView(mm.getLatLng(), 17, {animate:true});
                    setTimeout(function(){ mm.openPopup(); }, 250);
                });
            });
            map.setView([lat, lon], 15, {animate:true});
        }, function(err) {
            document.getElementById('btnMyLoc').disabled = false;
            alert('Could not get your location: ' + err.message);
        }, {enableHighAccuracy: true, timeout: 10000, maximumAge: 60000});
    });

    function escapeHtml(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
    function escapeAttr(s){ return escapeHtml(s).replace(/`/g,'&#96;'); }
    // Focus from schedule page jump: ?focus=dropoff&id=42&lat=14.6&lng=121.1&zoom=16
    (function applyFocusJump(){
        try {
            const sp = new URLSearchParams(window.location.search);
            if ((sp.get('focus') || '') !== 'dropoff') return;
            const lat = parseFloat(sp.get('lat') || 'NaN');
            const lng = parseFloat(sp.get('lng') || 'NaN');
            const zoom = parseInt(sp.get('zoom') || '16', 10) || 16;
            const id = parseInt(sp.get('id') || '0', 10) || 0;
            if (isFinite(lat) && isFinite(lng)) {
                map.setView([lat, lng], Math.max(Math.min(zoom, 19), 10), {animate: true});
                if (id > 0 && markers[id]) {
                    setTimeout(function(){ try { markers[id].openPopup(); } catch(_) {} }, 450);
                }
            }
        } catch (_) {}
    })();

    // Debug exports (QA harness only, no state mutation risk):
    window.__resident = { buildPopup, escapeHtml, escapeAttr, pollLucide, renderLucideSafe, popupOptions, addDatedMarker: (d) => {
        const m = L.circleMarker([d.lat, d.lon], {radius: 8, weight:2, color:'#fff', fillColor: d.color||'#2563eb', fillOpacity: 0.9}).addTo(map);
        bindDropPopup(m, d);
        markers[d.id] = m;
        return m;
    }, map, markers };
})();
</script>

</div>

<?php
require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';

