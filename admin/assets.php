<?php
// ============================================================
// admin/assets.php
// Asset registry — shows unique items with available/total counts.
// Kits are not listed here (managed separately via Kits page).
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

$pageTitle  = 'Asset Registry';
$activePage = 'assets';
$db         = getDB();

// ============================================================
// Quick POST actions: update status or retire an asset
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_status') {
        $db->prepare("UPDATE assets SET status=? WHERE asset_id=?")
           ->execute([$_POST['new_status'], intval($_POST['asset_id'])]);
        setFlash('success', 'Asset status updated.');
    }

    if ($_POST['action'] === 'retire_kit' && isSuperAdmin()) {
        // Retire every component of a kit at once
        $db->prepare("UPDATE assets SET status='retired' WHERE kit_id=?")
           ->execute([intval($_POST['kit_id'])]);
        $db->prepare("UPDATE kits SET status='retired' WHERE kit_id=?")
           ->execute([intval($_POST['kit_id'])]);
        setFlash('success', 'Kit and all components retired.');
    }

    if ($_POST['action'] === 'delete' && isSuperAdmin()) {
        if (!empty($_POST['base_code'])) {
            // retire all copies sharing the same base code
            $code = strtoupper(trim($_POST['base_code']));
            // match exact base followed by optional -NN suffix
            $db->prepare("UPDATE assets SET status='retired' WHERE asset_code LIKE ?")
               ->execute([$code . '%']);
            setFlash('success', 'All copies of ' . htmlspecialchars($code) . ' retired from inventory.');
        } elseif (!empty($_POST['asset_id'])) {
            $db->prepare("UPDATE assets SET status='retired' WHERE asset_id=?")
               ->execute([intval($_POST['asset_id'])]);
            setFlash('success', 'Asset retired from inventory.');
        }
    }

    header('Location: ' . APP_ROOT . '/admin/assets.php');
    exit;
}

// ============================================================
// Filters
// ============================================================
$search        = trim($_GET['search'] ?? '');
$filter_type   = $_GET['type']        ?? '';
$filter_status = $_GET['status']      ?? '';
$filter_kit    = $_GET['kit']         ?? '';   // 'yes','no','' = all

$where  = ["a.status != 'retired'"];
$params = [];

