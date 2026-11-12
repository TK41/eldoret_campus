<?php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('fees');

$shellModule = 'fees';
$shellModuleLabel = 'Fees Management';
$shellDashboard = APP_ROOT . '/fees/dashboard.php';
$shellAdmin = getCurrentAdmin();
$shellFlash = getFlash();
$shellNavItems = [
    ['type' => 'section', 'label' => 'Fees'],
    ['type' => 'link', 'label' => 'Dashboard', 'url' => APP_ROOT . '/fees/dashboard.php', 'key' => 'fees_dashboard', 'icon' => 'grid'],
    ['type' => 'link', 'label' => 'Students', 'url' => APP_ROOT . '/fees/students.php', 'key' => 'fees_students', 'icon' => 'users'],
    ['type' => 'link', 'label' => 'Post Payment', 'url' => APP_ROOT . '/fees/add_payment.php', 'key' => 'fees_add_payment', 'icon' => 'plus'],
    ['type' => 'section', 'label' => 'Management'],
    ['type' => 'link', 'label' => 'Groups', 'url' => APP_ROOT . '/fees/groups.php', 'key' => 'fees_groups', 'icon' => 'users'],
    ['type' => 'link', 'label' => 'Import Excel', 'url' => APP_ROOT . '/fees/import.php', 'key' => 'fees_import', 'icon' => 'upload'],
    ['type' => 'link', 'label' => 'Export Excel', 'url' => APP_ROOT . '/fees/export.php', 'key' => 'fees_export', 'icon' => 'download'],
];

require __DIR__ . '/../../shared/app_shell_header.php';
