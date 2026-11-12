<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'View Results';
$activePage = 'exam_results';
$db = getDB();

$sessionFilter = intval($_GET['session_id'] ?? 0);
$unitFilter    = intval($_GET['unit_id']    ?? 0);
$gradeFilter   = trim($_GET['grade']        ?? '');
$outcomeFilter = ($_GET['outcome'] ?? '') === 'pass' ? 'pass' : '';
$showAllResults = ($_GET['all'] ?? '') === '1';
$search        = trim($_GET['q']            ?? '');
$viewMode      = $_GET['view'] ?? 'table';

$allSessions = $db->query("SELECT * FROM exam_sessions ORDER BY session_id DESC")->fetchAll();
$allUnits    = [];
$sessionData = null;

if ($sessionFilter) {
    $sq = $db->prepare("SELECT * FROM exam_sessions WHERE session_id=?");
    $sq->execute([$sessionFilter]);
    $sessionData = $sq->fetch();
    if ($sessionData) {
        $uq = $db->prepare("SELECT * FROM exam_units WHERE is_active=1 AND programme=? ORDER BY unit_code");
        $uq->execute([$sessionData['programme']]);
        $allUnits = $uq->fetchAll();
    }
} elseif ($showAllResults) {
    $allUnits = $db->query("SELECT * FROM exam_units WHERE is_active=1 ORDER BY unit_code")->fetchAll();
}

$where  = ['1=1'];
$params = [];
if ($sessionFilter) { $where[] = 'r.session_id=?'; $params[] = $sessionFilter; }
if ($unitFilter)    { $where[] = 'r.unit_id=?';    $params[] = $unitFilter; }
if ($gradeFilter)   { $where[] = 'r.grade=?';      $params[] = $gradeFilter; }
if ($outcomeFilter === 'pass') { $where[] = "r.grade != 'F' AND r.total > 0"; }
if ($search)        { $where[] = '(COALESCE(fs.full_name,r.student_id) LIKE ? OR r.student_id LIKE ?)';
                      $params[] = "%$search%"; $params[] = "%$search%"; }

