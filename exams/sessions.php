<?php
// ============================================================
// exams/sessions.php
// Create & manage exam sessions
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Exam Sessions';
$activePage = 'exam_sessions';
$db  = getDB();
$errors = [];

// ── POST: create session ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();

    if ($_POST['action'] === 'create') {
        $name     = trim($_POST['name'] ?? '');
        $prog     = trim($_POST['programme'] ?? '');
        $year     = trim($_POST['year_level'] ?? '');
        $sem      = intval($_POST['semester'] ?? 1);
        $ay       = trim($_POST['academic_year'] ?? '');

        if (!$name)  $errors[] = 'Session name is required.';
        if (!$prog)  $errors[] = 'Programme is required.';
        if (!$ay)    $errors[] = 'Academic year is required.';

        if (empty($errors)) {
            $db->prepare("
                INSERT INTO exam_sessions (name, programme, year_level, semester, academic_year, created_by)
                VALUES (?,?,?,?,?,?)
            ")->execute([$name, $prog, $year ?: null, $sem, $ay, $_SESSION['admin_id']]);
            setFlash('success', "Session \"$name\" created successfully.");
            header('Location: ' . APP_ROOT . '/exams/sessions.php');
            exit;
        }
    }

    if ($_POST['action'] === 'toggle_lock') {
        $sid  = intval($_POST['session_id']);
        $lock = intval($_POST['lock']);
        $db->prepare("UPDATE exam_sessions SET is_locked=? WHERE session_id=?")->execute([$lock, $sid]);
        $msg = $lock ? 'Session locked — no further mark entry allowed.' : 'Session unlocked.';
        setFlash('success', $msg);
        header('Location: ' . APP_ROOT . '/exams/sessions.php');
        exit;
    }

    if ($_POST['action'] === 'delete' && isSuperAdmin()) {
        $sid = intval($_POST['session_id']);
        $db->prepare("DELETE FROM exam_sessions WHERE session_id=?")->execute([$sid]);
        setFlash('success', 'Session deleted.');
        header('Location: ' . APP_ROOT . '/exams/sessions.php');
        exit;
    }
}

