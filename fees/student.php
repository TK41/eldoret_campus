<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$db = getDB();
$id = intval($_GET['id'] ?? 0);
$groupFilter  = intval($_GET['group'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$backQuery = http_build_query([
    'group' => $groupFilter ?: null,
    'status' => $statusFilter ?: null,
    'q' => $search ?: null,
]);
$backUrl = APP_ROOT . '/fees/students.php' . ($backQuery ? '?' . $backQuery : '');

$paymentSortColumns = [
    'date_paid' => 'p.date_paid',
    'amount' => 'p.amount',
    'mode' => 'p.mode',
    'phone' => 'p.mpesa_number',
    'reference' => 'p.reference',
    'posted_by' => 'posted_by_name',
];
$requestedPaymentSort = $_GET['sort'] ?? 'date_paid';
$paymentSortKey = is_string($requestedPaymentSort) && isset($paymentSortColumns[$requestedPaymentSort])
    ? $requestedPaymentSort
    : 'date_paid';
$requestedPaymentDirection = $_GET['dir'] ?? 'asc';
$paymentSortDirection = is_string($requestedPaymentDirection) && strtolower($requestedPaymentDirection) === 'desc'
    ? 'DESC'
    : 'ASC';
$buildPaymentUrl = static function (array $changes = []): string {
    $query = $_GET;
    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }
    return APP_ROOT . '/fees/student.php' . ($query ? '?' . http_build_query($query) : '');
};
$paymentSortHeader = static function (string $label, string $key, bool $money = false) use (
    $paymentSortKey,
    $paymentSortDirection,
    $buildPaymentUrl
): string {
    $nextDirection = $paymentSortKey === $key && $paymentSortDirection === 'ASC' ? 'desc' : 'asc';
    $ariaSort = $paymentSortKey === $key ? ($paymentSortDirection === 'ASC' ? 'ascending' : 'descending') : 'none';
    $indicator = $paymentSortKey === $key ? ($paymentSortDirection === 'ASC' ? '↑' : '↓') : '↕';
    $href = htmlspecialchars($buildPaymentUrl(['sort' => $key, 'dir' => $nextDirection]), ENT_QUOTES, 'UTF-8');
    return '<th scope="col"' . ($money ? ' class="fee-money"' : '') . ' aria-sort="' . $ariaSort . '"><a class="table-sort-link" href="' . $href . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '<span aria-hidden="true">' . $indicator . '</span></a></th>';
};

if (!$id) { header('Location: ' . $backUrl); exit; }

// ── DELETE payment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment'])) {
    $pid = intval($_POST['payment_id']);
    $db->prepare("DELETE FROM fee_payments WHERE payment_id=? AND fee_student_id=?")
       ->execute([$pid, $id]);
    setFlash('success', 'Payment deleted.');
    header('Location: ' . APP_ROOT . '/fees/student.php?id=' . $id); exit;
}

