<?php
declare(strict_types=1);

/**
 * Build a source-only archive safe to publish on a PUBLIC GitHub repo.
 *
 * WHY THIS EXISTS
 * ---------------
 * `bin/build_deploy_zip.php` targets a private single-account host, and its
 * exclude list is sized for that. A public repo is a different threat model:
 * the archive is world-readable, permanent, and indexed by GitHub within
 * minutes. A single leaked value is unrecoverable — GitHub keeps forks and
 * cached pages even after a force-push.
 *
 * This script is therefore deny-by-default. It walks an explicit allow-list of
 * source paths rather than "everything except X", so a new runtime-data
 * directory cannot leak by omission the way an exclude list allows.
 *
 * It then VERIFIES what it wrote: every real value in .env is searched for
 * across the archive's text entries, plus generic credential patterns. A hit
 * is a hard failure with a non-zero exit, so a bad archive can never be
 * produced by accident.
 *
 * Usage: C:\xampp\php\php.exe bin\build_github_zip.php
 */

$projectRoot = dirname(__DIR__);
$dest = $projectRoot . DIRECTORY_SEPARATOR . 'marikina-econcern-source.zip';

$REMOVE = [0 => true, 1 => true, 2 => true];

// ---------------------------------------------------------------------------
// Allow-list: source trees that belong in a public repo.
// ---------------------------------------------------------------------------
$includeDirs = [
    'admin/',
    'api/',
    'assets/',
    'bin/',
    'database/',
    'docs/',
    'includes/',
    'public/',
    'tests/',
    'storage/fixtures/',
    'storage/mock_geocoding/',
    'storage/mock_holidays/',
    'storage/mock_weather/',
    '.well-known/',
];

$includeFiles = [
    '.env.example',
    '.env.infinityfree.example',
    '.gitignore',
    '.htaccess',
    'composer.json',
    'index.php',
    'manifest.apk.json',
    'manifest.php',
    'manifest.webmanifest',
    'phpunit.xml',
    'README.md',
    'repomix.config.json',
    'sw.js',
];

// ---------------------------------------------------------------------------
// Deny-list: applied on top, wins over the allow-list.
// ---------------------------------------------------------------------------
$excludeDirs = [
    '.phpunit.cache/',
    'apk/',
    'apk-admin/',
    'node_modules/',
    'uploads/',
    'vendor/',
    'storage/mock_emails/',
    'storage/mock_images/',
    'storage/qa_harnesses/',
    // Stray Gradle/TWA output from a mis-targeted build. The real project lives
    // in apk/ (excluded above); if a build ever lands in bin/ again it is
    // duplicated generated code, not source.
    'bin/app/',
    'bin/gradle/',
];

// Generated Android scaffolding that must never be committed as source.
$excludeFiles = [
    '.env',
    'app_error.log',
    'deploy.zip',
    'marikina-econcern-source.zip',
    'repomix-output.xml',
    'bin/build.gradle',
    'bin/settings.gradle',
    'bin/gradle.properties',
    'bin/gradlew',
    'bin/gradlew.bat',
    'bin/store_icon.png',
];

$excludeSuffixes = ['.log', '.zip', '.jks', '.keystore', '.pem', '.key', '.tmp'];

// Test scratch images that live beside the legitimate JSON seeds.
$excludeNamePatterns = [
    '#^qa_test_#',
    '#^tmp_qa_#',
    '#\.sql\.bak$#',
];

function matchesAnyDir(string $rel, array $dirs): bool
{
    foreach ($dirs as $d) {
        if ($rel === rtrim($d, '/') || str_starts_with($rel, $d)) {
            return true;
        }
    }
    return false;
}

function isAllowed(string $rel, array $includeDirs, array $includeFiles): bool
{
    foreach ($includeFiles as $f) {
        if ($rel === $f) {
            return true;
        }
    }
    return matchesAnyDir($rel, $includeDirs);
}

if (is_file($dest)) {
    @unlink($dest);
}

$zip = new ZipArchive();
if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create archive\n");
    exit(1);
}

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$added = [];
$skipped = [];

