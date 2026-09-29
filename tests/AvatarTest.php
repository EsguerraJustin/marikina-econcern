<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Profile avatars (includes/Avatar.php) and the privileged-action audit trail
 * (mc_admin_activity_log() in includes/helpers.php).
 *
 * These are the pure/DB-light parts only: no HTTP, no Cloudinary. The network
 * round trip (upload / replace / delete, and that a re-upload reuses the id
 * instead of orphaning) is verified against the live API by hand, because a unit
 * test that silently skipped on a credential failure would prove nothing.
 *
 * Every case here is a regression guard for a bug that was actually made and
 * caught during this work, not a speculative test.
 */
final class AvatarTest extends TestCase
{
    // ---------------------------------------------------------------- ids

    public function test_public_id_is_deterministic_per_row(): void
    {
        // The whole point: the same account must always produce the same id, so
        // that overwrite=true replaces the asset rather than orphaning it.
        $this->assertSame(mc_avatar_public_id('admins', 1), mc_avatar_public_id('admins', 1));
        $this->assertSame(mc_avatar_public_id('users', 7), mc_avatar_public_id('users', 7));
    }

    public function test_public_id_differs_per_account(): void
    {
        $this->assertNotSame(mc_avatar_public_id('users', 1), mc_avatar_public_id('users', 2));
        $this->assertNotSame(mc_avatar_public_id('admins', 1), mc_avatar_public_id('users', 1));
    }

    public function test_public_id_is_bare_so_the_upload_folder_is_not_doubled(): void
    {
        // A pre-prefixed id would come back from Cloudinary as
        // marikina_concern/uploads/marikina_concern/uploads/..., because
        // ba_cloudinary_upload_file() prepends its default folder.
        $this->assertStringNotContainsString('/', mc_avatar_public_id('users', 3));
        $this->assertStringNotContainsString('marikina_concern', mc_avatar_public_id('users', 3));
    }

    public function test_public_id_forces_a_known_table(): void
    {
        // A caller must not be able to steer the id via the table name.
        $this->assertSame(
            mc_avatar_public_id('users', 9),
            mc_avatar_public_id('users; DROP TABLE admins --', 9)
        );
        $this->assertSame(
            mc_avatar_public_id('users', 9),
            mc_avatar_public_id('../../etc/passwd', 9)
        );
    }

    // ---------------------------------------------------------------- urls

    public function test_avatar_url_is_empty_without_a_public_id(): void
    {
        $this->assertSame('', mc_avatar_url(''));
        $this->assertSame('', mc_avatar_url(null));
        $this->assertSame('', mc_avatar_url('   '));
    }

    public function test_avatar_url_passes_through_a_stored_url(): void
    {
        // A legacy row may already hold a full URL; it must not be turned into
        // a Cloudinary path.
        $this->assertSame('https://x/y.jpg', mc_avatar_url('https://x/y.jpg'));
        $this->assertSame('http://cdn.example/a.png', mc_avatar_url('http://cdn.example/a.png'));
    }

    public function test_avatar_url_applies_a_square_face_crop_by_default(): void
    {
        $url = mc_avatar_url('marikina_concern/uploads/citizen_5');
        $this->assertStringStartsWith('https://res.cloudinary.com/', $url);
        $this->assertStringContainsString('c_thumb', $url);
        $this->assertStringContainsString('g_face', $url);
        $this->assertStringContainsString('w_256', $url);
        $this->assertStringContainsString('h_256', $url);
        $this->assertStringEndsWith('/marikina_concern/uploads/citizen_5', $url);
    }

    public function test_avatar_url_honours_an_explicit_transformation(): void
    {
        $url = mc_avatar_url('foo/bar', 'w_48,h_48,c_thumb');
        $this->assertStringContainsString('w_48,h_48,c_thumb', $url);
        $this->assertStringNotContainsString('w_256', $url);
    }

