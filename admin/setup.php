<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';

$pageTitle = 'Admin Setup';

ensure_session_started();

$mysqli = db();
$hasAdminsTable = false;
$res = $mysqli->query("SHOW TABLES LIKE 'admins'");
if ($res) {
    $hasAdminsTable = (bool) $res->fetch_row();
    $res->free();
}

if (!$hasAdminsTable) {
    require_once __DIR__ . '/../includes/partials/head.php';
    ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="mc-neo-card">
                    <div class="card-body p-4">
                        <div class="fw-bold text-primary fs-4"><?= e(APP_NAME) ?></div>
                        <div class="text-muted">Admin Setup</div>
                        <hr>
                        <div class="alert alert-warning mb-0">
                            Admin tables are not installed yet. Please apply <span class="fw-semibold">database/admin_migration.sql</span> to your database, then reload this page.
                        </div>
                    </div>
                </div>
                <div class="text-muted small mt-3">
                    After setup, go to <a href="<?= e(app_url('/admin/login.php')) ?>">Admin Login</a>.
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/partials/foot.php';
    exit;
}

$countStmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM admins');
$countStmt->execute();
$countRes = $countStmt->get_result();
$countRow = $countRes ? $countRes->fetch_assoc() : null;
$countStmt->close();
$adminCount = (int) ($countRow['c'] ?? 0);

$adminList = [];
if ($adminCount > 0) {
    $listStmt = $mysqli->prepare('SELECT id, email, name, role, active, password_hash, department_id, locked_until, failed_login_attempts, created_at FROM admins ORDER BY id ASC');
    if ($listStmt) {
        $listStmt->execute();
        $lr = $listStmt->get_result();
        while ($r = $lr ? $lr->fetch_assoc() : false) {
            $hash = (string) ($r['password_hash'] ?? '');
            $info = password_get_info($hash);
            $r['hash_len'] = strlen($hash);
            $r['hash_algo'] = $info['algoName'] ?? 'unknown';
            $r['hash_cost'] = $info['options']['cost'] ?? null;
            $r['is_locked'] = $r['locked_until'] !== null
                && strtotime((string) $r['locked_until']) >= time();
            unset($r['password_hash']);
            $adminList[] = $r;
        }
        $listStmt->close();
    }
}

$adminById = [];
foreach ($adminList as $a) {
    $adminById[(int) $a['id']] = $a;
}

/* =========================================================================
 * Recovery-key gate
 *
 * This page is reachable without logging in, because its whole purpose is to
 * rescue an installation that nobody can sign into. That makes it the single
 * most dangerous page in the project: an unauthenticated "create Super Admin"
 * form is a full account takeover for anyone who can reach the URL.
 *
 * So every state-changing path below requires a server-side recovery key
 * (SETUP_RECOVERY_KEY in .env). Presenting the key grants a short-lived session
 * grant so it is submitted once, over POST, instead of sitting in the URL
 * where it would leak via Referer / browser history / access logs.
 *
 * There is no default value for the key. While it is empty, web recovery is
 * disabled entirely and `php bin/recover_admin.php` is the only route.
 * ========================================================================= */

$recoveryKeyConfigured = (defined('SETUP_RECOVERY_KEY') && SETUP_RECOVERY_KEY !== '');
$grantTtl = 900; // 15 minutes is plenty for a one-shot recovery
$hasGrant = isset($_SESSION['setup_recovery_grant'])
    && is_int($_SESSION['setup_recovery_grant'])
    && $_SESSION['setup_recovery_grant'] > time();

/* Bootstrap exception: when the admins table is completely empty there are no
 * accounts to protect and no lockout to recover from, so first-run setup stays
 * open. This matches the original behaviour and is unavoidable — a fresh
 * install has to be bootstrapable somehow. The moment one admin exists, every
 * state-changing path requires the recovery key. */
$firstRunOpen = ($adminCount === 0);
$canAct = $hasGrant || $firstRunOpen;

$error = '';
$success = '';
$notice = '';

