<?php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('admissions');
// admissions/partials/adm_header.php
$adm_admin = getCurrentAdmin();
$adm_flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Admissions') ?> — KIMC Admissions</title>
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
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/mobile.css">
    <script src="<?= APP_ROOT ?>/assets/js/mobile.js"></script>
    <style>
    :root {
        --adm-dark:  #1e1b4b;
        --adm-mid:   #3730a3;
        --adm-light: #4f46e5;
        --adm-pale:  #ede9fe;
    }
    [data-theme="dark"] { --adm-pale: rgba(79,70,229,.12); }
    .sidebar { background: linear-gradient(180deg, #1e1b4b 0%, #312e81 100%); border-right: 1px solid rgba(79,70,229,.2); }
    .nav-section-label { color: rgba(255,255,255,.35); }
    .nav-item { color: rgba(255,255,255,.65); }
    .nav-item:hover { background: rgba(255,255,255,.07); color: #fff; }
    .nav-item.active { background: rgba(79,70,229,.35); color: #c7d2fe; border-left: 3px solid #818cf8; }
    .nav-icon { opacity: .8; }
    .sidebar-footer { border-top: 1px solid rgba(255,255,255,.08); }
    .topbar { background: #1e1b4b; border-bottom: 1px solid rgba(79,70,229,.3); }
    .topbar-brand .brand-name { color: #c7d2fe; }
    .topbar-brand .brand-sub  { color: rgba(255,255,255,.45); }
    .sidebar-logout { color: #fca5a5; }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle menu">☰</button>
        <a href="<?= APP_ROOT ?>/admissions/dashboard.php" class="topbar-brand">
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo" style="height:38px;width:auto;object-fit:contain">
            <div class="brand-text">
                <span class="brand-name">KIMC Eldoret</span>
                <span class="brand-sub" style="color:rgba(255,255,255,.45)">Admissions</span>
            </div>
        </a>
    </div>
    <div class="topbar-right">
        <button class="icon-btn theme-toggle-btn" onclick="toggleTheme()" title="Toggle theme">
            <span id="theme-icon">🌙</span>
        </button>
        <a href="<?= APP_ROOT ?>/portal.php" class="icon-btn" title="Back to Portal" style="font-size:18px;text-decoration:none">🏠</a>
        <div class="user-menu" onclick="toggleUserMenu()">
            <?php
            $parts = preg_split('/\s+/', trim($adm_admin['full_name']));
            $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
            ?>
            <div class="avatar" style="background:linear-gradient(135deg,#3730a3,#4f46e5)"><?= htmlspecialchars($initials) ?></div>
            <span class="dropdown-arrow">▾</span>
            <div class="dropdown-menu" id="user-dropdown">
                <a href="<?= APP_ROOT ?>/auth/logout.php" class="dropdown-item danger" style="display:block;text-align:center;padding:12px 20px;">🚪 Sign Out</a>
            </div>
        </div>
    </div>
</header>

<div class="layout">
<aside class="sidebar" id="sidebar">
    <nav class="sidebar-nav">
        <div class="nav-section-label">Admissions</div>
        <a href="<?= APP_ROOT ?>/admissions/dashboard.php"
           class="nav-item <?= ($activePage??'')==='adm_dashboard'?'active':'' ?>">
            <span class="nav-icon">📋</span> Applications
        </a>
        <a href="<?= APP_ROOT ?>/admissions/apply.php" target="_blank" class="nav-item">
            <span class="nav-icon">🔗</span> Public Form ↗
        </a>

        <div class="nav-section-label">System</div>
        <a href="<?= APP_ROOT ?>/portal.php" class="nav-item">
            <span class="nav-icon">🏠</span> Portal Home
        </a>
        <a href="<?= APP_ROOT ?>/fees/dashboard.php" class="nav-item">
            <span class="nav-icon">💰</span> Fees
        </a>
        <a href="<?= APP_ROOT ?>/exams/dashboard.php" class="nav-item">
            <span class="nav-icon">🎓</span> Exams
        </a>
        <a href="<?= APP_ROOT ?>/admin/dashboard.php" class="nav-item">
            <span class="nav-icon">📦</span> Inventory
        </a>
    </nav>
    <div class="sidebar-footer">
        <div style="font-size:11px;color:rgba(255,255,255,.35)">Signed in as</div>
        <div style="font-weight:600;font-size:13px;color:#c7d2fe"><?= htmlspecialchars($adm_admin['username']) ?></div>
        <a href="<?= APP_ROOT ?>/auth/logout.php" class="sidebar-logout">Sign Out</a>
    </div>
</aside>

<main class="main-content">
    <?php if ($adm_flash): ?>
        <div class="alert alert-<?= $adm_flash['type'] ?>">
            <?= htmlspecialchars($adm_flash['message']) ?>
            <button onclick="this.parentElement.remove()" class="alert-close">×</button>
        </div>
    <?php endif; ?>
