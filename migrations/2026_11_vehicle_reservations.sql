-- SRS 10 Nov (UC018, UC003): a request reserves a QUANTITY of a vehicle TYPE from departure until 1 hour after the return;
-- the Administrative Assistant chooses the specific vehicle when assigning (vehicle_reservations.vehicle_id, vehicle_requests.vehicle_id).
-- Safe to run more than once. Existing requests keep their vehicle and get an assigned reservation row.
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS vehicle_type varchar(20) DEFAULT NULL COMMENT 'type requested (Car, MPV, Van, Bus, Lorry); NULL for Pay and Claim' AFTER vehicle_id;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS quantity tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'number of vehicles of that type' AFTER vehicle_type;
CREATE TABLE IF NOT EXISTS vehicle_reservations (
  reservation_id int(11) NOT NULL AUTO_INCREMENT,
  request_id int(11) NOT NULL,
  vehicle_type varchar(20) NOT NULL,
  vehicle_id int(11) DEFAULT NULL COMMENT 'set when the Assistant chooses the specific vehicle',
  start_dt datetime NOT NULL,
  end_dt datetime NOT NULL COMMENT 'return time + 1 hour',
  PRIMARY KEY (reservation_id),
  KEY idx_res_type (vehicle_type, start_dt, end_dt),
  KEY idx_res_request (request_id),
  CONSTRAINT fk_res_request FOREIGN KEY (request_id) REFERENCES vehicle_requests (request_id) ON DELETE CASCADE,
  CONSTRAINT fk_res_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (vehicle_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
UPDATE vehicle_requests vr JOIN vehicles v ON v.vehicle_id = vr.vehicle_id SET vr.vehicle_type = v.vehicle_type WHERE vr.vehicle_type IS NULL;
INSERT INTO vehicle_reservations (request_id, vehicle_type, vehicle_id, start_dt, end_dt)
  SELECT l.request_id, v.vehicle_type, l.vehicle_id, l.start_dt, l.end_dt FROM vehicle_locks l JOIN vehicles v ON v.vehicle_id = l.vehicle_id
   WHERE NOT EXISTS (SELECT 1 FROM vehicle_reservations r WHERE r.request_id = l.request_id AND r.vehicle_id = l.vehicle_id);
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_vehicle_reservations');
