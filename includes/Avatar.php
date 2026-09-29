<?php

declare(strict_types=1);

/* =========================================================================
   Profile avatars (Cloudinary)
   -------------------------------------------------------------------------
   Reuses the project's existing, already-working Cloudinary plumbing in
   includes/BasuraAlert/External.php:
     - ba_cloudinary_sign()          signed-request helper
     - ba_cloudinary_upload_file()   server-side signed upload
   No new HTTP client, no new credential handling, no new signing code.

   WHY SIGNED AND NOT THE UNSIGNED PRESET
   config.php defines CLOUDINARY_UPLOAD_PRESET ('marikina_concern_unsigned').
   An unsigned preset is a bearer token: anyone who reads the preset string can
   upload arbitrary files into this Cloudinary account with no secret. Avatars
   are personal data, so every upload here is a signed server-side POST using
   the API secret. The preset is deliberately not used.

   WHY A DETERMINISTIC public_id
   ba_cloudinary_upload_file() generates a RANDOM public_id per call
   (External.php:610), which is right for evidence photos (every upload is a
   distinct artifact) but wrong for an avatar: replacing a profile picture would
   orphan the previous asset in the account forever. Here the id is derived from
   the row id, and overwrite=true, so a re-upload replaces in place.

   WHY STORE public_id AND NOT THE FINAL URL
   The display URL is built by mc_avatar_url() with a Cloudinary URL
   transformation, so only a small face-cropped derivative is ever served, and
   the size/crop can be re-tuned later with no re-upload.

   WHY avatar_url EXISTS AT ALL
   An earlier version of this comment claimed avatar_url "keeps the
   admin/citizen lists cheap". That was not true and has been removed: the
   admin and citizen lists build their display URLs from avatar_public_id
   (admin/citizens.php and admin/citizen_view.php call the JS avatarUrl()
   helper, which applies a Cloudinary transformation to the public_id), and
   nothing outside this file reads avatar_url.

   It is kept because it is the only record of WHICH asset version was stored.
   secure_url carries a version segment - .../upload/v1790492118/<id> - that
   cannot be reconstructed from public_id alone, so this is the provenance of a
   given avatar and the thing to quote when diagnosing a CDN or transformation
   problem.

   IT IS NOW READ, and it is load-bearing rather than merely diagnostic. The
   public_id is deterministic (admin_<id> / citizen_<id>) with overwrite=true, so
   replacing a photo replaces the asset IN PLACE and the delivery URL built from
   public_id alone is byte-identical before and after. The browser then treats the
   new src as the copy it already has, serves it from cache, and never fetches the
   asset that was just uploaded: the page reported "Profile photo updated." while
   continuing to show the old photo. Measured in Chrome, two different uploads in
   one session produced 0 Cloudinary requests and an unchanged img src.
   mc_avatar_version_from_url() pulls the version back out of this column and
   mc_avatar_url() folds it into the delivery path, so the URL changes whenever the
   asset does. A version must be a PATH segment, not a query string - Cloudinary
   returns HTTP 404 for .../<id>?v=2 (verified against the live API).
   ========================================================================= */

/** Avatar-specific limits. Tighter than the 5 MB evidence-photo cap. */
const MC_AVATAR_MAX_BYTES      = 2 * 1024 * 1024;   // 2 MB
const MC_AVATAR_MAX_DIMENSION  = 4000;               // px, reject absurd input early
const MC_AVATAR_DISPLAY_SIZE   = 256;                // px, the derivative we serve
const MC_AVATAR_ALLOWED_MIME   = ['image/jpeg', 'image/png', 'image/webp'];

/* External.php supplies ba_cloudinary_upload_file(), ba_cloudinary_sign() and
   the CLOUDINARY_* constants this file depends on. It is required HERE rather
   than left to the caller: admin/api/citizen.php reaches this file without
   loading includes/basuraalert.php first, and without this line every upload
   from that endpoint failed with the misleading "Upload support is unavailable."
   while the identical upload worked from the two profile endpoints purely
   because something else happened to pull External.php in first. */
if (!function_exists('ba_cloudinary_upload_file')) {
    require_once __DIR__ . '/BasuraAlert/External.php';
}

