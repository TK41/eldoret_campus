<?php
// ============================================================
// exams/analytics.php
// Grade analytics, class rankings, unit performance heatmap
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Analytics';
$activePage = 'exam_analytics';
$db = getDB();

$sel_session = intval($_GET['session_id'] ?? 0);
$sessions = $db->query("SELECT * FROM exam_sessions ORDER BY session_id DESC")->fetchAll();

$rankings    = [];
$unitPerf    = [];
$gradeDist   = [];
$sessionInfo = null;

if ($sel_session) {
    $si = $db->prepare("SELECT * FROM exam_sessions WHERE session_id=?");
    $si->execute([$sel_session]);
    $sessionInfo = $si->fetch();

    // ── Class Rankings (by avg total, min 1 result) ──
    $rk = $db->prepare("
        SELECT r.student_id,
               COALESCE(fs.full_name, r.student_id) AS full_name,
               fs.programme, fg.name AS group_name,
               COUNT(r.result_id)    AS units_sat,
               SUM(r.total)          AS total_marks,
               ROUND(AVG(r.total),1) AS avg_score,
               SUM(CASE WHEN r.grade='A' THEN 1 ELSE 0 END) AS a_count,
               SUM(CASE WHEN r.grade='F' THEN 1 ELSE 0 END) AS f_count
        FROM exam_results r
        LEFT JOIN fee_students fs ON fs.student_id = r.student_id
        LEFT JOIN fee_groups fg ON fg.group_id = fs.group_id
        WHERE r.session_id = ? AND r.total > 0
        GROUP BY r.student_id
        ORDER BY avg_score DESC
    ");
    $rk->execute([$sel_session]);
    $rankings = $rk->fetchAll();

    // ── Unit Performance ──
    $up = $db->prepare("
        SELECT u.unit_id, u.unit_code, u.unit_name,
               COUNT(r.result_id) AS entries,
               ROUND(AVG(r.total),1) AS avg_score,
               MAX(r.total) AS max_score,
               MIN(r.total) AS min_score,
               SUM(CASE WHEN r.grade='A' THEN 1 ELSE 0 END) AS grade_a,
               SUM(CASE WHEN r.grade='B' THEN 1 ELSE 0 END) AS grade_b,
               SUM(CASE WHEN r.grade='C' THEN 1 ELSE 0 END) AS grade_c,
               SUM(CASE WHEN r.grade='D' THEN 1 ELSE 0 END) AS grade_d,
               SUM(CASE WHEN r.grade='F' THEN 1 ELSE 0 END) AS grade_f,
               SUM(CASE WHEN r.grade!='F' THEN 1 ELSE 0 END) AS pass_count
        FROM exam_results r
        JOIN exam_units u ON u.unit_id = r.unit_id
        WHERE r.session_id = ? AND r.total > 0
        GROUP BY r.unit_id
        ORDER BY avg_score DESC
    ");
    $up->execute([$sel_session]);
    $unitPerf = $up->fetchAll();

    // ── Grade Distribution ──
    $gd = $db->prepare("
        SELECT grade, COUNT(*) AS cnt
        FROM exam_results
        WHERE session_id=? AND total > 0
        GROUP BY grade ORDER BY FIELD(grade,'A','B','C','D','F')
    ");
    $gd->execute([$sel_session]);
    $gradeDist = $gd->fetchAll();
}

include __DIR__ . '/partials/header.php';

function gradeColor(string $g): string {
    return match($g) {
        'A' => '#15803d', 'B' => '#1d4ed8',
        'C' => '#b45309', 'D' => '#c2410c',
        default => '#dc2626'
    };
}
function gradeBg(string $g): string {
    return match($g) {
        'A' => 'rgba(22,163,74,.12)', 'B' => 'rgba(37,99,235,.12)',
        'C' => 'rgba(217,119,6,.12)', 'D' => 'rgba(234,88,12,.12)',
        default => 'rgba(220,38,38,.1)'
    };
}
?>

<style>
.session-select { padding:10px 14px; border:1px solid var(--border); border-radius:8px; background:var(--input-bg,var(--surface)); color:var(--text-primary); font-family:inherit; font-size:14px; min-width:320px; }
.rank-badge { width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; }
.rank-1 { background:#fef3c7; color:#92400e; }
.rank-2 { background:#f1f5f9; color:#334155; }
.rank-3 { background:#fdf4e7; color:#7c3b1a; }
.rank-n { background:var(--surface); color:var(--text-muted); border:1px solid var(--border); }

.unit-bar-row { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
.unit-bar-label { width:100px; font-size:11px; font-weight:700; color:var(--text-muted); text-overflow:ellipsis; overflow:hidden; white-space:nowrap; flex-shrink:0; }
.unit-bar-track { flex:1; height:22px; background:var(--border); border-radius:6px; overflow:hidden; position:relative; }
.unit-bar-fill { height:100%; border-radius:6px; transition:width .7s ease; display:flex; align-items:center; padding-left:8px; font-size:11px; font-weight:700; color:#fff; }
.unit-bar-score { font-family:'Space Mono',monospace; font-size:12px; font-weight:700; min-width:40px; text-align:right; }

.grade-donut-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; }
.grade-donut-item { text-align:center; padding:14px 8px; border-radius:10px; border:1px solid var(--border); }
.grade-donut-num { font-family:'Space Mono',monospace; font-size:24px; font-weight:700; }
.grade-donut-label { font-size:11px; color:var(--text-muted); margin-top:4px; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">📈 Analytics</h1>
        <p class="page-subtitle">Class rankings &amp; performance insights</p>
    </div>
    <?php if ($sel_session): ?>
    <a href="<?= APP_ROOT ?>/exams/transcripts.php?session_id=<?= $sel_session ?>" class="btn btn-ghost">🎓 Transcripts</a>
    <?php endif; ?>
</div>

<!-- Session picker -->
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);display:block;margin-bottom:8px">Exam Session</label>
        <select class="session-select" onchange="window.location='?session_id='+this.value">
            <option value="">— Select a session —</option>
            <?php foreach ($sessions as $s): ?>
            <option value="<?= $s['session_id'] ?>" <?= $sel_session===$s['session_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if (!$sel_session): ?>
<div class="card"><div style="padding:50px;text-align:center;color:var(--text-muted)">
    <div style="font-size:40px;margin-bottom:12px">📈</div>
    <p>Select an exam session to view analytics.</p>
</div></div>

<?php elseif (empty($rankings)): ?>
<div class="card"><div style="padding:50px;text-align:center;color:var(--text-muted)">
    <div style="font-size:40px;margin-bottom:12px">📊</div>
    <p>No results entered for this session yet.</p>
    <a href="<?= APP_ROOT ?>/exams/enter_marks.php?session_id=<?= $sel_session ?>" class="btn btn-primary" style="margin-top:12px">✏️ Enter Marks</a>
</div></div>

<?php else: ?>

<!-- ── Grade Distribution ── -->
<?php $total = array_sum(array_column($gradeDist, 'cnt')); ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header"><span class="card-title">🎯 Grade Distribution — <?= htmlspecialchars($sessionInfo['name']) ?></span></div>
    <div class="card-body">
        <div class="grade-donut-grid">
        <?php
        $allGrades = ['A','B','C','D','F'];
        $gradeMap  = array_column($gradeDist, 'cnt', 'grade');
        foreach ($allGrades as $g):
            $cnt = $gradeMap[$g] ?? 0;
            $pct = $total > 0 ? round(($cnt / $total) * 100) : 0;
        ?>
        <div class="grade-donut-item">
            <div class="grade-donut-num" style="color:<?= gradeColor($g) ?>"><?= $cnt ?></div>
            <div>
                <span class="grade-pill grade-<?= $g ?>" style="font-size:13px"><?= $g ?></span>
            </div>
            <div class="grade-donut-label"><?= $pct ?>%</div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- Stacked bar -->
        <div style="margin-top:20px;height:28px;border-radius:8px;overflow:hidden;display:flex">
        <?php foreach ($allGrades as $g):
            $cnt = $gradeMap[$g] ?? 0;
            $pct = $total > 0 ? ($cnt / $total) * 100 : 0;
            if ($pct < 0.5) continue;
        ?>
        <div title="<?= $g ?>: <?= $cnt ?>"
             style="width:<?= $pct ?>%;background:<?= gradeColor($g) ?>;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;transition:width .7s ease">
            <?= $pct > 5 ? $g : '' ?>
        </div>
        <?php endforeach; ?>
        </div>
        <div style="text-align:right;font-size:11px;color:var(--text-muted);margin-top:6px">
            <?= $total ?> total entries · Pass rate: <strong><?= $total > 0 ? round((($gradeMap['A']??0)+($gradeMap['B']??0)+($gradeMap['C']??0)+($gradeMap['D']??0)) / $total * 100) : 0 ?>%</strong>
        </div>
    </div>
</div>

<!-- ── Unit Performance ── -->
<?php if (!empty($unitPerf)): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header"><span class="card-title">📚 Unit Performance (Average Score)</span></div>
    <div class="card-body">
    <?php $maxAvg = max(array_column($unitPerf, 'avg_score')); ?>
    <?php foreach ($unitPerf as $up):
        $barPct = $maxAvg > 0 ? ($up['avg_score'] / 100) * 100 : 0;
        $passRate = $up['entries'] > 0 ? round(($up['pass_count'] / $up['entries']) * 100) : 0;
        $g = $up['avg_score'] >= 70 ? 'A' : ($up['avg_score'] >= 60 ? 'B' : ($up['avg_score'] >= 50 ? 'C' : ($up['avg_score'] >= 40 ? 'D' : 'F')));
    ?>
    <div class="unit-bar-row">
        <div class="unit-bar-label" title="<?= htmlspecialchars($up['unit_name']) ?>"><?= htmlspecialchars($up['unit_code']) ?></div>
        <div class="unit-bar-track">
            <div class="unit-bar-fill" style="width:<?= $barPct ?>%;background:<?= gradeColor($g) ?>">
                <?= $barPct > 15 ? htmlspecialchars($up['unit_name']) : '' ?>
            </div>
        </div>
        <div class="unit-bar-score"><?= $up['avg_score'] ?></div>
        <div style="font-size:11px;color:var(--text-muted);min-width:60px"><?= $passRate ?>% pass</div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- Unit detail table -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header"><span class="card-title">📊 Unit Breakdown</span></div>
    <div style="overflow-x:auto">
    <table class="data-table">
        <thead><tr>
            <th>Unit</th><th>Entries</th><th>Avg</th><th>High</th><th>Low</th>
            <th style="text-align:center">A</th><th style="text-align:center">B</th>
            <th style="text-align:center">C</th><th style="text-align:center">D</th>
            <th style="text-align:center">F</th><th>Pass%</th>
        </tr></thead>
        <tbody>
        <?php foreach ($unitPerf as $up):
            $pr = $up['entries'] > 0 ? round(($up['pass_count'] / $up['entries']) * 100) : 0;
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($up['unit_code']) ?></strong>
                <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($up['unit_name']) ?></div>
            </td>
            <td style="font-family:'Space Mono',monospace"><?= $up['entries'] ?></td>
            <td style="font-family:'Space Mono',monospace;font-weight:700"><?= $up['avg_score'] ?></td>
            <td style="font-family:'Space Mono',monospace;color:#15803d"><?= $up['max_score'] ?></td>
            <td style="font-family:'Space Mono',monospace;color:#dc2626"><?= $up['min_score'] ?></td>
            <?php foreach (['grade_a','grade_b','grade_c','grade_d','grade_f'] as $gk): ?>
            <td style="text-align:center;font-family:'Space Mono',monospace"><?= $up[$gk] ?></td>
            <?php endforeach; ?>
            <td>
                <div style="display:flex;align-items:center;gap:6px">
                    <div style="flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                        <div style="height:100%;width:<?= $pr ?>%;background:<?= $pr>=70?'#15803d':($pr>=50?'#d97706':'#dc2626') ?>;border-radius:3px"></div>
                    </div>
                    <span style="font-size:12px;font-weight:700"><?= $pr ?>%</span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Class Rankings ── -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <span class="card-title">🏆 Class Rankings — <?= count($rankings) ?> students</span>
        <span style="font-size:12px;color:var(--text-muted)">Ranked by average score</span>
    </div>
    <div style="overflow-x:auto">
    <table class="data-table">
        <thead><tr>
            <th style="width:50px">Rank</th>
            <th>Student</th>
            <th>Group</th>
            <th style="text-align:center">Units</th>
            <th style="text-align:center">A's</th>
            <th style="text-align:center">Fails</th>
            <th style="text-align:center">Avg Score</th>
            <th style="text-align:center">Grade</th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rankings as $pos => $stu):
            $rank   = $pos + 1;
            $g      = $stu['avg_score'] >= 70 ? 'A' : ($stu['avg_score'] >= 60 ? 'B' : ($stu['avg_score'] >= 50 ? 'C' : ($stu['avg_score'] >= 40 ? 'D' : 'F')));
            $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-n'));
        ?>
        <tr>
            <td style="text-align:center">
                <?php if ($rank <= 3): ?>
                    <span style="font-size:20px"><?= ['🥇','🥈','🥉'][$rank-1] ?></span>
                <?php else: ?>
                    <span class="rank-badge rank-n"><?= $rank ?></span>
                <?php endif; ?>
            </td>
            <td>
                <div style="font-weight:600"><?= htmlspecialchars($stu['full_name']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);font-family:'Space Mono',monospace"><?= htmlspecialchars($stu['student_id']) ?></div>
            </td>
            <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($stu['group_name'] ?? '—') ?></td>
            <td style="text-align:center;font-family:'Space Mono',monospace"><?= $stu['units_sat'] ?></td>
            <td style="text-align:center;font-family:'Space Mono',monospace;color:#15803d;font-weight:700"><?= $stu['a_count'] ?></td>
            <td style="text-align:center;font-family:'Space Mono',monospace;color:<?= $stu['f_count'] > 0 ? '#dc2626' : 'var(--text-muted)' ?>"><?= $stu['f_count'] ?></td>
            <td style="text-align:center">
                <div style="display:flex;align-items:center;gap:8px;justify-content:center">
                    <div style="width:80px;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                        <div style="height:100%;width:<?= $stu['avg_score'] ?>%;background:<?= gradeColor($g) ?>;border-radius:3px"></div>
                    </div>
                    <span style="font-family:'Space Mono',monospace;font-weight:700;font-size:14px"><?= $stu['avg_score'] ?></span>
                </div>
            </td>
            <td style="text-align:center"><span class="grade-pill grade-<?= $g ?>"><?= $g ?></span></td>
            <td>
                <a href="<?= APP_ROOT ?>/exams/transcripts.php?session_id=<?= $sel_session ?>&student_id=<?= urlencode($stu['student_id']) ?>"
                   class="btn btn-ghost btn-sm">🎓</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
