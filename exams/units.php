<?php
// ============================================================
// exams/units.php
// Manage exam units / subjects
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Units / Subjects';
$activePage = 'exam_units';
$db = getDB();
$errors = [];

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($_POST['action'] === 'create') {
        $code  = strtoupper(trim($_POST['unit_code'] ?? ''));
        $name  = trim($_POST['unit_name'] ?? '');
        $prog  = trim($_POST['programme'] ?? '');
        $year  = trim($_POST['year_level'] ?? '');
        $sem   = intval($_POST['semester'] ?? 1);

        if (!$code) $errors[] = 'Unit code is required.';
        if (!$name) $errors[] = 'Unit name is required.';
        if (!$prog) $errors[] = 'Programme is required.';

        if (empty($errors)) {
            try {
                $db->prepare("
                    INSERT INTO exam_units (unit_code, unit_name, programme, year_level, semester)
                    VALUES (?,?,?,?,?)
                ")->execute([$code, $name, $prog, $year ?: null, $sem]);
                setFlash('success', "Unit «$code — $name» added.");
                header('Location: ' . APP_ROOT . '/exams/units.php');
                exit;
            } catch (PDOException $e) {
                $errors[] = str_contains($e->getMessage(), 'Duplicate') ? "Unit code «$code» already exists." : 'DB error: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'toggle' && isSuperAdmin()) {
        $uid    = intval($_POST['unit_id']);
        $active = intval($_POST['is_active']);
        $db->prepare("UPDATE exam_units SET is_active=? WHERE unit_id=?")->execute([$active, $uid]);
        setFlash('success', 'Unit updated.');
        header('Location: ' . APP_ROOT . '/exams/units.php');
        exit;
    }

    if ($_POST['action'] === 'delete' && isSuperAdmin()) {
        $uid = intval($_POST['unit_id']);
        $db->prepare("DELETE FROM exam_units WHERE unit_id=?")->execute([$uid]);
        setFlash('success', 'Unit deleted.');
        header('Location: ' . APP_ROOT . '/exams/units.php');
        exit;
    }
}

$units = $db->query("
    SELECT u.*,
           COUNT(r.result_id) AS result_count
    FROM exam_units u
    LEFT JOIN exam_results r ON r.unit_id = u.unit_id
    GROUP BY u.unit_id
    ORDER BY u.programme, u.year_level, u.semester, u.unit_code
")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<style>
.prog-cert    { background:rgba(37,99,235,.1);  color:#1d4ed8; }
.prog-diploma { background:rgba(139,92,246,.1); color:#7c3aed; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">📚 Units / Subjects</h1>
        <p class="page-subtitle"><?= count($units) ?> unit<?= count($units)!==1?'s':'' ?> configured</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('new-unit-modal').style.display='flex'">
        ➕ Add Unit
    </button>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    <button onclick="this.parentElement.remove()" class="alert-close">×</button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body" style="padding:0">
    <?php if (empty($units)): ?>
        <div style="padding:50px;text-align:center;color:var(--text-muted)">
            <div style="font-size:40px;margin-bottom:12px">📚</div>
            <p>No units yet. Add some or they'll be seeded from the SQL file.</p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr>
            <th>Code</th>
            <th>Unit Name</th>
            <th>Programme</th>
            <th>Year</th>
            <th style="text-align:center">Sem</th>
            <th style="text-align:center">Results</th>
            <th style="text-align:center">Status</th>
            <?php if (isSuperAdmin()): ?><th>Actions</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php
        $lastProg = '';
        foreach ($units as $u):
            if ($u['programme'] !== $lastProg) {
                $lastProg = $u['programme'];
        ?>
        <tr>
            <td colspan="<?= isSuperAdmin() ? 8 : 7 ?>"
                style="background:var(--surface);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:8px 16px">
                📗 <?= ucfirst($u['programme']) ?> Programme
            </td>
        </tr>
        <?php } ?>
        <tr style="<?= !$u['is_active'] ? 'opacity:.5' : '' ?>">
            <td><code style="font-size:12px;font-weight:700"><?= htmlspecialchars($u['unit_code']) ?></code></td>
            <td style="font-weight:600"><?= htmlspecialchars($u['unit_name']) ?></td>
            <td>
                <span class="status-pill <?= $u['programme']==='certificate' ? 'prog-cert' : 'prog-diploma' ?>"
                      style="font-size:11px;padding:3px 10px;border-radius:10px">
                    <?= ucfirst($u['programme']) ?>
                </span>
            </td>
            <td style="font-size:12px"><?= htmlspecialchars($u['year_level'] ?? '—') ?></td>
            <td style="text-align:center;font-family:'Space Mono',monospace;font-size:12px"><?= $u['semester'] ?></td>
            <td style="text-align:center;font-family:'Space Mono',monospace;font-size:12px"><?= $u['result_count'] ?></td>
            <td style="text-align:center">
                <?php if ($u['is_active']): ?>
                    <span style="color:#16a34a;font-size:12px;font-weight:600">✅ Active</span>
                <?php else: ?>
                    <span style="color:var(--text-muted);font-size:12px">Inactive</span>
                <?php endif; ?>
            </td>
            <?php if (isSuperAdmin()): ?>
            <td>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="unit_id" value="<?= $u['unit_id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $u['is_active'] ? 0 : 1 ?>">
                    <button type="submit" class="btn btn-ghost btn-sm"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <?php if ($u['result_count'] == 0): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="unit_id" value="<?= $u['unit_id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Delete this unit?')">🗑</button>
                </form>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>
</div>

<!-- Grade Scale Reference -->
<div class="card" style="margin-top:20px">
    <div class="card-header"><span class="card-title">📐 Grade Scale Reference</span></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
            <?php
            $scale = [
                ['A','70–100','Excellent',   '#15803d','rgba(22,163,74,.1)'],
                ['B','60–69', 'Very Good',   '#1d4ed8','rgba(37,99,235,.1)'],
                ['C','50–59', 'Good',        '#b45309','rgba(217,119,6,.1)'],
                ['D','40–49', 'Satisfactory','#c2410c','rgba(234,88,12,.1)'],
                ['F','0–39',  'Fail',        '#dc2626','rgba(220,38,38,.08)'],
            ];
            foreach ($scale as [$g,$range,$label,$col,$bg]):
            ?>
            <div style="background:<?= $bg ?>;border:1px solid <?= $col ?>33;border-radius:10px;padding:14px 16px;text-align:center">
                <div style="font-size:24px;font-weight:700;color:<?= $col ?>;font-family:'Space Mono',monospace"><?= $g ?></div>
                <div style="font-size:13px;font-weight:700;margin:4px 0"><?= $range ?></div>
                <div style="font-size:11px;color:var(--text-muted)"><?= $label ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-top:16px">
            CA (Continuous Assessment) is out of <strong>30</strong> marks.
            Final Exam is out of <strong>70</strong> marks.
            Combined total is out of <strong>100</strong>.
        </p>
    </div>
</div>

<!-- ── Add Unit Modal ── -->
<div id="new-unit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center">
    <div style="background:var(--surface);border-radius:16px;padding:32px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3);margin:16px">
        <h2 style="margin-bottom:20px;font-size:18px">➕ Add New Unit</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create">

            <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;margin-bottom:16px">
                <div>
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Code <span class="required">*</span></label>
                    <input type="text" name="unit_code" placeholder="e.g. CFP-107" required
                           value="<?= htmlspecialchars($_POST['unit_code'] ?? '') ?>"
                           style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:'Space Mono',monospace;font-size:13px;text-transform:uppercase">
                </div>
                <div>
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Unit Name <span class="required">*</span></label>
                    <input type="text" name="unit_name" placeholder="e.g. Advanced Lighting" required
                           value="<?= htmlspecialchars($_POST['unit_name'] ?? '') ?>"
                           style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:24px">
                <div>
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Programme <span class="required">*</span></label>
                    <select name="programme" required style="width:100%;padding:10px 8px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                        <option value="">—</option>
                        <option value="certificate">Certificate</option>
                        <option value="diploma">Diploma</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Year Level</label>
                    <select name="year_level" style="width:100%;padding:10px 8px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                        <option value="">—</option>
                        <option value="Year 1">Year 1</option>
                        <option value="Year 2">Year 2</option>
                        <option value="Year 3">Year 3</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Semester</label>
                    <select name="semester" style="width:100%;padding:10px 8px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                        <option value="1">Sem 1</option>
                        <option value="2">Sem 2</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('new-unit-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Unit</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('new-unit-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
<?php if (!empty($errors)): ?>
document.getElementById('new-unit-modal').style.display = 'flex';
<?php endif; ?>
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
