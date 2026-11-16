-- UC007 A1: a driver records the mileage, fuel level and condition of a vehicle used for their task.
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS `vehicle_usage_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `schedule_id` int(11) DEFAULT NULL COMMENT 'the trip task the update was made from',
  `internal_task_id` int(11) DEFAULT NULL COMMENT 'or the internal task',
  `mileage` int(11) NOT NULL,
  `fuel_level` enum('empty','quarter','half','three_quarters','full') NOT NULL,
  `condition_issue` varchar(500) DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_usage_vehicle` (`vehicle_id`,`recorded_at`),
  KEY `idx_usage_driver` (`driver_id`),
  CONSTRAINT `fk_usage_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`),
  CONSTRAINT `fk_usage_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`),
  CONSTRAINT `fk_usage_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usage_task` FOREIGN KEY (`internal_task_id`) REFERENCES `internal_tasks` (`task_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_vehicle_usage_log');
