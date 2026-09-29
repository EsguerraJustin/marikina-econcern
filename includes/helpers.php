<?php

declare(strict_types=1);

// Asia/Manila is UTC+8 with NO DST transitions since 1990; this lock avoids
// any system-default timezone (e.g. UTC) leaking into date()/strtotime()
// calls used by schedule start/end / effective_from/to date comparisons.
if (!ini_get('date.timezone') || @date_default_timezone_get() !== 'Asia/Manila') {
    @date_default_timezone_set('Asia/Manila');
}

require_once __DIR__ . '/db.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_url(): string
{
    if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
        return str_replace(' ', '%20', rtrim(APP_BASE_URL, '/'));
    }
    return '';
}

function app_url(string $path): string
{
    $path = '/' . ltrim($path, '/');
    return str_replace(' ', '%20', app_base_url() . $path);
}

function app_public_url(string $path): string
{
    $path = '/' . ltrim($path, '/');
    if (defined('APP_PUBLIC_URL') && APP_PUBLIC_URL !== '') {
        return str_replace(' ', '%20', rtrim(APP_PUBLIC_URL, '/') . $path);
    }
    $scheme = 'http';
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['REQUEST_SCHEME']) && is_string($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] !== '') {
        $scheme = $_SERVER['REQUEST_SCHEME'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
        if (str_starts_with($proto, 'https')) {
            $scheme = 'https';
        }
    }
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    return str_replace(' ', '%20', $scheme . '://' . $host . app_base_url() . $path);
}

/* Resolves a stored photo reference to a browser-usable URL.
 *
 * Must be IDEMPOTENT. ba_store_image_mock() (includes/BasuraAlert/External.php:727)
 * already returns an app_url() value, i.e. it is ALREADY prefixed with the base
 * path, and that prefixed string is what gets written into ba_reports.photos_json.
 * The previous implementation prepended app_base_url() unconditionally, so every
 * new upload resolved to
 *   /base/base/base/storage/mock_images/x.jpg
 * i.e. a 404. The three legacy rows in the DB store a bare "/uploads/..." path and
 * were unaffected, which is why this survived: the QA harness
 * storage/qa_harnesses/_qa_photo_url_resolver_fix.php exercises this function in
 * isolation with a bare path and never against the real store output.
 *
 * Accepts, and returns unchanged: absolute http(s) URLs, protocol-relative URLs,
 * and anything already carrying the base prefix. Prefixes only a bare path. */
function ba_resolve_photo_url(string $storedUrl): string
{
    $trimmed = trim($storedUrl);
    if ($trimmed === '') {
        return '';
    }
    if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
        return $trimmed;
    }
    if (str_starts_with($trimmed, '//')) {
        return $trimmed;
    }
    $base = app_base_url();
    if ($base !== '' && str_starts_with($trimmed, $base . '/')) {
        return $trimmed;   // already prefixed — do not double it
    }
    return $base . '/' . ltrim($trimmed, '/');
}

/**
 * Reduce a redirect target to something that cannot leave this origin.
 *
 * redirect() used to pass its argument straight into a Location header, and
 * require_csrf_token() called it with $_SERVER['HTTP_REFERER'] BEFORE the user
 * had authenticated. Anyone could therefore send a victim to
 * /public/login.php (a real page, so the link looks legitimate) and have the
 * app bounce them onward to an attacker-controlled site - a working phishing
 * primitive that needed no malformed URL to be trusted.
 *
 * Rules:
 *  - empty, or containing control characters  -> fallback
 *  - protocol-relative //host, and its backslash form /\host -> fallback
 *  - absolute http(s) URL                      -> allowed only if same host
 *  - anything not rooted at /                  -> fallback
 *
 * @param string|null $fallback Defaults to the app root.
 */
function safe_redirect_target(string $candidate, ?string $fallback = null): string
{
    $fallback = ($fallback !== null && $fallback !== '') ? $fallback : app_url('/');
    $c = trim($candidate);

    if ($c === '') {
        return $fallback;
    }
    // Header injection / smuggling: CR, LF, NUL and friends.
    if (preg_match('/[\x00-\x1F\x7F]/', $c) === 1) {
        return $fallback;
    }

    // Browsers treat a backslash as a path separator, so "/\evil.com" and
    // "\\evil.com" both navigate off-origin. Normalise before testing.
    $probe = str_replace('\\', '/', $c);

    if (str_starts_with($probe, '//')) {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $probe) === 1) {
        $host = parse_url($probe, PHP_URL_HOST);
        $self = parse_url(app_url('/'), PHP_URL_HOST);
        if (!is_string($host) || !is_string($self) || strcasecmp($host, $self) !== 0) {
            return $fallback;
        }
        return $probe;
    }

    // Anything left must be a rooted, same-origin path.
    if (!str_starts_with($probe, '/')) {
        return $fallback;
    }
    return $probe;
}

function redirect(string $path): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . safe_redirect_target($path));
    exit;
}

