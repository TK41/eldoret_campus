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
        ($statusFilter === 'none'    ? " AND paid = 0" : ""))) . "
    ORDER BY s.full_name ASC
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
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">👥 Students</h1>
        <p class="page-subtitle" id="students-count"><?= count($students) ?> student<?= count($students) !== 1 ? 's' : '' ?> shown</p>
    </div>
    <div style="display:flex;gap:8px">
        <a href="<?= APP_ROOT ?>/fees/add_student.php" class="btn btn-primary">➕ Add Student</a>
        <a href="<?= APP_ROOT ?>/fees/add_payment.php" class="btn btn-ghost">💳 Post Payment</a>
    </div>
</div>

<form method="get" action="<?= APP_ROOT ?>/fees/students.php" class="filter-bar" id="students-filter-form">
    <input type="text" id="search-input" name="q" placeholder="🔍 Search name or Adm No…"
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
    </select>
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
                No students found. <a href="<?= APP_ROOT ?>/fees/import.php">Import from Excel</a> or <a href="<?= APP_ROOT ?>/fees/add_student.php">add manually</a>.
            </div>
        <?php else: ?>
        <div id="no-results" style="display:none;padding:20px;text-align:center;color:var(--text-muted)">No students match your search.</div>
        <table class="data-table" id="students-table">
            <thead><tr>
                <th>Student Name</th>
                <th>Adm No</th>
                <th>Group</th>
                <th>Programme</th>
                <th>Total Fees</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Status</th>
                <th>Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($students as $s):
                $pct = $s['total_fees'] > 0 ? ($s['paid'] / $s['total_fees']) * 100 : 0;
                if ($s['balance'] <= 0)        $status = ['paid',    'Fully Paid'];
                elseif ($s['paid'] > 0)        $status = ['partial', 'Partial'];
                else                           $status = ['none',    'No Payment'];
            ?>
                <tr>
                    <td style="font-weight:600"><?= htmlspecialchars($s['full_name']) ?></td>
                    <td><code style="font-size:12px"><?= htmlspecialchars($s['student_id']) ?></code></td>
                    <td style="font-size:12px"><?= htmlspecialchars($s['group_name']) ?></td>
                    <td style="font-size:12px"><?= htmlspecialchars($s['programme'] ?? '—') ?></td>
                    <td class="balance-col">KES <?= number_format($s['total_fees']) ?></td>
                    <td class="balance-col" style="color:#16a34a">KES <?= number_format($s['paid']) ?></td>
                    <td class="balance-col" style="color:<?= $s['balance'] > 0 ? '#dc2626' : '#16a34a' ?>">
                        KES <?= number_format(max(0, $s['balance'])) ?>
                    </td>
                    <td><span class="status-pill status-<?= $status[0] ?>"><?= $status[1] ?></span></td>
                    <td>
                        <a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $s['fee_student_id'] ?>&<?= $filterQuery ?>" class="btn btn-ghost btn-sm">View</a>
                        <a href="<?= APP_ROOT ?>/fees/add_payment.php?student_id=<?= $s['fee_student_id'] ?>&<?= $filterQuery ?>" class="btn btn-primary btn-sm">+ Pay</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
