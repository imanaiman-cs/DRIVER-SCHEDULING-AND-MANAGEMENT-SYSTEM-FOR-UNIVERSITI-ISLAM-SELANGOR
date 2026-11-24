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

-- ============================================================
-- DEMO DATA (generated by tools/seed_demo.php + tools/make_demo_sql.py; dates relative to the day of the import)
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TRIGGER IF EXISTS trg_history_no_update;
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (1,9,2,4,'Car',1,'with_driver','official','Registrar Office meeting at the Ministry of Higher Education','Kementerian Pendidikan Tinggi','No. 2, Menara 2, Jalan P5/6, Presint 5, 62200 Putrajaya','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '15:30:00'),2,NULL,NULL,1,1.0,'completed',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'),4,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'),6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'),7,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (2,10,4,5,'Van',1,'with_driver','official','Student leaders programme at Pusat Islam Selangor','Pusat Islam Selangor','Jalan Masjid, Seksyen 5, 40000 Shah Alam, Selangor','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '15:30:00'),10,NULL,NULL,1,1.0,'completed',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'),5,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'),6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'),7,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (3,8,3,3,'MPV',1,'with_driver','official','School visit for the teaching practicum','SMK Seksyen 9 Shah Alam','Jalan Sepana 9/9, Seksyen 9, 40100 Shah Alam, Selangor','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '15:30:00'),5,NULL,NULL,1,1.0,'completed',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'),3,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'),6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'),7,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (4,9,2,4,'Car',1,'with_driver','official','Collect documents from the Land Office','Pejabat Tanah dan Galian Selangor','Bangunan Sultan Salahuddin Abdul Aziz Shah, 40000 Shah Alam','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '15:30:00'),1,NULL,NULL,1,1.0,'completed',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'),4,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'),6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'),7,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (5,8,3,3,'Car',1,'with_driver','official','National higher education forum (faculty delegation)','Putrajaya International Convention Centre (PICC)','Dataran Gemilang, Presint 5, 62000 Putrajaya','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '17:00:00'),3,NULL,NULL,0,1.0,'approved',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),3,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (6,10,4,5,'Van',1,'with_driver','official','Student counselling outreach','Kolej Komuniti Hulu Langat','Jalan Hulu Langat, 43100 Hulu Langat, Selangor','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '14:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '18:00:00'),8,NULL,NULL,1,1.0,'approved',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),5,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (7,8,3,3,'Bus',4,'with_driver','official','Faculty students field trip (Bachelor of Education)','Muzium Negara','Jalan Damansara, 50480 Kuala Lumpur','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '07:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '18:00:00'),150,NULL,NULL,0,2.0,'driver_assigned',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),3,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),6,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (8,9,2,4,'MPV',1,'with_driver','official','Benchmarking visit to the Registrar of UNISEL','Universiti Selangor (UNISEL)','Jalan Timur Tambahan, 45600 Bestari Jaya, Selangor','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 2 DAY), '09:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 2 DAY), '13:00:00'),4,NULL,NULL,0,1.0,'hod_approved',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),4,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (9,10,4,5,'Van',1,'with_driver','official','Leadership camp (two days)','Kem Bina Negara Bukit Tinggi','Bukit Tinggi, 28750 Bentong, Pahang','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 6 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '17:00:00'),12,NULL,NULL,0,1.0,'pending',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (10,3,3,1,'Car',1,'with_driver','official','Deans meeting','Universiti Malaya','Jalan Profesor Diraja Ungku Aziz, 50603 Kuala Lumpur','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 5 DAY), '10:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 5 DAY), '16:00:00'),1,NULL,NULL,0,1.0,'pending',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (11,9,2,4,'Car',1,'pay_claim','official','Seminar on records management','Arkib Negara Malaysia','Jalan Tuanku Abdul Halim, 50480 Kuala Lumpur','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '12:00:00'),1,NULL,NULL,0,1.0,'approved',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),4,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (12,11,5,NULL,'Lorry',1,'self_drive','personal','Moving house','Taman Bukit Mewah','Jalan BM 1/2, Taman Bukit Mewah, 43000 Kajang','Vehicle yard',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '14:00:00'),1,46.0,138.00,0,1.0,'approved',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (13,10,4,NULL,'Lorry',1,'with_driver','personal','Transport furniture for a family wedding','Kampung Sungai Merab','Kampung Sungai Merab Luar, 43000 Kajang','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 11 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 11 DAY), '13:00:00'),1,24.0,72.00,0,1.0,'pending',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (14,9,2,4,'MPV',1,'with_driver','official','Collect certificates from the printer','Percetakan Nasional Malaysia','Jalan Chan Sow Lin, 55200 Kuala Lumpur','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 5 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 5 DAY), '12:00:00'),2,NULL,NULL,0,1.0,'rejected',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'The printer will deliver the certificates by courier.',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),4,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (15,8,3,3,'Car',1,'with_driver','official','Technology in education seminar','Cyberjaya Convention Centre','Persiaran Multimedia, 63000 Cyberjaya','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '12:30:00'),2,NULL,NULL,0,1.0,'cancelled',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'The seminar was postponed to next month.',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),8,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `requests` (`request_id`, `requester_id`, `department_id`, `approver_id`, `vehicle_type`, `quantity`, `driver_option`, `purpose_type`, `purpose`, `destination`, `address`, `waiting_location`, `depart_at`, `return_at`, `passengers`, `distance_km`, `charge`, `is_urgent`, `task_hours`, `status`, `charge_confirmed_at`, `approval_email_at`, `reminder_sent_at`, `hod_approved_at`, `hod_approved_by`, `assigned_at`, `assigned_by`, `hoa_approved_at`, `hoa_approved_by`, `assign_reject_reason`, `reject_reason`, `rejected_at`, `rejected_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `created_at`, `updated_at`) VALUES (16,10,4,5,'Car',1,'with_driver','official','Student welfare visit','Hospital Kajang','Jalan Semenyih, 43000 Kajang','Main entrance, Block A (Canselori)',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '13:00:00'),2,NULL,NULL,0,1.0,'lapsed',NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -3 DAY), '01:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (1,1,1,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '16:30:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (2,2,7,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '16:30:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (3,3,4,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '16:30:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (4,4,1,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '16:30:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (5,5,1,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '18:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (6,6,7,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '14:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '19:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (7,7,10,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '07:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '19:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (8,7,8,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '07:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '19:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (9,7,9,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '07:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '19:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (10,7,NULL,1,'Syarikat Bas Ekspres Maju Sdn Bhd','WXY 4410',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '07:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '19:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (11,8,4,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 2 DAY), '09:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 2 DAY), '14:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (12,9,7,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 6 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '18:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (13,10,1,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 5 DAY), '10:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 5 DAY), '17:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (14,12,11,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '15:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (15,13,11,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 11 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 11 DAY), '14:00:00'),1);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (16,14,4,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 5 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 5 DAY), '13:00:00'),0);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (17,15,1,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '13:30:00'),0);
INSERT INTO `request_vehicles` (`rv_id`, `request_id`, `vehicle_id`, `is_vendor`, `vendor_name`, `vendor_plate`, `start_dt`, `end_dt`, `active`) VALUES (18,16,1,0,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '14:00:00'),0);
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (1,1,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (2,2,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (3,3,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (4,4,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (5,5,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (6,6,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (7,7,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (8,8,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (9,9,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (10,10,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (11,11,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (12,14,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (13,15,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `request_documents` (`doc_id`, `request_id`, `doc_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES (14,16,'supporting_letter','surat_sokongan.pdf','uploads/documents/demo_supporting_letter.pdf','application/pdf',74,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `welfare_weeks` (`welfare_id`, `week_start_date`, `status`, `reject_reason`, `rejected_at`, `approved_at`, `approved_by`, `created_by`, `created_at`) VALUES (1,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 0 DAY),'approved',NULL,NULL,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 2 DAY), '01:46:51'),7,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `welfare_weeks` (`welfare_id`, `week_start_date`, `status`, `reject_reason`, `rejected_at`, `approved_at`, `approved_by`, `created_by`, `created_at`) VALUES (2,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY),'waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (1,'request','trip','outbound',1,1,NULL,1,9,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '09:30:00'),NULL,'completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (2,'request','trip','return',1,1,NULL,1,9,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '15:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '16:30:00'),NULL,'completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (3,'request','trip','outbound',2,2,NULL,7,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '10:00:00'),NULL,'completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (4,'request','trip','return',2,2,NULL,7,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '15:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '16:30:00'),NULL,'completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (5,'request','trip','outbound',3,3,NULL,4,4,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '09:30:00'),NULL,'completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (6,'request','trip','return',3,3,NULL,4,4,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '15:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '16:30:00'),NULL,'completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (7,'request','trip','outbound',4,4,NULL,1,2,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '10:00:00'),NULL,'completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (8,'request','trip','return',4,4,NULL,1,2,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '15:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '16:30:00'),NULL,'completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (9,'request','trip','outbound',5,5,NULL,1,12,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '09:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (10,'request','trip','return',5,5,NULL,1,3,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '17:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '18:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (11,'request','trip','outbound',6,6,NULL,7,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '14:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '15:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (12,'request','trip','return',6,6,NULL,7,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '18:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '19:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (13,'request','trip','outbound',7,7,NULL,10,9,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '07:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '09:30:00'),NULL,'driver_assigned',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (14,'request','trip','return',7,7,NULL,10,9,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '18:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '20:00:00'),NULL,'driver_assigned',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (15,'request','trip','outbound',7,8,NULL,8,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '07:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '09:30:00'),NULL,'driver_assigned',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (16,'request','trip','return',7,8,NULL,8,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '18:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '20:00:00'),NULL,'driver_assigned',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (17,'request','trip','outbound',7,9,NULL,9,4,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '07:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '09:30:00'),NULL,'driver_assigned',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (18,'request','trip','return',7,9,NULL,9,4,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '18:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '20:00:00'),NULL,'driver_assigned',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (19,'internal','workshop',NULL,NULL,NULL,NULL,7,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 2 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 2 DAY), '11:00:00'),'Collect the Urvan after the gearbox repair (Auto Service Klang)','waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (20,'internal','refuelling',NULL,NULL,NULL,NULL,8,7,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '08:45:00'),'Refuel bus BGN 9595 before the field trip','approved',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:51'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (21,'internal','test_drive',NULL,NULL,NULL,NULL,12,10,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '14:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '15:00:00'),'Test drive after the brake service','driver_assigned',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (22,'internal','workshop',NULL,NULL,NULL,NULL,4,5,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -4 DAY), '10:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -4 DAY), '12:00:00'),'Service at 60,000 km','completed',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:51'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (23,'welfare','welfare',NULL,NULL,NULL,1,6,7,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 2 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 3 DAY), '08:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 2 DAY), '01:46:51'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (24,'welfare','welfare',NULL,NULL,NULL,1,2,9,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 3 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 4 DAY), '08:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 2 DAY), '01:46:51'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (25,'welfare','welfare',NULL,NULL,NULL,1,6,7,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 4 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 5 DAY), '08:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 2 DAY), '01:46:51'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (26,'welfare','welfare',NULL,NULL,NULL,1,2,7,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 5 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY), '08:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 2 DAY), '01:46:51'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (27,'welfare','welfare',NULL,NULL,NULL,1,6,7,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY), '08:00:00'),NULL,'approved',TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 2 DAY), '01:46:51'),7,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (28,'welfare','welfare',NULL,NULL,NULL,2,2,NULL,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 8 DAY), '08:00:00'),NULL,'waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (29,'welfare','welfare',NULL,NULL,NULL,2,6,NULL,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 8 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 9 DAY), '08:00:00'),NULL,'waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (30,'welfare','welfare',NULL,NULL,NULL,2,2,NULL,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 9 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 10 DAY), '08:00:00'),NULL,'waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (31,'welfare','welfare',NULL,NULL,NULL,2,6,NULL,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 10 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 11 DAY), '08:00:00'),NULL,'waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (32,'welfare','welfare',NULL,NULL,NULL,2,2,NULL,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 11 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 12 DAY), '08:00:00'),NULL,'waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (33,'welfare','welfare',NULL,NULL,NULL,2,6,NULL,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 12 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 13 DAY), '08:00:00'),NULL,'waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `tasks` (`task_id`, `task_source`, `task_type`, `trip_type`, `request_id`, `rv_id`, `welfare_id`, `vehicle_id`, `driver_id`, `start_dt`, `end_dt`, `description`, `status`, `approved_at`, `approved_by`, `reject_reason`, `rejected_at`, `created_by`, `created_at`, `updated_at`) VALUES (34,'welfare','welfare',NULL,NULL,NULL,2,2,NULL,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 13 DAY), '08:00:00'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 14 DAY), '08:00:00'),NULL,'waiting',NULL,NULL,NULL,NULL,6,TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY), '17:46:51'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (1,1,9,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '10:00:00'),48210,48241,'3/4','good',NULL,NULL,NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (2,2,9,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '15:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -12 DAY), '17:00:00'),48241,48273,'1/2','good',NULL,NULL,NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (3,3,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '10:30:00'),143020,143044,'3/4','good',NULL,NULL,NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (4,4,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '15:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -10 DAY), '17:00:00'),143044,143069,'1/2','problem','Lights','Left brake light not working.',NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (5,5,4,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '08:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '10:00:00'),60415,60433,'3/4','good',NULL,NULL,NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (6,6,4,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '15:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -8 DAY), '17:00:00'),60433,60452,'1/2','good',NULL,NULL,NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (7,7,2,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '09:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '10:30:00'),48273,48293,'3/4','good',NULL,NULL,NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (8,8,2,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '15:30:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -6 DAY), '17:00:00'),48293,48314,'1/2','good',NULL,NULL,NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `task_reports` (`report_id`, `task_id`, `driver_id`, `actual_depart`, `actual_return`, `start_mileage`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used`, `created_at`, `updated_at`) VALUES (9,22,5,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -4 DAY), '10:00:00'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -4 DAY), '12:30:00'),60452,60466,'Full','good',NULL,NULL,NULL,1,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `schedule_changes` (`change_id`, `driver_id`, `task_id`, `start_date`, `end_date`, `reason`, `status`, `reject_reason`, `rejected_date`, `decided_by`, `decided_at`, `created_at`) VALUES (1,5,NULL,DATE_ADD(CURDATE(), INTERVAL 12 DAY),DATE_ADD(CURDATE(), INTERVAL 13 DAY),'Family wedding in Kelantan','pending',NULL,NULL,NULL,NULL,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `schedule_changes` (`change_id`, `driver_id`, `task_id`, `start_date`, `end_date`, `reason`, `status`, `reject_reason`, `rejected_date`, `decided_by`, `decided_at`, `created_at`) VALUES (2,7,NULL,DATE_ADD(CURDATE(), INTERVAL 15 DAY),DATE_ADD(CURDATE(), INTERVAL 15 DAY),'Court summons as a witness','approved',NULL,NULL,7,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:51'),TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `driver_replacements` (`replacement_id`, `task_id`, `previous_driver_id`, `new_driver_id`, `reason`, `status`, `reject_reason`, `created_by`, `date_time`, `decided_by`, `decided_at`) VALUES (1,10,12,3,'The driver has a medical appointment.','approved',NULL,6,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'),7,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (1,1,'submitted','pending','Urgent request',9,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (2,1,'hod_approved','hod_approved',NULL,4,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (3,1,'driver_assigned','driver_assigned',NULL,6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (4,1,'approved','approved',NULL,7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -13 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (5,2,'submitted','pending','Urgent request',10,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (6,2,'hod_approved','hod_approved',NULL,5,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (7,2,'driver_assigned','driver_assigned',NULL,6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (8,2,'approved','approved',NULL,7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -11 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (9,3,'submitted','pending','Urgent request',8,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (10,3,'hod_approved','hod_approved',NULL,3,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (11,3,'driver_assigned','driver_assigned',NULL,6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (12,3,'approved','approved',NULL,7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -9 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (13,4,'submitted','pending','Urgent request',9,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (14,4,'hod_approved','hod_approved',NULL,4,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (15,4,'driver_assigned','driver_assigned',NULL,6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (16,4,'approved','approved',NULL,7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -7 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (17,5,'submitted','pending',NULL,8,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (18,5,'hod_approved','hod_approved',NULL,3,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (19,5,'driver_assigned','driver_assigned',NULL,6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (20,5,'approved','approved',NULL,7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (21,6,'submitted','pending','Urgent request',10,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (22,6,'hod_approved','hod_approved',NULL,5,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (23,6,'driver_assigned','driver_assigned',NULL,6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (24,6,'approved','approved',NULL,7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (25,5,'replacement',NULL,'Return task: Azhari bin Mahmud -> Norhaslinda binti Abdul Karim. Reason: The driver has a medical appointment.',6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (26,5,'replacement_approved',NULL,'Azhari bin Mahmud -> Norhaslinda binti Abdul Karim',7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (27,7,'submitted','pending',NULL,8,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (28,7,'hod_approved','hod_approved',NULL,3,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (29,7,'driver_assigned','driver_assigned',NULL,6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (30,8,'submitted','pending',NULL,9,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (31,8,'hod_approved','hod_approved',NULL,4,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (32,9,'submitted','pending',NULL,10,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (33,10,'submitted','pending',NULL,3,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (34,11,'submitted','pending',NULL,9,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (35,11,'hod_approved','hod_approved',NULL,4,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (36,11,'approved','approved',NULL,7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (37,12,'submitted','pending',NULL,11,'driver',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (38,12,'charge_confirmed',NULL,NULL,6,'admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (39,12,'approved','approved',NULL,7,'head_admin',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (40,13,'submitted','pending',NULL,10,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (41,14,'submitted','pending',NULL,9,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (42,14,'rejected','rejected','The printer will deliver the certificates by courier.',4,'supervisor',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (43,15,'submitted','pending',NULL,8,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (44,15,'cancelled','cancelled','The seminar was postponed to next month.',8,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (45,16,'submitted','pending',NULL,10,'staff',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (46,16,'lapsed','lapsed','The Head of Department did not approve the request within 48 hours.',NULL,'system',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:50'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (47,1,'completed','completed',NULL,NULL,'system',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:51'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (48,2,'completed','completed',NULL,NULL,'system',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:51'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (49,3,'completed','completed',NULL,NULL,'system',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:51'));
INSERT INTO `request_history` (`history_id`, `request_id`, `action`, `status_to`, `reason`, `user_id`, `user_role`, `created_at`) VALUES (50,4,'completed','completed',NULL,NULL,'system',TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 0 DAY), '01:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (1,4,'URGENT. New vehicle request waiting for your approval #1 from Muhammad Syafiq bin Zainal (Registrar Office): Car, With Driver to Kementerian Pendidikan Tinggi, Thu, 26 Nov 2026, 8:30 am - 3:30 pm. Purpose: Registrar Office meeting at the Ministry of Higher Education.','staff/request.php?id=1',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (2,9,'Your vehicle request #1 was approved by the Head of Department.','staff/request.php?id=1',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (3,6,'URGENT. Request #1 from Muhammad Syafiq bin Zainal (Registrar Office): Car, With Driver to Kementerian Pendidikan Tinggi, Thu, 26 Nov 2026, 8:30 am - 3:30 pm is waiting for driver assignment.','admin/assign_driver.php?id=1',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (4,7,'URGENT. Driver assignment of request #1 from Muhammad Syafiq bin Zainal (Registrar Office): Car, With Driver to Kementerian Pendidikan Tinggi, Thu, 26 Nov 2026, 8:30 am - 3:30 pm is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (5,9,'Your vehicle request #1 was approved. Outbound: Fadzillah bin Mohd Noor (BDF 4400); Return: Fadzillah bin Mohd Noor (BDF 4400).','staff/request.php?id=1',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (6,19,'New outbound task: Thu, 26 Nov 2026, 8:30 am, BDF 4400, to Kementerian Pendidikan Tinggi. Wait at Main entrance, Block A (Canselori). Officer: Muhammad Syafiq bin Zainal 012-300 0002.','driver/task.php?id=1',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (7,19,'New return task: Thu, 26 Nov 2026, 3:30 pm, BDF 4400, to Kementerian Pendidikan Tinggi. Wait at Main entrance, Block A (Canselori). Officer: Muhammad Syafiq bin Zainal 012-300 0002.','driver/task.php?id=2',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (8,6,'The driver assignment of request #1 was approved by the Head of Admin.','staff/request.php?id=1',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (9,6,'Task report: Request task (outbound), Fri, 13 Nov 2026, 8:30 am - 9:30 am, BDF 4400 by Fadzillah bin Mohd Noor. End mileage 48,241 km, fuel 3/4.','admin/task.php?id=1',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (10,6,'Task report: Request task (return), Fri, 13 Nov 2026, 3:30 pm - 4:30 pm, BDF 4400 by Fadzillah bin Mohd Noor. End mileage 48,273 km, fuel 1/2.','admin/task.php?id=2',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (11,5,'URGENT. New vehicle request waiting for your approval #2 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Van, With Driver to Pusat Islam Selangor, Thu, 26 Nov 2026, 9:00 am - 3:30 pm. Purpose: Student leaders programme at Pusat Islam Selangor.','staff/request.php?id=2',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (12,10,'Your vehicle request #2 was approved by the Head of Department.','staff/request.php?id=2',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (13,6,'URGENT. Request #2 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Van, With Driver to Pusat Islam Selangor, Thu, 26 Nov 2026, 9:00 am - 3:30 pm is waiting for driver assignment.','admin/assign_driver.php?id=2',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (14,7,'URGENT. Driver assignment of request #2 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Van, With Driver to Pusat Islam Selangor, Thu, 26 Nov 2026, 9:00 am - 3:30 pm is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (15,10,'Your vehicle request #2 was approved. Outbound: Ahmad Faizal bin Mohd Rashid (WB 3322 D); Return: Ahmad Faizal bin Mohd Rashid (WB 3322 D).','staff/request.php?id=2',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (16,11,'New outbound task: Thu, 26 Nov 2026, 9:00 am, WB 3322 D, to Pusat Islam Selangor. Wait at Main entrance, Block A (Canselori). Officer: Siti Aishah binti Mohd Yusof 012-300 0003.','driver/task.php?id=3',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (17,11,'New return task: Thu, 26 Nov 2026, 3:30 pm, WB 3322 D, to Pusat Islam Selangor. Wait at Main entrance, Block A (Canselori). Officer: Siti Aishah binti Mohd Yusof 012-300 0003.','driver/task.php?id=4',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (18,6,'The driver assignment of request #2 was approved by the Head of Admin.','staff/request.php?id=2',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (19,6,'Task report: Request task (outbound), Sun, 15 Nov 2026, 9:00 am - 10:00 am, WB 3322 D by Ahmad Faizal bin Mohd Rashid. End mileage 143,044 km, fuel 3/4.','admin/task.php?id=3',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (20,6,'VEHICLE PROBLEM (Lights) on WB 3322 D: Left brake light not working.. The vehicle can still be used. Task report: Request task (return), Sun, 15 Nov 2026, 3:30 pm - 4:30 pm, WB 3322 D by Ahmad Faizal bin Mohd Rashid. End mileage 143,069 km, fuel 1/2.','admin/task.php?id=4',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (21,3,'URGENT. New vehicle request waiting for your approval #3 from Norliza binti Abdul Rahman (Faculty of Education): MPV, With Driver to SMK Seksyen 9 Shah Alam, Thu, 26 Nov 2026, 8:30 am - 3:30 pm. Purpose: School visit for the teaching practicum.','staff/request.php?id=3',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (22,8,'Your vehicle request #3 was approved by the Head of Department.','staff/request.php?id=3',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (23,6,'URGENT. Request #3 from Norliza binti Abdul Rahman (Faculty of Education): MPV, With Driver to SMK Seksyen 9 Shah Alam, Thu, 26 Nov 2026, 8:30 am - 3:30 pm is waiting for driver assignment.','admin/assign_driver.php?id=3',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (24,7,'URGENT. Driver assignment of request #3 from Norliza binti Abdul Rahman (Faculty of Education): MPV, With Driver to SMK Seksyen 9 Shah Alam, Thu, 26 Nov 2026, 8:30 am - 3:30 pm is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (25,8,'Your vehicle request #3 was approved. Outbound: Khairul Anuar bin Ismail (BQA 1188); Return: Khairul Anuar bin Ismail (BQA 1188).','staff/request.php?id=3',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (26,14,'New outbound task: Thu, 26 Nov 2026, 8:30 am, BQA 1188, to SMK Seksyen 9 Shah Alam. Wait at Main entrance, Block A (Canselori). Officer: Norliza binti Abdul Rahman 012-300 0001.','driver/task.php?id=5',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (27,14,'New return task: Thu, 26 Nov 2026, 3:30 pm, BQA 1188, to SMK Seksyen 9 Shah Alam. Wait at Main entrance, Block A (Canselori). Officer: Norliza binti Abdul Rahman 012-300 0001.','driver/task.php?id=6',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (28,6,'The driver assignment of request #3 was approved by the Head of Admin.','staff/request.php?id=3',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (29,6,'Task report: Request task (outbound), Tue, 17 Nov 2026, 8:30 am - 9:30 am, BQA 1188 by Khairul Anuar bin Ismail. End mileage 60,433 km, fuel 3/4.','admin/task.php?id=5',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (30,6,'Task report: Request task (return), Tue, 17 Nov 2026, 3:30 pm - 4:30 pm, BQA 1188 by Khairul Anuar bin Ismail. End mileage 60,452 km, fuel 1/2.','admin/task.php?id=6',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (31,4,'URGENT. New vehicle request waiting for your approval #4 from Muhammad Syafiq bin Zainal (Registrar Office): Car, With Driver to Pejabat Tanah dan Galian Selangor, Thu, 26 Nov 2026, 9:00 am - 3:30 pm. Purpose: Collect documents from the Land Office.','staff/request.php?id=4',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (32,9,'Your vehicle request #4 was approved by the Head of Department.','staff/request.php?id=4',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (33,6,'URGENT. Request #4 from Muhammad Syafiq bin Zainal (Registrar Office): Car, With Driver to Pejabat Tanah dan Galian Selangor, Thu, 26 Nov 2026, 9:00 am - 3:30 pm is waiting for driver assignment.','admin/assign_driver.php?id=4',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (34,7,'URGENT. Driver assignment of request #4 from Muhammad Syafiq bin Zainal (Registrar Office): Car, With Driver to Pejabat Tanah dan Galian Selangor, Thu, 26 Nov 2026, 9:00 am - 3:30 pm is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (35,9,'Your vehicle request #4 was approved. Outbound: Mohd Hafizuddin bin Zulkifli (BDF 4400); Return: Mohd Hafizuddin bin Zulkifli (BDF 4400).','staff/request.php?id=4',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (36,12,'New outbound task: Thu, 26 Nov 2026, 9:00 am, BDF 4400, to Pejabat Tanah dan Galian Selangor. Wait at Main entrance, Block A (Canselori). Officer: Muhammad Syafiq bin Zainal 012-300 0002.','driver/task.php?id=7',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (37,12,'New return task: Thu, 26 Nov 2026, 3:30 pm, BDF 4400, to Pejabat Tanah dan Galian Selangor. Wait at Main entrance, Block A (Canselori). Officer: Muhammad Syafiq bin Zainal 012-300 0002.','driver/task.php?id=8',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (38,6,'The driver assignment of request #4 was approved by the Head of Admin.','staff/request.php?id=4',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (39,6,'Task report: Request task (outbound), Thu, 19 Nov 2026, 9:00 am - 10:00 am, BDF 4400 by Mohd Hafizuddin bin Zulkifli. End mileage 48,293 km, fuel 3/4.','admin/task.php?id=7',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (40,6,'Task report: Request task (return), Thu, 19 Nov 2026, 3:30 pm - 4:30 pm, BDF 4400 by Mohd Hafizuddin bin Zulkifli. End mileage 48,314 km, fuel 1/2.','admin/task.php?id=8',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (41,3,'New vehicle request waiting for your approval #5 from Norliza binti Abdul Rahman (Faculty of Education): Car, With Driver to Putrajaya International Convention Centre (PICC), Sat, 28 Nov 2026, 8:00 am - 5:00 pm. Purpose: National higher education forum (faculty delegation).','staff/request.php?id=5',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (42,8,'Your vehicle request #5 was approved by the Head of Department.','staff/request.php?id=5',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (43,6,'Request #5 from Norliza binti Abdul Rahman (Faculty of Education): Car, With Driver to Putrajaya International Convention Centre (PICC), Sat, 28 Nov 2026, 8:00 am - 5:00 pm is waiting for driver assignment.','admin/assign_driver.php?id=5',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (44,7,'Driver assignment of request #5 from Norliza binti Abdul Rahman (Faculty of Education): Car, With Driver to Putrajaya International Convention Centre (PICC), Sat, 28 Nov 2026, 8:00 am - 5:00 pm is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (45,8,'Your vehicle request #5 was approved. Outbound: Azhari bin Mahmud (BDF 4400); Return: Azhari bin Mahmud (BDF 4400).','staff/request.php?id=5',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (46,22,'New outbound task: Sat, 28 Nov 2026, 8:00 am, BDF 4400, to Putrajaya International Convention Centre (PICC). Wait at Main entrance, Block A (Canselori). Officer: Norliza binti Abdul Rahman 012-300 0001.','driver/task.php?id=9',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (47,22,'New return task: Sat, 28 Nov 2026, 5:00 pm, BDF 4400, to Putrajaya International Convention Centre (PICC). Wait at Main entrance, Block A (Canselori). Officer: Norliza binti Abdul Rahman 012-300 0001.','driver/task.php?id=10',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (48,6,'The driver assignment of request #5 was approved by the Head of Admin.','staff/request.php?id=5',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (49,5,'URGENT. New vehicle request waiting for your approval #6 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Van, With Driver to Kolej Komuniti Hulu Langat, Thu, 26 Nov 2026, 2:00 pm - 6:00 pm. Purpose: Student counselling outreach.','staff/request.php?id=6',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (50,10,'Your vehicle request #6 was approved by the Head of Department.','staff/request.php?id=6',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (51,6,'URGENT. Request #6 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Van, With Driver to Kolej Komuniti Hulu Langat, Thu, 26 Nov 2026, 2:00 pm - 6:00 pm is waiting for driver assignment.','admin/assign_driver.php?id=6',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (52,7,'URGENT. Driver assignment of request #6 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Van, With Driver to Kolej Komuniti Hulu Langat, Thu, 26 Nov 2026, 2:00 pm - 6:00 pm is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (53,10,'Your vehicle request #6 was approved. Outbound: Zulkarnain bin Hamzah (WB 3322 D); Return: Zulkarnain bin Hamzah (WB 3322 D).','staff/request.php?id=6',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (54,16,'New outbound task: Thu, 26 Nov 2026, 2:00 pm, WB 3322 D, to Kolej Komuniti Hulu Langat. Wait at Main entrance, Block A (Canselori). Officer: Siti Aishah binti Mohd Yusof 012-300 0003.','driver/task.php?id=11',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (55,16,'New return task: Thu, 26 Nov 2026, 6:00 pm, WB 3322 D, to Kolej Komuniti Hulu Langat. Wait at Main entrance, Block A (Canselori). Officer: Siti Aishah binti Mohd Yusof 012-300 0003.','driver/task.php?id=12',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (56,6,'The driver assignment of request #6 was approved by the Head of Admin.','staff/request.php?id=6',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (57,7,'Driver replacement for Request task (return), Sat, 28 Nov 2026, 5:00 pm - 6:00 pm, BDF 4400: Azhari bin Mahmud -> Norhaslinda binti Abdul Karim is waiting for your approval. Reason: The driver has a medical appointment.','admin/approvals.php?tab=replacement',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (58,22,'You were replaced on Request task (return), Sat, 28 Nov 2026, 5:00 pm - 6:00 pm, BDF 4400. Reason: The driver has a medical appointment.','driver/tasks.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (59,13,'New task (replacement): Request task (return), Sat, 28 Nov 2026, 5:00 pm - 6:00 pm, BDF 4400, to Putrajaya International Convention Centre (PICC). Officer: Norliza binti Abdul Rahman 012-300 0001.','driver/task.php?id=10',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (60,8,'The driver of the return trip of your request #5 was changed to Norhaslinda binti Abdul Karim.','staff/request.php?id=5',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (61,6,'The Head of Admin approved the driver replacement on Request task (return), Sat, 28 Nov 2026, 5:00 pm - 6:00 pm, BDF 4400 (Norhaslinda binti Abdul Karim).','admin/schedule.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (62,3,'New vehicle request waiting for your approval #7 from Norliza binti Abdul Rahman (Faculty of Education): Bus x 4, With Driver to Muzium Negara, Fri, 04 Dec 2026, 7:30 am - 6:00 pm. Purpose: Faculty students field trip (Bachelor of Education).','staff/request.php?id=7',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (63,8,'Your vehicle request #7 was approved by the Head of Department.','staff/request.php?id=7',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (64,6,'Request #7 from Norliza binti Abdul Rahman (Faculty of Education): Bus x 4, With Driver to Muzium Negara, Fri, 04 Dec 2026, 7:30 am - 6:00 pm is waiting for driver assignment.','admin/assign_driver.php?id=7',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (65,7,'Driver assignment of request #7 from Norliza binti Abdul Rahman (Faculty of Education): Bus x 4, With Driver to Muzium Negara, Fri, 04 Dec 2026, 7:30 am - 6:00 pm is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (66,4,'New vehicle request waiting for your approval #8 from Muhammad Syafiq bin Zainal (Registrar Office): MPV, With Driver to Universiti Selangor (UNISEL), Fri, 27 Nov 2026, 9:30 am - 1:00 pm. Purpose: Benchmarking visit to the Registrar of UNISEL.','staff/request.php?id=8',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (67,9,'Your vehicle request #8 was approved by the Head of Department.','staff/request.php?id=8',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (68,6,'Request #8 from Muhammad Syafiq bin Zainal (Registrar Office): MPV, With Driver to Universiti Selangor (UNISEL), Fri, 27 Nov 2026, 9:30 am - 1:00 pm is waiting for driver assignment.','admin/assign_driver.php?id=8',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (69,5,'New vehicle request waiting for your approval #9 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Van, With Driver to Kem Bina Negara Bukit Tinggi, Tue, 01 Dec 2026, 8:00 am - Wed, 02 Dec 2026, 5:00 pm. Purpose: Leadership camp (two days).','staff/request.php?id=9',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (70,1,'New vehicle request waiting for your approval #10 from Hajah Rohana binti Ismail (Faculty of Education): Car, With Driver to Universiti Malaya, Mon, 30 Nov 2026, 10:00 am - 4:00 pm. Purpose: Deans meeting.','staff/request.php?id=10',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (71,4,'New vehicle request waiting for your approval #11 from Muhammad Syafiq bin Zainal (Registrar Office): Car, Pay and Claim to Arkib Negara Malaysia, Sun, 29 Nov 2026, 9:00 am - 12:00 pm. Purpose: Seminar on records management.','staff/request.php?id=11',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (72,9,'Your vehicle request #11 was approved by the Head of Department.','staff/request.php?id=11',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (73,7,'Request #11 from Muhammad Syafiq bin Zainal (Registrar Office): Car, Pay and Claim to Arkib Negara Malaysia, Sun, 29 Nov 2026, 9:00 am - 12:00 pm (Pay and Claim) is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (74,9,'Your vehicle request #11 was approved by the Head of Admin. You can print the approval letter from the request.','staff/request.php?id=11',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (75,6,'Request #11 (Pay and Claim) was approved by the Head of Admin.','staff/request.php?id=11',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (76,6,'New Personal lorry request #12 from Ahmad Faizal bin Mohd Rashid (Vehicle Section): Lorry, Self Drive to Taman Bukit Mewah, Thu, 03 Dec 2026, 8:00 am - 2:00 pm. Purpose: Moving house.','staff/request.php?id=12',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (77,7,'Personal Self Drive request #12 from Ahmad Faizal bin Mohd Rashid (Vehicle Section): Lorry, Self Drive to Taman Bukit Mewah, Thu, 03 Dec 2026, 8:00 am - 2:00 pm is waiting for your approval.','admin/approvals.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (78,11,'Your vehicle request #12 was approved by the Head of Admin. You can print the approval letter from the request.','staff/request.php?id=12',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (79,6,'Request #12 (Self Drive) was approved by the Head of Admin.','staff/request.php?id=12',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (80,6,'New Personal lorry request #13 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Lorry, With Driver to Kampung Sungai Merab, Sun, 06 Dec 2026, 9:00 am - 1:00 pm. Purpose: Transport furniture for a family wedding.','staff/request.php?id=13',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (81,4,'New vehicle request waiting for your approval #14 from Muhammad Syafiq bin Zainal (Registrar Office): MPV, With Driver to Percetakan Nasional Malaysia, Mon, 30 Nov 2026, 9:00 am - 12:00 pm. Purpose: Collect certificates from the printer.','staff/request.php?id=14',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (82,9,'Your vehicle request #14 was rejected by the Head of Department. Reason: The printer will deliver the certificates by courier.','staff/request.php?id=14',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (83,3,'New vehicle request waiting for your approval #15 from Norliza binti Abdul Rahman (Faculty of Education): Car, With Driver to Cyberjaya Convention Centre, Wed, 02 Dec 2026, 8:30 am - 12:30 pm. Purpose: Technology in education seminar.','staff/request.php?id=15',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (84,3,'Vehicle request #15 (Cyberjaya Convention Centre, Wed, 02 Dec 2026, 8:30 am) was cancelled by Norliza binti Abdul Rahman (Staff). Reason: The seminar was postponed to next month.','staff/request.php?id=15',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (85,6,'Vehicle request #15 (Cyberjaya Convention Centre, Wed, 02 Dec 2026, 8:30 am) was cancelled by Norliza binti Abdul Rahman (Staff). Reason: The seminar was postponed to next month.','staff/request.php?id=15',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (86,5,'New vehicle request waiting for your approval #16 from Siti Aishah binti Mohd Yusof (Student Affairs Division): Car, With Driver to Hospital Kajang, Sun, 29 Nov 2026, 9:00 am - 1:00 pm. Purpose: Student welfare visit.','staff/request.php?id=16',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (87,10,'Your vehicle request #16 has lapsed. The Head of Department did not approve the request within 48 hours. The vehicle reservation was released.','staff/request.php?id=16',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:50'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (88,7,'Driver assignment of internal task Refuelling, Thu, 26 Nov 2026, 8:00 am - 8:45 am, BGN 9595 (Roslan bin Abdul Wahab) is waiting for your approval.','admin/approvals.php?tab=internal',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (89,17,'New internal task: Refuelling, Thu, 26 Nov 2026, 8:00 am - 8:45 am, BGN 9595. Refuel bus BGN 9595 before the field trip','driver/task.php?id=20',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (90,6,'The Head of Admin approved internal task Refuelling, Thu, 26 Nov 2026, 8:00 am - 8:45 am, BGN 9595 (Roslan bin Abdul Wahab).','admin/internal_tasks.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (91,7,'Driver assignment of internal task Test Drive, Sat, 28 Nov 2026, 2:00 pm - 3:00 pm, BLJ 9955 (Nurul Ain binti Saharuddin) is waiting for your approval.','admin/approvals.php?tab=internal',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (92,7,'Driver assignment of internal task Workshop, Thu, 26 Nov 2026, 10:00 am - 12:00 pm, BQA 1188 (Siti Norzaharah binti Othman) is waiting for your approval.','admin/approvals.php?tab=internal',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (93,15,'New internal task: Workshop, Thu, 26 Nov 2026, 10:00 am - 12:00 pm, BQA 1188. Service at 60,000 km','driver/task.php?id=22',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (94,6,'The Head of Admin approved internal task Workshop, Thu, 26 Nov 2026, 10:00 am - 12:00 pm, BQA 1188 (Siti Norzaharah binti Othman).','admin/internal_tasks.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (95,6,'Task report: Workshop, Sat, 21 Nov 2026, 10:00 am - 12:00 pm, BQA 1188 by Siti Norzaharah binti Othman. End mileage 60,466 km, fuel Full.','admin/task.php?id=22',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (96,7,'The welfare duty schedule of the week of 23 Nov 2026 is waiting for your approval.','admin/approvals.php?tab=welfare',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (97,17,'Welfare duty (24 hours) on Wednesday, 25 Nov 2026 from 8:00 am, vehicle WA 2211 C.','driver/task.php?id=23',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (98,19,'Welfare duty (24 hours) on Thursday, 26 Nov 2026 from 8:00 am, vehicle BDG 5500.','driver/task.php?id=24',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (99,17,'Welfare duty (24 hours) on Friday, 27 Nov 2026 from 8:00 am, vehicle WA 2211 C.','driver/task.php?id=25',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (100,17,'Welfare duty (24 hours) on Saturday, 28 Nov 2026 from 8:00 am, vehicle BDG 5500.','driver/task.php?id=26',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (101,17,'Welfare duty (24 hours) on Sunday, 29 Nov 2026 from 8:00 am, vehicle WA 2211 C.','driver/task.php?id=27',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (102,6,'The Head of Admin approved the welfare duty schedule of the week of 23 Nov 2026.','admin/welfare.php?week=2026-11-23',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (103,7,'Schedule change: Siti Norzaharah binti Othman cannot work on 07 Dec 2026 - 08 Dec 2026. Reason: Family wedding in Kelantan','admin/schedule_changes.php?id=1',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (104,7,'Schedule change: Roslan bin Abdul Wahab cannot work on 10 Dec 2026. Reason: Court summons as a witness','admin/schedule_changes.php?id=2',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `link`, `is_read`, `email_sent`, `date_sent`) VALUES (105,17,'Your schedule change for 10 Dec 2026 was approved. You will not be given tasks on those dates.','driver/schedule_change.php',0,0,TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL -1 DAY), '17:46:51'));
UPDATE vehicles SET current_mileage = 48314, fuel_level = '1/2', status = 'active' WHERE vehicle_id = 1;
UPDATE vehicles SET current_mileage = 31540, fuel_level = '3/4', status = 'active' WHERE vehicle_id = 2;
UPDATE vehicles SET current_mileage = 152300, fuel_level = '1/2', status = 'inactive' WHERE vehicle_id = 3;
UPDATE vehicles SET current_mileage = 60466, fuel_level = 'Full', status = 'active' WHERE vehicle_id = 4;
UPDATE vehicles SET current_mileage = 22870, fuel_level = '3/4', status = 'active' WHERE vehicle_id = 5;
UPDATE vehicles SET current_mileage = 98750, fuel_level = 'Full', status = 'active' WHERE vehicle_id = 6;
UPDATE vehicles SET current_mileage = 143069, fuel_level = '1/2', status = 'maintenance' WHERE vehicle_id = 7;
UPDATE vehicles SET current_mileage = 210400, fuel_level = 'Full', status = 'active' WHERE vehicle_id = 8;
UPDATE vehicles SET current_mileage = 120750, fuel_level = '3/4', status = 'active' WHERE vehicle_id = 9;
UPDATE vehicles SET current_mileage = 187320, fuel_level = 'Full', status = 'active' WHERE vehicle_id = 10;
UPDATE vehicles SET current_mileage = 134800, fuel_level = '1/2', status = 'active' WHERE vehicle_id = 11;
UPDATE vehicles SET current_mileage = 56200, fuel_level = 'Full', status = 'active' WHERE vehicle_id = 12;
CREATE TRIGGER trg_history_no_update BEFORE UPDATE ON request_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'request_history is insert-only';
SET FOREIGN_KEY_CHECKS = 1;
