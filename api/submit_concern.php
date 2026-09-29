<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/basuraalert.php';

function _submit_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'One image is larger than the server allows (check php.ini upload_max_filesize).';
        case UPLOAD_ERR_FORM_SIZE:
            return 'One image exceeds the MAX_FILE_SIZE limit in the form.';
        case UPLOAD_ERR_PARTIAL:
            return 'One image was only partially uploaded. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return '';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server is missing a temporary upload folder. Contact admin.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server could not save the image to disk. Contact admin.';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension blocked the upload. Please try again.';
        default:
            return 'One of the uploads failed (error code ' . $code . ').';
    }
}

function _submit_shorthand_to_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    $last = strtolower($v[strlen($v) - 1]);
    $num = (int) $v;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}

function _submit_diagnostic_log(string $event, array $extra = []): void
{
    $payload = array_merge([
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'content_length' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
        'post_count' => count($_POST),
        'files_count' => count($_FILES),
    ], $extra);
    $line = 'SUBMIT_CONCERN: ' . json_encode($payload, JSON_UNESCAPED_SLASHES);
    @error_log($line, 3, __DIR__ . '/../app_error.log');
}

ob_start();

require_api_login();
require_csrf_token();

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxBytes = _submit_shorthand_to_bytes((string) ini_get('post_max_size'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && $contentLength > 0) {
    _submit_diagnostic_log('post_max_exceeded', ['post_max_bytes' => $postMaxBytes]);
    ob_end_clean();
    json_response([
        'ok' => false,
        'error' => 'Your submission is too large. Total payload must be less than ' . ini_get('post_max_size') . '. Try fewer images or smaller photos.',
        'hint' => 'post_max_size=' . ini_get('post_max_size') . '; reduce images or compress them first.'
    ], 413);
}

$mysqli = db();
$user = current_user($mysqli);
if (!$user) {
    ob_end_clean();
    json_response(['ok' => false, 'error' => 'Session expired. Please log in again.'], 401);
}

$uploadsBase = rtrim((string) UPLOADS_DIR, '/\\');
$uploadsWritable = true;
if (!is_dir($uploadsBase)) {
    $uploadsWritable = @mkdir($uploadsBase, 0755, true);
}
if (!$uploadsWritable || !is_writable($uploadsBase)) {
    _submit_diagnostic_log('uploads_dir_not_writable', ['dir' => $uploadsBase, 'writable' => is_writable($uploadsBase)]);
    ob_end_clean();
    json_response(['ok' => false, 'error' => 'Server upload folder is not writable. Contact admin.'], 500);
}

$concernTypeId = (int) ($_POST['concern_type_id'] ?? 0);
$street = trim((string) ($_POST['street'] ?? ''));
$barangay = trim((string) ($_POST['barangay'] ?? ''));
$landmark = trim((string) ($_POST['landmark'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));

if ($concernTypeId <= 0) {
    _submit_diagnostic_log('validation_missing_concern_type', ['content_length' => $contentLength, 'post_max' => $postMaxBytes, 'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80)]);
    ob_end_clean();
    json_response(['ok' => false, 'error' => 'Please select a concern type.'], 422);
}
if ($street === '') { ob_end_clean(); json_response(['ok' => false, 'error' => 'Street name is required.'], 422); }
if ($barangay === '') { ob_end_clean(); json_response(['ok' => false, 'error' => 'Barangay is required.'], 422); }
if ($landmark === '') { ob_end_clean(); json_response(['ok' => false, 'error' => 'Landmark is required.'], 422); }
if ($description === '' || mb_strlen($description) > 500) { ob_end_clean(); json_response(['ok' => false, 'error' => 'Description is required (max 500 characters).'], 422); }

$stmt = $mysqli->prepare('SELECT id FROM concern_types WHERE id = ? LIMIT 1');
db_prepared_execute($stmt, 'i', [$concernTypeId]);
$result = $stmt->get_result();
$type = $result ? $result->fetch_assoc() : null;
$stmt->close();
if (!$type) { ob_end_clean(); json_response(['ok' => false, 'error' => 'Invalid concern type.'], 422); }

$files = $_FILES['photos'] ?? null;
$paths = [];
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if ($files && is_array($files['name'] ?? null)) {
    $count = count($files['name']);
    if ($count > 5) {
        ob_end_clean();
        json_response(['ok' => false, 'error' => 'You can upload up to 5 images only.'], 422);
    }
    for ($i = 0; $i < $count; $i++) {
        $errCode = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($errCode !== UPLOAD_ERR_OK) {
            $msg = _submit_upload_error_message($errCode);
            if ($msg === '') continue;
            ob_end_clean();
            json_response(['ok' => false, 'error' => $msg], 422);
        }
        $size = (int) ($files['size'][$i] ?? 0);
        if ($size > 5 * 1024 * 1024) {
            ob_end_clean();
            json_response(['ok' => false, 'error' => 'Each image must be 5MB or below.'], 422);
        }
        if ($size <= 0) {
            ob_end_clean();
            json_response(['ok' => false, 'error' => 'One of the uploaded images is empty.'], 422);
        }
        $tmp = (string) ($files['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            ob_end_clean();
            json_response(['ok' => false, 'error' => 'One of the uploaded images failed. Please try again.'], 422);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!in_array($mime, $allowedMimes, true)) {
            $info = @getimagesize($tmp);
            if (!$info) {
                ob_end_clean();
                json_response(['ok' => false, 'error' => 'Only JPG, PNG, GIF, and WEBP image files are allowed. Detected: ' . $mime], 422);
            }
        }
    }
}

$mysqli->begin_transaction();

try {
    $reportNumber = generate_report_number($mysqli);

    $stmt = $mysqli->prepare('INSERT INTO concerns (report_number, user_id, concern_type_id, street, barangay, landmark, description, status, photos_json) VALUES (?, ?, ?, ?, ?, ?, ?, "New", NULL)');
    $ok = db_prepared_execute($stmt, 'siissss', [$reportNumber, (int) $user['id'], $concernTypeId, $street, $barangay, $landmark, $description]);
    $stmt->close();

    if (!$ok) {
        throw new RuntimeException('insert_failed');
    }

    $concernId = (int) $mysqli->insert_id;

    $stmt = $mysqli->prepare('INSERT INTO concern_timeline (concern_id, status, note, event_type) VALUES (?, "New", ?, ?)');
    db_prepared_execute($stmt, 'iss', [$concernId, 'Submitted', 'submitted']);
    $stmt->close();

    if ($files && is_array($files['name'] ?? null)) {
        $count = count($files['name']);
        $dir = $uploadsBase . '/concerns/' . $reportNumber;
        ensure_upload_dir($dir);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('upload_dir_unwritable:' . $dir);
        }

        for ($i = 0; $i < $count; $i++) {
            $errCode = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($errCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('upload_invalid_tmp_file:' . $i);
            }
            $orig = (string) ($files['name'][$i] ?? '');
            $size = (int) ($files['size'][$i] ?? 0);
            if ($size > 5 * 1024 * 1024) {
                throw new RuntimeException('upload_file_too_large_position_' . ($i + 1));
            }
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($tmp);
                switch ($mime) {
                    case 'image/jpeg': $ext = 'jpg'; break;
                    case 'image/png':  $ext = 'png'; break;
                    case 'image/gif':  $ext = 'gif'; break;
                    case 'image/webp': $ext = 'webp'; break;
                    default:           $ext = 'jpg'; break;
                }
            }
            $fileEntry = [
                'tmp_name' => $tmp,
                'name' => ($orig !== '' ? $orig : ('concern_' . $reportNumber . '_' . ($i + 1) . '.' . $ext)),
                'size' => $size,
                'error' => UPLOAD_ERR_OK,
                'type' => (string) ($files['type'][$i] ?? ('image/' . $ext)),
            ];
            $stored = ba_store_image_mock($fileEntry);
            if (empty($stored['ok']) || empty($stored['secure_url'])) {
                $errMsg = (isset($stored['error']) ? (string) $stored['error'] : 'Failed to save photo at position ' . ($i + 1));
                throw new RuntimeException('upload_save_failed_position_' . ($i + 1) . '_' . $errMsg);
            }
            $paths[] = (string) $stored['secure_url'];
        }
    }

    if ($paths !== []) {
        $json = json_encode($paths, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('json_encode_failed');
        }
        $stmt = $mysqli->prepare('UPDATE concerns SET photos_json = ? WHERE id = ?');
        db_prepared_execute($stmt, 'si', [$json, $concernId]);
        $stmt->close();
    }

    db_drain($mysqli);
    $mysqli->commit();
    ob_end_clean();
    json_response(['ok' => true, 'concern_id' => $concernId, 'report_number' => $reportNumber]);
} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch (Throwable $_) {}
    _submit_diagnostic_log('submit_exception', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    $msg = $e->getMessage();
    $userMsg = 'Submit failed. Please try again.';
    $status = 500;
    if (str_starts_with($msg, 'upload_move_failed:')) {
        $userMsg = 'Server could not save one image. Contact admin (disk full or permissions).';
    } elseif (str_starts_with($msg, 'upload_dir_unwritable:')) {
        $userMsg = 'Server upload folder is not writable. Contact admin.';
    }
    ob_end_clean();
    json_response(['ok' => false, 'error' => $userMsg, 'hint' => $msg], $status);
}
