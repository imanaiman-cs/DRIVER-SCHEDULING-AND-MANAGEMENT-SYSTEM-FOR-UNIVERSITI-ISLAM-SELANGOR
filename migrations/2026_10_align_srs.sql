-- ============================================================
-- Migration 2026_10_align_srs  -  bring an existing database in line with the final SRS
--
-- Run it by hand (phpMyAdmin > Import, or: mysql -uroot uis_driver_db < migrations/2026_10_align_srs.sql)
-- or do nothing: the system applies every file in migrations/ that has not been applied yet,
-- the first time a page is opened (see applyPendingMigrations() in config/database.php).
--
-- Written for MariaDB 10.4+ (XAMPP) and safe to run more than once.
-- A fresh import of database.sql already contains all of this.
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL,
    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- A. Accounts come from the UIS staff system (no local user management)
-- ------------------------------------------------------------
ALTER TABLE users COMMENT = 'Synced from the UIS staff system (tools/import_staff.php). The Vehicle Section does not create, edit or delete users.';
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL DEFAULT NULL;      -- login blocked until this time (15 min after 5 failed attempts)
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL DEFAULT NULL;
UPDATE users SET locked_until = NULL, failed_attempts = 0;

-- ------------------------------------------------------------
-- Drivers: licence classes are D (cars, vans), GDL (lorries) and PSV (buses)
-- ------------------------------------------------------------
UPDATE drivers SET license_class = NULLIF(CONCAT_WS(',',
        IF(FIND_IN_SET('D', license_class), 'D', NULL),
        IF(FIND_IN_SET('E', license_class) OR FIND_IN_SET('GDL', license_class), 'GDL', NULL),
        IF(FIND_IN_SET('E', license_class) OR FIND_IN_SET('PSV', license_class), 'PSV', NULL)), '')
 WHERE license_class IS NOT NULL AND (FIND_IN_SET('B2', license_class) OR FIND_IN_SET('E', license_class));
UPDATE drivers SET status = 'active' WHERE status = 'on_leave';          -- leave is handled in a separate UIS system
UPDATE drivers SET driver_type = 'regular';                              -- only carpool drivers remain

-- ------------------------------------------------------------
-- Vehicles: car, van, lorry, bus. Status: available, in_use (set by the system), maintenance, inactive
-- ------------------------------------------------------------
ALTER TABLE vehicles MODIFY status ENUM('available','in_use','maintenance','retired','inactive') NOT NULL DEFAULT 'available';
UPDATE vehicles SET status = 'inactive'  WHERE status = 'retired';
UPDATE vehicles SET status = 'available' WHERE status = 'in_use';        -- "In Use" is worked out from Confirmed trips, not stored
UPDATE vehicles SET vehicle_type = 'Bus' WHERE vehicle_type = 'Minibus';
UPDATE vehicles SET status = 'inactive' WHERE vehicle_type = 'Motorcycle';
ALTER TABLE vehicles MODIFY status ENUM('available','in_use','maintenance','inactive') NOT NULL DEFAULT 'available';
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS license_required ENUM('D','GDL','PSV') NOT NULL DEFAULT 'D';
UPDATE vehicles SET license_required = CASE vehicle_type WHEN 'Lorry' THEN 'GDL' WHEN 'Bus' THEN 'PSV' ELSE 'D' END;
CREATE UNIQUE INDEX IF NOT EXISTS uq_vehicles_plate ON vehicles (plate_number);

-- Maintenance: workshop name + expected return date (end_date)
ALTER TABLE vehicle_maintenance MODIFY end_date DATE NOT NULL;
ALTER TABLE vehicle_maintenance ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL DEFAULT NULL;

-- ------------------------------------------------------------
-- B. Vehicle requests: departure + return date/time, fuller details, time-period vehicle lock
-- ------------------------------------------------------------
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS return_date DATE NULL DEFAULT NULL;
UPDATE vehicle_requests SET return_date = trip_date WHERE return_date IS NULL;
ALTER TABLE vehicle_requests MODIFY return_date DATE NOT NULL;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS destination_address VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS passenger_names TEXT NULL DEFAULT NULL;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS waiting_address VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS depart_at DATETIME GENERATED ALWAYS AS (TIMESTAMP(trip_date, start_time)) STORED;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS return_at DATETIME GENERATED ALWAYS AS (TIMESTAMP(return_date, end_time)) STORED;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS lapsed_notified TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE vehicle_requests MODIFY status ENUM('pending','approved','assigned','rejected','processed','cancelled','lapsed','completed') NOT NULL DEFAULT 'pending';
CREATE INDEX IF NOT EXISTS idx_vr_period ON vehicle_requests (depart_at, return_at);
CREATE INDEX IF NOT EXISTS idx_vr_status ON vehicle_requests (status, created_at);

