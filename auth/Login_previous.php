<?php
// ============================================================
// auth/login.php  — RBAC-aware login
// Auto-redirects single-portal users; skips portal chooser
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/rbac.php';

// Already logged in? Send to correct destination
if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . getDefaultRedirect());
    exit;
}

$db          = getDB();
$mode        = $_GET['mode'] ?? 'login';
$allowSignup = ($_GET['admin_signup_key'] ?? '') === 'KIMC_ADMIN_ONLY_2024';
$errors      = [];
$success     = '';

// ── Silent schema migrations ─────────────────────────────────
try { $db->exec("ALTER TABLE admin_users ADD COLUMN first_name VARCHAR(60) NOT NULL DEFAULT '' AFTER admin_id"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE admin_users ADD COLUMN last_name  VARCHAR(60) NOT NULL DEFAULT '' AFTER first_name"); } catch (PDOException $e) {}
$db->exec("UPDATE admin_users SET first_name=TRIM(SUBSTRING_INDEX(full_name,' ',1)), last_name=TRIM(SUBSTRING(full_name,LOCATE(' ',full_name)+1)) WHERE first_name='' AND full_name!=''");

// ── LOGIN ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_mode'] ?? '') === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $errors[] = 'Please enter both username and password.';
    } else {
        $stmt = $db->prepare("
            SELECT admin_id, first_name, last_name, full_name,
                   username, password_hash, role, role_id, is_active
            FROM admin_users WHERE username=? LIMIT 1
        ");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !$admin['is_active']) {
            $errors[] = 'Invalid username or password.';
        } elseif (!password_verify($password, $admin['password_hash'])) {
            $errors[] = 'Invalid username or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['admin_id']   = $admin['admin_id'];
            $_SESSION['username']   = $admin['username'];
            $_SESSION['first_name'] = $admin['first_name'] ?: explode(' ', $admin['full_name'])[0];
            $_SESSION['full_name']  = $admin['full_name'];
            $_SESSION['role']       = $admin['role'];       // legacy compat
            $_SESSION['last_activity'] = time();

            // Load RBAC data into session
            loadUserRbacSession($admin['admin_id']);

            $db->prepare("UPDATE admin_users SET last_login=NOW() WHERE admin_id=?")
               ->execute([$admin['admin_id']]);

            // Auto-redirect based on permissions
            header('Location: ' . getDefaultRedirect());
            exit;
        }
    }
    $mode = 'login';
}

