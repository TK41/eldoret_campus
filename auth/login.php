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
    <title>Sign In — KIMC Eldoret Campus</title>

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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Cormorant+Garamond:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">
    <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html,body{min-height:100%;font-family:'Plus Jakarta Sans',sans-serif}
    body{
        display:grid;
        place-items:center;
        padding:0;
        min-height:100vh;
        background-color:#0f172a;
        background-image:url('<?= APP_ROOT ?>/assets/img/preview.jpg');
        background-size:cover;
        background-position:center;
        background-repeat:no-repeat;
        color:#111827;
    }
    body::before{content:'';position:fixed;inset:0;background:rgba(15,23,42,.08);pointer-events:none;z-index:0}
    .login-page{position:relative;width:100%;max-width:420px;z-index:1;padding:20px 24px 20px;transform:translateY(-24px)}
    .login-panel{background:transparent;border:none;box-shadow:none;margin-top:-18px}
    .login-hero{padding:0}
    .brand{display:flex;align-items:center;gap:16px;justify-content:flex-start;margin-top:-8px;margin-bottom:36px}
    .brand img{height:88px;width:auto;object-fit:contain}
    .brand-title{text-align:left}
    .brand-title .brand-sm{font-size:.95rem;font-weight:700;text-transform:uppercase;letter-spacing:.24em;color:#111827;opacity:.95;margin-bottom:4px}
    .brand-title h1{font-family:'Cormorant Garamond',serif;font-size:3.2rem;font-weight:700;margin:0;color:#111827;line-height:1}
    .brand-title p{font-size:.95rem;color:#111827;line-height:1.35;margin:0;text-transform:none}
    .message-box{padding:16px 18px;border-radius:18px;margin-bottom:18px;font-size:.95rem;line-height:1.6}
    .alert-error{background:rgba(254,226,226,.9);border:1px solid rgba(248,113,113,.35);color:#991b1b}
    .alert-success{background:rgba(220,253,208,.9);border:1px solid rgba(34,197,94,.35);color:#166534}
    .timeout-line{margin:0 0 18px;font-size:.95rem;line-height:1.5;color:#92400e;font-weight:600}
    .form-section{display:none;padding:0 0 28px}
    .form-section.active{display:block}
    .login-tabs{display:flex;gap:8px;background:rgba(255,255,255,.88);border:1px solid rgba(15,23,42,.15);border-radius:16px;padding:6px;margin-bottom:24px;overflow:hidden}
    .login-tab{flex:1;text-align:center;padding:12px 0;border-radius:12px;border:none;background:transparent;color:#475569;font-size:.95rem;font-weight:600;cursor:pointer;transition:all .2s}
    .login-tab.active{background:#111827;color:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.16)}
    .fg{margin-bottom:18px}
    .fg label{display:block;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#111827;margin-bottom:10px}
    .fg input{width:100%;padding:14px 16px;border:1px solid rgba(148,163,184,.45);border-radius:8px;background:#fff;color:#111827;font-size:1rem;transition:border-color .2s,box-shadow .2s}
    .fg input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.12)}
    .login-actions{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:8px}
    .login-actions .link-forgot{color:#111827;font-size:.95rem;text-decoration:none;transition:color .2s}
    .login-actions .link-forgot:hover{color:#475569}
    .btn-login{min-width:140px;padding:12px 16px;border:1px solid rgba(17,24,39,.35);border-radius:6px;background:transparent;color:#111827;font-size:.95rem;font-weight:700;cursor:pointer;transition:background .2s,border-color .2s}
    .btn-login:hover{background:rgba(255,255,255,.12);border-color:rgba(17,24,39,.55)}
    .login-meta{padding:0 0 0}
    .login-meta .small-info{display:none}
    .footer-links{display:none}
    .login-note{text-align:center;color:#111827;font-size:.84rem;padding:8px 32px 8px}
    @media(max-width:560px){
        body{padding:0;}
        .login-page{max-width:100%;padding:28px 16px;margin:0 auto;}
        .login-hero{padding:24px 20px 20px;}
        .form-section{padding:0 20px 24px;}
        .login-meta{padding:0 20px 24px;}
        .brand{flex-direction:column;gap:12px;justify-content:center;}
        .brand-title h1{font-size:1.75rem;}
    }
    </style>
</head>
<body class="kimc-login">

<div class="login-page">
    <div class="login-panel">
        <div class="login-hero">
            <div class="brand">
                <img src="<?= APP_ROOT ?>/assets/img/kimc_logo.png" alt="KIMC Logo">
                <div class="brand-title">
                </div>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="message-box alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="message-box alert-success"><?= $success ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['timeout'])): ?>
            <div class="timeout-line">⏱ Session expired. Please sign in.</div>
            <?php endif; ?>

            <?php if ($allowSignup): ?>
            <div class="login-tabs">
                <button class="login-tab <?= $mode==='login'?'active':'' ?>" onclick="switchTab('login')">Sign In</button>
                <button class="login-tab <?= $mode==='signup'?'active':'' ?>" onclick="switchTab('signup')">Create Account</button>
            </div>
            <?php endif; ?>

            <div class="form-section <?= $mode==='login'?'active':'' ?>" id="tab-login">
                <form method="POST">
                    <input type="hidden" name="form_mode" value="login">
                    <div class="fg">
                        <label>User Name</label>
                        <input id="login-username" class="thin-input" type="text" name="username" required autofocus autocomplete="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="fg password-field">
                        <label>Password</label>
                        <div class="input-with-toggle">
                            <input id="login-password" class="thin-input" type="password" name="password" required autocomplete="current-password">
                            <button type="button" class="pwd-toggle" aria-label="Show password">👁️</button>
                        </div>
                    </div>
                    <div class="login-actions login-actions-right">
                        <button type="submit" class="btn-login">Log In</button>
                    </div>
                </form>
            </div>

            <?php if ($allowSignup): ?>
            <div class="form-section <?= $mode==='signup'?'active':'' ?>" id="tab-signup">
                <div class="message-box alert-success">🔐 New accounts are assigned <strong>Inventory Staff</strong> access by default. A System Admin can update roles after creation.</div>
                <form method="POST">
                    <input type="hidden" name="form_mode" value="signup">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="fg" style="margin:0">
                            <label>First Name</label>
                            <input type="text" name="first_name" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                        </div>
                        <div class="fg" style="margin:0">
                            <label>Last Name</label>
                            <input type="text" name="last_name" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="fg" style="margin-top:12px">
                        <label>Email</label>
                        <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="fg">
                        <label>Username</label>
                        <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="fg">
                        <label>Password</label>
                        <input type="password" name="password" required>
                    </div>
                    <div class="fg">
                        <label>Confirm Password</label>
                        <input type="password" name="password2" required>
                    </div>
                    <div class="login-actions" style="justify-content:flex-end">
                        <button type="submit" class="btn-login">Create Account</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <div class="login-note">No account? Contact your system administrator.</div>
    <div class="login-note">KIMC Eldoret Campus &copy; <?= date('Y') ?></div>
</div>

<script>
(function(){
    const saved = localStorage.getItem('kimc_theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
})();
function switchTab(t) {
    document.querySelectorAll('.login-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
    document.getElementById('tab-'+t)?.classList.add('active');
    document.querySelector('.login-tab:nth-child('+(t==='login'?1:2)+')')?.classList.add('active');
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const btn = document.querySelector('.pwd-toggle');
    if (!btn) return;
    const input = document.getElementById('login-password');
    btn.addEventListener('click', function(){
        if (input.type === 'password'){
            input.type = 'text';
            btn.textContent = '🙈';
            btn.setAttribute('aria-label','Hide password');
        } else {
            input.type = 'password';
            btn.textContent = '👁️';
            btn.setAttribute('aria-label','Show password');
        }
        input.focus();
    });
});
</script>
</body>
</html>
