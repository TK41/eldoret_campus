<?php
// ============================================================
// admin/settings.php
// System configuration: fine rates, notification windows,
// SMTP settings, tier rules, and admin account management
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

$pageTitle  = 'Settings';
$activePage = 'settings';
$db         = getDB();
$admin      = getCurrentAdmin();

// ============================================================
// POST: Save general settings
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // -- Save system settings (fine rates, notification windows, SMTP) --
    if ($action === 'save_settings') {
        $settingKeys = [
            'equip_fine_per_hour', 'book_fine_per_day',
            'equip_alert_hours',   'book_alert_hours',
            'institution_name',    'smtp_host',
            'smtp_port',           'smtp_user',
        ];
        foreach ($settingKeys as $key) {
            if (isset($_POST[$key])) {
                $db->prepare("UPDATE settings SET setting_value=? WHERE setting_key=?")
                   ->execute([trim($_POST[$key]), $key]);
            }
        }
        // SMTP password only updated if not blank
        if (!empty($_POST['smtp_pass'])) {
            $db->prepare("UPDATE settings SET setting_value=? WHERE setting_key='smtp_pass'")
               ->execute([trim($_POST['smtp_pass'])]);
        }
        setFlash('success', '✓ Settings saved successfully.');
        header('Location: ' . APP_ROOT . '/admin/settings.php');
        exit;
    }

    // -- Update a tier's borrowing rules --
    if ($action === 'save_tier' && isSuperAdmin()) {
        $tid = intval($_POST['tier_id'] ?? 0);
        $db->prepare("
            UPDATE tiers SET
                max_books       = :max_books,
                book_loan_days  = :book_loan_days,
                max_equipment   = :max_equipment,
                equip_loan_hrs  = :equip_loan_hrs,
                can_reserve     = :can_reserve,
                can_kit         = :can_kit
            WHERE tier_id = :tier_id
        ")->execute([
            ':max_books'      => intval($_POST['max_books']      ?? 3),
            ':book_loan_days' => intval($_POST['book_loan_days'] ?? 14),
            ':max_equipment'  => intval($_POST['max_equipment']  ?? 1),
            ':equip_loan_hrs' => intval($_POST['equip_loan_hrs'] ?? 24),
            ':can_reserve'    => isset($_POST['can_reserve']) ? 1 : 0,
            ':can_kit'        => isset($_POST['can_kit']) ? 1 : 0,
            ':tier_id'        => $tid,
        ]);
        setFlash('success', "✓ Tier $tid rules updated.");
        header('Location: ' . APP_ROOT . '/admin/settings.php#tiers');
        exit;
    }

    // -- Change own admin password --
    if ($action === 'change_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass     = $_POST['new_password']     ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        // Fetch current hash
        $row = $db->prepare("SELECT password_hash FROM admin_users WHERE admin_id=?");
        $row->execute([$admin['admin_id']]);
        $row = $row->fetch();

        if (!password_verify($currentPass, $row['password_hash'])) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($newPass) < 8) {
            setFlash('error', 'New password must be at least 8 characters.');
        } elseif ($newPass !== $confirmPass) {
            setFlash('error', 'New passwords do not match.');
        } else {
            $db->prepare("UPDATE admin_users SET password_hash=? WHERE admin_id=?")
               ->execute([password_hash($newPass, PASSWORD_BCRYPT), $admin['admin_id']]);
            setFlash('success', '✓ Password changed successfully.');
        }
        header('Location: ' . APP_ROOT . '/admin/settings.php#password');
        exit;
    }

    // -- Add new admin account (superadmin only) --

    // Admin registration is handled by auth/register.php

    // -- Toggle admin account active status --
    if ($action === 'toggle_admin' && isSuperAdmin()) {
        $aid = intval($_POST['aid'] ?? 0);
        if ($aid !== $admin['admin_id']) {  // can't suspend yourself
            $db->prepare("UPDATE admin_users SET is_active = NOT is_active WHERE admin_id=?")
               ->execute([$aid]);
            setFlash('success', 'Admin account status toggled.');
        }
        header('Location: ' . APP_ROOT . '/admin/settings.php#admins');
        exit;
    }
}

