<?php
// ============================================================
// admin/permissions.php
// Read-only view of borrowing tier rules
// Links to settings.php for editing
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

$pageTitle  = 'Borrowing Permissions';
$activePage = 'permissions';
$db         = getDB();

// Fetch all tiers
$tiers = $db->query("SELECT * FROM tiers ORDER BY tier_id")->fetchAll();

// Count users per tier
$tierCounts = $db->query("
    SELECT tier_id, COUNT(*) AS total,
           SUM(is_active=1) AS active
    FROM users GROUP BY tier_id
")->fetchAll(PDO::FETCH_UNIQUE);

include __DIR__ . '/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Borrowing Permissions</h1>
        <p class="page-subtitle">Tier-based rules that control what each student can borrow</p>
    </div>
    <?php if (isSuperAdmin()): ?>
        <a href="<?= APP_ROOT ?>/admin/settings.php#tiers" class="btn btn-primary">✏️ Edit Tier Rules</a>
    <?php endif; ?>
</div>

<!-- ============================================================
     FULL TIER MATRIX TABLE
============================================================ -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header"><h2 class="card-title">📋 Tier Rules Matrix</h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rule</th>
                    <?php foreach ($tiers as $tier): ?>
                        <th style="text-align:center">
                            <span class="badge badge-tier-<?= $tier['tier_id'] ?>">
                                Tier <?= $tier['tier_id'] ?>
                            </span><br>
                            <small style="font-size:10px;color:var(--text-muted);text-transform:none;letter-spacing:0">
                                <?= htmlspecialchars($tier['name']) ?>
                            </small>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Users on this tier</strong></td>
                    <?php foreach ($tiers as $tier): ?>
                        <td style="text-align:center">
                            <span class="mono"><?= $tierCounts[$tier['tier_id']]['total'] ?? 0 ?></span>
                            <small style="color:var(--text-muted)">
                                (<?= $tierCounts[$tier['tier_id']]['active'] ?? 0 ?> active)
                            </small>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Max Books</td>
                    <?php foreach ($tiers as $tier): ?>
                        <td style="text-align:center;font-family:'Space Mono',monospace;font-weight:700">
                            <?= $tier['max_books'] >= 99 ? '∞' : $tier['max_books'] ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Book Loan Period</td>
                    <?php foreach ($tiers as $tier): ?>
                        <td style="text-align:center"><?= $tier['book_loan_days'] ?> days</td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Max Equipment Items</td>
                    <?php foreach ($tiers as $tier): ?>
                        <td style="text-align:center;font-family:'Space Mono',monospace;font-weight:700">
                            <?= $tier['max_equipment'] >= 99 ? '∞' : $tier['max_equipment'] ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Equipment Loan Period</td>
                    <?php foreach ($tiers as $tier): ?>
                        <td style="text-align:center">
                            <?= $tier['equip_loan_hrs'] >= 999 ? 'Custom' : $tier['equip_loan_hrs'] . 'h' ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Can Make Reservations</td>
                    <?php foreach ($tiers as $tier): ?>
                        <td style="text-align:center;font-size:16px">
                            <?= $tier['can_reserve'] ? '✅' : '❌' ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Can Borrow Kit Bundles</td>
                    <?php foreach ($tiers as $tier): ?>
                        <td style="text-align:center;font-size:16px">
                            <?= $tier['can_kit'] ? '✅' : '❌' ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Admin Dashboard Access</td>
                    <?php foreach ($tiers as $tier): ?>
                        <td style="text-align:center;font-size:16px">
                            <?= $tier['is_admin'] ? '✅' : '❌' ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Tier detail cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">
    <?php foreach ($tiers as $tier): ?>
        <div class="card">
            <div class="card-header">
                <span class="badge badge-tier-<?= $tier['tier_id'] ?>">Tier <?= $tier['tier_id'] ?></span>
                <span style="font-weight:700;color:var(--text-primary)"><?= htmlspecialchars($tier['name']) ?></span>
            </div>
            <div style="padding:16px 20px;font-size:13px;line-height:2;color:var(--text-muted)">
                <div>📚 <strong><?= $tier['max_books']>=99?'Unlimited':$tier['max_books'] ?></strong> books for <strong><?= $tier['book_loan_days'] ?> days</strong></div>
                <div>📷 <strong><?= $tier['max_equipment']>=99?'Unlimited':$tier['max_equipment'] ?></strong> equipment for <strong><?= $tier['equip_loan_hrs']>=999?'custom':$tier['equip_loan_hrs'].'h' ?></strong></div>
                <div><?= $tier['can_reserve']?'✅':'❌' ?> Advance reservations</div>
                <div><?= $tier['can_kit']?'✅':'❌' ?> Kit bundles</div>
                <div style="margin-top:8px;color:var(--text-primary);font-weight:600">
                    <?= $tierCounts[$tier['tier_id']]['total'] ?? 0 ?> student(s) on this tier
                </div>
            </div>
            <div style="padding:0 20px 16px">
                <a href="<?= APP_ROOT ?>/admin/users.php?tier=<?= $tier['tier_id'] ?>" class="btn btn-ghost btn-sm">
                    View Students →
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<style>
.badge-tier-1{background:rgba(107,114,128,.1);color:#6b7280;border:1px solid rgba(107,114,128,.25);}
.badge-tier-2{background:rgba(37,99,235,.1);color:#2563eb;border:1px solid rgba(37,99,235,.25);}
.badge-tier-3{background:rgba(16,185,129,.1);color:#059669;border:1px solid rgba(16,185,129,.25);}
.badge-tier-4{background:rgba(217,119,6,.1);color:#d97706;border:1px solid rgba(217,119,6,.25);}
</style>

<?php include __DIR__ . '/partials/footer.php'; ?>
