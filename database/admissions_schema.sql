-- ============================================================
-- database/admissions_schema.sql
-- Admissions Module — KIMC Eldoret Campus
-- Run once against kimc_inventory database
-- ============================================================

CREATE TABLE IF NOT EXISTS `admissions` (
  `admission_id`        int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_no`        varchar(20)  NOT NULL COMMENT 'e.g. KMC-2025-00042',
  -- Programme
  `programme_type`      enum('certificate','diploma','postgraduate') NOT NULL,
  `programme_name`      varchar(150) NOT NULL,
  `study_mode`          enum('regular','self_sponsored') NOT NULL DEFAULT 'regular',
  -- Personal
  `surname`             varchar(80)  NOT NULL,
  `middle_name`         varchar(80)  DEFAULT NULL,
  `first_name`          varchar(80)  NOT NULL,
  `date_of_birth`       date         DEFAULT NULL,
  `gender`              enum('male','female','other') DEFAULT NULL,
  `nationality`         varchar(60)  DEFAULT NULL,
  `national_id`         varchar(30)  DEFAULT NULL,
  `mobile_no`           varchar(20)  NOT NULL,
  `email`               varchar(120) DEFAULT NULL,
  -- Address
  `po_box`              varchar(20)  DEFAULT NULL,
  `postal_code`         varchar(10)  DEFAULT NULL,
  `city_town`           varchar(60)  DEFAULT NULL,
  `county`              varchar(60)  DEFAULT NULL,
  `sub_county`          varchar(60)  DEFAULT NULL,
  -- How they heard
  `heard_via`           varchar(60)  DEFAULT NULL,
  -- Declaration
  `declaration_agreed`  tinyint(1)   NOT NULL DEFAULT 0,
  -- Status
  `status`              enum('pending','shortlisted','admitted','rejected') NOT NULL DEFAULT 'pending',
  `officer_notes`       text         DEFAULT NULL,
  -- Meta
  `ip_address`          varchar(45)  DEFAULT NULL,
  `submitted_at`        timestamp    NULL DEFAULT current_timestamp(),
  `reviewed_at`         timestamp    NULL DEFAULT NULL,
  `reviewed_by`         int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`admission_id`),
  UNIQUE KEY `uq_ref` (`reference_no`),
  KEY `idx_status` (`status`),
  KEY `idx_submitted` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `admission_documents` (
  `doc_id`        int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `admission_id`  int(10) UNSIGNED NOT NULL,
  `doc_type`      enum('application_form','kcse_cert','kcpe_cert','birth_cert','national_id','passport_photo','mpesa_proof','other') NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name`   varchar(255) NOT NULL COMMENT 'Hashed filename on disk',
  `file_size`     int(10) UNSIGNED DEFAULT NULL,
  `mime_type`     varchar(80)  DEFAULT NULL,
  `uploaded_at`   timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`doc_id`),
  KEY `idx_admission` (`admission_id`),
  CONSTRAINT `fk_doc_admission` FOREIGN KEY (`admission_id`) REFERENCES `admissions` (`admission_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

ALTER TABLE `admissions`
  ADD CONSTRAINT `fk_adm_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`admin_id`) ON DELETE SET NULL;
