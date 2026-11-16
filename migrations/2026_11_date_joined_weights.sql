-- UC001 / UC003 A1 / UC018: the driver's experience is calculated from the date the driver joined the Vehicle Section,
-- and the driver score uses three weights: tasks this month, weekend tasks and experience (default 0.5 / 0.3 / 0.2).
-- Nothing is dropped: drivers.experience_years and the old setting W_AFTER_HOURS stay in the database, unused.
-- Safe to run more than once.
ALTER TABLE drivers ADD COLUMN IF NOT EXISTS date_joined date DEFAULT NULL COMMENT 'date the driver joined the Vehicle Section (experience is calculated from it)' AFTER license_expiry;
UPDATE drivers SET date_joined = DATE_SUB(CURDATE(), INTERVAL ROUND(experience_years * 12) MONTH) WHERE date_joined IS NULL AND experience_years > 0;
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
 ('W_WEEKEND',    '0.3', 'Weight of the number of weekend tasks (Saturday and Sunday) this month in the driver score'),
 ('W_EXPERIENCE', '0.2', 'Weight of the experience (years since the date joined) in the driver score');
UPDATE settings SET setting_value = '0.5', description = 'Weight of the number of tasks this month in the driver score (the highest weight)' WHERE setting_key = 'W_TASKS' AND setting_value = '0.6';
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_date_joined_weights');
