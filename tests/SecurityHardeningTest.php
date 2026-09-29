<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the two security fixes.
 *
 * Both exist because a live problem was confirmed by probe, not suspected:
 *   - GET /PROJECT_GUIDE.md returned HTTP 200 with Content-Length matching the
 *     file byte-for-byte, serving the Cloudinary API secret, the unsigned upload
 *     preset, Brevo/TextBee credentials and the production DB and FTP passwords.
 *     The .htaccess rules and the redaction are what stop it recurring, and
 *     test_blocked_paths_cannot_be_reopened asserts those rules are still there.
 *   - require_csrf_token() called redirect() with $_SERVER['HTTP_REFERER'] before
 *     authentication, and redirect() passed it straight into a Location header.
 *
 * Every case here is a shape that was actually tried against the running app.
 */
final class SecurityHardeningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    // ------------------------------------------------------- open redirect

    public function test_redirect_allows_ordinary_same_origin_paths(): void
    {
        $this->assertSame('/public/login.php', safe_redirect_target('/public/login.php'));
        $this->assertSame('/admin/profile.php?tab=1', safe_redirect_target('/admin/profile.php?tab=1'));
        $this->assertSame('/', safe_redirect_target('/'));
    }

    public function test_redirect_trims_surrounding_whitespace(): void
    {
        $this->assertSame('/public/login.php', safe_redirect_target("  /public/login.php \n"));
    }

    /**
     * @dataProvider offOriginTargets
     */
    public function test_redirect_rejects_off_origin_targets(string $input): void
    {
        $got = safe_redirect_target($input);
        $this->assertSame(
            app_url('/'),
            $got,
            "expected the app root as fallback, got: $got"
        );
    }

    public static function offOriginTargets(): array
    {
        return [
            'absolute https'          => ['https://evil.example/steal'],
            'absolute http'           => ['http://evil.example'],
            'mixed case scheme'       => ['HtTpS://EVIL.example/'],
            'protocol relative'       => ['//evil.example/steal'],
            'slash backslash'         => ['/\\evil.example/steal'],
            'double backslash'        => ['\\\\evil.example/steal'],
            'triple backslash'       => ['\\\\\\evil.example/steal'],
            'javascript scheme'       => ['javascript:alert(1)'],
            'data scheme'             => ['data:text/html,<script>alert(1)</script>'],
            'header injection CRLF'   => ["/ok\r\nSet-Cookie: a=b"],
            'header injection LF'     => ["/ok\nLocation: https://evil.example"],
            'bare relative'           => ['relative/path.php'],
            'empty'                   => [''],
            'whitespace only'         => ['   '],
        ];
    }

    public function test_single_leading_backslash_stays_on_our_origin(): void
    {
        // One backslash normalises to a single slash, i.e. a rooted path on this
        // host. It is not an off-origin redirect, so it is allowed - but it must
        // still be a rooted path with no scheme and no authority.
        $got = safe_redirect_target('\evil.example/steal');
        $this->assertStringStartsWith('/', $got);
        $this->assertStringNotContainsString('//', $got);
        $this->assertDoesNotMatchRegularExpression('#^[a-z]+://#i', $got);
    }

    public function test_our_own_absolute_url_is_still_allowed(): void
    {
        // In production APP_BASE_URL is a full https URL, so app_url() returns an
        // absolute same-host URL. Blocking that would break every redirect.
        $self = app_url('/');
        $this->assertSame($self, safe_redirect_target($self));
    }

    // ------------------------------------------------ maintenance endpoints

    /**
     * @dataProvider maintenanceScripts
     */
    public function test_maintenance_scripts_are_guarded(string $file, string $marker): void
    {
        $path = $this->root . '/' . $file;
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString(
            'require_maintenance_authorization(',
            $src,
            "$file has no authorization call; it is reachable over HTTP"
        );
        $this->assertStringContainsString($marker, $src);
    }

    public static function maintenanceScripts(): array
    {
        return [
            // cron worker, browser path now needs a super admin
            'dispatcher' => ['ba_dispatch_queued_notifications.php', 'current_admin(db())'],
            'reminders'  => ['ba_run_reminders.php', 'current_admin(db())'],
            // schema migrations are CLI-only (null admin)
            'ba migration'  => ['_run_basuraalert_migration.php', 'null'],
            'reports column' => ['_migrate_ba_reports_category_varchar.php', 'null'],
        ];
    }

    public function test_every_guarded_script_guards_before_it_acts(): void
    {
        // The guard has to come before the "no ?run=1" affordance, otherwise the
        // affordance still decides who may run the job.
        foreach (['ba_dispatch_queued_notifications.php', 'ba_run_reminders.php'] as $file) {
            $src = (string) file_get_contents($this->root . '/' . $file);
            $guard = strpos($src, 'require_maintenance_authorization(');
            $gate  = strpos($src, "\$_GET['run']");
            $this->assertNotFalse($guard, "$file: guard not found");
            $this->assertNotFalse($gate, "$file: run gate not found");
            $this->assertLessThan(
                $gate,
                $guard,
                "$file: the authorization call must precede the ?run=1 check"
            );
        }
    }

    // ------------------------------------------------- credential exposure

    public function test_blocked_paths_cannot_be_reopened(): void
    {
        $htaccess = (string) file_get_contents($this->root . '/.htaccess');
        foreach ([
            '^PROJECT_GUIDE\.md$'       => 'the guide was served with live credentials',
            '^composer\.(json|lock)$'   => 'composer.json names the dependency surface',
            '^repomix\.config\.json$'   => 'build config',
            '^\.env'                    => 'secrets',
            '^app_error\.log$'          => 'the log may contain PII',
            '\.(md|markdown)$'          => 'generic markdown backstop',
            '(^|/)\.'                   => 'generic dotfile backstop',
        ] as $needle => $why) {
            $this->assertStringContainsString(
                $needle,
                $htaccess,
                ".htaccess no longer blocks this path ($why)"
            );
        }
    }

    public function test_project_guide_carries_no_live_secret_values(): void
    {
        $guide = (string) file_get_contents($this->root . '/PROJECT_GUIDE.md');

        // Every secret-bearing row must have been replaced with the placeholder.
        foreach ([
            'API Secret',
            'API Key',
            'Upload Preset (unsigned)',
            'BREVO API KEY (V3)',
            'SMTP Password',
            'SMTP Username',
            'TEXTBEE API KEY',
            'CALENDARIFIC API KEY',
            'OPENWEATHER API KEY',
            'FTP Password',
        ] as $label) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($label, '/') . '\*?\*?\s*\|\s*`?<redacted/',
                $guide,
                "$label still has a real value in PROJECT_GUIDE.md"
            );
        }

        // The production database credentials sit in a Local|Live table, so the
        // Live cell must be redacted while the XAMPP defaults stay readable.
        // [^\|]* rather than a literal dash: the file uses an em dash there and
        // a hard-coded hyphen silently stops matching.
        $this->assertMatchesRegularExpression(
            '/Password\*{0,2}[ \t]*\|[ \t]*_\(blank[^|]*\|[ \t]*`?<redacted/',
            $guide,
            'the production database password is still present'
        );
        // Local column must NOT have been destroyed by the redaction.
        $this->assertStringContainsString('`localhost`', $guide);
        $this->assertStringContainsString('`root`', $guide);
        $this->assertStringContainsString('`e_concern`', $guide);

        // And the file must carry the rotation notice, so nobody assumes the
        // redaction means the exposed values are safe again.
        $this->assertStringContainsString('must still be rotated', $guide);
    }

    public function test_env_example_carries_no_real_values(): void
    {
        $env = (string) file_get_contents($this->root . '/.env.example');

        // Secrets that must be left completely empty in a template.
        // NOTE: [ \t]* not \s* - \s matches a newline, so `MAIL_PASSWORD=\n\n# ---`
        // would otherwise "have a value" (the next line's #) and fail.
        foreach ([
            'MAIL_PASSWORD',
            'BREVO_API_KEY',
            'SMS_TEXTBEE_API_KEY',
            'CLOUDINARY_API_KEY',
            'CLOUDINARY_API_SECRET',
            'CLOUDINARY_UPLOAD_PRESET',
            'CALENDARIFIC_API_KEY',
            'OPENWEATHER_API_KEY',
            'SETUP_RECOVERY_KEY',
        ] as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[ \t]*' . $key . '[ \t]*=[ \t]*\S+/m',
                $env,
                "$key still has a real value in .env.example"
            );
        }

        // The two that became placeholders rather than empty.
        $this->assertMatchesRegularExpression('/^MAIL_USERNAME[ \t]*=[ \t]*your-/m', $env);
        $this->assertMatchesRegularExpression('/^MAIL_FROM_ADDRESS[ \t]*=[ \t]*no-reply@/m', $env);

        // A commented-out line is still a committed credential, so the
        // production block must hold placeholders too. This one leaked: the real
        // DB password sat behind a '#'.
        foreach (['DB_PASS', 'DB_HOST', 'DB_USER', 'DB_NAME'] as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[ \t]*#[ \t]*' . $key . '[ \t]*=[ \t]*(?!your-)\S+/m',
                $env,
                "the production $key is still present in the commented block"
            );
        }
    }

    public function test_internal_docs_are_gitignored(): void
    {
        $gi = (string) file_get_contents($this->root . '/.gitignore');
        $this->assertMatchesRegularExpression('/^\*\.md$/m', $gi);
        $this->assertMatchesRegularExpression('/^PROJECT_GUIDE\.md$/m', $gi);
        // .env stays ignored and only the example is un-ignored.
        $this->assertMatchesRegularExpression('/^\.env$/m', $gi);
        $this->assertMatchesRegularExpression('/^!\.env\.example$/m', $gi);
    }
}
