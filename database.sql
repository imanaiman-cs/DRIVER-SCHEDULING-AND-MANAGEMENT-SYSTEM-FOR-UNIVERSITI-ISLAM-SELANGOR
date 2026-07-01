-- ============================================================
-- UIS Driver Scheduling and Management System
-- Database Schema for Universiti Islam Selangor (UIS)
-- ============================================================

DROP DATABASE IF EXISTS uis_driver_db;
CREATE DATABASE uis_driver_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uis_driver_db;

-- ============================================================
-- TABLE: users
-- Stores system login credentials for admins and drivers
-- ============================================================
CREATE TABLE users (
    user_id      INT           NOT NULL AUTO_INCREMENT,
    username     VARCHAR(50)   NOT NULL,
    password     VARCHAR(255)  NOT NULL,
    email        VARCHAR(100)  NOT NULL,
    full_name    VARCHAR(100)  NOT NULL,
    role         ENUM('admin','driver') NOT NULL DEFAULT 'admin',
    driver_id    INT           NULL DEFAULT NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: drivers
-- Stores driver profile and scoring information
-- ============================================================
CREATE TABLE drivers (
    driver_id           INT             NOT NULL AUTO_INCREMENT,
    employee_id         VARCHAR(20)     NOT NULL,
    name                VARCHAR(100)    NOT NULL,
    phone               VARCHAR(20)     NULL DEFAULT NULL,
    email               VARCHAR(100)    NULL DEFAULT NULL,
    address             TEXT            NULL DEFAULT NULL,
    experience_years    DECIMAL(4,1)    NOT NULL DEFAULT 0,
    attendance_rate     DECIMAL(5,2)    NOT NULL DEFAULT 100.00 COMMENT '0-100 percentage',
    performance_score   DECIMAL(5,2)    NOT NULL DEFAULT 5.00  COMMENT '0-10 scale',
    certification_score DECIMAL(5,2)    NOT NULL DEFAULT 5.00  COMMENT '0-10 scale',
    license_number      VARCHAR(50)     NULL DEFAULT NULL,
    license_class       VARCHAR(10)     NULL DEFAULT NULL,
    license_expiry      DATE            NULL DEFAULT NULL,
    status              ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (driver_id),
    UNIQUE KEY uq_drivers_employee_id (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: vehicles
-- Stores fleet vehicle information
-- ============================================================
CREATE TABLE vehicles (
    vehicle_id       INT          NOT NULL AUTO_INCREMENT,
    plate_number     VARCHAR(20)  NOT NULL,
    vehicle_type     ENUM('Bus','Van','Car','Minibus') NOT NULL,
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
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (vehicle_id),
    UNIQUE KEY uq_vehicles_plate_number (plate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: schedules
-- Stores trip/schedule assignments
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
    status          ENUM('pending','approved','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    priority_score  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    created_by      INT           NULL DEFAULT NULL,
    notes           TEXT          NULL DEFAULT NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (schedule_id),
    CONSTRAINT fk_schedules_driver_id   FOREIGN KEY (driver_id)   REFERENCES drivers(driver_id)  ON DELETE SET NULL,
    CONSTRAINT fk_schedules_vehicle_id  FOREIGN KEY (vehicle_id)  REFERENCES vehicles(vehicle_id) ON DELETE SET NULL,
    CONSTRAINT fk_schedules_created_by  FOREIGN KEY (created_by)  REFERENCES users(user_id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SAMPLE DATA: users
-- Password for admin: admin123  (stored as SHA2-256 hex)
-- In PHP application use password_hash / password_verify
-- ============================================================
INSERT INTO users (username, password, email, full_name, role, driver_id) VALUES
(
    'admin',
    SHA2('admin123', 256),
    'admin@uis.edu.my',
    'System Administrator',
    'admin',
    NULL
);

-- Driver portal accounts will be inserted after drivers table is populated (see below)

-- ============================================================
-- SAMPLE DATA: drivers
-- 8 drivers with realistic Malaysian names
-- ============================================================
INSERT INTO drivers
    (employee_id, name, phone, email, address, experience_years,
     attendance_rate, performance_score, certification_score,
     license_number, license_class, license_expiry, status)
VALUES
(
    'UIS-DRV-001',
    'Ahmad Faizal bin Mohd Rashid',
    '012-3456789',
    'faizal.rashid@uis.edu.my',
    'No. 12, Jalan Cempaka 3, Taman Cempaka, 40150 Shah Alam, Selangor',
    15.0,
    97.50,
    9.20,
    8.80,
    'D1234567',
    'D',
    '2026-08-31',
    'active'
),
(
    'UIS-DRV-002',
    'Mohd Hafizuddin bin Zulkifli',
    '013-2345678',
    'hafizuddin.zulkifli@uis.edu.my',
    'No. 5, Jalan Mawar 7, Taman Sri Muda, 40400 Shah Alam, Selangor',
    10.5,
    94.00,
    8.50,
    8.00,
    'D2345678',
    'D',
    '2025-12-31',
    'active'
),
(
    'UIS-DRV-003',
    'Norhaslinda binti Abdul Karim',
    '011-34567890',
    'haslinda.karim@uis.edu.my',
    'No. 88, Jalan Kenanga 2, Taman Kenanga, 45000 Kuala Selangor, Selangor',
    7.0,
    91.50,
    7.80,
    7.50,
    'D3456789',
    'B2',
    '2026-03-15',
    'active'
),
(
    'UIS-DRV-004',
    'Khairul Anuar bin Ismail',
    '019-4567890',
    'khairul.ismail@uis.edu.my',
    'No. 33, Jalan Damai 11, Taman Damai Jaya, 41000 Klang, Selangor',
    12.0,
    98.00,
    9.50,
    9.00,
    'D4567890',
    'D',
    '2027-01-20',
    'active'
),
(
    'UIS-DRV-005',
    'Siti Norzaharah binti Othman',
    '017-5678901',
    'zaharah.othman@uis.edu.my',
    'No. 21, Jalan Melati 4, Taman Melati Indah, 68000 Ampang, Selangor',
    3.5,
    85.00,
    6.50,
    6.00,
    'D5678901',
    'B2',
    '2025-06-30',
    'active'
),
(
    'UIS-DRV-006',
    'Zulkarnain bin Hamzah',
    '014-6789012',
    'zulkarnain.hamzah@uis.edu.my',
    'No. 7, Jalan Anggerik 9, Taman Anggerik, 41150 Klang, Selangor',
    8.0,
    89.50,
    8.00,
    7.80,
    'D6789012',
    'D',
    '2026-11-10',
    'on_leave'
),
(
    'UIS-DRV-007',
    'Roslan bin Abdul Wahab',
    '016-7890123',
    'roslan.wahab@uis.edu.my',
    'No. 45, Jalan Delima 6, Taman Delima, 40460 Shah Alam, Selangor',
    2.0,
    80.50,
    5.50,
    5.80,
    'D7890123',
    'B2',
    '2025-09-14',
    'active'
),
(
    'UIS-DRV-008',
    'Muhammad Asyraf bin Che Hassan',
    '018-8901234',
    'asyraf.hassan@uis.edu.my',
    'No. 60, Jalan Bayu 3, Taman Bayu Perdana, 41200 Klang, Selangor',
    5.0,
    92.00,
    7.20,
    7.00,
    'D8901234',
    'D',
    '2026-07-22',
    'active'
);

-- ============================================================
-- SAMPLE DATA: driver portal user accounts
-- Passwords: driver<employee_number>@uis  e.g. driver001@uis
-- Stored as SHA2-256 for SQL seed; PHP uses password_hash
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
-- SAMPLE DATA: vehicles
-- 2 buses, 2 vans, 2 cars
-- ============================================================
INSERT INTO vehicles
    (plate_number, vehicle_type, brand, model, year, capacity,
     fuel_type, status, last_maintenance, next_maintenance, mileage, notes)
VALUES
(
    'SEL 1234 A',
    'Bus',
    'Hino',
    'FB2W',
    2019,
    45,
    'Diesel',
    'available',
    '2024-11-01',
    '2025-05-01',
    128500,
    'Main campus shuttle bus. Air-conditioned. Suitable for large groups.'
),
(
    'SEL 5678 B',
    'Bus',
    'Scania',
    'K310',
    2021,
    50,
    'Diesel',
    'available',
    '2024-12-15',
    '2025-06-15',
    87300,
    'Long-distance university bus. GPS-tracked. Priority for inter-campus trips.'
),
(
    'WA 2211 C',
    'Van',
    'Toyota',
    'Hiace',
    2020,
    14,
    'Diesel',
    'available',
    '2024-10-20',
    '2025-04-20',
    64700,
    'Staff van. Air-conditioned. Ideal for small group official trips.'
),
(
    'WB 3322 D',
    'Van',
    'Nissan',
    'Urvan',
    2018,
    12,
    'Diesel',
    'maintenance',
    '2024-09-10',
    '2025-03-10',
    101200,
    'Currently under scheduled maintenance. Expected to be available by end of month.'
),
(
    'BDF 4400 E',
    'Car',
    'Proton',
    'X70',
    2022,
    5,
    'Petrol',
    'available',
    '2025-01-05',
    '2025-07-05',
    32400,
    'Executive sedan for VIP and management use.'
),
(
    'BDG 5500 F',
    'Car',
    'Perodua',
    'Bezza',
    2023,
    5,
    'Petrol',
    'available',
    '2025-01-18',
    '2025-07-18',
    18900,
    'General purpose compact car for day-to-day administrative errands.'
);

-- ============================================================
-- SAMPLE DATA: schedules
-- 10 trips across 2024-2025 with various statuses
-- priority_score pre-calculated using the formula:
--   (exp_score*0.30) + (att_score*0.20) + (perf_score*0.30) + (cert_score*0.20)
-- Driver 4 (Khairul) highest score ~9.21; Driver 7 (Roslan) lowest ~5.74
-- ============================================================
INSERT INTO schedules
    (driver_id, vehicle_id, trip_date, start_time, end_time,
     destination, purpose, passenger_count, status, priority_score, created_by, notes)
VALUES
-- 1. Completed trip: Khairul + Bus to KL
(
    4, 1,
    '2024-10-05', '07:00:00', '12:00:00',
    'Universiti Malaya, Kuala Lumpur',
    'Inter-university academic conference',
    38,
    'completed',
    9.21,
    1,
    'Annual academic conference. Return trip same day at 17:00.'
),
-- 2. Completed trip: Ahmad Faizal + Bus to Putrajaya
(
    1, 2,
    '2024-10-18', '08:00:00', '11:00:00',
    'Jabatan Pengajian Tinggi, Putrajaya',
    'Ministry briefing for university administrators',
    40,
    'completed',
    8.97,
    1,
    'Punctuality critical. Driver briefed on parking zones.'
),
-- 3. Completed trip: Mohd Hafizuddin + Van to Shah Alam
(
    2, 3,
    '2024-11-07', '09:00:00', '13:00:00',
    'Majlis Bandaraya Shah Alam (MBSA)',
    'Permit renewal documentation submission',
    6,
    'completed',
    8.49,
    1,
    'Admin staff trip. Driver to wait and return with staff.'
),
-- 4. Cancelled trip: Siti Norzaharah + Car (van under maintenance)
(
    5, 5,
    '2024-11-20', '14:00:00', '16:00:00',
    'Hospital Shah Alam, Selangor',
    'Staff medical appointment escort',
    3,
    'cancelled',
    6.28,
    1,
    'Cancelled due to driver personal emergency. Rescheduled to next week.'
),
-- 5. Completed trip: Muhammad Asyraf + Car
(
    8, 6,
    '2024-12-03', '10:00:00', '12:00:00',
    'Pejabat Pos Utama, Shah Alam',
    'Courier pickup for faculty documents',
    2,
    'completed',
    7.23,
    1,
    'Driver to collect registered mail and return immediately.'
),
-- 6. Approved upcoming trip: Khairul + Bus to Penang
(
    4, 2,
    '2025-03-10', '06:00:00', '14:00:00',
    'Universiti Sains Malaysia, Pulau Pinang',
    'Research collaboration visit',
    42,
    'approved',
    9.21,
    1,
    'Overnight trip. Hotel bookings confirmed. Return on 11 March.'
),
-- 7. Approved upcoming trip: Norhaslinda + Van
(
    3, 3,
    '2025-03-15', '08:30:00', '13:30:00',
    'Lembaga Hasil Dalam Negeri (LHDN), Petaling Jaya',
    'Finance department tax submission',
    5,
    'approved',
    7.72,
    1,
    'Sensitive documents on board. Driver advised to park securely.'
),
-- 8. Pending trip: Roslan + Car
(
    7, 6,
    '2025-04-02', '09:00:00', '11:00:00',
    'Jabatan Imigresen Malaysia, Shah Alam',
    'Foreign student document processing',
    4,
    'pending',
    5.74,
    1,
    'Awaiting final passenger list from International Office.'
),
-- 9. Pending trip: Ahmad Faizal + Bus (large group field trip)
(
    1, 1,
    '2025-04-14', '07:30:00', '18:30:00',
    'Putrajaya Botanical Garden & IOI City Mall, Putrajaya',
    'Student welfare day trip',
    44,
    'pending',
    8.97,
    1,
    'Student activity committee trip. Parent consent forms required.'
),
-- 10. In progress: Mohd Hafizuddin + Van (today-ish scenario)
(
    2, 3,
    '2025-02-28', '13:00:00', '17:00:00',
    'Cyberjaya University College of Medical Sciences, Cyberjaya',
    'MOA signing ceremony with partner institution',
    8,
    'in_progress',
    8.49,
    1,
    'VIP trip. Vice Chancellor on board. Formal dress code for driver.'
);

-- ============================================================
-- TABLE: messages
-- Internal communication between admin and drivers
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
-- End of schema
-- ============================================================
