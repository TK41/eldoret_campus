<?php
// ============================================================
// api/get-inventory-count.php
// Returns JSON with inventory count breakdown for an asset code
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';

// Require login for API access
requireLogin();

header('Content-Type: application/json');

$assetCode = $_GET['code'] ?? '';

if (empty($assetCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Asset code required']);
    exit;
}

$db = getDB();

// Query inventory breakdown by status and condition
$stmt = $db->prepare("
    SELECT 
        status,
        condition_rating,
        COUNT(*) as cnt
    FROM assets
    WHERE asset_code = ?
    GROUP BY status, condition_rating
    ORDER BY status, condition_rating
");
$stmt->execute([$assetCode]);
$rows = $stmt->fetchAll();

$result = [
    'total'       => 0,
    'available'   => 0,
    'checked_out' => 0,
    'maintenance' => 0,
    'retired'     => 0,
    'by_condition' => []
];

foreach ($rows as $row) {
    $result['total'] += $row['cnt'];
    $result[$row['status']] += $row['cnt'];
    
    $key = ucfirst($row['status']) . ' (' . ucfirst($row['condition_rating']) . ')';
    $result['by_condition'][$key] = $row['cnt'];
}

echo json_encode($result);
