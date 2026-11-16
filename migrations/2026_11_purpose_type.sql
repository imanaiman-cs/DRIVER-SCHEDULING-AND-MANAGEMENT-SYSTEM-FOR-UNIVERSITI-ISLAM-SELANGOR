-- SRS 10 Nov (UC018): purpose type of a request (Official / Unofficial need a supporting letter; Personal Rental comes later), and the vehicle type MPV (UC002).
-- Safe to run more than once.
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS purpose_type enum('official','unofficial','personal_rental') NOT NULL DEFAULT 'official' COMMENT 'Official and Unofficial need a supporting letter' AFTER request_type;
ALTER TABLE vehicles MODIFY COLUMN vehicle_type enum('Bus','Van','Car','Minibus','Lorry','Motorcycle','MPV') NOT NULL;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_purpose_type');
