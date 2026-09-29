<?php

declare(strict_types=1);

@ignore_user_abort(true);
@set_time_limit(60);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/basuraalert.php';
require_once __DIR__ . '/../includes/sms.php';

$_actionHandlersStarted = true;
try {

csrf_check();
require_api_login();
require_csrf_token();

$db = db();

ba_register_notification_flush($db);

$user = current_user($db);
if (!$user) json_response(['ok' => false, 'error' => 'Session expired.'], 401);
$userId = (int) $user['id'];

$action = isset($_POST['action']) ? (string) $_POST['action'] : 'submit_report';

if ($action === 'submit_report') {
    $category = isset($_POST['category']) ? (string) $_POST['category'] : '';
    $barangayId = isset($_POST['barangay_id']) ? (int) $_POST['barangay_id'] : 0;
    $date = isset($_POST['date_of_concern']) ? trim((string) $_POST['date_of_concern']) : '';
    $street = isset($_POST['street']) ? trim((string) $_POST['street']) : '';
    $landmark = isset($_POST['landmark']) ? trim((string) $_POST['landmark']) : '';
    $description = isset($_POST['description']) ? trim((string) $_POST['description']) : '';

    $errors = [];
    $allowedCategories = array_keys(ba_report_category_options());
    if (!in_array($category, $allowedCategories, true)) {
        $errors[] = 'Invalid report category.';
    }
    if ($barangayId <= 0) {
        $errors[] = 'Please select a barangay.';
    }
    if ($date === '' || !strtotime($date)) {
        $errors[] = 'Please enter a valid date of concern.';
    } else {
        $ts = strtotime($date);
        if ($ts > time()) $errors[] = 'Date of concern cannot be in the future.';
    }
    if ($street === '') $errors[] = 'Please enter the street address.';
    elseif (strlen($street) > 190) $errors[] = 'Street name is too long.';
    if ($landmark === '') $errors[] = 'Please enter a nearby landmark.';
    elseif (strlen($landmark) > 190) $errors[] = 'Landmark is too long.';
    if (trim($description) === '') $errors[] = 'Please provide a description.';
    elseif (strlen($description) > 4000) $errors[] = 'Description is too long.';

    if (count($errors) > 0) {
        json_response(['ok' => false, 'error' => implode(' ', $errors), 'errors' => $errors]);
    }

    $photos = [];
    $anyPhotoAttempt = isset($_FILES['photos']) && is_array($_FILES['photos']['name']);
    if (!$anyPhotoAttempt) {
        $errors[] = 'Please upload at least 1 photo as evidence.';
        json_response(['ok' => false, 'error' => 'Please upload at least 1 photo as evidence.', 'errors' => $errors]);
    }
    ensure_upload_dir(UPLOADS_DIR . '/basuraalert_reports');
    $count = count($_FILES['photos']['name']);
    if ($count === 0) {
        $errors[] = 'Please upload at least 1 photo as evidence.';
        json_response(['ok' => false, 'error' => 'Please upload at least 1 photo as evidence.', 'errors' => $errors]);
    }
    if ($count > 5) {
        json_response(['ok' => false, 'error' => 'Maximum 5 photos allowed.']);
    }
    $mimeExtMap = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/webp' => '.webp'];
    $localPaths = [];
    for ($i = 0; $i < $count; $i++) {
        $errCode = isset($_FILES['photos']['error'][$i]) ? (int) $_FILES['photos']['error'][$i] : UPLOAD_ERR_NO_FILE;
        if ($errCode !== UPLOAD_ERR_OK) {
            if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'Photo at position ' . ($i + 1) . ' exceeds the maximum allowed size (5 MB).';
            } elseif ($errCode !== UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Photo at position ' . ($i + 1) . ' failed to upload. Please try again or select a different file.';
            }
            continue;
        }
        $tmpName = $_FILES['photos']['tmp_name'][$i] ?? '';
        if (!is_uploaded_file($tmpName)) {
            $errors[] = 'Photo at position ' . ($i + 1) . ' is not a valid uploaded file.';
            continue;
        }
        $size = (int) ($_FILES['photos']['size'][$i] ?? 0);
        if ($size <= 0) {
            $errors[] = 'Photo at position ' . ($i + 1) . ' is empty.';
            continue;
        }
        if ($size > 5 * 1024 * 1024) {
            $errors[] = 'Photo at position ' . ($i + 1) . ' must be 5MB or smaller.';
            continue;
        }
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) @finfo_file($finfo, $tmpName) : '';
        if (!isset($mimeExtMap[$mime])) {
            $errors[] = 'Photo at position ' . ($i + 1) . ' is an invalid type. Allowed: JPG, PNG, GIF, WEBP.';
            continue;
        }
        $fileEntry = [
            'tmp_name' => $tmpName,
            'name' => (string) ($_FILES['photos']['name'][$i] ?? ('photo_' . ($i + 1) . '.' . ltrim($mimeExtMap[$mime], '.'))),
            'size' => $size,
            'error' => UPLOAD_ERR_OK,
            'type' => (string) ($_FILES['photos']['type'][$i] ?? $mime),
        ];
        $stored = ba_store_image_mock($fileEntry);
        if (empty($stored['ok']) || empty($stored['secure_url'])) {
            $errors[] = (isset($stored['error']) ? (string) $stored['error'] : ('Failed to save photo at position ' . ($i + 1) . '. Please try again.'));
            continue;
        }
        $photos[] = (string) $stored['secure_url'];
        // Keep the on-disk path so a later validation failure can unlink the
        // orphan. The previous cleanup guessed from the URL prefix
        // (str_starts_with($stale, '/uploads/')), but ba_store_image_mock()
        // writes to storage/mock_images/ and returns a /storage/... URL, so
        // that check never matched and every failed submit leaked files.
        $localPaths[] = (string) ($stored['local_path'] ?? '');
    }

    if (count($photos) === 0) {
        if (count($errors) === 0) {
            $errors[] = 'Please upload at least 1 valid photo as evidence.';
        }
        json_response(['ok' => false, 'error' => implode(' ', $errors), 'errors' => $errors]);
    }
    if (count($errors) > 0) {
        foreach ($localPaths as $abs) {
            if ($abs !== '' && is_file($abs)) {
                @unlink($abs);
            }
        }
        json_response(['ok' => false, 'error' => implode(' ', $errors), 'errors' => $errors]);
    }

    $payload = [
        'user_id' => $userId,
        'barangay_id' => $barangayId,
        'category' => $category,
        'date_of_concern' => date('Y-m-d', strtotime($date)),
        'street' => $street !== '' ? $street : null,
        'landmark' => $landmark !== '' ? $landmark : null,
        'description' => $description,
        'photos_json' => $photos,
    ];

    $result = ba_create_report($db, $payload);
    json_response($result);
}

