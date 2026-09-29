<?php

declare(strict_types=1);

/* Cross-platform `php -l` sweep.
 *
 * Replaces the previous composer script:
 *   find includes admin public api -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"
 * which had three independent defects:
 *   1. `find ... -exec ... \;` is GNU find(1) syntax. Composer runs scripts
 *      through cmd.exe on Windows, where `find` resolves to the unrelated
 *      C:\Windows\System32\find.exe text-search tool, so nothing was linted.
 *   2. `find -exec` discards the child's exit status by design, so the
 *      pipeline's status was grep's. All files clean -> grep -v matched
 *      nothing -> exit 1 -> composer reported FAILURE. A syntax error ->
 *      grep printed it -> exit 0 -> composer reported SUCCESS. The script
 *      reported the exact opposite of reality, which is the most likely reason
 *      the codebase accumulated undetected problems.
 *   3. It skipped bin/, tests/ and the root-level PHP files.
 *
 * Usage:  php bin/lint.php          (exit 0 = clean, exit 1 = failures)
 *         php bin/lint.php --quiet  (only print failures)
 */

$dirs = ['includes', 'admin', 'public', 'api', 'bin', 'tests', 'database', '.'];
$quiet = in_array('--quiet', $argv, true);

$files = [];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $files[$f->getPathname()] = true;
        }
    }
}
$files = array_keys($files);
sort($files);

$bad = 0;
foreach ($files as $file) {
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        $bad++;
        echo "FAIL  {$file}\n";
        foreach ($out as $line) {
            echo "      " . trim($line) . "\n";
        }
    }
}

$total = count($files);
if ($bad === 0) {
    if (!$quiet) {
        echo "OK: {$total} PHP file(s) linted, 0 syntax errors.\n";
    }
    exit(0);
}

echo "\n{$bad} of {$total} file(s) failed lint.\n";
exit(1);
