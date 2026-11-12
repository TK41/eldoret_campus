<?php
require_once __DIR__ . '/formatters.php';
require_once __DIR__ . '/icons.php';
$shellModuleLinks = [
    'inventory' => ['Inventory', APP_ROOT . '/admin/dashboard.php'],
    'fees' => ['Fees', APP_ROOT . '/fees/dashboard.php'],
    'admissions' => ['Admissions', APP_ROOT . '/admissions/dashboard.php'],
    'exams' => ['Exams', APP_ROOT . '/exams/dashboard.php'],
];
$shellAvailableModules = [];
foreach ($shellModuleLinks as $moduleKey => $moduleLink) {
    if (canAccess($moduleKey)) {
        $shellAvailableModules[$moduleKey] = $moduleLink;
    }
}
$shellInitialsParts = preg_split('/\s+/', trim((string) ($shellAdmin['full_name'] ?? '')));
$shellInitials = strtoupper(substr($shellInitialsParts[0] ?? '', 0, 1)
    . (isset($shellInitialsParts[1]) ? substr($shellInitialsParts[1], 0, 1) : ''));
$shellShowNotifications = $shellModule === 'inventory' && canAccess('inventory');
$shellUnreadNotificationCount = 0;
$shellRecentNotifications = [];
if ($shellShowNotifications) {
    $notificationDb = getDB();
    $unreadStmt = $notificationDb->prepare("
        SELECT COUNT(*)
        FROM notifications n
        LEFT JOIN notification_reads nr
            ON nr.notif_id = n.notif_id AND nr.admin_id = ?
        WHERE nr.notif_id IS NULL
    ");
    $unreadStmt->execute([$_SESSION['admin_id']]);
    $shellUnreadNotificationCount = (int) $unreadStmt->fetchColumn();

    $recentStmt = $notificationDb->prepare("
        SELECT n.notif_id, n.type, n.subject, n.status, n.created_at,
               a.name AS asset_name,
               nr.notif_id AS read_notif_id
        FROM notifications n
        LEFT JOIN transactions t ON t.txn_id = n.txn_id
        LEFT JOIN assets a ON a.asset_id = t.asset_id
        LEFT JOIN notification_reads nr
            ON nr.notif_id = n.notif_id AND nr.admin_id = ?
        ORDER BY n.created_at DESC, n.notif_id DESC
        LIMIT 5
    ");
    $recentStmt->execute([$_SESSION['admin_id']]);
    $shellRecentNotifications = $recentStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? $shellModuleLabel) ?> — KIMC Eldoret</title>
    <script>
        (function() {
            try {
                const theme = localStorage.getItem('kimc_theme') === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.style.colorScheme = theme;
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/theme.css') ?>">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/app-shell.css?v=<?= filemtime(__DIR__ . '/../assets/css/app-shell.css') ?>">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/mobile.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile.css') ?>">
    <?= $shellExtraHead ?? '' ?>
</head>
<body data-module="<?= htmlspecialchars($shellModule) ?>">
<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" type="button" onclick="toggleSidebar()" title="Toggle menu" aria-label="Toggle navigation"><?= appIcon('menu') ?></button>
        <a href="<?= htmlspecialchars($shellDashboard) ?>" class="topbar-brand">
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo">
            <div class="brand-text">
                <span class="brand-name">KIMC Eldoret</span>
                <span class="brand-sub"><?= htmlspecialchars($shellModuleLabel) ?></span>
            </div>
        </a>
    </div>
    <div class="topbar-right">
        <button class="icon-btn global-search-trigger" type="button" id="global-search-trigger"
                aria-label="Search (Ctrl or Command plus K)" title="Search (Ctrl or Command plus K)">
            <?= appIcon('search') ?>
            <span class="global-search-label">Search</span>
            <kbd>Ctrl K</kbd>
        </button>
        <?php if (count($shellAvailableModules) > 1): ?>
            <details class="module-switcher">
                <summary class="module-switcher-trigger" aria-label="Open module switcher">Modules <span aria-hidden="true"><?= appIcon('chevron-down') ?></span></summary>
                <div class="module-switcher-menu">
                    <a class="dropdown-item" href="<?= APP_ROOT ?>/portal.php"><?= appIcon('home') ?> Portal Home</a>
                    <div class="dropdown-divider"></div>
                    <?php foreach ($shellAvailableModules as $moduleKey => $moduleLink): ?>
                        <a class="dropdown-item <?= $shellModule === $moduleKey ? 'current-module' : '' ?>"
                           href="<?= htmlspecialchars($moduleLink[1]) ?>"
                           <?= $shellModule === $moduleKey ? 'aria-current="page"' : '' ?>>
                            <?= htmlspecialchars($moduleLink[0]) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
        <button class="icon-btn theme-toggle-btn" type="button" onclick="toggleTheme()" title="Toggle theme" aria-label="Toggle theme">
            <span id="theme-icon">
                <span class="theme-icon-for-light"><?= appIcon('moon') ?></span>
                <span class="theme-icon-for-dark"><?= appIcon('sun') ?></span>
            </span>
        </button>
        <?php if ($shellShowNotifications): ?>
            <details class="notification-menu" id="notification-menu">
                <summary class="icon-btn notif-bell" title="Notifications" aria-label="Notifications<?= $shellUnreadNotificationCount ? ' (' . $shellUnreadNotificationCount . ' unread)' : '' ?>">
                    <?= appIcon('bell') ?>
                    <?php if ($shellUnreadNotificationCount > 0): ?>
                        <span class="badge-dot"><?= $shellUnreadNotificationCount > 99 ? '99+' : $shellUnreadNotificationCount ?></span>
                    <?php endif; ?>
                </summary>
                <div class="notification-dropdown">
                    <div class="notification-dropdown-header">
                        <div>
                            <strong>Notifications</strong>
                            <span><?= count($shellRecentNotifications) ?> most recent</span>
                        </div>
                        <button type="button" class="notification-mark-read" id="mark-all-notifications-read"
                                data-endpoint="<?= APP_ROOT ?>/api/notification_read.php"
                                data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                                <?= $shellUnreadNotificationCount === 0 ? 'disabled' : '' ?>>
                            <?= appIcon('check') ?> Mark all as read
                        </button>
                    </div>
                    <div class="notification-list" id="notification-list">
                        <?php if (empty($shellRecentNotifications)): ?>
                            <p class="notification-empty">No notifications yet.</p>
                        <?php else: ?>
                            <?php foreach ($shellRecentNotifications as $notification): ?>
                                <a class="notification-item <?= $notification['read_notif_id'] === null ? 'unread' : '' ?>"
                                   href="<?= APP_ROOT ?>/admin/notifications.php">
                                    <span class="notification-item-dot" aria-hidden="true"></span>
                                    <span class="notification-item-copy">
                                        <strong><?= htmlspecialchars($notification['subject'] ?: ucfirst(str_replace('_', ' ', $notification['type']))) ?></strong>
                                        <span><?= htmlspecialchars($notification['asset_name'] ?: ucfirst(str_replace('_', ' ', $notification['type']))) ?></span>
                                        <small><?= htmlspecialchars(formatDate($notification['created_at'], true)) ?></small>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="notification-action-status" id="notification-action-status" aria-live="polite"></p>
                    <a href="<?= APP_ROOT ?>/admin/notifications.php" class="notification-view-all">
                        View all <?= appIcon('arrow-right') ?>
                    </a>
                </div>
            </details>
        <?php endif; ?>
        <div class="user-menu">
            <button type="button" class="user-menu-trigger" onclick="toggleUserMenu()" aria-haspopup="true" aria-expanded="false">
                <span class="avatar"><?= htmlspecialchars($shellInitials) ?></span>
                <span class="dropdown-arrow" aria-hidden="true"><?= appIcon('chevron-down') ?></span>
            </button>
            <div class="dropdown-menu" id="user-dropdown">
                <div class="user-menu-identity">
                    <span class="identity-label">Signed in as</span>
                    <strong><?= htmlspecialchars($shellAdmin['username'] ?? '') ?></strong>
                </div>
                <div class="dropdown-divider"></div>
                <a href="<?= APP_ROOT ?>/auth/logout.php" class="dropdown-item danger">Sign Out</a>
            </div>
        </div>
    </div>
</header>

<div class="global-search-overlay" id="global-search-overlay" hidden>
    <section class="global-search-dialog" role="dialog" aria-modal="true" aria-labelledby="global-search-title"
             data-endpoint="<?= APP_ROOT ?>/api/global_search.php">
        <div class="global-search-form">
            <?= appIcon('search') ?>
            <label class="sr-only" for="global-search-input" id="global-search-title">Search records</label>
            <input id="global-search-input" type="search" autocomplete="off"
                   placeholder="Search...">
            <button type="button" class="global-search-close" id="global-search-close" aria-label="Close search">
                <?= appIcon('x') ?>
            </button>
        </div>
        <div class="global-search-results" id="global-search-results" aria-live="polite"></div>
    </section>
</div>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-nav">
            <?php foreach ($shellNavItems as $item): ?>
                <?php if ($item['type'] === 'section'): ?>
                    <div class="nav-section-label"><?= htmlspecialchars($item['label']) ?></div>
                <?php else: ?>
                    <?php
                    $isActive = ($activePage ?? '') === $item['key']
                        || ($shellModule === 'inventory' && $item['key'] === 'assets' && ($activePage ?? '') === 'add_asset');
                    $navIcon = !empty($item['external']) ? 'external-link' : $item['icon'];
                    ?>
                    <a href="<?= htmlspecialchars($item['url']) ?>"
                       class="nav-item <?= $isActive ? 'active' : '' ?>"
                       title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
                       data-tooltip="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
                       aria-label="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
                       <?= $isActive ? 'aria-current="page"' : '' ?>
                       <?= !empty($item['external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><?= appIcon($navIcon) ?></span>
                        <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </aside>
    <main class="main-content">
        <div id="page-breadcrumb-source" hidden
             data-module-label="<?= htmlspecialchars($shellModuleLabel, ENT_QUOTES, 'UTF-8') ?>"
             data-home-url="<?= htmlspecialchars($shellDashboard, ENT_QUOTES, 'UTF-8') ?>"
             data-page-title="<?= htmlspecialchars($pageTitle ?? $shellModuleLabel, ENT_QUOTES, 'UTF-8') ?>"></div>
        <?php if (!empty($shellFlash)): ?>
            <div class="alert alert-<?= htmlspecialchars($shellFlash['type']) ?>" role="status">
                <?= htmlspecialchars($shellFlash['message']) ?>
                <button type="button" onclick="this.parentElement.remove()" class="alert-close" aria-label="Dismiss message"><?= appIcon('x') ?></button>
            </div>
        <?php endif; ?>