$results = [];
if ($sessionFilter || $showAllResults) {
    $rq = $db->prepare("
        SELECT r.*, u.unit_code, u.unit_name,
               COALESCE(fs.full_name, r.student_id) AS student_name,
               g.name AS group_name
        FROM exam_results r
        JOIN exam_units u ON u.unit_id=r.unit_id
        LEFT JOIN fee_students fs ON fs.student_id=r.student_id
        LEFT JOIN fee_groups g ON g.group_id=fs.group_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY student_name ASC, u.unit_code ASC
    ");
    $rq->execute($params);
    $results = $rq->fetchAll();
}

// Build per-student summary
$byStudent = [];
foreach ($results as $r) {
    $sid = $r['student_id'];
    if (!isset($byStudent[$sid])) $byStudent[$sid] = [
        'student_id'=>$sid,'student_name'=>$r['student_name'],
        'group_name'=>$r['group_name']??'—','units'=>[],'pts'=>0,'cnt'=>0
    ];
    $byStudent[$sid]['units'][] = $r;
    $byStudent[$sid]['pts']    += floatval($r['total']);
    $byStudent[$sid]['cnt']++;
}
foreach ($byStudent as &$s) $s['avg'] = $s['cnt']>0 ? round($s['pts']/$s['cnt'],1) : 0;
unset($s);
usort($byStudent, fn($a,$b)=>$b['avg']<=>$a['avg']);

include __DIR__ . '/partials/header.php';
?>
<style>
.fr{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.fr input,.fr select{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px}
.fr input{flex:1;min-width:180px}
.vbtn{padding:7px 14px;border:1px solid var(--border);border-radius:8px;font-size:12px;background:var(--surface);color:var(--text-muted);text-decoration:none;transition:background .15s}
.vbtn.on{background:#1a3a6b;color:#fff;border-color:#1a3a6b}
.sb{border:1px solid var(--border);border-radius:10px;margin-bottom:12px;overflow:hidden}
.sb-hd{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--surface);cursor:pointer;border-bottom:1px solid var(--border)}
.sb-hd:hover{background:var(--surface-hover)}
.ur{display:grid;grid-template-columns:110px 1fr 65px 65px 70px 55px;gap:0;padding:8px 16px;border-bottom:1px solid var(--border);align-items:center;font-size:12px}
.ur:last-child{border-bottom:none}
.ur:hover{background:var(--table-hover)}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= appIcon('clipboard') ?> View Results</h1>
        <p class="page-subtitle"><?= $sessionData ? htmlspecialchars($sessionData['name']) : ($showAllResults ? 'All sessions' : 'Select a session') ?></p>
    </div>
    <div style="display:flex;gap:8px">
        <?php if ($sessionFilter): ?>
        <a href="<?= APP_ROOT ?>/exams/enter_marks.php?session_id=<?= $sessionFilter ?>" class="btn btn-primary"><?= appIcon('pencil') ?> Enter Marks</a>
        <a href="<?= APP_ROOT ?>/exams/transcripts.php?session_id=<?= $sessionFilter ?>" class="btn btn-ghost"><?= appIcon('graduation-cap') ?> Transcripts</a>
        <?php endif; ?>
    </div>
</div>

<div class="fr">
    <select onchange="applyF('session_id',this.value)" style="flex:2;min-width:200px">
        <option value="">— Select Session —</option>
        <?php foreach ($allSessions as $s): ?>
        <option value="<?= $s['session_id'] ?>" <?= $s['session_id']==$sessionFilter?'selected':'' ?>>
            <?= htmlspecialchars($s['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php if ($allUnits): ?>
    <select onchange="applyF('unit_id',this.value)">
        <option value="">All Units</option>
        <?php foreach ($allUnits as $u): ?>
        <option value="<?= $u['unit_id'] ?>" <?= $u['unit_id']==$unitFilter?'selected':'' ?>>
            [<?= htmlspecialchars($u['unit_code']) ?>] <?= htmlspecialchars($u['unit_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select onchange="applyF('grade',this.value)">
        <option value="">All Grades</option>
        <?php foreach(['A','B','C','D','E','F'] as $g): ?>
        <option value="<?= $g ?>" <?= $gradeFilter===$g?'selected':'' ?>><?= $g ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" id="sq" placeholder="Search student…"
           value="<?= htmlspecialchars($search) ?>" oninput="lsearch(this.value)">
    <div style="display:flex;gap:4px">
        <a href="?<?= http_build_query(array_merge($_GET,['view'=>'table'])) ?>" class="vbtn <?= $viewMode==='table'?'on':'' ?>">≡ Table</a>
        <a href="?<?= http_build_query(array_merge($_GET,['view'=>'student'])) ?>" class="vbtn <?= $viewMode==='student'?'on':'' ?>"><?= appIcon('users') ?> By Student</a>
    </div>
</div>

<?php if (!$sessionFilter && !$showAllResults): ?>
<div class="card"><div class="card-body" style="padding:50px;text-align:center;color:var(--text-muted)">
    <div style="font-size:40px;margin-bottom:12px"><?= appIcon('clipboard') ?></div><p>Select an exam session above.</p>
</div></div>

<?php elseif (empty($results)): ?>
<div class="card"><div class="card-body" style="padding:50px;text-align:center;color:var(--text-muted)">
    <div style="font-size:36px;margin-bottom:12px"><?= appIcon('file') ?></div>
    <p>No results match the selected filters.</p>
    <a href="<?= APP_ROOT ?>/exams/enter_marks.php<?= $sessionFilter ? '?session_id=' . $sessionFilter : '' ?>" class="btn btn-primary" style="margin-top:14px"><?= appIcon('pencil') ?> Enter Marks</a>
</div></div>

<?php elseif ($viewMode==='student'): ?>
<div style="margin-bottom:12px;font-size:13px;color:var(--text-muted)"><?= pluralizeCount(count($byStudent), 'student') ?> &nbsp;·&nbsp; <?= pluralizeCount(count($results), 'entry') ?></div>
<div id="sl">
<?php foreach ($byStudent as $rank => $stu):
    $ag = $stu['avg']>=70?'A':($stu['avg']>=60?'B':($stu['avg']>=50?'C':($stu['avg']>=40?'D':'F')));
?>
<div class="sb" data-n="<?= htmlspecialchars(strtolower($stu['student_name'])) ?>" data-id="<?= htmlspecialchars(strtolower($stu['student_id'])) ?>">
    <div class="sb-hd" onclick="tb(<?= $rank ?>)">
        <div style="display:flex;align-items:center;gap:12px">
            <div style="font-size:18px;width:26px;text-align:center"><?= $rank < 3 ? appIcon('trophy') . ' ' . ($rank + 1) : $rank + 1 ?></div>
            <div>
                <div style="font-weight:700;font-size:14px"><?= htmlspecialchars(formatPersonName($stu['student_name'])) ?></div>
                <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($stu['student_id']) ?> · <?= htmlspecialchars(formatTitleCase($stu['group_name'])) ?></div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px">
            <span style="font-size:11px;color:var(--text-muted)"><?= pluralizeCount($stu['cnt'], 'unit') ?></span>
            <span class="grade-pill grade-<?= $ag ?>" style="font-size:13px;padding:5px 14px"><?= formatPercentage($stu['avg']) ?></span>
            <span class="expand-chevron" id="ar-<?= $rank ?>"><?= appIcon('chevron-down') ?></span>
        </div>
    </div>
    <div id="bd-<?= $rank ?>" style="display:none">
        <div class="ur" style="background:var(--bg);font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted)">
            <div>Code</div><div>Unit Name</div>
            <div style="text-align:center">CA/30</div><div style="text-align:center">Exam/70</div>
            <div style="text-align:center">Total</div><div style="text-align:center">Grade</div>
        </div>
        <?php foreach ($stu['units'] as $ur): ?>
        <div class="ur">
            <div><code style="font-size:10px"><?= htmlspecialchars($ur['unit_code']) ?></code></div>
            <div><?= htmlspecialchars($ur['unit_name']) ?></div>
            <div style="text-align:center"><?= $ur['ca_score'] !== null ? formatMark($ur['ca_score']) : '—' ?></div>
            <div style="text-align:center"><?= $ur['exam_score'] !== null ? formatMark($ur['exam_score']) : '—' ?></div>
            <div style="text-align:center"><span class="grade-pill grade-<?= $ur['grade'] ?>"><?= formatMark($ur['total']) ?></span></div>
            <div style="text-align:center"><span class="grade-pill grade-<?= $ur['grade'] ?>"><?= $ur['grade'] ?></span></div>
        </div>
        <?php endforeach; ?>
        <div style="padding:10px 16px;border-top:1px solid var(--border)">
            <?php if ($sessionFilter): ?>
                <a href="<?= APP_ROOT ?>/exams/transcripts.php?session_id=<?= $sessionFilter ?>&student_id=<?= urlencode($stu['student_id']) ?>" class="btn btn-ghost btn-sm"><?= appIcon('graduation-cap') ?> Transcript</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php else: ?>
<div class="card">
    <div class="card-body" style="padding:0">
        <div style="padding:10px 16px;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-muted)"><?= pluralizeCount(count($results), 'result') ?></div>
        <div style="overflow-x:auto">
        <table class="data-table" id="rt">
            <thead><tr>
                <th>Student</th><th>Adm No</th><th>Unit</th>
                <th style="text-align:center">CA /30</th><th style="text-align:center">Exam /70</th>
                <th style="text-align:center">Total</th><th style="text-align:center">Grade</th>
                <th>Remarks</th>
            </tr></thead>
            <tbody id="rb">
            <?php foreach ($results as $r): ?>
            <tr data-n="<?= htmlspecialchars(strtolower($r['student_name'])) ?>" data-id="<?= htmlspecialchars(strtolower($r['student_id'])) ?>">
                <td style="font-weight:600"><?= htmlspecialchars(formatPersonName($r['student_name'])) ?></td>
                <td><code style="font-size:11px"><?= htmlspecialchars($r['student_id']) ?></code></td>
                <td><code style="font-size:11px"><?= htmlspecialchars($r['unit_code']) ?></code><br>
                    <span style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['unit_name']) ?></span></td>
                <td style="text-align:center;font-size:12px"><?= $r['ca_score'] !== null ? formatMark($r['ca_score']) : '—' ?></td>
                <td style="text-align:center;font-size:12px"><?= $r['exam_score'] !== null ? formatMark($r['exam_score']) : '—' ?></td>
                <td style="text-align:center"><span class="grade-pill grade-<?= $r['grade'] ?>"><?= formatMark($r['total']) ?></span></td>
                <td style="text-align:center"><span class="grade-pill grade-<?= $r['grade'] ?>"><?= $r['grade'] ?></span></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($r['remarks']??'') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function applyF(k,v){const u=new URL(window.location.href);v?u.searchParams.set(k,v):u.searchParams.delete(k);u.searchParams.delete('q');window.location.href=u.toString();}
function lsearch(q){q=q.toLowerCase();
    document.querySelectorAll('#rb tr').forEach(r=>{const n=r.dataset.n||'',id=r.dataset.id||'';r.style.display=(!q||n.includes(q)||id.includes(q))?'':'none';});
    document.querySelectorAll('#sl .sb').forEach(b=>{const n=b.dataset.n||'',id=b.dataset.id||'';b.style.display=(!q||n.includes(q)||id.includes(q))?'':'none';});
}
function tb(i){const b=document.getElementById('bd-'+i),a=document.getElementById('ar-'+i),o=b.style.display!=='none';b.style.display=o?'none':'block';a.classList.toggle('collapsed',o);}
const sq=document.getElementById('sq');if(sq&&sq.value)lsearch(sq.value);
</script>
<style>
.expand-chevron { display: inline-flex; transition: transform .2s; }
.expand-chevron.collapsed { transform: rotate(180deg); }
</style>
<?php include __DIR__ . '/partials/footer.php'; ?>
