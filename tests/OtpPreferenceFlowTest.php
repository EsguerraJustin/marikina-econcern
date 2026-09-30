<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards the citizen OTP-preference contract end to end.
 *
 * Regression context (2026-09-30): a citizen turned OFF "Require SMS OTP on
 * login (2FA)" and was still asked for an SMS code on the next login. The write
 * and the read are the *same expression against the same column of the same
 * row* --
 *
 *   public/profile.php:27   (int) ($user['otp_enabled'] ?? 1) === 1
 *   public/login.php:97     (int) ($row['otp_enabled']  ?? 1) === 1
 *
 * -- and api/profile_basuraalert.php:226 is the only writer of
 * users.otp_enabled in the whole repo, with no reverter. So the flow was not
 * logically broken; it was *unverifiable*. Three gaps made a failed write
 * indistinguishable from a successful one:
 *
 *   1. save_otp_pref echoed back the value it *intended* to store, never read
 *      it back, and never checked affected_rows. public/profile.php then painted
 *      the "Less secure" badge from that echo, so the citizen saw a green
 *      confirmation for a write that may never have landed.
 *   2. public/login.php had no diagnostic on either branch, while its admin
 *      twin (admin/login.php:90) logs login_admin_success_otp_disabled. Neither
 *      outcome was observable in app_error.log, so a repeat was undiagnosable.
 *   3. public/login_otp.php never re-read users.otp_enabled and had no
 *      is_logged_in() guard, so any surviving pending challenge rendered the OTP
 *      screen regardless of the current preference. Its admin twin,
 *      admin/login_otp.php:9-11, does have that guard.
 *
 * These are static, file-reading tests on purpose: tests/bootstrap.php loads no
 * DB, and the failure mode only shows up at runtime against a live schema.
 */
final class OtpPreferenceFlowTest extends TestCase
{
    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function read(string $rel): string
    {
        $path = self::projectRoot() . '/' . $rel;
        self::assertFileExists($path, "Missing expected source file: {$rel}");
        return (string) file_get_contents($path);
    }

    /**
     * The body of one `if ($action === '<name>') { ... }` block, from the
     * opening test to the end of file. The action blocks in
     * api/profile_basuraalert.php are the tail of the file and each ends in a
     * terminating json_response(), so slicing to EOF is unambiguous here.
     */
    private static function actionBlock(string $rel, string $action): string
    {
        $src = self::read($rel);
        $needle = "\$action === '" . $action . "'";
        $pos = strpos($src, $needle);
        self::assertNotFalse($pos, "Could not find action '{$action}' in {$rel}");

        // Walk to the opening brace of the block.
        $brace = strpos($src, '{', $pos);
        self::assertNotFalse($brace, "Action '{$action}' in {$rel} has no block");

        return substr($src, $brace);
    }

    /** Anti-vacuity: the scanner must actually see the file it claims to check. */
    public function test_scanner_actually_reads_every_guarded_file(): void
    {
        $files = [
            'api/profile_basuraalert.php',
            'public/login.php',
            'public/login_otp.php',
            'public/verify_email.php',
        ];
        foreach ($files as $rel) {
            $src = self::read($rel);
            $this->assertGreaterThan(
                500,
                strlen($src),
                "{$rel} read as suspiciously small -- the scanner is not seeing the real file"
            );
        }
    }

    /**
     * Gap 1: the save must prove it landed.
     *
     * Requiring a read-back SELECT (or an affected_rows check) is what stops
     * the response from asserting a value the database never received.
     */
    public function test_save_otp_pref_verifies_the_write_before_reporting_success(): void
    {
        $block = self::actionBlock('api/profile_basuraalert.php', 'save_otp_pref');

        $readsBack = (bool) preg_match('/SELECT[^;\'"]*\botp_enabled\b/is', $block);
        $checksAffected = str_contains($block, 'affected_rows');

        $this->assertTrue(
            $readsBack || $checksAffected,
            'save_otp_pref reports the value it INTENDED to store without reading it back or '
            . 'checking affected_rows. The client paints the switch state from this response, so a '
            . 'write that never landed looks identical to one that did.'
        );
    }

