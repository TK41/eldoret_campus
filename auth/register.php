<?php
// ============================================================
// auth/register.php  —  Register a new admin / staff account
//
// ACCESS RULES:
//   • Must be logged in (any admin)
//   • Only superadmins can assign 'superadmin' role
//   • Staff can register new staff members (if you want to
//     restrict this further, add isSuperAdmin() check at top)
//
// HOW TO REACH THIS PAGE:
//   Settings → Admin Accounts → "+ Register New Admin" button
//   or direct URL: /auth/register.php
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/session.php';

// Must be logged in to register new accounts
requireLogin();

$db      = getDB();
$admin   = getCurrentAdmin();
$errors  = [];
$success = '';

// ============================================================
// POST: Create a new admin account
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $first_name = trim($_POST['first_name']   ?? '');
    $last_name  = trim($_POST['last_name']    ?? '');
    $email      = strtolower(trim($_POST['email']     ?? ''));
    $username   = strtolower(trim($_POST['username']  ?? ''));
    $password   = $_POST['password']                  ?? '';
    $password2  = $_POST['password2']                 ?? '';
    $role_raw   = $_POST['role']                      ?? 'staff';

    // Only superadmins may create other superadmins
    $role = ($role_raw === 'superadmin' && isSuperAdmin()) ? 'superadmin' : 'staff';

    // ── Validate ──
    if (!$first_name)                               $errors[] = 'First name is required.';
    if (!$last_name)                                $errors[] = 'Last name is required.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (!$username || strlen($username) < 3)        $errors[] = 'Username must be at least 3 characters.';
    if (!preg_match('/^[a-z0-9_]+$/', $username))   $errors[] = 'Username may only contain letters, numbers and underscores.';
    if (strlen($password) < 8)                      $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $password2)                   $errors[] = 'Passwords do not match.';

    // ── Uniqueness check ──
    if (empty($errors)) {
        $taken = $db->prepare("SELECT admin_id FROM admin_users WHERE username=? OR email=?");
        $taken->execute([$username, $email]);
        if ($taken->fetch()) {
            $errors[] = 'That username or email is already in use. Choose a different one.';
        }
    }

    // ── Insert ──
    if (empty($errors)) {
        // Store admin full name in UPPERCASE
        $full_name = strtoupper(trim("$first_name $last_name"));

        $db->prepare("
            INSERT INTO admin_users
                (first_name, last_name, full_name, email, username, password_hash, role)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $first_name, $last_name, $full_name, $email, $username,
            password_hash($password, PASSWORD_BCRYPT),
            $role,
        ]);

        $success = true;
        // Clear form fields on success
        $_POST = [];
    }
}

