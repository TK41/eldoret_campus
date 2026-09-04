<?php
// ============================================================
// exams/dashboard.php
// Exam Results Module — Overview Dashboard
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Exam Dashboard';
$activePage = 'exam_dashboard';
$db = getDB();

// ── Summary stats ──
$totalSessions  = $db->query("SELECT COUNT(*) FROM exam_sessions")->fetchColumn();
$totalResults   = $db->query("SELECT COUNT(*) FROM exam_results")->fetchColumn();
$totalUnits     = $db->query("SELECT COUNT(*) FROM exam_units WHERE is_active=1")->fetchColumn();

// Pass rate (grade != 'F')
$passCount = $db->query("SELECT COUNT(*) FROM exam_results WHERE grade != 'F' AND total > 0")->fetchColumn();
$passRate  = $totalResults > 0 ? round(($passCount / $totalResults) * 100, 1) : 0;

// Grade distribution
$gradeDist = $db->query("
    SELECT grade, COUNT(*) AS cnt
    FROM exam_results
    WHERE total > 0
    GROUP BY grade
    ORDER BY FIELD(grade,'A','B','C','D','F')
")->fetchAll();

// Recent sessions
$sessions = $db->query("
    SELECT s.*,
           a.full_name AS created_by_name,
           COUNT(DISTINCT r.result_id) AS entries
    FROM exam_sessions s
    LEFT JOIN admin_users a ON a.admin_id = s.created_by
    LEFT JOIN exam_results r ON r.session_id = s.session_id
    GROUP BY s.session_id
    ORDER BY s.session_id DESC
    LIMIT 6
")->fetchAll();

// Top performers (avg total across all units, minimum 2 results)
$topStudents = $db->query("
    SELECT r.student_id,
           COALESCE(fs.full_name, r.student_id) AS full_name,
           COALESCE(fs.programme, '—') AS programme,
           COUNT(r.result_id) AS unit_count,
           ROUND(AVG(r.total), 1) AS avg_score
    FROM exam_results r
    LEFT JOIN fee_students fs ON fs.student_id = r.student_id
    WHERE r.total > 0
    GROUP BY r.student_id
    HAVING unit_count >= 2
    ORDER BY avg_score DESC
    LIMIT 8
")->fetchAll();

// Recent entries (last 12)
$recent = $db->query("
    SELECT r.*, u.unit_name, u.unit_code, s.name AS session_name,
           COALESCE(fs.full_name, r.student_id) AS student_name,
           a.full_name AS entered_by_name
    FROM exam_results r
    JOIN exam_units u ON u.unit_id = r.unit_id
    JOIN exam_sessions s ON s.session_id = r.session_id
    LEFT JOIN fee_students fs ON fs.student_id = r.student_id
    LEFT JOIN admin_users a ON a.admin_id = r.entered_by
    ORDER BY r.updated_at DESC
    LIMIT 12
")->fetchAll();

include __DIR__ . '/partials/header.php';

// Grade colour helper
function gradeColor(string $g): string {
    return match($g) {
        'A' => '#15803d', 'B' => '#1d4ed8',
        'C' => '#b45309', 'D' => '#c2410c',
        default => '#dc2626'
    };
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">📊 Exam Results Dashboard</h1>
        <p class="page-subtitle">KIMC Eldoret Campus — Academic Performance Overview</p>
    </div>
    <div style="display:flex;gap:8px">
        <a href="<?= APP_ROOT ?>/exams/enter_marks.php" class="btn btn-primary">✏️ Enter Marks</a>
        <a href="<?= APP_ROOT ?>/exams/sessions.php"    class="btn btn-ghost">🗓 Sessions</a>
    </div>
</div>

<!-- ── Stat Cards ── -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px">
    <div class="stat-card exam-green">
        <div class="stat-label">Exam Sessions</div>
        <div class="stat-value"><?= $totalSessions ?></div>
        <div class="stat-sub"><a href="<?= APP_ROOT ?>/exams/sessions.php" style="color:inherit">Manage →</a></div>
    </div>
    <div class="stat-card exam-blue">
        <div class="stat-label">Total Results Entered</div>
        <div class="stat-value"><?= number_format($totalResults) ?></div>
        <div class="stat-sub"><?= $totalUnits ?> active units</div>
    </div>
    <div class="stat-card exam-green">
        <div class="stat-label">Overall Pass Rate</div>
        <div class="stat-value"><?= $passRate ?>%</div>
        <div class="stat-sub"><?= number_format($passCount) ?> passes</div>
    </div>
    <div class="stat-card exam-amber">
        <div class="stat-label">Active Units</div>
        <div class="stat-value"><?= $totalUnits ?></div>
        <div class="stat-sub"><a href="<?= APP_ROOT ?>/exams/units.php" style="color:inherit">View all →</a></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px">

    <!-- Recent Sessions -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <span class="card-title">🗓 Exam Sessions</span>
            <a href="<?= APP_ROOT ?>/exams/sessions.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($sessions)): ?>
                <div style="padding:30px;text-align:center;color:var(--text-muted)">
                    No sessions yet. <a href="<?= APP_ROOT ?>/exams/sessions.php">Create one →</a>
                </div>
            <?php else: ?>
            <table class="data-table">
                <thead><tr>
                    <th>Session</th><th>Programme</th><th>Year</th><th>Entries</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($sessions as $ses): ?>
                <tr>
                    <td style="font-weight:600"><?= htmlspecialchars($ses['name']) ?></td>
                    <td><span style="font-size:11px;text-transform:capitalize"><?= $ses['programme'] ?></span></td>
                    <td style="font-size:12px"><?= htmlspecialchars($ses['year_level'] ?? '—') ?></td>
                    <td><span style="font-family:'Space Mono',monospace;font-size:12px"><?= $ses['entries'] ?></span></td>
                    <td>
                        <?php if ($ses['is_locked']): ?>
                            <span class="locked-badge">🔒 Locked</span>
                        <?php else: ?>
                            <span class="status-pill" style="background:rgba(5,150,105,.1);color:#059669;font-size:11px;padding:3px 10px;border-radius:10px">Open</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= APP_ROOT ?>/exams/enter_marks.php?session_id=<?= $ses['session_id'] ?>" class="btn btn-primary btn-sm">Enter</a>
                        <a href="<?= APP_ROOT ?>/exams/results.php?session_id=<?= $ses['session_id'] ?>"     class="btn btn-ghost btn-sm">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Grade Distribution -->
    <div class="card">
        <div class="card-header"><span class="card-title">🎯 Grade Distribution</span></div>
        <div class="card-body">
            <?php if (empty($gradeDist)): ?>
                <div style="text-align:center;color:var(--text-muted);padding:20px 0">No results yet</div>
            <?php else:
                $maxCnt = max(array_column($gradeDist, 'cnt'));
            ?>
            <?php foreach ($gradeDist as $gd): ?>
            <?php $pct = $maxCnt > 0 ? ($gd['cnt'] / $maxCnt) * 100 : 0; ?>
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px">
                    <span class="grade-pill grade-<?= $gd['grade'] ?>"><?= $gd['grade'] ?></span>
                    <span style="font-family:'Space Mono',monospace;font-size:12px;color:var(--text-muted)"><?= $gd['cnt'] ?> students</span>
                </div>
                <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= gradeColor($gd['grade']) ?>;border-radius:4px;transition:width .6s ease"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Top Performers + Recent Activity -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Top Performers -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <span class="card-title">🏆 Top Performers</span>
            <a href="<?= APP_ROOT ?>/exams/analytics.php" class="btn btn-ghost btn-sm">Analytics</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($topStudents)): ?>
                <div style="padding:30px;text-align:center;color:var(--text-muted)">Not enough data yet</div>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>#</th><th>Student</th><th>Units</th><th>Avg</th></tr></thead>
                <tbody>
                <?php foreach ($topStudents as $i => $st): ?>
                <tr>
                    <td>
                        <?php if ($i === 0): ?><span style="font-size:16px">🥇</span>
                        <?php elseif ($i === 1): ?><span style="font-size:16px">🥈</span>
                        <?php elseif ($i === 2): ?><span style="font-size:16px">🥉</span>
                        <?php else: ?><span style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($st['full_name']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($st['student_id']) ?></div>
                    </td>
                    <td style="font-family:'Space Mono',monospace;font-size:12px"><?= $st['unit_count'] ?></td>
                    <td>
                        <?php
                        $avg = $st['avg_score'];
                        $g = $avg >= 70 ? 'A' : ($avg >= 60 ? 'B' : ($avg >= 50 ? 'C' : ($avg >= 40 ? 'D' : 'F')));
                        ?>
                        <span class="grade-pill grade-<?= $g ?>"><?= $avg ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <div class="card-header"><span class="card-title">🕒 Recent Entries</span></div>
        <div class="card-body" style="padding:0">
            <?php if (empty($recent)): ?>
                <div style="padding:30px;text-align:center;color:var(--text-muted)">No entries yet</div>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Student</th><th>Unit</th><th>Total</th><th>Grade</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td>
                        <div style="font-size:12px;font-weight:600"><?= htmlspecialchars($r['student_name']) ?></div>
                        <div style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($r['session_name']) ?></div>
                    </td>
                    <td style="font-size:12px"><?= htmlspecialchars($r['unit_code']) ?></td>
                    <td style="font-family:'Space Mono',monospace;font-size:12px"><?= $r['total'] ?></td>
                    <td><span class="grade-pill grade-<?= $r['grade'] ?>"><?= $r['grade'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
