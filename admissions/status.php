<?php
// ============================================================
// admissions/status.php  — PUBLIC PAGE (no login required)
// Applicant-facing status tracker
// Accessed two ways:
//   1. Immediately after submission: ?ref=KMC-2026-00001&dob=2000-01-15
//      (pre-loaded, ref + dob passed via redirect from apply.php)
//   2. Manual lookup: applicant types their ref no + date of birth
// ============================================================
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 0); error_reporting(0);

$app        = null;
$lookupErr  = '';
$justSubmitted = false;

// ── POST: manual lookup form ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ref = strtoupper(trim($_POST['reference_no'] ?? ''));
    $dob = trim($_POST['date_of_birth'] ?? '');

    if (!$ref || !$dob) {
        $lookupErr = 'Please enter both your Reference Number and Date of Birth.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT * FROM admissions WHERE reference_no = ? AND date_of_birth = ?");
            $stmt->execute([$ref, $dob]);
            $app  = $stmt->fetch();
            if (!$app) {
                $lookupErr = 'No application found. Please double-check your reference number and date of birth.';
            }
        } catch (Throwable $e) {
            error_log('Status lookup error: ' . $e->getMessage());
            $lookupErr = 'A system error occurred. Please try again later.';
        }
    }
}

// ── GET: pre-loaded from redirect (just submitted) ────────
if (!$app && $_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['ref']) && !empty($_GET['dob'])) {
    $ref = strtoupper(trim($_GET['ref']));
    $dob = trim($_GET['dob']);
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM admissions WHERE reference_no = ? AND date_of_birth = ?");
        $stmt->execute([$ref, $dob]);
        $app  = $stmt->fetch();
        if ($app) {
            $justSubmitted = true;
        }
    } catch (Throwable $e) {
        // silently fail — page will show lookup form
    }
}

$statusDocs = [];
if ($app) {
    try {
        $docStmt = getDB()->prepare('SELECT * FROM admission_documents WHERE admission_id = ? ORDER BY doc_id');
        $docStmt->execute([$app['admission_id']]);
        $statusDocs = $docStmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Status documents lookup error: ' . $e->getMessage());
    }
}

// ── Status config ─────────────────────────────────────────
function statusConfig(string $status): array {
    return match($status) {
        'pending'     => [
            'icon'    => '🕐',
            'label'   => 'Pending Review',
            'color'   => '#b45309',
            'bg'      => 'rgba(217,119,6,.08)',
            'border'  => 'rgba(217,119,6,.25)',
            'heading' => 'Your application is under review',
            'body'    => 'Our admissions team has received your application and is currently reviewing your documents. You will be notified of any updates.',
        ],
        'shortlisted' => [
            'icon'    => '📋',
            'label'   => 'Shortlisted',
            'color'   => '#1d4ed8',
            'bg'      => 'rgba(37,99,235,.07)',
            'border'  => 'rgba(37,99,235,.25)',
            'heading' => 'You have been shortlisted!',
            'body'    => 'Congratulations — your application has been shortlisted. The final admission decision is currently being processed. Please check back soon.',
        ],
        'admitted'    => [
            'icon'    => '🎉',
            'label'   => 'Admitted',
            'color'   => '#15803d',
            'bg'      => 'rgba(22,163,74,.07)',
            'border'  => 'rgba(22,163,74,.25)',
            'heading' => 'You have been admitted!',
            'body'    => 'Congratulations! You have been officially admitted to KIMC Eldoret Campus. Please report to the campus admissions office to complete your enrolment.',
        ],
        'rejected'    => [
            'icon'    => '❌',
            'label'   => 'Not Admitted',
            'color'   => '#dc2626',
            'bg'      => 'rgba(220,38,38,.07)',
            'border'  => 'rgba(220,38,38,.2)',
            'heading' => 'Application Unsuccessful',
            'body'    => 'We regret to inform you that your application was not successful at this time. Please see the feedback below from our admissions team.',
        ],
        default       => [
            'icon'    => '📄',
            'label'   => ucfirst($status),
            'color'   => '#6b7280',
            'bg'      => 'rgba(107,114,128,.07)',
            'border'  => 'rgba(107,114,128,.2)',
            'heading' => 'Application Status',
            'body'    => '',
        ],
    };
}

