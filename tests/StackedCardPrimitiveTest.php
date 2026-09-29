<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the shared .mc-stacked-card primitive and its five adopters.
 *
 * Regression context (2026-09-28): the "label above value" phone card layout
 * existed as EIGHTEEN byte-identical hand-rolled copies, one per page sheet:
 *
 *   content:attr(data-label);
 *   display:block;
 *   font:700 .7rem/1.2 Montserrat,sans-serif;
 *   letter-spacing:.3px;
 *   text-transform:uppercase;
 *   color:#6B7280;            (var(--ba-text-muted) in some of the copies)
 *   margin-bottom:5px
 *
 * plus `cell{padding:12px 0; border-bottom:1px dashed}` and a
 * `row{padding:4px 14px}` card shell. Measured on the screenshots that
 * prompted it, that cost ~370px of card for ONE report on my_concern.php and
 * ~250px for ONE item on ba_waste.php, because every field spent a full label
 * line plus a 5px gap plus 24px of padding — and the FIRST field was the
 * card's own title, so `ITEM / Aluminum cans` and `CONCERN / Pet
 * Registration` each labelled the heading directly above them.
 *
 * This is the third instance of the same failure shape this project has
 * already paid for: .mc-table-wrap (documented in PROJECT_GUIDE.md but never
 * defined anywhere) and .mc-avatar (needed extraction to common.css). The
 * guide's §"Global CSS Utilities" still describes a stacked-card block in
 * common.css that did not exist; it does now.
 *
 * Two things the primitive deliberately does NOT do, both asserted below
 * because each would be a silent regression:
 *
 *  - Reorder cells. The header promotion keys off :first-child, which is
 *    structural rather than semantic, precisely so that no cell moves.
 *    public/ba_dropoff_map.php's and public/ba_waste.php's filter JS reads
 *    cells by position.
 *  - Emit a second copy of itself. The layout is token-driven rather than
 *    wrapped in a media query, because public/ba_dropoff_map.php is
 *    single-column at EVERY width and needs the card on desktop too, while the
 *    others are real column grids above 768px. Emitting the block twice would
 *    guarantee the copies drift.
 */
final class StackedCardPrimitiveTest extends TestCase
{
    private const SHEET = 'assets/css/common.css';

    /** @var list<array{0: string, 1: string}> sheet path, human label */
    private const ADOPTERS = [
        ['public/ba_waste.php', 'ba_waste'],
        ['public/ba_schedule.php', 'ba_schedule (list view)'],
        ['public/ba_dropoff_map.php', 'ba_dropoff_map'],
        ['public/ba_my_reports.php', 'ba_my_reports'],
        ['public/my_concern.php', 'my_concern'],
    ];

    /**
     * Sheets that consumed the primitive and must therefore no longer carry
     * their own copy of the label block. public/my_concern.css is deliberately
     * NOT in this list: #concernsTable's desktop rules are ID-scoped and
     * !important and :nth-child() counts as a class, so it outranks anything a
     * shared block could carry without naming the ID. Its one surviving
     * `content:attr(data-label)` is asserted separately below.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const SHEETS_THAT_MUST_BE_CLEAN = [
        ['assets/css/public/ba_waste.css', 'ba_waste.css'],
        ['assets/css/public/ba_schedule.css', 'ba_schedule.css'],
        ['assets/css/public/ba_dropoff_map.css', 'ba_dropoff_map.css'],
        ['assets/css/public/ba_my_reports.css', 'ba_my_reports.css'],
    ];

    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function read(string $rel): string
    {
        $path = self::projectRoot() . '/' . $rel;
        if (!is_file($path)) {
            self::fail($rel . ' does not exist');
        }
        return (string) file_get_contents($path);
    }

    /** CSS has block comments only. */
    private static function stripCssComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', ' ', $css) ?? $css;
    }

    /**
     * PHP: drop T_COMMENT / T_DOC_COMMENT properly, so an assertion about
     * "this must not appear" is never satisfied by prose explaining the fix.
     * Same reason NotificationQueueSchemaTest.php uses token_get_all().
     */
    private static function stripPhpComments(string $php): string
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

    /** The .mc-stacked-card rules only, so assertions cannot trip on .mc-editor-dialog. */
    private static function primitiveBlock(): string
    {
        // Slice on the RAW source: the section boundaries are comments, and this
        // helper strips comments — searching for the marker afterwards would
        // never find it and the "block" would run to the end of the file.
        $raw = self::read(self::SHEET);
        $start = strpos($raw, '/* ---------- .mc-stacked-card');
        if ($start === false) {
            self::fail(self::SHEET . ' has no .mc-stacked-card section');
        }
        $end = strpos($raw, '/* ---------- .mc-editor-dialog', $start);
        $slice = $end === false ? substr($raw, $start) : substr($raw, $start, $end - $start);
        return self::stripCssComments($slice);
    }

    public function test_the_primitive_lives_in_common_css(): void
    {
        $css = self::stripCssComments(self::read(self::SHEET));

        $this->assertStringContainsString(
            '.mc-stacked-card{',
            $css,
            'The primitive must be defined in assets/css/common.css. It is shared by '
            . 'five pages across the public side; a page-local copy is what created '
            . 'the eighteen-way duplication in the first place.'
        );
        $this->assertStringContainsString(
            '--mc-sc-card-frame',
            $css,
            'The card chrome must be driven by tokens, so public/ba_dropoff_map.css can '
            . 're-emit the same layout at every width (it is single-column on desktop) '
            . 'from one set of values instead of restating them.'
        );
    }

    /**
     * The reference switch: label ABOVE value, first field labelled too.
     *
     * The three lists (public/ba_waste.php, public/ba_dropoff_map.php,
     * public/my_concern.php) were moved onto admin/dashboard.php's Recent
     * Concerns card design, which stacks a label over its value and prints a
     * label for the FIRST field as well. Three things make that reachable
     * without a second copy of the label block:
     *
     *  - --mc-sc-head-label-display. The label suppression on the promoted
     *    first cell used to be a hard-coded `content:none`, which is why no
     *    amount of token overriding could give the first field a label back.
     *    It is a `display` toggle now, and a `content` one was tried first and
     *    is impossible: `attr()` inside a custom property is substituted
     *    against the element DECLARING the property, every page declares these
     *    tokens on the wrapper, and the wrapper carries no data-label. The
     *    token resolved to the empty value and the first field rendered no
     *    label — silently, which is the failure mode this file exists for.
     *
     *  - --mc-sc-cell-top. The reference draws one line UNDER each field. The
     *    first cell already carries --mc-sc-head-rule on its bottom edge, so a
     *    non-zero top border stacks two lines between fields 1 and 2. Hence
     *    `0 none` rather than editing the rule per page.
     *
     * The layout itself is then pure tokens: one grid track + --mc-sc-value-col
     * 1 puts the value under the label.
     */
    public function test_the_reference_switch_is_two_tokens_not_a_second_block(): void
    {
        $block = self::primitiveBlock();

        $this->assertStringContainsString(
            '--mc-sc-head-label-display:none',
            $block,
            'The promoted first cell must suppress its label through a token, not a '
            . 'hard-coded content:none. Without it, no page can give its first field '
            . 'a label without hand-rolling a second label rule.'
        );
        $this->assertStringContainsString(
            'display:var(--mc-sc-head-label-display)',
            $block,
            'The primitive must read the token, or the declaration above is dead. It '
            . 'has to be `display`, NOT `content: var(--mc-sc-head-label)`: attr() in '
            . 'a custom property resolves against the element that DECLARES it, and '
            . 'the declaring element is the wrapper, which has no data-label.'
        );
        $this->assertStringContainsString(
            '--mc-sc-cell-top:var(--mc-sc-rule)',
            $block,
            'The between-cell divider needs its own token. --mc-sc-head-rule already '
            . 'draws the line under the first cell, so on the reference layout a '
            . 'non-zero --mc-sc-cell-top draws two lines between fields 1 and 2.'
        );
        $this->assertStringContainsString(
            'border-top:var(--mc-sc-cell-top)',
            $block,
            'The cell rule must read the token rather than --mc-sc-rule directly.'
        );
    }

    /**
     * The three reference adopters must set both tokens, and must reach the
     * labelled layout through tokens only — a literal `grid-template-columns`
     * or a hand-rolled `content:attr(data-label)` reintroduces the drift this
     * primitive exists to prevent.
     *
     * @dataProvider referenceTokenProvider
     */
    public function test_a_reference_page_switches_on_the_shared_tokens(
        string $rel,
        string $label
    ): void {
        $css = self::stripCssComments(self::read($rel));

        foreach ([
            '--mc-sc-head-label-display:block',
            '--mc-sc-cell-top:0 none',
            '--mc-sc-cell-cols',
            '--mc-sc-value-col:1',
        ] as $token) {
            $this->assertStringContainsString(
                $token,
                $css,
                $label . ' renders the reference card design but does not set ' . $token
                . '. Without it the page keeps the rail layout, or — worse — a label '
                . 'and a value side by side in a one-track grid.'
            );
        }

        $this->assertStringNotContainsString(
            '--mc-sc-head-label:',
            $css,
            $label . ' routes the first field\'s label through a custom property. '
            . 'attr() in a custom property resolves against the declaring element '
            . '(the wrapper, which has no data-label), so it resolves to the empty '
            . 'value and the label silently does not render. Use '
            . '--mc-sc-head-label-display instead.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function referenceTokenProvider(): array
    {
        return [
            'ba_waste' => ['assets/css/public/ba_waste.css', 'ba_waste.css'],
            'ba_dropoff_map' => ['assets/css/public/ba_dropoff_map.css', 'ba_dropoff_map.css'],
            'my_concern' => ['assets/css/public/my_concern.css', 'my_concern.css'],
        ];
    }

    public function test_the_card_chrome_is_breakpoint_gated(): void
    {
        $block = self::primitiveBlock();

        $this->assertStringContainsString(
            '@media (max-width:767.98px)',
            $block,
            'The card chrome must be breakpoint-gated. public/ba_waste.php, '
            . 'public/ba_my_reports.php and public/ba_schedule.php are real column grids '
            . 'above 768px; emitting the card border/radius/padding unconditionally '
            . 'turns their desktop table rows into cards.'
        );
        $this->assertStringNotContainsString(
            '.mc-stacked-card--always',
            $block,
            'The --always modifier is gone. It could not work: `border` is a shorthand, '
            . 'so a tokenised table-mode value of 0 would also erase the row '
            . 'border-bottom the base table relies on. The always-card page re-emits '
            . 'the rules in its own sheet instead.'
        );
    }

    public function test_the_always_card_page_reemits_from_the_same_tokens(): void
    {
        $sheet = self::stripCssComments(self::read('assets/css/public/ba_dropoff_map.css'));

        // public/ba_dropoff_map.php is single-column at EVERY width (guide
        // item 13), so it cannot use the breakpoint-gated block on desktop.
        // It re-emits the same rules, so every value it sets must be a token
        // rather than a literal — otherwise the two drift.
        // var(--mc-sc-rule), var(--mc-sc-head-cols) and var(--mc-sc-head-pad)
        // are absent on purpose. The divider is consumed as
        // var(--mc-sc-cell-top), which the primitive derives from --mc-sc-rule;
        // the promoted first cell now shares --mc-sc-cell-cols and
        // --mc-sc-cell-pad with every other cell, because the reference layout
        // puts its label in the same single track rather than treating the
        // first field as a heading. All three were restatements of values whose
        // meaning has since changed.
        foreach ([
            'var(--mc-sc-card-frame)',
            'var(--mc-sc-card-radius)',
            'var(--mc-sc-card-pad)',
            'var(--mc-sc-card-margin)',
            'var(--mc-sc-card-min)',
            'var(--mc-sc-cell-cols)',
            'var(--mc-sc-cell-pad)',
            'var(--mc-sc-col-gap)',
            'var(--mc-sc-cell-align)',
            'var(--mc-sc-cell-top)',
            'var(--mc-sc-head-rule)',
            'var(--mc-sc-head-label-display)',
            'var(--mc-sc-value-col)',
            'var(--mc-sc-label-font)',
            'var(--mc-sc-label-color)',
            'var(--mc-sc-label-align)',
            'var(--mc-sc-label-track)',
        ] as $token) {
            $this->assertStringContainsString(
                $token,
                $sheet,
                'ba_dropoff_map.css re-emits the card layout at every width and must '
                . 'read every value from the shared tokens, or the two copies drift. '
                . 'Missing: ' . $token
            );
        }
    }

    public function test_the_primitive_is_token_driven_not_duplicated_per_breakpoint(): void
    {
        $block = self::primitiveBlock();

        $this->assertSame(
            1,
            substr_count($block, 'content:attr(data-label)'),
            'The primitive must emit the label rule exactly ONCE. A second copy is '
            . 'the drift this primitive exists to prevent.'
        );
        $this->assertStringContainsString(
            'grid-template-columns:var(--mc-sc-cell-cols)',
            $block,
            'The cell rail must read its track definition from a custom property, so '
            . 'one media query can switch between the phone card and a desktop table. '
            . 'A hard-coded grid-template-columns cannot be switched.'
        );
        $this->assertStringContainsString(
            '--mc-sc-value-col',
            $block,
            'The value must land in a named grid column, so the below-360px variant '
            . 'can move it back to column 1 without duplicating the rule.'
        );
    }

    public function test_the_header_promotion_is_structural_not_ordered(): void
    {
        $block = self::primitiveBlock();

        $this->assertStringContainsString(
            '> :first-child',
            $block,
            'The card header must be promoted with :first-child so a page can opt in '
            . 'with one class and no per-cell markup.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|[;{\s])order\s*:/m',
            $block,
            'No `order` inside the primitive. Reordering cells would break the '
            . 'by-position lookups in ba_dropoff_map.php / ba_waste.php filter JS. '
            . '(Anchored on a leading delimiter because `border:` contains `order:`.)'
        );
        $this->assertStringNotContainsString(
            'column-reverse',
            $block,
            'No reversing flex order, for the same reason.'
        );
    }

    /**
     * @dataProvider adapterProvider
     */
    public function test_each_page_opts_in_with_the_primitive(string $rel, string $label): void
    {
        $this->assertStringContainsString(
            'mc-stacked-card',
            self::stripPhpComments(self::read($rel)),
            $label . ' must reference the .mc-stacked-card primitive. If its own '
            . 'stacked-card block was deleted without opting in, the phone layout '
            . 'for this page is simply gone and nothing fails loudly.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adapterProvider(): array
    {
        $out = [];
        foreach (self::ADOPTERS as [$rel, $label]) {
            $out[$label] = [$rel, $label];
        }
        return $out;
    }

    /**
     * @dataProvider cleanSheetProvider
     */
    public function test_a_consumed_sheet_carries_no_copy_of_the_block(
        string $rel,
        string $label
    ): void {
        if ($rel === 'assets/css/public/ba_dropoff_map.css') {
            // This one legitimately re-emits the layout (single-column at every
            // width). It is covered by the token-agreement test above; here we
            // only check it did not also keep the OLD stacked-above block.
            $css = self::stripCssComments(self::read($rel));
            $this->assertStringNotContainsString(
                'border-bottom:1px dashed',
                $css,
                $label . ' still uses the dashed cell divider the primitive replaced '
                . 'with a real --ba-border hairline.'
            );
            $this->assertStringNotContainsString(
                'display:block;\n  padding:12px 0;',
                $css,
                $label . ' still stacks the label above the value.'
            );
            return;
        }

        $css = self::stripCssComments(self::read($rel));

        $this->assertStringNotContainsString(
            'content:attr(data-label)',
            $css,
            $label . ' consumed the shared primitive, so it must not still hand-roll '
            . 'the label rule. A page that does both gets whichever rule wins on '
            . 'specificity, which is not a decision anyone made.'
        );
        $this->assertStringNotContainsString(
            'border-bottom:1px dashed',
            $css,
            $label . ' still uses the dashed cell divider the primitive replaced with '
            . 'a real --ba-border hairline.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cleanSheetProvider(): array
    {
        $out = [];
        foreach (self::SHEETS_THAT_MUST_BE_CLEAN as [$rel, $label]) {
            $out[$label] = [$rel, $label];
        }
        return $out;
    }

    public function test_my_concern_keeps_exactly_one_id_scoped_label_rule(): void
    {
        $css = self::stripCssComments(self::read('assets/css/public/my_concern.css'));

        $this->assertSame(
            1,
            substr_count($css, 'content:attr(data-label)'),
            'my_concern.css is a real <table> whose desktop rules are ID-scoped and '
            . '!important, and :nth-child() counts as a class, so it outranks any '
            . 'class-only override. One ID-scoped copy is therefore correct — but '
            . 'exactly one. Two means a second hand-rolled block crept back in.'
        );
        // var(--mc-sc-head-cols) is absent for the same reason as in the
        // drop-off test above: the promoted first cell reads --mc-sc-cell-cols.
        foreach ([
            'var(--mc-sc-cell-cols)',
            'var(--mc-sc-cell-top)',
            'var(--mc-sc-head-rule)',
            'var(--mc-sc-head-label-display)',
            'var(--mc-sc-card-pad)',
        ] as $token) {
            $this->assertStringContainsString(
                $token,
                $css,
                'The ID-scoped overrides must read their values from the shared tokens '
                . 'rather than restating them, or the two will drift. Missing: ' . $token
            );
        }
    }

    public function test_the_inline_scroll_box_left_the_dropoff_list(): void
    {
        $php = self::stripPhpComments(self::read('public/ba_dropoff_map.php'));
        $css = self::stripCssComments(self::read('assets/css/public/ba_dropoff_map.css'));

        $this->assertStringNotContainsString(
            'max-height:60vh',
            $php,
            'The 60vh scroll box was an inline style, which is why '
            . 'ba_dropoff_map.css needed max-height:none!important to switch it off on '
            . 'phones. It belongs in the sheet, where a media query can reach it.'
        );
        $this->assertStringContainsString(
            'mc-dropoff-scroll',
            $php,
            'The scroll box must now be a class the stylesheet owns.'
        );
        $this->assertMatchesRegularExpression(
            '/\.mc-dropoff-scroll\s*\{[^}]*max-height:\s*60vh[^}]*\}/',
            $css,
            'The sheet must own the desktop scroll box, since the inline style it '
            . 'replaced is gone.'
        );
        $this->assertMatchesRegularExpression(
            '/\.mc-dropoff-scroll\s*\{[^}]*max-height:\s*none/s',
            $css,
            'And the phone variant must switch it off WITHOUT !important. It used to '
            . 'need one only because the value was inline and outranked every rule in '
            . 'a stylesheet; now that it lives here, !important would be a trap for '
            . 'whoever edits this sheet next.'
        );
    }

    public function test_the_dropped_24_7_badge_is_not_printed_twice(): void
    {
        $php = self::stripPhpComments(self::read('public/ba_dropoff_map.php'));

        // Scoped to the acceptance badge row. The page legitimately mentions
        // 24/7 elsewhere: the "24/7 open locations" filter, its label, and the
        // Leaflet popup marker.
        $this->assertStringNotContainsString(
            "?>24/7</span>",
            $php,
            'The waste-acceptance badge row must not render its own 24/7 badge. '
            . 'open_24_7 is not an acceptance flag — the Hours cell is its labelled '
            . 'home, and printing it in both places said it twice on every 24/7 '
            . 'drop-off.'
        );
        $this->assertStringContainsString(
            "!empty(\$d['open_24_7']) ? '24/7'",
            $php,
            'The Hours cell must still be the single place a card shows 24/7.'
        );
    }
}
