<?php
// ============================================================
// exams/api/save_mark.php
// AJAX endpoint — saves a single CA or exam score in real time
// Returns JSON
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../auth/session.php';

header('Content-Type: application/json');
ini_set('display_errors', 0); error_reporting(0);

// Must be logged in
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$db         = getDB();
$session_id = intval($_POST['session_id'] ?? 0);
$unit_id    = intval($_POST['unit_id']    ?? 0);
$student_id = trim($_POST['student_id']  ?? '');
$field      = trim($_POST['field']       ?? '');   // 'ca_score', 'exam_score', or 'remarks'
$value      = trim($_POST['value']       ?? '');
$admin_id   = intval($_SESSION['admin_id']);

// Validate
if (!$session_id || !$unit_id || !$student_id || !in_array($field, ['ca_score', 'exam_score', 'remarks'])) {
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid parameters']);
    exit;
}

// Handle remarks separately — simple upsert, no score validation needed
if ($field === 'remarks') {
    $db->prepare("
        INSERT INTO exam_results (session_id, unit_id, student_id, remarks, entered_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE remarks=VALUES(remarks), updated_by=VALUES(updated_by), updated_at=NOW()
    ")->execute([$session_id, $unit_id, $student_id, $value ?: null, $admin_id, $admin_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// Check session is not locked
$locked = $db->prepare("SELECT is_locked FROM exam_sessions WHERE session_id=?");
$locked->execute([$session_id]);
$sess = $locked->fetch();
if (!$sess) {
    echo json_encode(['ok' => false, 'error' => 'Session not found']);
    exit;
}
if ($sess['is_locked']) {
    echo json_encode(['ok' => false, 'error' => 'This session is locked. Contact admin to unlock.']);
    exit;
}

// Validate score range
$max = $field === 'ca_score' ? 30 : 70;
if ($value === '' || $value === null) {
    $score = null;
} else {
    $score = floatval($value);
    if ($score < 0 || $score > $max) {
        echo json_encode(['ok' => false, 'error' => "Score must be 0–{$max}"]);
        exit;
    }
}

try {
    // Upsert: INSERT … ON DUPLICATE KEY UPDATE
    $sql = "
        INSERT INTO exam_results (session_id, unit_id, student_id, {$field}, entered_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            {$field}     = VALUES({$field}),
            updated_by   = VALUES(updated_by),
            updated_at   = NOW()
    ";
    $db->prepare($sql)->execute([$session_id, $unit_id, $student_id, $score, $admin_id, $admin_id]);

    // Fetch updated totals for this row
    $row = $db->prepare("
        SELECT ca_score, exam_score, total, grade
        FROM exam_results
        WHERE session_id=? AND unit_id=? AND student_id=?
    ");
    $row->execute([$session_id, $unit_id, $student_id]);
    $result = $row->fetch();

    echo json_encode([
        'ok'         => true,
        'ca_score'   => $result['ca_score'],
        'exam_score' => $result['exam_score'],
        'total'      => $result['total'],
        'grade'      => $result['grade'],
    ]);

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
}
