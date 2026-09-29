<?php
declare(strict_types=1);

/**
 * BasuraAlert — file-backed cache
 *
 * Every third-party integration in this project sits on a metered free tier:
 * Calendarific allows roughly one call per day and OpenWeather a thousand per
 * day, while three dashboards read holidays and weather on every page render.
 * Without a cache the quota is gone within minutes and the app silently drops
 * back to its fixtures - which is indistinguishable from "working" on screen.
 *
 * Storage is storage/cache/*.json. Two properties make that the right home:
 *
 *   - .htaccess blocks ^storage/(?!mock_images/), so a cache file cannot be
 *     fetched over HTTP and cannot be poisoned by a visitor. Filesystem reads
 *     bypass mod_rewrite, so the code below still reads it normally.
 *   - No schema change, so deploying needs no phpMyAdmin import.
 *
 * The directory is created on demand rather than shipped, because
 * bin/build_deploy_zip.php excludes storage/cache/ and a bare directory with no
 * .gitkeep would not survive the archive.
 */

function ba_cache_dir(): string
{
    $base = defined('STORAGE_DIR') ? STORAGE_DIR : (__DIR__ . '/../../storage');
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'cache';
}

/**
 * Absolute path for a cache entry. The name is restricted to a safe character
 * set because it becomes a filename; callers interpolate coordinates into it.
 */
function ba_cache_path(string $name): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    $safe = is_string($safe) ? trim($safe, '._-') : 'entry';
    if ($safe === '') {
        $safe = 'entry';
    }
    return ba_cache_dir() . DIRECTORY_SEPARATOR . $safe . '.json';
}

/**
 * Read an entry only if it is younger than $ttlSeconds. Returns null on a miss,
 * an expired entry, an unreadable file or malformed JSON - callers treat all
 * four the same way (go to the live API), so corruption degrades to a slow
 * response rather than a wrong answer.
 */
function ba_cache_read(string $name, int $ttlSeconds): ?array
{
    $path = ba_cache_path($name);
    // PHP memoises stat results for the life of a request. Without this, a
    // file written earlier in the same request keeps reporting its pre-write
    // mtime, so an entry whose TTL has just elapsed still reads as fresh and
    // the expiry check silently does nothing.
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return null;
    }
    $mtime = @filemtime($path);
    if ($mtime === false) {
        return null;
    }
    $age = time() - (int) $mtime;
    // A future mtime (clock skew between machines writing a shared mount, or a
    // restored backup) computes to a negative age, which every comparison here
    // treats as fresh - pinning the entry until wall-clock time catches up. A
    // future timestamp is not evidence of freshness, so clamp it to expired.
    if ($age < 0) {
        return null;
    }
    // $ttlSeconds of 0 or less means "no expiry" rather than "always expired",
    // so a caller can deliberately pin an entry.
    if ($ttlSeconds > 0 && $age >= $ttlSeconds) {
        return null;
    }
    $entry = ba_cache_decode($path);
    if ($entry === null) {
        return null;
    }
    $entry['_cache'] = ['hit' => true, 'age' => $age, 'path' => $path];
    return $entry;
}

/**
 * Read an entry regardless of age. This is the fallback that keeps a live API
 * outage from silently swapping real data for fixtures: if the network is down
 * we serve slightly stale real data and label it as such.
 *
 * The file's mtime is refreshed on a successful read (see _cache['touched']) so
 * a provider that stays down costs one connect timeout per TTL rather than one
 * per page view. That matters because the failure mode must not be the most
 * expensive one.
 *
 * The one concession is correctness: the entry stays flagged 'stale' and
 * keeps its original _cached_at, so nothing downstream can mistake a recovered
 * outage for a fresh reading. Only the retry schedule is affected, not the
 * provenance.
 */
function ba_cache_read_stale(string $name): ?array
{
    $path = ba_cache_path($name);
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return null;
    }
    $entry = ba_cache_decode($path);
    if ($entry === null) {
        return null;
    }
    $mtime = @filemtime($path);
    $age = $mtime === false ? null : max(0, time() - (int) $mtime);
    $entry['_cache'] = [
        'hit' => true,
        'stale' => true,
        'age' => $age,
        // Refreshing mtime here is deliberate and was a real bug. Reading a
        // stale entry without touching its timestamp leaves it permanently
        // expired, so EVERY subsequent page view pays the full connect timeout
        // against a metered API - the outage path was the worst case, not the
        // best. Touching the file gives the caller a grace window to recover
        // before the next live attempt.
        'touched' => @touch($path),
        'path' => $path,
    ];
    return $entry;
}

