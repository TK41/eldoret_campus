<?php
// ============================================================
// admin/manage_admins.php  — Users & Role Management
// Standalone page. Superadmin only.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/rbac.php';
requireLogin();
requireDo('system.manage_users');

$db = getDB();

// Add last_seen column silently if missing
try { $db->exec("ALTER TABLE admin_users ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL"); } catch (PDOException $e) {}

// Heartbeat — keep current user marked online
$db->prepare("UPDATE admin_users SET last_seen=UTC_TIMESTAMP() WHERE admin_id=?")->execute([$_SESSION['admin_id']]);
define('ONLINE_SECS', 5 * 60); // 5 min threshold

// XHR endpoints for realtime presence
if (isset($_GET['xhr'])) {
  header('Content-Type: application/json');
  if ($_GET['xhr'] === 'heartbeat') {
    $db->prepare("UPDATE admin_users SET last_seen=UTC_TIMESTAMP() WHERE admin_id=?")->execute([$_SESSION['admin_id']]);
    echo json_encode(['ok' => 1]);
    exit;
  }
  if ($_GET['xhr'] === 'list') {
    $rows = $db->query("SELECT admin_id,first_name,last_name,full_name,username,email,last_seen,is_active,role_id,IF(last_seen >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE,1,0) AS online FROM admin_users ORDER BY role_id ASC, full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach($rows as $r){
      $last_seen_eat = null;
      if ($r['last_seen']){
        try{
          $dt = new DateTime($r['last_seen'], new DateTimeZone('UTC'));
          $dt->setTimeZone(new DateTimeZone('Africa/Nairobi'));
          $last_seen_eat = $dt->format('d M Y, g:i a');
        } catch (Exception $e) { $last_seen_eat = null; }
      }
      $out[] = [
        'admin_id' => $r['admin_id'],
        'first_name' => $r['first_name'],
        'last_name' => $r['last_name'],
        'full_name' => $r['full_name'],
        'username' => $r['username'],
        'email' => $r['email'],
        'last_seen' => $r['last_seen'],
        'last_seen_eat' => $last_seen_eat,
        'is_active' => $r['is_active'],
        'role_id' => $r['role_id'],
        'online' => (bool)$r['online'],
      ];
    }
    echo json_encode(['ok' => 1, 'rows' => $out, 'now' => time()]);
    exit;
  }
}

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($_POST['action'] === 'create_user') {
        $fn=$_POST['first_name']??''; $ln=$_POST['last_name']??'';
        $email=strtolower(trim($_POST['email']??'')); $uname=strtolower(trim($_POST['username']??''));
        $pass=$_POST['password']??''; $roleId=intval($_POST['role_id']??0);
        $errs=[];
        if(!trim($fn)||!trim($ln)) $errs[]='Full name required.';
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $errs[]='Valid email required.';
        if(strlen($uname)<3) $errs[]='Username min 3 chars.';
        if(strlen($pass)<8)  $errs[]='Password min 8 chars.';
        if(!$roleId)         $errs[]='Please select a role.';
        if(empty($errs)){
            $t=$db->prepare("SELECT admin_id FROM admin_users WHERE username=? OR email=?");
            $t->execute([$uname,$email]);
            if($t->fetch()) $errs[]='Username or email already registered.';
        }
        if(empty($errs)){
            $rq=$db->prepare("SELECT name FROM roles WHERE role_id=?"); $rq->execute([$roleId]);
            $rn=$rq->fetchColumn()?:'staff';
            $es=match($rn){'superadmin'=>'superadmin','accountant'=>'accountant','admissions_officer'=>'admissions_officer','lecturer'=>'lecturer','inventory_staff'=>'inventory_staff',default=>'staff'};
            $db->prepare("INSERT INTO admin_users(first_name,last_name,full_name,email,username,password_hash,role,role_id) VALUES(?,?,?,?,?,?,?,?)")
               ->execute([trim($fn),trim($ln),trim("$fn $ln"),$email,$uname,password_hash($pass,PASSWORD_BCRYPT),$es,$roleId]);
            setFlash('success',"User ".trim($fn)." ".trim($ln)." created.");
        } else { setFlash('error',implode(' ',$errs)); }
        header('Location: '.APP_ROOT.'/admin/manage_admins.php'); exit;
    }

    if ($_POST['action'] === 'update_role') {
        $uid=intval($_POST['admin_id']); $roleId=intval($_POST['role_id']);
        $sa=$db->query("SELECT COUNT(*) FROM admin_users WHERE role='superadmin' AND is_active=1")->fetchColumn();
        $cr=$db->prepare("SELECT role FROM admin_users WHERE admin_id=?"); $cr->execute([$uid]);
        if($cr->fetchColumn()==='superadmin' && $sa<=1 && $roleId!=1){setFlash('error','Cannot remove last System Admin.');header('Location: '.APP_ROOT.'/admin/manage_admins.php');exit;}
        $rq=$db->prepare("SELECT name FROM roles WHERE role_id=?"); $rq->execute([$roleId]);
        $rn=$rq->fetchColumn()?:'staff';
        $es=match($rn){'superadmin'=>'superadmin','accountant'=>'accountant','admissions_officer'=>'admissions_officer','lecturer'=>'lecturer','inventory_staff'=>'inventory_staff',default=>'staff'};
        $db->prepare("UPDATE admin_users SET role_id=?,role=? WHERE admin_id=?")->execute([$roleId,$es,$uid]);
        setFlash('success','Role updated. Takes effect on next login.');
        header('Location: '.APP_ROOT.'/admin/manage_admins.php'); exit;
    }

    if ($_POST['action'] === 'toggle_active') {
        $uid=intval($_POST['admin_id']);
        if($uid===intval($_SESSION['admin_id'])){setFlash('error','Cannot deactivate yourself.');}
        else{$db->prepare("UPDATE admin_users SET is_active=NOT is_active WHERE admin_id=?")->execute([$uid]);setFlash('success','Account status updated.');}
        header('Location: '.APP_ROOT.'/admin/manage_admins.php'); exit;
    }

    if ($_POST['action'] === 'reset_password') {
        $uid=intval($_POST['admin_id']); $p=trim($_POST['new_password']??'');
        if(strlen($p)<8){setFlash('error','Password min 8 chars.');}
        else{$db->prepare("UPDATE admin_users SET password_hash=? WHERE admin_id=?")->execute([password_hash($p,PASSWORD_BCRYPT),$uid]);setFlash('success','Password reset.');}
        header('Location: '.APP_ROOT.'/admin/manage_admins.php'); exit;
    }

    if ($_POST['action'] === 'delete_user') {
        $uid=intval($_POST['admin_id']);
        if($uid===intval($_SESSION['admin_id'])){setFlash('error','Cannot delete yourself.');}
        else{$db->prepare("DELETE FROM admin_users WHERE admin_id=?")->execute([$uid]);setFlash('success','User deleted.');}
        header('Location: '.APP_ROOT.'/admin/manage_admins.php'); exit;
    }
}

