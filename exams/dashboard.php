<?php
// ============================================================
// exams/dashboard.php
// Exam Results Module — Overview Dashboard
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../shared/dashboard_components.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Exam Dashboard';
$activePage = 'exam_dashboard';
$db = getDB();
$buildDashboardUrl = static function (array $changes = []): string {
    $query = $_GET;
    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }
    return APP_ROOT . '/exams/dashboard.php' . ($query ? '?' . http_build_query($query) : '');
};
$sortHeader = static function (
    string $label,
    string $key,
    string $activeSort,
    string $direction,
    string $sortParam,
    string $directionParam,
    bool $numeric = false
) use ($buildDashboardUrl): string {
    $nextDirection = $activeSort === $key && $direction === 'ASC' ? 'desc' : 'asc';
    $ariaSort = $activeSort === $key ? ($direction === 'ASC' ? 'ascending' : 'descending') : 'none';
    $indicator = $activeSort === $key ? ($direction === 'ASC' ? '↑' : '↓') : '↕';
    $href = htmlspecialchars($buildDashboardUrl([
        $sortParam => $key,
        $directionParam => $nextDirection,
    ]), ENT_QUOTES, 'UTF-8');
    return '<th scope="col"' . ($numeric ? ' class="exam-number"' : '') . ' aria-sort="' . $ariaSort . '"><a class="table-sort-link" href="' . $href . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '<span aria-hidden="true">' . $indicator . '</span></a></th>';
};

// ── Summary stats ──
$totalSessions  = (int) $db->query("SELECT COUNT(*) FROM exam_sessions")->fetchColumn();
$totalResults   = (int) $db->query("SELECT COUNT(*) FROM exam_results")->fetchColumn();
$totalUnits     = (int) $db->query("SELECT COUNT(*) FROM exam_units WHERE is_active=1")->fetchColumn();

// Pass rate (grade != 'F')
$passCount = (int) $db->query("SELECT COUNT(*) FROM exam_results WHERE grade != 'F' AND total > 0")->fetchColumn();

