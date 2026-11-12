<?php
// Shared head + sticky top bar for student pages (same look as the admin system).
// Expects: $studentTitle (string), optional $studentName (string, shown when signed in)
require_once __DIR__ . '/../../../shared/formatters.php';
require_once __DIR__ . '/../../../shared/icons.php';
$studentTitle = $studentTitle ?? 'Equipment Booking';
$initials = '';
$isSignedIn = !empty($studentName);
if (!empty($studentName)) {
    $parts = preg_split('/\s+/', trim($studentName));
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($studentTitle) ?> — KIMC Eldoret</title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('kimc_student_theme') === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', t);
                document.documentElement.style.colorScheme = t;
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_ROOT ?>/assets/css/theme.css">
    <link rel="stylesheet" href="<?= BOOKING_ROOT ?>/assets/css/student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/student.css') ?>">
    <script>
        window.KIMC_ICON_PLUS = <?= json_encode(appIcon('plus')) ?>;
        window.KIMC_ICON_MINUS = <?= json_encode(appIcon('minus')) ?>;
        window.KIMC_ICON_CLOSE = <?= json_encode(appIcon('x')) ?>;
    </script>
</head>
<body class="student-body">
<header class="topbar">
    <div class="topbar-left">
        <a href="<?= BOOKING_ROOT ?>/reservations.php" class="topbar-brand">
            <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo" style="height:40px;width:auto;object-fit:contain">
            <div class="brand-text">
                <span class="brand-name">KIMC Eldoret</span>
                <span class="brand-sub">Equipment Booking</span>
            </div>
        </a>
    </div>
    <div class="topbar-right">
        <?php if ($isSignedIn): ?>
        <nav class="student-nav" aria-label="Booking navigation">
            <a href="<?= BOOKING_ROOT ?>/reservations.php#booking-form">New booking</a>
            <a href="<?= BOOKING_ROOT ?>/student/my_bookings.php">My bookings</a>
        </nav>
        <?php endif; ?>
        <button class="icon-btn theme-toggle-btn" type="button" onclick="studentToggleTheme()" title="Toggle theme" aria-label="Toggle theme">
            <span id="theme-icon">
                <span class="theme-icon-for-light"><?= appIcon('moon') ?></span>
                <span class="theme-icon-for-dark"><?= appIcon('sun') ?></span>
            </span>
        </button>
        <?php if ($initials !== ''): ?>
        <div class="user-menu">
            <button class="user-menu-trigger" id="user-menu-trigger" type="button" onclick="studentToggleMenu()" aria-expanded="false" aria-controls="user-dropdown" aria-label="Open account menu">
                <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                <span class="user-menu-name"><?= htmlspecialchars($studentName) ?></span>
                <span class="dropdown-arrow"><?= appIcon('chevron-down') ?></span>
            </button>
            <div class="dropdown-menu" id="user-dropdown">
                <a href="<?= BOOKING_ROOT ?>/student/logout.php" class="dropdown-item danger"><?= appIcon('log-out') ?> Sign Out</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</header>
