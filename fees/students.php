<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Students';
$activePage = 'fees_students';
$db = getDB();

$groupFilter  = intval($_GET['group'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$sortColumns = [
    'name' => 's.full_name',
    'student_id' => 's.student_id',
    'group' => 'group_name',
    'programme' => 's.programme',
    'total' => 's.total_fees',
    'paid' => 'COALESCE(SUM(p.amount),0)',
    'balance' => '(s.total_fees - COALESCE(SUM(p.amount),0))',
    'status' => "CASE WHEN COALESCE(SUM(p.amount),0) >= s.total_fees THEN 0 WHEN COALESCE(SUM(p.amount),0) > 0 THEN 1 ELSE 2 END",
];
$requestedSort = $_GET['sort'] ?? 'name';
$sortKey = is_string($requestedSort) && isset($sortColumns[$requestedSort]) ? $requestedSort : 'name';
$requestedDirection = $_GET['dir'] ?? 'asc';
$sortDirection = is_string($requestedDirection) && strtolower($requestedDirection) === 'desc' ? 'DESC' : 'ASC';
$buildStudentsUrl = static function (array $changes = []): string {
    $query = $_GET;
    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }
    return APP_ROOT . '/fees/students.php' . ($query ? '?' . http_build_query($query) : '');
};
$sortHeader = static function (string $label, string $key, bool $money = false) use ($sortKey, $sortDirection, $buildStudentsUrl): string {
    $nextDirection = $sortKey === $key && $sortDirection === 'ASC' ? 'desc' : 'asc';
    $ariaSort = $sortKey === $key ? ($sortDirection === 'ASC' ? 'ascending' : 'descending') : 'none';
    $indicator = $sortKey === $key ? ($sortDirection === 'ASC' ? '↑' : '↓') : '↕';
    $href = htmlspecialchars($buildStudentsUrl(['sort' => $key, 'dir' => $nextDirection]), ENT_QUOTES, 'UTF-8');
    return '<th scope="col"' . ($money ? ' class="fee-money"' : '') . ' aria-sort="' . $ariaSort . '"><a class="table-sort-link" href="' . $href . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '<span aria-hidden="true">' . $indicator . '</span></a></th>';
};

$filterQuery = http_build_query([
    'group' => $groupFilter ?: null,
    'status' => $statusFilter ?: null,
    'q' => $search ?: null,
]);

$where = ['s.is_active=1'];
$params = [];

if ($groupFilter) { $where[] = 's.group_id=?'; $params[] = $groupFilter; }
if ($search)      { $where[] = '(s.full_name LIKE ? OR s.student_id LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereSQL = implode(' AND ', $where);

$students = $db->prepare("
    SELECT s.*,
           CONCAT(g.name, IF(g.academic_year <> '', CONCAT(' (', g.academic_year, ')'), '')) AS group_name,
           COALESCE(SUM(p.amount),0) AS paid,
           s.total_fees - COALESCE(SUM(p.amount),0) AS balance
    FROM fee_students s
    JOIN fee_groups g ON g.group_id=s.group_id
    LEFT JOIN fee_payments p ON p.fee_student_id=s.fee_student_id
    WHERE $whereSQL
    GROUP BY s.fee_student_id
    HAVING 1=1
    " . ($statusFilter === 'paid'    ? " AND balance <= 0" :
        ($statusFilter === 'partial' ? " AND paid > 0 AND balance > 0" :
        ($statusFilter === 'none'    ? " AND paid = 0" :
        ($statusFilter === 'balance' ? " AND balance > 0" :
        ($statusFilter === 'collected' ? " AND paid > 0" : ""))))) . "
    ORDER BY " . $sortColumns[$sortKey] . " $sortDirection, s.fee_student_id ASC
");
$students->execute($params);
$students = $students->fetchAll();

$groups = $db->query("SELECT * FROM fee_groups ORDER BY group_id")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<style>
.filter-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; align-items:center; }
.filter-bar input, .filter-bar select { padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--input-bg); color:var(--text-primary); font-family:inherit; font-size:13px; }
.filter-bar input { flex:1; min-width:200px; }
.status-pill { display:inline-block; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:700; }
.status-paid    { background:rgba(22,163,74,.1);  color:#16a34a; }
.status-partial { background:rgba(217,119,6,.1);   color:#d97706; }
.status-none    { background:rgba(220,38,38,.1);   color:#dc2626; }
.balance-col { font-family:'Space Mono',monospace; font-size:12px; }
.fees-data-table th,.fees-data-table td{padding:12px 14px}
.fees-data-table thead th{position:sticky;top:0;z-index:2;background:var(--surface)}
.fees-data-table .fee-money{text-align:right;font-family:'Space Mono',monospace;font-variant-numeric:tabular-nums;white-space:nowrap}
.fees-data-table tbody tr[data-row-href]{cursor:pointer}
.fees-data-table tbody tr[data-row-href]:hover td{background:var(--table-hover)}
.fees-data-table tbody tr[data-row-href]:focus-visible{outline:2px solid var(--module-accent);outline-offset:-2px}
.student-payment-progress{display:flex;align-items:center;gap:7px;min-width:110px}
.student-payment-progress-track{display:block;width:70px;height:6px;overflow:hidden;border-radius:999px;background:var(--border)}
.student-payment-progress-track span{display:block;height:100%;border-radius:inherit;background:var(--color-success)}
.student-payment-progress-label{font-size:11px;color:var(--text-muted);font-variant-numeric:tabular-nums}
.table-sort-link{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;white-space:nowrap}
.table-sort-link:hover{color:var(--module-accent)}
.table-sort-link span{font-size:11px;color:var(--text-muted)}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= appIcon('users') ?> Students</h1>
        <p class="page-subtitle" id="students-count"><?= pluralizeCount(count($students), 'student') ?> shown</p>
    </div>
    <div style="display:flex;gap:8px">
        <a href="<?= APP_ROOT ?>/fees/add_student.php" class="btn btn-primary"><?= appIcon('plus') ?> Add Student</a>
        <a href="<?= APP_ROOT ?>/fees/add_payment.php" class="btn btn-ghost"><?= appIcon('credit-card') ?> Post Payment</a>
    </div>
</div>

<form method="get" action="<?= APP_ROOT ?>/fees/students.php" class="filter-bar" id="students-filter-form">
    <input type="text" id="search-input" name="q" placeholder="Search name or Adm No…"
           value="<?= htmlspecialchars($search) ?>">
    <select id="group-filter" name="group" onchange="this.form.submit()">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g):
            $groupLabel = $g['name'] . (($g['academic_year'] ?? '') ? ' (' . $g['academic_year'] . ')' : '');
        ?>
            <option value="<?= $g['group_id'] ?>" <?= $groupFilter == $g['group_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($groupLabel) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select id="status-filter" name="status" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="paid"    <?= $statusFilter==='paid'    ? 'selected':'' ?>>Fully Paid</option>
        <option value="partial" <?= $statusFilter==='partial' ? 'selected':'' ?>>Partial</option>
        <option value="none"    <?= $statusFilter==='none'    ? 'selected':'' ?>>No Payment</option>
        <option value="balance" <?= $statusFilter==='balance' ? 'selected':'' ?>>Balance Due</option>
        <option value="collected" <?= $statusFilter==='collected' ? 'selected':'' ?>>Has Payments</option>
    </select>
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortKey) ?>">
    <input type="hidden" name="dir" value="<?= strtolower($sortDirection) ?>">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('students-filter-form');
    const searchInput = document.getElementById('search-input');
    const table = document.getElementById('students-table');
    const countEl = document.getElementById('students-count');
    const noResults = document.getElementById('no-results');

    if (!form || !searchInput || !table || !countEl) return;

    const rows = Array.from(table.querySelectorAll('tbody tr'));
    rows.forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('a, button, form, input, select')) return;
            if (row.dataset.rowHref) window.location.href = row.dataset.rowHref;
        });
        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target === row && row.dataset.rowHref) {
                window.location.href = row.dataset.rowHref;
            }
        });
    });

    function updateCount(visible) {
        countEl.textContent = visible + ' student' + (visible !== 1 ? 's' : '') + ' shown';
    }

    function filterTable() {
        const q = searchInput.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(tr => {
            const name = (tr.cells[0]?.textContent || '').toLowerCase();
            const adm = (tr.cells[1]?.textContent || '').toLowerCase();
            if (!q || name.includes(q) || adm.includes(q)) {
                tr.style.display = '';
                visible++;
            } else {
                tr.style.display = 'none';
            }
        });
        if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
        updateCount(visible);
    }

    let debounceTimer;
    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(filterTable, 250);
    });

    // Allow Enter to submit the form for a server-side search if desired
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            form.submit();
        }
    });

    // Run initial filter if there's an existing query
    if (searchInput.value.trim()) filterTable();
});
</script>

