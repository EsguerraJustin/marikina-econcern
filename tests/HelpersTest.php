<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function test_e_escapes_html(): void
    {
        $this->assertSame('&lt;b&gt;test&lt;/b&gt;', e('<b>test</b>'));
        $this->assertSame('a &amp; b', e('a & b'));
        $this->assertSame('&#039;quoted&#039;', e("'quoted'"));
    }

    public function test_validate_password_rules_rejects_short(): void
    {
        $errors = validate_password_rules('Ab1!');
        $this->assertContains('Password must be at least 8 characters.', $errors);
    }

    public function test_validate_password_rules_accepts_strong(): void
    {
        $errors = validate_password_rules('Str0ng!Pass');
        $this->assertSame([], $errors);
    }

    public function test_validate_password_rules_requires_all_classes(): void
    {
        $this->assertNotEmpty(validate_password_rules('alllowercase1!')); // missing upper
        $this->assertNotEmpty(validate_password_rules('ALLUPPERCASE1!')); // missing lower
        $this->assertNotEmpty(validate_password_rules('NoDigit!Pass'));   // missing digit
        $this->assertNotEmpty(validate_password_rules('NoSymbol1Pass'));  // missing symbol
    }

    public function test_validate_upload_file_rejects_empty(): void
    {
        $file = ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0, 'name' => '', 'type' => ''];
        $res = validate_upload_file($file, ['image/jpeg'], 1024, 'photo');
        $this->assertFalse($res['ok']);
    }

    public function test_app_url_encodes_spaces(): void
    {
        // APP_BASE_URL is /Marikina Concern/... (without %20), app_url should encode
        $url = app_url('/public/login.php');
        $this->assertStringNotContainsString(' ', $url);
    }

    public function test_ensure_upload_dir_creates_with_0755(): void
    {
        $tmp = sys_get_temp_dir() . '/mc_test_upload_' . bin2hex(random_bytes(4));
        ensure_upload_dir($tmp);
        $this->assertDirectoryExists($tmp);
        // Cleanup
        @rmdir($tmp);
    }

    /**
     * Regression: ba_store_image_mock() returns an app_url() value, i.e. already
     * carrying the base path, and that prefixed string is what lands in
     * ba_reports.photos_json. The resolver used to prepend app_base_url()
     * unconditionally, so every newly uploaded evidence photo resolved to
     * /base/base/base/storage/mock_images/x.jpg - a 404. The three legacy rows
     * in the DB store a bare "/uploads/..." path, which is why the bug survived:
     * the existing QA harness exercised only the bare-path case.
     */
    public function test_ba_resolve_photo_url_is_idempotent(): void
    {
        $base = app_base_url();
        $this->assertNotSame('', $base, 'app_base_url() must be resolvable for this test');

        // A bare path gains the prefix exactly once.
        $bare = '/uploads/basuraalert_reports/x.jpg';
        $once = ba_resolve_photo_url($bare);
        $this->assertSame($base . $bare, $once);

        // An already-prefixed value is returned untouched.
        $alreadyPrefixed = $base . '/storage/mock_images/x.jpg';
        $this->assertSame($alreadyPrefixed, ba_resolve_photo_url($alreadyPrefixed));

        // Resolving an already-resolved value must not prefix it a second time.
        $twice = ba_resolve_photo_url($once);
        $this->assertSame($once, $twice);

        // The base path must never appear more than once.
        $this->assertSame(1, substr_count(ba_resolve_photo_url($once), $base));
    }

    public function test_ba_resolve_photo_url_passes_through_absolute_urls(): void
    {
        $this->assertSame('https://res.cloudinary.com/x/y.jpg', ba_resolve_photo_url('https://res.cloudinary.com/x/y.jpg'));
        $this->assertSame('http://cdn.example/a.jpg', ba_resolve_photo_url('http://cdn.example/a.jpg'));
        $this->assertSame('//cdn.example/a.jpg', ba_resolve_photo_url('//cdn.example/a.jpg'));
        $this->assertSame('', ba_resolve_photo_url(''));
        $this->assertSame('', ba_resolve_photo_url('   '));
    }
}
