<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the phone layout contract for the Drop-off points list.
 *
 * Regression context (2026-09-28): the list is a 5-column desktop table
 * (Landmark / Barangay / Type / Status / Actions) carrying `min-width:560px`,
 * inside a `col-md-7 col-lg-6` panel. Worked out at each breakpoint that floor
 * overflows the panel below ~1000px and overflows a 390px phone by 210px, so
 * the list scrolled sideways and the first column's text was cut off at the left
 * edge with nothing indicating the cut. There was no mobile path at all: the
 * only `max-width` media query in the sheet touched the hero, the KPI grid and
 * the map.
 *
 * The stacked-card treatment below 768px is the same shape PROJECT_GUIDE.md
 * records for public/ba_dropoff_map.php (guide item 13).
 *
 * Two traps this file exists to keep closed:
 *
 *  - `min-width:560px` is THE load-bearing desktop rule. It must be released
 *    inside the media query. Leaving it in place would not fail loudly; it would
 *    just move the clipping from a table to a card.
 *
 *  - The PHP must emit `data-label` on every <td>. The label is rendered by
 *    `td::before{content:attr(data-label)}` and a cell with no data-label
 *    renders no label at all — silently. That is the same contract the guide
 *    states for the client-side tables.
 *
 * These assertions are static; bootstrap.php loads no browser and no CSS
 * engine, matching DropoffHoursFormatTest.php and DropoffFormBannerTest.php.
 */
final class DropoffListMobileTest extends TestCase
{
    private const PAGE = 'admin/ba_dropoffs.php';
    private const SHEET = 'assets/css/admin/ba_dropoffs.css';

    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function sheet(): string
    {
        return (string) file_get_contents(self::projectRoot() . '/' . self::SHEET);
    }

    private static function page(): string
    {
        return (string) file_get_contents(self::projectRoot() . '/' . self::PAGE);
    }

    /**
     * Every @media block whose query mentions $breakpoint.
     *
     * Returns a list rather than the first hit on purpose: this sheet already
     * carried a `max-width:576px` block for the hero / KPI grid / map before the
     * phone toolbar rules were added, so "the first 576px block" is the wrong
     * block for almost every question asked here.
     *
     * @return list<string>
     */
    private static function mediaBlocks(string $breakpoint): array
    {
        $src = self::sheet();
        $offset = 0;
        $found = [];
        while (($open = strpos($src, '@media', $offset)) !== false) {
            $brace = strpos($src, '{', $open);
            if ($brace === false) {
                break;
            }
            $query = substr($src, $open, $brace - $open);
            $body = substr($src, $brace, self::blockLength($src, $brace));
            if (str_contains($query, $breakpoint)) {
                $found[] = $query . $body;
            }
            $offset = $brace + self::blockLength($src, $brace) + 1;
        }
        return $found;
    }

