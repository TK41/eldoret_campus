<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Fees Dashboard';
$activePage = 'fees_dashboard';
$db = getDB();

// ── Summary stats ──
$totalStudents  = $db->query("SELECT COUNT(*) FROM fee_students WHERE is_active=1")->fetchColumn();

// Total collected & total due — use subquery to avoid JOIN multiplication
$totalCollected = $db->query("SELECT COALESCE(SUM(amount),0) FROM fee_payments p JOIN fee_students s ON s.fee_student_id=p.fee_student_id WHERE s.is_active=1")->fetchColumn();
$totalFeesDue   = $db->query("SELECT COALESCE(SUM(total_fees),0) FROM fee_students WHERE is_active=1")->fetchColumn();
$totalBalance   = max(0, $totalFeesDue - $totalCollected);

// Fully paid = paid >= total_fees
$fullyPaid = $db->query("
    SELECT COUNT(*) FROM fee_students s
    WHERE s.is_active=1
    AND (SELECT COALESCE(SUM(p.amount),0) FROM fee_payments p WHERE p.fee_student_id=s.fee_student_id) >= s.total_fees
")->fetchColumn();

// Overpaid = paid > total_fees
$overpaid = $db->query("
    SELECT COUNT(*) FROM fee_students s
    WHERE s.is_active=1
    AND (SELECT COALESCE(SUM(p.amount),0) FROM fee_payments p WHERE p.fee_student_id=s.fee_student_id) > s.total_fees
")->fetchColumn();

// This month — based on when entry was posted (created_at)
$monthCollected = $db->query("
    SELECT COALESCE(SUM(p.amount),0) FROM fee_payments p
    JOIN fee_students s ON s.fee_student_id=p.fee_student_id
    WHERE s.is_active=1
    AND YEAR(p.created_at)=YEAR(NOW()) AND MONTH(p.created_at)=MONTH(NOW())
")->fetchColumn();

// Today — based on when entry was posted (created_at)
$todayCollected = $db->query("
    SELECT COALESCE(SUM(p.amount),0) FROM fee_payments p
    JOIN fee_students s ON s.fee_student_id=p.fee_student_id
    WHERE s.is_active=1 AND DATE(p.created_at)=CURDATE()
")->fetchColumn();

// Per-group breakdown — use subqueries to get correct sums per student, then aggregate
$groups = $db->query("
    SELECT
        g.group_id,
        CONCAT(g.name, IF(g.academic_year <> '', CONCAT(' (', g.academic_year, ')'), '')) AS group_name,
        g.name,
        g.academic_year,
        g.total_fees,
        COUNT(s.fee_student_id) AS student_count,
        COALESCE(SUM(
            (SELECT COALESCE(SUM(p2.amount),0) FROM fee_payments p2 WHERE p2.fee_student_id=s.fee_student_id)
        ),0) AS collected,
        COALESCE(SUM(s.total_fees),0) AS expected
    FROM fee_groups g
    LEFT JOIN fee_students s ON s.group_id=g.group_id AND s.is_active=1
    GROUP BY g.group_id
    ORDER BY g.group_id
")->fetchAll();

// Overpaid students list
$overpaidStudents = $db->query("
    SELECT s.fee_student_id, s.full_name, s.student_id, s.total_fees,
           CONCAT(g.name, IF(g.academic_year <> '', CONCAT(' (', g.academic_year, ')'), '')) AS group_name,
           (SELECT COALESCE(SUM(p.amount),0) FROM fee_payments p WHERE p.fee_student_id=s.fee_student_id) AS paid
    FROM fee_students s
    JOIN fee_groups g ON g.group_id=s.group_id
    WHERE s.is_active=1
    HAVING paid > s.total_fees
    ORDER BY (paid - s.total_fees) DESC
")->fetchAll();

// Recent payments (last 10)
$recent = $db->query("
    SELECT p.*, s.full_name, s.student_id
    FROM fee_payments p
    JOIN fee_students s ON s.fee_student_id=p.fee_student_id
    ORDER BY p.created_at DESC LIMIT 10
")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<style>
.fees-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px}
.fees-stat{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px}
.fees-stat .label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:8px}
.fees-stat .value{font-size:26px;font-weight:700;color:var(--text-primary);font-family:'Space Mono',monospace;line-height:1}
.fees-stat .sub{font-size:12px;color:var(--text-muted);margin-top:5px}
.accent-gold {border-left:4px solid #d97706}
.accent-green{border-left:4px solid #16a34a}
.accent-red  {border-left:4px solid #dc2626}
.accent-blue {border-left:4px solid #1a3a6b}
.accent-purple{border-left:4px solid #7c3aed}

.group-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-bottom:28px}
.group-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px}
.group-name{font-weight:700;font-size:14px;color:var(--text-primary);margin-bottom:4px}
.group-meta{font-size:12px;color:var(--text-muted);margin-bottom:14px}
.progress-bar{height:8px;background:var(--border);border-radius:4px;overflow:hidden;margin-bottom:8px}
.progress-fill{height:100%;background:linear-gradient(90deg,#d97706,#f59e0b);border-radius:4px}
.progress-fill.full{background:linear-gradient(90deg,#16a34a,#22c55e)}
.progress-fill.over{background:linear-gradient(90deg,#7c3aed,#a855f7)}
.group-amounts{display:flex;justify-content:space-between;font-size:12px}

.mode-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase}
.mode-mpesa{background:rgba(22,163,74,.1);color:#16a34a}
.mode-helb{background:rgba(26,58,107,.1);color:#1a3a6b}
.mode-bank{background:rgba(107,114,128,.1);color:#6b7280}
.mode-ecitizen{background:rgba(217,119,6,.1);color:#d97706}
.mode-smis{background:rgba(139,92,246,.1);color:#7c3aed}
.mode-receipted{background:rgba(139,92,246,.1);color:#7c3aed}
.mode-nairobi-campus{background:rgba(6,182,212,.1);color:#0891b2}
.mode-other{background:rgba(107,114,128,.1);color:#6b7280}

.overpay-card{background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.2);border-radius:12px;padding:20px;margin-bottom:28px}
.overpay-title{font-size:15px;font-weight:700;color:#7c3aed;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.excess-amount{font-family:'Space Mono',monospace;font-weight:700;color:#7c3aed}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">💳 Fees Dashboard</h1>
        <p class="page-subtitle">Overview of all student fee collections</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a href="<?= APP_ROOT ?>/portal.php" class="btn btn-ghost btn-sm" style="text-decoration:none">🏠 Portal</a>
        <a href="<?= APP_ROOT ?>/fees/add_payment.php" class="btn btn-primary btn-sm">➕ Post Payment</a>
        <a href="<?= APP_ROOT ?>/fees/students.php" class="btn btn-ghost btn-sm">👥 All Students</a>
    </div>
</div>

<!-- Stat cards -->
<div class="fees-stat-grid">
    <div class="fees-stat accent-blue">
        <div class="label">Total Students</div>
        <div class="value"><?= number_format($totalStudents) ?></div>
        <div class="sub"><?= $fullyPaid ?> fully paid</div>
    </div>
    <div class="fees-stat accent-gold">
        <div class="label">Total Collected</div>
        <div class="value">KES <?= number_format($totalCollected) ?></div>
        <div class="sub">of KES <?= number_format($totalFeesDue) ?> expected</div>
    </div>
    <div class="fees-stat accent-red">
        <div class="label">Outstanding Balance</div>
        <div class="value">KES <?= number_format($totalBalance) ?></div>
        <div class="sub"><?= $totalFeesDue > 0 ? number_format(($totalBalance/$totalFeesDue)*100,1) : 0 ?>% still owed</div>
    </div>
    <div class="fees-stat accent-green">
        <div class="label">This Month (Posted)</div>
        <div class="value">KES <?= number_format($monthCollected) ?></div>
        <div class="sub">Posted today: KES <?= number_format($todayCollected) ?></div>
    </div>
</div>

<!-- Overpaid students alert -->
<!-- Overpaid students alert -->
<?php if (!empty($overpaidStudents)): ?>
<div class="overpay-card">

    <div class="overpay-title">
        ⚠️ Students with Excess Payments
    </div>

    <!-- Mobile scroll wrapper -->
    <div class="table-scroll">

        <table class="data-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Adm No</th>
                    <th>Group</th>
                    <th>Total Fees</th>
                    <th>Amount Paid</th>
                    <th>Excess</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($overpaidStudents as $op):
                $excess = $op['paid'] - $op['total_fees'];
            ?>
                <tr>
                    <td style="font-weight:600">
                        <?= htmlspecialchars($op['full_name']) ?>
                    </td>

                    <td>
                        <code style="font-size:12px">
                            <?= htmlspecialchars($op['student_id']) ?>
                        </code>
                    </td>

                    <td style="font-size:12px">
                        <?= htmlspecialchars($op['group_name']) ?>
                    </td>

                    <td style="font-family:'Space Mono',monospace;font-size:12px">
                        KES <?= number_format($op['total_fees']) ?>
                    </td>

                    <td style="font-family:'Space Mono',monospace;font-size:12px;color:#16a34a">
                        KES <?= number_format($op['paid']) ?>
                    </td>

                    <td>
                        <span class="excess-amount">
                            KES <?= number_format($excess) ?>
                        </span>
                    </td>

                    <td>
                        <a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $op['fee_student_id'] ?>"
                           class="btn btn-ghost btn-sm">
                            View
                        </a>

                        <a href="<?= APP_ROOT ?>/fees/overpayment.php?id=<?= $op['fee_student_id'] ?>"
                           class="btn btn-sm"
                           style="background:rgba(124,58,237,.1);color:#7c3aed;border:1px solid rgba(124,58,237,.3);border-radius:6px;padding:4px 10px;font-size:12px;font-weight:600;text-decoration:none">
                            Resolve
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>

        </table>

    </div>

</div>
<?php endif; ?>

<!-- Per-group breakdown -->
<h2 style="font-size:15px;font-weight:700;margin-bottom:14px;color:var(--text-primary)">Collection by Group</h2>
<div class="group-grid">
<?php foreach ($groups as $g):
    $pct = $g['expected'] > 0 ? min(100, ($g['collected'] / $g['expected']) * 100) : 0;
    $over = $g['collected'] > $g['expected'];
?>
    <div class="group-card">
        <div class="group-name"><?= htmlspecialchars($g['group_name']) ?></div>
        <div class="group-meta"><?= $g['student_count'] ?> students · KES <?= number_format($g['total_fees']) ?>/student</div>
        <div class="progress-bar">
            <div class="progress-fill <?= $over ? 'over' : ($pct>=100?'full':'') ?>" style="width:<?= min(100,$pct) ?>%"></div>
        </div>
        <div class="group-amounts">
            <span style="color:#16a34a;font-weight:600">KES <?= number_format($g['collected']) ?> paid</span>
            <span style="color:<?= $over ? '#7c3aed' : '#dc2626' ?>">
                <?= $over ? 'KES '.number_format($g['collected']-$g['expected']).' excess' : 'KES '.number_format(max(0,$g['expected']-$g['collected'])).' owed' ?>
            </span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- Recent payments -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h2 style="font-size:15px;font-weight:700;margin:0">Recent Payments</h2>
        <a href="<?= APP_ROOT ?>/fees/students.php" style="font-size:12px;color:var(--text-muted)">View all →</a>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($recent)): ?>
            <div style="padding:32px;text-align:center;color:var(--text-muted)">No payments posted yet.</div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Student</th><th>Adm No</th><th>Amount</th><th>Mode</th><th>Reference</th><th>Date Paid</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $r['fee_student_id'] ?>" style="font-weight:600;color:var(--text-primary)"><?= htmlspecialchars($r['full_name']) ?></a></td>
                    <td><code style="font-size:12px"><?= htmlspecialchars($r['student_id']) ?></code></td>
                    <td><strong>KES <?= number_format($r['amount']) ?></strong></td>
                    <td><span class="mode-badge mode-<?= str_replace(['_',' '],['-','-'],$r['mode']) ?>"><?= strtoupper(str_replace('_',' ',$r['mode'])) ?></span></td>
                    <td style="font-family:'Space Mono',monospace;font-size:11px"><?= htmlspecialchars($r['reference']??'—') ?></td>
                    <td><?= date('d M Y',strtotime($r['date_paid'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>