// Grade distribution
$gradeRows = $db->query("
    SELECT grade, COUNT(*) AS cnt
    FROM exam_results
    WHERE total > 0
    GROUP BY grade
    ORDER BY FIELD(grade,'A','B','C','D','E','F')
")->fetchAll();
$gradeNames = ['A', 'B', 'C', 'D', 'E', 'F'];
$gradeDistribution = array_fill_keys($gradeNames, 0);
foreach ($gradeRows as $gradeRow) {
    if (isset($gradeDistribution[$gradeRow['grade']])) {
        $gradeDistribution[$gradeRow['grade']] = (int) $gradeRow['cnt'];
    }
}
$gradedResultCount = array_sum($gradeDistribution);
$passRate = $gradedResultCount > 0 ? round(($passCount / $gradedResultCount) * 100, 1) : 0;

// Recent sessions
$sessionSortColumns = [
    'session' => 's.name',
    'programme' => 's.programme',
    'year' => 's.year_level',
    'entries' => 'entries',
    'status' => 's.is_locked',
];
$requestedSessionSort = $_GET['session_sort'] ?? 'session';
$sessionSortKey = is_string($requestedSessionSort) && isset($sessionSortColumns[$requestedSessionSort])
    ? $requestedSessionSort
    : 'session';
$requestedSessionDirection = $_GET['session_dir'] ?? 'asc';
$sessionSortDirection = is_string($requestedSessionDirection) && strtolower($requestedSessionDirection) === 'desc'
    ? 'DESC'
    : 'ASC';
$sessions = $db->query("
    SELECT s.*,
           a.full_name AS created_by_name,
           COUNT(DISTINCT r.result_id) AS entries
    FROM exam_sessions s
    LEFT JOIN admin_users a ON a.admin_id = s.created_by
    LEFT JOIN exam_results r ON r.session_id = s.session_id
    GROUP BY s.session_id
    ORDER BY " . $sessionSortColumns[$sessionSortKey] . " $sessionSortDirection, s.session_id DESC
    LIMIT 6
")->fetchAll();

// Top performers (avg total across all units, minimum 2 results)
$performerSortColumns = [
    'student' => 'full_name',
    'units' => 'unit_count',
    'average' => 'avg_score',
];
$requestedPerformerSort = $_GET['performer_sort'] ?? 'average';
$performerSortKey = is_string($requestedPerformerSort) && isset($performerSortColumns[$requestedPerformerSort])
    ? $requestedPerformerSort
    : 'average';
$requestedPerformerDirection = $_GET['performer_dir'] ?? 'desc';
$performerSortDirection = is_string($requestedPerformerDirection) && strtolower($requestedPerformerDirection) === 'asc'
    ? 'ASC'
    : 'DESC';
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
    ORDER BY " . $performerSortColumns[$performerSortKey] . " $performerSortDirection, r.student_id ASC
    LIMIT 8
")->fetchAll();

// Recent entries (last 12)
$entrySortColumns = [
    'student' => 'student_name',
    'unit' => 'u.unit_code',
    'total' => 'r.total',
    'grade' => "FIELD(r.grade,'A','B','C','D','E','F')",
    'updated' => 'r.updated_at',
];
$requestedEntrySort = $_GET['entry_sort'] ?? 'updated';
$entrySortKey = is_string($requestedEntrySort) && isset($entrySortColumns[$requestedEntrySort])
    ? $requestedEntrySort
    : 'updated';
$requestedEntryDirection = $_GET['entry_dir'] ?? 'desc';
$entrySortDirection = is_string($requestedEntryDirection) && strtolower($requestedEntryDirection) === 'asc'
    ? 'ASC'
    : 'DESC';
$recent = $db->query("
    SELECT r.*, u.unit_name, u.unit_code, s.name AS session_name,
           COALESCE(fs.full_name, r.student_id) AS student_name,
           a.full_name AS entered_by_name
    FROM exam_results r
    JOIN exam_units u ON u.unit_id = r.unit_id
    JOIN exam_sessions s ON s.session_id = r.session_id
    LEFT JOIN fee_students fs ON fs.student_id = r.student_id
    LEFT JOIN admin_users a ON a.admin_id = r.entered_by
    ORDER BY " . $entrySortColumns[$entrySortKey] . " $entrySortDirection, r.result_id DESC
    LIMIT 12
")->fetchAll();

include __DIR__ . '/partials/header.php';

// Grade colour helper
function gradeColor(string $g): string {
    return match($g) {
        'A', 'B' => '#15803d',
        'C', 'D' => '#b45309',
        default => '#dc2626'
    };
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= appIcon('chart') ?> Exam Results Dashboard</h1>
        <p class="page-subtitle">
            <?= $totalResults > 0
                ? pluralizeCount($totalResults, 'result') . ' across ' . pluralizeCount($totalSessions, 'exam session') . ' · ' . pluralizeCount($totalUnits, 'active unit') . ' · ' . formatPercentage($passRate) . ' pass rate'
                : 'No marks have been entered yet. Enter marks to start the results overview.' ?>
        </p>
    </div>
    <div class="dashboard-actions">
        <a href="<?= APP_ROOT ?>/exams/enter_marks.php" class="btn btn-primary"><?= appIcon('pencil') ?> Enter Marks</a>
        <a href="<?= APP_ROOT ?>/exams/sessions.php" class="btn btn-ghost"><?= appIcon('calendar') ?> Sessions</a>
    </div>
</div>

<style>
.exam-data-table th,.exam-data-table td{padding:12px 14px}
.exam-data-table thead th{position:sticky;top:0;z-index:2;background:var(--surface)}
.exam-data-table .table-sort-link{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;white-space:nowrap}
.exam-data-table .table-sort-link:hover{color:var(--module-accent)}
.exam-data-table .table-sort-link span{font-size:11px;color:var(--text-muted)}
.exam-data-table .exam-number{text-align:right;font-variant-numeric:tabular-nums}
.exam-data-table td.exam-number{font-variant-numeric:tabular-nums}
.exam-data-table tbody tr[data-row-href]{cursor:pointer}
.exam-data-table tbody tr[data-row-href]:hover td{background:var(--table-hover)}
.exam-data-table tbody tr[data-row-href]:focus-visible{outline:2px solid var(--module-accent);outline-offset:-2px}
</style>

<!-- ── Stat Cards ── -->
<div class="dashboard-stat-grid">
    <?php
    renderDashboardStatCard([
        'label' => 'Exam Sessions',
        'value' => formatNumber($totalSessions),
        'summary' => $totalSessions > 0 ? 'Manage exam sessions' : 'Create the first exam session',
        'href' => APP_ROOT . '/exams/sessions.php',
        'tone' => 'info',
        'problem' => true,
        'active' => $totalSessions > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Total Results Entered',
        'value' => formatNumber($totalResults),
        'summary' => $totalResults > 0 ? pluralizeCount($totalUnits, 'active unit') : 'No marks entered yet',
        'href' => APP_ROOT . '/exams/results.php?all=1',
        'tone' => 'info',
        'problem' => true,
        'active' => $totalResults > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Overall Pass Rate',
        'value' => formatPercentage($passRate),
        'summary' => $totalResults > 0 ? pluralizeCount($passCount, 'pass') : 'Enter marks to calculate the pass rate',
        'href' => APP_ROOT . '/exams/results.php?all=1&outcome=pass',
        'tone' => $totalResults === 0 ? 'neutral' : ($passRate >= 80 ? 'success' : ($passRate >= 50 ? 'warning' : 'danger')),
        'problem' => true,
        'active' => $totalResults > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Active Units',
        'value' => formatNumber($totalUnits),
        'summary' => $totalUnits > 0 ? 'View configured units' : 'No active units configured',
        'href' => APP_ROOT . '/exams/units.php',
        'tone' => 'info',
        'problem' => true,
        'active' => $totalUnits > 0,
    ]);
    ?>
</div>

<div class="exam-dashboard-main-grid">

    <!-- Recent Sessions -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <span class="card-title"><?= appIcon('calendar') ?> Exam Sessions</span>
            <a href="<?= APP_ROOT ?>/exams/sessions.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($sessions)): ?>
                <div style="padding:30px;text-align:center;color:var(--text-muted)">
                    No sessions yet. <a href="<?= APP_ROOT ?>/exams/sessions.php">Create one <?= appIcon('arrow-right') ?></a>
                </div>
            <?php else: ?>
            <div class="table-scroll">
            <table class="data-table exam-data-table">
                <caption class="sr-only">Recent exam sessions</caption>
                <thead><tr>
                    <?= $sortHeader('Session', 'session', $sessionSortKey, $sessionSortDirection, 'session_sort', 'session_dir') ?>
                    <?= $sortHeader('Programme', 'programme', $sessionSortKey, $sessionSortDirection, 'session_sort', 'session_dir') ?>
                    <?= $sortHeader('Year', 'year', $sessionSortKey, $sessionSortDirection, 'session_sort', 'session_dir') ?>
                    <?= $sortHeader('Entries', 'entries', $sessionSortKey, $sessionSortDirection, 'session_sort', 'session_dir', true) ?>
                    <?= $sortHeader('Status', 'status', $sessionSortKey, $sessionSortDirection, 'session_sort', 'session_dir') ?>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($sessions as $ses): ?>
                <tr data-row-href="<?= APP_ROOT ?>/exams/results.php?session_id=<?= (int) $ses['session_id'] ?>" tabindex="0"
                    aria-label="View results for <?= htmlspecialchars($ses['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <td style="font-weight:600"><?= htmlspecialchars($ses['name']) ?></td>
                    <td><span style="font-size:11px"><?= htmlspecialchars(formatTitleCase($ses['programme'])) ?></span></td>
                    <td style="font-size:12px"><?= htmlspecialchars($ses['year_level'] ?? '—') ?></td>
                    <td><span style="font-size:12px"><?= pluralizeCount($ses['entries'], 'entry') ?></span></td>
                    <td>
                        <?php if ($ses['is_locked']): ?>
                            <span class="locked-badge"><?= appIcon('lock') ?> Locked</span>
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
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Grade Distribution -->
    <div class="card">
        <div class="card-header"><span class="card-title"><?= appIcon('chart') ?> Grade Distribution</span></div>
        <div class="card-body">
        <?php foreach ($gradeNames as $grade): ?>
            <?php
                $gradeCount = $gradeDistribution[$grade];
                $gradePercent = $gradedResultCount > 0 ? ($gradeCount / $gradedResultCount) * 100 : 0;
            ?>
            <div class="exam-grade-row">
                <span class="grade-pill grade-<?= $grade ?>"><?= $grade ?></span>
                <div class="exam-grade-track" role="progressbar"
                     aria-label="Grade <?= $grade ?> share"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= round($gradePercent, 1) ?>">
                    <div class="exam-grade-fill" style="width:<?= $gradePercent ?>%;background:<?= gradeColor($grade) ?>"></div>
                </div>
                <span class="exam-grade-count"><?= formatNumber($gradeCount) ?> · <?= formatPercentage($gradePercent) ?></span>
                </div>
            <?php endforeach; ?>
        <?php if ($gradedResultCount === 0): ?>
            <div class="dashboard-empty-state">
                <p>No marks have been entered yet.</p>
                <a href="<?= APP_ROOT ?>/exams/enter_marks.php" class="btn btn-ghost"><?= appIcon('pencil') ?> Enter Marks</a>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Top Performers + Recent Activity -->
<div class="exam-dashboard-lower-grid">

    <!-- Top Performers -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <span class="card-title"><?= appIcon('trophy') ?> Top Performers</span>
            <a href="<?= APP_ROOT ?>/exams/analytics.php" class="btn btn-ghost btn-sm">Analytics</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (count($topStudents) < 5): ?>
                <div class="dashboard-empty-state">
                    <p>Enter marks for at least 5 students across 2 or more units to see rankings.</p>
                    <a href="<?= APP_ROOT ?>/exams/enter_marks.php" class="btn btn-ghost"><?= appIcon('pencil') ?> Enter Marks</a>
                </div>
            <?php else: ?>
            <div class="table-scroll">
            <table class="data-table exam-data-table">
                <caption class="sr-only">Top exam performers</caption>
                <thead><tr>
                    <th scope="col">#</th>
                    <?= $sortHeader('Student', 'student', $performerSortKey, $performerSortDirection, 'performer_sort', 'performer_dir') ?>
                    <?= $sortHeader('Units', 'units', $performerSortKey, $performerSortDirection, 'performer_sort', 'performer_dir', true) ?>
                    <?= $sortHeader('Avg', 'average', $performerSortKey, $performerSortDirection, 'performer_sort', 'performer_dir', true) ?>
                </tr></thead>
                <tbody>
                <?php foreach ($topStudents as $i => $st): ?>
                <tr data-row-href="<?= APP_ROOT ?>/exams/results.php?all=1&amp;q=<?= rawurlencode($st['student_id']) ?>" tabindex="0"
                    aria-label="View results for <?= htmlspecialchars(formatPersonName($st['full_name']), ENT_QUOTES, 'UTF-8') ?>">
                    <td>
                        <?php if ($i < 3): ?><span style="font-size:16px"><?= appIcon('trophy') ?> <?= $i + 1 ?></span>
                        <?php else: ?><span style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:13px"><?= htmlspecialchars(formatPersonName($st['full_name'])) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($st['student_id']) ?></div>
                    </td>
                    <td><?= formatNumber($st['unit_count']) ?></td>
                    <td>
                        <?php
                        $avg = $st['avg_score'];
                        $g = $avg >= 70 ? 'A' : ($avg >= 60 ? 'B' : ($avg >= 50 ? 'C' : ($avg >= 40 ? 'D' : 'F')));
                        ?>
                        <span class="grade-pill grade-<?= $g ?>"><?= $g ?> · <?= formatMark($avg) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <div class="card-header"><span class="card-title"><?= appIcon('clock') ?> Recent Entries</span></div>
        <div class="card-body" style="padding:0">
            <?php if (empty($recent)): ?>
                <div class="dashboard-empty-state">
                    <p>No marks have been entered yet.</p>
                    <a href="<?= APP_ROOT ?>/exams/enter_marks.php" class="btn btn-ghost"><?= appIcon('pencil') ?> Enter Marks</a>
                </div>
            <?php else: ?>
            <div class="table-scroll">
            <table class="data-table exam-data-table">
                <caption class="sr-only">Recent exam entries</caption>
                <thead><tr>
                    <?= $sortHeader('Student', 'student', $entrySortKey, $entrySortDirection, 'entry_sort', 'entry_dir') ?>
                    <?= $sortHeader('Unit', 'unit', $entrySortKey, $entrySortDirection, 'entry_sort', 'entry_dir') ?>
                    <?= $sortHeader('Total', 'total', $entrySortKey, $entrySortDirection, 'entry_sort', 'entry_dir', true) ?>
                    <?= $sortHeader('Grade', 'grade', $entrySortKey, $entrySortDirection, 'entry_sort', 'entry_dir') ?>
                    <?= $sortHeader('Updated', 'updated', $entrySortKey, $entrySortDirection, 'entry_sort', 'entry_dir') ?>
                </tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                <tr data-row-href="<?= APP_ROOT ?>/exams/results.php?session_id=<?= (int) $r['session_id'] ?>" tabindex="0"
                    aria-label="View session results for <?= htmlspecialchars(formatPersonName($r['student_name']), ENT_QUOTES, 'UTF-8') ?>">
                    <td>
                        <div style="font-size:12px;font-weight:600"><?= htmlspecialchars(formatPersonName($r['student_name'])) ?></div>
                        <div style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($r['session_name']) ?></div>
                    </td>
                    <td style="font-size:12px"><?= htmlspecialchars($r['unit_code']) ?></td>
                    <td class="exam-number"><?= formatMark($r['total']) ?></td>
                    <td><span class="grade-pill grade-<?= $r['grade'] ?>"><?= $r['grade'] ?></span></td>
                    <td class="date-value"><?= formatDate($r['updated_at'], true) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.exam-data-table tbody tr[data-row-href]').forEach(function (row) {
    row.addEventListener('click', function (event) {
        if (event.target.closest('a, button, form, input, select')) return;
        window.location.href = row.dataset.rowHref;
    });
    row.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target === row) window.location.href = row.dataset.rowHref;
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
