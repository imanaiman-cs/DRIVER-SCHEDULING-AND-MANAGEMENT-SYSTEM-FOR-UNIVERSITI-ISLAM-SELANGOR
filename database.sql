-- ============================================================
-- UIS Driver Scheduling and Management System
-- Database Schema for Universiti Islam Selangor (UIS)
-- ============================================================

DROP DATABASE IF EXISTS uis_driver_db;
CREATE DATABASE uis_driver_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uis_driver_db;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE users (
    user_id      INT           NOT NULL AUTO_INCREMENT,
    username     VARCHAR(50)   NOT NULL,
    password     VARCHAR(255)  NOT NULL,
    email        VARCHAR(100)  NOT NULL,
    full_name    VARCHAR(100)  NOT NULL,
    role         ENUM('admin','driver','staff','supervisor') NOT NULL DEFAULT 'admin',
    driver_id    INT           NULL DEFAULT NULL,
    department   VARCHAR(100)  NULL DEFAULT NULL,
    supervisor_id INT          NULL DEFAULT NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: drivers
-- ============================================================
CREATE TABLE drivers (
    driver_id           INT             NOT NULL AUTO_INCREMENT,
    employee_id         VARCHAR(20)     NOT NULL,
    name                VARCHAR(100)    NOT NULL,
    phone               VARCHAR(20)     NULL DEFAULT NULL,
    email               VARCHAR(100)    NULL DEFAULT NULL,
    address             TEXT            NULL DEFAULT NULL,
    experience_years    DECIMAL(4,1)    NOT NULL DEFAULT 0,
    license_number      VARCHAR(50)     NULL DEFAULT NULL,
    license_class       VARCHAR(10)     NULL DEFAULT NULL COMMENT 'comma list: B2,D,E',
    license_expiry      DATE            NULL DEFAULT NULL,
    status              ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
    driver_type         ENUM('top_management','regular') NOT NULL DEFAULT 'regular',
    assigned_to         VARCHAR(150)    NULL DEFAULT NULL COMMENT 'Top Management drivers: the one Top Management officer this driver serves',
    photo               VARCHAR(255)    NULL DEFAULT NULL COMMENT 'relative path under uploads/drivers/',
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (driver_id),
    UNIQUE KEY uq_drivers_employee_id (employee_id),
    UNIQUE KEY uq_drivers_assigned_to (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: vehicles
-- ============================================================
CREATE TABLE vehicles (
    vehicle_id       INT          NOT NULL AUTO_INCREMENT,
    plate_number     VARCHAR(20)  NOT NULL,
    vehicle_type     ENUM('Bus','Van','Car','Minibus','Lorry','Motorcycle') NOT NULL,
    brand            VARCHAR(50)  NULL DEFAULT NULL,
    model            VARCHAR(50)  NULL DEFAULT NULL,
    year             INT          NULL DEFAULT NULL,
    capacity         INT          NOT NULL DEFAULT 4,
    fuel_type        ENUM('Petrol','Diesel','Electric','Hybrid') NOT NULL DEFAULT 'Petrol',
    status           ENUM('available','in_use','maintenance','retired') NOT NULL DEFAULT 'available',
    last_maintenance DATE         NULL DEFAULT NULL,
    next_maintenance DATE         NULL DEFAULT NULL,
    mileage          INT          NOT NULL DEFAULT 0,
    notes            TEXT         NULL DEFAULT NULL,
    photo            VARCHAR(255) NULL DEFAULT NULL COMMENT 'relative path under uploads/vehicles/',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (vehicle_id),
    UNIQUE KEY uq_vehicles_plate_number (plate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: schedules
-- A job that needs several drivers (e.g. a seminar with 2+ buses) is
-- stored as one row per driver. All rows share the same job_group.
-- Upgrading an existing database:
--   ALTER TABLE schedules
--     ADD COLUMN job_group INT NULL DEFAULT NULL AFTER trip_type,
--     ADD KEY idx_schedules_job_group (job_group);
-- ============================================================
CREATE TABLE schedules (
    schedule_id     INT           NOT NULL AUTO_INCREMENT,
    driver_id       INT           NULL DEFAULT NULL,
    vehicle_id      INT           NULL DEFAULT NULL,
    trip_date       DATE          NOT NULL,
    start_time      TIME          NOT NULL,
    end_time        TIME          NOT NULL,
    destination     VARCHAR(255)  NOT NULL,
    purpose         VARCHAR(255)  NULL DEFAULT NULL,
    passenger_count INT           NOT NULL DEFAULT 1,
    officer_name    VARCHAR(255)  NULL DEFAULT NULL COMMENT 'Officer(s) the driver serves',
    officer_phone   VARCHAR(50)   NULL DEFAULT NULL COMMENT 'Officer contact number',
    waiting_place   VARCHAR(150)  NULL DEFAULT NULL COMMENT 'Where the driver waits (tempat menunggu)',
    status          ENUM('pending','approved','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    priority_score  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    created_by      INT           NULL DEFAULT NULL,
    notes           TEXT          NULL DEFAULT NULL,
    trip_type       ENUM('regular','top_management') NOT NULL DEFAULT 'regular',
    job_group       INT           NULL DEFAULT NULL COMMENT 'Shared id of a job that needs several drivers (one row per driver); NULL for a normal single-driver job',
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (schedule_id),
    KEY idx_schedules_job_group (job_group),
    CONSTRAINT fk_schedules_driver_id  FOREIGN KEY (driver_id)  REFERENCES drivers(driver_id)  ON DELETE SET NULL,
    CONSTRAINT fk_schedules_vehicle_id FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE SET NULL,
    CONSTRAINT fk_schedules_created_by FOREIGN KEY (created_by) REFERENCES users(user_id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: messages
-- ============================================================
CREATE TABLE messages (
    message_id  INT           NOT NULL AUTO_INCREMENT,
    sender_id   INT           NOT NULL,
    receiver_id INT           NOT NULL,
    body        TEXT          NOT NULL,
    is_read     TINYINT(1)    NOT NULL DEFAULT 0,
    parent_id   INT           NULL DEFAULT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    CONSTRAINT fk_msg_sender   FOREIGN KEY (sender_id)   REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_receiver FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_parent   FOREIGN KEY (parent_id)   REFERENCES messages(message_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: vehicle_requests (e-Kenderaan)
-- Staff submit a vehicle request -> their supervisor (head of
-- section) approves/rejects -> admin processes it into a schedule.
-- Staff may withdraw a request while it is still 'pending'
-- (status 'cancelled', cancelled_at set).
-- Upgrading an existing database:
--   ALTER TABLE vehicle_requests
--     MODIFY status ENUM('pending','approved','rejected','processed','cancelled') NOT NULL DEFAULT 'pending',
--     ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_at;
-- ============================================================
CREATE TABLE vehicle_requests (
    request_id      INT           NOT NULL AUTO_INCREMENT,
    staff_id        INT           NOT NULL,
    vehicle_id      INT           NULL DEFAULT NULL,
    trip_date       DATE          NOT NULL,
    start_time      TIME          NOT NULL,
    end_time        TIME          NOT NULL,
    destination     VARCHAR(255)  NOT NULL,
    purpose         TEXT          NOT NULL,
    passenger_count INT           NOT NULL DEFAULT 1,
    officer_name    VARCHAR(255)  NULL DEFAULT NULL,
    officer_phone   VARCHAR(50)   NULL DEFAULT NULL,
    waiting_place   VARCHAR(150)  NULL DEFAULT NULL,
    vehicles_needed TINYINT       NOT NULL DEFAULT 1 COMMENT 'How many vehicles the trip needs (1-5)',
    status          ENUM('pending','approved','rejected','processed','cancelled') NOT NULL DEFAULT 'pending',
    supervisor_id   INT           NULL DEFAULT NULL,
    supervisor_notes TEXT         NULL DEFAULT NULL,
    reviewed_at     TIMESTAMP     NULL DEFAULT NULL,
    cancelled_at    TIMESTAMP     NULL DEFAULT NULL,
    schedule_id     INT           NULL DEFAULT NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    CONSTRAINT fk_vr_staff      FOREIGN KEY (staff_id)      REFERENCES users(user_id)        ON DELETE CASCADE,
    CONSTRAINT fk_vr_vehicle    FOREIGN KEY (vehicle_id)    REFERENCES vehicles(vehicle_id)  ON DELETE SET NULL,
    CONSTRAINT fk_vr_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(user_id)        ON DELETE SET NULL,
    CONSTRAINT fk_vr_schedule   FOREIGN KEY (schedule_id)   REFERENCES schedules(schedule_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: request_vehicles
-- Every vehicle the staff member asked for on a request (a trip may need
-- more than one). vehicle_requests.vehicle_id keeps the first of them.
-- When a request asks for "any available vehicle", no rows are stored and
-- vehicle_requests.vehicles_needed says how many are wanted.
-- Upgrading an existing database:
--   ALTER TABLE vehicle_requests
--     ADD COLUMN vehicles_needed TINYINT NOT NULL DEFAULT 1 AFTER waiting_place;
--   (then create request_vehicles below and run the INSERT ... SELECT that
--    copies vehicle_requests.vehicle_id into it)
-- ============================================================
CREATE TABLE request_vehicles (
    request_id  INT NOT NULL,
    vehicle_id  INT NOT NULL,
    PRIMARY KEY (request_id, vehicle_id),
    CONSTRAINT fk_rv_request FOREIGN KEY (request_id) REFERENCES vehicle_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_rv_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: request_documents
-- Supporting documents (PDF / image) attached to a vehicle
-- request, e.g. release letter, seminar/invitation letter.
-- Files live in uploads/documents/ and are served only through
-- ajax/get_document.php (access-checked).
-- ============================================================
CREATE TABLE request_documents (
    doc_id        INT           NOT NULL AUTO_INCREMENT,
    request_id    INT           NOT NULL,
    doc_type      ENUM('release_letter','seminar_letter','approval_letter','programme','other') NOT NULL DEFAULT 'other',
    original_name VARCHAR(255)  NOT NULL,
    stored_path   VARCHAR(255)  NOT NULL,
    mime_type     VARCHAR(100)  NOT NULL,
    file_size     INT           NOT NULL,
    uploaded_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (doc_id),
    INDEX idx_rd_request (request_id),
    CONSTRAINT fk_rd_request FOREIGN KEY (request_id) REFERENCES vehicle_requests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: email_log
-- Every e-mail the system tries to send (sent, failed, or skipped
-- when sending is switched off), so admins can see what drivers
-- were told and when.
-- ============================================================
CREATE TABLE email_log (
    log_id        INT           NOT NULL AUTO_INCREMENT,
    driver_id     INT           NULL DEFAULT NULL,
    to_email      VARCHAR(190)  NULL DEFAULT NULL,
    delivered_to  VARCHAR(255)  NULL DEFAULT NULL COMMENT 'set when a test redirect replaced the recipient',
    subject       VARCHAR(255)  NOT NULL,
    kind          VARCHAR(30)   NOT NULL COMMENT 'assigned, updated, cancelled, removed, reminder, test',
    ref_date      DATE          NULL DEFAULT NULL COMMENT 'trip date the e-mail is about (used to avoid duplicate reminders)',
    schedule_ids  VARCHAR(255)  NULL DEFAULT NULL,
    status        ENUM('sent','failed','skipped') NOT NULL,
    error_message VARCHAR(500)  NULL DEFAULT NULL,
    body_html     MEDIUMTEXT    NULL DEFAULT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_email_created (created_at),
    KEY idx_email_driver_kind (driver_id, kind, ref_date),
    CONSTRAINT fk_email_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED: users – admin account
-- Login: username=admin / password=admin123
-- ============================================================
INSERT INTO users (username, password, email, full_name, role, driver_id) VALUES
(
    'admin',
    SHA2('admin123', 256),
    'admin@uis.edu.my',
    'Rozaimi bin Kamaruzzaman',
    'admin',
    NULL
);

-- ============================================================
-- SEED: drivers – 12 drivers
-- Priority score formula (0–10):
--   (LEAST(exp/20,1)*10*0.30) + (att/100*10*0.20) + (perf*0.30) + (cert*0.20)
-- Approximate scores:
--   drv001=8.72  drv002=7.61  drv003=6.72  drv004=8.41
--   drv005=5.53  drv006=7.26  drv007=4.72  drv008=6.56
--   drv009=9.31  drv010=6.16  drv011=4.54  drv012=7.38
-- ============================================================
INSERT INTO drivers
    (employee_id, name, phone, email, address,
     experience_years,
     license_number, license_class, license_expiry, status)
VALUES
(
    'UIS-DRV-001', 'Ahmad Faizal bin Mohd Rashid',
    '012-3456789', 'faizal.rashid@uis.edu.my',
    'No. 12, Jalan Cempaka 3, Taman Cempaka, 40150 Shah Alam, Selangor',
    15.0, 'D1234567', 'D,E', '2027-08-31', 'active'
),
(
    'UIS-DRV-002', 'Mohd Hafizuddin bin Zulkifli',
    '013-2345678', 'hafizuddin.zulkifli@uis.edu.my',
    'No. 5, Jalan Mawar 7, Taman Sri Muda, 40400 Shah Alam, Selangor',
    10.5, 'D2345678', 'D,E', '2027-12-31', 'active'
),
(
    'UIS-DRV-003', 'Norhaslinda binti Abdul Karim',
    '011-34567890', 'haslinda.karim@uis.edu.my',
    'No. 88, Jalan Kenanga 2, Taman Kenanga, 45000 Kuala Selangor, Selangor',
    7.0, 'D3456789', 'B2,D', '2027-03-15', 'active'
),
(
    'UIS-DRV-004', 'Khairul Anuar bin Ismail',
    '019-4567890', 'khairul.ismail@uis.edu.my',
    'No. 33, Jalan Damai 11, Taman Damai Jaya, 41000 Klang, Selangor',
    12.0, 'D4567890', 'D,E', '2028-01-20', 'active'
),
(
    'UIS-DRV-005', 'Siti Norzaharah binti Othman',
    '017-5678901', 'zaharah.othman@uis.edu.my',
    'No. 21, Jalan Melati 4, Taman Melati Indah, 68000 Ampang, Selangor',
    3.5, 'D5678901', 'B2,D', '2027-06-30', 'active'
),
(
    'UIS-DRV-006', 'Zulkarnain bin Hamzah',
    '014-6789012', 'zulkarnain.hamzah@uis.edu.my',
    'No. 7, Jalan Anggerik 9, Taman Anggerik, 41150 Klang, Selangor',
    8.0, 'D6789012', 'D,E', '2027-11-10', 'on_leave'
),
(
    'UIS-DRV-007', 'Roslan bin Abdul Wahab',
    '016-7890123', 'roslan.wahab@uis.edu.my',
    'No. 45, Jalan Delima 6, Taman Delima, 40460 Shah Alam, Selangor',
    2.0, 'D7890123', 'B2,D', '2027-09-14', 'active'
),
(
    'UIS-DRV-008', 'Muhammad Asyraf bin Che Hassan',
    '018-8901234', 'asyraf.hassan@uis.edu.my',
    'No. 60, Jalan Bayu 3, Taman Bayu Perdana, 41200 Klang, Selangor',
    5.0, 'D8901234', 'D', '2027-07-22', 'active'
),
(
    'UIS-DRV-009', 'Fadzillah bin Mohd Noor',
    '012-9012345', 'fadzillah.noor@uis.edu.my',
    'No. 3, Jalan Pelangi 1, Taman Pelangi Maju, 40150 Shah Alam, Selangor',
    18.0, 'D9012345', 'D,E', '2028-05-15', 'active'
),
(
    'UIS-DRV-010', 'Nurul Ain binti Saharuddin',
    '011-0123456', 'nurulain.saharuddin@uis.edu.my',
    'No. 18, Jalan Seroja 5, Taman Seroja, 41000 Klang, Selangor',
    4.0, 'D0123456', 'B2,D', '2027-10-30', 'active'
),
(
    'UIS-DRV-011', 'Hairul Nizam bin Kamaruddin',
    '013-1234567', 'hairul.kamaruddin@uis.edu.my',
    'No. 27, Jalan Teratai 8, Taman Sri Andalas, 41200 Klang, Selangor',
    6.0, 'D1345678', 'D', '2026-12-01', 'inactive'
),
(
    'UIS-DRV-012', 'Azhari bin Mahmud',
    '017-2345670', 'azhari.mahmud@uis.edu.my',
    'No. 9, Jalan Dahlia 3, Taman Meru Jaya, 41050 Klang, Selangor',
    9.0, 'D2456789', 'D,E', '2028-03-20', 'active'
);

-- ============================================================
-- SEED: driver portal user accounts (auto-generated)
-- Passwords: driver001@uis … driver012@uis
-- ============================================================
INSERT INTO users (username, password, email, full_name, role, driver_id)
SELECT
    CONCAT('drv', LPAD(CAST(driver_id AS CHAR), 3, '0')),
    SHA2(CONCAT('driver', LPAD(CAST(driver_id AS CHAR), 3, '0'), '@uis'), 256),
    email,
    name,
    'driver',
    driver_id
FROM drivers;

-- ============================================================
-- SEED: vehicles – 10 fleet vehicles
-- ============================================================
INSERT INTO vehicles
    (plate_number, vehicle_type, brand, model, year, capacity,
     fuel_type, status, last_maintenance, next_maintenance, mileage, notes)
VALUES
(
    'BGN 9595', 'Bus', 'Hino', 'RK8J', 2019, 44, 'Diesel', 'available',
    '2026-04-10', '2026-10-10', 198500,
    'Main campus shuttle bus. Air-conditioned. GPS-tracked. Suitable for large groups.'
),
(
    'BNJ 9276', 'Bus', 'Hino', 'RN8J', 2021, 44, 'Diesel', 'available',
    '2026-05-20', '2026-11-20', 147300,
    'Long-distance university bus. Priority for inter-campus and interstate trips.'
),
(
    'WA 2211 C', 'Van', 'Toyota', 'Hiace', 2020, 14, 'Diesel', 'available',
    '2026-03-15', '2026-09-15', 104700,
    'Staff van. Air-conditioned. Ideal for small group official trips.'
),
(
    'WB 3322 D', 'Van', 'Nissan', 'Urvan', 2018, 12, 'Diesel', 'maintenance',
    '2026-06-01', '2026-12-01', 161200,
    'Under scheduled maintenance. Expected to be available mid-July 2026.'
),
(
    'BDF 4400 E', 'Car', 'Proton', 'X70', 2022, 5, 'Petrol', 'available',
    '2026-05-05', '2026-11-05', 72400,
    'Executive sedan for Top Management use.'
),
(
    'BDG 5500 F', 'Car', 'Perodua', 'Bezza', 2023, 5, 'Petrol', 'available',
    '2026-04-18', '2026-10-18', 48900,
    'General purpose compact car for day-to-day administrative errands.'
),
(
    'SEL 7890 G', 'Minibus', 'Toyota', 'Hiace Commuter', 2021, 16, 'Diesel', 'available',
    '2026-05-12', '2026-11-12', 88600,
    'Minibus for medium-size faculty groups. Air-conditioned.'
),
(
    'WC 4455 H', 'Minibus', 'Mercedes-Benz', 'Sprinter', 2020, 20, 'Diesel', 'in_use',
    '2026-04-28', '2026-10-28', 112300,
    'Premium minibus. Currently assigned for an ongoing trip.'
),
(
    'BKH 9555', 'Bus', 'Nissan', 'Civilian', 2020, 40, 'Diesel', 'available',
    '2026-06-10', '2026-12-10', 134800,
    'Third bus for high-demand periods and large group trips.'
),
(
    'BDF 7700 J', 'Car', 'Honda', 'Civic', 2019, 5, 'Petrol', 'retired',
    '2025-12-01', NULL, 241000,
    'Retired from service. High mileage. Pending disposal process.'
),

-- ── Real UIS fleet: buses, lorries, motorcycles ──────────────
(
    'BNE 3611', 'Bus', 'Nissan', 'Civilian', 2018, 40, 'Diesel', 'available',
    '2026-05-15', '2026-11-15', 176400,
    'University bus for campus shuttle and medium-distance group trips.'
),
(
    'BHW 8595', 'Lorry', 'Nissan', 'Daihatsu', 2017, 3, 'Diesel', 'available',
    '2026-04-22', '2026-10-22', 156900,
    'Cargo lorry for logistics, equipment transport and event setup.'
),
(
    'BLJ 9955', 'Lorry', 'Daihatsu', 'Gran Max', 2020, 3, 'Diesel', 'available',
    '2026-05-30', '2026-11-30', 98200,
    'Light cargo lorry for small deliveries and inter-department logistics.'
),
(
    'BQC 9552', 'Lorry', 'Hino', '300 Series', 2021, 3, 'Diesel', 'available',
    '2026-06-05', '2026-12-05', 87500,
    'Heavy-duty lorry for large equipment and furniture transport.'
),
(
    'BMX 1252', 'Motorcycle', 'Yamaha', 'LC135', 2019, 2, 'Petrol', 'available',
    '2026-05-08', '2026-11-08', 45300,
    'Dispatch motorcycle for document delivery and quick campus errands.'
),
(
    'BMX 1253', 'Motorcycle', 'Yamaha', 'LC135', 2019, 2, 'Petrol', 'available',
    '2026-05-08', '2026-11-08', 43100,
    'Dispatch motorcycle for document delivery and quick campus errands.'
),
(
    'BNE 543', 'Motorcycle', 'Honda', 'Wave', 2020, 2, 'Petrol', 'available',
    '2026-04-25', '2026-10-25', 38700,
    'Dispatch motorcycle for mail runs and nearby official errands.'
),
(
    'BNE 544', 'Motorcycle', 'Honda', 'Wave', 2020, 2, 'Petrol', 'available',
    '2026-04-25', '2026-10-25', 36200,
    'Dispatch motorcycle for mail runs and nearby official errands.'
),
(
    'BNE 546', 'Motorcycle', 'Honda', 'Wave', 2021, 2, 'Petrol', 'available',
    '2026-05-18', '2026-11-18', 29800,
    'Dispatch motorcycle for mail runs and nearby official errands.'
);

-- ============================================================
-- SEED: schedules – 52 trips (Jan 2025 – Dec 2026)
-- Driver priority scores:
--   1=8.72  2=7.61  3=6.72  4=8.41  5=5.53
--   7=4.72  8=6.56  9=9.31 10=6.16 12=7.38
-- Vehicles: 1=Bus Hino BGN9595  2=Bus Hino BNJ9276  3=Van Hiace  5=Car X70
--           6=Car Bezza  7=Minibus Hiace  8=Minibus Sprinter  9=Bus Nissan BKH9555
-- ============================================================
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count,
     status, priority_score, created_by, notes)
VALUES

-- ── 2025 Q1 ────────────────────────────────────────────────
(4,  1, '2025-01-08', '07:00:00', '17:00:00',
 'Universiti Malaya, Kuala Lumpur',
 'Inter-university academic conference',
 38, 'completed', 8.41, 1,
 'Annual academic conference. Return trip same day at 17:00.'),

(1,  2, '2025-01-15', '08:00:00', '11:00:00',
 'Jabatan Pengajian Tinggi, Putrajaya',
 'Ministry briefing for university administrators',
 40, 'completed', 8.72, 1,
 'Punctuality critical. Driver briefed on parking zones.'),

(9,  1, '2025-02-05', '07:30:00', '17:30:00',
 'Universiti Teknologi MARA, Seremban, Negeri Sembilan',
 'Faculty of Education collaborative workshop',
 42, 'completed', 9.31, 1,
 'Full-day programme. Lunch provided by host university.'),

(2,  3, '2025-02-18', '09:00:00', '13:00:00',
 'Majlis Bandaraya Shah Alam (MBSA)',
 'Permit renewal documentation submission',
 6,  'completed', 7.61, 1,
 'Admin staff trip. Driver to wait and return with staff.'),

(12, 5, '2025-03-04', '10:00:00', '12:30:00',
 'Bank Muamalat Malaysia, Jalan Raja Laut, Kuala Lumpur',
 'Corporate banking appointment – Finance Department',
 3,  'completed', 7.38, 1,
 'Driver to accompany Bursar and two finance officers.'),

(4,  2, '2025-03-20', '06:00:00', '20:00:00',
 'Universiti Sains Malaysia, Pulau Pinang',
 'Research collaboration visit',
 42, 'completed', 8.41, 1,
 'Overnight trip. Hotel bookings confirmed. Return on 21 March.'),

-- ── 2025 Q2 ────────────────────────────────────────────────
(3,  3, '2025-04-01', '08:30:00', '13:30:00',
 'Lembaga Hasil Dalam Negeri (LHDN), Petaling Jaya',
 'Finance department tax submission',
 5,  'completed', 6.72, 1,
 'Sensitive documents on board. Driver advised to park securely.'),

(8,  6, '2025-04-10', '10:00:00', '12:00:00',
 'Pejabat Pos Utama, Shah Alam',
 'Courier pickup for faculty documents',
 2,  'completed', 6.56, 1,
 'Driver to collect registered mail and return immediately.'),

(9,  7, '2025-04-24', '08:00:00', '16:00:00',
 'Kolej Universiti Islam Selangor, Bestari Jaya',
 'Academic programme coordination meeting',
 14, 'completed', 9.31, 1,
 'UIS satellite campus visit.'),

(1,  1, '2025-05-12', '07:30:00', '18:30:00',
 'Putrajaya Botanical Garden & IOI City Mall, Putrajaya',
 'Student welfare day trip',
 44, 'completed', 8.72, 1,
 'Student activity committee trip. Parent consent forms collected.'),

(2,  7, '2025-05-28', '13:00:00', '17:00:00',
 'Cyberjaya University College of Medical Sciences, Cyberjaya',
 'MOA signing ceremony with partner institution',
 8,  'completed', 7.61, 1,
 'Top Management trip. Vice Chancellor on board. Formal dress code for driver.'),

(12, 3, '2025-06-05', '09:00:00', '13:00:00',
 'Hospital Sungai Buloh, Selangor',
 'Staff occupational health screening',
 10, 'completed', 7.38, 1,
 'Annual health check-up for administrative staff.'),

-- ── 2025 Q3 ────────────────────────────────────────────────
(4,  9, '2025-06-18', '08:00:00', '18:00:00',
 'Universiti Kebangsaan Malaysia, Bangi',
 'Inter-university sports day',
 38, 'completed', 8.41, 1,
 'Students and staff. Departure at 07:45 AM sharp.'),

(10, 6, '2025-07-03', '10:30:00', '12:30:00',
 'Jabatan Imigresen Malaysia, Putrajaya',
 'International student EMGS documentation',
 4,  'completed', 6.16, 1,
 'International Office accompanying students.'),

(9,  2, '2025-07-22', '06:30:00', '21:00:00',
 'Universiti Teknologi Malaysia, Johor Bahru',
 'Cross-state research presentation',
 10, 'completed', 9.31, 1,
 'Long-distance trip. Rest stop at R&R Pagoh.'),

(1,  7, '2025-08-06', '08:00:00', '13:00:00',
 'Kompleks PKNS Shah Alam',
 'UIS Open Day promotional event',
 14, 'completed', 8.72, 1,
 'Bring promotional banners and brochures.'),

(3,  6, '2025-08-20', '09:30:00', '11:30:00',
 'KWSP Cawangan Shah Alam',
 'EPF matter – HR Department',
 3,  'completed', 6.72, 1,
 'Routine HR errand.'),

(12, 1, '2025-09-08', '07:00:00', '19:00:00',
 'Universiti Islam Antarabangsa Malaysia, Gombak',
 'International Islamic Studies Conference',
 40, 'completed', 7.38, 1,
 'Conference registration required before 08:30.'),

(8,  5, '2025-09-25', '14:00:00', '16:30:00',
 'Hospital Tengku Ampuan Rahimah, Klang',
 'Staff emergency medical escort',
 2,  'completed', 6.56, 1,
 'Urgent trip. Admin approval obtained verbally.'),

-- ── 2025 Q4 ────────────────────────────────────────────────
(2,  9, '2025-10-14', '07:30:00', '18:00:00',
 'Langkawi International Airport, Kedah',
 'Top Management delegation pickup – international guests',
 8,  'completed', 7.61, 1,
 'Guests from Al-Azhar University, Egypt.'),

(5,  6, '2025-11-04', '10:00:00', '12:00:00',
 'Pejabat Daerah dan Tanah Klang',
 'Land matters documentation',
 3,  'cancelled', 5.53, 1,
 'Cancelled: relevant officer was on leave. Rescheduled to November 19.'),

(7,  6, '2025-11-19', '09:00:00', '11:00:00',
 'Jabatan Imigresen Malaysia, Shah Alam',
 'Foreign student document processing',
 4,  'completed', 4.72, 1,
 'Rescheduled from Nov 4. All documents verified beforehand.'),

(4,  2, '2025-12-02', '06:30:00', '21:00:00',
 'Universiti Putra Malaysia, Serdang',
 'Annual Convocation ceremony transport',
 48, 'completed', 8.41, 1,
 'Multiple trips. Coordinate with UPM events committee.'),

(9,  1, '2025-12-15', '08:00:00', '22:00:00',
 'Johor Premium Outlets, Johor Bahru',
 'Year-end staff appreciation trip',
 43, 'completed', 9.31, 1,
 'Leisure trip for support staff. Depart 07:30 from main gate.'),

-- ── 2026 Q1 ────────────────────────────────────────────────
(1,  7, '2026-01-10', '09:00:00', '13:00:00',
 'Kementerian Pendidikan Malaysia, Putrajaya',
 'Annual university performance audit',
 6,  'completed', 8.72, 1,
 'Senior management team trip. Formal attire required.'),

(12, 3, '2026-02-03', '08:30:00', '12:30:00',
 'MARA Headquarters, Jalan Raja Laut, Kuala Lumpur',
 'Bumiputera education grant application',
 5,  'completed', 7.38, 1,
 'Finance officers accompanying.'),

(3,  6, '2026-02-18', '10:00:00', '12:00:00',
 'Suruhanjaya Komunikasi dan Multimedia (MCMC), Cyberjaya',
 'ICT compliance visit',
 4,  'completed', 6.72, 1,
 'IT Department officers.'),

(8,  5, '2026-03-05', '08:00:00', '11:00:00',
 'Pejabat Pos Utama, Petaling Jaya',
 'Faculty mail pickup and delivery',
 2,  'completed', 6.56, 1,
 ''),

(2,  9, '2026-03-20', '07:00:00', '19:00:00',
 'Universiti Malaysia Kelantan, Kota Bharu',
 'Cross-university research collaboration',
 10, 'completed', 7.61, 1,
 'Long-distance trip. Rest stop at R&R Gua Musang.'),

-- ── 2026 Q2 ────────────────────────────────────────────────
(9,  2, '2026-04-08', '06:30:00', '21:00:00',
 'Universiti Teknologi MARA, Arau, Perlis',
 'National Education Summit',
 48, 'completed', 9.31, 1,
 'Overnight stay. Two-day event (8–9 April 2026).'),

(10, 7, '2026-04-22', '09:00:00', '14:00:00',
 'Malaysia Convention & Exhibition Bureau, Kuala Lumpur',
 'Education fair participation – UIS booth',
 14, 'completed', 6.16, 1,
 'Bring 3 roll-up banners and booth materials.'),

(4,  1, '2026-05-06', '07:30:00', '18:30:00',
 'Universiti Pendidikan Sultan Idris, Tanjong Malim',
 'Collaborative curriculum review workshop',
 40, 'completed', 8.41, 1,
 'Faculty of Islamic Education delegates.'),

(12, 5, '2026-05-19', '10:00:00', '13:00:00',
 'Jabatan Perkhidmatan Awam (JPA), Putrajaya',
 'Civil service coordination meeting',
 4,  'completed', 7.38, 1,
 ''),

(1,  7, '2026-06-03', '08:00:00', '12:00:00',
 'Dewan Bahasa dan Pustaka, Jalan Duta, Kuala Lumpur',
 'Malay language academic seminar',
 12, 'completed', 8.72, 1,
 'Faculty of Languages delegation.'),

(3,  3, '2026-06-17', '09:30:00', '13:30:00',
 'Hospital Sultanah Bahiyah, Alor Setar, Kedah',
 'Medical staff welfare programme',
 8,  'cancelled', 6.72, 1,
 'Cancelled due to flash flood warning in Kedah. Will reschedule in August 2026.'),

-- ── July 2026 – In Progress (current) ─────────────────────
(9,  8, '2026-07-01', '08:00:00', '16:00:00',
 'Pusat Islam Malaysia, Putrajaya',
 'Islamic Studies faculty delegation visit',
 18, 'in_progress', 9.31, 1,
 'Top Management trip. Accompanied by Dean of Islamic Studies.'),

(4,  1, '2026-07-02', '07:00:00', '12:00:00',
 'Universiti Islam Antarabangsa Malaysia, Gombak',
 'Joint convocation preparation committee',
 35, 'in_progress', 8.41, 1,
 'Urgent scheduling. Driver informed the day before.'),

-- ── July – September 2026 – Approved ─────────────────────
(2,  9, '2026-07-10', '08:00:00', '17:00:00',
 'Jabatan Agama Islam Selangor (JAIS), Shah Alam',
 'Religious affairs committee meeting',
 10, 'approved', 7.61, 1,
 'Halal certification discussion.'),

(12, 7, '2026-07-15', '09:00:00', '13:00:00',
 'Malaysia Productivity Corporation (MPC), Petaling Jaya',
 'Quality management training',
 14, 'approved', 7.38, 1,
 ''),

(1,  2, '2026-07-22', '07:00:00', '19:00:00',
 'Universiti Malaysia Sabah, Kota Kinabalu',
 'East Malaysia academic exchange programme',
 8,  'approved', 8.72, 1,
 'Flight-connect trip. Drop at KLIA then collect on return.'),

(10, 6, '2026-08-05', '10:00:00', '12:30:00',
 'Suruhanjaya Syarikat Malaysia (SSM), Shah Alam',
 'Company registration renewal – Finance Department',
 3,  'approved', 6.16, 1,
 ''),

(8,  5, '2026-08-12', '09:00:00', '11:30:00',
 'Agensi Kelayakan Malaysia (MQA), Putrajaya',
 'Academic programme accreditation audit',
 4,  'approved', 6.56, 1,
 'Bring all accreditation documents.'),

-- ── August – December 2026 – Pending ─────────────────────
(7,  6, '2026-08-20', '10:00:00', '12:00:00',
 'Jabatan Pendaftaran Negara, Shah Alam',
 'Student MyKad processing assistance',
 4,  'pending',  4.72, 1,
 'Awaiting final passenger list from Student Affairs.'),

(5,  3, '2026-09-02', '08:30:00', '14:30:00',
 'Perpustakaan Negara Malaysia, Kuala Lumpur',
 'Library resources acquisition visit',
 6,  'pending',  5.53, 1,
 'Librarian team trip.'),

(3,  7, '2026-09-15', '08:00:00', '17:00:00',
 'Universiti Sultan Zainal Abidin (UniSZA), Kuala Terengganu',
 'Cross-state Islamic arts exhibition',
 12, 'pending',  6.72, 1,
 'Long-distance trip. Rest stop at R&R Temerloh.'),

(9,  2, '2026-09-28', '06:30:00', '22:00:00',
 'Universiti Malaysia Perlis, Arau',
 'National Islamic Education Conference',
 48, 'pending',  9.31, 1,
 'Overnight trip. Two-day event (28–29 September 2026).'),

(12, 5, '2026-10-07', '09:00:00', '12:00:00',
 'Majlis Peperiksaan Malaysia (MPM), Petaling Jaya',
 'Examination board coordination',
 5,  'pending',  7.38, 1,
 ''),

(4,  1, '2026-10-22', '07:30:00', '18:30:00',
 'Universiti Sains Islam Malaysia, Nilai, Negeri Sembilan',
 'Intercampus convocation ceremony support',
 44, 'pending',  8.41, 1,
 'Coordinate with USIM events committee.'),

(2,  9, '2026-11-05', '07:00:00', '19:00:00',
 'Universiti Teknologi MARA, Shah Alam',
 'Faculty attachment programme',
 10, 'pending',  7.61, 1,
 'Academic exchange. Bring MOU documents.'),

(10, 7, '2026-11-18', '09:00:00', '15:00:00',
 'Pusat Konvensyen Antarabangsa Putrajaya (PICC)',
 'International higher education summit',
 14, 'pending',  6.16, 1,
 ''),

(8,  6, '2026-12-03', '10:00:00', '12:30:00',
 'Sekolah Menengah Kebangsaan Alam Megah, Shah Alam',
 'UIS school outreach programme',
 3,  'pending',  6.56, 1,
 'Bring UIS promotional materials and brochures.'),

(1,  2, '2026-12-17', '06:30:00', '23:30:00',
 'Langkawi International Airport, Kedah',
 'End-of-year staff retreat – Langkawi',
 20, 'pending',  8.72, 1,
 'Annual retreat. Hotel bookings pending confirmation.');

-- ============================================================
-- SEED: messages – 26 messages (admin ↔ various drivers)
-- User IDs:
--   1  = admin (Encik Rozaimi)
--   2  = drv001 Ahmad Faizal
--   3  = drv002 Mohd Hafizuddin
--   4  = drv003 Norhaslinda
--   5  = drv004 Khairul Anuar
--   6  = drv005 Siti Norzaharah
--   7  = drv006 Zulkarnain (on leave)
--   8  = drv007 Roslan
--   9  = drv008 Muhammad Asyraf
--   10 = drv009 Fadzillah
--   11 = drv010 Nurul Ain
--   12 = drv011 Hairul Nizam (inactive)
--   13 = drv012 Azhari
-- ============================================================
INSERT INTO messages (sender_id, receiver_id, body, is_read, created_at) VALUES

-- Thread 1: Admin ↔ Khairul (drv004) – March 2025 Penang trip
(1, 5,
 'Hi Khairul, just a reminder that your trip to USM Penang on 20 March 2025 has been approved. Please ensure bus SEL 5678 B is in good condition before departure.',
 1, '2025-03-18 09:15:00'),
(5, 1,
 'Thank you, Mr. Rozaimi. I have checked the vehicle and everything is in order. Estimated departure at 6:00 AM – will be on time.',
 1, '2025-03-18 10:02:00'),
(1, 5,
 'Good. Please contact the office if any issues arise during the journey. Safe travels!',
 1, '2025-03-18 10:30:00'),

-- Thread 2: Admin ↔ Ahmad Faizal (drv001) – May 2025 student trip
(1, 2,
 'Dear Faizal, please be informed that the student welfare trip to Putrajaya on 12 May 2025 has been confirmed. Bus SEL 1234 A is allocated for 44 passengers.',
 1, '2025-05-09 14:00:00'),
(2, 1,
 'Understood, thank you. I will make sure the bus is clean and the air-conditioning is fully functional. Will I receive the passenger name list in advance?',
 1, '2025-05-09 15:22:00'),
(1, 2,
 'The student committee will hand over the list on the day of departure. Please ensure the passenger count does not exceed the vehicle capacity at any point.',
 1, '2025-05-10 08:05:00'),

-- Thread 3: Admin ↔ Fadzillah (drv009) – December 2025 Langkawi retreat
(1, 10,
 'Congratulations, Fadzillah! You have been selected as the lead driver for the annual staff retreat to Langkawi on 15 December 2025, in recognition of your outstanding performance this year.',
 1, '2025-12-10 10:00:00'),
(10, 1,
 'Thank you so much for the trust, Mr. Rozaimi. I am ready for this assignment. Could you let me know the exact departure time from UIS?',
 1, '2025-12-10 11:45:00'),
(1, 10,
 'Departure is at 8:00 AM from the main gate. Please arrive by 7:30 AM for the vehicle pre-departure inspection. Have a safe journey!',
 1, '2025-12-10 14:20:00'),

-- Thread 4: Driver Roslan (drv007) asking about November 2025 schedule
(8, 1,
 'Good morning, Mr. Rozaimi. I would like to check on my schedule for November 2025. The system shows an assignment on 19 November, but I have not received any official notification yet.',
 1, '2025-11-16 09:00:00'),
(1, 8,
 'Good morning, Roslan. Yes, that assignment is confirmed. Trip to the Immigration Department, Shah Alam on 19 November at 9:00 AM. Please use car BDG 5500 F. Thank you.',
 1, '2025-11-16 10:30:00'),

-- Thread 5: Admin ↔ Norhaslinda (drv003) – June 2026 cancelled trip
(4, 1,
 'Mr. Rozaimi, I received the cancellation notice for the trip to Hospital Sultanah Bahiyah, Alor Setar on 17 June 2026. Will this trip be rescheduled?',
 1, '2026-06-18 08:30:00'),
(1, 4,
 'Yes, Norhaslinda. The trip will be rescheduled in August 2026. We will notify you of the new date as soon as it is confirmed. Thank you for your understanding.',
 1, '2026-06-18 09:15:00'),

-- Thread 6: Admin ↔ Muhammad Asyraf (drv008) – performance recognition
(1, 9,
 'Dear Asyraf, management is very pleased with your work performance this quarter. Your punctuality and passenger feedback scores are both excellent. Keep up the great work!',
 1, '2025-10-05 11:00:00'),
(9, 1,
 'Thank you, Mr. Rozaimi. I will continue to provide the best service to all passengers and staff. Please do not hesitate to share any feedback or areas where I can improve.',
 1, '2025-10-05 12:30:00'),

-- Thread 7: Admin ↔ Azhari (drv012) – welcome message
(1, 13,
 'Welcome to the UIS driver team, Azhari! Your account has been activated. Username: drv012, temporary password: driver012@uis. Please change your password after your first login.',
 1, '2025-01-05 08:00:00'),
(13, 1,
 'Thank you, Mr. Rozaimi. I have successfully logged in. Could you guide me on how to update my personal details in the system?',
 1, '2025-01-05 09:45:00'),
(1, 13,
 'You can update your profile under "My Profile" in the left sidebar menu. If you encounter any technical issues, please contact our office directly. Welcome aboard!',
 1, '2025-01-05 10:20:00'),

-- Thread 8: Admin ↔ Khairul (drv004) – July 2026 current trip briefing
(1, 5,
 'Good morning, Khairul. Today\'s trip to UIAM Gombak (2 July 2026) is on schedule. Please depart at exactly 7:00 AM. 35 passengers have been confirmed.',
 1, '2026-07-02 06:00:00'),
(5, 1,
 'Good morning, Mr. Rozaimi. I am already at UIS and the bus is ready. Will depart on time as instructed.',
 1, '2026-07-02 06:35:00'),

-- Thread 9: Driver Nurul Ain (drv010) asking about upcoming trips (unread)
(11, 1,
 'Mr. Rozaimi, I would like to inquire about my upcoming assignments for August and November 2026. There are two trips listed in the system – could I get more details on each one?',
 0, '2026-07-01 14:00:00'),
(1, 11,
 'Hi Nurul Ain. First assignment: 5 August 2026 to SSM Shah Alam (3 passengers, car BDG 5500 F). Second: 18 November 2026 to PICC Putrajaya (14 passengers, minibus SEL 7890 G). Full details are available in the system.',
 0, '2026-07-01 15:30:00'),

-- Thread 10: Admin ↔ Mohd Hafizuddin (drv002) – July 2026 approved trip (unread)
(1, 3,
 'Dear Hafizuddin, your trip to JAIS Shah Alam on 10 July 2026 has been approved. Bus BJK 6600 I is allocated for 10 passengers. Please check the system for the full trip details.',
 0, '2026-07-02 08:00:00'),
(3, 1,
 'Thank you, Mr. Rozaimi. I have reviewed the details in the system. Is there adequate parking available at the JAIS Shah Alam building?',
 0, '2026-07-02 09:15:00'),
(1, 3,
 'Yes, there is free parking in front of the JAIS building. Please present your official UIS duty card to the security guard upon arrival. Safe trip!',
 0, '2026-07-02 10:00:00');

-- ============================================================
-- UPDATE: driver_type assignments
-- Top Management drivers: Ahmad Faizal(1), Hafizuddin(2), Khairul(4),
--                         Zulkarnain(6), Fadzillah(9), Azhari(12)
-- ============================================================
UPDATE drivers SET driver_type = 'top_management'
WHERE driver_id IN (1, 2, 4, 6, 9, 12);

-- Each Top Management driver is dedicated to one Top Management officer
UPDATE drivers SET assigned_to = ELT(FIELD(driver_id, 1, 2, 4, 6, 9, 12),
    'Vice-Chancellor',
    'Deputy Vice-Chancellor (Academic)',
    'Deputy Vice-Chancellor (Student Affairs)',
    'Registrar',
    'Bursar',
    'Chief Librarian')
WHERE driver_id IN (1, 2, 4, 6, 9, 12);

-- ============================================================
-- UPDATE: trip_type assignments
-- Top Management trips: Ministry, senior management, accreditation, national conferences,
--                       convocation, senior management affairs
-- ============================================================
UPDATE schedules SET trip_type = 'top_management'
WHERE schedule_id IN (2, 5, 11, 18, 20, 23, 25, 26, 30, 33, 36, 37, 38, 40, 42, 46, 47, 48, 49, 50);

-- ============================================================
-- UPDATE: officer contact + waiting place for seeded schedules
-- ============================================================
UPDATE schedules SET
    officer_name  = ELT(MOD(schedule_id, 6) + 1,
        'En. Faruq, En. Amir',
        'Pn. Norliza binti Abdul Rahman',
        'Dr. Syafiq bin Zainal',
        'Pn. Siti Aishah binti Mohd Yusof',
        'En. Hafiz bin Ramli',
        'Prof. Madya Dr. Rohaizad bin Ismail'),
    officer_phone = ELT(MOD(schedule_id, 6) + 1,
        '011-2835 4792',
        '012-3344 5566',
        '013-4455 6677',
        '014-5566 7788',
        '016-6677 8899',
        '019-7788 9900'),
    waiting_place = ELT(MOD(schedule_id, 5) + 1,
        'Stor UIS',
        'Lobi Bangunan Pentadbiran',
        'Pondok Pengawal Utama',
        'Lobi Fakulti',
        'Perpustakaan UIS');

-- ============================================================
-- SEED: supervisor + staff accounts (e-Kenderaan module)
-- Supervisor login: hos001 / hos001@uis
-- Staff logins:     stf001 / staff001@uis ... stf003 / staff003@uis
-- ============================================================
INSERT INTO users (username, password, email, full_name, role, driver_id, department, supervisor_id) VALUES
(
    'hos001',
    SHA2('hos001@uis', 256),
    'hafizi@uis.edu.my',
    'Mohd Hafizi bin Harun',
    'supervisor', NULL,
    'Transport Unit', NULL
);

SET @sup_id := LAST_INSERT_ID();

INSERT INTO users (username, password, email, full_name, role, driver_id, department, supervisor_id) VALUES
(
    'stf001',
    SHA2('staff001@uis', 256),
    'norliza@uis.edu.my',
    'Norliza binti Abdul Rahman',
    'staff', NULL,
    'Faculty of Education', @sup_id
),
(
    'stf002',
    SHA2('staff002@uis', 256),
    'syafiq@uis.edu.my',
    'Muhammad Syafiq bin Zainal',
    'staff', NULL,
    'Registrar Office', @sup_id
),
(
    'stf003',
    SHA2('staff003@uis', 256),
    'aishah@uis.edu.my',
    'Siti Aishah binti Mohd Yusof',
    'staff', NULL,
    'Student Affairs Division', @sup_id
);

SET @stf1 := @sup_id + 1;
SET @stf2 := @sup_id + 2;
SET @stf3 := @sup_id + 3;

-- ============================================================
-- SEED: vehicle_requests – sample e-Kenderaan requests
-- ============================================================
INSERT INTO vehicle_requests
    (staff_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count,
     officer_name, officer_phone, waiting_place,
     status, supervisor_id, supervisor_notes, reviewed_at, created_at)
VALUES
(
    @stf1, 3, '2026-10-20', '08:30:00', '13:00:00',
    'Kementerian Pendidikan Malaysia, Putrajaya',
    'Submission of faculty accreditation documents and meeting with ministry officers.',
    4, 'Pn. Norliza binti Abdul Rahman', '012-3344 5566', 'Lobi Fakulti Pendidikan',
    'pending', @sup_id, NULL, NULL,
    '2026-10-05 09:15:00'
),
(
    @stf2, 7, '2026-10-22', '07:30:00', '17:30:00',
    'Universiti Kebangsaan Malaysia, Bangi',
    'Registrar office benchmarking visit for student records management system.',
    10, 'En. Muhammad Syafiq bin Zainal', '013-4455 6677', 'Lobi Pejabat Pendaftar',
    'approved', @sup_id,
    'Approved. Please ensure the group departs on time.',
    '2026-10-04 14:20:00',
    '2026-10-03 11:00:00'
),
(
    @stf3, 5, '2026-10-15', '09:00:00', '12:00:00',
    'Majlis Perbandaran Kajang',
    'Collection of student activity permit documents for convocation festival.',
    2, 'Pn. Siti Aishah binti Mohd Yusof', '014-5566 7788', 'Pondok Pengawal Utama',
    'rejected', @sup_id,
    'Rejected: trip not justified for a vehicle booking. Please use the document courier service.',
    '2026-10-02 16:45:00',
    '2026-10-01 10:30:00'
),
(
    @stf1, 1, '2026-10-28', '07:00:00', '18:00:00',
    'Universiti Malaya, Kuala Lumpur',
    'Faculty of Education staff attending the national TVET curriculum seminar.',
    30, 'Pn. Norliza binti Abdul Rahman', '012-3344 5566', 'Lobi Fakulti Pendidikan',
    'approved', @sup_id,
    'Approved. Large group — please coordinate with the transport unit on pickup point.',
    '2026-10-05 10:05:00',
    '2026-10-04 08:50:00'
);

-- The vehicles each seeded request asked for
INSERT INTO request_vehicles (request_id, vehicle_id)
SELECT request_id, vehicle_id FROM vehicle_requests WHERE vehicle_id IS NOT NULL;

-- One request needs two buses (70 passengers): add the second bus
UPDATE vehicle_requests SET passenger_count = 70, vehicles_needed = 2
WHERE destination = 'Universiti Malaya, Kuala Lumpur' AND status = 'approved';
INSERT INTO request_vehicles (request_id, vehicle_id)
SELECT request_id, 2 FROM vehicle_requests
WHERE destination = 'Universiti Malaya, Kuala Lumpur' AND status = 'approved';


-- ============================================================
-- SEED: demo trips around "today" (relative to the import date)
-- Gives every driver data for TODAY, THIS WEEK and THIS MONTH so the
-- dashboards, calendars, reports and allocation scores look alive no
-- matter when the database is imported.
--   * Past dates      -> completed (a few cancelled)
--   * Today           -> hand-written trips below (mix of statuses)
--   * Future dates    -> approved / pending
--   * Weekends        -> some Saturday/Sunday trips (weekend factor)
-- Each statement skips a driver or vehicle that is already booked on
-- that day, so the demo data never double-books anything.
-- ============================================================

-- Today's trips (hand-written so each driver sees something different)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type, notes)
VALUES
(1,  5,  CURDATE(), '08:00:00', '11:30:00', 'Kementerian Pendidikan Malaysia, Putrajaya',
 'Meeting with ministry officers on programme accreditation', 3,
 'Prof. Madya Dr. Rohaizad bin Ismail', '019-7788 9900', 'Lobi Bangunan Pentadbiran',
 'in_progress', 8.72, 1, 'top_management', 'Departed on time.'),
(1,  7,  CURDATE(), '14:00:00', '17:00:00', 'Bank Muamalat Malaysia, Kuala Lumpur',
 'Corporate banking appointment - Finance Department', 6,
 'Pn. Norliza binti Abdul Rahman', '012-3344 5566', 'Lobi Fakulti',
 'approved', 8.72, 1, 'top_management', NULL),
(2,  1,  CURDATE(), '08:30:00', '12:30:00', 'Universiti Kebangsaan Malaysia, Bangi',
 'Inter-university collaboration meeting', 12,
 'Dr. Syafiq bin Zainal', '013-4455 6677', 'Pondok Pengawal Utama',
 'in_progress', 7.61, 1, 'top_management', NULL),
(3,  6,  CURDATE(), '10:00:00', '12:00:00', 'Pejabat Tanah dan Galian Selangor, Shah Alam',
 'Document submission - Registrar Office', 3,
 'Pn. Siti Aishah binti Mohd Yusof', '014-5566 7788', 'Stor UIS',
 'approved', 6.72, 1, 'regular', NULL),
(4,  9,  CURDATE(), '08:00:00', '17:00:00', 'Universiti Putra Malaysia, Serdang',
 'Faculty collaboration workshop', 35,
 'En. Faruq, En. Amir', '011-2835 4792', 'Lobi Bangunan Pentadbiran',
 'approved', 8.41, 1, 'top_management', 'Full-day programme.'),
(5,  15, CURDATE(), '09:00:00', '11:00:00', 'Pejabat Pos Besar, Shah Alam',
 'Official mail and parcel delivery', 1,
 'En. Hafiz bin Ramli', '016-6677 8899', 'Stor UIS',
 'approved', 5.53, 1, 'regular', NULL),
(7,  3,  CURDATE(), '13:00:00', '16:00:00', 'Hospital Shah Alam',
 'Staff medical appointment transport', 8,
 'En. Hafiz bin Ramli', '016-6677 8899', 'Perpustakaan UIS',
 'pending', 4.72, 1, 'regular', NULL),
(9,  11, CURDATE(), '14:00:00', '18:00:00', 'Putrajaya International Convention Centre (PICC)',
 'Attending national higher-education forum', 30,
 'Prof. Madya Dr. Rohaizad bin Ismail', '019-7788 9900', 'Lobi Bangunan Pentadbiran',
 'approved', 9.31, 1, 'top_management', NULL),
(12, 2,  CURDATE(), '07:30:00', '11:00:00', 'Jabatan Kemajuan Islam Malaysia (JAKIM), Putrajaya',
 'Programme accreditation briefing', 20,
 'Dr. Syafiq bin Zainal', '013-4455 6677', 'Pondok Pengawal Utama',
 'completed', 7.38, 1, 'top_management', 'Returned early.');

-- Team jobs: one job that needs several drivers (one row per driver, sharing job_group)
-- Seminar needing 2 vehicles (regular drivers, tomorrow)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type, notes)
VALUES
(3,  7, CURDATE() + INTERVAL 1 DAY, '07:30:00', '17:30:00', 'Pusat Konvensyen Shah Alam',
 'Seminar Kebangsaan Pendidikan Islam - staff and student delegates', 28,
 'Pn. Norliza binti Abdul Rahman', '012-3344 5566', 'Lobi Fakulti',
 'approved', 6.72, 1, 'regular', 'Seminar needs 2 vehicles / 2 drivers.'),
(7,  3, CURDATE() + INTERVAL 1 DAY, '07:30:00', '17:30:00', 'Pusat Konvensyen Shah Alam',
 'Seminar Kebangsaan Pendidikan Islam - staff and student delegates', 28,
 'Pn. Norliza binti Abdul Rahman', '012-3344 5566', 'Lobi Fakulti',
 'approved', 4.72, 1, 'regular', 'Seminar needs 2 vehicles / 2 drivers.');
SET @team1 := LAST_INSERT_ID();
UPDATE schedules SET job_group = @team1 WHERE schedule_id IN (@team1, @team1 + 1);

-- Convocation transport needing 2 buses (top-management drivers, in 3 days)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type, notes)
VALUES
(1,  1, CURDATE() + INTERVAL 3 DAY, '06:30:00', '16:00:00', 'Universiti Putra Malaysia, Serdang',
 'Convocation ceremony - graduates and guests', 70,
 'Prof. Madya Dr. Rohaizad bin Ismail', '019-7788 9900', 'Lobi Bangunan Pentadbiran',
 'approved', 8.72, 1, 'top_management', 'Two buses. Depart together from the main gate.'),
(4,  2, CURDATE() + INTERVAL 3 DAY, '06:30:00', '16:00:00', 'Universiti Putra Malaysia, Serdang',
 'Convocation ceremony - graduates and guests', 70,
 'Prof. Madya Dr. Rohaizad bin Ismail', '019-7788 9900', 'Lobi Bangunan Pentadbiran',
 'approved', 8.41, 1, 'top_management', 'Two buses. Depart together from the main gate.');
SET @team2 := LAST_INSERT_ID();
UPDATE schedules SET job_group = @team2 WHERE schedule_id IN (@team2, @team2 + 1);

-- A finished team job two days ago (so history also shows one)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type, notes)
VALUES
(5,  15, CURDATE() - INTERVAL 2 DAY, '08:00:00', '12:00:00', 'Kolej Komuniti Klang',
 'Student programme set-up and equipment delivery', 2,
 'En. Hafiz bin Ramli', '016-6677 8899', 'Stor UIS',
 'completed', 5.53, 1, 'regular', 'Two riders for the equipment run.'),
(10, 16, CURDATE() - INTERVAL 2 DAY, '08:00:00', '12:00:00', 'Kolej Komuniti Klang',
 'Student programme set-up and equipment delivery', 2,
 'En. Hafiz bin Ramli', '016-6677 8899', 'Stor UIS',
 'completed', 6.16, 1, 'regular', 'Two riders for the equipment run.');
SET @team3 := LAST_INSERT_ID();
UPDATE schedules SET job_group = @team3 WHERE schedule_id IN (@team3, @team3 + 1);

-- Long trip with a relief driver: two drivers share one bus (in 5 days)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type, notes)
VALUES
(9,  11, CURDATE() + INTERVAL 5 DAY, '06:00:00', '20:00:00', 'Universiti Malaysia Terengganu, Kuala Nerus',
 'Inter-university benchmarking visit (long distance)', 35,
 'Dr. Syafiq bin Zainal', '013-4455 6677', 'Lobi Bangunan Pentadbiran',
 'approved', 9.31, 1, 'top_management', 'Long trip: the two drivers take turns driving the same bus.'),
(12, 11, CURDATE() + INTERVAL 5 DAY, '06:00:00', '20:00:00', 'Universiti Malaysia Terengganu, Kuala Nerus',
 'Inter-university benchmarking visit (long distance)', 35,
 'Dr. Syafiq bin Zainal', '013-4455 6677', 'Lobi Bangunan Pentadbiran',
 'approved', 7.38, 1, 'top_management', 'Long trip: the two drivers take turns driving the same bus.');
SET @team4 := LAST_INSERT_ID();
UPDATE schedules SET job_group = @team4 WHERE schedule_id IN (@team4, @team4 + 1);

-- A processed e-Kenderaan request: the staff member can see the driver and phone number
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type, notes)
VALUES
(3,  7, '2026-10-22', '07:30:00', '17:30:00', 'Universiti Kebangsaan Malaysia, Bangi',
 'Registrar office benchmarking visit for student records management system.', 10,
 'En. Muhammad Syafiq bin Zainal', '013-4455 6677', 'Lobi Pejabat Pendaftar',
 'approved', 6.72, 1, 'regular', 'e-Kenderaan request. Supervisor-approved.');
SET @proc_sched := LAST_INSERT_ID();
UPDATE vehicle_requests SET status = 'processed', schedule_id = @proc_sched
WHERE destination = 'Universiti Kebangsaan Malaysia, Bangi' AND status = 'approved';

-- Dates to fill: every day of the current month + 7 days either side of today
DROP TEMPORARY TABLE IF EXISTS demo_dates;
CREATE TEMPORARY TABLE demo_dates (
    d   DATE NOT NULL PRIMARY KEY,
    td  INT  NOT NULL,
    dow TINYINT NOT NULL
);
INSERT IGNORE INTO demo_dates (d, td, dow)
SELECT x.d, TO_DAYS(x.d), DAYOFWEEK(x.d)
FROM (
    SELECT DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL (t.n * 10 + u.n) DAY AS d
    FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) t
    CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
    UNION
    SELECT CURDATE() + INTERVAL (t.n * 10 + u.n - 7) DAY
    FROM (SELECT 0 AS n UNION ALL SELECT 1) t
    CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
    WHERE t.n * 10 + u.n <= 14
) x
WHERE x.d <= LAST_DAY(CURDATE()) OR x.d <= CURDATE() + INTERVAL 7 DAY;

-- Slot A: management trip, morning (car)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type)
SELECT ELT(MOD(dd.td + 0, 5) + 1, 1, 2, 4, 9, 12), 5, dd.d, '08:00:00', '12:00:00',
       ELT(MOD(dd.td + 0, 8) + 1, 'Kementerian Pendidikan Malaysia, Putrajaya', 'Jabatan Agama Islam Selangor (JAIS), Shah Alam', 'Universiti Kebangsaan Malaysia, Bangi', 'Bank Muamalat Malaysia, Kuala Lumpur', 'Majlis Agama Islam Selangor (MAIS), Shah Alam', 'Putrajaya International Convention Centre (PICC)', 'Jabatan Kemajuan Islam Malaysia (JAKIM), Putrajaya', 'Universiti Malaya, Kuala Lumpur'),
       ELT(MOD(dd.td + 0, 8) + 1, 'Meeting with ministry officers on programme accreditation', 'Official courtesy visit and coordination meeting', 'Inter-university collaboration meeting', 'Corporate banking appointment - Finance Department', 'Management meeting and document submission', 'Attending national higher-education forum', 'Programme accreditation briefing', 'Academic benchmarking visit'),
       2 + MOD(dd.td, 3),
       ELT(MOD(dd.td, 6) + 1, 'En. Faruq, En. Amir', 'Pn. Norliza binti Abdul Rahman', 'Dr. Syafiq bin Zainal',
                              'Pn. Siti Aishah binti Mohd Yusof', 'En. Hafiz bin Ramli', 'Prof. Madya Dr. Rohaizad bin Ismail'),
       ELT(MOD(dd.td, 6) + 1, '011-2835 4792', '012-3344 5566', '013-4455 6677', '014-5566 7788', '016-6677 8899', '019-7788 9900'),
       ELT(MOD(dd.td, 5) + 1, 'Stor UIS', 'Lobi Bangunan Pentadbiran', 'Pondok Pengawal Utama', 'Lobi Fakulti', 'Perpustakaan UIS'),
       CASE WHEN dd.d < CURDATE() THEN IF(MOD(dd.td, 11) = 0, 'cancelled', 'completed') WHEN dd.d = CURDATE() THEN 'approved' ELSE IF(MOD(dd.td, 4) = 0, 'pending', 'approved') END,
       ROUND(5 + MOD(dd.td * 7, 45) / 10, 2),
       1, 'top_management'
FROM demo_dates dd
WHERE (dd.dow BETWEEN 2 AND 6 OR dd.dow = 7)
  AND NOT EXISTS (
        SELECT 1 FROM schedules x
        WHERE x.trip_date = dd.d AND x.status <> 'cancelled'
          AND (x.driver_id = ELT(MOD(dd.td + 0, 5) + 1, 1, 2, 4, 9, 12) OR x.vehicle_id = 5));

-- Slot B: regular trip, morning (car)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type)
SELECT ELT(MOD(dd.td + 0, 5) + 1, 3, 5, 7, 8, 10), 6, dd.d, '09:00:00', '13:00:00',
       ELT(MOD(dd.td + 0, 8) + 1, 'Pejabat Tanah dan Galian Selangor, Shah Alam', 'Hospital Shah Alam', 'Majlis Bandaraya Shah Alam (MBSA)', 'Pejabat Pos Besar, Shah Alam', 'Politeknik Sultan Salahuddin Abdul Aziz Shah, Shah Alam', 'Kolej Komuniti Klang', 'Sekolah Menengah Agama Shah Alam', 'Pusat Zakat Selangor, Shah Alam'),
       ELT(MOD(dd.td + 0, 8) + 1, 'Document submission - Registrar Office', 'Staff medical appointment transport', 'Permit renewal documentation submission', 'Official mail and parcel delivery', 'Student programme coordination', 'Student practical placement visit', 'Outreach programme logistics', 'Student welfare programme coordination'),
       2 + MOD(dd.td, 3),
       ELT(MOD(dd.td, 6) + 1, 'En. Faruq, En. Amir', 'Pn. Norliza binti Abdul Rahman', 'Dr. Syafiq bin Zainal',
                              'Pn. Siti Aishah binti Mohd Yusof', 'En. Hafiz bin Ramli', 'Prof. Madya Dr. Rohaizad bin Ismail'),
       ELT(MOD(dd.td, 6) + 1, '011-2835 4792', '012-3344 5566', '013-4455 6677', '014-5566 7788', '016-6677 8899', '019-7788 9900'),
       ELT(MOD(dd.td, 5) + 1, 'Stor UIS', 'Lobi Bangunan Pentadbiran', 'Pondok Pengawal Utama', 'Lobi Fakulti', 'Perpustakaan UIS'),
       CASE WHEN dd.d < CURDATE() THEN IF(MOD(dd.td, 11) = 0, 'cancelled', 'completed') WHEN dd.d = CURDATE() THEN 'approved' ELSE IF(MOD(dd.td, 4) = 0, 'pending', 'approved') END,
       ROUND(5 + MOD(dd.td * 7, 45) / 10, 2),
       1, 'regular'
FROM demo_dates dd
WHERE (dd.dow BETWEEN 2 AND 7 OR MOD(dd.td, 2) = 0)
  AND NOT EXISTS (
        SELECT 1 FROM schedules x
        WHERE x.trip_date = dd.d AND x.status <> 'cancelled'
          AND (x.driver_id = ELT(MOD(dd.td + 0, 5) + 1, 3, 5, 7, 8, 10) OR x.vehicle_id = 6));

-- Slot C: management trip, afternoon (minibus)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type)
SELECT ELT(MOD(dd.td + 2, 5) + 1, 1, 2, 4, 9, 12), 7, dd.d, '14:00:00', '17:30:00',
       ELT(MOD(dd.td + 3, 8) + 1, 'Kementerian Pendidikan Malaysia, Putrajaya', 'Jabatan Agama Islam Selangor (JAIS), Shah Alam', 'Universiti Kebangsaan Malaysia, Bangi', 'Bank Muamalat Malaysia, Kuala Lumpur', 'Majlis Agama Islam Selangor (MAIS), Shah Alam', 'Putrajaya International Convention Centre (PICC)', 'Jabatan Kemajuan Islam Malaysia (JAKIM), Putrajaya', 'Universiti Malaya, Kuala Lumpur'),
       ELT(MOD(dd.td + 3, 8) + 1, 'Meeting with ministry officers on programme accreditation', 'Official courtesy visit and coordination meeting', 'Inter-university collaboration meeting', 'Corporate banking appointment - Finance Department', 'Management meeting and document submission', 'Attending national higher-education forum', 'Programme accreditation briefing', 'Academic benchmarking visit'),
       8 + MOD(dd.td, 7),
       ELT(MOD(dd.td, 6) + 1, 'En. Faruq, En. Amir', 'Pn. Norliza binti Abdul Rahman', 'Dr. Syafiq bin Zainal',
                              'Pn. Siti Aishah binti Mohd Yusof', 'En. Hafiz bin Ramli', 'Prof. Madya Dr. Rohaizad bin Ismail'),
       ELT(MOD(dd.td, 6) + 1, '011-2835 4792', '012-3344 5566', '013-4455 6677', '014-5566 7788', '016-6677 8899', '019-7788 9900'),
       ELT(MOD(dd.td, 5) + 1, 'Stor UIS', 'Lobi Bangunan Pentadbiran', 'Pondok Pengawal Utama', 'Lobi Fakulti', 'Perpustakaan UIS'),
       CASE WHEN dd.d < CURDATE() THEN IF(MOD(dd.td, 11) = 0, 'cancelled', 'completed') WHEN dd.d = CURDATE() THEN 'approved' ELSE IF(MOD(dd.td, 4) = 0, 'pending', 'approved') END,
       ROUND(5 + MOD(dd.td * 7, 45) / 10, 2),
       1, 'top_management'
FROM demo_dates dd
WHERE dd.dow BETWEEN 2 AND 6 AND MOD(dd.td, 3) = 0
  AND NOT EXISTS (
        SELECT 1 FROM schedules x
        WHERE x.trip_date = dd.d AND x.status <> 'cancelled'
          AND (x.driver_id = ELT(MOD(dd.td + 2, 5) + 1, 1, 2, 4, 9, 12) OR x.vehicle_id = 7));

-- Slot D: regular trip, afternoon (van)
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, officer_name, officer_phone, waiting_place,
     status, priority_score, created_by, trip_type)
SELECT ELT(MOD(dd.td + 2, 5) + 1, 3, 5, 7, 8, 10), 3, dd.d, '14:30:00', '17:00:00',
       ELT(MOD(dd.td + 3, 8) + 1, 'Pejabat Tanah dan Galian Selangor, Shah Alam', 'Hospital Shah Alam', 'Majlis Bandaraya Shah Alam (MBSA)', 'Pejabat Pos Besar, Shah Alam', 'Politeknik Sultan Salahuddin Abdul Aziz Shah, Shah Alam', 'Kolej Komuniti Klang', 'Sekolah Menengah Agama Shah Alam', 'Pusat Zakat Selangor, Shah Alam'),
       ELT(MOD(dd.td + 3, 8) + 1, 'Document submission - Registrar Office', 'Staff medical appointment transport', 'Permit renewal documentation submission', 'Official mail and parcel delivery', 'Student programme coordination', 'Student practical placement visit', 'Outreach programme logistics', 'Student welfare programme coordination'),
       6 + MOD(dd.td, 6),
       ELT(MOD(dd.td, 6) + 1, 'En. Faruq, En. Amir', 'Pn. Norliza binti Abdul Rahman', 'Dr. Syafiq bin Zainal',
                              'Pn. Siti Aishah binti Mohd Yusof', 'En. Hafiz bin Ramli', 'Prof. Madya Dr. Rohaizad bin Ismail'),
       ELT(MOD(dd.td, 6) + 1, '011-2835 4792', '012-3344 5566', '013-4455 6677', '014-5566 7788', '016-6677 8899', '019-7788 9900'),
       ELT(MOD(dd.td, 5) + 1, 'Stor UIS', 'Lobi Bangunan Pentadbiran', 'Pondok Pengawal Utama', 'Lobi Fakulti', 'Perpustakaan UIS'),
       CASE WHEN dd.d < CURDATE() THEN IF(MOD(dd.td, 11) = 0, 'cancelled', 'completed') WHEN dd.d = CURDATE() THEN 'approved' ELSE IF(MOD(dd.td, 4) = 0, 'pending', 'approved') END,
       ROUND(5 + MOD(dd.td * 7, 45) / 10, 2),
       1, 'regular'
FROM demo_dates dd
WHERE dd.dow BETWEEN 2 AND 6 AND MOD(dd.td, 3) = 1
  AND NOT EXISTS (
        SELECT 1 FROM schedules x
        WHERE x.trip_date = dd.d AND x.status <> 'cancelled'
          AND (x.driver_id = ELT(MOD(dd.td + 2, 5) + 1, 3, 5, 7, 8, 10) OR x.vehicle_id = 3));

DROP TEMPORARY TABLE IF EXISTS demo_dates;

-- ============================================================
-- End of schema – 12 drivers · 19 vehicles · 26 messages · 4 vehicle requests
--   · 52 fixed schedules (2025–2026) + demo trips around the import date
-- ============================================================
