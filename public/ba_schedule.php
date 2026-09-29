<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$_ba_mysqli = db();
$_ba_user = current_user($_ba_mysqli);
if (!$_ba_user || ba_user_barangay_id($_ba_mysqli, $_ba_user) === null) {
    $_SESSION['flash_error'] = 'Set your barangay first in Profile to view your personalized collection schedule.';
    $_back = $_SERVER['REQUEST_URI'] ?? '';
    redirect(app_url('/public/profile.php') . ($_back !== '' ? '?redirect_back=' . urlencode($_back) : ''));
}

$pageTitle = 'Collection Schedule';
$activeNav = 'ba_schedule';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$barangays = ba_list_barangays($mysqli);
$currentBarangayId = ba_user_barangay_id($mysqli, $user);
$filterBarangay = isset($_GET['barangay']) ? (int) $_GET['barangay'] : ($currentBarangayId > 0 ? $currentBarangayId : 0);
$filterWaste = isset($_GET['waste_type']) ? (string) $_GET['waste_type'] : '';
$filterType = isset($_GET['schedule_type']) ? (string) $_GET['schedule_type'] : '';
$filterView = isset($_GET['view']) && in_array($_GET['view'], ['list','calendar'], true) ? (string) $_GET['view'] : 'list';
$filterQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$_ba_autoBypass = false;
if ($filterQ !== '') {
    if (preg_match('/#?\d{2,}/', $filterQ) || strlen($filterQ) >= 3) {
        $_ba_autoBypass = true;
        if (!isset($_GET['barangay'])) $filterBarangay = 0;
        if (!isset($_GET['waste_type'])) $filterWaste = '';
        if (!isset($_GET['schedule_type'])) $filterType = '';
    }
}

$schedules = ba_list_schedules($mysqli, $filterBarangay > 0 ? $filterBarangay : null);
$schedules = array_values(array_filter($schedules, static function ($s) use ($filterWaste, $filterType) {
    if ($filterWaste !== '' && ($s['waste_type'] ?? '') !== $filterWaste) return false;
    if ($filterType !== '') {
        $st = (string) ($s['schedule_type'] ?? '');
        if ($filterType === 'regular') {
            if ($st !== 'regular' && $st !== 'recurring') return false;
        } elseif ($st !== $filterType) return false;
    }
    return true;
}));

