<?php
// ============================================================
// exams/transcripts.php
// Per-student report card / transcript — printable
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Transcripts';
$activePage = 'exam_transcripts';
$db = getDB();

$sel_session = intval($_GET['session_id']  ?? 0);
$sel_student = trim($_GET['student_id']    ?? '');
$print_all   = isset($_GET['print_all']);

$sessions = $db->query("SELECT * FROM exam_sessions ORDER BY session_id DESC")->fetchAll();

// Students who have results in selected session
$studentsWithResults = [];
if ($sel_session) {
    $sw = $db->prepare("
        SELECT DISTINCT r.student_id,
               COALESCE(fs.full_name, r.student_id) AS full_name,
               fs.programme, fg.name AS group_name
        FROM exam_results r
        LEFT JOIN fee_students fs ON fs.student_id = r.student_id
        LEFT JOIN fee_groups fg ON fg.group_id = fs.group_id
        WHERE r.session_id = ?
        ORDER BY full_name
    ");
    $sw->execute([$sel_session]);
    $studentsWithResults = $sw->fetchAll();
}

// Fetch transcript data for a student
function getTranscript(PDO $db, int $sessionId, string $studentId): array {
    $q = $db->prepare("
        SELECT r.*, u.unit_code, u.unit_name, u.year_level, u.semester,
               s.name AS session_name, s.academic_year, s.programme AS session_prog,
               COALESCE(fs.full_name, r.student_id) AS student_name,
               fs.programme AS student_prog, fg.name AS group_name
        FROM exam_results r
        JOIN exam_units u ON u.unit_id = r.unit_id
        JOIN exam_sessions s ON s.session_id = r.session_id
        LEFT JOIN fee_students fs ON fs.student_id = r.student_id
        LEFT JOIN fee_groups fg ON fg.group_id = fs.group_id
        WHERE r.session_id = ? AND r.student_id = ?
        ORDER BY u.year_level, u.semester, u.unit_code
    ");
    $q->execute([$sessionId, $studentId]);
    return $q->fetchAll();
}

// Build transcript(s) to show
$transcripts = []; // [ ['student_id'=>..., 'rows'=>[...], 'summary'=>[...]], ... ]

if ($sel_session) {
    $targets = $sel_student ? [$sel_student] : array_column($studentsWithResults, 'student_id');
    foreach ($targets as $sid) {
        $rows = getTranscript($db, $sel_session, $sid);
        if (empty($rows)) continue;
        $totals = array_filter(array_column($rows, 'total'), fn($v) => $v !== null && $v > 0);
        $transcripts[] = [
            'student_id'   => $sid,
            'student_name' => $rows[0]['student_name'],
            'student_prog' => $rows[0]['student_prog'] ?? '—',
            'group_name'   => $rows[0]['group_name']   ?? '—',
            'session_name' => $rows[0]['session_name'],
            'academic_year'=> $rows[0]['academic_year'],
            'rows'         => $rows,
            'avg'          => $totals ? round(array_sum($totals)/count($totals), 1) : 0,
            'units_sat'    => count($rows),
            'units_passed' => count(array_filter($rows, fn($r) => $r['grade'] !== 'F')),
        ];
    }
}

include __DIR__ . '/partials/header.php';
?>

<style>
/* ── Screen styles ── */
.filter-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; align-items:flex-end; }
.filter-bar .fg { display:flex; flex-direction:column; gap:4px; }
.filter-bar label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); }
.filter-bar select { padding:9px 12px; border:1px solid var(--border); border-radius:8px; background:var(--input-bg,var(--surface)); color:var(--text-primary); font-family:inherit; font-size:13px; }

