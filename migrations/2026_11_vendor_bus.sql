-- UC017 A2: a task can be given to a vendor bus (vendor name, bus registration number, vendor driver) instead of a UIS driver.
-- Safe to run more than once.
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS vendor_name varchar(150) DEFAULT NULL COMMENT 'vendor bus: company' AFTER leg_no;
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS vendor_bus_plate varchar(20) DEFAULT NULL COMMENT 'vendor bus: registration number' AFTER vendor_name;
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS vendor_driver_name varchar(100) DEFAULT NULL COMMENT 'vendor bus: driver' AFTER vendor_bus_plate;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_vendor_bus');