function _setup_diag(string $event, array $extra = []): void
{
    $line = json_encode([
        'ts'   => date('c'),
        'mod'  => 'admin_setup',
        'ev'   => $event,
        'ip'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ] + $extra, JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        $line = '{"mod":"admin_setup","ev":"' . $event . '"}';
    }
    @error_log($line . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

/** Issue a fresh CSRF token after a consumed one so a corrected retry works. */
function _setup_renew_csrf(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Key submission (GET ?key=...) -----------------------------------------
if (isset($_GET['key']) && is_string($_GET['key']) && $_GET['key'] !== '') {
    if (!$recoveryKeyConfigured) {
        $notice = 'Admin recovery is disabled because SETUP_RECOVERY_KEY is not configured. Use the CLI helper instead: <span class="font-monospace">php bin/recover_admin.php --list</span>.';
        _setup_diag('recovery_key_rejected_unconfigured');
    } elseif (hash_equals(SETUP_RECOVERY_KEY, $_GET['key'])) {
        session_regenerate_id(true);
        $_SESSION['setup_recovery_grant'] = time() + $grantTtl;
        _setup_renew_csrf();
        _setup_diag('recovery_grant_issued', ['remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        // Post/Redirect/Get so the key does not linger in the address bar.
        redirect(app_url('/admin/setup.php'));
    } else {
        $error = 'That recovery key is not valid.';
        _setup_diag('recovery_key_rejected_invalid', [
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'key_len'   => strlen($_GET['key']),
        ]);
    }
}

// --- Actions (POST; require an active grant unless bootstrapping) ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canAct) {
        $error = 'Your recovery session has expired or was never authorised. Re-enter the recovery key, then try again.';
        _setup_renew_csrf();
    } else {
        csrf_check();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_super_admin') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            if ($name === '') {
                $error = 'Name is required.';
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email.';
            } elseif ($password === '') {
                $error = 'Password is required.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($password !== $confirm) {
                $error = 'Passwords do not match.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare('INSERT INTO admins (name, email, password_hash, role, department_id, active) VALUES (?, ?, ?, "super_admin", NULL, 1)');
                $ok = db_prepared_execute($stmt, 'sss', [$name, $email, $hash]);
                $newId = (int) $mysqli->insert_id;
                $stmt->close();

                if (!$ok || $newId <= 0) {
                    $error = ($mysqli->errno === 1062)
                        ? 'An admin with that email already exists.'
                        : 'Failed to create Super Admin. Please try again.';
                    _setup_renew_csrf();
                } else {
                    _setup_diag('super_admin_created_via_recovery', [
                        'new_admin_id' => $newId,
                        'remote_ip'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]);
                    unset($_SESSION['setup_recovery_grant']);
                    login_admin($newId);
                    redirect(app_url('/admin/dashboard.php'));
                }
            }
        } elseif ($action === 'recover_admin') {
            $targetId = (int) ($_POST['admin_id'] ?? 0);
            $newPassword = (string) ($_POST['password'] ?? '');

            if ($targetId <= 0 || !isset($adminById[$targetId])) {
                $error = 'Please choose a valid admin account to recover.';
                _setup_renew_csrf();
            } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
                $error = 'New password must be at least 8 characters (or leave it blank to keep the current password).';
                _setup_renew_csrf();
            } else {
                $target = $adminById[$targetId];
                $changes = [];

                // Re-enable, clear any failed-login lockout, and optionally reset
                // the password — the three things that can lock an admin out.
                $stmt = $mysqli->prepare('UPDATE admins SET active = 1, locked_until = NULL, failed_login_attempts = 0 WHERE id = ?');
                $ok = db_prepared_execute($stmt, 'i', [$targetId]);
                $stmt->close();

                if (!$ok) {
                    $error = 'Failed to update the admin account. Please try again.';
                    _setup_renew_csrf();
                } else {
                    if ((int) ($target['active'] ?? 0) !== 1) {
                        $changes[] = 'account re-enabled';
                    }
                    if (!empty($target['is_locked']) || (int) ($target['failed_login_attempts'] ?? 0) > 0) {
                        $changes[] = 'login lockout cleared';
                    }

                    if ($newPassword !== '') {
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $mysqli->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                        $pwOk = db_prepared_execute($stmt, 'si', [$hash, $targetId]);
                        $stmt->close();
                        if (!$pwOk) {
                            $error = 'Account was re-enabled but the password reset failed. Please set it again.';
                            _setup_renew_csrf();
                            $changes = [];
                        } else {
                            $changes[] = 'password reset';
                        }
                    }

                    if ($error === '') {
                        if (!$changes) {
                            $changes[] = 'no changes were needed';
                        }
                        $success = 'Recovered ' . (string) $target['email'] . ' — ' . implode(', ', $changes) . '. You can now sign in.';
                        _setup_diag('admin_recovered', [
                            'admin_id'  => $targetId,
                            'changes'   => $changes,
                            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        ]);
                        unset($_SESSION['setup_recovery_grant']);
                        $_SESSION['flash_success'] = $success;
                        redirect(app_url('/admin/login.php'));
                    }
                }
            }
        } else {
            $error = 'Unknown action.';
            _setup_renew_csrf();
        }
    }
}

