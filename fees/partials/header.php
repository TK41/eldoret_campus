<?php
// fees/partials/header.php — shared nav for all fees pages
$fees_admin = getCurrentAdmin();
$fees_flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Fees') ?> — KIMC Fees</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">
    <style>
    /* ── Fees accent overrides ── */
    :root {
        --fees-primary:   #92400e;
        --fees-mid:       #b45309;
        --fees-light:     #d97706;
        --fees-pale:      #fef3c7;
        --fees-border:    rgba(180,83,9,.18);
    }
    [data-theme="dark"] {
        --fees-pale:  rgba(180,83,9,.12);
        --fees-border: rgba(180,83,9,.25);
    }
    .sidebar { background: linear-gradient(180deg, #1c0a00 0%, #2d1200 100%); border-right: 1px solid rgba(180,83,9,.2); }
    .nav-section-label { color: rgba(255,255,255,.35); }
    .nav-item { color: rgba(255,255,255,.65); }
    .nav-item:hover { background: rgba(255,255,255,.07); color: #fff; }
    .nav-item.active { background: rgba(180,83,9,.35); color: #fcd34d; border-left: 3px solid #d97706; }
    .nav-icon { opacity: .8; }
    .sidebar-footer { border-top: 1px solid rgba(255,255,255,.08); }
    .topbar { background: #1c0a00; border-bottom: 1px solid rgba(180,83,9,.3); }
    .topbar-brand .brand-name { color: #fcd34d; }
    .topbar-brand .brand-sub  { color: rgba(255,255,255,.45); }
    .sidebar-logout { color: #fca5a5; }
    .fees-badge { display:inline-block; background: rgba(180,83,9,.2); color:#d97706; font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; margin-left:6px; letter-spacing:.5px; border:1px solid rgba(180,83,9,.3); }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle menu">☰</button>
        <a href="<?= APP_ROOT ?>/fees/dashboard.php" class="topbar-brand">
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo" style="height:38px;width:auto;object-fit:contain">
            <div class="brand-text">
                <span class="brand-name" style="color:#fcd34d">KIMC Eldoret</span>
                <span class="brand-sub" style="color:rgba(255,255,255,.45)">Fees Management</span>
            </div>
        </a>
    </div>
    <div class="topbar-right">
        <button class="icon-btn theme-toggle-btn" onclick="toggleTheme()" title="Toggle theme">
            <span id="theme-icon">🌙</span>
        </button>
        <a href="<?= APP_ROOT ?>/portal.php" class="icon-btn" title="Back to Portal" style="font-size:18px;text-decoration:none">🏠</a>
        <div class="user-menu" onclick="toggleUserMenu()">
            <div class="avatar" style="background:linear-gradient(135deg,#92400e,#d97706)"><?= strtoupper(substr($fees_admin['full_name'], 0, 1)) ?></div>
            <div class="user-info">
                <span class="user-name" style="color:#fcd34d"><strong><?= htmlspecialchars($fees_admin['full_name']) ?></strong></span>
                <span class="user-role" style="color:rgba(255,255,255,.5)"><?= ucfirst($fees_admin['role']) ?></span>
            </div>
            <span class="dropdown-arrow" style="color:rgba(255,255,255,.4)">▾</span>
            <div class="dropdown-menu" id="user-dropdown">
                <a href="<?= APP_ROOT ?>/portal.php" class="dropdown-item">🏠 Portal Home</a>
                <a href="<?= APP_ROOT ?>/admin/dashboard.php" class="dropdown-item">📦 Inventory</a>
                <div class="dropdown-divider"></div>
                <a href="<?= APP_ROOT ?>/auth/logout.php" class="dropdown-item danger">🚪 Sign Out</a>
            </div>
        </div>
    </div>
</header>

<div class="layout">
<aside class="sidebar" id="sidebar">
    <nav class="sidebar-nav">
        <div class="nav-section-label">Fees</div>
        <a href="<?= APP_ROOT ?>/fees/dashboard.php"
           class="nav-item <?= ($activePage??'')==='fees_dashboard'?'active':'' ?>">
            <span class="nav-icon">⬛</span> Dashboard
        </a>
        <a href="<?= APP_ROOT ?>/fees/students.php"
           class="nav-item <?= ($activePage??'')==='fees_students'?'active':'' ?>">
            <span class="nav-icon">👥</span> Students
        </a>
        <a href="<?= APP_ROOT ?>/fees/add_payment.php"
           class="nav-item <?= ($activePage??'')==='fees_add_payment'?'active':'' ?>">
            <span class="nav-icon">➕</span> Post Payment
        </a>

        <div class="nav-section-label">Management</div>
        <a href="<?= APP_ROOT ?>/fees/groups.php"
           class="nav-item <?= ($activePage??'')==='fees_groups'?'active':'' ?>">
            <span class="nav-icon">🗂</span> Groups
        </a>
        <a href="<?= APP_ROOT ?>/fees/import.php"
           class="nav-item <?= ($activePage??'')==='fees_import'?'active':'' ?>">
            <span class="nav-icon">📥</span> Import Excel
        </a>

        <div class="nav-section-label">System</div>
        <a href="<?= APP_ROOT ?>/portal.php" class="nav-item">
            <span class="nav-icon">🏠</span> Portal Home
        </a>
        <a href="<?= APP_ROOT ?>/admin/dashboard.php" class="nav-item">
            <span class="nav-icon">📦</span> Inventory
        </a>
    </nav>
    <div class="sidebar-footer">
        <div style="font-size:11px;color:rgba(255,255,255,.35)">Signed in as</div>
        <div style="font-weight:600;font-size:13px;color:#fcd34d"><?= htmlspecialchars($fees_admin['username']) ?></div>
        <a href="<?= APP_ROOT ?>/auth/logout.php" class="sidebar-logout">Sign Out</a>
    </div>
</aside>

<main class="main-content">
    <?php if ($fees_flash): ?>
        <div class="alert alert-<?= $fees_flash['type'] ?>">
            <?= htmlspecialchars($fees_flash['message']) ?>
            <button onclick="this.parentElement.remove()" class="alert-close">×</button>
        </div>
    <?php endif; ?>