if (!function_exists('mc_avatar_cloud_ready')) {
    function mc_avatar_cloud_ready(): bool
    {
        return defined('CLOUDINARY_CLOUD_NAME') && CLOUDINARY_CLOUD_NAME !== ''
            && defined('CLOUDINARY_API_KEY') && CLOUDINARY_API_KEY !== ''
            && defined('CLOUDINARY_API_SECRET') && CLOUDINARY_API_SECRET !== '';
    }
}

/**
 * Deterministic, BARE Cloudinary public_id for an account, e.g. "admin_1".
 *
 * Bare on purpose. ba_cloudinary_upload_file() applies its own default folder
 * ('marikina_concern/uploads') and Cloudinary PREPENDS the folder to public_id,
 * so passing an already-prefixed id yields a doubled path. Passing folder => ''
 * instead is worse: ba_cloudinary_sign() omits empty values from the
 * string-to-sign while Cloudinary still includes `folder=`, so every upload
 * fails with HTTP 401 "Invalid Signature" (verified against the live API).
 *
 * We therefore upload under this bare id and persist the FULL public_id that
 * Cloudinary returns, which already contains the folder. Because the id is
 * derived from the row id and overwrite=true, re-uploading replaces the asset
 * in place rather than orphaning the previous one - which is exactly what the
 * random-suffix default would have done on every avatar change.
 */
function mc_avatar_public_id(string $table, int $rowId): string
{
    $table = ($table === 'admins') ? 'admin' : 'citizen';
    return $table . '_' . $rowId;
}

/**
 * Extract the Cloudinary asset version from a stored avatar_url.
 *
 * secure_url looks like .../image/upload/v1790501024/<public_id>, and that
 * version segment is the ONLY thing that changes when an avatar is replaced -
 * see mc_avatar_url() for why that matters. Returns '' when there is no version
 * to be had (empty url, a hand-entered URL, or a legacy row).
 */
function mc_avatar_version_from_url(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    // Matches the v<digits> path segment only. A query string is deliberately
    // NOT accepted: Cloudinary 404s on .../<id>?v=2 (verified against the live
    // API), so a cache-buster query param would break the image outright. The
    // version has to travel as a path segment.
    if (preg_match('#/image/upload/(?:[^/]+/)*v(\d+)(?:/|$)#', $url, $m)) {
        return 'v' . $m[1];
    }
    return '';
}

/**
 * Build a display URL from a stored public_id.
 * c_thumb,g_face crops to a square centred on the detected face and falls back
 * to a centre crop, so a portrait is usable as a 40px list avatar and as a
 * 256px hero without storing two files.
 *
 * $version is the asset version (see mc_avatar_version_from_url()). It is
 * REQUIRED in practice, because the public_id is deterministic: replacing a
 * photo overwrites the asset in place, so the delivery URL is otherwise
 * byte-identical before and after. The browser then treats the new src as the
 * one it already has, serves the cached copy, and the freshly uploaded photo
 * never appears - the page reports success and shows the old image. Passing
 * the version makes the URL change, which forces the refetch.
 *
 * Omit it and you get the old frozen URL, so every row that predates the
 * version column keeps rendering exactly as before.
 */
function mc_avatar_url(?string $publicId, string $transformation = '', string $version = ''): string
{
    $publicId = trim((string) $publicId);
    if ($publicId === '') {
        return '';
    }
    if (stripos($publicId, 'http://') === 0 || stripos($publicId, 'https://') === 0) {
        // Already a URL (legacy row or manual entry) - hand it back untouched.
        return $publicId;
    }
    if (!defined('CLOUDINARY_CLOUD_NAME') || CLOUDINARY_CLOUD_NAME === '') {
        return '';
    }
    $size  = (int) MC_AVATAR_DISPLAY_SIZE;
    $trans = trim($transformation) !== ''
        ? $transformation
        : 'c_thumb,g_face,w_' . $size . ',h_' . $size . ',q_auto,f_auto';

    $version = trim($version);
    if ($version !== '' && !preg_match('/^v\d+$/', $version)) {
        $version = '';   // never let a stray value corrupt the delivery path
    }

    return 'https://res.cloudinary.com/' . CLOUDINARY_CLOUD_NAME
        . '/image/upload/' . $trans . '/' . ($version !== '' ? $version . '/' : '') . $publicId;
}

