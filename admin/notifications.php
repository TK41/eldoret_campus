<?php
// ============================================================
// admin/notifications.php
// View and manage the notification queue
// Admin can view pending alerts, mark sent, send manually,
// and see the history of all sent notifications
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

$pageTitle  = 'Notifications';
$activePage = 'notifications';
$db         = getDB();

// ============================================================
// POST: Mark a notification as sent (manual override)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_sent') {
        $db->prepare("UPDATE notifications SET status='sent', sent_at=NOW() WHERE notif_id=?")
           ->execute([intval($_POST['notif_id'])]);
        setFlash('success', 'Notification marked as sent.');
    }

    if ($action === 'mark_failed') {
        $db->prepare("UPDATE notifications SET status='failed' WHERE notif_id=?")
           ->execute([intval($_POST['notif_id'])]);
        setFlash('success', 'Notification marked as failed.');
    }

    // Dismiss / delete a notification
    if ($action === 'delete') {
        $db->prepare("DELETE FROM notifications WHERE notif_id=?")
           ->execute([intval($_POST['notif_id'])]);
        setFlash('success', 'Notification removed.');
    }

    // Generate overdue alerts for all current overdue transactions
    if ($action === 'generate_overdue') {
        $overdue = $db->query("
            SELECT t.txn_id, t.user_id, t.due_at,
                   a.name AS asset_name, a.asset_type,
                   u.email
            FROM transactions t
            JOIN assets a ON t.asset_id = a.asset_id
            JOIN users  u ON t.user_id  = u.user_id
            WHERE t.returned_at IS NULL
              AND t.due_at < NOW()
        ")->fetchAll();

        $count = 0;
        foreach ($overdue as $o) {
            // Don't duplicate if overdue alert already exists
            $exists = $db->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE txn_id=? AND type='overdue' AND status='pending'
            ");
            $exists->execute([$o['txn_id']]);
            if (!$exists->fetchColumn()) {
                $db->prepare("
                    INSERT INTO notifications
                        (user_id, txn_id, type, channel, subject, body, scheduled_at)
                    VALUES (?, ?, 'overdue', 'email',
                            'OVERDUE: Please return your borrowed item immediately',
                            CONCAT('Your item \"', ?, '\" is overdue. Please return it immediately to avoid further fines.'),
                            NOW())
                ")->execute([$o['user_id'], $o['txn_id'], $o['asset_name']]);
                $count++;
            }
        }
        setFlash('success', "$count overdue notification(s) generated.");
    }

    header('Location: ' . APP_ROOT . '/admin/notifications.php');
    exit;
}

// ============================================================
// Fetch notification data
// ============================================================
// Pending notifications
$pending = $db->query("
    SELECT n.*, u.full_name, u.email, u.phone,
           a.name AS asset_name, a.asset_code
    FROM notifications n
    JOIN users u ON n.user_id = u.user_id
    LEFT JOIN transactions t ON n.txn_id = t.txn_id
    LEFT JOIN assets       a ON t.asset_id = a.asset_id
    WHERE n.status = 'pending'
    ORDER BY n.scheduled_at ASC
")->fetchAll();

// Notification history (last 50 sent/failed)
$history = $db->query("
    SELECT n.*, u.full_name, u.email,
           a.name AS asset_name
    FROM notifications n
    JOIN users u ON n.user_id = u.user_id
    LEFT JOIN transactions t ON n.txn_id = t.txn_id
    LEFT JOIN assets       a ON t.asset_id = a.asset_id
    WHERE n.status IN ('sent','failed')
    ORDER BY n.sent_at DESC
    LIMIT 50
")->fetchAll();

// Count overdue items without notifications yet
$unnotifiedOverdue = $db->query("
    SELECT COUNT(*) FROM transactions t
    WHERE t.returned_at IS NULL AND t.due_at < NOW()
    AND NOT EXISTS (
        SELECT 1 FROM notifications n
        WHERE n.txn_id = t.txn_id AND n.type = 'overdue' AND n.status = 'pending'
    )
")->fetchColumn();

include __DIR__ . '/partials/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Notifications</h1>
        <p class="page-subtitle">Due-date alerts and overdue notices — email &amp; SMS queue</p>
    </div>
    <div style="display:flex;gap:8px">
        <?php if ($unnotifiedOverdue > 0): ?>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="generate_overdue">
                <button type="submit" class="btn btn-danger">
                    ⚠ Generate <?= $unnotifiedOverdue ?> Overdue Alert(s)
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Stats row -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card accent-orange">
        <div class="stat-label">Pending</div>
        <div class="stat-value"><?= count($pending) ?></div>
        <div class="stat-sub">Queued to send</div>
    </div>
    <div class="stat-card accent-green">
        <div class="stat-label">Sent (recent)</div>
        <div class="stat-value"><?= count(array_filter($history, fn($h) => $h['status']==='sent')) ?></div>
        <div class="stat-sub">Last 50 records</div>
    </div>
    <div class="stat-card accent-red">
        <div class="stat-label">Failed</div>
        <div class="stat-value"><?= count(array_filter($history, fn($h) => $h['status']==='failed')) ?></div>
        <div class="stat-sub">Check SMTP settings</div>
    </div>
    <div class="stat-card accent-blue">
        <div class="stat-label">Unnotified Overdue</div>
        <div class="stat-value"><?= $unnotifiedOverdue ?></div>
        <div class="stat-sub">No alert sent yet</div>
    </div>
</div>

<!-- ============================================================
     PENDING NOTIFICATIONS
============================================================ -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h2 class="card-title">📤 Pending Queue</h2>
        <span class="badge badge-orange"><?= count($pending) ?> pending</span>
    </div>

    <?php if (empty($pending)): ?>
        <div class="empty-row" style="padding:32px;text-align:center;color:var(--text-muted)">
            ✅ No pending notifications. All clear!
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Type</th><th>Recipient</th><th>Asset</th>
                        <th>Message</th><th>Channel</th><th>Scheduled</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $n): ?>
                        <tr>
                            <td>
                                <span class="badge badge-<?= $n['type'] === 'overdue' ? 'overdue' : 'due_soon' ?>">
                                    <?= ucfirst(str_replace('_',' ', $n['type'])) ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($n['full_name']) ?></strong><br>
                                <small style="color:var(--text-muted)"><?= htmlspecialchars($n['email']) ?></small>
                                <?php if ($n['phone']): ?>
                                    <br><small style="color:var(--text-muted)">📱 <?= htmlspecialchars($n['phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($n['asset_name']): ?>
                                    <strong><?= htmlspecialchars($n['asset_name']) ?></strong><br>
                                    <span class="mono" style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($n['asset_code'] ?? '') ?></span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:260px;font-size:12px"><?= htmlspecialchars($n['subject']) ?></td>
                            <td>
                                <span style="font-family:'Space Mono',monospace;font-size:10px;text-transform:uppercase">
                                    <?= strtoupper($n['channel']) ?>
                                </span>
                            </td>
                            <td class="mono" style="font-size:11px">
                                <?= date('d M Y H:i', strtotime($n['scheduled_at'])) ?>
                                <?php if (strtotime($n['scheduled_at']) <= time()): ?>
                                    <br><span style="color:#dc2626;font-size:10px">Overdue to send</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    <!-- Mark as sent manually -->
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action"   value="mark_sent">
                                        <input type="hidden" name="notif_id" value="<?= $n['notif_id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">✓ Mark Sent</button>
                                    </form>
                                    <!-- Delete -->
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action"   value="delete">
                                        <input type="hidden" name="notif_id" value="<?= $n['notif_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Remove this notification?')">✕</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     NOTIFICATION HISTORY
============================================================ -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">📋 Recent History</h2>
        <span style="font-size:12px;color:var(--text-muted)">Last 50 records</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th><th>Recipient</th><th>Asset</th>
                    <th>Subject</th><th>Channel</th><th>Sent At</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr><td colspan="7" class="empty-row">No notification history yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td><span class="badge badge-<?= $h['type']==='overdue'?'overdue':'due_soon' ?>"><?= ucfirst(str_replace('_',' ',$h['type'])) ?></span></td>
                            <td><?= htmlspecialchars($h['full_name']) ?><br><small style="color:var(--text-muted)"><?= htmlspecialchars($h['email']) ?></small></td>
                            <td style="font-size:12px"><?= htmlspecialchars($h['asset_name'] ?? '—') ?></td>
                            <td style="font-size:12px;max-width:220px"><?= htmlspecialchars($h['subject']) ?></td>
                            <td class="mono" style="font-size:10px"><?= strtoupper($h['channel']) ?></td>
                            <td class="mono" style="font-size:11px"><?= $h['sent_at'] ? date('d M Y H:i', strtotime($h['sent_at'])) : '—' ?></td>
                            <td>
                                <span class="badge <?= $h['status']==='sent' ? 'badge-available' : 'badge-overdue' ?>">
                                    <?= ucfirst($h['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
