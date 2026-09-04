<?php
// ============================================================
// auth/rbac.php
// Role-Based Access Control helpers
// Include AFTER config/db.php and auth/session.php on every
// protected page:
//   require_once __DIR__ . '/../auth/rbac.php';
// ============================================================

// ── Permission cache (per request) ──────────────────────────
function _rbacPerms(): array {
    static $perms = null;
    if ($perms !== null) return $perms;

    $roleId = $_SESSION['role_id'] ?? null;
    if (!$roleId) { $perms = []; return $perms; }

    // Superadmin shortcut — all permissions
    if (($_SESSION['role_name'] ?? '') === 'superadmin') {
        $perms = ['*']; return $perms;
    }

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT CONCAT(ma.module, '.', ma.action) AS perm_key
        FROM role_permissions rp
        JOIN module_actions ma ON ma.action_id = rp.action_id
        WHERE rp.role_id = ?
    ");
    $stmt->execute([$roleId]);
    $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $perms;
}

// ── canDo('fees.post_payment') ───────────────────────────────
// Returns true if logged-in user has this permission
function canDo(string $permission): bool {
    $perms = _rbacPerms();
    if (in_array('*', $perms)) return true;   // superadmin
    return in_array($permission, $perms);
}

// ── canAccess('fees') ────────────────────────────────────────
// Returns true if user has ANY permission in this module
function canAccess(string $module): bool {
    $perms = _rbacPerms();
    if (in_array('*', $perms)) return true;
    foreach ($perms as $p) {
        if (str_starts_with($p, $module . '.')) return true;
    }
    return false;
}

// ── requireAccess('fees') ────────────────────────────────────
// Hard gate — redirect to portal if no module access
function requireAccess(string $module): void {
    if (!canAccess($module)) {
        setFlash('error', "You don't have permission to access that module.");
        header('Location: ' . APP_ROOT . '/portal.php');
        exit;
    }
}

// ── requireDo('fees.delete_payment') ────────────────────────
// Hard gate — redirect to portal if no specific action
function requireDo(string $permission): void {
    if (!canDo($permission)) {
        setFlash('error', "You don't have permission to perform that action.");
        header('Location: ' . APP_ROOT . '/portal.php');
        exit;
    }
}

// ── getAccessibleModules() ───────────────────────────────────
// Returns array of module keys the current user can access
// Used by portal.php to show only relevant cards
function getAccessibleModules(): array {
    $perms = _rbacPerms();
    if (in_array('*', $perms)) {
        return ['inventory', 'fees', 'exams', 'admissions', 'system'];
    }
    $modules = [];
    foreach ($perms as $p) {
        $mod = explode('.', $p)[0];
        if (!in_array($mod, $modules)) $modules[] = $mod;
    }
    return $modules;
}

// ── getDefaultRedirect() ─────────────────────────────────────
// Returns the URL a user should land on after login
// Single-module users skip the portal entirely
function getDefaultRedirect(): string {
    $modules = getAccessibleModules();

    // Remove 'system' from the redirect list — not a portal
    $portals = array_values(array_filter($modules, fn($m) => $m !== 'system'));

    if (count($portals) === 1) {
        // Single portal → go straight there
        return match($portals[0]) {
            'inventory'  => APP_ROOT . '/admin/dashboard.php',
            'fees'       => APP_ROOT . '/fees/dashboard.php',
            'exams'      => APP_ROOT . '/exams/dashboard.php',
            'admissions' => APP_ROOT . '/admissions/dashboard.php',
            default      => APP_ROOT . '/portal.php',
        };
    }

    // Multiple portals → show portal chooser
    return APP_ROOT . '/portal.php';
}

// ── loadUserRbacSession() ────────────────────────────────────
// Call this during login to populate RBAC session data
function loadUserRbacSession(int $adminId): void {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT r.role_id, r.name AS role_name, r.label AS role_label
        FROM admin_users au
        LEFT JOIN roles r ON r.role_id = au.role_id
        WHERE au.admin_id = ?
    ");
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();

    if ($row) {
        $_SESSION['role_id']    = $row['role_id'];
        $_SESSION['role_name']  = $row['role_name'];
        $_SESSION['role_label'] = $row['role_label'];
    } else {
        // Fallback: no role assigned → read from legacy role column
        $leg = $db->prepare("SELECT role FROM admin_users WHERE admin_id=?");
        $leg->execute([$adminId]);
        $r = $leg->fetchColumn();
        $_SESSION['role_name']  = $r ?: 'staff';
        $_SESSION['role_label'] = $r === 'superadmin' ? 'System Admin' : 'Staff';
        $_SESSION['role_id']    = null;
    }
}