    /**
     * Gap 1 (cont.): the response must echo what is STORED, not the POST input.
     *
     * If the JSON still reports the raw $otpEnabled local, the citizen-facing
     * badge is a rendering of intent rather than of state.
     */
    public function test_save_otp_pref_response_reports_the_persisted_value(): void
    {
        $block = self::actionBlock('api/profile_basuraalert.php', 'save_otp_pref');

        $this->assertMatchesRegularExpression(
            '/[\'"]otp_enabled[\'"]\s*=>\s*\$[A-Za-z_][A-Za-z0-9_]*/i',
            $block,
            "save_otp_pref must return the value it read back from the database into a variable, "
            . "not the raw \$otpEnabled request value"
        );

        $this->assertDoesNotMatchRegularExpression(
            '/[\'"]otp_enabled[\'"]\s*=>\s*\$otpEnabled\b/',
            $block,
            "save_otp_pref echoes the request value straight back as 'otp_enabled'. That is the "
            . 'exact value that was never confirmed against the database.'
        );
    }

    /** Gap 1 (cont.): the write must be observable in app_error.log. */
    public function test_save_otp_pref_emits_a_diagnostic(): void
    {
        $block = self::actionBlock('api/profile_basuraalert.php', 'save_otp_pref');

        $this->assertTrue(
            str_contains($block, '_auth_diag(') || str_contains($block, 'error_log('),
            'save_otp_pref writes the citizen\'s 2FA setting with no log line at all, so a failed '
            . 'write leaves no trace in app_error.log.'
        );
    }

    /**
     * Gap 2: the login decision must be observable on BOTH branches.
     *
     * The admin portal already does this (admin/login.php:90). Without a citizen
     * twin, "was OTP skipped or demanded?" is unanswerable after the fact,
     * which is the entire reason the original report could not be diagnosed.
     */
    public function test_login_logs_both_the_bypass_and_the_otp_demand(): void
    {
        $src = self::read('public/login.php');

        $this->assertStringContainsString(
            'login_success_otp_disabled',
            $src,
            'public/login.php does not log the OTP-disabled bypass. Its admin twin does '
            . '(admin/login.php:90 login_admin_success_otp_disabled).'
        );

        $this->assertStringContainsString(
            'login_otp_required',
            $src,
            'public/login.php does not log the OTP-required branch either, so neither outcome leaves '
            . 'a trace in app_error.log.'
        );
    }

    /**
     * Gap 3: the OTP screen must be unreachable once the citizen has opted out.
     *
     * public/login_otp.php is the only page that can render the code prompt, and
     * it was the only OTP screen in the app without an is_logged_in() guard --
     * admin/login_otp.php:9-11 has had one all along.
     */
    public function test_login_otp_page_guards_a_logged_in_session(): void
    {
        $src = self::read('public/login_otp.php');

        $this->assertStringContainsString(
            'is_logged_in()',
            $src,
            'public/login_otp.php has no is_logged_in() guard. admin/login_otp.php:9-11 has one; '
            . 'without it a surviving pending challenge renders the prompt for an authenticated user.'
        );
    }

    /** Gap 3 (cont.): it must also re-read the stored preference, not trust the session. */
    public function test_login_otp_page_rereads_the_stored_otp_preference(): void
    {
        $src = self::read('public/login_otp.php');

        $this->assertStringContainsString(
            'otp_enabled',
            $src,
            'public/login_otp.php never re-reads users.otp_enabled. The preference can be turned off '
            . 'while a challenge is pending, and the prompt would still be served.'
        );
    }

    /**
     * Gap 4: a failed login must not leave a challenge behind.
     *
     * get_active_otp() returns the newest unconsumed row and
     * invalidate_active_otps() only runs when a NEW code is created, so an
     * abandoned challenge otherwise outlives the login that created it.
     */
    public function test_failed_login_clears_the_pending_otp_state(): void
    {
        $src = self::read('public/login.php');

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($src, 'clear_pending_login_state()'),
            'clear_pending_login_state() appears once in public/login.php (the OTP-disabled success '
            . 'path at :99). The failed-attempt branch at :51-57 must clear it too.'
        );
    }

    /**
     * Gap 5: the email-verification auto-login must honour the preference.
     *
     * public/verify_email.php:31 called login_user() unconditionally, so
     * consuming a valid verification token signed the citizen in with 2FA
     * completely bypassed -- inconsistent with public/login.php:97-108.
     */
    public function test_verify_email_does_not_bypass_the_otp_preference(): void
    {
        $src = self::read('public/verify_email.php');

        $this->assertStringContainsString(
            'otp_enabled',
            $src,
            'public/verify_email.php auto-logs the citizen in with login_user() and never consults '
            . 'users.otp_enabled, so a verification link is a full 2FA bypass.'
        );
    }
}
