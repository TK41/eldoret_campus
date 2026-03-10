<?php
// ============================================================
// auth/logout.php
// Destroys the admin session and redirects to login
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/session.php';

// Destroy all session data
session_unset();
session_destroy();

// Redirect to login page
header('Location: ' . APP_ROOT . '/auth/login.php?logged_out=1');
exit;
?>
