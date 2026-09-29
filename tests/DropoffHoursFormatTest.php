<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the client-side Operation Hours validator to the server-side one.
 *
 * Regression context (2026-09-27): admin/ba_dropoffs.php's isValidHoursFormat()
 * and includes/BasuraAlert/Validation.php's ba_validate_operation_hours() are two
 * implementations of the same rule, and they had drifted -- not in the regexes
 * themselves, but in the CHARACTERS those regexes were built from. The page file
 * was double-encoded, so its dash classes held U+00E2 U+20AC U+201C instead of
 * U+2013. The server file was clean. Every operation_hours value in the database
 * uses real en dashes, so the client rejected all of them, the warning banner
 * fired on every existing drop-off, and Save stayed disabled even though the
 * server would have accepted the save.
 *
 * The two files also contradicted each other inside one page:
 * parseDaysFromHoursText() used proper \u2013 escapes for its dash set while
 * isValidHoursFormat() did not, which is what identified the corruption as
 * accidental rather than intentional.
 *
 * These assertions are static. bootstrap.php loads no browser and no JS runtime,
 * so the tests verify the character classes rather than executing them -- which
 * is precisely the part that broke.
 */
final class DropoffHoursFormatTest extends TestCase
{
    private const PAGE = 'admin/ba_dropoffs.php';
    private const SERVER_VALIDATOR = 'includes/BasuraAlert/Validation.php';

    /** The en dash and em dash the database actually stores. */
    private const EN_DASH = "\u{2013}";
    private const EM_DASH = "\u{2014}";

    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function pageSource(): string
    {
        return (string) file_get_contents(self::projectRoot() . '/' . self::PAGE);
    }

    /** Body of isValidHoursFormat(), up to the next top-level function. */
    private static function clientValidator(): string
    {
        $src = self::pageSource();
        $start = strpos($src, 'function isValidHoursFormat(');
        if ($start === false) {
            self::fail(self::PAGE . ' must still define isValidHoursFormat()');
        }
        $body = substr($src, (int) $start);
        $end = strpos($body, "\n    function ", 1);
        return $end === false ? $body : substr($body, 0, (int) $end);
    }

    /**
     * The characters inside a regex character class, e.g. `[–—]` or `[-–/]`.
     *
     * @return list<string>
     */
    private static function charClasses(string $source): array
    {
        preg_match_all('/\[([^\]\[]*)\]/u', $source, $m);
        return $m[1];
    }

    public function test_client_normalises_real_dashes_to_a_hyphen(): void
    {
        $body = self::clientValidator();

        $this->assertMatchesRegularExpression(
            '/\.replace\(\/\[.{1,8}\]\/g,\s*\'-\'\)/u',
            $body,
            'isValidHoursFormat() must still fold the dash variants to a plain hyphen before matching.'
        );

        $classes = self::charClasses($body);
        $dashClass = null;
        foreach ($classes as $class) {
            if (str_contains($class, self::EN_DASH) || str_contains($class, self::EM_DASH)) {
                $dashClass = $class;
                break;
            }
        }

        $this->assertNotNull(
            $dashClass,
            "isValidHoursFormat() has no character class containing a real en dash (U+2013) or\n"
            . "em dash (U+2014). Its dash class almost certainly holds double-encoded\n"
            . "characters, so it cannot match the en dashes stored in ba_dropoff_points.\n"
            . "Classes found: " . implode(' | ', $classes)
        );
    }

    public function test_client_time_range_separator_accepts_a_real_en_dash(): void
    {
        $body = self::clientValidator();

        $this->assertMatchesRegularExpression(
            '/timeRangeRe\s*=\s*\/.*?\\\\s\*\[[^\]]*' . self::EN_DASH . '[^\]]*\]/u',
            $body,
            'The timeRangeRe separator class must include the real en dash (U+2013); '
            . 'this is the class that has to match "6:00-8:00" in the database.'
        );
    }

    public function test_client_and_server_agree_on_the_stored_hours_values(): void
    {
        $server = (string) file_get_contents(self::projectRoot() . '/' . self::SERVER_VALIDATOR);
        $client = self::clientValidator();

        // Both implementations must at minimum normalise the same dash set.
        foreach ([self::SERVER_VALIDATOR => $server, self::PAGE => $client] as $label => $source) {
            $this->assertStringContainsString(
                self::EN_DASH,
                $source,
                $label . ' must recognise U+2013 (en dash). The database stores it: '
                . 'ba_dropoff_points 177 is "Tue/Thu/Sat 6:00-8:00 AM ; 5:00-7:00 PM" '
                . 'and 179 is "Mon-Fri 8:00 AM - 5:00 PM ; Sat 8:00 AM - 12:00 NN", '
                . 'both with real en dashes.'
            );
        }

        // And neither may be holding a double-encoded dash.
        $this->assertSame(
            [],
            self::mojibakeRuns($client),
            self::PAGE . ' still contains double-encoded characters inside isValidHoursFormat().'
        );
    }

    /**
     * @return list<string>
     */
    private static function mojibakeRuns(string $text): array
    {
        $map = [];
        for ($b = 0; $b <= 0xFF; $b++) {
            $c = @mb_convert_encoding(chr($b), 'UTF-8', 'Windows-1252');
            if (is_string($c) && mb_strlen($c, 'UTF-8') === 1 && !isset($map[$c])) {
                $map[$c] = chr($b);
            }
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $found = [];
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
            foreach (preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $bytes .= $map[$ch];
            }
            if (strlen($bytes) >= 2 && preg_match('//u', $bytes) === 1 && preg_match('/[\x80-\xFF]/', $bytes) === 1) {
                $found[] = $run;
            }
            $i = $j;
        }
        return $found;
    }
}
