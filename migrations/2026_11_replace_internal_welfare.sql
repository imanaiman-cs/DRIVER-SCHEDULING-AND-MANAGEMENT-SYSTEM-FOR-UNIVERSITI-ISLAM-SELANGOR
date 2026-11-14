-- SRS 14 Nov UC008: the driver of an approved internal task or welfare duty day can be replaced too. The replacement is recorded in
-- driver_replacements (schedule_id is then empty and internal_task_id or welfare_id says which task). Safe to run more than once.
ALTER TABLE driver_replacements MODIFY COLUMN schedule_id int(11) DEFAULT NULL;
ALTER TABLE driver_replacements ADD COLUMN IF NOT EXISTS internal_task_id int(11) DEFAULT NULL AFTER schedule_id;
ALTER TABLE driver_replacements ADD COLUMN IF NOT EXISTS welfare_id int(11) DEFAULT NULL AFTER internal_task_id;
ALTER TABLE driver_replacements ADD KEY IF NOT EXISTS idx_repl_internal (internal_task_id);
ALTER TABLE driver_replacements ADD KEY IF NOT EXISTS idx_repl_welfare (welfare_id);
ALTER TABLE driver_replacements ADD FOREIGN KEY IF NOT EXISTS fk_repl_internal (internal_task_id) REFERENCES internal_tasks (task_id) ON DELETE CASCADE;
ALTER TABLE driver_replacements ADD FOREIGN KEY IF NOT EXISTS fk_repl_welfare (welfare_id) REFERENCES welfare_roster (roster_id) ON DELETE CASCADE;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_replace_internal_welfare');
