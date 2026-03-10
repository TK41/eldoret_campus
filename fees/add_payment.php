<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Post Payment';
$activePage = 'fees_add_payment';
$db = getDB();
$errors = [];
$preselect = intval($_GET['student_id'] ?? 0);

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fee_student_id = intval($_POST['fee_student_id'] ?? 0);
    $amount         = floatval($_POST['amount'] ?? 0);
    $mode           = trim($_POST['mode'] ?? 'mpesa');
    $mpesa_number   = trim($_POST['mpesa_number'] ?? '');
    $reference      = strtoupper(trim($_POST['reference'] ?? ''));
    $date_paid      = trim($_POST['date_paid'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    if (!$fee_student_id)    $errors[] = 'Please select a student.';
    if ($amount <= 0)        $errors[] = 'Amount must be greater than zero.';
    if (!$date_paid)         $errors[] = 'Date paid is required.';

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO fee_payments
                (fee_student_id, amount, mode, mpesa_number, reference, date_paid, notes, posted_by)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $fee_student_id, $amount, $mode,
            $mpesa_number ?: null, $reference ?: null,
            $date_paid, $notes ?: null,
            $_SESSION['admin_id']
        ]);
        setFlash('success', 'Payment of KES ' . number_format($amount) . ' posted successfully.');
        header('Location: ' . APP_ROOT . '/fees/student.php?id=' . $fee_student_id);
        exit;
    }
}

