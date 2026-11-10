-- SRS 10 Nov UC024: a welfare duty day can be reported by the driver too (task_reports.welfare_id). Safe to run more than once.
ALTER TABLE task_reports ADD COLUMN IF NOT EXISTS welfare_id int(11) DEFAULT NULL COMMENT 'or the welfare duty day' AFTER internal_task_id;
ALTER TABLE task_reports ADD UNIQUE KEY IF NOT EXISTS uq_report_welfare (welfare_id);
ALTER TABLE task_reports ADD FOREIGN KEY IF NOT EXISTS fk_report_welfare (welfare_id) REFERENCES welfare_roster (roster_id) ON DELETE SET NULL;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_welfare_report');