// ── SIGNUP ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_mode'] ?? '') === 'signup') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = strtolower(trim($_POST['email']    ?? ''));
    $username   = strtolower(trim($_POST['username'] ?? ''));
    $password   = $_POST['password']  ?? '';
    $password2  = $_POST['password2'] ?? '';

    if (!$first_name)  $errors[] = 'First name is required.';
    if (!$last_name)   $errors[] = 'Last name is required.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (!$username || strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
    if (strlen($password) < 8)              $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $password2)           $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $taken = $db->prepare("SELECT admin_id FROM admin_users WHERE username=? OR email=?");
        $taken->execute([$username, $email]);
        if ($taken->fetch()) $errors[] = 'That username or email is already registered.';
    }

    if (empty($errors)) {
        $adminCount = $db->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
        $isFirst    = ($adminCount == 0);
        $role       = $isFirst ? 'superadmin' : 'staff';
        $roleId     = $isFirst ? 1 : 5; // superadmin=1, inventory_staff=5
        $full_name  = trim("$first_name $last_name");

        $db->prepare("
            INSERT INTO admin_users (first_name, last_name, full_name, email, username, password_hash, role, role_id)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $first_name, $last_name, $full_name, $email, $username,
            password_hash($password, PASSWORD_BCRYPT), $role, $roleId
        ]);
        $success = "Account created for <strong>$first_name $last_name</strong>. You can now sign in.";
        $mode    = 'login';
    } else {
        $mode = 'signup';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — KIMC Eldoret</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=Space+Mono&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
        --navy: #07111f; --navy-2: #0d1e35; --gold: #c9a84c; --gold-lt: #e4c46a;
        --gold-pale: rgba(201,168,76,.12); --off-white: #f0eeea;
        --muted: rgba(255,255,255,.38); --border: rgba(201,168,76,.18);
        --input-bg: rgba(255,255,255,.05); --input-border: rgba(255,255,255,.12);
    }
    html, body { height: 100%; background: var(--navy); color: #fff; font-family: 'DM Sans', sans-serif; overflow-y: auto; }
    #bg-canvas { position: fixed; inset: 0; z-index: 0; pointer-events: none; }
    .geo-overlay { position: fixed; inset: 0; z-index: 1; pointer-events: none; overflow-y: auto; }
    .geo-overlay svg { position: absolute; width: 100%; height: 100%; opacity: .04; }
    .wrapper { position: relative; z-index: 10; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
    .card {
        width: 420px; max-width: 100%;
        background: rgba(13,30,53,.72);
        backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
        border: 1px solid var(--border); border-radius: 20px; padding: 44px 40px 36px;
        box-shadow: 0 0 0 1px rgba(255,255,255,.03), 0 24px 64px rgba(0,0,0,.6), 0 0 80px rgba(201,168,76,.04);
        animation: cardIn .7s cubic-bezier(.16,1,.3,1) both;
    }
    @keyframes cardIn { from { opacity:0; transform:translateY(32px) scale(.97); } to { opacity:1; transform:translateY(0) scale(1); } }
    @keyframes fadeUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
    .logo-wrap { text-align: center; margin-bottom: 28px; animation: fadeUp .5s .1s ease both; }
    .logo-img { height: 52px; width: auto; object-fit: contain; margin-bottom: 14px; filter: brightness(1.05); }
    .school-name { font-family: 'Cormorant Garamond', serif; font-size: 22px; font-weight: 700; color: var(--off-white); line-height: 1.2; }
    .school-name em { font-style: normal; color: var(--gold); }
    .school-sub { font-size: 11px; font-weight: 500; letter-spacing: 2.5px; text-transform: uppercase; color: var(--muted); margin-top: 5px; }
    .divider { display: flex; align-items: center; gap: 10px; margin: 0 0 24px; animation: fadeUp .5s .15s ease both; }
    .divider-line { flex: 1; height: 1px; background: var(--border); }
    .divider-icon { font-size: 10px; color: var(--gold); opacity: .6; }
    .tabs { display: flex; background: rgba(255,255,255,.04); border-radius: 10px; padding: 3px; margin-bottom: 24px; animation: fadeUp .5s .18s ease both; }
    .tab { flex: 1; padding: 9px; border: none; background: none; color: var(--muted); font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all .2s; }
    .tab.active { background: var(--gold-pale); color: var(--gold-lt); border: 1px solid var(--border); }
    .tab:hover:not(.active) { color: rgba(255,255,255,.65); }
    .alert { border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 18px; animation: fadeUp .3s ease both; }
    .alert-error   { background: rgba(220,38,38,.1); border: 1px solid rgba(220,38,38,.25); color: #fca5a5; }
    .alert-success { background: rgba(22,163,74,.1);  border: 1px solid rgba(22,163,74,.25);  color: #86efac; }
    .form-panel { animation: fadeUp .5s .2s ease both; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px; color: var(--muted); margin-bottom: 7px; }
    .input-wrap { position: relative; }
    .form-control { width: 100%; padding: 11px 14px; background: var(--input-bg); border: 1px solid var(--input-border); border-radius: 9px; color: #fff; font-family: 'DM Sans', sans-serif; font-size: 14px; outline: none; box-sizing: border-box; transition: border-color .2s, background .2s, box-shadow .2s; }
    .form-control::placeholder { color: rgba(255,255,255,.2); }
    .form-control:focus { border-color: var(--gold); background: rgba(255,255,255,.07); box-shadow: 0 0 0 3px rgba(201,168,76,.1); }
    .form-control:-webkit-autofill, .form-control:-webkit-autofill:focus { -webkit-box-shadow: 0 0 0 100px #0d1e35 inset; -webkit-text-fill-color: #fff; }
    .toggle-eye { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted); font-size: 15px; padding: 4px; transition: color .2s; }
    .toggle-eye:hover { color: var(--gold-lt); }
    .name-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .btn-submit { width: 100%; padding: 13px; border: none; border-radius: 10px; background: linear-gradient(135deg,#b8860b,#c9a84c,#e4c46a); color: #1a0f00; font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 700; letter-spacing: .4px; cursor: pointer; margin-top: 6px; transition: transform .15s, box-shadow .15s, filter .15s; }
    .btn-submit:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(201,168,76,.3); filter: brightness(1.05); }
    .btn-submit:active { transform: none; }
    .card-footer { text-align: center; margin-top: 20px; font-size: 11px; color: var(--muted); animation: fadeUp .5s .25s ease both; }
    .card-footer a { color: rgba(201,168,76,.7); text-decoration: none; }
    .card-footer a:hover { color: var(--gold-lt); }
    @media (max-width: 480px) { html,body{overflow:auto;} .card{padding:32px 24px 28px;} .school-name{font-size:19px;} }
    </style>
</head>
<body>
<canvas id="bg-canvas"></canvas>
<div class="geo-overlay">
    <svg viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
        <line x1="0" y1="0" x2="1440" y2="900" stroke="white" stroke-width=".5"/>
        <line x1="1440" y1="0" x2="0" y2="900" stroke="white" stroke-width=".5"/>
        <line x1="720" y1="0" x2="720" y2="900" stroke="white" stroke-width=".3"/>
        <line x1="0" y1="450" x2="1440" y2="450" stroke="white" stroke-width=".3"/>
        <rect x="40" y="40" width="120" height="120" rx="2" stroke="white" stroke-width=".5" fill="none"/>
        <rect x="50" y="50" width="100" height="100" rx="2" stroke="white" stroke-width=".3" fill="none"/>
        <line x1="40" y1="100" x2="160" y2="100" stroke="white" stroke-width=".3"/>
        <line x1="100" y1="40" x2="100" y2="160" stroke="white" stroke-width=".3"/>
        <rect x="1280" y="40" width="120" height="120" rx="2" stroke="white" stroke-width=".5" fill="none"/>
        <rect x="1290" y="50" width="100" height="100" rx="2" stroke="white" stroke-width=".3" fill="none"/>
        <line x1="1280" y1="100" x2="1400" y2="100" stroke="white" stroke-width=".3"/>
        <line x1="1340" y1="40" x2="1340" y2="160" stroke="white" stroke-width=".3"/>
        <rect x="40" y="740" width="120" height="120" rx="2" stroke="white" stroke-width=".5" fill="none"/>
        <rect x="1280" y="740" width="120" height="120" rx="2" stroke="white" stroke-width=".5" fill="none"/>
        <circle cx="720" cy="450" r="200" stroke="white" stroke-width=".3" fill="none"/>
        <circle cx="720" cy="450" r="350" stroke="white" stroke-width=".2" fill="none"/>
    </svg>
</div>
<div class="wrapper">
    <div class="card">
        <div class="logo-wrap">
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo" class="logo-img">
            <div class="school-name">Kenya Institute of<br>Mass Communication<br><em>Eldoret Campus</em></div>
            <div class="school-sub">Campus Management System</div>
        </div>
        <div class="divider">
            <div class="divider-line"></div><span class="divider-icon">◆</span><div class="divider-line"></div>
        </div>
        <div class="tabs">
            <button type="button" class="tab <?= $mode==='login'  ? 'active':'' ?>" onclick="switchTab('login',this)">Sign In</button>
            <button type="button" class="tab <?= $mode==='signup' ? 'active':'' ?>" id="tab-signup"
                    onclick="switchTab('signup',this)" <?= !$allowSignup ? 'style="display:none"':'' ?>>Create Account</button>
        </div>
        <?php if (isset($_GET['timeout'])): ?>
            <div class="alert alert-error">Session expired due to inactivity. Please sign in again.</div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">✓ <?= $success ?></div>
        <?php endif; ?>
        <!-- LOGIN -->
        <div id="panel-login" class="form-panel" <?= $mode!=='login' ? 'style="display:none"':'' ?>>
            <form method="POST" action="?mode=login">
                <input type="hidden" name="form_mode" value="login">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Your username" autocomplete="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrap">
                        <input type="password" id="login-pwd" name="password" class="form-control" placeholder="Your password" autocomplete="current-password" required>
                        <button type="button" class="toggle-eye" onclick="togglePwd('login-pwd')">👁</button>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Sign In →</button>
            </form>
            <?php if (!$allowSignup): ?>
                <div class="card-footer" style="margin-top:14px">No account? Contact your system administrator.</div>
            <?php endif; ?>
        </div>
        <!-- SIGNUP -->
        <div id="panel-signup" class="form-panel" <?= $mode!=='signup' ? 'style="display:none"':'' ?> <?= !$allowSignup ? 'hidden':'' ?>>
            <form method="POST" action="?mode=signup&admin_signup_key=KIMC_ADMIN_ONLY_2024">
                <input type="hidden" name="form_mode" value="signup">
                <div class="name-row">
                    <div class="form-group"><label>First Name</label><input type="text" name="first_name" class="form-control" placeholder="Jane" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"></div>
                    <div class="form-group"><label>Last Name</label><input type="text" name="last_name" class="form-control" placeholder="Odhiambo" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"></div>
                </div>
                <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" placeholder="you@kimc.ac.ke" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></div>
                <div class="form-group"><label>Username</label><input type="text" name="username" class="form-control" placeholder="e.g. jdoe" autocomplete="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
                <div class="form-group"><label>Password</label>
                    <div class="input-wrap"><input type="password" id="signup-pwd" name="password" class="form-control" placeholder="Minimum 8 characters" minlength="8" required><button type="button" class="toggle-eye" onclick="togglePwd('signup-pwd')">👁</button></div>
                </div>
                <div class="form-group"><label>Confirm Password</label>
                    <div class="input-wrap"><input type="password" id="signup-pwd2" name="password2" class="form-control" placeholder="Repeat password" required><button type="button" class="toggle-eye" onclick="togglePwd('signup-pwd2')">👁</button></div>
                </div>
                <button type="submit" class="btn-submit">Create Account →</button>
            </form>
            <div class="card-footer">Already have an account? <a href="#" onclick="switchTab('login');return false">Sign in →</a></div>
        </div>
        <div class="card-footer">KIMC Eldoret Campus &copy; <?= date('Y') ?></div>
    </div>
</div>
<script>
function switchTab(tab, btn) {
    document.getElementById('panel-login').style.display  = tab==='login'  ? 'block':'none';
    document.getElementById('panel-signup').style.display = tab==='signup' ? 'block':'none';
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
    if (btn) { btn.classList.add('active'); }
    else { document.querySelectorAll('.tab')[tab==='login'?0:1].classList.add('active'); }
}
function togglePwd(id) { const i=document.getElementById(id); i.type=i.type==='password'?'text':'password'; }
(function() {
    const canvas=document.getElementById('bg-canvas'), ctx=canvas.getContext('2d');
    let W, H, particles=[];
    function resize(){ W=canvas.width=window.innerWidth; H=canvas.height=window.innerHeight; }
    resize(); window.addEventListener('resize', resize);
    function P(){ this.reset=function(){ this.x=Math.random()*W; this.y=Math.random()*H; this.vx=(Math.random()-.5)*.3; this.vy=(Math.random()-.5)*.3; this.r=Math.random()*1.5+.3; this.a=Math.random()*.35+.05; this.gold=Math.random()<.25; }; this.reset(); }
    for(let i=0;i<80;i++) particles.push(new P());
    function drawLines(){ for(let i=0;i<particles.length;i++) for(let j=i+1;j<particles.length;j++){ const dx=particles[i].x-particles[j].x, dy=particles[i].y-particles[j].y, d=Math.sqrt(dx*dx+dy*dy); if(d<120){ const a=(1-d/120)*.07; ctx.strokeStyle=particles[i].gold?`rgba(201,168,76,${a})`:`rgba(180,200,255,${a})`; ctx.lineWidth=.5; ctx.beginPath(); ctx.moveTo(particles[i].x,particles[i].y); ctx.lineTo(particles[j].x,particles[j].y); ctx.stroke(); }}}
    function animate(){ ctx.clearRect(0,0,W,H); const g=ctx.createRadialGradient(W*.35,H*.4,0,W*.35,H*.4,W*.7); g.addColorStop(0,'rgba(26,58,107,.18)'); g.addColorStop(1,'rgba(7,17,31,0)'); ctx.fillStyle=g; ctx.fillRect(0,0,W,H); drawLines(); particles.forEach(p=>{ p.x+=p.vx; p.y+=p.vy; if(p.x<-10)p.x=W+10; if(p.x>W+10)p.x=-10; if(p.y<-10)p.y=H+10; if(p.y>H+10)p.y=-10; ctx.beginPath(); ctx.arc(p.x,p.y,p.r,0,Math.PI*2); ctx.fillStyle=p.gold?`rgba(201,168,76,${p.a})`:`rgba(180,210,255,${p.a*.7})`; ctx.fill(); }); requestAnimationFrame(animate); }
    animate();
})();
</script>
</body>
</html>
