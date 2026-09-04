<?php
// ============================================================
// admin/partials/header.php
// Shared top navigation bar + sidebar for all admin pages
// Include at the top of every admin page AFTER requireLogin()
//
// Usage:
//   $pageTitle = 'Dashboard';   // set before including
//   $activePage = 'dashboard';  // matches nav item keys
//   include __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('inventory');
getDB()->prepare("UPDATE admin_users SET last_seen=NOW() WHERE admin_id=?")->execute([$_SESSION['admin_id']]);
// ============================================================

$admin = getCurrentAdmin();
$flash = getFlash();  // one-time flash message
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — KIMC Inventory</title>

    <script>
        (function() {
            try {
                const savedTheme = localStorage.getItem('kimc_theme');
                const theme = savedTheme === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.style.colorScheme = theme;
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">
</head>
<body>

<!-- ============================================================
     TOP NAVIGATION BAR
============================================================ -->
<header class="topbar">
    <div class="topbar-left">
        <!-- Hamburger for mobile -->
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle menu">☰</button>

        <!-- Logo + System Name -->
        <a href="<?= APP_ROOT ?>/admin/dashboard.php" class="topbar-brand">
            <!-- Logo image -->
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo" style="height:40px;width:auto;object-fit:contain">
            <div class="brand-text">
                <span class="brand-name">KIMC Eldoret</span>
                <span class="brand-sub">Inventory System</span>
            </div>
        </a>
    </div>

    <div class="topbar-right">
        <!-- Theme toggle button -->
        <button class="icon-btn theme-toggle-btn" onclick="toggleTheme()" title="Toggle theme">
            <span id="theme-icon">🌙</span>
        </button>

        <!-- Notifications bell -->
        <a href="<?= APP_ROOT ?>/admin/notifications.php" class="icon-btn notif-bell" title="Notifications">
            🔔
            <?php
            // Show unread count badge
            try {
                $db = getDB();
                $count = $db->query("SELECT COUNT(*) FROM notifications WHERE status = 'pending'")->fetchColumn();
                if ($count > 0) echo '<span class="badge-dot">' . $count . '</span>';
            } catch (Exception $e) { /* suppress if table not ready */ }
            ?>
        </a>

        <!-- Admin user menu -->
        <div class="user-menu" onclick="toggleUserMenu()">
            <?php
            $parts = preg_split('/\s+/', trim($admin['full_name']));
            $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
            ?>
            <div class="avatar"><?= htmlspecialchars($initials) ?></div>
            <span class="dropdown-arrow">▾</span>

            <div class="dropdown-menu" id="user-dropdown">
                <a href="<?= APP_ROOT ?>/auth/logout.php" class="dropdown-item danger" style="display:block;text-align:center;padding:12px 20px;">🚪 Sign Out</a>
            </div>
        </div>
    </div>
</header>

<!-- ============================================================
     SIDEBAR NAVIGATION
============================================================ -->
<div class="layout">
<aside class="sidebar" id="sidebar">

    <!-- Navigation links -->
    <nav class="sidebar-nav">

        <div class="nav-section-label">Main</div>

        <a href="<?= APP_ROOT ?>/admin/dashboard.php"
           class="nav-item <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-icon">⬛</span> Dashboard
        </a>

        <a href="<?= APP_ROOT ?>/admin/assets.php"
           class="nav-item <?= ($activePage ?? '') === 'assets' ? 'active' : '' ?>">
            <span class="nav-icon">📦</span> Assets
        </a>

        <a href="<?= APP_ROOT ?>/admin/add_asset.php"
           class="nav-item <?= ($activePage ?? '') === 'add_asset' ? 'active' : '' ?>">
            <span class="nav-icon">➕</span> Add Item
        </a>

        <a href="<?= APP_ROOT ?>/admin/kits.php"
           class="nav-item <?= ($activePage ?? '') === 'kits' ? 'active' : '' ?>">
            <span class="nav-icon">🎒</span> Kits
        </a>

        <a href="<?= APP_ROOT ?>/admin/transactions.php"
           class="nav-item <?= ($activePage ?? '') === 'transactions' ? 'active' : '' ?>">
            <span class="nav-icon">↕️</span> Transactions
        </a>

        <div class="nav-section-label">Borrowers</div>

        <a href="<?= APP_ROOT ?>/admin/users.php"
           class="nav-item <?= ($activePage ?? '') === 'users' ? 'active' : '' ?>">
            <span class="nav-icon">👥</span> Users
        </a>

        <a href="<?= APP_ROOT ?>/admin/permissions.php"
           class="nav-item <?= ($activePage ?? '') === 'permissions' ? 'active' : '' ?>">
            <span class="nav-icon">🔐</span> Permissions
        </a>

        <div class="nav-section-label">System</div>

        <a href="<?= APP_ROOT ?>/admin/notifications.php"
           class="nav-item <?= ($activePage ?? '') === 'notifications' ? 'active' : '' ?>">
            <span class="nav-icon">🔔</span> Notifications
        </a>

        <a href="<?= APP_ROOT ?>/admin/settings.php"
           class="nav-item <?= ($activePage ?? '') === 'settings' ? 'active' : '' ?>">
            <span class="nav-icon">⚙️</span> Settings
        </a>

    </nav>

    <!-- Sidebar footer removed (info available in top-right user menu) -->
</aside>

<!-- Main content wrapper — closed in footer.php -->
<main class="main-content">

    <!-- Flash message banner (success/error from redirects) -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>">
            <?= $flash['message'] ?>
            <button onclick="this.parentElement.remove()" class="alert-close">×</button>
        </div>
    <?php endif; ?>
