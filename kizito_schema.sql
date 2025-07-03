-- Database Schema for 'kizito'
-- Tailored for ST KIZITO PREPARATORY SEMINARY RWEBISHURI (P5-P7 System)
-- Version 1.0

SET NAMES utf8mb4;
SET time_zone = '+03:00'; -- East Africa Time (EAT)
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

-- 1. academic_years
DROP TABLE IF EXISTS `academic_years`;
CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_name` varchar(9) NOT NULL COMMENT 'e.g., "2023/2024"',
  PRIMARY KEY (`id`),
  UNIQUE KEY `year_name` (`year_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default academic years
INSERT INTO `academic_years` (`year_name`) VALUES
('2023/2024'),
('2024/2025'),
('2025/2026');

-- 2. terms
DROP TABLE IF EXISTS `terms`;
CREATE TABLE `terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `term_name` varchar(10) NOT NULL COMMENT 'e.g., "Term I", "Term II"',
  PRIMARY KEY (`id`),
  UNIQUE KEY `term_name` (`term_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default terms
INSERT INTO `terms` (`term_name`) VALUES
('Term I'),
('Term II'),
('Term III');

-- 3. classes
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_name` varchar(10) NOT NULL COMMENT 'e.g., "P5", "P6"',
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_name` (`class_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default classes (P5-P7)
INSERT INTO `classes` (`class_name`) VALUES
('P5'),
('P6'),
('P7');

-- 4. subjects
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(20) NOT NULL COMMENT 'e.g., "ENG", "MTC"',
  `subject_name_full` varchar(100) NOT NULL COMMENT 'e.g., "English Language", "Mathematics"',
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_code` (`subject_code`),
  UNIQUE KEY `subject_name_full` (`subject_name_full`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default subjects (example for upper primary)
INSERT INTO `subjects` (`subject_code`, `subject_name_full`) VALUES
('ENG', 'English Language'),
('MTC', 'Mathematics'),
('SCI', 'Science'),
('SST', 'Social Studies'),
('RE', 'Religious Education');
-- Add other relevant P5-P7 subjects as needed

-- 5. students
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_name` varchar(255) NOT NULL,
  `lin_no` varchar(50) DEFAULT NULL,
  `current_class_id` int(11) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `lin_no` (`lin_no`),
  KEY `current_class_id` (`current_class_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`current_class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','teacher') NOT NULL DEFAULT 'teacher',
  `full_name` varchar(150) DEFAULT NULL,
  -- `email` varchar(100) DEFAULT NULL UNIQUE, -- Optional: Add if email login or notifications are needed in the future.
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Example admin user (password: "password123" - CHANGE THIS!)
-- Hashed using password_hash('password123', PASSWORD_DEFAULT)
INSERT INTO `users` (`username`, `password_hash`, `role`, `full_name`, `is_active`) VALUES
('admin', '$2y$10$k.oVixUnxG13z2wYijqM8uX0bJ4C2.p9.1E/X8xJjZ.0bLzQ8yH1m', 'admin', 'System Administrator', 1);

-- 7. report_batches
DROP TABLE IF EXISTS `report_batches`;
CREATE TABLE `report_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term_end_date` date DEFAULT NULL,
  `next_term_begin_date` date DEFAULT NULL,
  `import_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_batch` (`academic_year_id`,`term_id`,`class_id`),
  KEY `academic_year_id` (`academic_year_id`),
  KEY `term_id` (`term_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `report_batches_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `report_batches_ibfk_2` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `report_batches_ibfk_3` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 8. scores
DROP TABLE IF EXISTS `scores`;
CREATE TABLE `scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_batch_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `bot_score` decimal(5,1) DEFAULT NULL,
  `mot_score` decimal(5,1) DEFAULT NULL,
  `eot_score` decimal(5,1) DEFAULT NULL,
  `eot_remark` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_score_entry` (`report_batch_id`,`student_id`,`subject_id`),
  KEY `report_batch_id` (`report_batch_id`),
  KEY `student_id` (`student_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`report_batch_id`) REFERENCES `report_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `scores_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `scores_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 9. student_term_summaries
DROP TABLE IF EXISTS `student_term_summaries`;
CREATE TABLE `student_term_summaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `report_batch_id` int(11) NOT NULL,
  `aggregate_points` int(11) DEFAULT NULL,
  `division` varchar(10) DEFAULT NULL,
  `average_score` decimal(5,2) DEFAULT NULL,
  `position_in_class` int(11) DEFAULT NULL,
  `total_students_in_class` int(11) DEFAULT NULL,
  `class_teacher_remark` text DEFAULT NULL,
  `head_teacher_remark` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_summary_entry` (`student_id`,`report_batch_id`),
  KEY `student_id` (`student_id`),
  KEY `report_batch_id` (`report_batch_id`),
  CONSTRAINT `student_term_summaries_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `student_term_summaries_ibfk_2` FOREIGN KEY (`report_batch_id`) REFERENCES `report_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 10. activity_log
DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET foreign_key_checks = 1;
