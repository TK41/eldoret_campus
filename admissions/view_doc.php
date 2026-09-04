<?php
// ============================================================
// admissions/view_doc.php  — Secure file delivery (admin only)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$db    = getDB();
$docId = intval($_GET['doc_id'] ?? 0);
if (!$docId) { http_response_code(404); exit('Document not found.'); }

$doc = $db->prepare("SELECT * FROM admission_documents WHERE doc_id=?");
$doc->execute([$docId]);
$doc = $doc->fetch();
if (!$doc) { http_response_code(404); exit('Document not found.'); }

// ── Path fix: uploads/ lives one level above admissions/, not inside it ──
// apply.php saves to: __DIR__ . '/../uploads/'  (i.e. project-root/uploads/)
// This file must look in the same location.
$filePath = realpath(__DIR__ . '/../uploads/' . basename($doc['stored_name']));

// realpath() returns false if the file doesn't exist or path escapes the directory
if ($filePath === false || !file_exists($filePath)) {
    http_response_code(404);
    exit('File not found on disk.');
}

// ── Safety: ensure the resolved path is still inside the uploads directory ──
$uploadsDir = realpath(__DIR__ . '/../uploads');
if ($uploadsDir === false || strpos($filePath, $uploadsDir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    exit('Access denied.');
}

// ── Stream the file to browser ──
$mime = $doc['mime_type'] ?: mime_content_type($filePath);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . addslashes($doc['original_name']) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;