-- ============================================================
-- DSMS demo data (import AFTER dsms.sql)
--
-- All people are DUMMY names. Every test account uses the password
--   Password123
-- (stored as a bcrypt hash, must_change_password = 0 for the seed).
--
-- Dates are relative to the day you import this file (CURDATE()/NOW()),
-- so the demo requests are always "in the future" and the calendar is
-- never empty. Re-import seed.sql (after dsms.sql) to refresh the demo.
-- ============================================================
USE dsms;
SET NAMES utf8mb4;

-- ---------- PTJ (departments / units) ----------
INSERT INTO ptj (id, code, name) VALUES
(1,'BPI','Bahagian Pengurusan Insan (BPI)'),
(2,'BEND','Bendahari'),
(3,'PEND','Pendaftar'),
(4,'BHEP','BHEP'),
(5,'PEM','Pemasaran'),
(6,'FSU','FSU'),
(7,'FSS','FSS'),
(8,'FP','FP'),
(9,'FEPP','FEPP'),
(10,'RIMC','RIMC'),
(11,'PUST','Pustakaan'),
(12,'LAN','Lanskap');

-- ---------- Pickup points ----------
INSERT INTO pickup_points (id, name) VALUES
(1,'1st Cafe'),(2,'Parking Pengurusan'),(3,'Bulatan UIS'),(4,'Susur Gajah'),
(5,'Stor Utama'),(6,'Depan Masjid Al Azhar'),(7,'Depan KUISCELL');

