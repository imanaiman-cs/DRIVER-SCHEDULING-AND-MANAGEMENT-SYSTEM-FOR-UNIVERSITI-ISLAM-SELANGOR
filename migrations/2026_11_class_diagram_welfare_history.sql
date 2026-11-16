-- History is never deleted (CLAUDE.md: approvals, assignments and replacements are kept). A deleted welfare duty day is now a soft delete
-- (tasks.status = 'cancelled'), so its driver replacements and its task report stay. The database refuses to delete a task that has them.
--   tasks.welfare_date: only a day that is not cancelled takes the date, so the day can be scheduled again
--   driver_replacements / task_reports -> tasks: no cascade delete any more; welfare_duties -> tasks: no cascade delete either
-- Runs after 2026_11_class_diagram_tasks (see applyPendingMigrations()). Safe to run more than once.

ALTER TABLE tasks MODIFY COLUMN `welfare_date` date GENERATED ALWAYS AS (if(`task_source` = 'welfare' and `status` <> 'cancelled', cast(`start_dt` as date), NULL)) STORED COMMENT 'one welfare duty per day (cancelled days do not count)';

ALTER TABLE driver_replacements DROP FOREIGN KEY IF EXISTS fk_repl_task;
ALTER TABLE driver_replacements ADD FOREIGN KEY IF NOT EXISTS fk_repl_task (task_id) REFERENCES tasks (task_id);
ALTER TABLE task_reports DROP FOREIGN KEY IF EXISTS fk_report_task;
ALTER TABLE task_reports ADD FOREIGN KEY IF NOT EXISTS fk_report_task (task_id) REFERENCES tasks (task_id);
ALTER TABLE tasks DROP FOREIGN KEY IF EXISTS fk_task_duty;
ALTER TABLE tasks ADD FOREIGN KEY IF NOT EXISTS fk_task_duty (duty_id) REFERENCES welfare_duties (duty_id);

INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_class_diagram_welfare_history');
