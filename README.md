# KIMC Eldoret Campus — Inventory System
## Setup Guide for XAMPP

---

## 📁 File Structure
Paste this entire folder into your XAMPP `htdocs` directory:

```
C:\xampp\htdocs\kimc-inventory\
│
├── index.php                    ← Entry point (redirects to login)
│
├── config/
│   └── db.php                   ← Database credentials (EDIT THIS)
│
├── auth/
│   ├── session.php              ← Session helpers
│   ├── login.php                ← Admin login page
│   └── logout.php               ← Logout handler
│
├── admin/
│   ├── partials/
│   │   ├── header.php           ← Shared nav/sidebar
│   │   └── footer.php           ← Shared footer
│   ├── dashboard.php            ← Main dashboard
│   ├── assets.php               ← View all assets
│   ├── add_asset.php            ← Add books/equipment
│   ├── kits.php                 ← Kit management
│   ├── transactions.php         ← Check-out/in log
│   ├── notifications.php        ← Alert queue
│   ├── users.php                ← Borrower management
│   ├── permissions.php          ← Tier rules
│   └── settings.php             ← System config
│
├── assets/
│   ├── css/
│   │   ├── style.css            ← Main stylesheet
│   │   └── theme.css            ← Light/Dark theme variables
│   └── js/
│       └── main.js              ← Theme toggle, UI helpers
│
└── database/
    └── kimc_inventory.sql       ← Import this first!
```

---

## 🚀 Step-by-Step Setup

### Step 1 — Start XAMPP
Open XAMPP Control Panel and start:
- ✅ **Apache**
- ✅ **MySQL**

### Step 2 — Import the Database
1. Open your browser → go to `http://localhost/phpmyadmin`
2. Click **Import** in the top menu
3. Click **Choose File** → select `database/kimc_inventory.sql`
4. Click **Go** / **Import**

The database `kimc_inventory` will be created with all tables and default data.

### Step 3 — Configure Database Connection
Open `config/db.php` and check these values:
```php
define('DB_HOST', 'localhost');   // usually localhost for XAMPP
define('DB_NAME', 'kimc_inventory');
define('DB_USER', 'root');        // XAMPP default
define('DB_PASS', '');            // XAMPP default is empty
define('APP_ROOT', '/kimc-inventory');  // URL path - change if folder name differs
```

### Step 4 — Open in Browser
Visit: `http://localhost/kimc-inventory`

You will be redirected to the login page.

---

## 🔐 Default Login Credentials

| Field    | Value          |
|----------|----------------|
| Username | `admin`        |
| Password | `Admin@KIMC2024` |

> ⚠️ **Change this password immediately** after first login via the Settings page!

---

## 🌗 Light / Dark Theme
Click the **🌙 moon icon** in the top-right corner to switch themes.  
Your preference is saved in your browser (localStorage).

---

## 📋 Pasting Files into VS Code

Each file is clearly separated and self-contained:

| File | Paste as |
|------|----------|
| `database/kimc_inventory.sql` | New file → import via phpMyAdmin |
| `config/db.php` | `config/db.php` |
| `auth/session.php` | `auth/session.php` |
| `auth/login.php` | `auth/login.php` |
| `auth/logout.php` | `auth/logout.php` |
| `admin/partials/header.php` | `admin/partials/header.php` |
| `admin/partials/footer.php` | `admin/partials/footer.php` |
| `admin/dashboard.php` | `admin/dashboard.php` |
| `admin/add_asset.php` | `admin/add_asset.php` |
| `admin/assets.php` | `admin/assets.php` |
| `assets/css/style.css` | `assets/css/style.css` |
| `assets/css/theme.css` | `assets/css/theme.css` |
| `assets/js/main.js` | `assets/js/main.js` |

---

## 🔧 Troubleshooting

**"Database connection failed"**  
→ Check `config/db.php` — make sure DB_USER/DB_PASS match your XAMPP MySQL settings.

**Page shows but no styles**  
→ Confirm `APP_ROOT` in `config/db.php` matches your folder name exactly (case-sensitive on Linux).

**Login not working**  
→ Make sure you ran the `.sql` file — it seeds the default admin account.

**PHP errors showing**  
→ XAMPP requires PHP 7.4+ (PHP 8+ recommended). Check XAMPP control panel for PHP version.

---

## 🎒 Student equipment bookings

The student booking page is available at `http://localhost/eldoret_campus/booking/reservations.php`.
Students are validated against the existing active inventory `users` register and borrowing tier.
Requests remain pending until authorized inventory staff approve them; a pending request does
not reserve stock. Staff must verify the student's identity before approval and physical handover.
The portal issues a one-time access code with the booking reference so the student can view or
cancel a pending request without receiving access to other student records.

Staff manage requests from **Inventory → Reservations**. Approval rechecks the requested period,
including all kit components and active checkouts. Physical handover creates the existing
component-level `transactions` records with a reservation link. Staff use the existing Transactions
check-in workflow; kit components can be returned individually or as a complete remaining kit.
Staff can mark a component missing with a required note; it remains an open transaction and the kit
is marked incomplete until that component is returned or otherwise reconciled.

### Database setup for bookings

- **New installation:** import the updated `database/kimc_inventory.sql`.
- **Existing installation:** back up the database, then run `database/reservations_schema.sql`
  once in phpMyAdmin against `kimc_inventory`. The migration adds tables and transaction links;
  it does not delete or rewrite existing borrowing history. Keep the backup as the recovery path.

Only individually tracked equipment assets are reservable; quantity-managed stock is not present
in the current inventory model. Student login accounts, automated email/SMS delivery, and
institution-specific identity verification are not configured by this change. Staff identity
verification remains mandatory before approval and issue.
