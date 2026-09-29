<?php
declare(strict_types=1);

// Lightweight bootstrap — does not require DB connection for pure unit tests
// Load env + helpers so functions are available without full app boot

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/basuraalert.php';
// Avatar.php brings its own External.php dependency (see the note in that
// file), so mc_avatar_* is available without the app shell.
require_once __DIR__ . '/../includes/Avatar.php';

// Mock DB for tests that need it — they will skip if no DB
