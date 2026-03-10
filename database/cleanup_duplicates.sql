-- ============================================================
-- KIMC Fees Module — Duplicate Cleanup
-- Run this in phpMyAdmin BEFORE re-running the Excel import
-- This wipes all fee_students and fee_payments so you start fresh
-- ============================================================

USE kimc_inventory;

-- Step 1: Remove all payments first (foreign key order)
DELETE FROM fee_payments;

-- Step 2: Remove all students
DELETE FROM fee_students;

-- Step 3: Reset auto-increment counters
ALTER TABLE fee_payments AUTO_INCREMENT = 1;
ALTER TABLE fee_students AUTO_INCREMENT = 1;

-- Done. Now go to Fees → Import Excel and run the import once.