// Load all students for dropdown
$allStudents = $db->query("
    SELECT s.fee_student_id, s.full_name, s.student_id, s.total_fees, g.name AS group_name,
           COALESCE((SELECT SUM(p.amount) FROM fee_payments p WHERE p.fee_student_id=s.fee_student_id),0) AS paid
    FROM fee_students s
    JOIN fee_groups g ON g.group_id=s.group_id
    WHERE s.is_active=1
    ORDER BY s.full_name ASC
")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<style>
.payment-form { max-width:640px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-group { margin-bottom:18px; }
.form-group label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted); margin-bottom:7px; }
.form-control { width:100%; padding:10px 13px; border-radius:8px; border:1.5px solid var(--border); background:var(--input-bg); color:var(--text-primary); font-family:inherit; font-size:14px; transition:border-color .2s; outline:none; box-sizing:border-box; }
.form-control:focus { border-color:#d97706; box-shadow:0 0 0 3px rgba(217,119,6,.1); }
.student-preview { background:rgba(217,119,6,.06); border:1px solid rgba(217,119,6,.2); border-radius:10px; padding:14px 16px; margin-bottom:18px; display:none; }
.preview-name { font-weight:700; font-size:15px; }
.preview-bal  { font-size:13px; margin-top:4px; color:var(--text-muted); }
.preview-bar  { height:6px; background:var(--border); border-radius:3px; margin-top:10px; overflow:hidden; }
.preview-fill { height:100%; background:linear-gradient(90deg,#d97706,#f59e0b); border-radius:3px; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">➕ Post Payment</h1>
        <p class="page-subtitle">Record a new fee payment for a student</p>
    </div>
    <a href="<?= APP_ROOT ?>/fees/students.php" class="btn btn-ghost">← Back to Students</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error" style="margin-bottom:20px">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card payment-form">
    <div class="card-body">
        <form method="POST">

            <!-- Student picker -->
            <div class="form-group">
                <label>Student *</label>
                <input type="text" id="student-search" class="form-control"
                       placeholder="Type name or Adm No to search…" autocomplete="off"
                       oninput="searchStudent(this.value)">
                <input type="hidden" name="fee_student_id" id="fee_student_id" value="<?= $preselect ?>">
                <div id="student-dropdown" style="position:relative"></div>
            </div>

            <!-- Student preview -->
            <div class="student-preview" id="student-preview">
                <div class="preview-name" id="prev-name"></div>
                <div class="preview-bal" id="prev-bal"></div>
                <div class="preview-bar"><div class="preview-fill" id="prev-fill"></div></div>
            </div>

            <div class="form-row">
                <!-- Amount -->
                <div class="form-group">
                    <label>Amount (KES) *</label>
                    <input type="number" name="amount" class="form-control" min="1" step="0.01"
                           placeholder="e.g. 20000" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required>
                </div>
                <!-- Date paid -->
                <div class="form-group">
                    <label>Date Paid *</label>
                    <input type="date" name="date_paid" class="form-control"
                           value="<?= htmlspecialchars($_POST['date_paid'] ?? date('Y-m-d')) ?>" required>
                </div>
            </div>

            <!-- Mode -->
            <div class="form-group">
                <label>Mode of Payment *</label>
                <select name="mode" id="mode-select" class="form-control" onchange="togglePhoneField()">
                    <?php
                    $modes = ['mpesa'=>'M-Pesa','helb'=>'HELB','bank'=>'Bank','ecitizen'=>'eCitizen','smis'=>'SMIS','receipted'=>'Receipted','nairobi_campus'=>'Nairobi Campus','other'=>'Other'];
                    foreach ($modes as $k => $v):
                        $sel = (($_POST['mode'] ?? 'mpesa') === $k) ? 'selected' : '';
                    ?>
                        <option value="<?= $k ?>" <?= $sel ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <!-- Phone / Ref number -->
                <div class="form-group" id="phone-group">
                    <label id="phone-label">M-Pesa Number</label>
                    <input type="text" name="mpesa_number" class="form-control"
                           placeholder="e.g. 0712345678" id="phone-input"
                           value="<?= htmlspecialchars($_POST['mpesa_number'] ?? '') ?>">
                </div>
                <!-- Transaction reference -->
                <div class="form-group">
                    <label>Transaction Reference</label>
                    <input type="text" name="reference" class="form-control"
                           placeholder="e.g. TEDIK83AZ5" style="text-transform:uppercase"
                           oninput="this.value=this.value.toUpperCase()"
                           value="<?= htmlspecialchars($_POST['reference'] ?? '') ?>">
                </div>
            </div>

            <!-- Notes -->
            <div class="form-group">
                <label>Notes (optional)</label>
                <input type="text" name="notes" class="form-control" placeholder="Any additional notes…"
                       value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">
            </div>

            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-primary" style="flex:1">✓ Post Payment</button>
                <a href="<?= APP_ROOT ?>/fees/students.php" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
// Student data injected from PHP
const feeStudents = <?= json_encode(array_map(fn($s) => [
    'id'       => $s['fee_student_id'],
    'name'     => $s['full_name'],
    'adm'      => $s['student_id'],
    'group'    => $s['group_name'],
    'total'    => floatval($s['total_fees']),
    'paid'     => floatval($s['paid']),
    'balance'  => floatval($s['total_fees']) - floatval($s['paid']),
], $allStudents)) ?>;

// Pre-fill if student_id passed in URL
const preselect = <?= $preselect ?>;
if (preselect) {
    const s = feeStudents.find(x => x.id === preselect);
    if (s) selectStudent(s);
}

function searchStudent(q) {
    const dd = document.getElementById('student-dropdown');
    q = q.trim().toLowerCase();
    if (q.length < 1) { dd.innerHTML = ''; return; }

    const matches = feeStudents.filter(s =>
        s.name.toLowerCase().includes(q) || s.adm.toLowerCase().includes(q)
    ).slice(0, 8);

    if (!matches.length) {
        dd.innerHTML = '<div style="padding:10px;font-size:13px;color:var(--text-muted);background:var(--surface);border:1px solid var(--border);border-radius:8px;margin-top:4px">No students found</div>';
        return;
    }

    dd.innerHTML = '<div style="position:absolute;width:100%;z-index:50;background:var(--surface);border:1px solid var(--border);border-radius:8px;margin-top:4px;box-shadow:0 8px 24px rgba(0,0,0,.12);overflow:hidden">' +
        matches.map(s => `
            <div onclick="selectStudent(${JSON.stringify(s).replace(/"/g,'&quot;')})"
                 style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-light);transition:background .1s"
                 onmouseover="this.style.background='var(--surface-hover)'" onmouseout="this.style.background=''">
                <div style="font-weight:600;font-size:13px">${s.name}</div>
                <div style="font-size:11px;color:var(--text-muted)">${s.adm} · ${s.group}</div>
                <div style="font-size:11px;margin-top:2px;color:${s.balance > 0 ? '#dc2626' : '#16a34a'}">
                    Balance: KES ${s.balance.toLocaleString()} of KES ${s.total.toLocaleString()}
                </div>
            </div>`
        ).join('') + '</div>';
}

function selectStudent(s) {
    if (typeof s === 'string') s = JSON.parse(s);
    document.getElementById('fee_student_id').value = s.id;
    document.getElementById('student-search').value = s.name + ' (' + s.adm + ')';
    document.getElementById('student-dropdown').innerHTML = '';

    const pct = s.total > 0 ? Math.min(100, (s.paid / s.total) * 100) : 0;
    document.getElementById('prev-name').textContent = s.name + ' — ' + s.adm;
    document.getElementById('prev-bal').innerHTML =
        '<strong style="color:#16a34a">Paid: KES ' + s.paid.toLocaleString() + '</strong>' +
        ' &nbsp;·&nbsp; ' +
        '<strong style="color:#dc2626">Balance: KES ' + Math.max(0,s.balance).toLocaleString() + '</strong>' +
        ' &nbsp;·&nbsp; Total: KES ' + s.total.toLocaleString();
    document.getElementById('prev-fill').style.width = pct + '%';
    document.getElementById('student-preview').style.display = 'block';
}

// Close dropdown on outside click
document.addEventListener('click', e => {
    if (!e.target.closest('#student-dropdown') && e.target.id !== 'student-search') {
        document.getElementById('student-dropdown').innerHTML = '';
    }
});

function togglePhoneField() {
    const mode = document.getElementById('mode-select').value;
    const grp  = document.getElementById('phone-group');
    const lbl  = document.getElementById('phone-label');
    const inp  = document.getElementById('phone-input');
    if (mode === 'mpesa') {
        lbl.textContent = 'M-Pesa Number'; inp.placeholder = '0712345678'; grp.style.display = '';
    } else if (mode === 'bank') {
        lbl.textContent = 'Account / Agent Ref'; inp.placeholder = 'Bank reference'; grp.style.display = '';
    } else if (['helb','ecitizen','smis','receipted','nairobi_campus'].includes(mode)) {
        lbl.textContent = 'Reference / Batch No'; inp.placeholder = 'e.g. BATCH(6517)'; grp.style.display = '';
    } else {
        grp.style.display = 'none';
    }
}
togglePhoneField();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
