# KIMC Admissions Module

A plug-in admissions module for KIMC Eldoret Campus system (PHP/PDO/MariaDB).

## File Structure

```
admission_module/
├── database/
│   └── admissions_schema.sql       ← Run this FIRST in phpMyAdmin
├── admissions/
│   ├── partials/
│   │   ├── adm_header.php          ← Admin sidebar/nav (indigo theme)
│   │   └── adm_footer.php          ← Admin footer
│   ├── uploads/                    ← Auto-created; stores uploaded docs
│   ├── apply.php                   ← PUBLIC — 4-step application wizard
│   ├── dashboard.php               ← ADMIN — all applications list
│   ├── application.php             ← ADMIN — single application detail
│   └── view_doc.php                ← ADMIN — secure document stream
```

## Installation

### 1. Run the SQL
Import `database/admissions_schema.sql` into `kimc_inventory` via phpMyAdmin.

### 2. Copy the folder
Drop the `admissions/` folder into your `eldoret_campus/` root:
```
eldoret_campus/
├── admin/
├── fees/
├── exams/
├── admissions/      ← drop here
└── portal.php
```

### 3. Place the PDF form
Copy the official `KIMC_KAB_ADM_001.pdf` form into:
```
eldoret_campus/admissions/KIMC_KAB_ADM_001.pdf
```
This is served via the "Download Form (PDF)" button on the public page.

### 4. Secure the uploads folder
Add a `.htaccess` file inside `admissions/uploads/` to block direct browser access:
```apache
# admissions/uploads/.htaccess
Options -Indexes
Deny from all
```
Files are only served through `view_doc.php` which enforces login.

### 5. Set folder permissions
```bash
chmod 755 admissions/uploads/
```

---

## Access URLs

| URL | Who | Purpose |
|-----|-----|---------|
| `/admissions/apply.php` | Public (no login) | 4-step application form |
| `/admissions/dashboard.php` | Admin (login required) | Review all applications |
| `/admissions/application.php?id=X` | Admin | Single application detail + docs |
| `/admissions/view_doc.php?doc_id=X` | Admin | Securely stream uploaded file |

---

## Public Application Flow

1. Student visits `apply.php`
2. Downloads PDF form (optional — can also fill digitally via the online form)
3. Completes 4 steps: **Personal → Education → Documents → Declaration**
4. Uploads: Scanned Application Form, KCSE Cert, KCPE Cert, Birth Cert/National ID
5. Submits → receives **Reference Number** on screen (e.g. `KMC-2025-00042`)

## Required Documents

| Document | Required? |
|----------|-----------|
| Scanned Application Form | ✅ Mandatory |
| KCSE Certificate | ✅ Mandatory |
| KCPE Certificate | ✅ Mandatory |
| Birth Certificate | ✅ At least one of these two |
| National ID | ✅ At least one of these two |
| Passport Photo | Optional |

---

## Admin Workflow

1. Officer logs in → navigates to `/admissions/dashboard.php`
2. Views all applications with status, programme, doc count, submission date
3. Filters by status (Pending / Shortlisted / Admitted / Rejected) or programme
4. Clicks **View** on any application to see full details + uploaded documents
5. Clicks **View** on each document to open it inline in a new tab
6. Updates status and adds officer notes via the sidebar form

## Status Pipeline

```
Pending → Shortlisted → Admitted
                      ↘ Rejected
```

---

## Security Notes

- Uploaded files are stored with **random hex filenames** (no original names on disk)
- The `uploads/` directory should be blocked from direct web access via `.htaccess`
- All file downloads go through `view_doc.php` which enforces `requireLogin()`
- File types accepted: PDF, JPG, PNG only (enforced server-side via `mime_content_type()`)
- Max file size: 5 MB per file
- CSRF tokens protect all POST forms
