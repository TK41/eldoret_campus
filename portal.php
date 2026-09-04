<?php
// ============================================================
// portal.php — RBAC-aware portal chooser
// Only shows cards the logged-in user has access to
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/auth/rbac.php';
requireLogin();

$admin   = getCurrentAdmin();
$flash   = getFlash();

// Auto-redirect single-portal users away from this page
$portals = array_filter(getAccessibleModules(), fn($m) => $m !== 'system');
if (count($portals) === 1) {
    header('Location: ' . getDefaultRedirect());
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIMC Eldoret — Portal</title>

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
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=Space+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">
    <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
        --navy:#0d1f3c;--navy-mid:#1a3a6b;--navy-light:#2a5298;
        --gold:#c9a84c;--gold-light:#e4c46a;
        --cream:#faf8f4;--ink:#1a1a2e;--muted:#6b7280;
        --border:rgba(201,168,76,.2);--card-bg:#ffffff;
        --shadow-sm:0 2px 8px rgba(13,31,60,.08);
        --shadow-md:0 8px 32px rgba(13,31,60,.14);
        --shadow-lg:0 20px 60px rgba(13,31,60,.22);
        --exam-dark:#022c22;--exam-mid:#065f46;--exam-light:#059669;
    }
    [data-theme="dark"]{
        --cream:#0f1624;--card-bg:#162035;--ink:#e8eaf2;--muted:#8892a4;
        --border:rgba(201,168,76,.15);
        --shadow-sm:0 2px 8px rgba(0,0,0,.25);
        --shadow-md:0 8px 32px rgba(0,0,0,.4);
        --shadow-lg:0 20px 60px rgba(0,0,0,.55);
    }
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--ink);min-height:100vh;overflow-x:hidden}
    body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");pointer-events:none;z-index:0}
    .topbar{position:fixed;top:0;left:0;right:0;height:64px;background:rgba(255,255,255,.85);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:100}
    [data-theme="dark"] .topbar{background:rgba(15,22,36,.85)}
    .topbar-brand{display:flex;align-items:center;gap:12px;text-decoration:none}
    .topbar-brand img{height:36px;width:auto;object-fit:contain}
    .brand-text-sm{display:flex;flex-direction:column}
    .brand-name-sm{font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:700;color:var(--navy);line-height:1.2;letter-spacing:.3px}
    [data-theme="dark"] .brand-name-sm{color:#c9d4ec}
    .brand-sub-sm{font-size:10px;color:var(--muted);letter-spacing:.5px;text-transform:uppercase}
    .topbar-right{display:flex;align-items:center;gap:16px}
    .topbar-user{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--muted)}
    .topbar-avatar{width:32px;height:32px;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
    .topbar-name{font-weight:500;color:var(--ink);font-size:13px}
    .role-badge{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;background:rgba(26,58,107,.08);color:var(--navy-mid);border:1px solid rgba(26,58,107,.15)}
    .btn-signout{font-size:12px;font-weight:500;color:var(--muted);text-decoration:none;padding:5px 12px;border:1px solid var(--border);border-radius:20px;transition:all .2s;font-family:'DM Sans',sans-serif}
    .btn-signout:hover{color:#dc2626;border-color:rgba(220,38,38,.3);background:rgba(220,38,38,.04)}
    .btn-theme{background:none;border:1px solid var(--border);border-radius:8px;width:34px;height:34px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;color:var(--ink)}
    .btn-theme:hover{background:rgba(201,168,76,.08)}
    .main{min-height:100vh;padding-top:64px;display:flex;flex-direction:column;position:relative;z-index:1}
    .hero{text-align:center;padding:72px 32px 56px}
    .hero-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--gold);margin-bottom:20px;padding:5px 16px;border:1px solid var(--border);border-radius:20px;background:rgba(201,168,76,.06)}
    .hero-eyebrow::before,.hero-eyebrow::after{content:'◆';font-size:6px;opacity:.6}
    .hero-title{font-family:'Cormorant Garamond',serif;font-size:clamp(36px,5vw,58px);font-weight:700;color:var(--navy);line-height:1.1;letter-spacing:-.5px;margin-bottom:16px}
    [data-theme="dark"] .hero-title{color:#dce4f5}
    .hero-title span{color:var(--gold);position:relative}
    .hero-title span::after{content:'';position:absolute;bottom:-2px;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--gold),transparent)}
    .hero-sub{font-size:15px;color:var(--muted);max-width:460px;margin:0 auto;line-height:1.6}
    .divider{display:flex;align-items:center;gap:16px;max-width:280px;margin:0 auto 56px}
    .divider-line{flex:1;height:1px;background:linear-gradient(90deg,transparent,var(--border),transparent)}
    .divider-diamond{width:8px;height:8px;background:var(--gold);transform:rotate(45deg);flex-shrink:0;opacity:.6}
    .portals{display:flex;justify-content:center;gap:28px;padding:0 32px 80px;flex-wrap:wrap}
    .portal-card{width:300px;background:var(--card-bg);border-radius:20px;border:1px solid var(--border);box-shadow:var(--shadow-md);overflow:hidden;text-decoration:none;color:inherit;display:flex;flex-direction:column;transition:transform .3s cubic-bezier(.34,1.56,.64,1),box-shadow .3s ease;position:relative;animation:fadeUp .6s ease both}
    .portal-card:nth-child(1){animation-delay:.10s}
    .portal-card:nth-child(2){animation-delay:.22s}
    .portal-card:nth-child(3){animation-delay:.34s}
    .portal-card:nth-child(4){animation-delay:.46s}
    .portal-card:hover{transform:translateY(-8px);box-shadow:var(--shadow-lg)}
    .portal-card:hover .card-arrow{transform:translateX(4px);opacity:1}
    .portal-card:hover .card-glow{opacity:1}
    .card-glow{position:absolute;inset:0;border-radius:20px;opacity:0;transition:opacity .3s;pointer-events:none}
    .portal-inventory .card-glow{background:radial-gradient(ellipse at 50% 0%,rgba(26,58,107,.06) 0%,transparent 70%)}
    .portal-fees      .card-glow{background:radial-gradient(ellipse at 50% 0%,rgba(201,168,76,.08) 0%,transparent 70%)}
    .portal-exam      .card-glow{background:radial-gradient(ellipse at 50% 0%,rgba(5,150,105,.09) 0%,transparent 70%)}
    .portal-admissions .card-glow{background:radial-gradient(ellipse at 50% 0%,rgba(79,70,229,.07) 0%,transparent 70%)}
    .card-header{height:150px;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
    .portal-inventory  .card-header{background:linear-gradient(145deg,#0d1f3c 0%,#1a3a6b 50%,#1e4d8c 100%)}
    .portal-fees       .card-header{background:linear-gradient(145deg,#2a1a0a 0%,#7a4a1a 50%,#c9841a 100%)}
    .portal-exam       .card-header{background:linear-gradient(145deg,#022c22 0%,#065f46 50%,#047857 100%)}
    .portal-admissions .card-header{background:linear-gradient(145deg,#1e1b4b 0%,#3730a3 50%,#4f46e5 100%)}
    .card-pattern{position:absolute;inset:0;opacity:.12}
    .card-pattern svg{width:100%;height:100%}
    .card-icon-wrap{position:relative;z-index:1;text-align:center}
    .card-icon{width:66px;height:66px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 8px;backdrop-filter:blur(8px);background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2)}
    .card-header-label{font-size:10px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.5);position:relative;z-index:1}
    .card-body{padding:24px 24px 20px;flex:1;display:flex;flex-direction:column}
    .card-title{font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:700;color:var(--ink);margin-bottom:8px;letter-spacing:-.3px;line-height:1.2}
    .card-desc{font-size:13px;color:var(--muted);line-height:1.65;flex:1;margin-bottom:18px}
    .card-features{list-style:none;margin-bottom:20px;display:flex;flex-direction:column;gap:7px}
    .card-features li{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--muted)}
    .feat-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0}
    .portal-inventory  .feat-dot{background:var(--navy-mid)}
    .portal-fees       .feat-dot{background:var(--gold)}
    .portal-exam       .feat-dot{background:#059669}
    .portal-admissions .feat-dot{background:#4f46e5}
    .card-cta{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-radius:10px;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;transition:all .2s;text-decoration:none;color:#fff}
    .portal-inventory  .card-cta{background:linear-gradient(135deg,var(--navy-mid),var(--navy))}
    .portal-inventory  .card-cta:hover{background:linear-gradient(135deg,var(--navy-light),var(--navy-mid))}
    .portal-fees       .card-cta{background:linear-gradient(135deg,#b8760a,#8a540a)}
    .portal-fees       .card-cta:hover{background:linear-gradient(135deg,var(--gold),#b8760a)}
    .portal-exam       .card-cta{background:linear-gradient(135deg,var(--exam-mid),var(--exam-dark))}
    .portal-exam       .card-cta:hover{background:linear-gradient(135deg,var(--exam-light),var(--exam-mid))}
    .portal-admissions .card-cta{background:linear-gradient(135deg,#3730a3,#1e1b4b)}
    .portal-admissions .card-cta:hover{background:linear-gradient(135deg,#4f46e5,#3730a3)}
    .card-arrow{font-size:16px;transition:transform .2s,opacity .2s;opacity:.7}
    .portal-footer{text-align:center;padding:24px 32px 40px;border-top:1px solid var(--border);font-size:11.5px;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:24px;flex-wrap:wrap}
    .portal-footer a{color:var(--muted);text-decoration:none}
    .portal-footer a:hover{color:var(--gold)}
    .footer-sep{opacity:.3}
    @keyframes fadeUp{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:translateY(0)}}
    .hero{animation:fadeUp .5s ease both}
    .bg-deco{position:fixed;pointer-events:none;z-index:0}
    .bg-deco-1{top:-120px;right:-120px;width:480px;height:480px;border-radius:50%;background:radial-gradient(circle,rgba(26,58,107,.06) 0%,transparent 70%)}
    .bg-deco-2{bottom:-80px;left:-80px;width:360px;height:360px;border-radius:50%;background:radial-gradient(circle,rgba(201,168,76,.06) 0%,transparent 70%)}
    .flash-msg{position:fixed;top:76px;left:50%;transform:translateX(-50%);background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.25);color:#991b1b;padding:10px 20px;border-radius:10px;font-size:13px;z-index:200;white-space:nowrap}
    @media(max-width:1050px){.portal-card{width:280px}}
    @media(max-width:750px){.hero{padding:48px 20px 40px}.portals{gap:16px;padding:0 16px 60px}.portal-card{width:100%;max-width:380px}.topbar{padding:0 16px}.brand-text-sm{display:none}}
    </style>
</head>
<body>

<div class="bg-deco bg-deco-1"></div>
<div class="bg-deco bg-deco-2"></div>

<?php if ($flash && $flash['type'] === 'error'): ?>
<div class="flash-msg">⚠️ <?= htmlspecialchars($flash['message']) ?></div>
<?php endif; ?>

<header class="topbar">
    <a href="<?= APP_ROOT ?>/portal.php" class="topbar-brand">
        <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo">
        <div class="brand-text-sm">
            <span class="brand-name-sm">KIMC Eldoret</span>
            <span class="brand-sub-sm">Campus System</span>
        </div>
    </a>
    <div class="topbar-right">
        <div class="topbar-user">
            <div class="topbar-avatar"><?= strtoupper(substr($admin['first_name'] ?: $admin['full_name'], 0, 1)) ?></div>
            <div>
                <span class="topbar-name"><?= htmlspecialchars($admin['first_name'] ?: explode(' ', $admin['full_name'])[0]) ?></span>
                <div><span class="role-badge"><?= htmlspecialchars($_SESSION['role_label'] ?? ucfirst($admin['role'])) ?></span></div>
            </div>
        </div>
        <?php if (canAccess('system')): ?>
        <a href="<?= APP_ROOT ?>/admin/manage_admins.php" class="btn-theme" title="Manage Users" style="font-size:14px;text-decoration:none">👥</a>
        <?php endif; ?>
        <button class="btn-theme" onclick="togglePortalTheme()" title="Toggle theme" id="theme-btn">🌙</button>
        <a href="<?= APP_ROOT ?>/auth/logout.php" class="btn-signout">Sign Out</a>
    </div>
</header>

<main class="main">
    <section class="hero">
        <div class="hero-eyebrow">Kenya Institute of Mass Communication · Eldoret</div>
        <h1 class="hero-title">
            Welcome,<br><span><?= htmlspecialchars($admin['first_name'] ?: explode(' ', $admin['full_name'])[0]) ?></span>
        </h1>
        <p class="hero-sub">Select a portal to continue.</p>
    </section>

    <div class="divider">
        <div class="divider-line"></div>
        <div class="divider-diamond"></div>
        <div class="divider-line"></div>
    </div>

    <div class="portals">

        <?php if (canAccess('inventory')): ?>
        <a href="<?= APP_ROOT ?>/admin/dashboard.php" class="portal-card portal-inventory">
            <div class="card-glow"></div>
            <div class="card-header">
                <div class="card-pattern">
                    <svg viewBox="0 0 320 150" xmlns="http://www.w3.org/2000/svg">
                        <line x1="0" y1="150" x2="150" y2="0" stroke="white" stroke-width="1"/>
                        <line x1="60" y1="150" x2="210" y2="0" stroke="white" stroke-width="1"/>
                        <line x1="120" y1="150" x2="270" y2="0" stroke="white" stroke-width="1"/>
                        <line x1="180" y1="150" x2="330" y2="0" stroke="white" stroke-width="1"/>
                        <rect x="110" y="35" width="100" height="80" rx="4" stroke="white" stroke-width="1" fill="none"/>
                        <rect x="125" y="50" width="70" height="50" rx="2" stroke="white" stroke-width=".5" fill="none"/>
                    </svg>
                </div>
                <div class="card-icon-wrap">
                    <div class="card-icon">📦</div>
                    <div class="card-header-label">Inventory System</div>
                </div>
            </div>
            <div class="card-body">
                <div class="card-title">Inventory &amp; Loans</div>
                <p class="card-desc">Manage equipment, books, and kit bundles. Track checkouts, returns, and conditions.</p>
                <ul class="card-features">
                    <li><span class="feat-dot"></span>Books &amp; equipment catalogue</li>
                    <li><span class="feat-dot"></span>Student checkout &amp; check-in</li>
                    <li><span class="feat-dot"></span>Kit bundle management</li>
                    <?php if (canDo('inventory.delete_asset')): ?>
                    <li><span class="feat-dot"></span>Full admin access</li>
                    <?php else: ?>
                    <li><span class="feat-dot"></span>Checkout &amp; check-in only</li>
                    <?php endif; ?>
                </ul>
                <div class="card-cta">Open Inventory Portal <span class="card-arrow">→</span></div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (canAccess('fees')): ?>
        <a href="<?= APP_ROOT ?>/fees/dashboard.php" class="portal-card portal-fees">
            <div class="card-glow"></div>
            <div class="card-header">
                <div class="card-pattern">
                    <svg viewBox="0 0 320 150" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="160" cy="75" r="65" stroke="white" stroke-width="1" fill="none"/>
                        <circle cx="160" cy="75" r="45" stroke="white" stroke-width=".5" fill="none"/>
                        <circle cx="160" cy="75" r="25" stroke="white" stroke-width=".5" fill="none"/>
                        <line x1="95" y1="75" x2="225" y2="75" stroke="white" stroke-width=".5"/>
                        <line x1="160" y1="10" x2="160" y2="140" stroke="white" stroke-width=".5"/>
                    </svg>
                </div>
                <div class="card-icon-wrap">
                    <div class="card-icon">💳</div>
                    <div class="card-header-label">Fees Management</div>
                </div>
            </div>
            <div class="card-body">
                <div class="card-title">Fees &amp; Payments</div>
                <p class="card-desc">Collect and track school fees. Manage payment records, receipts, and balances.</p>
                <ul class="card-features">
                    <li><span class="feat-dot"></span>M-Pesa &amp; bank payment entry</li>
                    <li><span class="feat-dot"></span>Per-student fee statements</li>
                    <?php if (canDo('fees.delete_payment')): ?>
                    <li><span class="feat-dot"></span>Full admin access</li>
                    <?php else: ?>
                    <li><span class="feat-dot"></span>Post &amp; view payments</li>
                    <?php endif; ?>
                    <li><span class="feat-dot"></span>Receipt generation</li>
                </ul>
                <div class="card-cta">Open Fees Portal <span class="card-arrow">→</span></div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (canAccess('exams')): ?>
        <a href="<?= APP_ROOT ?>/exams/dashboard.php" class="portal-card portal-exam">
            <div class="card-glow"></div>
            <div class="card-header">
                <div class="card-pattern">
                    <svg viewBox="0 0 320 150" xmlns="http://www.w3.org/2000/svg">
                        <line x1="60" y1="20" x2="60" y2="130" stroke="white" stroke-width=".6"/>
                        <line x1="120" y1="20" x2="120" y2="130" stroke="white" stroke-width=".6"/>
                        <line x1="180" y1="20" x2="180" y2="130" stroke="white" stroke-width=".6"/>
                        <line x1="240" y1="20" x2="240" y2="130" stroke="white" stroke-width=".6"/>
                        <line x1="40" y1="50" x2="280" y2="50" stroke="white" stroke-width=".6"/>
                        <line x1="40" y1="90" x2="280" y2="90" stroke="white" stroke-width=".6"/>
                        <rect x="70" y="95" width="26" height="30" rx="2" stroke="white" stroke-width="1" fill="none"/>
                        <rect x="130" y="68" width="26" height="57" rx="2" stroke="white" stroke-width="1" fill="none"/>
                        <rect x="190" y="45" width="26" height="80" rx="2" stroke="white" stroke-width="1" fill="none"/>
                        <circle cx="248" cy="42" r="12" stroke="white" stroke-width="1" fill="none"/>
                        <text x="248" y="47" text-anchor="middle" fill="white" font-size="12" font-family="sans-serif">A</text>
                    </svg>
                </div>
                <div class="card-icon-wrap">
                    <div class="card-icon">🎓</div>
                    <div class="card-header-label">Exam Results</div>
                </div>
            </div>
            <div class="card-body">
                <div class="card-title">Exam Results</div>
                <p class="card-desc">Enter marks, view results, generate transcripts and track academic performance.</p>
                <ul class="card-features">
                    <li><span class="feat-dot"></span>Real-time mark entry &amp; autosave</li>
                    <li><span class="feat-dot"></span>CA &amp; final exam tracking</li>
                    <li><span class="feat-dot"></span>Printable transcripts</li>
                    <li><span class="feat-dot"></span>Class rankings &amp; analytics</li>
                </ul>
                <div class="card-cta">Open Exam Portal <span class="card-arrow">→</span></div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (canAccess('admissions')): ?>
        <a href="<?= APP_ROOT ?>/admissions/dashboard.php" class="portal-card portal-admissions">
            <div class="card-glow"></div>
            <div class="card-header">
                <div class="card-pattern">
                    <svg viewBox="0 0 320 150" xmlns="http://www.w3.org/2000/svg">
                        <rect x="60" y="25" width="200" height="100" rx="6" stroke="white" stroke-width="1" fill="none"/>
                        <line x1="60" y1="55" x2="260" y2="55" stroke="white" stroke-width=".6"/>
                        <line x1="90" y1="25" x2="90" y2="125" stroke="white" stroke-width=".6"/>
                        <circle cx="75" cy="40" r="8" stroke="white" stroke-width=".8" fill="none"/>
                        <line x1="105" y1="38" x2="200" y2="38" stroke="white" stroke-width=".8"/>
                        <line x1="105" y1="45" x2="170" y2="45" stroke="white" stroke-width=".5"/>
                        <line x1="105" y1="70" x2="240" y2="70" stroke="white" stroke-width=".6"/>
                        <line x1="105" y1="80" x2="220" y2="80" stroke="white" stroke-width=".6"/>
                        <line x1="105" y1="100" x2="240" y2="100" stroke="white" stroke-width=".6"/>
                        <line x1="105" y1="110" x2="190" y2="110" stroke="white" stroke-width=".6"/>
                    </svg>
                </div>
                <div class="card-icon-wrap">
                    <div class="card-icon">📋</div>
                    <div class="card-header-label">Admissions</div>
                </div>
            </div>
            <div class="card-body">
                <div class="card-title">Admissions</div>
                <p class="card-desc">Review student applications, verify documents, and manage the admissions pipeline.</p>
                <ul class="card-features">
                    <li><span class="feat-dot"></span>View all applications</li>
                    <li><span class="feat-dot"></span>Review uploaded documents</li>
                    <?php if (canDo('admissions.update_status')): ?>
                    <li><span class="feat-dot"></span>Update application status</li>
                    <?php endif; ?>
                    <li><span class="feat-dot"></span>Track application pipeline</li>
                </ul>
                <div class="card-cta">Open Admissions Portal <span class="card-arrow">→</span></div>
            </div>
        </a>
        <?php endif; ?>

    </div>

    <footer class="portal-footer">
        <span>KIMC Eldoret Campus System &copy; <?= date('Y') ?></span>
        <span class="footer-sep">|</span>
        <?php if (canAccess('inventory')): ?><a href="<?= APP_ROOT ?>/admin/dashboard.php">Inventory</a><span class="footer-sep">|</span><?php endif; ?>
        <?php if (canAccess('fees')): ?><a href="<?= APP_ROOT ?>/fees/dashboard.php">Fees</a><span class="footer-sep">|</span><?php endif; ?>
        <?php if (canAccess('exams')): ?><a href="<?= APP_ROOT ?>/exams/dashboard.php">Exams</a><span class="footer-sep">|</span><?php endif; ?>
        <?php if (canAccess('admissions')): ?><a href="<?= APP_ROOT ?>/admissions/dashboard.php">Admissions</a><span class="footer-sep">|</span><?php endif; ?>
        <?php if (canAccess('system')): ?><a href="<?= APP_ROOT ?>/admin/manage_admins.php">Users &amp; Roles</a><span class="footer-sep">|</span><?php endif; ?>
        <a href="<?= APP_ROOT ?>/auth/logout.php">Sign Out</a>
    </footer>
</main>

<script>
const THEME_KEY = 'kimc_theme';
(function(){
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
// Auto-dismiss flash
setTimeout(() => { document.querySelector('.flash-msg')?.remove(); }, 4000);
</script>
</body>
</html>
