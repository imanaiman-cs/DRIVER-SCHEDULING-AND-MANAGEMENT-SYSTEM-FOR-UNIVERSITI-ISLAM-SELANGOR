-- Coordinates of the destination and of an "Other" waiting location, filled when the staff member picks an
-- address suggestion; used for the Google Maps / Waze buttons. Safe to run more than once.
ALTER TABLE `vehicle_requests`
  ADD COLUMN IF NOT EXISTS `dest_lat` decimal(9,6) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `dest_lng` decimal(9,6) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `wait_lat` decimal(9,6) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `wait_lng` decimal(9,6) DEFAULT NULL;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_request_coords');