-- One lock per vehicle per requested period (replaces the whole-day hold)
CREATE TABLE IF NOT EXISTS vehicle_locks (
    lock_id    INT      NOT NULL AUTO_INCREMENT,
    request_id INT      NOT NULL,
    vehicle_id INT      NOT NULL,
    start_dt   DATETIME NOT NULL,
    end_dt     DATETIME NOT NULL,
    PRIMARY KEY (lock_id),
    KEY idx_lock_vehicle (vehicle_id, start_dt, end_dt),
    KEY idx_lock_request (request_id),
    CONSTRAINT fk_lock_request FOREIGN KEY (request_id) REFERENCES vehicle_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_lock_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO vehicle_locks (request_id, vehicle_id, start_dt, end_dt)
SELECT vr.request_id, vr.vehicle_id, vr.depart_at, vr.return_at
  FROM vehicle_requests vr
 WHERE vr.vehicle_id IS NOT NULL AND vr.status IN ('pending','approved','assigned','processed')
   AND NOT EXISTS (SELECT 1 FROM vehicle_locks l WHERE l.request_id = vr.request_id AND l.vehicle_id = vr.vehicle_id);

-- ------------------------------------------------------------
-- D. Tasks (schedules): outbound (leg_no 1) and return (leg_no 2); fast overlap checks
-- ------------------------------------------------------------
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS request_id INT NULL DEFAULT NULL;
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS leg_no TINYINT NULL DEFAULT NULL;
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS start_dt DATETIME GENERATED ALWAYS AS (TIMESTAMP(trip_date, start_time)) STORED;
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS end_dt   DATETIME GENERATED ALWAYS AS (TIMESTAMP(trip_date, end_time))   STORED;
CREATE INDEX IF NOT EXISTS idx_sched_driver_time  ON schedules (driver_id, start_dt, end_dt);
CREATE INDEX IF NOT EXISTS idx_sched_vehicle_time ON schedules (vehicle_id, start_dt, end_dt);
CREATE INDEX IF NOT EXISTS idx_sched_request      ON schedules (request_id);

-- ------------------------------------------------------------
-- E. Internal tasks can be cancelled (with a reason) and are never deleted
-- ------------------------------------------------------------
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS status ENUM('active','cancelled') NOT NULL DEFAULT 'active';
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL DEFAULT NULL;
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS start_dt DATETIME GENERATED ALWAYS AS (TIMESTAMP(task_date, start_time)) STORED;
ALTER TABLE internal_tasks ADD COLUMN IF NOT EXISTS end_dt   DATETIME GENERATED ALWAYS AS (TIMESTAMP(task_date, end_time))   STORED;
CREATE INDEX IF NOT EXISTS idx_itask_time ON internal_tasks (driver_id, start_dt, end_dt);
CREATE INDEX IF NOT EXISTS idx_itask_vehicle_time ON internal_tasks (vehicle_id, start_dt, end_dt);

-- ------------------------------------------------------------
-- C. Manual approval reminder: record the e-mail result
-- ------------------------------------------------------------
ALTER TABLE reminders ADD COLUMN IF NOT EXISTS email_error VARCHAR(255) NULL DEFAULT NULL;

-- ------------------------------------------------------------
-- G. E-mail log: any user, not only drivers
-- ------------------------------------------------------------
ALTER TABLE email_log ADD COLUMN IF NOT EXISTS user_id INT NULL DEFAULT NULL;

-- ------------------------------------------------------------
-- Settings: only what the SRS keeps (scoring weights, lapse time, office hours, session and login rules)
-- ------------------------------------------------------------
DELETE FROM settings WHERE setting_key IN ('REMINDER_AFTER_HOURS', 'MIN_DAYS_AHEAD', 'WELFARE_PENALTY');
INSERT INTO settings (setting_key, setting_value, description) VALUES
    ('LOCKOUT_MINUTES',     '15', 'Minutes a login is blocked after too many failed attempts'),
    ('TASK_DURATION_HOURS', '2',  'Length of the outbound and the return task of a trip (hours)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
UPDATE settings SET setting_value = '08:00' WHERE setting_key = 'WORK_START';
UPDATE settings SET setting_value = '17:30' WHERE setting_key = 'WORK_END';
UPDATE settings SET setting_value = '48'    WHERE setting_key = 'LAPSE_HOURS';
UPDATE settings SET setting_value = '15'    WHERE setting_key = 'SESSION_TIMEOUT_MIN';
UPDATE settings SET setting_value = '5'     WHERE setting_key = 'MAX_FAILED_ATTEMPTS';

INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_10_align_srs');
