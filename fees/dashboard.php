<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../shared/dashboard_components.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Fees Dashboard';
$activePage = 'fees_dashboard';
$db = getDB();
$buildFeesDashboardUrl = static function (array $changes = []): string {
    $query = $_GET;
    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }
    return APP_ROOT . '/fees/dashboard.php' . ($query ? '?' . http_build_query($query) : '');
};
$sortHeader = static function (
    string $label,
    string $key,
    string $activeSort,
    string $direction,
    string $sortParam,
    string $directionParam,
    bool $money = false
) use ($buildFeesDashboardUrl): string {
    $nextDirection = $activeSort === $key && $direction === 'ASC' ? 'desc' : 'asc';
    $ariaSort = $activeSort === $key ? ($direction === 'ASC' ? 'ascending' : 'descending') : 'none';
    $indicator = $activeSort === $key ? ($direction === 'ASC' ? '↑' : '↓') : '↕';
    $href = htmlspecialchars($buildFeesDashboardUrl([
        $sortParam => $key,
        $directionParam => $nextDirection,
    ]), ENT_QUOTES, 'UTF-8');
    return '<th scope="col"' . ($money ? ' class="fee-money"' : '') . ' aria-sort="' . $ariaSort . '"><a class="table-sort-link" href="' . $href . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '<span aria-hidden="true">' . $indicator . '</span></a></th>';
};

// ── Summary stats ──
$totalStudents  = $db->query("SELECT COUNT(*) FROM fee_students WHERE is_active=1")->fetchColumn();

// Total collected & total due — use subquery to avoid JOIN multiplication
$totalCollected = $db->query("SELECT COALESCE(SUM(amount),0) FROM fee_payments p JOIN fee_students s ON s.fee_student_id=p.fee_student_id WHERE s.is_active=1")->fetchColumn();
$totalFeesDue   = $db->query("SELECT COALESCE(SUM(total_fees),0) FROM fee_students WHERE is_active=1")->fetchColumn();
$balanceStats = $db->query("
    SELECT
        COUNT(CASE WHEN s.total_fees > COALESCE(p.paid, 0) THEN 1 END) AS students_with_balance,
        COALESCE(SUM(GREATEST(s.total_fees - COALESCE(p.paid, 0), 0)), 0) AS balance
    FROM fee_students s
    LEFT JOIN (
        SELECT fee_student_id, SUM(amount) AS paid
        FROM fee_payments
        GROUP BY fee_student_id
    ) p ON p.fee_student_id = s.fee_student_id
    WHERE s.is_active = 1
")->fetch();
$totalBalance = (float) $balanceStats['balance'];
$studentsWithBalance = (int) $balanceStats['students_with_balance'];
$collectionPercent = $totalFeesDue > 0 ? max(0, min(100, ($totalCollected / $totalFeesDue) * 100)) : 0;

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
$overpaidSortColumns = [
    'student' => 's.full_name',
    'student_id' => 's.student_id',
    'group' => 'group_name',
    'total' => 's.total_fees',
    'paid' => 'paid',
    'excess' => '((SELECT COALESCE(SUM(p.amount),0) FROM fee_payments p WHERE p.fee_student_id=s.fee_student_id) - s.total_fees)',
];
$requestedOverpaidSort = $_GET['overpay_sort'] ?? 'excess';
$overpaidSortKey = is_string($requestedOverpaidSort) && isset($overpaidSortColumns[$requestedOverpaidSort])
    ? $requestedOverpaidSort
    : 'excess';
$requestedOverpaidDirection = $_GET['overpay_dir'] ?? 'desc';
$overpaidSortDirection = is_string($requestedOverpaidDirection) && strtolower($requestedOverpaidDirection) === 'asc'
    ? 'ASC'
    : 'DESC';
$overpaidStudents = $db->query("
    SELECT s.fee_student_id, s.full_name, s.student_id, s.total_fees,
           CONCAT(g.name, IF(g.academic_year <> '', CONCAT(' (', g.academic_year, ')'), '')) AS group_name,
           (SELECT COALESCE(SUM(p.amount),0) FROM fee_payments p WHERE p.fee_student_id=s.fee_student_id) AS paid
    FROM fee_students s
    JOIN fee_groups g ON g.group_id=s.group_id
    WHERE s.is_active=1
    HAVING paid > s.total_fees
    ORDER BY " . $overpaidSortColumns[$overpaidSortKey] . " $overpaidSortDirection, s.fee_student_id ASC
")->fetchAll();

// Recent payments (last 10)
$paymentsThisMonth = ($_GET['payments'] ?? '') === 'month';
$paymentSortColumns = [
    'student' => 's.full_name',
    'student_id' => 's.student_id',
    'amount' => 'p.amount',
    'mode' => 'p.mode',
    'reference' => 'p.reference',
    'date_paid' => 'p.date_paid',
    'posted' => 'p.created_at',
];
$requestedPaymentSort = $_GET['payment_sort'] ?? 'posted';
$paymentSortKey = is_string($requestedPaymentSort) && isset($paymentSortColumns[$requestedPaymentSort])
    ? $requestedPaymentSort
    : 'posted';
$requestedPaymentDirection = $_GET['payment_dir'] ?? 'desc';
$paymentSortDirection = is_string($requestedPaymentDirection) && strtolower($requestedPaymentDirection) === 'asc'
    ? 'ASC'
    : 'DESC';
$recentSql = "
    SELECT p.*, s.full_name, s.student_id
    FROM fee_payments p
    JOIN fee_students s ON s.fee_student_id=p.fee_student_id
";
if ($paymentsThisMonth) {
    $recentSql .= " WHERE s.is_active=1 AND YEAR(p.created_at)=YEAR(NOW()) AND MONTH(p.created_at)=MONTH(NOW())";
}
$recentSql .= " ORDER BY " . $paymentSortColumns[$paymentSortKey] . " $paymentSortDirection, p.payment_id DESC LIMIT 10";
$recent = $db->query($recentSql)->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<style>
.group-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-bottom:28px}
.group-grid > .dashboard-empty-state{grid-column:1/-1}
.group-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px}
.group-name{font-weight:700;font-size:14px;color:var(--text-primary);margin-bottom:4px}
.group-meta{font-size:12px;color:var(--text-muted);margin-bottom:14px}
.group-amounts{display:flex;justify-content:space-between;gap:12px;font-size:12px}

