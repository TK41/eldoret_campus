<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Groups';
$activePage = 'fees_groups';
$db = getDB();
$errors = [];

// Add group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group'])) {
    $name          = trim($_POST['name'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? '');
    $programme     = trim($_POST['programme'] ?? '');
    $intake        = trim($_POST['intake'] ?? '');
    $year_label    = trim($_POST['year_label'] ?? '');
    $total_fees    = floatval($_POST['total_fees'] ?? 0);

    if (!$name)          $errors[] = 'Group name is required.';
    if (!$academic_year) $errors[] = 'Academic year is required.';
    if ($total_fees <= 0) $errors[] = 'Total fees must be greater than zero.';

    if (empty($errors)) {
        $db->prepare("INSERT INTO fee_groups (name, academic_year, programme, intake, year_label, total_fees) VALUES (?,?,?,?,?,?)")
           ->execute([$name, $academic_year, $programme, $intake ?: null, $year_label ?: null, $total_fees]);
        setFlash('success', "Group '{$name} ({$academic_year})' created.");
        header('Location: ' . APP_ROOT . '/fees/groups.php'); exit;
    }
}

// Edit fees amount
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fees'])) {
    $gid   = intval($_POST['group_id']);
    $fees  = floatval($_POST['total_fees']);
    if ($fees > 0) {
        $db->prepare("UPDATE fee_groups SET total_fees=? WHERE group_id=?")->execute([$fees, $gid]);
        setFlash('success', 'Group fees updated.');
    }
    header('Location: ' . APP_ROOT . '/fees/groups.php'); exit;
}

$groups = $db->query("
    SELECT g.*,
           COALESCE(fs.student_count, 0) AS student_count,
           COALESCE(fp.collected, 0) AS collected,
           COALESCE(fs.expected, 0) AS expected
    FROM fee_groups g
    LEFT JOIN (
        SELECT group_id,
               COUNT(*) AS student_count,
               SUM(total_fees) AS expected
        FROM fee_students
        WHERE is_active = 1
        GROUP BY group_id
    ) fs ON fs.group_id = g.group_id
    LEFT JOIN (
        SELECT s.group_id,
               SUM(p.amount) AS collected
        FROM fee_payments p
        JOIN fee_students s ON s.fee_student_id = p.fee_student_id AND s.is_active = 1
        GROUP BY s.group_id
    ) fp ON fp.group_id = g.group_id
    ORDER BY g.group_id
")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<style>
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted); margin-bottom:6px; }
.form-control { width:100%; padding:10px 13px; border-radius:8px; border:1.5px solid var(--border); background:var(--input-bg); color:var(--text-primary); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; transition:border-color .2s; }
.form-control:focus { border-color:#d97706; box-shadow:0 0 0 3px rgba(217,119,6,.1); }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.group-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:16px; }
.group-card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; }
.group-title { font-size:15px; font-weight:700; }
.group-tag { display:inline-block; padding:2px 9px; border-radius:10px; font-size:11px; font-weight:700; text-transform:uppercase; background:rgba(217,119,6,.1); color:#d97706; }
.inline-edit { display:flex; gap:8px; align-items:center; }
.inline-edit input { padding:7px 10px; border-radius:6px; border:1px solid var(--border); background:var(--input-bg); color:var(--text-primary); font-family:'Space Mono',monospace; font-size:13px; width:140px; }
</style>

<div class="page-header">
    <div><h1 class="page-title">🗂 Groups</h1><p class="page-subtitle">Manage student intake groups and fee structures</p></div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error" style="margin-bottom:16px">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Existing groups -->
<?php foreach ($groups as $g):
    $pct = $g['expected'] > 0 ? min(100, ($g['collected'] / $g['expected']) * 100) : 0;
    $groupLabel = $g['name'] . (($g['academic_year'] ?? '') ? ' (' . $g['academic_year'] . ')' : '');
?>
<div class="group-card">
    <div class="group-card-header">
        <div>
            <div class="group-title"><?= htmlspecialchars($groupLabel) ?></div>
            <div style="margin-top:4px;font-size:12px;color:var(--text-muted)">
                <span class="group-tag"><?= ucfirst($g['programme']) ?></span>
                <?php if ($g['intake']): ?> · <?= htmlspecialchars($g['intake']) ?><?php endif; ?>
                <?php if ($g['year_label']): ?> · <?= htmlspecialchars($g['year_label']) ?><?php endif; ?>
                · <?= $g['student_count'] ?> students
            </div>
        </div>
        <div style="text-align:right">
            <div style="font-size:18px;font-weight:700;font-family:'Space Mono',monospace">KES <?= number_format($g['total_fees']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)">per student</div>
        </div>
    </div>
    <div style="background:var(--border);height:6px;border-radius:3px;overflow:hidden;margin-bottom:10px">
        <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,#d97706,#f59e0b);border-radius:3px"></div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div style="font-size:12px;color:var(--text-muted)">
            Collected: <strong style="color:#16a34a">KES <?= number_format($g['collected']) ?></strong>
            · Outstanding: <strong style="color:#dc2626">KES <?= number_format(max(0,$g['expected']-$g['collected'])) ?></strong>
        </div>
        <form method="POST" class="inline-edit">
            <input type="hidden" name="group_id" value="<?= $g['group_id'] ?>">
            <input type="number" name="total_fees" value="<?= $g['total_fees'] ?>" min="1" step="0.01">
            <button type="submit" name="update_fees" class="btn btn-ghost btn-sm">Update Fees</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Add new group -->
<div class="card" style="margin-top:8px">
    <div class="card-header"><h2 style="font-size:15px;font-weight:700;margin:0">➕ Add New Group</h2></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Group Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. CERT JAN-INTAKE"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Academic Year *</label>
                    <input type="text" name="academic_year" class="form-control" placeholder="e.g. 2024/2025"
                           value="<?= htmlspecialchars($_POST['academic_year'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Programme *</label>
                    <select name="programme" class="form-control">
                        <option value="certificate">Certificate</option>
                        <option value="diploma">Diploma</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Intake (e.g. Jan 2026)</label>
                    <input type="text" name="intake" class="form-control" placeholder="e.g. January 2026"
                           value="<?= htmlspecialchars($_POST['intake'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Year Label</label>
                    <input type="text" name="year_label" class="form-control" placeholder="e.g. Year 1"
                           value="<?= htmlspecialchars($_POST['year_label'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group" style="max-width:240px">
                <label>Total Fees (KES) *</label>
                <input type="number" name="total_fees" class="form-control" min="1" step="0.01"
                       placeholder="e.g. 129000" value="<?= htmlspecialchars($_POST['total_fees'] ?? '') ?>">
            </div>
            <button type="submit" name="add_group" class="btn btn-primary">✓ Create Group</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
