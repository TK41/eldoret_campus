<?php
// ============================================================
// admissions/application.php  — ADMIN view of one application
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Application Detail';
$activePage = 'adm_dashboard';
$db = getDB();

// Provisioning helper: creates fee_students + users entries and links back to admissions
function provision_student($db, $admissionId, $studentId, $fullName, $groupId, $programme, $email = null, $phone = null) {
    try {
        $db->beginTransaction();

        // Lookup group fees
        $g = $db->prepare("SELECT total_fees FROM fee_groups WHERE group_id=?");
        $g->execute([$groupId]);
        $g = $g->fetch();
        $totalFees = $g ? $g['total_fees'] : 0;

        // Insert to fee_students
        $insFs = $db->prepare("INSERT INTO fee_students (student_id, full_name, programme, group_id, total_fees, is_active) VALUES (?,?,?,?,?,1)");
        $insFs->execute([$studentId, $fullName, $programme, $groupId, $totalFees]);
        $feeStudentId = $db->lastInsertId();

        // Determine tier based on programme
        $tierMap = ['certificate' => 1, 'diploma' => 2, 'postgraduate' => 3];
        $tierId = $tierMap[$programme] ?? 1;

        // Insert to users (inventory)
        $insU = $db->prepare("INSERT INTO users (student_id, full_name, email, phone, tier_id, is_active) VALUES (?,?,?,?,?,1)");
        $insU->execute([$studentId, $fullName, $email ?: ($studentId.'@example.local'), $phone ?: '', $tierId]);
        $userId = $db->lastInsertId();

        // Update admissions row
        $upd = $db->prepare("UPDATE admissions SET provisioned=1, provisioned_at=NOW(), fee_student_id=?, inventory_user_id=? WHERE admission_id=?");
        $upd->execute([$feeStudentId, $userId, $admissionId]);

        $db->commit();
        return ['success' => true, 'fee_student_id' => $feeStudentId, 'user_id' => $userId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_ROOT . '/admissions/dashboard.php'); exit; }

// Fetch current application for confirm actions
$curStmt = $db->prepare("SELECT * FROM admissions WHERE admission_id=?");
$curStmt->execute([$id]);
$curApp = $curStmt->fetch();

// Handle direct confirm_provision POST (second-step of the admit flow)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_provision'])) {
    verifyCsrf();
    $studentId = trim($_POST['student_id'] ?? '');
    $groupId   = intval($_POST['fee_group_id'] ?? 0);
    $notes     = trim($_POST['officer_notes'] ?? '');
    if (!$studentId || !$groupId) { setFlash('error','Student ID and fee group are required.'); header('Location: '.APP_ROOT.'/admissions/application.php?id='.$id); exit; }
    $fullName = trim(($curApp['surname'] . ' ' . $curApp['first_name'] . ' ' . ($curApp['middle_name'] ?? '')));
    $res = provision_student($db, $id, $studentId, $fullName, $groupId, $curApp['programme_type'], $curApp['email'] ?? null, $curApp['mobile_no'] ?? null);
    if ($res['success']) {
        $db->prepare("UPDATE admissions SET status='admitted', officer_notes=?, rejection_reason=NULL, reviewed_at=NOW(), reviewed_by=? WHERE admission_id=?")->execute([$notes ?: null, $_SESSION['admin_id'], $id]);
        setFlash('success','Student provisioned successfully.');
    } else {
        setFlash('error','Provision failed: '.($res['error']??'Unknown'));
    }
    header('Location: '.APP_ROOT.'/admissions/application.php?id='.$id); exit;
}

// Handle direct confirm_unprovision POST (second-step of demotion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_unprovision'])) {
    verifyCsrf();
    try {
        $db->beginTransaction();
        if (!empty($curApp['fee_student_id'])) {
            $db->prepare("DELETE FROM fee_students WHERE fee_student_id=?")->execute([$curApp['fee_student_id']]);
        }
        if (!empty($curApp['inventory_user_id'])) {
            $db->prepare("DELETE FROM users WHERE user_id=?")->execute([$curApp['inventory_user_id']]);
        }
        $db->prepare("UPDATE admissions SET provisioned=0, provisioned_at=NULL, fee_student_id=NULL, inventory_user_id=NULL WHERE admission_id=?")->execute([$id]);
        $db->commit();
        setFlash('success','Student unprovisioned.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error','Unprovision failed: '.$e->getMessage());
    }
    header('Location: '.APP_ROOT.'/admissions/application.php?id='.$id); exit;
}

