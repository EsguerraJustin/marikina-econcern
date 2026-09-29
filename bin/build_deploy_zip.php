<?php
declare(strict_types=1);

/**
 * Build deploy.zip for InfinityFree File Manager / FTP upload.
 * Excludes dev-only and secret files so you never upload them by accident.
 * Usage:  C:\xampp\php\php.exe bin\build_deploy_zip.php
 * Output: deploy.zip (project root) — upload + extract into htdocs/
 */

$projectRoot = dirname(__DIR__);
$dest = $projectRoot . DIRECTORY_SEPARATOR . 'deploy.zip';
if (is_file($dest)) {
    @unlink($dest);
}

// Exact-file/dir excludes (relative to project root, forward slashes).
$excludedPaths = [
    '.env',
    'app_error.log',
    'deploy.zip',
    'deploy.zip.zip',
    'repomix-output.xml',
    'vendor/',
    'node_modules/',
    'tests/',
    '.phpunit.cache/',
    '.git/',
    'storage/qa_harnesses/',
    'storage/mock_emails/',
    'storage/mock_images/',
    'storage/logs/',
    'storage/cache/',
    'storage/fixtures/images/',
    'uploads/concerns/',
    'uploads/basuraalert_reports/',
    'uploads/feedback/',
    'docs/',
    'tmp/',
    'temp/',
    'apk/',
    'apk-admin/', // local TWA/Gradle projects - built on the dev machine, never served
    'manifest.apk.json', // build-only manifests for Bubblewrap; absolute live URLs, not needed on the host
    'manifest.admin.json',
    '.well-known/assetlinks.json', // upload by hand: only meaningful once the keystore fingerprint is real
];

// Suffix excludes. Keeps signing keys out of the archive even if one is ever
// dropped in the project root — a leaked keystore means anyone can ship an
// update to a published app.
$excludedSuffixes = ['.md', '.markdown', '.tmp', '.temp', '.log', '.zip', '.jks', '.keystore'];

$zip = new ZipArchive();
if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create deploy.zip\n");
    exit(1);
}

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$added = 0;
$skipped = 0;
foreach ($rii as $file) {
    $full = (string) $file;
    $rel = substr($full, strlen($projectRoot) + 1);
    $relFwd = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

    $skip = false;
    foreach ($excludedPaths as $ex) {
        // Trailing '/' = whole directory tree; otherwise exact file match only
        // (so '.env' does NOT swallow '.env.infinityfree.example').
        if (str_ends_with($ex, '/')) {
            if (str_starts_with($relFwd, $ex) || $relFwd . '/' === $ex || $relFwd === rtrim($ex, '/')) {
                $skip = true;
                break;
            }
        } elseif ($relFwd === $ex) {
            $skip = true;
            break;
        }
    }
    if (!$skip) {
        foreach ($excludedSuffixes as $sfx) {
            if (str_ends_with(strtolower($relFwd), $sfx)) {
                $skip = true;
                break;
            }
        }
    }
    // Keep .gitkeep placeholders so empty dirs survive.
    if ($skip && basename($relFwd) === '.gitkeep') {
        $skip = false;
    }
    if ($skip) {
        $skipped++;
        continue;
    }
    if ($file->isDir()) {
        $zip->addEmptyDir($relFwd);
    } else {
        $zip->addFile($full, $relFwd);
        $added++;
    }
}
$zip->close();
echo "Wrote deploy.zip: {$added} files added, {$skipped} skipped.\n";
echo "Upload to InfinityFree File Manager > htdocs/ and extract there.\n";
echo "Then copy htdocs/.env.infinityfree.example to htdocs/.env and fill in values.\n";
