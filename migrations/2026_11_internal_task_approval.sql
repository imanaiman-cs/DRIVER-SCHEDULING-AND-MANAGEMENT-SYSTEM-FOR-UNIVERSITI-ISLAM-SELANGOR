-- SRS 10 Nov (UC004 Manage Internal Task, UC005 Assign Internal Task, UC026): an internal task is added WITHOUT a driver (Waiting for Driver,
-- the vehicle is reserved), the Assistant assigns a driver (Driver Assigned) and the Head of Admin approves (Approved = status 'active').
-- A rejection returns the task to the Assistant (Waiting for Driver). Safe to run more than once.
ALTER TABLE internal_tasks MODIFY COLUMN driver_id int(11) DEFAULT NULL;
ALTER TABLE internal_tasks MODIFY COLUMN status enum('waiting','assigned','active','cancelled') NOT NULL DEFAULT 'waiting' COMMENT 'waiting = Waiting for Driver, assigned = Driver Assigned, active = Approved, cancelled = Deleted';
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS approved_by int(11) DEFAULT NULL AFTER cancelled_at;
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS approved_at datetime DEFAULT NULL AFTER approved_by;
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS reject_reason varchar(255) DEFAULT NULL AFTER approved_at;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_internal_task_approval');
