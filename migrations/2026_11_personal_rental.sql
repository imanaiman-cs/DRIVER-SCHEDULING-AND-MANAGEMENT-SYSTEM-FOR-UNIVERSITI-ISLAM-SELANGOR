-- SRS 10 Nov (UC018 A4, UC003 A5): Personal Rental of a lorry: round-trip distance and the charge (RM3 per km).
-- Safe to run more than once.
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS distance_km decimal(8,1) DEFAULT NULL COMMENT 'round-trip distance of a Personal Rental' AFTER purpose_type;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS charge decimal(10,2) DEFAULT NULL COMMENT 'Personal Rental charge = distance x RENTAL_RATE_PER_KM' AFTER distance_km;
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES ('RENTAL_RATE_PER_KM', '3.00', 'Personal Rental charge per kilometre of the round trip (RM)');
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_personal_rental');
