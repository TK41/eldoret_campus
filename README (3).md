# KIMC RBAC Module — Installation Guide

## Files in this package

```
rbac_module/
├── database/
│   └── rbac_schema.sql          ← Run this FIRST
├── auth/
│   ├── rbac.php                 ← Core RBAC helpers (new file)
│   └── login.php                ← Replace auth/login.php
├── admin/
│   └── manage_admins.php        ← New page: Users & Roles UI
├── portal.php                   ← Replace portal.php
└── README.md
```

---

## Step 1 — Run the SQL

Import `database/rbac_schema.sql` into `kimc_inventory` via phpMyAdmin.

This creates:
- `roles` table (5 default roles)
- `module_actions` table (all actions per module)
- `role_permissions` table (role → action mapping)
- Adds `role_id` column to `admin_users`
- Migrates existing users: superadmin → role_id 1, staff → role_id 5

---

## Step 2 — Copy the files

| This file | Goes to |
|-----------|---------|
| `auth/rbac.php` | `eldoret_campus/auth/rbac.php` (NEW) |
| `auth/login.php` | `eldoret_campus/auth/login.php` (REPLACE) |
| `admin/manage_admins.php` | `eldoret_campus/admin/manage_admins.php` (NEW) |
| `portal.php` | `eldoret_campus/portal.php` (REPLACE) |

---

## Step 3 — Add rbac.php to every module header

Add ONE line to each module's header partial, right after
`require_once __DIR__ . '/../auth/session.php';`

### admin/partials/header.php
```php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('inventory');
```

### fees/partials/header.php
```php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('fees');
```

### exams/partials/header.php
```php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('exams');
```

### admissions/partials/adm_header.php
```php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('admissions');
```

---

## Step 4 — Add action-level guards (optional but recommended)

For sensitive actions inside modules, wrap the logic with `requireDo()`:

### fees/add_payment.php (top of file, after requireLogin)
```php
require_once __DIR__ . '/../auth/rbac.php';
requireDo('fees.post_payment');
```

### fees/student.php (delete payment block)
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment'])) {
    if (!canDo('fees.delete_payment')) {
        setFlash('error', 'You do not have permission to delete payments.');
        header('Location: ' . APP_ROOT . '/fees/student.php?id=' . $id);
        exit;
    }
    // ... rest of delete logic
}
```

### admin/add_asset.php
```php
require_once __DIR__ . '/../auth/rbac.php';
requireDo('inventory.add_asset');
```

### exams/enter_marks.php
```php
require_once __DIR__ . '/../auth/rbac.php';
requireDo('exams.enter_marks');
```

### admin/manage_admins.php
```php
require_once __DIR__ . '/../auth/rbac.php';
requireDo('system.manage_users');
```

---

## Step 5 — Hide UI elements based on permissions

In any PHP template, use `canDo()` to show/hide buttons:

```php
<?php if (canDo('fees.delete_payment')): ?>
    <button class="btn btn-danger">Delete</button>
<?php endif; ?>

<?php if (canDo('inventory.add_asset')): ?>
    <a href="add_asset.php" class="btn btn-primary">Add Asset</a>
<?php endif; ?>
```

---

## Default Roles & Access

| Role | Module Access | Key Restrictions |
|------|--------------|------------------|
| **System Admin** | All modules | Full access everywhere |
| **Accountant** | Fees only | Can post payments, cannot delete |
| **Admissions Officer** | Admissions only | View & update status |
| **Lecturer** | Exams only | Enter marks, view results |
| **Inventory Staff** | Inventory only | Checkout/checkin, no delete |

---

## How login auto-redirect works

After login, `getDefaultRedirect()` in `rbac.php` checks how many
portal modules the user can access:

- **1 portal** → goes directly to that module dashboard (skips portal)
- **2+ portals** → goes to portal.php (shows only their cards)

Examples:
- Accountant logs in → straight to `fees/dashboard.php`
- Lecturer logs in → straight to `exams/dashboard.php`
- System Admin logs in → portal.php (sees all 4 cards)

---

## Adding a new portal in future

1. Add actions to `module_actions`:
```sql
INSERT INTO module_actions (module, action, label, sort_order) VALUES
('library', 'view',       'View Library Dashboard', 1),
('library', 'checkout',   'Checkout Books',          2),
('library', 'add_book',   'Add Books',               3);
```

2. Grant to relevant roles in `role_permissions`:
```sql
INSERT INTO role_permissions (role_id, action_id)
SELECT 5, action_id FROM module_actions WHERE module='library';
```

3. Add `requireAccess('library')` to the new module's header partial.

4. Add a card to `portal.php` wrapped in `<?php if (canAccess('library')): ?>`.

5. Update `getDefaultRedirect()` in `rbac.php` to include `'library'`
   in the match statement.

**No changes needed to any existing module.**

---

## Managing users

Visit: `https://yoursite/admin/manage_admins.php`

System Admin can:
- Create new users with any role
- Change a user's role via dropdown (takes effect on their next login)
- Reset any user's password
- Deactivate / reactivate accounts
- View the full permissions matrix (which role can do what)

> Role changes take effect the **next time the user logs in**,
> since permissions are loaded into the session at login time.
> To force immediate effect, ask the user to log out and back in.