-- ---------- Users ----------
INSERT INTO users (id, staff_id, name, email, phone, role, ptj_id, approver_id, password_hash, must_change_password) VALUES
(1,'hod2','Dr. Hakimi bin Ramli (Dummy)','hod2@dsms.test','012-000 0002','hod',3,NULL,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(2,'hod1','Pn. Rosnani binti Salleh (Dummy)','hod1@dsms.test','012-000 0003','hod',5,1,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(3,'headadmin1','En. Zulkifli bin Mansor (Dummy)','headadmin1@dsms.test','012-000 0004','head_admin',1,1,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(4,'admin1','Pn. Azlina binti Yusof (Dummy)','admin1@dsms.test','012-000 0005','admin_assistant',1,1,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(5,'staff1','Nurul Aisyah binti Karim (Dummy)','staff1@dsms.test','012-000 0006','staff',5,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(6,'staff2','Muhammad Haziq bin Osman (Dummy)','staff2@dsms.test','012-000 0007','staff',5,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(7,'driver1','Ahmad Faiz bin Latif (Dummy)','driver1@dsms.test','013-000 0001','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(8,'driver2','Mohd Syafiq bin Daud (Dummy)','driver2@dsms.test','013-000 0002','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(9,'driver3','Khairul Amin bin Zaini (Dummy)','driver3@dsms.test','013-000 0003','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(10,'driver4','Azman bin Hashim (Dummy)','driver4@dsms.test','013-000 0004','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(11,'driver5','Roslan bin Mat Yusof (Dummy)','driver5@dsms.test','013-000 0005','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(12,'driver6','Faizal bin Jamal (Dummy)','driver6@dsms.test','013-000 0006','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(13,'driver7','Suhaimi bin Abu Bakar (Dummy)','driver7@dsms.test','013-000 0007','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(14,'driver8','Hafizuddin bin Roslee (Dummy)','driver8@dsms.test','013-000 0008','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(15,'driver9','Ismail bin Kassim (Dummy)','driver9@dsms.test','013-000 0009','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(16,'driver10','Zainal bin Mokhtar (Dummy)','driver10@dsms.test','013-000 0010','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(17,'driver11','Rashid bin Ariffin (Dummy)','driver11@dsms.test','013-000 0011','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(18,'driver12','Shamsul bin Kadir (Dummy)','driver12@dsms.test','013-000 0012','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(19,'driver13','Nordin bin Hamid (Dummy)','driver13@dsms.test','013-000 0013','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(20,'driver14','Kamarul bin Idris (Dummy)','driver14@dsms.test','013-000 0014','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(21,'driver15','Samad bin Wahid (Dummy)','driver15@dsms.test','013-000 0015','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0),
(22,'driver16','Ridzuan bin Basri (Dummy)','driver16@dsms.test','013-000 0016','driver',1,2,'$2y$12$Makx7Ieg0caskn/mq8/ffeRZY9H/LOot5QPjK.lCI6u.W86D9S/ii',0);

-- ---------- Drivers: 12 carpool (1-12), 4 management (13-16) ----------
INSERT INTO drivers (id, user_id, driver_group) VALUES
(1,7,'carpool'),
(2,8,'carpool'),
(3,9,'carpool'),
(4,10,'carpool'),
(5,11,'carpool'),
(6,12,'carpool'),
(7,13,'carpool'),
(8,14,'carpool'),
(9,15,'carpool'),
(10,16,'carpool'),
(11,17,'carpool'),
(12,18,'carpool'),
(13,19,'management'),
(14,20,'management'),
(15,21,'management'),
(16,22,'management');

-- Licences: 1-8 carpool D (1 and 2 also GDL); 9-12 carpool D + PSV; 13-16 management D
INSERT INTO driver_licences (driver_id, licence_class) VALUES
(1,'D'),
(1,'GDL'),
(2,'D'),
(2,'GDL'),
(3,'D'),
(4,'D'),
(5,'D'),
(6,'D'),
(7,'D'),
(8,'D'),
(9,'D'),
(9,'PSV'),
(10,'D'),
(10,'PSV'),
(11,'D'),
(11,'PSV'),
(12,'D'),
(12,'PSV'),
(13,'D'),
(14,'D'),
(15,'D'),
(16,'D');

-- ---------- 19 vehicles (placeholder plate numbers) ----------
INSERT INTO vehicles (id, reg_no, brand, model, vehicle_type, capacity, required_licence, road_tax_expiry) VALUES
(1,'DSM 1001','Toyota','Camry','Sedan',3,'D',DATE_ADD(CURDATE(), INTERVAL 73 DAY)),
(2,'DSM 1002','Toyota','Camry','Sedan',3,'D',DATE_ADD(CURDATE(), INTERVAL 86 DAY)),
(3,'DSM 1003','Nissan','Sylphy','Sedan',4,'D',DATE_ADD(CURDATE(), INTERVAL 99 DAY)),
(4,'DSM 1004','Nissan','Serena','MPV',5,'D',DATE_ADD(CURDATE(), INTERVAL 112 DAY)),
(5,'DSM 1005','Perodua','Bezza','Sedan',4,'D',DATE_ADD(CURDATE(), INTERVAL 125 DAY)),
(6,'DSM 1006','Proton','Saga','Sedan',4,'D',DATE_ADD(CURDATE(), INTERVAL 138 DAY)),
(7,'DSM 1007','Proton','Saga','Sedan',4,'D',DATE_ADD(CURDATE(), INTERVAL 151 DAY)),
(8,'DSM 1008','Proton','Saga','Sedan',4,'D',DATE_ADD(CURDATE(), INTERVAL 164 DAY)),
(9,'DSM 2001','Nissan','Urvan','Van',10,'D',DATE_ADD(CURDATE(), INTERVAL 177 DAY)),
(10,'DSM 2002','Nissan','Urvan','Van',10,'D',DATE_ADD(CURDATE(), INTERVAL 190 DAY)),
(11,'DSM 2003','Nissan','Urvan','Van',10,'D',DATE_ADD(CURDATE(), INTERVAL 203 DAY)),
(12,'DSM 2004','Toyota','Hiace','Van',9,'D',DATE_ADD(CURDATE(), INTERVAL 216 DAY)),
(13,'DSM 3001','Hino','Lorry','Lorry',1,'GDL',DATE_ADD(CURDATE(), INTERVAL 229 DAY)),
(14,'DSM 3002','Daihatsu','3-Tonne Lorry','Lorry',1,'GDL',DATE_ADD(CURDATE(), INTERVAL 242 DAY)),
(15,'DSM 3003','Daihatsu','Gran Max Lorry','Lorry',1,'GDL',DATE_ADD(CURDATE(), INTERVAL 255 DAY)),
(16,'DSM 4001','Hino','Bus','Bus',40,'PSV',DATE_ADD(CURDATE(), INTERVAL 268 DAY)),
(17,'DSM 4002','Nissan','Bus','Bus',40,'PSV',DATE_ADD(CURDATE(), INTERVAL 281 DAY)),
(18,'DSM 4003','Nissan','Bus','Bus',44,'PSV',DATE_ADD(CURDATE(), INTERVAL 294 DAY)),
(19,'DSM 4004','Hino','Bus','Bus',25,'PSV',DATE_ADD(CURDATE(), INTERVAL 307 DAY));

-- ---------- Maintenance and leave (to demo the availability checks) ----------
INSERT INTO vehicle_maintenance (vehicle_id, start_date, end_date, workshop, note) VALUES
(8,  DATE_ADD(CURDATE(), INTERVAL 3 DAY),  DATE_ADD(CURDATE(), INTERVAL 6 DAY),  'Bengkel Contoh A', 'Scheduled service'),
(10, DATE_SUB(CURDATE(), INTERVAL 2 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY),  'Bengkel Contoh B', 'Brake repair');

INSERT INTO driver_leaves (driver_id, start_date, end_date, type, note) VALUES
(5, DATE_ADD(CURDATE(), INTERVAL 7 DAY),  DATE_ADD(CURDATE(), INTERVAL 9 DAY),  'leave', 'Annual leave'),
(6, CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 1 DAY),  'mc',    'Medical certificate');

-- ---------- Sample requests in every status ----------
INSERT INTO requests (id, requester_id, approver_id, vehicle_id, destination_name, full_address, purpose, passenger_count, passenger_names, contact_phone, pickup_point_id, departure_datetime, return_datetime, status, status_reason, submitted_at, approved_at, assigned_at, confirmed_at, completed_at, cancelled_by, cancelled_by_role, cancelled_at) VALUES
(1,5,2,5,'Putrajaya International Convention Centre','Presint 5, 62000 Putrajaya','Attend national higher education forum',3,'Nurul Aisyah; Dr. Hakimi; Pn. Rosnani','012-000 0006',1,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 5 DAY), INTERVAL '08:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 5 DAY), INTERVAL '17:00' HOUR_MINUTE),'Pending Approval',NULL,DATE_SUB(NOW(), INTERVAL 6 HOUR),NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(2,6,2,3,'Kementerian Pendidikan Tinggi','Aras 5, Blok E, Presint 2, 62100 Putrajaya','Submit accreditation documents',2,'Muhammad Haziq; En. Zulkifli','012-000 0007',2,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 6 DAY), INTERVAL '09:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 6 DAY), INTERVAL '15:00' HOUR_MINUTE),'Pending Approval',NULL,DATE_SUB(NOW(), INTERVAL 40 HOUR),NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(3,6,2,9,'Universiti Teknologi Contoh, Skudai','81310 Skudai, Johor','Inter-university student exchange visit',9,'Student delegation (9 pax)','012-000 0007',3,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 7 DAY), INTERVAL '07:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 8 DAY), INTERVAL '19:00' HOUR_MINUTE),'Approved',NULL,DATE_SUB(NOW(), INTERVAL 30 HOUR),DATE_SUB(NOW(), INTERVAL 24 HOUR),NULL,NULL,NULL,NULL,NULL,NULL),
(4,5,2,4,'Hotel Contoh, Kuala Lumpur','Jalan Ampang, 50450 Kuala Lumpur','Staff retreat briefing',5,'5 staff of Pemasaran','012-000 0006',4,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 9 DAY), INTERVAL '08:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 9 DAY), INTERVAL '18:00' HOUR_MINUTE),'Driver Assigned',NULL,DATE_SUB(NOW(), INTERVAL 3 DAY),DATE_SUB(NOW(), INTERVAL 70 HOUR),DATE_SUB(NOW(), INTERVAL 20 HOUR),NULL,NULL,NULL,NULL,NULL),
(5,6,2,16,'Masjid Negara','Jalan Perdana, 50480 Kuala Lumpur','Student programme (bus trip)',38,'Student group (38 pax)','012-000 0007',6,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 10 DAY), INTERVAL '07:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 11 DAY), INTERVAL '18:00' HOUR_MINUTE),'Confirmed',NULL,DATE_SUB(NOW(), INTERVAL 4 DAY),DATE_SUB(NOW(), INTERVAL 3 DAY),DATE_SUB(NOW(), INTERVAL 50 HOUR),DATE_SUB(NOW(), INTERVAL 30 HOUR),NULL,NULL,NULL,NULL),
(6,5,2,1,'Bank Contoh, Shah Alam','Persiaran Kayangan, 40000 Shah Alam','Finance appointment',2,'Nurul Aisyah; Pn. Rosnani','012-000 0006',2,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 0 DAY), INTERVAL '09:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 0 DAY), INTERVAL '16:00' HOUR_MINUTE),'Confirmed',NULL,DATE_SUB(NOW(), INTERVAL 5 DAY),DATE_SUB(NOW(), INTERVAL 4 DAY),DATE_SUB(NOW(), INTERVAL 3 DAY),DATE_SUB(NOW(), INTERVAL 2 DAY),NULL,NULL,NULL,NULL),
(7,5,2,2,'Pejabat Tanah Contoh','Jalan Contoh 1, Shah Alam','Personal errand',1,'Nurul Aisyah','012-000 0006',1,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 12 DAY), INTERVAL '09:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 12 DAY), INTERVAL '12:00' HOUR_MINUTE),'Rejected','The purpose is not an official UIS duty.',DATE_SUB(NOW(), INTERVAL 2 DAY),NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(8,6,2,7,'Jabatan Contoh, Bangi','43650 Bandar Baru Bangi','Collect printed materials',2,'Muhammad Haziq; Staff','012-000 0007',5,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 4 DAY), INTERVAL '09:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 4 DAY), INTERVAL '13:00' HOUR_MINUTE),'Lapsed','Your request lapsed because it was not approved within two days.',DATE_SUB(NOW(), INTERVAL 4 DAY),NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(9,5,2,6,'Seremban','Jalan Contoh, 70000 Seremban','Site visit',3,'3 staff','012-000 0006',3,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 14 DAY), INTERVAL '08:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 14 DAY), INTERVAL '16:00' HOUR_MINUTE),'Cancelled','The programme was postponed.',DATE_SUB(NOW(), INTERVAL 3 DAY),NULL,NULL,NULL,NULL,5,'staff',DATE_SUB(NOW(), INTERVAL 1 DAY)),
(10,6,2,7,'Pusat Dakwah Contoh','Jalan Contoh 2, Kajang','Community programme',4,'4 staff','012-000 0007',2,DATE_ADD(DATE_ADD(CURDATE(), INTERVAL -6 DAY), INTERVAL '08:00' HOUR_MINUTE),DATE_ADD(DATE_ADD(CURDATE(), INTERVAL -5 DAY), INTERVAL '17:00' HOUR_MINUTE),'Completed',NULL,DATE_SUB(NOW(), INTERVAL 12 DAY),DATE_SUB(NOW(), INTERVAL 11 DAY),DATE_SUB(NOW(), INTERVAL 10 DAY),DATE_SUB(NOW(), INTERVAL 9 DAY),DATE_SUB(NOW(), INTERVAL 5 DAY),NULL,NULL,NULL);

-- ---------- Legs: every request has a departure and a return leg (driver set once assigned) ----------
INSERT INTO request_legs (request_id, leg_type, driver_id, start_datetime, end_datetime)
SELECT r.id, 'departure', NULL, r.departure_datetime, DATE_ADD(r.departure_datetime, INTERVAL 2 HOUR) FROM requests r
UNION ALL
SELECT r.id, 'return', NULL, DATE_SUB(r.return_datetime, INTERVAL 2 HOUR), r.return_datetime FROM requests r;

-- Assigned drivers (carpool drivers; bus trip uses PSV drivers 9 and 10)
UPDATE request_legs SET driver_id=3 WHERE request_id=4 AND leg_type='departure';
UPDATE request_legs SET driver_id=4 WHERE request_id=4 AND leg_type='return';
UPDATE request_legs SET driver_id=9 WHERE request_id=5 AND leg_type='departure';
UPDATE request_legs SET driver_id=10 WHERE request_id=5 AND leg_type='return';
UPDATE request_legs SET driver_id=1 WHERE request_id=6 AND leg_type='departure';
UPDATE request_legs SET driver_id=1 WHERE request_id=6 AND leg_type='return';
UPDATE request_legs SET driver_id=2 WHERE request_id=10 AND leg_type='departure';
UPDATE request_legs SET driver_id=2 WHERE request_id=10 AND leg_type='return';

-- ---------- Vehicle day holds (rule R3): one row per vehicle per day of every ACTIVE request ----------
-- Pending / Approved / Driver Assigned / Confirmed hold the vehicle; Rejected, Cancelled,
-- Lapsed (and past Completed) trips hold nothing.
INSERT INTO vehicle_day_holds (vehicle_id, hold_date, request_id) VALUES
(5,DATE_ADD(CURDATE(), INTERVAL 5 DAY),1),
(3,DATE_ADD(CURDATE(), INTERVAL 6 DAY),2),
(9,DATE_ADD(CURDATE(), INTERVAL 7 DAY),3),
(9,DATE_ADD(CURDATE(), INTERVAL 8 DAY),3),
(4,DATE_ADD(CURDATE(), INTERVAL 9 DAY),4),
(16,DATE_ADD(CURDATE(), INTERVAL 10 DAY),5),
(16,DATE_ADD(CURDATE(), INTERVAL 11 DAY),5),
(1,DATE_ADD(CURDATE(), INTERVAL 0 DAY),6);

-- ---------- Comments, reminders, notifications, audit ----------
INSERT INTO request_comments (request_id, user_id, user_role, comment, created_at) VALUES
(3, 2, 'hod',  'Approved. Please make sure the student list is submitted before departure.', DATE_SUB(NOW(), INTERVAL 24 HOUR)),
(7, 2, 'hod',  'Rejected because this is not an official duty.', DATE_SUB(NOW(), INTERVAL 40 HOUR)),
(4, 4, 'admin_assistant', 'Two drivers assigned. Waiting for Head Admin approval.', DATE_SUB(NOW(), INTERVAL 20 HOUR));

INSERT INTO reminders (request_id, sent_by, sent_at) VALUES
(2, 4, DATE_SUB(NOW(), INTERVAL 5 HOUR));

INSERT INTO notifications (user_id, message, link, is_read) VALUES
(2, 'New vehicle request from Nurul Aisyah is waiting for your approval.', '?page=requests/show&id=1', 0),
(2, 'Reminder: the request from Muhammad Haziq will lapse soon.', '?page=requests/show&id=2', 0),
(3, 'A driver assignment (request #4) is waiting for your approval.', '?page=requests/show&id=4', 0),
(5, 'Your request #7 was rejected: The purpose is not an official UIS duty.', '?page=requests/show&id=7', 1),
(6, 'Your request #8 lapsed because it was not approved within two days.', '?page=requests/show&id=8', 0);

INSERT INTO audit_log (user_id, user_role, action, request_id, reason, created_at) VALUES
(2, 'hod', 'approve', 3, NULL, DATE_SUB(NOW(), INTERVAL 24 HOUR)),
(2, 'hod', 'reject',  7, 'The purpose is not an official UIS duty.', DATE_SUB(NOW(), INTERVAL 40 HOUR)),
(NULL, NULL, 'lapse', 8, 'Not approved within two days.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(5, 'staff', 'cancel', 9, 'The programme was postponed.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4, 'admin_assistant', 'assign', 4, NULL, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(4, 'admin_assistant', 'reminder', 2, NULL, DATE_SUB(NOW(), INTERVAL 5 HOUR));

-- ---------- Internal tasks (count in driver workload) ----------
INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, vehicle_id, driver_id, location, description, created_by) VALUES
('workshop_send',   CURDATE(),                          '08:00:00','10:00:00', 8,  7, 'Bengkel Contoh A', 'Send Saga for service', 4),
('refuel',          DATE_ADD(CURDATE(), INTERVAL 1 DAY),'14:00:00','15:00:00', 17, 8, 'Petrol station',   'Refuel bus before trip', 4),
('document_delivery',DATE_ADD(CURDATE(), INTERVAL 2 DAY),'10:00:00','12:00:00', NULL,9, 'Putrajaya',       'Deliver documents to ministry', 4);

-- ---------- Welfare duty roster: one 24-hour duty driver per day this month ----------
-- Carpool drivers 1..12 take turns in rotation order.
INSERT INTO welfare_roster (duty_date, driver_id)
SELECT DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL n.n DAY), (n.n MOD 12) + 1
FROM (SELECT a.x + b.x * 10 AS n FROM
        (SELECT 0 x UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
         UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
        CROSS JOIN (SELECT 0 x UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) b) n
WHERE DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL n.n DAY) <= LAST_DAY(CURDATE());

-- ---------- Settings (editable by admin; used by the recommender and the jobs) ----------
INSERT INTO settings (setting_key, setting_value, description) VALUES
('W_TASKS',            '0.6', 'Recommendation weight for tasks this month (highest weight)'),
('W_AFTER_HOURS',      '0.4', 'Recommendation weight for after-hours tasks this month'),
('WELFARE_PENALTY',    '20',  'Points removed from the score of the welfare duty driver of that day'),
('LAPSE_HOURS',        '48',  'Pending requests not approved within this many hours lapse'),
('MIN_DAYS_AHEAD',     '2',   'Departure must be at least this many days after today (rule R1)'),
('WORK_START',         '08:00','Start of normal working hours (earlier = after-hours)'),
('WORK_END',           '17:30','End of normal working hours (later = after-hours)'),
('SESSION_TIMEOUT_MIN','15',  'Log out after this many minutes of inactivity'),
('MAX_FAILED_ATTEMPTS','5',   'Lock the account after this many wrong passwords'),
('MAX_UPLOAD_MB',      '5',   'Maximum size of each uploaded document');
