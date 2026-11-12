<?php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('admissions');

$shellModule = 'admissions';
$shellModuleLabel = 'Admissions';
$shellDashboard = APP_ROOT . '/admissions/dashboard.php';
$shellAdmin = getCurrentAdmin();
$shellFlash = getFlash();
$shellNavItems = [
    ['type' => 'section', 'label' => 'Admissions'],
    ['type' => 'link', 'label' => 'Applications', 'url' => APP_ROOT . '/admissions/dashboard.php', 'key' => 'adm_dashboard', 'icon' => '📋'],
    ['type' => 'link', 'label' => 'Public Form', 'url' => APP_ROOT . '/admissions/apply.php', 'key' => '', 'icon' => '↗', 'external' => true],
];

require __DIR__ . '/../../shared/app_shell_header.php';
