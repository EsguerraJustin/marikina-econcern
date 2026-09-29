<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';

logout_admin();
redirect(app_url('/admin/login.php?logged_out=1'));

