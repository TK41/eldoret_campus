-- ============================================================
-- database/exams_schema.sql
-- Exam Results Module — KIMC Eldoret Campus
-- Run this once against kimc_inventory database
-- ============================================================

-- Units/Subjects taught at KIMC
CREATE TABLE IF NOT EXISTS `exam_units` (
  `unit_id`     int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_code`   varchar(20)  NOT NULL,
  `unit_name`   varchar(120) NOT NULL,
  `programme`   enum('certificate','diploma') NOT NULL DEFAULT 'certificate',
  `year_level`  varchar(30)  DEFAULT NULL COMMENT 'e.g. Year 1, Year 2',
  `semester`    tinyint(1)   DEFAULT 1 COMMENT '1 or 2',
  `is_active`   tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  timestamp    NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`unit_id`),
  UNIQUE KEY `uq_unit_code` (`unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Exam series/sessions (e.g. "May 2025 End of Semester")
CREATE TABLE IF NOT EXISTS `exam_sessions` (
  `session_id`   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         varchar(120) NOT NULL COMMENT 'e.g. End of Semester 1 — May 2025',
  `programme`    enum('certificate','diploma') NOT NULL DEFAULT 'certificate',
  `year_level`   varchar(30)  DEFAULT NULL,
  `semester`     tinyint(1)   DEFAULT 1,
  `academic_year`varchar(20)  NOT NULL COMMENT 'e.g. 2024/2025',
  `is_locked`    tinyint(1)   NOT NULL DEFAULT 0 COMMENT 'When locked no new entries allowed',
  `created_by`   int(10) UNSIGNED DEFAULT NULL,
  `created_at`   timestamp    NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`session_id`),
  KEY `idx_programme` (`programme`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Marks for each student per unit per session
CREATE TABLE IF NOT EXISTS `exam_results` (
  `result_id`   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`  int(10) UNSIGNED NOT NULL,
  `unit_id`     int(10) UNSIGNED NOT NULL,
  `student_id`  varchar(30) NOT NULL COMMENT 'Links to fee_students.student_id',
  `ca_score`    decimal(5,2) DEFAULT NULL COMMENT 'Continuous Assessment out of 30',
  `exam_score`  decimal(5,2) DEFAULT NULL COMMENT 'Final Exam out of 70',
  `total`       decimal(5,2) GENERATED ALWAYS AS (COALESCE(`ca_score`,0) + COALESCE(`exam_score`,0)) STORED,
  `grade`       varchar(3)   GENERATED ALWAYS AS (
                  CASE
                    WHEN (COALESCE(`ca_score`,0) + COALESCE(`exam_score`,0)) >= 70 THEN 'A'
                    WHEN (COALESCE(`ca_score`,0) + COALESCE(`exam_score`,0)) >= 60 THEN 'B'
                    WHEN (COALESCE(`ca_score`,0) + COALESCE(`exam_score`,0)) >= 50 THEN 'C'
                    WHEN (COALESCE(`ca_score`,0) + COALESCE(`exam_score`,0)) >= 40 THEN 'D'
                    ELSE 'F'
                  END
                ) STORED,
  `remarks`     varchar(255) DEFAULT NULL,
  `entered_by`  int(10) UNSIGNED DEFAULT NULL,
  `updated_by`  int(10) UNSIGNED DEFAULT NULL,
  `created_at`  timestamp NULL DEFAULT current_timestamp(),
  `updated_at`  timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`result_id`),
  UNIQUE KEY `uq_result` (`session_id`,`unit_id`,`student_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Foreign keys (add only if InnoDB supports them in your setup)
ALTER TABLE `exam_results`
  ADD CONSTRAINT `fk_er_session` FOREIGN KEY (`session_id`) REFERENCES `exam_sessions` (`session_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_er_unit`    FOREIGN KEY (`unit_id`)    REFERENCES `exam_units`    (`unit_id`)    ON DELETE CASCADE;

ALTER TABLE `exam_sessions`
  ADD CONSTRAINT `fk_es_admin` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`admin_id`) ON DELETE SET NULL;

-- ── Seed default KIMC units ──
INSERT IGNORE INTO `exam_units` (`unit_code`, `unit_name`, `programme`, `year_level`, `semester`) VALUES
('CFP-101', 'Introduction to Film Production',      'certificate', 'Year 1', 1),
('CFP-102', 'Camera & Lighting Techniques',         'certificate', 'Year 1', 1),
('CFP-103', 'Sound Design & Recording',             'certificate', 'Year 1', 1),
('CFP-104', 'Scriptwriting & Storytelling',         'certificate', 'Year 1', 1),
('CFP-105', 'Post-Production & Editing',            'certificate', 'Year 1', 2),
('CFP-106', 'Media Ethics & Communication',         'certificate', 'Year 1', 2),
('DFP-201', 'Advanced Cinematography',              'diploma',     'Year 2', 1),
('DFP-202', 'Film Directing',                       'diploma',     'Year 2', 1),
('DFP-203', 'Documentary Production',               'diploma',     'Year 2', 1),
('DFP-204', 'Advanced Post-Production',             'diploma',     'Year 2', 2),
('DFP-205', 'Media Law & Intellectual Property',    'diploma',     'Year 2', 2),
('DFP-301', 'Feature Film Production',              'diploma',     'Year 3', 1),
('DFP-302', 'Broadcast Journalism',                 'diploma',     'Year 3', 1),
('DFP-303', 'Research Project & Thesis',            'diploma',     'Year 3', 2);
