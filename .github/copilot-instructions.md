# KIMC Eldoret Campus Inventory System — AI Coding Agent Instructions

## Project Overview
A XAMPP-based PHP inventory management system for KIMC Eldoret Campus. Core purpose: track borrowable assets (books & equipment), manage checkouts/check-ins, enforce tier-based borrowing rules, and handle fines/notifications.

**Key Stack:** PHP (no framework), PDO/MySQL, vanilla JavaScript, session-based auth

---

## Architecture & Data Flow

### Core Entities
- **Assets** (`assets` table): Unified single table holding both books and equipment; distinguished by `asset_type` ENUM. See `admin/add_asset.php` for how form conditionally renders book vs. equipment fields.
- **Kits** (`kits` + `kit_components` tables): Equipment bundles (e.g., camera kit = body + lens). Kits themselves are checked out as units; `admin/kits.php` manages kit creation and component assignment.
- **Users** (`users` table): Borrowers with tiered permission levels (`tier_id` 1–4, from `tiers` table). Each tier defines max_books, book_loan_days, max_equipment, equip_loan_hrs, can_reserve, can_kit.
- **Transactions** (`transactions` table): Core audit log. Single record per checkout; `returned_at` NULL = still checked out. Stores condition ratings, fines, staff who processed it.
- **Notifications** (`notifications` table): Queued alerts (due_soon, overdue, fine_issued, etc.) with channel (email/SMS) and status tracking.

### Authentication & Session Flow
1. User visits `index.php` → checks `$_SESSION['admin_id']` → redirects to `/admin/dashboard.php` if logged in, else `/auth/login.php`
2. Login (`auth/login.php`): POST validates username + bcrypt password_hash, calls `session_regenerate_id(true)` for fixation prevention, populates session vars (admin_id, username, full_name, role).
3. Protected pages: `require_once __DIR__ . '/../auth/session.php'` then `requireLogin()` at top.
4. Session helpers in `auth/session.php`: `requireLogin()`, `getCurrentAdmin()`, `isSuperAdmin()`, `setFlash()` / `getFlash()` for one-time messages.

### Database Connection Pattern
- `config/db.php` defines `getDB(): PDO` — singleton pattern, connection reused per request.
- Uses prepared statements exclusively (prevents SQL injection): `$db->prepare("... WHERE id = ?")->execute([$id])`.
- Default fetch mode is `PDO::FETCH_ASSOC` (associative arrays).
- Error mode is `PDO::ERRMODE_EXCEPTION`.

### Admin Page Template Pattern
Every admin page (`admin/*.php`) follows this structure:
```php
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

$pageTitle  = 'Page Title';
$activePage = 'page_key';  // matches sidebar nav key
$db         = getDB();
$errors     = [];

// POST logic here

include __DIR__ . '/partials/header.php';  // includes HTML head + top nav
?>
<!-- Page content here -->
<?php include __DIR__ . '/partials/footer.php'; ?>
```
- `header.php` renders top navbar, sidebar, flash messages, theme toggle. References `$pageTitle`, `$activePage`, `$flash`.
- `footer.php` closes HTML, includes `assets/js/main.js`.

---

## Key Files & Responsibilities

| File | Purpose |
|------|---------|
| `config/db.php` | PDO singleton, app constants (DB_HOST, APP_ROOT, etc.) |
| `auth/session.php` | Session helpers: `requireLogin()`, `getCurrentAdmin()`, `isSuperAdmin()`, flash functions |
| `admin/dashboard.php` | Overview stats (total assets, overdue items, fines), recent transactions, due alerts |
| `admin/add_asset.php` | Form + POST handler to add books or equipment; includes kit assignment for equipment |
| `admin/assets.php` | List all assets with status filter, search, condition rating, quick-action buttons |
| `admin/kits.php` | Create kits, assign components (individual assets), view kit status |
| `admin/transactions.php` | Checkout/check-in forms, transaction log, fine tracking, condition reporting |
| `admin/users.php` | Borrower directory, tier assignment, active/inactive status, fines owed |
| `admin/notifications.php` | Pending alert queue; mark sent/failed; retry mechanism |
| `admin/permissions.php` | View/edit tier rules (max_books, loan_days, etc.) for each tier |
| `admin/settings.php` | System config: fine rates, alert thresholds, SMTP credentials |
| `admin/partials/header.php` | Shared HTML head, top navbar, sidebar nav menu, user dropdown |
| `admin/partials/footer.php` | HTML closing tags, includes main.js |
| `assets/css/style.css` | Main stylesheet (layout, components, grid) |
| `assets/css/theme.css` | CSS variables for light/dark theme (--color-primary, etc.) |
| `assets/js/main.js` | Theme toggle (localStorage), UI helpers (sidebar toggle, flash fade) |
| `auth/login.php` | Login form + session creation logic |
| `database/kimc_inventory.sql` | Full schema with seed data (tiers, default admin account) |

---

## Common Workflows & Patterns

