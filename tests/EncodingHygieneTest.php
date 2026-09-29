<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards the source encoding.
 *
 * Regression context (2026-09-27): admin/ba_dropoffs.php was valid UTF-8 on disk
 * but its CHARACTERS were double-encoded -- a real en dash had been decoded as
 * Windows-1252 into three characters and re-encoded as UTF-8. The damage was
 * functional, not cosmetic: isValidHoursFormat() built its dash character
 * classes out of those corrupted characters, so
 *
 *     v.replace(/[<a-circumflex><euro><left-dquote>...]/g, '-')
 *
 * matched none of U+2013 / U+2014. Every drop-off row in the database stores
 * real en dashes, so loading any existing non-24/7 point produced
 * hasTimeRange === false, the "Invalid Operation Hours format" banner fired, and
 * Save was disabled -- for data the server-side validator accepted. The ASCII
 * presets kept working, which is why it looked intermittent.
 *
 * These tests are static and file-reading on purpose: bootstrap.php loads no DB
 * and no JS runtime, and the failure mode only appears when a browser meets the
 * page.
 */
final class EncodingHygieneTest extends TestCase
{
    private const SOURCE_DIRS = ['includes', 'api', 'admin', 'public', 'assets', 'bin', 'tests', 'database'];

    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Repo-relative path with forward slashes, so assertions and keys compare
     * the same on Windows, where getPathname() and dirname(__DIR__) disagree
     * about separators.
     */
    private static function relPath(string $path): string
    {
        $p = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', self::projectRoot());
        return str_starts_with($p, $root) ? ltrim(substr($p, strlen($root)), '/') : $p;
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $root = self::projectRoot();
        $files = [];
        foreach (self::SOURCE_DIRS as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && in_array(strtolower($f->getExtension()), ['php', 'css', 'js'], true)) {
                    $files[] = $f->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * character -> the Windows-1252 byte it would have been decoded from.
     *
     * @return array<string, string>
     */
    private static function cp1252ByteMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        for ($b = 0; $b <= 0xFF; $b++) {
            $c = @mb_convert_encoding(chr($b), 'UTF-8', 'Windows-1252');
            if (is_string($c) && mb_strlen($c, 'UTF-8') === 1 && !isset($map[$c])) {
                $map[$c] = chr($b);
            }
        }
        return $map;
    }

    /**
     * Maximal runs of non-ASCII Windows-1252 characters that reverse to VALID
     * UTF-8, keyed by the original text.
     *
     * Two constraints make this safe to run over real source:
     *   1. every character in a run must be non-ASCII, because all the bytes of a
     *      mojibake run came from a UTF-8 sequence for a non-ASCII character;
     *   2. the reversed bytes must be valid UTF-8, which a single legitimate
     *      Latin-1 character can never be, since that is one byte.
     *
     * Constraint 2 is what lets a real diacritic map through untouched.
     * includes/BasuraAlert/Dropoffs.php has one:
     *
     *     'a-circumflex' => 'a', 'o-umlaut' => 'o', ...
     *
     * A codepoint-blacklist scanner flags that as corruption. This does not.
     *
     * @return array<string, int> run text => occurrences
     */
    private static function mojibakeRuns(string $text): array
    {
        $map = self::cp1252ByteMap();
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return [];
        }

        $runs = [];
        $n = count($chars);
        $i = 0;
        while ($i < $n) {
            if (strlen($chars[$i]) <= 1 || !isset($map[$chars[$i]])) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $n && strlen($chars[$j]) > 1 && isset($map[$chars[$j]])) {
                $j++;
            }
            $run = implode('', array_slice($chars, $i, $j - $i));
            $bytes = '';
            foreach (preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                $bytes .= $map[$ch];
            }
            if (strlen($bytes) >= 2 && preg_match('//u', $bytes) === 1 && preg_match('/[\x80-\xFF]/', $bytes) === 1) {
                $runs[$run] = ($runs[$run] ?? 0) + 1;
            }
            $i = $j;
        }
        return $runs;
    }

    public function test_no_source_file_contains_double_encoded_characters(): void
    {
        $offenders = [];
        foreach (self::sourceFiles() as $file) {
            $text = (string) file_get_contents($file);
            $runs = self::mojibakeRuns($text);
            if ($runs === []) {
                continue;
            }
            $detail = [];
            foreach ($runs as $run => $count) {
                $cps = implode(' ', array_map(
                    // mb_ord(), not (int): casting a non-numeric string to int
                    // yields 0, which made this diagnostic print "U+0".
                    static fn(string $c): string => 'U+' . strtoupper(str_pad(dechex(mb_ord($c, 'UTF-8')), 4, '0', STR_PAD_LEFT)),
                    preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) ?: []
                ));
                $detail[] = $cps . ' x' . $count;
            }
            $offenders[] = self::relPath($file) . ': ' . implode(', ', $detail);
        }

        $this->assertSame(
            [],
            $offenders,
            "Double-encoded (mojibake) text found. A real character was decoded as\n"
            . "Windows-1252 and re-encoded as UTF-8, so the file is valid UTF-8 but the\n"
            . "characters are wrong. This is a FUNCTIONAL bug, not cosmetic: it broke\n"
            . "the dash classes in admin/ba_dropoffs.php's isValidHoursFormat(), which\n"
            . "then rejected every operation_hours value in the database.\n"
            . "Repair by reversing the run through Windows-1252 to get the original\n"
            . "UTF-8 bytes. Do NOT 'fix' it with a codepoint blacklist: legitimate\n"
            . "Latin-1 text such as the diacritic map in includes/BasuraAlert/Dropoffs.php\n"
            . "is indistinguishable from mojibake that way.\n"
            . implode("\n", $offenders)
        );
    }

    public function test_every_source_file_is_strictly_valid_utf8(): void
    {
        $offenders = [];
        foreach (self::sourceFiles() as $file) {
            $raw = (string) file_get_contents($file);
            if (preg_match('//u', $raw) !== 1) {
                $offenders[] = self::relPath($file) . ' is not valid UTF-8';
            }
            if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
                // A BOM before "<?php" is echoed as output and breaks the page.
                $offenders[] = self::relPath($file) . ' starts with a UTF-8 BOM';
            }
        }
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_scanner_is_not_vacuously_green(): void
    {
        // If mojibakeRuns() ever stops detecting anything, the guard above is
        // worthless. Feed it the exact sequence that caused the original bug.
        //
        // Direction matters: take the UTF-8 bytes of an en dash and read them as
        // Windows-1252, which is the step that produced the corruption. The
        // opposite conversion is a round trip and returns the input unchanged.
        $enDashUtf8 = "\xE2\x80\x93";
        $asMojibake = mb_convert_encoding($enDashUtf8, 'UTF-8', 'Windows-1252');

        $this->assertNotSame(
            $enDashUtf8,
            $asMojibake,
            'Sanity check on the fixture itself: reading UTF-8 bytes as Windows-1252 must change them.'
        );

        $runs = self::mojibakeRuns('const clean = v.replace(/[' . $asMojibake . ']/g, \'-\');');

        $this->assertNotSame(
            [],
            $runs,
            'The mojibake scanner must still detect a double-encoded en dash, or '
            . 'test_no_source_file_contains_double_encoded_characters() proves nothing.'
        );

        // Reverse it the same way mojibakeRuns() does, and confirm we are back
        // to the original en dash.
        $map = self::cp1252ByteMap();
        $run = (string) array_key_first($runs);
        $bytes = '';
        foreach (preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $bytes .= $map[$ch];
        }
        $this->assertSame(
            $enDashUtf8,
            $bytes,
            'The detected run must reverse back to the original en dash bytes.'
        );
    }

    public function test_legitimate_latin1_text_is_not_flagged(): void
    {
        // The address-normalisation map from includes/BasuraAlert/Dropoffs.php.
        $map = "'a-circumflex' => 'a', 'o-umlaut' => 'o', 'n-tilde' => 'n'";
        $this->assertSame(
            [],
            self::mojibakeRuns($map),
            'A single legitimate Latin-1 character reverses to one byte, which is never '
            . 'valid UTF-8, so it must not be reported. If this fails the scanner would '
            . 'corrupt real Tagalog and accented text.'
        );
    }
}