if ($search) {
    $where[]  = "(a.name LIKE ? OR a.asset_code LIKE ? OR a.barcode LIKE ? OR a.author LIKE ?)";
    $like     = "%$search%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($filter_type && in_array($filter_type, ['equipment','book'])) {
    $where[] = "a.asset_type = ?"; $params[] = $filter_type;
}
if ($filter_status && in_array($filter_status, ['available','checked_out','maintenance'])) {
    $where[] = "a.status = ?"; $params[] = $filter_status;
}
if ($filter_kit === 'yes') { $where[] = "a.kit_id IS NOT NULL"; }
if ($filter_kit === 'no')  { $where[] = "a.kit_id IS NULL"; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ============================================================
// Fetch all (non-retired) assets with tier + kit info
// ============================================================
$assets = $db->prepare("
    SELECT a.*,
           t.name   AS tier_name
    FROM assets a
    LEFT JOIN tiers t ON a.min_tier = t.tier_id
    $whereSQL
    ORDER BY a.name ASC
");
$assets->execute($params);
$assets = $assets->fetchAll();

// Kits are managed on the separate kits page; asset listing ignores them entirely.
// (The kit query and lookup were removed.)
// ============================================================
// Separate assets into:
//   $kitComponents[kit_id]  — assets that belong to a kit
//   $standaloneAssets       — flattened list of all assets (kit membership is ignored)
// ============================================================
$isFiltered     = $search || $filter_type || $filter_status;
$standaloneAssets = $assets; // every asset participates in grouping

// always collapse duplicates by base code and track available quantity
$groupedStandalone = [];
foreach ($standaloneAssets as $a) {
    $base = preg_replace('/-\d+$/','', $a['asset_code']);
    if (!isset($groupedStandalone[$base])) {
        $groupedStandalone[$base] = [
            'rep'   => $a,
            'total' => 0,
            'avail' => 0,
        ];
    }
    $groupedStandalone[$base]['total']++;
    if ($a['status'] === 'available') {
        $groupedStandalone[$base]['avail']++;
    }
}

// counts for subtitle and visibility
$groupCount = count($groupedStandalone);
$availableCount = 0;
$visibleCount = 0;
foreach ($groupedStandalone as $info) {
    $availableCount += $info['avail'];
    if ($info['avail'] > 0) $visibleCount++;
}

include __DIR__ . '/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Asset Registry</h1>
        <p class="page-subtitle">
            <?= $groupCount ?> item type(s), <?= $availableCount ?> copies available
            <?php if (!$isFiltered): ?>
                &middot; 
            <?php endif; ?>
        </p>
    </div>
    <a href="<?= APP_ROOT ?>/admin/add_asset.php" class="btn btn-primary">+ Add New Item</a>
</div>

<!-- Search + Filter bar -->
<div class="filter-bar card" style="margin-bottom:20px">
    <form method="GET" class="filter-form">
        <div class="search-input-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" name="search" class="form-control search-input"
                   placeholder="Search name, code, barcode, author..."
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <select name="type" class="form-control filter-select">
            <option value="">All Types</option>
            <option value="equipment" <?= $filter_type==='equipment'?'selected':''?>>Equipment</option>
            <option value="book"      <?= $filter_type==='book'     ?'selected':''?>>Books</option>
        </select>
        <select name="status" class="form-control filter-select">
            <option value="">All Statuses</option>
            <option value="available"   <?= $filter_status==='available'  ?'selected':''?>>Available</option>
            <option value="checked_out" <?= $filter_status==='checked_out'?'selected':''?>>Checked Out</option>
            <option value="maintenance" <?= $filter_status==='maintenance'?'selected':''?>>Maintenance</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= APP_ROOT ?>/admin/assets.php" class="btn btn-ghost">Clear</a>
    </form>
</div>

<!-- ============================================================
     ASSET TABLE
     Shows each unique item code once with available/total
     counts. Filtered view: flat list of matching items.
============================================================ -->
<div class="card">
    <div class="table-wrap">
        <table class="data-table" id="asset-table">
            <thead>
                <tr>
                            <th>Code</th>
                    <th>Qty</th>
                    <th>Name / Details</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Condition</th>
                    <th>Min. Tier</th>
                    <th>Value (KES)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($visibleCount === 0): ?>
                <tr>
                    <td colspan="9" class="empty-row">
                        <?php if ($groupCount === 0): ?>
                            No assets found.
                            <a href="<?= APP_ROOT ?>/admin/add_asset.php">Add one →</a>
                        <?php else: ?>
                            No available assets match the current filter.
                        <?php endif; ?>
                    </td>
                </tr>

            <?php else: ?>
                <?php foreach ($groupedStandalone as $base => $info): ?>
                    <?php if ($info['avail'] === 0) continue; ?>
                    <?php $a = $info['rep']; ?>
                    <tr>
                        <td class="mono"><?= htmlspecialchars($a['asset_code']) ?></td>
                        <td><?= $info['avail'] ?><?= $info['total']>1?"/".$info['total']:'' ?></td>
                        <td>
                            <strong><?= htmlspecialchars($a['name']) ?></strong>
                            <?php if ($a['author']): ?>
                                <br><small style="color:var(--text-muted)"><?= htmlspecialchars($a['author']) ?></small>
                            <?php endif; ?>
                            <?php if ($a['manufacturer'] || $a['model']): ?>
                                <br><small style="color:var(--text-muted)"><?= htmlspecialchars(trim($a['manufacturer'].' '.$a['model'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= $a['asset_type'] ?>"><?= ucfirst($a['asset_type']) ?></span></td>
                        <td><span class="badge badge-<?= $a['status'] ?>"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span></td>
                        <td><span class="badge badge-condition-<?= $a['condition_rating'] ?>"><?= ucfirst($a['condition_rating']) ?></span></td>
                        <td><small>Tier <?= $a['min_tier'] ?></small></td>
                        <td class="mono"><?= $a['purchase_value'] ? number_format($a['purchase_value'],2) : '—' ?></td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <a href="<?= APP_ROOT ?>/admin/add_asset.php?edit=<?= $a['asset_id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                                <?php if ($a['status'] === 'available'): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="asset_id" value="<?= $a['asset_id'] ?>">
                                        <input type="hidden" name="new_status" value="maintenance">
                                        <button class="btn btn-warn btn-sm">→ Maintenance</button>
                                    </form>
                                <?php elseif ($a['status'] === 'maintenance'): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="asset_id" value="<?= $a['asset_id'] ?>">
                                        <input type="hidden" name="new_status" value="available">
                                        <button class="btn btn-success btn-sm">→ Available</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (isSuperAdmin()): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="base_code" value="<?= htmlspecialchars(preg_replace('/-\d+$/','', $a['asset_code'])) ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Retire all copies of <?= addslashes($a['name']) ?>?')">Delete</button>
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




<?php include __DIR__ . '/partials/footer.php'; ?>