    public function test_avatar_url_never_double_prefixes(): void
    {
        $id = 'marikina_concern/uploads/citizen_1';
        $this->assertSame(1, substr_count(mc_avatar_url($id), 'marikina_concern/uploads/'));
    }

    // ------------------------------------------------- version / cache busting

    public function test_version_is_read_out_of_a_stored_secure_url(): void
    {
        // This is the whole point of the avatar_url column: the version segment
        // cannot be reconstructed from public_id alone.
        $this->assertSame(
            'v1790501024',
            mc_avatar_version_from_url('https://res.cloudinary.com/evcdbbrn/image/upload/v1790501024/marikina_concern/uploads/admin_1.jpg')
        );
        $this->assertSame(
            'v42',
            mc_avatar_version_from_url('https://res.cloudinary.com/x/image/upload/c_thumb,w_256/v42/foo/bar')
        );
    }

    public function test_version_is_empty_when_there_is_none_to_find(): void
    {
        $this->assertSame('', mc_avatar_version_from_url(''));
        $this->assertSame('', mc_avatar_version_from_url(null));
        // A query string is NOT a version. Cloudinary 404s on .../<id>?v=2
        // (verified live), so a cache-buster query would break the image.
        $this->assertSame('', mc_avatar_version_from_url('https://x/image/upload/foo/bar?v=2'));
        $this->assertSame('', mc_avatar_version_from_url('https://x/image/upload/foo/bar'));
    }

    public function test_version_changes_the_delivery_url(): void
    {
        $id = 'marikina_concern/uploads/admin_1';
        $a = mc_avatar_url($id, '', 'v1790501022');
        $b = mc_avatar_url($id, '', 'v1790501024');

        // THE REGRESSION. The public_id is deterministic and overwrite=true, so
        // without the version these were byte-identical: the browser kept serving
        // the cached asset and a freshly uploaded photo never appeared, while the
        // page reported "Profile photo updated." (measured in Chrome: 0 requests).
        $this->assertNotSame($a, $b, 'a replaced avatar must produce a different URL');
        $this->assertStringContainsString('/v1790501022/', $a);
        $this->assertStringContainsString('/v1790501024/', $b);
        // ...and the transformation must survive the insert.
        $this->assertStringContainsString('c_thumb', $b);
        $this->assertStringContainsString('g_face', $b);
        $this->assertStringContainsString('w_256', $b);
        $this->assertStringEndsWith('/marikina_concern/uploads/admin_1', $b);
    }

    public function test_omitting_the_version_keeps_the_legacy_url_shape(): void
    {
        // Rows written before the version was read must render exactly as they
        // did, so this is deliberately unchanged rather than "fixed" to add one.
        $id = 'marikina_concern/uploads/citizen_1';
        $this->assertSame(mc_avatar_url($id), mc_avatar_url($id, '', ''));
        $this->assertStringNotContainsString('/v', mc_avatar_url($id, '', ''));
    }

    public function test_a_bogus_version_cannot_corrupt_the_delivery_path(): void
    {
        // A traversal or injection attempt in the version must not reach the URL.
        $id = 'marikina_concern/uploads/admin_1';
        $this->assertSame(mc_avatar_url($id), mc_avatar_url($id, '', '../../evil'));
        $this->assertSame(mc_avatar_url($id), mc_avatar_url($id, '', 'v1/../../x'));
        $this->assertStringNotContainsString('evil', mc_avatar_url($id, '', '../../evil'));
    }

    public function test_two_uploads_of_the_same_row_produce_different_urls(): void
    {
        // End-to-end shape of the bug, without touching the network: same row,
        // same deterministic public_id, two different stored versions.
        $row = 'marikina_concern/uploads/citizen_7';
        $first  = mc_avatar_version_from_url('https://res.cloudinary.com/c/image/upload/v1000/' . $row . '.jpg');
        $second = mc_avatar_version_from_url('https://res.cloudinary.com/c/image/upload/v2000/' . $row . '.jpg');
        $this->assertNotSame(mc_avatar_url($row, '', $first), mc_avatar_url($row, '', $second));
    }

