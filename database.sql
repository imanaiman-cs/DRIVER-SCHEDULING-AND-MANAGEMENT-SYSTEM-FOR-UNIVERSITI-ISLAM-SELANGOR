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
    role         ENUM('admin','driver') NOT NULL DEFAULT 'admin',
    driver_id    INT           NULL DEFAULT NULL,
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
    attendance_rate     DECIMAL(5,2)    NOT NULL DEFAULT 100.00 COMMENT '0-100 percentage',
    performance_score   DECIMAL(5,2)    NOT NULL DEFAULT 5.00  COMMENT '0-10 scale',
    certification_score DECIMAL(5,2)    NOT NULL DEFAULT 5.00  COMMENT '0-10 scale',
    license_number      VARCHAR(50)     NULL DEFAULT NULL,
    license_class       VARCHAR(10)     NULL DEFAULT NULL,
    license_expiry      DATE            NULL DEFAULT NULL,
    status              ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
    driver_type         ENUM('top_management','regular') NOT NULL DEFAULT 'regular',
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (driver_id),
    UNIQUE KEY uq_drivers_employee_id (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: vehicles
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
    trip_type       ENUM('regular','top_management') NOT NULL DEFAULT 'regular',
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (schedule_id),
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
-- TABLE: leave_requests
-- ============================================================
CREATE TABLE leave_requests (
    request_id   INT           NOT NULL AUTO_INCREMENT,
    driver_id    INT           NOT NULL,
    leave_type   ENUM('emergency','medical','annual','personal') NOT NULL DEFAULT 'personal',
    start_date   DATE          NOT NULL,
    end_date     DATE          NOT NULL,
    reason       TEXT          NOT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by  INT           NULL DEFAULT NULL,
    admin_notes  TEXT          NULL DEFAULT NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    CONSTRAINT fk_lr_driver   FOREIGN KEY (driver_id)   REFERENCES drivers(driver_id) ON DELETE CASCADE,
    CONSTRAINT fk_lr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)     ON DELETE SET NULL
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
     experience_years, attendance_rate, performance_score, certification_score,
     license_number, license_class, license_expiry, status)