// Page uses the full admin layout
$pageTitle  = 'Register Admin';
$activePage = 'settings';   // highlights Settings in sidebar
include __DIR__ . '/../admin/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Register New Admin</h1>
        <p class="page-subtitle">Create a login account for authorised KIMC staff</p>
    </div>
    <a href="<?= APP_ROOT ?>/admin/settings.php#admins" class="btn btn-ghost">
        ← Back to Settings
    </a>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start">

    <!-- ── Registration form ── -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">👤 New Admin Account</h2>
        </div>

        <?php if ($success): ?>
            <!-- Success banner after creating an account -->
            <div style="padding:20px">
                <div style="background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.28);border-radius:10px;padding:18px 20px;margin-bottom:20px">
                    <div style="font-size:22px;margin-bottom:8px">✅</div>
                    <div style="font-weight:700;font-size:15px;color:var(--text-primary);margin-bottom:4px">
                        Account created successfully!
                    </div>
                    <div style="font-size:13px;color:var(--text-muted)">
                        The new admin can now sign in at
                        <a href="<?= APP_ROOT ?>/auth/login.php" style="color:#1a3a6b;font-weight:600">
                            <?= APP_ROOT ?>/auth/login.php
                        </a>
                        using their username and password.
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <a href="<?= APP_ROOT ?>/auth/register.php" class="btn btn-primary">+ Register Another</a>
                    <a href="<?= APP_ROOT ?>/admin/settings.php#admins" class="btn btn-ghost">View All Admins</a>
                </div>
            </div>

        <?php else: ?>
            <!-- Validation errors -->
            <?php if (!empty($errors)): ?>
                <div style="padding:16px 20px 0">
                    <div style="background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.28);color:#dc2626;border-radius:8px;padding:12px 16px;font-size:13px;line-height:1.8">
                        <?php foreach ($errors as $e): ?>
                            <div>⚠ <?= htmlspecialchars($e) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" style="padding:20px">

                <!-- Name row -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">
                    <div class="form-group" style="margin:0">
                        <label>First Name <span style="color:#dc2626">*</span></label>
                        <input type="text" name="first_name" class="form-control"
                               placeholder="Jane" required
                               value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label>Last Name <span style="color:#dc2626">*</span></label>
                        <input type="text" name="last_name" class="form-control"
                               placeholder="Odhiambo" required
                               value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                    </div>
                </div>

                <!-- Email -->
                <div class="form-group">
                    <label>Email Address <span style="color:#dc2626">*</span></label>
                    <input type="email" name="email" class="form-control"
                           placeholder="jdoe@kimc.ac.ke" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <span class="field-hint">Used for notifications. Must be unique.</span>
                </div>

                <!-- Username -->
                <div class="form-group">
                    <label>Username <span style="color:#dc2626">*</span></label>
                    <input type="text" name="username" class="form-control"
                           placeholder="e.g. jdoe" autocomplete="off" required
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <span class="field-hint">3+ characters, lowercase letters, numbers and underscores only.</span>
                </div>

                <!-- Role -->
                <div class="form-group">
                    <label>Role <span style="color:#dc2626">*</span></label>
                    <select name="role" class="form-control">
                        <option value="staff" <?= ($_POST['role'] ?? '') !== 'superadmin' ? 'selected' : '' ?>>
                            Staff — can manage assets, transactions &amp; students
                        </option>
                        <?php if (isSuperAdmin()): ?>
                            <option value="superadmin" <?= ($_POST['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>>
                                Superadmin — full access including settings &amp; user management
                            </option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Password -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">
                    <div class="form-group" style="margin:0">
                        <label>Password <span style="color:#dc2626">*</span></label>
                        <div style="position:relative">
                            <input type="password" id="pw1" name="password" class="form-control"
                                   placeholder="Minimum 8 characters" minlength="8" required
                                   style="padding-right:38px">
                            <button type="button" onclick="togglePwd('pw1')"
                                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:15px;padding:2px">
                                👁
                            </button>
                        </div>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label>Confirm Password <span style="color:#dc2626">*</span></label>
                        <div style="position:relative">
                            <input type="password" id="pw2" name="password2" class="form-control"
                                   placeholder="Repeat password" required
                                   style="padding-right:38px">
                            <button type="button" onclick="togglePwd('pw2')"
                                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:15px;padding:2px">
                                👁
                            </button>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:10px;padding-top:4px">
                    <button type="submit" class="btn btn-primary" style="flex:1">
                        ✓ Create Admin Account
                    </button>
                    <a href="<?= APP_ROOT ?>/admin/settings.php#admins" class="btn btn-ghost">Cancel</a>
                </div>

            </form>
        <?php endif; ?>
    </div>

    <!-- ── Right panel: guidelines ── -->
    <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Role guide card -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">🔐 Role Guide</h2></div>
            <div style="padding:0 20px 20px;display:flex;flex-direction:column;gap:14px">

                <div style="padding:14px;border-radius:8px;border:1px solid rgba(37,99,235,.2);background:rgba(37,99,235,.04)">
                    <div style="font-weight:700;font-size:13px;color:#2563eb;margin-bottom:8px">
                        👤 Staff
                    </div>
                    <ul style="margin:0;padding-left:16px;font-size:12.5px;color:var(--text-muted);line-height:2">
                        <li>Check items in &amp; out</li>
                        <li>View &amp; add assets</li>
                        <li>Manage students</li>
                        <li>Send notifications</li>
                        <li>View transactions &amp; reports</li>
                    </ul>
                </div>

                <div style="padding:14px;border-radius:8px;border:1px solid rgba(220,38,38,.2);background:rgba(220,38,38,.04)">
                    <div style="font-weight:700;font-size:13px;color:#dc2626;margin-bottom:8px">
                        ⚡ Superadmin
                    </div>
                    <ul style="margin:0;padding-left:16px;font-size:12.5px;color:var(--text-muted);line-height:2">
                        <li>Everything Staff can do</li>
                        <li>Manage admin accounts</li>
                        <li>Edit tier &amp; fine rules</li>
                        <li>Retire / delete assets</li>
                        <li>Full system settings</li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- Security tips card -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">💡 Tips</h2></div>
            <div style="padding:14px 20px;font-size:12.5px;color:var(--text-muted);line-height:2">
                <div>• Use the staff member's official KIMC email</div>
                <div>• Share the password securely (not via email)</div>
                <div>• They can change their password after first login via Settings → Change Password</div>
                <div>• You can suspend accounts anytime from Settings → Admin Accounts</div>
            </div>
        </div>

    </div>
</div>

<script>
function togglePwd(id) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
}
</script>

<?php include __DIR__ . '/../admin/partials/footer.php'; ?>