// --- Diagnostic view (also the first-run view when no admin exists yet) -----
$showFirstRunForm = $firstRunOpen;

require_once __DIR__ . '/../includes/partials/head.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-8">
            <div class="mc-neo-card">
                <div class="card-body p-4">
                    <div class="fw-bold text-primary fs-4"><?= e(APP_NAME) ?></div>
                    <div class="text-muted">
                        <?= $showFirstRunForm ? 'Create the first Super Admin account.' : 'Admin Setup — recovery and diagnostics' ?>
                    </div>
                    <hr>

                    <?php if ($error !== '') : ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if ($notice !== '') : ?>
                        <div class="alert alert-warning"><?= $notice /* intentional: contains a <span class="font-monospace"> */ ?></div>
                    <?php endif; ?>

                    <?php if ($success !== '') : ?>
                        <div class="alert alert-success"><?= e($success) ?></div>
                    <?php endif; ?>

                    <?php if ($showFirstRunForm) : ?>

                        <?php /* No admin rows exist at all, so there is nothing to protect
                                 and no lockout to recover from — first-run setup stays open. */ ?>
                        <form method="post" id="setupForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_super_admin">
                            <div class="mb-3">
                                <label for="setup_name" class="form-label">Name</label>
                                <input id="setup_name" class="form-control" name="name" autocomplete="name" value="<?= e((string) ($_POST['name'] ?? '')) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="setup_email" class="form-label">Email</label>
                                <input id="setup_email" class="form-control" type="email" name="email" autocomplete="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="setup_password" class="form-label">Password</label>
                                <input id="setup_password" class="form-control" type="password" name="password" autocomplete="new-password" required>
                            </div>
                            <div class="mb-3">
                                <label for="setup_confirm_password" class="form-label">Confirm Password</label>
                                <input id="setup_confirm_password" class="form-control" type="password" name="confirm_password" autocomplete="new-password" required>
                            </div>
                            <div class="d-grid">
                                <button class="btn btn-primary" type="submit" id="setupSubmit">Create Super Admin</button>
                            </div>
                        </form>
