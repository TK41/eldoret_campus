-- ============================================================
-- Migration: Add rejection_reason column to admissions table
-- Run once on your database before deploying the new files
-- ============================================================

ALTER TABLE admissions
    ADD COLUMN rejection_reason TEXT NULL DEFAULT NULL
    COMMENT 'Public-facing message shown to the applicant when their application is rejected'
    AFTER officer_notes;

-- Verify the column was added
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_NAME = 'admissions' AND COLUMN_NAME = 'rejection_reason';
