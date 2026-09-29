<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the FAQ editor to the shared centred-dialog treatment.
 *
 * Regression context (2026-09-28): admin/ba_feedback.php's Edit FAQ dialog was
 * a Bootstrap `offcanvas offcanvas-end` drawer at
 * `--bs-offcanvas-width:540px`. A drawer pins itself to one screen edge, so it
 * rendered as a full-height panel glued to the right of the screen with a
 * sliver of the FAQ list showing at the left and nothing centred — and because
 * the offcanvas footer is not pinned, Cancel/Save floated mid-panel above a
 * dead zone. That is verbatim the problem PROJECT_GUIDE.md records for
 * ba_schedules / ba_waste / ba_announcements, which were already migrated to
 * the shared `.mc-editor-dialog` block in common.css. This page was the last
 * offcanvas in the repository.
 *
 * The migration is the one the guide documents: only the Bootstrap event name
 * changes in JS (`show.bs.offcanvas` -> `show.bs.modal`), because Bootstrap
 * passes `relatedTarget` on both and the prefill block only ever read the
 * trigger's `data-mode` and its row.
 */
final class FaqEditorDialogTest extends TestCase
{
    private const PAGE = 'admin/ba_feedback.php';
    private const SHEET = 'assets/css/admin/ba_feedback.css';

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

    /**
     * Drop T_COMMENT / T_DOC_COMMENT, then HTML comments.
     *
     * Both are needed: token_get_all() treats everything outside <?php ?> as
     * T_INLINE_HTML, so an explanatory `<!-- ... -->` inside the markup is NOT
     * reported as a comment and would otherwise satisfy — or break — an
     * assertion about what the page's code says.
     */
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
        return preg_replace('#<!--.*?-->#s', ' ', $out) ?? $out;
    }

    private static function css(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', ' ', $css) ?? $css;
    }

    public function test_the_editor_uses_the_shared_dialog_primitive(): void
    {
        $code = self::code(self::read(self::PAGE));

        $this->assertStringContainsString(
            'mc-editor-dialog',
            $code,
            'The FAQ editor must use the shared .mc-editor-dialog block from '
            . 'common.css, the same one ba_schedules / ba_waste / ba_announcements use.'
        );
        $this->assertStringContainsString(
            'modal-dialog-centered',
            $code,
            'The dialog has to be centred. The whole point of the change.'
        );
    }

    public function test_no_offcanvas_survives_in_the_page(): void
    {
        $code = self::code(self::read(self::PAGE));

        foreach ([
            'offcanvas' => 'markup class or Bootstrap event name',
            'offcanvas-header' => 'header',
            'offcanvas-body' => 'body',
            'offcanvas-footer' => 'footer',
            'offcanvas-title' => 'title',
            'show.bs.offcanvas' => 'the show listener',
            'data-bs-toggle="offcanvas"' => 'a trigger',
            'data-bs-dismiss="offcanvas"' => 'a dismiss control',
        ] as $needle => $what) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                'No ' . $what . ' may still name an offcanvas. Bootstrap will not '
                . 'raise an error for `data-bs-toggle="offcanvas"` pointing at a '
                . 'modal element — the button simply does nothing, and the FAQ editor '
                . 'becomes unreachable with no visible cause.'
            );
        }
    }

    public function test_both_triggers_and_the_dismiss_controls_were_converted(): void
    {
        $code = self::code(self::read(self::PAGE));

        $this->assertSame(
            2,
            substr_count($code, 'data-bs-toggle="modal" data-bs-target="#faqEditor"'),
            'There are two triggers — "New FAQ" in the toolbar and "Edit" on each row. '
            . 'Converting only one leaves the other silently dead.'
        );
        $this->assertSame(
            2,
            substr_count($code, 'data-bs-dismiss="modal"'),
            'The .btn-close and the Cancel button both dismiss. Both must be converted.'
        );
        $this->assertStringContainsString(
            'show.bs.modal',
            $code,
            'The prefill listener must be on the modal event. show.bs.offcanvas never '
            . 'fires for a modal, so the form would open blank.'
        );
    }

    public function test_the_id_contract_the_listener_reads_is_intact(): void
    {
        $code = self::code(self::read(self::PAGE));

        // The prefill block assigns to these by id. If the markup is renamed and
        // the script is not, every one of these throws and the dialog opens empty
        // — the same class of failure as the citizen-edit modal in guide item 19.
        foreach ([
            'faqLbl', 'faqId', 'faqQ', 'faqA', 'faqCategory', 'faqStatus', 'faqSaveBtn',
        ] as $id) {
            $this->assertStringContainsString(
                'id="' . $id . '"',
                $code,
                '#' . $id . ' must be declared in the dialog markup.'
            );
            $this->assertStringContainsString(
                'getElementById("' . $id . '")',
                $code,
                '#' . $id . ' must be read by the prefill listener. A rename on one '
                . 'side only opens the dialog empty with no visible error.'
            );
        }

        // The alert is reached through the clearAlert()/setAlert() helpers,
        // which take a "#id" selector rather than a getElementById() argument.
        $this->assertStringContainsString(
            'id="faqEditorAlert"',
            $code,
            '#faqEditorAlert must be declared in the dialog footer.'
        );
        foreach (['clearAlert("#faqEditorAlert")', 'setAlert("#faqEditorAlert"'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $code,
                '#faqEditorAlert must be reachable from the script via the alert '
                . 'helpers. Expected: ' . $needle
            );
        }
    }

    public function test_the_footer_alert_rule_was_added_for_this_page(): void
    {
        $css = self::css(self::read(self::SHEET));

        $this->assertStringContainsString(
            '#faqEditorAlert',
            $css,
            'The shared .mc-editor-dialog footer block only knows #editorAlert, which '
            . 'is the id on the other three pages. Without a rule for this page\'s '
            . '#faqEditorAlert it would sit inline beside the buttons instead of '
            . 'taking its own full-width row above them.'
        );
        $this->assertStringContainsString(
            '#faqEditorAlert:empty',
            $css,
            'An empty alert must collapse, or the footer holds a dead full-width row.'
        );
    }

    public function test_the_dead_offcanvas_declarations_are_gone_from_this_sheet(): void
    {
        $css = self::css(self::read(self::SHEET));

        $this->assertStringNotContainsString(
            '.mc-admin-offcanvas-form',
            $css,
            'This page was the last markup consumer of .mc-admin-offcanvas-form in the '
            . 'repository, so the declaration is dead here.'
        );
        $this->assertStringNotContainsString(
            '--bs-offcanvas-width',
            $css,
            'The drawer width token is meaningless without a drawer.'
        );
    }
}
