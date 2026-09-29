<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the "don't scold an untouched form" contract on admin/ba_dropoffs.php.
 *
 * Regression context (2026-09-28): the page painted
 *
 *   "Still missing or invalid: Please select a Barangay (...) ; Please type the
 *    Landmark (...) ; Please fill in the Full Street Address (...)"
 *
 * on first load, before the admin had touched anything, and showed Save as a
 * fully clickable blue button underneath it. Two independent defects, both in
 * the init sequence:
 *
 *  1. emptyForm() ended with refreshSaveBannerFromValidation(). emptyForm() is
 *     a WIPE, but it is also the page's initialiser (called near the end of the
 *     IIFE), so the first thing the page did was ask the validator for a report
 *     and print what was missing from a form nobody had started filling in.
 *
 *  2. renderStepWorkflowUI() hard-coded `btnSaveBottom.disabled = false` for
 *     legacy mode. It runs LAST in init — it is also invoked by
 *     revalidateStepInfoLive() and revalidateStepWasteLive(), which both call it
 *     as their first statement — so it overrode the disable that
 *     setSaveButtonEnabled() had just applied. The button's state therefore
 *     contradicted the message printed directly above it.
 *
 * Fix: an _isPristineForm flag, true only while the form is blank and
 * untouched. It gates the MESSAGE only. setSaveButtonEnabled() is deliberately
 * not gated — Save must stay disabled either way, since the submit gate
 * re-runs the same validateThreeRequired().
 *
 * These assertions are static. bootstrap.php loads no browser and no JS
 * runtime, so the tests verify the source rather than executing it, which is
 * the same approach DropoffHoursFormatTest.php takes.
 */
final class DropoffFormBannerTest extends TestCase
{
    private const PAGE = 'admin/ba_dropoffs.php';

    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function pageSource(): string
    {
        return (string) file_get_contents(self::projectRoot() . '/' . self::PAGE);
    }

    /**
     * Body of a named function, up to the next top-level function declaration.
     */
    private static function functionBody(string $name, bool $stripComments = true): string
    {
        $src = self::pageSource();
        $start = strpos($src, 'function ' . $name . '(');
        if ($start === false) {
            self::fail(self::PAGE . ' must still define ' . $name . '()');
        }
        // Rewind to the start of the line so the declaration is included.
        $body = substr($src, (int) $start);
        $end = strpos($body, "\n    function ", 1);
        $body = $end === false ? $body : substr($body, 0, (int) $end);

        return $stripComments ? self::stripJsComments($body) : $body;
    }

    /**
     * Drop C-style block and line comments from a JS fragment.
     *
     * Needed because several of these assertions are "this name must NOT appear
     * here", and the fix documents each regression in a comment that necessarily
     * quotes the offending name — so a raw substring scan fails on the
     * explanation rather than on the code. NotificationQueueSchemaTest.php
     * reaches for token_get_all() for the same reason.
     *
     * The line-comment rule is guarded with a negative look-behind for a colon,
     * so a scheme or protocol-relative URL inside a string is not mistaken for
     * a comment. Not a full JS lexer; the four function bodies inspected here
     * contain no other comment opener inside a string literal.
     */
    private static function stripJsComments(string $js): string
    {
        $js = preg_replace('#/\*.*?\*/#s', ' ', $js) ?? $js;
        $js = preg_replace('#(?<!:)//[^\n]*#', ' ', $js) ?? $js;
        return $js;
    }

    public function test_empty_form_does_not_ask_for_a_validation_report(): void
    {
        $body = self::functionBody('emptyForm');

        $this->assertStringNotContainsString(
            'refreshSaveBannerFromValidation(',
            $body,
            'emptyForm() must not call refreshSaveBannerFromValidation(). It is the '
            . "page's initialiser as well as the Reset handler, so calling it here\n"
            . 'painted the "Still missing or invalid: ..." triage list on a blank form\n'
            . "on first load. emptyForm() should clear #saveMsg and set the pristine flag."
        );
    }

    public function test_empty_form_marks_the_form_pristine_and_clears_the_banner(): void
    {
        $body = self::functionBody('emptyForm');

        $this->assertMatchesRegularExpression(
            '/_isPristineForm\s*=\s*true\s*;/',
            $body,
            'emptyForm() must set _isPristineForm = true, or Reset form leaves the '
            . 'suppression flag off and the next keystroke is judged against a '
            . 'still-untouched form.'
        );
        $this->assertMatchesRegularExpression(
            "/getElementById\('saveMsg'\)[\s\S]{0,80}?textContent\s*=\s*''/",
            $body,
            'emptyForm() owns clearing #saveMsg. (.ba-form-actions > #saveMsg:empty '
            . 'collapses the row entirely, so an empty element is the desired state.)'
        );
    }