function generate_report_number(mysqli $mysqli): string
{
    $yy = date('y');

    $stmt = $mysqli->prepare('SELECT report_number FROM concerns WHERE report_number LIKE CONCAT(?, "%") ORDER BY id DESC LIMIT 1');
    $prefix = 'EC-' . $yy . '-';
    db_prepared_execute($stmt, 's', [$prefix]);
    $result = $stmt->get_result();
    $last = $result ? ($result->fetch_assoc()['report_number'] ?? null) : null;
    $stmt->close();

    $nextSeq = 1;
    if (is_string($last) && preg_match('/^EC-\d{2}-(\d{5})$/', $last, $m)) {
        $nextSeq = ((int) $m[1]) + 1;
    }

    return $prefix . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
}

function ensure_upload_dir(string $path): void
{
    if (!is_dir($path)) {
        // 0755 is sufficient — 0755 is world-writable and flagged by security scanners.
        @mkdir($path, 0755, true);
        // Harden: ensure correct perms even if dir existed with wrong mode
        @chmod($path, 0755);
    }
}

function validate_upload_file(array $file, array $allowedMimes, int $maxBytes, string $fieldName = 'file'): array
{
    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit (MAX_FILE_SIZE).',
            UPLOAD_ERR_PARTIAL    => 'Upload was interrupted — please retry.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write upload to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        return ['ok' => false, 'error' => $msgs[$code] ?? 'Upload failed (code ' . $code . ').'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload temp file.'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'Empty file not allowed.'];
    }
    if ($size > $maxBytes) {
        $mb = round($maxBytes / 1048576, 1);
        return ['ok' => false, 'error' => $fieldName . ' exceeds ' . $mb . ' MB limit.'];
    }
    // MIME via finfo — more reliable than $_FILES['type'] (client-supplied)
    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $tmp);
            @finfo_close($finfo);
        }
    }
    if (!is_string($mime) || $mime === '') {
        $mime = (string)($file['type'] ?? 'application/octet-stream');
    }
    // Normalize jpeg variants
    if ($mime === 'image/jpg') $mime = 'image/jpeg';
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'error' => 'Invalid file type (' . $mime . '). Allowed: ' . implode(', ', $allowedMimes)];
    }
    // Extension sanity check — block double-extension tricks
    $ext = strtolower((string) pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $dangerous = ['php','phtml','phar','inc','cgi','pl','exe','sh','bat','cmd'];
    if (in_array($ext, $dangerous, true)) {
        return ['ok' => false, 'error' => 'File extension .' . $ext . ' is not allowed.'];
    }
    return ['ok' => true, 'mime' => $mime, 'ext' => $ext, 'size' => $size, 'tmp' => $tmp];
}

function validate_password_rules(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain a lowercase letter.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain an uppercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain a number.';
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $errors[] = 'Password must contain a symbol.';
    }
    return $errors;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
        if (function_exists('ensure_session_started')) {
            ensure_session_started();
        } else {
            @session_start();
        }
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_token_name(): string
{
    return 'csrf_token';
}

function require_csrf_token(): void
{
    if (PHP_SAPI === 'cli') return;
    csrf_check();
}

function csrf_field(): string
{
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '" autocomplete="off">';
}

function csrf_header_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (function_exists('ensure_session_started')) {
            ensure_session_started();
        } else {
            @session_start();
        }
    }

    $token = null;
    if (!empty($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    } else {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if ($headers === false) $headers = [];
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-CSRF-Token') === 0 || strcasecmp($k, 'X-CSRFToken') === 0) {
                $token = is_string($v) ? $v : null;
                break;
            }
        }
        if ($token === null && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
    }

    $sessionToken = !empty($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    $valid = is_string($token) && $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);

    if (!$valid) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (is_string($accept) && (stripos($accept, 'application/json') !== false || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')) {
            /* 403, not 419. 419 is an unassigned IANA code and this Apache build
               does not know it: http_response_code(419) came back over the wire
               as 500 (verified with a throwaway probe across 200/201/400/401/
               403/404/409/413/419/422/500 - every other code passed through).
               A 500 made the client show a generic "network error" and hid the
               fact that the request had been rejected for a missing token.
               403 is the standard code for "understood, refused" and travels
               reliably; callers distinguish a CSRF failure from a 403 raised by
               require_super_admin() using the 'hint' below. */
            json_response([
                'ok' => false,
                'error' => 'Invalid or missing security token. Please refresh the page and try again.',
                'hint' => 'csrf_mismatch',
            ], 403);
        }
        $_SESSION['flash_error'] = 'Invalid or missing security token. Please refresh the page and try again.';
        $redirectTo = $_SERVER['HTTP_REFERER'] ?? app_url('/');
        if (!is_string($redirectTo) || $redirectTo === '') $redirectTo = app_url('/');
        redirect($redirectTo);
    }

    if (!(isset($_SESSION['csrf_used']) && is_array($_SESSION['csrf_used']) && in_array($sessionToken, $_SESSION['csrf_used'], true))) {
        if (!isset($_SESSION['csrf_used']) || !is_array($_SESSION['csrf_used'])) {
            $_SESSION['csrf_used'] = [];
        }
        $_SESSION['csrf_used'][] = $sessionToken;
        if (count($_SESSION['csrf_used']) > 16) {
            $_SESSION['csrf_used'] = array_slice($_SESSION['csrf_used'], -16);
        }
    }
}

