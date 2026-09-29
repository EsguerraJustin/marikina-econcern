<?php
declare(strict_types=1);

/**
 * BasuraAlert — Validation service (Option B dedup)
 * Extracted from admin/api/ba_dropoffs_admin.php duplicated blocks.
 * Provides single source of truth for:
 *   - Dropoff child schedule rows (ba_dropoff_schedules)
 *   - Operation hours string (custom hours)
 */

function ba_validate_dropoff_schedules(?array $schedules): array
{
    // Accepts decoded JSON array or null
    if ($schedules === null) {
        return ['ok' => true, 'cleaned' => [], 'error' => null];
    }
    if (!is_array($schedules)) {
        return ['ok' => false, 'cleaned' => [], 'error' => 'Schedules payload must be a JSON array.'];
    }
    if (count($schedules) === 0) {
        return ['ok' => true, 'cleaned' => [], 'error' => null];
    }

    $validWastesList = ['Biodegradable','Non-Biodegradable','Recyclable','Hazardous','Special','Bulky'];
    $timeRe = '/^\d{2}:\d{2}:\d{2}$/';
    $dateRe = '/^\d{4}-\d{2}-\d{2}$/';
    $schedErrors = [];
    $cleanedRows = [];

    foreach (array_values($schedules) as $idx => $row) {
        if (!is_array($row)) {
            $schedErrors[] = "Schedule row ".($idx+1).": not an object (corrupt payload)";
            continue;
        }
        $rowErrs = [];
        $dow = isset($row['day_of_week']) ? (int)$row['day_of_week'] : -1;
        if ($dow < 0 || $dow > 6) $rowErrs[] = 'day-of-week must be 0-6 (Sun-Sat)';
        $wt = isset($row['waste_type']) ? trim((string)$row['waste_type']) : '';
        if ($wt === '') $rowErrs[] = 'waste_type blank';
        elseif (!in_array($wt, $validWastesList, true)) $rowErrs[] = 'waste_type "'.e($wt).'" is not in allowed list ('.implode(',',$validWastesList).')';
        $ts = isset($row['time_start']) ? trim((string)$row['time_start']) : '';
        $te = isset($row['time_end'])   ? trim((string)$row['time_end'])   : '';
        if ($ts === '' || !preg_match($timeRe, $ts)) $rowErrs[] = 'time_start must be HH:MM:SS (got "'.e($ts).'")';
        if ($te === '' || !preg_match($timeRe, $te)) $rowErrs[] = 'time_end must be HH:MM:SS (got "'.e($te).'")';
        if (preg_match($timeRe, $ts) && preg_match($timeRe, $te) && $ts >= $te) $rowErrs[] = 'time_start ('.$ts.') must be strictly earlier than time_end ('.$te.') on the same day';
        $ef = isset($row['effective_from']) && (string)$row['effective_from'] !== '' ? trim((string)$row['effective_from']) : null;
        $et = isset($row['effective_to'])   && (string)$row['effective_to']   !== '' ? trim((string)$row['effective_to'])   : null;
        if ($ef !== null && !preg_match($dateRe, $ef)) $rowErrs[] = 'effective_from must be blank or YYYY-MM-DD (got "'.e($ef).'")';
        if ($et !== null && !preg_match($dateRe, $et)) $rowErrs[] = 'effective_to must be blank or YYYY-MM-DD (got "'.e($et).'")';
        if ($ef !== null && $et !== null && preg_match($dateRe,$ef) && preg_match($dateRe,$et) && $ef > $et) $rowErrs[] = 'effective_from ('.$ef.') must be earlier than or equal to effective_to ('.$et.')';
        if (!empty($rowErrs)) { $schedErrors[] = "Schedule row ".($idx+1).": ".implode('; ',$rowErrs); continue; }
        $cleanedRows[] = [
            'day_of_week'    => $dow,
            'waste_type'     => $wt,
            'time_start'     => $ts,
            'time_end'       => $te,
            'effective_from' => $ef,
            'effective_to'   => $et,
        ];
    }

    if (!empty($schedErrors)) {
        return ['ok' => false, 'cleaned' => [], 'error' => 'Invalid schedule rows: '.implode(' | ',$schedErrors)];
    }
    return ['ok' => true, 'cleaned' => $cleanedRows, 'error' => null];
}

/**
 * Single source of truth for a curbside collection window.
 *
 * ba_collection_schedules stores time_start/time_end as TIME plus exactly one
 * date -- collection_date for one_time/exception, day_of_week for weekly. There
 * is no end-date column, so a window is same-day by construction: "ends 7:01 am
 * the next day" is not representable, and the only thing left to police is that
 * the end is strictly later than the start.
 *
 * save_schedule in admin/api/basuraalert_admin.php validated barangay, waste
 * type, schedule type, status and day-of-week and then wrote the row, never
 * looking at either time. 06:58 -> 06:58 and 10:46 -> 01:46 both saved (rows
 * 403, 339, 340 in the local DB). ba_validate_dropoff_schedules() above already
 * enforces this for drop-off range rows; this is the same rule for the curbside
 * table, so both are one function apart instead of one copy each.
 *
 * @return string|null Error message, or null when the window is valid.
 */
