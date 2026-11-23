-- Fuel level and odometer recorded by the driver after a task; safe to run more than once.
-- vehicles keeps the latest reading (fuel_level 0-4 quarters: 0 almost empty, 4 full; mileage = odometer in km).
ALTER TABLE `vehicles`
  ADD COLUMN IF NOT EXISTS `fuel_level` tinyint(4) DEFAULT NULL COMMENT '0 almost empty .. 4 full (quarters)',
  ADD COLUMN IF NOT EXISTS `fuel_updated_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `fuel_updated_by` int(11) DEFAULT NULL COMMENT 'driver_id';
CREATE TABLE IF NOT EXISTS `vehicle_readings` (
  `reading_id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL,
  `fuel_level` tinyint(4) NOT NULL,
  `odometer` int(11) DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reading_id`),
  UNIQUE KEY `uq_reading_schedule` (`schedule_id`),
  UNIQUE KEY `uq_reading_task` (`task_id`),
  KEY `idx_reading_vehicle` (`vehicle_id`, `recorded_at`),
  CONSTRAINT `fk_reading_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_vehicle_fuel');
