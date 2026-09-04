<?php
// ============================================================
// admissions/dashboard.php  — ADMIN (requires login)
// Admissions officer view of all submitted applications
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Admissions Dashboard';
$activePage = 'adm_dashboard';
$db = getDB();

// ── Filters ──
$statusFilter  = trim($_GET['status']  ?? '');
$progFilter    = trim($_GET['prog']    ?? '');
$search        = trim($_GET['q']       ?? '');

$where  = ['1=1'];
$params = [];
if ($statusFilter) { $where[] = 'a.status=?';         $params[] = $statusFilter; }
if ($progFilter)   { $where[] = 'a.programme_type=?'; $params[] = $progFilter; }
if ($search)       { $where[] = '(CONCAT(a.surname," ",a.first_name) LIKE ? OR a.reference_no LIKE ? OR a.mobile_no LIKE ?)';
                     $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSQL = implode(' AND ', $where);

// ── Stats ──
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='pending')     AS pending,
        SUM(status='shortlisted') AS shortlisted,
        SUM(status='admitted')    AS admitted,
        SUM(status='rejected')    AS rejected
    FROM admissions
")->fetch();

// ── Applications list ──
$apps = $db->prepare("
    SELECT a.*,
           COUNT(d.doc_id) AS doc_count
    FROM admissions a
    LEFT JOIN admission_documents d ON d.admission_id = a.admission_id
    WHERE $whereSQL
    GROUP BY a.admission_id
    ORDER BY a.submitted_at DESC
");
$apps->execute($params);
$apps = $apps->fetchAll();

$admin = getCurrentAdmin();
include __DIR__ . '/partials/adm_header.php';
?>

<style>
.status-pill{display:inline-block;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.sp-pending    {background:rgba(217,119,6,.1);  color:#b45309}
.sp-shortlisted{background:rgba(37,99,235,.1);  color:#1d4ed8}
.sp-admitted   {background:rgba(22,163,74,.1);  color:#15803d}
.sp-rejected   {background:rgba(220,38,38,.1);  color:#dc2626}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.filter-bar input,.filter-bar select{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px}
.filter-bar input{flex:1;min-width:200px}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">🎓 Admissions Dashboard</h1>
        <p class="page-subtitle">KIMC Eldoret Campus — Application Review</p>
    </div>
    <div style="display:flex;gap:8px">
        <a href="<?= APP_ROOT ?>/admissions/apply.php" target="_blank" class="btn btn-ghost">👁 Preview Public Form</a>
    </div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px">
    <div class="stat-card">
        <div class="stat-label">Total Applications</div>
        <div class="stat-value"><?= $stats['total'] ?></div>
    </div>
    <div class="stat-card" style="cursor:pointer" onclick="applyF('status','pending')">
        <div class="stat-label">Pending Review</div>
        <div class="stat-value" style="color:#b45309"><?= $stats['pending'] ?></div>
    </div>
    <div class="stat-card" style="cursor:pointer" onclick="applyF('status','shortlisted')">
        <div class="stat-label">Shortlisted</div>
        <div class="stat-value" style="color:#1d4ed8"><?= $stats['shortlisted'] ?></div>
    </div>
    <div class="stat-card" style="cursor:pointer" onclick="applyF('status','admitted')">
        <div class="stat-label">Admitted</div>
        <div class="stat-value" style="color:#15803d"><?= $stats['admitted'] ?></div>
    </div>
    <div class="stat-card" style="cursor:pointer" onclick="applyF('status','rejected')">
        <div class="stat-label">Not Admitted</div>
        <div class="stat-value" style="color:#dc2626"><?= $stats['rejected'] ?></div>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar">
    <input type="text" id="sq" placeholder="🔍 Search name, ref no, mobile…"
           value="<?= htmlspecialchars($search) ?>" oninput="lsearch(this.value)">
    <select onchange="applyF('status',this.value)">
        <option value="">All Statuses</option>
        <?php foreach(['pending','shortlisted','admitted','rejected'] as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
    </select>
    <select onchange="applyF('prog',this.value)">
        <option value="">All Programmes</option>
        <?php foreach(['certificate','diploma','postgraduate'] as $p): ?>
        <option value="<?= $p ?>" <?= $progFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($statusFilter||$progFilter||$search): ?>
    <a href="<?= APP_ROOT ?>/admissions/dashboard.php" class="btn btn-ghost btn-sm">↺ Clear</a>
    <?php endif; ?>
</div>

<!-- Applications table -->
<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (empty($apps)): ?>
        <div style="padding:60px;text-align:center;color:var(--text-muted)">
            <div style="font-size:40px;margin-bottom:12px">📭</div>
            <p>No applications found<?= $search||$statusFilter||$progFilter ? ' matching your filters' : ' yet' ?>.</p>
        </div>
        <?php else: ?>
        <table class="data-table" id="apps-tbl">
            <thead><tr>
                <th>Ref No</th>
                <th>Full Name</th>
                <th>Mobile</th>
                <th>Programme</th>
                <th>Type</th>
                <th>Docs</th>
                <th>Submitted</th>
                <th>Status</th>
                <th></th>
            </tr></thead>
            <tbody id="apps-body">
            <?php foreach ($apps as $a): ?>
            <tr data-search="<?= htmlspecialchars(strtolower($a['surname'].' '.$a['first_name'].' '.$a['reference_no'].' '.$a['mobile_no'])) ?>">
                <td><code style="font-size:11px;font-weight:700"><?= htmlspecialchars($a['reference_no']) ?></code></td>
                <td style="font-weight:600"><?= htmlspecialchars($a['surname'].', '.$a['first_name'].($a['middle_name']?' '.$a['middle_name']:'')) ?></td>
                <td style="font-family:'Space Mono',monospace;font-size:12px"><?= htmlspecialchars($a['mobile_no']) ?></td>
                <td style="font-size:12px;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                    title="<?= htmlspecialchars($a['programme_name']) ?>"><?= htmlspecialchars($a['programme_name']) ?></td>
                <td><span style="font-size:11px;text-transform:capitalize"><?= $a['programme_type'] ?></span></td>
                <td>
                    <span style="font-family:'Space Mono',monospace;font-size:12px;
                        color:<?= $a['doc_count']>=4?'#15803d':'#b45309' ?>">
                        <?= $a['doc_count'] ?> file<?= $a['doc_count']!==1?'s':'' ?>
                    </span>
                </td>
                <td style="font-size:12px;color:var(--text-muted)"><?= date('d M Y', strtotime($a['submitted_at'])) ?></td>
                <td><span class="status-pill sp-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
                <td>
                    <a href="<?= APP_ROOT ?>/admissions/application.php?id=<?= $a['admission_id'] ?>"
                       class="btn btn-primary btn-sm">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="padding:10px 16px;font-size:12px;color:var(--text-muted);border-top:1px solid var(--border)">
            Showing <strong id="visible-count"><?= count($apps) ?></strong> of <?= count($apps) ?> applications
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function applyF(k,v){const u=new URL(window.location.href);v?u.searchParams.set(k,v):u.searchParams.delete(k);window.location.href=u.toString();}
function lsearch(q){
    q=q.toLowerCase();
    let vis=0;
    document.querySelectorAll('#apps-body tr').forEach(r=>{
        const s=r.dataset.search||'';
        const show=!q||s.includes(q);
        r.style.display=show?'':'none';
        if(show)vis++;
    });
    const vc=document.getElementById('visible-count');
    if(vc)vc.textContent=vis;
}
const sq=document.getElementById('sq');
if(sq&&sq.value)lsearch(sq.value);
</script>

<?php include __DIR__ . '/partials/adm_footer.php'; ?>
