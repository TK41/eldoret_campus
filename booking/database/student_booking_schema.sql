-- ============================================================
-- Student online booking: accounts, custom kits, no student return date
-- Run ONCE in phpMyAdmin against your inventory database (select the
-- database first; no USE statement is included on purpose).
-- Requires: reservations_schema.sql already applied. Back up first.
-- ============================================================

-- Student login accounts, keyed by admission number (fee_students.student_id)
CREATE TABLE IF NOT EXISTS student_accounts (
  account_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id          VARCHAR(30) NOT NULL,
  password_hash       VARCHAR(255) NULL,
  activation_hash     CHAR(64) NULL,            -- sha256 of staff-issued code
  activation_expires  DATETIME NULL,            -- UTC
  activated_at        DATETIME NULL,
  last_login          DATETIME NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student_account (student_id)
);

-- Failed-attempt log used for lockouts and IP throttling
CREATE TABLE IF NOT EXISTS student_auth_attempts (
  attempt_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_type  ENUM('login','activate') NOT NULL,
  student_id    VARCHAR(30) NOT NULL,
  ip_address    VARCHAR(45) NOT NULL,
  attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt_student (attempt_type, student_id, attempted_at),
  INDEX idx_attempt_ip (attempt_type, ip_address, attempted_at)
);

-- Student-built kits ('custom'); the student no longer sends a return time
ALTER TABLE reservations
  MODIFY COLUMN reservation_type ENUM('single','kit','custom') NOT NULL,
  MODIFY COLUMN access_token_hash CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS kit_name VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS fee_student_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS student_name_snapshot VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS programme_snapshot VARCHAR(150) NULL;
