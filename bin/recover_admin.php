<?php

declare(strict_types=1);

/**
 * Marikina E-Concern — Admin Recovery CLI
 *
 * The last-resort escape hatch for an installation nobody can sign into:
 * a disabled admin, a forgotten super-admin password, or a lockout that
 * admin/setup.php cannot fix (e.g. SETUP_RECOVERY_KEY lost).
 *
 * Usage:
 *   php bin/recover_admin.php --list
 *   php bin/recover_admin.php --enable=<email>            re-enable + clear lockout
 *   php bin/recover_admin.php --unlock=<email>            clear lockout only
 *   php bin/recover_admin.php --password=<pass> --email=<email>
 *   php bin/recover_admin.php --promote=<email>           make a super_admin
 *   php bin/recover_admin.php --demote=<email>            make a department_admin
 *   php bin/recover_admin.php --add-super --name="Full Name" --email=<email> --password=<pass>
 *
 * --email may be an email address OR a numeric id, so you can still recover an
 * account whose email column is empty or malformed.
 *
 * This script talks to the database directly and performs no authentication —
 * anyone who can run PHP against this project's .env can already reach the
 * database, so it grants no privilege that filesystem/DB access does not.
 * It prints every change it makes and refuses ambiguous input.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

/** Parse --key=value and bare --flag arguments. */
function parse_args(array $argv): array
{
    $out = ['_args' => []];
    foreach ($argv as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--')) {
            continue;
        }
        $body = substr($arg, 2);
        if ($body === '') {
            continue;
        }
        $eq = strpos($body, '=');
        if ($eq === false) {
            $out[$body] = true;
        } else {
            $out[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }
    }
    return $out;
}

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg): never
{
    fwrite(STDERR, 'ERROR: ' . $msg . PHP_EOL);
    exit(1);
}

$args = parse_args($argv ?? []);

if (isset($args['help']) || isset($args['h'])) {
    out('Usage: php bin/recover_admin.php [options]');
    out('');
    out('  --list                          Show all admin accounts and their state');
    out('  --enable=<email|id>             Re-enable the account and clear any lockout');
    out('  --unlock=<email|id>             Clear lockout / failed attempts only');
    out('  --password=<newpass>            Set a new password (min 8 chars)');
    out('  --promote=<email|id>            Set role to super_admin');
    out('  --demote=<email|id>             Set role to department_admin');
    out('  --add-super                     Create a new super_admin account');
    out('    --name="Full Name"           Required with --add-super');
    out('    --email=<email>              Required with --add-super');
    out('    --password=<pass>            Required with --add-super (min 8 chars)');
    out('');
    out('Combine options to act on one account, e.g.');
    out('  php bin/recover_admin.php --enable=admin@city.gov.ph --password=NewPass123');
    exit(0);
}