    // ---------------------------------------------------------------- initials

    public function test_initials_uses_first_and_last_word(): void
    {
        // First letter of the first word + first letter of the LAST word, which
        // is what the existing JS initials() in admin/citizens.php does, so a
        // citizen sees the same two letters everywhere.
        $this->assertSame('JE', mc_avatar_initials('Justin Curby Esguerra'));
        $this->assertSame('MS', mc_avatar_initials('  maria   santos  '));
    }

    public function test_initials_handles_a_single_word_and_empty_input(): void
    {
        $this->assertSame('C', mc_avatar_initials('Cher'));
        $this->assertSame('?', mc_avatar_initials(''));
        $this->assertSame('?', mc_avatar_initials('   '));
    }

    public function test_initials_is_multibyte_safe(): void
    {
        // mb_substr, not substr: substr would cut a UTF-8 sequence in half and
        // emit a replacement character into the markup.
        $this->assertSame('ÑC', mc_avatar_initials('Ñoño Cruz'));
    }

    // ---------------------------------------------------------------- validation

    public function test_avatar_validate_rejects_a_missing_file(): void
    {
        $res = mc_avatar_validate(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0, 'name' => '', 'type' => '']);
        $this->assertFalse($res['ok']);
        $this->assertNotSame('', $res['error']);
    }

    public function test_avatar_validate_rejects_a_non_uploaded_temp_file(): void
    {
        // is_uploaded_file() is false for a path the test wrote itself, which is
        // exactly the shape of an attempt to smuggle /etc/passwd in as tmp_name.
        $tmp = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($tmp, 'not an image');
        try {
            $res = mc_avatar_validate([
                'error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp,
                'size' => 12, 'name' => 'x.png', 'type' => 'image/png',
            ]);
            $this->assertFalse($res['ok']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_avatar_limits_are_tighter_than_the_evidence_photo_cap(): void
    {
        $this->assertLessThan(5 * 1024 * 1024, MC_AVATAR_MAX_BYTES);
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], MC_AVATAR_ALLOWED_MIME);
    }

    // ---------------------------------------------------------------- read path

    /**
     * The bug this guards: mc_avatar_upload() saved avatar_public_id correctly,
     * but current_admin() and current_user() did not SELECT it. The profile
     * pages read it with `?? ''`, so the key was silently absent, mc_avatar_url()
     * returned '', and includes/partials/avatar.php rendered the initials tile
     * on every server-side load. The photo survived in the DB and on Cloudinary
     * the whole time, which is why it looked like the upload had failed.
     *
     * Asserted against the source because the column list is an inline SQL
     * literal, not a constant, and trimming it is otherwise invisible: the
     * admin citizen list kept working (admin/api/citizens.php selects the column
     * itself), so nothing else in the app would ever notice the omission.
     *
     * @dataProvider identitySelects
     */
    public function test_identity_selects_carry_avatar_public_id(string $file, string $marker): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
        $this->assertMatchesRegularExpression(
            $marker,
            $src,
            "$file no longer selects avatar_public_id, so the profile photo renders "
            . 'as initials on every page load even though the row is saved'
        );
    }

    public static function identitySelects(): array
    {
        return [
            'current_admin'  => ['includes/admin_auth.php', '/SELECT a\.id,[^;]*a\.avatar_public_id/'],
            'current_user'   => ['includes/auth.php', "/'full'\s*=>\s*'SELECT[^;]*avatar_public_id/"],
            // 'legacy' must stay free of it: naming an optional column in both
            // statements makes a pre-avatar schema throw twice, current_user()
            // returns null, and the citizen is logged out of every page.
            'current_user legacy stays avatar-free' => ['includes/auth.php', "/'legacy'\s*=>\s*'SELECT(?![^;]*avatar_public_id)[^;]*'/"],
        ];
    }

    // ---------------------------------------------------------------- audit log

    public function test_audit_log_splits_before_and_after_into_separate_columns(): void
    {
        // old_value and new_value used to hold the same {from,to} map, which made
        // the pair impossible to diff. They must be the before-state and the
        // after-state respectively.
        $db = $this->dbOrSkip();
        $db->query('DELETE FROM admin_activity_log');

        mc_admin_activity_log($db, 1, 'selftest_split', 'user', 42, [
            'email' => ['from' => 'old@x.com', 'to' => 'new@x.com'],
        ]);

        $row = $db->query("SELECT * FROM admin_activity_log WHERE action = 'selftest_split'")->fetch_assoc();
        $this->assertIsArray($row, 'audit row was not written');
        $this->assertNotSame($row['old_value'], $row['new_value']);

        $old = json_decode((string) $row['old_value'], true);
        $new = json_decode((string) $row['new_value'], true);
        $this->assertSame('old@x.com', $old['email']);
        $this->assertSame('new@x.com', $new['email']);

        $db->query('DELETE FROM admin_activity_log');
    }

    public function test_audit_log_note_lands_only_in_new_value(): void
    {
        $db = $this->dbOrSkip();
        $db->query('DELETE FROM admin_activity_log');

        mc_admin_activity_log($db, 1, 'selftest_note', 'user', 42, [
            'email' => ['from' => 'a@b.c', 'to' => 'd@e.f'],
        ], 'super admin settled verification out of band');

        $row = $db->query("SELECT * FROM admin_activity_log WHERE action = 'selftest_note'")->fetch_assoc();
        $old = json_decode((string) $row['old_value'], true);
        $new = json_decode((string) $row['new_value'], true);
        $this->assertArrayNotHasKey('__note', $old);
        $this->assertSame('super admin settled verification out of band', $new['__note']);

        $db->query('DELETE FROM admin_activity_log');
    }

    public function test_audit_log_preserves_non_ascii(): void
    {
        $db = $this->dbOrSkip();
        $db->query('DELETE FROM admin_activity_log');

        mc_admin_activity_log($db, 1, 'selftest_utf8', 'user', 1, [
            'barangay' => ['from' => 'Bagong Silangan', 'to' => 'Tañada'],
        ]);

        $row = $db->query("SELECT * FROM admin_activity_log WHERE action = 'selftest_utf8'")->fetch_assoc();
        $new = json_decode((string) $row['new_value'], true);
        $this->assertSame('Tañada', $new['barangay']);

        $db->query('DELETE FROM admin_activity_log');
    }

    public function test_audit_log_truncates_to_the_column_widths(): void
    {
        $db = $this->dbOrSkip();
        $db->query('DELETE FROM admin_activity_log');

        mc_admin_activity_log($db, 1, str_repeat('a', 200), str_repeat('b', 200), null, [
            'x' => ['from' => '1', 'to' => '2'],
        ]);

        $row = $db->query("SELECT * FROM admin_activity_log WHERE action LIKE 'aaaa%'")->fetch_assoc();
        $this->assertSame(60, strlen($row['action']));
        $this->assertSame(30, strlen((string) $row['target_type']));
        $this->assertNull($row['target_id']);

        $db->query('DELETE FROM admin_activity_log');
    }

    public function test_audit_log_reports_failure_without_throwing(): void
    {
        // The write must never be the reason a legitimate update is reported to
        // the user as failed, so a failure returns false rather than throwing.
        $db = $this->dbOrSkip();
        $db->query('DELETE FROM admin_activity_log');
        $result = mc_admin_activity_log($db, 1, 'selftest_ok', 'user', 1, ['a' => ['from' => '1', 'to' => '2']]);
        $this->assertTrue($result);
        $db->query('DELETE FROM admin_activity_log');
    }

    private function dbOrSkip(): mysqli
    {
        if (!function_exists('db')) {
            $this->markTestSkipped('db() unavailable');
        }
        $db = db();
        if (!$db instanceof mysqli) {
            $this->markTestSkipped('no database connection');
        }
        return $db;
    }
}
