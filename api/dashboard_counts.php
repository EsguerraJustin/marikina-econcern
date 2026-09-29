<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_api_login();
require_csrf_token();

$mysqli = db();
$user = current_user($mysqli);
if (!$user) {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}

$stmt = $mysqli->prepare('SELECT status, COUNT(*) AS cnt FROM concerns WHERE user_id = ? GROUP BY status');
db_prepared_execute($stmt, 'i', [(int) $user['id']]);
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$counts = [
    'New' => 0,
    'Ongoing' => 0,
    'Acknowledge' => 0,
    'Completed' => 0,
    'Cancelled' => 0,
];

foreach ($rows as $r) {
    $status = (string) ($r['status'] ?? '');
    if (isset($counts[$status])) {
        $counts[$status] = (int) $r['cnt'];
    }
}

json_response(['ok' => true, 'counts' => $counts]);
