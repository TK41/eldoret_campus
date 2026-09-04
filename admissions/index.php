<?php
// ============================================================
// admissions/index.php  — PUBLIC LANDING PAGE
// Single entry point for the KIMC Eldoret Admissions Portal
// ============================================================
require_once __DIR__ . '/../config/db.php';
if (is_file(__DIR__ . '/intake.php')) {
    require_once __DIR__ . '/intake.php';
} elseif (!function_exists('getNextAdmissionsIntake')) {
    function getNextAdmissionsIntake($today = null): array {
        $today = $today ?: new DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $currentMonth = (int) $today->format('n');
        foreach ([3 => 'March', 5 => 'May', 9 => 'September'] as $month => $name) {
            if ($month >= $currentMonth) {
                return ['month' => $month, 'name' => $name, 'year' => $year, 'label' => $name . ' ' . $year];
            }
        }
        return ['month' => 3, 'name' => 'March', 'year' => $year + 1, 'label' => 'March ' . ($year + 1)];
    }
}

$nextIntake = getNextAdmissionsIntake();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admissions Portal — KIMC Eldoret Campus</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
    --navy:#0d1f3c;
    --navy-mid:#1a3a6b;
    --navy-light:#1e4d8c;
    --green:#065f46;
    --green-mid:#059669;
    --green-light:#34d399;
    --cream:#fafaf9;
    --white:#fff;
    --ink:#0f172a;
    --muted:#64748b;
    --border:#e2e8f0;
    --gold:#f59e0b;
    --gold-light:#fde68a;
    --red:#c1121f;
}

html,body{min-height:100vh}
body{
    font-family:'Plus Jakarta Sans',sans-serif;
    background:var(--cream);
    color:var(--ink);
    display:flex;
    flex-direction:column;
}

/* ── Background texture ── */
body::before{
    content:'';
    position:fixed;
    inset:0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(13,31,60,.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 90%, rgba(5,150,105,.05) 0%, transparent 60%);
    pointer-events:none;
    z-index:0;
}

/* ── Topbar ── */
.topbar{
    background:var(--navy);
    padding:0 32px;
    height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    position:relative;
    z-index:10;
    border-bottom:1px solid rgba(255,255,255,.06);
}
.topbar-brand{display:flex;align-items:center;gap:14px;text-decoration:none}
.topbar-brand img{height:38px;width:auto;object-fit:contain}
.brand-text{}
.brand-name{font-size:15px;font-weight:800;color:#fff;letter-spacing:-.3px;line-height:1.1}
.brand-sub{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.8px;text-transform:uppercase;margin-top:2px}
.topbar-year{
    font-family:'Space Mono',monospace;
    font-size:11px;
    color:var(--gold);
    background:rgba(245,158,11,.1);
    border:1px solid rgba(245,158,11,.2);
    padding:5px 12px;
    border-radius:20px;
    letter-spacing:.5px;
}

/* ── Main ── */
main{
    flex:1;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:48px 24px 64px;
    position:relative;
    z-index:1;
}

/* ── Header block ── */
.portal-header{
    text-align:center;
    max-width:600px;
    margin-bottom:52px;
}
.institute-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:var(--navy);
    color:#fff;
    font-size:11px;
    font-weight:700;
    letter-spacing:1.2px;
    text-transform:uppercase;
    padding:6px 18px;
    border-radius:24px;
    margin-bottom:20px;
    border:1px solid rgba(255,255,255,.1);
}
.institute-badge span{color:var(--gold-light)}
.portal-title{
    font-size:clamp(28px,5vw,46px);
    font-weight:800;
    line-height:1.1;
    letter-spacing:-.8px;
    color:var(--navy);
    margin-bottom:10px;
}
.portal-title em{
    font-style:normal;
    color:var(--red);
}
.portal-subtitle{
    font-size:15px;
    color:var(--muted);
    line-height:1.7;
    max-width:460px;
    margin:0 auto 24px;
}
.intake-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:rgba(5,150,105,.08);
    border:1px solid rgba(5,150,105,.2);
    color:var(--green);
    font-size:12px;
    font-weight:700;
    padding:6px 16px;
    border-radius:20px;
    letter-spacing:.3px;
}
.intake-pill::before{
    content:'';
    width:7px;height:7px;
    border-radius:50%;
    background:var(--green-mid);
    box-shadow:0 0 0 3px rgba(5,150,105,.2);
    animation:pulse 2s infinite;
}
@keyframes pulse{
    0%,100%{box-shadow:0 0 0 3px rgba(5,150,105,.2)}
    50%{box-shadow:0 0 0 6px rgba(5,150,105,.06)}
}

