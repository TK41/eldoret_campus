<?php
// ============================================================
// api/student-items.php
// Live item search for the student kit builder. Returns equipment grouped
// by name/model with how many are free for the chosen pickup time.
// Never exposes asset IDs; the booking form submits group keys only.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/student_auth.php';
require_once __DIR__ . '/../reservation_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
studentRequireLogin(true);

$db = getDB();
$profile = studentLoadProfile($db);
if (!$profile || !$profile['fee_active'] || !$profile['user_active']) {
    http_response_code(403);
    echo json_encode(['error' => 'Your account cannot book equipment right now.']);
    exit;
}

$rules = studentBookingRules();
$pickup = (string) ($_GET['pickup'] ?? '');
if ($pickup === '') {
    // No pickup chosen yet: show the catalogue as of now; availability is rechecked per pickup time.
    $start = gmdate('Y-m-d H:i:s');
} else {
    if ($pickupError = studentPickupError($pickup, $rules)) {
        http_response_code(422);
        echo json_encode(['error' => $pickupError]);
        exit;
    }
    $start = reservationParseLocalDateTime($pickup);
}
$end = studentWindowEnd($start, (int) $profile['equip_loan_hrs']);
$query = substr(trim((string) ($_GET['q'] ?? '')), 0, 60);

$items = [];
foreach (array_slice(studentItemGroups($db, $start, $end, $query), 0, 300) as $group) {
    $items[] = [
        'key'    => $group['key'],
        'name'   => $group['name'],
        'detail' => $group['detail'],
        'total'  => $group['total'],
        'free'   => $group['free'],
    ];
}
echo json_encode(['items' => $items]);
