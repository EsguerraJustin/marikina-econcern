<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards the admin-message -> citizen-notification contract.
 *
 * Regression context (2026-09-30): an admin sent a citizen "Your concern has
 * been resolved..." from the Message Citizen dialog on admin/concern_view.php.
 * Nothing appeared in the citizen's Notifications tab. Confirmed, not
 * speculative: admin/api/post_message.php performed exactly one statement --
 *
 *   INSERT INTO concern_messages (concern_id, sender, message) VALUES (?, "department", ?)
 *
 * -- and loaded only includes/admin_auth.php, so the notification service was
 * never even in scope. The citizen had to open the concern manually to find out
 * an admin had replied.
 *
 * Two things had to change beyond adding the INSERT of the notification row:
 *
 *   1. ref_table/ref_id were already written by ba_push_notification() and are
 *      already indexed (idx_notif_ref), but NOTHING in the app ever rendered or
 *      followed them. public/ba_notifications.php:485-488 only called
 *      markRowRead(). So even the pre-existing report_update notifications took
 *      the citizen nowhere.
 *   2. ba_notifications.type is an ENUM, so a department message had no
 *      representable value until 'concern_message' was added to both schema
 *      copies.
 *
 * Static, file-reading tests on purpose: tests/bootstrap.php loads no DB, and
 * the notification service cannot be exercised without a live schema.
 */
final class ConcernMessageNotificationTest extends TestCase
{
    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function read(string $rel): string
    {
        $path = self::projectRoot() . '/' . $rel;
        self::assertFileExists($path, "Missing expected file: {$rel}");
        return (string) file_get_contents($path);
    }

