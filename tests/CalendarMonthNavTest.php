<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the calendar's month navigation and its legibility floor.
 *
 * Regression context (2026-09-28), two separate problems on
 * public/ba_schedule.php?view=calendar:
 *
 * 1. NO NAVIGATION AT ALL. $startOfMonth was `date('Y-m-01')`, hardcoded to the
 *    current month, with no $_GET['month'] and no prev/next control anywhere on
 *    the page. A resident could only ever see the current month.
 *
 * 2. A LATENT YEAR BUG THAT NAVIGATION TURNS INTO A REAL ONE. The cell loop
 *    built its dates with `mktime(0,0,0,$monthNum,$d,(int)date('Y'))` — the month
 *    from the current date, the year ALWAYS this year. That was invisible while
 *    both halves came from `date()`. The moment a month can be selected,
 *    December of a previous year silently produced December of THIS year, and
 *    those wrong dates are what the day-detail modal reads. There were TWO such
 *    call sites (the cell loop and the weekly-expansion loop).
 *
 * 3. ILLEGIBLE CELLS. `.sw` and `.sg` are two independent
 *    `repeat(7,1fr)` grids, both min-width:0, so below ~700px each cell
 *    collapsed to ~80px. After the cell padding, the pill's own padding and its
 *    icon, about 31px of text remained at 11.2px, and every waste label
 *    rendered as "Wet…" / "Dry …" / "Clea…". The old max-width:520px block made
 *    it strictly worse by shrinking the font to .62rem in an even narrower cell.
 *
 * These assertions are static; bootstrap.php loads no browser and no CSS engine.
 */
final class CalendarMonthNavTest extends TestCase
{
    private const PAGE = 'public/ba_schedule.php';
    private const SHEET = 'assets/css/public/ba_schedule.css';

    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function page(): string
    {
        $code = self::projectRoot() . '/' . self::PAGE;
        if (!is_file($code)) {
            self::fail(self::PAGE . ' does not exist');
        }
        return (string) file_get_contents($code);
    }

    private static function sheet(): string
    {
        $code = self::projectRoot() . '/' . self::SHEET;
        if (!is_file($code)) {
            self::fail(self::SHEET . ' does not exist');
        }
        return (string) file_get_contents($code);
    }

