<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../basuraalert.php';
/* For mc_avatar_url() / mc_avatar_initials(), used by the greeting avatar below.
   current_user() carries avatar_public_id, so the photo is available here. */
require_once __DIR__ . '/../Avatar.php';

require_login();

$mysqli = db();
$user = current_user($mysqli);
if (!$user) {
    logout_user();
    redirect(app_url('/public/login.php'));
}

$activeNav = isset($activeNav) ? (string) $activeNav : 'home';

$baUnreadCount = 0;
try {
    $baUnreadCount = ba_count_unread_notifications($mysqli, (int) $user['id']);
} catch (Throwable $e) {
    $baUnreadCount = 0;
}

$_baBasuraLogoUrl = app_url('/assets/img/Basura Module Logo.jpg');
$_baHomeActive = ($activeNav === 'home') ? 'active' : '';
$_baNavs = [
    'ba_dashboard'   => ['label' => 'Dashboard',          'icon' => 'layout-dashboard', 'url' => '/public/ba_dashboard.php'],
    'ba_schedule'    => ['label' => 'Collection Schedule','icon' => 'calendar-days',    'url' => '/public/ba_schedule.php'],
    'ba_waste'       => ['label' => 'Waste Segregation',  'icon' => 'leaf',             'url' => '/public/ba_waste.php'],
    'ba_dropoff_map' => ['label' => 'Drop-off Map',       'icon' => 'map-pin',          'url' => '/public/ba_dropoff_map.php'],
    'ba_announcements' => ['label' => 'Announcements',    'icon' => 'megaphone',        'url' => '/public/ba_announcements.php'],
    'ba_notifications' => ['label' => 'Notifications',    'icon' => 'bell-ring',        'url' => '/public/ba_notifications.php', 'badge' => $baUnreadCount],
    'ba_report'       => ['label' => 'Report an Issue',   'icon' => 'plus-circle',      'url' => '/public/ba_report.php'],
    'ba_my_reports'   => ['label' => 'My Reports',        'icon' => 'clipboard-list',   'url' => '/public/ba_my_reports.php'],
    'ba_faq'          => ['label' => 'FAQ & Contact',     'icon' => 'circle-help',      'url' => '/public/ba_faq.php'],
];
$_ecNavs = [
    'submit'  => ['label' => 'Submit Concern',  'icon' => 'send-horizontal', 'url' => '/public/submit_concern.php'],
    'my'      => ['label' => 'My Concern',      'icon' => 'clipboard-list',  'url' => '/public/my_concern.php'],
    'profile' => ['label' => 'Profile',         'icon' => 'user-circle-2',   'url' => '/public/profile.php'],
];
?>
<div class="mc-app-shell">

    <!-- ===========================================================
         PUBLIC MOBILE DRAWER SIDEBAR (< 768px only)
         Desktop ≥ 768px: rendered display:none; top-tabs used.
         Bootstrap collapse id=sidebarMenu preserved.
         =========================================================== -->
    <div class="mc-sidebar-drawer collapse d-md-none border-end min-vh-100" id="sidebarMenu">
        <div class="position-sticky pt-4 mc-sidebar-inner">

            <div class="mc-sidebar-brand px-4 pb-4 mb-3 border-bottom">
                <div class="mc-sidebar-brand-row mb-3">
                    <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
                         alt="Official Seal of the City of Marikina"
                         width="44" height="44" loading="eager" decoding="async"
                         class="mc-sidebar-seal">
                    <div class="mc-sidebar-brand-text">
                        <div class="mc-sidebar-appname"><?= e(APP_NAME) ?></div>
                        <div class="mc-sidebar-subtitle text-muted">Citizen Portal</div>
                    </div>
                </div>
                <img src="<?= e(app_url('/assets/img/Marikina-e-Concern.png')) ?>"
                     alt="Marikina e-Concern — Citizen Portal"
                     width="100%" height="30" loading="eager" decoding="async"
                     class="mc-sidebar-wordmark">
            </div>

            <ul class="nav flex-column mc-sidebar-nav">

                <li class="nav-item mc-nav-item">
                    <a class="nav-link mc-nav-link <?= $_baHomeActive ?>"
                       href="<?= e(app_url('/public/dashboard.php')) ?>">
                        <i data-lucide="layout-dashboard" class="lucide lucide-18 mc-nav-icon"></i>
                        <span class="mc-nav-label">Home</span>
                    </a>
                </li>

                <li class="nav-item mc-nav-section mt-4 pt-3 border-top">
                    <div class="px-4 text-muted small text-uppercase mb-2 mc-nav-section-title">
                        <span class="mc-nav-section-inline">
                            <i data-lucide="leaf" class="lucide lucide-14"></i>
                            BasuraAlert
                        </span>
                    </div>
                </li>
                <?php foreach ($_baNavs as $_baKey => $_ba) : ?>
                    <?php $_active = ($activeNav === $_baKey) ? 'active' : ''; ?>
                    <li class="nav-item mc-nav-item">
                        <a class="nav-link mc-nav-link <?= $_active ?>"
                           href="<?= e(app_url($_ba['url'])) ?>">
                            <i data-lucide="<?= e($_ba['icon']) ?>" class="lucide lucide-18 mc-nav-icon"></i>
                            <span class="mc-nav-label"><?= e($_ba['label']) ?></span>
                            <?php if (!empty($_ba['badge'])) : ?>
                                <span class="badge mc-nav-badge ms-auto"><?= e((string) $_ba['badge']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>

                <li class="nav-item mc-nav-section mt-4 pt-3 border-top">
                    <div class="px-4 text-muted small text-uppercase mb-2 mc-nav-section-title">
                        <span class="mc-nav-section-inline">
                            <i data-lucide="building-2" class="lucide lucide-14"></i>
                            E-Concern
                        </span>
                    </div>
                </li>
                <?php foreach ($_ecNavs as $_ecKey => $_ec) : ?>
                    <?php $_active = ($activeNav === $_ecKey) ? 'active' : ''; ?>
                    <li class="nav-item mc-nav-item">
                        <a class="nav-link mc-nav-link <?= $_active ?>"
                           href="<?= e(app_url($_ec['url'])) ?>">
                            <i data-lucide="<?= e($_ec['icon']) ?>" class="lucide lucide-18 mc-nav-icon"></i>
                            <span class="mc-nav-label"><?= e($_ec['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>

                <li class="nav-item mc-nav-item mt-4 pt-3 border-top">
                    <a class="nav-link mc-nav-link mc-nav-logout"
                       href="<?= e(app_url('/public/logout.php')) ?>">
                        <i data-lucide="log-out" class="lucide lucide-18 mc-nav-icon"></i>
                        <span class="mc-nav-label">Logout</span>
                    </a>
                </li>

            </ul>

            <div class="px-4 mt-4 mb-4 text-center">
                <img src="<?= e(app_url('/assets/img/AlagangMarikinaCity.png')) ?>"
                     alt="Alagang Marikina — City Program Seal"
                     width="64" height="64" loading="lazy" decoding="async"
                     class="mc-sidebar-seal-bottom">
            </div>
        </div>
    </div>

    <!-- ===========================================================
         DESKTOP TOP NAVIGATION BAR (≥ 768px visible; mobile brand row only)
         Sticky. Cream for Public (Navy reserved for Admin).
         Official images §7.2 1/2/3/4 placed inline.
         =========================================================== -->
    <header class="mc-topnav mc-topnav-public border-bottom">
        <div class="mc-topnav-inner">

            <div class="mc-topnav-brand d-flex align-items-center gap-2 me-4 flex-shrink-0">
                <button class="ba-btn ba-btn-ghost ba-btn-sm mc-sidebar-toggle d-md-none me-2" type="button"
                        data-bs-toggle="collapse" data-bs-target="#sidebarMenu"
                        aria-controls="sidebarMenu" aria-expanded="false" aria-label="Open navigation menu">
                    <i data-lucide="menu" class="lucide lucide-16"></i>
                </button>
                <a class="mc-topnav-brand-link d-flex align-items-center gap-2 text-decoration-none"
                   href="<?= e(app_url('/public/dashboard.php')) ?>" aria-label="Go to Home Dashboard">
                    <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
                         alt="Official Seal of Marikina City"
                         width="36" height="36" loading="eager" decoding="async"
                         class="mc-topnav-seal">
                    <img src="<?= e(app_url('/assets/img/Marikina-e-Concern.png')) ?>"
                         alt="Marikina e-Concern — Citizen Portal"
                         width="160" height="28" loading="eager" decoding="async"
                         class="mc-topnav-wordmark">
                </a>
            </div>

            <nav class="mc-topnav-tabs d-none d-md-flex flex-wrap align-items-center gap-2 me-auto ms-2"
                 aria-label="Primary section tabs">

                <a class="mc-topnav-tab <?= $_baHomeActive ?>"
                   href="<?= e(app_url('/public/dashboard.php')) ?>">
                    <i data-lucide="layout-dashboard" class="lucide lucide-16"></i>
                    <span>Home</span>
                </a>

                <div class="mc-topnav-dropdown dropdown">
                    <button class="mc-topnav-tab mc-topnav-tab-dropdown dropdown-toggle" type="button"
                            id="baDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?= e($_baBasuraLogoUrl) ?>"
                             alt="" width="20" height="20" loading="lazy" decoding="async"
                             class="mc-topnav-tab-logo">
                        <i data-lucide="leaf" class="lucide lucide-14"></i>
                        <span>BasuraAlert</span>
                    </button>
                    <ul class="dropdown-menu mc-topnav-dropdown-menu" aria-labelledby="baDropdown">
                        <?php foreach ($_baNavs as $_baKey => $_ba) : ?>
                            <?php $_active = ($activeNav === $_baKey) ? 'active' : ''; ?>
                            <li>
                                <a class="dropdown-item mc-topnav-dropdown-item <?= $_active ?>"
                                   href="<?= e(app_url($_ba['url'])) ?>">
                                    <i data-lucide="<?= e($_ba['icon']) ?>" class="lucide lucide-14"></i>
                                    <span><?= e($_ba['label']) ?></span>
                                    <?php if (!empty($_ba['badge'])) : ?>
                                        <span class="badge mc-nav-badge ms-auto"><?= e((string) $_ba['badge']) ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="mc-topnav-dropdown dropdown">
                    <button class="mc-topnav-tab mc-topnav-tab-dropdown dropdown-toggle" type="button"
                            id="ecDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i data-lucide="building-2" class="lucide lucide-14"></i>
                        <span>E-Concern</span>
                    </button>
                    <ul class="dropdown-menu mc-topnav-dropdown-menu" aria-labelledby="ecDropdown">
                        <?php foreach ($_ecNavs as $_ecKey => $_ec) : ?>
                            <?php $_active = ($activeNav === $_ecKey) ? 'active' : ''; ?>
                            <li>
                                <a class="dropdown-item mc-topnav-dropdown-item <?= $_active ?>"
                                   href="<?= e(app_url($_ec['url'])) ?>">
                                    <i data-lucide="<?= e($_ec['icon']) ?>" class="lucide lucide-14"></i>
                                    <span><?= e($_ec['label']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

            </nav>

            <div class="mc-topnav-actions d-flex align-items-center gap-2 flex-shrink-0">

                <a class="mc-topnav-action d-none d-md-inline-flex"
                   href="<?= e(app_url('/public/ba_notifications.php')) ?>"
                   aria-label="Notifications">
                    <i data-lucide="bell-ring" class="lucide lucide-16"></i>
                    <?php if ($baUnreadCount > 0) : ?>
                        <span class="mc-topnav-notif-badge"><?= e((string) $baUnreadCount) ?></span>
                    <?php endif; ?>
                </a>

                <a class="mc-user-greeting mc-user-greeting--avatar d-none d-md-inline-flex align-items-center gap-2"
                   href="<?= e(app_url('/public/profile.php')) ?>"
                   aria-label="Go to my profile page">
                    <?php
                    /* The shared partial, not a second hand-rolled avatar: it owns the
                       img/fallback markup, and the same tile the citizen sees on
                       their own profile. sm (40px) matches the 40x40 pill the topnav
                       collapses this greeting to between 768-1099px.

                       Not $avatarEditable - a 35px camera badge has nowhere to go on
                       a 40px nav tile, and a <button> inside this <a> would be
                       invalid HTML. It does get a file input, so clicking the photo
                       opens the picker: without one it rendered with cursor:pointer
                       and silently did nothing. The id goes on the .mc-avatar element
                       itself, because that is where --mc-avatar-size lives for
                       avatar.js to read back. */
                    $avatarPublicId  = (string) ($user['avatar_public_id'] ?? '');
                    $avatarVersion   = mc_avatar_version_from_url($user['avatar_url'] ?? '');
                    $avatarName      = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
                    $avatarSize      = 'sm';
                    $avatarRole      = '';
                    $avatarEditable  = false;
                    $avatarInputId   = 'userTopAvatarFile';
                    $avatarImgId     = '';
                    $avatarActionsId = 'userTopAvatarBox';
                    ?>
                    <span class="mc-topnav-avatar"><?php require __DIR__ . '/avatar.php'; ?></span>
                    <span class="mc-user-name">Hi, <?= e($user['first_name']) ?></span>
                </a>

                <img src="<?= e(app_url('/assets/img/AlagangMarikinaCity.png')) ?>"
                     alt="Alagang Marikina Seal"
                     width="28" height="28" loading="lazy" decoding="async"
                     class="mc-topnav-seal-end d-none d-md-inline-block">

                <a class="mc-topnav-logout d-none d-md-inline-flex"
                   href="<?= e(app_url('/public/logout.php')) ?>"
                   aria-label="Log out of citizen portal">
                    <i data-lucide="log-out" class="lucide lucide-16"></i>
                    <span class="d-none d-lg-inline ms-1">Logout</span>
                </a>
            </div>

        </div>
    </header>

    <!-- ===========================================================
         MAIN CONTENT AREA
         =========================================================== -->
    <main class="mc-shell-main">
        <?php
            $_flashErrors = [];
            if (isset($_SESSION['flash_error']) && is_string($_SESSION['flash_error']) && $_SESSION['flash_error'] !== '') {
                $_flashErrors[] = $_SESSION['flash_error'];
                unset($_SESSION['flash_error']);
            }
            if (isset($_SESSION['flash_success']) && is_string($_SESSION['flash_success']) && $_SESSION['flash_success'] !== '') {
                $_flashSuccess = $_SESSION['flash_success'];
                unset($_SESSION['flash_success']);
            }
        ?>
        <?php if (isset($_flashSuccess) && is_string($_flashSuccess)) : ?>
            <div class="alert alert-success small mb-3 d-flex align-items-start mc-flash mc-flash-success" role="alert">
                <i data-lucide="check-circle-2" class="lucide lucide-18 mc-flash-icon"></i>
                <span class="mc-flash-text"><?= e($_flashSuccess) ?></span>
            </div>
        <?php endif; ?>
        <?php foreach ($_flashErrors as $_flashMsg) : ?>
            <div class="alert alert-warning small mb-3 d-flex align-items-start mc-flash mc-flash-warning" role="alert">
                <i data-lucide="alert-triangle" class="lucide lucide-18 mc-flash-icon"></i>
                <span class="mc-flash-text"><?= e($_flashMsg) ?></span>
            </div>
        <?php endforeach; ?>
        <?php unset($_flashErrors, $_flashMsg, $_flashSuccess, $_baKey, $_ba, $_ecKey, $_ec, $_active); ?>

        <div class="d-md-none d-flex align-items-center justify-content-between mb-4 mc-shell-topbar-mobile">
            <a class="mc-user-greeting mc-user-greeting--avatar d-inline-flex align-items-center gap-2"
               href="<?= e(app_url('/public/profile.php')) ?>"
               aria-label="Go to my profile page">
                <?php
                /* Same shared partial as the desktop greeting above, with "Mobile"
                   ids so the two do not collide on the same page. Locals are
                   cleared afterwards to keep them out of the including page. */
                $avatarPublicId  = (string) ($user['avatar_public_id'] ?? '');
                $avatarVersion   = mc_avatar_version_from_url($user['avatar_url'] ?? '');
                $avatarName      = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
                $avatarSize      = 'sm';
                $avatarRole      = '';
                $avatarEditable  = false;
                $avatarInputId   = 'userTopAvatarFileMobile';
                $avatarImgId     = '';
                $avatarActionsId = 'userTopAvatarBoxMobile';
                ?>
                <span class="mc-topnav-avatar"><?php require __DIR__ . '/avatar.php'; ?></span>
                <span class="mc-user-name">Hi, <?= e($user['first_name']) ?></span>
            </a>
            <?php unset($avatarPublicId, $avatarVersion, $avatarName, $avatarSize, $avatarRole, $avatarEditable, $avatarInputId, $avatarImgId, $avatarActionsId); ?>
        </div>