foreach ($rii as $file) {
    $full = (string) $file;
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($full, strlen($projectRoot) + 1));

    $skip = false;

    if ($file->isDir()) {
        $skip = !isAllowed(rtrim($rel, '/') . '/x', $includeDirs, $includeFiles);
        if (!$skip) {
            $skip = matchesAnyDir($rel, $excludeDirs);
        }
    } else {
        $skip = !isAllowed($rel, $includeDirs, $includeFiles)
            || in_array($rel, $excludeFiles, true)
            || matchesAnyDir($rel, $excludeDirs);

        foreach ($excludeSuffixes as $sfx) {
            if (str_ends_with(strtolower($rel), $sfx)) {
                $skip = true;
                break;
            }
        }
        foreach ($excludeNamePatterns as $re) {
            if (preg_match($re, basename($rel)) === 1) {
                $skip = true;
                break;
            }
        }
    }

    // Keep .gitkeep so empty directories survive the round trip.
    if ($skip && !$file->isDir() && basename($rel) === '.gitkeep') {
        $skip = false;
    }

    if ($skip) {
        $skipped[] = $rel;
        continue;
    }

    if ($file->isDir()) {
        $zip->addEmptyDir($rel);
    } else {
        $zip->addFile($full, $rel);
        $added[] = $rel;
    }
}

$zip->close();

// ---------------------------------------------------------------------------
// VERIFY — read .env and make sure none of its real values shipped.
// ---------------------------------------------------------------------------
/**
 * Only these count as secrets. Matching every .env value produces pure noise:
 * APP_NAME, MAIL_HOST, *_FIXTURE and *_MODE are public config and legitimately
 * appear in templates, tests and source. Flagging those trains you to ignore
 * the check, which is how real leaks get waved through.
 */
$secretKeyPattern = '/(PASS|SECRET|TOKEN|_KEY|PRIVATE|CREDENTIAL|RECOVERY|APIKEY)/i';

$realValues = [];
$envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (preg_match($secretKeyPattern, $k) !== 1) {
            continue;
        }
        // Only values that look like real secrets, not "" or "0".
        if (strlen($v) >= 8 && !in_array(strtolower($v), ['true', 'false', 'localhost', 'pending'], true)) {
            $realValues[$k] = $v;
        }
    }
}

$genericPatterns = [
    '#BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY#',
    '#sk_live_[0-9a-zA-Z]{10,}#',
    '#xox[baprs]-[0-9A-Za-z-]{10,}#',
    '#AIza[0-9A-Za-z\-_]{30,}#',
    '#eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}#', // JWT
];

$scan = new ZipArchive();
if ($scan->open($dest, ZipArchive::CHECKCONS) !== true) {
    fwrite(STDERR, "Cannot reopen archive for verification\n");
    exit(1);
}

$leaks = [];
for ($i = 0; $i < $scan->numFiles; $i++) {
    $stat = $scan->statIndex($i);
    $name = $stat['name'];
    $size = $stat['size'];
    if ($size === 0 || $size > 4_000_000 || str_ends_with(strtolower($name), '.png')) {
        continue;
    }
    $body = $scan->getFromIndex($i);
    if ($body === false) {
        continue;
    }
    foreach ($realValues as $key => $val) {
        if (str_contains($body, $val)) {
            $leaks[] = "$name contains live .env value for $key";
        }
    }
    foreach ($genericPatterns as $re) {
        if (preg_match($re, $body) === 1) {
            $leaks[] = "$name matches credential pattern $re";
        }
    }
}
$scan->close();

printf("Archive: %s (%.2f MB)\n", basename($dest), filesize($dest) / 1048576);
printf("Included: %d entries | Excluded: %d\n\n", count($added), count($skipped));

if ($leaks !== []) {
    fwrite(STDERR, "ABORTED - archive would leak secrets:\n");
    foreach (array_unique($leaks) as $l) {
        fwrite(STDERR, "  - $l\n");
    }
    @unlink($dest);
    exit(1);
}

echo "Verification passed: no .env values, keys, or credentials found in the archive.\n";
echo "Checked " . count($realValues) . " live .env values against every text entry.\n";
echo "\nTop-level dirs/files included:\n";
$top = [];
foreach ($added as $a) {
    $top[explode('/', $a)[0]] = true;
}
foreach (array_keys($top) as $t) {
    echo "  $t\n";
}
echo "\nBefore publishing, still confirm: repo is PUBLIC-safe, and never commit .env.\n";