// Intercept initial 'admit' / 'demote' submissions to present a confirmation step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    verifyCsrf();
    $newStatus = trim($_POST['status'] ?? '');
    $notes     = trim($_POST['officer_notes'] ?? '');

    // Admit: redirect to a confirmation page (GET) showing fee groups for selection
    if ($newStatus === 'admitted') {
        if (!empty($curApp['provisioned'])) { setFlash('error','Student already provisioned.'); header('Location: '.APP_ROOT.'/admissions/application.php?id='.$id); exit; }
        $gstmt = $db->prepare("SELECT group_id,name,academic_year FROM fee_groups WHERE programme=? ORDER BY group_id");
        $gstmt->execute([$curApp['programme_type']]);
        $_SESSION['provision_preview'] = ['groups' => $gstmt->fetchAll(), 'notes' => $notes];
        header('Location: '.APP_ROOT.'/admissions/application.php?id='.$id.'&show_provision=1'); exit;
    }

    // Demote from admitted: ask for confirmation before unprovision
    if ($curApp['status'] === 'admitted' && $newStatus !== 'admitted' && !empty($curApp['provisioned'])) {
        $_SESSION['unprov_preview'] = ['newStatus' => $newStatus, 'notes' => $notes];
        header('Location: '.APP_ROOT.'/admissions/application.php?id='.$id.'&confirm_unprovision=1'); exit;
    }

    // Otherwise fall back to the normal status update flow below
}

// ── Status update (superadmin only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    verifyCsrf();
    $newStatus       = trim($_POST['status'] ?? '');
    $notes           = trim($_POST['officer_notes'] ?? '');
    $rejectionReason = trim($_POST['rejection_reason'] ?? '');
    $allowed         = ['pending','shortlisted','admitted','rejected'];
    if (in_array($newStatus, $allowed)) {
        // Only persist rejection_reason when actually rejecting; clear it on any other status
        $reasonToSave = ($newStatus === 'rejected') ? ($rejectionReason ?: null) : null;
        $db->prepare("
            UPDATE admissions
            SET status=?, officer_notes=?, rejection_reason=?, reviewed_at=NOW(), reviewed_by=?
            WHERE admission_id=?
        ")->execute([$newStatus, $notes ?: null, $reasonToSave, $_SESSION['admin_id'], $id]);
        setFlash('success', 'Application status updated to: ' . ucfirst($newStatus));
        header('Location: ' . APP_ROOT . '/admissions/application.php?id=' . $id); exit;
    }
}

$app = $db->prepare("SELECT * FROM admissions WHERE admission_id=?");
$app->execute([$id]);
$app = $app->fetch();
if (!$app) { header('Location: ' . APP_ROOT . '/admissions/dashboard.php'); exit; }

$docs = $db->prepare("SELECT * FROM admission_documents WHERE admission_id=? ORDER BY doc_id");
$docs->execute([$id]);
$docs = $docs->fetchAll();

$kcseSubjects = json_decode($app['kcse_subjects'] ?? '[]', true) ?: [];
$prevInst     = json_decode($app['prev_institutions'] ?? '[]', true) ?: [];

// Previous / next application navigation
$nav = $db->query("SELECT admission_id FROM admissions ORDER BY submitted_at DESC")->fetchAll(PDO::FETCH_COLUMN);
$pos = array_search($id, $nav);
$prevId = $pos > 0 ? $nav[$pos-1] : null;
$nextId = $pos < count($nav)-1 ? $nav[$pos+1] : null;

$admin = getCurrentAdmin();
$flash = getFlash();

$docLabels = [
    'application_form' => '📄 Application Form',
    'kcse_cert'        => '🎓 KCSE Certificate',
    'kcpe_cert'        => '📜 KCPE Certificate',
    'birth_cert'       => '👶 Birth Certificate',
    'national_id'      => '🪪 National ID',
    'passport_photo'   => '🖼 Passport Photo',
    'mpesa_proof'      => '💚 M-Pesa Proof',
    'other'            => '📎 Other',
];

include __DIR__ . '/partials/adm_header.php';

// ── Status update (superadmin only) ──

?>

<style>
.app-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start}
@media(max-width:900px){.app-grid{grid-template-columns:1fr}}