// ============================================================
// Fetch current settings (seed defaults if missing)
// ============================================================
try {
    $db->exec("INSERT IGNORE INTO settings (setting_key,setting_value) VALUES
        ('equip_fine_per_hour','5.00'),('book_fine_per_day','10.00'),
        ('equip_alert_hours','4'),('book_alert_hours','24'),
        ('institution_name','KIMC Eldoret Campus'),
        ('smtp_host',''),('smtp_port','587'),('smtp_user',''),('smtp_pass','')");
} catch(Exception $e) {}

$settings = $db->query("SELECT setting_key, setting_value FROM settings")
               ->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch all tiers
$tiers = $db->query("SELECT * FROM tiers ORDER BY tier_id")->fetchAll();

// Fetch all admin users
$admins = $db->query("SELECT * FROM admin_users ORDER BY role DESC, full_name")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">System configuration, fine rates, tier rules, and admin accounts</p>
    </div>
</div>

<!-- Settings tabs -->
<div class="settings-tabs">
    <button class="stab active" onclick="switchSettingsTab('general', this)" data-tab="general">⚙️ General</button>
    <button class="stab" onclick="switchSettingsTab('tiers', this)" data-tab="tiers">🔐 Tier Rules</button>
    <button class="stab" onclick="switchSettingsTab('admins', this)" data-tab="admins">👤 Admin Accounts</button>
    <button class="stab" onclick="switchSettingsTab('password', this)" data-tab="password">🔑 Change Password</button>
</div>

<!-- ============================================================
     TAB: GENERAL SETTINGS
============================================================ -->
<div class="stab-content active" id="content-general">
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">

        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h2 class="card-title">🏛 Institution</h2></div>
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Institution Name</label>
                    <input type="text" name="institution_name" class="form-control"
                           value="<?= htmlspecialchars($settings['institution_name'] ?? 'KIMC Eldoret Campus') ?>">
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h2 class="card-title">💰 Fine Rates</h2></div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Equipment Fine (KES per hour)</label>
                    <input type="number" name="equip_fine_per_hour" class="form-control"
                           step="0.50" min="0"
                           value="<?= htmlspecialchars($settings['equip_fine_per_hour'] ?? '5.00') ?>">
                    <span class="field-hint">Charged hourly for overdue equipment</span>
                </div>
                <div class="form-group">
                    <label>Book Fine (KES per day)</label>
                    <input type="number" name="book_fine_per_day" class="form-control"
                           step="1" min="0"
                           value="<?= htmlspecialchars($settings['book_fine_per_day'] ?? '10.00') ?>">
                    <span class="field-hint">Charged daily for overdue books</span>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h2 class="card-title">🔔 Notification Windows</h2></div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Equipment Alert (hours before due)</label>
                    <select name="equip_alert_hours" class="form-control">
                        <?php foreach ([2,4,6,8,12,24] as $h): ?>
                            <option value="<?= $h ?>" <?= ($settings['equip_alert_hours']??'4') == $h ? 'selected' : '' ?>>
                                <?= $h ?> hours before due
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Book Alert (hours before due)</label>
                    <select name="book_alert_hours" class="form-control">
                        <?php foreach ([12,24,48,72] as $h): ?>
                            <option value="<?= $h ?>" <?= ($settings['book_alert_hours']??'24') == $h ? 'selected' : '' ?>>
                                <?= $h ?> hours (<?= $h/24 ?> day<?= $h>24?'s':'' ?>) before due
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px">
            <div class="card-header">
                <h2 class="card-title">📧 Email / SMTP Settings</h2>
                <span style="font-size:12px;color:var(--text-muted)">For automated email notifications</span>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control"
                           placeholder="smtp.gmail.com"
                           value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-control"
                           placeholder="587"
                           value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Username / Email</label>
                    <input type="email" name="smtp_user" class="form-control"
                           placeholder="noreply@kimc.ac.ke"
                           value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Password (leave blank to keep current)</label>
                    <input type="password" name="smtp_pass" class="form-control"
                           placeholder="Enter new password to update">
                    <span class="field-hint">Use an App Password for Gmail accounts</span>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">✓ Save All Settings</button>
        </div>
    </form>
</div>

<!-- ============================================================
     TAB: TIER RULES
============================================================ -->
<div class="stab-content" id="content-tiers">
    <?php foreach ($tiers as $tier): ?>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="badge badge-tier-<?= $tier['tier_id'] ?>" style="margin-right:8px">Tier <?= $tier['tier_id'] ?></span>
                    <?= htmlspecialchars($tier['name']) ?>
                </h2>
                <?php if (!isSuperAdmin()): ?>
                    <span style="font-size:12px;color:var(--text-muted)">Superadmin only</span>
                <?php endif; ?>
            </div>
            <form method="POST">
                <input type="hidden" name="action"  value="save_tier">
                <input type="hidden" name="tier_id" value="<?= $tier['tier_id'] ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Max Books Allowed</label>
                        <input type="number" name="max_books" class="form-control"
                               min="0" max="99" value="<?= $tier['max_books'] ?>"
                               <?= !isSuperAdmin() ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label>Book Loan Period (days)</label>
                        <input type="number" name="book_loan_days" class="form-control"
                               min="1" max="365" value="<?= $tier['book_loan_days'] ?>"
                               <?= !isSuperAdmin() ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label>Max Equipment Items</label>
                        <input type="number" name="max_equipment" class="form-control"
                               min="0" max="99" value="<?= $tier['max_equipment'] ?>"
                               <?= !isSuperAdmin() ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label>Equipment Loan Period (hours)</label>
                        <input type="number" name="equip_loan_hrs" class="form-control"
                               min="1" max="9999" value="<?= $tier['equip_loan_hrs'] ?>"
                               <?= !isSuperAdmin() ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label>Permissions</label>
                        <div style="display:flex;flex-direction:column;gap:10px;padding-top:4px">
                            <label class="toggle-label" style="cursor:pointer;display:flex;align-items:center;gap:10px">
                                <input type="checkbox" name="can_reserve" value="1"
                                       <?= $tier['can_reserve'] ? 'checked' : '' ?>
                                       <?= !isSuperAdmin() ? 'disabled' : '' ?>>
                                <span>Can make advance reservations</span>
                            </label>
                            <label class="toggle-label" style="cursor:pointer;display:flex;align-items:center;gap:10px">
                                <input type="checkbox" name="can_kit" value="1"
                                       <?= $tier['can_kit'] ? 'checked' : '' ?>
                                       <?= !isSuperAdmin() ? 'disabled' : '' ?>>
                                <span>Can borrow kit bundles</span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php if (isSuperAdmin()): ?>
                    <div style="padding:0 20px 20px">
                        <button type="submit" class="btn btn-primary btn-sm">Save Tier <?= $tier['tier_id'] ?></button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<!-- ============================================================
     TAB: ADMIN ACCOUNTS
============================================================ -->
<div class="stab-content" id="content-admins">
    <!-- Current admin accounts table -->
    <div class="card" style="margin-bottom:20px">
        <div class="card-header"><h2 class="card-title">👤 Admin Accounts</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Last Login</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($admins as $a): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($a['full_name']) ?></strong></td>
                            <td class="mono"><?= htmlspecialchars($a['username']) ?></td>
                            <td><?= htmlspecialchars($a['email']) ?></td>
                            <td><span class="badge badge-<?= $a['role']==='superadmin'?'overdue':'available' ?>"><?= ucfirst($a['role']) ?></span></td>
                            <td style="font-size:12px"><?= $a['last_login'] ? date('d M Y H:i', strtotime($a['last_login'])) : 'Never' ?></td>
                            <td><span class="badge badge-<?= $a['is_active']?'available':'maintenance' ?>"><?= $a['is_active']?'Active':'Suspended'?></span></td>
                            <td>
                                <?php if (isSuperAdmin() && $a['admin_id'] !== $admin['admin_id']): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action" value="toggle_admin">
                                        <input type="hidden" name="aid" value="<?= $a['admin_id'] ?>">
                                        <button type="submit" class="btn btn-<?= $a['is_active']?'warn':'success' ?> btn-sm">
                                            <?= $a['is_active']?'Suspend':'Activate' ?>
                                        </button>
                                    </form>
                                <?php elseif ($a['admin_id'] === $admin['admin_id']): ?>
                                    <span style="font-size:12px;color:var(--text-muted)">You</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Register new admin — links to dedicated page -->
    <?php if (isSuperAdmin()): ?>
        <div class="card">
            <div style="padding:24px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap">
                <div>
                    <div style="font-weight:700;font-size:15px;color:var(--text-primary);margin-bottom:4px">
                        Register a New Admin Account
                    </div>
                    <div style="font-size:13px;color:var(--text-muted)">
                        Add authorised KIMC staff who need access to the inventory system.
                    </div>
                </div>
                <a href="<?= APP_ROOT ?>/auth/register.php" class="btn btn-primary" style="white-space:nowrap;flex-shrink:0">
                    + Register New Admin
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     TAB: CHANGE PASSWORD
============================================================ -->
<div class="stab-content" id="content-password">
    <div class="card" style="max-width:460px">
        <div class="card-header"><h2 class="card-title">🔑 Change Your Password</h2></div>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control"
                           minlength="8" required placeholder="Minimum 8 characters">
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Settings tab styles + JS -->
<style>
.settings-tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:0; }
.stab {
    padding:10px 18px; background:none; border:none; border-bottom:2px solid transparent;
    cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;
    color:var(--text-muted); transition:all .15s; margin-bottom:-1px;
}
.stab:hover { color:var(--text-primary); }
.stab.active { color:#1a3a6b; border-bottom-color:#1a3a6b; }
[data-theme="dark"] .stab.active { color:#60a5fa; border-bottom-color:#60a5fa; }
.stab-content { display:none; }
.stab-content.active { display:block; }
.badge-tier-1 { background:rgba(107,114,128,.1);color:#6b7280;border:1px solid rgba(107,114,128,.25); }
.badge-tier-2 { background:rgba(37,99,235,.1);color:#2563eb;border:1px solid rgba(37,99,235,.25); }
.badge-tier-3 { background:rgba(16,185,129,.1);color:#059669;border:1px solid rgba(16,185,129,.25); }
.badge-tier-4 { background:rgba(217,119,6,.1);color:#d97706;border:1px solid rgba(217,119,6,.25); }
</style>

<script>
function switchSettingsTab(name, btn) {
    document.querySelectorAll('.stab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.stab').forEach(el => el.classList.remove('active'));
    document.getElementById('content-' + name).classList.add('active');
    if (btn) btn.classList.add('active');
}

// Auto-open from hash (script is at page bottom so DOM is already ready)
(function() {
    const hash = window.location.hash.replace('#', '');
    const validTabs = ['general', 'tiers', 'admins', 'password'];
    const target = validTabs.includes(hash) ? hash : 'general';
    const contentEl = document.getElementById('content-' + target);
    const btnEl = document.querySelector('[data-tab="' + target + '"]');
    if (contentEl) {
        document.querySelectorAll('.stab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.stab').forEach(el => el.classList.remove('active'));
        contentEl.classList.add('active');
    }
    if (btnEl) btnEl.classList.add('active');
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
