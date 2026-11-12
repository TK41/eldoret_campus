<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/rbac.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$query = trim((string) ($_GET['q'] ?? ''));
if (strlen($query) > 100) {
    $query = substr($query, 0, 100);
}
if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'groups' => []]);
    exit;
}

$db = getDB();
$like = '%' . $query . '%';
$groups = [];
$studentItems = [];
$seenStudentIds = [];

if (canAccess('fees')) {
    $stmt = $db->prepare("
        SELECT fee_student_id, student_id, full_name, programme
        FROM fee_students
        WHERE full_name LIKE ? OR student_id LIKE ?
        ORDER BY full_name
        LIMIT 5
    ");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $seenStudentIds[$row['student_id']] = true;
        $studentItems[] = [
            'label' => formatPersonName($row['full_name']),
            'meta' => 'ADM No. ' . $row['student_id'] . ($row['programme'] ? ' · ' . formatTitleCase($row['programme']) : ''),
            'url' => APP_ROOT . '/fees/student.php?id=' . (int) $row['fee_student_id'],
        ];
    }
}

if (canAccess('inventory')) {
    $stmt = $db->prepare("
        SELECT user_id, student_id, full_name, department
        FROM users
        WHERE full_name LIKE ? OR student_id LIKE ?
        ORDER BY full_name
        LIMIT 5
    ");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($seenStudentIds[$row['student_id']])) {
            continue;
        }
        $studentItems[] = [
            'label' => formatPersonName($row['full_name']),
            'meta' => 'ADM No. ' . $row['student_id'] . ($row['department'] ? ' · ' . formatTitleCase($row['department']) : ''),
            'url' => APP_ROOT . '/admin/users.php?search=' . rawurlencode($row['student_id']),
        ];
    }
}

if (canAccess('fees') || canAccess('inventory')) {
    $groups[] = ['label' => 'Students', 'items' => $studentItems];
}

if (canAccess('admissions')) {
    $stmt = $db->prepare("
        SELECT admission_id, reference_no, first_name, middle_name, surname, mobile_no, programme_name
        FROM admissions
        WHERE CONCAT_WS(' ', first_name, middle_name, surname) LIKE ?
           OR CONCAT_WS(' ', surname, middle_name, first_name) LIKE ?
           OR reference_no LIKE ?
           OR mobile_no LIKE ?
        ORDER BY submitted_at DESC
        LIMIT 5
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = trim(implode(' ', array_filter([
            $row['first_name'],
            $row['middle_name'],
            $row['surname'],
        ])));
        $items[] = [
            'label' => formatPersonName($name),
            'meta' => $row['reference_no'] . ' · ' . $row['mobile_no'] . ' · ' . formatTitleCase($row['programme_name']),
            'url' => APP_ROOT . '/admissions/application.php?id=' . (int) $row['admission_id'],
        ];
    }
    $groups[] = ['label' => 'Applicants', 'items' => $items];
}

if (canAccess('inventory')) {
    $stmt = $db->prepare("
        SELECT asset_id, name, asset_code, barcode, status
        FROM assets
        WHERE status != 'retired'
          AND (name LIKE ? OR asset_code LIKE ? OR barcode LIKE ?)
        ORDER BY name
        LIMIT 5
    ");
    $stmt->execute([$like, $like, $like]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'label' => $row['name'],
            'meta' => 'Tag ' . $row['asset_code'] . ' · ' . formatTitleCase(str_replace('_', ' ', $row['status'])),
            'url' => APP_ROOT . '/admin/assets.php?search=' . rawurlencode($row['asset_code']),
        ];
    }
    $groups[] = ['label' => 'Assets', 'items' => $items];

    $stmt = $db->prepare("
        SELECT t.txn_id, t.user_id, u.full_name AS borrower_name, u.student_id,
               a.name AS asset_name, a.asset_code, t.checkout_at, t.returned_at
        FROM transactions t
        JOIN users u ON u.user_id = t.user_id
        JOIN assets a ON a.asset_id = t.asset_id
        WHERE CAST(t.txn_id AS CHAR) LIKE ?
           OR u.full_name LIKE ?
           OR u.student_id LIKE ?
           OR a.name LIKE ?
           OR a.asset_code LIKE ?
        ORDER BY t.checkout_at DESC
        LIMIT 5
    ");
    $stmt->execute([$like, $like, $like, $like, $like]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'label' => '#' . $row['txn_id'] . ' · ' . $row['asset_name'],
            'meta' => formatPersonName($row['borrower_name']) . ' · ' . $row['student_id']
                . ' · ' . ($row['returned_at'] === null ? 'Checked out' : 'Returned'),
            'url' => APP_ROOT . '/admin/transactions.php?txn_id=' . (int) $row['txn_id'],
        ];
    }
    $groups[] = ['label' => 'Transactions', 'items' => $items];
}

if (canAccess('exams')) {
    $stmt = $db->prepare("
        SELECT r.session_id, r.student_id, r.total, r.grade,
               u.unit_code, u.unit_name, s.name AS session_name
        FROM exam_results r
        JOIN exam_units u ON u.unit_id = r.unit_id
        JOIN exam_sessions s ON s.session_id = r.session_id
        WHERE r.student_id LIKE ?
           OR u.unit_code LIKE ?
           OR u.unit_name LIKE ?
           OR s.name LIKE ?
        ORDER BY r.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'label' => $row['student_id'] . ' · ' . $row['unit_code'],
            'meta' => $row['session_name'] . ' · ' . formatMark($row['total']) . '% · Grade ' . $row['grade'],
            'url' => APP_ROOT . '/exams/results.php?' . http_build_query([
                'session_id' => (int) $row['session_id'],
                'q' => $row['student_id'],
            ]),
        ];
    }
    $groups[] = ['label' => 'Exam results', 'items' => $items];
}

echo json_encode(['success' => true, 'groups' => $groups], JSON_INVALID_UTF8_SUBSTITUTE);