### Adding a Workflow to an Admin Page
1. Check POST action: `if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'action_name') { ... }`
2. Validate inputs & collect errors into `$errors` array.
3. Use prepared statements for all DB queries.
4. On success: `setFlash('success', 'Message')` or `setFlash('error', 'Error message')`.
5. Redirect: `header('Location: ' . APP_ROOT . '/admin/page.php'); exit;`
6. Display errors/flash in HTML before form or at top of page.

Example (from `kits.php`):
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_kit') {
    $kit_code = strtoupper(trim($_POST['kit_code'] ?? ''));
    // ... validate, check unique, insert, setFlash, redirect
}
```

### Calculating Due Dates
Use tier-based loan periods from `tiers` table: books use `book_loan_days`, equipment uses `equip_loan_hrs`.
See `admin/transactions.php` `calcDueDate()` helper for pattern — uses DateTime manipulation, converts to MySQL format.

### Permission/Role Checks
- `isSuperAdmin()` returns true only if `$_SESSION['role'] === 'superadmin'`.
- Most pages don't enforce superadmin-only; role system is present but workflows are generally open to 'staff' as well.
- Future: wrap sensitive actions (delete, permission changes) in `if (isSuperAdmin()) { ... }` guards.

### Theme System
- HTML root has `data-theme="light"` (set in header.php).
- `assets/js/main.js` `toggleTheme()` swaps to 'dark', stores choice in localStorage.
- `assets/css/theme.css` defines CSS variables for both themes; stylesheet uses var() fallbacks.
- Dark theme is client-side only; no backend preference storage.

### Flash Messages
- `setFlash($type, $message)` stores in `$_SESSION['flash']` array.
- `getFlash()` retrieves and clears it (one-time display).
- `header.php` renders flash banner with appropriate color/icon.

---

## Database Query Patterns

### Fetch Single Row
```php
$stmt = $db->prepare("SELECT * FROM assets WHERE asset_id = ?");
$stmt->execute([$id]);
$asset = $stmt->fetch();  // array or false
```

### Fetch All Rows
```php
$results = $db->query("SELECT * FROM users WHERE tier_id = ?")->fetchAll();
```

### Count/Aggregate
```php
$count = $db->query("SELECT COUNT(*) FROM transactions WHERE returned_at IS NULL")->fetchColumn();
```

### Insert with Last ID
```php
$db->prepare("INSERT INTO kits (kit_code, name, description) VALUES (?, ?, ?)")
   ->execute([$kit_code, $name, $description]);
$kitId = $db->lastInsertId();
```

### Insert Ignore (for duplicates)
```php
$db->prepare("INSERT IGNORE INTO kit_components (kit_id, asset_id) VALUES (?, ?)")
   ->execute([$kitId, $assetId]);
```

---

## Configuration & Setup

### Environment
- **Runs on:** Apache (XAMPP), PHP 7.4+
- **Database:** MySQL/MariaDB
- **Entry point:** `index.php` (auto-redirects based on session)
- **App root:** Configured in `config/db.php` as `APP_ROOT` constant (e.g., '/kimc-inventory')
- **Default admin:** username `admin`, password `Admin@KIMC2024` (must change in production)

### To Add a New Admin Page
1. Create `admin/page_name.php` with standard template above.
2. Set `$activePage = 'page_name'` to match a sidebar nav item key (or create new).
3. Update `admin/partials/header.php` sidebar nav if it's a new top-level page.
4. Define form POST handlers following the pattern.

### To Add a New Table
1. Add to `database/kimc_inventory.sql` before running setup.
2. Use `FOREIGN KEY` constraints linking to existing tables.
3. Include indexes on frequently-filtered columns (e.g., `status`, `user_id`).
4. Update relevant pages' SQL queries.

---

## Important Notes for AI Agents

- **No ORM/Framework:** All SQL is hand-written. Ensure prepared statements are used everywhere to prevent injection.
- **Session-Based Auth Only:** No JWT or token-based auth. `$_SESSION` is the single source of truth.
- **Unified Asset Table:** Both books and equipment are in `assets` with an `asset_type` ENUM. When querying, filter by type if needed.
- **Tier System:** All borrowing limits are tier-based. Always check `users.tier_id` → `tiers.*` when enforcing limits.
- **Transactions Are Append-Only:** Once created, transactions are not deleted; soft-delete (retirement) is done via status changes.
- **Finalization Path:** Flash message + redirect is the standard success/error UX pattern.
- **No API Endpoints:** Currently all requests are form-based POST; no REST API.

---

## Quick Commands (Local Development)

Start XAMPP:
```bash
# Windows: Use XAMPP Control Panel GUI, or via CLI
C:\xampp\apache_start.bat
C:\xampp\mysql_start.bat
```

Access app:
```
http://localhost/kimc-inventory
```

Import fresh DB (phpMyAdmin → Import → select `database/kimc_inventory.sql`):
```
http://localhost/phpmyadmin
```

---

## Next Steps for Agents

When taking on a task:
1. Identify which table(s) are involved and their current state in the schema.
2. Verify the existing permission/role context (`$_SESSION['role']`).
3. Follow the POST validation → DB mutation → flash + redirect pattern.
4. Use `APP_ROOT` constant for all URLs (critical for relative path deployments).
5. Test in local XAMPP environment before committing changes.
