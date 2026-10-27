-- UC010 A3: a request can be "With driver" (1, the default) or "Without driver" (0).
-- Safe to run more than once.
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS need_driver TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = with driver, 0 = without driver' AFTER passenger_count;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_need_driver');
