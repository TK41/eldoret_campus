<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
require_once __DIR__ . '/partials/student_sync.php';
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
    $national_id = trim($_POST['national_id'] ?? '');
    $gender      = trim($_POST['gender'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if (!$full_name)      $errors[] = 'Full name is required.';
    if (!$group_id)       $errors[] = 'Please select a group.';
    if ($total_fees <= 0) $errors[] = 'Total fees must be greater than zero.';
    if ($gender && !in_array($gender, ['male','female','other'], true)) $errors[] = 'Please select a valid gender.';

    if (empty($errors)) {
        $db->prepare("
            UPDATE fee_students
            SET full_name=?, programme=?, group_id=?, total_fees=?, national_id=?, gender=?, is_active=?
            WHERE fee_student_id=?
        ")->execute([
            $full_name,
            $programme ?: null,
            $group_id,
            $total_fees,
            $national_id ?: null,
            $gender ?: null,
            $is_active,
            $id,
        ]);

        $syncResult = syncToInventory($id, [
            'student_id' => $student['student_id'],
            'full_name'  => $full_name,
            'programme'  => $programme,
            'group_id'   => $group_id,
            'phone'      => '',
            'is_active'  => $is_active,
        ], $db);

        if (!empty($syncResult['email'])) {
            $db->prepare("UPDATE fee_students SET email = ? WHERE fee_student_id = ?")
               ->execute([$syncResult['email'], $id]);
        }

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
                <label>National ID</label>
                <input type="text" name="national_id" class="form-control"
                       placeholder="Optional national ID number"
                       value="<?= htmlspecialchars($_POST['national_id'] ?? $student['national_id'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Gender</label>
                <select name="gender" class="form-control">
                    <option value="">— Select gender —</option>
                    <option value="male" <?= ((($_POST['gender'] ?? $student['gender'] ?? '') === 'male') ? 'selected' : '') ?>>Male</option>
                    <option value="female" <?= ((($_POST['gender'] ?? $student['gender'] ?? '') === 'female') ? 'selected' : '') ?>>Female</option>
                    <option value="other" <?= ((($_POST['gender'] ?? $student['gender'] ?? '') === 'other') ? 'selected' : '') ?>>Other</option>
                </select>
            </div>
            <div class="form-group">
                <label>Group *</label>
                <select name="group_id" class="form-control" required>
                    <?php foreach ($groups as $g):
                        $groupLabel = $g['name'] . (($g['academic_year'] ?? '') ? ' (' . $g['academic_year'] . ')' : '');
                    ?>
                        <option value="<?= $g['group_id'] ?>"
                                <?= (($_POST['group_id'] ?? $student['group_id']) == $g['group_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($groupLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Total Fees (KES) *</label>
                <input type="number" name="total_fees" class="form-control" min="1" step="0.01"
                       value="<?= htmlspecialchars($_POST['total_fees'] ?? $student['total_fees']) ?>" required>
            </div>
            <div class="form-group full-width" style="margin-top:-6px">
                <label>Active Student</label>
                <label class="toggle-label">
                    <input type="checkbox" name="is_active" value="1"
                        <?= ((isset($_POST['is_active']) ? $_POST['is_active'] : $student['is_active']) ? 'checked' : '') ?> >
                    <span class="toggle-switch"></span>
                    <span class="toggle-text">Student account is active and may borrow items</span>
                </label>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-primary" style="flex:1">✓ Save Changes</button>
                <a href="<?= APP_ROOT ?>/fees/student.php?id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