/* ── Cards row ── */
.cards-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
    width:100%;
    max-width:680px;
    margin-bottom:40px;
}
@media(max-width:560px){
    .cards-row{grid-template-columns:1fr}
}

/* ── Portal card ── */
.portal-card{
    background:#fff;
    border:1.5px solid var(--border);
    border-radius:20px;
    padding:32px 28px;
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    text-decoration:none;
    color:inherit;
    position:relative;
    overflow:hidden;
    transition:transform .2s, box-shadow .2s, border-color .2s;
    box-shadow:0 1px 3px rgba(0,0,0,.06), 0 8px 24px rgba(0,0,0,.05);
    cursor:pointer;
}
.portal-card:hover{
    transform:translateY(-4px);
    box-shadow:0 4px 6px rgba(0,0,0,.06), 0 20px 40px rgba(0,0,0,.1);
}
.portal-card.card-apply{border-color:rgba(13,31,60,.15)}
.portal-card.card-apply:hover{border-color:var(--navy-mid)}
.portal-card.card-status{border-color:rgba(5,150,105,.2)}
.portal-card.card-status:hover{border-color:var(--green-mid)}

/* Card accent strip */
.portal-card::before{
    content:'';
    position:absolute;
    top:0;left:0;right:0;
    height:4px;
    border-radius:20px 20px 0 0;
    transition:height .2s;
}
.card-apply::before{background:linear-gradient(90deg,var(--navy),var(--navy-mid))}
.card-status::before{background:linear-gradient(90deg,var(--green),var(--green-mid))}
.portal-card:hover::before{height:5px}

/* Card body */
.card-icon{
    font-size:36px;
    margin-bottom:16px;
    line-height:1;
}
.card-tag{
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:1px;
    padding:3px 10px;
    border-radius:10px;
    margin-bottom:10px;
}
.card-apply .card-tag{background:rgba(13,31,60,.07);color:var(--navy-mid)}
.card-status .card-tag{background:rgba(5,150,105,.08);color:var(--green)}
.card-title{
    font-size:20px;
    font-weight:800;
    letter-spacing:-.4px;
    margin-bottom:8px;
    line-height:1.2;
}
.card-desc{
    font-size:13px;
    color:var(--muted);
    line-height:1.7;
    flex:1;
    margin-bottom:24px;
}
.card-cta{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:11px 20px;
    border-radius:10px;
    font-size:13px;
    font-weight:700;
    transition:all .2s;
    width:100%;
    justify-content:center;
}
.card-apply .card-cta{
    background:var(--navy);
    color:#fff;
}
.card-apply:hover .card-cta{background:var(--navy-mid)}
.card-status .card-cta{
    background:linear-gradient(135deg,var(--green),var(--green-mid));
    color:#fff;
}
.card-status:hover .card-cta{filter:brightness(1.08)}

.card-cta-arrow{
    transition:transform .2s;
}
.portal-card:hover .card-cta-arrow{transform:translateX(4px)}

/* ── Info strip ── */
.info-strip{
    display:flex;
    gap:28px;
    align-items:center;
    flex-wrap:wrap;
    justify-content:center;
    max-width:640px;
    padding:18px 28px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:0 1px 3px rgba(0,0,0,.05);
}
.info-item{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:12px;
    color:var(--muted);
}
.info-item strong{color:var(--ink);font-weight:700}
.info-divider{width:1px;height:28px;background:var(--border)}
@media(max-width:480px){.info-divider{display:none}.info-strip{gap:14px}}

