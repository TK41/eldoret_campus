<?php
// ============================================================
// index.php  (place in kimc-inventory/ root)
// Entry point — redirects to login or dashboard based on session
// ============================================================

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/auth/session.php';

// If already logged in go to dashboard, otherwise login page
if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . APP_ROOT . '/portal.php');
} else {
    header('Location: ' . APP_ROOT . '/auth/login.php');
}
exit;
?>
