-- ============================================================
-- KIMC Eldoret Campus — Fees Module Schema
-- Run this in phpMyAdmin AFTER kimc_inventory.sql
-- Uses the same database: kimc_inventory
-- ============================================================

USE if0_41234664_db_kimcinventory;

-- ============================================================
-- TABLE: fee_groups
-- The 4 intake/year groups (Cert May, Cert Sept, Dip 2nd, Dip 3rd)
-- ============================================================
CREATE TABLE IF NOT EXISTS fee_groups (
  group_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,              -- e.g. "CERT MAY-INTAKE"
  programme   ENUM('certificate','diploma') NOT NULL,
  intake      VARCHAR(30),                        -- e.g. "May 2025", "Sept 2025"
  year_label  VARCHAR(30),                        -- e.g. "Year 1", "Second Year"
  total_fees  DECIMAL(10,2) NOT NULL DEFAULT 0,   -- KES 129,000 or 201,000
  is_active   BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed the 4 groups from the Excel
INSERT IGNORE INTO fee_groups (group_id, name, programme, intake, year_label, total_fees) VALUES
  (1, 'CERT MAY-INTAKE',   'certificate', 'May 2025',  'Year 1',      129000.00),
  (2, 'CERT SEPT-INTAKE',  'certificate', 'Sept 2025', 'Year 1',      129000.00),
  (3, 'DIPLOMA SECOND YR', 'diploma',     NULL,        'Second Year', 201000.00),
  (4, 'DIPLOMA THIRD YR',  'diploma',     NULL,        'Third Year',  201000.00);

-- ============================================================
-- TABLE: fee_students
-- Each student enrolled in the fees module
-- student_id links conceptually to inventory users.student_id
-- but is stored independently (fees can have students not in inventory)
-- ============================================================
CREATE TABLE IF NOT EXISTS fee_students (
  fee_student_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id      VARCHAR(30) NOT NULL,           -- e.g. 12822/25
  full_name       VARCHAR(120) NOT NULL,
  programme       VARCHAR(100),                   -- e.g. CERT IN FILM PRODUCTION
  group_id        INT UNSIGNED NOT NULL,
  total_fees      DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student_id (student_id),
  INDEX idx_group (group_id),
  FOREIGN KEY (group_id) REFERENCES fee_groups(group_id)
);

-- ============================================================
-- TABLE: fee_payments
-- Every individual payment posted for a student
-- ============================================================
CREATE TABLE IF NOT EXISTS fee_payments (
  payment_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fee_student_id  INT UNSIGNED NOT NULL,
  amount          DECIMAL(10,2) NOT NULL,
  mode            ENUM('mpesa','helb','bank','ecitizen','smis','receipted','nairobi_campus','other')
                  NOT NULL DEFAULT 'mpesa',
  mpesa_number    VARCHAR(20) NULL,               -- phone or bank ref
  reference       VARCHAR(60) NULL,               -- transaction code e.g. TEDIK83AZ5
  date_paid       DATE NOT NULL,
  notes           TEXT NULL,
  posted_by       INT UNSIGNED NOT NULL,          -- admin_users.admin_id
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_student (fee_student_id),
  INDEX idx_date (date_paid),
  FOREIGN KEY (fee_student_id) REFERENCES fee_students(fee_student_id) ON DELETE CASCADE,
  FOREIGN KEY (posted_by) REFERENCES admin_users(admin_id)
);
