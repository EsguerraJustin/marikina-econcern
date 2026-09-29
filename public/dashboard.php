<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Home Dashboard';
$activeNav = 'home';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

?>
<div class="mc-dash-page">
    <section class="mc-dash-hero">
        <div class="mc-dash-hero-inner">
            <div class="mc-dash-hero-left">
                <div class="mc-dash-avatar-wrap" aria-hidden="true">
                    <i data-lucide="user-circle-2" class="lucide"></i>
                </div>
                <div class="mc-dash-welcome">
                    <h1>Welcome to <?= e(APP_NAME) ?></h1>
                    <p>Submit a concern and monitor its progress in one place.</p>
                </div>
            </div>
            <div class="mc-dash-hero-right">
                <a class="btn btn-primary mc-dash-hero-cta mc-dash-hero-cta--white" href="<?= e(app_url('/public/submit_concern.php')) ?>">
                    <i data-lucide="send-horizontal" class="lucide lucide-16 me-2" style="color:#3368A0!important;stroke:#3368A0!important;stroke-width:2.3!important;width:16px!important;height:16px!important;display:inline-block!important;flex-shrink:0;opacity:1!important;visibility:visible!important"></i>Submit a Concern
                </a>
            </div>
        </div>
    </section>

    <section class="mc-dash-stats" aria-label="Concern status counts">
        <?php
        $cards = [
            ['New', 'mc-dash-stat--new', 'inbox'],
            ['Ongoing', 'mc-dash-stat--ongoing', 'clock-8'],
            ['Acknowledge', 'mc-dash-stat--total', 'check-check'],
            ['Completed', 'mc-dash-stat--done', 'badge-check'],
            ['Cancelled', 'mc-dash-stat--cancel', 'ban'],
        ];
        foreach ($cards as $c) :
            [$status, $cls, $icon] = $c;
        ?>
            <div class="mc-dash-stat <?= e($cls) ?>">
                <div class="mc-dash-stat-head">
                    <div style="min-width:0">
                        <div class="mc-dash-stat-label"><?= e($status) ?></div>
                        <div class="mc-dash-stat-value" data-count-status="<?= e($status) ?>">—</div>
                    </div>
                    <div class="mc-dash-stat-icon" aria-hidden="true">
                        <i data-lucide="<?= e($icon) ?>" class="lucide"></i>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <footer class="mc-dash-footer" aria-label="Dashboard footer brand">
        <div class="mc-dash-footer-inner">
            <div class="mc-dash-footer-brand">
                <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="Official Seal of Marikina City" width="44" height="44" loading="lazy" decoding="async" class="mc-dash-footer-seal">
                <div class="mc-dash-footer-meta">
                    <div class="mc-dash-footer-title">City Government of Marikina</div>
                    <div class="mc-dash-footer-sub">Shoe Capital of the Philippines · Since 1630</div>
                </div>
                <img src="<?= e(app_url('/assets/img/Marikina-e-Concern.png')) ?>" alt="Marikina e-Concern" width="260" height="24" loading="lazy" decoding="async" class="mc-dash-footer-wordmark">
            </div>
            <img src="<?= e(app_url('/assets/img/AlagangMarikinaCity.png')) ?>" alt="Alagang Marikina City Program" width="220" height="36" loading="lazy" decoding="async" class="mc-dash-footer-alagang">
        </div>
    </footer>
</div>

<?php
$dashboardCountsEndpoint = e(app_url('/api/dashboard_counts.php'));
$pageScripts = <<<HTML
<script>
$(function () {
  $.getJSON("{$dashboardCountsEndpoint}")
    .done(function (res) {
      if (!res || !res.ok) return;
      const counts = res.counts || {};
      $("[data-count-status]").each(function () {
        const status = $(this).data("count-status");
        $(this).text(counts[status] != null ? counts[status] : 0);
      });
    });
});
</script>
HTML;

require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