<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (empty($students)): ?>
            <div style="padding:40px;text-align:center;color:var(--text-muted)">
                <?php if ($statusFilter === 'balance'): ?>
                    No students have an outstanding balance.
                <?php elseif ($statusFilter === 'collected'): ?>
                    No students have posted payments.
                <?php else: ?>
                    No students found. <a href="<?= APP_ROOT ?>/fees/import.php">Import from Excel</a> or <a href="<?= APP_ROOT ?>/fees/add_student.php">add manually</a>.
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div id="no-results" style="display:none;padding:20px;text-align:center;color:var(--text-muted)">No students match your search.</div>
        <div class="table-scroll">
        <table class="data-table fees-data-table" id="students-table">
            <caption class="sr-only">Fees students and payment progress</caption>
            <thead><tr>
                <?= $sortHeader('Student Name', 'name') ?>
                <?= $sortHeader('Adm No', 'student_id') ?>
                <?= $sortHeader('Group', 'group') ?>
                <?= $sortHeader('Programme', 'programme') ?>
                <?= $sortHeader('Total Fees', 'total', true) ?>
                <?= $sortHeader('Paid', 'paid', true) ?>
                <th scope="col">Progress</th>
                <?= $sortHeader('Balance', 'balance', true) ?>
                <?= $sortHeader('Status', 'status') ?>
                <th scope="col">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($students as $s):
                $pct = $s['total_fees'] > 0 ? min(100, max(0, ($s['paid'] / $s['total_fees']) * 100)) : 0;
                if ($s['balance'] <= 0)        $status = ['paid',    'Fully Paid'];
                elseif ($s['paid'] > 0)        $status = ['partial', 'Partial'];
                else                           $status = ['none',    'No Payment'];
                $studentUrl = APP_ROOT . '/fees/student.php?id=' . (int) $s['fee_student_id'];
            ?>
                <tr data-row-href="<?= htmlspecialchars($studentUrl, ENT_QUOTES, 'UTF-8') ?>" tabindex="0"
                    aria-label="View fees for <?= htmlspecialchars(formatPersonName($s['full_name']), ENT_QUOTES, 'UTF-8') ?>">
                    <td style="font-weight:600"><?= htmlspecialchars(formatPersonName($s['full_name'])) ?></td>
                    <td><code style="font-size:12px"><?= htmlspecialchars($s['student_id']) ?></code></td>
                    <td style="font-size:12px"><?= htmlspecialchars(formatTitleCase($s['group_name'])) ?></td>
                    <td style="font-size:12px"><?= htmlspecialchars(formatTitleCase($s['programme'] ?? '—')) ?></td>
                    <td class="fee-money"><?= formatCurrency($s['total_fees']) ?></td>
                    <td class="fee-money" style="color:#16a34a"><?= formatCurrency($s['paid']) ?></td>
                    <td>
                        <div class="student-payment-progress" role="progressbar"
                             aria-label="<?= htmlspecialchars(formatPersonName($s['full_name'])) ?> fee payment progress"
                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= round($pct, 1) ?>"
                             aria-valuetext="<?= formatPercentage($pct) ?> paid">
                            <span class="student-payment-progress-track"><span style="width:<?= $pct ?>%"></span></span>
                            <span class="student-payment-progress-label"><?= formatPercentage($pct) ?></span>
                        </div>
                    </td>
                    <td class="fee-money" style="color:<?= $s['balance'] > 0 ? '#dc2626' : '#16a34a' ?>">
                        <?= formatCurrency(max(0, $s['balance'])) ?>
                    </td>
                    <td><span class="status-pill status-<?= $status[0] ?>"><?= $status[1] ?></span></td>
                    <td>
                        <a href="<?= htmlspecialchars($studentUrl . '&' . $filterQuery, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost btn-sm">View</a>
                        <a href="<?= APP_ROOT ?>/fees/add_payment.php?student_id=<?= (int) $s['fee_student_id'] ?>&<?= htmlspecialchars($filterQuery, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-sm">+ Pay</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
