-- ============================================================
-- KIMC Eldoret Campus Inventory System
-- Database Schema for MariaDB (XAMPP compatible)
-- Run this file first in phpMyAdmin or via MySQL CLI
-- ============================================================

-- Create and select the database
CREATE DATABASE IF NOT EXISTS kimc_inventory
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE kimc_inventory;

-- ============================================================
-- TABLE: admin_users
-- Stores admin/staff login credentials
-- ============================================================
CREATE TABLE IF NOT EXISTS admin_users (
  admin_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(150) UNIQUE NOT NULL,
  username      VARCHAR(60) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,          -- bcrypt hashed password
  role          ENUM('superadmin','staff') NOT NULL DEFAULT 'staff',
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  last_login    DATETIME NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: tiers
-- Defines borrowing permission levels for students/users
-- ============================================================
CREATE TABLE IF NOT EXISTS tiers (
  tier_id         TINYINT UNSIGNED PRIMARY KEY,
  name            VARCHAR(60) NOT NULL,
  max_books       TINYINT UNSIGNED NOT NULL DEFAULT 3,
  book_loan_days  TINYINT UNSIGNED NOT NULL DEFAULT 14,
  max_equipment   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  equip_loan_hrs  SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  can_reserve     BOOLEAN NOT NULL DEFAULT FALSE,
  can_kit         BOOLEAN NOT NULL DEFAULT FALSE,
  is_admin        BOOLEAN NOT NULL DEFAULT FALSE
);

-- Seed default tier data
INSERT IGNORE INTO tiers VALUES
  (1, 'Certificate',        3, 14,  1,  24, FALSE, FALSE, FALSE),
  (2, 'Diploma',            5, 21, 2, 48, TRUE,  FALSE, FALSE),
  (3, 'Advanced / Honours', 8, 28,  4,  72, TRUE,  TRUE,  FALSE),
  (4, 'Staff / Faculty',    99,180, 99, 999, TRUE,  TRUE,  TRUE);

-- Update existing tier records to new names (for existing databases)
UPDATE IGNORE tiers SET name='Certificate' WHERE tier_id=1;
UPDATE IGNORE tiers SET name='Diploma' WHERE tier_id=2;
UPDATE IGNORE tiers SET name='Advanced / Honours' WHERE tier_id=3;
UPDATE IGNORE tiers SET name='Staff / Faculty' WHERE tier_id=4;

-- ============================================================
-- TABLE: users
-- Students and faculty who borrow items
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
  user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id    VARCHAR(30) UNIQUE NOT NULL,    -- e.g. KIMC/ELD/2024/001
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(150) UNIQUE NOT NULL,
  phone         VARCHAR(20),
  department    VARCHAR(100),
  tier_id       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  fee_student_id INT UNSIGNED DEFAULT NULL,
  fines_owed    DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_student_id (student_id),
  INDEX idx_fee_student_id (fee_student_id),
  INDEX idx_tier (tier_id),
  FOREIGN KEY (tier_id) REFERENCES tiers(tier_id)
);