$sessions = $db->query("
    SELECT s.*, a.full_name AS creator,
           COUNT(DISTINCT r.result_id) AS entries,
           COUNT(DISTINCT r.student_id) AS students_count
    FROM exam_sessions s
    LEFT JOIN admin_users a ON a.admin_id = s.created_by
    LEFT JOIN exam_results r ON r.session_id = s.session_id
    GROUP BY s.session_id
    ORDER BY s.session_id DESC
")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<style>
.prog-cert   { background:rgba(37,99,235,.1); color:#1d4ed8; }
.prog-diploma { background:rgba(139,92,246,.1); color:#7c3aed; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">🗓 Exam Sessions</h1>
        <p class="page-subtitle"><?= count($sessions) ?> session<?= count($sessions) !== 1 ? 's' : '' ?> total</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('new-session-modal').style.display='flex'">
        ➕ New Session
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
        <?php if (empty($sessions)): ?>
            <div style="padding:50px;text-align:center;color:var(--text-muted)">
                <div style="font-size:40px;margin-bottom:12px">🗓</div>
                <p>No exam sessions yet.</p>
                <button class="btn btn-primary" style="margin-top:12px"
                    onclick="document.getElementById('new-session-modal').style.display='flex'">
                    Create First Session
                </button>
            </div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th>Session Name</th>
                <th>Programme</th>
                <th>Year</th>
                <th>Sem</th>
                <th>Academic Year</th>
                <th>Students</th>
                <th>Entries</th>
                <th>Status</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
            <tr>
                <td style="font-weight:600"><?= htmlspecialchars($s['name']) ?></td>
                <td>
                    <span class="status-pill <?= $s['programme']==='certificate' ? 'prog-cert' : 'prog-diploma' ?>" style="font-size:11px;padding:3px 10px;border-radius:10px">
                        <?= ucfirst($s['programme']) ?>
                    </span>
                </td>
                <td style="font-size:12px"><?= htmlspecialchars($s['year_level'] ?? '—') ?></td>
                <td style="font-family:'Space Mono',monospace;font-size:12px">Sem <?= $s['semester'] ?></td>
                <td style="font-size:12px"><?= htmlspecialchars($s['academic_year']) ?></td>
                <td style="font-family:'Space Mono',monospace;font-size:12px"><?= $s['students_count'] ?></td>
                <td style="font-family:'Space Mono',monospace;font-size:12px"><?= $s['entries'] ?></td>
                <td>
                    <?php if ($s['is_locked']): ?>
                        <span class="locked-badge">🔒 Locked</span>
                    <?php else: ?>
                        <span class="status-pill" style="background:rgba(5,150,105,.1);color:#059669;font-size:11px;padding:3px 10px;border-radius:10px">✅ Open</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <a href="<?= APP_ROOT ?>/exams/enter_marks.php?session_id=<?= $s['session_id'] ?>"
                       class="btn btn-primary btn-sm" <?= $s['is_locked'] ? 'style="opacity:.5;pointer-events:none"' : '' ?>>
                        ✏️ Enter
                    </a>
                    <a href="<?= APP_ROOT ?>/exams/results.php?session_id=<?= $s['session_id'] ?>"
                       class="btn btn-ghost btn-sm">📋 Results</a>

                    <!-- Lock/Unlock -->
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle_lock">
                        <input type="hidden" name="session_id" value="<?= $s['session_id'] ?>">
                        <input type="hidden" name="lock" value="<?= $s['is_locked'] ? 0 : 1 ?>">
                        <button type="submit" class="btn btn-ghost btn-sm"
                                onclick="return confirm('<?= $s['is_locked'] ? 'Unlock this session?' : 'Lock this session? No new marks can be entered.' ?>')"
                                title="<?= $s['is_locked'] ? 'Unlock' : 'Lock' ?>">
                            <?= $s['is_locked'] ? '🔓' : '🔒' ?>
                        </button>
                    </form>

                    <?php if (isSuperAdmin()): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="session_id" value="<?= $s['session_id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"
                                onclick="return confirm('Delete this session and ALL its marks? This cannot be undone.')">
                            🗑
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── New Session Modal ── -->
<div id="new-session-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center">
    <div style="background:var(--surface);border-radius:16px;padding:32px;width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.3);margin:16px">
        <h2 style="margin-bottom:20px;font-size:18px">➕ New Exam Session</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-group" style="margin-bottom:16px">
                <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Session Name <span class="required">*</span></label>
                <input type="text" name="name" class="form-control"
                       placeholder="e.g. End of Semester 1 — May 2025" required
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                       style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
                <div class="form-group">
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Programme <span class="required">*</span></label>
                    <select name="programme" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                        <option value="">— Select —</option>
                        <option value="certificate">Certificate</option>
                        <option value="diploma">Diploma</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Year Level</label>
                    <select name="year_level" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                        <option value="">— All years —</option>
                        <option value="Year 1">Year 1</option>
                        <option value="Year 2">Year 2</option>
                        <option value="Year 3">Year 3</option>
                    </select>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px">
                <div class="form-group">
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Semester</label>
                    <select name="semester" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                        <option value="1">Semester 1</option>
                        <option value="2">Semester 2</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">Academic Year <span class="required">*</span></label>
                    <input type="text" name="academic_year" placeholder="e.g. 2024/2025" required
                           style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px">
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('new-session-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Session</button>
            </div>
        </form>
    </div>
</div>

<script>
// Close modal on backdrop click
document.getElementById('new-session-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
<?php if (!empty($errors)): ?>
// Re-open modal on validation errors
document.getElementById('new-session-modal').style.display = 'flex';
<?php endif; ?>
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
