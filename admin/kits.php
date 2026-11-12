<?php
// ============================================================
// admin/kits.php
// Manage equipment kits (bundles of assets issued together)
// Admin can create kits, assign component assets, and view status
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../booking/reservation_helpers.php';
requireLogin();
requireAccess('inventory');

$pageTitle  = 'Kit Management';
$activePage = 'kits';
$db         = getDB();
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
}

// ============================================================
// POST: Create a new kit
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_kit'
    && !isset($_POST['add_to_kit_id'])) {
    $kit_code   = strtoupper(trim($_POST['kit_code']   ?? ''));
    $name       = trim($_POST['name']       ?? '');
    $description= trim($_POST['description']?? '');
    $min_tier   = intval($_POST['min_tier'] ?? 2);
    $components = array_filter(array_map('intval', $_POST['components'] ?? []));

    if (empty($kit_code)) $errors[] = 'Kit code is required (e.g. KIT-001).';
    if (empty($name))     $errors[] = 'Kit name is required.';

    // Check unique kit code
    if (empty($errors)) {
        $exists = $db->prepare("SELECT kit_id FROM kits WHERE kit_code = ?");
        $exists->execute([$kit_code]);
        if ($exists->fetch()) $errors[] = "Kit code $kit_code already exists.";
    }

    if (empty($errors)) {
        // Insert kit
        $db->prepare("
            INSERT INTO kits (kit_code, name, description, min_tier)
            VALUES (?, ?, ?, ?)
        ")->execute([$kit_code, $name, $description ?: null, $min_tier]);

        $kitId = $db->lastInsertId();

        // Assign components as a reusable kit template; assets remain independent inventory items.
        foreach ($components as $assetId) {
            $db->prepare("INSERT IGNORE INTO kit_components (kit_id, asset_id) VALUES (?, ?)")
               ->execute([$kitId, $assetId]);
        }

        setFlash('success', "Kit <strong>$kit_code — $name</strong> created with " . pluralizeCount(count($components), 'component') . ".");
        header('Location: ' . APP_ROOT . '/admin/kits.php');
        exit;
    }
}

// ============================================================
// POST: Add new components to an existing kit (even if checked out)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_kit_id']) && !empty($_POST['new_components'])) {
    $kitId = intval($_POST['add_to_kit_id']);
    $newAssets = array_values(array_unique(array_filter(array_map('intval', $_POST['new_components']), function ($assetId) {
        return $assetId > 0;
    })));

    try {
        $db->beginTransaction();
        $kit = $db->prepare("SELECT status FROM kits WHERE kit_id = ? FOR UPDATE");
        $kit->execute([$kitId]);
        $kitStatus = $kit->fetchColumn();
        if (!$kitStatus) {
            throw new InvalidArgumentException('Kit not found.');
        }

        $currentComponents = $db->prepare("SELECT asset_id FROM kit_components WHERE kit_id = ?");
        $currentComponents->execute([$kitId]);
        $currentIds = array_map('intval', $currentComponents->fetchAll(PDO::FETCH_COLUMN));
        $newAssets = array_values(array_diff($newAssets, $currentIds));
        if (!$newAssets) {
            throw new InvalidArgumentException('Select at least one new component.');
        }

        $placeholders = implode(',', array_fill(0, count($newAssets), '?'));
        $assetStmt = $db->prepare("
            SELECT asset_id, asset_code, name, status, condition_rating
            FROM assets
            WHERE asset_id IN ($placeholders)
            ORDER BY asset_id
            FOR UPDATE
        ");
        $assetStmt->execute($newAssets);
        $assetsToAdd = $assetStmt->fetchAll();
        if (count($assetsToAdd) !== count($newAssets)) {
            throw new InvalidArgumentException('One or more selected assets do not exist.');
        }
        foreach ($assetsToAdd as $asset) {
            if ($asset['status'] !== 'available') {
                throw new InvalidArgumentException($asset['asset_code'] . ' is not available to add to a kit.');
            }
        }

        $issuedKit = null;
        if ($kitStatus === 'checked_out') {
            if (!canDo('inventory.checkout')) {
                throw new InvalidArgumentException('You do not have permission to add components to an issued kit.');
            }
            $issuedKitStmt = $db->prepare("
                SELECT t.user_id, t.staff_id, t.due_at, t.kit_group_id
                FROM transactions t
                JOIN kit_components kc ON kc.asset_id = t.asset_id
                WHERE kc.kit_id = ? AND t.returned_at IS NULL
                ORDER BY t.txn_id
                LIMIT 1
                FOR UPDATE
            ");
            $issuedKitStmt->execute([$kitId]);
            $issuedKit = $issuedKitStmt->fetch();
            if (!$issuedKit || $issuedKit['due_at'] <= gmdate('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('The kit has no valid active issue or its return time has passed.');
            }
            reservationEnsureAvailable(
                $db,
                $newAssets,
                gmdate('Y-m-d H:i:s'),
                $issuedKit['due_at']
            );
            if (empty($issuedKit['kit_group_id'])) {
                $issuedKit['kit_group_id'] = bin2hex(random_bytes(16));
                $db->prepare("
                    UPDATE transactions t
                    JOIN kit_components kc ON kc.asset_id = t.asset_id
                    SET t.kit_group_id = ?, t.is_kit_txn = 1
                    WHERE kc.kit_id = ? AND t.returned_at IS NULL
                ")->execute([$issuedKit['kit_group_id'], $kitId]);
            }
        }

        $currentOwner = $db->prepare("SELECT kit_id FROM kit_components WHERE asset_id = ? LIMIT 1");
        $removeOldLink = $db->prepare("DELETE FROM kit_components WHERE asset_id = ? AND kit_id != ?");
        $addLink = $db->prepare("INSERT IGNORE INTO kit_components (kit_id, asset_id) VALUES (?, ?)");
        $issueAddedAsset = $db->prepare("
            INSERT INTO transactions
                (asset_id, user_id, staff_id, checkout_at, due_at, condition_out,
                 condition_note, is_kit_txn, kit_group_id)
            VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'Added to issued kit', 1, ?)
        ");
        foreach ($assetsToAdd as $asset) {
            $currentOwner->execute([$asset['asset_id']]);
            $oldKitId = $currentOwner->fetchColumn();
            if ($oldKitId) {
                $removeOldLink->execute([$asset['asset_id'], $kitId]);
            }
            $addLink->execute([$kitId, $asset['asset_id']]);

            if ($issuedKit) {
                $issueAddedAsset->execute([
                    $asset['asset_id'],
                    $issuedKit['user_id'],
                    $issuedKit['staff_id'],
                    $issuedKit['due_at'],
                    $asset['condition_rating'],
                    $issuedKit['kit_group_id'],
                ]);
                $db->prepare("UPDATE assets SET status = 'checked_out' WHERE asset_id = ?")
                    ->execute([$asset['asset_id']]);
            }
        }
        $db->commit();
        setFlash('success', 'Component(s) added to kit' . ($issuedKit ? ' and issued in the existing kit transaction.' : '.'));
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Kit component update failed: ' . $e->getMessage());
        setFlash('error', 'The kit components could not be updated. No changes were saved.');
    }
    header('Location: ' . APP_ROOT . '/admin/kits.php');
    exit;
}

// ============================================================
// POST: Remove a component from a kit
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_component') {
    $kitId   = intval($_POST['kit_id']   ?? 0);
    $assetId = intval($_POST['asset_id'] ?? 0);
    $db->prepare("DELETE FROM kit_components WHERE kit_id = ? AND asset_id = ?")->execute([$kitId, $assetId]);
    setFlash('success', 'Component removed from kit.');
    header('Location: ' . APP_ROOT . '/admin/kits.php');
    exit;
}

// ============================================================
// POST: Delete a kit entirely
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_kit' && isSuperAdmin()) {
    $kitId = intval($_POST['kit_id'] ?? 0);
    $db->prepare("DELETE FROM kit_components WHERE kit_id = ?")->execute([$kitId]);
    $db->prepare("DELETE FROM kits WHERE kit_id = ?")->execute([$kitId]);
    setFlash('success', 'Kit deleted. Individual asset records remain unchanged.');
    header('Location: ' . APP_ROOT . '/admin/kits.php');
    exit;
}

// ============================================================
// Fetch all kits with their components
// ============================================================
$kits = $db->query("
    SELECT k.*,
           t.name AS tier_name,
           COUNT(kc.asset_id) AS component_count
    FROM kits k
    LEFT JOIN tiers        t  ON k.min_tier = t.tier_id
    LEFT JOIN kit_components kc ON k.kit_id = kc.kit_id
    GROUP BY k.kit_id
    ORDER BY k.kit_code
")->fetchAll();

// Fetch components for each kit
$kitComponents = [];
$componentRows = $db->query("
    SELECT kc.kit_id, a.asset_id, a.asset_code, a.name,
           a.status, a.condition_rating
    FROM kit_components kc
    JOIN assets a ON kc.asset_id = a.asset_id
    ORDER BY a.name
")->fetchAll();
foreach ($componentRows as $c) {
    $kitComponents[$c['kit_id']][] = $c;
}

// Assets not yet in any kit (for new-kit creation)
$unassignedAssets = $db->query("
    SELECT a.asset_id, a.asset_code, a.name, a.asset_type, a.status
    FROM assets a
    WHERE a.asset_type = 'equipment'
      AND a.status != 'retired'
      AND NOT EXISTS (
          SELECT 1 FROM kit_components kc WHERE kc.asset_id = a.asset_id
      )
    ORDER BY a.name
")->fetchAll();

// Equipment eligible to be added to an existing kit, including assets already assigned elsewhere
// so they can be reassigned to a different kit instead of being blocked forever.
$kitAddableAssets = $db->query("
    SELECT a.asset_id, a.asset_code, a.name, a.asset_type, a.status,
           k.kit_id AS current_kit_id,
           k.name AS current_kit_name
    FROM assets a
    LEFT JOIN kit_components kc ON kc.asset_id = a.asset_id
    LEFT JOIN kits k ON k.kit_id = kc.kit_id
    WHERE a.asset_type = 'equipment'
      AND a.status != 'retired'
    ORDER BY a.name
")->fetchAll();

// Tiers for the dropdown
$tiers = $db->query("SELECT * FROM tiers ORDER BY tier_id")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Kit Management</h1>
        <p class="page-subtitle">Bundle equipment items into kits — checked out and returned as a unit</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('create-kit-modal')">+ Create Kit</button>
</div>

<!-- ============================================================
     KITS GRID
============================================================ -->
<?php if (empty($kits)): ?>
    <div class="card" style="padding:40px;text-align:center;color:var(--text-muted)">
        No kits yet. <button class="btn btn-primary" style="margin-left:12px" onclick="openModal('create-kit-modal')">Create your first kit <?= appIcon('arrow-right') ?></button>
    </div>
<?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:20px">
        <?php foreach ($kits as $kit): ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <span class="mono" style="color:var(--text-muted);font-size:11px"><?= htmlspecialchars($kit['kit_code']) ?></span>
                        <h3 style="font-size:15px;font-weight:700;color:var(--text-primary);margin-top:2px">
                            <?= htmlspecialchars($kit['name']) ?>
                        </h3>
                    </div>
                    <span class="badge badge-<?= $kit['status'] ?>"><?= ucfirst(str_replace('_',' ',$kit['status'])) ?></span>
                </div>

                <div style="padding:16px 20px">
                    <!-- Kit meta -->
                    <div style="display:flex;gap:16px;margin-bottom:14px;font-size:12px;color:var(--text-muted)">
                        <span><?= appIcon('shield') ?> Min: Tier <?= $kit['min_tier'] ?> (<?= htmlspecialchars($kit['tier_name']) ?>)</span>
                        <span><?= appIcon('package') ?> <?= $kit['component_count'] ?> component(s)</span>
                    </div>

                    <?php if ($kit['description']): ?>
                        <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px"><?= htmlspecialchars($kit['description']) ?></p>
                    <?php endif; ?>

                    <!-- Component list -->
                    <div style="margin-bottom:14px">
                        <?php $comps = $kitComponents[$kit['kit_id']] ?? []; ?>
                        <?php if (empty($comps)): ?>
                            <span style="color:var(--text-muted);font-size:12px">No components assigned yet.</span>
                        <?php else: ?>
                            <div style="display:flex;flex-direction:column;gap:6px">
                                <?php foreach ($comps as $comp): ?>
                                    <div style="display:flex;align-items:center;justify-content:space-between;background:var(--table-head-bg);border:1px solid var(--border);border-radius:6px;padding:7px 10px">
                                        <div>
                                            <span class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($comp['asset_code']) ?></span>
                                            <span style="font-size:13px;font-weight:500;margin-left:8px"><?= htmlspecialchars($comp['name']) ?></span>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:8px">
                                            <span class="badge badge-<?= $comp['status'] ?>" style="font-size:9px"><?= ucfirst(str_replace('_',' ',$comp['status'])) ?></span>
                                            <!-- Remove component button -->
                                            <form method="POST" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                <input type="hidden" name="action"   value="remove_component">
                                                <input type="hidden" name="kit_id"   value="<?= $kit['kit_id'] ?>">
                                                <input type="hidden" name="asset_id" value="<?= $comp['asset_id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"
                                                        style="padding:2px 7px;font-size:10px"
                                                        onclick="return confirm('Remove this component from the kit?')" aria-label="Remove component"><?= appIcon('x') ?></button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Kit actions -->
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <!-- Add component to this kit -->
                        <button class="btn btn-ghost btn-sm"
                                onclick="openAddComponent(<?= $kit['kit_id'] ?>, '<?= addslashes($kit['name']) ?>')">
                            + Add Component
                        </button>

                        <!-- Delete kit (superadmin only) -->
                        <?php if (isSuperAdmin()): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_kit">
                                <input type="hidden" name="kit_id" value="<?= $kit['kit_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Delete this kit? Components will become standalone assets.')">
                                    Delete Kit
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============================================================
     CREATE KIT MODAL
============================================================ -->
<div class="modal-overlay" id="create-kit-modal">
    <div class="modal" style="width:560px">
        <div class="modal-header">
            <h2 class="modal-title"><?= appIcon('backpack') ?> Create New Kit</h2>
            <button class="modal-close" onclick="closeModal('create-kit-modal')" aria-label="Close dialog"><?= appIcon('x') ?></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_kit">
            <div class="modal-body">

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error" style="margin-bottom:14px">
                        <?php foreach ($errors as $e): ?><div><?= $e ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
                    <div class="form-group">
                        <label>Kit Code *</label>
                        <input type="text" name="kit_code" class="form-control" required
                               placeholder="e.g. KIT-001" style="text-transform:uppercase">
                    </div>
                    <div class="form-group">
                        <label>Kit Name *</label>
                        <input type="text" name="name" class="form-control" required
                               placeholder="e.g. Canon EOS R5 Kit">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="2"
                              placeholder="What is this kit used for?"></textarea>
                </div>

                <div class="form-group" style="margin-bottom:14px">
                    <label>Minimum Tier to Borrow</label>
                    <select name="min_tier" class="form-control">
                        <?php foreach ($tiers as $tier): ?>
                            <option value="<?= $tier['tier_id'] ?>" <?= $tier['tier_id'] === 2 ? 'selected' : '' ?>>
                                Tier <?= $tier['tier_id'] ?> — <?= htmlspecialchars($tier['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Add Components (select equipment assets)</label>
                    <?php if (!empty($unassignedAssets)): ?>
                        <input type="search" class="form-control asset-search" data-asset-search="create-kit-assets"
                               placeholder="Search by asset code or name" aria-label="Search available equipment">
                    <?php endif; ?>
                    <div id="create-kit-assets" class="asset-search-list" style="max-height:200px;overflow-y:auto;border:1.5px solid var(--border);border-radius:8px">
                        <?php if (empty($unassignedAssets)): ?>
                            <div style="padding:16px;color:var(--text-muted);font-size:13px;text-align:center">
                                No unassigned equipment available. Add equipment first.
                            </div>
                        <?php else: ?>
                            <?php foreach ($unassignedAssets as $a): ?>
                                <label data-asset-search-item="<?= htmlspecialchars(strtolower($a['asset_code'] . ' ' . $a['name']), ENT_QUOTES) ?>" style="display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border-light)">
                                    <input type="checkbox" name="components[]" value="<?= $a['asset_id'] ?>">
                                    <span>
                                        <span class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($a['asset_code']) ?></span>
                                        <span style="font-size:13px;margin-left:8px"><?= htmlspecialchars($a['name']) ?></span>
                                        <span class="badge badge-<?= $a['status'] ?>" style="margin-left:6px;font-size:9px"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <div class="asset-search-empty" style="display:none;padding:12px;color:var(--text-muted);font-size:13px;text-align:center">No matching equipment.</div>
                        <?php endif; ?>
                    </div>
                    <span class="field-hint">You can add more components later from the kit card.</span>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('create-kit-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Kit <?= appIcon('arrow-right') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     ADD COMPONENT TO EXISTING KIT MODAL
============================================================ -->
<div class="modal-overlay" id="add-component-modal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title"><?= appIcon('plus') ?> Add Component to <span id="kit-name-label"></span></h2>
            <button class="modal-close" onclick="closeModal('add-component-modal')" aria-label="Close dialog"><?= appIcon('x') ?></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_kit"><!-- reused as add-component via JS -->
            <div class="modal-body">
                <input type="hidden" name="add_to_kit_id" id="add-to-kit-id">
                <?php if (!empty($kitAddableAssets)): ?>
                    <input type="search" class="form-control asset-search" data-asset-search="existing-kit-assets"
                           placeholder="Search by asset code or name" aria-label="Search equipment to add">
                <?php else: ?>
                    <p style="color:var(--text-muted);font-size:13px;margin-bottom:12px">No equipment available.</p>
                <?php endif; ?>
                <div id="existing-kit-assets" class="asset-search-list" style="max-height:280px;overflow-y:auto;border:1.5px solid var(--border);border-radius:8px">
                    <?php foreach ($kitAddableAssets as $a): ?>
                        <label data-asset-search-item="<?= htmlspecialchars(strtolower($a['asset_code'] . ' ' . $a['name']), ENT_QUOTES) ?>" style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border-light)">
                            <input type="checkbox" name="new_components[]" value="<?= $a['asset_id'] ?>" <?= $a['status'] !== 'available' ? 'disabled' : '' ?>>
                            <span class="mono" style="font-size:11px"><?= htmlspecialchars($a['asset_code']) ?></span>
                            <span style="flex:1"><?= htmlspecialchars($a['name']) ?></span>
                            <?php if (!empty($a['current_kit_name'])): ?>
                                <span class="badge badge-warning" style="font-size:9px">In <?= htmlspecialchars($a['current_kit_name']) ?></span>
                            <?php endif; ?>
                            <span class="badge badge-<?= $a['status'] ?>" style="font-size:9px"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!empty($kitAddableAssets)): ?>
                        <div class="asset-search-empty" style="display:none;padding:12px;color:var(--text-muted);font-size:13px;text-align:center">No matching equipment.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('add-component-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Selected</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); backdrop-filter:blur(3px); z-index:300; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal { background:var(--surface); border:1px solid var(--border); border-radius:12px; width:500px; max-width:95vw; max-height:90vh; overflow-y:auto; animation:slideUp .2s ease; }
@keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }
.modal-header { display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border); }
.modal-title { font-size:16px;font-weight:700;color:var(--text-primary); }
.modal-close { background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted); }
.modal-body { padding:20px; }
.modal-footer { padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px; }
</style>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); }));

function openAddComponent(kitId, kitName) {
    document.getElementById('kit-name-label').textContent = kitName;
    document.getElementById('add-to-kit-id').value = kitId;
    openModal('add-component-modal');
}

document.querySelectorAll('[data-asset-search]').forEach(input => {
    const list = document.getElementById(input.dataset.assetSearch);
    const items = list.querySelectorAll('[data-asset-search-item]');
    const emptyState = list.querySelector('.asset-search-empty');

    input.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        let visibleCount = 0;

        items.forEach(item => {
            const matches = item.dataset.assetSearchItem.includes(query);
            item.style.display = matches ? 'flex' : 'none';
            if (matches) visibleCount++;
        });

        emptyState.style.display = visibleCount ? 'none' : 'block';
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
