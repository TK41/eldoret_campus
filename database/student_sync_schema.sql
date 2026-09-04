-- Migration: fees / inventory student sync schema
-- Run this in phpMyAdmin or via MySQL CLI against the kimc_inventory database.

USE kimc_inventory;

ALTER TABLE fee_students
  ADD COLUMN IF NOT EXISTS email VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS national_id VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS gender ENUM('male','female','other') DEFAULT NULL;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS fee_student_id INT UNSIGNED DEFAULT NULL;

ALTER TABLE users
  ADD CONSTRAINT IF NOT EXISTS fk_inv_fee
    FOREIGN KEY (fee_student_id)
    REFERENCES fee_students(fee_student_id)
    ON DELETE CASCADE ON UPDATE CASCADE;
