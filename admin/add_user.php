<?php
// ============================================================
// admin/add_user.php
// Add a new student/borrower OR edit an existing one
// Handles GET (show form) and POST (save to DB)
// When ?edit=ID is in URL, it pre-fills the form for editing
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

$db     = getDB();
$errors = [];
$isEdit = false;
$user   = [];  // existing user data when editing

// ============================================================
// Define departments and their available tiers
// ============================================================
$departments = [
    'Film Production' => [1, 2],  // Certificate (tier 1), Diploma (tier 2)
    'Broadcast Journalism' => [1, 2],
    'Print Media' => [1, 2],
    'Digital Media' => [1, 2],
    'Other' => [1, 2, 3, 4],  // All tiers for other departments
];

// ============================================================
// Load all tiers for reference
// ============================================================
$all_tiers = $db->query("SELECT * FROM tiers ORDER BY tier_id")->fetchAll();

// Map tiers to names for easier lookup
$tier_map = [];
foreach ($all_tiers as $t) {
    $tier_map[$t['tier_id']] = $t['name'];
}

// ============================================================
// EDIT MODE: pre-load existing user data from DB
// ============================================================
$editId = intval($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$editId]);
    $user = $stmt->fetch();

    if (!$user) {
        setFlash('error', 'User not found.');
        header('Location: ' . APP_ROOT . '/admin/users.php');
        exit;
    }
    $isEdit    = true;
    $pageTitle = 'Edit User: ' . $user['full_name'];
} else {
    $pageTitle = 'Add Staff Member';
}

if (!$isEdit) {
    foreach ($departments as $dept => $tierIds) {
        $departments[$dept] = [4];
    }
}

$activePage = 'users';

// ============================================================
// POST: Process form submission (add or update)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect and sanitise all fields
    $student_id = strtoupper(trim($_POST['student_id'] ?? ''));
    // Store full name in UPPERCASE regardless of input
    $full_name  = strtoupper(trim($_POST['full_name']  ?? ''));
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $phone      = trim($_POST['phone']      ?? '');
    $department = trim($_POST['department'] ?? '');
    $tier_id    = intval($_POST['tier_id']  ?? 1);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    // -- Validation --
    if (empty($student_id)) $errors[] = 'Student ID is required.';
    if (empty($full_name))  $errors[] = 'Full name is required.';
    if (empty($email))      $errors[] = 'Email address is required.';
    if (empty($department)) $errors[] = 'Department is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

    // Check student_id uniqueness (skip own record when editing)
    if (empty($errors)) {
        $checkId = $db->prepare(
            "SELECT user_id FROM users WHERE student_id = ?" .
            ($isEdit ? " AND user_id != $editId" : "")
        );
        $checkId->execute([$student_id]);
        if ($checkId->fetch()) $errors[] = "Student ID <strong>$student_id</strong> already exists.";
    }

    // Check email uniqueness
    if (empty($errors)) {
        $checkEmail = $db->prepare(
            "SELECT user_id FROM users WHERE email = ?" .
            ($isEdit ? " AND user_id != $editId" : "")
        );
        $checkEmail->execute([$email]);
        if ($checkEmail->fetch()) $errors[] = "Email <strong>$email</strong> is already registered.";
    }

    // -- Save if valid --
    if (empty($errors)) {
        if ($isEdit) {
            // UPDATE existing record
            $stmt = $db->prepare("
                UPDATE users SET
                    student_id = :student_id,
                    full_name  = :full_name,
                    email      = :email,
                    phone      = :phone,
                    department = :department,
                    tier_id    = :tier_id,
                    is_active  = :is_active
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ':student_id' => $student_id,
                ':full_name'  => $full_name,
                ':email'      => $email,
                ':phone'      => $phone ?: null,
                ':department' => $department ?: null,
                ':tier_id'    => $tier_id,
                ':is_active'  => $is_active,
                ':user_id'    => $editId,
            ]);
            setFlash('success', "✓ Student <strong>$full_name</strong> updated successfully.");

        } else {
            // INSERT new record
            $stmt = $db->prepare("
                INSERT INTO users (student_id, full_name, email, phone, department, tier_id, is_active)
                VALUES (:student_id, :full_name, :email, :phone, :department, :tier_id, 1)
            ");
            $stmt->execute([
                ':student_id' => $student_id,
                ':full_name'  => $full_name,
                ':email'      => $email,
                ':phone'      => $phone ?: null,
                ':department' => $department ?: null,
                ':tier_id'    => $tier_id,
            ]);
            setFlash('success', "✓ Student <strong>$full_name</strong> added successfully.");
        }

        header('Location: ' . APP_ROOT . '/admin/users.php');
        exit;
    }

    // On validation error: keep POST data to refill the form
    $user = $_POST;
}

// Helper: get field value (POST overrides DB data during error)
function val(array $user, string $key, string $default = ''): string {
    return htmlspecialchars($user[$key] ?? $default);
}

include __DIR__ . '/partials/header.php';
?>

