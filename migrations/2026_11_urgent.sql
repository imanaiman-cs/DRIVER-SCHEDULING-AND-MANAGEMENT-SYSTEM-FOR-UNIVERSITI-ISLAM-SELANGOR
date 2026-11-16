-- SRS 10 Nov (UC018 A5): a request less than 2 days before departure is accepted and marked Urgent. There is no paper form any more.
-- Safe to run more than once.
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS is_urgent tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = submitted less than 2 days before departure' AFTER own_vehicle_plate;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_urgent');
