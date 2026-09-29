<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards the ba_notifications schema/code contract.
 *
 * Regression context (2026-09-27): ba_flush_queued_notifications() referenced a
 * column named `delivered_at` that has never existed in any migration or
 * schema. MySQL resolves columns at PREPARE time, so $db->prepare() threw
 * mysqli_sql_exception before dispatching anything. The flush runs from a
 * register_shutdown_function, i.e. after json_response() had already echoed the
 * body and exit()ed, so the fatal text was appended to the sent JSON and broke
 * the client's JSON.parse(). A resident whose report saved fine saw "Network or
 * server error. Please try again."
 *
 * Two ways that recurs, both covered here:
 *   1. a column name in PHP that the migration never declares;
 *   2. a column the PHP depends on but the migration forgets to declare, so a
 *      `php bin/migrate.php --fresh` database is missing it too.
 *
 * A third, found the same day: the flush existed in one entry point only, so
 * notifications queued by an admin action were never sent. See
 * test_every_queue_producer_registers_the_flush().
 *
 * These are static, file-reading tests on purpose: bootstrap.php loads no DB,
 * and the failure mode only shows up at runtime against a live schema.
 */
final class NotificationQueueSchemaTest extends TestCase
{
    /** Columns the queue dispatcher writes that the original CREATE TABLE omitted. */
    private const DELIVERY_COLUMNS = ['retry_count', 'next_attempt_at', 'sent_at'];

    private const SQL_VERB = '/\b(?:SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b/i';

    private static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Repo-relative path with forward slashes, so assertions and keys compare
     * the same on Windows, where getPathname() and dirname(__DIR__) disagree
     * about separators.
     */
    private static function relPath(string $path): string
    {
        $p = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', self::projectRoot());
        return str_starts_with($p, $root) ? ltrim(substr($p, strlen($root)), '/') : $p;
    }

    /**
     * Every real SQL string literal in a PHP source, comments excluded.
     *
     * Uses token_get_all() rather than a regex so that (a) a column name quoted
     * inside a comment is never mistaken for code, and (b) heredoc bodies, which
     * may interpolate, are skipped instead of misparsed. This matters: the fix
     * for the original bug is a comment that quotes 'delivered_at', and a naive
     * scanner would report the fix itself as the bug.
     *
     * @return list<string>
     */
    private static function sqlLiterals(string $source): array
    {
        $out = [];
        foreach (self::tokenizePhp($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = self::unquote($token[1]);
            }
        }
        return $out;
    }

    /**
     * @return list<array{int, string, int}|string>
     */
    private static function tokenizePhp(string $fragment): array
    {
        // token_get_all() emits everything before an opening tag as
        // T_INLINE_HTML, so a slice starting at `function ba_...` tokenizes as
        // one HTML blob and yields zero string literals -- which would make the
        // column-drift guard pass vacuously. Re-enter PHP mode when needed.
        $head = ltrim($fragment);
        if (!str_starts_with($head, '<?php') && !str_starts_with($head, '<?=')) {
            $fragment = '<?php ' . $fragment . ' ?>';
        }
        return token_get_all($fragment);
    }

