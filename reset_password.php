<?php
// ============================================================
// reset_password.php
// Place this file in: C:\xampp\htdocs\kimc-inventory\
// Run it ONCE in your browser: http://localhost/kimc-inventory/reset_password.php
// DELETE THIS FILE immediately after use for security!
// ============================================================

require_once __DIR__ . '/config/db.php';

// ── Set your desired admin credentials here ──
$username     = 'admin';
$new_password = 'Admin@KIMC2024';
$full_name    = 'System Administrator';
$email        = 'admin@kimc.ac.ke';

$action = $_GET['action'] ?? '';

// ============================================================
// ACTION: reset — updates or inserts the admin account
// ============================================================
if ($action === 'reset') {
    $db   = getDB();
    $hash = password_hash($new_password, PASSWORD_BCRYPT);

    // Check if admin user exists
    $exists = $db->prepare("SELECT admin_id FROM admin_users WHERE username = ?");
    $exists->execute([$username]);
    $row = $exists->fetch();

    if ($row) {
        // Update existing account
        $stmt = $db->prepare(
            "UPDATE admin_users SET password_hash = ?, full_name = ?, email = ?, is_active = 1 WHERE username = ?"
        );
        $stmt->execute([$hash, $full_name, $email, $username]);
        $msg = "✅ Password updated successfully for user <strong>$username</strong>.";
    } else {
        // Insert new admin account
        $stmt = $db->prepare(
            "INSERT INTO admin_users (full_name, email, username, password_hash, role, is_active)
             VALUES (?, ?, ?, ?, 'superadmin', 1)"
        );
        $stmt->execute([$full_name, $email, $username, $hash]);
        $msg = "✅ Admin account <strong>$username</strong> created successfully.";
    }

    // Verify the hash works
    $verify = password_verify($new_password, $hash);
    $verify_msg = $verify
        ? "✅ Hash verification: PASSED — login will work."
        : "❌ Hash verification: FAILED — something is wrong with your PHP version.";
}

// ============================================================
// ACTION: show_hash — just prints the hash for manual SQL use
// ============================================================
if ($action === 'show_hash') {
    $hash = password_hash($new_password, PASSWORD_BCRYPT);
    $verify = password_verify($new_password, $hash);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KIMC — Password Reset Utility</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 640px; margin: 60px auto; padding: 24px; background: #f4f6fb; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; }
        h1 { font-size: 20px; margin-bottom: 6px; color: #1a3a6b; }
        p { color: #64748b; margin-bottom: 20px; font-size: 14px; }
        .info { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 20px; font-size: 14px; }
        .info strong { display: block; margin-bottom: 8px; color: #1e3a8a; }
        .btn { display: inline-block; padding: 10px 22px; background: #1a3a6b; color: #fff; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; margin-right: 10px; }
        .btn-ghost { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .success { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin-bottom: 20px; font-size: 14px; color: #15803d; }
        .warn { background: #fefce8; border: 1px solid #fde047; border-radius: 8px; padding: 16px; margin: 20px 0; font-size: 13px; color: #854d0e; }
        .hash-box { background: #0f172a; color: #4ade80; font-family: monospace; font-size: 11px; padding: 12px; border-radius: 6px; word-break: break-all; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin: 12px 0; }
        td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
        td:first-child { color: #64748b; width: 140px; }
        td:last-child { font-weight: 600; font-family: monospace; }
    </style>
</head>
<body>
<div class="card">
    <h1>🔧 KIMC Inventory — Password Reset Utility</h1>
    <p>Use this tool to fix login issues by regenerating the admin password hash on your XAMPP server.</p>

    <!-- Show current credentials being set -->
    <div class="info">
        <strong>Credentials that will be set:</strong>
        <table>
            <tr><td>Username</td><td><?= htmlspecialchars($username) ?></td></tr>
            <tr><td>Password</td><td><?= htmlspecialchars($new_password) ?></td></tr>
            <tr><td>Full Name</td><td><?= htmlspecialchars($full_name) ?></td></tr>
            <tr><td>Email</td><td><?= htmlspecialchars($email) ?></td></tr>
        </table>
        <small style="color:#64748b">To change these, edit the variables at the top of reset_password.php</small>
    </div>

    <?php if ($action === 'reset'): ?>
        <!-- Show result of reset action -->
        <div class="success">
            <?= $msg ?><br><br>
            <?= $verify_msg ?><br><br>
            <strong>✅ You can now <a href="<?= APP_ROOT ?>/auth/login.php">log in here</a>.</strong>
        </div>

        <div class="warn">
            ⚠️ <strong>Security reminder:</strong> Delete <code>reset_password.php</code> from your htdocs folder now!
            It is a security risk if left on a live server.
        </div>

    <?php elseif ($action === 'show_hash'): ?>
        <!-- Just show hash for manual use -->
        <div class="success">
            Generated hash for password: <strong><?= htmlspecialchars($new_password) ?></strong><br>
            Verification: <?= $verify ? '✅ PASSED' : '❌ FAILED' ?>
        </div>
        <p style="margin-bottom:6px"><strong>Hash (copy this into your SQL):</strong></p>
        <div class="hash-box"><?= htmlspecialchars($hash) ?></div>
        <p style="font-size:12px; color:#64748b">Use this in phpMyAdmin:<br>
        <code>UPDATE admin_users SET password_hash = '<?= htmlspecialchars($hash) ?>' WHERE username = '<?= $username ?>';</code></p>

    <?php else: ?>
        <!-- Default: show action buttons -->
        <a href="?action=reset" class="btn">🔄 Reset Password in Database</a>
        <a href="?action=show_hash" class="btn btn-ghost">Show Hash Only</a>

        <div class="warn" style="margin-top:20px">
            ℹ️ <strong>When to use "Reset Password":</strong> Click this if you can't log in.
            It will update the admin_users table with a freshly generated hash that matches your XAMPP PHP version.<br><br>
            ℹ️ <strong>When to use "Show Hash Only":</strong> If you prefer to paste the hash manually into phpMyAdmin SQL.
        </div>
    <?php endif; ?>
</div>
</body>
</html>
