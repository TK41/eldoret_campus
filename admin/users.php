<?php
// ============================================================
// admin/users.php
// View all registered students / borrowers
// Admin can search, filter, suspend, and navigate to add/edit
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

$pageTitle  = 'Users & Students';
$activePage = 'users';
$db         = getDB();

// ============================================================
// Handle quick actions (suspend / reactivate / delete)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $uid = intval($_POST['user_id'] ?? 0);

    if ($_POST['action'] === 'toggle_active') {
        $db->prepare("UPDATE users SET is_active = NOT is_active WHERE user_id = ?")
           ->execute([$uid]);
        setFlash('success', 'User status updated.');
    }

    if ($_POST['action'] === 'delete' && isSuperAdmin()) {
        // Hard delete — removes the user and all their records completely
        // Foreign key cascade will handle linked transactions if ON DELETE CASCADE
        // is set; otherwise we manually clean up first.
        try {
            $db->beginTransaction();

            // Remove notifications linked to this user
            $db->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$uid]);

            // Remove transactions linked to this user
            $db->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$uid]);

            // Remove the user record itself
            $db->prepare("DELETE FROM users WHERE user_id = ?")->execute([$uid]);

            $db->commit();
            setFlash('success', 'User and all their records have been permanently deleted.');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    header('Location: ' . APP_ROOT . '/admin/users.php');
    exit;
}

// ============================================================
// Filters from GET parameters
// ============================================================
$search            = trim($_GET['search']     ?? '');
$filter_tier       = intval($_GET['tier']     ?? 0);
$filter_department = trim($_GET['department'] ?? '');
$filter_status     = $_GET['status']          ?? '';

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = "(u.full_name LIKE ? OR u.student_id LIKE ? OR u.email LIKE ? OR u.department LIKE ?)";
    $like     = "%$search%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($filter_department) {
    $where[]  = "u.department = ?";
    $params[] = $filter_department;
}
if ($filter_tier > 0) {
    $where[]  = "u.tier_id = ?";
    $params[] = $filter_tier;
}
if ($filter_status === 'active')   { $where[] = "u.is_active = 1"; }
if ($filter_status === 'inactive') { $where[] = "u.is_active = 0"; }
if ($filter_status === 'fines')    { $where[] = "u.fines_owed > 0"; }

$whereSQL = implode(' AND ', $where);