// ── Steps timeline ────────────────────────────────────────
function statusStep(string $current): array {
    $order  = ['pending', 'shortlisted', 'admitted'];
    $curIdx = array_search($current, $order);
    // Rejected is a terminal branch — handled separately
    return [
        ['key' => 'pending',     'label' => 'Received',   'sub' => 'Application submitted'],
        ['key' => 'shortlisted', 'label' => 'Shortlisted','sub' => 'Documents reviewed'],
        ['key' => 'admitted',    'label' => 'Admitted',   'sub' => 'Final decision'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Application Status — KIMC Eldoret Campus</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --navy:#0d1f3c; --navy-mid:#1a3a6b;
    --green:#065f46; --green-mid:#059669; --green-light:#34d399;
    --cream:#fafaf9; --white:#fff;
    --ink:#1a1a2e; --muted:#6b7280;
    --border:#e5e7eb;
    --radius:12px;
    --shadow:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.06);
}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--ink);min-height:100vh}

/* ── Topbar ── */
.topbar{background:var(--navy);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-brand{display:flex;align-items:center;gap:12px;text-decoration:none}
.topbar-brand img{height:36px;width:auto;object-fit:contain}
.brand-name{font-size:16px;font-weight:700;color:#fff;letter-spacing:-.3px}
.brand-sub{font-size:10px;color:rgba(255,255,255,.5);letter-spacing:.5px;text-transform:uppercase}

/* ── Page shell ── */
.page-wrap{max-width:640px;margin:0 auto;padding:36px 20px 80px}

/* ── Lookup card ── */
.card{background:#fff;border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);overflow:hidden}
.card-hd{padding:22px 28px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.card-hd-icon{font-size:26px}
.card-hd-title{font-size:17px;font-weight:700}
.card-hd-sub{font-size:12px;color:var(--muted);margin-top:2px}
.card-body{padding:24px 28px}

/* ── Form ── */
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.fg label{font-size:12px;font-weight:600;color:var(--ink)}
.fg label .req{color:#dc2626;margin-left:2px}
.fg input{padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;
    font-family:inherit;font-size:14px;color:var(--ink);background:#fff;
    transition:border-color .15s,box-shadow .15s;width:100%}
.fg input:focus{outline:none;border-color:var(--green-mid);box-shadow:0 0 0 3px rgba(5,150,105,.1)}
.btn{padding:12px 24px;border-radius:10px;font-family:inherit;font-size:14px;font-weight:600;
    cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:8px;justify-content:center}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green-mid));color:#fff;width:100%}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(5,150,105,.3)}
.btn-ghost{background:#fff;color:var(--ink);border:1.5px solid var(--border);font-size:13px;padding:9px 18px;width:auto;text-decoration:none}
.btn-ghost:hover{background:var(--cream)}
.alert-err{background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.2);color:#991b1b;
    padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:20px}

/* ── Status badge ── */
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;
    border-radius:20px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}

/* ── Status card ── */
.status-banner{padding:24px 28px;border-radius:0;border-bottom:1px solid var(--border)}
.status-banner-icon{font-size:40px;margin-bottom:10px}
.status-banner-heading{font-size:20px;font-weight:700;margin-bottom:6px}
.status-banner-body{font-size:13px;color:var(--muted);line-height:1.7}

/* ── Timeline ── */
.timeline{display:flex;align-items:flex-start;gap:0;padding:24px 28px;border-bottom:1px solid var(--border);overflow-x:auto}
.tl-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;min-width:80px}
.tl-step:not(:last-child)::after{
    content:'';position:absolute;top:14px;left:calc(50% + 14px);
    width:calc(100% - 28px);height:2px;background:var(--border);z-index:0
}
.tl-step.done:not(:last-child)::after{background:var(--green-mid)}
.tl-dot{width:28px;height:28px;border-radius:50%;border:2px solid var(--border);
    background:#fff;display:flex;align-items:center;justify-content:center;
    font-size:12px;font-weight:700;z-index:1;position:relative;transition:all .3s}
.tl-step.done .tl-dot{background:var(--green-mid);border-color:var(--green-mid);color:#fff}
.tl-step.active .tl-dot{background:var(--navy);border-color:var(--navy);color:#fff;
    box-shadow:0 0 0 4px rgba(13,31,60,.12)}
.tl-step.rejected-step .tl-dot{background:#dc2626;border-color:#dc2626;color:#fff}
.tl-label{font-size:11px;font-weight:700;margin-top:7px;text-align:center;color:var(--muted)}
.tl-step.done .tl-label,.tl-step.active .tl-label{color:var(--ink)}
.tl-sub{font-size:10px;color:var(--muted);text-align:center;margin-top:2px}

/* ── Detail rows ── */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:22px 28px;border-bottom:1px solid var(--border)}
@media(max-width:480px){.detail-grid{grid-template-columns:1fr}}
.detail-item{}
.detail-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:3px}
.detail-value{font-size:13px;font-weight:600;color:var(--ink)}

/* ── Rejection reason ── */
.rejection-box{margin:0;padding:20px 28px;background:rgba(220,38,38,.04);border-bottom:1px solid rgba(220,38,38,.12)}
.rejection-box-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#dc2626;margin-bottom:8px}
.rejection-box-text{font-size:13px;line-height:1.75;color:#7f1d1d}

/* ── Just submitted banner ── */
.submitted-note{background:rgba(5,150,105,.07);border:1px solid rgba(5,150,105,.2);
    border-radius:12px;padding:16px 20px;margin-bottom:24px;font-size:13px;
    display:flex;gap:12px;align-items:flex-start;line-height:1.6}
.submitted-note-icon{font-size:22px;flex-shrink:0;margin-top:1px}

/* ── Login hint ── */
.login-hint{background:#fff;border:1px solid var(--border);border-radius:12px;
    padding:18px 22px;margin-top:20px;display:flex;gap:12px;align-items:flex-start;font-size:13px}
.login-hint-icon{font-size:20px;flex-shrink:0;margin-top:1px}
.login-hint strong{display:block;margin-bottom:3px;font-size:13px}
.login-hint span{color:var(--muted)}
.login-hint code{font-family:'Space Mono',monospace;background:rgba(5,150,105,.08);
    padding:1px 6px;border-radius:5px;font-size:12px;color:var(--green)}

/* ── Footer actions ── */
.card-foot{padding:18px 28px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.documents-panel{padding:20px 28px;border-bottom:1px solid var(--border)}
.document-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(229,231,235,.8);font-size:13px}
.document-row:last-child{border-bottom:none}
.document-name{font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.document-meta{font-size:11px;color:var(--muted);white-space:nowrap}

/* ── Hero strip ── */
.hero{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-mid) 60%,#1e4d8c 100%);
    padding:28px 24px;text-align:center;color:#fff}
.hero h1{font-size:22px;font-weight:700;margin-bottom:4px}
.hero p{font-size:13px;opacity:.65;margin:0 auto;max-width:440px}
</style>
</head>
<body>

<!-- Topbar -->
<header class="topbar">
    <a href="<?= APP_ROOT ?>/admissions/index.php" class="topbar-brand">
        <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo">
        <div>
            <div class="brand-name">KIMC Eldoret Campus</div>
            <div class="brand-sub">Admissions Portal</div>
        </div>
    </a>
    <a href="<?= APP_ROOT ?>/admissions/index.php" class="btn btn-ghost" style="font-size:12px;padding:7px 14px">← Admissions Portal</a>
</header>

<div class="hero">
    <h1>Application Status</h1>
    <p>Track your KIMC Eldoret Campus admission in real time</p>
</div>

<div class="page-wrap">

<?php if ($app): ?>

    <!-- ══════════════════════════════════════════
         STATUS VIEW
    ══════════════════════════════════════════ -->

    <?php
    $cfg    = statusConfig($app['status']);
    $steps  = statusStep($app['status']);
    $order  = ['pending','shortlisted','admitted'];
    $curIdx = array_search($app['status'], $order);
    $isRej  = $app['status'] === 'rejected';
    ?>

    <?php if ($justSubmitted): ?>
    <div class="submitted-note">
        <div class="submitted-note-icon">🎉</div>
        <div>
            <strong style="display:block;margin-bottom:3px">Application received successfully!</strong>
            Your application has been submitted and is now under review by the admissions team.
            To check your status again in the future, use your <strong>Reference Number</strong> and
            <strong>Date of Birth</strong> on this page.
        </div>
    </div>
    <?php endif; ?>

    <div class="card">

        <!-- Status banner -->
        <div class="status-banner" style="background:<?= $cfg['bg'] ?>;border-bottom:1px solid <?= $cfg['border'] ?>">
            <div class="status-banner-icon"><?= $cfg['icon'] ?></div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px">
                <div class="status-banner-heading"><?= htmlspecialchars($cfg['heading']) ?></div>
                <span class="status-pill" style="background:<?= $cfg['bg'] ?>;border:1px solid <?= $cfg['border'] ?>;color:<?= $cfg['color'] ?>">
                    <?= $cfg['icon'] ?> <?= htmlspecialchars($cfg['label']) ?>
                </span>
            </div>
            <p class="status-banner-body"><?= htmlspecialchars($cfg['body']) ?></p>
        </div>

        <!-- Timeline (hidden for rejected) -->
        <?php if (!$isRej): ?>
        <div class="timeline">
            <?php foreach ($steps as $i => $step):
                $stepIdx = array_search($step['key'], $order);
                $isDone  = $curIdx !== false && $stepIdx < $curIdx;
                $isAct   = $step['key'] === $app['status'];
                $cls     = $isDone ? 'done' : ($isAct ? 'active' : '');
            ?>
            <div class="tl-step <?= $cls ?>">
                <div class="tl-dot"><?= $isDone ? '✓' : ($isAct ? ($i+1) : ($i+1)) ?></div>
                <div class="tl-label"><?= htmlspecialchars($step['label']) ?></div>
                <div class="tl-sub"><?= htmlspecialchars($step['sub']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <!-- Rejected mini bar -->
        <div class="timeline">
            <div class="tl-step done">
                <div class="tl-dot">✓</div>
                <div class="tl-label">Received</div>
                <div class="tl-sub">Submitted</div>
            </div>
            <div class="tl-step done">
                <div class="tl-dot">✓</div>
                <div class="tl-label">Reviewed</div>
                <div class="tl-sub">Documents checked</div>
            </div>
            <div class="tl-step rejected-step">
                <div class="tl-dot">✕</div>
                <div class="tl-label">Not Admitted</div>
                <div class="tl-sub">Final decision</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Rejection reason -->
        <?php if ($isRej && !empty($app['rejection_reason'])): ?>
        <div class="rejection-box">
            <div class="rejection-box-title">📋 Feedback from Admissions Team</div>
            <div class="rejection-box-text"><?= nl2br(htmlspecialchars($app['rejection_reason'])) ?></div>
        </div>
        <?php endif; ?>

        <!-- Application details -->
        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-label">Reference Number</div>
                <div class="detail-value" style="font-family:'Space Mono',monospace;font-size:14px;color:var(--green)">
                    <?= htmlspecialchars($app['reference_no']) ?>
                </div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Applicant Name</div>
                <div class="detail-value">
                    <?= htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['surname']) ?>
                </div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Programme</div>
                <div class="detail-value"><?= htmlspecialchars($app['programme_name']) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Programme Type</div>
                <div class="detail-value" style="text-transform:capitalize"><?= htmlspecialchars($app['programme_type']) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Submitted</div>
                <div class="detail-value"><?= date('d M Y', strtotime($app['submitted_at'])) ?></div>
            </div>
            <?php if ($app['reviewed_at']): ?>
            <div class="detail-item">
                <div class="detail-label">Last Updated</div>
                <div class="detail-value"><?= date('d M Y', strtotime($app['reviewed_at'])) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="documents-panel">
            <div class="detail-label" style="margin-bottom:8px">Uploaded Documents</div>
            <?php if (empty($statusDocs)): ?>
            <div style="font-size:13px;color:var(--muted)">No documents are currently recorded.</div>
            <?php else: ?>
                <?php foreach ($statusDocs as $document): ?>
                <div class="document-row">
                    <span aria-hidden="true">📄</span>
                    <span class="document-name" title="<?= htmlspecialchars($document['original_name']) ?>"><?= htmlspecialchars($document['original_name']) ?></span>
                    <span class="document-meta"><?= htmlspecialchars(str_replace('_', ' ', $document['doc_type'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card-foot">
            <span style="font-size:12px;color:var(--muted)">Need to correct information or a document?</span>
            <a href="<?= APP_ROOT ?>/admissions/apply.php?update=1&amp;ref=<?= urlencode($app['reference_no']) ?>&amp;dob=<?= urlencode($app['date_of_birth']) ?>" class="btn btn-primary" style="width:auto;text-decoration:none">Update Existing Application →</a>
        </div>

<?php else: ?>

    <!-- ══════════════════════════════════════════
         LOOKUP FORM
    ══════════════════════════════════════════ -->

    <?php if ($lookupErr): ?>
    <div class="alert-err">⚠️ <?= htmlspecialchars($lookupErr) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-hd">
            <div class="card-hd-icon">🔍</div>
            <div>
                <div class="card-hd-title">Check Application Status</div>
                <div class="card-hd-sub">Enter the details below to view your admission progress</div>
            </div>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="fg">
                    <label>Reference Number <span class="req">*</span></label>
                    <input type="text" name="reference_no" required
                           placeholder="e.g. KIMC-2026-00000"
                           value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>"
                           style="font-family:'Space Mono',monospace;letter-spacing:.5px;text-transform:uppercase">
                </div>
                <div class="fg" style="margin-bottom:20px">
                    <label>Date of Birth <span class="req">*</span></label>
                    <input type="date" name="date_of_birth" required
                           value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary">View Application Status →</button>
            </form>
        </div>
    </div>

    <div class="login-hint" style="margin-top:20px">
        <div class="login-hint-icon">ℹ️</div>
        <div>
            <strong>Where do I find my Reference Number?</strong>
            <span>
                Your reference number was shown on screen
                immediately after you submitted your application. It is in the format
                <code>KIMC-YYYY-NNNNN</code>.
            </span>
        </div>
    </div>

<?php endif; ?>

</div><!-- /.page-wrap -->
</body>
</html>
