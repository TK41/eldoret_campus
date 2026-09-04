<?php
// ============================================================
// exams/enter_marks.php
// Real-time mark entry grid — autosaves on blur/change via AJAX
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Enter Marks';
$activePage = 'exam_enter';
$db = getDB();

$selectedSession = intval($_GET['session_id'] ?? 0);
$selectedUnit    = intval($_GET['unit_id']    ?? 0);

// Load all sessions
$allSessions = $db->query("SELECT * FROM exam_sessions ORDER BY session_id DESC")->fetchAll();

$sessionData   = null;
$units         = [];
$students      = [];
$existingMarks = [];

if ($selectedSession) {
    $stm = $db->prepare("SELECT * FROM exam_sessions WHERE session_id=?");
    $stm->execute([$selectedSession]);
    $sessionData = $stm->fetch();

    if ($sessionData) {
        // Units filtered by programme + year_level
        $uq = $db->prepare("
            SELECT * FROM exam_units
            WHERE is_active=1 AND programme=?
              AND (year_level=? OR year_level IS NULL OR ?='')
            ORDER BY unit_code
        ");
        $yl = $sessionData['year_level'] ?? '';
        $uq->execute([$sessionData['programme'], $yl, $yl]);
        $units = $uq->fetchAll();

        // Students — match by programme prefix
        $progLike = $sessionData['programme'] === 'certificate' ? 'CERT%' : 'DIPLOMA%';
        $ylFilter = '';
        $ylParams = [$progLike];
        if ($yl) { $ylFilter = " AND g.year_label LIKE ?"; $ylParams[] = '%' . $yl . '%'; }

        $sq = $db->prepare("
            SELECT fs.student_id, fs.full_name, fs.programme, g.name AS group_name
            FROM fee_students fs
            JOIN fee_groups g ON g.group_id=fs.group_id
            WHERE fs.is_active=1 AND fs.programme LIKE ? $ylFilter
            ORDER BY fs.full_name ASC
        ");
        $sq->execute($ylParams);
        $students = $sq->fetchAll();

        // Existing marks for selected unit
        if ($selectedUnit && $students) {
            $sids = array_column($students, 'student_id');
            $ph   = implode(',', array_fill(0, count($sids), '?'));
            $mq   = $db->prepare("
                SELECT student_id, ca_score, exam_score, total, grade, remarks
                FROM exam_results
                WHERE session_id=? AND unit_id=? AND student_id IN ($ph)
            ");
            $mq->execute(array_merge([$selectedSession, $selectedUnit], $sids));
            foreach ($mq->fetchAll() as $m) $existingMarks[$m['student_id']] = $m;
        }
    }
}

// PHP helper for grade background style
function gbg(string $g): string {
    return match($g) {
        'A' => 'background:rgba(22,163,74,.12);color:#15803d',
        'B' => 'background:rgba(37,99,235,.12);color:#1d4ed8',
        'C' => 'background:rgba(217,119,6,.12);color:#b45309',
        'D' => 'background:rgba(234,88,12,.12);color:#c2410c',
        default => 'background:rgba(220,38,38,.1);color:#dc2626',
    };
}

include __DIR__ . '/partials/header.php';
?>

<style>
.marks-filter-bar {
    display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;
    margin-bottom:20px; padding:16px 20px;
    background:var(--surface); border:1px solid var(--border); border-radius:12px;
}
.marks-filter-bar .fg { display:flex; flex-direction:column; gap:5px; flex:1; min-width:180px; }
.marks-filter-bar label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted); }
.marks-filter-bar select { padding:9px 12px; border:1px solid var(--border); border-radius:8px;
    background:var(--input-bg,var(--surface)); color:var(--text-primary); font-family:inherit; font-size:13px; }

.marks-wrap { overflow-x:auto; }
.marks-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.marks-tbl th {
    padding:10px 14px; text-align:left; font-weight:600; font-size:11px;
    text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted);
    border-bottom:2px solid var(--border); white-space:nowrap;
    position:sticky; top:0; background:var(--surface); z-index:2;
}
.marks-tbl td { padding:8px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.marks-tbl tr:hover td { background:var(--table-hover); }
.marks-tbl tr:last-child td { border-bottom:none; }
.marks-tbl th:nth-child(1), .marks-tbl td:nth-child(1) {
    position:sticky; left:0; background:var(--surface); z-index:1; min-width:190px;
}
.marks-tbl tr:hover td:nth-child(1) { background:var(--table-hover); }
.marks-tbl th:nth-child(2), .marks-tbl td:nth-child(2) {
    position:sticky; left:190px; background:var(--surface); z-index:1; min-width:105px;
}
.marks-tbl tr:hover td:nth-child(2) { background:var(--table-hover); }

.sc-in {
    width:72px; padding:6px 8px; border:1.5px solid var(--border); border-radius:7px;
    background:var(--input-bg,var(--surface)); color:var(--text-primary);
    font-family:'Space Mono',monospace; font-size:13px; text-align:center;
    transition:border-color .15s, box-shadow .15s; display:block; margin:0 auto;
}
.sc-in:focus { outline:none; border-color:#059669; box-shadow:0 0 0 3px rgba(5,150,105,.15); }
.sc-in.saving  { border-color:#d97706; }
.sc-in.saved   { border-color:#16a34a; animation:pulse-green .4s ease; }
.sc-in.err-val { border-color:#dc2626; }
@keyframes pulse-green { 0%{box-shadow:0 0 0 0 rgba(22,163,74,.4)} 70%{box-shadow:0 0 0 6px rgba(22,163,74,0)} 100%{box-shadow:none} }

.live-tot {
    font-family:'Space Mono',monospace; font-size:13px; font-weight:700;
    padding:4px 12px; border-radius:8px; min-width:52px; text-align:center;
    display:inline-block; transition:background .3s,color .3s;
}
.si { font-size:11px; display:block; text-align:center; min-height:16px;
      transition:opacity .3s; }
.si-ok   { color:#16a34a; } .si-err { color:#dc2626; } .si-busy { color:#d97706; }

.rem-in {
    width:100%; padding:5px 8px; border:1px solid var(--border); border-radius:6px;
    background:var(--input-bg,var(--surface)); color:var(--text-primary);
    font-family:inherit; font-size:12px;
}
.rem-in:focus { outline:none; border-color:#059669; box-shadow:0 0 0 2px rgba(5,150,105,.12); }

.unit-card {
    border:1px solid var(--border); border-radius:10px; padding:14px 16px;
    cursor:pointer; text-decoration:none; display:block;
    transition:border-color .15s, box-shadow .15s;
}
.unit-card:hover { border-color:#059669; box-shadow:0 2px 12px rgba(5,150,105,.15); text-decoration:none; }
.pbar-w { height:6px; background:var(--border); border-radius:3px; overflow:hidden; margin-top:6px; }
.pbar-f { height:100%; background:#059669; border-radius:3px; transition:width .5s ease; }

.lock-over {
    position:absolute; inset:0; background:rgba(0,0,0,.42); z-index:10;
    display:flex; align-items:center; justify-content:center;
    border-radius:12px; backdrop-filter:blur(2px);
}
.lock-box { background:var(--surface); border-radius:12px; padding:28px 36px; text-align:center; box-shadow:0 8px 40px rgba(0,0,0,.3); }
</style>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">✏️ Enter Marks</h1>
        <p class="page-subtitle">Marks save automatically as you type</p>
    </div>
    <div style="display:flex;gap:8px">
        <a href="<?= APP_ROOT ?>/exams/sessions.php" class="btn btn-ghost">🗓 Sessions</a>
        <?php if ($selectedSession): ?>
        <a href="<?= APP_ROOT ?>/exams/results.php?session_id=<?= $selectedSession ?>" class="btn btn-ghost">📋 Results</a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter bar -->
<form method="GET" id="ff">
<div class="marks-filter-bar">
    <div class="fg" style="flex:2">
        <label>Exam Session</label>
        <select name="session_id" onchange="document.getElementById('ff').submit()">
            <option value="">— Choose session —</option>
            <?php foreach ($allSessions as $s): ?>
            <option value="<?= $s['session_id'] ?>" <?= $s['session_id']==$selectedSession?'selected':'' ?>>
                <?= htmlspecialchars($s['name']) ?><?= $s['is_locked']?' [LOCKED]':'' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($selectedSession && $units): ?>
    <div class="fg" style="flex:2">
        <label>Unit / Subject</label>
        <select name="unit_id" onchange="document.getElementById('ff').submit()">
            <option value="">— Choose unit —</option>
            <?php foreach ($units as $u): ?>
            <option value="<?= $u['unit_id'] ?>" <?= $u['unit_id']==$selectedUnit?'selected':'' ?>>
                [<?= htmlspecialchars($u['unit_code']) ?>] <?= htmlspecialchars($u['unit_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if ($selectedSession): ?>
    <div style="align-self:flex-end">
        <a href="<?= APP_ROOT ?>/exams/enter_marks.php" class="btn btn-ghost btn-sm">↺ Reset</a>
    </div>
    <?php endif; ?>
</div>
</form>

<?php if (!$selectedSession): ?>
<!-- No session -->
<div class="card">
    <div class="card-body" style="padding:60px;text-align:center;color:var(--text-muted)">
        <div style="font-size:48px;margin-bottom:16px">✏️</div>
        <h3 style="margin-bottom:8px;color:var(--text-primary)">Select an Exam Session</h3>
        <p>Choose a session from the dropdown above to begin entering marks.</p>
        <?php if (empty($allSessions)): ?>
        <a href="<?= APP_ROOT ?>/exams/sessions.php" class="btn btn-primary" style="margin-top:16px">Create First Session →</a>
        <?php endif; ?>
    </div>
</div>

<?php elseif (!$students): ?>
<div class="alert alert-error">
    No students found for <strong><?= htmlspecialchars($sessionData['name'] ?? '') ?></strong>.
    Make sure students are imported in the Fees module with a matching programme before entering marks.
    <a href="<?= APP_ROOT ?>/fees/import.php" style="margin-left:8px">Import Students →</a>
</div>

<?php elseif (!$selectedUnit): ?>
<!-- Unit picker -->
<div class="card">
    <div class="card-header">
        <span class="card-title">
            📚 <?= htmlspecialchars($sessionData['name']) ?>
            <?php if ($sessionData['is_locked']): ?><span class="locked-badge" style="margin-left:10px">🔒 Locked</span><?php endif; ?>
        </span>
    </div>
    <div class="card-body">
        <p style="color:var(--text-muted);margin-bottom:20px">
            <?= count($students) ?> students · <?= count($units) ?> units available. Pick a unit to enter marks.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px">
        <?php foreach ($units as $u):
            $cnt = $db->prepare("SELECT COUNT(*) FROM exam_results WHERE session_id=? AND unit_id=?");
            $cnt->execute([$selectedSession, $u['unit_id']]);
            $filled = intval($cnt->fetchColumn());
            $pct = count($students) > 0 ? round(($filled / count($students)) * 100) : 0;
        ?>
        <a href="?session_id=<?= $selectedSession ?>&unit_id=<?= $u['unit_id'] ?>" class="unit-card">
            <div style="font-family:'Space Mono',monospace;font-size:11px;color:#059669;margin-bottom:3px"><?= htmlspecialchars($u['unit_code']) ?></div>
            <div style="font-weight:600;font-size:13px;color:var(--text-primary);margin-bottom:8px"><?= htmlspecialchars($u['unit_name']) ?></div>
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted)">
                <span><?= $filled ?>/<?= count($students) ?> entered</span>
                <span style="font-weight:700;color:<?= $pct==100?'#16a34a':'var(--text-muted)' ?>"><?= $pct ?>%</span>
            </div>
            <div class="pbar-w"><div class="pbar-f" style="width:<?= $pct ?>%"></div></div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<?php else:
// ── MAIN: Mark entry grid ──
$currentUnit = null;
foreach ($units as $u) { if ($u['unit_id'] == $selectedUnit) { $currentUnit = $u; break; } }
$filledCount = count($existingMarks);
$totalCount  = count($students);
$pct = $totalCount > 0 ? round(($filledCount / $totalCount) * 100) : 0;
?>

<!-- Session / unit info banner -->
<div style="background:rgba(5,150,105,.07);border:1px solid rgba(5,150,105,.2);border-radius:10px;
     padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
        <div style="font-weight:700;font-size:14px;color:var(--text-primary)">
            <?= htmlspecialchars($sessionData['name']) ?>
            <?php if ($sessionData['is_locked']): ?><span class="locked-badge" style="margin-left:8px">🔒 Locked</span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
            [<?= htmlspecialchars($currentUnit['unit_code'] ?? '') ?>] <?= htmlspecialchars($currentUnit['unit_name'] ?? '') ?>
            &nbsp;·&nbsp; <?= $filledCount ?>/<?= $totalCount ?> students
            &nbsp;·&nbsp; CA /30 &nbsp; Exam /70 &nbsp; Total /100
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
        <div>
            <div class="pbar-w" style="width:130px"><div class="pbar-f" style="width:<?= $pct ?>%"></div></div>
            <div style="font-size:11px;color:var(--text-muted);text-align:right;margin-top:2px"><?= $pct ?>% complete</div>
        </div>
        <div id="gss" style="font-size:12px;color:var(--text-muted);min-width:90px;text-align:right"></div>
    </div>
</div>

<?php if (!$sessionData['is_locked']): ?>
<div style="background:rgba(5,150,105,.06);border:1px solid rgba(5,150,105,.18);border-radius:9px;
     padding:10px 14px;font-size:12px;color:var(--text-muted);margin-bottom:14px">
    💡 <strong>Auto-save is ON</strong> — marks save instantly as you type.
    Use <kbd style="background:var(--border);padding:1px 5px;border-radius:4px;font-size:11px">Tab</kbd> or
    <kbd style="background:var(--border);padding:1px 5px;border-radius:4px;font-size:11px">Enter</kbd> to move between cells.
    &nbsp;|&nbsp; CA: 0–30 &nbsp; Exam: 0–70
</div>
<?php endif; ?>

<!-- Marks table -->
<div class="card" style="position:relative">

    <?php if ($sessionData['is_locked']): ?>
    <div class="lock-over">
        <div class="lock-box">
            <div style="font-size:40px;margin-bottom:10px">🔒</div>
            <h3 style="margin-bottom:8px">Session Locked</h3>
            <p style="color:var(--text-muted);font-size:13px;margin-bottom:<?= isSuperAdmin()?'16px':'0' ?>">
                This session is locked. Contact an administrator to unlock it.
            </p>
            <?php if (isSuperAdmin()): ?>
            <form method="POST" action="<?= APP_ROOT ?>/exams/sessions.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="toggle_lock">
                <input type="hidden" name="session_id" value="<?= $selectedSession ?>">
                <input type="hidden" name="lock" value="0">
                <button type="submit" class="btn btn-primary">🔓 Unlock Session</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="marks-wrap">
        <table class="marks-tbl" id="marks-tbl">
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Adm No</th>
                    <th style="text-align:center">CA<br><small style="font-weight:400;text-transform:none">/30</small></th>
                    <th style="text-align:center">Exam<br><small style="font-weight:400;text-transform:none">/70</small></th>
                    <th style="text-align:center">Total<br><small style="font-weight:400;text-transform:none">/100</small></th>
                    <th style="text-align:center">Grade</th>
                    <th>Remarks</th>
                    <th style="text-align:center">Save</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $i => $stu):
                $m    = $existingMarks[$stu['student_id']] ?? null;
                $ca   = $m ? $m['ca_score']   : '';
                $ex   = $m ? $m['exam_score'] : '';
                $tot  = $m ? $m['total']      : '';
                $grd  = $m ? $m['grade']      : '';
                $rem  = $m ? $m['remarks']    : '';
                $has  = ($ca !== '' && $ca !== null) || ($ex !== '' && $ex !== null);
            ?>
            <tr data-student="<?= htmlspecialchars($stu['student_id']) ?>"
                data-session="<?= $selectedSession ?>" data-unit="<?= $selectedUnit ?>">
                <td>
                    <div style="font-weight:600"><?= htmlspecialchars($stu['full_name']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($stu['group_name'] ?? '') ?></div>
                </td>
                <td><code style="font-size:11px"><?= htmlspecialchars($stu['student_id']) ?></code></td>
                <td style="text-align:center">
                    <input type="number" class="sc-in ca-in" data-field="ca_score"
                           min="0" max="30" step="0.5"
                           value="<?= $ca !== '' && $ca !== null ? htmlspecialchars((string)$ca) : '' ?>"
                           placeholder="—" <?= $sessionData['is_locked']?'disabled':'' ?>>
                </td>
                <td style="text-align:center">
                    <input type="number" class="sc-in ex-in" data-field="exam_score"
                           min="0" max="70" step="0.5"
                           value="<?= $ex !== '' && $ex !== null ? htmlspecialchars((string)$ex) : '' ?>"
                           placeholder="—" <?= $sessionData['is_locked']?'disabled':'' ?>>
                </td>
                <td style="text-align:center">
                    <span class="live-tot" id="tot-<?= $i ?>"
                          style="<?= ($tot!=='' && $tot!==null) ? gbg($grd) : 'color:var(--text-muted)' ?>">
                        <?= ($tot!=='' && $tot!==null) ? number_format((float)$tot,1) : '—' ?>
                    </span>
                </td>
                <td style="text-align:center">
                    <span class="grade-pill <?= $grd ? 'grade-'.$grd : '' ?>" id="grd-<?= $i ?>">
                        <?= $grd ?: '—' ?>
                    </span>
                </td>
                <td>
                    <input type="text" class="rem-in" data-field="remarks"
                           placeholder="Optional…" value="<?= htmlspecialchars((string)$rem) ?>"
                           maxlength="200" <?= $sessionData['is_locked']?'disabled':'' ?>>
                </td>
                <td style="text-align:center">
                    <span class="si <?= $has ? 'si-ok' : '' ?>" id="si-<?= $i ?>"><?= $has ? '✓' : '○' ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
(function(){
    const API  = '<?= APP_ROOT ?>/exams/api/save_mark.php';
    const CSRF = '<?= csrfToken() ?>';
    let timers = {};

    function gradeStyle(g) {
        return {A:{bg:'rgba(22,163,74,.12)',c:'#15803d'},B:{bg:'rgba(37,99,235,.12)',c:'#1d4ed8'},
                C:{bg:'rgba(217,119,6,.12)', c:'#b45309'},D:{bg:'rgba(234,88,12,.12)',c:'#c2410c'},
                F:{bg:'rgba(220,38,38,.1)',  c:'#dc2626'}}[g] || {bg:'var(--border)',c:'var(--text-muted)'};
    }
    function computeGrade(t) {
        if(t===null) return '—';
        return t>=70?'A':t>=60?'B':t>=50?'C':t>=40?'D':'F';
    }
    function rowIdx(el) {
        return [...document.querySelectorAll('#marks-tbl tbody tr')].indexOf(el.closest('tr'));
    }
    function si(i, cls, txt) {
        const el = document.getElementById('si-'+i);
        if(!el) return;
        el.className = 'si ' + cls;
        el.textContent = txt;
    }
    function gss(txt, col) {
        const el = document.getElementById('gss');
        if(el) { el.textContent = txt; el.style.color = col; }
    }
    function updateLiveTotal(row, i) {
        const ca  = row.querySelector('.ca-in');
        const ex  = row.querySelector('.ex-in');
        const tEl = document.getElementById('tot-'+i);
        const gEl = document.getElementById('grd-'+i);
        if(!tEl||!gEl) return;
        const cv = ca.value!=='' ? parseFloat(ca.value) : null;
        const ev = ex.value!=='' ? parseFloat(ex.value) : null;
        if(cv===null && ev===null) {
            tEl.textContent='—'; tEl.style.background=''; tEl.style.color='var(--text-muted)';
            gEl.textContent='—'; gEl.className='grade-pill'; return;
        }
        const tot = (cv||0)+(ev||0);
        const g   = computeGrade(tot);
        const s   = gradeStyle(g);
        tEl.textContent=tot.toFixed(1); tEl.style.background=s.bg; tEl.style.color=s.c;
        gEl.textContent=g; gEl.className='grade-pill grade-'+g;
    }
    function save(input) {
        const row = input.closest('tr');
        const i   = rowIdx(input);
        const f   = input.dataset.field;
        const v   = input.value;
        // Local range check
        if(f==='ca_score' && v!=='') { const n=parseFloat(v); if(n<0||n>30){input.classList.add('err-val'); si(i,'si-err','✗ 0–30'); return;} }
        if(f==='exam_score' && v!=='') { const n=parseFloat(v); if(n<0||n>70){input.classList.add('err-val'); si(i,'si-err','✗ 0–70'); return;} }
        input.classList.remove('err-val');
        input.classList.add('saving');
        si(i,'si-busy','⏳');
        gss('Saving…','#d97706');
        const body = new URLSearchParams({
            session_id:row.dataset.session, unit_id:row.dataset.unit,
            student_id:row.dataset.student, field:f, value:v, csrf_token:CSRF
        });
        fetch(API,{method:'POST',body})
        .then(r=>r.json())
        .then(d=>{
            input.classList.remove('saving');
            if(d.ok) {
                input.classList.add('saved');
                setTimeout(()=>input.classList.remove('saved'),2000);
                si(i,'si-ok','✓');
                gss('All saved ✓','#16a34a');
                // Update total & grade from server
                const tEl=document.getElementById('tot-'+i);
                const gEl=document.getElementById('grd-'+i);
                if(tEl && d.total!==null && d.total!==undefined) {
                    const s=gradeStyle(d.grade);
                    tEl.textContent=parseFloat(d.total).toFixed(1);
                    tEl.style.background=s.bg; tEl.style.color=s.c;
                    gEl.textContent=d.grade; gEl.className='grade-pill grade-'+d.grade;
                }
            } else {
                input.classList.add('err-val');
                si(i,'si-err','✗');
                gss('Save error','#dc2626');
                if(d.error) { const b=document.createElement('div'); b.className='alert alert-error';
                    b.innerHTML=d.error+'<button onclick="this.parentElement.remove()" class="alert-close">×</button>';
                    document.querySelector('.main-content').prepend(b); }
            }
        })
        .catch(()=>{ input.classList.remove('saving'); input.classList.add('err-val');
            si(i,'si-err','✗ Net'); gss('Network error','#dc2626'); });
    }
    // Score inputs
    document.querySelectorAll('.sc-in').forEach(inp=>{
        const row=inp.closest('tr');
        const getIdx=()=>rowIdx(inp);
        inp.addEventListener('input',()=>{ updateLiveTotal(row,getIdx());
            const k=row.dataset.student+'_'+inp.dataset.field;
            clearTimeout(timers[k]); timers[k]=setTimeout(()=>save(inp),400);
        });
        inp.addEventListener('focus',()=>{ inp.dataset.last=inp.value; });
        inp.addEventListener('blur',()=>{ const k=row.dataset.student+'_'+inp.dataset.field;
            clearTimeout(timers[k]); if(inp.value!==inp.dataset.last) save(inp); });
        inp.addEventListener('keydown',e=>{
            if(e.key==='Enter'||e.key==='Tab'){
                const all=[...document.querySelectorAll('.sc-in:not([disabled])')];
                const idx=all.indexOf(inp);
                if(e.key==='Enter'){ e.preventDefault(); if(idx<all.length-1) all[idx+1].focus(); }
            }
        });
    });
    // Remarks inputs (debounced 800ms)
    document.querySelectorAll('.rem-in').forEach(inp=>{
        inp.addEventListener('input',()=>{
            const row=inp.closest('tr');
            const k=row.dataset.student+'_remarks';
            clearTimeout(timers[k]);
            timers[k]=setTimeout(()=>save(inp),800);
        });
    });
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
