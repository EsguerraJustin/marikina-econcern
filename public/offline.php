<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Offline';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="container py-5" style="max-width: 560px;">
    <div class="text-center mb-4">
        <img src="<?= e(app_url('/assets/icons/icon-192.png')) ?>" alt="E-Concern" width="96" height="96" class="mb-3 rounded">
        <h1 class="h4">You are offline</h1>
        <p class="text-muted">Walang internet connection. Ang iyong submitted reports ay nasa server pa rin — bumalik kapag may signal na.</p>
    </div>
    <div class="d-grid gap-2">
        <a class="btn btn-primary" href="<?= e(app_url('/public/index.php')) ?>">Retry — back to home</a>
        <a class="btn btn-outline-secondary" href="<?= e(app_url('/public/ba_schedule.php')) ?>">View cached schedule</a>
    </div>
    <p class="text-muted small text-center mt-4 mb-0">Marikina E-Concern works best online. All reports and API calls sync with the live server.</p>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>
