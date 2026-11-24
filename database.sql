-- ============================================================
-- UIS Driver Scheduling and Management System (DSMS)
-- database.sql  -  SCHEMA (SRS UC001-UC028, class diagram) + demo data
--
-- Import it with phpMyAdmin (Import) or:   mysql -uroot < database.sql
-- It DROPS and re-creates the database uis_driver_db.
--
-- Demo passwords are listed in LOGIN_ACCOUNTS.txt (stored as bcrypt hashes here).
-- Demo dates are relative to the day of the import.
--
-- Tables (class diagram -> table)
--   Department        departments          (reports to = parent_id)
--   User + roles      users                (role: staff | supervisor (Head of Department) | driver | admin (Administrative Assistant) | head_admin)
--   Driver            drivers              (licence classes, licence expiry, start date of service)
--   Vehicle           vehicles
--   Request           requests             + request_vehicles (the vehicles a request reserves, vendor buses)
--   Task              tasks                (request tasks: outbound/return, internal tasks, welfare duty days)
--   WelfareDuty       welfare_weeks
--   TaskReport        task_reports
--   ScheduleChange    schedule_changes
--   DriverReplacement driver_replacements
--   Notification      notifications        (+ email_log, whatsapp_log)
--   status history    request_history      (every status change with the reason, user, date and time)
--   verification      verification_codes   (UC014: register and reset password)
-- ============================================================

DROP DATABASE IF EXISTS uis_driver_db;
CREATE DATABASE uis_driver_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uis_driver_db;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+08:00';

