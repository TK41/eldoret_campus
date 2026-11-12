<?php
// ============================================================
// admin/student_accounts.php
// Staff issue one-time activation codes so students can create (or reset)
// their booking-portal password. Verify the student's ID before issuing.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../auth/student_auth.php';
require_once __DIR__ . '/../reservation_helpers.php';

requireLogin();
requireAccess('inventory');

$pageTitle  = 'Student Logins';
$activePage = 'student_accounts';
$db         = getDB();
$canIssue   = canDo('inventory.checkout');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canIssue) {
        http_response_code(403);
        exit('You do not have permission to issue activation codes.');
    }
    $feeStudentId = filter_var($_POST['fee_student_id'] ?? null, FILTER_VALIDATE_INT);
    if (($_POST['action'] ?? '') === 'issue_code' && $feeStudentId && ($_POST['id_checked'] ?? '') === '1') {
        $stmt = $db->prepare("SELECT student_id, full_name, is_active FROM fee_students WHERE fee_student_id = ?");
        $stmt->execute([$feeStudentId]);
        $student = $stmt->fetch();
        if ($student && $student['is_active']) {
            $code = studentIssueActivationCode($db, $student['student_id']);
            $_SESSION['issued_activation'] = [
                'fee_student_id' => $feeStudentId,
                'student_id' => $student['student_id'],
                'name'       => $student['full_name'],
                'code'       => $code,
                'issued_at'  => time(),
            ];
        } else {
            setFlash('error', 'That student is not active in the fees register.');
        }
    } else {
        setFlash('error', 'Confirm you checked the student\'s ID before issuing a code.');
    }
    header('Location: ' . BOOKING_ROOT . '/admin/student_accounts.php?' . http_build_query(['q' => $_GET['q'] ?? '']));
    exit;
}

// The code stays visible beside its Issue button for 60 seconds, then is dropped from the session.
const ISSUED_CODE_TTL = 60;
$issued = $_SESSION['issued_activation'] ?? null;
$issuedRemaining = $issued ? ISSUED_CODE_TTL - (time() - (int) $issued['issued_at']) : 0;
if ($issuedRemaining <= 0) {
    unset($_SESSION['issued_activation']);
    $issued = null;
}

$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$params = [];
$where = "fs.is_active = 1 AND fs.fee_student_id = (
              SELECT MAX(f2.fee_student_id) FROM fee_students f2
              WHERE UPPER(TRIM(f2.student_id)) = UPPER(TRIM(fs.student_id)))";
if ($search !== '') {
    $where .= " AND (fs.student_id LIKE ? OR fs.full_name LIKE ? OR fs.programme LIKE ?)";
    $like = '%' . addcslashes($search, '%_') . '%';
    $params = [$like, $like, $like];
}
$count = $db->prepare("SELECT COUNT(*) FROM fee_students fs WHERE $where");
$count->execute($params);
$totalRows = (int) $count->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);

