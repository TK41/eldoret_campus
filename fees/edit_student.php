<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Edit Student';
$activePage = 'fees_students';
$db = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_ROOT . '/fees/students.php'); exit; }

$student = $db->prepare("SELECT * FROM fee_students WHERE fee_student_id=?");
$student->execute([$id]);
$student = $student->fetch();
if (!$student) { header('Location: ' . APP_ROOT . '/fees/students.php'); exit; }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name  = trim($_POST['full_name'] ?? '');
    $programme  = trim($_POST['programme'] ?? '');
    $group_id   = intval($_POST['group_id'] ?? 0);
    $total_fees = floatval($_POST['total_fees'] ?? 0);

    if (!$full_name)     $errors[] = 'Full name is required.';
    if (!$group_id)      $errors[] = 'Please select a group.';
    if ($total_fees <= 0) $errors[] = 'Total fees must be greater than zero.';

    if (empty($errors)) {
        $db->prepare("
            UPDATE fee_students SET full_name=?, programme=?, group_id=?, total_fees=? WHERE fee_student_id=?
        ")->execute([$full_name, $programme ?: null, $group_id, $total_fees, $id]);
        setFlash('success', 'Student updated successfully.');
        header('Location: ' . APP_ROOT . '/fees/student.php?id=' . $id); exit;
    }
}

$groups = $db->query("SELECT * FROM fee_groups ORDER BY group_id")->fetchAll();
include __DIR__ . '/partials/header.php';
?>

<style>
.edit-form { max-width:560px; }
.form-group { margin-bottom:18px; }
.form-group label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted); margin-bottom:7px; }
.form-control { width:100%; padding:10px 13px; border-radius:8px; border:1.5px solid var(--border); background:var(--input-bg); color:var(--text-primary); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; transition:border-color .2s; }
.form-control:focus { border-color:#d97706; box-shadow:0 0 0 3px rgba(217,119,6,.1); }
</style>

<div class="page-header">
    <div><h1 class="page-title">✏️ Edit Student</h1></div>
    <a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $id ?>" class="btn btn-ghost">← Back to Ledger</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card edit-form">
    <div class="card-body">
        <div style="margin-bottom:16px;padding:10px 14px;background:var(--surface-hover);border-radius:8px;font-size:13px">
            <strong>Adm No:</strong> <code><?= htmlspecialchars($student['student_id']) ?></code>
            <span style="color:var(--text-muted);font-size:11px;margin-left:8px">(cannot be changed)</span>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" class="form-control"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? $student['full_name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Programme</label>
                <input type="text" name="programme" class="form-control"
                       value="<?= htmlspecialchars($_POST['programme'] ?? $student['programme'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Group *</label>
                <select name="group_id" class="form-control" required>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['group_id'] ?>"
                                <?= (($_POST['group_id'] ?? $student['group_id']) == $g['group_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Total Fees (KES) *</label>
                <input type="number" name="total_fees" class="form-control" min="1" step="0.01"
                       value="<?= htmlspecialchars($_POST['total_fees'] ?? $student['total_fees']) ?>" required>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-primary" style="flex:1">✓ Save Changes</button>
                <a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
