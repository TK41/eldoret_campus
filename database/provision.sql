-- Migration: add provisioning columns to admissions
ALTER TABLE admissions
  ADD COLUMN IF NOT EXISTS provisioned TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS provisioned_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS fee_student_id INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS inventory_user_id INT UNSIGNED DEFAULT NULL;

-- Run this file in phpMyAdmin or via MySQL CLI against the kimc_inventory database
