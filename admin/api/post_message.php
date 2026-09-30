<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_auth.php';
/* The notification service was not in scope here, which is the whole reason an
   admin's message never reached the citizen's Notifications tab: this endpoint
   required admin_auth.php only, so ba_push_notification() did not exist. */
require_once __DIR__ . '/../../includes/basuraalert.php';

require_api_admin_login();
require_csrf_token();

$mysqli = db();
$admin = current_admin($mysqli);
if (!$admin) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}

$id = (int) ($_POST['id'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));

if ($id <= 0) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
if ($message === '') json_response(['ok' => false, 'error' => 'Message is required.'], 422);

$where = 'c.id = ?';
$types = 'i';
$params = [$id];

if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $types .= 'i';
    $params[] = (int) ($admin['department_id'] ?? 0);
}

/* user_id and report_number come back with the authorisation check because both
   are needed to build the notification: the first is the recipient, the second
   is what tells the citizen WHICH concern the message is about. The original
   query selected c.id only, so no notification could have been addressed. */
$stmt = $mysqli->prepare(
    'SELECT c.id, c.user_id, c.report_number
     FROM concerns c
     JOIN concern_types ct ON ct.id = c.concern_type_id
     JOIN departments d ON d.id = ct.department_id
     WHERE ' . $where . '
     LIMIT 1'
);
db_prepared_execute($stmt, $types, $params);
$res = $stmt->get_result();
$concern = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!is_array($concern) || empty($concern)) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

$stmt = $mysqli->prepare('INSERT INTO concern_messages (concern_id, sender, message) VALUES (?, "department", ?)');
$saved = db_prepared_execute($stmt, 'is', [$id, $message]);
$stmt->close();

if (!$saved) {
    json_response(['ok' => false, 'error' => 'send_failed'], 500);
}

/* The message is the source of truth, so the notification is best-effort: it
   runs after the INSERT has already committed and before the response is
   emitted, and a Throwable is caught and logged rather than propagated, so it
   can never fail the send or corrupt the JSON the admin client is waiting on. A
   lost message would be strictly worse than a missing notification. */
$citizenId = (int) ($concern['user_id'] ?? 0);
$reference = trim((string) ($concern['report_number'] ?? ''));

if ($citizenId > 0) {
    try {
        /* in_app only. ba_push_notification_via_prefs() writes one row per
           enabled channel and ba_count_unread_notifications() counts rows, so
           fanning a single message out to sms+email would show the citizen three
           notifications and inflate the bell badge threefold. */
        $excerpt = function_exists('mb_strimwidth')
            ? trim(mb_strimwidth($message, 0, 140, '…', 'UTF-8'))
            : substr($message, 0, 140);

        ba_push_notification(
            $mysqli,
            $citizenId,
            'concern_message',
            'New message from the city government',
            ($reference !== '' ? 'About your concern ' . $reference . ': ' : '') . $excerpt,
            'in_app',
            ['table' => 'concerns', 'id' => $id]
        );
    } catch (Throwable $e) {
        @error_log('[admin_post_message] citizen notification failed: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../../app_error.log');
    }
}

json_response(['ok' => true, 'notified' => $citizenId > 0]);
