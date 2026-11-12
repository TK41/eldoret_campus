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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canProcess) {
        http_response_code(403);
        exit('You do not have permission to process equipment reservations.');
    }

    $action = $_POST['action'] ?? '';
    $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    $receiveMsg = null;
    $reason = trim($_POST['reason'] ?? '');
    if (!$reservationId || !in_array($action, ['approve', 'reject', 'issue', 'cancel', 'expire', 'receive'], true)) {
        setFlash('error', 'Invalid reservation action.');
        header('Location: ' . APP_ROOT . '/admin/reservation.php?id=' . (int) ($reservationId ?: 0));
        exit;
    }

    try {
        $db->beginTransaction();
        $lock = $db->prepare("
            SELECT r.*, u.is_active, u.fines_owed, t.can_reserve, t.can_kit
            FROM reservations r
            JOIN users u ON u.user_id = r.user_id
            JOIN tiers t ON t.tier_id = u.tier_id
            WHERE r.reservation_id = ?
            FOR UPDATE
        ");
        $lock->execute([$reservationId]);
        $reservation = $lock->fetch();
        if (!$reservation) {
            throw new InvalidArgumentException('Reservation not found.');
        }

        $itemStmt = $db->prepare("
            SELECT ri.asset_id, ri.asset_code_snapshot, ri.asset_name_snapshot,
                   a.condition_rating
            FROM reservation_items ri
            JOIN assets a ON a.asset_id = ri.asset_id
            WHERE ri.reservation_id = ?
            ORDER BY ri.asset_id
        ");
        $itemStmt->execute([$reservationId]);
        $items = $itemStmt->fetchAll();
        $assetIds = array_map('intval', array_column($items, 'asset_id'));

        if ($action === 'approve') {
            if ($reservation['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending requests can be approved.');
            }
            if (($_POST['identity_verified'] ?? '') !== '1') {
                throw new InvalidArgumentException('Verify the student against their institutional identification before approval.');
            }
            if (!$reservation['is_active'] || !$reservation['can_reserve']
                || (in_array($reservation['reservation_type'], ['kit', 'custom'], true) && count($assetIds) > 1 && !$reservation['can_kit'])
                || (float) $reservation['fines_owed'] > 0) {
                throw new InvalidArgumentException('The student is not currently eligible to borrow this item or kit.');
            }
            if ($reservation['requested_end'] <= gmdate('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('This request has expired and cannot be approved.');
            }
            reservationEnsureAvailable(
                $db,
                $assetIds,
                $reservation['requested_start'],
                $reservation['requested_end']
            );
            $db->prepare("
                UPDATE reservations
                SET status = 'approved', decision_at = UTC_TIMESTAMP(), decision_by = ?
                WHERE reservation_id = ?
            ")->execute([$admin['admin_id'], $reservationId]);
            reservationLog($db, $reservationId, 'approved', (int) $admin['admin_id']);
        } elseif ($action === 'reject') {
            if ($reservation['status'] !== 'pending' || $reason === '' || strlen($reason) > 500) {
                throw new InvalidArgumentException('A pending request and a rejection reason of 1–500 characters are required.');
            }
            $db->prepare("
                UPDATE reservations
                SET status = 'rejected', rejection_reason = ?, decision_at = UTC_TIMESTAMP(), decision_by = ?
                WHERE reservation_id = ?
            ")->execute([$reason, $admin['admin_id'], $reservationId]);
            reservationLog($db, $reservationId, 'rejected', (int) $admin['admin_id'], $reason);
        } elseif ($action === 'issue') {
            $now = gmdate('Y-m-d H:i:s');
            if ($reservation['status'] !== 'approved') {
                throw new InvalidArgumentException('Only approved reservations can be issued.');
            }
            if ($reservation['requested_start'] > $now || $reservation['requested_end'] <= $now) {
                throw new InvalidArgumentException('Issue the equipment only during the approved collection period.');
            }
            if (!$reservation['is_active'] || (float) $reservation['fines_owed'] > 0 || !$items) {
                throw new InvalidArgumentException('The student is not eligible or the requested equipment is no longer complete.');
            }
            // Students don't pick a return time: staff confirm the due date/time here.
            $dueUtc = reservationParseLocalDateTime(trim($_POST['due_at'] ?? ''))
                ?: $reservation['requested_end'];
            if ($dueUtc <= $now || $dueUtc <= $reservation['requested_start']) {
                throw new InvalidArgumentException('The due date and time must be in the future and after pickup.');
            }
            if ($dueUtc > gmdate('Y-m-d H:i:s', strtotime('+60 days'))) {
                throw new InvalidArgumentException('The due date cannot be more than 60 days away.');
            }
            reservationEnsureAvailable(
                $db,
                $assetIds,
                $reservation['requested_start'],
                $dueUtc,
                $reservationId
            );

            $isGroupIssue = in_array($reservation['reservation_type'], ['kit', 'custom'], true);
            $kitGroupId = $isGroupIssue
                ? bin2hex(random_bytes(16))
                : null;
            $insertTxn = $db->prepare("
                INSERT INTO transactions
                    (asset_id, user_id, staff_id, checkout_at, due_at, condition_out,
                     condition_note, is_kit_txn, kit_group_id, reservation_id)
                VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)
            ");
            $updateAsset = $db->prepare("UPDATE assets SET status = 'checked_out' WHERE asset_id = ?");
            foreach ($items as $item) {
                $insertTxn->execute([
                    $item['asset_id'],
                    $reservation['user_id'],
                    $admin['admin_id'],
                    $dueUtc,
                    $item['condition_rating'],
                    'Issued against booking ' . $reservation['booking_reference'],
                    $isGroupIssue ? 1 : 0,
                    $kitGroupId,
                    $reservationId,
                ]);
                $updateAsset->execute([$item['asset_id']]);
            }
            if ($reservation['reservation_type'] === 'kit') {
                $db->prepare("UPDATE kits SET status = 'checked_out' WHERE kit_id = ?")
                    ->execute([$reservation['kit_id']]);
            }
            $db->prepare("
                UPDATE reservations
                SET status = 'issued', issued_at = UTC_TIMESTAMP(), issued_by = ?, requested_end = ?
                WHERE reservation_id = ?
            ")->execute([$admin['admin_id'], $dueUtc, $reservationId]);
            reservationLog($db, $reservationId, 'issued', (int) $admin['admin_id'],
                'Due ' . reservationFormatLocalDateTime($dueUtc));
        } elseif ($action === 'cancel') {
            if (!in_array($reservation['status'], ['pending', 'approved'], true)) {
                throw new InvalidArgumentException('Only pending or approved reservations can be cancelled.');
            }
            $db->prepare("
                UPDATE reservations
                SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP()
                WHERE reservation_id = ?
            ")->execute([$reservationId]);
            reservationLog($db, $reservationId, 'cancelled_by_staff', (int) $admin['admin_id'], $reason ?: null);
        } elseif ($action === 'receive') {
            if (!canDo('inventory.checkin')) {
                throw new InvalidArgumentException('You do not have permission to check equipment in.');
            }
            if ($reservation['status'] !== 'issued') {
                throw new InvalidArgumentException('Only issued reservations can be received.');
            }
            $open = $db->prepare("
                SELECT t.txn_id, t.asset_id, t.is_kit_txn, a.asset_code
                FROM transactions t JOIN assets a ON a.asset_id = t.asset_id
                WHERE t.reservation_id = ? AND t.returned_at IS NULL
                  AND COALESCE(t.condition_in, '') <> 'missing'
                FOR UPDATE
            ");
            $open->execute([$reservationId]);
            $openTxns = $open->fetchAll();
            $picked = $_POST['rx'] ?? [];
            $done = 0; $missing = 0;
            foreach ($openTxns as $txn) {
                $row = $picked[$txn['txn_id']] ?? null;
                if (!is_array($row) || ($row['sel'] ?? '') !== '1') continue;
                $cond = $row['condition'] ?? 'good';
                $note = trim($row['note'] ?? '');
                if (!in_array($cond, ['excellent', 'good', 'fair', 'damaged', 'missing'], true)) {
                    throw new InvalidArgumentException('Select a valid return condition.');
                }
                if ($cond === 'missing') {
                    if ($note === '') {
                        throw new InvalidArgumentException('Add a note describing the missing item (' . $txn['asset_code'] . ').');
                    }
                    $db->prepare("UPDATE transactions SET condition_in = 'missing', condition_note = ? WHERE txn_id = ?")
                        ->execute([$note, $txn['txn_id']]);
                    $db->prepare("UPDATE assets SET status = 'missing' WHERE asset_id = ?")->execute([$txn['asset_id']]);
                    if ($txn['is_kit_txn'] && $reservation['kit_id']) {
                        $db->prepare("UPDATE kits SET status = 'incomplete' WHERE kit_id = ?")->execute([$reservation['kit_id']]);
                    }
                    reservationLog($db, $reservationId, 'component_missing', (int) $admin['admin_id'], $txn['asset_code'] . ': ' . $note);
                    $missing++;
                    continue;
                }
                $db->prepare("
                    UPDATE transactions
                    SET returned_at = UTC_TIMESTAMP(), returned_by = ?, condition_in = ?, condition_note = ?,
                        fine_amount = 0, fine_paid = 1
                    WHERE txn_id = ?
                ")->execute([$admin['admin_id'], $cond, $note ?: null, $txn['txn_id']]);
                $db->prepare("UPDATE assets SET status = 'available', condition_rating = ? WHERE asset_id = ?")
                    ->execute([$cond, $txn['asset_id']]);
                reservationLog($db, $reservationId, 'component_returned', (int) $admin['admin_id'],
                    $txn['asset_code'] . ' returned in ' . $cond . ' condition');
                $done++;
            }
            if (!$done && !$missing) {
                throw new InvalidArgumentException('Select at least one item to receive.');
            }
            completeReservationReturn($db, $reservationId, (int) $admin['admin_id']);
            if ($reservation['kit_id']) {
                $left = $db->prepare("SELECT COUNT(*) FROM transactions WHERE reservation_id = ? AND returned_at IS NULL");
                $left->execute([$reservationId]);
                if ((int) $left->fetchColumn() === 0) {
                    $db->prepare("UPDATE kits SET status = 'available' WHERE kit_id = ?")->execute([$reservation['kit_id']]);
                }
            }
            $receiveMsg = $done . ' item(s) received' . ($missing ? ', ' . $missing . ' marked missing' : '') . '.';
        } elseif ($action === 'expire') {
            if (!in_array($reservation['status'], ['pending', 'approved'], true)
                || $reservation['requested_end'] > gmdate('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('Only a pending or approved request whose return time has passed can be expired.');
            }
            $db->prepare("UPDATE reservations SET status = 'expired' WHERE reservation_id = ?")
                ->execute([$reservationId]);
            reservationLog($db, $reservationId, 'expired', (int) $admin['admin_id']);
        }

        $db->commit();
        $success = [
            'approve' => 'Reservation approved.',
            'reject' => 'Reservation rejected.',
            'issue' => 'Equipment issued and linked to the existing transaction log.',
            'cancel' => 'Reservation cancelled.',
            'expire' => 'Reservation expired.',
            'receive' => $receiveMsg ?? 'Items received.',
        ];
        setFlash('success', $success[$action]);
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Inventory reservation action failed: ' . $e->getMessage());
        setFlash('error', 'The reservation action could not be completed. No changes were saved.');
    }
    header('Location: ' . APP_ROOT . '/admin/reservation.php?id=' . (int) ($reservationId ?: 0));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$st = $db->prepare("
    SELECT r.*, COALESCE(r.kit_name, k.name) AS kit_label, k.kit_code,
           u.full_name, COALESCE(fsx.student_id, u.student_id) AS student_id, u.email, u.phone, u.department, u.fines_owed, u.is_active,
           t.name AS tier_name, g.name AS group_name, g.year_label,
           dcs.full_name AS decided_by_name, iss.full_name AS issued_by_name
    FROM reservations r
    JOIN users u ON u.user_id = r.user_id
    LEFT JOIN tiers t ON t.tier_id = u.tier_id
    LEFT JOIN kits k ON k.kit_id = r.kit_id
    LEFT JOIN fee_students fsx ON fsx.fee_student_id = COALESCE(r.fee_student_id, u.fee_student_id)
    LEFT JOIN fee_students fs ON fs.fee_student_id = u.fee_student_id
    LEFT JOIN fee_groups g ON g.group_id = fs.group_id
    LEFT JOIN admin_users dcs ON dcs.admin_id = r.decision_by
    LEFT JOIN admin_users iss ON iss.admin_id = r.issued_by
    WHERE r.reservation_id = ?
");
$st->execute([$id]);
$r = $st->fetch();
if (!$r) {
    setFlash('error', 'Reservation not found.');
    header('Location: ' . APP_ROOT . '/admin/reservations.php');
    exit;
}

$st = $db->prepare("
    SELECT ri.asset_id, ri.asset_code_snapshot, ri.asset_name_snapshot, a.status AS asset_status,
           (SELECT t.txn_id FROM transactions t WHERE t.reservation_id = ri.reservation_id AND t.asset_id = ri.asset_id
            ORDER BY t.txn_id DESC LIMIT 1) AS txn_id
    FROM reservation_items ri
    JOIN assets a ON a.asset_id = ri.asset_id
    WHERE ri.reservation_id = ?
    ORDER BY ri.asset_name_snapshot
");
$st->execute([$id]);
$items = $st->fetchAll();
$txns = [];
$st = $db->prepare("SELECT * FROM transactions WHERE reservation_id = ?");
$st->execute([$id]);
foreach ($st->fetchAll() as $tx) {
    $txns[$tx['txn_id']] = $tx;
}
$events = $db->prepare("
    SELECT e.event_type, e.detail, e.created_at, a.full_name AS staff_name
    FROM reservation_events e LEFT JOIN admin_users a ON a.admin_id = e.staff_id
    WHERE e.reservation_id = ? ORDER BY e.created_at DESC, e.event_id DESC
");
$events->execute([$id]);
$events = $events->fetchAll();

$itemState = function (array $it) use ($txns, $r): array {
    $tx = $it['txn_id'] ? ($txns[$it['txn_id']] ?? null) : null;
    if (!$tx) return [$r['status'] === 'returned' ? 'returned' : 'booked', null];
    if ($tx['returned_at']) return ['returned', $tx];
    if ($tx['condition_in'] === 'missing') return ['missing', $tx];
    return ['issued', $tx];
};
$openItems = array_filter($items, fn($it) => $itemState($it)[0] === 'issued');
$canReceive = canDo('inventory.checkin') && $r['status'] === 'issued' && $openItems;
$now = gmdate('Y-m-d H:i:s');
$token = htmlspecialchars(csrfToken());

$pageTitle = 'Reservation ' . $r['booking_reference'];
$activePage = 'reservations';
include __DIR__ . '/partials/header.php';
$e = fn($v) => htmlspecialchars((string) $v);
?>

<link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/reservations.css?v=<?= filemtime(__DIR__ . '/../assets/css/reservations.css') ?>">
<div class="page-header">
    <div>
        <h1 class="page-title rv-title"><?= $e($r['booking_reference']) ?>
            <span class="badge badge-<?= $e($r['status']) ?>"><?= $e(ucfirst($r['status'])) ?></span></h1>
        <p class="page-subtitle"><?= $e(reservationDisplayTitle($r)) ?> · submitted <?= $e(reservationFormatLocalDateTime($r['submitted_at'])) ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= APP_ROOT ?>/admin/reservations.php">&larr; All reservations</a>
</div>

<div class="card rv-card">
    <h3 class="rv-h">Student</h3>
    <div class="rv-grid">
        <div class="rv-field"><small>Name</small><div><strong><?= $e(formatPersonName($r['full_name'])) ?></strong></div></div>
        <div class="rv-field"><small>Admission no.</small><div class="mono"><?= $e($r['student_id']) ?></div></div>
        <div class="rv-field"><small>Programme / class</small><div><?= $e(formatTitleCase((string) ($r['programme_snapshot'] ?: $r['department'] ?: ''))) ?><?= $r['group_name'] ? ' · ' . $e(formatTitleCase($r['group_name'])) : '' ?><?= $r['year_label'] ? ' (' . $e($r['year_label']) . ')' : '' ?></div></div>
        <div class="rv-field"><small>Tier</small><div><?= $e($r['tier_name'] ?: '—') ?></div></div>
        <div class="rv-field"><small>Phone</small><div><?= $e($r['phone'] ?: '—') ?></div></div>
        <div class="rv-field"><small>Email</small><div><?= $e($r['email'] ?: '—') ?></div></div>
        <div class="rv-field"><small>Fines owed</small><div><?= (float) $r['fines_owed'] > 0 ? '<span style="color:var(--danger,#c0392b)">' . formatCurrency($r['fines_owed'], true) . '</span>' : formatCurrency(0, true) ?></div></div>
        <div class="rv-field"><small>Account</small><div><?= $r['is_active'] ? 'Active' : 'Inactive' ?></div></div>
    </div>
</div>

<div class="card rv-card">
    <h3 class="rv-h">Booking</h3>
    <div class="rv-grid">
        <div class="rv-field"><small>Pickup</small><div class="mono"><?= $e(reservationFormatLocalDateTime($r['requested_start'])) ?></div></div>
        <div class="rv-field"><small><?= $r['status'] === 'issued' || $r['status'] === 'returned' ? 'Due' : 'Default due' ?></small><div class="mono"><?= $e(reservationFormatLocalDateTime($r['requested_end'])) ?></div></div>
        <?php if ($r['issued_at']): ?><div class="rv-field"><small>Issued</small><div class="mono"><?= $e(reservationFormatLocalDateTime($r['issued_at'])) ?><?= $r['issued_by_name'] ? ' · ' . $e($r['issued_by_name']) : '' ?></div></div><?php endif; ?>
        <?php if ($r['returned_at']): ?><div class="rv-field"><small>Returned</small><div class="mono"><?= $e(reservationFormatLocalDateTime($r['returned_at'])) ?></div></div><?php endif; ?>
        <div class="rv-field rv-wide"><small>Purpose</small><div><?= $e($r['purpose']) ?></div></div>
        <?php if ($r['rejection_reason']): ?><div class="rv-field rv-wide"><small>Rejection reason</small><div><?= $e($r['rejection_reason']) ?></div></div><?php endif; ?>
    </div>
</div>

<?php if ($canReceive): ?>
<form method="post" onsubmit="return confirm('Confirm the selected items were received?')">
    <input type="hidden" name="csrf_token" value="<?= $token ?>">
    <input type="hidden" name="action" value="receive">
    <input type="hidden" name="reservation_id" value="<?= $id ?>">
<?php endif; ?>
<div class="card rv-card">
    <h3 class="rv-h">Equipment booked (<?= pluralizeCount(count($items), 'item') ?>)</h3>
    <div style="overflow-x:auto">
    <table class="data-table rv-table">
        <thead><tr><th>Code</th><th>Item</th><th>State</th><?php if ($canReceive): ?><th>Receive</th><th>Condition</th><th>Note</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($items as $it): [$state, $tx] = $itemState($it); ?>
            <tr>
                <td class="mono"><?= $e($it['asset_code_snapshot']) ?></td>
                <td><?= $e($it['asset_name_snapshot']) ?></td>
                <td>
                    <span class="badge badge-<?= $state === 'issued' ? 'issued' : ($state === 'returned' ? 'returned' : ($state === 'missing' ? 'rejected' : 'approved')) ?>"><?= $e(ucfirst($state)) ?></span>
                    <?php if ($tx && $tx['returned_at']): ?><div class="rv-sub"><?= $e(ucfirst((string) $tx['condition_in'])) ?></div><?php endif; ?>
                    <?php if ($state === 'missing' && $tx['condition_note']): ?><div class="rv-sub"><?= $e($tx['condition_note']) ?></div><?php endif; ?>
                </td>
                <?php if ($canReceive): ?>
                    <?php if ($state === 'issued'): $tid = (int) $it['txn_id']; ?>
                        <td><input type="checkbox" name="rx[<?= $tid ?>][sel]" value="1" checked></td>
                        <td>
                            <select class="form-control" name="rx[<?= $tid ?>][condition]">
                                <?php foreach (['excellent', 'good', 'fair', 'damaged', 'missing'] as $c): ?>
                                    <option value="<?= $c ?>" <?= $c === 'good' ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input class="form-control" name="rx[<?= $tid ?>][note]" maxlength="255" placeholder="Required if missing"></td>
                    <?php else: ?><td colspan="3" style="color:var(--text-muted)">—</td><?php endif; ?>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($canReceive): ?>
        <div class="rv-foot"><button class="btn btn-primary" type="submit">Receive selected items</button></div>
    <?php endif; ?>
</div>
<?php if ($canReceive): ?></form><?php endif; ?>

<?php if ($canProcess): ?>
<div class="card rv-card">
    <h3 class="rv-h">Actions</h3>
    <?php if ($r['status'] === 'pending'): ?>
        <div class="rv-actions">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $token ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="reservation_id" value="<?= $id ?>">
                <label>
                    <input type="checkbox" name="identity_verified" value="1" required> Student ID checked
                </label>
                <button class="btn btn-success" type="submit">Approve</button>
            </form>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $token ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="reservation_id" value="<?= $id ?>">
                <input class="form-control" name="reason" maxlength="500" placeholder="Rejection reason" required>
                <button class="btn btn-danger" type="submit">Reject</button>
            </form>
        </div>
    <?php elseif ($r['status'] === 'approved' && $r['requested_end'] > $now): ?>
        <div class="rv-actions">
            <form method="post" onsubmit="return confirm('Confirm physical handover and issue these items?')">
                <input type="hidden" name="csrf_token" value="<?= $token ?>">
                <input type="hidden" name="action" value="issue">
                <input type="hidden" name="reservation_id" value="<?= $id ?>">
                <label>Due back (confirm with student)</label>
                <input class="form-control" type="datetime-local" name="due_at" required
                       value="<?= $e(reservationLocalInputValue($r['requested_end'])) ?>">
                <button class="btn btn-primary" type="submit">Issue equipment</button>
            </form>
            <form method="post" onsubmit="return confirm('Cancel this approved booking?')">
                <input type="hidden" name="csrf_token" value="<?= $token ?>">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="reservation_id" value="<?= $id ?>">
                <button class="btn btn-ghost" type="submit">Cancel booking</button>
            </form>
        </div>
    <?php elseif (in_array($r['status'], ['pending', 'approved'], true)): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $token ?>">
            <input type="hidden" name="action" value="expire">
            <input type="hidden" name="reservation_id" value="<?= $id ?>">
            <p>The collection window has passed.</p>
            <button class="btn btn-ghost" type="submit">Mark expired</button>
        </form>
    <?php elseif ($r['status'] === 'issued'): ?>
        <p style="color:var(--text-muted)">Equipment is out. Use “Receive selected items” above to check it back in.</p>
    <?php else: ?>
        <p style="color:var(--text-muted)">No further actions for this reservation.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card rv-card">
    <h3 class="rv-h">History</h3>
    <ul class="rv-history">
    <?php foreach ($events as $ev): ?>
        <li>
            <span class="when mono"><?= $e(reservationFormatLocalDateTime($ev['created_at'])) ?></span>
            <strong><?= $e(str_replace('_', ' ', ucfirst($ev['event_type']))) ?></strong>
            <?php if ($ev['staff_name']): ?><span><?= $e($ev['staff_name']) ?></span><?php endif; ?>
            <?php if ($ev['detail']): ?><span style="color:var(--text-muted)"><?= $e($ev['detail']) ?></span><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
