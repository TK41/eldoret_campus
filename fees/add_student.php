<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Add Student';
$activePage = 'fees_students';
$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id  = trim($_POST['student_id'] ?? '');
    $full_name   = trim($_POST['full_name'] ?? '');
    $programme   = trim($_POST['programme'] ?? '');
    $group_id    = intval($_POST['group_id'] ?? 0);
    $total_fees  = floatval($_POST['total_fees'] ?? 0);

    if (!$student_id) $errors[] = 'Admission number is required.';
    if (!$full_name)  $errors[] = 'Full name is required.';
    if (!$group_id)   $errors[] = 'Please select a group.';
    if ($total_fees <= 0) $errors[] = 'Total fees must be greater than zero.';

    if (empty($errors)) {
        // Check duplicate
        $exists = $db->prepare("SELECT fee_student_id FROM fee_students WHERE student_id=?");
        $exists->execute([$student_id]);
        if ($exists->fetch()) {
            $errors[] = "Admission number $student_id already exists.";
        }
    }

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO fee_students (student_id, full_name, programme, group_id, total_fees)
            VALUES (?,?,?,?,?)
        ")->execute([$student_id, $full_name, $programme ?: null, $group_id, $total_fees]);
        $newId = $db->lastInsertId();
        setFlash('success', "Student $full_name added successfully.");
        header('Location: ' . APP_ROOT . '/fees/student.php?id=' . $newId);
        exit;
    }
}

$groups = $db->query("SELECT * FROM fee_groups ORDER BY group_id")->fetchAll();
include __DIR__ . '/partials/header.php';
?>

<style>
.add-form { max-width:560px; }
.form-group { margin-bottom:18px; }
.form-group label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted); margin-bottom:7px; }
.form-control { width:100%; padding:10px 13px; border-radius:8px; border:1.5px solid var(--border); background:var(--input-bg); color:var(--text-primary); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; transition:border-color .2s; }
.form-control:focus { border-color:#d97706; box-shadow:0 0 0 3px rgba(217,119,6,.1); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">➕ Add Student</h1>
        <p class="page-subtitle">Manually register a student in the fees module</p>
    </div>
    <a href="<?= APP_ROOT ?>/fees/students.php" class="btn btn-ghost">← Back</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card add-form">
    <div class="card-body">
        <form method="POST">
            <div class="form-group">
                <label>Admission Number *</label>
                <input type="text" name="student_id" class="form-control" placeholder="e.g. 12822/25"
                       value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" class="form-control" placeholder="e.g. JANE ACHIENG ODHIAMBO"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Programme</label>
                <input type="text" name="programme" class="form-control"
                       placeholder="e.g. CERT IN FILM PRODUCTION"
                       value="<?= htmlspecialchars($_POST['programme'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Group *</label>
                <select name="group_id" class="form-control" id="group-select" onchange="setFees()" required>
                    <option value="">— Select group —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['group_id'] ?>"
                                data-fees="<?= $g['total_fees'] ?>"
                                <?= (($_POST['group_id'] ?? '') == $g['group_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Total Fees (KES) *</label>
                <input type="number" name="total_fees" class="form-control" id="fees-input"
                       min="1" step="0.01" placeholder="Auto-filled from group"
                       value="<?= htmlspecialchars($_POST['total_fees'] ?? '') ?>" required>
                <span style="font-size:11px;color:var(--text-muted);margin-top:4px;display:block">Auto-filled from group but can be overridden.</span>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-primary" style="flex:1">✓ Add Student</button>
                <a href="<?= APP_ROOT ?>/fees/students.php" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function setFees() {
    const sel = document.getElementById('group-select');
    const opt = sel.options[sel.selectedIndex];
    const fees = opt.getAttribute('data-fees');
    if (fees) document.getElementById('fees-input').value = fees;
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
