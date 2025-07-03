-- Database Schema Definition
-- Version: v10 (based on interactions)
-- Generated on: 2024-07-08

SET NAMES utf8mb4;
SET time_zone = '+00:00'; -- UTC is recommended for server timezone
SET foreign_key_checks = 0; -- Disable checks during schema creation
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'; -- Or other appropriate SQL modes

--
-- Table structure for table `academic_years`
--
DROP TABLE IF EXISTS `academic_years`;
CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_name` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `year_name` (`year_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `classes`
--
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_name` varchar(50) NOT NULL,
  -- Add other relevant fields like 'level' (e.g., 'Lower Primary', 'Upper Primary') if they exist
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_name` (`class_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `terms`
--
DROP TABLE IF EXISTS `terms`;
CREATE TABLE `terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `term_name` varchar(50) NOT NULL, -- e.g., "I", "II", "III"
  `order_index` tinyint(4) DEFAULT NULL COMMENT 'Optional: For custom sorting of terms',
  PRIMARY KEY (`id`),
  UNIQUE KEY `term_name` (`term_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `subjects`
--
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(20) NOT NULL COMMENT 'e.g., mtc, eng, sst',
  `subject_name_full` varchar(100) NOT NULL COMMENT 'e.g., Mathematics, English Language',
  -- Add other relevant fields like 'department' or 'is_core_subject' if they exist
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_code` (`subject_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `users`
--
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','teacher','student','parent') NOT NULL DEFAULT 'teacher', -- Adjust roles as needed
  `full_name` varchar(150) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_dismissed_admin_activity_ts` timestamp NULL DEFAULT NULL COMMENT 'For superadmin activity feed dismissal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `students`
--
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_name` varchar(255) NOT NULL,
  `lin_no` varchar(50) DEFAULT NULL,
  `current_class_id` int(11) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `admission_date` date DEFAULT NULL,
  `parent_contact` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 for active, 0 for inactive/archived',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `lin_no` (`lin_no`), -- Assuming LIN should be unique if provided
  KEY `current_class_id` (`current_class_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`current_class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `report_batch_settings`
--
DROP TABLE IF EXISTS `report_batch_settings`;
CREATE TABLE `report_batch_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term_end_date` date DEFAULT NULL,
  `next_term_begin_date` date DEFAULT NULL,
  `import_date` timestamp NOT NULL DEFAULT current_timestamp(),
  -- Add other settings like 'report_generation_status', 'principal_signature_url' if needed
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_batch` (`academic_year_id`,`term_id`,`class_id`),
  KEY `term_id` (`term_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `report_batch_settings_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `report_batch_settings_ibfk_2` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `report_batch_settings_ibfk_3` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `scores`
--
DROP TABLE IF EXISTS `scores`;
CREATE TABLE `scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_batch_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `bot_score` decimal(5,1) DEFAULT NULL,
  `mot_score` decimal(5,1) DEFAULT NULL,
  `eot_score` decimal(5,1) DEFAULT NULL,
  `eot_remark` varchar(50) DEFAULT NULL, -- Added based on recent feature
  `eot_grade_on_report` varchar(10) DEFAULT NULL, -- If grades are stored after calculation
  `eot_points_on_report` tinyint(4) DEFAULT NULL, -- If points are stored after calculation
  `teacher_initials_on_report` varchar(10) DEFAULT NULL, -- If initials are stored per score
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_score_entry` (`report_batch_id`,`student_id`,`subject_id`),
  KEY `student_id` (`student_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`report_batch_id`) REFERENCES `report_batch_settings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `scores_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `scores_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `student_report_summary`
--
DROP TABLE IF EXISTS `student_report_summary`;
CREATE TABLE `student_report_summary` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `report_batch_id` int(11) NOT NULL,
  `p4p7_aggregate_points` int(11) DEFAULT NULL,
  `p4p7_division` varchar(10) DEFAULT NULL,
  `p1p3_total_eot_score` decimal(6,1) DEFAULT NULL,
  `p1p3_average_eot_score` decimal(5,2) DEFAULT NULL,
  `p1p3_position_in_class` int(11) DEFAULT NULL,
  `p1p3_total_students_in_class` int(11) DEFAULT NULL,
  `auto_classteachers_remark_text` text DEFAULT NULL,
  `auto_headteachers_remark_text` text DEFAULT NULL,
  `manual_classteachers_remark_text` text DEFAULT NULL, -- For manual overrides
  `manual_headteachers_remark_text` text DEFAULT NULL, -- For manual overrides
  `p1p3_total_bot_score` decimal(6,1) DEFAULT NULL,
  `p1p3_position_total_bot` int(11) DEFAULT NULL,
  `p1p3_total_mot_score` decimal(6,1) DEFAULT NULL,
  `p1p3_position_total_mot` int(11) DEFAULT NULL,
  `p1p3_position_total_eot` int(11) DEFAULT NULL,
  `p1p3_average_bot_score` decimal(5,2) DEFAULT NULL,
  `p1p3_average_mot_score` decimal(5,2) DEFAULT NULL,
  `p4p7_aggregate_bot_score` int(11) DEFAULT NULL,
  `p4p7_division_bot` varchar(10) DEFAULT NULL,
  `p4p7_aggregate_mot_score` int(11) DEFAULT NULL,
  `p4p7_division_mot` varchar(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_summary_entry` (`student_id`,`report_batch_id`),
  KEY `report_batch_id` (`report_batch_id`),
  CONSTRAINT `student_report_summary_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `student_report_summary_ibfk_2` FOREIGN KEY (`report_batch_id`) REFERENCES `report_batch_settings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `activity_log`
--
DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `notified_user_id` int(11) DEFAULT NULL COMMENT 'User ID to be notified, if any',
  `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'For notifications targeted at notified_user_id',
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `action_type` (`action_type`),
  KEY `timestamp` (`timestamp`),
  KEY `notified_user_id` (`notified_user_id`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `activity_log_ibfk_2` FOREIGN KEY (`notified_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Example: Add default users (superadmin, admin, teacher)
-- Ensure you change passwords immediately after setup if using defaults.
-- Password 'password123' hashed with password_hash('password123', PASSWORD_DEFAULT)
-- For a real system, these should be created via an interface or a secure setup script.
-- INSERT INTO `users` (`username`, `password_hash`, `role`, `full_name`, `email`, `is_active`) VALUES
-- ('superadmin', '$2y$10$exampleSuperAdminHash....', 'superadmin', 'Super Administrator', 'superadmin@example.com', 1),
-- ('adminuser', '$2y$10$exampleAdminHash.......', 'admin', 'Administrator User', 'admin@example.com', 1),
-- ('teacheruser', '$2y$10$exampleTeacherHash.....', 'teacher', 'Teacher User', 'teacher@example.com', 1);

-- Example: Populate basic lookup tables (if empty)
-- INSERT INTO `academic_years` (`year_name`) VALUES ('2023'), ('2024'), ('2025') ON DUPLICATE KEY UPDATE year_name=year_name;
-- INSERT INTO `classes` (`class_name`) VALUES ('P1'), ('P2'), ('P3'), ('P4'), ('P5'), ('P6'), ('P7') ON DUPLICATE KEY UPDATE class_name=class_name;
-- INSERT INTO `terms` (`term_name`, `order_index`) VALUES ('I', 1), ('II', 2), ('III', 3) ON DUPLICATE KEY UPDATE term_name=term_name;
-- INSERT INTO `subjects` (`subject_code`, `subject_name_full`) VALUES
-- ('english', 'English Language'), ('mtc', 'Mathematics'), ('re', 'Religious Education'),
-- ('lit1', 'Literacy ONE'), ('lit2', 'Literacy TWO'), ('local_lang', 'Local Language'),
-- ('science', 'Science'), ('sst', 'Social Studies'), ('kiswahili', 'Kiswahili')
-- ON DUPLICATE KEY UPDATE subject_code=subject_code;

SET foreign_key_checks = 1; -- Re-enable checks
