<?php
// ============================================================
// reset_password.php  — STANDALONE (no other files needed)
// 
// STEP 1: Put this file in C:\xampp\htdocs\kimc-inventory\
// STEP 2: Visit http://localhost/kimc-inventory/reset_password.php
// STEP 3: Click "Reset Password Now"
// STEP 4: Delete this file after it works!
// ============================================================

$db_host  = 'localhost';
$db_name  = 'kimc_inventory';
$db_user  = 'root';
$db_pass  = '';   // XAMPP default — empty string

$username     = 'admin';
$new_password = 'Admin@KIMC2026';
$full_name    = 'System Administrator';
$email        = 'admin@kimc.ac.ke';

$msg = ''; $error = '';

if (isset($_GET['reset'])) {
    try {
        $pdo = new PDO(
            "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
            $db_user, $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $hash = password_hash($new_password, PASSWORD_BCRYPT);
        $check = $pdo->prepare("SELECT admin_id FROM admin_users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE admin_users SET password_hash=?, full_name=?, email=?, is_active=1 WHERE username=?")
                ->execute([$hash, $full_name, $email, $username]);
            $msg = "Password updated for: <strong>$username</strong>";
        } else {
            $pdo->prepare("INSERT INTO admin_users (full_name,email,username,password_hash,role,is_active) VALUES (?,?,?,?,'superadmin',1)")
                ->execute([$full_name, $email, $username, $hash]);
            $msg = "Admin account created: <strong>$username</strong>";
        }
        if (password_verify($new_password, $hash)) $msg .= "<br>&#10003; Hash verified &mdash; login will work!";
    } catch (PDOException $e) { $error = $e->getMessage(); }
}
?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>KIMC Fix Login</title>
<style>
body{font-family:Arial,sans-serif;background:#f0f4ff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border-radius:12px;padding:40px;max-width:480px;width:95%;box-shadow:0 4px 20px rgba(0,0,0,.1)}
h2{color:#1a3a6b;margin:0 0 6px}p{color:#64748b;font-size:14px;margin:0 0 20px}
table{width:100%;border-collapse:collapse;font-size:14px;margin-bottom:20px}
td{padding:8px 10px;border-bottom:1px solid #e2e8f0}td:first-child{color:#64748b;width:110px}td:last-child{font-weight:700}
.btn{display:block;width:100%;padding:13px;background:#1a3a6b;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;text-align:center;text-decoration:none;box-sizing:border-box}
.btn:hover{background:#243f7a}
.ok{background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px;color:#15803d;font-size:14px;margin-bottom:18px}
.err{background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:14px;color:#dc2626;font-size:13px;margin-bottom:18px}
.warn{background:#fefce8;border:1px solid #fde047;border-radius:8px;padding:10px;color:#854d0e;font-size:12px;margin-top:16px}
code{background:#f1f5f9;padding:2px 5px;border-radius:4px;font-size:12px}
</style></head><body>
<div class="box">
  <h2>&#128295; KIMC Login Fix</h2>
  <p>Resets the admin password hash directly in your database.</p>
  <?php if($error): ?>
    <div class="err"><strong>&#10060; Database error:</strong><br><?=htmlspecialchars($error)?><br><br>
    <strong>Check:</strong><br>
    &bull; Is <strong>MySQL running</strong> in XAMPP Control Panel?<br>
    &bull; Did you import <code>kimc_inventory.sql</code> in phpMyAdmin?<br>
    &bull; Database name must be exactly <code>kimc_inventory</code>
    </div>
  <?php elseif($msg): ?>
    <div class="ok"><?=$msg?></div>
    <table>
      <tr><td>Username</td><td><?=htmlspecialchars($username)?></td></tr>
      <tr><td>Password</td><td><?=htmlspecialchars($new_password)?></td></tr>
    </table>
    <a href="/kimc-inventory/auth/login.php" class="btn">&#8594; Go to Login Page</a>
    <div class="warn">&#9888;&#65039; <strong>Delete <code>reset_password.php</code></strong> from your folder now — security risk!</div>
  <?php else: ?>
    <table>
      <tr><td>Username</td><td><?=htmlspecialchars($username)?></td></tr>
      <tr><td>Password</td><td><?=htmlspecialchars($new_password)?></td></tr>
      <tr><td>Database</td><td><?=htmlspecialchars($db_name)?></td></tr>
      <tr><td>DB Host</td><td><?=htmlspecialchars($db_host)?></td></tr>
    </table>
    <a href="?reset=1" class="btn">&#128260; Reset Password Now</a>
  <?php endif; ?>
</div>
</body></html>