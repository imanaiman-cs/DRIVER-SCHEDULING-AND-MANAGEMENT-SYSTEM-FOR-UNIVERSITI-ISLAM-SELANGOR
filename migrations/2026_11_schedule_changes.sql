-- UC029 / UC030: a driver asks the Head of Admin for unavailable dates or to be withdrawn from a task.
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS `schedule_change_requests` (
  `change_id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `change_type` enum('unavailable','withdraw') NOT NULL,
  `schedule_id` int(11) DEFAULT NULL COMMENT 'the task of a withdraw request',
  `reason` varchar(500) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `decision_comment` varchar(500) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`change_id`),
  KEY `idx_change_driver` (`driver_id`,`status`),
  KEY `idx_change_status` (`status`,`created_at`),
  CONSTRAINT `fk_change_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`),
  CONSTRAINT `fk_change_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `schedule_change_dates` (
  `change_id` int(11) NOT NULL,
  `change_date` date NOT NULL,
  PRIMARY KEY (`change_id`,`change_date`),
  KEY `idx_change_date` (`change_date`),
  CONSTRAINT `fk_change_dates` FOREIGN KEY (`change_id`) REFERENCES `schedule_change_requests` (`change_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_schedule_changes');
