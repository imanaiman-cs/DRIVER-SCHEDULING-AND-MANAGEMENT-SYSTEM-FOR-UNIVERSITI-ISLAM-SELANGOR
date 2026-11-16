-- Pay and Claim request (SRS summary of 10 Nov 2026): the staff member uses their OWN vehicle and claims the cost.
-- No UIS vehicle, no vehicle lock, no driver. Head of Department approves, then the Head of Admin approves (or rejects with a reason).
-- Safe to run more than once.
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS request_type enum('uis','pay_claim') NOT NULL DEFAULT 'uis' COMMENT 'uis = a UIS vehicle (with or without driver), pay_claim = own vehicle, claim the cost' AFTER need_driver;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS own_vehicle_plate varchar(20) DEFAULT NULL COMMENT 'registration number of the own vehicle (Pay and Claim)' AFTER request_type;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_pay_claim');