-- ============================================================
-- TABLES
-- ============================================================

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(120) NOT NULL,
  `parent_id` int(11) DEFAULT NULL COMMENT 'the department this one reports to (its Head of Department approves requests of this Head of Department)',
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `uq_department_name` (`department_name`),
  KEY `idx_department_parent` (`parent_id`),
  CONSTRAINT `fk_department_parent` FOREIGN KEY (`parent_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_no` varchar(30) NOT NULL COMMENT 'UIS staff ID',
  `full_name` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL COMMENT 'UIS email',
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('staff','supervisor','driver','admin','head_admin') NOT NULL DEFAULT 'staff',
  `pending_role` enum('supervisor') DEFAULT NULL COMMENT 'Head of Department role waiting for confirmation by the Administrative Assistant',
  `department_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_staff_no` (`staff_no`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_department` (`department_id`,`role`,`status`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `drivers` (
  `driver_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `licence_classes` varchar(20) NOT NULL COMMENT 'comma list of D, GDL, PSV',
  `licence_expiry` date NOT NULL,
  `start_date` date NOT NULL COMMENT 'start date of service (experience in Recommend Driver)',
  `photo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`driver_id`),
  UNIQUE KEY `uq_drivers_user` (`user_id`),
  CONSTRAINT `fk_drivers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL AUTO_INCREMENT,
  `plate_number` varchar(20) NOT NULL,
  `vehicle_type` enum('Car','MPV','Van','Bus','Lorry') NOT NULL,
  `brand` varchar(50) NOT NULL,
  `model` varchar(50) NOT NULL,
  `fuel_type` enum('Petrol','Diesel','Electric','Hybrid') NOT NULL DEFAULT 'Petrol',
  `capacity` int(11) NOT NULL,
  `licence_required` enum('D','GDL','PSV') NOT NULL DEFAULT 'D',
  `current_mileage` int(11) NOT NULL DEFAULT 0,
  `fuel_level` enum('Full','3/4','1/2','1/4','Empty') NOT NULL DEFAULT 'Full',
  `status` enum('active','maintenance','inactive') NOT NULL DEFAULT 'active',
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`vehicle_id`),
  UNIQUE KEY `uq_vehicles_plate` (`plate_number`),
  KEY `idx_vehicles_type` (`vehicle_type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `requester_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL COMMENT 'department of the requester when the request was made',
  `approver_id` int(11) DEFAULT NULL COMMENT 'the Head of Department who approves (NULL for a Personal request)',
  `vehicle_type` enum('Car','MPV','Van','Bus','Lorry') NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `driver_option` enum('with_driver','pay_claim','self_drive') NOT NULL DEFAULT 'with_driver',
  `purpose_type` enum('official','unofficial','personal') NOT NULL DEFAULT 'official',
  `purpose` varchar(500) NOT NULL,
  `destination` varchar(200) NOT NULL,
  `address` varchar(500) NOT NULL COMMENT 'full address of the destination',
  `waiting_location` varchar(200) NOT NULL,
  `depart_at` datetime NOT NULL,
  `return_at` datetime NOT NULL,
  `passengers` int(11) NOT NULL DEFAULT 1,
  `distance_km` decimal(8,1) DEFAULT NULL COMMENT 'round-trip distance (Personal)',
  `charge` decimal(10,2) DEFAULT NULL COMMENT 'distance x charge per km (Personal)',
  `is_urgent` tinyint(1) NOT NULL DEFAULT 0,
  `task_hours` decimal(4,1) NOT NULL DEFAULT 1.0 COMMENT 'length of the outbound and the return task',
  `status` enum('pending','hod_approved','driver_assigned','approved','rejected','cancelled','lapsed','completed') NOT NULL DEFAULT 'pending',
  `charge_confirmed_at` datetime DEFAULT NULL COMMENT 'Personal Self Drive: charge confirmed by the Administrative Assistant, waiting for the Head of Admin',
  `approval_email_at` datetime DEFAULT NULL COMMENT 'UC019: when the approval email was sent (NULL = to be sent again)',
  `reminder_sent_at` datetime DEFAULT NULL COMMENT 'UC020: reminder email sent once',
  `hod_approved_at` datetime DEFAULT NULL,
  `hod_approved_by` int(11) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `hoa_approved_at` datetime DEFAULT NULL,
  `hoa_approved_by` int(11) DEFAULT NULL,
  `assign_reject_reason` varchar(500) DEFAULT NULL COMMENT 'last driver assignment returned by the Head of Admin',
  `reject_reason` varchar(500) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `cancel_reason` varchar(500) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'date submitted',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `idx_requests_requester` (`requester_id`,`created_at`),
  KEY `idx_requests_approver` (`approver_id`,`status`),
  KEY `idx_requests_status` (`status`,`created_at`),
  KEY `idx_requests_period` (`depart_at`,`return_at`),
  CONSTRAINT `fk_requests_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_requests_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_requests_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `request_vehicles` (
  `rv_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `vehicle_id` int(11) DEFAULT NULL COMMENT 'NULL for a vendor bus',
  `is_vendor` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Vendor Bus Needed',
  `vendor_name` varchar(150) DEFAULT NULL,
  `vendor_plate` varchar(20) DEFAULT NULL,
  `start_dt` datetime NOT NULL COMMENT 'departure',
  `end_dt` datetime NOT NULL COMMENT 'return + 1 hour',
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = reservation released (rejected, cancelled, lapsed)',
  PRIMARY KEY (`rv_id`),
  KEY `idx_rv_request` (`request_id`),
  KEY `idx_rv_vehicle_time` (`vehicle_id`,`active`,`start_dt`,`end_dt`),
  CONSTRAINT `fk_rv_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rv_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `request_documents` (
  `doc_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `doc_type` varchar(30) NOT NULL DEFAULT 'supporting_letter',
  `original_name` varchar(255) NOT NULL,
  `stored_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`doc_id`),
  KEY `idx_rd_request` (`request_id`),
  CONSTRAINT `fk_rd_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`request_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `welfare_weeks` (
  `welfare_id` int(11) NOT NULL AUTO_INCREMENT,
  `week_start_date` date NOT NULL COMMENT 'Monday of the week',
  `status` enum('waiting','driver_assigned','approved') NOT NULL DEFAULT 'waiting',
  `reject_reason` varchar(500) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`welfare_id`),
  UNIQUE KEY `uq_welfare_week` (`week_start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tasks` (
  `task_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_source` enum('request','internal','welfare') NOT NULL,
  `task_type` enum('trip','workshop','refuelling','test_drive','welfare') NOT NULL,
  `trip_type` enum('outbound','return') DEFAULT NULL COMMENT 'request tasks only',
  `request_id` int(11) DEFAULT NULL,
  `rv_id` int(11) DEFAULT NULL COMMENT 'the reserved vehicle of the request',
  `welfare_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `start_dt` datetime NOT NULL,
  `end_dt` datetime NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('waiting','driver_assigned','approved','completed','cancelled') NOT NULL DEFAULT 'waiting',
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `reject_reason` varchar(500) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`task_id`),
  KEY `idx_tasks_driver_time` (`driver_id`,`start_dt`,`end_dt`),
  KEY `idx_tasks_vehicle_time` (`vehicle_id`,`start_dt`,`end_dt`),
  KEY `idx_tasks_request` (`request_id`),
  KEY `idx_tasks_welfare` (`welfare_id`),
  KEY `idx_tasks_status` (`task_source`,`status`,`start_dt`),
  CONSTRAINT `fk_tasks_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_rv` FOREIGN KEY (`rv_id`) REFERENCES `request_vehicles` (`rv_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_welfare` FOREIGN KEY (`welfare_id`) REFERENCES `welfare_weeks` (`welfare_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`),
  CONSTRAINT `fk_tasks_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `task_reports` (
  `report_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `actual_depart` datetime NOT NULL,
  `actual_return` datetime NOT NULL,
  `start_mileage` int(11) NOT NULL COMMENT 'vehicle mileage before the trip',
  `end_mileage` int(11) NOT NULL,
  `fuel_level` enum('Full','3/4','1/2','1/4','Empty') NOT NULL,
  `vehicle_condition` enum('good','problem') NOT NULL DEFAULT 'good',
  `problem_type` enum('Plate Number','Tyre','Lights','Body Damage','Engine','Others') DEFAULT NULL,
  `problem_desc` varchar(500) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `can_be_used` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`report_id`),
  UNIQUE KEY `uq_report_task` (`task_id`),
  CONSTRAINT `fk_report_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`task_id`),
  CONSTRAINT `fk_report_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedule_changes` (
  `change_id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL COMMENT 'set when the driver asks to be removed from a task',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `reject_reason` varchar(500) DEFAULT NULL,
  `rejected_date` datetime DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`change_id`),
  KEY `idx_sc_driver` (`driver_id`,`status`,`start_date`,`end_date`),
  CONSTRAINT `fk_sc_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`),
  CONSTRAINT `fk_sc_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`task_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `driver_replacements` (
  `replacement_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `previous_driver_id` int(11) DEFAULT NULL,
  `new_driver_id` int(11) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reject_reason` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `date_time` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`replacement_id`),
  KEY `idx_repl_task` (`task_id`,`status`),
  CONSTRAINT `fk_repl_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`task_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `request_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `status_to` varchar(30) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL = done by the system',
  `user_role` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `idx_history_request` (`request_id`,`created_at`),
  KEY `idx_history_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` varchar(500) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `date_sent` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_notif_user` (`user_id`,`is_read`,`date_sent`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `verification_codes` (
  `code_id` int(11) NOT NULL AUTO_INCREMENT,
  `purpose` enum('register','reset') NOT NULL,
  `staff_no` varchar(30) NOT NULL,
  `email` varchar(150) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`code_id`),
  UNIQUE KEY `uq_code_email_purpose` (`email`,`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A new code replaces any earlier code for the same email (UC014)';

CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `to_email` varchar(190) DEFAULT NULL,
  `delivered_to` varchar(255) DEFAULT NULL COMMENT 'set when a test redirect replaced the recipient',
  `subject` varchar(255) NOT NULL,
  `kind` varchar(30) NOT NULL,
  `ref_date` date DEFAULT NULL,
  `schedule_ids` varchar(255) DEFAULT NULL,
  `status` enum('sent','failed','skipped') NOT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_email_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `whatsapp_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `to_number` varchar(20) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('sent','failed','skipped') NOT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_wa_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The status history is INSERT ONLY: the database refuses to change or delete a row.
DELIMITER //
CREATE TRIGGER trg_history_no_update BEFORE UPDATE ON request_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'request_history is insert-only'//
CREATE TRIGGER trg_history_no_delete BEFORE DELETE ON request_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'request_history is insert-only'//
DELIMITER ;

-- ============================================================
-- SEED: settings
-- ============================================================
INSERT INTO settings (setting_key, setting_value, description) VALUES
 ('W_TASKS',               '0.5',   'Recommend Driver: weight of the number of tasks this month'),
 ('W_WEEKEND',             '0.3',   'Recommend Driver: weight of the number of weekend tasks this month'),
 ('W_EXPERIENCE',          '0.2',   'Recommend Driver: weight of the years of experience'),
 ('RECOMMEND_TOP',         '3',     'How many of the best-ranked drivers are shown as recommended'),
 ('LAPSE_HOURS',           '48',    'A request not approved by the Head of Department after this many hours becomes Lapsed'),
 ('REMINDER_HOURS',        '24',    'The reminder email is sent to the Head of Department after this many hours'),
 ('URGENT_HOURS',          '48',    'A request departing within this many hours is Urgent'),
 ('TASK_DURATION_HOURS',   '1',     'Default length of the outbound and the return task (hours)'),
 ('RESERVATION_BUFFER_HOURS','1',   'The vehicle stays reserved this many hours after the return time'),
 ('CHARGE_PER_KM',         '3.00',  'Personal lorry request: charge per kilometre (RM)'),
 ('WELFARE_START',         '08:00', 'Welfare duty: the 24-hour duty starts at this time'),
 ('UIS_EMAIL_DOMAINS',     'uis.edu.my', 'Accepted UIS email domains (comma list)'),
 ('CODE_EXPIRY_MINUTES',   '15',    'A verification code expires after this many minutes'),
 ('SESSION_TIMEOUT_MIN',   '15',    'Minutes without activity before a user is signed out'),
 ('MAX_FAILED_ATTEMPTS',   '5',     'Failed logins before the login is blocked'),
 ('LOCKOUT_MINUTES',       '15',    'Minutes a login is blocked after too many failed attempts');

-- ============================================================
-- SEED: departments (reports to = parent). The Vice-Chancellor's Office is the parent department.
-- ============================================================
INSERT INTO departments (department_id, department_name, parent_id) VALUES
 (1, 'Office of the Vice-Chancellor', NULL),
 (2, 'Registrar Office', 1),
 (3, 'Faculty of Education', 1),
 (4, 'Student Affairs Division', 1),
 (5, 'Vehicle Section', 2),
 (6, 'Faculty of Science and Technology', 1),
 (7, 'Bursary', 1);

-- ============================================================
-- SEED: users (demo passwords in LOGIN_ACCOUNTS.txt)
-- ============================================================
INSERT INTO users (user_id, staff_no, full_name, email, phone, address, password, role, pending_role, department_id, status) VALUES
 (1,'hos005','Prof. Dr. Ahmad Zaki bin Mansor','zaki.mansor@uis.edu.my','03-5543 0001','UIS Bandar Seri Putra, 43000 Kajang','$2y$10$K2ZXXz5T9W0w8wfX3AKCvullQC44VzVKoXJUvZtk2nIs0K6gqlpfG','supervisor',NULL,1,'active'),
 (2,'hos001','Mohd Hafizi bin Harun','hafizi@uis.edu.my','03-5543 0002',NULL,'$2y$10$0HskJaNxtjsuPTFfwCHWxuDFiXwLFhhrbiib4F0v7VVXapfIRswb.','supervisor',NULL,5,'active'),
 (3,'hos002','Hajah Rohana binti Ismail','rohana@uis.edu.my','03-5543 0003',NULL,'$2y$10$EMt3gvCOw8VPZYTePeAHMe4g.Jnqic7ZOKbPJFYVnc5yTpVzQAKgC','supervisor',NULL,3,'active'),
 (4,'hos003','Dr. Nor Azlina binti Hashim','azlina@uis.edu.my','03-5543 0004',NULL,'$2y$10$Dp2tDjvqnNaV6a3tgJHGVOEmcGNdO4845UMA6iln3fzdV41dPh9Yi','supervisor',NULL,2,'active'),
 (5,'hos004','Azman bin Yusof','azman@uis.edu.my','03-5543 0005',NULL,'$2y$10$A1K5YcFRgoCEECRP5kKAeuup3ytDP8gnJXJ.H2Om/4q0g5MJ/EF2e','supervisor',NULL,4,'active'),
 (6,'admin','Rozaimi bin Kamaruzzaman','rozaimi@uis.edu.my','03-5543 0010',NULL,'$2y$10$tmBpnZk4T.328S32nigTr.dlo8UDVlZ3Krtfq8GQf27E6Otn.mRpq','admin',NULL,5,'active'),
 (7,'head001','Haji Zulkifli bin Awang','zulkifli@uis.edu.my','03-5543 0011',NULL,'$2y$10$v2.jmXmoAS7XeU.WoxwtQectjUj5ILl8p5nxdF4DU4nA57ve19ufy','head_admin',NULL,5,'active'),
 (8,'stf001','Norliza binti Abdul Rahman','norliza@uis.edu.my','012-300 0001','No. 4, Jalan Bangi Avenue 2, 43000 Kajang','$2y$10$gn6GEc7uhHJ2aTRhSmXXOOjRmv1lrkuzSfYYpDsSC5GYte.211j52','staff',NULL,3,'active'),
 (9,'stf002','Muhammad Syafiq bin Zainal','syafiq@uis.edu.my','012-300 0002',NULL,'$2y$10$X3kvDYNDTKbDTG3O/f7LY.gv5cN3Olxi7CjSm5kZL8pHfWhbkQIIO','staff',NULL,2,'active'),
 (10,'stf003','Siti Aishah binti Mohd Yusof','aishah@uis.edu.my','012-300 0003',NULL,'$2y$10$HFOsO8WRHtC0UbpnUneC3edSsZKmJbshoFljCo/qu65hJwFm2zmVO','staff',NULL,4,'active'),
 (11,'drv001','Ahmad Faizal bin Mohd Rashid','faizal.rashid@uis.edu.my','012-3456789','No. 12, Jalan Cempaka 3, 40150 Shah Alam','$2y$10$X8Gb1JTnmNAOOg7T9Ec4zO9BmtN8PwtFU3RKsXQA68O0VgD3DY69u','driver',NULL,5,'active'),
 (12,'drv002','Mohd Hafizuddin bin Zulkifli','hafizuddin@uis.edu.my','013-2345678',NULL,'$2y$10$11Q6R2D63AN0/zg5Muinzuc3VzbzFn5znPeEde0Tt3G14NsNVQpAy','driver',NULL,5,'active'),
 (13,'drv003','Norhaslinda binti Abdul Karim','haslinda@uis.edu.my','011-34567890',NULL,'$2y$10$wrzpngcdR.g7UCdcE1clzuBArn/j1VBQqLp9oNpUPeLX2lAcOKx7m','driver',NULL,5,'active'),
 (14,'drv004','Khairul Anuar bin Ismail','khairul@uis.edu.my','019-4567890',NULL,'$2y$10$5DsGkRd3CdkkTTDj5U/UGODeRKWDHJzBtatPL2flxnCv2Dqzd3JGi','driver',NULL,5,'active'),
 (15,'drv005','Siti Norzaharah binti Othman','zaharah@uis.edu.my','017-5678901',NULL,'$2y$10$gUWZSPd6RURQRZ1hVAN8.etAkTWkEUAIHJNnxfdIc47AhhWriak1i','driver',NULL,5,'active'),
 (16,'drv006','Zulkarnain bin Hamzah','zulkarnain@uis.edu.my','014-6789012',NULL,'$2y$10$4XaGUlyBm37Y2DHrD4Rcru/ORHscCrz552DZlE8u4FBu1kOvbwaXi','driver',NULL,5,'active'),
 (17,'drv007','Roslan bin Abdul Wahab','roslan@uis.edu.my','016-7890123',NULL,'$2y$10$1EFz74fVyV4kH77vp9V6ZejkZ6glayepCOSgdwo5sl.WrKwJi.gDS','driver',NULL,5,'active'),
 (18,'drv008','Muhammad Asyraf bin Che Hassan','asyraf@uis.edu.my','018-8901234',NULL,'$2y$10$3ROV7zuusW0l5g8VMBD7t.KwMeajlO9TsmhBEEQjOnwuAnj4Q0nPu','driver',NULL,5,'active'),
 (19,'drv009','Fadzillah bin Mohd Noor','fadzillah@uis.edu.my','012-9012345',NULL,'$2y$10$wzb3rP/RedFaPvfE7CAQDuTq.ZyZo8YVJt3grNyqZ3Z17h1DE6X2u','driver',NULL,5,'active'),
 (20,'drv010','Nurul Ain binti Saharuddin','nurulain@uis.edu.my','011-0123456',NULL,'$2y$10$IEV452U7aIVbFJGDLi9SM.DDUdDIM2e80gOWOftYbh.y40xfdVV3.','driver',NULL,5,'active'),
 (21,'drv011','Hairul Nizam bin Kamaruddin','hairul@uis.edu.my','013-1234567',NULL,'$2y$10$O2arGw2BzBnUfPdVX4wFM.D9YOubc80sXGz1qkue4UbiYemtL7hiq','driver',NULL,5,'inactive'),
 (22,'drv012','Azhari bin Mahmud','azhari@uis.edu.my','017-2345670',NULL,'$2y$10$bUci0/04gG6b3KbmnwIBt.po6zAfSGCntKArIekZq18VpPAS0Ejny','driver',NULL,5,'active'),
 (23,'stf004','Dr. Hakim bin Razali','hakim.razali@uis.edu.my','012-300 0004',NULL,'$2y$10$/owAZis1ncpLEBEaWM/SY.lGlOKtIQENYPcZt6oPdXvPL/URQ4PS6','staff','supervisor',6,'active');

-- Drivers (D = car, MPV, van; GDL = lorry; PSV = bus). drv008's licence has expired (not recommended).
INSERT INTO drivers (driver_id, user_id, licence_classes, licence_expiry, start_date) VALUES
 (1, 11, 'D,GDL,PSV', '2027-08-31', '2011-03-01'),
 (2, 12, 'D,GDL,PSV', '2027-12-31', '2016-01-04'),
 (3, 13, 'D,GDL',     '2027-03-15', '2019-07-01'),
 (4, 14, 'D,PSV',     '2028-01-20', '2014-05-12'),
 (5, 15, 'D',         '2027-06-30', '2023-02-01'),
 (6, 16, 'D,GDL,PSV', '2027-11-10', '2018-09-03'),
 (7, 17, 'D,PSV',     '2027-09-14', '2024-06-03'),
 (8, 18, 'D',         '2026-10-31', '2021-08-16'),
 (9, 19, 'D,GDL,PSV', '2028-05-15', '2008-01-02'),
 (10, 20, 'D,GDL',    '2027-10-30', '2022-04-11'),
 (11, 21, 'D',        '2027-12-01', '2020-10-01'),
 (12, 22, 'D,PSV',    '2028-03-20', '2017-11-20');

-- ============================================================
-- SEED: vehicles (car, MPV, van, bus, lorry). One van is under maintenance, one car is inactive.
-- ============================================================
INSERT INTO vehicles (vehicle_id, plate_number, vehicle_type, brand, model, fuel_type, capacity, licence_required, current_mileage, fuel_level, status) VALUES
 (1,'BDF 4400','Car','Proton','X70','Petrol',5,'D',48210,'Full','active'),
 (2,'BDG 5500','Car','Perodua','Bezza','Petrol',5,'D',31540,'3/4','active'),
 (3,'BDF 7700','Car','Honda','Civic','Petrol',5,'D',152300,'1/2','inactive'),
 (4,'BQA 1188','MPV','Toyota','Innova','Petrol',7,'D',60415,'Full','active'),
 (5,'BQB 2299','MPV','Perodua','Alza','Petrol',7,'D',22870,'3/4','active'),
 (6,'WA 2211 C','Van','Toyota','Hiace','Diesel',14,'D',98750,'Full','active'),
 (7,'WB 3322 D','Van','Nissan','Urvan','Diesel',12,'D',143020,'1/4','maintenance'),
 (8,'BGN 9595','Bus','Hino','RK8J','Diesel',44,'PSV',210400,'Full','active'),
 (9,'BNJ 9276','Bus','Hino','RN8J','Diesel',44,'PSV',120750,'3/4','active'),
 (10,'BKH 9555','Bus','Nissan','Civilian','Diesel',40,'PSV',187320,'Full','active'),
 (11,'BHW 8595','Lorry','Daihatsu','Delta','Diesel',3,'GDL',134800,'1/2','active'),
 (12,'BLJ 9955','Lorry','Isuzu','NLR','Diesel',3,'GDL',56200,'Full','active');

SET FOREIGN_KEY_CHECKS = 1;
