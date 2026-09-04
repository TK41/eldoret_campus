<?php
// ============================================================
// admin/transactions.php
// Transactions with full KIT support:
//   - Kits appear as ONE row in the table (not one per component)
//   - Click ▶ to expand and see every item in the kit
//   - Kit check-out: creates one transaction PER component,
//     all sharing the same kit_group_id (links them together)
//   - Kit check-in: returns ALL components in a single action
//   - Single assets still work exactly as before
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

// Suppress PHP notices/warnings — prevents them from corrupting inline <script> blocks
ini_set('display_errors', 0);
error_reporting(0);

$pageTitle  = 'Transactions';
$activePage = 'transactions';
$db         = getDB();
$admin      = getCurrentAdmin();
$errors     = [];

// ============================================================
// ONE-TIME MIGRATION: add kit columns if they don't exist yet
// Safe to run every page load — silently ignores if present
// ============================================================
try {
    $db->exec("ALTER TABLE transactions ADD COLUMN kit_group_id VARCHAR(36) NULL DEFAULT NULL");
} catch (PDOException $e) { /* already exists — ignore */ }
try {
    $db->exec("ALTER TABLE transactions ADD COLUMN is_kit_txn TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) { /* already exists — ignore */ }
try {
    $db->exec("CREATE INDEX idx_kit_group ON transactions (kit_group_id)");
} catch (PDOException $e) { /* already exists — ignore */ }

// ============================================================
// HELPER: calculate due date from asset type + user's tier
// ============================================================
function calcDueDate(PDO $db, string $assetType, int $userId): string {
    $tier = $db->prepare("
        SELECT t.book_loan_days, t.equip_loan_hrs
        FROM users u JOIN tiers t ON u.tier_id = t.tier_id
        WHERE u.user_id = ?
    ");
    $tier->execute([$userId]);
    $tier = $tier->fetch();

    $now = new DateTime();
    if ($assetType === 'book') {
        $now->modify('+' . ($tier['book_loan_days'] ?? 14) . ' days');
    } else {
        $now->modify('+' . ($tier['equip_loan_hrs'] ?? 24) . ' hours');
    }
    return $now->format('Y-m-d H:i:s');
}

// ============================================================
// HELPER: validate a student can borrow
// Returns array of error strings (empty = OK)
// ============================================================
function validateStudent(PDO $db, int $userId, bool $needKit = false): array {
    $errs = [];
    $row  = $db->prepare("
        SELECT u.is_active, u.fines_owed, t.can_kit
        FROM users u JOIN tiers t ON u.tier_id = t.tier_id
        WHERE u.user_id = ?
    ");
    $row->execute([$userId]);
    $row = $row->fetch();
    if (!$row || !$row['is_active'])     $errs[] = 'Student account is suspended.';
    if ($row && $row['fines_owed'] > 0)  $errs[] = 'Student has KES ' . number_format($row['fines_owed'], 2) . ' outstanding fines. Clear fines first.';
    if ($needKit && $row && !$row['can_kit']) $errs[] = "Student's tier does not allow kit borrowing. Upgrade their tier first.";
    return $errs;
}

// ============================================================
// POST: Check out a SINGLE asset (non-kit)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout_single') {

    $asset_id      = intval($_POST['asset_id']      ?? 0);
    $user_id       = intval($_POST['user_id']       ?? 0);
    $condition_out = $_POST['condition_out']         ?? 'good';
    $cond_note     = trim($_POST['condition_note']   ?? '');

    if (!$asset_id) $errors[] = 'Please select an asset.';
    if (!$user_id)  $errors[] = 'Please select a student.';

    if (empty($errors)) {
        // Confirm asset is available
        $asset = $db->prepare("SELECT status, name, asset_type FROM assets WHERE asset_id = ?");
        $asset->execute([$asset_id]);
        $asset = $asset->fetch();
        if (!$asset || $asset['status'] !== 'available') {
            $errors[] = "Asset is not available (status: " . ($asset['status'] ?? 'not found') . ").";
        }
        $errors = array_merge($errors, validateStudent($db, $user_id));
    }

    if (empty($errors)) {
        $dueAt = calcDueDate($db, $asset['asset_type'], $user_id);

        // Insert transaction — is_kit_txn = 0 for standalone items
        $db->prepare("
            INSERT INTO transactions
                (asset_id, user_id, staff_id, checkout_at, due_at,
                 condition_out, condition_note, is_kit_txn)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, 0)
        ")->execute([$asset_id, $user_id, $admin['admin_id'], $dueAt, $condition_out, $cond_note ?: null]);

        // Mark asset checked out
        $db->prepare("UPDATE assets SET status='checked_out' WHERE asset_id=?")->execute([$asset_id]);

        // Schedule due-soon notification
        $notifAt = (new DateTime($dueAt))->modify('-4 hours')->format('Y-m-d H:i:s');
        $db->prepare("
            INSERT INTO notifications (user_id, type, channel, subject, body, scheduled_at)
            VALUES (?, 'due_soon', 'email', 'Item due soon — please return on time',
                    CONCAT('Your item is due at ', ?), ?)
        ")->execute([$user_id, $dueAt, $notifAt]);

        setFlash('success', "✓ Checked out. Due: " . date('d M Y H:i', strtotime($dueAt)));
        header('Location: ' . APP_ROOT . '/admin/transactions.php');
        exit;
    }
}

// ============================================================
// POST: Check out an entire KIT
// Creates one transaction per component, ALL sharing the same
// kit_group_id — this is what makes them a single "unit"
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout_kit') {

    $kit_id        = intval($_POST['kit_id']       ?? 0);
    $user_id       = intval($_POST['user_id']      ?? 0);
    $condition_out = $_POST['condition_out']        ?? 'good';
    $cond_note     = trim($_POST['condition_note']  ?? '');

    if (!$kit_id)  $errors[] = 'Please select a kit.';
    if (!$user_id) $errors[] = 'Please select a student.';

    if (empty($errors)) {
        $errors = array_merge($errors, validateStudent($db, $user_id, true));
    }

    if (empty($errors)) {
        // Load all components for the kit
        $components = $db->prepare("
            SELECT a.asset_id, a.name, a.asset_code, a.status
            FROM kit_components kc
            JOIN assets a ON kc.asset_id = a.asset_id
            WHERE kc.kit_id = ?
        ");
        $components->execute([$kit_id]);
        $components = $components->fetchAll();

        if (empty($components)) {
            $errors[] = 'This kit has no components. Add items to the kit first.';
        }

        // Make sure every component is available
        $notReady = array_filter($components, fn($c) => $c['status'] !== 'available');
        if (!empty($notReady)) {
            $names = implode(', ', array_column($notReady, 'name'));
            $errors[] = "Some components are not available: $names";
        }
    }

    if (empty($errors)) {
        // Generate a unique kit_group_id — all component transactions will share this
        $kitGroupId = date('Ymd-His') . '-' . sprintf('%04x', mt_rand(0, 0xffff));
        $dueAt      = calcDueDate($db, 'equipment', $user_id);

        $kitInfo = $db->prepare("SELECT name, kit_code FROM kits WHERE kit_id=?");
        $kitInfo->execute([$kit_id]);
        $kitInfo = $kitInfo->fetch();

        // Insert one transaction row per component, linked by kit_group_id
        $insertStmt = $db->prepare("
            INSERT INTO transactions
                (asset_id, user_id, staff_id, checkout_at, due_at,
                 condition_out, condition_note, is_kit_txn, kit_group_id)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, 1, ?)
        ");

        foreach ($components as $comp) {
            $insertStmt->execute([
                $comp['asset_id'],
                $user_id,
                $admin['admin_id'],
                $dueAt,
                $condition_out,
                $cond_note ?: null,
                $kitGroupId,
            ]);
            // Mark each component as checked out
            $db->prepare("UPDATE assets SET status='checked_out' WHERE asset_id=?")
               ->execute([$comp['asset_id']]);
        }

        // Mark the kit itself as checked out
        $db->prepare("UPDATE kits SET status='checked_out' WHERE kit_id=?")->execute([$kit_id]);

        // ONE notification for the whole kit
        $notifAt = (new DateTime($dueAt))->modify('-4 hours')->format('Y-m-d H:i:s');
        $db->prepare("
            INSERT INTO notifications (user_id, type, channel, subject, body, scheduled_at)
            VALUES (?, 'due_soon', 'email',
                    CONCAT('Kit due soon: ', ?),
                    CONCAT('Your kit \"', ?, '\" (', ?, ' items) is due at ', ?), ?)
        ")->execute([
            $user_id,
            $kitInfo['kit_code'],
            $kitInfo['name'],
            count($components),
            $dueAt,
            $notifAt,
        ]);

        setFlash('success', "✓ Kit <strong>{$kitInfo['name']}</strong> (" . count($components) . " items) checked out. Due: " . date('d M Y H:i', strtotime($dueAt)));
        header('Location: ' . APP_ROOT . '/admin/transactions.php');
        exit;
    }
}

// ============================================================
// POST: Return (check-in) an entire KIT at once
// Finds all active transactions with matching kit_group_id
// and marks them all returned in one action
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin_kit') {

    $kit_group_id = trim($_POST['kit_group_id'] ?? '');
    $condition_in = $_POST['condition_in']       ?? 'good';
    $cond_note    = trim($_POST['condition_note'] ?? '');
    $fine_amount  = floatval($_POST['fine_amount'] ?? 0);
    $fine_paid    = isset($_POST['fine_paid']) ? 1 : 0;

    // Kits are equipment loans in this system, so no overdue fines apply on return.
    $fine_amount = 0;
    $fine_paid   = 1;

    if (empty($kit_group_id)) {
        $errors[] = 'Invalid kit group reference.';
    } else {
        // Fetch every unreturned transaction in this kit group
        $kitTxns = $db->prepare("
            SELECT t.txn_id, t.user_id, t.asset_id, a.kit_id
            FROM transactions t
            JOIN assets a ON t.asset_id = a.asset_id
            WHERE t.kit_group_id = ? AND t.returned_at IS NULL
        ");
        $kitTxns->execute([$kit_group_id]);
        $kitTxns = $kitTxns->fetchAll();

        if (empty($kitTxns)) {
            $errors[] = 'No active transactions found for this kit — already returned?';
        }
    }

    if (empty($errors)) {
        $userId = $kitTxns[0]['user_id'];

        // Distribute fine evenly across components
        $perItemFine = count($kitTxns) > 0 ? round($fine_amount / count($kitTxns), 2) : 0;

        $updateTxn = $db->prepare("
            UPDATE transactions
            SET returned_at    = NOW(),
                condition_in   = ?,
                condition_note = ?,
                fine_amount    = ?,
                fine_paid      = ?
            WHERE txn_id = ?
        ");

        foreach ($kitTxns as $txn) {
            $updateTxn->execute([
                $condition_in,
                $cond_note ?: null,
                $perItemFine,
                $fine_paid,
                $txn['txn_id'],
            ]);
            // Free the asset
            $db->prepare("UPDATE assets SET status='available', condition_rating=? WHERE asset_id=?")
               ->execute([$condition_in, $txn['asset_id']]);
        }

        // Set kit back to available — find kit_id via first component
        $kitIdRow = $db->prepare("SELECT kit_id FROM assets WHERE asset_id=? AND kit_id IS NOT NULL");
        $kitIdRow->execute([$kitTxns[0]['asset_id']]);
        $kitIdRow = $kitIdRow->fetch();
        if ($kitIdRow) {
            $db->prepare("UPDATE kits SET status='available' WHERE kit_id=?")
               ->execute([$kitIdRow['kit_id']]);
        }

        // Update student fine balance if not paid on the spot
        if ($fine_amount > 0 && !$fine_paid) {
            $db->prepare("UPDATE users SET fines_owed = fines_owed + ? WHERE user_id=?")
               ->execute([$fine_amount, $userId]);
        }

        setFlash('success', "✓ Kit returned — " . count($kitTxns) . " component(s) checked in.");
        header('Location: ' . APP_ROOT . '/admin/transactions.php');
        exit;
    }
}

// ============================================================
// POST: Return a single asset
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin_single') {

    $txn_id       = intval($_POST['txn_id']       ?? 0);
    $condition_in = $_POST['condition_in']         ?? 'good';
    $cond_note    = trim($_POST['condition_note']  ?? '');
    $fine_amount  = floatval($_POST['fine_amount'] ?? 0);
    $fine_paid    = isset($_POST['fine_paid']) ? 1 : 0;

    $txnRow = $db->prepare("
        SELECT t.*, a.asset_id FROM transactions t
        JOIN assets a ON t.asset_id = a.asset_id
        WHERE t.txn_id = ? AND t.returned_at IS NULL
    ");
    $txnRow->execute([$txn_id]);
    $txnRow = $txnRow->fetch();

    if (!$txnRow) {
        $errors[] = 'Transaction not found or already returned.';
    } else {
        if ($txnRow['asset_type'] !== 'book') {
            // No fines should be charged for equipment returns
            $fine_amount = 0;
            $fine_paid   = 1;
        }

        $db->prepare("
            UPDATE transactions
            SET returned_at=NOW(), condition_in=?, condition_note=?, fine_amount=?, fine_paid=?
            WHERE txn_id=?
        ")->execute([$condition_in, $cond_note ?: null, $fine_amount, $fine_paid, $txn_id]);

        $db->prepare("UPDATE assets SET status='available', condition_rating=? WHERE asset_id=?")
           ->execute([$condition_in, $txnRow['asset_id']]);

        if ($fine_amount > 0 && !$fine_paid) {
            $db->prepare("UPDATE users SET fines_owed = fines_owed + ? WHERE user_id=?")
               ->execute([$fine_amount, $txnRow['user_id']]);
        }

        setFlash('success', '✓ Item returned and condition logged.');
        header('Location: ' . APP_ROOT . '/admin/transactions.php');
        exit;
    }
}

// ============================================================
// POST: Clear user fines
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_fine') {
    $uid = intval($_POST['user_id'] ?? 0);
    $db->prepare("UPDATE users SET fines_owed=0 WHERE user_id=?")->execute([$uid]);
    $db->prepare("UPDATE transactions SET fine_paid=1 WHERE user_id=? AND fine_paid=0")->execute([$uid]);
    setFlash('success', 'Fines cleared for student.');
    header('Location: ' . APP_ROOT . '/admin/transactions.php');
    exit;
}

// ============================================================
// FETCH: All transactions for display
// ============================================================
$filter_status = $_GET['status']  ?? '';
$filter_type   = $_GET['type']    ?? '';
$filter_user   = intval($_GET['user_id'] ?? 0);

$where  = ['1=1'];
$params = [];

if ($filter_user > 0)              { $where[] = "t.user_id = ?";                              $params[] = $filter_user; }
if ($filter_status === 'active')   { $where[] = "t.returned_at IS NULL AND t.due_at >= NOW()"; }
if ($filter_status === 'overdue')  { $where[] = "t.returned_at IS NULL AND t.due_at < NOW()";  }
if ($filter_status === 'returned') { $where[] = "t.returned_at IS NOT NULL";                   }
if ($filter_type === 'book')       { $where[] = "a.asset_type = 'book'";                       }
if ($filter_type === 'equipment')  { $where[] = "a.asset_type = 'equipment'";                  }

$whereSQL = implode(' AND ', $where);

$allTxns = $db->prepare("
    SELECT
        t.*,
        a.name        AS asset_name,
        a.asset_code,
        a.asset_type,
        k.name        AS kit_name,
        k.kit_code,
        u.full_name   AS borrower_name,
        u.student_id  AS borrower_sid,
        s.full_name   AS staff_name,
        CASE
            WHEN t.returned_at IS NOT NULL THEN 'returned'
            WHEN t.due_at < NOW()          THEN 'overdue'
            ELSE 'active'
        END AS txn_status,
        CASE
            WHEN t.returned_at IS NULL AND t.due_at < NOW()
            THEN TIMESTAMPDIFF(HOUR, t.due_at, NOW())
            ELSE 0
        END AS hours_overdue
    FROM transactions t
    JOIN assets      a  ON t.asset_id = a.asset_id
    JOIN users       u  ON t.user_id  = u.user_id
    JOIN admin_users s  ON t.staff_id = s.admin_id
    LEFT JOIN kits   k  ON a.kit_id   = k.kit_id
    WHERE $whereSQL
    ORDER BY t.checkout_at DESC
    LIMIT 300
");
$allTxns->execute($params);
$allTxns = $allTxns->fetchAll();

// ============================================================
// GROUP kit transactions so each kit appears as ONE display row
// Non-kit transactions stay as individual rows
// ============================================================
$displayRows   = [];   // final rows for the table
$seenKitGroups = [];   // kit_group_id => index in $displayRows

foreach ($allTxns as $txn) {

    if ($txn['is_kit_txn'] && !empty($txn['kit_group_id'])) {

        $gid = $txn['kit_group_id'];

        if (isset($seenKitGroups[$gid])) {
            // Already have a row for this kit — just add this component to it
            $displayRows[$seenKitGroups[$gid]]['components'][] = $txn;
            // Keep the worst status (overdue > active > returned)
            $priority = ['returned'=>0, 'active'=>1, 'overdue'=>2];
            if (($priority[$txn['txn_status']] ?? 0) > ($priority[$displayRows[$seenKitGroups[$gid]]['txn_status']] ?? 0)) {
                $displayRows[$seenKitGroups[$gid]]['txn_status']    = $txn['txn_status'];
                $displayRows[$seenKitGroups[$gid]]['hours_overdue'] = max($displayRows[$seenKitGroups[$gid]]['hours_overdue'], $txn['hours_overdue']);
            }
        } else {
            // First time we see this kit group — create the parent row
            $idx = count($displayRows);
            $seenKitGroups[$gid] = $idx;

            $displayRows[] = [
                'is_kit'        => true,
                'kit_group_id'  => $gid,
                'kit_name'      => $txn['kit_name']      ?? 'Kit',
                'kit_code'      => $txn['kit_code']      ?? '—',
                'borrower_name' => $txn['borrower_name'],
                'borrower_sid'  => $txn['borrower_sid'],
                'staff_name'    => $txn['staff_name'],
                'checkout_at'   => $txn['checkout_at'],
                'due_at'        => $txn['due_at'],
                'returned_at'   => $txn['returned_at'],
                'txn_status'    => $txn['txn_status'],
                'hours_overdue' => $txn['hours_overdue'],
                'condition_out' => $txn['condition_out'],
                'condition_in'  => $txn['condition_in'],
                'condition_note'=> $txn['condition_note'],
                'user_id'       => $txn['user_id'],
                'components'    => [$txn],
            ];
        }

    } else {
        // Normal standalone transaction row
        $displayRows[] = array_merge(['is_kit' => false], $txn);
    }
}

// Sum fines across components for each kit row
foreach ($displayRows as &$row) {
    if ($row['is_kit']) {
        $row['fine_amount'] = array_sum(array_column($row['components'], 'fine_amount'));
        $row['fine_paid']   = min(array_column($row['components'], 'fine_paid')); // 0 if any unpaid
    }
}
unset($row);

// ============================================================
// Data for the checkout modal dropdowns
// ============================================================

// Available single assets (not part of any kit)
$availableSingles = $db->query("
    SELECT asset_id, asset_code, name, asset_type, condition_rating
    FROM assets
    WHERE status='available' AND kit_id IS NULL
    ORDER BY asset_type, name
")->fetchAll();

// Available kits (all components must be available)
$availableKits = $db->query("
    SELECT k.kit_id, k.kit_code, k.name,
           COUNT(kc.asset_id)                       AS total_components,
           SUM(a.status != 'available')             AS unavailable
    FROM kits k
    LEFT JOIN kit_components kc ON k.kit_id  = kc.kit_id
    LEFT JOIN assets         a  ON kc.asset_id = a.asset_id
    WHERE k.status = 'available'
    GROUP BY k.kit_id
    HAVING unavailable = 0
    ORDER BY k.name
")->fetchAll();

// Component list per kit for the preview panel (keyed by kit_id)
$kitPreviewData = [];
if (!empty($availableKits)) {
    $kitIdList = implode(',', array_map('intval', array_column($availableKits, 'kit_id')));
    $prevRows  = $db->query("
        SELECT kc.kit_id, a.asset_code, a.name, a.condition_rating
        FROM kit_components kc
        JOIN assets a ON kc.asset_id = a.asset_id
        WHERE kc.kit_id IN ($kitIdList)
        ORDER BY a.name
    ")->fetchAll();
    foreach ($prevRows as $p) {
        $kitPreviewData[$p['kit_id']][] = [
            'code' => $p['asset_code'],
            'name' => $p['name'],
            'cond' => $p['condition_rating'],
        ];
    }
}

// Active students for dropdowns
$activeUsers = $db->query("
    SELECT u.user_id, u.student_id, u.full_name, u.fines_owed,
           t.name AS tier_name, t.can_kit
    FROM users u JOIN tiers t ON u.tier_id = t.tier_id
    WHERE u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

// Fine rate settings
$fineRates = $db->query("
    SELECT setting_key, setting_value FROM settings
    WHERE setting_key IN ('equip_fine_per_hour','book_fine_per_day')
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Stat counts from display rows
$activeCount   = count(array_filter($displayRows, fn($r) => $r['txn_status'] === 'active'));
$overdueCount  = count(array_filter($displayRows, fn($r) => $r['txn_status'] === 'overdue'));
$returnedCount = count(array_filter($displayRows, fn($r) => $r['txn_status'] === 'returned'));

include __DIR__ . '/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Transactions</h1>
        <p class="page-subtitle">Kits appear as one row — click ▶ to expand components</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="openModal('checkout-modal')">📤 Check Out</button>
        <button class="btn btn-success" onclick="openModal('return-picker-modal')">↩ Check In</button>
    </div>
</div>

<!-- Stat cards -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card accent-blue">
        <div class="stat-label">Active Loans</div>
        <div class="stat-value"><?= $activeCount ?></div>
        <div class="stat-sub">Items &amp; kits out</div>
    </div>
    <div class="stat-card accent-red">
        <div class="stat-label">Overdue</div>
        <div class="stat-value"><?= $overdueCount ?></div>
        <div class="stat-sub">Past due date</div>
    </div>
    <div class="stat-card accent-green">
        <div class="stat-label">Returned</div>
        <div class="stat-value"><?= $returnedCount ?></div>
        <div class="stat-sub">In current view</div>
    </div>
    <div class="stat-card accent-orange">
        <div class="stat-label">Kits Available</div>
        <div class="stat-value"><?= count($availableKits) ?></div>
        <div class="stat-sub"><?= count($availableSingles) ?> single items too</div>
    </div>
</div>

<!-- Filter bar -->
<div class="filter-bar card" style="margin-bottom:20px">
    <form method="GET" class="filter-form">
        <select name="status" class="form-control filter-select">
            <option value="">All Statuses</option>
            <option value="active"   <?= $filter_status==='active'  ?'selected':''?>>Active</option>
            <option value="overdue"  <?= $filter_status==='overdue' ?'selected':''?>>Overdue</option>
            <option value="returned" <?= $filter_status==='returned'?'selected':''?>>Returned</option>
        </select>
        <select name="type" class="form-control filter-select">
            <option value="">All Types</option>
            <option value="equipment" <?= $filter_type==='equipment'?'selected':''?>>Equipment</option>
            <option value="book"      <?= $filter_type==='book'     ?'selected':''?>>Books</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= APP_ROOT ?>/admin/transactions.php" class="btn btn-ghost">Clear</a>
    </form>
</div>

<!-- ============================================================
     MAIN TRANSACTION TABLE
     Kit rows: single row with expand toggle
     Single rows: normal row, no toggle
============================================================ -->
<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:38px"></th><!-- expand arrow -->
                    <th>Asset / Kit</th>
                    <th>Borrower</th>
                    <th>Checked Out</th>
                    <th>Due</th>
                    <th>Returned</th>
                    <th>Condition</th>
                    <th>Fine (KES)</th>
                    <th>Status</th>
                    <th style="min-width:130px;width:130px">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($displayRows)): ?>
                <tr><td colspan="10" class="empty-row">No transactions yet. Use "Check Out" to get started.</td></tr>
            <?php else: ?>

                <?php foreach ($displayRows as $row): ?>

                    <?php if ($row['is_kit']): ?>
                    <!-- ══════════════════════════════════════════════
                         KIT ROW — represents the entire kit as one row
                    ══════════════════════════════════════════════ -->
                    <tr class="kit-row" data-group="<?= htmlspecialchars($row['kit_group_id']) ?>">

                        <!-- Expand / collapse arrow button -->
                        <td style="text-align:center;padding:8px 4px">
                            <button class="expand-btn"
                                    onclick="toggleComponents('<?= htmlspecialchars($row['kit_group_id']) ?>')"
                                    title="Show kit components">
                                <span class="expand-arrow">▶</span>
                            </button>
                        </td>

                        <!-- Kit name, code, item count -->
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <span style="font-size:22px;line-height:1">🎒</span>
                                <div>
                                    <div style="font-weight:700;color:var(--text-primary)">
                                        <?= htmlspecialchars($row['kit_name']) ?>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:6px;margin-top:3px">
                                        <span class="mono" style="font-size:11px;color:var(--text-muted)">
                                            <?= htmlspecialchars($row['kit_code']) ?>
                                        </span>
                                        <span class="badge badge-kit">
                                            Kit · <?= count($row['components']) ?> items
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <!-- Borrower -->
                        <td>
                            <strong><?= htmlspecialchars($row['borrower_name']) ?></strong><br>
                            <span class="mono" style="font-size:11px;color:var(--text-muted)">
                                <?= htmlspecialchars($row['borrower_sid']) ?>
                            </span>
                        </td>

                        <!-- Checked out date -->
                        <td class="mono" style="font-size:11px">
                            <?= date('d M Y H:i', strtotime($row['checkout_at'])) ?>
                        </td>

                        <!-- Due date — red if overdue -->
                        <td class="mono" style="font-size:11px;<?= $row['txn_status']==='overdue'?'color:#dc2626;font-weight:700':'' ?>">
                            <?= date('d M Y H:i', strtotime($row['due_at'])) ?>
                            <?php if ($row['hours_overdue'] > 0): ?>
                                <br><small style="color:#dc2626"><?= $row['hours_overdue'] ?>h overdue</small>
                            <?php endif; ?>
                        </td>

                        <!-- Returned date -->
                        <td class="mono" style="font-size:11px">
                            <?= $row['returned_at']
                                ? date('d M Y H:i', strtotime($row['returned_at']))
                                : '<span style="color:var(--text-muted)">—</span>' ?>
                        </td>

                        <!-- Overall condition -->
                        <td>
                            <?php $cond = $row['condition_in'] ?: $row['condition_out']; ?>
                            <?php if ($cond): ?>
                                <span class="badge badge-condition-<?= $cond ?>"><?= ucfirst($cond) ?></span>
                            <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                            <?php if ($row['condition_note']): ?>
                                <br><small style="color:var(--text-muted);cursor:help"
                                           title="<?= htmlspecialchars($row['condition_note']) ?>">📝 Note</small>
                            <?php endif; ?>
                        </td>

                        <!-- Fine total across all components -->
                        <td class="mono" style="font-size:12px;<?= $row['fine_amount']>0?'color:#dc2626;font-weight:700':'color:var(--text-muted)' ?>">
                            <?= $row['fine_amount'] > 0 ? 'KES '.number_format($row['fine_amount'],2) : '—' ?>
                            <?php if ($row['fine_amount']>0 && $row['fine_paid']): ?>
                                <br><small style="color:#16a34a">Paid</small>
                            <?php endif; ?>
                        </td>

                        <!-- Status badge -->
                        <td>
                            <span class="badge badge-<?= $row['txn_status'] ?>">
                                <?= ucfirst($row['txn_status']) ?>
                            </span>
                        </td>

                        <!-- Return entire kit action -->
                        <td style="white-space:nowrap;width:130px;padding:8px 12px">
                            <?php if ($row['txn_status'] !== 'returned'): ?>
                                <button class="btn btn-success btn-sm"
                                        onclick="openKitCheckin(
                                            '<?= htmlspecialchars($row['kit_group_id']) ?>',
                                            '<?= addslashes($row['kit_name']) ?>',
                                            <?= $row['hours_overdue'] ?>,
                                            <?= count($row['components']) ?>
                                        )">
                                    ↩ Return Kit
                                </button>
                            <?php else: ?>
                                <span style="font-size:12px;color:var(--text-muted)">Returned</span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- ── Hidden expandable component rows ── -->
                    <tr class="components-panel" id="panel-<?= htmlspecialchars($row['kit_group_id']) ?>"
                        style="display:none">
                        <td colspan="10" style="padding:0;border-bottom:2px solid var(--border)">
                            <div class="components-inner">
                                <div class="components-heading">
                                    📦 <strong><?= htmlspecialchars($row['kit_name']) ?></strong>
                                    — <?= count($row['components']) ?> component(s)
                                    <span style="font-weight:400;color:var(--text-muted);margin-left:8px;font-size:11px">
                                        Click ▼ again to collapse
                                    </span>
                                </div>
                                <table class="components-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Item Name</th>
                                            <th>Condition Out</th>
                                            <th>Condition In</th>
                                            <th>Fine</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($row['components'] as $comp): ?>
                                            <tr>
                                                <td class="mono" style="font-size:11px">
                                                    <?= htmlspecialchars($comp['asset_code']) ?>
                                                </td>
                                                <td><?= htmlspecialchars($comp['asset_name']) ?></td>
                                                <td>
                                                    <?php if ($comp['condition_out']): ?>
                                                        <span class="badge badge-condition-<?= $comp['condition_out'] ?>">
                                                            <?= ucfirst($comp['condition_out']) ?>
                                                        </span>
                                                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($comp['condition_in']): ?>
                                                        <span class="badge badge-condition-<?= $comp['condition_in'] ?>">
                                                            <?= ucfirst($comp['condition_in']) ?>
                                                        </span>
                                                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                                                </td>
                                                <td class="mono" style="font-size:11px;color:<?= $comp['fine_amount']>0?'#dc2626':'var(--text-muted)' ?>">
                                                    <?= $comp['fine_amount']>0 ? number_format($comp['fine_amount'],2) : '—' ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $comp['txn_status'] ?>">
                                                        <?= ucfirst($comp['txn_status']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <?php else: ?>
                    <!-- ══════════════════════════════════════════════
                         SINGLE ASSET ROW — normal non-kit transaction
                    ══════════════════════════════════════════════ -->
                    <tr>
                        <td></td><!-- no expand button -->
                        <td>
                            <strong><?= htmlspecialchars($row['asset_name']) ?></strong><br>
                            <span class="mono" style="font-size:11px;color:var(--text-muted)">
                                <?= htmlspecialchars($row['asset_code']) ?>
                            </span>
                            <span class="badge badge-<?= $row['asset_type'] ?>" style="margin-left:4px">
                                <?= ucfirst($row['asset_type']) ?>
                            </span>
                        </td>
                        <td>
                            <?= htmlspecialchars($row['borrower_name']) ?><br>
                            <span class="mono" style="font-size:11px;color:var(--text-muted)">
                                <?= htmlspecialchars($row['borrower_sid']) ?>
                            </span>
                        </td>
                        <td class="mono" style="font-size:11px"><?= date('d M Y H:i', strtotime($row['checkout_at'])) ?></td>
                        <td class="mono" style="font-size:11px;<?= $row['txn_status']==='overdue'?'color:#dc2626;font-weight:700':'' ?>">
                            <?= date('d M Y H:i', strtotime($row['due_at'])) ?>
                            <?php if ($row['hours_overdue'] > 0): ?>
                                <br><small style="color:#dc2626"><?= $row['hours_overdue'] ?>h overdue</small>
                            <?php endif; ?>
                        </td>
                        <td class="mono" style="font-size:11px">
                            <?= $row['returned_at'] ? date('d M Y H:i', strtotime($row['returned_at'])) : '<span style="color:var(--text-muted)">—</span>' ?>
                        </td>
                        <td>
                            <?php $cond = $row['condition_in'] ?: $row['condition_out']; ?>
                            <?php if ($cond): ?><span class="badge badge-condition-<?= $cond ?>"><?= ucfirst($cond) ?></span><?php endif; ?>
                            <?php if ($row['condition_note']): ?><br><small style="color:var(--text-muted);cursor:help" title="<?= htmlspecialchars($row['condition_note']) ?>">📝 Note</small><?php endif; ?>
                        </td>
                        <td class="mono" style="font-size:12px;<?= $row['fine_amount']>0?'color:#dc2626;font-weight:700':'color:var(--text-muted)' ?>">
                            <?= $row['fine_amount']>0 ? number_format($row['fine_amount'],2) : '—' ?>
                            <?= ($row['fine_amount']>0 && $row['fine_paid']) ? '<br><small style="color:#16a34a">Paid</small>' : '' ?>
                        </td>
                        <td><span class="badge badge-<?= $row['txn_status'] ?>"><?= ucfirst($row['txn_status']) ?></span></td>
                        <td style="white-space:nowrap;width:130px;padding:8px 12px">
                            <?php if ($row['txn_status'] !== 'returned'): ?>
                                <button class="btn btn-success btn-sm"
                                        style="white-space:nowrap;min-width:80px"
                                        onclick="openSingleCheckin(<?= $row['txn_id'] ?>, '<?= addslashes($row['asset_name']) ?>', <?= $row['hours_overdue'] ?>, '<?= $row['asset_type'] ?>')">
                                    ↩ Return
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================================
     CHECK-OUT MODAL  (two tabs: Kit | Single Item)
============================================================ -->
<div class="modal-overlay" id="checkout-modal">
    <div class="modal" style="width:560px">
        <div class="modal-header">
            <h2 class="modal-title">📤 New Check-Out</h2>
            <button class="modal-close" onclick="closeModal('checkout-modal')">×</button>
        </div>
        <div class="modal-body">

            <!-- Tab buttons -->
            <div class="co-tabs">
                <button type="button" class="co-tab active" onclick="switchTab('kit',this)">
                    🎒 Check Out Kit
                </button>
                <button type="button" class="co-tab" onclick="switchTab('single',this)">
                    📦 Single Item
                </button>
            </div>

            <!-- KIT CHECKOUT FORM -->
            <form method="POST" id="form-kit">
                <input type="hidden" name="action" value="checkout_kit">

                <div class="form-group" style="margin-bottom:14px">
                    <label>Select Kit <span class="required">*</span></label>
                    <select name="kit_id" class="form-control" required onchange="previewKit(this)">
                        <option value="">— Choose available kit —</option>
                        <?php foreach ($availableKits as $k): ?>
                            <option value="<?= $k['kit_id'] ?>" data-kit="<?= $k['kit_id'] ?>">
                                [<?= htmlspecialchars($k['kit_code']) ?>]
                                <?= htmlspecialchars($k['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Component preview panel — filled by JS -->
                <div id="kit-preview" style="display:none;margin-bottom:14px">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted)">
                        Kit Contents
                    </label>
                    <div id="kit-preview-list" class="kit-preview-box"></div>
                </div>

                <div class="form-group" style="margin-bottom:14px">
                    <label>Student <span class="required">*</span></label>
                    <input type="hidden" name="user_id" id="kit-user-id" required>
                    <div class="ac-wrap">
                        <input type="text" class="form-control ac-input" id="kit-user-search"
                               placeholder="Type name or student ID..." autocomplete="off"
                               oninput="acSearch(this,'kit-user-id','ac-kit-users')">
                        <div class="ac-dropdown" id="ac-kit-users"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px">
                    <label>Overall Condition</label>
                    <select name="condition_out" class="form-control">
                        <option value="excellent">Excellent</option>
                        <option value="good" selected>Good</option>
                        <option value="fair">Fair</option>
                        <option value="damaged">Damaged</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:20px">
                    <label>Condition / Damage Note</label>
                    <textarea name="condition_note" class="form-control" rows="2"
                              placeholder="Note any pre-existing damage to protect the student..."></textarea>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('checkout-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">✓ Check Out Entire Kit →</button>
                </div>
            </form>

            <!-- SINGLE ITEM CHECKOUT FORM -->
            <form method="POST" id="form-single" style="display:none">
                <input type="hidden" name="action" value="checkout_single">

                <div class="form-group" style="margin-bottom:14px">
                    <label>Asset <span class="required">*</span></label>
                    <input type="hidden" name="asset_id" id="single-asset-id" required>
                    <div class="ac-wrap">
                        <input type="text" class="form-control ac-input" id="single-asset-search"
                               placeholder="Type item name or code..." autocomplete="off"
                               oninput="acSearch(this,'single-asset-id','ac-single-assets')">
                        <div class="ac-dropdown" id="ac-single-assets"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px">
                    <label>Student <span class="required">*</span></label>
                    <input type="hidden" name="user_id" id="single-user-id" required>
                    <div class="ac-wrap">
                        <input type="text" class="form-control ac-input" id="single-user-search"
                               placeholder="Type name or student ID..." autocomplete="off"
                               oninput="acSearch(this,'single-user-id','ac-single-users')">
                        <div class="ac-dropdown" id="ac-single-users"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px">
                    <label>Condition</label>
                    <select name="condition_out" class="form-control">
                        <option value="excellent">Excellent</option>
                        <option value="good" selected>Good</option>
                        <option value="fair">Fair</option>
                        <option value="damaged">Damaged</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:20px">
                    <label>Condition Note</label>
                    <textarea name="condition_note" class="form-control" rows="2"
                              placeholder="Log any pre-existing damage..."></textarea>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('checkout-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Check-Out →</button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- ============================================================
     RETURN PICKER MODAL — opened by the "↩ Check In" header button
     Shows all active/overdue loans so the user can pick which to return
     without having to scroll through the table first
============================================================ -->
<div class="modal-overlay" id="return-picker-modal">
    <div class="modal" style="width:600px">
        <div class="modal-header">
            <h2 class="modal-title">↩ Select Item to Return</h2>
            <button class="modal-close" onclick="closeModal('return-picker-modal')">×</button>
        </div>
        <div class="modal-body" style="padding:0">

            <?php
            // Collect all active transactions grouped (same logic as main table)
            $activeLoanRows = array_filter($displayRows, fn($r) => $r['txn_status'] !== 'returned');
            ?>

            <?php if (empty($activeLoanRows)): ?>
                <div style="padding:32px;text-align:center;color:var(--text-muted)">
                    No active loans at the moment.
                </div>
            <?php else: ?>
                <div style="overflow-y:auto;max-height:60vh">
                    <?php foreach ($activeLoanRows as $row): ?>
                        <div class="picker-item <?= $row['txn_status'] === 'overdue' ? 'picker-overdue' : '' ?>"
                             onclick="<?php if ($row['is_kit']): ?>
                                 openKitCheckin('<?= htmlspecialchars($row['kit_group_id']) ?>','<?= addslashes($row['kit_name']) ?>',<?= $row['hours_overdue'] ?>,<?= count($row['components']) ?>);closeModal('return-picker-modal')
                             <?php else: ?>
                                 openSingleCheckin(<?= $row['txn_id'] ?>,'<?= addslashes($row['asset_name']) ?>',<?= $row['hours_overdue'] ?>,'<?= $row['asset_type'] ?>');closeModal('return-picker-modal')
                             <?php endif; ?>">

                            <div class="picker-icon">
                                <?= $row['is_kit'] ? '🎒' : ($row['asset_type']==='book' ? '📚' : '📷') ?>
                            </div>
                            <div class="picker-info">
                                <div class="picker-name">
                                    <?php if ($row['is_kit']): ?>
                                        <?= htmlspecialchars($row['kit_name']) ?>
                                        <span class="badge badge-kit" style="margin-left:6px">Kit · <?= count($row['components']) ?> items</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($row['asset_name']) ?>
                                        <span class="badge badge-<?= $row['asset_type'] ?>" style="margin-left:6px"><?= ucfirst($row['asset_type']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="picker-meta">
                                    <?= htmlspecialchars($row['borrower_name']) ?>
                                    &middot; Due <?= date('d M Y H:i', strtotime($row['due_at'])) ?>
                                    <?php if ($row['hours_overdue'] > 0): ?>
                                        <span style="color:#dc2626;font-weight:700"> · ⚠ <?= $row['hours_overdue'] ?>h overdue</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="picker-arrow">→</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- ============================================================
     KIT CHECK-IN MODAL — returns all components at once
============================================================ -->
<div class="modal-overlay" id="kit-checkin-modal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">↩ Return Kit: <span id="kci-name"></span></h2>
            <button class="modal-close" onclick="closeModal('kit-checkin-modal')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action"       value="checkin_kit">
            <input type="hidden" name="kit_group_id" id="kci-group">
            <div class="modal-body">

                <!-- Info banner showing how many items will be returned -->
                <div style="background:rgba(37,99,235,.07);border:1px solid rgba(37,99,235,.2);border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#2563eb">
                    All <strong id="kci-count"></strong> components will be returned together in one action.
                </div>

                <!-- Overdue fine calculator -->
                <div id="kci-fine-panel" style="display:none;background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.2);border-radius:8px;padding:14px;margin-bottom:14px">
                    <div style="color:#dc2626;font-weight:700;margin-bottom:6px">⚠ Kit Overdue — Fine Applicable</div>
                    <div id="kci-fine-calc" style="font-size:13px;color:var(--text-primary);margin-bottom:10px"></div>
                    <div style="display:flex;gap:12px;align-items:flex-end">
                        <div style="flex:1">
                            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted)">Total Fine (KES)</label>
                            <input type="number" name="fine_amount" id="kci-fine-amt" class="form-control" step="0.01" min="0" value="0">
                        </div>
                        <label style="display:flex;align-items:center;gap:6px;padding-bottom:10px;cursor:pointer;font-size:13px;white-space:nowrap">
                            <input type="checkbox" name="fine_paid" value="1"> Paid now
                        </label>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px">
                    <label>Overall Condition on Return</label>
                    <select name="condition_in" class="form-control">
                        <option value="excellent">Excellent</option>
                        <option value="good" selected>Good</option>
                        <option value="fair">Fair</option>
                        <option value="damaged">Damaged</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Damage / Condition Notes</label>
                    <textarea name="condition_note" class="form-control" rows="3"
                              placeholder="Note any damage or missing items per component, e.g. 'Scratch on camera body. SD card missing. Battery charged.'"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('kit-checkin-modal')">Cancel</button>
                <button type="submit" class="btn btn-success">↩ Return Entire Kit</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     SINGLE ITEM CHECK-IN MODAL
============================================================ -->
<div class="modal-overlay" id="single-checkin-modal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">↩ Return: <span id="sci-name"></span></h2>
            <button class="modal-close" onclick="closeModal('single-checkin-modal')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action"  value="checkin_single">
            <input type="hidden" name="txn_id"  id="sci-txn">
            <div class="modal-body">

                <div id="sci-fine-panel" style="display:none;background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.2);border-radius:8px;padding:14px;margin-bottom:14px">
                    <div style="color:#dc2626;font-weight:700;margin-bottom:6px">⚠ Overdue — Fine Applicable</div>
                    <div id="sci-fine-calc" style="font-size:13px;margin-bottom:10px"></div>
                    <div style="display:flex;gap:12px;align-items:flex-end">
                        <div style="flex:1">
                            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted)">Fine Amount (KES)</label>
                            <input type="number" name="fine_amount" id="sci-fine-amt" class="form-control" step="0.01" min="0" value="0">
                        </div>
                        <label style="display:flex;align-items:center;gap:6px;padding-bottom:10px;cursor:pointer;font-size:13px;white-space:nowrap">
                            <input type="checkbox" name="fine_paid" value="1"> Paid now
                        </label>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px">
                    <label>Condition on Return</label>
                    <select name="condition_in" class="form-control">
                        <option value="excellent">Excellent</option>
                        <option value="good" selected>Good</option>
                        <option value="fair">Fair</option>
                        <option value="damaged">Damaged</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Condition Note</label>
                    <textarea name="condition_note" class="form-control" rows="2"
                              placeholder="Log any new damage or missing accessories..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('single-checkin-modal')">Cancel</button>
                <button type="submit" class="btn btn-success">✓ Confirm Return</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Styles ── -->
<style>
/* Return picker modal items */
.picker-item {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px; cursor: pointer;
    border-bottom: 1px solid var(--border-light);
    transition: background .12s;
}
.picker-item:last-child { border-bottom: none; }
.picker-item:hover { background: var(--table-hover); }
.picker-overdue { background: rgba(220,38,38,.04); }
.picker-overdue:hover { background: rgba(220,38,38,.08); }
.picker-icon { font-size: 24px; line-height: 1; flex-shrink: 0; }
.picker-info { flex: 1; min-width: 0; }
.picker-name { font-weight: 600; font-size: 14px; color: var(--text-primary); margin-bottom: 4px; }
.picker-meta { font-size: 12px; color: var(--text-muted); }
.picker-arrow { color: var(--text-muted); font-size: 16px; flex-shrink: 0; }

/* Modals */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(3px);z-index:300;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:14px;width:500px;max-width:96vw;max-height:92vh;overflow-y:auto;animation:mup .2s ease}
@keyframes mup{from{transform:translateY(18px);opacity:0}to{transform:none;opacity:1}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border)}
.modal-title{font-size:16px;font-weight:700;color:var(--text-primary)}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted);line-height:1;padding:2px 6px;border-radius:6px}
.modal-close:hover{background:var(--surface-hover);color:var(--text-primary)}
.modal-body{padding:20px}
.modal-footer{padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}

/* Action column — never collapse, button always fully visible */
#txn-table th:last-child,
#txn-table td:last-child {
    min-width: 130px;
    width: 130px;
    padding: 8px 12px;
    white-space: nowrap;
}
#txn-table .btn-sm {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 7px 13px;
    font-size: 12px;
    min-height: 34px;
    white-space: nowrap;
    width: 100%;
    justify-content: center;
}

/* Check-out modal tabs */
.co-tabs{display:flex;gap:3px;margin-bottom:18px;border-bottom:1px solid var(--border);padding-bottom:0}
.co-tab{padding:9px 16px;background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;font-family:inherit;font-size:13px;font-weight:600;color:var(--text-muted);margin-bottom:-1px;transition:all .15s}
.co-tab:hover{color:var(--text-primary)}
.co-tab.active{color:#1a3a6b;border-bottom-color:#1a3a6b}
[data-theme="dark"] .co-tab.active{color:#60a5fa;border-bottom-color:#60a5fa}

/* Autocomplete */
.ac-wrap{position:relative}
.ac-dropdown{display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:999;max-height:220px;overflow-y:auto}
.ac-dropdown.open{display:block}
.ac-item{padding:9px 12px;cursor:pointer;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border-light);transition:background .1s}
.ac-item:last-child{border-bottom:none}
.ac-item:hover{background:var(--surface-hover)}
.ac-main{font-weight:600;font-size:13px;color:var(--text-primary);flex:1}
.ac-sub{font-family:'Space Mono',monospace;font-size:11px;color:var(--text-muted)}
.ac-warn{font-size:11px;color:#dc2626;font-weight:600}
.ac-empty{color:var(--text-muted);font-size:13px;cursor:default}
.ac-empty:hover{background:none}

/* Expand button */
.expand-btn{width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:var(--surface);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;padding:0}
.expand-btn:hover{border-color:#1a3a6b;background:rgba(26,58,107,.06)}
.expand-arrow{font-size:9px;color:var(--text-muted);transition:transform .2s;display:inline-block}
.expand-btn.open .expand-arrow{transform:rotate(90deg);color:#1a3a6b}
[data-theme="dark"] .expand-btn:hover{border-color:#60a5fa}
[data-theme="dark"] .expand-btn.open .expand-arrow{color:#60a5fa}

/* Kit parent row highlight */
.kit-row{background:rgba(26,58,107,.025)}
.kit-row:hover td{background:rgba(26,58,107,.05)!important}
[data-theme="dark"] .kit-row{background:rgba(96,165,250,.03)}

/* Kit badge */
.badge-kit{background:rgba(26,58,107,.12);color:#1a3a6b;border:1px solid rgba(26,58,107,.3);font-family:'Space Mono',monospace;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
[data-theme="dark"] .badge-kit{background:rgba(96,165,250,.15);color:#60a5fa;border-color:rgba(96,165,250,.35)}

/* Expanded components panel */
.components-panel td{padding:0!important}
.components-inner{padding:0;border-top:1px dashed var(--border)}
.components-heading{padding:10px 20px 10px 52px;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;background:var(--table-head-bg);border-bottom:1px solid var(--border-light)}
.components-table{width:100%;border-collapse:collapse}
.components-table th{padding:8px 16px 8px 52px;font-family:'Space Mono',monospace;font-size:9px;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);text-align:left;border-bottom:1px solid var(--border-light)}
.components-table td{padding:10px 16px 10px 52px;font-size:13px;border-bottom:1px solid var(--border-light)}
.components-table tr:last-child td{border-bottom:none}

/* Kit preview box in checkout modal */
.kit-preview-box{border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-top:6px;max-height:180px;overflow-y:auto}
.kp-item{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--border-light);font-size:13px}
.kp-item:last-child{border-bottom:none}
.kp-code{font-family:'Space Mono',monospace;font-size:10px;color:var(--text-muted);min-width:68px}
.kp-name{flex:1}
.kp-cond{font-size:11px;color:var(--text-muted)}
</style>

<!-- ── Dynamic data for main.js (PHP-generated globals) ── -->
<script>
const fineRates = {
    equipment: <?= floatval($fineRates['equip_fine_per_hour'] ?? 5) ?>,
    book:      <?= floatval($fineRates['book_fine_per_day']   ?? 10) ?>
};

const kitData = <?= json_encode($kitPreviewData) ?>;

const acUsers = <?= json_encode(array_map(fn($u) => [
    'id'    => $u['user_id'],
    'label' => $u['full_name'],
    'sub'   => $u['student_id'],
    'warn'  => $u['fines_owed'] > 0 ? '⚠ Fines KES ' . number_format($u['fines_owed'], 2) : '',
], $activeUsers)) ?>;

const acAssets = <?= json_encode(array_map(fn($a) => [
    'id'    => $a['asset_id'],
    'label' => $a['name'],
    'sub'   => $a['asset_code'],
], $availableSingles)) ?>;
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