<!-- Page heading -->
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit User' : 'Add Staff Member' ?></h1>
        <p class="page-subtitle">
            <?= $isEdit
                ? 'Update details for ' . htmlspecialchars($user['full_name'] ?? '')
                : 'Add a new staff member to the inventory.' ?>
        </p>
    </div>
    <a href="<?= APP_ROOT ?>/admin/users.php" class="btn btn-ghost">← Back to Users</a>
</div>

<!-- Validation errors -->
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <div>
            <strong>Please fix the following:</strong>
            <ul style="margin-top:6px;padding-left:18px">
                <?php foreach ($errors as $e): ?>
                    <li><?= $e ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button onclick="this.parentElement.remove()" class="alert-close">×</button>
    </div>
<?php endif; ?>

<!-- ============================================================
     ADD / EDIT USER FORM
============================================================ -->
<form method="POST" action="">

    <div class="card" style="margin-bottom:20px">
        <div class="card-header">
            <h2 class="card-title">
                <?= $isEdit ? '✏️ Student Information' : '👤 New Student Details' ?>
            </h2>
            <?php if ($isEdit): ?>
                <span class="mono" style="color:var(--text-muted)">
                    ID: <?= val($user, 'user_id') ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="form-grid">

            <!-- Student ID — e.g. KIMC/ELD/2024/001 -->
            <div class="form-group">
                <label for="student_id">
                    Student / Staff ID <span class="required">*</span>
                </label>
                <input
                    type="text"
                    id="student_id"
                    name="student_id"
                    class="form-control"
                    required
                    placeholder="e.g. KIMC/ELD/2024/001"
                    value="<?= val($user, 'student_id') ?>"
                    style="text-transform:uppercase"
                >
                <span class="field-hint">
                    Use the format: KIMC/ELD/YEAR/NUMBER
                </span>
            </div>

            <!-- Full Name -->
            <div class="form-group">
                <label for="full_name">
                    Full Name <span class="required">*</span>
                </label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    class="form-control"
                    required
                    placeholder="e.g. Jane Achieng Odhiambo"
                    value="<?= val($user, 'full_name') ?>"
                >
            </div>

            <!-- Email -->
            <div class="form-group">
                <label for="email">
                    Email Address <span class="required">*</span>
                </label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-control"
                    required
                    placeholder="student@kimc.ac.ke"
                    value="<?= val($user, 'email') ?>"
                >
                <span class="field-hint">
                    Used for due-date and overdue notifications
                </span>
            </div>

            <!-- Phone -->
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    class="form-control"
                    placeholder="e.g. 0712 345 678"
                    value="<?= val($user, 'phone') ?>"
                >
                <span class="field-hint">Used for SMS notifications (optional)</span>
            </div>

            <!-- Department - Dropdown -->
            <div class="form-group">
                <label for="department">
                    Department <span class="required">*</span>
                </label>
                <select
                    id="department"
                    name="department"
                    class="form-control"
                    required
                    onchange="updateAvailableTiers()">
                    <option value="">— Select Department —</option>
                    <?php foreach (array_keys($departments) as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>"
                            <?= val($user, 'department') === $dept ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">
                    Select your department to see available borrowing levels
                </span>
            </div>

            <!-- Borrowing Tier -->
            <div class="form-group">
                <label for="tier_id">Borrowing Level <span class="required">*</span></label>
                <select id="tier_id" name="tier_id" class="form-control" required>
                    <option value="">— Select Department First —</option>
                    <?php foreach ($all_tiers as $tier): ?>
                        <option value="<?= $tier['tier_id'] ?>"
                                class="tier-option"
                                data-tier="<?= $tier['tier_id'] ?>"
                            <?= intval($user['tier_id'] ?? 1) === $tier['tier_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tier['name']) ?>
                            (<?= $tier['max_equipment'] ?> equipment,
                             <?= $tier['book_loan_days'] ?>-day books)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">
                    Determines what items and how many this student can borrow.
                    <a href="<?= APP_ROOT ?>/admin/permissions.php" target="_blank">View tier rules →</a>
                </span>
            </div>

            <!-- Account Status (edit mode only) -->
            <?php if ($isEdit): ?>
                <div class="form-group full-width">
                    <label>Account Status</label>
                    <label class="toggle-label">
                        <input type="checkbox" name="is_active" value="1"
                               <?= ($user['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <span class="toggle-switch"></span>
                        <span class="toggle-text">
                            Account is <strong>active</strong> — student can borrow items
                        </span>
                    </label>
                    <span class="field-hint">
                        Uncheck to suspend the account. Suspended students cannot check out items.
                    </span>
                </div>
            <?php endif; ?>

        </div><!-- end .form-grid -->
    </div>

    <!-- ── Tier explanation panel ── -->
    <div class="card" style="margin-bottom:20px">
        <div class="card-header">
            <h2 class="card-title">📋 Available Borrowing Levels</h2>
        </div>
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Level</th>
                        <th>Name</th>
                        <th>Max Books</th>
                        <th>Book Loan Period</th>
                        <th>Max Equipment</th>
                        <th>Equipment Loan</th>
                        <th>Can Reserve</th>
                        <th>Kit Access</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_tiers as $tier): ?>
                        <tr id="tier-row-<?= $tier['tier_id'] ?>" class="tier-row-display" style="display:none">
                            <td><strong><?= htmlspecialchars($tier['name']) ?></strong></td>
                            <td><?= htmlspecialchars($tier['name']) ?></td>
                            <td><?= $tier['max_books'] == 99 ? 'Unlimited' : $tier['max_books'] ?></td>
                            <td><?= $tier['book_loan_days'] ?> days</td>
                            <td><?= $tier['max_equipment'] == 99 ? 'Unlimited' : $tier['max_equipment'] ?></td>
                            <td><?= $tier['equip_loan_hrs'] >= 999 ? 'Custom' : $tier['equip_loan_hrs'] . 'h' ?></td>
                            <td><?= $tier['can_reserve'] ? '✅' : '❌' ?></td>
                            <td><?= $tier['can_kit']    ? '✅' : '❌' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p id="tier-empty-msg" style="padding: 20px; text-align: center; color: var(--text-muted);">
                Select a department to see available borrowing levels
            </p>
        </div>
    </div>

    <script>
    // Department → Available Tiers mapping (passed from PHP)
    const departmentTiers = <?= json_encode($departments) ?>;

    function updateAvailableTiers() {
        const deptSelect = document.getElementById('department');
        const tierSelect = document.getElementById('tier_id');
        const selectedDept = deptSelect.value;

        // Get available tier IDs for selected department
        const availableTierIds = departmentTiers[selectedDept] || [];

        // Hide all tier options first
        document.querySelectorAll('.tier-option').forEach(opt => {
            opt.style.display = 'none';
        });

        // Show only available tier options
        availableTierIds.forEach(tierId => {
            const opt = document.querySelector(`.tier-option[data-tier="${tierId}"]`);
            if (opt) opt.style.display = 'block';
        });

        // Update tier summary table
        document.querySelectorAll('.tier-row-display').forEach(row => {
            row.style.display = 'none';
        });

        availableTierIds.forEach(tierId => {
            const row = document.getElementById(`tier-row-${tierId}`);
            if (row) row.style.display = 'table-row';
        });

        // Hide/show empty message
        const emptyMsg = document.getElementById('tier-empty-msg');
        if (availableTierIds.length > 0) {
            emptyMsg.style.display = 'none';
        } else {
            emptyMsg.style.display = 'block';
        }

        // Reset tier selection to first available
        if (availableTierIds.length > 0) {
            tierSelect.value = availableTierIds[0];
        } else {
            tierSelect.value = '';
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Set placeholder text for tier select if no department selected
        const dept = document.getElementById('department').value;
        if (!dept) {
            document.getElementById('tier-empty-msg').style.display = 'block';
        } else {
            updateAvailableTiers();
        }
    });
    </script>

    <!-- Submit buttons -->
    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">
            <?= $isEdit ? '✓ Save Changes' : '✓ Add Staff Member' ?>
        </button>
        <?php if (!$isEdit): ?>
            <button type="reset" class="btn btn-ghost btn-lg">Reset Form</button>
        <?php endif; ?>
        <a href="<?= APP_ROOT ?>/admin/users.php" class="btn btn-ghost btn-lg">Cancel</a>
    </div>

</form>

<style>
/* Toggle switch for active/inactive */
.toggle-label {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    padding: 10px 0;
}
.toggle-label input { display: none; }
.toggle-switch {
    width: 44px; height: 24px;
    background: var(--border);
    border-radius: 999px;
    position: relative;
    flex-shrink: 0;
    transition: background .2s;
}
.toggle-switch::after {
    content: '';
    position: absolute;
    top: 3px; left: 3px;
    width: 18px; height: 18px;
    background: #fff;
    border-radius: 50%;
    transition: transform .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.toggle-label input:checked + .toggle-switch { background: #16a34a; }
.toggle-label input:checked + .toggle-switch::after { transform: translateX(20px); }
.toggle-text { font-size: 14px; color: var(--text-primary); }
</style>

<script>
// Auto-uppercase student ID
document.getElementById('student_id').addEventListener('input', function() {
    const p = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(p, p);
});

// Highlight the matching tier row when tier dropdown changes
document.getElementById('tier_id').addEventListener('change', function() {
    document.querySelectorAll('[id^="tier-row-"]').forEach(function(row) {
        row.style.background = '';
    });
    const selected = document.getElementById('tier-row-' + this.value);
    if (selected) selected.style.background = 'rgba(26,58,107,.06)';
});

// Highlight on load too
(function() {
    const sel = document.getElementById('tier_id');
    if (sel) {
        const row = document.getElementById('tier-row-' + sel.value);
        if (row) row.style.background = 'rgba(26,58,107,.06)';
    }
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
