<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

require_login();

$mysqli = db();
$user = current_user($mysqli);
if (!is_array($user)) {
    logout_user();
    redirect(app_url('/public/login.php'));
}

$pageTitle = 'Notification Preferences';
$activeNav = 'ba_notifications';

$userId = (int) $user['id'];
ba_ensure_user_prefs($mysqli, $userId);
$prefs = ba_get_user_prefs($mysqli, $userId);

$saveAlert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $payload = [
        'enable_email_reminders'   => isset($_POST['enable_email_reminders']) ? 1 : 0,
        'enable_sms_reminders'     => isset($_POST['enable_sms_reminders']) ? 1 : 0,
        'enable_in_app_reminders'  => isset($_POST['enable_in_app_reminders']) ? 1 : 0,
        'reminder_hours_before'    => (int) ($_POST['reminder_hours_before'] ?? 12),
    ];
    $eventToggles = [];
    foreach (['reminder','schedule_change','announcement','report_submit','report_update','feedback_reply'] as $ev) {
        foreach (['in_app','email','sms'] as $ch) {
            $key = 'ev_' . $ev . '_' . $ch;
            $eventToggles[$key] = isset($_POST[$key]) ? 1 : 0;
        }
    }
    $payload = array_merge($payload, $eventToggles);
    $ok = ba_save_user_prefs($mysqli, $userId, $payload);
    $prefs = ba_get_user_prefs($mysqli, $userId);
    $saveAlert = $ok
        ? '<div class="alert alert-success">Notification preferences saved. Your delivery channel selection applies to all new BasuraAlert notifications.</div>'
        : '<div class="alert alert-danger">Failed to save preferences. Please try again.</div>';
}

$eventTypes = [
    'reminder'       => ['label' => 'Pre-collection reminder',            'tip'   => 'Reminder sent hours before your next scheduled pickup.'],
    'schedule_change'=> ['label' => 'Schedule change notice',              'tip'   => 'Reroutes, one-time exceptions, or barangay schedule updates.'],
    'announcement'   => ['label' => 'Announcement or public alert',        'tip'   => 'Holiday notices, service disruptions, and city advisories.'],
    'report_submit'  => ['label' => 'Report submit confirmation',          'tip'   => 'Acknowledgment when you submit a new issue report.'],
    'report_update'  => ['label' => 'Report status update',                'tip'   => 'Admin acknowledgment, in-progress, completion, or rejection.'],
    'feedback_reply' => ['label' => 'Feedback / Contact-Us reply',         'tip'   => 'When an admin replies to your feedback or contact inquiry.'],
];

$channels = [
    'in_app' => ['label' => 'In-app',      'hint' => 'Free, always-on, and immediate.'],
    'email'  => ['label' => 'Email',       'hint' => 'Requires an email address on your profile. Sent via Brevo SMTP.'],
    'sms'    => ['label' => 'SMS (Text)',  'hint' => 'Requires a mobile number on your profile. Sent via TextBee SMS gateway.'],
];

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';
?>
<?= csrf_header_meta() ?>

<div class="row g-3 mb-3">
    <div class="col-md-8">
        <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" class="mc-topnav-tab-logo me-2" style="width:28px;height:28px;"><h2 class="h4 fw-bold mb-0">Notification Preferences</h2>
        <div class="text-muted small">Choose how BasuraAlert reaches you for each type of event. All channels deliver live: In-app (immediate), Email (Brevo), SMS (TextBee).</div>
    </div>
    <div class="col-md-4 text-md-end">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('/public/ba_notifications.php')) ?>">← Back to notifications</a>
    </div>
</div>

<?php if ($saveAlert !== '') echo $saveAlert; ?>

<form method="POST" class="card border-0 shadow-sm mb-3">
    <?= csrf_field() ?>
    <div class="card-header bg-white"><div class="fw-bold">Global delivery channels</div></div>
    <div class="card-body p-4">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="swInApp" name="enable_in_app_reminders" <?= !empty($prefs['enable_in_app_reminders']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="swInApp"><strong>In-app notifications</strong><div class="small text-muted">Inbox-style list within the site — always on.</div></label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="swEmail" name="enable_email_reminders" <?= !empty($prefs['enable_email_reminders']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="swEmail"><strong>Email</strong><div class="small text-muted">Sent via Brevo transactional SMTP API.</div></label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="swSms" name="enable_sms_reminders" <?= !empty($prefs['enable_sms_reminders']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="swSms"><strong>SMS (text)</strong><div class="small text-muted">Sent via TextBee SMS gateway — real text message to your mobile.</div></label>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label for="leadRange" class="form-label d-flex justify-content-between align-items-center">
                <span><strong>Pre-collection reminder lead time</strong></span>
                <span class="badge bg-primary fs-6"><?= (int) ($prefs['reminder_hours_before'] ?? 12) ?> hours before pickup</span>
            </label>
            <input type="range" min="1" max="72" step="1" id="leadRange" name="reminder_hours_before" value="<?= (int) ($prefs['reminder_hours_before'] ?? 12) ?>" class="form-range">
            <div class="d-flex justify-content-between small text-muted mt-1"><span>1h (very short)</span><span>12h (default)</span><span>24h</span><span>48h</span><span>72h (very early)</span></div>
            <div class="form-text">Reminder events are generated automatically by running <code class="text-muted">php ba_run_reminders.php</code> (cron or browser — delivers live via in-app / email / SMS).</div>
        </div>
    </div>

    <div class="card-header bg-white border-top"><div class="fw-bold">Fine-grained per-event × channel toggles</div><div class="small text-muted">Untick any channel–event combination you do not want to receive.</div></div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th style="min-width:30%;">Event type</th>
                        <?php foreach ($channels as $chKey => $ch) : ?>
                            <th class="text-center"><?= e($ch['label']) ?><div class="small text-muted fw-normal"><?= e($ch['hint']) ?></div></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eventTypes as $evKey => $ev) : ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($ev['label']) ?></div>
                                <div class="small text-muted"><?= e($ev['tip']) ?></div>
                            </td>
                            <?php foreach ($channels as $chKey => $ch) :
                                $cell = 'ev_' . $evKey . '_' . $chKey;
                                $val = isset($prefs[$cell]) ? (int) $prefs[$cell] : 1;
                            ?>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox" name="<?= e($cell) ?>" id="<?= e($cell) ?>" <?= $val ? 'checked' : '' ?>>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="d-grid d-md-flex justify-content-md-end mt-4 gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/ba_dashboard.php')) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Preferences</button>
        </div>
    </div>
</form>

<?php
require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
