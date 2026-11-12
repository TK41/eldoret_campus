<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/student_auth.php';
studentEndSession();
header('Location: ' . BOOKING_ROOT . '/student/login.php');
exit;
