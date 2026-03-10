<?php
// ============================================================
// admin/dashboard.php
// Main admin dashboard — shows stats, recent transactions,
// overdue alerts, and notification queue at a glance
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';

// Redirect to login if not authenticated
requireLogin();

// -- Page metadata for the header partial --
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

// ============================================================
// Fetch statistics from the database
// ============================================================
$db = getDB();

// Total assets by type and status
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(asset_type = 'equipment') AS total_equipment,
        SUM(asset_type = 'book')      AS total_books,
        SUM(status = 'available')     AS available,
        SUM(status = 'checked_out')   AS checked_out,
        SUM(status = 'maintenance')   AS maintenance
    FROM assets WHERE status != 'retired'
")->fetch();

// Overdue transactions WITH borrower info
$overdue_borrowers = $db->query("
    SELECT DISTINCT
        u.user_id,
        u.full_name,
        u.student_id,
        u.phone,
        COUNT(DISTINCT t.txn_id) AS item_count,
        GROUP_CONCAT(DISTINCT a.name SEPARATOR ', ') AS items,
        MIN(TIMESTAMPDIFF(HOUR, t.due_at, NOW())) AS hours_overdue,
        SUM(t.fine_amount) AS total_fine
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    JOIN assets a ON t.asset_id = a.asset_id
    WHERE t.returned_at IS NULL AND t.due_at < NOW()
    GROUP BY u.user_id
    ORDER BY t.due_at ASC
")->fetchAll();

// Count of overdue items
$overdue_count = count($overdue_borrowers);

// Items due today
$due_today = $db->query("
    SELECT COUNT(*) FROM transactions
    WHERE returned_at IS NULL
    AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
")->fetchColumn();

// Total fines outstanding
$total_fines = $db->query("
    SELECT COALESCE(SUM(fine_amount), 0) FROM transactions WHERE fine_paid = FALSE
")->fetchColumn();

// ============================================================
// Fetch recent transactions (last 10)
// ============================================================
// For kit transactions, only show ONE representative row per kit_group_id
// (the first component). Non-kit rows show normally.
$recent_txns = $db->query("
    SELECT
        t.txn_id,
        a.name         AS asset_name,
        a.asset_code,
        a.asset_type,
        a.kit_id,
        k.name         AS kit_name,
        k.kit_code,
        u.full_name    AS borrower,
        u.student_id,
        t.checkout_at,
        t.due_at,
        t.returned_at,
        t.fine_amount,
        t.kit_group_id,
        CASE
            WHEN t.returned_at IS NOT NULL THEN 'returned'
            WHEN t.due_at < NOW()          THEN 'overdue'
            ELSE 'active'
        END AS txn_status,
        (SELECT COUNT(*) FROM transactions WHERE kit_group_id = t.kit_group_id AND kit_group_id IS NOT NULL) AS component_count
    FROM transactions t
    JOIN assets      a  ON t.asset_id = a.asset_id
    JOIN users       u  ON t.user_id  = u.user_id
    LEFT JOIN kits   k  ON a.kit_id   = k.kit_id
    WHERE (
        a.kit_id IS NULL
        OR t.txn_id = (
            SELECT MIN(t2.txn_id) FROM transactions t2
            WHERE t2.kit_group_id = t.kit_group_id AND t2.kit_group_id IS NOT NULL
        )
    )
    ORDER BY t.checkout_at DESC
    LIMIT 10
")->fetchAll();

// ============================================================
// Render the page
// ============================================================
include __DIR__ . '/partials/header.php';
?>

<!-- Page heading -->
<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle"><?= date('l, d F Y') ?> &mdash; Welcome back, <strong><?= htmlspecialchars($admin['full_name']) ?></strong>!</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a href="<?= APP_ROOT ?>/portal.php" class="btn btn-ghost btn-sm" style="text-decoration:none">
            🏠 Portal
        </a>
        <a href="<?= APP_ROOT ?>/admin/transactions.php?action=checkout" class="btn btn-primary">
            + New Check-Out
        </a>
    </div>
</div>

<!-- ============================================================
     STAT CARDS
============================================================ -->
<div class="stats-grid">

    <div class="stat-card accent-blue">
        <div class="stat-label">Total Assets</div>
        <div class="stat-value"><?= number_format($stats['total']) ?></div>
        <div class="stat-sub"><?= $stats['total_equipment'] ?> equipment &middot; <?= $stats['total_books'] ?> books</div>
    </div>

    <div class="stat-card accent-green">
        <div class="stat-label">Available Now</div>
        <div class="stat-value"><?= number_format($stats['available']) ?></div>
        <div class="stat-sub"><?= $stats['checked_out'] ?> currently out</div>
    </div>

    <div class="stat-card accent-red">
        <div class="stat-label">Overdue Items</div>
        <div class="stat-value"><?= $overdue_count ?></div>
        <div class="stat-sub">KES <?= number_format($total_fines, 2) ?> in outstanding fines</div>
    </div>

    <div class="stat-card accent-orange">
        <div class="stat-label">Due in 24 Hours</div>
        <div class="stat-value"><?= $due_today ?></div>
        <div class="stat-sub">Alerts scheduled</div>
    </div>

</div>

<!-- ============================================================
     MAIN DASHBOARD GRID: Overdue + Recent Txns + Inventory Health
============================================================ -->
<div style="display: grid; grid-template-columns: 1fr 360px; gap: 24px;">

    <!-- Left Column: Overdue Borrowers (if any) + Recent Transactions -->
    <div>

        <!-- 🚨 OVERDUE BORROWERS PANEL (only shown when there are overdue items) -->
        <?php if ($overdue_count > 0): ?>
        <div class="card" style="margin-bottom: 24px; background: rgba(220, 38, 38, .06); border: 1px solid rgba(220, 38, 38, .2);">
            <div class="card-header" style="border-bottom: 1px solid rgba(220, 38, 38, .2);">
                <h2 class="card-title" style="color: #dc2626;">🚨 Overdue Borrowers (<?= $overdue_count ?> active)</h2>
            </div>
            <div style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($overdue_borrowers as $borrower): ?>
                    <?php
                        $days_overdue = intdiv($borrower['hours_overdue'], 24);
                        $hours_part = $borrower['hours_overdue'] % 24;
                        $fine_estimated = $borrower['total_fine'];
                    ?>
                    <div style="padding: 14px 16px; border-bottom: 1px solid rgba(220, 38, 38, .15); display: flex; gap: 12px; align-items: flex-start;">
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <strong style="color: #dc2626;"><?= htmlspecialchars($borrower['full_name']) ?></strong>
                                <span class="mono" style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($borrower['student_id']) ?></span>
                            </div>
                            <div style="font-size: 12px; color: var(--text-primary); margin-bottom: 6px;">
                                <?= htmlspecialchars(substr($borrower['items'], 0, 60)) ?><?= strlen($borrower['items']) > 60 ? '...' : '' ?>
                            </div>
                            <div style="display: flex; gap: 12px; font-size: 11px; color: var(--text-muted); margin-bottom: 8px;">
                                <span><strong style="color: #dc2626;"><?= $days_overdue ?>d <?= $hours_part ?>h</strong> overdue</span>
                                <span>Fine: <strong style="color: #dc2626;">KES <?= number_format($fine_estimated, 2) ?></strong></span>
                            </div>
                            <?php if ($borrower['phone']): ?>
                                <a href="tel:<?= urlencode($borrower['phone']) ?>" class="btn btn-ghost btn-xs" style="text-decoration: none; color: #1a3a6b;">
                                    📞 Call <?= htmlspecialchars($borrower['phone']) ?>
                                </a>
                            <?php else: ?>
                                <span style="font-size: 11px; color: var(--text-muted);">No phone on file</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- RECENT TRANSACTIONS (spans full width of left column) -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Recent Transactions</h2>
                <a href="<?= APP_ROOT ?>/admin/transactions.php" class="btn btn-ghost btn-sm">View All</a>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:32px"></th>
                            <th>Item</th>
                            <th>Borrower</th>
                            <th>Student ID</th>
                            <th>Checked Out</th>
                            <th>Due</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_txns)): ?>
                            <tr><td colspan="7" class="empty-row">No transactions yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_txns as $i => $txn): ?>
                                <tr <?= !empty($txn['kit_group_id']) ? "class=\"kit-dash-row\" data-di=\"$i\"" : '' ?>>

                                    <!-- Expand arrow for kit rows -->
                                    <td style="width:32px;padding:8px 4px;text-align:center">
                                        <?php if (!empty($txn['kit_group_id'])): ?>
                                            <button class="expand-btn"
                                                    onclick="toggleDashKit(<?= $i ?>)"
                                                    title="Show kit items">
                                                <span class="expand-arrow">▶</span>
                                            </button>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Item name -->
                                    <td>
                                        <?php if (!empty($txn['kit_group_id'])): ?>
                                            <strong>🎒 <?= htmlspecialchars($txn['kit_name'] ?? $txn['asset_name']) ?></strong><br>
                                            <span class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($txn['kit_code'] ?? '') ?></span>
                                        <?php else: ?>
                                            <strong><?= htmlspecialchars($txn['asset_name']) ?></strong><br>
                                            <span class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($txn['asset_code']) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= htmlspecialchars($txn['borrower']) ?></td>

                                    <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($txn['student_id']) ?></td>

                                    <td class="mono" style="font-size:12px">
                                        <?= date('d M, H:i', strtotime($txn['checkout_at'])) ?>
                                    </td>

                                    <td class="mono" style="font-size:12px">
                                        <?= date('d M, H:i', strtotime($txn['due_at'])) ?>
                                    </td>

                                    <td>
                                        <span class="badge badge-<?= $txn['txn_status'] ?>">
                                            <?= ucfirst($txn['txn_status']) ?>
                                        </span>
                                    </td>
                                </tr>

                                <?php if (!empty($txn['kit_group_id'])): ?>
                                <!-- Kit component sub-rows (fetched inline) -->
                                <?php
                                $kitComps = $db->prepare("
                                    SELECT a.asset_code, a.name, a.asset_type,
                                           CASE WHEN t2.returned_at IS NOT NULL THEN 'returned'
                                                WHEN t2.due_at < NOW() THEN 'overdue'
                                                ELSE 'active' END AS comp_status
                                    FROM transactions t2
                                    JOIN assets a ON t2.asset_id = a.asset_id
                                    WHERE t2.kit_group_id = ?
                                    ORDER BY a.name
                                ");
                                $kitComps->execute([$txn['kit_group_id']]);
                                $kitComps = $kitComps->fetchAll();
                                ?>
                                <tr class="kit-dash-panel" id="dash-kit-<?= $i ?>" style="display:none">
                                    <td colspan="7" style="padding:0">
                                        <table style="width:100%;border-collapse:collapse;background:var(--table-head-bg)">
                                            <?php foreach ($kitComps as $kc): ?>
                                                <tr>
                                                    <td style="width:32px"></td>
                                                    <td style="padding:7px 12px 7px 40px;font-size:12px">
                                                        <span class="mono" style="color:var(--text-muted);margin-right:8px"><?= htmlspecialchars($kc['asset_code']) ?></span>
                                                        <?= htmlspecialchars($kc['name']) ?>
                                                    </td>
                                                    <td colspan="4"></td>
                                                    <td style="padding:7px 12px;width:90px">
                                                        <span class="badge badge-<?= $kc['comp_status'] ?>" style="font-size:10px">
                                                            <?= ucfirst($kc['comp_status']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </td>
                                </tr>
                                <?php endif; ?>

                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Column: Inventory Health + Quick Actions -->
    <div>

        <!-- INVENTORY HEALTH -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2 class="card-title">📊 Inventory Health</h2>
            </div>
            <div style="padding: 16px;">

                <!-- Available Assets Progress -->
                <div style="margin-bottom: 18px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px;">
                        <span style="font-weight: 600;">Available</span>
                        <span style="color: var(--text-muted);"><?= $stats['available'] ?> / <?= $stats['total'] ?></span>
                    </div>
                    <div style="width: 100%; height: 8px; background: var(--border); border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; background: #10b981; width: <?= $stats['total'] > 0 ? ($stats['available'] / $stats['total'] * 100) : 0 ?>%;"></div>
                    </div>
                </div>

                <!-- Checked Out Assets Progress -->
                <div style="margin-bottom: 18px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px;">
                        <span style="font-weight: 600;">Checked Out</span>
                        <span style="color: var(--text-muted);"><?= $stats['checked_out'] ?> / <?= $stats['total'] ?></span>
                    </div>
                    <div style="width: 100%; height: 8px; background: var(--border); border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; background: #f59e0b; width: <?= $stats['total'] > 0 ? ($stats['checked_out'] / $stats['total'] * 100) : 0 ?>%;"></div>
                    </div>
                </div>

                <!-- Maintenance Assets Progress -->
                <div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px;">
                        <span style="font-weight: 600;">Maintenance</span>
                        <span style="color: var(--text-muted);"><?= $stats['maintenance'] ?> / <?= $stats['total'] ?></span>
                    </div>
                    <div style="width: 100%; height: 8px; background: var(--border); border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; background: #ef4444; width: <?= $stats['total'] > 0 ? ($stats['maintenance'] / $stats['total'] * 100) : 0 ?>%;"></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">⚡ Quick Actions</h2>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px; padding: 12px;">
                <a href="<?= APP_ROOT ?>/admin/transactions.php?action=checkout" class="btn btn-primary btn-sm" style="text-align: center; text-decoration: none;">
                    📤 Check Out Item
                </a>
                <a href="<?= APP_ROOT ?>/admin/transactions.php?action=checkin" class="btn btn-success btn-sm" style="text-align: center; text-decoration: none;">
                    ↩ Check In Item
                </a>
                <a href="<?= APP_ROOT ?>/admin/assets.php" class="btn btn-ghost btn-sm" style="text-align: center; text-decoration: none;">
                    📚 View Assets
                </a>
                <a href="<?= APP_ROOT ?>/admin/kits.php" class="btn btn-ghost btn-sm" style="text-align: center; text-decoration: none;">
                    🎒 Manage Kits
                </a>
                <a href="<?= APP_ROOT ?>/admin/users.php" class="btn btn-ghost btn-sm" style="text-align: center; text-decoration: none;">
                    👥 Borrowers
                </a>
            </div>
        </div>

    </div>

</div>

<style>
.kit-dash-row { background: rgba(26,58,107,.025); }
.kit-dash-row:hover td { background: rgba(26,58,107,.05) !important; }
[data-theme="dark"] .kit-dash-row { background: rgba(96,165,250,.03); }
.badge-kit {
    background:rgba(26,58,107,.1); color:#1a3a6b;
    border:1px solid rgba(26,58,107,.3);
    font-family:'Space Mono',monospace; font-size:10px; font-weight:700;
    padding:2px 8px; border-radius:999px; letter-spacing:.4px;
    text-transform:uppercase; white-space:nowrap;
}
[data-theme="dark"] .badge-kit { background:rgba(96,165,250,.15); color:#60a5fa; border-color:rgba(96,165,250,.35); }
.expand-btn {
    width:24px; height:24px; border-radius:6px;
    border:1px solid var(--border); background:var(--surface);
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:all .15s; padding:0;
}
.expand-btn:hover { border-color:#1a3a6b; background:rgba(26,58,107,.07); }
.expand-arrow { font-size:9px; color:var(--text-muted); transition:transform .2s; display:inline-block; }
.expand-btn.open .expand-arrow { transform:rotate(90deg); color:#1a3a6b; }
[data-theme="dark"] .expand-btn.open .expand-arrow { color:#60a5fa; }
</style>
<script>
function toggleDashKit(i) {
    const panel = document.getElementById('dash-kit-' + i);
    const btn   = document.querySelector(`.kit-dash-row[data-di="${i}"] .expand-btn`);
    if (!panel) return;
    if (panel.style.display === 'none' || !panel.style.display) {
        panel.style.display = 'table-row';
        btn.classList.add('open');
    } else {
        panel.style.display = 'none';
        btn.classList.remove('open');
    }
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