$_pubMaxId = 0;
foreach ($schedules as $_s) { if (!empty($_s['id']) && (int) $_s['id'] > $_pubMaxId) { $_pubMaxId = (int) $_s['id']; } }
$_pubRefPad = max(2, strlen((string) $_pubMaxId));
$_pubTotal = count($schedules);
$_baBypassFlag = $_ba_autoBypass ? '1' : '0';
$_baFilterQJs = json_encode($filterQ, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function ba_render_sched_row(array $s, string $view, int $refPad): string
{
    $sid = (int) ($s['id'] ?? 0);
    $refStr = str_pad((string) $sid, $refPad, '0', STR_PAD_LEFT);
    $wt = (string) $s['waste_type'];
    $wtBadge = ba_waste_badge($wt);
    $st = (string) ($s['schedule_type'] ?? 'regular');
    $stClass = $st === 'exception' ? 'bg-danger' : ($st === 'one_time' ? 'bg-warning text-dark' : 'bg-light text-dark');
    $when = '';
    if ($st === 'one_time' || $st === 'exception') {
        $when = !empty($s['collection_date']) ? date('M j, Y (l)', strtotime((string) $s['collection_date'])) : '—';
    } else {
        $dow = (int) ($s['day_of_week'] ?? 0);
        $when = ba_day_name($dow);
    }
    $time = ba_format_time($s['time_start'] ?? null) . ' – ' . ba_format_time($s['time_end'] ?? null);
    $barangay = e((string) ($s['barangay_name'] ?? ''));
    $notes = !empty($s['notes']) ? '<div class="small mc-ba-sched-secondary mt-1">' . e((string) $s['notes']) . '</div>' : '';
    $linkedBadge = '';
    $mapLink = '';
    $linkedType = (string) ($s['linked_type'] ?? 'none');
    $linkedModal = '';
    $mapHrefRaw = '';
    if ($linkedType === 'dropoff') {
        $dName = trim((string) ($s['__dropoff_name'] ?? ''));
        $linkedBadge = ' <span class="badge bg-info text-dark ms-1" title="' . e('Linked to drop-off: ' . ($dName !== '' ? $dName : '#' . (int) ($s['__dropoff_id'] ?? 0))) . '"><i data-lucide="tag" class="lucide-14"></i> Drop-off</span>';
        $dId = (int) ($s['__dropoff_id'] ?? 0);
        $dLat = (float) ($s['__dropoff_lat'] ?? 0);
        $dLng = (float) ($s['__dropoff_lng'] ?? 0);
        $linkedModal = '<span class="badge bg-info text-dark me-1"><i data-lucide="tag" class="lucide-14"></i> Drop-off' . ($dName !== '' ? ' · ' . e($dName) : '') . '</span>';
        if ($dId > 0 && $dLat !== 0.0 && $dLng !== 0.0) {
            $mapHref = e(app_url('/public/ba_dropoff_map.php?focus=dropoff&id=' . $dId . '&lat=' . $dLat . '&lng=' . $dLng . '&zoom=16'));
            $mapLink = '<div class="small mt-1"><a class="text-decoration-none" target="_blank" rel="noopener" href="' . $mapHref . '">Open dropoff on map →</a></div>';
            $mapHrefRaw = app_url('/public/ba_dropoff_map.php?focus=dropoff&id=' . $dId . '&lat=' . $dLat . '&lng=' . $dLng . '&zoom=16');
        }
    }
    /* Detail payload for the row-pick modal. Same shape as the calendar items
       so the shared scDayDetailModal + buildDetailCard path renders it. */
    $wtm = ba_waste_meta($wt);
    $stLabel = e(ucfirst(str_replace('_', ' ', $st)));
    $notesModal = !empty($s['notes']) ? '<div class="mt-2 text-muted small"><i data-lucide="info" class="lucide-14"></i> ' . e((string) $s['notes']) . '</div>' : '';
    $detailJson = json_encode([
        'sid' => $sid, 'ref' => $refStr,
        'title' => (string) $s['title'],
        'wt' => $wt, 'wt_short' => $wtm['short'], 'wt_label' => $wtm['label'], 'wt_badge' => $wtm['badge'], 'wt_icon' => $wtm['icon'],
        'st' => $st, 'st_label' => $stLabel, 'st_class' => $stClass,
        'when' => $when, 'time' => $time, 'brgy' => $barangay,
        'linked_html' => $linkedModal, 'map_href' => $mapHrefRaw, 'notes_html' => $notesModal,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $detailAttr = htmlspecialchars($detailJson !== false ? $detailJson : '{}', ENT_QUOTES, 'UTF-8');
    return '<div class="mc-ba-sched-row" role="button" tabindex="0" aria-label="View schedule details: ' . e((string) $s['title']) . ' #' . e($refStr) . '" data-id="' . $sid . '" data-title="' . e((string) $s['title']) . '" data-ref_padded="' . e($refStr) . '" data-detail="' . $detailAttr . '">
        <div class="mc-ba-sched-cell mc-ba-sched-col-ref" data-label="Ref #"><button type="button" class="btn btn-sm border-0 lh-sm user-select-all ba-pub-ref-btn" style="font-family:JetBrains Mono,Fira Code,Consolas,monospace;font-size:13px;letter-spacing:0.3px;padding:2px 8px;cursor:pointer;" title="Search Ref #' . $refStr . '">#' . $refStr . '</button></div>
        <div class="mc-ba-sched-cell mc-ba-sched-col-sched" data-label="Schedule"><div class="mc-cell-wrap"><i data-lucide="calendar-days" class="lucide mc-cell-icon"></i><div class="mc-cell-content"><strong>' . e((string) $s['title']) . '</strong>' . $linkedBadge . $notes . $mapLink . '</div></div></div>
        <div class="mc-ba-sched-cell mc-ba-sched-col-waste" data-label="Waste Type">' . $wtBadge . '</div>
        <div class="mc-ba-sched-cell mc-ba-sched-col-type" data-label="Type"><span class="badge ' . $stClass . ' border"><i data-lucide="tag" class="lucide" style="width:12px;height:12px;flex-shrink:0;"></i> ' . e(ucfirst(str_replace('_', ' ', $st))) . '</span></div>
        <div class="mc-ba-sched-cell mc-ba-sched-col-when" data-label="When"><span style="display:inline-flex;align-items:center;gap:6px;color:#2B3441;font-size:.9rem;line-height:1.45;margin-top:2px;"><i data-lucide="calendar" class="lucide" style="width:14px;height:14px;color:#4A5568;flex-shrink:0;"></i>' . e($when) . '</span></div>
        <div class="mc-ba-sched-cell mc-ba-sched-col-time" data-label="Time"><span style="display:inline-flex;align-items:center;gap:6px;color:#2B3441;font-size:.9rem;line-height:1.45;margin-top:2px;"><i data-lucide="clock" class="lucide" style="width:14px;height:14px;color:#4A5568;flex-shrink:0;"></i>' . e($time) . '</span></div>
        <div class="mc-ba-sched-cell mc-ba-sched-col-brgy" data-label="Barangay">' . ($view === 'list' ? '<span style="display:inline-flex;align-items:center;gap:6px;color:#2B3441;font-size:.9rem;line-height:1.45;margin-top:2px;"><i data-lucide="map-pin" class="lucide" style="width:14px;height:14px;color:#4A5568;flex-shrink:0;"></i>' . $barangay . '</span>' : '') . '</div>
    </div>';
}
?>

<div class="mc-ba-schedule-page">
<!-- ================= PAGE HERO CARD (Minimalism: 1 CTA + Brand identity — matches My Concern header reference) ================= -->
<div class="mc-ba-sched-hero">
    <div class="mc-ba-sched-hero-inner">
        <div class="mc-ba-sched-hero-title-row">
            <div class="mc-ba-sched-hero-icon-wrap">
                <i data-lucide="calendar-days" class="lucide lucide-24"></i>
            </div>
            <div class="mc-ba-sched-hero-title">
                <h1 class="mb-0">Collection Schedule</h1>
                <p class="mb-0 text-muted" style="margin-top:2px;">View and search collection schedules by barangay, waste type, and schedule type</p>
            </div>
        </div>
        <div class="mc-ba-sched-hero-brand">
            <div class="mb-2 text-end">
                <div class="btn-group btn-group-sm" role="group" aria-label="View">
                    <a href="<?= e(app_url('/public/ba_schedule.php?view=list')) ?><?= $filterBarangay ? '&barangay=' . $filterBarangay : '' ?><?= $filterWaste ? '&waste_type=' . urlencode($filterWaste) : '' ?><?= $filterType ? '&schedule_type=' . urlencode($filterType) : '' ?>" class="btn btn-outline-secondary<?= $filterView === 'list' ? ' active' : '' ?>">List</a>
                    <a href="<?= e(app_url('/public/ba_schedule.php?view=calendar')) ?><?= $filterBarangay ? '&barangay=' . $filterBarangay : '' ?><?= $filterWaste ? '&waste_type=' . urlencode($filterWaste) : '' ?><?= $filterType ? '&schedule_type=' . urlencode($filterType) : '' ?>" class="btn btn-outline-secondary<?= $filterView === 'calendar' ? ' active' : '' ?>">Calendar</a>
                </div>
            </div>
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" width="36" height="36" loading="eager" decoding="async" class="mc-ba-sched-brand-img" style="margin-left:auto;">
        </div>
    </div>
</div>

<!-- ================= UNIFIED FILTER + SEARCH CARD (Single card = matches My Concern unified filter reference) ================= -->
<form method="GET" class="mc-neo-card mb-4">
    <div class="card-body" style="padding: var(--ba-space-4);">
        <!-- Row 1: Select filters (Barangay / Waste Type / Schedule Type) + Submit -->
        <div class="row mb-4 g-3 align-items-end">
            <div class="col-md-3">
                <label for="fBarangay" class="form-label">Barangay</label>
                <select id="fBarangay" name="barangay" class="form-select">
                    <option value="">All barangays</option>
                    <?php foreach ($barangays as $b) : ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $filterBarangay === (int) $b['id'] ? 'selected' : '' ?>><?= e((string) $b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="fWaste" class="form-label">Waste Type</label>
                <select id="fWaste" name="waste_type" class="form-select">
                    <option value="">All types</option>
                    <?php foreach (['Biodegradable','Non-Biodegradable','Recyclable','Special','Mixed','Hazardous'] as $w) : ?>
                        <option value="<?= $w ?>" <?= $filterWaste === $w ? 'selected' : '' ?>><?= $w ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="fType" class="form-label">Schedule Type</label>
                <select id="fType" name="schedule_type" class="form-select">
                    <option value="">All types</option>
                    <option value="regular" <?= $filterType === 'regular' ? 'selected' : '' ?>>Weekly / Recurring</option>
                    <option value="one_time" <?= $filterType === 'one_time' ? 'selected' : '' ?>>One-time</option>
                    <option value="exception" <?= $filterType === 'exception' ? 'selected' : '' ?>>Exception / holiday override</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i data-lucide="filter" class="lucide lucide-16"></i><span>Filter</span>
                </button>
                <?php if ($filterBarangay . $filterWaste . $filterType !== '' && ($filterBarangay !== (int)($currentBarangayId > 0 ? $currentBarangayId : 0) || $filterWaste !== '' || $filterType !== '')) : ?>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/ba_schedule.php?view=' . urlencode($filterView))) ?>">
                        <i data-lucide="x-circle" class="lucide lucide-16"></i><span>Clear</span>
                    </a>
                <?php endif; ?>
                <input type="hidden" name="view" value="<?= e($filterView) ?>">
            </div>
        </div>

        <!-- Row 2: Search + Refresh (matches My Concern search + refresh layout) -->
        <div class="row g-3">
            <div class="col-md-8">
                <div class="mc-ba-sched-search-wrap">
                    <i data-lucide="search" class="lucide lucide-20 mc-ba-sched-search-icon"></i>
                    <input id="pubSchedSearch" type="search" class="form-control" placeholder="Ref # (e.g. 23 or #023) or schedule title keywords… (press Esc to clear)" autocomplete="off" spellcheck="false" value="<?= e($filterQ) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <button type="button" class="btn btn-outline-primary w-100" id="schedRefreshBtn">
                    <i data-lucide="refresh-cw" class="lucide lucide-16"></i>
                    <span>Refresh</span>
                </button>
            </div>
        </div>

        <!-- Row 3: Info line + results count (matches My Concern ⓘ info reference) -->
        <div class="mc-ba-sched-info mt-4" id="pubSchedSearchInfo">
            <i data-lucide="info" class="lucide lucide-16" style="color:var(--ba-text-muted);"></i>
            <span id="pubSchedShowingWrap">Showing <strong id="pubSchedShowingCount"><?= $_pubTotal ?></strong> of <strong><?= $_pubTotal ?></strong> result<?= $_pubTotal !== 1 ? 's' : '' ?><?php if ($filterBarangay || $filterWaste || $filterType) : ?> <span class="text-muted">(filtered via dropdowns)</span><?php endif; ?><?php if ($_ba_autoBypass) : ?> <span class="text-info ms-1"><i data-lucide="unlock" class="lucide-14"></i> Default filters bypassed — Ref/title search across all Published schedules</span><?php endif; ?><span id="pubSchedInfoMsg"></span></span>
        </div>
    </div>
</form>

<?php if ($filterView === 'calendar') :
    $today = date('Y-m-d');

    /* Selected month. Was hardcoded to date('Y-m-01'), so the calendar could
       only ever display the current month — there was no prev/next control and
       no $_GET['month'] anywhere on the page. Now navigable.

       The year used to come from date('Y') and the month from date('m'), so
       the mktime() call below could get away with a hardcoded year. It cannot
       any more: navigating to December of a previous year silently produced
       December of THIS year, and those wrong dates feed the day-detail modal.
       Every value below now derives from the single validated $calMonthTs. */
    $calMonthTs = strtotime('first day of this month');
    if (isset($_GET['month']) && is_string($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) === 1) {
        $requested = strtotime($_GET['month'] . '-01 00:00:00');
        if ($requested !== false && date('Y-m', $requested) === $_GET['month']) {
            // Clamp to a 10-year window either side of now: a public page
            // should not build a calendar for year 9999 off one query string.
            $minTs = strtotime('-10 years', strtotime('first day of this month'));
            $maxTs = strtotime('+10 years', strtotime('last day of this month'));
            $calMonthTs = max($minTs, min($maxTs, $requested));
        }
    }

    $startOfMonth = date('Y-m-01', $calMonthTs);
    $endOfMonth = date('Y-m-t', $calMonthTs);
    $curTs = $calMonthTs;
    $calYear = (int) date('Y', $curTs);
    $monthNum = (int) date('m', $curTs);
    $firstDow = (int) date('N', $curTs);
    $firstDow = $firstDow === 7 ? 0 : $firstDow;
    $daysInMonth = (int) date('t', $curTs);
    $calMonthParam = date('Y-m', $calMonthTs);
    $calPrevParam = date('Y-m', strtotime('-1 month', $calMonthTs));
    $calNextParam = date('Y-m', strtotime('+1 month', $calMonthTs));
    $calIsCurrentMonth = ($calMonthParam === date('Y-m'));

    // Preserve the three active filters across every month link, exactly the
    // way the List/Calendar view toggle at the top of the page already does.
    $calFilterQs = ($filterBarangay ? '&barangay=' . $filterBarangay : '')
        . ($filterWaste ? '&waste_type=' . urlencode($filterWaste) : '')
        . ($filterType ? '&schedule_type=' . urlencode($filterType) : '');
    $calLink = static fn(string $m): string =>
        e(app_url('/public/ba_schedule.php?view=calendar&month=' . $m . $calFilterQs));

    /* Dot colour for the compact phone grid + legend + agenda. Maps the same
       ba_waste_meta() badge the pills already use, so the three surfaces can
       never disagree about what "wet" looks like. */
    $_calDotFn = static function (string $badge): string {
        if (str_contains($badge, 'bg-success')) return 'dot-success';
        if (str_contains($badge, 'bg-secondary')) return 'dot-secondary';
        if (str_contains($badge, 'bg-danger')) return 'dot-danger';
        if (str_contains($badge, 'bg-warning')) return 'dot-warning';
        if (str_contains($badge, 'bg-info')) return 'dot-info';
        return 'dot-primary';
    };

    $cells = [];
    for ($i = 0; $i < $firstDow; $i++) $cells[] = null;
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $ds = date('Y-m-d', mktime(0, 0, 0, $monthNum, $d, $calYear));
        $cells[] = ['day' => $d, 'date' => $ds, 'items' => []];
    }
    $targetTsMap = [];
    $_calItemFn = static function (array $s) use ($_pubRefPad): string {
        $sid = (int) ($s['id'] ?? 0);
        $ref = str_pad((string) $sid, $_pubRefPad, '0', STR_PAD_LEFT);
        $wt = (string) $s['waste_type'];
        $st = (string) ($s['schedule_type'] ?? 'regular');
        $wtm = ba_waste_meta($wt);
        $dow = (int) ($s['day_of_week'] ?? 0);
        $when = '';
        if ($st === 'one_time' || $st === 'exception') {
            $when = !empty($s['collection_date']) ? date('M j, Y (l)', strtotime((string) $s['collection_date'])) : '—';
        } else {
            $when = ba_day_name($dow);
        }
        $time = ba_format_time($s['time_start'] ?? null) . ' – ' . ba_format_time($s['time_end'] ?? null);
        $brgy = e((string) ($s['barangay_name'] ?? ''));
        $linked = '';
        $lt = (string) ($s['linked_type'] ?? 'none');
        $mapHref = '';
        if ($lt === 'dropoff') {
            $dn = trim((string) ($s['__dropoff_name'] ?? ''));
            $did = (int) ($s['__dropoff_id'] ?? 0);
            $dlat = (float) ($s['__dropoff_lat'] ?? 0);
            $dlng = (float) ($s['__dropoff_lng'] ?? 0);
            $linked = '<span class="badge bg-info text-dark me-1"><i data-lucide="tag" class="lucide-14"></i> Drop-off' . ($dn !== '' ? ' · ' . e($dn) : '') . '</span>';
            if ($did > 0 && $dlat !== 0.0 && $dlng !== 0.0) {
                $mapHref = app_url('/public/ba_dropoff_map.php?focus=dropoff&id=' . $did . '&lat=' . $dlat . '&lng=' . $dlng . '&zoom=16');
            }
        }
        $notes = !empty($s['notes']) ? '<div class="mt-2 text-muted small"><i data-lucide="info" class="lucide-14"></i> ' . e((string) $s['notes']) . '</div>' : '';
        $stClass = $st === 'exception' ? 'bg-danger' : ($st === 'one_time' ? 'bg-warning text-dark' : 'bg-light text-dark');
        $stLabel = e(ucfirst(str_replace('_', ' ', $st)));
        $json = json_encode([
            'sid' => $sid, 'ref' => $ref,
            'title' => (string) $s['title'],
            'wt' => $wt, 'wt_short' => $wtm['short'], 'wt_label' => $wtm['label'], 'wt_badge' => $wtm['badge'], 'wt_icon' => $wtm['icon'],
            'st' => $st, 'st_label' => $stLabel, 'st_class' => $stClass,
            'when' => $when, 'time' => $time, 'brgy' => $brgy,
            'linked_html' => $linked, 'map_href' => $mapHref, 'notes_html' => $notes
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    };
    foreach ($schedules as $s) {
        $type = (string) $s['schedule_type'];
        if ($type === 'one_time' || $type === 'exception') {
            if (!empty($s['collection_date']) && $s['collection_date'] >= $startOfMonth && $s['collection_date'] <= $endOfMonth) {
                $targetTsMap[$s['collection_date']][] = $_calItemFn($s);
            }
        } else {
            $dow = (int) $s['day_of_week'];
            $dow = $dow === 1 ? 7 : $dow - 1;
            for ($d = 1; $d <= $daysInMonth; $d++) {
                // $calYear, not date('Y') — see the note on the cell loop above.
                $ts = mktime(0, 0, 0, $monthNum, $d, $calYear);
                $wd = (int) date('w', $ts);
                if ($wd === $dow) {
                    $ds = date('Y-m-d', $ts);
                    if ($ds >= $startOfMonth && $ds <= $endOfMonth) $targetTsMap[$ds][] = $_calItemFn($s);
                }
            }
        }
    }
    foreach ($cells as &$cell) {
        if (is_array($cell) && isset($targetTsMap[$cell['date']])) {
            $cell['items'] = $targetTsMap[$cell['date']];
        }
    }
    unset($cell);
    while (count($cells) % 7 !== 0) $cells[] = null;

    /* Legend (desktop + phone) and phone agenda list. Both derive from the
       same $schedules/$cells the grid uses, so filtering by dropdown can
       never leave them showing a waste type the grid hid. */
    $_calLegend = [];
    foreach ($schedules as $_ls) {
        $_lwt = (string) ($_ls['waste_type'] ?? '');
        if ($_lwt === '' || isset($_calLegend[$_lwt])) continue;
        $_lm = ba_waste_meta($_lwt);
        $_calLegend[$_lwt] = [
            'short' => (string) ($_lm['short'] ?? $_lwt),
            'dot' => $_calDotFn((string) ($_lm['badge'] ?? '')),
        ];
    }
    $_calAgenda = [];
    foreach ($cells as $_cc) {
        if (is_array($_cc) && !empty($_cc['items'])) $_calAgenda[] = $_cc;
    }
?>
<div class="mc-neo-card mc-cal">
    <?php /* No .p-* utility: .mc-neo-card already contributes 24px, and Bootstrap's
             padding utilities are !important, so a .p-4 here silently stacked a
             second 24px gutter inside the card. .mc-cal > .card-body owns the
             inset now, once. */ ?>
    <div class="card-body">
        <div class="mc-cal-bar">
            <a class="mc-cal-nav" href="<?= $calLink($calPrevParam) ?>" rel="prev" aria-label="Previous month">
                <i data-lucide="chevron-left" class="lucide"></i><span>Prev</span>
            </a>
            <h2 class="mc-cal-title" aria-live="polite"><?= date('F Y', $calMonthTs) ?></h2>
            <a class="mc-cal-nav" href="<?= $calLink($calNextParam) ?>" rel="next" aria-label="Next month">
                <span>Next</span><i data-lucide="chevron-right" class="lucide"></i>
            </a>
        </div>
        <?php if (!$calIsCurrentMonth) : ?>
            <div class="mc-cal-now">
                <a class="mc-cal-now-link" href="<?= $calLink(date('Y-m')) ?>">
                    <i data-lucide="calendar-check" class="lucide-14"></i> Back to <?= date('F Y') ?>
                </a>
            </div>
        <?php endif; ?>
        <?php if (!empty($_calLegend)) : ?>
            <div class="mc-cal-legend" aria-label="Waste type legend">
                <?php foreach ($_calLegend as $_lv) : ?>
                    <span class="mc-cal-legend-item"><span class="mc-cal-dot <?= e($_lv['dot']) ?>" aria-hidden="true"></span><?= e($_lv['short']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <!-- One wrapper for the weekday header AND the day grid: they are two
             independent 7-column grids, and the min-width below is what stops
             them squeezing on a phone. Sharing a wrapper makes it impossible
             for the two to end up at different widths and drift out of
             column alignment. -->
        <div class="mc-cal-grid">
        <div class="sw">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd) : ?>
                <div class="swd"><?= $wd ?></div>
            <?php endforeach; ?>
        </div>
        <div class="sg">
        <?php foreach ($cells as $cell) : ?>
            <?php
            if ($cell === null) { echo '<div class="sc sce"></div>'; continue; }
            $isToday = $cell['date'] === $today;
            $itemsCount = count($cell['items']);
            $visibleCount = min(2, $itemsCount);
            $moreCount = max(0, $itemsCount - $visibleCount);
            $allJson = htmlspecialchars('[' . implode(',', $cell['items']) . ']', ENT_QUOTES, 'UTF-8');
            $cellAria = e(date('F j, Y (l)', strtotime($cell['date'])))
                . ($itemsCount > 0 ? ', ' . $itemsCount . ' collection' . ($itemsCount === 1 ? '' : 's') : ', no collections');
            ?>
            <div class="sc<?= $isToday ? ' t' : '' ?><?= $itemsCount > 0 ? ' has-items' : '' ?>" role="button" tabindex="0" aria-label="<?= $cellAria ?>" data-day="<?= e($cell['date']) ?>" data-label="<?= e(date('F j, Y (l)', strtotime($cell['date']))) ?>" data-items="<?= $allJson ?>">
                <div class="sn<?= $isToday ? ' t' : '' ?>"><?= (int) $cell['day'] ?></div>
                <?php if ($itemsCount > 0) : ?>
                <?php /* The phone calendar collapses each day to its number plus a
                         count chip and dots, because a waste name cannot fit a ~41px
                         column and a truncated one is worse than a count. Hidden on
                         desktop, where the pills carry the information. */ ?>
                <span class="sc-count" aria-hidden="true"><?= $itemsCount ?></span>
                <?php
                $_dots = [];
                foreach (array_slice($cell['items'], 0, 6) as $_dr) {
                    $_da = json_decode((string) $_dr, true);
                    $_db = is_array($_da) ? (string) ($_da['wt_badge'] ?? '') : '';
                    $_dc = $_calDotFn($_db);
                    if (!in_array($_dc, $_dots, true)) $_dots[] = $_dc;
                    if (count($_dots) >= 3) break;
                }
                ?>
                <?php if (!empty($_dots)) : ?>
                    <span class="sc-dots" aria-hidden="true"><?php foreach ($_dots as $_dd) : ?><span class="mc-cal-dot <?= e($_dd) ?>"></span><?php endforeach; ?></span>
                <?php endif; ?>
                <div class="sb">
                    <?php for ($i = 0; $i < $visibleCount; $i++) :
                        $itemRaw = $cell['items'][$i] ?? '{}';
                        $_arr = json_decode($itemRaw, true);
                        if (!is_array($_arr)) $_arr = [];
                        ?>
                        <button type="button" class="eb btn p-0 border-0 m-0 text-start <?= e($_arr['wt_badge'] ?? 'bg-light text-dark') ?>" data-idx="<?= $i ?>" title="<?= e(($_arr['wt_short'] ?? 'Waste') . ' · Ref #' . ($_arr['ref'] ?? '')) ?>" aria-label="View schedule details: <?= e(($_arr['wt_short'] ?? 'Waste') . ' Ref #' . ($_arr['ref'] ?? '')) ?>">
                            <span class="ei"><?= e($_arr['wt_icon'] ?? '') ?></span>
                            <span class="et"><?= e($_arr['wt_short'] ?? 'Waste') ?></span>
                        </button>
                    <?php endfor; ?>
                    <?php if ($moreCount > 0) : ?>
                        <button type="button" class="mb btn btn-sm p-0 border-0 m-0" data-more="<?= $moreCount ?>" aria-label="Show <?= $moreCount ?> additional schedules for this date">View all <?= $itemsCount ?></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div><!-- /.sg -->
        </div><!-- /.mc-cal-grid -->
        <?php /* Phone agenda (option b): the compact grid shows number + count +
                 dots only, so the waste names need a second surface one tap away
                 without opening a modal per day. Desktop keeps the grid alone —
                 .mc-cal-agenda is display:none above the phone breakpoint. */ ?>
        <?php if (!empty($_calAgenda)) : ?>
        <div class="mc-cal-agenda" aria-label="Upcoming collections this month">
            <h3 class="mc-cal-agenda-title"><i data-lucide="list" class="lucide-16"></i> Upcoming collections <span class="mc-cal-agenda-total"><?= count($_calAgenda) ?> day<?= count($_calAgenda) === 1 ? '' : 's' ?></span></h3>
            <div class="mc-cal-agenda-list">
            <?php foreach ($_calAgenda as $_ag) :
                $_agDate = (string) ($_ag['date'] ?? '');
                $_agTs = strtotime($_agDate);
                $_agCount = count($_ag['items']);
                $_agJson = htmlspecialchars('[' . implode(',', $_ag['items']) . ']', ENT_QUOTES, 'UTF-8');
                $_agNames = [];
                foreach (array_slice($_ag['items'], 0, 2) as $_ar) {
                    $_aa = json_decode((string) $_ar, true);
                    if (is_array($_aa) && !empty($_aa['wt_short'])) $_agNames[] = (string) $_aa['wt_short'];
                }
                $_agSub = implode(' · ', $_agNames);
                if ($_agCount > 2) $_agSub .= ($_agSub !== '' ? ' · ' : '') . '+' . ($_agCount - 2) . ' more';
                $_agDots = [];
                foreach (array_slice($_ag['items'], 0, 6) as $_ar) {
                    $_aa = json_decode((string) $_ar, true);
                    $_ab = is_array($_aa) ? (string) ($_aa['wt_badge'] ?? '') : '';
                    $_ad = $_calDotFn($_ab);
                    if (!in_array($_ad, $_agDots, true)) $_agDots[] = $_ad;
                    if (count($_agDots) >= 3) break;
                }
            ?>
                <button type="button" class="mc-cal-agenda-item" data-day="<?= e($_agDate) ?>" data-label="<?= e($_agTs !== false ? date('F j, Y (l)', $_agTs) : $_agDate) ?>" data-items="<?= $_agJson ?>">
                    <span class="mc-cal-agenda-date" aria-hidden="true"><strong><?= (int) ($_ag['day'] ?? 0) ?></strong><small><?= e($_agTs !== false ? date('D', $_agTs) : '') ?></small></span>
                    <span class="mc-cal-agenda-body">
                        <span class="mc-cal-agenda-day"><?= e($_agTs !== false ? date('F j, Y (l)', $_agTs) : $_agDate) ?></span>
                        <span class="mc-cal-agenda-sub"><span class="mc-cal-agenda-dots" aria-hidden="true"><?php foreach ($_agDots as $_ad) : ?><span class="mc-cal-dot <?= e($_ad) ?>"></span><?php endforeach; ?></span><?= e($_agSub !== '' ? $_agSub : $_agCount . ' schedules') ?></span>
                    </span>
                    <span class="mc-cal-agenda-meta" aria-hidden="true"><span class="mc-cal-agenda-count"><?= $_agCount ?></span><i data-lucide="chevron-right" class="lucide-16"></i></span>
                </button>
            <?php endforeach; ?>
            </div>
            <div class="mc-cal-agenda-empty" hidden>No collections match your search. Only Published schedules are listed here.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade sm" id="scDayDetailModal" tabindex="-1" aria-labelledby="scDayDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header text-white">
                <div>
                    <h2 class="modal-title h5" id="scDayDetailModalLabel"><i data-lucide="calendar-days" class="lucide-16"></i> <span id="scDayTitle">Schedules</span></h2>
                    <div id="scDaySubtitle" class="small mt-1"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="scDayList" class="dl"></div>
            </div>
            <div class="modal-footer d-flex flex-wrap justify-content-between">
                <div id="scDayCount" class="small text-muted"></div>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php else : ?>
<div class="mc-neo-card">
    <div class="card-body p-0">
        <div class="mc-ba-schedule-table-wrap mc-stacked-card">
            <div class="mc-ba-schedule-table">
                <div class="mc-ba-sched-row mc-ba-sched-head">
                    <div class="mc-ba-sched-cell mc-ba-sched-col-ref"><span class="mc-th-wrap"><i data-lucide="hash" class="lucide mc-th-icon"></i>Ref #</span></div>
                    <div class="mc-ba-sched-cell mc-ba-sched-col-sched"><span class="mc-th-wrap"><i data-lucide="calendar-days" class="lucide mc-th-icon"></i>Schedule</span></div>
                    <div class="mc-ba-sched-cell mc-ba-sched-col-waste"><span class="mc-th-wrap"><i data-lucide="recycle" class="lucide mc-th-icon"></i>Waste Type</span></div>
                    <div class="mc-ba-sched-cell mc-ba-sched-col-type"><span class="mc-th-wrap"><i data-lucide="tag" class="lucide mc-th-icon"></i>Type</span></div>
                    <div class="mc-ba-sched-cell mc-ba-sched-col-when"><span class="mc-th-wrap"><i data-lucide="calendar" class="lucide mc-th-icon"></i>When</span></div>
                    <div class="mc-ba-sched-cell mc-ba-sched-col-time"><span class="mc-th-wrap"><i data-lucide="clock" class="lucide mc-th-icon"></i>Time</span></div>
                    <div class="mc-ba-sched-cell mc-ba-sched-col-brgy"><span class="mc-th-wrap"><i data-lucide="map-pin" class="lucide mc-th-icon"></i>Barangay</span></div>
                </div>
                <div class="mc-ba-schedule-tbody">
                    <?php if (count($schedules) === 0) : ?>
                        <div class="mc-ba-sched-row"><div class="mc-ba-sched-cell mc-ba-sched-empty" style="grid-column:1 / -1">No schedules match your filters. Only Published schedules are listed here — Draft or archived schedules stay hidden.</div></div>
                    <?php else : ?>
                        <?php foreach ($schedules as $s) : ?>
                            <?= ba_render_sched_row($s, $filterView, $_pubRefPad) . "\n" ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($filterView === 'list') : ?>
            <div class="p-3 small text-muted" id="baSchedListHint">Click the Ref # button to filter by that schedule ID.</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

<script>
(function () {
  const $s = document.getElementById('pubSchedSearch');
  const $infoMsg = document.getElementById('pubSchedInfoMsg');
  const $showing = document.getElementById('pubSchedShowingCount');
  const _baInitialQ = <?= $_baFilterQJs ?>;
  const _baAlreadyBypassed = <?= $_baBypassFlag === '1' ? 'true' : 'false' ?>;
  const _baCurUrl = new URL(window.location.href);
  const $modal = document.getElementById('scDayDetailModal');
  const $modalDayTitle = document.getElementById('scDayTitle');
  const $modalSubtitle = document.getElementById('scDaySubtitle');
  const $modalList = document.getElementById('scDayList');
  const $modalCount = document.getElementById('scDayCount');
  let bsModal = null;
  function __ensureBsModal() {
    if (bsModal) return bsModal;
    if (!$modal) return null;
    if (typeof window.bootstrap !== 'undefined' && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      try { bsModal = new window.bootstrap.Modal($modal, { backdrop: true, keyboard: true }); }
      catch(_err) { bsModal = null; }
    }
    return bsModal;
  }
  (function __waitBootstrap() {
    var tries = 0;
    var iv = setInterval(function () {
      tries++;
      if (__ensureBsModal()) { clearInterval(iv); return; }
      if (tries >= 40) clearInterval(iv);
    }, 100);
  })();
  const totalRows = (function(){
    const listRows = document.querySelectorAll('.mc-ba-sched-row[data-id]');
    if (listRows.length) return listRows.length;
    let total = 0;
    document.querySelectorAll('.sc[data-items]').forEach(cell => {
      try {
        const arr = JSON.parse(cell.dataset.items || '[]');
        total += Array.isArray(arr) ? arr.length : 0;
      } catch(_) {}
    });
    return total;
  })();
  function _qMeaningful(raw) {
    const q = String(raw||'').trim();
    if (!q) return false;
    if (/#?\d{2,}/.test(q)) return true;
    return q.length >= 3;
  }
  function _buildNextUrl(raw) {
    const u = new URL(_baCurUrl.href);
    const q = String(raw||'').trim();
    if (q === '') u.searchParams.delete('q');
    else u.searchParams.set('q', q);
    return u;
  }
  function _schedMatches(data, q, qDigits, hasDigits, parts) {
    if (!data || typeof data !== 'object') return false;
    const id = String(data.sid || '');
    const padded = String(data.ref || '').toLowerCase();
    const title = String(data.title || '').toLowerCase();
    const wt = String(data.wt || '').toLowerCase();
    const wtShort = String(data.wt_short || '').toLowerCase();
    if (hasDigits && (id === qDigits || padded.includes(qDigits))) return true;
    if (!parts.length) return false;
    for (const p of parts) {
      if (title.includes(p) || padded.includes(p) || id.includes(p) || wt.includes(p) || wtShort.includes(p)) continue;
      return false;
    }
    return true;
  }
  function buildDetailCard(it) {
    if (!it) return '';
    const mapBtn = it.map_href ? '<div class="mt-2"><a class="btn btn-sm btn-outline-primary text-decoration-none" target="_blank" rel="noopener" href="' + it.map_href + '"><i data-lucide="map-pin" class="lucide-14"></i> Open dropoff on map</a></div>' : '';
    return '<div class="di" data-id="' + (it.sid || '') + '" data-ref_padded="' + (it.ref || '') + '" data-title="' + (it.title || '') + '">' +
      '<div class="dh">' +
        '<button type="button" class="btn btn-sm ba-pub-ref-btn rb" style="font-family:JetBrains Mono,Fira Code,Consolas,monospace;font-weight:700;min-width:64px;text-align:center;padding:3px 8px;cursor:pointer;" title="Search Ref #' + (it.ref || '') + '">#' + (it.ref || '') + '</button>' +
        '<h3 class="dt">' + (it.title || '') + '</h3>' +
      '</div>' +
      '<div class="db">' +
        '<span class="badge ' + (it.wt_badge || 'bg-light text-dark') + '"><i data-lucide="recycle" class="lucide-14"></i> ' + (it.wt_label || it.wt_short || 'Waste') + '</span> ' +
        '<span class="badge ' + (it.st_class || 'bg-light text-dark') + ' border"><i data-lucide="tag" class="lucide-14"></i> ' + (it.st_label || 'Regular') + '</span> ' +
        (it.linked_html || '') +
      '</div>' +
      '<div class="dm">' +
        '<div><i data-lucide="calendar" class="lucide-14"></i><span>' + (it.when || '—') + '</span></div>' +
        '<div><i data-lucide="clock" class="lucide-14"></i><span>' + (it.time || '—') + '</span></div>' +
        '<div><i data-lucide="map-pin" class="lucide-14"></i><span>' + (it.brgy || '—') + '</span></div>' +
      '</div>' +
      (it.notes_html || '') + mapBtn +
    '</div>';
  }
  function _showSingleSchedule(it, dayLabel, dayDate, refItems, refIdx) {
    const m = __ensureBsModal();
    if (!it || !m || !$modalList) return;
    const item = it;
    if ($modalDayTitle) $modalDayTitle.textContent = 'Schedule #' + (item.ref || '') + ' · ' + (item.title || '');
    if ($modalSubtitle) $modalSubtitle.textContent = (dayLabel || '') + (dayDate ? ' · ' + dayDate : '') + ' · Tap Ref # to search';
    $modalList.innerHTML = buildDetailCard(item);
    if ($modalCount) {
      const extra = Array.isArray(refItems) && refItems.length > 1
        ? ' · ' + '<button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="scBackToList"><i data-lucide="arrow-left" class="lucide-14"></i> Back to all ' + refItems.length + ' schedules</button>'
        : '';
      $modalCount.innerHTML = '1 schedule shown' + extra;
    }
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    $modalList.querySelectorAll('.rb').forEach(b => {
      b.addEventListener('click', (e) => {
        const mm = __ensureBsModal();
        if (mm) mm.hide();
        e.preventDefault();
        if (!$s) return;
        const ref = String(b.textContent || '').replace(/\s+/g, '').replace(/^#/, '');
        $s.value = '#' + ref;
        const u = _buildNextUrl($s.value);
        setTimeout(() => { window.location.href = u.href; }, 120);
      });
    });
    const $back = document.getElementById('scBackToList');
    if ($back && Array.isArray(refItems)) {
      $back.addEventListener('click', () => { _showList(refItems, dayLabel, dayDate, refIdx); });
    }
    m.show();
  }
  function _showList(items, label, day, prefocusIdx) {
    const m = __ensureBsModal();
    if (!m || !$modalList) return;
    const arr = Array.isArray(items) ? items.slice() : [];
    if (typeof prefocusIdx === 'number' && !isNaN(prefocusIdx) && prefocusIdx >= 0 && prefocusIdx < arr.length) {
      const before = arr.slice(0, prefocusIdx);
      const f = [arr[prefocusIdx]];
      const after = arr.slice(prefocusIdx + 1);
      items = f.concat(before).concat(after);
    } else { items = arr; }
    if ($modalDayTitle) $modalDayTitle.textContent = 'Schedules for ' + (label || 'this day');
    if ($modalSubtitle) $modalSubtitle.textContent = 'Collection day ' + (day || '') + ' · Click any card for full details';
    $modalList.innerHTML = items.length ? items.map(buildDetailCard).join('') : '<div class="de">No schedules found for this day.</div>';
    if ($modalCount) $modalCount.textContent = items.length + ' schedule' + (items.length !== 1 ? 's' : '') + ' loaded · Click a card to view full details';
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    $modalList.querySelectorAll('.di').forEach((card, idx) => {
      card.style.cursor = 'pointer';
      card.addEventListener('click', (e) => {
        const tgt = e.target;
        if (tgt && (tgt.closest && (tgt.closest('a') || tgt.closest('.rb')))) return;
        _showSingleSchedule(items[idx], label, day, items, idx);
      });
    });
    $modalList.querySelectorAll('.rb').forEach(b => {
      b.addEventListener('click', (e) => {
        const mm = __ensureBsModal();
        if (mm) mm.hide();
        e.preventDefault();
        if (!$s) return;
        const ref = String(b.textContent || '').replace(/\s+/g, '').replace(/^#/, '');
        $s.value = '#' + ref;
        const u = _buildNextUrl($s.value);
        setTimeout(() => { window.location.href = u.href; }, 120);
      });
    });
    m.show();
  }
  function openDayModal(cell, focusIdx) {
    if (!cell) return;
    const label = String(cell.dataset.label || 'Schedules');
    const day = String(cell.dataset.day || '');
    let items = [];
    try { items = JSON.parse(cell.dataset.items || '[]'); } catch(_) { items = []; }
    if (!Array.isArray(items)) items = [];
    _showList(items, label, day, focusIdx);
  }
  let t;
  let _reloading = false;
  /* Grid + agenda share one source of truth. Cells used to overwrite
     dataset.items with the filtered subset, so a second keystroke filtered
     an already-filtered day and clearing the box never restored removed
     pills. Stash the server-rendered array once in dataset.allItems and
     always filter from it; dataset.items stays the *visible* subset the
     modal reads. */
  function _calAll(el) {
    try {
      if (el.dataset.allItems) {
        const a = JSON.parse(el.dataset.allItems);
        if (Array.isArray(a)) return a;
      }
    } catch (_) {}
    try {
      const cur = JSON.parse(el.dataset.items || '[]');
      const arr = Array.isArray(cur) ? cur : [];
      try { el.dataset.allItems = JSON.stringify(arr); } catch (_) {}
      return arr;
    } catch (_) { return []; }
  }
  function _calDot(badge) {
    const b = String(badge || '');
    if (b.indexOf('bg-success') >= 0) return 'dot-success';
    if (b.indexOf('bg-secondary') >= 0) return 'dot-secondary';
    if (b.indexOf('bg-danger') >= 0) return 'dot-danger';
    if (b.indexOf('bg-warning') >= 0) return 'dot-warning';
    if (b.indexOf('bg-info') >= 0) return 'dot-info';
    return 'dot-primary';
  }
  function _paintDots(cell, arr) {
    const box = cell.querySelector('.sc-dots');
    if (!box) return;
    const seen = [];
    arr.slice(0, 6).forEach(function (it) {
      const d = _calDot(it && it.wt_badge);
      if (seen.indexOf(d) < 0) seen.push(d);
      if (seen.length >= 3) return;
    });
    box.innerHTML = seen.slice(0, 3).map(function (d) { return '<span class="mc-cal-dot ' + d + '"></span>'; }).join('');
  }
  function _paintAgenda(item, arr) {
    const total = arr.length;
    const names = arr.slice(0, 2).map(function (it) { return String((it && it.wt_short) || 'Waste'); });
    let sub = names.join(' · ');
    if (total > 2) sub += (sub ? ' · ' : '') + '+' + (total - 2) + ' more';
    const subEl = item.querySelector('.mc-cal-agenda-sub');
    if (subEl) {
      const dotsHtml = arr.slice(0, 6).reduce(function (acc, it) {
        const d = _calDot(it && it.wt_badge);
        return acc.indexOf(d) < 0 && acc.length < 3 ? acc.concat([d]) : acc;
      }, []).map(function (d) { return '<span class="mc-cal-dot ' + d + '"></span>'; }).join('');
      const safeSub = String(sub || (total + ' schedules')).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      subEl.innerHTML = '<span class="mc-cal-agenda-dots" aria-hidden="true">' + dotsHtml + '</span>' + safeSub;
    }
    const countEl = item.querySelector('.mc-cal-agenda-count');
    if (countEl) countEl.textContent = String(total);
    try { item.dataset.items = JSON.stringify(arr); } catch (_) {}
  }
  function _updateAgendaEmpty() {
    const list = document.querySelector('.mc-cal-agenda-list');
    const empty = document.querySelector('.mc-cal-agenda-empty');
    if (!list || !empty) return;
    const anyVisible = Array.prototype.some.call(list.children, function (c) { return c.style.display !== 'none'; });
    empty.hidden = anyVisible;
    const titleTotal = document.querySelector('.mc-cal-agenda-total');
    if (titleTotal) {
      const n = Array.prototype.filter.call(list.children, function (c) { return c.style.display !== 'none'; }).length;
      titleTotal.textContent = String(n) + ' day' + (n === 1 ? '' : 's');
    }
  }
  function rowMatches(el, q, qDigits, hasDigits, parts) {
    const id = String(el.dataset.id || '');
    const padded = String(el.dataset.ref_padded || '').toLowerCase();
    const title = String(el.dataset.title || '').toLowerCase();
    if (hasDigits && (id === qDigits || padded.includes(qDigits))) return true;
    if (!parts.length) return false;
    for (const p of parts) {
      if (title.includes(p) || padded.includes(p) || id.includes(p)) continue;
      return false;
    }
    return true;
  }
  function runFilter() {
    const raw = $s ? String($s.value || '').trim() : '';
    if (raw.length === 0) {
      const listRows = document.querySelectorAll('.mc-ba-sched-row[data-id]');
      listRows.forEach(r => r.style.display = '');
      document.querySelectorAll('.sc[data-items]').forEach(cell => {
        const items = _calAll(cell);
        const total = items.length;
        try { cell.dataset.items = JSON.stringify(items); } catch (_) {}
        const visibleCount = Math.min(2, total);
        const moreCount = Math.max(0, total - visibleCount);
        const sb = cell.querySelector('.sb');
        let badges = cell.querySelectorAll('.eb');
        /* A previous filter may have removed pill nodes; rebuild from the
           stashed original so clearing the box restores the day. */
        if (sb && badges.length < visibleCount) {
          const more = cell.querySelector('.mb');
          for (let i = badges.length; i < visibleCount; i++) {
            const it = items[i];
            if (!it) continue;
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'eb btn p-0 border-0 m-0 text-start ' + String(it.wt_badge || 'bg-light text-dark');
            b.dataset.idx = String(i);
            b.innerHTML = '<span class="ei"></span><span class="et"></span>';
            if (sb) sb.insertBefore(b, more);
          }
          badges = cell.querySelectorAll('.eb');
        }
        badges.forEach((b, idx) => {
          const rawItem = items[idx];
          if (idx < visibleCount && rawItem) {
            b.style.display = '';
            b.style.visibility = 'visible';
            const cls = String(rawItem.wt_badge || 'bg-light text-dark');
            b.className = 'eb btn p-0 border-0 m-0 text-start ' + cls;
            const iconEl = b.querySelector('.ei');
            const textEl = b.querySelector('.et');
            if (iconEl) iconEl.textContent = String(rawItem.wt_icon || '');
            if (textEl) textEl.textContent = String(rawItem.wt_short || 'Waste');
            const title = String((rawItem.wt_short || 'Waste') + ' · Ref #' + (rawItem.ref || ''));
            b.title = title;
            b.setAttribute('aria-label', 'View schedule details: ' + title);
            b.dataset.idx = String(idx);
          } else if (idx < 2) {
            b.style.display = 'none';
          } else {
            b.remove();
          }
        });
        const $more = cell.querySelector('.mb');
        if ($more) {
          $more.style.display = moreCount > 0 ? '' : 'none';
          $more.textContent = 'View all ' + total;
          $more.dataset.more = String(moreCount);
        }
        /* The phone calendar hides .sb and shows this count instead, so it has to
           track the same filtered total the pills are being rebuilt from. */
        const $count = cell.querySelector('.sc-count');
        if ($count) $count.textContent = String(total);
        _paintDots(cell, items);
        cell.classList.toggle('has-items', total > 0);
        cell.style.display = '';
        cell.style.opacity = cell.classList.contains('t') || total > 0 ? '' : '0.45';
      });
      document.querySelectorAll('.mc-cal-agenda-item').forEach(item => {
        const all = _calAll(item);
        _paintAgenda(item, all);
        item.style.display = '';
      });
      _updateAgendaEmpty();
      if ($showing) $showing.textContent = String(totalRows);
      if ($infoMsg) $infoMsg.textContent = '';
      return;
    }
    const q = raw.toLowerCase();
    const qDigits = (raw.match(/\d+/g) || []).join('');
    const hasDigits = qDigits.length > 0;
    const parts = q.split(/\s+/).filter(Boolean);
    let shownTotal = 0;
    const listRows = document.querySelectorAll('.mc-ba-sched-row[data-id]');
    listRows.forEach(r => {
      if (rowMatches(r, q, qDigits, hasDigits, parts)) { r.style.display = ''; shownTotal++; } else { r.style.display = 'none'; }
    });
    const calCells = document.querySelectorAll('.sc[data-items]');
    calCells.forEach(cell => {
      const all = _calAll(cell);
      const matches = [];
      all.forEach(it => { if (_schedMatches(it, q, qDigits, hasDigits, parts)) matches.push(it); });
      const total = matches.length;
      const visibleCount = Math.min(2, total);
      const moreCount = Math.max(0, total - visibleCount);
      shownTotal += total;
      try { cell.dataset.items = JSON.stringify(matches); } catch (_) {}
      const badges = cell.querySelectorAll('.eb');
      const rendered = Math.min(2, all.length);
      badges.forEach((b, idx) => {
        const vi = matches[idx];
        if (idx < visibleCount && vi) {
          b.style.display = '';
          b.style.visibility = 'visible';
          const cls = String(vi.wt_badge || 'bg-light text-dark');
          b.className = 'eb btn p-0 border-0 m-0 text-start ' + cls;
          const iconEl = b.querySelector('.ei');
          const textEl = b.querySelector('.et');
          if (iconEl) iconEl.textContent = String(vi.wt_icon || '');
          if (textEl) textEl.textContent = String(vi.wt_short || 'Waste');
          const title = String((vi.wt_short || 'Waste') + ' · Ref #' + (vi.ref || ''));
          b.title = title;
          b.setAttribute('aria-label', 'View schedule details: ' + title);
          b.dataset.idx = String(idx);
        } else {
          b.style.display = 'none';
          if (idx >= rendered) b.remove();
        }
      });
      for (let i = rendered; i < visibleCount; i++) {
        const it = matches[i];
        if (!it) continue;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'eb btn p-0 border-0 m-0 text-start ' + String(it.wt_badge || 'bg-light text-dark');
        b.dataset.idx = String(i);
        const title = String((it.wt_short || 'Waste') + ' · Ref #' + (it.ref || ''));
        b.title = title;
        b.setAttribute('aria-label', 'View schedule details: ' + title);
        b.innerHTML = '<span class="ei"></span><span class="et"></span>';
        const iconEl = b.querySelector('.ei');
        const textEl = b.querySelector('.et');
        if (iconEl) iconEl.textContent = String(it.wt_icon || '');
        if (textEl) textEl.textContent = String(it.wt_short || 'Waste');
        const sb = cell.querySelector('.sb');
        const more = cell.querySelector('.mb');
        if (sb) sb.insertBefore(b, more);
      }
      const $more = cell.querySelector('.mb');
      if ($more) {
        $more.style.display = moreCount > 0 ? '' : 'none';
        $more.textContent = 'View all ' + total;
        $more.dataset.more = String(moreCount);
        $more.setAttribute('aria-label', 'Show ' + moreCount + ' additional schedule matches for this date');
      }
      /* Same reason as the reset branch: on phones the pills are hidden and
         the count chip + dots are the only per-day signal, so both must show
         the FILTERED total, not the count the server rendered. */
      const $count = cell.querySelector('.sc-count');
      if ($count) $count.textContent = String(total);
      _paintDots(cell, matches);
      cell.classList.toggle('has-items', total > 0);
      cell.style.opacity = total === 0 ? (cell.classList.contains('t') ? '1' : '0.35') : '';
    });
    document.querySelectorAll('.mc-cal-agenda-item').forEach(item => {
      const all = _calAll(item);
      const matches = all.filter(it => _schedMatches(it, q, qDigits, hasDigits, parts));
      _paintAgenda(item, matches);
      item.style.display = matches.length > 0 ? '' : 'none';
    });
    _updateAgendaEmpty();
    if ($showing) $showing.textContent = String(shownTotal);
    if ($infoMsg) {
      const rawCur = String($s.value || '').trim();
      const meaningful = _qMeaningful(rawCur);
      const curUrl = _buildNextUrl(rawCur);
      const alreadyQ = _baCurUrl.searchParams.get('q');
      const qChanged = rawCur !== (alreadyQ === null ? '' : alreadyQ);
      if (qChanged) { try { window.history.replaceState(null, '', curUrl.href); } catch(_) {} }
      if (shownTotal === 0) {
        let extraBtn = '';
        if (meaningful && !_baAlreadyBypassed && !_reloading) extraBtn = ' <button type="button" class="btn btn-sm btn-info ms-1" id="baBypassSearchBtn"><i data-lucide="search" class="lucide-14"></i> Search ALL barangays for this</button>';
        $infoMsg.innerHTML = '<i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> <strong class="text-danger">No matches</strong> in Ref # or Title.' + extraBtn + ' <span class="text-muted">Only Published schedules are listed here — Draft or archived schedules stay hidden even when the title is correct.</span>';
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        const $bb = document.getElementById('baBypassSearchBtn');
        if ($bb) $bb.addEventListener('click', () => { _reloading = true; window.location.href = curUrl.href; });
        if (meaningful && !_baAlreadyBypassed && totalRows > 0 && !_reloading) { clearTimeout(t); t = setTimeout(function(){ _reloading = true; window.location.href = curUrl.href; }, 550); }
      } else {
        const pct = totalRows ? Math.round((shownTotal/totalRows) * 100) : 0;
        $infoMsg.textContent = pct + '% · Esc to clear';
      }
    }
  }
  if ($s) {
    $s.addEventListener('input', () => { clearTimeout(t); t = setTimeout(runFilter, 80); });
    $s.addEventListener('keydown', (e) => { if (e.key === 'Escape') { $s.value = ''; const u = _buildNextUrl(''); try { window.history.replaceState(null,'',u.href); } catch(_){} runFilter(); $s.blur(); } });
  }
  document.querySelectorAll('.ba-pub-ref-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      if (!$s) return;
      const ref = String(btn.textContent || '').replace(/\s+/g, '').replace(/^#/, '');
      $s.value = '#' + ref;
      const u = _buildNextUrl($s.value);
      _reloading = true; window.location.href = u.href;
    });
  });
  /* List rows open the same day-detail modal as the calendar cells. Ref #
     buttons and map links keep their own behavior via the guard below. */
  (function bindRowModal() {
    const $tbody = document.querySelector('.mc-ba-schedule-tbody');
    if (!$tbody) return;
    function openRow(row) {
      let it = null;
      try { it = JSON.parse(row.dataset.detail || '{}'); } catch (_) { it = null; }
      if (!it || typeof it !== 'object') return;
      _showSingleSchedule(it, String(it.when || ''), String(it.brgy || ''), [it], 0);
    }
    $tbody.addEventListener('click', (e) => {
      const row = e.target && e.target.closest ? e.target.closest('.mc-ba-sched-row[data-detail]') : null;
      if (!row || !$tbody.contains(row)) return;
      if (e.target.closest && e.target.closest('a,button')) return;
      openRow(row);
    });
    $tbody.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
      const row = e.target && e.target.closest ? e.target.closest('.mc-ba-sched-row[data-detail]') : null;
      if (!row || !$tbody.contains(row)) return;
      if (e.target !== row) return;
      e.preventDefault();
      openRow(row);
    });
  })();
  const $refreshBtn = document.getElementById('schedRefreshBtn');
  if ($refreshBtn) {
    $refreshBtn.addEventListener('click', () => { window.location.reload(); });
  }
  (function bindCalDelegates() {
    const $grid = document.querySelector('.sg');
    if (!$grid) return;
    function getCellFromEl(el) {
      if (!el || typeof el.closest !== 'function') return null;
      const c = el.closest('.sc[data-items]');
      return c || null;
    }
    $grid.addEventListener('click', (e) => {
      let tgt = e.target;
      if (!tgt) return;
      if (typeof tgt.closest !== 'function') {
        tgt = tgt.parentNode ? tgt.parentNode : null;
        if (!tgt) return;
      }
      const btn = tgt.closest('.eb');
      if (btn) {
        e.preventDefault();
        e.stopPropagation();
        const cell = getCellFromEl(btn);
        if (!cell) return;
        let items = [];
        try { items = JSON.parse(cell.dataset.items || '[]'); } catch(_) { items = []; }
        if (!Array.isArray(items)) items = [];
        const idx = Math.min(Math.max(items.length - 1, 0), Math.max(0, parseInt(String(btn.dataset.idx || '0'), 10) || 0));
        const label = String(cell.dataset.label || 'Schedules');
        const day = String(cell.dataset.day || '');
        if (items[idx]) _showSingleSchedule(items[idx], label, day, items, idx);
        return;
      }
      const moreBtn = tgt.closest('.mb');
      if (moreBtn) {
        e.preventDefault();
        e.stopPropagation();
        const cell = getCellFromEl(moreBtn);
        if (!cell) return;
        openDayModal(cell, 0);
        return;
      }
      const cellEl = getCellFromEl(tgt);
      if (!cellEl) return;
      if (cellEl.classList && cellEl.classList.contains('sce')) return;
      const withinBadgeOrMore = tgt.closest('.eb') || tgt.closest('.mb');
      if (!withinBadgeOrMore) {
        e.preventDefault();
        openDayModal(cellEl, 0);
      }
    });
    /* The day cell is a real button now. It had to become one: on phones the
       waste pills are hidden (a name cannot fit a ~41px column) and they were the
       only focusable thing in the grid, so without this the compact calendar
       would have had no keyboard path at all. Enter/Space on a nested .eb or .mb
       is left to the browser, which already turns it into a click. */
    $grid.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
      const tgt = e.target;
      if (!tgt || typeof tgt.closest !== 'function') return;
      if (tgt.closest('.eb') || tgt.closest('.mb')) return;
      const cellEl = getCellFromEl(tgt);
      if (!cellEl) return;
      e.preventDefault();
      openDayModal(cellEl, 0);
    });
    /* Phone agenda rows carry the same data-day/data-label/data-items shape
       as a grid cell, so they open the same modal with no extra code path. */
    const $agenda = document.querySelector('.mc-cal-agenda-list');
    if ($agenda) {
      $agenda.addEventListener('click', (e) => {
        const item = e.target && e.target.closest ? e.target.closest('.mc-cal-agenda-item') : null;
        if (!item) return;
        e.preventDefault();
        openDayModal(item, 0);
      });
    }
  })();
  runFilter();
})();
</script>
<script>
(function(){
  function __renderSafe(){
    if (typeof window.__renderLucide === 'function') {
      window.__renderLucide();
      return true;
    }
    try { if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') { lucide.createIcons({nameAttr:'data-lucide',attrs:{width:16,height:16}}); return true; } } catch(e) {}
    return false;
  }
  function __poll(){
    var retries = 0;
    var iv = setInterval(function(){
      retries++;
      if (__renderSafe()) { clearInterval(iv); return; }
      if (retries >= 30) clearInterval(iv);
    }, 100);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', __poll, {once:true});
  else __poll();
  window.addEventListener('pageshow', function(e){ if (e.persisted) setTimeout(__poll, 120); }, {passive:true});
})();
</script>
<?php
require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
