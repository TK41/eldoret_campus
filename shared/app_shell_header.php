<?php
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
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/app-shell.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/mobile.css">
    <?= $shellExtraHead ?? '' ?>
</head>
<body data-module="<?= htmlspecialchars($shellModule) ?>">
<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" type="button" onclick="toggleSidebar()" title="Toggle menu" aria-label="Toggle navigation">☰</button>
        <a href="<?= htmlspecialchars($shellDashboard) ?>" class="topbar-brand">
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo">
            <div class="brand-text">
                <span class="brand-name">KIMC Eldoret</span>
                <span class="brand-sub"><?= htmlspecialchars($shellModuleLabel) ?></span>
            </div>
        </a>
    </div>
    <div class="topbar-right">
        <?php if (count($shellAvailableModules) > 1): ?>
            <details class="module-switcher">
                <summary class="module-switcher-trigger" aria-label="Open module switcher">Modules <span aria-hidden="true">▾</span></summary>
                <div class="module-switcher-menu">
                    <a class="dropdown-item" href="<?= APP_ROOT ?>/portal.php">Portal Home</a>
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
            <span id="theme-icon">🌙</span>
        </button>
        <?php if (canAccess('inventory')): ?>
            <?php $shellNotificationCount = (int) getDB()->query("SELECT COUNT(*) FROM notifications WHERE status = 'pending'")->fetchColumn(); ?>
            <a href="<?= APP_ROOT ?>/admin/notifications.php" class="icon-btn notif-bell" title="Notifications" aria-label="Notifications">
                🔔
                <?php if ($shellNotificationCount > 0): ?>
                    <span class="badge-dot"><?= $shellNotificationCount ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
        <div class="user-menu">
            <button type="button" class="user-menu-trigger" onclick="toggleUserMenu()" aria-haspopup="true" aria-expanded="false">
                <span class="avatar"><?= htmlspecialchars($shellInitials) ?></span>
                <span class="dropdown-arrow" aria-hidden="true">▾</span>
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

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-nav">
            <?php foreach ($shellNavItems as $item): ?>
                <?php if ($item['type'] === 'section'): ?>
                    <div class="nav-section-label"><?= htmlspecialchars($item['label']) ?></div>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($item['url']) ?>"
                       class="nav-item <?= ($activePage ?? '') === $item['key'] ? 'active' : '' ?>"
                       <?= ($activePage ?? '') === $item['key'] ? 'aria-current="page"' : '' ?>
                       <?= !empty($item['external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><?= $item['icon'] ?></span>
                        <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </aside>
    <main class="main-content">
        <?php if (!empty($shellFlash)): ?>
            <div class="alert alert-<?= htmlspecialchars($shellFlash['type']) ?>" role="status">
                <?= htmlspecialchars($shellFlash['message']) ?>
                <button type="button" onclick="this.parentElement.remove()" class="alert-close" aria-label="Dismiss message">×</button>
            </div>
        <?php endif; ?>
