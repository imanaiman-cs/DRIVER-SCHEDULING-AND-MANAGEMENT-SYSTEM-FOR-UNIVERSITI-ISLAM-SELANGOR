-- ============================================================
-- UIS Driver Scheduling and Management System (DSMS)
-- database.sql  -  FINAL SCHEMA (SRS 2026-10) + demo data
--
-- Import it with phpMyAdmin (Import) or:   mysql -uroot < database.sql
-- It DROPS and re-creates the database uis_driver_db.
-- An existing database is upgraded with migrations/2026_10_align_srs.sql instead.
--
-- Demo passwords are listed in LOGIN_ACCOUNTS.txt (hashed with bcrypt here).
-- Demo dates are relative to the day of the import.
-- ============================================================

DROP DATABASE IF EXISTS uis_driver_db;
CREATE DATABASE uis_driver_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uis_driver_db;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+08:00';

-- ============================================================
-- TABLES
-- ============================================================

CREATE TABLE `drivers` (
  `driver_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `experience_years` decimal(4,1) NOT NULL DEFAULT 0.0,
  `license_number` varchar(50) DEFAULT NULL,
  `license_class` varchar(10) DEFAULT NULL COMMENT 'comma list: B2,D,E',
  `license_expiry` date DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `photo` varchar(255) DEFAULT NULL COMMENT 'relative path under uploads/drivers/',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`driver_id`),
  UNIQUE KEY `uq_drivers_employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','driver','staff','supervisor','head_admin') NOT NULL DEFAULT 'staff',
  `driver_id` int(11) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `phone` varchar(30) DEFAULT NULL,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Synced from the UIS staff system (tools/import_staff.php). The Vehicle Section does not create, edit or delete users.';

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL AUTO_INCREMENT,
  `plate_number` varchar(20) NOT NULL,
  `vehicle_type` enum('Bus','Van','Car','Minibus','Lorry','Motorcycle') NOT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `model` varchar(50) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `capacity` int(11) NOT NULL DEFAULT 4,
  `fuel_type` enum('Petrol','Diesel','Electric','Hybrid') NOT NULL DEFAULT 'Petrol',
  `status` enum('available','in_use','maintenance','inactive') NOT NULL DEFAULT 'available',
  `last_maintenance` date DEFAULT NULL,
  `next_maintenance` date DEFAULT NULL,
  `mileage` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL COMMENT 'relative path under uploads/vehicles/',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `license_required` enum('D','GDL','PSV') NOT NULL DEFAULT 'D',
  PRIMARY KEY (`vehicle_id`),
  UNIQUE KEY `uq_vehicles_plate_number` (`plate_number`),
  UNIQUE KEY `uq_vehicles_plate` (`plate_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schema_migrations` (
  `name` varchar(100) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `trip_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `destination` varchar(255) NOT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `passenger_count` int(11) NOT NULL DEFAULT 1,
  `officer_name` varchar(255) DEFAULT NULL COMMENT 'Officer(s) the driver serves',
  `officer_phone` varchar(50) DEFAULT NULL COMMENT 'Officer contact number',
  `waiting_place` varchar(150) DEFAULT NULL COMMENT 'Where the driver waits (tempat menunggu)',
  `status` enum('pending','approved','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `request_id` int(11) DEFAULT NULL,
  `leg_no` tinyint(4) DEFAULT NULL,
  `start_dt` datetime GENERATED ALWAYS AS (timestamp(`trip_date`,`start_time`)) STORED,
  `end_dt` datetime GENERATED ALWAYS AS (timestamp(`trip_date`,`end_time`)) STORED,
  PRIMARY KEY (`schedule_id`),
  KEY `fk_schedules_created_by` (`created_by`),
  KEY `idx_schedules_request` (`request_id`),
  KEY `idx_sched_driver_time` (`driver_id`,`start_dt`,`end_dt`),
  KEY `idx_sched_vehicle_time` (`vehicle_id`,`start_dt`,`end_dt`),
  KEY `idx_sched_request` (`request_id`),
  CONSTRAINT `fk_schedules_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_schedules_driver_id` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_schedules_vehicle_id` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vehicle_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `trip_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `destination` varchar(255) NOT NULL,
  `purpose` text NOT NULL,
  `passenger_count` int(11) NOT NULL DEFAULT 1,
  `officer_name` varchar(255) DEFAULT NULL,
  `officer_phone` varchar(50) DEFAULT NULL,
  `waiting_place` varchar(150) DEFAULT NULL,
  `status` enum('pending','approved','assigned','rejected','processed','cancelled','lapsed','completed') NOT NULL DEFAULT 'pending',
  `supervisor_id` int(11) DEFAULT NULL,
  `supervisor_notes` text DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status_reason` varchar(500) DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_by_role` varchar(20) DEFAULT NULL,
  `return_date` date NOT NULL,
  `destination_address` varchar(500) DEFAULT NULL,
  `passenger_names` text DEFAULT NULL,
  `waiting_address` varchar(255) DEFAULT NULL,
  `depart_at` datetime GENERATED ALWAYS AS (timestamp(`trip_date`,`start_time`)) STORED,
  `return_at` datetime GENERATED ALWAYS AS (timestamp(`return_date`,`end_time`)) STORED,
  `lapsed_notified` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`request_id`),
  KEY `fk_vr_staff` (`staff_id`),
  KEY `fk_vr_vehicle` (`vehicle_id`),
  KEY `fk_vr_supervisor` (`supervisor_id`),
  KEY `fk_vr_schedule` (`schedule_id`),
  KEY `idx_vr_period` (`depart_at`,`return_at`),
  KEY `idx_vr_status` (`status`,`created_at`),
  CONSTRAINT `fk_vr_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vr_staff` FOREIGN KEY (`staff_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vr_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vr_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_role` varchar(20) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_audit_request` (`request_id`),
  KEY `idx_audit_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `driver_replacements` (
  `replacement_id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_id` int(11) NOT NULL,
  `previous_driver_id` int(11) DEFAULT NULL,
  `new_driver_id` int(11) DEFAULT NULL,
  `reason` varchar(500) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`replacement_id`),
  KEY `idx_repl_schedule` (`schedule_id`),
  CONSTRAINT `fk_repl_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) DEFAULT NULL,
  `to_email` varchar(190) DEFAULT NULL,
  `delivered_to` varchar(255) DEFAULT NULL COMMENT 'set when a test redirect replaced the recipient',
  `subject` varchar(255) NOT NULL,
  `kind` varchar(30) NOT NULL COMMENT 'assigned, updated, cancelled, removed, reminder, test',
  `ref_date` date DEFAULT NULL COMMENT 'trip date the e-mail is about (used to avoid duplicate reminders)',
  `schedule_ids` varchar(255) DEFAULT NULL,
  `status` enum('sent','failed','skipped') NOT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_email_created` (`created_at`),
  KEY `idx_email_driver_kind` (`driver_id`,`kind`,`ref_date`),
  CONSTRAINT `fk_email_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE SET NULL
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
  KEY `idx_wa_created` (`created_at`),
  KEY `idx_wa_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `internal_tasks` (
  `task_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_type` enum('workshop_send','workshop_collect','refuel','test_drive','road_tax','document_delivery','other') NOT NULL,
  `task_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `driver_id` int(11) NOT NULL,
  `location` varchar(150) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `cancel_reason` varchar(255) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `start_dt` datetime GENERATED ALWAYS AS (timestamp(`task_date`,`start_time`)) STORED,
  `end_dt` datetime GENERATED ALWAYS AS (timestamp(`task_date`,`end_time`)) STORED,
  PRIMARY KEY (`task_id`),
  KEY `idx_itask_driver` (`driver_id`,`task_date`),
  KEY `idx_itask_time` (`driver_id`,`start_dt`,`end_dt`),
  KEY `idx_itask_vehicle_time` (`vehicle_id`,`start_dt`,`end_dt`),
  CONSTRAINT `fk_itask_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` varchar(500) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_notif_user` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reminders` (
  `reminder_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `email_error` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`reminder_id`),
  KEY `idx_reminder_request` (`request_id`),
  CONSTRAINT `fk_reminder_request` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `request_comments` (
  `comment_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_role` varchar(20) NOT NULL,
  `comment` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`comment_id`),
  KEY `idx_comment_request` (`request_id`,`created_at`),
  KEY `fk_comment_user` (`user_id`),
  CONSTRAINT `fk_comment_request` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `request_documents` (
  `doc_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `doc_type` enum('release_letter','seminar_letter','approval_letter','programme','other') NOT NULL DEFAULT 'other',
  `original_name` varchar(255) NOT NULL,
  `stored_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`doc_id`),
  KEY `idx_rd_request` (`request_id`),
  CONSTRAINT `fk_rd_request` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vehicle_locks` (
  `lock_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `start_dt` datetime NOT NULL,
  `end_dt` datetime NOT NULL,
  PRIMARY KEY (`lock_id`),
  KEY `idx_lock_vehicle` (`vehicle_id`,`start_dt`,`end_dt`),
  KEY `idx_lock_request` (`request_id`),
  CONSTRAINT `fk_lock_request` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lock_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vehicle_maintenance` (
  `maintenance_id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `workshop` varchar(120) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`maintenance_id`),
  KEY `idx_maint_dates` (`vehicle_id`,`start_date`,`end_date`),
  CONSTRAINT `fk_maint_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `welfare_roster` (
  `roster_id` int(11) NOT NULL AUTO_INCREMENT,
  `duty_date` date NOT NULL,
  `driver_id` int(11) NOT NULL,
  `replaced_from_driver_id` int(11) DEFAULT NULL,
  `replacement_reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`roster_id`),
  UNIQUE KEY `uq_welfare_date` (`duty_date`),
  KEY `idx_welfare_driver` (`driver_id`,`duty_date`),
  CONSTRAINT `fk_welfare_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- The history (audit_log) and the comments are INSERT ONLY: the database refuses to change or delete a row.
DELIMITER //
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is insert-only'//
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is insert-only'//
CREATE TRIGGER trg_comment_no_update BEFORE UPDATE ON request_comments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Comments cannot be edited'//
CREATE TRIGGER trg_comment_no_delete BEFORE DELETE ON request_comments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Comments cannot be deleted'//
DELIMITER ;


-- ============================================================
-- SEED: drivers (carpool drivers; licence classes D = car/van, GDL = lorry, PSV = bus)
-- ============================================================
INSERT INTO drivers (driver_id, employee_id, name, phone, email, experience_years, license_number, license_class, license_expiry, address, status) VALUES
(1,'UIS-DRV-001','Ahmad Faizal bin Mohd Rashid','012-3456789','faizal.rashid@uis.edu.my',15.0,'D1234567','D,GDL,PSV','2027-08-31','No. 12, Jalan Cempaka 3, Taman Cempaka, 40150 Shah Alam, Selangor','active'),
(2,'UIS-DRV-002','Mohd Hafizuddin bin Zulkifli','013-2345678','hafizuddin.zulkifli@uis.edu.my',10.5,'D2345678','D,GDL,PSV','2027-12-31','No. 5, Jalan Mawar 7, Taman Sri Muda, 40400 Shah Alam, Selangor','active'),
(3,'UIS-DRV-003','Norhaslinda binti Abdul Karim','011-34567890','haslinda.karim@uis.edu.my',7.0,'D3456789','D,GDL','2027-03-15','No. 88, Jalan Kenanga 2, Taman Kenanga, 45000 Kuala Selangor, Selangor','active'),
(4,'UIS-DRV-004','Khairul Anuar bin Ismail','019-4567890','khairul.ismail@uis.edu.my',12.0,'D4567890','D,PSV','2028-01-20','No. 33, Jalan Damai 11, Taman Damai Jaya, 41000 Klang, Selangor','active'),
(5,'UIS-DRV-005','Siti Norzaharah binti Othman','017-5678901','zaharah.othman@uis.edu.my',3.5,'D5678901','D','2027-06-30','No. 21, Jalan Melati 4, Taman Melati Indah, 68000 Ampang, Selangor','active'),
(6,'UIS-DRV-006','Zulkarnain bin Hamzah','014-6789012','zulkarnain.hamzah@uis.edu.my',8.0,'D6789012','D,GDL,PSV','2027-11-10','No. 7, Jalan Anggerik 9, Taman Anggerik, 41150 Klang, Selangor','active'),
(7,'UIS-DRV-007','Roslan bin Abdul Wahab','016-7890123','roslan.wahab@uis.edu.my',2.0,'D7890123','D,PSV','2027-09-14','No. 45, Jalan Delima 6, Taman Delima, 40460 Shah Alam, Selangor','active'),
(8,'UIS-DRV-008','Muhammad Asyraf bin Che Hassan','018-8901234','asyraf.hassan@uis.edu.my',5.0,'D8901234','D','2027-07-22','No. 60, Jalan Bayu 3, Taman Bayu Perdana, 41200 Klang, Selangor','active'),
(9,'UIS-DRV-009','Fadzillah bin Mohd Noor','012-9012345','fadzillah.noor@uis.edu.my',18.0,'D9012345','D,GDL,PSV','2028-05-15','No. 3, Jalan Pelangi 1, Taman Pelangi Maju, 40150 Shah Alam, Selangor','active'),
(10,'UIS-DRV-010','Nurul Ain binti Saharuddin','011-0123456','nurulain.saharuddin@uis.edu.my',4.0,'D0123456','D,GDL','2027-10-30','No. 18, Jalan Seroja 5, Taman Seroja, 41000 Klang, Selangor','active'),
(11,'UIS-DRV-011','Hairul Nizam bin Kamaruddin','013-1234567','hairul.kamaruddin@uis.edu.my',6.0,'D1345678','D','2026-12-01','No. 27, Jalan Teratai 8, Taman Sri Andalas, 41200 Klang, Selangor','inactive'),
(12,'UIS-DRV-012','Azhari bin Mahmud','017-2345670','azhari.mahmud@uis.edu.my',9.0,'D2456789','D,PSV','2028-03-20','No. 9, Jalan Dahlia 3, Taman Meru Jaya, 41050 Klang, Selangor','active');


-- ============================================================
-- SEED: user accounts  (in the real system these are synced from the UIS staff system, see tools/import_staff.php)
--   Staff -> their Head of Department; a Head of Department -> hos005; drivers and the Vehicle Section -> hos001 (Transport Unit)
-- ============================================================
INSERT INTO users (user_id, username, password, email, full_name, role, driver_id, department, supervisor_id, phone) VALUES
(1,'hos005','$2y$12$xzBlgF5MRUwZFocb9IhoLeCpdZgnGELJiYO.VbYaGQAFqx1Rl8E0e','zaki.mansor@uis.edu.my','Prof. Dr. Ahmad Zaki bin Mansor','supervisor',NULL,'Rectory',NULL,'03-5543 0001'),
(2,'hos001','$2y$12$H6WgmaP4zlDLChQYdYJBhuQcP6fcI4tmjS9pqvoRItzdpziGaSaWm','hafizi@uis.edu.my','Mohd Hafizi bin Harun','supervisor',NULL,'Transport Unit',1,'03-5543 0002'),
(3,'hos002','$2y$12$XeyHfK6Rf9NbOczoyh6t0uhe7/aEz0j3INBbgg6AyyX/FhaE.pkO.','rohana@uis.edu.my','Hajah Rohana binti Ismail','supervisor',NULL,'Faculty of Education',1,'03-5543 0003'),
(4,'hos003','$2y$12$eciXr.HeEw4AlHHvdDo9M.IoJKCHrTK0nAGg5QbLj7kx6Q/jrKKAK','azlina@uis.edu.my','Dr. Nor Azlina binti Hashim','supervisor',NULL,'Registrar Office',1,'03-5543 0004'),
(5,'hos004','$2y$12$fu1qLIgsoXP0sGf5kNh3weU7sQ2Kdl//TxtuNrEml1eud7Vtvxw1e','azman@uis.edu.my','Azman bin Yusof','supervisor',NULL,'Student Affairs Division',1,'03-5543 0005'),
(6,'admin','$2y$12$Ky8cs04nGk0MN.JmVm5ZMOY3C7mi3JFgmm130RHK6/lYUI4tfoRAu','rozaimi@uis.edu.my','Rozaimi bin Kamaruzzaman','admin',NULL,'Transport Unit',2,'03-5543 0010'),
(7,'head001','$2y$12$D/sduuDhSKUWG2PTlqRMqu8ga8o4GpV7/24VSLUIvaex0v/cMzh6a','zulkifli@uis.edu.my','Haji Zulkifli bin Awang','head_admin',NULL,'Transport Unit',2,'03-5543 0011'),
(8,'stf001','$2y$12$c7wQzp3KQoe30AH0WfKG9OSYlsJ8HDZsdnvL7R/DInzO1W3DcPtCe','norliza@uis.edu.my','Norliza binti Abdul Rahman','staff',NULL,'Faculty of Education',3,'012-300 0001'),
(9,'stf002','$2y$12$NrH1dWsfKKMOqDCJ8Z9ULuTZU6KrxNcI3ZoszEeYbdWLvT94EaCuC','syafiq@uis.edu.my','Muhammad Syafiq bin Zainal','staff',NULL,'Registrar Office',4,'012-300 0002'),
(10,'stf003','$2y$12$fQW.21e97zb4kA2zQNZhQeMGuos.lHLTqSL8Wcjb9rAirHFD6CUIi','aishah@uis.edu.my','Siti Aishah binti Mohd Yusof','staff',NULL,'Student Affairs Division',5,'012-300 0003'),
(11,'drv001','$2y$12$/4THfhaG2FpWKyDQBr3xUONs88LKyQT4b.H9P05oPy9GMGZahj91e','faizal.rashid@uis.edu.my','Ahmad Faizal bin Mohd Rashid','driver',1,'Transport Unit',2,'012-3456789'),
(12,'drv002','$2y$12$X8fkpo6nK6JeMJJDpA5f9.rYBiNzxwaP0xTR9hl3IY/kfY0J/enQy','hafizuddin.zulkifli@uis.edu.my','Mohd Hafizuddin bin Zulkifli','driver',2,'Transport Unit',2,'013-2345678'),
(13,'drv003','$2y$12$BwTwPgmpl1afd1.YwGtWiujeqv.ylIe7t0zMFMN2xcdL9V2uGK9.2','haslinda.karim@uis.edu.my','Norhaslinda binti Abdul Karim','driver',3,'Transport Unit',2,'011-34567890'),
(14,'drv004','$2y$12$p2H563tPWhI39CMCWmoej.uqy4GU8rEXgyyFqSiKLzyWdXjIFFunO','khairul.ismail@uis.edu.my','Khairul Anuar bin Ismail','driver',4,'Transport Unit',2,'019-4567890'),
(15,'drv005','$2y$12$B./snxxaNOQW/m3nGHCzOu6.vE1yBboSYw6e0T2YGLgk/nhkrCQTO','zaharah.othman@uis.edu.my','Siti Norzaharah binti Othman','driver',5,'Transport Unit',2,'017-5678901'),
(16,'drv006','$2y$12$OuCs.OS3tn3/5AL7kSteL.Km2tU5Ga6VshDDyY7EQ2RgK7/7BV/U6','zulkarnain.hamzah@uis.edu.my','Zulkarnain bin Hamzah','driver',6,'Transport Unit',2,'014-6789012'),
(17,'drv007','$2y$12$4gBoRqyK5PGgPMBsm3bM4e7Bg8lTj9HM7x3VMZLc7.z236n5Nmj7q','roslan.wahab@uis.edu.my','Roslan bin Abdul Wahab','driver',7,'Transport Unit',2,'016-7890123'),
(18,'drv008','$2y$12$2j8fE3JFEUG3wrBFkp7hpumdKq1sf102wonp1UsFh2vZvsxilUAli','asyraf.hassan@uis.edu.my','Muhammad Asyraf bin Che Hassan','driver',8,'Transport Unit',2,'018-8901234'),
(19,'drv009','$2y$12$VMQUX6UE0FcTKPrxrHH3NusJSIgX0hVpMwIS8hK2JzDe8al17I2/S','fadzillah.noor@uis.edu.my','Fadzillah bin Mohd Noor','driver',9,'Transport Unit',2,'012-9012345'),
(20,'drv010','$2y$12$MO6xE.fogb.zXnSre2u6weaI.j9fT/4Uioq5z0ODbdeytufUm676a','nurulain.saharuddin@uis.edu.my','Nurul Ain binti Saharuddin','driver',10,'Transport Unit',2,'011-0123456'),
(21,'drv011','$2y$12$wJ0eYOKAGOoyZ1C5x/RJLuk8JaeN4JqP0Xl9TWt5BZisC5pgWne0K','hairul.kamaruddin@uis.edu.my','Hairul Nizam bin Kamaruddin','driver',11,'Transport Unit',2,'013-1234567'),
(22,'drv012','$2y$12$DhemtMeLYiDwg6jlS6sjt.LVqYokH7e06j43TrfXY6GcEpzmGeDC6','azhari.mahmud@uis.edu.my','Azhari bin Mahmud','driver',12,'Transport Unit',2,'017-2345670');


-- ============================================================
-- SEED: vehicles (car, van, lorry, bus). One is under maintenance, one is inactive.
-- ============================================================
INSERT INTO vehicles (vehicle_id, plate_number, vehicle_type, brand, model, year, capacity, fuel_type, status, license_required) VALUES
(1,'BGN 9595','Bus','Hino','RK8J',2019,44,'Diesel','available','PSV'),
(2,'BNJ 9276','Bus','Hino','RN8J',2021,44,'Diesel','available','PSV'),
(3,'WA 2211 C','Van','Toyota','Hiace',2020,14,'Diesel','available','D'),
(4,'WB 3322 D','Van','Nissan','Urvan',2018,12,'Diesel','maintenance','D'),
(5,'BDF 4400 E','Car','Proton','X70',2022,5,'Petrol','available','D'),
(6,'BDG 5500 F','Car','Perodua','Bezza',2023,5,'Petrol','available','D'),
(7,'SEL 7890 G','Bus','Toyota','Hiace Commuter',2021,16,'Diesel','available','PSV'),
(8,'BKH 9555','Bus','Nissan','Civilian',2020,40,'Diesel','available','PSV'),
(9,'BDF 7700 J','Car','Honda','Civic',2019,5,'Petrol','inactive','D'),
(10,'BNE 3611','Bus','Nissan','Civilian',2018,40,'Diesel','available','PSV'),
(11,'BHW 8595','Lorry','Nissan','Daihatsu',2017,3,'Diesel','available','GDL'),
(12,'BLJ 9955','Lorry','Daihatsu','Gran Max',2020,3,'Diesel','available','GDL');


INSERT INTO vehicle_maintenance (vehicle_id, start_date, end_date, workshop, note)
VALUES (4, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 9 DAY), 'Auto Service Klang Sdn Bhd', 'Gearbox repair');

-- ============================================================
-- SEED: settings
-- ============================================================
INSERT INTO settings (setting_key, setting_value, description) VALUES
 ('W_TASKS',             '0.6',   'Weight of the number of tasks this month in the driver score'),
 ('W_AFTER_HOURS',       '0.4',   'Weight of the number of tasks outside working hours in the driver score'),
 ('LAPSE_HOURS',         '48',    'A request still Pending after this many hours becomes Lapsed'),
 ('WORK_START',          '08:00', 'Working hours start (a task before this is outside working hours)'),
 ('WORK_END',            '17:30', 'Working hours end (a task after this is outside working hours)'),
 ('SESSION_TIMEOUT_MIN', '15',    'Minutes without activity before a user is signed out'),
 ('MAX_FAILED_ATTEMPTS', '5',     'Failed logins before the login is blocked'),
 ('LOCKOUT_MINUTES',     '15',    'Minutes a login is blocked after too many failed attempts'),
 ('TASK_DURATION_HOURS', '2',     'Length of the outbound and the return task of a trip (hours)');

-- ============================================================
-- SEED: demo requests (relative to today) in every status
-- ============================================================
INSERT INTO vehicle_requests
 (request_id, staff_id, vehicle_id, trip_date, start_time, return_date, end_time, destination, destination_address, purpose, passenger_count, passenger_names,
  officer_name, officer_phone, waiting_place, waiting_address, status, supervisor_id, status_reason, reviewed_at, created_at)
VALUES
 (1, 8, 5, DATE_ADD(CURDATE(), INTERVAL 7 DAY), '09:00:00', DATE_ADD(CURDATE(), INTERVAL 7 DAY), '17:00:00', 'Putrajaya Convention Centre',
  'Presint 5, 62000 Putrajaya', 'Attend the national higher education forum with the faculty delegation', 3, 'Norliza; Hazim; Aina',
  'Norliza binti Abdul Rahman', '012-300 0001', 'Main entrance', NULL, 'pending', 3, NULL, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),
 (2, 9, 3, DATE_ADD(CURDATE(), INTERVAL 5 DAY), '08:00:00', DATE_ADD(CURDATE(), INTERVAL 6 DAY), '12:00:00', 'Universiti Malaysia Terengganu',
  'Jalan Datuk Mohd Abdullah, 21030 Kuala Nerus, Terengganu', 'Registrar benchmarking visit (two days)', 9, 'Syafiq; Rahim; Mariam; 6 officers',
  'Muhammad Syafiq bin Zainal', '012-300 0002', 'Main entrance', NULL, 'approved', 4, NULL, DATE_SUB(NOW(), INTERVAL 5 HOUR), DATE_SUB(NOW(), INTERVAL 20 HOUR)),
 (3, 10, 6, DATE_ADD(CURDATE(), INTERVAL 4 DAY), '10:00:00', DATE_ADD(CURDATE(), INTERVAL 4 DAY), '16:00:00', 'Kementerian Pendidikan Tinggi',
  'No. 2, Menara 2, Jalan P5/6, Presint 5, 62200 Putrajaya', 'Submit the student affairs report', 2, 'Aishah; Hakim',
  'Siti Aishah binti Mohd Yusof', '012-300 0003', 'Other', 'Lobby of Block B', 'assigned', 5, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
 (4, 8, 1, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '07:30:00', DATE_ADD(CURDATE(), INTERVAL 3 DAY), '17:30:00', 'Masjid Sultan Salahuddin Abdul Aziz Shah',
  'Persiaran Masjid, Seksyen 14, 40000 Shah Alam', 'Faculty students programme (bus)', 40, '40 students and 2 lecturers',
  'Norliza binti Abdul Rahman', '012-300 0001', 'Main entrance', NULL, 'processed', 3, NULL, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
 (5, 9, 5, DATE_SUB(CURDATE(), INTERVAL 2 DAY), '09:00:00', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '12:00:00', 'Pejabat Tanah Shah Alam',
  'Seksyen 14, 40000 Shah Alam', 'Collect land office documents', 2, 'Syafiq; Rahim',
  'Muhammad Syafiq bin Zainal', '012-300 0002', 'Main entrance', NULL, 'rejected', 4, 'The documents can be sent by courier.', DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
 (6, 10, 6, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '09:00:00', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:00:00', 'Pusat Islam Selangor',
  'Seksyen 5, 40000 Shah Alam', 'Student counselling programme', 4, 'Aishah and 3 counsellors',
  'Siti Aishah binti Mohd Yusof', '012-300 0003', 'Main entrance', NULL, 'lapsed', 5, NULL, NULL, DATE_SUB(NOW(), INTERVAL 4 DAY)),
 (7, 8, 6, DATE_ADD(CURDATE(), INTERVAL 10 DAY), '08:30:00', DATE_ADD(CURDATE(), INTERVAL 10 DAY), '12:30:00', 'Cyberjaya Convention Centre',
  'Persiaran Multimedia, 63000 Cyberjaya', 'Technology seminar', 2, 'Norliza; Hazim',
  'Norliza binti Abdul Rahman', '012-300 0001', 'Main entrance', NULL, 'cancelled', 3, 'The seminar was postponed.', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
 (8, 8, 3, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '08:00:00', DATE_SUB(CURDATE(), INTERVAL 12 DAY), '15:00:00', 'Universiti Putra Malaysia',
  'Persiaran Universiti 1, 43400 Serdang', 'Joint research meeting', 5, 'Faculty research team',
  'Norliza binti Abdul Rahman', '012-300 0001', 'Main entrance', NULL, 'completed', 3, NULL, DATE_SUB(NOW(), INTERVAL 17 DAY), DATE_SUB(NOW(), INTERVAL 18 DAY));
UPDATE vehicle_requests SET cancelled_at = DATE_SUB(NOW(), INTERVAL 2 DAY), cancelled_by = 8, cancelled_by_role = 'staff' WHERE request_id = 7;

-- Vehicle locks of the active requests (the requested period)
INSERT INTO vehicle_locks (request_id, vehicle_id, start_dt, end_dt)
SELECT request_id, vehicle_id, depart_at, return_at FROM vehicle_requests WHERE status IN ('pending','approved','assigned','processed');

-- Tasks of request 3 (Driver Assigned), 4 (Confirmed) and 8 (Completed): outbound (leg 1) + return (leg 2)
INSERT INTO schedules (driver_id, vehicle_id, trip_date, start_time, end_time, destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
                       status, created_by, notes, request_id, leg_no)
SELECT t.driver, vr.vehicle_id, t.d, t.s, t.e, vr.destination, LEFT(vr.purpose, 255), vr.passenger_count, vr.officer_name, vr.officer_phone,
       COALESCE(vr.waiting_address, vr.waiting_place), t.st, 6, CONCAT('e-Kenderaan request #', vr.request_id), vr.request_id, t.leg
  FROM vehicle_requests vr
  JOIN (SELECT 3 AS rid, 1 AS leg, 3 AS driver, DATE_ADD(CURDATE(), INTERVAL 4 DAY) AS d, '10:00:00' AS s, '12:00:00' AS e, 'approved' AS st
        UNION ALL SELECT 3, 2, 5, DATE_ADD(CURDATE(), INTERVAL 4 DAY), '16:00:00', '18:00:00', 'approved'
        UNION ALL SELECT 4, 1, 1, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '07:30:00', '09:30:00', 'approved'
        UNION ALL SELECT 4, 2, 1, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '17:30:00', '19:30:00', 'approved'
        UNION ALL SELECT 8, 1, 8, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '08:00:00', '10:00:00', 'completed'
        UNION ALL SELECT 8, 2, 8, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '15:00:00', '17:00:00', 'completed') t ON t.rid = vr.request_id;
UPDATE vehicle_requests SET schedule_id = (SELECT MIN(schedule_id) FROM schedules WHERE schedules.request_id = vehicle_requests.request_id)
 WHERE request_id IN (3, 4, 8);

-- History of the demo requests (who, what, when, reason)
INSERT INTO audit_log (user_id, user_role, action, request_id, reason, created_at) VALUES
 (8, 'staff', 'submit', 1, 'Request submitted', DATE_SUB(NOW(), INTERVAL 1 DAY)),
 (9, 'staff', 'submit', 2, 'Request submitted', DATE_SUB(NOW(), INTERVAL 20 HOUR)),
 (4, 'supervisor', 'approve', 2, NULL, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
 (10, 'staff', 'submit', 3, 'Request submitted', DATE_SUB(NOW(), INTERVAL 3 DAY)),
 (5, 'supervisor', 'approve', 3, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (6, 'admin', 'assign', 3, 'Task 1: Norhaslinda; task 2: Siti Norzaharah', DATE_SUB(NOW(), INTERVAL 1 DAY)),
 (8, 'staff', 'submit', 4, 'Request submitted', DATE_SUB(NOW(), INTERVAL 5 DAY)),
 (3, 'supervisor', 'approve', 4, NULL, DATE_SUB(NOW(), INTERVAL 4 DAY)),
 (6, 'admin', 'assign', 4, 'Both tasks: Ahmad Faizal', DATE_SUB(NOW(), INTERVAL 3 DAY)),
 (7, 'head_admin', 'confirm', 4, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (9, 'staff', 'submit', 5, 'Request submitted', DATE_SUB(NOW(), INTERVAL 5 DAY)),
 (4, 'supervisor', 'reject', 5, 'The documents can be sent by courier.', DATE_SUB(NOW(), INTERVAL 4 DAY)),
 (10, 'staff', 'submit', 6, 'Request submitted', DATE_SUB(NOW(), INTERVAL 4 DAY)),
 (NULL, 'system', 'lapse', 6, 'Not approved within two days', DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (8, 'staff', 'submit', 7, 'Request submitted', DATE_SUB(NOW(), INTERVAL 3 DAY)),
 (3, 'supervisor', 'approve', 7, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (8, 'staff', 'cancel', 7, 'The seminar was postponed.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (8, 'staff', 'submit', 8, 'Request submitted', DATE_SUB(NOW(), INTERVAL 18 DAY)),
 (3, 'supervisor', 'approve', 8, NULL, DATE_SUB(NOW(), INTERVAL 17 DAY)),
 (7, 'head_admin', 'confirm', 8, NULL, DATE_SUB(NOW(), INTERVAL 15 DAY)),
 (NULL, 'system', 'complete', 8, NULL, DATE_SUB(NOW(), INTERVAL 12 DAY));
INSERT INTO request_comments (request_id, user_id, user_role, comment, created_at)
VALUES (4, 3, 'supervisor', 'Please make sure the bus leaves on time; students must be back before Maghrib.', DATE_SUB(NOW(), INTERVAL 4 DAY));

-- ============================================================
-- SEED: past tasks (completed) so the recommender and the reports have something to show
--   about 45 tasks over the last two months, spread over the drivers, some in the evening or at the weekend
-- ============================================================
INSERT INTO schedules (driver_id, vehicle_id, trip_date, start_time, end_time, destination, purpose, passenger_count, officer_name, officer_phone, waiting_place, status, created_by, notes)
WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 45)
SELECT ELT(1 + (i * 5) MOD 10, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10),
       ELT(1 + (i MOD 3), 5, 6, 3),
       DATE_SUB(CURDATE(), INTERVAL (3 + (i * 4) MOD 58) DAY),
       ELT(1 + (i MOD 5), '07:00:00', '09:00:00', '13:00:00', '18:30:00', '08:30:00'),
       ELT(1 + (i MOD 5), '09:00:00', '11:30:00', '16:00:00', '21:00:00', '12:00:00'),
       ELT(1 + (i MOD 6), 'Putrajaya', 'Kuala Lumpur Sentral', 'Bangi', 'Universiti Malaya', 'KLIA', 'Shah Alam'),
       'Official duty', 2, 'Vehicle Section', '03-5543 0010', 'Main entrance', 'completed', 6, 'Imported past task'
  FROM n;

-- ============================================================
-- SEED: internal tasks (workshop runs, refuelling, test drives, document delivery)
-- ============================================================
INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, vehicle_id, driver_id, location, description, created_by, status, cancel_reason, cancelled_at) VALUES
 ('workshop_send',     DATE_SUB(CURDATE(), INTERVAL 3 DAY), '09:00:00', '11:00:00', 4, 2, 'Auto Service Klang Sdn Bhd', 'Send the Urvan for the gearbox repair', 6, 'active', NULL, NULL),
 ('refuel',            DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:00:00', '09:00:00', 1, 6, 'Petronas Seksyen 15',       'Refuel the bus before the faculty trip', 6, 'active', NULL, NULL),
 ('test_drive',        DATE_ADD(CURDATE(), INTERVAL 2 DAY), '10:00:00', '11:00:00', 12, 9, 'Shah Alam',                'Test drive after the brake service', 6, 'active', NULL, NULL),
 ('document_delivery', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '14:00:00', '16:00:00', 6, 10, 'Putrajaya',               'Deliver the vehicle insurance documents', 6, 'active', NULL, NULL),
 ('workshop_collect',  DATE_ADD(CURDATE(), INTERVAL 9 DAY), '09:00:00', '10:30:00', 4, 12, 'Auto Service Klang Sdn Bhd', 'Collect the Urvan after the repair', 6, 'active', NULL, NULL),
 ('refuel',            DATE_ADD(CURDATE(), INTERVAL 6 DAY), '17:45:00', '18:30:00', 3, 4, 'Petronas Seksyen 9',        'Refuel the van before the long trip', 6, 'cancelled', 'The van was refuelled earlier.', NOW());

-- ============================================================
-- SEED: welfare duty roster for this month (one driver per day, 24 hours, in rotation)
-- ============================================================
INSERT INTO welfare_roster (duty_date, driver_id)
WITH RECURSIVE d (day_date) AS (
    SELECT DATE_FORMAT(CURDATE(), '%Y-%m-01')
    UNION ALL SELECT DATE_ADD(day_date, INTERVAL 1 DAY) FROM d WHERE day_date < LAST_DAY(CURDATE())
)
SELECT day_date, ELT(1 + (DAYOFMONTH(day_date) MOD 10), 1, 2, 3, 4, 5, 6, 7, 8, 9, 10) FROM d;

SET FOREIGN_KEY_CHECKS = 1;
INSERT INTO schema_migrations (name) VALUES ('2026_10_align_srs');
