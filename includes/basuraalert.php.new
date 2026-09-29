<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/email_verification.php';

// --- Modular split (Option B) — extracted services for maintainability ---
// Each file defines a subset of ba_* functions. This wrapper preserves
// backwards-compat so existing `require_once .../basuraalert.php` still works.
require_once __DIR__ . '/BasuraAlert/Schedule.php';
require_once __DIR__ . '/BasuraAlert/Notifications.php';
require_once __DIR__ . '/BasuraAlert/Reports.php';
require_once __DIR__ . '/BasuraAlert/Dropoffs.php';
require_once __DIR__ . '/BasuraAlert/External.php';
require_once __DIR__ . '/BasuraAlert/Validation.php';

// --- End modular requires ---