$student = $db->prepare("
    SELECT s.*, CONCAT(g.name, IF(g.academic_year <> '', CONCAT(' (', g.academic_year, ')'), '')) AS group_name, g.total_fees AS group_fees
    FROM fee_students s JOIN fee_groups g ON g.group_id=s.group_id
    WHERE s.fee_student_id=?
");
$student->execute([$id]);
$student = $student->fetch();
if (!$student) { header('Location: ' . $backUrl); exit; }

$payments = $db->prepare("
    SELECT p.*, COALESCE(a.full_name, 'System Import') AS posted_by_name
    FROM fee_payments p
    LEFT JOIN admin_users a ON a.admin_id=p.posted_by
    WHERE p.fee_student_id=?
    ORDER BY " . $paymentSortColumns[$paymentSortKey] . " $paymentSortDirection, p.payment_id DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$totalPaid    = array_sum(array_column($payments, 'amount'));
$balance      = $student['total_fees'] - $totalPaid;
$pct          = $student['total_fees'] > 0 ? min(100, ($totalPaid / $student['total_fees']) * 100) : 0;

$pageTitle  = formatPersonName($student['full_name']);
$activePage = 'fees_students';

include __DIR__ . '/partials/header.php';
?>

<style>
.student-header { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:24px 28px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; }
.student-avatar { width:52px; height:52px; background:linear-gradient(135deg,#92400e,#d97706); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:700; flex-shrink:0; }
.student-meta   { flex:1; min-width:0; }
.student-name   { font-size:20px; font-weight:700; color:var(--text-primary); line-height:1.2; }
.student-sub    { font-size:13px; color:var(--text-muted); margin-top:3px; }
.balance-panel  { text-align:right; }
.balance-amount { font-size:22px; font-weight:700; font-family:'Space Mono',monospace; }
.balance-label  { font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.7px; }

.progress-bar { height:10px; background:var(--border); border-radius:5px; overflow:hidden; margin:16px 0 8px; }
.progress-fill { height:100%; background:linear-gradient(90deg,#d97706,#f59e0b); border-radius:5px; transition:width .5s; }
.progress-fill.full { background:linear-gradient(90deg,#16a34a,#22c55e); }

.stat-row { display:flex; gap:24px; flex-wrap:wrap; }
.stat-item { text-align:center; }
.stat-item .val { font-size:18px; font-weight:700; font-family:'Space Mono',monospace; }
.stat-item .lbl { font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; }

.mode-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
.mode-mpesa       { background:rgba(22,163,74,.1);  color:#16a34a; }
.mode-helb        { background:rgba(26,58,107,.1);  color:#1a3a6b; }
.mode-bank        { background:rgba(107,114,128,.1); color:#6b7280; }
.mode-ecitizen    { background:rgba(217,119,6,.1);   color:#d97706; }
.mode-smis        { background:rgba(139,92,246,.1);  color:#7c3aed; }
.mode-receipted   { background:rgba(139,92,246,.1);  color:#7c3aed; }
.mode-nairobi-campus { background:rgba(6,182,212,.1); color:#0891b2; }
.mode-other       { background:rgba(107,114,128,.1); color:#6b7280; }
.fees-data-table th,.fees-data-table td{padding:12px 14px}
.fees-data-table thead th{position:sticky;top:0;z-index:2;background:var(--surface)}
.fees-data-table .fee-money{text-align:right;font-family:'Space Mono',monospace;font-variant-numeric:tabular-nums;white-space:nowrap}
.fees-data-table .table-sort-link{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;white-space:nowrap}
.fees-data-table .table-sort-link:hover{color:var(--module-accent)}
.fees-data-table .table-sort-link span{font-size:11px;color:var(--text-muted)}

@media print {
    .topbar, .sidebar, .app-footer, .no-print { display:none !important; }
    .main-content { margin-left:0 !important; padding:0 !important; }
    .layout { padding-top:0 !important; }
}
</style>

<!-- Breadcrumb -->
<div style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
    <a href="<?= htmlspecialchars($backUrl) ?>" style="color:var(--text-muted)">Students</a> › <?= htmlspecialchars(formatPersonName($student['full_name'])) ?>
</div>

<!-- Student header card -->
<div class="student-header">
    <div style="display:flex;align-items:center;gap:16px;flex:1">
        <div class="student-avatar"><?= strtoupper(substr(formatPersonName($student['full_name']),0,1)) ?></div>
        <div class="student-meta">
            <div class="student-name"><?= htmlspecialchars(formatPersonName($student['full_name'])) ?></div>
            <div class="student-sub">
                <code><?= htmlspecialchars($student['student_id']) ?></code>
                · <?= htmlspecialchars(formatTitleCase($student['group_name'])) ?>
                <?php if ($student['programme']): ?>
                    · <span><?= htmlspecialchars(formatTitleCase($student['programme'])) ?></span>
                <?php endif; ?>
            </div>

            <!-- Progress bar -->
            <div class="progress-bar">
                <div class="progress-fill <?= $pct >= 100 ? 'full' : '' ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="stat-row">
                <div class="stat-item">
                    <div class="val" style="color:#16a34a"><?= formatCurrency($totalPaid) ?></div>
                    <div class="lbl">Paid</div>
                </div>
                <div class="stat-item">
                    <div class="val" style="color:<?= $balance > 0 ? '#dc2626' : '#16a34a' ?>"><?= formatCurrency(max(0,$balance)) ?></div>
                    <div class="lbl">Balance</div>
                </div>
                <div class="stat-item">
                    <div class="val"><?= formatCurrency($student['total_fees']) ?></div>
                    <div class="lbl">Total Fees</div>
                </div>
                <div class="stat-item">
                    <div class="val"><?= formatPercentage($pct) ?></div>
                    <div class="lbl">Cleared</div>
                </div>
            </div>
        </div>
    </div>

    <div class="no-print" style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
        <a href="<?= APP_ROOT ?>/fees/add_payment.php?student_id=<?= $id ?>" class="btn btn-primary"><?= appIcon('plus') ?> Post Payment</a>
        <button onclick="window.print()" class="btn btn-ghost"><?= appIcon('printer') ?> Print Statement</button>
        <a href="<?= APP_ROOT ?>/fees/edit_student.php?id=<?= $id ?>" class="btn btn-ghost" style="font-size:12px"><?= appIcon('pencil') ?> Edit Student</a>
    </div>
</div>

<!-- Payment history table -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h2 style="font-size:15px;font-weight:700;margin:0">Payment History (<?= pluralizeCount(count($payments), 'entry') ?>)</h2>
        <?php if ($balance <= 0): ?>
            <span style="background:rgba(22,163,74,.1);color:#16a34a;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= appIcon('check') ?> FULLY PAID</span>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($payments)): ?>
            <div style="padding:40px;text-align:center;color:var(--text-muted)">
                No payments posted yet. <a href="<?= APP_ROOT ?>/fees/add_payment.php?student_id=<?= $id ?>">Post first payment <?= appIcon('arrow-right') ?></a>
            </div>
        <?php else: ?>
        <div class="table-scroll">
        <table class="data-table fees-data-table">
            <thead><tr>
                <th>#</th>
                <?= $paymentSortHeader('Date Paid', 'date_paid') ?>
                <?= $paymentSortHeader('Amount', 'amount', true) ?>
                <?= $paymentSortHeader('Mode', 'mode') ?>
                <?= $paymentSortHeader('Phone / Ref No', 'phone') ?>
                <?= $paymentSortHeader('Transaction Code', 'reference') ?>
                <?= $paymentSortHeader('Posted By', 'posted_by') ?>
                <th class="no-print">Action</th>
            </tr></thead>
            <tbody>
            <?php $running = 0; foreach ($payments as $i => $p):
                $running += $p['amount'];
                $modeKey = str_replace(['_',' '], ['-','-'], strtolower($p['mode']));
            ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></td>
                    <td class="date-value"><?= formatDate($p['date_paid']) ?></td>
                    <td class="fee-money"><strong><?= formatCurrency($p['amount']) ?></strong></td>
                    <td><span class="mode-badge mode-<?= $modeKey ?>"><?= strtoupper(str_replace(['_'],['  '],$p['mode'])) ?></span></td>
                    <td style="font-family:'Space Mono',monospace;font-size:11px"><?= htmlspecialchars($p['mpesa_number'] ?? '—') ?></td>
                    <td style="font-family:'Space Mono',monospace;font-size:11px;font-weight:600"><?= htmlspecialchars($p['reference'] ?? '—') ?></td>
                    <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars(formatPersonName($p['posted_by_name'])) ?></td>
                    <td class="no-print">
                        <?php if (isSuperAdmin()): ?>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Delete this payment of <?= formatCurrency($p['amount']) ?>?')">
                            <input type="hidden" name="payment_id" value="<?= $p['payment_id'] ?>">
                            <button type="submit" name="delete_payment" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--table-head-bg);font-weight:700">
                    <td colspan="2" style="padding:12px 16px;text-align:right;font-size:13px">TOTAL PAID</td>
                    <td class="fee-money" style="padding:12px 16px;color:#16a34a"><?= formatCurrency($totalPaid) ?></td>
                    <td colspan="5"></td>
                </tr>
                <tr style="background:var(--table-head-bg);font-weight:700">
                    <td colspan="2" style="padding:12px 16px;text-align:right;font-size:13px">BALANCE</td>
                    <td class="fee-money" style="padding:12px 16px;color:<?= $balance > 0 ? '#dc2626':'#16a34a' ?>"><?= formatCurrency(max(0,$balance)) ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>