.mode-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase}
.mode-mpesa,.mode-helb,.mode-bank,.mode-ecitizen,.mode-smis,.mode-receipted,.mode-nairobi-campus,.mode-other{
    background:var(--table-head-bg);color:var(--text-primary);border:1px solid var(--border)
}

.overpay-card{background:var(--color-warning-bg);border:1px solid var(--color-warning);border-radius:12px;padding:20px;margin-bottom:28px}
.overpay-title{font-size:15px;font-weight:700;color:var(--color-warning);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.excess-amount{font-family:'Space Mono',monospace;font-weight:700;color:var(--color-warning)}
.fees-data-table th,.fees-data-table td{padding:12px 14px}
.fees-data-table thead th{position:sticky;top:0;z-index:2;background:var(--surface)}
.fees-data-table .fee-money{text-align:right;font-family:'Space Mono',monospace;font-variant-numeric:tabular-nums;white-space:nowrap}
.fees-data-table tbody tr[data-row-href]{cursor:pointer}
.fees-data-table tbody tr[data-row-href]:hover td{background:var(--table-hover)}
.fees-data-table tbody tr[data-row-href]:focus-visible{outline:2px solid var(--module-accent);outline-offset:-2px}
.table-sort-link{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;white-space:nowrap}
.table-sort-link:hover{color:var(--module-accent)}
.table-sort-link span{font-size:11px;color:var(--text-muted)}
.btn-fees-secondary{background:#334155;border:1px solid #334155;color:#fff}
.btn-fees-secondary:hover{background:#1e293b;border-color:#1e293b}
[data-theme="dark"] .btn-fees-secondary{background:#475569;border-color:#64748b;color:#fff}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= appIcon('credit-card') ?> Fees Dashboard</h1>
    </div>
    <div class="dashboard-actions">
        <a href="<?= APP_ROOT ?>/portal.php" class="btn btn-ghost btn-sm" style="text-decoration:none"><?= appIcon('home') ?> Portal</a>
        <a href="<?= APP_ROOT ?>/fees/add_payment.php" class="btn btn-primary"><?= appIcon('plus') ?> Post Payment</a>
        <a href="<?= APP_ROOT ?>/fees/students.php" class="btn btn-ghost btn-sm"><?= appIcon('users') ?> All Students</a>
    </div>
</div>

<!-- Stat cards -->
<div class="dashboard-stat-grid">
    <?php
    renderDashboardStatCard([
        'label' => 'Total Students',
        'value' => formatNumber($totalStudents),
        'summary' => $totalStudents > 0 ? pluralizeCount($fullyPaid, 'student') . ' fully paid' : 'No active students yet',
        'href' => APP_ROOT . '/fees/students.php',
        'tone' => 'info',
        'problem' => true,
        'active' => $totalStudents > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Total Collected',
        'value' => formatCurrency($totalCollected),
        'summary' => $totalFeesDue <= 0
            ? 'No fee targets configured'
            : ($totalCollected > 0 ? 'of ' . formatCurrency($totalFeesDue) . ' expected' : 'No payments collected yet · of ' . formatCurrency($totalFeesDue) . ' expected'),
        'compactValue' => true,
        'href' => APP_ROOT . '/fees/students.php?status=collected',
        'tone' => $totalFeesDue > 0 ? ($collectionPercent >= 75 ? 'success' : ($collectionPercent >= 40 ? 'warning' : 'danger')) : 'neutral',
        'problem' => true,
        'active' => abs((float) $totalCollected) > 0,
        'progress' => [
            'value' => $collectionPercent,
            'label' => $totalFeesDue > 0 ? formatPercentage($collectionPercent) . ' collected' : 'No fees expected',
        ],
    ]);
    renderDashboardStatCard([
        'label' => 'Outstanding Balance',
        'value' => formatCurrency($totalBalance),
        'summary' => $studentsWithBalance > 0 ? pluralizeCount($studentsWithBalance, 'student') . ' with balances due' : 'No outstanding balances',
        'compactValue' => true,
        'href' => APP_ROOT . '/fees/students.php?status=balance',
        'tone' => 'danger',
        'problem' => true,
        'active' => $totalBalance > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'This Month (Posted)',
        'value' => formatCurrency($monthCollected),
        'summary' => $monthCollected == 0
            ? 'No payments posted this month'
            : (abs((float) $todayCollected) > 0 ? 'Posted today: ' . formatCurrency($todayCollected) : 'No payments posted today'),
        'compactValue' => true,
        'href' => APP_ROOT . '/fees/dashboard.php?payments=month#recent-payments',
        'tone' => $monthCollected > 0 ? 'success' : ($monthCollected < 0 ? 'danger' : 'neutral'),
        'problem' => true,
        'active' => abs((float) $monthCollected) > 0,
    ]);
    ?>
</div>

<!-- Overpaid students alert -->
<!-- Overpaid students alert -->
<?php if (!empty($overpaidStudents)): ?>
<div class="overpay-card">

    <div class="overpay-title">
        <?= appIcon('alert-triangle') ?> Students with Excess Payments
    </div>

    <!-- Mobile scroll wrapper -->
    <div class="table-scroll">

        <table class="data-table fees-data-table">
            <caption class="sr-only">Students with excess fee payments</caption>
            <thead>
                <tr>
                    <?= $sortHeader('Student', 'student', $overpaidSortKey, $overpaidSortDirection, 'overpay_sort', 'overpay_dir') ?>
                    <?= $sortHeader('Adm No', 'student_id', $overpaidSortKey, $overpaidSortDirection, 'overpay_sort', 'overpay_dir') ?>
                    <?= $sortHeader('Group', 'group', $overpaidSortKey, $overpaidSortDirection, 'overpay_sort', 'overpay_dir') ?>
                    <?= $sortHeader('Total Fees', 'total', $overpaidSortKey, $overpaidSortDirection, 'overpay_sort', 'overpay_dir', true) ?>
                    <?= $sortHeader('Amount Paid', 'paid', $overpaidSortKey, $overpaidSortDirection, 'overpay_sort', 'overpay_dir', true) ?>
                    <?= $sortHeader('Excess', 'excess', $overpaidSortKey, $overpaidSortDirection, 'overpay_sort', 'overpay_dir', true) ?>
                    <th scope="col">Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($overpaidStudents as $op):
                $excess = $op['paid'] - $op['total_fees'];
                $studentUrl = APP_ROOT . '/fees/student.php?id=' . (int) $op['fee_student_id'];
            ?>
                <tr data-row-href="<?= htmlspecialchars($studentUrl, ENT_QUOTES, 'UTF-8') ?>" tabindex="0"
                    aria-label="View fees for <?= htmlspecialchars(formatPersonName($op['full_name']), ENT_QUOTES, 'UTF-8') ?>">
                    <td style="font-weight:600">
                        <?= htmlspecialchars(formatPersonName($op['full_name'])) ?>
                    </td>

                    <td>
                        <code style="font-size:12px">
                            <?= htmlspecialchars($op['student_id']) ?>
                        </code>
                    </td>

                    <td style="font-size:12px">
                        <?= htmlspecialchars(formatTitleCase($op['group_name'])) ?>
                    </td>

                    <td class="fee-money">
                        <?= formatCurrency($op['total_fees']) ?>
                    </td>

                    <td class="fee-money" style="color:var(--color-success)">
                        <?= formatCurrency($op['paid']) ?>
                    </td>

                    <td class="fee-money">
                        <span class="excess-amount">
                            <?= formatCurrency($excess) ?>
                        </span>
                    </td>

                    <td>
                        <a href="<?= htmlspecialchars($studentUrl, ENT_QUOTES, 'UTF-8') ?>"
                           class="btn btn-ghost btn-sm">
                            View
                        </a>

                        <a href="<?= APP_ROOT ?>/fees/overpayment.php?id=<?= $op['fee_student_id'] ?>"
                           class="btn btn-sm btn-fees-secondary">
                            Manage overpayment
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>

        </table>

    </div>

</div>
<?php endif; ?>

<div class="group-grid">
<?php if (empty($groups)): ?>
    <div class="dashboard-empty-state">No fee groups have been configured yet.</div>
<?php endif; ?>
<?php foreach ($groups as $g):
    $hasExpected = $g['expected'] > 0;
    $pct = $hasExpected ? max(0, min(100, ($g['collected'] / $g['expected']) * 100)) : 0;
    $progressTone = !$hasExpected ? 'neutral' : ($pct >= 75 ? 'success' : ($pct >= 40 ? 'warning' : 'danger'));
    $over = $g['collected'] > $g['expected'];
?>
    <div class="group-card">
        <div class="group-name"><?= htmlspecialchars(formatTitleCase($g['group_name'])) ?></div>
        <div class="group-meta"><?= pluralizeCount($g['student_count'], 'student') ?> · <?= formatCurrency($g['total_fees']) ?>/student</div>
        <div class="fee-group-progress" role="progressbar"
             aria-label="<?= htmlspecialchars(formatTitleCase($g['group_name'])) ?> fee collection progress"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= round($pct, 1) ?>"
             aria-valuetext="<?= htmlspecialchars(formatPercentage($pct) . ' collected') ?>">
            <div class="fee-group-progress-fill fee-group-progress-fill--<?= $progressTone ?>" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="fee-group-progress-meta">
            <span><?= $hasExpected ? formatPercentage($pct) . ' collected' : 'No active students' ?></span>
            <span><?= $hasExpected ? formatCurrency($g['expected']) . ' expected' : '—' ?></span>
        </div>
        <div class="group-amounts" style="margin-top:10px">
            <span style="color:<?= $g['collected'] < 0 ? 'var(--color-danger-text)' : 'var(--color-success)' ?>;font-weight:600"><?= formatCurrency($g['collected']) ?> paid</span>
            <span style="color:<?= !$hasExpected ? 'var(--text-muted)' : ($over ? 'var(--color-warning)' : 'var(--color-danger-text)') ?>">
                <?= !$hasExpected ? '—' : ($over ? formatCurrency($g['collected'] - $g['expected']) . ' excess' : formatCurrency(max(0, $g['expected'] - $g['collected'])) . ' owed') ?>
            </span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- Recent payments -->
<div class="card" id="recent-payments">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h2 style="font-size:15px;font-weight:700;margin:0"><?= $paymentsThisMonth ? 'Payments Posted This Month' : 'Recent Payments' ?></h2>
        <a href="<?= APP_ROOT ?>/fees/dashboard.php?payments=<?= $paymentsThisMonth ? 'all' : 'month' ?>#recent-payments" style="font-size:12px;color:var(--text-muted)">
            <?= $paymentsThisMonth ? 'View recent payments' : 'View this month' ?> <?= appIcon('arrow-right') ?>
        </a>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($recent)): ?>
            <div class="dashboard-empty-state">
                <p><?= $paymentsThisMonth ? 'No payments have been posted this month.' : 'No payments have been posted yet.' ?></p>
                <a href="<?= APP_ROOT ?>/fees/add_payment.php" class="btn btn-ghost"><?= appIcon('plus') ?> Post a payment</a>
            </div>
        <?php else: ?>
        <div class="table-scroll">
        <table class="data-table fees-data-table">
            <caption class="sr-only">Recent fee payments</caption>
            <thead><tr>
                <?= $sortHeader('Student', 'student', $paymentSortKey, $paymentSortDirection, 'payment_sort', 'payment_dir') ?>
                <?= $sortHeader('Adm No', 'student_id', $paymentSortKey, $paymentSortDirection, 'payment_sort', 'payment_dir') ?>
                <?= $sortHeader('Amount', 'amount', $paymentSortKey, $paymentSortDirection, 'payment_sort', 'payment_dir', true) ?>
                <?= $sortHeader('Mode', 'mode', $paymentSortKey, $paymentSortDirection, 'payment_sort', 'payment_dir') ?>
                <?= $sortHeader('Reference', 'reference', $paymentSortKey, $paymentSortDirection, 'payment_sort', 'payment_dir') ?>
                <?= $sortHeader('Date Paid', 'date_paid', $paymentSortKey, $paymentSortDirection, 'payment_sort', 'payment_dir') ?>
                <?= $sortHeader('Posted', 'posted', $paymentSortKey, $paymentSortDirection, 'payment_sort', 'payment_dir') ?>
            </tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr data-row-href="<?= APP_ROOT ?>/fees/student.php?id=<?= (int) $r['fee_student_id'] ?>" tabindex="0"
                    aria-label="View payment history for <?= htmlspecialchars(formatPersonName($r['full_name']), ENT_QUOTES, 'UTF-8') ?>">
                    <td><a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $r['fee_student_id'] ?>" style="font-weight:600;color:var(--text-primary)"><?= htmlspecialchars(formatPersonName($r['full_name'])) ?></a></td>
                    <td><code style="font-size:12px"><?= htmlspecialchars($r['student_id']) ?></code></td>
                    <td class="fee-money"><strong><?= formatCurrency($r['amount']) ?></strong></td>
                    <td><span class="mode-badge mode-<?= str_replace(['_',' '],['-','-'],$r['mode']) ?>"><?= strtoupper(str_replace('_',' ',$r['mode'])) ?></span></td>
                    <td style="font-family:'Space Mono',monospace;font-size:11px"><?= htmlspecialchars($r['reference']??'—') ?></td>
                    <td class="date-value"><?= formatDate($r['date_paid']) ?></td>
                    <td class="date-value"><?= formatDate($r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.fees-data-table tbody tr[data-row-href]').forEach(function (row) {
    row.addEventListener('click', function (event) {
        if (event.target.closest('a, button, form, input, select')) return;
        window.location.href = row.dataset.rowHref;
    });
    row.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target === row) window.location.href = row.dataset.rowHref;
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>