// ── Data ─────────────────────────────────────────────────────
$admins=$db->query("SELECT au.*,r.label AS role_label,r.name AS role_name,IF(au.last_seen >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE,1,0) AS online FROM admin_users au LEFT JOIN roles r ON r.role_id=au.role_id ORDER BY au.role_id ASC,au.full_name ASC")->fetchAll();
$roles=$db->query("SELECT * FROM roles ORDER BY role_id")->fetchAll();
$allActions=$db->query("SELECT * FROM module_actions ORDER BY module,sort_order")->fetchAll();
$rolePerms=$db->query("SELECT rp.role_id,ma.module,ma.action FROM role_permissions rp JOIN module_actions ma ON ma.action_id=rp.action_id")->fetchAll();
$permIndex=[];
foreach($rolePerms as $rp) $permIndex[$rp['role_id']][$rp['module']][$rp['action']]=true;
$mc=['inventory'=>'#1a3a6b','fees'=>'#b8760a','exams'=>'#065f46','admissions'=>'#3730a3','system'=>'#dc2626'];
$total=count($admins);
$onlineCnt=0;
foreach($admins as $u) {
    if ($u['last_seen']) {
        try {
            $lastSeenUtc = (new DateTime($u['last_seen'], new DateTimeZone('UTC')))->getTimestamp();
            if ((time() - $lastSeenUtc) <= ONLINE_SECS) $onlineCnt++;
        } catch (Exception $e) {}
    }
}
$admin=getCurrentAdmin(); $flash=getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Users &amp; Roles — KIMC</title>