function ba_validate_collection_window(?string $timeStart, ?string $timeEnd): ?string
{
    $rawStart = trim((string) ($timeStart ?? ''));
    $rawEnd = trim((string) ($timeEnd ?? ''));

    // No window at all is a legitimate "times not specified" state, and every
    // existing row predating this check may be in it.
    if ($rawStart === '' && $rawEnd === '') {
        return null;
    }
    // Half a window is not. Otherwise clearing the end field would be a way to
    // dodge the ordering rule entirely.
    if ($rawStart === '' || $rawEnd === '') {
        return 'Start time and End time must both be filled in — a collection window needs both.';
    }

    $start = ba_time_to_seconds($rawStart);
    if ($start === null) {
        return 'Start time is not a valid time of day (got "' . $rawStart . '"). Use HH:MM.';
    }
    $end = ba_time_to_seconds($rawEnd);
    if ($end === null) {
        return 'End time is not a valid time of day (got "' . $rawEnd . '"). Use HH:MM.';
    }

    if ($end <= $start) {
        return 'End time must be later than Start time — a collection window cannot be zero-length or cross midnight.';
    }
    return null;
}

/** Seconds since midnight for HH:MM or HH:MM:SS, or null if unparseable. */
function ba_time_to_seconds(?string $time): ?int
{
    $t = trim((string) ($time ?? ''));
    if ($t === '') return null;
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) return null;
    $hours = (int) $m[1];
    $minutes = (int) $m[2];
    $seconds = (int) ($m[3] ?? 0);
    if ($hours > 23 || $minutes > 59 || $seconds > 59) return null;
    return ($hours * 3600) + ($minutes * 60) + $seconds;
}

function ba_validate_operation_hours(string $operationHours, bool $open247): ?string
{
    // Returns null if valid, error string if invalid
    if ($open247) {
        return null; // Open 24/7 accepts anything (including blank)
    }
    $hrs = trim($operationHours);
    if ($hrs === '') {
        return null; // Blank is handled by caller (may be allowed depending on context); here we don't enforce non-blank
    }
    $clean = preg_replace('/[–—]/u', '-', $hrs);
    $clean = preg_replace('/\s+/u', ' ', (string)$clean);
    $clean = trim((string)$clean);

    // Rule #1: MUST contain a time RANGE (two times with dash)
    $hasTimeRange = (bool) preg_match('/(?:^|[^0-9])\s*\d{1,2}(?::\d{2})?\s*(?:[AaPp][Mm]?|NN)?\s*[-–](?:to|until)?\s*\d{1,2}(?::\d{2})?\s*(?:[AaPp][Mm]?|NN)?(?:$|[^0-9])/', $clean);
    if (!$hasTimeRange) {
        return 'Invalid Custom Operation Hours format. Required pattern: Day(s): Time Start - Time End (two times with a dash). Examples: "Mon–Fri: 8:00 AM - 5:00 PM", "09:00-17:00", "Mon,Wed,Fri: 7:00 AM-10:00 AM ; Sat: 9:00 AM-12:00 NN". Single time only (no end time), random letters like "zxdscfddvghjmk"/"ewan", or phrases without a proper range are not allowed.';
    }
    // Rule #2: Strip allowed tokens, reject if remaining is pure letters (garbage)
    $stripWordsRe = '/\b(Sun|Mon|Tue|Wed|Thu|Fri|Sat|Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Daily|Every\s*day|24\s*\/?\s*7|24\s*hrs|AM|PM|NN|PH|Holiday|Holidays|to|until|before|after|open|closed|hrs?|hours?|only|mall|market|mass|bins?|locked|overnight|before|after|daily)\b/iu';
    $stripped = preg_replace($stripWordsRe, '', $clean);
    $stripped = preg_replace('/[^A-Za-z0-9]/u', '', (string)$stripped);
    $looksLikePureLetters = ($stripped !== '' && !preg_match('/[0-9]/', (string)$stripped));
    if ($looksLikePureLetters) {
        return 'Invalid Custom Operation Hours format. Random letters or free-form phrases without a proper time range are blocked. Required: Day(s): StartTime - EndTime (e.g. "Mon–Fri: 8:00 AM - 5:00 PM", "09:00-17:00").';
    }
    return null;
}

function ba_validate_operation_hours_strict(string $operationHours, bool $open247, bool $requireNonBlank = false): ?string
{
    if (!$open247 && $requireNonBlank && trim($operationHours) === '') {
        return 'Hours & Days of Operation — either turn ON the Open 24/7 chip, OR use "+ Add range" to build at least one custom hours range (Day range + Start time + End time).';
    }
    return ba_validate_operation_hours($operationHours, $open247);
}
