<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(app_url('/public/dashboard.php'));
}

$pageTitle = 'Welcome';
require_once __DIR__ . '/../includes/partials/head.php';

?>
<div class="mc-home-page">
    <div class="mc-home-hero">
        <div class="mc-home-hero-overlay">
            <div class="mc-home-brand-card">
                <div class="mc-home-brand-row">
                    <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="Official Seal of Marikina City" class="mc-home-brand-seal">
                    <div class="mc-home-brand-text">
                        <h1><?= e(APP_NAME) ?></h1>
                        <p>City Government of Marikina · Official Citizen Portal</p>
                    </div>
                    <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module — Waste Management System" class="mc-home-brand-basura">
                </div>

                <div class="mc-home-wordmark-box">
                    <img src="<?= e(app_url('/assets/img/Marikina-e-Concern.png')) ?>" alt="Marikina E-Concern Wordmark" class="mc-home-wordmark-img">
                </div>

                <h2 class="mc-home-hero-title">
                    Report concerns and track progress <span>online</span>.
                </h2>
                <p class="mc-home-hero-tagline">
                    Isumite ang inyong reklamo, follow-up ang status, at makipag-ugnayan sa tamang departamento
                    — mula waste collection schedules hanggang business permit concerns. Lahat official, mabilis, at transparent.
                </p>

                <div class="mc-home-hero-cta-row">
                    <a class="btn btn-primary ba-btn-primary mc-home-cta-primary" href="<?= e(app_url('/public/login.php')) ?>">
                        <i data-lucide="log-in" class="lucide-18"></i> Click here to login
                    </a>
                    <a class="btn btn-outline-primary mc-home-cta-secondary" href="<?= e(app_url('/public/register.php')) ?>">
                        <i data-lucide="user-plus" class="lucide-18"></i> Create an account
                    </a>
                </div>

                <div class="mc-home-hero-meta">
                    <span class="mc-home-meta-item">
                        <i data-lucide="shield-check" class="lucide-18"></i> Secure · DPA RA 10173 compliant
                    </span>
                    <span class="mc-home-meta-item">
                        <i data-lucide="building-2" class="lucide-18"></i> 20+ city departments
                    </span>
                    <span class="mc-home-meta-item">
                        <i data-lucide="trash-2" class="lucide-18"></i> BasuraAlert module included
                    </span>
                    <span class="mc-home-meta-item">
                        <i data-lucide="smartphone" class="lucide-18"></i> Mobile-first responsive
                    </span>
                </div>

                <div class="mc-home-privacy-note">
                    <i data-lucide="info" class="lucide-14 mc-home-privacy-icon"></i>
                    By creating an account, you agree to the Data Privacy Act (RA 10173) and understand that false reports may have consequences.
                </div>
            </div>
        </div>
    </div>

    <div class="mc-home-content">
        <div class="mc-home-section-title">
            <h2>Mga serbisyo ng lungsod</h2>
            <p>Pumili sa ibaba para mabilis na makapagsimula.</p>
        </div>

        <div class="mc-home-tiles">
            <a class="mc-home-tile" href="<?= e(app_url('/public/submit_concern.php')) ?>">
                <div class="mc-home-tile-icon mc-home-tile-icon--concern">
                    <i data-lucide="message-square-warning" class="lucide-24"></i>
                </div>
                <div class="mc-home-tile-body">
                    <h3>E-Concern</h3>
                    <p>Magsumite o mag-follow up ng reklamo sa tamang departamento.</p>
                    <span class="mc-home-tile-arrow">Buksan <i data-lucide="arrow-right" class="lucide-14"></i></span>
                </div>
            </a>

            <a class="mc-home-tile" href="<?= e(app_url('/public/ba_schedule.php')) ?>">
                <img class="mc-home-tile-img" src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="">
                <div class="mc-home-tile-body">
                    <h3>BasuraAlert</h3>
                    <p>Tingnan ang collection schedule sa inyong barangay at mag-request ng pickup.</p>
                    <span class="mc-home-tile-arrow">Buksan <i data-lucide="arrow-right" class="lucide-14"></i></span>
                </div>
            </a>

            <a class="mc-home-tile" href="<?= e(app_url('/public/ba_announcements.php')) ?>">
                <div class="mc-home-tile-icon mc-home-tile-icon--news">
                    <i data-lucide="newspaper" class="lucide-24"></i>
                </div>
                <div class="mc-home-tile-body">
                    <h3>Announcements</h3>
                    <p>City-wide bulletins, class suspensions, at barangay advisories.</p>
                    <span class="mc-home-tile-arrow">Tingnan lahat <i data-lucide="arrow-right" class="lucide-14"></i></span>
                </div>
            </a>

            <a class="mc-home-tile" href="<?= e(app_url('/public/ba_faq.php')) ?>">
                <div class="mc-home-tile-icon mc-home-tile-icon--faq">
                    <i data-lucide="book-open" class="lucide-24"></i>
                </div>
                <div class="mc-home-tile-body">
                    <h3>FAQ & Contact</h3>
                    <p>Mga madalas itanong at direktang contact info ng bawat departamento.</p>
                    <span class="mc-home-tile-arrow">Tingnan lahat <i data-lucide="arrow-right" class="lucide-14"></i></span>
                </div>
            </a>
        </div>

        <div class="mc-home-announcements">
            <div class="mc-home-announcements-head">
                <h3><i data-lucide="megaphone" class="lucide-16"></i> Mga pinakabagong balita</h3>
                <a href="<?= e(app_url('/public/ba_announcements.php')) ?>" class="mc-home-announcements-more">
                    Lahat ng balita <i data-lucide="arrow-right" class="lucide-14"></i>
                </a>
            </div>
            <ul class="mc-home-announcements-list">
                <li>
                    <time datetime="2026-09-05">Set 5, 2026</time>
                    <div>
                        <h4>Pagsuspinde ng klase — Lungsod ng Marikina</h4>
                        <p>Official announcement mula sa Office of the Mayor tungkol sa suspension of classes ngayong araw.</p>
                    </div>
                </li>
                <li>
                    <time datetime="2026-09-03">Set 3, 2026</time>
                    <div>
                        <h4>Bagong schedule ng basura — Barangay Industrial Valley Complex</h4>
                        <p>Simula Set 12, 2026, ang koleksyon ng residual waste ay tuwing Martes at Biyernes na lamang.</p>
                    </div>
                </li>
                <li>
                    <time datetime="2026-08-28">Ago 28, 2026</time>
                    <div>
                        <h4>Free rabies vaccination para sa mga alaga</h4>
                        <p>City Veterinary Office, 4th floor ng Marikina City Hall Extension. Lunes hanggang Biyernes 8AM-4PM.</p>
                    </div>
                </li>
            </ul>
        </div>

        <div class="mc-home-cityfoot">
            <div class="mc-home-cityfoot-col">
                <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>" alt="" class="mc-home-cityfoot-seal">
                <div>
                    <p class="mc-home-cityfoot-name">City Government of Marikina</p>
                    <p class="mc-home-cityfoot-sub">Shoe Capital of the Philippines · Since 1630</p>
                </div>
            </div>
            <div class="mc-home-cityfoot-col">
                <p><strong><i data-lucide="map-pin" class="lucide-14"></i> Address:</strong> Marikina City Hall, Shoe Ave, Marikina, 1800 Metro Manila</p>
                <p><strong><i data-lucide="phone" class="lucide-14"></i> Telephone:</strong> (02) 8646-0071 · (02) 8646-0072</p>
                <p><strong><i data-lucide="mail" class="lucide-14"></i> Email:</strong> info@marikina.gov.ph</p>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/partials/foot.php'; ?>