<script>
    (function() {
        try {
            const savedTheme = localStorage.getItem('kimc_theme');
            const theme = savedTheme === 'dark' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        } catch (e) {}
    })();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">
<link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/mobile.css">
<style>
.topbar{background:#1e1b4b!important;border-bottom:1px solid rgba(79,70,229,.25)!important}
/* Online pip */
.pip{width:9px;height:9px;border-radius:50%;display:inline-block;vertical-align:middle;margin-right:3px;flex-shrink:0}
.pip.on{background:#22c55e;box-shadow:0 0 0 2px rgba(34,197,94,.2);animation:pp 2s ease-in-out infinite}
.pip.off{background:#d1d5db}
[data-theme="dark"] .pip.off{background:#374151}
@keyframes pp{0%,100%{box-shadow:0 0 0 2px rgba(34,197,94,.2)}50%{box-shadow:0 0 0 5px rgba(34,197,94,.0)}}
/* Avatar */
.uav{width:42px;height:42px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#1a3a6b,#2a5298);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;position:relative}
.uav .sd{position:absolute;bottom:1px;right:1px;width:11px;height:11px;border-radius:50%;border:2px solid var(--surface)}
.uav .sd.on{background:#22c55e}
.uav .sd.off{background:#9ca3af}
[data-theme="dark"] .uav .sd.off{background:#4b5563}
/* User row */
.urow{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid var(--border);transition:background .12s}
.urow:last-child{border-bottom:none}
.urow:hover{background:var(--table-hover)}
.uinfo{flex:1;min-width:0}
.uname{font-weight:700;font-size:14px;color:var(--text-primary)}
.umeta{font-size:11px;color:var(--text-muted);margin-top:3px;line-height:1.7}
.ubadges{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:4px}
/* Presence pill */
.pp-on{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:#16a34a}
.pp-off{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;background:rgba(156,163,175,.08);border:1px solid rgba(156,163,175,.15);color:#9ca3af}
/* Account pill */
.ap{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.ap-on{background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.2)}
.ap-off{background:rgba(220,38,38,.08);color:#dc2626;border:1px solid rgba(220,38,38,.15)}
.ap-you{background:rgba(79,70,229,.1);color:#4f46e5;border:1px solid rgba(79,70,229,.2)}
/* Role select */
.rsel{padding:5px 10px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text-primary);font-family:inherit;font-size:12px;cursor:pointer}
.rsel:focus{outline:none;border-color:#4f46e5}
.rsel:disabled{opacity:.5;cursor:not-allowed}
/* Right actions */
.uright{display:flex;align-items:center;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}
/* Tabs */
.pgtabs{display:flex;gap:4px;background:var(--bg,#f5f5f5);border-radius:10px;padding:4px;margin-bottom:20px;border:1px solid var(--border)}
[data-theme="dark"] .pgtabs{background:rgba(255,255,255,.04)}
.pgtab{flex:1;text-align:center;padding:9px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;color:var(--text-muted);border:none;background:none;font-family:inherit;transition:all .2s}
.pgtab.active{background:var(--surface);color:var(--text-primary);box-shadow:0 1px 4px rgba(0,0,0,.1)}
.tpane{display:none}.tpane.active{display:block}
/* Stat chips */
.schips{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.sc{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}
.sc-b{background:rgba(26,58,107,.08);color:#1a3a6b}
.sc-g{background:rgba(34,197,94,.1);color:#16a34a}
.sc-gr{background:rgba(156,163,175,.1);color:#6b7280}
/* Permissions matrix */
.pm{width:100%;border-collapse:collapse;font-size:12px}
.pm th{padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);border-bottom:2px solid var(--border);white-space:nowrap}
.pm td{padding:7px 10px;border-bottom:1px solid var(--border)}
.pm tr:last-child td{border-bottom:none}
.pm tr:hover td{background:var(--table-hover)}
.pm-y{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:rgba(22,163,74,.12);color:#16a34a;font-size:13px;font-weight:700}
.pm-n{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;color:var(--border);font-size:16px}
.pm-mh{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;padding:10px 10px 5px;background:var(--bg,#f9fafb);border-bottom:1px solid var(--border)}
[data-theme="dark"] .pm-mh{background:rgba(255,255,255,.03)}
/* Modal */
.mbg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;align-items:center;justify-content:center}
.mbg.show{display:flex}
.mbox{background:var(--surface);border-radius:16px;padding:28px;width:100%;max-width:480px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto}
.mtit{font-size:17px;font-weight:700;margin-bottom:18px}
.mfg{margin-bottom:14px}
.mfg label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);display:block;margin-bottom:5px}
.mfg input,.mfg select{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px}
.mfg input:focus,.mfg select:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.1)}
.mfoot{display:flex;gap:10px;justify-content:flex-end;margin-top:20px}
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>
    <a href="<?= APP_ROOT ?>/portal.php" class="topbar-brand">
      <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC" style="height:36px;width:auto;object-fit:contain">
      <div class="brand-text">
        <span class="brand-name" style="color:#c7d2fe">KIMC Eldoret</span>
        <span class="brand-sub" style="color:rgba(255,255,255,.4)">Users &amp; Roles</span>
      </div>
    </a>
  </div>
  <div class="topbar-right">
    <button class="icon-btn theme-toggle-btn" onclick="toggleTheme()"><span id="theme-icon">🌙</span></button>
    <a href="<?= APP_ROOT ?>/portal.php" class="icon-btn" style="font-size:18px;text-decoration:none" title="Portal">🏠</a>
    <div class="user-menu" onclick="toggleUserMenu()">
      <div class="avatar" style="background:linear-gradient(135deg,#3730a3,#4f46e5)"><?= strtoupper(substr($admin['full_name'],0,1)) ?></div>
      <div class="user-info hide-mobile">
        <span class="user-name" style="color:#c7d2fe"><strong><?= htmlspecialchars($admin['first_name']?:explode(' ',$admin['full_name'])[0]) ?></strong></span>
        <span class="user-role" style="color:rgba(255,255,255,.45)"><?= htmlspecialchars($_SESSION['role_label']??'Admin') ?></span>
      </div>
      <span class="dropdown-arrow" style="color:rgba(255,255,255,.4)">▾</span>
      <div class="dropdown-menu" id="user-dropdown">
        <a href="<?= APP_ROOT ?>/portal.php" class="dropdown-item">🏠 Portal</a>
        <div class="dropdown-divider"></div>
        <a href="<?= APP_ROOT ?>/auth/logout.php" class="dropdown-item danger">🚪 Sign Out</a>
      </div>
    </div>
  </div>
</header>
<div class="layout">
<aside class="sidebar" id="sidebar" style="background:linear-gradient(180deg,#1e1b4b 0%,#312e81 100%)">
  <nav class="sidebar-nav">
    <div class="nav-section-label" style="color:rgba(255,255,255,.35)">Management</div>
    <a href="<?= APP_ROOT ?>/admin/manage_admins.php" class="nav-item" style="background:rgba(79,70,229,.3);color:#c7d2fe;border-left:3px solid #818cf8"><span class="nav-icon">👥</span> Users &amp; Roles</a>
    <div class="nav-section-label" style="color:rgba(255,255,255,.35)">Portals</div>
    <a href="<?= APP_ROOT ?>/portal.php" class="nav-item" style="color:rgba(255,255,255,.65)"><span class="nav-icon">🏠</span> Portal</a>
    <?php if(canAccess('inventory')): ?><a href="<?= APP_ROOT ?>/admin/dashboard.php" class="nav-item" style="color:rgba(255,255,255,.65)"><span class="nav-icon">📦</span> Inventory</a><?php endif; ?>
    <?php if(canAccess('fees')): ?><a href="<?= APP_ROOT ?>/fees/dashboard.php" class="nav-item" style="color:rgba(255,255,255,.65)"><span class="nav-icon">💰</span> Fees</a><?php endif; ?>
    <?php if(canAccess('exams')): ?><a href="<?= APP_ROOT ?>/exams/dashboard.php" class="nav-item" style="color:rgba(255,255,255,.65)"><span class="nav-icon">🎓</span> Exams</a><?php endif; ?>
    <?php if(canAccess('admissions')): ?><a href="<?= APP_ROOT ?>/admissions/dashboard.php" class="nav-item" style="color:rgba(255,255,255,.65)"><span class="nav-icon">📋</span> Admissions</a><?php endif; ?>
  </nav>
  <div class="sidebar-footer" style="border-top:1px solid rgba(255,255,255,.08)">
    <div style="font-size:11px;color:rgba(255,255,255,.35)">Signed in as</div>
    <div style="font-weight:600;font-size:13px;color:#c7d2fe"><?= htmlspecialchars($admin['username']) ?></div>
    <a href="<?= APP_ROOT ?>/auth/logout.php" style="color:#fca5a5;font-size:12px;text-decoration:none">Sign Out</a>
  </div>
</aside>
<main class="main-content">

<?php if($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:16px">
  <?= htmlspecialchars($flash['message']) ?>
  <button onclick="this.parentElement.remove()" class="alert-close">×</button>
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">👥 Users &amp; Roles</h1>
    <div class="schips">
      <span class="sc sc-b">👤 <?= $total ?> user<?= $total!==1?'s':'' ?></span>
      <span class="sc sc-g"><span class="pip on"></span><?= $onlineCnt ?> online</span>
      <span class="sc sc-gr"><span class="pip off"></span><?= $total-$onlineCnt ?> offline</span>
    </div>
  </div>
  <button class="btn btn-primary" onclick="openM('m-new')">➕ New User</button>
</div>

<div class="pgtabs">
  <button class="pgtab active" onclick="swTab('users',this)">👤 Users</button>
  <button class="pgtab" onclick="swTab('matrix',this)">🔑 Permissions</button>
</div>

<!-- USERS TAB -->
<div class="tpane active" id="tab-users">
  <input type="text" placeholder="🔍 Search name, username or email…" oninput="filterU(this.value)"
    style="width:100%;max-width:340px;padding:9px 14px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text-primary);font-family:inherit;font-size:13px;margin-bottom:14px">
  <div class="card"><div class="card-body" style="padding:0" id="ulist">
  <?php foreach($admins as $u):
    $on = false;
    if ($u['last_seen']) {
      try {
        $lastSeenUtc = (new DateTime($u['last_seen'], new DateTimeZone('UTC')))->getTimestamp();
        $on = (time() - $lastSeenUtc) <= ONLINE_SECS;
      } catch (Exception $e) { $on = false; }
    }
    $you=intval($u['admin_id'])===intval($_SESSION['admin_id']);
    if($u['last_seen']){
      $dt=new DateTime($u['last_seen'],new DateTimeZone('UTC'));
      $dt->setTimeZone(new DateTimeZone('Africa/Nairobi'));
      $ls=$dt->format('d M Y, g:i a');
    }else{$ls=null;}
    $ini=strtoupper(substr($u['first_name']?:$u['full_name'],0,1));
  ?>
  <div class="urow" data-admin-id="<?= $u['admin_id'] ?>" data-q="<?= htmlspecialchars(strtolower($u['full_name'].' '.$u['username'].' '.($u['email']??''))) ?>">
    <div class="uav"><?= $ini ?><span class="sd <?= $on?'on':'off' ?>"></span></div>
    <div class="uinfo">
      <div class="uname"><?= htmlspecialchars($u['full_name']) ?></div>
      <div class="umeta"><code style="font-size:11px"><?= htmlspecialchars($u['username']) ?></code><?php if($u['email']): ?> &middot; <?= htmlspecialchars($u['email']) ?><?php endif; ?></div>
      <div class="ubadges">
        <!-- Online/offline presence -->
        <span class="<?= $on?'pp-on':'pp-off' ?>">
          <span class="pip <?= $on?'on':'off' ?>"></span>
          <?= $on ? 'Online now' : ($ls ? 'Last seen '.$ls : 'Never logged in') ?>
        </span>
        <!-- Account status -->
        <?php if($you): ?>
          <span class="ap ap-you">You</span>
        <?php elseif($u['is_active']): ?>
          <span class="ap ap-on">Account active</span>
        <?php else: ?>
          <span class="ap ap-off">Account disabled</span>
        <?php endif; ?>
      </div>
    </div>
    <!-- Role -->
    <select class="rsel" onchange="updateRole(<?= $u['admin_id'] ?>,this)" <?= $you?'disabled title="Cannot change your own role"':'' ?>>
      <?php foreach($roles as $r): ?>
      <option value="<?= $r['role_id'] ?>" <?= $r['role_id']==$u['role_id']?'selected':'' ?>><?= htmlspecialchars($r['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <!-- Actions -->
    <div class="uright">
      <button class="btn btn-ghost btn-sm" onclick="openReset(<?= $u['admin_id'] ?>,'<?= htmlspecialchars(addslashes($u['full_name'])) ?>')" title="Reset password">🔑</button>
      <?php if(!$you): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="admin_id" value="<?= $u['admin_id'] ?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="color:<?= $u['is_active']?'#b45309':'#16a34a' ?>"
                title="<?= $u['is_active']?'Disable account':'Enable account' ?>"
                onclick="return confirm('<?= $u['is_active']?'Disable':'Enable' ?> <?= htmlspecialchars(addslashes($u['full_name'])) ?>?')">
          <?= $u['is_active']?'🔒 Disable':'🔓 Enable' ?>
        </button>
      </form>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="admin_id" value="<?= $u['admin_id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($u['full_name'])) ?> permanently?')">🗑</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div></div>
</div>

<!-- PERMISSIONS TAB -->
<div class="tpane" id="tab-matrix">
  <div class="card">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-muted)">
      Read-only — what each role can do. ✓ = permitted &nbsp;·&nbsp; · = not permitted
    </div>
    <div style="overflow-x:auto"><table class="pm">
      <thead><tr>
        <th style="min-width:210px">Action</th>
        <?php foreach($roles as $r): ?><th style="text-align:center;min-width:115px"><?= htmlspecialchars($r['label']) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php $cm=null; foreach($allActions as $a):
        if($a['module']!==$cm){$cm=$a['module'];$col=$mc[$cm]??'#6b7280';?>
      <tr><td colspan="<?= count($roles)+1 ?>" class="pm-mh" style="color:<?= $col ?>"><?= strtoupper($cm) ?></td></tr>
      <?php } ?>
      <tr>
        <td style="padding-left:22px;font-size:12px"><?= htmlspecialchars($a['label']) ?></td>
        <?php foreach($roles as $r): $has=$r['name']==='superadmin'||($permIndex[$r['role_id']][$a['module']][$a['action']]??false); ?>
        <td style="text-align:center"><span class="<?= $has?'pm-y':'pm-n' ?>"><?= $has?'✓':'·' ?></span></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

</main></div>

<!-- New User Modal -->
<div class="mbg" id="m-new" onclick="if(event.target===this)closeM('m-new')">
  <div class="mbox">
    <div class="mtit">➕ Create Admin User</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="create_user">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="mfg"><label>First Name *</label><input type="text" name="first_name" required placeholder="Jane"></div>
        <div class="mfg"><label>Last Name *</label><input type="text" name="last_name" required placeholder="Odhiambo"></div>
      </div>
      <div class="mfg"><label>Email *</label><input type="email" name="email" required placeholder="jane@kimc.ac.ke"></div>
      <div class="mfg"><label>Username *</label><input type="text" name="username" required placeholder="jodhiambo" autocomplete="off"></div>
      <div class="mfg"><label>Password * (min 8 chars)</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
      <div class="mfg"><label>Role *</label>
        <select name="role_id" required>
          <option value="">— Select Role —</option>
          <?php foreach($roles as $r): ?><option value="<?= $r['role_id'] ?>"><?= htmlspecialchars($r['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeM('m-new')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="mbg" id="m-reset" onclick="if(event.target===this)closeM('m-reset')">
  <div class="mbox" style="max-width:380px">
    <div class="mtit">🔑 Reset Password</div>
    <p id="rfor" style="font-size:13px;color:var(--text-muted);margin-bottom:18px"></p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="admin_id" id="ruid">
      <div class="mfg"><label>New Password *</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
      <div class="mfoot">
        <button type="button" class="btn btn-ghost" onclick="closeM('m-reset')">Cancel</button>
        <button type="submit" class="btn btn-primary">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<form method="POST" id="rf" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <input type="hidden" name="action" value="update_role">
  <input type="hidden" name="admin_id" id="rf-uid">
  <input type="hidden" name="role_id" id="rf-rid">
</form>

<script src="<?= APP_ROOT ?>/assets/js/main.js"></script>
<script src="<?= APP_ROOT ?>/assets/js/mobile.js"></script>
<script>
function swTab(n,b){document.querySelectorAll('.tpane').forEach(p=>p.classList.remove('active'));document.querySelectorAll('.pgtab').forEach(x=>x.classList.remove('active'));document.getElementById('tab-'+n)?.classList.add('active');b.classList.add('active');}
function openM(id){document.getElementById(id)?.classList.add('show');}
function closeM(id){document.getElementById(id)?.classList.remove('show');}
function openReset(uid,name){document.getElementById('ruid').value=uid;document.getElementById('rfor').textContent='Resetting password for: '+name;openM('m-reset');}
document.querySelectorAll('.rsel').forEach(s=>s.dataset.orig=s.value);
function updateRole(uid,sel){if(!confirm('Change this user\'s role? Takes effect on their next login.')){sel.value=sel.dataset.orig;return;}document.getElementById('rf-uid').value=uid;document.getElementById('rf-rid').value=sel.value;document.getElementById('rf').submit();}
function filterU(q){q=q.toLowerCase();document.querySelectorAll('#ulist .urow').forEach(r=>r.style.display=(!q||r.dataset.q.includes(q))?'':'none');}
// Realtime presence: heartbeat + polling
(function(){
    const ONLINE_SECS = <?= ONLINE_SECS ?>;
    function sendHeartbeat(){ fetch(location.pathname + '?xhr=heartbeat', {credentials:'same-origin'}).catch(()=>{}); }
    function refreshList(){
      fetch(location.pathname + '?xhr=list', {credentials:'same-origin'})
        .then(r=>r.json())
        .then(data=>{
          if(!data.ok) return;
          let onlineCount = 0;
          let offlineCount = 0;
          data.rows.forEach(u=>{
            if(u.online) onlineCount++; else offlineCount++;
            const row = document.querySelector('.urow[data-admin-id="'+u.admin_id+'"]');
            if(!row) return;
            const on = !!u.online;
            // pip
            const pip = row.querySelector('.pip'); if(pip){ pip.classList.toggle('on', on); pip.classList.toggle('off', !on); }
            // sd dot in avatar
            const sd = row.querySelector('.uav .sd'); if(sd){ sd.classList.toggle('on', on); sd.classList.toggle('off', !on); }
            // presence label — use server-provided EAT time
            const badge = row.querySelector('.ubadges span');
            if(badge){
              if(on) badge.innerHTML = '<span class="pip on"></span>Online now';
              else if(u.last_seen_eat) badge.innerHTML = '<span class="pip off"></span>Last seen '+u.last_seen_eat;
              else badge.innerHTML = '<span class="pip off"></span>Never logged in';
            }
            // account active pill
            const ap = row.querySelector('.ap');
            if(ap){
              if(parseInt(u.admin_id)===parseInt(<?= json_encode($_SESSION['admin_id']) ?>)){
                ap.className='ap ap-you'; ap.textContent='You';
              } else if(u.is_active){ ap.className='ap ap-on'; ap.textContent='Account active'; }
              else { ap.className='ap ap-off'; ap.textContent='Account disabled'; }
            }
          });
          const onlineEl = document.querySelector('.sc.sc-g');
          const offlineEl = document.querySelector('.sc.sc-gr');
          if(onlineEl) onlineEl.innerHTML = '<span class="pip on"></span>' + onlineCount + ' online';
          if(offlineEl) offlineEl.innerHTML = '<span class="pip off"></span>' + offlineCount + ' offline';
        }).catch(()=>{});
    }
    // initial
    sendHeartbeat(); refreshList();
    // heartbeat every 45s, refresh list every 12s
    setInterval(sendHeartbeat,45000);
    setInterval(refreshList,12000);
})();
setTimeout(()=>location.reload(),60000);
</script>
</body></html>