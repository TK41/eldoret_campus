<?php
// ============================================================
// admin/dashboard.php
// Main admin dashboard — shows stats, recent transactions,
// overdue alerts, and notification queue at a glance
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../booking/reservation_helpers.php';
require_once __DIR__ . '/../shared/dashboard_components.php';

// Redirect to login if not authenticated
requireLogin();

// -- Page metadata for the header partial --
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

// ============================================================
// Fetch statistics from the database
// ============================================================
$db = getDB();

$pending_reservations = (int) $db->query("
    SELECT COUNT(*) FROM reservations WHERE status = 'pending'
")->fetchColumn();
$reservations_today = (int) $db->query("
    SELECT COUNT(*) FROM reservations
    WHERE submitted_at >= CURDATE()
      AND submitted_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
")->fetchColumn();

// Total assets by type and status
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(asset_type = 'equipment') AS total_equipment,
        SUM(asset_type = 'book')      AS total_books,
        SUM(status = 'available')     AS available,
        SUM(status = 'checked_out')   AS checked_out,
        SUM(status = 'reserved')      AS reserved,
        SUM(status = 'missing')       AS missing,
        SUM(status = 'maintenance')   AS maintenance
    FROM assets WHERE status != 'retired'
")->fetch();
foreach (['total', 'total_equipment', 'total_books', 'available', 'checked_out', 'reserved', 'missing', 'maintenance'] as $statKey) {
    $stats[$statKey] = (int) ($stats[$statKey] ?? 0);
}

// Overdue transactions WITH borrower info
$overdue_borrowers = $db->query("
    SELECT DISTINCT
        u.user_id,
        u.full_name,
        u.student_id,
        u.phone,
        COUNT(DISTINCT t.txn_id) AS item_count,
        GROUP_CONCAT(DISTINCT a.name SEPARATOR ', ') AS items,
        MAX(TIMESTAMPDIFF(HOUR, t.due_at, NOW())) AS hours_overdue,
        SUM(t.fine_amount) AS total_fine
    FROM transactions t
    JOIN users u ON t.user_id = u.user_id
    JOIN assets a ON t.asset_id = a.asset_id
    WHERE t.returned_at IS NULL
      AND COALESCE(t.condition_in, '') != 'missing'
      AND t.due_at < NOW()
    GROUP BY u.user_id
    ORDER BY MAX(t.due_at) ASC
")->fetchAll();

// Count overdue transactions separately from the grouped borrower list.
$overdue_count = (int) $db->query("
    SELECT COUNT(*)
    FROM transactions t
    WHERE t.returned_at IS NULL
      AND COALESCE(t.condition_in, '') != 'missing'
      AND t.due_at < NOW()
")->fetchColumn();
$overdue_borrower_count = count($overdue_borrowers);

// Items due today
$due_today = (int) $db->query("
    SELECT COUNT(*) FROM transactions
    WHERE returned_at IS NULL
      AND COALESCE(condition_in, '') != 'missing'
      AND due_at >= NOW()
      AND due_at < DATE_ADD(NOW(), INTERVAL 24 HOUR)
")->fetchColumn();

// Total fines outstanding
$total_fines = $db->query("
    SELECT COALESCE(SUM(fine_amount), 0) FROM transactions WHERE fine_paid = FALSE
")->fetchColumn();

// ============================================================
// Fetch recent transactions (last 10)
// ============================================================
// Show one representative row per reservation or kit group, not one row per component.
$recent_txns = $db->query("
    SELECT
        t.txn_id,
        a.name         AS asset_name,
        a.asset_code,
        a.asset_type,
        kc.kit_id,
        k.name         AS kit_name,
        k.kit_code,
        u.full_name    AS borrower,
        u.student_id,
        t.checkout_at,
        t.due_at,
        t.returned_at,
        t.fine_amount,
        t.kit_group_id,
        t.reservation_id,
        CASE
            WHEN t.returned_at IS NOT NULL THEN 'returned'
            WHEN t.due_at < NOW()          THEN 'overdue'
            ELSE 'active'
        END AS txn_status,
        (SELECT COUNT(*) FROM transactions WHERE kit_group_id = t.kit_group_id AND kit_group_id IS NOT NULL) AS component_count
    FROM transactions t
    JOIN assets      a  ON t.asset_id = a.asset_id
    JOIN users       u  ON t.user_id  = u.user_id
    LEFT JOIN (
        SELECT asset_id, MIN(kit_id) AS kit_id
        FROM kit_components
        GROUP BY asset_id
    ) kc ON a.asset_id = kc.asset_id
    LEFT JOIN kits k ON kc.kit_id = k.kit_id
    WHERE (
        (
            t.reservation_id IS NOT NULL
            AND t.txn_id = (
                SELECT MIN(t2.txn_id) FROM transactions t2
                WHERE t2.reservation_id = t.reservation_id
            )
        )
        OR (
            t.reservation_id IS NULL
            AND (
                kc.kit_id IS NULL
                OR t.txn_id = (
                    SELECT MIN(t2.txn_id) FROM transactions t2
                    WHERE t2.kit_group_id = t.kit_group_id AND t2.kit_group_id IS NOT NULL
                )
            )
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
        <p class="page-subtitle">
            <?= date('l, j F Y') ?> · <?= pluralizeCount($stats['checked_out'], 'item') ?> out ·
            <?= pluralizeCount($overdue_count, 'overdue item') ?> ·
            <?= $due_today === 0 ? 'Nothing due in the next 24 hours' : pluralizeCount($due_today, 'item') . ' due in the next 24 hours' ?>
        </p>
    </div>
    <div class="dashboard-actions">
        <a href="<?= APP_ROOT ?>/portal.php" class="btn btn-ghost btn-sm" style="text-decoration:none">
            <?= appIcon('home') ?> Portal
        </a>
        <a href="<?= APP_ROOT ?>/admin/transactions.php?action=checkout" class="btn btn-primary">
            <?= appIcon('arrow-left-right') ?> New Check-Out
        </a>
    </div>
</div>

<!-- ============================================================
     STAT CARDS
============================================================ -->
<div class="dashboard-stat-grid">
    <?php
    renderDashboardStatCard([
        'label' => 'Total Assets',
        'value' => formatNumber($stats['total']),
        'summary' => $stats['total'] > 0
            ? formatNumber($stats['total_equipment']) . ' equipment · ' . formatNumber($stats['total_books']) . ' books'
            : 'No assets have been added yet',
        'href' => APP_ROOT . '/admin/assets.php',
        'tone' => 'info',
        'problem' => true,
        'active' => (int) $stats['total'] > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Available Now',
        'value' => formatNumber($stats['available']),
        'summary' => (int) $stats['total'] === 0
            ? 'Add assets to see what is available'
            : ((int) $stats['available'] === 0 ? 'No items are currently available' : pluralizeCount($stats['checked_out'], 'item') . ' currently out'),
        'href' => APP_ROOT . '/admin/assets.php?status=available',
        'tone' => $stats['available'] > 0 ? 'success' : 'warning',
        'problem' => true,
        'active' => (int) $stats['total'] > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Overdue Items',
        'value' => formatNumber($overdue_count),
        'summary' => $overdue_count > 0 ? formatCurrency($total_fines, true) . ' in outstanding fines' : 'Nothing overdue right now',
        'href' => APP_ROOT . '/admin/transactions.php?status=overdue',
        'tone' => 'danger',
        'problem' => true,
        'active' => $overdue_count > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Due in 24 Hours',
        'value' => formatNumber($due_today),
        'summary' => $due_today > 0 ? 'Items due soon' : 'Nothing due in the next 24 hours',
        'href' => APP_ROOT . '/admin/transactions.php?due=next24',
        'tone' => 'warning',
        'problem' => true,
        'active' => $due_today > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Pending Bookings',
        'value' => formatNumber($pending_reservations),
        'summary' => $pending_reservations > 0 ? 'Student requests awaiting review' : 'No booking requests awaiting review',
        'href' => APP_ROOT . '/admin/reservations.php?status=pending',
        'tone' => 'warning',
        'problem' => true,
        'active' => $pending_reservations > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Missing Assets',
        'value' => formatNumber($stats['missing']),
        'summary' => $stats['missing'] > 0 ? 'Still outstanding' : 'No missing assets',
        'href' => APP_ROOT . '/admin/assets.php?status=missing',
        'tone' => 'danger',
        'problem' => true,
        'active' => $stats['missing'] > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Reservations Today',
        'value' => formatNumber($reservations_today),
        'summary' => $reservations_today > 0 ? 'Requests submitted today' : 'No reservations submitted today',
        'href' => APP_ROOT . '/admin/reservations.php?date=today',
        'tone' => 'info',
        'problem' => true,
        'active' => $reservations_today > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Under Maintenance',
        'value' => formatNumber($stats['maintenance']),
        'summary' => $stats['maintenance'] > 0 ? 'Items unavailable for checkout' : 'No items under maintenance',
        'href' => APP_ROOT . '/admin/assets.php?status=maintenance',
        'tone' => 'warning',
        'problem' => true,
        'active' => $stats['maintenance'] > 0,
    ]);
    ?>
</div>

<!-- ============================================================
     MAIN DASHBOARD GRID: Overdue + Recent Txns + Inventory Health
============================================================ -->
<div class="dashboard-main-grid">

    <!-- Left Column: Overdue Borrowers (if any) + Recent Transactions -->
    <div>

        <!-- Overdue borrowers panel (only shown when there are overdue items) -->
        <?php if ($overdue_borrower_count > 0): ?>
        <div class="card overdue-borrowers-card">
            <div class="card-header">
                <h2 class="card-title" style="color: #dc2626;"><?= appIcon('alert-triangle') ?> Overdue Borrowers (<?= $overdue_borrower_count ?> active)</h2>
            </div>
            <div class="overdue-borrowers-list">
                <?php foreach ($overdue_borrowers as $borrower): ?>
                    <?php
                        $hoursOverdue = max(0, (int) $borrower['hours_overdue']);
                        $days_overdue = intdiv($hoursOverdue, 24);
                        $hours_part = $hoursOverdue % 24;
                        $fine_estimated = $borrower['total_fine'];
                    ?>
                    <div class="overdue-borrower-row">
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <strong style="color: var(--text-primary);"><?= htmlspecialchars(formatPersonName($borrower['full_name'])) ?></strong>
                                <span class="mono" style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($borrower['student_id']) ?></span>
                            </div>
                            <div style="font-size: 12px; color: var(--text-primary); margin-bottom: 6px;">
                                <?= htmlspecialchars(substr($borrower['items'], 0, 60)) ?><?= strlen($borrower['items']) > 60 ? '...' : '' ?>
                            </div>
                            <div class="overdue-borrower-meta">
                                <span class="overdue-pill"><?= $days_overdue ?>d <?= $hours_part ?>h overdue</span>
                                <span>Fine: <strong style="color: #dc2626;"><?= formatCurrency($fine_estimated, true) ?></strong></span>
                            </div>
                            <?php if ($borrower['phone']): ?>
                                <a href="tel:<?= urlencode($borrower['phone']) ?>" class="btn btn-ghost btn-sm" style="text-decoration: none; color: #1a3a6b;">
                                    <?= appIcon('phone') ?> Call <?= htmlspecialchars($borrower['phone']) ?>
                                </a>
                            <?php else: ?>
                                <span class="no-phone-label">No phone on file</span>
                                <a href="<?= APP_ROOT ?>/admin/add_user.php?edit=<?= (int) $borrower['user_id'] ?>" class="btn btn-ghost btn-sm">Add phone</a>
                            <?php endif; ?>
                            <form method="POST" action="<?= APP_ROOT ?>/admin/notifications.php" class="queue-overdue-reminder">
                                <input type="hidden" name="action" value="generate_overdue">
                                <input type="hidden" name="user_id" value="<?= (int) $borrower['user_id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <button type="submit" class="btn btn-ghost btn-sm"><?= appIcon('bell') ?> Queue reminder</button>
                            </form>
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
                                                <span class="expand-arrow"><?= appIcon('chevron-down') ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Item name -->
                                    <td>
                                        <?php if (!empty($txn['kit_group_id'])): ?>
                                            <strong><?= appIcon(!empty($txn['kit_name']) ? 'backpack' : 'package') ?> <?= htmlspecialchars($txn['kit_name'] ?? $txn['asset_name']) ?></strong><br>
                                            <span class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($txn['kit_code'] ?? '') ?></span>
                                        <?php else: ?>
                                            <strong><?= htmlspecialchars($txn['asset_name']) ?></strong><br>
                                            <span class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($txn['asset_code']) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= htmlspecialchars(formatPersonName($txn['borrower'])) ?></td>

                                    <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($txn['student_id']) ?></td>

                                    <td class="mono" style="font-size:12px">
                                        <?= htmlspecialchars(reservationFormatLocalDateTime($txn['checkout_at'])) ?>
                                    </td>

                                    <td class="mono" style="font-size:12px">
                                        <?= htmlspecialchars(reservationFormatLocalDateTime($txn['due_at'])) ?>
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
                                    WHERE " . (!empty($txn['reservation_id']) ? 't2.reservation_id = ?' : 't2.kit_group_id = ?') . "
                                    ORDER BY a.name
                                ");
                                $kitComps->execute([
                                    !empty($txn['reservation_id']) ? $txn['reservation_id'] : $txn['kit_group_id']
                                ]);
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
                <h2 class="card-title"><?= appIcon('chart') ?> Inventory Health</h2>
            </div>
            <div class="inventory-health-list">
                <?php
                $inventoryHealthMetrics = [
                    ['key' => 'available', 'label' => 'Available', 'tone' => 'success'],
                    ['key' => 'checked_out', 'label' => 'Checked out', 'tone' => 'info'],
                    ['key' => 'reserved', 'label' => 'Reserved', 'tone' => 'warning'],
                    ['key' => 'maintenance', 'label' => 'Maintenance', 'tone' => 'warning'],
                    ['key' => 'missing', 'label' => 'Missing', 'tone' => 'danger'],
                ];
                foreach ($inventoryHealthMetrics as $metric):
                    $metricCount = $stats[$metric['key']];
                    $metricPercent = $stats['total'] > 0 ? min(100, max(0, ($metricCount / $stats['total']) * 100)) : 0;
                ?>
                <div class="inventory-health-item">
                    <div class="inventory-health-label">
                        <strong><?= $metric['label'] ?></strong>
                        <span><?= formatNumber($metricCount) ?> / <?= formatNumber($stats['total']) ?></span>
                    </div>
                    <div class="inventory-health-track" role="progressbar"
                         aria-label="<?= $metric['label'] ?> assets"
                         aria-valuemin="0" aria-valuemax="<?= max(1, $stats['total']) ?>"
                         aria-valuenow="<?= $metricCount ?>"
                         aria-valuetext="<?= formatNumber($metricCount) ?> of <?= formatNumber($stats['total']) ?> assets">
                        <span class="inventory-health-fill inventory-health-fill--<?= $metric['tone'] ?>" style="width:<?= $metricPercent ?>%"></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= appIcon('zap') ?> Quick Actions</h2>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px; padding: 12px;">
                <a href="<?= APP_ROOT ?>/admin/transactions.php?action=checkout" class="btn btn-primary btn-sm" style="text-align: center; text-decoration: none;">
                    <?= appIcon('upload') ?> Check Out Item
                </a>
                <a href="<?= APP_ROOT ?>/admin/transactions.php?action=checkin" class="btn btn-success btn-sm" style="text-align: center; text-decoration: none;">
                    <?= appIcon('arrow-left-right') ?> Check In Item
                </a>
                <a href="<?= APP_ROOT ?>/admin/assets.php" class="btn btn-ghost btn-sm" style="text-align: center; text-decoration: none;">
                    <?= appIcon('package') ?> View Assets
                </a>
                <a href="<?= APP_ROOT ?>/admin/kits.php" class="btn btn-ghost btn-sm" style="text-align: center; text-decoration: none;">
                    <?= appIcon('backpack') ?> Manage Kits
                </a>
                <a href="<?= APP_ROOT ?>/admin/users.php" class="btn btn-ghost btn-sm" style="text-align: center; text-decoration: none;">
                    <?= appIcon('users') ?> Borrowers
                </a>
            </div>
        </div>

    </div>

</div>

<style>
.overdue-borrowers-card { margin-bottom:24px; background:var(--surface); border:1px solid var(--border); border-left:4px solid var(--color-danger-text); }
.overdue-borrowers-card .card-header { border-bottom:1px solid var(--border); }
.overdue-borrowers-list { max-height:400px; overflow-y:auto; }
.overdue-borrower-row { padding:14px 16px; border-bottom:1px solid var(--border); display:flex; gap:12px; align-items:flex-start; }
.overdue-borrower-row:last-child { border-bottom:0; }
.overdue-borrower-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px; font-size:11px; color:var(--text-muted); }
.overdue-pill { display:inline-flex; padding:3px 8px; border-radius:999px; background:var(--color-danger-bg); color:var(--color-danger-text); font-weight:700; white-space:nowrap; }
.no-phone-label { display:inline-block; margin-right:6px; color:var(--text-muted); font-size:11px; }
.queue-overdue-reminder { display:inline-block; margin-left:6px; }
.inventory-health-list { display:flex; flex-direction:column; gap:16px; padding:16px; }
.inventory-health-label { display:flex; justify-content:space-between; gap:10px; margin-bottom:6px; font-size:12px; }
.inventory-health-label span { color:var(--text-muted); font-variant-numeric:tabular-nums; }
.inventory-health-track { height:8px; overflow:hidden; border-radius:999px; background:var(--border); }
.inventory-health-fill { display:block; height:100%; border-radius:inherit; }
.inventory-health-fill--success { background:var(--color-success); }
.inventory-health-fill--info { background:var(--color-info); }
.inventory-health-fill--warning { background:var(--color-warning); }
.inventory-health-fill--danger { background:var(--color-danger-text); }
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
.expand-arrow { width:14px; height:14px; color:var(--text-muted); transition:transform .2s; display:inline-flex; transform:rotate(-90deg); }
.expand-btn.open .expand-arrow { transform:none; color:#1a3a6b; }
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