-- ============================================================
-- TABLE: kits
-- Equipment bundles (e.g. Camera Kit = body + lens + battery)
-- ============================================================
CREATE TABLE IF NOT EXISTS kits (
  kit_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kit_code  VARCHAR(30) UNIQUE NOT NULL,       -- e.g. KIT-001
  name      VARCHAR(120) NOT NULL,
  description TEXT,
  min_tier  TINYINT UNSIGNED NOT NULL DEFAULT 2,
  status    ENUM('available','checked_out','reserved','incomplete','maintenance')
            NOT NULL DEFAULT 'available',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: assets
-- All borrowable items: books AND equipment in one table
-- asset_type flag distinguishes them
-- ============================================================
CREATE TABLE IF NOT EXISTS assets (
  asset_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_code      VARCHAR(30) UNIQUE NOT NULL,  -- e.g. EQ-034 or BK-1042
  name            VARCHAR(200) NOT NULL,
  asset_type      ENUM('equipment','book') NOT NULL,
  kit_id          INT UNSIGNED NULL,            -- belongs to a kit (optional)
  status          ENUM('available','checked_out','reserved','maintenance','retired','missing')
                  NOT NULL DEFAULT 'available',
  condition_rating ENUM('excellent','good','fair','damaged') NOT NULL DEFAULT 'good',
  barcode         VARCHAR(80),
  purchase_value  DECIMAL(10,2),
  -- Book-specific fields
  isbn            VARCHAR(20),
  author          VARCHAR(120),
  publisher       VARCHAR(120),
  year_published  YEAR,
  dewey_decimal   VARCHAR(30),
  -- Equipment-specific fields
  serial_number   VARCHAR(100),
  manufacturer    VARCHAR(100),
  model           VARCHAR(100),
  min_tier        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  -- Housekeeping
  notes           TEXT,
  added_by        INT UNSIGNED,                 -- FK → admin_users
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_asset_code (asset_code),
  INDEX idx_status (status),
  INDEX idx_type (asset_type),
  FOREIGN KEY (kit_id) REFERENCES kits(kit_id) ON DELETE SET NULL,
  FOREIGN KEY (added_by) REFERENCES admin_users(admin_id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE: kit_components
-- Links individual assets to their parent kit
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory_components (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_id  INT UNSIGNED NOT NULL,
  component_name VARCHAR(150) NOT NULL,
  component_code VARCHAR(80) NULL,
  serial_number VARCHAR(120) NULL,
  `condition`   ENUM('excellent','good','fair','damaged') NOT NULL DEFAULT 'good',
  status        ENUM('active','inactive','missing','damaged') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_component_code (component_code),
  KEY idx_inventory (inventory_id),
  FOREIGN KEY (inventory_id) REFERENCES assets(asset_id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE: kit_components
-- Links individual assets to their parent kit
-- ============================================================
CREATE TABLE IF NOT EXISTS kit_components (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kit_id      INT UNSIGNED NOT NULL,
  asset_id    INT UNSIGNED NOT NULL,
  is_required BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE KEY uq_kit_asset (kit_id, asset_id),
  FOREIGN KEY (kit_id)   REFERENCES kits(kit_id)   ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE
);

-- ============================================================
-- TABLES: reservations and their item-level allocation snapshot
-- ============================================================
CREATE TABLE IF NOT EXISTS reservations (
  reservation_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_reference   VARCHAR(32) NOT NULL UNIQUE,
  access_token_hash   CHAR(64) NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  reservation_type    ENUM('single','kit') NOT NULL,
  asset_id            INT UNSIGNED NULL,
  kit_id              INT UNSIGNED NULL,
  requested_start     DATETIME NOT NULL,
  requested_end       DATETIME NOT NULL,
  purpose             VARCHAR(500) NOT NULL,
  status              ENUM('pending','approved','rejected','cancelled','expired','issued','returned')
                      NOT NULL DEFAULT 'pending',
  rejection_reason    VARCHAR(500) NULL,
  submitted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decision_at         DATETIME NULL,
  decision_by         INT UNSIGNED NULL,
  issued_at           DATETIME NULL,
  issued_by           INT UNSIGNED NULL,
  cancelled_at        DATETIME NULL,
  returned_at         DATETIME NULL,
  INDEX idx_reservations_status_dates (status, requested_start, requested_end),
  INDEX idx_reservations_user_date (user_id, submitted_at),
  CONSTRAINT fk_reservation_user FOREIGN KEY (user_id) REFERENCES users(user_id),
  CONSTRAINT fk_reservation_asset FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservation_kit FOREIGN KEY (kit_id) REFERENCES kits(kit_id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservation_decider FOREIGN KEY (decision_by) REFERENCES admin_users(admin_id) ON DELETE SET NULL,
  CONSTRAINT fk_reservation_issuer FOREIGN KEY (issued_by) REFERENCES admin_users(admin_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS reservation_items (
  reservation_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id      INT UNSIGNED NOT NULL,
  asset_id            INT UNSIGNED NOT NULL,
  asset_code_snapshot VARCHAR(30) NOT NULL,
  asset_name_snapshot VARCHAR(200) NOT NULL,
  UNIQUE KEY uq_reservation_asset (reservation_id, asset_id),
  INDEX idx_reservation_item_asset (asset_id),
  CONSTRAINT fk_reservation_item_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_reservation_item_asset
    FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS reservation_events (
  event_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT UNSIGNED NOT NULL,
  event_type     VARCHAR(32) NOT NULL,
  staff_id       INT UNSIGNED NULL,
  detail         VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reservation_events (reservation_id, created_at),
  CONSTRAINT fk_reservation_event_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_reservation_event_staff
    FOREIGN KEY (staff_id) REFERENCES admin_users(admin_id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE: transactions
-- Core check-out / check-in audit log
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
  txn_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id        INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  staff_id        INT UNSIGNED NOT NULL,         -- admin who processed it
  checkout_at     DATETIME NOT NULL,
  due_at          DATETIME NOT NULL,
  returned_at     DATETIME NULL,
  returned_by     INT UNSIGNED NULL,
  reservation_id  INT UNSIGNED NULL,
  kit_group_id    VARCHAR(36) NULL,
  is_kit_txn      TINYINT(1) NOT NULL DEFAULT 0,
  condition_out   ENUM('excellent','good','fair','damaged') DEFAULT 'good',
  condition_in    ENUM('excellent','good','fair','damaged','missing') NULL,
  condition_note  TEXT,                          -- damage log field
  fine_amount     DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  fine_paid       BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (asset_id)  REFERENCES assets(asset_id),
  FOREIGN KEY (user_id)   REFERENCES users(user_id),
  FOREIGN KEY (staff_id)  REFERENCES admin_users(admin_id),
  FOREIGN KEY (returned_by) REFERENCES admin_users(admin_id) ON DELETE SET NULL,
  FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE SET NULL,
  INDEX idx_user (user_id),
  INDEX idx_asset (asset_id),
  INDEX idx_due (due_at),
  INDEX idx_returned (returned_at),
  INDEX idx_txn_filters (returned_at, due_at, user_id, checkout_at),
  INDEX idx_kit_group (kit_group_id),
  INDEX idx_txn_reservation (reservation_id)
);

CREATE INDEX idx_kit_components_asset ON kit_components (asset_id);

-- ============================================================
-- TABLE: notifications
-- Queue of email/SMS alerts for due items and overdue fines
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
  notif_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  txn_id        INT UNSIGNED NULL,
  type          ENUM('due_soon','overdue','reservation_confirm',
                     'fine_issued','damage_report') NOT NULL,
  channel       SET('email','sms') NOT NULL DEFAULT 'email',
  subject       VARCHAR(200),
  body          TEXT NOT NULL,
  status        ENUM('pending','sent','failed') DEFAULT 'pending',
  scheduled_at  DATETIME NOT NULL,
  sent_at       DATETIME NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (txn_id)  REFERENCES transactions(txn_id) ON DELETE SET NULL,
  INDEX idx_pending (status, scheduled_at)
);

-- Admin read state is separate from the email/SMS delivery status.
CREATE TABLE IF NOT EXISTS notification_reads (
  admin_id  INT UNSIGNED NOT NULL,
  notif_id  INT UNSIGNED NOT NULL,
  read_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id, notif_id),
  INDEX idx_notification_reads_notif (notif_id),
  FOREIGN KEY (admin_id) REFERENCES admin_users(admin_id) ON DELETE CASCADE,
  FOREIGN KEY (notif_id) REFERENCES notifications(notif_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: settings
-- Key-value store for system configuration
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL,
  description   VARCHAR(255)
);

-- Seed default settings
INSERT IGNORE INTO settings VALUES
  ('equip_fine_per_hour',  '5.00',                  'Fine in KES per hour for overdue equipment'),
  ('book_fine_per_day',    '10.00',                  'Fine in KES per day for overdue books'),
  ('equip_alert_hours',    '4',                      'Hours before due to send equipment alert'),
  ('book_alert_hours',     '24',                     'Hours before due to send book alert'),
  ('institution_name',     'KIMC Eldoret Campus',    'Name displayed in notifications'),
  ('smtp_host',            'smtp.gmail.com',          'SMTP server for email notifications'),
  ('smtp_port',            '587',                    'SMTP port'),
  ('smtp_user',            '',                       'SMTP username/email'),
  ('smtp_pass',            '',                       'SMTP password (app password)');

-- ============================================================
-- DEFAULT ADMIN ACCOUNT
-- Username: admin | Password: Admin@KIMC2024
-- Change this password immediately after first login!
-- ============================================================
INSERT IGNORE INTO admin_users (full_name, email, username, password_hash, role)
VALUES (
  'System Administrator',
  'admin@kimc.ac.ke',
  'admin',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Admin@KIMC2024
  'superadmin'
);
-- NOTE: The hash above is for password "Admin@KIMC2024"
-- Generate a new hash with: echo password_hash('YourPassword', PASSWORD_BCRYPT);