    /** Drop T_COMMENT / T_DOC_COMMENT so prose cannot satisfy a code assertion. */
    private static function code(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    private static function css(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', ' ', $css) ?? $css;
    }

    public function test_the_month_is_read_from_the_query_string(): void
    {
        $code = self::code(self::page());

        $this->assertStringContainsString(
            "\$_GET['month']",
            $code,
            'The calendar has to accept a month. It was hardcoded to date(\'Y-m-01\'), '
            . 'so a resident could only ever view the current month.'
        );
        $this->assertMatchesRegularExpression(
            "/preg_match\('\/\^\\\\d\{4\}-\\\\d\{2\}\\\$\/'/",
            $code,
            '$_GET is attacker-controlled. The month must be pattern-validated as '
            . 'YYYY-MM before it is ever handed to strtotime().'
        );
        $this->assertStringContainsString(
            "date('Y-m', \$requested) === \$_GET['month']",
            $code,
            'The round-trip check is what rejects "2026-13" and "2026-00", which pass a '
            . 'regex but do not name a month. Without it strtotime silently rolls '
            . 'over to the next year.'
        );
    }

    public function test_the_month_is_clamped(): void
    {
        $code = self::code(self::page());

        $this->assertMatchesRegularExpression(
            "/strtotime\('-10 years'/",
            $code,
            'A public page must not build a calendar for year 9999 off one query '
            . 'string. The window has to be clamped on both sides.'
        );
        $this->assertMatchesRegularExpression(
            "/strtotime\('\+10 years'/",
            $code,
            'Clamp the future side too, not just the past.'
        );
        $this->assertMatchesRegularExpression(
            '/max\(\$minTs,\s*min\(\$maxTs,\s*\$requested\)\)/',
            $code,
            'The clamp must actually wrap the requested timestamp.'
        );
    }

    public function test_every_calendar_date_uses_the_selected_year(): void
    {
        $code = self::code(self::page());

        // Two call sites built calendar dates: the cell loop and the
        // weekly-expansion loop. Both used (int)date('Y').
        $this->assertSame(
            0,
            preg_match_all('/mktime\(\s*0\s*,\s*0\s*,\s*0\s*,\s*\$monthNum\s*,\s*\$d\s*,\s*\(int\)\s*date\(/', $code),
            'mktime() must not take its year from date(\'Y\'). That was invisible '
            . 'while the month came from date(\'m\') too, and silently wrong the '
            . 'moment a month can be selected — the resulting dates are what the '
            . 'day-detail modal reads.'
        );
        $this->assertSame(
            2,
            preg_match_all('/mktime\(\s*0\s*,\s*0\s*,\s*0\s*,\s*\$monthNum\s*,\s*\$d\s*,\s*\$calYear\s*\)/', $code),
            'BOTH date-building call sites must pass $calYear: the cell loop and the '
            . 'weekly-expansion loop. Fixing only the first leaves weekly schedules '
            . 'landing on the wrong year.'
        );
    }

    public function test_month_links_preserve_the_active_filters(): void
    {
        $code = self::code(self::page());

        foreach (['$filterBarangay', '$filterWaste', '$filterType'] as $filter) {
            $this->assertStringContainsString(
                $filter,
                self::page(),
                $filter . ' must survive a month link, exactly as it already does on '
                . 'the List/Calendar view toggle. Dropping it silently resets the '
                . 'resident\'s filters on every page turn.'
            );
        }
        $this->assertStringContainsString(
            'view=calendar&month=',
            self::page(),
            'The prev/next links must carry view=calendar as well as the month, or '
            . 'clicking Next drops the resident into the list view.'
        );
    }

    public function test_there_are_prev_and_next_controls(): void
    {
        $page = self::page();

        foreach (['rel="prev"', 'rel="next"'] as $rel) {
            $this->assertStringContainsString(
                $rel,
                $page,
                'The calendar needs a ' . $rel . ' control. Without one the only '
                . 'reachable month is the current one.'
            );
        }
        $this->assertStringContainsString(
            'Back to ',
            $page,
            'A way back to the current month is needed once the calendar is '
            . 'navigable, or a resident who paged forward is stranded.'
        );
    }

    public function test_the_grid_has_a_min_width_floor(): void
    {
        $css = self::css(self::sheet());

        $this->assertMatchesRegularExpression(
            '/\.mc-cal-grid\s*\{\s*min-width:\s*\d+px/',
            $css,
            'THE load-bearing rule. .sw and .sg are two independent '
            . 'repeat(7,1fr) grids with min-width:0, so below ~700px each cell '
            . 'collapsed to ~80px and left ~31px of text — hence "Wet…". A min-width '
            . 'floor plus a horizontal scroller is the Rule #6 answer; without it the '
            . 'cell size is unchanged.'
        );
        $this->assertMatchesRegularExpression(
            '/\.mc-cal\s*\{[^}]*overflow-x:\s*auto/',
            $css,
            'The floor has to live on something that scrolls, or the grid is simply '
            . 'clipped.'
        );
    }

    public function test_both_grids_share_one_wrapper_so_they_cannot_drift(): void
    {
        $page = self::page();

        $this->assertSame(
            1,
            substr_count($page, 'class="mc-cal-grid"'),
            '.sw and .sg must share a single width wrapper, or the weekday header and '
            . 'the day grid can end up at different widths and lose column alignment.'
        );
        $this->assertStringContainsString(
            'class="sw"',
            $page,
            'The weekday header grid must still exist — it is inside .mc-cal-grid now.'
        );
    }

    public function test_event_pills_wrap_instead_of_ellipsising(): void
    {
        $css = self::css(self::sheet());

        $this->assertMatchesRegularExpression(
            '/\.sg \.eb\s*\{[^}]*white-space:\s*normal/s',
            $css,
            'The single declaration the calendar fix rests on. The base rule sets '
            . 'white-space:nowrap + text-overflow:ellipsis, which is what produced '
            . '"Wet…" / "Dry …" / "Clea…".'
        );
        $this->assertMatchesRegularExpression(
            '/\.sg \.eb\s*\{[^}]*text-overflow:\s*clip/s',
            $css,
            'overflow:visible alone is not enough — text-overflow has to be reset off '
            . 'ellipsis too, or the name is still cut.'
        );
    }

    public function test_the_phone_font_shrink_was_not_reinstated(): void
    {
        $css = self::css(self::sheet());

        // The minified base block still carries a bare `.eb{font-size:.62rem}`
        // inside max-width:520px, which SHRANK the pill text in an even narrower
        // cell and made the truncation worse. It is dead, not deleted, so the
        // guard is that a higher-specificity `.sg .eb` rule in the same
        // breakpoint outranks it with a size no smaller than the base.
        $this->assertStringNotContainsString(
            '.sg .eb{font-size:.62rem',
            $css,
            'The pill font must not be shrunk below the base size on phones. That is '
            . 'what turned "Wet…" into an even shorter ellipsis.'
        );
        $this->assertMatchesRegularExpression(
            '/\.sg \.eb\s*\{[^}]*font-size:\s*\.6[6-9]rem/s',
            $css,
            'The 520px block must carry a .sg .eb rule — two class selectors, so it '
            . 'beats the minified single-class .eb — with a size at or above the base '
            . '.7rem.'
        );
    }

    public function test_the_day_number_is_no_longer_white_on_white(): void
    {
        $css = self::css(self::sheet());

        $this->assertMatchesRegularExpression(
            '/\.sg \.sn\s*\{[^}]*background:\s*var\(--ba-surface-2\)/s',
            $css,
            '.sn carried background:var(--ba-surface) — white on a white cell — so the '
            . 'day number was invisible on every day except today.'
        );
    }

    public function test_empty_lead_and_trail_cells_stop_consuming_110px(): void
    {
        $css = self::css(self::sheet());

        $this->assertMatchesRegularExpression(
            '/\.sg \.sce\s*\{[^}]*min-height:\s*0/s',
            $css,
            'A 6-week month opens with a band of empty lead cells. At min-height:110px '
            . 'each, that is most of a screen of grey boxes with nothing in them.'
        );
    }
}
