<?php
// ============================================================
// api/heartbeat.php
// Updates current admin user's last_seen for realtime presence
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

header('Content-Type: application/json');
$db = getDB();

try {
    $db->exec("ALTER TABLE admin_users ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL");
} catch (PDOException $e) {
    // ignore if column already exists
}

try {
    $stmt = $db->prepare("UPDATE admin_users SET last_seen = UTC_TIMESTAMP() WHERE admin_id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
} catch (PDOException $e) {
    // ignore update failures in heartbeat
}

echo json_encode(['ok' => 1]);