/** Initials for the fallback tile, e.g. "Justin Curby Esguerra" -> "JE". */
function mc_avatar_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1, 'UTF-8');
    $last  = (count($parts) > 1) ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';
    return mb_strtoupper($first . $last, 'UTF-8');
}

/** Read the current avatar columns for a row. Returns [public_id, url]. */
function mc_avatar_get(mysqli $db, string $table, int $rowId): array
{
    $table = ($table === 'admins') ? 'admins' : 'users';
    $stmt = $db->prepare("SELECT avatar_public_id, avatar_url FROM `$table` WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['', ''];
    }
    db_prepared_execute($stmt, 'i', [$rowId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row)) {
        return ['', ''];
    }
    return [(string) ($row['avatar_public_id'] ?? ''), (string) ($row['avatar_url'] ?? '')];
}

/** Persist the avatar columns for a row. */
function mc_avatar_save(mysqli $db, string $table, int $rowId, string $publicId, string $url): bool
{
    $table = ($table === 'admins') ? 'admins' : 'users';
    $stmt = $db->prepare("UPDATE `$table` SET avatar_public_id = ?, avatar_url = ? WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $ok = db_prepared_execute($stmt, 'sss', [$publicId, $url, $rowId]);
    $stmt->close();
    return (bool) $ok;
}

/** Does the target row exist? Checked before any upload so a bad id cannot
 *  create a Cloudinary asset that nothing will ever reference. */
function mc_avatar_row_exists(mysqli $db, string $table, int $rowId): bool
{
    $table = ($table === 'admins') ? 'admins' : 'users';
    $stmt = $db->prepare("SELECT 1 FROM `$table` WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    db_prepared_execute($stmt, 'i', [$rowId]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $stmt->close();
    return $row !== null;
}

/**
 * Validate a raw $_FILES entry for an avatar.
 * Reuses validate_upload_file() (helpers.php) for the size cap, is_uploaded_file
 * check, finfo MIME sniffing and the .php/.phar double-extension block, then
 * adds an avatar-specific size cap, an allow-list, and a pixel-dimension
 * ceiling so a decompression bomb is rejected before it reaches Cloudinary.
 *
 * @return array{ok:bool,error:string,bytes:int,width:?int,height:?int}
 */
function mc_avatar_validate(array $file): array
{
    $out = ['ok' => false, 'error' => '', 'bytes' => 0, 'width' => null, 'height' => null];

    $check = validate_upload_file($file, MC_AVATAR_ALLOWED_MIME, MC_AVATAR_MAX_BYTES, 'profile photo');
    if (empty($check['ok'])) {
        // validate_upload_file() returns a singular 'error' string on failure.
        $out['error'] = (string) ($check['error'] ?? 'Invalid profile photo.');
        return $out;
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $out['bytes'] = (int) ($check['size'] ?? 0);

    // Pixel ceiling. getimagesize() reads the header only, so this is cheap.
    $info = @getimagesize($tmp);
    if (is_array($info)) {
        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);
        if ($w > 0 && $h > 0) {
            if ($w > MC_AVATAR_MAX_DIMENSION || $h > MC_AVATAR_MAX_DIMENSION) {
                $out['error'] = 'That image is too large. Please use one under '
                    . intdiv(MC_AVATAR_MAX_DIMENSION, 1000) . ' megapixels per side.';
                return $out;
            }
            $out['width']  = $w;
            $out['height'] = $h;
        }
    }

    $out['ok'] = true;
    return $out;
}

/**
 * Upload an avatar to Cloudinary and persist the resulting ids.
 *
 * @return array{ok:bool,error:string,public_id:string,url:string,version:string,display_url:string}
 */
function mc_avatar_upload(mysqli $db, string $table, int $rowId, array $file): array
{
    $out = ['ok' => false, 'error' => '', 'public_id' => '', 'url' => '', 'version' => '', 'display_url' => ''];

    if ($rowId <= 0) {
        $out['error'] = 'Invalid account.';
        return $out;
    }
    // Existence is confirmed BEFORE the upload, not after. The save is an
    // "UPDATE ... WHERE id = ?", so a bad id matches zero rows and the write
    // would silently do nothing while the endpoint still reported success -
    // leaving a real Cloudinary asset behind that no row references. (Found by
    // uploading against id=99999: HTTP 200, nothing stored, orphan asset.)
    if (!mc_avatar_row_exists($db, $table, $rowId)) {
        $out['error'] = ($table === 'admins') ? 'Administrator not found.' : 'Citizen not found.';
        return $out;
    }
    if (!mc_avatar_cloud_ready()) {
        $out['error'] = 'Photo storage is not configured. Please contact an administrator.';
        return $out;
    }
    if (!function_exists('ba_cloudinary_upload_file')) {
        $out['error'] = 'Upload support is unavailable.';
        return $out;
    }

    $check = mc_avatar_validate($file);
    if (!$check['ok']) {
        $out['error'] = $check['error'];
        return $out;
    }

    $tmp = (string) ($file['tmp_name'] ?? '');

    // Cloudinary needs a real extension on the multipart part; the sniffed MIME
    // is authoritative, the client-supplied name is not.
    $ext = '.jpg';
    $mime = (string) ($check['mime'] ?? '');
    if ($mime === 'image/png')  { $ext = '.png'; }
    if ($mime === 'image/webp') { $ext = '.webp'; }
    $staged = $tmp . $ext;
    if (!@copy($tmp, $staged)) {
        $out['error'] = 'Could not read the uploaded photo.';
        return $out;
    }

    $publicId = mc_avatar_public_id($table, $rowId);
    // No 'folder' key: the default applies and prefixes the bare id. See
    // mc_avatar_public_id() for why folder => '' is not an option.
    $upload = ba_cloudinary_upload_file($staged, $publicId, [
        'public_id'   => $publicId,
        'overwrite'   => true,
        'upload_type' => 'upload',
        // No eager transformation: we store the public_id and transform on read
        // via mc_avatar_url(), so the crop can change without re-uploading.
    ]);
    @unlink($staged);

    if (empty($upload['ok'])) {
        $out['error'] = 'Photo upload failed. Please try again.';
        return $out;
    }

    $returnedId = (string) ($upload['public_id'] ?? '');
    if ($returnedId === '') {
        $out['error'] = 'Photo upload failed. Please try again.';
        return $out;
    }
    $baseUrl = (string) ($upload['secure_url'] ?? '');

    if (!mc_avatar_save($db, $table, $rowId, $returnedId, $baseUrl)) {
        $out['error'] = 'Photo was uploaded but could not be saved to your profile.';
        return $out;
    }

    // Read the version back out of the URL Cloudinary just returned, so the
    // delivery URL differs from the previous photo's. Without this the browser
    // caches the old asset and the new photo is never displayed.
    $version = mc_avatar_version_from_url($baseUrl);

    $out['ok']          = true;
    $out['public_id']   = $returnedId;
    $out['url']         = $baseUrl;
    $out['version']     = $version;
    $out['display_url'] = mc_avatar_url($returnedId, '', $version);
    return $out;
}

/**
 * Remove an avatar: clears the columns first so a Cloudinary outage can never
 * leave the row pointing at an asset the user believes they deleted, then
 * attempts the remote destroy on a best-effort basis.
 */
function mc_avatar_delete(mysqli $db, string $table, int $rowId): array
{
    $out = ['ok' => false, 'error' => '', 'public_id' => ''];

    [$publicId] = mc_avatar_get($db, $table, $rowId);
    if ($publicId === '') {
        $out['ok'] = true;   // nothing to do
        return $out;
    }
    // Echoed so the client can clear its OTHER tiles for this account too (the
    // topnav photo), not just the one it was looking at.
    $out['public_id'] = $publicId;

    if (!mc_avatar_save($db, $table, $rowId, '', '')) {
        $out['error'] = 'Could not remove the photo from your profile.';
        return $out;
    }

    // Best-effort remote cleanup. The row is already correct either way.
    if (mc_avatar_cloud_ready() && $publicId !== '' && stripos($publicId, 'http') !== 0) {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => CLOUDINARY_BASE_URL . '/image/destroy',
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'public_id' => $publicId,
                    'timestamp' => time(),
                    'api_key'   => CLOUDINARY_API_KEY,
                    'signature' => ba_cloudinary_sign(['public_id' => $publicId, 'timestamp' => time()]),
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable $e) {
            // Intentionally ignored: the local row is already cleared.
        }
    }

    $out['ok'] = true;
    return $out;
}