    /**
     * Length of the brace-delimited block opening at $open, including the
     * closing brace. $open must point at the opening `{`.
     */
    private static function blockLength(string $src, int $open): int
    {
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i - $open + 1;
                }
            }
        }
        return $len - $open;
    }

    /**
     * Assert $needle appears in at least one @media block for $breakpoint.
     */
    private static function assertInSomeBlock(
        string $breakpoint,
        string $needle,
        string $message
    ): void {
        $blocks = self::mediaBlocks($breakpoint);
        self::assertNotEmpty(
            $blocks,
            self::SHEET . ' has no @media block matching ' . $breakpoint
        );
        foreach ($blocks as $block) {
            if (str_contains($block, $needle)) {
                self::assertTrue(true);
                return;
            }
        }
        self::fail(
            $message . "\nLooked for \"" . $needle . "\" in " . count($blocks)
            . ' @media block(s) matching ' . $breakpoint . '.'
        );
    }

    public function test_the_560px_floor_is_released_below_the_phone_breakpoint(): void
    {
        self::assertInSomeBlock(
            'max-width: 767.98px',
            '.mc-admin-table-wrap .table { min-width: 0; }',
            'THE load-bearing rule. The table carries min-width:560px on desktop, which '
            . 'overflows a col-md-7 panel below ~1000px and a 390px phone by 210px. It '
            . 'has to be released below the breakpoint or the clipping just moves from '
            . 'the table to the card.'
        );
    }

    public function test_the_phone_block_actually_builds_stacked_cards(): void
    {
        foreach ([
            'thead hidden' => 'thead { display: none; }',
            'row becomes a card' => 'tbody tr {',
            'card border' => 'border: 1px solid var(--ba-border);',
            'cell becomes a label row' => 'tbody td {',
            'label is read from data-label' => 'content: attr(data-label);',
            'tap target floor' => 'min-height: 44px;',
        ] as $what => $needle) {
            self::assertInSomeBlock(
                'max-width: 767.98px',
                $needle,
                'The stacked-card block is missing the ' . $what
                . ' rule ("' . $needle . '").'
            );
        }
    }

    public function test_the_scroll_box_is_dropped_on_phones(): void
    {
        self::assertInSomeBlock(
            'max-width: 767.98px',
            'max-height: none;',
            'A nested 64vh scroller inside an already-scrolling page is a trap on a '
            . 'phone, and the card layout does not need one.'
        );
    }

    public function test_the_address_cap_is_a_class_so_it_can_be_released(): void
    {
        $this->assertStringNotContainsString(
            'max-width:16ch',
            self::page(),
            'max-width:16ch was an inline style on the address line. An inline '
            . 'declaration outranks every stylesheet rule short of !important, so the '
            . 'phone block could not release the cap. It must be a class.'
        );
        $this->assertStringContainsString(
            '.mc-admin-dropoff-addr { max-width: 16ch; }',
            self::sheet(),
            'The 16ch cap belongs in the sheet, on a class, so the media query can undo it.'
        );
        self::assertInSomeBlock(
            'max-width: 767.98px',
            '.mc-admin-dropoff-addr { max-width: none; white-space: normal; }',
            'Under stacked cards the address gets a full row of its own and has nothing '
            . 'to truncate against — the same .mc-truncate intrinsic-floor trap as the '
            . '437px floor fixed in admin/citizens.css.'
        );
    }

    public function test_the_filter_select_is_a_class_not_an_inline_style(): void
    {
        $this->assertStringNotContainsString(
            'style="flex:0 1 200px;min-width:160px"',
            self::page(),
            'The barangay select carried an inline flex/min-width, so the phone block '
            . 'could not reflow it to full width from a stylesheet.'
        );
        self::assertInSomeBlock(
            'max-width: 576px',
            '.mc-admin-toolbar > #filterBarangay',
            'The phone toolbar must target the select BY ID, because the desktop rule '
            . 'is an ID selector and would otherwise outrank a class-based override.'
        );
        self::assertInSomeBlock(
            'max-width: 576px',
            'min-width: 0;',
            'The phone toolbar must also release the select\'s 160px min-width, or it '
            . 'cannot go full width on a 320px phone.'
        );
    }

    /**
     * @dataProvider labelProvider
     */
    public function test_every_list_cell_emits_its_data_label(string $label): void
    {
        $this->assertStringContainsString(
            'data-label="' . $label . '"',
            self::page(),
            'The <td> carrying "' . $label . '" has no data-label. Labels are rendered '
            . 'by td::before{content:attr(data-label)}, so a missing attribute renders '
            . 'NO label at all and fails silently. PROJECT_GUIDE.md: "The PHP MUST '
            . 'emit data-label on every cell or no label renders."'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function labelProvider(): array
    {
        return [
            'Landmark' => ['Landmark'],
            'Barangay' => ['Barangay'],
            'Type' => ['Type'],
            'Status' => ['Status'],
            'Actions' => ['Actions'],
        ];
    }

    public function test_the_list_keeps_its_dom_order_for_the_filter_js(): void
    {
        // admin/ba_dropoffs.php's #btnFilter handler compares
        // tr.children[1].textContent against the barangay <option> text, and the
        // list row click handler resolves the id from tr.dataset.id. The phone
        // layout is CSS-only and must not reorder or re-parent cells, or the
        // filter silently matches nothing.
        $this->assertStringContainsString(
            'b.children[1].textContent',
            self::page(),
            'The filter still depends on the Barangay cell staying the 2nd child.'
        );
        $this->assertStringNotContainsString(
            'flex-direction: column-reverse',
            self::sheet(),
            'No reversing flex order on the list rows, for the same reason.'
        );
    }
}
