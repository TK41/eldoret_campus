-- ============================================================
-- database/rbac_schema.sql
-- Role-Based Access Control — KIMC Eldoret Campus
-- Run this AFTER kimc_inventory is set up
-- ============================================================

-- ── 1. Roles table ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `roles` (
  `role_id`    int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       varchar(40)  NOT NULL COMMENT 'machine key e.g. superadmin',
  `label`      varchar(80)  NOT NULL COMMENT 'human label e.g. System Admin',
  `is_system`  tinyint(1)   NOT NULL DEFAULT 0 COMMENT 'system roles cannot be deleted',
  `created_at` timestamp    NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `uq_role_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ── 2. Module actions — what actions exist per module ───────
CREATE TABLE IF NOT EXISTS `module_actions` (
  `action_id`   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `module`      varchar(40)  NOT NULL COMMENT 'e.g. fees, inventory, exams, admissions',
  `action`      varchar(60)  NOT NULL COMMENT 'e.g. view, post_payment, delete_payment',
  `label`       varchar(100) NOT NULL COMMENT 'human label for UI',
  `sort_order`  tinyint(3)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`action_id`),
  UNIQUE KEY `uq_module_action` (`module`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ── 3. Role permissions — which roles can do which actions ──
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `perm_id`   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`   int(10) UNSIGNED NOT NULL,
  `action_id` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`perm_id`),
  UNIQUE KEY `uq_role_action` (`role_id`, `action_id`),
  CONSTRAINT `fk_rp_role`   FOREIGN KEY (`role_id`)   REFERENCES `roles`          (`role_id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_rp_action` FOREIGN KEY (`action_id`) REFERENCES `module_actions` (`action_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ── 4. Add role_id to admin_users ───────────────────────────
-- (Keeps existing role column for backward compat during migration)
ALTER TABLE `admin_users`
  ADD COLUMN IF NOT EXISTS `role_id` int(10) UNSIGNED DEFAULT NULL AFTER `role`,
  ADD CONSTRAINT `fk_au_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL;

-- ── 5. Seed: Roles ──────────────────────────────────────────
INSERT IGNORE INTO `roles` (`role_id`, `name`, `label`, `is_system`) VALUES
(1, 'superadmin',         'System Admin',       1),
(2, 'accountant',         'Accountant',         0),
(3, 'admissions_officer', 'Admissions Officer', 0),
(4, 'lecturer',           'Lecturer',           0),
(5, 'inventory_staff',    'Inventory Staff',    0);

-- ── 6. Seed: Module actions ─────────────────────────────────

-- INVENTORY actions
INSERT IGNORE INTO `module_actions` (`module`, `action`, `label`, `sort_order`) VALUES
('inventory', 'view',           'View Inventory Dashboard',    1),
('inventory', 'checkout',       'Checkout Equipment/Books',    2),
('inventory', 'checkin',        'Check In Equipment/Books',    3),
('inventory', 'add_asset',      'Add New Assets',              4),
('inventory', 'edit_asset',     'Edit Assets',                 5),
('inventory', 'delete_asset',   'Delete Assets',               6),
('inventory', 'manage_kits',    'Manage Kit Bundles',          7),
('inventory', 'view_reports',   'View Reports & Exports',      8);

-- FEES actions
INSERT IGNORE INTO `module_actions` (`module`, `action`, `label`, `sort_order`) VALUES
('fees', 'view',             'View Fees Dashboard',         1),
('fees', 'view_students',    'View Student Fee Records',    2),
('fees', 'post_payment',     'Post Payments',               3),
('fees', 'delete_payment',   'Delete Payments',             4),
('fees', 'add_student',      'Add Fee Students',            5),
('fees', 'edit_student',     'Edit Fee Students',           6),
('fees', 'manage_groups',    'Manage Fee Groups',           7),
('fees', 'export',           'Export Fee Data',             8);

-- EXAMS actions
INSERT IGNORE INTO `module_actions` (`module`, `action`, `label`, `sort_order`) VALUES
('exams', 'view',            'View Exams Dashboard',        1),
('exams', 'enter_marks',     'Enter Student Marks',         2),
('exams', 'view_results',    'View Results & Transcripts',  3),
('exams', 'manage_sessions', 'Create/Lock Exam Sessions',   4),
('exams', 'manage_units',    'Manage Units/Subjects',       5),
('exams', 'view_analytics',  'View Analytics & Rankings',   6);

-- ADMISSIONS actions
INSERT IGNORE INTO `module_actions` (`module`, `action`, `label`, `sort_order`) VALUES
('admissions', 'view',           'View Admissions Dashboard', 1),
('admissions', 'view_applicant', 'View Applicant Details',    2),
('admissions', 'update_status',  'Update Application Status', 3);

-- SYSTEM actions (admin panel)
INSERT IGNORE INTO `module_actions` (`module`, `action`, `label`, `sort_order`) VALUES
('system', 'manage_users',   'Manage Admin Users',          1),
('system', 'manage_roles',   'Manage Roles & Permissions',  2),
('system', 'view_settings',  'View System Settings',        3),
('system', 'edit_settings',  'Edit System Settings',        4);

-- ── 7. Seed: superadmin gets ALL permissions ─────────────────
INSERT IGNORE INTO `role_permissions` (`role_id`, `action_id`)
SELECT 1, action_id FROM `module_actions`;

-- ── 8. Seed: accountant — fees only ─────────────────────────
INSERT IGNORE INTO `role_permissions` (`role_id`, `action_id`)
SELECT 2, action_id FROM `module_actions`
WHERE module = 'fees' AND action IN ('view','view_students','post_payment','export');

-- ── 9. Seed: admissions_officer — admissions only ───────────
INSERT IGNORE INTO `role_permissions` (`role_id`, `action_id`)
SELECT 3, action_id FROM `module_actions`
WHERE module = 'admissions';

-- ── 10. Seed: lecturer — exams (enter + view) ───────────────
INSERT IGNORE INTO `role_permissions` (`role_id`, `action_id`)
SELECT 4, action_id FROM `module_actions`
WHERE module = 'exams' AND action IN ('view','enter_marks','view_results','view_analytics');

-- ── 11. Seed: inventory_staff — inventory (no delete) ───────
INSERT IGNORE INTO `role_permissions` (`role_id`, `action_id`)
SELECT 5, action_id FROM `module_actions`
WHERE module = 'inventory' AND action IN ('view','checkout','checkin','view_reports');

-- ── 12. Migrate existing users → role_id ────────────────────
UPDATE `admin_users` SET `role_id` = 1 WHERE `role` = 'superadmin' AND `role_id` IS NULL;
UPDATE `admin_users` SET `role_id` = 5 WHERE `role` = 'staff'      AND `role_id` IS NULL;
