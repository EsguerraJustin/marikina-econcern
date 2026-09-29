<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

// Helper to read env with fallback — keeps local XAMPP working without .env
function _cfg_env(string $key, mixed $fallback): mixed
{
    $v = env($key, null);
    return $v !== null && $v !== '' ? $v : $fallback;
}

define('APP_NAME', (string) _cfg_env('APP_NAME', 'E-Concern'));
// Store WITHOUT %20 — helpers.php encodes spaces. Legacy %20 value still works via env override.
define('APP_BASE_URL', (string) _cfg_env('APP_BASE_URL', '/Marikina Concern/Marikina Concern'));
define('APP_PUBLIC_URL', (string) _cfg_env('APP_PUBLIC_URL', 'http://localhost/Marikina%20Concern/Marikina%20Concern'));

define('DB_HOST', (string) _cfg_env('DB_HOST', '127.0.0.1'));
define('DB_USER', (string) _cfg_env('DB_USER', 'root'));
define('DB_PASS', (string) _cfg_env('DB_PASS', ''));
define('DB_NAME', (string) _cfg_env('DB_NAME', 'e_concern'));

define('UPLOADS_DIR', __DIR__ . '/../uploads');

define('MAIL_HOST', (string) _cfg_env('MAIL_HOST', 'smtp-relay.brevo.com'));
define('MAIL_PORT', (int) _cfg_env('MAIL_PORT', 587));
define('MAIL_USERNAME', (string) _cfg_env('MAIL_USERNAME', 'b581fb001@smtp-brevo.com'));
define('MAIL_PASSWORD', (string) _cfg_env('MAIL_PASSWORD', ''));
define('MAIL_FROM_ADDRESS', (string) _cfg_env('MAIL_FROM_ADDRESS', 'jcesguerra21@gmail.com'));
define('MAIL_FROM_NAME', (string) _cfg_env('MAIL_FROM_NAME', 'Marikina E-Concern'));
define('BREVO_API_KEY', (string) _cfg_env('BREVO_API_KEY', ''));

define('SMS_PROVIDER', (string) _cfg_env('SMS_PROVIDER', 'textbee'));
define('SMS_TEXTBEE_BASE_URL', (string) _cfg_env('SMS_TEXTBEE_BASE_URL', 'https://api.textbee.dev/api/v1'));
define('SMS_TEXTBEE_ENDPOINT', (string) _cfg_env('SMS_TEXTBEE_ENDPOINT', SMS_TEXTBEE_BASE_URL . '/gateway/send-sms'));
define('SMS_TEXTBEE_API_KEY', (string) _cfg_env('SMS_TEXTBEE_API_KEY', ''));
define('SMS_TEXTBEE_DEVICE_ID', (string) _cfg_env('SMS_TEXTBEE_DEVICE_ID', ''));
define('SMS_SENDER', (string) _cfg_env('SMS_SENDER', 'MarikinaCG'));

define('STORAGE_DIR', __DIR__ . '/../storage');

define('CLOUDINARY_CLOUD_NAME', (string) _cfg_env('CLOUDINARY_CLOUD_NAME', ''));
define('CLOUDINARY_API_KEY', (string) _cfg_env('CLOUDINARY_API_KEY', ''));
define('CLOUDINARY_API_SECRET', (string) _cfg_env('CLOUDINARY_API_SECRET', ''));
define('CLOUDINARY_BASE_URL', 'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME);
define('CLOUDINARY_UPLOAD_PRESET', (string) _cfg_env('CLOUDINARY_UPLOAD_PRESET', (defined('CLOUDINARY_UPLOAD_PRESET_SET') ? CLOUDINARY_UPLOAD_PRESET_SET : 'marikina_concern_unsigned')));

define('CALENDARIFIC_API_KEY', (string) _cfg_env('CALENDARIFIC_API_KEY', ''));
define('OPENWEATHER_API_KEY', (string) _cfg_env('OPENWEATHER_API_KEY', ''));

/* Admin recovery key.
 * Gates the unauthenticated recovery / create-super-admin paths in admin/setup.php.
 * There is deliberately NO usable default: while this is empty, web-based admin
 * recovery is disabled and the CLI (php bin/recover_admin.php) is the only route.
 * Generate one with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;" */
define('SETUP_RECOVERY_KEY', (string) _cfg_env('SETUP_RECOVERY_KEY', ''));

define('MOCK_EMAIL_FAIL_PCT', (int) _cfg_env('MOCK_EMAIL_FAIL_PCT', 0));
define('MOCK_WEATHER_FIXTURE', (string) _cfg_env('MOCK_WEATHER_FIXTURE', 'sunny-2-day'));
define('MOCK_HOLIDAY_FIXTURE', (string) _cfg_env('MOCK_HOLIDAY_FIXTURE', 'split-2026'));
define('MOCK_GEOCODING_MODE', (string) _cfg_env('MOCK_GEOCODING_MODE', 'valid'));
define('MOCK_IMAGE_STORAGE_MODE', (string) _cfg_env('MOCK_IMAGE_STORAGE_MODE', 'local_disk'));
define('MOCK_SMS_FAIL_PCT', (int) _cfg_env('MOCK_SMS_FAIL_PCT', 0));

// Warn in logs if critical secrets are empty (helps detect missing .env)
if (BREVO_API_KEY === '' || SMS_TEXTBEE_API_KEY === '' || CLOUDINARY_CLOUD_NAME === '') {
    // Only log once per request
    static $_warned = false;
    if (!$_warned) {
        $_warned = true;
        @error_log('[config] Warning: BREVO_API_KEY / SMS_TEXTBEE_API_KEY / CLOUDINARY_* empty — set them in .env (see .env.example)', 3, __DIR__ . '/../app_error.log');
    }
}

// Warn when emailed verification/reset links would point at localhost while the
// app is being served on a public host (links are built from APP_PUBLIC_URL).
if (is_string(APP_PUBLIC_URL) && preg_match('#^(https?://)?(localhost|127\.0\.0\.1)([:/]|$)#i', APP_PUBLIC_URL)) {
    $reqHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($reqHost !== '' && !preg_match('#^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#i', $reqHost)) {
        @error_log('[config] Warning: APP_PUBLIC_URL still points at localhost while serving ' . $reqHost . ' — emailed verification/reset links will be unreachable. Set APP_PUBLIC_URL to the public origin in .env', 3, __DIR__ . '/../app_error.log');
    }
}