<script>
(function () {
    var form = document.getElementById('setupForm');
    var btn = document.getElementById('setupSubmit');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        if (btn.dataset.submitting === '1') return false;
        btn.dataset.submitting = '1';
        btn.disabled = true;
        btn.textContent = 'Creating…';
    });
})();
</script>
                        <div class="text-muted small mt-3">
                            This page disables itself automatically after the first admin is created.
                        </div>

                    <?php else : ?>

                        <div class="alert alert-info mb-3">
                            Setup has already been completed (<span class="fw-semibold"><?= $adminCount ?></span> admin
                            account<?= $adminCount === 1 ? '' : 's' ?> found). Go to the
                            <a href="<?= e(app_url('/admin/login.php')) ?>" class="alert-link">Admin Login page</a> to sign in.
                        </div>

                        <div class="small text-muted mb-2 fw-semibold">Registered admin accounts</div>
                        <div class="table-responsive mb-3 border rounded">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Email (login with this)</th>
                                        <th>Display name</th>
                                        <th>Role</th>
                                        <th>Dept ID</th>
                                        <th>Active</th>
                                        <th>Locked</th>
                                        <th>Hash</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($adminList as $a) : ?>
                                        <tr>
                                            <td><?= (int) $a['id'] ?></td>
                                            <td class="fw-semibold text-primary"><?= e((string) $a['email']) ?></td>
                                            <td><?= e((string) $a['name']) ?></td>
                                            <td><span class="badge bg-secondary"><?= e((string) $a['role']) ?></span></td>
                                            <td><?= $a['department_id'] === null ? '—' : (int) $a['department_id'] ?></td>
                                            <td>
                                                <?php if ((int) $a['active'] === 1) : ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else : ?>
                                                    <span class="badge bg-danger">Disabled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($a['is_locked'])) : ?>
                                                    <span class="badge bg-warning text-dark">Locked</span>
                                                <?php else : ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="small font-monospace text-muted"><?= e((string) $a['hash_algo']) ?>, len <?= (int) $a['hash_len'] ?></span></td>
                                            <td class="small text-muted"><?= e((string) $a['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="card border-0 bg-light mb-3">
                            <div class="card-body p-3">
                                <div class="fw-semibold mb-1">Account integrity checks</div>
                                <ul class="mb-0 small">
                                    <?php
                                    $allOk = true;
                                    foreach ($adminList as $a) {
                                        $issues = [];
                                        if ((int) $a['active'] !== 1) { $issues[] = 'account is disabled (active=0)'; $allOk = false; }
                                        if (!empty($a['is_locked'])) { $issues[] = 'temporarily locked until ' . (string) $a['locked_until']; $allOk = false; }
                                        if ((int) $a['hash_len'] < 50) { $issues[] = 'password hash too short (len ' . (int) $a['hash_len'] . ')'; $allOk = false; }
                                        if (!in_array((string) $a['hash_algo'], ['bcrypt','argon2i','argon2id'], true)) { $issues[] = 'weak/unknown hash algo ' . var_export((string) $a['hash_algo'], true); $allOk = false; }
                                        if ((string) $a['email'] === '') { $issues[] = 'email is empty'; $allOk = false; }
                                        if (!filter_var((string) $a['email'], FILTER_VALIDATE_EMAIL)) { $issues[] = 'email is not a valid address'; $allOk = false; }
                                        if ($issues) {
                                            echo '<li class="text-danger"><span class="fw-semibold">#' . (int) $a['id'] . ' ' . e((string) $a['email']) . '</span>: ' . e(implode('; ', $issues)) . '</li>';
                                        } else {
                                            echo '<li class="text-success"><span class="fw-semibold">#' . (int) $a['id'] . ' ' . e((string) $a['email']) . '</span>: all checks passed</li>';
                                        }
                                    }
                                    ?>
                                </ul>
                                <?php if ($allOk) : ?>
                                    <div class="mt-2 small text-success">Overall: <?= $adminCount === 1 ? 'admin account' : 'all admin accounts' ?> look healthy.</div>
                                <?php else : ?>
                                    <div class="mt-2 small text-danger">Overall: one or more admin accounts need attention (see above).</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($hasGrant) : ?>

                            <div class="card border-0 bg-light mb-3">
                                <div class="card-body p-3">
                                    <div class="fw-semibold mb-1">Recover admin access</div>
                                    <p class="small text-muted mb-3">
                                        Re-enables the account, clears any failed-login lockout, and optionally resets the password.
                                    </p>
                                    <form method="post" id="recoverForm">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="recover_admin">
                                        <div class="mb-3">
                                            <label for="recover_admin_id" class="form-label">Account</label>
                                            <select id="recover_admin_id" class="form-select" name="admin_id" required>
                                                <?php foreach ($adminList as $a) : ?>
                                                    <option value="<?= (int) $a['id'] ?>" <?= (int) ($_POST['admin_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                                                        #<?= (int) $a['id'] ?> — <?= e((string) $a['email']) ?> (<?= e((string) $a['role']) ?><?= (int) $a['active'] === 1 ? '' : ', disabled' ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="recover_password" class="form-label">New password <span class="text-muted fw-normal">(optional — blank keeps the current one)</span></label>
                                            <input id="recover_password" class="form-control" type="password" name="password" autocomplete="new-password" minlength="8">
                                        </div>
                                        <div class="d-grid">
                                            <button class="btn btn-primary" type="submit">Recover Access</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card border-0 bg-light">
                                <div class="card-body p-3">
                                    <div class="fw-semibold mb-1">Add another Super Admin</div>
                                    <p class="small text-muted mb-3">
                                        Use this if you need a second set of credentials. It does not modify existing accounts.
                                    </p>
                                    <form method="post" id="setupForm">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="create_super_admin">
                                        <div class="mb-3">
                                            <label for="setup_name" class="form-label">Name</label>
                                            <input id="setup_name" class="form-control" name="name" autocomplete="name" value="<?= e((string) ($_POST['name'] ?? '')) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="setup_email" class="form-label">Email</label>
                                            <input id="setup_email" class="form-control" type="email" name="email" autocomplete="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="setup_password" class="form-label">Password</label>
                                            <input id="setup_password" class="form-control" type="password" name="password" autocomplete="new-password" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="setup_confirm_password" class="form-label">Confirm Password</label>
                                            <input id="setup_confirm_password" class="form-control" type="password" name="confirm_password" autocomplete="new-password" required>
                                        </div>
                                        <div class="d-grid">
                                            <button class="btn btn-primary" type="submit" id="setupSubmit">Create Super Admin</button>
                                        </div>
                                    </form>
<script>
(function () {
    var form = document.getElementById('setupForm');
    var btn = document.getElementById('setupSubmit');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        if (btn.dataset.submitting === '1') return false;
        btn.dataset.submitting = '1';
        btn.disabled = true;
        btn.textContent = 'Creating…';
    });
})();
</script>
                                </div>
                            </div>

                        <?php else : ?>

                            <div class="card border-0 bg-light">
                                <div class="card-body p-3">
                                    <div class="fw-semibold mb-1">Need to re-enable an account or add a Super Admin?</div>

                                    <?php if ($recoveryKeyConfigured) : ?>
                                        <p class="small text-muted mb-3">
                                            This page cannot sign you in, so it needs the server-side recovery key
                                            (<span class="font-monospace">SETUP_RECOVERY_KEY</span> in <span class="font-monospace">.env</span>)
                                            to unlock the recovery and add-admin forms.
                                        </p>
                                        <form method="get" action="<?= e(app_url('/admin/setup.php')) ?>" id="keyForm" class="mb-2">
                                            <div class="mb-2">
                                                <label for="recovery_key" class="form-label">Recovery key</label>
                                                <input id="recovery_key" class="form-control" type="password" name="key" autocomplete="off" required>
                                            </div>
                                            <button class="btn btn-outline-primary btn-sm" type="submit">Unlock recovery</button>
                                        </form>
                                    <?php else : ?>
                                        <div class="alert alert-warning mb-2">
                                            Web-based recovery is <strong>disabled</strong> because
                                            <span class="font-monospace">SETUP_RECOVERY_KEY</span> is not set in
                                            <span class="font-monospace">.env</span>. This fails closed on purpose so an
                                            unauthenticated visitor can never create a Super Admin.
                                        </div>
                                    <?php endif; ?>

                                    <ul class="small mb-2">
                                        <li>To recover an account, run <span class="font-monospace">php bin/recover_admin.php --list</span> then <span class="font-monospace">php bin/recover_admin.php --enable=&lt;email&gt;</span> from the project folder.</li>
                                        <li>To add a Super Admin from the CLI, run <span class="font-monospace">php bin/recover_admin.php --add-super --email=&lt;email&gt; --password=&lt;pass&gt; --name=&quot;Full Name&quot;</span>.</li>
                                        <li>To wipe all admins and re-run first-time setup, run <span class="font-monospace">DELETE FROM admins;</span> in your MySQL client (phpMyAdmin), then reload this page.</li>
                                    </ul>
                                </div>
                            </div>

                        <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/partials/foot.php';