VALUES
(
    'UIS-DRV-001', 'Ahmad Faizal bin Mohd Rashid',
    '012-3456789', 'faizal.rashid@uis.edu.my',
    'No. 12, Jalan Cempaka 3, Taman Cempaka, 40150 Shah Alam, Selangor',
    15.0, 97.50, 9.20, 8.80, 'D1234567', 'D', '2027-08-31', 'active'
),
(
    'UIS-DRV-002', 'Mohd Hafizuddin bin Zulkifli',
    '013-2345678', 'hafizuddin.zulkifli@uis.edu.my',
    'No. 5, Jalan Mawar 7, Taman Sri Muda, 40400 Shah Alam, Selangor',
    10.5, 94.00, 8.50, 8.00, 'D2345678', 'D', '2027-12-31', 'active'
),
(
    'UIS-DRV-003', 'Norhaslinda binti Abdul Karim',
    '011-34567890', 'haslinda.karim@uis.edu.my',
    'No. 88, Jalan Kenanga 2, Taman Kenanga, 45000 Kuala Selangor, Selangor',
    7.0, 91.50, 7.80, 7.50, 'D3456789', 'B2', '2027-03-15', 'active'
),
(
    'UIS-DRV-004', 'Khairul Anuar bin Ismail',
    '019-4567890', 'khairul.ismail@uis.edu.my',
    'No. 33, Jalan Damai 11, Taman Damai Jaya, 41000 Klang, Selangor',
    12.0, 98.00, 9.50, 9.00, 'D4567890', 'D', '2028-01-20', 'active'
),
(
    'UIS-DRV-005', 'Siti Norzaharah binti Othman',
    '017-5678901', 'zaharah.othman@uis.edu.my',
    'No. 21, Jalan Melati 4, Taman Melati Indah, 68000 Ampang, Selangor',
    3.5, 85.00, 6.50, 6.00, 'D5678901', 'B2', '2027-06-30', 'active'
),
(
    'UIS-DRV-006', 'Zulkarnain bin Hamzah',
    '014-6789012', 'zulkarnain.hamzah@uis.edu.my',
    'No. 7, Jalan Anggerik 9, Taman Anggerik, 41150 Klang, Selangor',
    8.0, 89.50, 8.00, 7.80, 'D6789012', 'D', '2027-11-10', 'on_leave'
),
(
    'UIS-DRV-007', 'Roslan bin Abdul Wahab',
    '016-7890123', 'roslan.wahab@uis.edu.my',
    'No. 45, Jalan Delima 6, Taman Delima, 40460 Shah Alam, Selangor',
    2.0, 80.50, 5.50, 5.80, 'D7890123', 'B2', '2027-09-14', 'active'
),
(
    'UIS-DRV-008', 'Muhammad Asyraf bin Che Hassan',
    '018-8901234', 'asyraf.hassan@uis.edu.my',
    'No. 60, Jalan Bayu 3, Taman Bayu Perdana, 41200 Klang, Selangor',
    5.0, 92.00, 7.20, 7.00, 'D8901234', 'D', '2027-07-22', 'active'
),
(
    'UIS-DRV-009', 'Fadzillah bin Mohd Noor',
    '012-9012345', 'fadzillah.noor@uis.edu.my',
    'No. 3, Jalan Pelangi 1, Taman Pelangi Maju, 40150 Shah Alam, Selangor',
    18.0, 99.00, 9.80, 9.50, 'D9012345', 'D', '2028-05-15', 'active'
),
(
    'UIS-DRV-010', 'Nurul Ain binti Saharuddin',
    '011-0123456', 'nurulain.saharuddin@uis.edu.my',
    'No. 18, Jalan Seroja 5, Taman Seroja, 41000 Klang, Selangor',
    4.0, 88.00, 7.00, 6.80, 'D0123456', 'B2', '2027-10-30', 'active'
),
(
    'UIS-DRV-011', 'Hairul Nizam bin Kamaruddin',
    '013-1234567', 'hairul.kamaruddin@uis.edu.my',
    'No. 27, Jalan Teratai 8, Taman Sri Andalas, 41200 Klang, Selangor',
    6.0, 72.00, 5.00, 5.00, 'D1345678', 'B2', '2026-12-01', 'inactive'
),
(
    'UIS-DRV-012', 'Azhari bin Mahmud',
    '017-2345670', 'azhari.mahmud@uis.edu.my',
    'No. 9, Jalan Dahlia 3, Taman Meru Jaya, 41050 Klang, Selangor',
    9.0, 93.50, 8.20, 8.00, 'D2456789', 'D', '2028-03-20', 'active'
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
    'SEL 1234 A', 'Bus', 'Hino', 'FB2W', 2019, 45, 'Diesel', 'available',
    '2026-04-10', '2026-10-10', 198500,
    'Main campus shuttle bus. Air-conditioned. GPS-tracked. Suitable for large groups.'
),
(
    'SEL 5678 B', 'Bus', 'Scania', 'K310', 2021, 50, 'Diesel', 'available',
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
    'Executive sedan for VIP and senior management use.'
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
    'BJK 6600 I', 'Bus', 'Yutong', 'ZK6119HQ', 2020, 40, 'Diesel', 'available',
    '2026-06-10', '2026-12-10', 134800,
    'Third bus for high-demand periods and large group trips.'
),
(
    'BDF 7700 J', 'Car', 'Honda', 'Civic', 2019, 5, 'Petrol', 'retired',
    '2025-12-01', NULL, 241000,
    'Retired from service. High mileage. Pending disposal process.'
);

-- ============================================================
-- SEED: schedules – 52 trips (Jan 2025 – Dec 2026)
-- Driver priority scores:
--   1=8.72  2=7.61  3=6.72  4=8.41  5=5.53
--   7=4.72  8=6.56  9=9.31 10=6.16 12=7.38
-- Vehicles: 1=Bus Hino  2=Bus Scania  3=Van Hiace  5=Car X70
--           6=Car Bezza  7=Minibus Hiace  8=Minibus Sprinter  9=Bus Yutong
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
 'VIP trip. Vice Chancellor on board. Formal dress code for driver.'),

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
 'VIP delegation pickup – international guests',
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
 'VIP trip. Accompanied by Dean of Islamic Studies.'),

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
 'Dear Asyraf, management is very pleased with your work performance this quarter. Your attendance rate and passenger feedback scores are both excellent. Keep up the great work!',
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

