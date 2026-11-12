<?php
require_once __DIR__ . '/../../auth/rbac.php';
if (!defined('BOOKING_ROOT')) {
    define('BOOKING_ROOT', APP_ROOT . '/booking');
}
requireAccess('inventory');
getDB()->prepare("UPDATE admin_users SET last_seen=NOW() WHERE admin_id=?")->execute([$_SESSION['admin_id']]);

$shellModule = 'inventory';
$shellModuleLabel = 'Inventory';
$shellDashboard = APP_ROOT . '/admin/dashboard.php';
$shellAdmin = getCurrentAdmin();
$admin = $shellAdmin;
$shellFlash = getFlash();
$shellNavItems = [
    ['type' => 'section', 'label' => 'Main'],
    ['type' => 'link', 'label' => 'Dashboard', 'url' => APP_ROOT . '/admin/dashboard.php', 'key' => 'dashboard', 'icon' => 'grid'],
    ['type' => 'link', 'label' => 'Assets', 'url' => APP_ROOT . '/admin/assets.php', 'key' => 'assets', 'icon' => 'package'],
    ['type' => 'link', 'label' => 'Kits', 'url' => APP_ROOT . '/admin/kits.php', 'key' => 'kits', 'icon' => 'backpack'],
    ['type' => 'link', 'label' => 'Reservations', 'url' => APP_ROOT . '/admin/reservations.php', 'key' => 'reservations', 'icon' => 'calendar'],
    ['type' => 'link', 'label' => 'Student Logins', 'url' => BOOKING_ROOT . '/admin/student_accounts.php', 'key' => 'student_accounts', 'icon' => 'key'],
    ['type' => 'link', 'label' => 'Transactions', 'url' => APP_ROOT . '/admin/transactions.php', 'key' => 'transactions', 'icon' => 'arrow-left-right'],
    ['type' => 'section', 'label' => 'Borrowers'],
    ['type' => 'link', 'label' => 'Users', 'url' => APP_ROOT . '/admin/users.php', 'key' => 'users', 'icon' => 'users'],
    ['type' => 'link', 'label' => 'Permissions', 'url' => APP_ROOT . '/admin/permissions.php', 'key' => 'permissions', 'icon' => 'shield'],
    ['type' => 'section', 'label' => 'System'],
    ['type' => 'link', 'label' => 'Notifications', 'url' => APP_ROOT . '/admin/notifications.php', 'key' => 'notifications', 'icon' => 'bell'],
    ['type' => 'link', 'label' => 'Settings', 'url' => APP_ROOT . '/admin/settings.php', 'key' => 'settings', 'icon' => 'settings'],
];

require __DIR__ . '/../../shared/app_shell_header.php';
