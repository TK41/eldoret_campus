<?php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('exams');
// exams/partials/header.php — shared nav for all exam pages
$exam_admin = getCurrentAdmin();
$exam_flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Exams') ?> — KIMC Exams</title>
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
    <style>
    /* ── Exam module accent (teal/emerald) ── */
    :root {
        --exam-primary:  #065f46;
        --exam-mid:      #047857;
        --exam-light:    #059669;
        --exam-pale:     #d1fae5;
        --exam-border:   rgba(5,150,105,.18);
    }
    [data-theme="dark"] {
        --exam-pale:   rgba(5,150,105,.12);
        --exam-border: rgba(5,150,105,.25);
    }
    .sidebar { background: linear-gradient(180deg, #022c22 0%, #064e3b 100%); border-right: 1px solid rgba(5,150,105,.2); }
    .nav-section-label { color: rgba(255,255,255,.35); }
    .nav-item { color: rgba(255,255,255,.65); }
    .nav-item:hover { background: rgba(255,255,255,.07); color: #fff; }
    .nav-item.active { background: rgba(5,150,105,.35); color: #6ee7b7; border-left: 3px solid #059669; }
    .nav-icon { opacity: .8; }
    .sidebar-footer { border-top: 1px solid rgba(255,255,255,.08); }
    .topbar { background: #022c22; border-bottom: 1px solid rgba(5,150,105,.3); }
    .topbar-brand .brand-name { color: #6ee7b7; }
    .topbar-brand .brand-sub  { color: rgba(255,255,255,.45); }
    .sidebar-logout { color: #fca5a5; }

    /* Grade pill colours */
    .grade-A { background:rgba(22,163,74,.12);  color:#15803d;  font-weight:700; }
    .grade-B { background:rgba(37,99,235,.12);  color:#1d4ed8;  font-weight:700; }
    .grade-C { background:rgba(217,119,6,.12);  color:#b45309;  font-weight:700; }
    .grade-D { background:rgba(234,88,12,.12);  color:#c2410c;  font-weight:700; }
    .grade-F { background:rgba(220,38,38,.1);   color:#dc2626;  font-weight:700; }

    .grade-pill {
        display:inline-block; padding:2px 10px; border-radius:10px;
        font-family:'Space Mono',monospace; font-size:12px;
    }

    /* Inline score input styling */
    .score-input {
        width: 72px;
        padding: 5px 8px;
        border: 1px solid var(--border);
        border-radius: 6px;
        background: var(--input-bg, var(--surface));
        color: var(--text-primary);
        font-family: 'Space Mono', monospace;
        font-size: 13px;
        text-align: center;
        transition: border-color .15s, box-shadow .15s;
    }
    .score-input:focus {
        outline: none;
        border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5,150,105,.15);
    }
    .score-input.saving { border-color: #d97706; }
    .score-input.saved  { border-color: #16a34a; }
    .score-input.error  { border-color: #dc2626; }

    /* Live total badge */
    .live-total {
        font-family:'Space Mono',monospace;
        font-size:13px; font-weight:700;
        padding:4px 10px; border-radius:8px;
        min-width:52px; text-align:center;
        transition: background .3s, color .3s;
    }

    /* Stat cards exam theme */
    .stat-card.exam-green::before  { background: #059669; }
    .stat-card.exam-blue::before   { background: #2563eb; }
    .stat-card.exam-amber::before  { background: #d97706; }
    .stat-card.exam-red::before    { background: #dc2626; }

    /* Lock badge */
    .locked-badge {
        display:inline-flex; align-items:center; gap:4px;
        background:rgba(220,38,38,.1); color:#dc2626;
        border:1px solid rgba(220,38,38,.25);
        padding:3px 10px; border-radius:10px;
        font-size:11px; font-weight:700;
    }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle menu">☰</button>
        <a href="<?= APP_ROOT ?>/exams/dashboard.php" class="topbar-brand">
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo" style="height:38px;width:auto;object-fit:contain">
            <div class="brand-text">
                <span class="brand-name" style="color:#6ee7b7">KIMC Eldoret</span>
                <span class="brand-sub" style="color:rgba(255,255,255,.45)">Exam Results</span>
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
            $parts = preg_split('/\s+/', trim($exam_admin['full_name']));
            $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
            ?>
            <div class="avatar" style="background:linear-gradient(135deg,#065f46,#059669)"><?= htmlspecialchars($initials) ?></div>
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
        <div class="nav-section-label">Exam Results</div>
        <a href="<?= APP_ROOT ?>/exams/dashboard.php"
           class="nav-item <?= ($activePage??'')==='exam_dashboard'?'active':'' ?>">
            <span class="nav-icon">📊</span> Dashboard
        </a>
        <a href="<?= APP_ROOT ?>/exams/sessions.php"
           class="nav-item <?= ($activePage??'')==='exam_sessions'?'active':'' ?>">
            <span class="nav-icon">🗓</span> Exam Sessions
        </a>
        <a href="<?= APP_ROOT ?>/exams/enter_marks.php"
           class="nav-item <?= ($activePage??'')==='exam_enter'?'active':'' ?>">
            <span class="nav-icon">✏️</span> Enter Marks
        </a>
        <a href="<?= APP_ROOT ?>/exams/results.php"
           class="nav-item <?= ($activePage??'')==='exam_results'?'active':'' ?>">
            <span class="nav-icon">📋</span> View Results
        </a>
        <a href="<?= APP_ROOT ?>/exams/transcripts.php"
           class="nav-item <?= ($activePage??'')==='exam_transcripts'?'active':'' ?>">
            <span class="nav-icon">🎓</span> Transcripts
        </a>
        <a href="<?= APP_ROOT ?>/exams/analytics.php"
           class="nav-item <?= ($activePage??'')==='exam_analytics'?'active':'' ?>">
            <span class="nav-icon">📈</span> Analytics
        </a>

        <div class="nav-section-label">Setup</div>
        <a href="<?= APP_ROOT ?>/exams/units.php"
           class="nav-item <?= ($activePage??'')==='exam_units'?'active':'' ?>">
            <span class="nav-icon">📚</span> Units/Subjects
        </a>

        <!-- System links intentionally omitted (use topbar home button to navigate) -->
    </nav>
    <!-- Sidebar footer removed (info in top-right) -->
</aside>

<main class="main-content">
    <?php if ($exam_flash): ?>
        <div class="alert alert-<?= $exam_flash['type'] ?>">
            <?= htmlspecialchars($exam_flash['message']) ?>
            <button onclick="this.parentElement.remove()" class="alert-close">×</button>
        </div>
    <?php endif; ?>