    public function test_reset_wipes_the_form_exactly_once(): void
    {
        $src = self::pageSource();

        $this->assertSame(
            1,
            preg_match_all("/getElementById\('btnReset'\)\?\.addEventListener/", $src),
            '#btnReset must have exactly ONE click listener. It had two: the first '
            . 'called emptyForm(), the second called enterLegacyEditMode(0) + '
            . 'emptyForm() again. Every Reset click therefore ran two full form '
            . 'wipes and two map.setView() calls, and the clean result only held '
            . 'because the first handler happened to run before the second.'
        );
    }

    public function test_legacy_save_button_defers_to_the_validator(): void
    {
        $body = self::functionBody('renderStepWorkflowUI');

        $this->assertStringNotContainsString(
            'btnSaveBottom.disabled = false',
            $body,
            "renderStepWorkflowUI() must not force-enable #btnSave in legacy mode. It\n"
            . 'runs last in the init sequence, so it overrode setSaveButtonEnabled()\n'
            . 'and left Save clickable above a message saying it could not be saved.'
        );
        $this->assertStringContainsString(
            'setSaveButtonEnabled()',
            $body,
            'The legacy branch must delegate to setSaveButtonEnabled(), which reads the '
            . 'same validateThreeRequired() the submit gate uses.'
        );
    }

    public function test_banner_is_suppressed_only_while_the_form_is_pristine(): void
    {
        $body = self::functionBody('refreshSaveBannerFromValidation');

        $this->assertMatchesRegularExpression(
            '/else\s+if\s*\(\s*_isPristineForm\s*\)/',
            $body,
            'The warning branch must be gated on _isPristineForm, and the gate must be '
            . 'an else-if AFTER the issues.length === 0 check so the green '
            . '"All required fields filled" path is unaffected.'
        );
    }

    public function test_the_gate_does_not_also_relax_the_save_button(): void
    {
        $body = self::functionBody('setSaveButtonEnabled');

        $this->assertStringNotContainsString(
            '_isPristineForm',
            $body,
            'setSaveButtonEnabled() must stay UNGATED. Save has to remain disabled on a '
            . 'blank form: the submit gate re-runs validateThreeRequired(), so a '
            . 'clickable Save on an empty form is a dead end, not a feature.'
        );
    }

    /**
     * Every deliberate admin action has to clear the pristine flag, or the
     * banner stays silent after the admin has started and stops being useful.
     *
     * @dataProvider touchPointsProvider
     */
    public function test_every_deliberate_action_marks_the_form_touched(
        string $needle,
        string $why
    ): void {
        $this->assertStringContainsString(
            $needle,
            self::pageSource(),
            'Expected a markFormTouched() call here: ' . $why
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function touchPointsProvider(): array
    {
        return [
            'fAddress input' => [
                "delete el.dataset.autoFilled; markFormTouched();",
                'typing the Full Street Address is the primary way to start.',
            ],
            'fName input' => [
                "el.addEventListener('input', () => { markFormTouched(); setSaveButtonEnabled();",
                'typing the Landmark is the primary way to start.',
            ],
            'fBarangay change' => [
                "el.addEventListener('change', () => { markFormTouched(); setSaveButtonEnabled();",
                'picking a Barangay from the dropdown is a deliberate choice.',
            ],
            'Info-field group' => [
                "el.addEventListener('change', () => { markFormTouched(); revalidateStepInfoLive();",
                'the second listener group covers fType / fStatus / fNotes / fPhoto too.',
            ],
            'hours builder buttons' => [
                "getElementById('customH_add')?.addEventListener('click', () => { markFormTouched();",
                'add / clear / undo in the Hours & Days builder all mutate the form.',
            ],
            'pin placed or dragged' => [
                '_isPristineForm = false; // dropping or dragging the pin is an attempt',
                'updatePinFromLatLng() is the single funnel for map click and dragend.',
            ],
            'existing record loaded' => [
                '_isPristineForm = false; // an existing record is being edited',
                'loadIntoForm() is an edit of real data, not a blank form, so its '
                . 'banner (green or warning) must keep working.',
            ],
        ];
    }
}
