<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin_auth.php';
/* For mc_avatar_url() / mc_avatar_initials(), used by the greeting avatar below.
   current_admin() carries avatar_public_id, so the photo is available here. */
require_once __DIR__ . '/../Avatar.php';

require_admin_login();

$mysqli = db();
$admin = current_admin($mysqli);
if (!$admin) {
    logout_admin();
    redirect(app_url('/admin/login.php'));
}

$activeNav = isset($activeNav) ? (string) $activeNav : 'dashboard';
$isSuper = ($admin['role'] ?? '') === 'super_admin';

$_baCoreNavs = [
    'dashboard'  => ['label' => 'Admin Dashboard',   'icon' => 'layout-dashboard',  'url' => '/admin/dashboard.php'],
    'concerns'   => ['label' => 'Manage Concerns',   'icon' => 'inbox',             'url' => '/admin/concerns.php'],
];
$_baModuleNavs = [
    'ba_dashboard'    => ['label' => 'Module Dashboard', 'icon' => 'layout-dashboard',     'url' => '/admin/ba_dashboard.php'],
    'ba_schedules'    => ['label' => 'Schedules',          'icon' => 'calendar-days',      'url' => '/admin/ba_schedules.php'],
    'ba_waste'        => ['label' => 'Waste Guide',        'icon' => 'leaf',               'url' => '/admin/ba_waste.php'],
    'ba_dropoffs'     => ['label' => 'Drop-off Points',    'icon' => 'map-pin',            'url' => '/admin/ba_dropoffs.php'],
    'ba_announcements'=> ['label' => 'Announcements',      'icon' => 'megaphone',          'url' => '/admin/ba_announcements.php'],
    'ba_notifications'=> ['label' => 'Push Notifications', 'icon' => 'bell-ring',          'url' => '/admin/ba_notifications.php'],
    'ba_reports'      => ['label' => 'Resident Reports',   'icon' => 'clipboard-list',     'url' => '/admin/ba_reports.php'],
    'ba_feedback'     => ['label' => 'Feedback / FAQ',     'icon' => 'message-circle-question','url' => '/admin/ba_feedback.php'],
];
$_baMgmtNavs = [];
if ($isSuper) {
    $_baMgmtNavs = [
        'departments' => ['label' => 'Departments',     'icon' => 'building-2',  'url' => '/admin/departments.php'],
        'types'       => ['label' => 'Concern Types',   'icon' => 'tags',        'url' => '/admin/concern_types.php'],
        'admins'      => ['label' => 'Admin Accounts',  'icon' => 'user-cog',    'url' => '/admin/admin_accounts.php'],
        'citizens'    => ['label' => 'Citizens',        'icon' => 'users',       'url' => '/admin/citizens.php'],
        'reports'     => ['label' => 'Analytics',       'icon' => 'bar-chart-3', 'url' => '/admin/reports.php'],
    ];
}
$_baProfileNavs = [
    'profile' => ['label' => 'Admin Profile', 'icon' => 'user-circle-2', 'url' => '/admin/profile.php'],
];
$_baLogoutUrl = app_url('/admin/logout.php');
?>
<div class="mc-app-shell mc-app-shell-admin">

    <!-- ===========================================================
         ADMIN MOBILE DRAWER SIDEBAR (< 768px only)
         =========================================================== -->
    <div class="mc-sidebar-drawer mc-sidebar-admin collapse d-md-none border-end min-vh-100" id="sidebarMenu">
        <div class="position-sticky pt-4 mc-sidebar-inner mc-sidebar-inner-admin">

            <div class="mc-sidebar-brand px-4 pb-4 mb-3 border-bottom mc-admin-border">
                <div class="mc-sidebar-brand-row mb-3">
                    <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
                         alt="Official Seal of the City of Marikina"
                         width="40" height="40" loading="eager" decoding="async"
                         class="mc-sidebar-seal mc-admin-seal-pad">
                    <div class="mc-sidebar-brand-text">
                        <div class="mc-sidebar-appname mc-admin-white"><?= e(APP_NAME) ?></div>
                        <div class="mc-sidebar-subtitle mc-admin-sub">Administrator Portal</div>
                    </div>
                </div>
                <img src="<?= e(app_url('/assets/img/Marikina-e-Concern.png')) ?>"
                     alt="Marikina e-Concern — Admin Portal"
                     width="100%" height="28" loading="eager" decoding="async"
                     class="mc-sidebar-wordmark mc-admin-wordmark-inv">
            </div>

            <ul class="nav flex-column mc-sidebar-nav mc-admin-nav-ink">

                <?php foreach ($_baCoreNavs as $_k => $_n) : ?>
                    <?php $_a = ($activeNav === $_k) ? 'active' : ''; ?>
                    <li class="nav-item mc-nav-item">
                        <a class="nav-link mc-nav-link mc-admin-nav-link <?= $_a ?>"
                           href="<?= e(app_url($_n['url'])) ?>">
                            <i data-lucide="<?= e($_n['icon']) ?>" class="lucide lucide-18 mc-nav-icon"></i>
                            <span class="mc-nav-label"><?= e($_n['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>

                <li class="nav-item mc-nav-section mt-4 pt-3 border-top mc-admin-border">
                    <div class="px-4 small text-uppercase mb-2 mc-nav-section-title mc-admin-section-sub">
                        <span class="mc-nav-section-inline">
                            <i data-lucide="leaf" class="lucide lucide-14"></i>
                            BasuraAlert
                        </span>
                    </div>
                </li>
                <?php foreach ($_baModuleNavs as $_k => $_n) : ?>
                    <?php $_a = ($activeNav === $_k) ? 'active' : ''; ?>
                    <li class="nav-item mc-nav-item">
                        <a class="nav-link mc-nav-link mc-admin-nav-link <?= $_a ?>"
                           href="<?= e(app_url($_n['url'])) ?>">
                            <i data-lucide="<?= e($_n['icon']) ?>" class="lucide lucide-18 mc-nav-icon"></i>
                            <span class="mc-nav-label"><?= e($_n['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>

                <?php if ($isSuper) : ?>
                    <li class="nav-item mc-nav-section mt-4 pt-3 border-top mc-admin-border">
                        <div class="px-4 small text-uppercase mb-2 mc-nav-section-title mc-admin-section-sub">
                            <span class="mc-nav-section-inline">
                                <i data-lucide="shield-check" class="lucide lucide-14"></i>
                                Management
                            </span>
                        </div>
                    </li>
                    <?php foreach ($_baMgmtNavs as $_k => $_n) : ?>
                        <?php $_a = ($activeNav === $_k) ? 'active' : ''; ?>
                        <li class="nav-item mc-nav-item">
                            <a class="nav-link mc-nav-link mc-admin-nav-link <?= $_a ?>"
                               href="<?= e(app_url($_n['url'])) ?>">
                                <i data-lucide="<?= e($_n['icon']) ?>" class="lucide lucide-18 mc-nav-icon"></i>
                                <span class="mc-nav-label"><?= e($_n['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php foreach ($_baProfileNavs as $_k => $_n) : ?>
                    <?php $_a = ($activeNav === $_k) ? 'active' : ''; ?>
                    <li class="nav-item mc-nav-item mt-4 pt-3 border-top mc-admin-border">
                        <a class="nav-link mc-nav-link mc-admin-nav-link <?= $_a ?>"
                           href="<?= e(app_url($_n['url'])) ?>">
                            <i data-lucide="<?= e($_n['icon']) ?>" class="lucide lucide-18 mc-nav-icon"></i>
                            <span class="mc-nav-label"><?= e($_n['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>

                <li class="nav-item mc-nav-item">
                    <a class="nav-link mc-nav-link mc-nav-logout mc-admin-nav-link mc-admin-nav-logout"
                       href="<?= e($_baLogoutUrl) ?>">
                        <i data-lucide="log-out" class="lucide lucide-18 mc-nav-icon"></i>
                        <span class="mc-nav-label">Logout</span>
                    </a>
                </li>

            </ul>

            <div class="px-4 mt-4 mb-4 text-center">
                <img src="<?= e(app_url('/assets/img/AlagangMarikinaCity.png')) ?>"
                     alt="Alagang Marikina — City Program Seal"
                     width="56" height="56" loading="lazy" decoding="async"
                     class="mc-sidebar-seal-bottom mc-admin-alagang-inv">
            </div>
        </div>
    </div>

    <!-- ===========================================================
         ADMIN DESKTOP TOP NAV (≥768px)
         Navy linear-gradient; white text + inverted wordmark.
         =========================================================== -->
    <header class="mc-topnav mc-topnav-admin border-bottom mc-topnav-admin-navy">
        <div class="mc-topnav-inner">

            <div class="mc-topnav-brand d-flex align-items-center gap-2 me-4 flex-shrink-0">
                <button class="ba-btn ba-btn-ghost ba-btn-sm mc-sidebar-toggle mc-admin-btnghost-white d-md-none me-2" type="button"
                        data-bs-toggle="collapse" data-bs-target="#sidebarMenu"
                        aria-controls="sidebarMenu" aria-expanded="false" aria-label="Open admin navigation menu">
                    <i data-lucide="menu" class="lucide lucide-16"></i>
                </button>
                <a class="mc-topnav-brand-link d-flex align-items-center gap-2 text-decoration-none"
                   href="<?= e(app_url('/admin/dashboard.php')) ?>" aria-label="Go to Admin Dashboard">
                    <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
                         alt="Official Seal of Marikina City"
                         width="32" height="32" loading="eager" decoding="async"
                         class="mc-topnav-seal mc-admin-seal-pad">
                    <img src="<?= e(app_url('/assets/img/Marikina-e-Concern.png')) ?>"
                         alt="Marikina e-Concern — Administrator Portal"
                         width="150" height="26" loading="eager" decoding="async"
                         class="mc-topnav-wordmark mc-admin-wordmark-inv">
                </a>
            </div>

            <nav class="mc-topnav-tabs mc-topnav-tabs-admin d-none d-md-flex flex-wrap align-items-center gap-2 me-auto ms-2"
                 aria-label="Admin section tabs">

                <?php foreach ($_baCoreNavs as $_k => $_n) : ?>
                    <?php $_a = ($activeNav === $_k) ? 'active' : ''; ?>
                    <a class="mc-topnav-tab mc-topnav-tab-admin <?= $_a ?>"
                       href="<?= e(app_url($_n['url'])) ?>">
                        <i data-lucide="<?= e($_n['icon']) ?>" class="lucide lucide-16"></i>
                        <span><?= e($_n['label']) ?></span>
                    </a>
                <?php endforeach; ?>

                <div class="mc-topnav-dropdown dropdown">
                    <button class="mc-topnav-tab mc-topnav-tab-admin mc-topnav-tab-dropdown dropdown-toggle" type="button"
                            id="baAdminModDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i data-lucide="leaf" class="lucide lucide-14"></i>
                        <span>BasuraAlert</span>
                    </button>
                    <ul class="dropdown-menu mc-topnav-dropdown-menu mc-topnav-dropdown-menu-md" aria-labelledby="baAdminModDropdown">
                        <?php foreach ($_baModuleNavs as $_k => $_n) : ?>
                            <?php $_a = ($activeNav === $_k) ? 'active' : ''; ?>
                            <li>
                                <a class="dropdown-item mc-topnav-dropdown-item <?= $_a ?>"
                                   href="<?= e(app_url($_n['url'])) ?>">
                                    <i data-lucide="<?= e($_n['icon']) ?>" class="lucide lucide-14"></i>
                                    <span><?= e($_n['label']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <?php if ($isSuper && count($_baMgmtNavs) > 0) : ?>
                    <div class="mc-topnav-dropdown dropdown">
                        <button class="mc-topnav-tab mc-topnav-tab-admin mc-topnav-tab-dropdown dropdown-toggle" type="button"
                                id="baAdminMgmtDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i data-lucide="shield-check" class="lucide lucide-14"></i>
                            <span>Management</span>
                        </button>
                        <ul class="dropdown-menu mc-topnav-dropdown-menu mc-topnav-dropdown-menu-md" aria-labelledby="baAdminMgmtDropdown">
                            <?php foreach ($_baMgmtNavs as $_k => $_n) : ?>
                                <?php $_a = ($activeNav === $_k) ? 'active' : ''; ?>
                                <li>
                                    <a class="dropdown-item mc-topnav-dropdown-item <?= $_a ?>"
                                       href="<?= e(app_url($_n['url'])) ?>">
                                        <i data-lucide="<?= e($_n['icon']) ?>" class="lucide lucide-14"></i>
                                        <span><?= e($_n['label']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

            </nav>

            <div class="mc-topnav-actions mc-topnav-actions-admin d-flex align-items-center gap-2 flex-shrink-0">

                <a class="mc-user-greeting mc-user-greeting--avatar d-none d-md-inline-flex align-items-center gap-2 mc-admin-white"
                   href="<?= e(app_url('/admin/profile.php')) ?>"
                   aria-label="Go to my admin profile page">
                    <?php
                    /* The shared partial, not a second hand-rolled avatar: it owns the
                       img/fallback markup, and the same tile the admin sees on their
                       own profile. sm (40px) matches the 40x40 pill the topnav
                       collapses this greeting to between 768-1099px.

                       The tile is NOT $avatarEditable - a 35px camera badge has
                       nowhere to go on a 40px nav tile, and a <button> inside this
                       <a> would be invalid HTML. It does get a file input, though,
                       so clicking the photo opens the picker: without one it
                       rendered with cursor:pointer and silently did nothing, which
                       is worse than not looking clickable. avatar.js wires it up
                       with tileTriggers and cancels the navigation.

                       --navy is needed HERE only: this greeting sits on the navy
                       #1F4570 topbar, where the shared pale-tint fallback with
                       #3368A0 initials is unreadable. The mobile greeting below sits
                       on the page background and keeps the default. */
                    $avatarPublicId  = (string) ($admin['avatar_public_id'] ?? '');
                    $avatarVersion   = mc_avatar_version_from_url($admin['avatar_url'] ?? '');
                    $avatarName      = (string) ($admin['name'] ?? '');
                    $avatarSize      = 'sm';
                    $avatarRole      = 'admin';
                    $avatarEditable  = false;
                    $avatarInputId   = 'adminTopAvatarFile';
                    $avatarImgId     = '';
                    /* The id goes on the .mc-avatar element itself, not on the
                       wrapper: avatar.js reads --mc-avatar-size back off this box
                       to size a swapped-in <img>, and that property is declared on
                       .mc-avatar, not on .mc-topnav-avatar. */
                    $avatarActionsId = 'adminTopAvatarBox';
                    ?>
                    <span class="mc-topnav-avatar mc-topnav-avatar--navy"><?php require __DIR__ . '/avatar.php'; ?></span>
                    <span class="mc-user-name">Hi, <?= e((string) ($admin['name'] ?? '')) ?></span>
                </a>

                <a class="mc-topnav-logout mc-topnav-logout-admin d-none d-md-inline-flex"
                   href="<?= e($_baLogoutUrl) ?>"
                   aria-label="Log out of administrator portal">
                    <i data-lucide="log-out" class="lucide lucide-16"></i>
                    <span class="d-none d-lg-inline ms-1">Logout</span>
                </a>
            </div>

        </div>
    </header>

    <!-- ===========================================================
         ADMIN MAIN CONTENT AREA
         =========================================================== -->
    <main class="mc-shell-main mc-shell-main-admin">
        <div class="d-md-none d-flex align-items-center justify-content-between mb-4 mc-shell-topbar-mobile">
            <a class="mc-user-greeting mc-user-greeting--avatar d-inline-flex align-items-center gap-2"
               href="<?= e(app_url('/admin/profile.php')) ?>"
               aria-label="Go to my admin profile page">
                <?php
                /* Same shared partial as the desktop greeting above, without --navy:
                   this block is on the page background, not the navy topbar, so the
                   default pale-tint fallback is correct here. Ids are suffixed
                   "Mobile" because the desktop greeting is rendered on the same
                   page and the two must not collide. Locals are cleared afterwards
                   to keep them out of the including page's scope. */
                $avatarPublicId  = (string) ($admin['avatar_public_id'] ?? '');
                $avatarVersion   = mc_avatar_version_from_url($admin['avatar_url'] ?? '');
                $avatarName      = (string) ($admin['name'] ?? '');
                $avatarSize      = 'sm';
                $avatarRole      = 'admin';
                $avatarEditable  = false;
                $avatarInputId   = 'adminTopAvatarFileMobile';
                $avatarImgId     = '';
                $avatarActionsId = 'adminTopAvatarBoxMobile';
                ?>
                <span class="mc-topnav-avatar"><?php require __DIR__ . '/avatar.php'; ?></span>
                <span class="mc-user-name">Hi, <?= e((string) ($admin['name'] ?? '')) ?></span>
            </a>
            <?php unset($avatarPublicId, $avatarVersion, $avatarName, $avatarSize, $avatarRole, $avatarEditable, $avatarInputId, $avatarImgId, $avatarActionsId); ?>
        </div>