    private static function unquote(string $literal): string
    {
        if (strlen($literal) < 2) {
            return '';
        }
        $inner = substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }
        return stripcslashes($inner);
    }

    /**
     * Production PHP that writes ba_notifications. Root-level maintenance
     * scripts count (ba_dispatch_queued_notifications.php is one of the two
     * dispatchers), so they are scanned too.
     *
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $root = self::projectRoot();
        $files = [];

        foreach (['includes', 'api', 'admin'] as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }

        foreach (glob($root . '/*.php') ?: [] as $f) {
            $files[] = $f;
        }

        sort($files);
        return $files;
    }

    /**
     * Column names declared by CREATE TABLE ba_notifications in the migration,
     * which is the documented source of truth for a migrated database.
     *
     * @return list<string>
     */
    private static function declaredColumns(): array
    {
        $sql = (string) file_get_contents(self::projectRoot() . '/database/basuraalert_migration.sql');

        if (!preg_match('/CREATE TABLE IF NOT EXISTS ba_notifications\s*\((.*?)\n\)\s*ENGINE=/s', $sql, $m)) {
            self::fail('Could not locate CREATE TABLE ba_notifications in database/basuraalert_migration.sql');
        }

        $cols = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }
            $first = strtok($line, " \t(");
            if ($first === false) {
                continue;
            }
            $first = strtolower($first);
            // Table constraints share the CREATE TABLE body but are not columns.
            if (in_array($first, ['primary', 'unique', 'key', 'index', 'constraint', 'foreign'], true)) {
                continue;
            }
            $cols[] = $first;
        }
        return $cols;
    }

    /**
     * Column names assigned by every SQL statement that touches
     * ba_notifications. Assignment targets (`col = ...`, `t.col = ...`) are
     * unambiguous in DML, which keeps this free of the false positives a full
     * identifier scan would produce from SELECT lists and SQL keywords.
     *
     * @return array<string, list<string>> file => column names
     */
    private static function assignedColumnsInNotificationSql(): array
    {
        $found = [];
        foreach (self::sourceFiles() as $file) {
            $src = (string) file_get_contents($file);
            if (!str_contains($src, 'ba_notifications')) {
                continue;
            }
            $cols = [];
            foreach (self::sqlLiterals($src) as $sql) {
                // Require a real DML statement, or an HTML/JS blob that merely
                // contains the string "ba_notifications" (a CSS class name, say)
                // would contribute junk identifiers.
                if (!str_contains($sql, 'ba_notifications') || !preg_match(self::SQL_VERB, $sql)) {
                    continue;
                }
                // `col =` / `t.col =`, but not `<=`, `>=`, `!=`, `:=`.
                if (preg_match_all('/(?:[a-z_][a-z0-9_]*\.)?([a-z_][a-z0-9_]*)\s*=(?![=>])/i', $sql, $m)) {
                    foreach ($m[1] as $col) {
                        $cols[] = strtolower($col);
                    }
                }
            }
            if ($cols !== []) {
                $found[self::relPath($file)] = array_values(array_unique($cols));
            }
        }
        return $found;
    }

    public function test_migration_declares_the_delivery_tracking_columns(): void
    {
        $declared = self::declaredColumns();

        foreach (self::DELIVERY_COLUMNS as $col) {
            $this->assertContains(
                $col,
                $declared,
                'ba_notifications must declare ' . $col . ' in database/basuraalert_migration.sql. '
                . 'Without it, a database built by `php bin/migrate.php` lacks the column and '
                . 'ba_dispatch_queued_notifications.php / ba_flush_queued_notifications() both fatal.'
            );
        }
    }

    public function test_migration_does_not_declare_phantom_columns(): void
    {
        $this->assertNotContains(
            'delivered_at',
            self::declaredColumns(),
            'delivered_at was never a real column; it must not reappear in the schema.'
        );
    }

    public function test_notification_sql_only_assigns_declared_columns(): void
    {
        $declared = self::declaredColumns();
        $offenders = [];

        foreach (self::assignedColumnsInNotificationSql() as $file => $cols) {
            $unknown = array_values(array_diff($cols, $declared));
            if ($unknown !== []) {
                $offenders[] = $file . ' assigns unknown ba_notifications column(s): ' . implode(', ', $unknown);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "ba_notifications SQL assigns columns the migration never declares.\nMySQL resolves\n"
            . "columns at PREPARE, so each of these throws mysqli_sql_exception at runtime --\n"
            . "and from a shutdown handler that is fatal to an already-sent JSON response.\n"
            . implode("\n", $offenders)
        );
    }

    public function test_scanner_actually_covers_the_dispatchers(): void
    {
        // If this ever returns nothing the guard above is vacuously green.
        $scanned = self::assignedColumnsInNotificationSql();

        $this->assertArrayHasKey(
            'includes/BasuraAlert/Notifications.php',
            $scanned,
            'The queue dispatcher must be in scope, or the column-drift guard tests nothing.'
        );
        $this->assertArrayHasKey(
            'ba_dispatch_queued_notifications.php',
            $scanned,
            'The cron dispatcher must be in scope, or the column-drift guard tests nothing.'
        );
        $this->assertContains(
            'sent_at',
            $scanned['includes/BasuraAlert/Notifications.php'] ?? [],
            'Sanity check: the fixed column must actually be visible to the scanner, otherwise the '
            . 'scanner is matching nothing and the drift guard is worthless.'
        );
    }

    public function test_flush_marks_success_with_sent_at(): void
    {
        $src = (string) file_get_contents(
            self::projectRoot() . '/includes/BasuraAlert/Notifications.php'
        );

        $start = strpos($src, 'function ba_flush_queued_notifications');
        $this->assertNotFalse($start, 'ba_flush_queued_notifications() must exist in BasuraAlert/Notifications.php');

        // Isolate the function body.
        $body = substr($src, (int) $start);
        $end = strpos($body, "\nfunction ", 1);
        if ($end !== false) {
            $body = substr($body, 0, $end);
        }

        $sql = self::sqlLiterals($body);
        $stamping = array_values(array_filter(
            $sql,
            static fn(string $s): bool => str_contains($s, 'sent_at = CURRENT_TIMESTAMP')
        ));
        $this->assertNotSame(
            [],
            $stamping,
            'A successful dispatch must stamp sent_at, the column PROJECT_GUIDE.md documents as sent_at=NOW().'
        );

        foreach ($sql as $statement) {
            $this->assertStringNotContainsString(
                'delivered_at',
                $statement,
                'delivered_at does not exist in any schema. This is the exact line that made report '
                . 'submit report "Network or server error" while the report itself saved fine.'
            );
        }
    }

    /**
     * Every entry point that can write a `queued` row to ba_notifications.
     *
     * The flush used to be an inline closure in api/basuraalert_actions.php and
     * nowhere else, so an admin action that queued a row (feedback reply,
     * announcement fan-out, report status update) had nothing to send it and it
     * sat at `queued` until the cron dispatcher ran. The drain now lives in
     * ba_register_notification_flush(); this list is what keeps every producer
     * wired to it.
     */
    private const FLUSH_ENTRY_POINTS = [
        'api/basuraalert_actions.php',
        'admin/api/basuraalert_admin.php',
        'ba_run_reminders.php',
    ];

    private function flushHelperBody(): string
    {
        $src = (string) file_get_contents(
            self::projectRoot() . '/includes/BasuraAlert/Notifications.php'
        );

        $start = strpos($src, 'function ba_register_notification_flush');
        $this->assertNotFalse($start, 'ba_register_notification_flush() must exist in BasuraAlert/Notifications.php');

        $body = substr($src, (int) $start);
        $end = strpos($body, "\nfunction ", 1);
        if ($end !== false) {
            $body = substr($body, 0, $end);
        }
        return $body;
    }

    public function test_flush_helper_cannot_corrupt_a_sent_response(): void
    {
        $body = $this->flushHelperBody();

        $this->assertStringContainsString(
            'catch (Throwable',
            $body,
            'The flush runs after json_response() has echoed and exit()ed, so an escaping '
            . 'exception appends fatal text to the sent JSON body. It must be caught.'
        );
        $this->assertStringContainsString(
            'ob_end_clean',
            $body,
            'Output emitted by the flush must be discarded, not appended to the response body.'
        );
        $this->assertStringContainsString(
            'ba_flush_queued_notifications',
            $body,
            'The helper must delegate to the dispatcher; wrapping it is the whole point.'
        );
    }

    public function test_every_queue_producer_registers_the_flush(): void
    {
        $missing = [];
        foreach (self::FLUSH_ENTRY_POINTS as $rel) {
            $path = self::projectRoot() . '/' . $rel;
            if (!is_file($path)) {
                $missing[] = $rel . ' does not exist';
                continue;
            }
            if (!str_contains((string) file_get_contents($path), 'ba_register_notification_flush(')) {
                $missing[] = $rel . ' queues notifications but never calls ba_register_notification_flush() -- '
                    . 'its rows will sit at delivery_status=queued until the cron dispatcher runs, '
                    . 'and Infinity Free\'s free tier has no cron.';
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function test_backfill_migration_covers_every_delivery_column(): void
    {
        $path = self::projectRoot() . '/database/20260927_notification_delivery_columns.sql';
        $this->assertFileExists($path, 'bin/migrate.php --fresh and existing databases both need the backfill.');

        $sql = (string) file_get_contents($path);
        foreach (self::DELIVERY_COLUMNS as $col) {
            $this->assertStringContainsString(
                "COLUMN_NAME = '" . $col . "'",
                $sql,
                'The backfill must be guarded by an INFORMATION_SCHEMA check for ' . $col . '.'
            );
        }

        $this->assertStringContainsString(
            "'notif_delivery_cols' => 'database/20260927_notification_delivery_columns.sql'",
            (string) file_get_contents(self::projectRoot() . '/bin/migrate.php'),
            'bin/migrate.php skips a file by name once applied, so the backfill only reaches '
            . 'existing databases if it is registered in $MIGRATIONS.'
        );
    }
}