.transcript-card {
    border:1px solid var(--border); border-radius:12px;
    overflow:hidden; margin-bottom:32px;
    background:var(--surface);
    page-break-after: always;
}
.transcript-header {
    background:linear-gradient(135deg, #022c22, #065f46);
    color:#fff; padding:24px 28px;
    display:flex; justify-content:space-between; align-items:flex-start;
}
.transcript-logo { display:flex; align-items:center; gap:14px; }
.transcript-logo img { height:52px; width:auto; object-fit:contain; filter:brightness(0) invert(1); }
.transcript-school { font-size:13px; opacity:.75; margin-top:2px; }
.transcript-meta { text-align:right; font-size:12px; opacity:.8; line-height:1.7; }

.transcript-student {
    padding:20px 28px; border-bottom:1px solid var(--border);
    display:flex; gap:32px; flex-wrap:wrap;
}
.ts-field { display:flex; flex-direction:column; gap:2px; }
.ts-label { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700; }
.ts-value { font-size:14px; font-weight:600; }

.transcript-table { width:100%; border-collapse:collapse; }
.transcript-table th {
    padding:10px 16px; background:var(--surface);
    font-size:11px; text-transform:uppercase; letter-spacing:.5px;
    color:var(--text-muted); font-weight:700;
    border-bottom:2px solid var(--border); text-align:left;
}
.transcript-table td {
    padding:11px 16px; border-bottom:1px solid var(--border);
    font-size:13px;
}
.transcript-table tr:last-child td { border-bottom:none; }

.transcript-footer {
    padding:20px 28px; background:var(--surface);
    border-top:2px solid var(--border);
    display:flex; justify-content:space-between; align-items:center;
    flex-wrap:wrap; gap:12px;
}
.grade-summary { display:flex; gap:16px; flex-wrap:wrap; }
.gs-item { display:flex; flex-direction:column; align-items:center; gap:2px; }
.gs-val { font-family:'Space Mono',monospace; font-size:18px; font-weight:700; }
.gs-lbl { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }

/* ── Print styles ── */
@media print {
    .topbar, .sidebar, .app-footer,
    .page-header, .filter-bar, .filter-bar+*,
    .no-print, .btn { display:none !important; }

    body { background:#fff; color:#000; }
    .layout { display:block; }
    .main-content { margin:0 !important; padding:0 !important; }

    .transcript-card {
        border:1px solid #ccc;
        margin-bottom:0;
        page-break-after:always;
        break-after:page;
    }
    .transcript-header { background:#022c22 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .transcript-table th { background:#f5f5f5 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .grade-pill { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">🎓 Transcripts</h1>
        <p class="page-subtitle">Student academic result records</p>
    </div>
    <div style="display:flex;gap:8px">
        <?php if (!empty($transcripts)): ?>
        <button onclick="window.print()" class="btn btn-primary">🖨 Print<?= count($transcripts) > 1 ? ' All ('.count($transcripts).')' : '' ?></button>
        <?php endif; ?>
        <?php if ($sel_session): ?>
        <a href="?session_id=<?= $sel_session ?>" class="btn btn-ghost">👥 All Students</a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar no-print">
    <div class="fg">
        <label>Exam Session</label>
        <select onchange="window.location='?session_id='+this.value">
            <option value="">— Select Session —</option>
            <?php foreach ($sessions as $s): ?>
            <option value="<?= $s['session_id'] ?>" <?= $sel_session===$s['session_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($sel_session && !empty($studentsWithResults)): ?>
    <div class="fg">
        <label>Student</label>
        <select onchange="window.location='?session_id=<?= $sel_session ?>&student_id='+this.value">
            <option value="">All Students (<?= count($studentsWithResults) ?>)</option>
            <?php foreach ($studentsWithResults as $st): ?>
            <option value="<?= htmlspecialchars($st['student_id']) ?>"
                    <?= $sel_student === $st['student_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($st['full_name']) ?> — <?= htmlspecialchars($st['student_id']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</div>

<?php if (!$sel_session): ?>
<div class="card"><div style="padding:50px;text-align:center;color:var(--text-muted)">
    <div style="font-size:40px;margin-bottom:12px">🎓</div>
    <p>Select an exam session to generate transcripts.</p>
</div></div>

<?php elseif (empty($transcripts)): ?>
<div class="card"><div style="padding:50px;text-align:center;color:var(--text-muted)">
    <div style="font-size:40px;margin-bottom:12px">📋</div>
    <p>No results found for this selection.</p>
    <a href="<?= APP_ROOT ?>/exams/enter_marks.php?session_id=<?= $sel_session ?>" class="btn btn-primary" style="margin-top:12px">
        ✏️ Enter Marks First
    </a>
</div></div>

<?php else: ?>

<?php foreach ($transcripts as $t):
    $avgG = $t['avg'] >= 70 ? 'A' : ($t['avg'] >= 60 ? 'B' : ($t['avg'] >= 50 ? 'C' : ($t['avg'] >= 40 ? 'D' : 'F')));
    $passRate = $t['units_sat'] > 0 ? round(($t['units_passed'] / $t['units_sat']) * 100) : 0;
?>
<div class="transcript-card">
    <!-- Header -->
    <div class="transcript-header">
        <div class="transcript-logo">
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo">
            <div>
                <div style="font-size:18px;font-weight:700;letter-spacing:.3px">KIMC Eldoret Campus</div>
                <div class="transcript-school">Kenya Institute of Mass Communication</div>
                <div style="font-size:11px;opacity:.6;margin-top:4px">P.O. Box — Eldoret, Kenya</div>
            </div>
        </div>
        <div class="transcript-meta">
            <div style="font-size:15px;font-weight:700;color:#6ee7b7;letter-spacing:.5px">ACADEMIC TRANSCRIPT</div>
            <div><?= htmlspecialchars($t['session_name']) ?></div>
            <div>Academic Year: <?= htmlspecialchars($t['academic_year']) ?></div>
            <div>Generated: <?= date('d M Y') ?></div>
        </div>
    </div>

    <!-- Student Details -->
    <div class="transcript-student">
        <div class="ts-field">
            <span class="ts-label">Student Name</span>
            <span class="ts-value"><?= htmlspecialchars($t['student_name']) ?></span>
        </div>
        <div class="ts-field">
            <span class="ts-label">Admission No.</span>
            <span class="ts-value" style="font-family:'Space Mono',monospace"><?= htmlspecialchars($t['student_id']) ?></span>
        </div>
        <div class="ts-field">
            <span class="ts-label">Programme</span>
            <span class="ts-value"><?= htmlspecialchars($t['student_prog']) ?></span>
        </div>
        <div class="ts-field">
            <span class="ts-label">Group / Intake</span>
            <span class="ts-value"><?= htmlspecialchars($t['group_name']) ?></span>
        </div>
    </div>

    <!-- Results Table -->
    <table class="transcript-table">
        <thead>
            <tr>
                <th>Unit Code</th>
                <th>Unit Name</th>
                <th style="text-align:center">CA (30)</th>
                <th style="text-align:center">Exam (70)</th>
                <th style="text-align:center">Total (100)</th>
                <th style="text-align:center">Grade</th>
                <th style="text-align:center">Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($t['rows'] as $row): ?>
        <tr>
            <td><code style="font-size:12px"><?= htmlspecialchars($row['unit_code']) ?></code></td>
            <td><?= htmlspecialchars($row['unit_name']) ?></td>
            <td style="text-align:center;font-family:'Space Mono',monospace"><?= $row['ca_score']   ?? '—' ?></td>
            <td style="text-align:center;font-family:'Space Mono',monospace"><?= $row['exam_score'] ?? '—' ?></td>
            <td style="text-align:center;font-family:'Space Mono',monospace;font-weight:700;font-size:14px"><?= $row['total'] ?? '—' ?></td>
            <td style="text-align:center"><span class="grade-pill grade-<?= $row['grade'] ?>"><?= $row['grade'] ?: '—' ?></span></td>
            <td style="font-size:12px;color:var(--text-muted)">
                <?php
                $rem = $row['remarks'] ?? '';
                if (!$rem) {
                    if     ($row['grade'] === 'A') $rem = 'Excellent';
                    elseif ($row['grade'] === 'B') $rem = 'Very Good';
                    elseif ($row['grade'] === 'C') $rem = 'Good';
                    elseif ($row['grade'] === 'D') $rem = 'Satisfactory';
                    elseif ($row['grade'] === 'F') $rem = 'Fail — Resit Required';
                }
                echo htmlspecialchars($rem);
                ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Footer / Summary -->
    <div class="transcript-footer">
        <div class="grade-summary">
            <div class="gs-item">
                <div class="gs-val"><?= $t['units_sat'] ?></div>
                <div class="gs-lbl">Units Sat</div>
            </div>
            <div class="gs-item">
                <div class="gs-val" style="color:#15803d"><?= $t['units_passed'] ?></div>
                <div class="gs-lbl">Passed</div>
            </div>
            <div class="gs-item">
                <div class="gs-val" style="color:#dc2626"><?= $t['units_sat'] - $t['units_passed'] ?></div>
                <div class="gs-lbl">Failed</div>
            </div>
            <div class="gs-item">
                <div class="gs-val"><?= $t['avg'] ?></div>
                <div class="gs-lbl">Average</div>
            </div>
            <div class="gs-item">
                <div class="gs-val"><?= $passRate ?>%</div>
                <div class="gs-lbl">Pass Rate</div>
            </div>
        </div>
        <div style="text-align:right">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">Overall Grade</div>
            <span class="grade-pill grade-<?= $avgG ?>" style="font-size:18px;padding:6px 18px"><?= $avgG ?></span>
        </div>
    </div>

    <!-- Signature line (for print) -->
    <div style="padding:16px 28px;border-top:1px solid var(--border);display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;font-size:11px;color:var(--text-muted)">
        <div style="border-top:1px solid var(--border);padding-top:8px;margin-top:20px">Lecturer's Signature</div>
        <div style="border-top:1px solid var(--border);padding-top:8px;margin-top:20px;text-align:center">HOD's Signature</div>
        <div style="border-top:1px solid var(--border);padding-top:8px;margin-top:20px;text-align:right">Registrar's Stamp &amp; Signature</div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