$users = $db->prepare("
    SELECT
        u.*,
        t.name AS tier_name,
        COUNT(tx.txn_id) AS active_loans,
        SUM(CASE WHEN tx.due_at < NOW() AND tx.returned_at IS NULL THEN 1 ELSE 0 END) AS overdue_loans
    FROM users u
    LEFT JOIN tiers        t  ON u.tier_id = t.tier_id
    LEFT JOIN transactions tx ON u.user_id = tx.user_id AND tx.returned_at IS NULL
    WHERE $whereSQL
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
");
$users->execute($params);
$users = $users->fetchAll();

$tiers = $db->query("SELECT * FROM tiers ORDER BY tier_id")->fetchAll();

$totals = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(is_active = 1) AS active,
        SUM(fines_owed > 0) AS with_fines
    FROM users
")->fetch();

include __DIR__ . '/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Users &amp; Students</h1>
        <p class="page-subtitle">
            <?= $totals['total'] ?> registered &middot;
            <?= $totals['active'] ?> active &middot;
            <?= $totals['with_fines'] ?> with outstanding fines
        </p>
    </div>
    <a href="<?= APP_ROOT ?>/admin/add_user.php" class="btn btn-primary">+ Add Student</a>
</div>

<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card accent-blue">
        <div class="stat-label">Total Users</div>
        <div class="stat-value"><?= $totals['total'] ?></div>
        <div class="stat-sub">All registered borrowers</div>
    </div>
    <div class="stat-card accent-green">
        <div class="stat-label">Active Accounts</div>
        <div class="stat-value"><?= $totals['active'] ?></div>
        <div class="stat-sub">Can borrow items</div>
    </div>
    <div class="stat-card accent-red">
        <div class="stat-label">With Fines</div>
        <div class="stat-value"><?= $totals['with_fines'] ?></div>
        <div class="stat-sub">Borrowing suspended</div>
    </div>
    <div class="stat-card accent-orange">
        <div class="stat-label">Showing</div>
        <div class="stat-value"><?= count($users) ?></div>
        <div class="stat-sub">Matching current filter</div>
    </div>
</div>

<div class="filter-bar card" style="margin-bottom:20px">
    <form method="GET" action="" class="filter-form">
        <div class="search-input-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" name="search" class="form-control search-input"
                   placeholder="Search name, student ID, email, department..."
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <select name="department" class="form-control filter-select">
            <option value="">All Departments</option>
            <option value="Film Production"      <?= ($filter_department === 'Film Production')      ? 'selected' : '' ?>>Film Production</option>
            <option value="Broadcast Journalism" <?= ($filter_department === 'Broadcast Journalism') ? 'selected' : '' ?>>Broadcast Journalism</option>
            <option value="Print Media"          <?= ($filter_department === 'Print Media')          ? 'selected' : '' ?>>Print Media</option>
            <option value="Digital Media"        <?= ($filter_department === 'Digital Media')        ? 'selected' : '' ?>>Digital Media</option>
        </select>
        <select name="tier" class="form-control filter-select">
            <option value="">All Levels</option>
            <?php foreach ($tiers as $tier): ?>
                <option value="<?= $tier['tier_id'] ?>"
                    <?= $filter_tier === $tier['tier_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tier['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-control filter-select">
            <option value="">All Statuses</option>
            <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Suspended</option>
            <option value="fines"    <?= $filter_status === 'fines'    ? 'selected' : '' ?>>Has Fines</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= APP_ROOT ?>/admin/users.php" class="btn btn-ghost">Clear</a>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Full Name</th>
                    <th>Department</th>
                    <th>Tier / Level</th>
                    <th>Active Loans</th>
                    <th>Fines (KES)</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="9" class="empty-row">
                            No users found.
                            <a href="<?= APP_ROOT ?>/admin/add_user.php">Add a student →</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars($u['student_id']) ?></td>

                            <td>
                                <strong><?= htmlspecialchars($u['full_name']) ?></strong><br>
                                <small style="color:var(--text-muted)"><?= htmlspecialchars($u['email']) ?></small>
                            </td>

                            <td><?= htmlspecialchars($u['department'] ?: '—') ?></td>

                            <td>
                                <span class="badge badge-tier-<?= $u['tier_id'] ?>">
                                    T<?= $u['tier_id'] ?> — <?= htmlspecialchars($u['tier_name']) ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($u['active_loans'] > 0): ?>
                                    <span style="font-weight:600"><?= $u['active_loans'] ?></span>
                                    <?php if ($u['overdue_loans'] > 0): ?>
                                        <span class="badge badge-overdue" style="margin-left:4px">
                                            <?= $u['overdue_loans'] ?> overdue
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--text-muted)">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($u['fines_owed'] > 0): ?>
                                    <span style="color:#dc2626;font-weight:700;font-family:'Space Mono',monospace">
                                        <?= number_format($u['fines_owed'], 2) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted)">0.00</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($u['is_active']): ?>
                                    <span class="badge badge-available">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-maintenance">Suspended</span>
                                <?php endif; ?>
                            </td>

                            <td style="font-size:12px"><?= date('d M Y', strtotime($u['created_at'])) ?></td>

                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">

                                    <a href="<?= APP_ROOT ?>/admin/add_user.php?edit=<?= $u['user_id'] ?>"
                                       class="btn btn-ghost btn-sm">Edit</a>

                                    <a href="<?= APP_ROOT ?>/admin/transactions.php?user_id=<?= $u['user_id'] ?>"
                                       class="btn btn-ghost btn-sm">History</a>

                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action"  value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm <?= $u['is_active'] ? 'btn-warn' : 'btn-success' ?>">
                                            <?= $u['is_active'] ? 'Suspend' : 'Reactivate' ?>
                                        </button>
                                    </form>

                                    <?php if (isSuperAdmin()): ?>
                                    <form method="POST" style="display:inline"
                                          onsubmit="return confirmDelete('<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                                        <input type="hidden" name="action"  value="delete">
                                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.badge-tier-1 { background:rgba(107,114,128,.1); color:#6b7280; border:1px solid rgba(107,114,128,.25); }
.badge-tier-2 { background:rgba(37,99,235,.1);   color:#2563eb; border:1px solid rgba(37,99,235,.25); }
.badge-tier-3 { background:rgba(16,185,129,.1);  color:#059669; border:1px solid rgba(16,185,129,.25); }
.badge-tier-4 { background:rgba(217,119,6,.1);   color:#d97706; border:1px solid rgba(217,119,6,.25); }
</style>

<script>
function confirmDelete(name) {
    return confirm(
        'Permanently delete "' + name + '"?\n\n' +
        'This will remove the user and ALL their transaction history from the database.\n' +
        'This cannot be undone.'
    );
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
