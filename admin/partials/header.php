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
    ['type' => 'link', 'label' => 'Dashboard', 'url' => APP_ROOT . '/admin/dashboard.php', 'key' => 'dashboard', 'icon' => '⬛'],
    ['type' => 'link', 'label' => 'Assets', 'url' => APP_ROOT . '/admin/assets.php', 'key' => 'assets', 'icon' => '📦'],
    ['type' => 'link', 'label' => 'Add Item', 'url' => APP_ROOT . '/admin/add_asset.php', 'key' => 'add_asset', 'icon' => '➕'],
    ['type' => 'link', 'label' => 'Kits', 'url' => APP_ROOT . '/admin/kits.php', 'key' => 'kits', 'icon' => '🎒'],
    ['type' => 'link', 'label' => 'Reservations', 'url' => APP_ROOT . '/admin/reservations.php', 'key' => 'reservations', 'icon' => '🗓️'],
    ['type' => 'link', 'label' => 'Student Logins', 'url' => BOOKING_ROOT . '/admin/student_accounts.php', 'key' => 'student_accounts', 'icon' => '🔑'],
    ['type' => 'link', 'label' => 'Transactions', 'url' => APP_ROOT . '/admin/transactions.php', 'key' => 'transactions', 'icon' => '↕️'],
    ['type' => 'section', 'label' => 'Borrowers'],
    ['type' => 'link', 'label' => 'Users', 'url' => APP_ROOT . '/admin/users.php', 'key' => 'users', 'icon' => '👥'],
    ['type' => 'link', 'label' => 'Permissions', 'url' => APP_ROOT . '/admin/permissions.php', 'key' => 'permissions', 'icon' => '🔐'],
    ['type' => 'section', 'label' => 'System'],
    ['type' => 'link', 'label' => 'Notifications', 'url' => APP_ROOT . '/admin/notifications.php', 'key' => 'notifications', 'icon' => '🔔'],
    ['type' => 'link', 'label' => 'Settings', 'url' => APP_ROOT . '/admin/settings.php', 'key' => 'settings', 'icon' => '⚙️'],
];

require __DIR__ . '/../../shared/app_shell_header.php';
