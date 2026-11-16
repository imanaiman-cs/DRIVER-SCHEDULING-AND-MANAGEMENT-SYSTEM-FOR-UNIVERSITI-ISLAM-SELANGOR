-- Class diagram (Iman, 16 Nov 2026): ONE Task class for request tasks, internal tasks and welfare duty days, and a WelfareDuty class per week.
--   schedules + internal_tasks + welfare_roster  ->  tasks (task_source = request | internal | welfare)
--   welfare weeks                                 ->  welfare_duties (one row per week, 1..7 tasks)
--   task_reports, driver_replacements, vehicle_usage_log, schedule_change_requests now point to tasks.task_id
--   vehicle_requests.schedule_id is dropped (a request has 0..* tasks through tasks.request_id)
-- Task ids: a request task keeps its schedule_id; internal and welfare tasks get new ids after the last schedule id.
-- Status of a task: waiting (no driver) -> assigned (driver assigned) -> approved (Head of Admin) -> completed; or cancelled.
-- Run it ONCE (the app records it in schema_migrations); it moves data and drops the old tables.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `welfare_duties` (
  `duty_id` int(11) NOT NULL AUTO_INCREMENT,
  `week_start_date` date NOT NULL COMMENT 'Monday of the week',
  `status` enum('waiting','assigned','approved') NOT NULL DEFAULT 'waiting' COMMENT 'follows its days: waiting if a day has no driver, assigned if a day waits for the Head of Admin, else approved',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`duty_id`),
  UNIQUE KEY `uq_duty_week` (`week_start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tasks` (
  `task_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_source` enum('request','internal','welfare') NOT NULL,
  `request_id` int(11) DEFAULT NULL COMMENT 'task_source request',
  `duty_id` int(11) DEFAULT NULL COMMENT 'task_source welfare',
  `task_type` enum('trip','workshop','workshop_send','workshop_collect','refuel','test_drive','road_tax','document_delivery','welfare','other') NOT NULL,
  `trip_type` enum('outbound','return') DEFAULT NULL COMMENT 'request tasks: outbound or return trip',
  `driver_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `start_dt` datetime NOT NULL,
  `end_dt` datetime NOT NULL,
  `task_date` date GENERATED ALWAYS AS (cast(`start_dt` as date)) STORED,
  `location` varchar(255) DEFAULT NULL COMMENT 'destination of a trip or place of an internal task',
  `description` varchar(255) DEFAULT NULL,
  `vendor_name` varchar(150) DEFAULT NULL COMMENT 'vendor bus: company',
  `vendor_bus_plate` varchar(20) DEFAULT NULL COMMENT 'vendor bus: registration number',
  `vendor_driver_name` varchar(100) DEFAULT NULL COMMENT 'vendor bus: driver',
  `status` enum('waiting','assigned','approved','completed','cancelled') NOT NULL DEFAULT 'waiting',
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `welfare_date` date GENERATED ALWAYS AS (if(`task_source` = 'welfare', cast(`start_dt` as date), NULL)) STORED COMMENT 'one welfare duty per day',
  PRIMARY KEY (`task_id`),
  UNIQUE KEY `uq_task_welfare_date` (`welfare_date`),
  KEY `idx_task_request` (`request_id`, `trip_type`),
  KEY `idx_task_duty` (`duty_id`),
  KEY `idx_task_driver_time` (`driver_id`, `start_dt`, `end_dt`),
  KEY `idx_task_vehicle_time` (`vehicle_id`, `start_dt`, `end_dt`),
  KEY `idx_task_source` (`task_source`, `status`, `task_date`),
  CONSTRAINT `fk_task_request` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_duty` FOREIGN KEY (`duty_id`) REFERENCES `welfare_duties` (`duty_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Class Task: request tasks, internal tasks and welfare duty days';

-- id offsets for internal and welfare tasks
SET @base_int := (SELECT COALESCE(MAX(schedule_id), 0) FROM schedules);
SET @base_wel := @base_int + (SELECT COALESCE(MAX(task_id), 0) FROM internal_tasks);

-- 1. request tasks (and the imported past trips without a request)
INSERT IGNORE INTO tasks (task_id, task_source, request_id, task_type, trip_type, driver_id, vehicle_id, start_dt, end_dt, location, description,
                          vendor_name, vendor_bus_plate, vendor_driver_name, status, assigned_by, assigned_at, approved_at, created_by, created_at)
SELECT s.schedule_id, 'request', s.request_id, 'trip', CASE s.leg_no WHEN 1 THEN 'outbound' WHEN 2 THEN 'return' ELSE NULL END,
       s.driver_id, s.vehicle_id, s.start_dt, s.end_dt, s.destination, s.purpose, s.vendor_name, s.vendor_bus_plate, s.vendor_driver_name,
       CASE WHEN s.status IN ('cancelled', 'completed') THEN s.status
            WHEN vr.status = 'completed' THEN 'completed'
            WHEN vr.status = 'processed' THEN 'approved'
            WHEN vr.status IN ('assigned', 'approved', 'pending') THEN 'assigned'
            WHEN vr.status IN ('cancelled', 'rejected', 'lapsed') THEN 'cancelled'
            WHEN s.status = 'pending' THEN 'assigned'
            ELSE 'approved' END,
       s.created_by, s.created_at, IF(vr.status IN ('processed', 'completed'), vr.updated_at, NULL), s.created_by, s.created_at
  FROM schedules s LEFT JOIN vehicle_requests vr ON vr.request_id = s.request_id;

-- 2. internal tasks
INSERT IGNORE INTO tasks (task_id, task_source, task_type, driver_id, vehicle_id, start_dt, end_dt, location, description, status,
                          approved_by, approved_at, reject_reason, cancel_reason, cancelled_at, created_by, created_at)
SELECT @base_int + t.task_id, 'internal', t.task_type, t.driver_id, t.vehicle_id, t.start_dt, t.end_dt, t.location, t.description,
       IF(t.status = 'active', 'approved', t.status), t.approved_by, t.approved_at, t.reject_reason, t.cancel_reason, t.cancelled_at, t.created_by, t.created_at
  FROM internal_tasks t;

-- 3. welfare weeks and days (a day lasts 24 hours from midnight)
INSERT IGNORE INTO welfare_duties (week_start_date, status)
SELECT DATE_SUB(w.duty_date, INTERVAL WEEKDAY(w.duty_date) DAY) AS wk,
       CASE WHEN SUM(w.status = 'waiting') > 0 THEN 'waiting' WHEN SUM(w.status = 'assigned') > 0 THEN 'assigned' ELSE 'approved' END
  FROM welfare_roster w GROUP BY wk;
INSERT IGNORE INTO tasks (task_id, task_source, duty_id, task_type, driver_id, vehicle_id, start_dt, end_dt, description, status,
                          approved_by, approved_at, reject_reason, created_at)
SELECT @base_wel + w.roster_id, 'welfare', d.duty_id, 'welfare', w.driver_id, w.vehicle_id, TIMESTAMP(w.duty_date), DATE_ADD(TIMESTAMP(w.duty_date), INTERVAL 1 DAY),
       'Welfare duty (24 hours)', IF(w.status = 'active', 'approved', w.status), w.approved_by, w.approved_at, w.reject_reason, w.created_at
  FROM welfare_roster w JOIN welfare_duties d ON d.week_start_date = DATE_SUB(w.duty_date, INTERVAL WEEKDAY(w.duty_date) DAY);

-- 4. task reports: one task_id instead of three columns; driver and vehicle come from the task
ALTER TABLE task_reports ADD COLUMN IF NOT EXISTS `task_id` int(11) DEFAULT NULL AFTER `report_id`;
UPDATE task_reports SET task_id = COALESCE(schedule_id, @base_int + internal_task_id, @base_wel + welfare_id) WHERE task_id IS NULL;
ALTER TABLE task_reports DROP FOREIGN KEY IF EXISTS fk_report_schedule, DROP FOREIGN KEY IF EXISTS fk_report_task, DROP FOREIGN KEY IF EXISTS fk_report_welfare,
                         DROP FOREIGN KEY IF EXISTS fk_report_driver, DROP FOREIGN KEY IF EXISTS fk_report_vehicle;
ALTER TABLE task_reports DROP INDEX IF EXISTS uq_report_schedule, DROP INDEX IF EXISTS uq_report_internal, DROP INDEX IF EXISTS uq_report_welfare,
                         DROP INDEX IF EXISTS idx_report_driver, DROP INDEX IF EXISTS idx_report_vehicle;
ALTER TABLE task_reports DROP COLUMN IF EXISTS schedule_id, DROP COLUMN IF EXISTS internal_task_id, DROP COLUMN IF EXISTS welfare_id,
                         DROP COLUMN IF EXISTS driver_id, DROP COLUMN IF EXISTS vehicle_id;
ALTER TABLE task_reports MODIFY COLUMN `task_id` int(11) NOT NULL;
ALTER TABLE task_reports ADD UNIQUE KEY IF NOT EXISTS uq_report_task (task_id);
ALTER TABLE task_reports ADD FOREIGN KEY IF NOT EXISTS fk_report_task (task_id) REFERENCES tasks (task_id) ON DELETE CASCADE;

-- 5. driver replacements: one task_id; names of the class diagram; a status (pending until the Head of Admin approves the new assignment)
ALTER TABLE driver_replacements ADD COLUMN IF NOT EXISTS `task_id` int(11) DEFAULT NULL AFTER `replacement_id`;
UPDATE driver_replacements SET task_id = COALESCE(schedule_id, @base_int + internal_task_id, @base_wel + welfare_id) WHERE task_id IS NULL;
ALTER TABLE driver_replacements DROP FOREIGN KEY IF EXISTS fk_repl_schedule, DROP FOREIGN KEY IF EXISTS fk_repl_internal, DROP FOREIGN KEY IF EXISTS fk_repl_welfare;
ALTER TABLE driver_replacements DROP INDEX IF EXISTS idx_repl_schedule, DROP INDEX IF EXISTS idx_repl_internal, DROP INDEX IF EXISTS idx_repl_welfare;
ALTER TABLE driver_replacements DROP COLUMN IF EXISTS schedule_id, DROP COLUMN IF EXISTS internal_task_id, DROP COLUMN IF EXISTS welfare_id;
ALTER TABLE driver_replacements CHANGE COLUMN IF EXISTS `new_driver_id` `driver_id` int(11) DEFAULT NULL COMMENT 'the new driver';
ALTER TABLE driver_replacements CHANGE COLUMN IF EXISTS `changed_by` `replaced_by` int(11) DEFAULT NULL;
ALTER TABLE driver_replacements CHANGE COLUMN IF EXISTS `changed_at` `replaced_at` datetime NOT NULL DEFAULT current_timestamp();
ALTER TABLE driver_replacements ADD COLUMN IF NOT EXISTS `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved' COMMENT 'pending until the Head of Admin approves the new assignment' AFTER `replaced_at`;
ALTER TABLE driver_replacements MODIFY COLUMN `task_id` int(11) NOT NULL;
ALTER TABLE driver_replacements ADD KEY IF NOT EXISTS idx_repl_task (task_id);
ALTER TABLE driver_replacements ADD FOREIGN KEY IF NOT EXISTS fk_repl_task (task_id) REFERENCES tasks (task_id) ON DELETE CASCADE;

-- 6. vehicle usage log: one task_id
ALTER TABLE vehicle_usage_log ADD COLUMN IF NOT EXISTS `task_id` int(11) DEFAULT NULL AFTER `driver_id`;
UPDATE vehicle_usage_log SET task_id = COALESCE(schedule_id, @base_int + internal_task_id) WHERE task_id IS NULL;
ALTER TABLE vehicle_usage_log DROP FOREIGN KEY IF EXISTS fk_usage_schedule, DROP FOREIGN KEY IF EXISTS fk_usage_task;
ALTER TABLE vehicle_usage_log DROP INDEX IF EXISTS fk_usage_schedule, DROP INDEX IF EXISTS fk_usage_task;
ALTER TABLE vehicle_usage_log DROP COLUMN IF EXISTS schedule_id, DROP COLUMN IF EXISTS internal_task_id;
ALTER TABLE vehicle_usage_log ADD KEY IF NOT EXISTS idx_usage_task (task_id);
ALTER TABLE vehicle_usage_log ADD FOREIGN KEY IF NOT EXISTS fk_usage_task (task_id) REFERENCES tasks (task_id) ON DELETE SET NULL;

-- 7. schedule change requests: the task of a "withdraw" request
ALTER TABLE schedule_change_requests DROP FOREIGN KEY IF EXISTS fk_change_schedule;
ALTER TABLE schedule_change_requests DROP INDEX IF EXISTS fk_change_schedule;
ALTER TABLE schedule_change_requests CHANGE COLUMN IF EXISTS `schedule_id` `task_id` int(11) DEFAULT NULL COMMENT 'the task of a withdraw request';
ALTER TABLE schedule_change_requests ADD KEY IF NOT EXISTS idx_change_task (task_id);
ALTER TABLE schedule_change_requests ADD FOREIGN KEY IF NOT EXISTS fk_change_task (task_id) REFERENCES tasks (task_id) ON DELETE SET NULL;

-- 8. a request has its tasks through tasks.request_id
ALTER TABLE vehicle_requests DROP FOREIGN KEY IF EXISTS fk_vr_schedule;
ALTER TABLE vehicle_requests DROP INDEX IF EXISTS fk_vr_schedule;
ALTER TABLE vehicle_requests DROP COLUMN IF EXISTS schedule_id;

-- 9. the old tables
DROP TABLE IF EXISTS welfare_roster;
DROP TABLE IF EXISTS internal_tasks;
DROP TABLE IF EXISTS schedules;

SET FOREIGN_KEY_CHECKS = 1;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_class_diagram_tasks');
