-- Class diagram (Iman, 16 Nov 2026), class ScheduleChange: changeID, driverID, taskID, startDate, endDate, reason, status,
-- approvedBy, rejectReason, rejectedBy, rejectedDate.
--   schedule_change_requests + schedule_change_dates  ->  schedule_changes (a date range start_date .. end_date)
--   decision_comment / decided_by / decided_at        ->  approved_by, approved_at  or  reject_reason, rejected_by, rejected_at
-- UC027: approving has no comment, rejecting needs the reason. Run it ONCE (recorded in schema_migrations).

RENAME TABLE IF EXISTS schedule_change_requests TO schedule_changes;
ALTER TABLE schedule_changes ADD COLUMN IF NOT EXISTS `start_date` date DEFAULT NULL AFTER `task_id`;
ALTER TABLE schedule_changes ADD COLUMN IF NOT EXISTS `end_date` date DEFAULT NULL AFTER `start_date`;
ALTER TABLE schedule_changes ADD COLUMN IF NOT EXISTS `approved_by` int(11) DEFAULT NULL AFTER `status`;
ALTER TABLE schedule_changes ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approved_by`;
ALTER TABLE schedule_changes ADD COLUMN IF NOT EXISTS `reject_reason` varchar(500) DEFAULT NULL AFTER `approved_at`;
ALTER TABLE schedule_changes ADD COLUMN IF NOT EXISTS `rejected_by` int(11) DEFAULT NULL AFTER `reject_reason`;
ALTER TABLE schedule_changes ADD COLUMN IF NOT EXISTS `rejected_at` datetime DEFAULT NULL AFTER `rejected_by`;

UPDATE schedule_changes c
  JOIN (SELECT change_id, MIN(change_date) AS d1, MAX(change_date) AS d2 FROM schedule_change_dates GROUP BY change_id) d ON d.change_id = c.change_id
   SET c.start_date = d.d1, c.end_date = d.d2
 WHERE c.start_date IS NULL;
UPDATE schedule_changes c JOIN tasks t ON t.task_id = c.task_id
   SET c.start_date = t.task_date, c.end_date = t.task_date
 WHERE c.start_date IS NULL;
UPDATE schedule_changes SET start_date = DATE(created_at), end_date = DATE(created_at) WHERE start_date IS NULL;
UPDATE schedule_changes SET approved_by = decided_by, approved_at = decided_at WHERE status = 'approved' AND approved_at IS NULL;
UPDATE schedule_changes SET reject_reason = decision_comment, rejected_by = decided_by, rejected_at = decided_at WHERE status = 'rejected' AND rejected_at IS NULL;

ALTER TABLE schedule_changes MODIFY COLUMN `start_date` date NOT NULL, MODIFY COLUMN `end_date` date NOT NULL;
ALTER TABLE schedule_changes DROP COLUMN IF EXISTS decision_comment, DROP COLUMN IF EXISTS decided_by, DROP COLUMN IF EXISTS decided_at;
ALTER TABLE schedule_changes ADD KEY IF NOT EXISTS idx_change_dates (driver_id, start_date, end_date);
DROP TABLE IF EXISTS schedule_change_dates;

INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_class_diagram_schedule_change');
