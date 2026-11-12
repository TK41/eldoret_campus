<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/rbac.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}
if (!canAccess('inventory')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Inventory access is required.']);
    exit;
}
if (($_POST['action'] ?? '') !== 'mark_all_read') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported notification action.']);
    exit;
}
verifyCsrf();

$adminId = (int) $_SESSION['admin_id'];
$stmt = getDB()->prepare("
    INSERT IGNORE INTO notification_reads (admin_id, notif_id)
    SELECT ?, n.notif_id
    FROM notifications n
    LEFT JOIN notification_reads nr
        ON nr.admin_id = ? AND nr.notif_id = n.notif_id
    WHERE nr.notif_id IS NULL
");
$stmt->execute([$adminId, $adminId]);

echo json_encode(['success' => true]);
