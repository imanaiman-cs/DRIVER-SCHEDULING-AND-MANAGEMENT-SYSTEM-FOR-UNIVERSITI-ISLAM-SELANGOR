-- SRS 10 Nov (UC024 Manage Assigned Task Report): the driver reports a completed task: actual times, end mileage, fuel level, condition
-- (problem type, description, photo, can the vehicle still be used). The report updates the vehicle. A report is never deleted.
-- vehicles.fuel_level: the current fuel level (UC002). Safe to run more than once.
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS fuel_level enum('empty','quarter','half','three_quarters','full') DEFAULT NULL COMMENT 'current fuel level' AFTER mileage;
CREATE TABLE IF NOT EXISTS task_reports (
  report_id int(11) NOT NULL AUTO_INCREMENT,
  schedule_id int(11) DEFAULT NULL COMMENT 'the request task reported',
  internal_task_id int(11) DEFAULT NULL COMMENT 'or the internal task',
  driver_id int(11) NOT NULL,
  vehicle_id int(11) NOT NULL,
  actual_depart datetime NOT NULL,
  actual_return datetime NOT NULL,
  start_mileage int(11) NOT NULL COMMENT 'mileage of the vehicle before the report',
  end_mileage int(11) NOT NULL,
  fuel_level enum('empty','quarter','half','three_quarters','full') NOT NULL,
  vehicle_condition enum('ok','problem') NOT NULL DEFAULT 'ok',
  problem_type enum('Plate Number','Tyre','Lights','Body Damage','Engine','Others') DEFAULT NULL,
  problem_desc varchar(500) DEFAULT NULL,
  photo varchar(255) DEFAULT NULL,
  can_be_used tinyint(1) DEFAULT NULL COMMENT 'with a problem: 1 = the vehicle can still be used, 0 = Under Maintenance',
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY (report_id),
  UNIQUE KEY uq_report_schedule (schedule_id),
  UNIQUE KEY uq_report_internal (internal_task_id),
  KEY idx_report_driver (driver_id),
  KEY idx_report_vehicle (vehicle_id, created_at),
  CONSTRAINT fk_report_schedule FOREIGN KEY (schedule_id) REFERENCES schedules (schedule_id) ON DELETE SET NULL,
  CONSTRAINT fk_report_task FOREIGN KEY (internal_task_id) REFERENCES internal_tasks (task_id) ON DELETE SET NULL,
  CONSTRAINT fk_report_driver FOREIGN KEY (driver_id) REFERENCES drivers (driver_id),
  CONSTRAINT fk_report_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_task_reports');