-- ============================================================
-- UPDATE: trip_type assignments
-- Top Management trips: Ministry, VIP, accreditation, national conferences,
--                       convocation, senior management affairs
-- ============================================================
UPDATE schedules SET trip_type = 'top_management'
WHERE schedule_id IN (2, 5, 11, 18, 20, 23, 25, 26, 30, 33, 36, 37, 38, 40, 42, 46, 47, 48, 49, 50);

-- ============================================================
-- SEED: leave_requests – 10 sample requests
-- ============================================================
INSERT INTO leave_requests
    (driver_id, leave_type, start_date, end_date, reason, status, reviewed_by, admin_notes, created_at)
VALUES
(
    6, 'medical', '2025-06-16', '2025-06-20',
    'I am currently undergoing medical treatment for a knee injury sustained during duty. My doctor has advised complete rest for 5 days. Attached is the medical certificate from Hospital Tengku Ampuan Rahimah, Klang.',
    'approved', 1,
    'Medical leave approved. Please ensure fitness certificate is submitted before return to duty.',
    '2025-06-14 10:00:00'
),
(
    3, 'annual', '2025-07-14', '2025-07-18',
    'I would like to apply for 5 days annual leave for family vacation in conjunction with school holidays. I have ensured that there are no scheduled trips assigned to me during this period.',
    'approved', 1,
    'Annual leave approved. No scheduled trips in this period.',
    '2025-07-07 09:30:00'
),
(
    5, 'emergency', '2025-11-03', '2025-11-03',
    'My father was admitted to the hospital last night with a heart attack. I need to be by his side today and am unable to attend the assigned trip on 4 November 2025. I apologise for the late notice.',
    'approved', 1,
    'Emergency leave approved. Trip rescheduled. Please update us on your father\'s condition.',
    '2025-11-03 06:45:00'
),
(
    7, 'personal', '2025-12-22', '2025-12-24',
    'I wish to apply for personal leave to attend my sister\'s wedding ceremony in Kelantan. The event spans 3 days. I have confirmed with colleagues that my duties can be covered during this period.',
    'approved', 1,
    'Personal leave approved. Congratulations to your family!',
    '2025-12-10 14:00:00'
),
(
    11, 'medical', '2026-01-12', '2026-01-14',
    'I am experiencing severe flu and fever. I visited the clinic this morning and the doctor has recommended 3 days rest. I will submit the medical certificate upon recovery.',
    'approved', 1,
    'Medical leave approved. Rest well and submit MC upon return.',
    '2026-01-12 07:30:00'
),
(
    8, 'annual', '2026-03-23', '2026-03-27',
    'I am applying for annual leave during the school holiday period to spend time with my family. I have checked the schedule and I have no assigned trips during this period.',
    'approved', 1,
    'Annual leave approved. Enjoy your family time.',
    '2026-03-15 11:00:00'
),
(
    10, 'emergency', '2026-05-13', '2026-05-13',
    'My child was involved in a road accident this morning and has been admitted to Hospital Klang. I am unable to perform any duties today and request emergency leave. Sincerest apologies for the last-minute notice.',
    'approved', 1,
    'Emergency leave approved. We hope your child recovers quickly. Please keep us informed.',
    '2026-05-13 08:15:00'
),
(
    3, 'annual', '2026-08-04', '2026-08-08',
    'Applying for 5 days annual leave to use my remaining leave entitlement. No scheduled trips assigned to me during this period. I have coordinated with the transport office beforehand.',
    'pending', NULL, NULL,
    '2026-07-01 09:00:00'
),
(
    6, 'personal', '2026-07-28', '2026-07-29',
    'I need to attend a family obligation in Johor Bahru and am requesting 2 days personal leave. I am currently on medical monitoring and this leave will allow me to consult my specialist in JB.',
    'pending', NULL, NULL,
    '2026-07-02 08:00:00'
),
(
    5, 'annual', '2026-09-15', '2026-09-19',
    'Request for annual leave during the Hari Malaysia long weekend period. I have no assigned trips during these dates. This will be used for a family road trip to Sabah.',
    'rejected', 1,
    'Leave rejected: you are scheduled for a trip on 2 September 2026 that overlaps with the preparation period. Please reapply after that trip is completed.',
    '2026-08-20 10:30:00'
);

-- ============================================================
-- End of schema – 52 schedules · 12 drivers · 10 vehicles · 26 messages · 10 leave requests
-- ============================================================
