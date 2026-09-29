<?php

declare(strict_types=1);

/**
 * PWA manifest, served through PHP.
 *
 * WHY THIS EXISTS
 * ---------------
 * InfinityFree's free-hosting bot filter intercepts certain subresource
 * requests and answers them with its own HTML challenge page (the `aes.js`
 * `__test`-cookie interstitial) instead of the real file. Chrome then tried to
 * parse that HTML as the manifest and failed with:
 *
 *     Manifest: Line 1, column 1, Syntax error.
 *
 * which blocks PWA installability outright ("This app cannot be installed").
 * Top-level navigations and /assets/* subresources pass the filter, so the
 * failure looked arbitrary until the request classes were compared.
 *
 * Serving the manifest from PHP gets a real Content-Type on a request class
 * that the filter does not touch. Same bytes, different transport.
 *
 * The canonical file stays manifest.webmanifest so the repo still has a plain,
 * cacheable source. Change that file, not this one.
 *
 * NOTE: Bubblewrap must NOT be pointed at either of these. Its CLI fetches the
 * manifest with no browser cookie, so InfinityFree's filter answers with the
 * challenge page and the build fails. The APK build uses manifest.apk.json
 * served from localhost — see bin/build_apk_project.js.
 */

require_once __DIR__ . '/includes/helpers.php';

$file = __DIR__ . '/manifest.webmanifest';

header('Content-Type: application/manifest+json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
/* Short cache: enough to absorb reload storms, fresh enough that a manifest
 * edit shows up without a purge. Bump SW_VERSION in sw.js to force clients to
 * re-fetch after changing icons or start_url. */
header('Cache-Control: public, max-age=300');

if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'manifest.webmanifest not found']);
    exit;
}

$body = (string) file_get_contents($file);
if (trim($body) === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'manifest.webmanifest is empty']);
    exit;
}

echo $body;