if ($action === 'submit_feedback') {
    $kind = isset($_POST['kind']) ? (string) $_POST['kind'] : 'Contact_Us';
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    if (!in_array($kind, ['Feedback','FAQ_Suggestion','Contact_Us'], true)) $kind = 'Contact_Us';
    if ($subject === '' || strlen($subject) > 190) json_response(['ok' => false, 'error' => 'Please enter a valid subject (max 190 chars).']);
    if ($message === '' || strlen($message) > 4000) json_response(['ok' => false, 'error' => 'Please enter a message (max 4000 chars).']);
    $id = ba_create_feedback($db, [
        'user_id' => $userId,
        'kind' => $kind,
        'subject' => $subject,
        'message' => $message,
    ]);
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Failed to send your message.']);
    json_response(['ok' => true, 'message' => 'Message sent. An administrator will review and respond.']);
}

if ($action === 'mark_report_rating') {
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : null;
    if ($reportId <= 0 || $rating < 1 || $rating > 5) json_response(['ok' => false, 'error' => 'Invalid rating.']);
    $report = ba_get_report($db, $reportId);
    if (!$report || (int) $report['user_id'] !== $userId) json_response(['ok' => false, 'error' => 'Report not found.'], 404);
    if (!in_array(ba_normalize_report_status((string)$report['status']), ['Completed','Rejected'], true)) {
        json_response(['ok' => false, 'error' => 'Rating is available once the report reaches Completed or Rejected.']);
    }
    $ok = ba_save_report_rating($db, $reportId, $userId, $rating, $comment);
    json_response(['ok' => $ok, 'message' => $ok ? 'Thank you for your rating!' : 'Failed to save rating.']);
}

if ($action === 'mark_notifications_read') {
    $notifId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($notifId > 0) {
        ba_mark_notification_read($db, $notifId, $userId);
    } else {
        ba_mark_all_notifications_read($db, $userId);
    }
    json_response(['ok' => true]);
}

if ($action === 'delete_notification') {
    $notifId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($notifId <= 0) json_response(['ok' => false, 'error' => 'Invalid notification id.'], 400);
    $ok = ba_delete_notification($db, $notifId, $userId);
    json_response(['ok' => $ok, 'message' => $ok ? 'Notification deleted.' : 'Could not delete notification.']);
}

if ($action === 'delete_all_notifications') {
    $onlyUnread = !empty($_POST['only_unread']);
    $deleted = ba_delete_all_notifications_for_user($db, $userId, $onlyUnread);
    $label = $onlyUnread ? 'unread notifications' : 'notifications';
    $msg = match (true) {
        $deleted > 1 => $deleted . ' ' . $label . ' deleted.',
        $deleted === 1 => '1 ' . substr($label, 0, -1) . ' deleted.',
        default => 'No ' . $label . ' to delete.',
    };
    json_response(['ok' => true, 'deleted_count' => $deleted, 'only_unread' => $onlyUnread, 'message' => $msg]);
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);

} catch (Throwable $e) {
    $err = 'Server error while processing your request. Please try again in a moment.';
    $hint = '';
    $eClass = get_class($e);
    $eMsg = $e->getMessage();
    $eFile = basename((string)$e->getFile());
    $eLine = $e->getLine();
    @error_log('[basuraalert_actions] uncaught ' . $eClass . ': ' . $eMsg . ' @ ' . $eFile . ':' . $eLine . PHP_EOL, 3, __DIR__ . '/../app_error.log');
    if ($e instanceof InvalidArgumentException || $eClass === 'InvalidArgumentException'
        || $e instanceof mysqli_sql_exception || stripos($eClass, 'mysqli') !== false
        || $e instanceof RuntimeException) {
        $hint = $eMsg;
    }
    if (ini_get('display_errors') && (string)ini_get('display_errors') !== '' && strcasecmp((string)ini_get('display_errors'), 'off') !== 0 && strcasecmp((string)ini_get('display_errors'), '0') !== 0) {
        $hint = $hint !== '' ? $hint . ' · ' : '';
        $hint .= $eClass . ' @ ' . $eFile . ':' . $eLine;
    }
    json_response(['ok' => false, 'error' => $err, 'hint' => $hint], 500);
}