.info-section{margin-bottom:20px}
.info-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;
    color:var(--text-muted);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.info-row{display:grid;grid-template-columns:140px 1fr;gap:8px;padding:6px 0;font-size:13px;
    border-bottom:1px solid rgba(0,0,0,.04)}
.info-row:last-child{border-bottom:none}
.info-label{color:var(--text-muted);font-weight:500}
.info-value{color:var(--text-primary);font-weight:500}

.status-pill{display:inline-block;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.sp-pending    {background:rgba(217,119,6,.1);  color:#b45309}
.sp-shortlisted{background:rgba(37,99,235,.1);  color:#1d4ed8}
.sp-admitted   {background:rgba(22,163,74,.1);  color:#15803d}
.sp-rejected   {background:rgba(220,38,38,.1);  color:#dc2626}

.doc-card{display:flex;align-items:center;gap:10px;padding:10px 12px;
    border:1px solid var(--border);border-radius:9px;margin-bottom:8px;transition:border-color .15s}
.doc-card:hover{border-color:#059669}
.doc-icon{font-size:22px;flex-shrink:0}
.doc-info{flex:1;min-width:0}
.doc-name{font-size:12px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.doc-meta{font-size:11px;color:var(--text-muted)}
.doc-view-btn{font-size:11px;padding:5px 10px;border-radius:6px;background:rgba(5,150,105,.08);
    color:#059669;border:1px solid rgba(5,150,105,.2);text-decoration:none;white-space:nowrap;font-weight:600;transition:background .15s}
.doc-view-btn:hover{background:rgba(5,150,105,.15)}

.kcse-mini{width:100%;border-collapse:collapse;font-size:12px}
.kcse-mini th{padding:5px 8px;text-align:left;color:var(--text-muted);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--border)}
.kcse-mini td{padding:5px 8px;border-bottom:1px solid var(--border)}
.kcse-mini tr:last-child td{border-bottom:none}

.sidebar-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:16px}
.sidebar-card-hd{padding:14px 16px;border-bottom:1px solid var(--border);font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px}
.sidebar-card-body{padding:14px 16px}
</style>

<!-- Breadcrumb + nav -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div style="font-size:12px;color:var(--text-muted)">
        <a href="<?= APP_ROOT ?>/admissions/dashboard.php" style="color:var(--text-muted)">Applications</a>
        › <strong><?= htmlspecialchars($app['reference_no']) ?></strong>
    </div>
    <div style="display:flex;gap:8px">
        <?php if ($prevId): ?>
        <a href="?id=<?= $prevId ?>" class="btn btn-ghost btn-sm">← Prev</a>
        <?php endif; ?>
        <?php if ($nextId): ?>
        <a href="?id=<?= $nextId ?>" class="btn btn-ghost btn-sm">Next →</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:16px">
    <?= htmlspecialchars($flash['message']) ?>
    <button onclick="this.parentElement.remove()" style="float:right;background:none;border:none;cursor:pointer;font-size:16px">×</button>
</div>
<?php endif; ?>

<!-- Application header -->
<div style="background:var(--surface);border:1px solid var(--border);border-radius:13px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div style="display:flex;align-items:center;gap:16px">
        <div style="width:52px;height:52px;background:linear-gradient(135deg,#022c22,#065f46);color:#6ee7b7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;flex-shrink:0">
            <?= strtoupper(substr($app['first_name'],0,1)) ?>
        </div>
        <div>
            <div style="font-size:19px;font-weight:700;color:var(--text-primary)">
                <?= htmlspecialchars($app['surname'].', '.$app['first_name'].($app['middle_name']?' '.$app['middle_name']:'')) ?>
            </div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:3px">
                <code><?= htmlspecialchars($app['reference_no']) ?></code>
                &nbsp;·&nbsp; <?= htmlspecialchars($app['programme_name']) ?>
                &nbsp;·&nbsp; Submitted <?= date('d M Y, g:i a', strtotime($app['submitted_at'])) ?>
            </div>
        </div>
    </div>
    <span class="status-pill sp-<?= $app['status'] ?>" style="font-size:13px;padding:6px 16px">
        <?= ucfirst($app['status']) ?>
    </span>
</div>

<div class="app-grid">
<!-- ── LEFT: full application details ── -->
<div>
    <div class="card">
        <div class="card-body" style="padding:20px 24px">

            <!-- Programme -->
            <div class="info-section">
                <div class="info-section-title">Programme</div>
                <div class="info-row"><span class="info-label">Type</span><span class="info-value" style="text-transform:capitalize"><?= $app['programme_type'] ?></span></div>
                <div class="info-row"><span class="info-label">Programme</span><span class="info-value"><?= htmlspecialchars($app['programme_name']) ?></span></div>
                <div class="info-row"><span class="info-label">Study Mode</span><span class="info-value" style="text-transform:capitalize"><?= str_replace('_',' ',$app['study_mode']) ?></span></div>
            </div>

            <!-- Personal -->
            <div class="info-section">
                <div class="info-section-title">Personal Information</div>
                <div class="info-row"><span class="info-label">Full Name</span><span class="info-value"><?= htmlspecialchars($app['surname'].', '.$app['first_name'].($app['middle_name']?' '.$app['middle_name']:'')) ?></span></div>
                <div class="info-row"><span class="info-label">Date of Birth</span><span class="info-value"><?= $app['date_of_birth'] ? date('d M Y', strtotime($app['date_of_birth'])) : '—' ?></span></div>
                <div class="info-row"><span class="info-label">Gender</span><span class="info-value" style="text-transform:capitalize"><?= $app['gender'] ?? '—' ?></span></div>
                <div class="info-row"><span class="info-label">Nationality</span><span class="info-value"><?= htmlspecialchars($app['nationality'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">National ID</span><span class="info-value"><code><?= htmlspecialchars($app['national_id'] ?? '—') ?></code></span></div>
                <div class="info-row"><span class="info-label">Mobile</span><span class="info-value"><strong><?= htmlspecialchars($app['mobile_no']) ?></strong></span></div>
                <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($app['email'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Religion</span><span class="info-value"><?= htmlspecialchars($app['religion'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">M-Pesa Ref</span><span class="info-value"><code><?= htmlspecialchars($app['mpesa_transaction_no'] ?? '—') ?></code></span></div>
            </div>

            <!-- Address -->
            <div class="info-section">
                <div class="info-section-title">Address</div>
                <div class="info-row"><span class="info-label">P.O. Box</span><span class="info-value"><?= htmlspecialchars($app['po_box'] ?? '—') ?> – <?= htmlspecialchars($app['postal_code'] ?? '') ?></span></div>
                <div class="info-row"><span class="info-label">Town</span><span class="info-value"><?= htmlspecialchars($app['city_town'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">County</span><span class="info-value"><?= htmlspecialchars($app['county'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Sub-County</span><span class="info-value"><?= htmlspecialchars($app['sub_county'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Country</span><span class="info-value"><?= htmlspecialchars($app['country_of_residence'] ?? '—') ?></span></div>
            </div>

            <!-- Guardian -->
            <div class="info-section">
                <div class="info-section-title">Parent / Guardian</div>
                <div class="info-row"><span class="info-label">Father/Guardian</span><span class="info-value"><?= htmlspecialchars($app['father_name'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Father Tel</span><span class="info-value"><?= htmlspecialchars($app['father_tel'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Father Occupation</span><span class="info-value"><?= htmlspecialchars($app['father_occupation'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Mother/Guardian</span><span class="info-value"><?= htmlspecialchars($app['mother_name'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Mother Tel</span><span class="info-value"><?= htmlspecialchars($app['mother_tel'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Mother Occupation</span><span class="info-value"><?= htmlspecialchars($app['mother_occupation'] ?? '—') ?></span></div>
            </div>

            <!-- Education history -->
            <?php if (!empty($prevInst)): ?>
            <div class="info-section">
                <div class="info-section-title">Educational Background</div>
                <?php foreach ($prevInst as $i => $inst): ?>
                <div style="background:var(--bg,var(--cream,#f9fafb));border-radius:8px;padding:10px 12px;margin-bottom:8px;font-size:13px">
                    <strong><?= htmlspecialchars($inst['name'] ?? '—') ?></strong>
                    <div style="color:var(--text-muted);font-size:12px;margin-top:3px">
                        <?= htmlspecialchars($inst['from'] ?? '') ?> – <?= htmlspecialchars($inst['to'] ?? '') ?>
                        <?php if ($inst['cert'] ?? ''): ?> · <?= htmlspecialchars($inst['cert']) ?><?php endif; ?>
                        <?php if ($inst['grade'] ?? ''): ?> · Grade: <?= htmlspecialchars($inst['grade']) ?><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($app['expelled']): ?>
                <div style="background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.15);border-radius:8px;padding:10px 12px;font-size:13px;color:#991b1b;margin-top:8px">
                    ⚠️ <strong>Previously expelled/discontinued.</strong><br>
                    <span style="color:var(--text-muted)"><?= htmlspecialchars($app['expelled_details'] ?? '') ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- KCSE -->
            <div class="info-section">
                <div class="info-section-title">KCSE Results</div>
                <table class="kcse-mini">
                    <thead><tr><th>Subject</th><th>Grade</th></tr></thead>
                    <tbody>
                        <tr><td>English</td><td><strong><?= htmlspecialchars($app['kcse_english'] ?? '—') ?></strong></td></tr>
                        <tr><td>Kiswahili</td><td><strong><?= htmlspecialchars($app['kcse_kiswahili'] ?? '—') ?></strong></td></tr>
                        <?php foreach ($kcseSubjects as $ks): ?>
                        <tr><td><?= htmlspecialchars($ks['subject'] ?? '') ?></td><td><strong><?= htmlspecialchars($ks['grade'] ?? '') ?></strong></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Other -->
            <div class="info-section">
                <div class="info-section-title">Financing &amp; Additional</div>
                <div class="info-row"><span class="info-label">Sponsor</span><span class="info-value"><?= htmlspecialchars($app['sponsor_name'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Disability</span><span class="info-value"><?= $app['has_disability'] ? '✓ '.htmlspecialchars($app['disability_details'] ?? '') : 'None' ?></span></div>
                <div class="info-row"><span class="info-label">Accommodation</span><span class="info-value"><?= $app['needs_accommodation'] ? '✓ Requested' : 'Not requested' ?></span></div>
                <div class="info-row"><span class="info-label">Heard via</span><span class="info-value"><?= htmlspecialchars($app['heard_via'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">IP Address</span><span class="info-value"><code style="font-size:11px"><?= htmlspecialchars($app['ip_address'] ?? '—') ?></code></span></div>
            </div>

        </div>
    </div>
</div>

<!-- ── RIGHT: sidebar (docs + status) ── -->
<div>

    <!-- Uploaded Documents -->
    <div class="sidebar-card">
        <div class="sidebar-card-hd">📎 Uploaded Documents <span style="margin-left:auto;font-size:12px;font-weight:400;color:var(--text-muted)"><?= count($docs) ?> file<?= count($docs)!==1?'s':'' ?></span></div>
        <div class="sidebar-card-body">
            <?php if (empty($docs)): ?>
            <div style="color:var(--text-muted);font-size:13px">No documents uploaded.</div>
            <?php else: ?>
            <?php foreach ($docs as $d): ?>
            <div class="doc-card">
                <div class="doc-icon"><?= explode(' ',$docLabels[$d['doc_type']]??'📎')[0] ?></div>
                <div class="doc-info">
                    <div class="doc-name" title="<?= htmlspecialchars($d['original_name']) ?>"><?= htmlspecialchars($d['original_name']) ?></div>
                    <div class="doc-meta">
                        <?= isset($docLabels[$d['doc_type']]) ? ltrim(strstr($docLabels[$d['doc_type']],' ')) : $d['doc_type'] ?>
                        · <?= $d['file_size'] ? number_format($d['file_size']/1024,0).' KB' : '—' ?>
                    </div>
                </div>
                <a href="<?= APP_ROOT ?>/admissions/view_doc.php?doc_id=<?= $d['doc_id'] ?>"
                   target="_blank" class="doc-view-btn">View</a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Missing doc checklist -->
            <?php
            $uploadedTypes = array_column($docs, 'doc_type');
            $requiredTypes = ['application_form','kcse_cert','kcpe_cert'];
            $hasBirthOrId  = in_array('birth_cert',$uploadedTypes) || in_array('national_id',$uploadedTypes);
            $missing = [];
            foreach ($requiredTypes as $rt) { if (!in_array($rt,$uploadedTypes)) $missing[] = $docLabels[$rt]; }
            if (!$hasBirthOrId) $missing[] = '👶/🪪 Birth Cert or National ID';
            ?>
            <?php if (!empty($missing)): ?>
            <div style="margin-top:10px;background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.15);border-radius:8px;padding:10px 12px;font-size:12px;color:#991b1b">
                <strong>Missing documents:</strong>
                <ul style="margin-top:5px;padding-left:16px;line-height:1.9">
                    <?php foreach ($missing as $m): ?><li><?= $m ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <div style="margin-top:10px;background:rgba(22,163,74,.07);border:1px solid rgba(22,163,74,.2);border-radius:8px;padding:8px 12px;font-size:12px;color:#15803d">
                ✅ All required documents submitted
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status update -->
    <div class="sidebar-card">
        <div class="sidebar-card-hd">🔖 Application Status</div>
        <div class="sidebar-card-body">
            <div style="margin-bottom:12px">
                <span class="status-pill sp-<?= $app['status'] ?>" style="font-size:13px;padding:6px 16px">
                    <?= ucfirst($app['status']) ?>
                </span>
            </div>
            <?php if ($app['officer_notes']): ?>
            <div style="background:var(--bg,#f9fafb);border-radius:8px;padding:10px 12px;font-size:13px;color:var(--text-muted);margin-bottom:12px">
                <strong style="color:var(--text-primary)">Officer Notes (internal):</strong><br>
                <?= nl2br(htmlspecialchars($app['officer_notes'])) ?>
            </div>
            <?php endif; ?>
            <?php if ($app['status'] === 'rejected' && !empty($app['rejection_reason'])): ?>
            <div style="background:rgba(220,38,38,.05);border:1px solid rgba(220,38,38,.15);border-radius:8px;padding:10px 12px;font-size:13px;color:#991b1b;margin-bottom:12px">
                <strong style="display:block;margin-bottom:3px">❌ Rejection Reason (shown to applicant):</strong>
                <?= nl2br(htmlspecialchars($app['rejection_reason'])) ?>
            </div>
            <?php endif; ?>
            <?php if ($app['reviewed_at']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:12px">
                Last reviewed: <?= date('d M Y, g:i a', strtotime($app['reviewed_at'])) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($_GET['show_provision']) && !empty($_SESSION['provision_preview'])):
                $preview = $_SESSION['provision_preview']; ?>
                <div style="background:rgba(22,163,74,.06);border:1px solid rgba(22,163,74,.15);border-radius:8px;padding:12px;margin-bottom:12px">
                    <strong style="display:block;margin-bottom:8px">Confirm Provision Student</strong>
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px">You are about to provision this applicant into the Fees and Inventory systems. Select fee group and supply student ID.</div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="confirm_provision" value="1">
                        <div style="margin-bottom:8px">
                            <label style="font-size:12px;display:block;margin-bottom:4px">Student ID <span class="req">*</span></label>
                            <input type="text" name="student_id" placeholder="e.g. 12822/25" required style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-family:inherit">
                        </div>
                        <div style="margin-bottom:8px">
                            <label style="font-size:12px;display:block;margin-bottom:4px">Fee Group <span class="req">*</span></label>
                            <select name="fee_group_id" required style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px">
                                <?php foreach ($preview['groups'] as $pg): ?>
                                    <option value="<?= $pg['group_id'] ?>"><?= htmlspecialchars($pg['name']) ?><?= $pg['academic_year']? ' ('.htmlspecialchars($pg['academic_year']).')':'' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-bottom:8px">
                            <label style="font-size:12px;display:block;margin-bottom:4px">Officer Notes</label>
                            <textarea name="officer_notes" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px"><?= htmlspecialchars($preview['notes'] ?? '') ?></textarea>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="submit" class="btn btn-primary">Confirm & Provision</button>
                            <a href="<?= APP_ROOT ?>/admissions/application.php?id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php elseif (!empty($_GET['confirm_unprovision']) && !empty($_SESSION['unprov_preview'])):
                $up = $_SESSION['unprov_preview']; ?>
                <div style="background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.15);border-radius:8px;padding:12px;margin-bottom:12px">
                    <strong style="display:block;margin-bottom:8px">Confirm Unprovision</strong>
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px">This will remove the student record from Fees and Inventory. This action cannot be undone.</div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="confirm_unprovision" value="1">
                        <div style="display:flex;gap:8px">
                            <button type="submit" class="btn" style="background:linear-gradient(135deg,#b91c1c,#dc2626);color:#fff">Confirm Remove</button>
                            <a href="<?= APP_ROOT ?>/admissions/application.php?id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php else: ?>
            <form method="POST" id="status-update-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="update_status" value="1">
                <div style="margin-bottom:10px">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px">Update Status</label>
                    <select name="status" id="status-select"
                            onchange="toggleRejectionField()"
                            style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                        <?php foreach(['pending','shortlisted','admitted','rejected'] as $s): ?>
                        <option value="<?= $s ?>" <?= $app['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Rejection reason — visible only when status = rejected -->
                <div id="rejection-reason-wrap" style="margin-bottom:12px;display:<?= $app['status']==='rejected'?'block':'none' ?>">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;color:#dc2626">
                        ❌ Rejection Reason <span style="color:#dc2626">*</span>
                        <span style="font-weight:400;color:var(--text-muted);font-size:11px"> — visible to applicant</span>
                    </label>
                    <textarea name="rejection_reason" id="rejection-reason-field"
                              placeholder="e.g. The minimum entry requirement of KCSE Mean Grade D+ was not met. We encourage you to reapply once you meet the requirements."
                              style="width:100%;padding:9px 12px;border:1.5px solid rgba(220,38,38,.4);border-radius:8px;font-family:inherit;font-size:13px;background:rgba(220,38,38,.04);color:var(--text-primary);resize:vertical;min-height:90px"
                              ><?= htmlspecialchars($app['rejection_reason'] ?? '') ?></textarea>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                        This message is shown directly to the applicant on the status page. Be clear and professional.
                    </div>
                </div>

                <div style="margin-bottom:12px">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px">
                        Officer Notes
                        <span style="font-weight:400;color:var(--text-muted);font-size:11px"> — internal only</span>
                    </label>
                    <textarea name="officer_notes"
                              style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;background:var(--input-bg,var(--surface));color:var(--text-primary);resize:vertical;min-height:70px"
                              placeholder="Internal notes for the admissions team…"
                              ><?= htmlspecialchars($app['officer_notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%" id="status-submit-btn">Update Status</button>
            </form>
            <?php endif; ?>

            <script>
            function toggleRejectionField() {
                var sel  = document.getElementById('status-select');
                var wrap = document.getElementById('rejection-reason-wrap');
                var field = document.getElementById('rejection-reason-field');
                var btn  = document.getElementById('status-submit-btn');
                if (sel.value === 'rejected') {
                    wrap.style.display = 'block';
                    field.setAttribute('required','required');
                    btn.textContent = '❌ Mark as Rejected';
                    btn.style.background = 'linear-gradient(135deg,#b91c1c,#dc2626)';
                } else if (sel.value === 'admitted') {
                    wrap.style.display = 'none';
                    field.removeAttribute('required');
                    btn.textContent = '🎉 Mark as Admitted';
                    btn.style.background = 'linear-gradient(135deg,#15803d,#16a34a)';
                } else if (sel.value === 'shortlisted') {
                    wrap.style.display = 'none';
                    field.removeAttribute('required');
                    btn.textContent = '📋 Mark as Shortlisted';
                    btn.style.background = 'linear-gradient(135deg,#1d4ed8,#2563eb)';
                } else {
                    wrap.style.display = 'none';
                    field.removeAttribute('required');
                    btn.textContent = 'Update Status';
                    btn.style.background = '';
                }
            }
            // Run on page load to set correct button label
            toggleRejectionField();

            // Confirm before rejecting
            document.getElementById('status-update-form').addEventListener('submit', function(e) {
                var sel = document.getElementById('status-select');
                if (sel.value === 'rejected') {
                    var reason = document.getElementById('rejection-reason-field').value.trim();
                    if (!reason) {
                        e.preventDefault();
                        alert('Please provide a rejection reason. This is shown to the applicant.');
                        document.getElementById('rejection-reason-field').focus();
                        return;
                    }
                    if (!confirm('Are you sure you want to mark this application as REJECTED?\n\nThe applicant will see your rejection reason on their status page.')) {
                        e.preventDefault();
                    }
                } else if (sel.value === 'admitted') {
                    if (!confirm('Mark this application as ADMITTED?\n\nThe applicant will see a congratulations message on their status page.')) {
                        e.preventDefault();
                    }
                }
            });
            </script>
        </div>
    </div>

    <!-- Quick links -->
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= APP_ROOT ?>/admissions/dashboard.php" class="btn btn-ghost btn-sm">← All Applications</a>
        <?php if ($prevId): ?><a href="?id=<?= $prevId ?>" class="btn btn-ghost btn-sm">← Prev</a><?php endif; ?>
        <?php if ($nextId): ?><a href="?id=<?= $nextId ?>" class="btn btn-ghost btn-sm">Next →</a><?php endif; ?>
    </div>
</div>
</div><!-- /.app-grid -->

<?php include __DIR__ . '/partials/adm_footer.php'; ?>