/* ── Footer ── */
footer{
    text-align:center;
    padding:20px 24px;
    font-size:12px;
    color:var(--muted);
    border-top:1px solid var(--border);
    background:#fff;
    position:relative;
    z-index:1;
}

/* ── Decorative corner ── */
.card-corner{
    position:absolute;
    bottom:-20px;right:-20px;
    width:80px;height:80px;
    border-radius:50%;
    opacity:.06;
}
.card-apply .card-corner{background:var(--navy)}
.card-status .card-corner{background:var(--green-mid)}

/* ── Animate in ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.portal-header{animation:fadeUp .5s ease both}
.portal-card:nth-child(1){animation:fadeUp .5s .1s ease both}
.portal-card:nth-child(2){animation:fadeUp .5s .2s ease both}
.info-strip{animation:fadeUp .5s .3s ease both}
</style>
</head>
<body>

<!-- Topbar -->
<header class="topbar">
    <a href="<?= APP_ROOT ?>/admissions/" class="topbar-brand">
        <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo">
        <div class="brand-text">
            <div class="brand-name">KIMC Eldoret Campus</div>
            <div class="brand-sub">Admissions Portal</div>
        </div>
    </a>
    <div class="topbar-year"><?= htmlspecialchars($nextIntake['label']) ?> Intake</div>
</header>

<main>

    <!-- Header -->
    <div class="portal-header">
        <div class="institute-badge">
            🎓 <span>Kenya Institute of Mass Communication</span>
        </div>
        <h1 class="portal-title">
            Eldoret Campus<br><em>Admissions Portal</em>
        </h1>
        <p class="portal-subtitle">
            Apply for your programme or track the progress of an existing application.
            All admissions are processed through this portal.
        </p>
        <div class="intake-pill">
            Next intake: <?= htmlspecialchars($nextIntake['label']) ?>
        </div>
    </div>

    <!-- Two main cards -->
    <div class="cards-row">

        <!-- Apply card -->
        <a href="<?= APP_ROOT ?>/admissions/apply.php" class="portal-card card-apply">
            <div class="card-corner"></div>
            <div class="card-icon">📝</div>
            <div class="card-tag">New Application</div>
            <div class="card-title">Apply Now</div>
            <p class="card-desc">
                First time here? Complete the online application form to apply for a programme at KIMC Eldoret Campus. Takes about 10–15 minutes.
            </p>
            <div class="card-cta">
                Start Application <span class="card-cta-arrow">→</span>
            </div>
        </a>

        <!-- Status card -->
        <a href="<?= APP_ROOT ?>/admissions/status.php" class="portal-card card-status">
            <div class="card-corner"></div>
            <div class="card-icon">🔍</div>
            <div class="card-tag">Track Progress</div>
            <div class="card-title">Application Status</div>
            <p class="card-desc">
                Already applied? Log in with your reference number and date of birth to view your current admission status.
            </p>
            <div class="card-cta">
                Check My Status <span class="card-cta-arrow">→</span>
            </div>
        </a>

    </div>

    <!-- Info strip -->
    <div class="info-strip">
        <div class="info-item">
            📋 <span>Have your <strong>KCSE & KCPE</strong> certificates ready</span>
        </div>
        <div class="info-divider"></div>
        <div class="info-item">
            🪪 <span>National ID or <strong>Birth Certificate</strong> required</span>
        </div>
        <div class="info-divider"></div>
        <div class="info-item">
            📁 <span>All documents as <strong>PDF · max 5 MB</strong></span>
        </div>
    </div>

</main>

<footer>
    &copy; <?= date('Y') ?> Kenya Institute of Mass Communication — Eldoret Campus &nbsp;·&nbsp;
    Admissions queries: contact the campus office directly.
</footer>

</body>
</html>