/**
 * Guard for the root-level maintenance scripts.
 *
 * These are cron workers that were also reachable from a browser through a
 * "Run now" form: ba_dispatch_queued_notifications.php?run=1 and
 * ba_run_reminders.php?run=1 both returned HTTP 200 with no session at all, so
 * anyone who guessed the URL could fire real SMS and real email at real
 * citizens, repeatedly and for free. _run_basuraalert_migration.php?confirm=1
 * did the same to the schema (ALTER TABLE), and
 * _migrate_ba_reports_category_varchar.php had no guard whatsoever.
 *
 * CLI stays open, because that is how cron invokes them and it cannot be
 * reached over HTTP. The browser path is kept for demos, but now requires a
 * logged-in super admin. Passing $admin as null makes the check CLI-only, which
 * is what the two migration scripts want: there is no reason to run a schema
 * migration from a web page.
 *
 * @param array|null $admin Current admin row, or null for CLI-only
 */
function require_maintenance_authorization(?array $admin, string $label): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if ($admin === null) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden\n\n" . $label . " is a command-line maintenance script.\n"
            . "Run it from a shell or cron, not over HTTP.\n";
        exit;
    }
    if (($admin['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden\n\n" . $label . " requires a Super Admin session.\n";
        exit;
    }
}

/**
 * Write a row to admin_activity_log.
 *
 * The table (action / target_type / target_id / old_value / new_value /
 * ip_address / user_agent) already existed but nothing in the codebase ever
 * wrote to it, so privileged actions were invisible after the fact. This is the
 * single writer for it.
 *
 * $changes is normally a field => ['from' => x, 'to' => y] map, which is split
 * across the two TEXT columns so they actually mean something:
 *   old_value = {"email":"old@x.com","mobile":"0917..."}
 *   new_value = {"email":"new@x.com","mobile":"+63917...","__note":"..."}
 * A flat field => scalar map is also accepted and is recorded as a change from
 * NULL, which is what it means when the caller did not capture a before-state.
 *
 * Returns false on failure rather than throwing: an audit-log write must never
 * be the reason a legitimate update is reported to the user as failed, but the
 * failure is surfaced via error_log so it is not silent either.
 *
 * @param mixed $extra Optional scalar merged into the new_value payload, for
 *                     things worth recording that are not a field change
 *                     (e.g. whether a security-relevant default was overridden).
 */
function mc_admin_activity_log(mysqli $db, int $adminId, string $action, ?string $targetType, ?int $targetId, array $changes, $extra = null): bool
{
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $ip = substr($ip, 0, 45);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
        ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255)
        : null;

    $oldState = [];
    $newState = [];
    foreach ($changes as $field => $delta) {
        if (is_array($delta) && array_key_exists('from', $delta) && array_key_exists('to', $delta)) {
            $oldState[$field] = $delta['from'];
            $newState[$field] = $delta['to'];
        } else {
            $oldState[$field] = null;
            $newState[$field] = $delta;
        }
    }
    if ($extra !== null) {
        $newState['__note'] = $extra;
    }

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $oldJson = json_encode($oldState, $jsonFlags);
    $newJson = json_encode($newState, $jsonFlags);
    if (!is_string($oldJson) || !is_string($newJson)) {
        // json_encode failed outright; store a marker rather than losing the
        // row entirely, since the fact that the action happened matters most.
        $oldJson = json_encode(['__encode_error' => true]);
        $newJson = json_encode(['__encode_error' => true]);
    }

    $stmt = $db->prepare(
        'INSERT INTO admin_activity_log
            (admin_id, action, target_type, target_id, old_value, new_value, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        @error_log('[admin_activity_log] prepare failed: ' . $db->error, 3, STORAGE_DIR . '/../app_error.log');
        return false;
    }
    // Types must line up positionally with the column list above:
    //   admin_id i | action s | target_type s | target_id i
    //   old_value s | new_value s | ip_address s | user_agent s
    // which is "ississss". Getting the i/s at 4 and 5 the wrong way round
    // binds old_value as an integer, which silently stores the audit payload
    // as the integer 0 while the write still reports success.
    $ok = db_prepared_execute($stmt, 'ississss', [
        $adminId,
        substr($action, 0, 60),
        $targetType !== null ? substr($targetType, 0, 30) : null,
        $targetId,
        $oldJson,
        $newJson,
        $ip,
        $ua,
    ]);
    $stmt->close();

    if (!$ok) {
        @error_log('[admin_activity_log] insert failed: ' . $db->error, 3, STORAGE_DIR . '/../app_error.log');
    }
    return (bool) $ok;
}
