
-- ------------------------------------------------------------
-- I. Out of scope: self-drive, driver leave, top-management drivers, messages, multi-leg trips, per-day holds
-- ------------------------------------------------------------
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS driver_leaves;
DROP TABLE IF EXISTS request_legs;
DROP TABLE IF EXISTS request_vehicles;
DROP TABLE IF EXISTS vehicle_day_holds;

ALTER TABLE users DROP COLUMN IF EXISTS is_locked;
ALTER TABLE users DROP COLUMN IF EXISTS must_change_password;

ALTER TABLE schedules DROP COLUMN IF EXISTS job_group;
ALTER TABLE schedules DROP COLUMN IF EXISTS self_drive;
ALTER TABLE schedules DROP COLUMN IF EXISTS override_reason;
ALTER TABLE schedules DROP COLUMN IF EXISTS trip_type;
ALTER TABLE schedules DROP COLUMN IF EXISTS priority_score;

ALTER TABLE vehicle_requests DROP COLUMN IF EXISTS vehicles_needed;
ALTER TABLE vehicle_requests DROP COLUMN IF EXISTS self_drive;

ALTER TABLE drivers DROP INDEX IF EXISTS uq_drivers_assigned_to;
ALTER TABLE drivers DROP COLUMN IF EXISTS assigned_to;
ALTER TABLE drivers DROP COLUMN IF EXISTS driver_type;
ALTER TABLE drivers MODIFY status ENUM('active','inactive') NOT NULL DEFAULT 'active';