    /**
     * The same file with every comment removed, so an assertion can only be
     * satisfied by real code.
     *
     * NotificationQueueSchemaTest.php:54-62 records why this matters: the fix
     * for its original bug is a comment that quotes 'delivered_at', so a naive
     * scanner reports the fix itself as the bug. The same trap applies here --
     * post_message.php explains in prose why it must NOT fan out across
     * channels, and a plain substring match reads that explanation as a
     * violation. token_get_all() is used rather than a regex so a quoted
     * identifier inside a comment is never mistaken for code, and the leading
     * <?php is re-entered when a slice would otherwise tokenize as raw HTML.
     */
    private static function codeOnly(string $source): string
    {
        $fragment = str_contains($source, '<?php') ? $source : '<?php ' . $source;
        $out = '';
        foreach (token_get_all($fragment) as $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }
            $out .= ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) ? ' ' : $token[1];
        }
        return $out;
    }

    /** Anti-vacuity: the scanner must actually see every file it claims to check. */
    public function test_scanner_actually_reads_every_guarded_file(): void
    {
        $files = [
            'admin/api/post_message.php',
            'public/ba_notifications.php',
            'assets/css/public/ba_notifications.css',
            'database/basuraalert_migration.sql',
            'database/full_install_infinityfree.sql',
            'database/20260929_concern_message_notification.sql',
            'bin/migrate.php',
        ];
        foreach ($files as $rel) {
            $src = self::read($rel);
            $this->assertGreaterThan(
                200,
                strlen($src),
                "{$rel} read as suspiciously small -- the scanner is not seeing the real file"
            );
        }
    }

    /**
     * The endpoint must load the notification service. Requiring only
     * includes/admin_auth.php leaves ba_push_notification() undefined, so the
     * notification could not be created even if the code tried.
     */
    public function test_post_message_endpoint_loads_the_notification_service(): void
    {
        $code = self::codeOnly(self::read('admin/api/post_message.php'));

        $this->assertStringContainsString(
            'includes/basuraalert.php',
            $code,
            'admin/api/post_message.php does not require the BasuraAlert service, so '
            . 'ba_push_notification() is not in scope.'
        );
    }

    /**
     * The recipient must be derivable from the authorising query.
     *
     * The original SELECT fetched only c.id, so even a correct notification
     * call would have had no user_id to address.
     */
    public function test_post_message_selects_the_recipient(): void
    {
        $code = self::codeOnly(self::read('admin/api/post_message.php'));

        $this->assertMatchesRegularExpression(
            '/SELECT[^;]*\bc\.user_id\b/is',
            $code,
            'The authorising SELECT does not fetch concerns.user_id, so the notification has no '
            . 'recipient. The original query selected c.id only.'
        );

        $this->assertMatchesRegularExpression(
            '/SELECT[^;]*\bc\.report_number\b/is',
            $code,
            'The authorising SELECT does not fetch concerns.report_number, so the notification cannot '
            . 'identify which concern it is about.'
        );
    }

    /** The notification must be an in-app row pointing at the conversation. */
    public function test_post_message_creates_an_in_app_concern_message_notification(): void
    {
        $code = self::codeOnly(self::read('admin/api/post_message.php'));

        $this->assertStringContainsString(
            'ba_push_notification(',
            $code,
            'admin/api/post_message.php creates no citizen notification at all.'
        );

        $this->assertStringContainsString(
            "'concern_message'",
            $code,
            "The notification must use the 'concern_message' type so it gets its own icon and label."
        );

        $this->assertStringContainsString(
            "'concerns'",
            $code,
            "The notification must set ref_table = 'concerns' so the tap can deep-link to the "
            . 'conversation.'
        );

        $this->assertStringContainsString(
            "'in_app'",
            $code,
            "The notification must be delivered 'in_app' only. ba_push_notification_via_prefs() "
            . 'writes one row per enabled channel, and ba_count_unread_notifications() counts rows, '
            . 'so a multi-channel fan-out would inflate the bell badge threefold for one message.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/ba_push_notification_via_prefs\s*\(/',
            $code,
            'post_message must not fan out across channels for a single admin message.'
        );
    }

    /**
     * A notification failure must not fail the send. The message is the source
     * of truth; losing it because a side-effect insert failed would be worse
     * than a missing notification.
     */
    public function test_notification_failure_does_not_fail_the_message_send(): void
    {
        $code = self::codeOnly(self::read('admin/api/post_message.php'));

        $notifyAt = strpos($code, 'ba_push_notification(');
        $okAt = strrpos($code, "'ok' => true");

        $this->assertIsInt($notifyAt);
        $this->assertIsInt($okAt);
        $this->assertLessThan(
            $okAt,
            $notifyAt,
            'The notification must be created BEFORE the endpoint emits its final ok:true response, '
            . 'so that a failed notification insert can be caught and logged instead of corrupting '
            . 'or replacing the JSON the admin client is waiting on.'
        );
    }

    /** The tap must go to the conversation, not just flip the unread dot. */
    public function test_notification_cards_carry_a_deep_link_target(): void
    {
        $code = self::codeOnly(self::read('public/ba_notifications.php'));

        $this->assertStringContainsString(
            'data-href',
            $code,
            'Notification cards expose no target. ref_table/ref_id are written and indexed but were '
            . 'never rendered, so a tap could only mark the row read.'
        );

        $this->assertStringContainsString(
            'ba_notification_target_url(',
            $code,
            'The card does not resolve its target through the notification service, so the mapping '
            . 'has been re-implemented in the page and cannot be unit tested.'
        );

        // The click handler used to be bound to .ba-notif-unread only, which made
        // a notification completely inert once it had been read.
        $this->assertStringContainsString(
            'closest(".mc-notif-card")',
            $code,
            'The tap handler is still scoped to unread rows, so a read notification is inert.'
        );
    }

    /**
     * Behavioural test of the routing itself, not a string match on the source.
     *
     * This is the payoff of the fix -- an admin's message has to land the
     * citizen on the conversation -- so it is worth actually calling the
     * function. tests/bootstrap.php already loads includes/basuraalert.php.
     */
    public function test_target_url_routes_each_ref_table_to_a_real_page(): void
    {
        if (!function_exists('ba_notification_target_url')) {
            $this->markTestSkipped('includes/basuraalert.php did not load in this environment');
        }

        $concerns = ba_notification_target_url(['ref_table' => 'concerns', 'ref_id' => 42]);
        $this->assertStringContainsString('concern_view.php', $concerns);
        $this->assertStringContainsString('42', $concerns);

        $reports = ba_notification_target_url(['ref_table' => 'ba_reports', 'ref_id' => 7]);
        $this->assertStringContainsString('ba_my_reports.php', $reports);
        $this->assertStringContainsString('7', $reports);

        $announcements = ba_notification_target_url(['ref_table' => 'ba_announcements', 'ref_id' => 3]);
        $this->assertStringContainsString('ba_announcements.php', $announcements);

        // A missing, zero, or unrecognised ref must degrade to the old
        // mark-read-only behaviour rather than emit a broken or wrong link.
        $this->assertSame('', ba_notification_target_url(['ref_table' => 'concerns', 'ref_id' => 0]));
        $this->assertSame('', ba_notification_target_url(['ref_table' => '', 'ref_id' => 5]));
        $this->assertSame('', ba_notification_target_url(['ref_table' => 'no_such_table', 'ref_id' => 5]));
        $this->assertSame('', ba_notification_target_url(null));
    }

    /** The new type needs its own icon and its own tint, or it renders as an unstyled bell. */
    public function test_new_type_has_an_icon_and_a_tint(): void
    {
        $page = self::codeOnly(self::read('public/ba_notifications.php'));
        $this->assertMatchesRegularExpression(
            "/case\\s*'concern_message'/",
            $page,
            'No icon case for the concern_message type; it would fall through to the default bell.'
        );

        $css = self::read('assets/css/public/ba_notifications.css');
        $this->assertStringContainsString(
            '.mc-notif-icon--concern_message',
            $css,
            'No CSS tint for .mc-notif-icon--concern_message; every other type has one, so the new '
            . 'icon tile would render unstyled.'
        );
    }

    /**
     * The ENUM is the storage contract. Both schema copies must agree or a fresh
     * `php bin/migrate.php --fresh` database and a phpMyAdmin import of
     * full_install_infinityfree.sql end up with different types.
     */
    public function test_concern_message_is_in_the_enum_in_both_schema_copies(): void
    {
        foreach (
            [
                'database/basuraalert_migration.sql',
                'database/full_install_infinityfree.sql',
            ] as $rel
        ) {
            $sql = self::read($rel);
            $this->assertMatchesRegularExpression(
                '/`?type`?\s+(?:ENUM|enum)\s*\([^)]*\bconcern_message\b/is',
                $sql,
                "{$rel}: ba_notifications.type ENUM does not contain 'concern_message'. MySQL resolves "
                . 'an ENUM at INSERT time, so the notification insert would fail on a live database.'
            );
        }
    }

    /**
     * The two schema copies must declare the SAME enum, not merely both contain
     * the new value.
     *
     * This is not paranoia. bin/build_infinityfree_sql.php:86-106 regenerates
     * database/full_install_infinityfree.sql from a live `mysqldump` of the local
     * e_concern database -- it does not read database/basuraalert_migration.sql.
     * So running it on a machine that has not yet applied
     * 20260929_concern_message_notification.sql silently overwrites the file and
     * drops 'concern_message', while the migration that backfills an existing
     * database still carries it. The two paths then disagree, which is the same
     * drift that left ba_notification_preferences.ev_* missing from the migrate
     * chain entirely (see NotificationQueueSchemaTest's class docblock).
     */
    public function test_both_schema_copies_declare_an_identical_type_enum(): void
    {
        $fromMigration = self::notificationTypeEnum('database/basuraalert_migration.sql');
        $fromInstall = self::notificationTypeEnum('database/full_install_infinityfree.sql');

        $this->assertNotSame(
            [],
            $fromMigration,
            'Could not locate the ba_notifications.type ENUM in database/basuraalert_migration.sql'
        );
        $this->assertNotSame(
            [],
            $fromInstall,
            'Could not locate the ba_notifications.type ENUM in database/full_install_infinityfree.sql'
        );

        sort($fromMigration);
        sort($fromInstall);
        $this->assertSame(
            $fromMigration,
            $fromInstall,
            'database/basuraalert_migration.sql and database/full_install_infinityfree.sql declare '
            . 'different ba_notifications.type enums. If full_install was regenerated by '
            . 'bin/build_infinityfree_sql.php from a database that has not run the 20260929 '
            . 'migration, the phpMyAdmin import path and the bin/migrate.php path now disagree.'
        );
    }

    /**
     * The declared values of ba_notifications.type, in source order.
     *
     * Scoped to the ba_notifications CREATE TABLE block because other tables in
     * the same file also declare `type` enums (waste_type, schedule_type).
     *
     * @return list<string>
     */
    private static function notificationTypeEnum(string $rel): array
    {
        $sql = self::read($rel);
        if (!preg_match('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?ba_notifications`?\s*\((.*?)\n\)\s*ENGINE=/is', $sql, $block)) {
            return [];
        }
        if (!preg_match('/`?type`?\s+(?:ENUM|enum)\s*\(([^)]*)\)/i', $block[1], $enum)) {
            return [];
        }
        preg_match_all("/'([^']+)'/", $enum[1], $values);
        return $values[1];
    }

    /**
     * InfinityFree's free tier has no SSH or PHP CLI
     * (docs/DEPLOY_INFINITYFREE.md:24), so the migration is applied by hand in
     * phpMyAdmin. It still has to be a real migration file: idempotent,
     * INFORMATION_SCHEMA-guarded, and registered in bin/migrate.php so the
     * canonical local/CI path stays in step.
     */
    public function test_migration_is_guarded_and_registered(): void
    {
        $sql = self::read('database/20260929_concern_message_notification.sql');

        $this->assertStringContainsString(
            'INFORMATION_SCHEMA',
            $sql,
            'The migration is not INFORMATION_SCHEMA-guarded. bin/migrate.php replays a file once per '
            . 'recorded name, and a re-run must be a no-op.'
        );

        $this->assertStringContainsString(
            'concern_message',
            $sql,
            'The migration does not add the concern_message value.'
        );

        $this->assertMatchesRegularExpression(
            '/COLUMN_TYPE\s+NOT\s+LIKE/i',
            $sql,
            'The guard must test the existing column definition (COLUMN_TYPE) for the new value, not '
            . 'merely that the column exists -- the column already exists, so an existence check would '
            . 'make the migration a permanent no-op.'
        );

        $runner = self::read('bin/migrate.php');
        $this->assertStringContainsString(
            'database/20260929_concern_message_notification.sql',
            $runner,
            'The migration is not registered in bin/migrate.php $MIGRATIONS, so the canonical runner '
            . 'would never apply it locally or in CI.'
        );
    }
}
