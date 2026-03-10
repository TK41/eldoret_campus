<?php
// ============================================================
// portal.php
// Post-login landing page — choose Inventory or Fees portal
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/auth/session.php';
requireLogin();

$admin = getCurrentAdmin();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIMC Eldoret — Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=Space+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">

    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        --navy:       #0d1f3c;
        --navy-mid:   #1a3a6b;
        --navy-light: #2a5298;
        --gold:       #c9a84c;
        --gold-light: #e4c46a;
        --gold-pale:  #f7efd8;
        --cream:      #faf8f4;
        --ink:        #1a1a2e;
        --muted:      #6b7280;
        --border:     rgba(201,168,76,.2);
        --card-bg:    #ffffff;
        --shadow-sm:  0 2px 8px rgba(13,31,60,.08);
        --shadow-md:  0 8px 32px rgba(13,31,60,.14);
        --shadow-lg:  0 20px 60px rgba(13,31,60,.22);
    }

    [data-theme="dark"] {
        --cream:    #0f1624;
        --card-bg:  #162035;
        --ink:      #e8eaf2;
        --muted:    #8892a4;
        --border:   rgba(201,168,76,.15);
        --shadow-sm: 0 2px 8px rgba(0,0,0,.25);
        --shadow-md: 0 8px 32px rgba(0,0,0,.4);
        --shadow-lg: 0 20px 60px rgba(0,0,0,.55);
    }

    body {
        font-family: 'DM Sans', sans-serif;
        background: var(--cream);
        color: var(--ink);
        min-height: 100vh;
        overflow-x: hidden;
    }

    /* ── Grain overlay ── */
    body::before {
        content: '';
        position: fixed;
        inset: 0;
        background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
        pointer-events: none;
        z-index: 0;
    }

    /* ── Top bar ── */
    .topbar {
        position: fixed;
        top: 0; left: 0; right: 0;
        height: 64px;
        background: rgba(255,255,255,.85);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 32px;
        z-index: 100;
    }
    [data-theme="dark"] .topbar {
        background: rgba(15,22,36,.85);
    }

    .topbar-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
    }
    .topbar-brand img {
        height: 36px;
        width: auto;
        object-fit: contain;
    }
    .brand-text-sm {
        display: flex;
        flex-direction: column;
    }
    .brand-name-sm {
        font-family: 'Cormorant Garamond', serif;
        font-size: 15px;
        font-weight: 700;
        color: var(--navy);
        line-height: 1.2;
        letter-spacing: .3px;
    }
    [data-theme="dark"] .brand-name-sm { color: #c9d4ec; }
    .brand-sub-sm {
        font-size: 10px;
        color: var(--muted);
        letter-spacing: .5px;
        text-transform: uppercase;
    }

    .topbar-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .topbar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        color: var(--muted);
    }
    .topbar-avatar {
        width: 32px; height: 32px;
        background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
        color: #fff;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 13px;
        flex-shrink: 0;
    }
    .topbar-name {
        font-weight: 500;
        color: var(--ink);
        font-size: 13px;
    }
    .btn-signout {
        font-size: 12px;
        font-weight: 500;
        color: var(--muted);
        text-decoration: none;
        padding: 5px 12px;
        border: 1px solid var(--border);
        border-radius: 20px;
        transition: all .2s;
        font-family: 'DM Sans', sans-serif;
    }
    .btn-signout:hover {
        color: #dc2626;
        border-color: rgba(220,38,38,.3);
        background: rgba(220,38,38,.04);
    }
    .btn-theme {
        background: none;
        border: 1px solid var(--border);
        border-radius: 8px;
        width: 34px; height: 34px;
        cursor: pointer;
        font-size: 16px;
        display: flex; align-items: center; justify-content: center;
        transition: background .2s;
        color: var(--ink);
    }
    .btn-theme:hover { background: rgba(201,168,76,.08); }

    /* ── Main layout ── */
    .main {
        min-height: 100vh;
        padding-top: 64px;
        display: flex;
        flex-direction: column;
        position: relative;
        z-index: 1;
    }

    /* ── Hero section ── */
    .hero {
        text-align: center;
        padding: 72px 32px 56px;
        position: relative;
    }

    .hero-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 20px;
        padding: 5px 16px;
        border: 1px solid var(--border);
        border-radius: 20px;
        background: rgba(201,168,76,.06);
    }
    .hero-eyebrow::before, .hero-eyebrow::after {
        content: '◆';
        font-size: 6px;
        opacity: .6;
    }

    .hero-title {
        font-family: 'Cormorant Garamond', serif;
        font-size: clamp(36px, 5vw, 58px);
        font-weight: 700;
        color: var(--navy);
        line-height: 1.1;
        letter-spacing: -.5px;
        margin-bottom: 16px;
    }
    [data-theme="dark"] .hero-title { color: #dce4f5; }

    .hero-title span {
        color: var(--gold);
        position: relative;
    }
    .hero-title span::after {
        content: '';
        position: absolute;
        bottom: -2px; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--gold), transparent);
    }

    .hero-sub {
        font-size: 15px;
        color: var(--muted);
        max-width: 420px;
        margin: 0 auto 12px;
        line-height: 1.6;
        font-weight: 400;
    }

    .hero-greeting {
        font-size: 13px;
        color: var(--muted);
        margin-bottom: 48px;
    }
    .hero-greeting strong {
        color: var(--gold);
        font-weight: 600;
    }

    /* ── Decorative divider ── */
    .divider {
        display: flex;
        align-items: center;
        gap: 16px;
        max-width: 280px;
        margin: 0 auto 56px;
    }
    .divider-line {
        flex: 1;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--border), transparent);
    }
    .divider-diamond {
        width: 8px; height: 8px;
        background: var(--gold);
        transform: rotate(45deg);
        flex-shrink: 0;
        opacity: .6;
    }

    /* ── Portal cards ── */
    .portals {
        display: flex;
        justify-content: center;
        gap: 32px;
        padding: 0 32px 80px;
        flex-wrap: wrap;
    }

    .portal-card {
        width: 340px;
        background: var(--card-bg);
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-md);
        overflow: hidden;
        text-decoration: none;
        color: inherit;
        display: flex;
        flex-direction: column;
        transition: transform .3s cubic-bezier(.34,1.56,.64,1), box-shadow .3s ease;
        position: relative;
        animation: fadeUp .6s ease both;
    }
    .portal-card:nth-child(1) { animation-delay: .1s; }
    .portal-card:nth-child(2) { animation-delay: .22s; }

    .portal-card:hover {
        transform: translateY(-8px);
        box-shadow: var(--shadow-lg);
    }
    .portal-card:hover .card-arrow {
        transform: translateX(4px);
        opacity: 1;
    }
    .portal-card:hover .card-glow {
        opacity: 1;
    }

    /* Card glow effect on hover */
    .card-glow {
        position: absolute;
        inset: 0;
        border-radius: 20px;
        opacity: 0;
        transition: opacity .3s;
        pointer-events: none;
    }
    .portal-inventory .card-glow {
        background: radial-gradient(ellipse at 50% 0%, rgba(26,58,107,.06) 0%, transparent 70%);
    }
    .portal-fees .card-glow {
        background: radial-gradient(ellipse at 50% 0%, rgba(201,168,76,.08) 0%, transparent 70%);
    }

    /* Card header band */
    .card-header {
        height: 160px;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .portal-inventory .card-header {
        background: linear-gradient(145deg, #0d1f3c 0%, #1a3a6b 50%, #1e4d8c 100%);
    }
    .portal-fees .card-header {
        background: linear-gradient(145deg, #2a1a0a 0%, #7a4a1a 50%, #c9841a 100%);
    }

    /* Geometric pattern in card header */
    .card-pattern {
        position: absolute;
        inset: 0;
        opacity: .12;
    }
    .card-pattern svg { width: 100%; height: 100%; }

    /* Card icon */
    .card-icon-wrap {
        position: relative;
        z-index: 1;
        text-align: center;
    }
    .card-icon {
        width: 72px; height: 72px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        margin: 0 auto 10px;
        backdrop-filter: blur(8px);
    }
    .portal-inventory .card-icon {
        background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.2);
    }
    .portal-fees .card-icon {
        background: rgba(255,255,255,.1);
        border: 1px solid rgba(255,255,255,.18);
    }
    .card-header-label {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: rgba(255,255,255,.5);
        position: relative;
        z-index: 1;
    }

    /* Card body */
    .card-body {
        padding: 28px 28px 24px;
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .card-title {
        font-family: 'Cormorant Garamond', serif;
        font-size: 26px;
        font-weight: 700;
        color: var(--ink);
        margin-bottom: 10px;
        letter-spacing: -.3px;
        line-height: 1.2;
    }
    .card-desc {
        font-size: 13.5px;
        color: var(--muted);
        line-height: 1.65;
        flex: 1;
        margin-bottom: 20px;
    }

    /* Feature list */
    .card-features {
        list-style: none;
        margin-bottom: 24px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .card-features li {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 12.5px;
        color: var(--muted);
    }
    .feat-dot {
        width: 6px; height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .portal-inventory .feat-dot { background: var(--navy-mid); }
    .portal-fees .feat-dot { background: var(--gold); }

    /* CTA button */
    .card-cta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 13px 20px;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        font-family: 'DM Sans', sans-serif;
        font-size: 14px;
        font-weight: 600;
        transition: all .2s;
        text-decoration: none;
        color: #fff;
    }
    .portal-inventory .card-cta {
        background: linear-gradient(135deg, var(--navy-mid), var(--navy));
    }
    .portal-inventory .card-cta:hover {
        background: linear-gradient(135deg, var(--navy-light), var(--navy-mid));
    }
    .portal-fees .card-cta {
        background: linear-gradient(135deg, #b8760a, #8a540a);
        color: #fff;
    }
    .portal-fees .card-cta:hover {
        background: linear-gradient(135deg, var(--gold), #b8760a);
    }
    .card-arrow {
        font-size: 18px;
        transition: transform .2s, opacity .2s;
        opacity: .7;
    }

    /* Coming soon badge */
    .badge-soon {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: var(--gold);
        background: rgba(201,168,76,.1);
        border: 1px solid rgba(201,168,76,.25);
        border-radius: 20px;
        padding: 3px 10px;
        margin-bottom: 10px;
        width: fit-content;
    }

    /* ── Footer ── */
    .portal-footer {
        text-align: center;
        padding: 24px 32px 40px;
        border-top: 1px solid var(--border);
        font-size: 11.5px;
        color: var(--muted);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 24px;
        flex-wrap: wrap;
    }
    .portal-footer a {
        color: var(--muted);
        text-decoration: none;
    }
    .portal-footer a:hover { color: var(--gold); }
    .footer-sep { opacity: .3; }

    /* ── Animations ── */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(28px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .hero { animation: fadeUp .5s ease both; }

    /* ── Background decorations ── */
    .bg-deco {
        position: fixed;
        pointer-events: none;
        z-index: 0;
    }
    .bg-deco-1 {
        top: -120px; right: -120px;
        width: 480px; height: 480px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(26,58,107,.06) 0%, transparent 70%);
    }
    .bg-deco-2 {
        bottom: -80px; left: -80px;
        width: 360px; height: 360px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(201,168,76,.06) 0%, transparent 70%);
    }

    /* ── Responsive ── */
    @media (max-width: 750px) {
        .hero { padding: 48px 20px 40px; }
        .portals { gap: 20px; padding: 0 20px 60px; }
        .portal-card { width: 100%; max-width: 380px; }
        .topbar { padding: 0 20px; }
        .brand-text-sm { display: none; }
    }
    </style>
</head>
<body>

<!-- Background decorations -->
<div class="bg-deco bg-deco-1"></div>
<div class="bg-deco bg-deco-2"></div>

<!-- Top bar -->
<header class="topbar">
    <div class="topbar-brand">
        <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo">
        <div class="brand-text-sm">
            <span class="brand-name-sm">KIMC Eldoret</span>
            <span class="brand-sub-sm">Campus System</span>
        </div>
    </div>
    <div class="topbar-right">
        <div class="topbar-user">
            <div class="topbar-avatar"><?= strtoupper(substr($admin['first_name'] ?: $admin['full_name'], 0, 1)) ?></div>
            <span class="topbar-name"><?= htmlspecialchars($admin['first_name'] ?: explode(' ', $admin['full_name'])[0]) ?></span>
        </div>
        <button class="btn-theme" onclick="togglePortalTheme()" title="Toggle theme" id="theme-btn">🌙</button>
        <a href="<?= APP_ROOT ?>/auth/logout.php" class="btn-signout">Sign Out</a>
    </div>
</header>

<main class="main">

    <!-- Hero -->
    <section class="hero">
        <div class="hero-eyebrow">Kenya Institute of Mass Communication · Eldoret</div>
        <h1 class="hero-title">
            Welcome back,<br><span><?= htmlspecialchars($admin['first_name'] ?: explode(' ', $admin['full_name'])[0]) ?></span>
        </h1>
        <p class="hero-sub">Select a portal to continue. Each section is independently managed but shares the same student records.</p>
    </section>

    <div class="divider">
        <div class="divider-line"></div>
        <div class="divider-diamond"></div>
        <div class="divider-line"></div>
    </div>

    <!-- Portal cards -->
    <div class="portals">

        <!-- Inventory Portal -->
        <a href="<?= APP_ROOT ?>/admin/dashboard.php" class="portal-card portal-inventory">
            <div class="card-glow"></div>

            <div class="card-header">
                <!-- Geometric background pattern -->
                <div class="card-pattern">
                    <svg viewBox="0 0 340 160" xmlns="http://www.w3.org/2000/svg">
                        <line x1="0" y1="160" x2="160" y2="0" stroke="white" stroke-width="1"/>
                        <line x1="60" y1="160" x2="220" y2="0" stroke="white" stroke-width="1"/>
                        <line x1="120" y1="160" x2="280" y2="0" stroke="white" stroke-width="1"/>
                        <line x1="180" y1="160" x2="340" y2="0" stroke="white" stroke-width="1"/>
                        <line x1="240" y1="160" x2="400" y2="0" stroke="white" stroke-width="1"/>
                        <rect x="120" y="40" width="100" height="80" rx="4" stroke="white" stroke-width="1" fill="none"/>
                        <rect x="135" y="55" width="70" height="50" rx="2" stroke="white" stroke-width=".5" fill="none"/>
                    </svg>
                </div>
                <div class="card-icon-wrap">
                    <div class="card-icon">📦</div>
                    <div class="card-header-label">Inventory System</div>
                </div>
            </div>

            <div class="card-body">
                <div class="card-title">Inventory &amp; Loans</div>
                <p class="card-desc">Manage all campus equipment, books, and kit bundles. Track checkouts, returns, and conditions in real time.</p>
                <ul class="card-features">
                    <li><span class="feat-dot"></span>Books &amp; equipment catalogue</li>
                    <li><span class="feat-dot"></span>Student checkout &amp; check-in</li>
                    <li><span class="feat-dot"></span>Kit bundle management</li>
                    <li><span class="feat-dot"></span>Overdue tracking &amp; fines</li>
                </ul>
                <div class="card-cta">
                    Open Inventory Portal
                    <span class="card-arrow">→</span>
                </div>
            </div>
        </a>

        <!-- Fees Portal -->
        <a href="<?= APP_ROOT ?>/fees/dashboard.php" class="portal-card portal-fees">
            <div class="card-glow"></div>

            <div class="card-header">
                <div class="card-pattern">
                    <svg viewBox="0 0 340 160" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="170" cy="80" r="70" stroke="white" stroke-width="1" fill="none"/>
                        <circle cx="170" cy="80" r="50" stroke="white" stroke-width=".5" fill="none"/>
                        <circle cx="170" cy="80" r="30" stroke="white" stroke-width=".5" fill="none"/>
                        <line x1="100" y1="80" x2="240" y2="80" stroke="white" stroke-width=".5"/>
                        <line x1="170" y1="10" x2="170" y2="150" stroke="white" stroke-width=".5"/>
                        <line x1="120" y1="30" x2="220" y2="130" stroke="white" stroke-width=".5"/>
                        <line x1="220" y1="30" x2="120" y2="130" stroke="white" stroke-width=".5"/>
                    </svg>
                </div>
                <div class="card-icon-wrap">
                    <div class="card-icon">💳</div>
                    <div class="card-header-label">Fees Management</div>
                </div>
            </div>

            <div class="card-body">
                <div class="card-title">Fees &amp; Payments</div>
                <p class="card-desc">Collect and track school fees with M-Pesa integration. Manage payment records, receipts, and outstanding balances.</p>
                <ul class="card-features">
                    <li><span class="feat-dot"></span>M-Pesa STK Push integration</li>
                    <li><span class="feat-dot"></span>Bank &amp; manual payment entry</li>
                    <li><span class="feat-dot"></span>Per-student fee statements</li>
                    <li><span class="feat-dot"></span>Receipt generation</li>
                </ul>
                <div class="card-cta">
                    Open Fees Portal
                    <span class="card-arrow">→</span>
                </div>
            </div>
        </a>

    </div>

    <!-- Footer -->
    <footer class="portal-footer">
        <span>KIMC Eldoret Campus System &copy; <?= date('Y') ?></span>
        <span class="footer-sep">|</span>
        <a href="<?= APP_ROOT ?>/admin/dashboard.php">Dashboard</a>
        <span class="footer-sep">|</span>
        <a href="<?= APP_ROOT ?>/admin/settings.php">Settings</a>
        <span class="footer-sep">|</span>
        <a href="<?= APP_ROOT ?>/auth/logout.php">Sign Out</a>
    </footer>

</main>

<script>
// Minimal theme toggle for portal page (standalone, no main.js dependency)
const THEME_KEY = 'kimc_theme';

(function() {
    const saved = localStorage.getItem(THEME_KEY) || 'light';
    document.documentElement.setAttribute('data-theme', saved);
    const btn = document.getElementById('theme-btn');
    if (btn) btn.textContent = saved === 'dark' ? '☀️' : '🌙';
})();

function togglePortalTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem(THEME_KEY, next);
    const btn = document.getElementById('theme-btn');
    if (btn) btn.textContent = next === 'dark' ? '☀️' : '🌙';
}
</script>
</body>
</html>
