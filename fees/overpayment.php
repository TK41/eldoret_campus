<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Resolve Overpayment';
$activePage = 'fees_students';
$db = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_ROOT . '/fees/dashboard.php'); exit; }

$student = $db->prepare("
    SELECT s.*, CONCAT(g.name, IF(g.academic_year <> '', CONCAT(' (', g.academic_year, ')'), '')) AS group_name
    FROM fee_students s JOIN fee_groups g ON g.group_id=s.group_id
    WHERE s.fee_student_id=?
");
$student->execute([$id]); $student = $student->fetch();
if (!$student) { header('Location: ' . APP_ROOT . '/fees/dashboard.php'); exit; }

$payments = $db->prepare("
    SELECT p.*, a.full_name AS posted_by_name
    FROM fee_payments p JOIN admin_users a ON a.admin_id=p.posted_by
    WHERE p.fee_student_id=? ORDER BY p.date_paid ASC
");
$payments->execute([$id]); $payments = $payments->fetchAll();

$totalPaid = array_sum(array_column($payments,'amount'));
$excess    = $totalPaid - $student['total_fees'];

if ($excess <= 0) {
    setFlash('info','This student does not have an overpayment.');
    header('Location: ' . APP_ROOT . '/fees/student.php?id=' . $id); exit;
}

// Determine which payment modes are personal (refundable) vs external
$personalModes   = ['mpesa','bank','ecitizen'];
$externalModes   = ['helb','smis','nairobi_campus','receipted'];

// Work out how much of the excess came from personal vs external sources
// by going through payments in order and attributing the excess to the last payments
$paidPersonal = 0; $paidExternal = 0;
foreach ($payments as $p) {
    if (in_array($p['mode'], $personalModes)) $paidPersonal += $p['amount'];
    else $paidExternal += $p['amount'];
}
$canRefund  = min($excess, $paidPersonal > 0 ? $excess : 0);
$mustForward = $excess - $canRefund;

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $notes     = trim($_POST['notes'] ?? '');
    $nextGroup = intval($_POST['next_group_id'] ?? 0);

    if (!in_array($action, ['refund','forward'])) $errors[] = 'Please select an action.';
    if ($action === 'forward' && !$nextGroup)      $errors[] = 'Please select the semester/group to forward to.';

    if (empty($errors)) {
        if ($action === 'refund') {
            // Record a negative payment as a refund
            $db->prepare("
                INSERT INTO fee_payments (fee_student_id,amount,mode,reference,date_paid,notes,posted_by)
                VALUES (?,?,?,?,CURDATE(),?,?)
            ")->execute([$id, -$excess, 'other', 'REFUND-'.strtoupper(substr($student['student_id'],0,8)), 'REFUND: '.$notes, $_SESSION['admin_id']]);
            setFlash('success', 'Refund of KES '.number_format($excess).' recorded for '.$student['full_name'].'.');
        } else {
            // Forward: find or create the student record in the next group
            // Check if student exists in target group already
            $existing = $db->prepare("SELECT fee_student_id FROM fee_students WHERE student_id=? AND group_id=?");
            $existing->execute([$student['student_id'], $nextGroup]); $existing = $existing->fetch();

            if (!$existing) {
                $targetGroup = $db->prepare("SELECT * FROM fee_groups WHERE group_id=?");
                $targetGroup->execute([$nextGroup]); $targetGroup = $targetGroup->fetch();
                $db->prepare("INSERT INTO fee_students (student_id,full_name,programme,group_id,total_fees) VALUES (?,?,?,?,?)")
                   ->execute([$student['student_id'],$student['full_name'],$student['programme'],$nextGroup,$targetGroup['total_fees']]);
                $nextFsid = $db->lastInsertId();
            } else {
                $nextFsid = $existing['fee_student_id'];
            }
            // Post the excess as a payment in the next group
            $db->prepare("
                INSERT INTO fee_payments (fee_student_id,amount,mode,reference,date_paid,notes,posted_by)
                VALUES (?,?,?,?,CURDATE(),?,?)
            ")->execute([$nextFsid, $excess, 'other', 'FWD-'.strtoupper(substr($student['student_id'],0,8)).'-'.date('Ymd'), 'Forwarded from previous semester. '.$notes, $_SESSION['admin_id']]);
            // Reduce current student balance by recording negative
            $db->prepare("
                INSERT INTO fee_payments (fee_student_id,amount,mode,reference,date_paid,notes,posted_by)
                VALUES (?,?,?,?,CURDATE(),?,?)
            ")->execute([$id, -$excess, 'other', 'FWD-OUT-'.date('Ymd'), 'Forwarded KES '.number_format($excess).' to next semester. '.$notes, $_SESSION['admin_id']]);
            setFlash('success', 'KES '.number_format($excess).' forwarded to next semester for '.$student['full_name'].'.');
        }
        header('Location: ' . APP_ROOT . '/fees/student.php?id=' . $id); exit;
    }
}

$groups = $db->query("SELECT * FROM fee_groups WHERE group_id != {$student['group_id']} ORDER BY group_id")->fetchAll();
include __DIR__ . '/partials/header.php';
?>

<style>
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted);margin-bottom:7px}
.form-control{width:100%;padding:10px 13px;border-radius:8px;border:1.5px solid var(--border);background:var(--input-bg);color:var(--text-primary);font-family:inherit;font-size:14px;outline:none;box-sizing:border-box;transition:border-color .2s}
.form-control:focus{border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.1)}
.option-card{border:2px solid var(--border);border-radius:12px;padding:20px;cursor:pointer;transition:border-color .2s,background .2s;margin-bottom:12px}
.option-card:has(input:checked){border-color:#d97706;background:rgba(217,119,6,.04)}
.option-card.disabled{opacity:.5;cursor:not-allowed}
.option-card label{cursor:pointer;display:flex;gap:14px;align-items:flex-start}
.option-title{font-weight:700;font-size:14px;margin-bottom:4px}
.option-desc{font-size:13px;color:var(--text-muted);line-height:1.5}
.excess-box{background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.2);border-radius:12px;padding:20px;margin-bottom:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
</style>

<div style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
    <a href="<?= APP_ROOT ?>/fees/students.php" style="color:var(--text-muted)">Students</a> ›
    <a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $id ?>" style="color:var(--text-muted)"><?= htmlspecialchars($student['full_name']) ?></a> ›
    Resolve Overpayment
</div>

<div class="page-header">
    <div><h1 class="page-title">⚠️ Resolve Overpayment</h1><p class="page-subtitle"><?= htmlspecialchars($student['full_name']) ?> · <?= htmlspecialchars($student['student_id']) ?></p></div>
    <a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $id ?>" class="btn btn-ghost">← Back to Ledger</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><?php foreach($errors as $e): ?><div><?=htmlspecialchars($e)?></div><?php endforeach;?></div>
<?php endif; ?>

<!-- Excess summary -->
<div class="excess-box">
    <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:4px">Excess Amount</div>
        <div style="font-size:32px;font-weight:700;font-family:'Space Mono',monospace;color:#7c3aed">KES <?= number_format($excess) ?></div>
    </div>
    <div style="flex:1;font-size:13px;color:var(--text-muted);line-height:1.7">
        <div>Total fees: <strong>KES <?= number_format($student['total_fees']) ?></strong></div>
        <div>Total paid: <strong style="color:#16a34a">KES <?= number_format($totalPaid) ?></strong></div>
        <div>Overpaid by: <strong style="color:#7c3aed">KES <?= number_format($excess) ?></strong></div>
        <?php if ($paidPersonal > 0 && $paidExternal == 0): ?>
            <div style="margin-top:6px;color:#16a34a">✓ All payments are personal funds — refund is allowed.</div>
        <?php elseif ($paidExternal > 0 && $paidPersonal == 0): ?>
            <div style="margin-top:6px;color:#d97706">⚠ All payments are from external sources (HELB/SMIS etc.) — refund not allowed, forward only.</div>
        <?php else: ?>
            <div style="margin-top:6px;color:#d97706">⚠ Mixed payment sources — refund allowed only for personal portion.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="max-width:600px">
    <div class="card-body">
        <form method="POST">

            <!-- Option: Refund -->
            <div class="option-card <?= ($paidPersonal <= 0) ? 'disabled' : '' ?>">
                <label>
                    <input type="radio" name="action" value="refund" <?= ($paidPersonal <= 0) ? 'disabled' : '' ?> style="margin-top:3px;accent-color:#d97706">
                    <div>
                        <div class="option-title">💵 Refund to Student</div>
                        <div class="option-desc">
                            The excess of <strong>KES <?= number_format($excess) ?></strong> will be refunded directly to the student.
                            Only available when payment was made by the student personally (M-Pesa, bank, eCitizen).
                            <?php if ($paidPersonal <= 0): ?><br><span style="color:#dc2626">Not available — no personal payments on record.</span><?php endif; ?>
                        </div>
                    </div>
                </label>
            </div>

            <!-- Option: Forward -->
            <div class="option-card">
                <label>
                    <input type="radio" name="action" value="forward" style="margin-top:3px;accent-color:#d97706">
                    <div style="flex:1">
                        <div class="option-title">➡️ Forward to Next Semester</div>
                        <div class="option-desc">The excess of <strong>KES <?= number_format($excess) ?></strong> will be credited as a payment in the next semester. Required for HELB, SMIS, and other institutional sources.</div>
                        <div style="margin-top:12px" id="group-select-wrap">
                            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted);margin-bottom:7px">Forward to Group / Semester</label>
                            <select name="next_group_id" class="form-control">
                                <option value="">— Select semester —</option>
                                <?php foreach ($groups as $g): ?>
                                    <?php $groupLabel = $g['name'] . (($g['academic_year'] ?? '') ? ' (' . $g['academic_year'] . ')' : ''); ?>
                                <option value="<?= $g['group_id'] ?>"><?= htmlspecialchars($groupLabel) ?> (KES <?= number_format($g['total_fees']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </label>
            </div>

            <div class="form-group" style="margin-top:16px">
                <label>Notes (optional)</label>
                <input type="text" name="notes" class="form-control" placeholder="e.g. Student requested refund via M-Pesa" value="<?= htmlspecialchars($_POST['notes']??'') ?>">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;padding:13px">✓ Confirm Resolution</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
