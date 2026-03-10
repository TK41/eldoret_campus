<?php
// ============================================================
// auth/session.php
// Session management helpers
// Include this at the top of every protected admin page
// ============================================================

// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// requireLogin()
// Redirects to login page if no valid admin session exists
// Call at the top of every admin page
// ============================================================
function requireLogin(): void {
    // auto-expire session after 10 minutes of inactivity
    $timeout = 10 * 60; // seconds
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        // destroy old session and redirect with timeout flag
        session_unset();
        session_destroy();
        header('Location: ' . APP_ROOT . '/auth/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . APP_ROOT . '/auth/login.php');
        exit;
    }
}

// ============================================================
// getCurrentAdmin()
// Returns the current logged-in admin's session data as array
// ============================================================
function getCurrentAdmin(): array {
    return [
        'admin_id'   => $_SESSION['admin_id']   ?? null,
        'username'   => $_SESSION['username']   ?? '',
        'first_name' => $_SESSION['first_name'] ?? '',
        'full_name'  => $_SESSION['full_name']  ?? '',
        'role'       => $_SESSION['role']       ?? 'staff',
    ];
}

// ============================================================
// isSuperAdmin()
// Returns true only if current user has superadmin role
// ============================================================
function isSuperAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'superadmin';
}

// ============================================================
// setFlash() / getFlash()
// One-time session messages (success/error banners)
// ============================================================
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);  // consume it once
        return $flash;
    }
    return null;
}

// ============================================================
// csrfToken() / verifyCsrf()
// Basic CSRF protection for forms
// ============================================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }
}
?>
