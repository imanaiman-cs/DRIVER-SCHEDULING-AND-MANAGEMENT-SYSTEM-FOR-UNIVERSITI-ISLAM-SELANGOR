-- SRS 10 Nov (UC006 Manage Welfare Duty, UC007 Assign Welfare Duty Task, UC026): welfare duty is a WEEKLY schedule. Each day has a vehicle
-- (car or van, reserved for the 24 hours) and a driver chosen afterwards. Status per day: waiting = Waiting for Driver, assigned = Driver Assigned,
-- active = Approved by the Head of Admin. Days that existed before keep working as Approved (default 'active'). Safe to run more than once.
ALTER TABLE welfare_roster MODIFY COLUMN driver_id int(11) DEFAULT NULL;
ALTER TABLE welfare_roster ADD COLUMN IF NOT EXISTS vehicle_id int(11) DEFAULT NULL AFTER driver_id;
ALTER TABLE welfare_roster ADD COLUMN IF NOT EXISTS status enum('waiting','assigned','active') NOT NULL DEFAULT 'active' COMMENT 'waiting = Waiting for Driver, assigned = Driver Assigned, active = Approved' AFTER vehicle_id;
ALTER TABLE welfare_roster ADD COLUMN IF NOT EXISTS approved_by int(11) DEFAULT NULL AFTER replacement_reason;
ALTER TABLE welfare_roster ADD COLUMN IF NOT EXISTS approved_at datetime DEFAULT NULL AFTER approved_by;
ALTER TABLE welfare_roster ADD COLUMN IF NOT EXISTS reject_reason varchar(255) DEFAULT NULL AFTER approved_at;
ALTER TABLE welfare_roster ADD KEY IF NOT EXISTS idx_welfare_vehicle (vehicle_id, duty_date);
ALTER TABLE welfare_roster ADD FOREIGN KEY IF NOT EXISTS fk_welfare_vehicle (vehicle_id) REFERENCES vehicles (vehicle_id);
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_welfare_weekly');
