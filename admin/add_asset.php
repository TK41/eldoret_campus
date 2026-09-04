<?php
// ============================================================
// admin/add_asset.php
// Form to add new items to the inventory (books + equipment)
// Handles both GET (show form) and POST (save to database)
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';

requireLogin();

$pageTitle  = 'Add New Item';
$activePage = 'add_asset';
$db         = getDB();
$errors     = [];
$success    = false;
$isEdit     = false;
$asset      = [];

// Fetch tiers for the "Minimum Tier" selector
$tiers = $db->query("SELECT tier_id, name FROM tiers ORDER BY tier_id")->fetchAll();

// ============================================================
// EDIT MODE: Load existing asset data if ?edit=ID is in URL
// ============================================================
$editId = intval($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $db->prepare("SELECT * FROM assets WHERE asset_id = ?");
    $stmt->execute([$editId]);
    $asset = $stmt->fetch();

    if (!$asset) {
        setFlash('error', 'Asset not found.');
        header('Location: ' . APP_ROOT . '/admin/assets.php');
        exit;
    }
    // Count how many active (non-retired) assets share the same name + type —
    // that is the true "copy group". The old LIKE-on-asset_code approach was
    // unreliable because stripping the suffix could match unrelated codes.
    $cntStmt = $db->prepare("
        SELECT COUNT(*) FROM assets
        WHERE name = ? AND asset_type = ? AND status != 'retired'
    ");
    $cntStmt->execute([$asset['name'], $asset['asset_type']]);
    $asset['quantity'] = (int)$cntStmt->fetchColumn();

    $availStmt = $db->prepare("
        SELECT COUNT(*) FROM assets
        WHERE name = ? AND asset_type = ? AND status = 'available'
    ");
    $availStmt->execute([$asset['name'], $asset['asset_type']]);
    $asset['available'] = (int)$availStmt->fetchColumn();

    $isEdit    = true;
    $pageTitle = 'Edit Item: ' . $asset['name'];
}

// ============================================================
// POST: Process the add-item form
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // -- Shared required fields --
    $asset_type   = $_POST['asset_type'] ?? '';
    $name         = strtoupper(trim($_POST['name'] ?? ''));
    $asset_code   = strtoupper(trim($_POST['asset_code'] ?? ''));
    $status       = $_POST['status'] ?? 'available';
    $condition    = $_POST['condition_rating'] ?? 'good';
    $barcode      = trim($_POST['barcode'] ?? '');
    $purchase_val = trim($_POST['purchase_value'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');
    $min_tier     = intval($_POST['min_tier'] ?? 1);
    $quantity     = intval($_POST['quantity'] ?? 1);

    if ($isEdit) {
        // Count actual copies by name+type, not by a fragile LIKE on the code
        $oldCountStmt = $db->prepare("
            SELECT COUNT(*) FROM assets
            WHERE name = ? AND asset_type = ? AND status != 'retired'
        ");
        $oldCountStmt->execute([$asset['name'], $asset['asset_type']]);
        $oldCount = (int)$oldCountStmt->fetchColumn();
        $oldName  = $asset['name'];
        $oldType  = $asset['asset_type'];
    }

    // -- Book-specific fields --
    $isbn      = trim($_POST['isbn'] ?? '');
    $author    = trim($_POST['author'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $year_pub  = trim($_POST['year_published'] ?? '');
    $dewey     = trim($_POST['dewey_decimal'] ?? '');

    // -- Equipment-specific fields --
    $serial       = trim($_POST['serial_number'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $model        = trim($_POST['model'] ?? '');
    $kit_id       = intval($_POST['kit_id'] ?? 0) ?: null;

    // -- Validation --
    if (empty($name))       $errors[] = 'Item name is required.';
    if (empty($asset_code)) $errors[] = 'Asset code is required (e.g. EQ-001 or BK-001).';
    if ($quantity < 1 || $quantity > 100) $errors[] = 'Quantity must be between 1 and 100.';
    if (!in_array($asset_type, ['equipment','book'])) $errors[] = 'Please select Equipment or Book.';

    // Check asset code uniqueness
    if (empty($errors)) {
        $check = $db->prepare("SELECT COUNT(*) FROM assets WHERE asset_code = ?" . ($isEdit ? " AND asset_id != $editId" : ""));
        $check->execute([$asset_code]);
        if ($check->fetchColumn() > 0) {
            $errors[] = "Asset code <strong>$asset_code</strong> already exists. Use a unique code.";
        }
    }

    // -- Save to database if no errors --
    if (empty($errors)) {
        $admin = getCurrentAdmin();

        if ($isEdit) {
            // UPDATE existing record
            $db->prepare("
                UPDATE assets SET
                    asset_code       = :asset_code,
                    name             = :name,
                    asset_type       = :asset_type,
                    kit_id           = :kit_id,
                    status           = :status,
                    condition_rating = :condition_rating,
                    barcode          = :barcode,
                    purchase_value   = :purchase_value,
                    isbn             = :isbn,
                    author           = :author,
                    publisher        = :publisher,
                    year_published   = :year_published,
                    dewey_decimal    = :dewey_decimal,
                    serial_number    = :serial_number,
                    manufacturer     = :manufacturer,
                    model            = :model,
                    min_tier         = :min_tier,
                    notes            = :notes
                WHERE asset_id = :asset_id
            ")->execute([
                ':asset_code'       => $asset_code,
                ':name'             => $name,
                ':asset_type'       => $asset_type,
                ':kit_id'           => ($asset_type === 'equipment' ? $kit_id : null),
                ':status'           => $status,
                ':condition_rating' => $condition,
                ':barcode'          => $barcode ?: null,
                ':purchase_value'   => $purchase_val ?: null,
                ':isbn'             => ($asset_type === 'book' ? $isbn : null),
                ':author'           => ($asset_type === 'book' ? $author : null),
                ':publisher'        => ($asset_type === 'book' ? $publisher : null),
                ':year_published'   => ($asset_type === 'book' ? $year_pub : null),
                ':dewey_decimal'    => ($asset_type === 'book' ? $dewey : null),
                ':serial_number'    => ($asset_type === 'equipment' ? $serial : null),
                ':manufacturer'     => ($asset_type === 'equipment' ? $manufacturer : null),
                ':model'            => ($asset_type === 'equipment' ? $model : null),
                ':min_tier'         => $min_tier,
                ':notes'            => $notes ?: null,
                ':asset_id'         => $editId,
            ]);

            // Handle quantity adjustment (add or retire copies)
            if ($quantity > $oldCount) {
                // Add extra copies — find the highest existing copy number for this name/type
                $existingCodes = $db->prepare("
                    SELECT asset_code FROM assets
                    WHERE name = ? AND asset_type = ? AND status != 'retired'
                    ORDER BY asset_id
                ");
                $existingCodes->execute([$oldName, $oldType]);
                $codes = $existingCodes->fetchAll(PDO::FETCH_COLUMN);
                // Find the next available suffix number
                $maxNum = 0;
                foreach ($codes as $c) {
                    if (preg_match('/-(\d+)$/', $c, $m)) {
                        $maxNum = max($maxNum, (int)$m[1]);
                    }
                }
                // Base code for new copies: strip suffix from current asset_code if present
                $copyBase = preg_match('/-\d{2}$/', $asset_code) ? preg_replace('/-\d+$/', '', $asset_code) : $asset_code;

                $addStmt = $db->prepare("INSERT INTO assets (
                    asset_code, name, asset_type, kit_id, status,
                    condition_rating, barcode, purchase_value,
                    isbn, author, publisher, year_published, dewey_decimal,
                    serial_number, manufacturer, model, min_tier, notes, added_by
                ) VALUES (
                    :asset_code, :name, :asset_type, :kit_id, :status,
                    :condition_rating, :barcode, :purchase_value,
                    :isbn, :author, :publisher, :year_published, :dewey_decimal,
                    :serial_number, :manufacturer, :model, :min_tier, :notes, :added_by
                )");
                for ($i = $oldCount + 1; $i <= $quantity; $i++) {
                    $maxNum++;
                    $addStmt->execute([
                        ':asset_code'       => $copyBase . '-' . str_pad($maxNum, 2, '0', STR_PAD_LEFT),
                        ':name'             => $name,
                        ':asset_type'       => $asset_type,
                        ':kit_id'           => ($asset_type === 'equipment' ? $kit_id : null),
                        ':status'           => $status,
                        ':condition_rating' => $condition,
                        ':barcode'          => $barcode ?: null,
                        ':purchase_value'   => $purchase_val ?: null,
                        ':isbn'             => $isbn ?: null,
                        ':author'           => $author ?: null,
                        ':publisher'        => $publisher ?: null,
                        ':year_published'   => $year_pub ?: null,
                        ':dewey_decimal'    => $dewey ?: null,
                        ':serial_number'    => $serial ?: null,
                        ':manufacturer'     => $manufacturer ?: null,
                        ':model'            => $model ?: null,
                        ':min_tier'         => $min_tier,
                        ':notes'            => $notes ?: null,
                        ':added_by'         => $admin['admin_id'],
                    ]);
                }
            } elseif ($quantity < $oldCount) {
                // Retire the excess copies — only pick available ones to avoid disrupting loans
                $remove = $oldCount - $quantity;
                $pick = $db->prepare("
                    SELECT asset_id FROM assets
                    WHERE name = ? AND asset_type = ? AND status = 'available'
                    ORDER BY asset_id DESC
                    LIMIT ?
                ");
                $pick->execute([$oldName, $oldType, $remove]);
                $ids = $pick->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) < $remove) {
                    // Not enough available copies to retire — warn but proceed with what we can
                    $errors[] = 'Could only retire ' . count($ids) . ' of ' . $remove . ' copies — the rest are checked out or in maintenance.';
                }
                if ($ids) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $db->prepare("UPDATE assets SET status='retired' WHERE asset_id IN ($ph)")->execute($ids);
                }
            }

            setFlash('success', "✓ Asset <strong>$name</strong> updated successfully.");

        } else {
            // INSERT new record(s)
            $stmt = $db->prepare("INSERT INTO assets (
                asset_code, name, asset_type, kit_id, status,
                condition_rating, barcode, purchase_value,
                isbn, author, publisher, year_published, dewey_decimal,
                serial_number, manufacturer, model, min_tier, notes, added_by
            ) VALUES (
                :asset_code, :name, :asset_type, :kit_id, :status,
                :condition_rating, :barcode, :purchase_value,
                :isbn, :author, :publisher, :year_published, :dewey_decimal,
                :serial_number, :manufacturer, :model, :min_tier, :notes, :added_by
            )");

            for ($i = 0; $i < $quantity; $i++) {
                $copy_code = ($quantity > 1)
                    ? $asset_code . '-' . str_pad($i + 1, 2, '0', STR_PAD_LEFT)
                    : $asset_code;

                $stmt->execute([
                    ':asset_code'       => $copy_code,
                    ':name'             => $name,
                    ':asset_type'       => $asset_type,
                    ':kit_id'           => ($asset_type === 'equipment' ? $kit_id : null),
                    ':status'           => $status,
                    ':condition_rating' => $condition,
                    ':barcode'          => $barcode ?: null,
                    ':purchase_value'   => $purchase_val ?: null,
                    ':isbn'             => $isbn ?: null,
                    ':author'           => $author ?: null,
                    ':publisher'        => $publisher ?: null,
                    ':year_published'   => $year_pub ?: null,
                    ':dewey_decimal'    => $dewey ?: null,
                    ':serial_number'    => $serial ?: null,
                    ':manufacturer'     => $manufacturer ?: null,
                    ':model'            => $model ?: null,
                    ':min_tier'         => $min_tier,
                    ':notes'            => $notes ?: null,
                    ':added_by'         => $admin['admin_id'],
                ]);
            }

            $msg = ($quantity == 1)
                ? "✓ Item <strong>" . htmlspecialchars($name) . "</strong> ($asset_code) added successfully."
                : "✓ Added <strong>$quantity copies</strong> of <strong>" . htmlspecialchars($name) . "</strong> successfully.";

            setFlash('success', $msg);
        }

        header('Location: ' . APP_ROOT . '/admin/assets.php');
        exit;
    }

    // On validation error: keep POST data to refill the form
    $asset = $_POST;
}

include __DIR__ . '/partials/header.php';

// Helper to get form value
function getFormValue($field, $default = '') {
    global $asset, $isEdit;
    if (!empty($_POST)) {
        return $_POST[$field] ?? $default;
    } elseif ($isEdit && $asset) {
        return $asset[$field] ?? $default;
    }
    return $default;
}

$selectedType = getFormValue('asset_type', '');
if (!in_array($selectedType, ['book', 'equipment'], true)) {
    $selectedType = '';
}
?>

<!-- ── Page Header ── -->
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? htmlspecialchars('Edit: ' . $asset['name']) : 'Add New Item' ?></h1>
        <p class="page-subtitle"><?= $isEdit ? 'Update asset details and manage copies' : 'Add books or media equipment to the inventory' ?></p>
    </div>
    <a href="<?= APP_ROOT ?>/admin/assets.php" class="btn btn-ghost">← Back to Assets</a>
</div>

<!-- ── Validation errors ── -->
<?php if (!empty($errors)): ?>
    <div style="margin-bottom:20px;padding:14px 18px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.28);border-radius:10px;color:#dc2626">
        <strong>Please fix the following:</strong>
        <ul style="margin:8px 0 0 18px;line-height:2">
            <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ============================================================
     ADD / EDIT ITEM FORM
============================================================ -->
<form method="POST" action="" id="add-asset-form">

    <!-- ── Step 1: Choose type ── -->
    <div class="card" style="margin-bottom:20px">
        <div class="card-header">
            <h2 class="card-title">Step 1 — Choose Item Type</h2>
        </div>
        <div class="card-body">
            <div class="form-grid" style="padding:0;gap:12px">
                <div class="form-group full-width">
                    <label for="asset_type">Item Type <span class="required">*</span></label>
                    <select id="asset_type" name="asset_type" class="form-control" onchange="switchType(this.value)">
                        <option value="" <?= $selectedType === '' ? 'selected' : '' ?>>Select item type</option>
                        <option value="equipment" <?= $selectedType === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                        <option value="book" <?= $selectedType === 'book' ? 'selected' : '' ?>>Book</option>
                    </select>
                    <span class="field-hint">Select an option to show the matching form fields.</span>
                </div>
            </div>

            <div class="selection-tip" id="selection-tip">
                <?= $selectedType ? 'You selected <strong>' . ($selectedType === 'equipment' ? 'Equipment' : 'Book') . '</strong>. Fill in the matching fields below.' : 'Choose equipment or a book to reveal the matching details form.' ?>
            </div>
        </div>
    </div>

    <!-- ── Step 2: Common Details ── -->
    <div class="card" style="margin-bottom:20px">
        <div class="card-header">
            <h2 class="card-title">Step 2 — Basic Details</h2>
        </div>
        <div class="card-body form-grid">

            <div class="form-group full-width">
                <label for="name">Item Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" class="form-control" required
                       placeholder="<?= $selectedType === 'equipment' ? 'e.g. Canon EOS R5 Body' : ($selectedType === 'book' ? 'e.g. Cinematography Today 4th Ed.' : 'e.g. Enter item name') ?>"
                       value="<?= htmlspecialchars(getFormValue('name')) ?>">
            </div>

            <div class="form-group">
                <label for="asset_code">Asset Code <span class="required">*</span></label>
                <input type="text" id="asset_code" name="asset_code" class="form-control" required
                       placeholder="<?= $selectedType === 'equipment' ? 'e.g. EQ-001' : ($selectedType === 'book' ? 'e.g. BK-001' : 'e.g. EQ-001 or BK-001') ?>"
                       value="<?= htmlspecialchars(getFormValue('asset_code')) ?>"
                       style="text-transform:uppercase">
                <span class="field-hint">Must be unique. Use EQ- for equipment, BK- for books.</span>
            </div>

            <div class="form-group">
                <label for="quantity">Quantity <span class="required">*</span></label>
                <input type="number" id="quantity" name="quantity" class="form-control" required
                       min="1" max="100" value="<?= htmlspecialchars(getFormValue('quantity','1')) ?>">
                <span class="field-hint">How many copies to add (1–100).</span>
                <?php if ($isEdit): ?>
                    <span class="field-hint">
                        Currently <?= intval($asset['available'] ?? 0) ?> available
                        of <?= intval($asset['quantity'] ?? 0) ?> total.
                    </span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="barcode">Barcode / QR Code</label>
                <input type="text" id="barcode" name="barcode" class="form-control"
                       placeholder="Scan or enter barcode"
                       value="<?= htmlspecialchars(getFormValue('barcode')) ?>">
            </div>

            <div class="form-group">
                <label for="status">Initial Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="available"   <?= getFormValue('status','available') === 'available'   ? 'selected' : '' ?>>Available</option>
                    <option value="maintenance" <?= getFormValue('status','available') === 'maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
                    <option value="retired"     <?= getFormValue('status','available') === 'retired'     ? 'selected' : '' ?>>Retired / Disposed</option>
                </select>
            </div>

            <div class="form-group">
                <label for="condition_rating">Condition</label>
                <select id="condition_rating" name="condition_rating" class="form-control">
                    <option value="excellent" <?= getFormValue('condition_rating','good') === 'excellent' ? 'selected' : '' ?>>Excellent</option>
                    <option value="good"      <?= getFormValue('condition_rating','good') === 'good'      ? 'selected' : '' ?>>Good</option>
                    <option value="fair"      <?= getFormValue('condition_rating','good') === 'fair'      ? 'selected' : '' ?>>Fair</option>
                    <option value="damaged"   <?= getFormValue('condition_rating','good') === 'damaged'   ? 'selected' : '' ?>>Damaged</option>
                </select>
            </div>

            <div class="form-group">
                <label for="purchase_value">Purchase Value (KES)</label>
                <input type="number" id="purchase_value" name="purchase_value" class="form-control"
                       placeholder="e.g. 150000" step="0.01" min="0"
                       value="<?= htmlspecialchars(getFormValue('purchase_value')) ?>">
            </div>

            <div class="form-group full-width">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="Any additional notes, purchase order, location, etc."><?= htmlspecialchars(getFormValue('notes')) ?></textarea>
            </div>

        </div>
    </div>

    <!-- ── Step 3a: Equipment Details ── -->
    <div class="card" id="equipment-section"
         style="margin-bottom:20px;display:<?= $selectedType === 'equipment' ? 'block' : 'none' ?>">
        <div class="card-header">
            <h2 class="card-title">Step 3 — Equipment Details</h2>
        </div>
        <div class="card-body form-grid">

            <div class="form-group">
                <label for="manufacturer">Manufacturer / Brand</label>
                <input type="text" id="manufacturer" name="manufacturer" class="form-control"
                       placeholder="e.g. Canon, Sony, DJI, Apple"
                       value="<?= htmlspecialchars(getFormValue('manufacturer')) ?>">
            </div>

            <div class="form-group">
                <label for="model">Model</label>
                <input type="text" id="model" name="model" class="form-control"
                       placeholder="e.g. EOS R5, H6, MacBook Pro 14&quot;"
                       value="<?= htmlspecialchars(getFormValue('model')) ?>">
            </div>

            <div class="form-group">
                <label for="serial_number">Serial Number</label>
                <input type="text" id="serial_number" name="serial_number" class="form-control"
                       placeholder="Manufacturer serial number"
                       value="<?= htmlspecialchars(getFormValue('serial_number')) ?>">
            </div>

            <div class="form-group">
                <label for="min_tier">Minimum Tier to Borrow</label>
                <select id="min_tier" name="min_tier" class="form-control">
                    <?php foreach ($tiers as $tier): ?>
                        <option value="<?= $tier['tier_id'] ?>"
                            <?= intval(getFormValue('min_tier', 1)) === (int)$tier['tier_id'] ? 'selected' : '' ?>>
                            Tier <?= $tier['tier_id'] ?> — <?= htmlspecialchars($tier['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">Prevents low-tier students from borrowing sensitive equipment.</span>
            </div>

        </div>
    </div>

    <!-- ── Step 3b: Book Details ── -->
    <div class="card" id="book-section"
         style="margin-bottom:20px;display:<?= $selectedType === 'book' ? 'block' : 'none' ?>">
        <div class="card-header">
            <h2 class="card-title">Step 3 — Book Details</h2>
        </div>
        <div class="card-body form-grid">

            <div class="form-group">
                <label for="author">Author(s)</label>
                <input type="text" id="author" name="author" class="form-control"
                       placeholder="e.g. Walter Murch"
                       value="<?= htmlspecialchars(getFormValue('author')) ?>">
            </div>

            <div class="form-group">
                <label for="isbn">ISBN</label>
                <input type="text" id="isbn" name="isbn" class="form-control"
                       placeholder="e.g. 978-0-374-52467-7"
                       value="<?= htmlspecialchars(getFormValue('isbn')) ?>">
            </div>

            <div class="form-group">
                <label for="publisher">Publisher</label>
                <input type="text" id="publisher" name="publisher" class="form-control"
                       placeholder="e.g. University of California Press"
                       value="<?= htmlspecialchars(getFormValue('publisher')) ?>">
            </div>

            <div class="form-group">
                <label for="year_published">Year Published</label>
                <input type="number" id="year_published" name="year_published" class="form-control"
                       placeholder="e.g. 2021" min="1900" max="<?= date('Y') ?>"
                       value="<?= htmlspecialchars(getFormValue('year_published')) ?>">
            </div>

            <div class="form-group">
                <label for="dewey_decimal">Dewey Decimal Number</label>
                <input type="text" id="dewey_decimal" name="dewey_decimal" class="form-control"
                       placeholder="e.g. 791.43 MUR"
                       value="<?= htmlspecialchars(getFormValue('dewey_decimal')) ?>">
            </div>

        </div>
    </div>

    <!-- ── Submit buttons ── -->
    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">
            <?= $isEdit ? '✓ Update Item' : '✓ Add to Inventory' ?>
        </button>
        <button type="reset" class="btn btn-ghost btn-lg">Reset Form</button>
        <a href="<?= APP_ROOT ?>/admin/assets.php" class="btn btn-ghost btn-lg">Cancel</a>
    </div>

</form>

<script>
function getCodePrefix(type) {
    if (type === 'equipment') return 'EQ-';
    if (type === 'book') return 'BK-';
    return '';
}

function switchType(type) {
    const normalizedType = type === 'equipment' ? 'equipment' : type === 'book' ? 'book' : '';
    const selectionTip = document.getElementById('selection-tip');
    const equipmentSection = document.getElementById('equipment-section');
    const bookSection = document.getElementById('book-section');
    const nameInput = document.getElementById('name');
    const codeInput = document.getElementById('asset_code');

    if (normalizedType === 'equipment') {
        bookSection.style.display = 'none';
        setTimeout(function() {
            equipmentSection.style.display = 'block';
        }, 10);
    } else if (normalizedType === 'book') {
        equipmentSection.style.display = 'none';
        setTimeout(function() {
            bookSection.style.display = 'block';
        }, 10);
    } else {
        equipmentSection.style.display = 'none';
        bookSection.style.display = 'none';
    }

    if (selectionTip) {
        selectionTip.innerHTML = normalizedType === 'equipment'
            ? 'You selected <strong>Equipment</strong>. Fill in the equipment-specific fields below.'
            : normalizedType === 'book'
                ? 'You selected <strong>Book</strong>. Fill in the book-specific fields below.'
                : 'Choose equipment or a book to reveal the matching details form.';
    }

    if (nameInput) {
        nameInput.placeholder = normalizedType === 'equipment'
            ? 'e.g. Canon EOS R5 Body'
            : normalizedType === 'book'
                ? 'e.g. Cinematography Today 4th Ed.'
                : 'e.g. Enter item name';
    }

    if (codeInput) {
        codeInput.placeholder = normalizedType === 'equipment'
            ? 'e.g. EQ-001'
            : normalizedType === 'book'
                ? 'e.g. BK-001'
                : 'e.g. EQ-001 or BK-001';

        const prefix = getCodePrefix(normalizedType);
        if (prefix) {
            const currentValue = (codeInput.value || '').toUpperCase().trim();
            const withoutPrefix = currentValue.replace(/^EQ-/, '').replace(/^BK-/, '');
            const suffix = withoutPrefix.replace(/^-+/, '').trim();
            codeInput.value = suffix ? prefix + suffix : prefix;
        } else {
            codeInput.value = '';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const assetCodeInput = document.getElementById('asset_code');
    if (assetCodeInput) {
        assetCodeInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }

    const typeSelect = document.getElementById('asset_type');
    if (typeSelect) {
        typeSelect.addEventListener('change', function() {
            switchType(this.value);
        });
        switchType(typeSelect.value);
    }
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