/** Resolve an admin by numeric id or by email. */
function find_admin(mysqli $db, string $needle): ?array
{
    $stmt = $db->prepare('SELECT id, name, email, role, active, locked_until, failed_login_attempts, department_id
                          FROM admins WHERE id = ? LIMIT 1');
    if ($stmt) {
        db_prepared_execute($stmt, 'i', [(int) $needle]);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (is_array($row) && (int) $row['id'] > 0) {
            return $row;
        }
    }

    $stmt = $db->prepare('SELECT id, name, email, role, active, locked_until, failed_login_attempts, department_id
                          FROM admins WHERE email = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    db_prepared_execute($stmt, 's', [$needle]);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

function describe_admin(array $a): string
{
    $bits = [];
    $bits[] = ((int) $a['active'] === 1) ? 'active' : 'DISABLED';
    if ($a['locked_until'] !== null) {
        $until = strtotime((string) $a['locked_until']);
        $bits[] = $until >= time()
            ? 'LOCKED until ' . (string) $a['locked_until']
            : 'lockout expired';
    }
    if ((int) ($a['failed_login_attempts'] ?? 0) > 0) {
        $bits[] = (int) $a['failed_login_attempts'] . ' failed attempt(s)';
    }
    return implode(', ', $bits);
}

try {
    $db = db();
} catch (Throwable $e) {
    fail('Could not connect to the database: ' . $e->getMessage());
}

// --- --list ----------------------------------------------------------------
if (isset($args['list'])) {
    $stmt = $db->prepare('SELECT id, name, email, role, active, locked_until, failed_login_attempts
                          FROM admins ORDER BY id ASC');
    if (!$stmt) {
        fail('Could not read the admins table: ' . $db->error);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        out('No admin accounts exist. Create the first one with:');
        out('  php bin/recover_admin.php --add-super --name="Full Name" --email=admin@city.gov.ph --password=YourPass123');
        exit(0);
    }

    out(sprintf('%-4s %-34s %-18s %-10s %s', 'ID', 'EMAIL', 'ROLE', 'ACTIVE', 'STATE'));
    out(str_repeat('-', 100));
    foreach ($rows as $r) {
        out(sprintf(
            '%-4d %-34s %-18s %-10s %s',
            (int) $r['id'],
            mb_substr((string) $r['email'], 0, 34),
            (string) $r['role'],
            ((int) $r['active'] === 1) ? 'yes' : 'NO',
            describe_admin($r)
        ));
    }
    exit(0);
}

// --- --add-super -----------------------------------------------------------
if (isset($args['add-super'])) {
    $name = trim((string) ($args['name'] ?? ''));
    $email = trim((string) ($args['email'] ?? ''));
    $password = (string) ($args['password'] ?? '');

    if ($name === '') {
        fail('--name is required with --add-super');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('--email must be a valid email address');
    }
    if (strlen($password) < 8) {
        fail('--password must be at least 8 characters');
    }
    if (find_admin($db, $email) !== null) {
        fail('An admin with email ' . $email . ' already exists. Use --enable / --password on the existing account instead.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO admins (name, email, password_hash, role, department_id, active) VALUES (?, ?, ?, "super_admin", NULL, 1)');
    if (!$stmt) {
        fail('Could not prepare insert: ' . $db->error);
    }
    $ok = db_prepared_execute($stmt, 'sss', [$name, $email, $hash]);
    $newId = (int) $db->insert_id;
    $stmt->close();
    if (!$ok || $newId <= 0) {
        fail('Insert failed: ' . $db->error);
    }
    out('Created super_admin #' . $newId . ' (' . $email . ').');
    exit(0);
}

// --- Mutating actions on an existing account -------------------------------
$target = null;
foreach (['enable', 'unlock', 'password', 'promote', 'demote'] as $key) {
    if (isset($args[$key]) && $args[$key] !== true) {
        $needle = trim((string) $args[$key]);
        if ($needle === '') {
            fail('--' . $key . ' needs a value, e.g. --' . $key . '=admin@city.gov.ph');
        }
        if ($target !== null) {
            fail('Only one account can be modified per run (got both --' . $args['_target_key'] . ' and --' . $key . ').');
        }
        $args['_target_key'] = $key;
        $target = find_admin($db, $needle);
        if ($target === null) {
            fail('No admin account matches "' . $needle . '". Run --list to see valid accounts.');
        }
    }
}

if ($target === null) {
    fail('Nothing to do. Pass --list, --add-super, or one of --enable / --unlock / --password / --promote / --demote. Try --help.');
}

$id = (int) $target['id'];
$password = isset($args['password']) && $args['password'] !== true ? (string) $args['password'] : '';
if ($password !== '' && strlen($password) < 8) {
    fail('--password must be at least 8 characters');
}

$set = [];
$params = [];
$types = '';
$changes = [];

// --enable implies unlock: a disabled account with a stale lockout still cannot log in.
if (isset($args['enable'])) {
    $set[] = 'active = ?';
    $params[] = 1;
    $types .= 'i';
    $changes[] = 'account re-enabled';
}
if (isset($args['unlock']) || isset($args['enable'])) {
    // Literals, not placeholders — keep $types in step with the real '?' count.
    $set[] = 'locked_until = NULL';
    $set[] = 'failed_login_attempts = 0';
    $changes[] = 'lockout and failed attempts cleared';
}
if (isset($args['promote'])) {
    $set[] = 'role = ?';
    $params[] = 'super_admin';
    $types .= 's';
    $set[] = 'department_id = NULL';
    $changes[] = 'role set to super_admin';
}
if (isset($args['demote'])) {
    $set[] = 'role = ?';
    $params[] = 'department_admin';
    $types .= 's';
    $changes[] = 'role set to department_admin';
}
if ($password !== '') {
    $set[] = 'password_hash = ?';
    $params[] = password_hash($password, PASSWORD_DEFAULT);
    $types .= 's';
    $changes[] = 'password reset';
}

$params[] = $id;
$types .= 'i';

$sql = 'UPDATE admins SET ' . implode(', ', $set) . ' WHERE id = ?';
$stmt = $db->prepare($sql);
if (!$stmt) {
    fail('Could not prepare update: ' . $db->error);
}
$ok = db_prepared_execute($stmt, $types, $params);
$stmt->close();
if (!$ok) {
    fail('Update failed: ' . $db->error);
}

out('Updated admin #' . $id . ' (' . (string) $target['email'] . '): ' . implode('; ', $changes) . '.');
out('State before: ' . describe_admin($target));