$rows = $db->prepare("
    SELECT fs.fee_student_id, fs.student_id, fs.full_name, fs.programme,
           (fs.national_id IS NOT NULL AND fs.national_id <> '') AS has_national_id,
           sa.password_hash IS NOT NULL AS has_password, sa.activated_at, sa.last_login,
           sa.activation_expires,
           (sa.activation_hash IS NOT NULL AND sa.activation_expires > UTC_TIMESTAMP()) AS code_valid
    FROM fee_students fs
    LEFT JOIN student_accounts sa ON CONVERT(sa.student_id USING utf8mb4) = CONVERT(fs.student_id USING utf8mb4)
    WHERE $where
    ORDER BY fs.full_name
    LIMIT ? OFFSET ?
");
foreach ($params as $i => $value) {
    $rows->bindValue($i + 1, $value);
}
$rows->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$rows->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
$rows->execute();
$students = $rows->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Student Logins</h1>
        <p class="page-subtitle">Students with a national ID on file can create their own password. For everyone else, check their student ID and issue an activation code. A new code also resets a forgotten password.</p>
    </div>
    <a class="btn btn-ghost" href="<?= APP_ROOT ?>/admin/reservations.php">Back to reservations</a>
</div>

<div class="card" style="margin-bottom:18px">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
        <div class="form-group" style="margin:0;min-width:220px;flex:1">
            <label>Search admission number, name or course</label>
            <input class="form-control" type="search" name="q" value="<?= htmlspecialchars($search) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Search</button>
    </form>
</div>

<div class="card">
    <?php if (!$students): ?>
        <p>No active students match.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="data-table" style="min-width:820px">
            <thead><tr><th>Student</th><th>Course</th><th>National ID on file</th><th>Login status</th><th>Activation code</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars(formatPersonName($s['full_name'])) ?></strong><br><span class="mono"><?= htmlspecialchars($s['student_id']) ?></span></td>
                    <td><?= htmlspecialchars(formatTitleCase($s['programme'] ?? '')) ?></td>
                    <td><?= $s['has_national_id'] ? 'Yes' : 'No' ?></td>
                    <td>
                        <?php if ($s['has_password']): ?>
                            Active<?= $s['last_login'] ? '<br><span style="font-size:12px;color:var(--text-muted)">Last sign-in ' . htmlspecialchars(reservationFormatLocalDateTime($s['last_login'])) . '</span>' : '' ?>
                        <?php else: ?>
                            Not activated
                        <?php endif; ?>
                        <?php if ($s['code_valid']): ?>
                            <br><span style="font-size:12px;color:var(--text-muted)">Code issued, expires <?= htmlspecialchars(reservationFormatLocalDateTime($s['activation_expires'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="min-width:210px">
                        <?php if ($canIssue): ?>
                            <form method="post" onsubmit="return confirm('Issue a new code for <?= htmlspecialchars(addslashes($s['full_name']), ENT_QUOTES) ?>? Any earlier code stops working.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                <input type="hidden" name="action" value="issue_code">
                                <input type="hidden" name="fee_student_id" value="<?= (int) $s['fee_student_id'] ?>">
                                <label style="display:flex;align-items:center;gap:5px;font-size:11px;margin-bottom:5px">
                                    <input type="checkbox" name="id_checked" value="1" required> Student ID checked
                                </label>
                                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                                    <button class="btn btn-primary btn-sm" type="submit"><?= $s['has_password'] ? 'Issue reset code' : 'Issue code' ?></button>
                                    <?php if ($issued && (int) $issued['fee_student_id'] === (int) $s['fee_student_id']): ?>
                                        <div class="issued-code" data-remaining="<?= (int) $issuedRemaining ?>">
                                            <span class="issued-code-value"><?= htmlspecialchars($issued['code']) ?></span>
                                            <span class="issued-code-timer"></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php else: ?><span style="color:var(--text-muted)">No permission</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px">
        <span>Page <?= $page ?> of <?= $totalPages ?> · <?= $totalRows ?> students</span>
        <div style="display:flex;gap:8px">
            <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?<?= http_build_query(['q' => $search, 'page' => $page - 1]) ?>">Previous</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="?<?= http_build_query(['q' => $search, 'page' => $page + 1]) ?>">Next</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<style>
.issued-code { display: flex; flex-direction: column; padding: 4px 12px; border: 1px solid rgba(22,163,74,.4); background: rgba(22,163,74,.1); border-radius: 8px; }
.issued-code-value { font-family: 'Space Mono', monospace; font-size: 18px; font-weight: 700; letter-spacing: 2px; color: var(--text-primary); }
.issued-code-timer { font-size: 11px; color: var(--text-muted); }
</style>
<script>
(function () {
    var box = document.querySelector('.issued-code');
    if (!box) return;
    var left = parseInt(box.dataset.remaining, 10) || 0;
    var timer = box.querySelector('.issued-code-timer');
    function tick() {
        if (left <= 0) {
            box.remove();
            return;
        }
        var m = Math.floor(left / 60), s = left % 60;
        timer.textContent = 'Hides in ' + m + ':' + (s < 10 ? '0' : '') + s;
        left -= 1;
        setTimeout(tick, 1000);
    }
    tick();
})();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