function ba_cache_write(string $name, array $payload): bool
{
    $dir = ba_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    if (!is_writable($dir)) {
        return false;
    }
    $payload['_cached_at'] = date('c');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }
    // Write to a sibling temp file and rename over the target. A rename within
    // the same directory is atomic, so a concurrent reader never sees a
    // half-written file - which matters because three dashboards can render
    // concurrently and would otherwise race a plain file_put_contents.
    $path = ba_cache_path($name);
    // getmypid() can be null on some SAPIs, which would collapse every
    // concurrent writer onto one shared temp name. random_bytes makes the
    // collision probability negligible without depending on the SAPI.
    $uniq = substr(bin2hex(random_bytes(6)), 0, 12);
    $tmp = $path . '.' . $uniq . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0644);
    return true;
}

/**
 * Try to become the single process that refreshes a cache entry.
 *
 * The TTL only bounds SEQUENTIAL refreshes. Without this, N simultaneous
 * requests that all miss see an empty entry and all call the live API: 8
 * concurrent renders meant 8 OpenWeather calls, and against Calendarific a cold
 * cache after deploy would burn its whole monthly allowance in one burst.
 *
 * The wait is bounded and non-blocking on acquisition. A caller that does NOT
 * get the lock should skip the live call entirely and fall through to whatever
 * is on disk (stale data or labelled fixtures) - blocking instead would make a
 * slow provider serialise every page view behind one connect timeout.
 *
 * The lock is returned ONLY if the caller genuinely needs to fetch. After
 * winning the lock the entry is re-checked, because a process that waited may
 * have been queued behind a fetch that already succeeded. Without that re-check
 * the waiter wakes up, wins the lock and immediately spends a second call on
 * data that is already on disk - measured at 3 live fetches for 8 concurrent
 * cold-cache renders, versus 1 with the re-check.
 *
 * $ttlSeconds is the entry's own TTL, used only for that post-lock re-check.
 *
 * Returns a lock handle to pass to ba_cache_release_refresh(), or false when
 * the caller should fall back instead of fetching. Nothing is left locked in
 * the false case.
 */
function ba_cache_claim_refresh(string $name, int $ttlSeconds = 0, float $waitSeconds = 2.0)
{
    $dir = ba_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;   // no lock possible; caller falls back rather than burning quota
    }
    $fh = @fopen(ba_cache_path($name) . '.lock', 'c');
    if ($fh === false) {
        return false;
    }
    $deadline = microtime(true) + max(0.0, $waitSeconds);
    do {
        if (@flock($fh, LOCK_EX | LOCK_NB)) {
            // We hold the lock, but whoever held it before may have just
            // populated the entry we came here to fill.
            if (ba_cache_read($name, $ttlSeconds) !== null) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
                return false;
            }
            return $fh;   // caller owns the refresh; must release when done
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    @fclose($fh);
    return false;
}

function ba_cache_release_refresh($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function ba_cache_decode(string $path): ?array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Introspection for admin/integration_status.php. Never throws and never
 * touches the network - it only reports what is on disk right now.
 */
function ba_cache_inspect(string $name): array
{
    $path = ba_cache_path($name);
    clearstatcache(true, $path);
    $out = ['name' => $name, 'path' => $path, 'exists' => false, 'age' => null, 'size' => null, 'valid' => false];
    if (!is_file($path)) {
        return $out;
    }
    $out['exists'] = true;
    $mtime = @filemtime($path);
    $out['age'] = $mtime === false ? null : time() - (int) $mtime;
    $out['size'] = @filesize($path) ?: null;
    $out['valid'] = ba_cache_decode($path) !== null;
    return $out;
}

/**
 * Every cache entry currently on disk, newest first.
 *
 * Lock files and in-flight .tmp files are excluded: they are machinery, not
 * data, and listing them would report phantom entries. Negative ages (a future
 * mtime from clock skew) are clamped to 0 for the same reason ba_cache_read
 * treats them as not-fresh.
 */
function ba_cache_list(): array
{
    $dir = ba_cache_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $items = [];
    foreach ((array) @scandir($dir) as $file) {
        if (!is_string($file) || !str_ends_with($file, '.json')) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        $items[] = [
            'name' => substr($file, 0, -5),
            'age' => $mtime === false ? null : max(0, time() - (int) $mtime),
            'size' => @filesize($path) ?: null,
        ];
    }
    usort($items, static fn(array $a, array $b): int => ($a['age'] ?? PHP_INT_MAX) <=> ($b['age'] ?? PHP_INT_MAX));
    return $items;
}
