-- Up to three contact officers per request (one per line in officer_name / officer_phone, same order).
-- The task copies them. Safe to run more than once.
ALTER TABLE `vehicle_requests`
  MODIFY `officer_name` varchar(400) DEFAULT NULL,
  MODIFY `officer_phone` varchar(120) DEFAULT NULL;
ALTER TABLE `schedules`
  MODIFY `officer_name` varchar(400) DEFAULT NULL COMMENT 'Officer(s) the driver serves, one per line',
  MODIFY `officer_phone` varchar(120) DEFAULT NULL COMMENT 'Officer phone(s), one per line';
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_contact_officers');
