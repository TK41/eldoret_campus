<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../booking/reservation_helpers.php';

requireLogin();
requireAccess('inventory');

$db = getDB();
$admin = getCurrentAdmin();
$errors = [];
$canProcess = canDo('inventory.checkout');

$statusFilter = $_GET['status'] ?? '';
$dateFilter = ($_GET['date'] ?? '') === 'today' ? 'today' : '';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$where = [];
$params = [];
if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'cancelled', 'expired', 'issued', 'returned'], true)) {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
}
if ($dateFilter === 'today') {
    $where[] = 'r.submitted_at >= CURDATE() AND r.submitted_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
}
if ($search !== '') {
    $where[] = '(r.booking_reference LIKE ? OR COALESCE(fsx.student_id, u.student_id) LIKE ? OR u.full_name LIKE ? OR a.name LIKE ? OR k.name LIKE ? OR r.kit_name LIKE ?)';
    $searchTerm = '%' . $search . '%';
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$count = $db->prepare("
    SELECT COUNT(*)
    FROM reservations r
    JOIN users u ON u.user_id = r.user_id
    LEFT JOIN fee_students fsx ON fsx.fee_student_id = COALESCE(r.fee_student_id, u.fee_student_id)
    LEFT JOIN assets a ON a.asset_id = r.asset_id
    LEFT JOIN kits k ON k.kit_id = r.kit_id
    $whereSql
");
$count->execute($params);
$totalRows = (int) $count->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$rows = $db->prepare("
    SELECT r.*, u.full_name, COALESCE(fsx.student_id, u.student_id) AS student_id, u.department, a.name AS asset_name, a.asset_code,
           COALESCE(r.kit_name, k.name) AS kit_name, k.kit_code, ri.item_count,
           (SELECT e.event_type FROM reservation_events e WHERE e.reservation_id = r.reservation_id ORDER BY e.created_at DESC, e.event_id DESC LIMIT 1) AS last_event,
           (SELECT MAX(e.created_at) FROM reservation_events e WHERE e.reservation_id = r.reservation_id) AS last_event_at
    FROM reservations r
    JOIN users u ON u.user_id = r.user_id
    LEFT JOIN fee_students fsx ON fsx.fee_student_id = COALESCE(r.fee_student_id, u.fee_student_id)
    LEFT JOIN assets a ON a.asset_id = r.asset_id
    LEFT JOIN kits k ON k.kit_id = r.kit_id
    LEFT JOIN (
        SELECT reservation_id,
               COUNT(*) AS item_count
        FROM reservation_items
        GROUP BY reservation_id
    ) ri ON ri.reservation_id = r.reservation_id
    $whereSql
    ORDER BY FIELD(r.status, 'pending', 'approved', 'issued', 'rejected', 'cancelled', 'expired', 'returned'),
             r.submitted_at DESC
    LIMIT ? OFFSET ?
");
$rows->execute(array_merge($params, [$perPage, $offset]));
$reservations = $rows->fetchAll();
$statusCounts = $db->query("
    SELECT status, COUNT(*) AS total FROM reservations GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);
$pageTitle = 'Equipment Reservations';
$activePage = 'reservations';

include __DIR__ . '/partials/header.php';
?>

<link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/reservations.css?v=<?= filemtime(__DIR__ . '/../assets/css/reservations.css') ?>">
<div class="page-header">
    <div>
        <h1 class="page-title">Equipment Reservations</h1>
        <p class="page-subtitle">Open a booking to view the student and equipment, then approve, issue or receive it.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= BOOKING_ROOT ?>/admin/student_accounts.php">Student logins</a>
        <a class="btn btn-ghost" href="<?= BOOKING_ROOT ?>/reservations.php" target="_blank" rel="noopener">Open student booking portal</a>
    </div>
</div>

<div class="stats-grid" style="margin-bottom:24px">
    <?php foreach (['pending', 'approved', 'issued', 'returned'] as $status): ?>
        <div class="stat-card accent-<?= $status === 'pending' ? 'orange' : ($status === 'issued' ? 'blue' : 'green') ?>">
            <div class="stat-label"><?= htmlspecialchars(ucfirst($status)) ?></div>
            <div class="stat-value"><?= (int) ($statusCounts[$status] ?? 0) ?></div>
            <div class="stat-sub">Reservation<?= ($statusCounts[$status] ?? 0) == 1 ? '' : 's' ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card rv-card">
    <form method="get" class="rv-filter">
        <div class="form-group" style="min-width:220px;flex:1">
            <label>Search reference, student, item or kit</label>
            <input class="form-control" type="search" name="q" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="form-group" style="min-width:170px">
            <label>Status</label>
            <select class="form-control" name="status">
                <option value="">All statuses</option>
                <?php foreach (['pending', 'approved', 'issued', 'returned', 'rejected', 'cancelled', 'expired'] as $status): ?>
                    <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="min-width:150px">
            <label>Submitted</label>
            <select class="form-control" name="date">
                <option value="">Any date</option>
                <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Filter</button>
    </form>
</div>

<div class="card rv-card">
    <?php if (!$reservations): ?>
        <p><?= $dateFilter === 'today' ? 'No reservations were submitted today.' : 'No reservations match this view.' ?></p>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="data-table rv-table rv-list">
                <thead><tr><th>Reference</th><th>Student</th><th>Request</th><th>Pickup</th><th>Status</th><th>Last update</th></tr></thead>
                <tbody>
                <?php foreach ($reservations as $row): $url = APP_ROOT . '/admin/reservation.php?id=' . (int) $row['reservation_id']; ?>
                    <tr>
                        <td><a href="<?= $url ?>"><strong><?= htmlspecialchars($row['booking_reference']) ?></strong></a></td>
                        <td>
                            <a href="<?= $url ?>"><?= htmlspecialchars(formatPersonName($row['full_name'])) ?></a><br>
                            <span class="mono" style="font-size:12px"><?= htmlspecialchars($row['student_id']) ?></span>
                        </td>
                        <td><?= htmlspecialchars(reservationDisplayTitle($row)) ?>
                            <div style="font-size:12px;color:var(--text-muted)"><?= pluralizeCount($row['item_count'], 'item') ?></div></td>
                        <td class="mono" style="font-size:12px"><?= htmlspecialchars(reservationFormatLocalDateTime($row['requested_start'])) ?></td>
                        <td><span class="badge badge-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                        <td style="font-size:12px">
                            <?php if ($row['last_event']): ?>
                                <?= htmlspecialchars(str_replace('_', ' ', ucfirst($row['last_event']))) ?><br>
                                <span class="mono" style="color:var(--text-muted)"><?= htmlspecialchars(reservationFormatLocalDateTime($row['last_event_at'])) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="rv-pager">
            <span>Page <?= $page ?> of <?= $totalPages ?> · <?= $totalRows ?> requests</span>
            <div>
                <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?<?= http_build_query(['q' => $search, 'status' => $statusFilter, 'date' => $dateFilter, 'page' => $page - 1]) ?>">Previous</a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="?<?= http_build_query(['q' => $search, 'status' => $statusFilter, 'date' => $dateFilter, 'page' => $page + 1]) ?>">Next</a><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
