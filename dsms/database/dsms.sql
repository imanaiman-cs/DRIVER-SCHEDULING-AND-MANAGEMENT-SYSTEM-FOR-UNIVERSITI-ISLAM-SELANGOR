-- ============================================================
-- DSMS - Driver Scheduling and Management System
-- Universiti Islam Selangor (UIS) - Vehicle Section
--
-- database/dsms.sql  =  STRUCTURE ONLY (tables, keys, indexes, triggers)
-- database/seed.sql  =  demo data (import this AFTER dsms.sql)
--
-- Import order (phpMyAdmin > Import, or the mysql command line):
--   1. dsms.sql
--   2. seed.sql
--
-- WARNING: importing dsms.sql again DROPS and re-creates every DSMS table,
-- so all data in them is lost. It never touches other databases.
-- ============================================================

CREATE DATABASE IF NOT EXISTS dsms
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dsms;

SET FOREIGN_KEY_CHECKS = 0;

-- Drop in any order (foreign key checks are off) so the file can be re-run.
DROP TABLE IF EXISTS settings, audit_log, notifications, reminders, welfare_roster,
    internal_tasks, driver_replacements, request_comments, vehicle_day_holds,
    request_legs, request_documents, requests, pickup_points, vehicle_maintenance,
    vehicles, driver_leaves, driver_licences, drivers, users, ptj;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- ptj : department / unit (PTJ = Pusat Tanggungjawab)
-- ------------------------------------------------------------
CREATE TABLE ptj (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(150) NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ptj_code (code)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- users : every person who can log in.
-- Roles generalise: every role is also "staff" (can request vehicles).
-- approver_id = the person who approves this user's requests:
--   normal staff -> Head of Department; a Head of Department -> their own head.
-- ------------------------------------------------------------
CREATE TABLE users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id             VARCHAR(30)  NOT NULL,
    name                 VARCHAR(120) NOT NULL,
    email                VARCHAR(150) NOT NULL,
    phone                VARCHAR(30)  NULL,
    role                 ENUM('staff','driver','hod','admin_assistant','head_admin') NOT NULL DEFAULT 'staff',
    ptj_id               INT UNSIGNED NULL,
    approver_id          INT UNSIGNED NULL,
    password_hash        VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1)   NOT NULL DEFAULT 1,   -- 1 = temporary password, must change on first login
    failed_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0, -- locks the account at 5
    is_locked            TINYINT(1)   NOT NULL DEFAULT 0,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,   -- no hard delete: deactivate instead
    last_login_at        DATETIME     NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_staff_id (staff_id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_approver (approver_id),
    CONSTRAINT fk_users_ptj      FOREIGN KEY (ptj_id)      REFERENCES ptj(id)   ON DELETE SET NULL,
    CONSTRAINT fk_users_approver FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- drivers : extra details for users who drive.
-- driver_group 'management' drivers are dedicated to top management and are
-- NEVER recommended or assigned in the normal flow (rule R8).
-- ------------------------------------------------------------
CREATE TABLE drivers (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    driver_group ENUM('carpool','management') NOT NULL DEFAULT 'carpool',
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_user (user_id),
    KEY idx_drivers_group (driver_group, is_active),
    CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A driver may hold several licence classes (B2, D, E, H, GDL, PSV).
CREATE TABLE driver_licences (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id     INT UNSIGNED NOT NULL,
    licence_class ENUM('B2','D','E','H','GDL','PSV') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_driver_licence (driver_id, licence_class),
    CONSTRAINT fk_licence_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Leave / medical certificate (MC). Drivers on leave are filtered out of recommendations.
CREATE TABLE driver_leaves (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id  INT UNSIGNED NOT NULL,
    start_date DATE         NOT NULL,
    end_date   DATE         NOT NULL,
    type       ENUM('leave','mc') NOT NULL DEFAULT 'leave',
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_leave_dates (driver_id, start_date, end_date),
    CONSTRAINT fk_leave_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- vehicles. required_licence says which licence class may drive it
-- (cars/vans -> D, lorries -> GDL, buses -> PSV).
-- No hard delete: set status = 'Inactive'.
-- ------------------------------------------------------------
CREATE TABLE vehicles (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reg_no           VARCHAR(20)  NOT NULL,
    brand            VARCHAR(60)  NOT NULL,
    model            VARCHAR(60)  NOT NULL,
    vehicle_type     ENUM('Sedan','MPV','Van','Bus','Lorry') NOT NULL,
    capacity         SMALLINT UNSIGNED NOT NULL,
    required_licence ENUM('D','GDL','PSV') NOT NULL DEFAULT 'D',
    road_tax_expiry  DATE         NULL,
    status           ENUM('Available','Inactive') NOT NULL DEFAULT 'Available',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicles_reg (reg_no),
    KEY idx_vehicles_status (status, capacity)
) ENGINE=InnoDB;

CREATE TABLE vehicle_maintenance (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id INT UNSIGNED NOT NULL,
    start_date DATE         NOT NULL,
    end_date   DATE         NOT NULL,
    workshop   VARCHAR(120) NULL,
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maint_dates (vehicle_id, start_date, end_date),
    CONSTRAINT fk_maint_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pickup_points (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name      VARCHAR(120) NOT NULL,
    is_active TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pickup_name (name)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- requests : ONE request = ONE trip (departure leg + return leg).
-- The vehicle is chosen by the requester; the driver is assigned per leg
-- (see request_legs). is_self_drive = admin marked "no driver needed".
-- ------------------------------------------------------------
CREATE TABLE requests (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requester_id       INT UNSIGNED NOT NULL,
    approver_id        INT UNSIGNED NULL,
    vehicle_id         INT UNSIGNED NOT NULL,
    destination_name   VARCHAR(150) NOT NULL,
    full_address       TEXT         NOT NULL,
    purpose            TEXT         NOT NULL,
    passenger_count    SMALLINT UNSIGNED NOT NULL,
    passenger_names    TEXT         NULL,
    contact_phone      VARCHAR(30)  NOT NULL,
    pickup_point_id    INT UNSIGNED NULL,
    departure_datetime DATETIME     NOT NULL,
    return_datetime    DATETIME     NOT NULL,
    status             ENUM('Pending Approval','Approved','Rejected','Lapsed',
                            'Driver Assigned','Confirmed','Completed','Cancelled')
                       NOT NULL DEFAULT 'Pending Approval',
    status_reason      VARCHAR(500) NULL,     -- why it was rejected / cancelled / lapsed
    is_self_drive      TINYINT(1)   NOT NULL DEFAULT 0,
    submitted_at       DATETIME     NOT NULL,
    approved_at        DATETIME     NULL,
    assigned_at        DATETIME     NULL,
    confirmed_at       DATETIME     NULL,
    completed_at       DATETIME     NULL,
    cancelled_by       INT UNSIGNED NULL,
    cancelled_by_role  VARCHAR(20)  NULL,
    cancelled_at       DATETIME     NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_req_status (status, submitted_at),
    KEY idx_req_requester (requester_id, status),
    KEY idx_req_approver (approver_id, status),
    KEY idx_req_vehicle_dates (vehicle_id, departure_datetime, return_datetime),
    CONSTRAINT fk_req_requester FOREIGN KEY (requester_id)    REFERENCES users(id),
    CONSTRAINT fk_req_approver  FOREIGN KEY (approver_id)     REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_req_vehicle   FOREIGN KEY (vehicle_id)      REFERENCES vehicles(id),
    CONSTRAINT fk_req_pickup    FOREIGN KEY (pickup_point_id) REFERENCES pickup_points(id) ON DELETE SET NULL,
    CONSTRAINT fk_req_cancelby  FOREIGN KEY (cancelled_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE request_documents (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id    INT UNSIGNED NOT NULL,
    file_path     VARCHAR(255) NOT NULL,    -- random name inside storage/uploads
    original_name VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    file_size     INT UNSIGNED NOT NULL,
    uploaded_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_request (request_id),
    CONSTRAINT fk_doc_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Each request has a 'departure' leg and a 'return' leg, each with its own
-- driver (may be the same person). driver_id stays NULL until assigned.
CREATE TABLE request_legs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id     INT UNSIGNED NOT NULL,
    leg_type       ENUM('departure','return') NOT NULL,
    driver_id      INT UNSIGNED NULL,
    start_datetime DATETIME     NOT NULL,
    end_datetime   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leg (request_id, leg_type),
    KEY idx_leg_driver_time (driver_id, start_datetime, end_datetime),
    CONSTRAINT fk_leg_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_leg_driver  FOREIGN KEY (driver_id)  REFERENCES drivers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- vehicle_day_holds : rule R3. The vehicle is held for EVERY date from
-- departure to return. UNIQUE(vehicle_id, hold_date) is what guarantees two
-- people can never hold the same vehicle on the same day, even if they press
-- Submit at the same moment: the second INSERT fails at database level.
-- ------------------------------------------------------------
CREATE TABLE vehicle_day_holds (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id INT UNSIGNED NOT NULL,
    hold_date  DATE         NOT NULL,
    request_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hold_vehicle_day (vehicle_id, hold_date),
    KEY idx_hold_request (request_id),
    CONSTRAINT fk_hold_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    CONSTRAINT fk_hold_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Comments are append-only (rule R9): triggers at the bottom block UPDATE/DELETE.
CREATE TABLE request_comments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    user_role  VARCHAR(20)  NOT NULL,
    comment    TEXT         NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comment_request (request_id, created_at),
    CONSTRAINT fk_comment_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_comment_user    FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB;

-- Change Driver (rule R7): the request status does not change, but every swap is logged.
CREATE TABLE driver_replacements (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id         INT UNSIGNED NOT NULL,
    leg_id             INT UNSIGNED NOT NULL,
    previous_driver_id INT UNSIGNED NULL,
    new_driver_id      INT UNSIGNED NOT NULL,
    reason             VARCHAR(500) NOT NULL,
    changed_by         INT UNSIGNED NOT NULL,
    changed_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_repl_request (request_id),
    CONSTRAINT fk_repl_request FOREIGN KEY (request_id)         REFERENCES requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_repl_leg     FOREIGN KEY (leg_id)             REFERENCES request_legs(id) ON DELETE CASCADE,
    CONSTRAINT fk_repl_prev    FOREIGN KEY (previous_driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
    CONSTRAINT fk_repl_new     FOREIGN KEY (new_driver_id)      REFERENCES drivers(id),
    CONSTRAINT fk_repl_by      FOREIGN KEY (changed_by)         REFERENCES users(id)
) ENGINE=InnoDB;

-- Internal tasks (workshop run, refuel, ...) need no approval but count in driver workload.
CREATE TABLE internal_tasks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_type   ENUM('workshop_send','workshop_collect','refuel','test_drive',
                     'road_tax','document_delivery','other') NOT NULL,
    task_date   DATE         NOT NULL,
    start_time  TIME         NOT NULL,
    end_time    TIME         NOT NULL,
    vehicle_id  INT UNSIGNED NULL,
    driver_id   INT UNSIGNED NOT NULL,
    location    VARCHAR(150) NULL,
    description VARCHAR(255) NULL,
    created_by  INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_itask_driver (driver_id, task_date),
    KEY idx_itask_vehicle (vehicle_id, task_date),
    CONSTRAINT fk_itask_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    CONSTRAINT fk_itask_driver  FOREIGN KEY (driver_id)  REFERENCES drivers(id),
    CONSTRAINT fk_itask_by      FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Welfare duty: one 24-hour duty driver per day. duty_date is UNIQUE.
CREATE TABLE welfare_roster (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    duty_date              DATE         NOT NULL,
    driver_id              INT UNSIGNED NOT NULL,
    replaced_from_driver_id INT UNSIGNED NULL,
    replacement_reason     VARCHAR(500) NULL,
    created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_welfare_date (duty_date),
    KEY idx_welfare_driver (driver_id, duty_date),
    CONSTRAINT fk_welfare_driver FOREIGN KEY (driver_id) REFERENCES drivers(id),
    CONSTRAINT fk_welfare_from   FOREIGN KEY (replaced_from_driver_id) REFERENCES drivers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE reminders (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    sent_by    INT UNSIGNED NOT NULL,
    sent_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reminder_request (request_id),
    CONSTRAINT fk_reminder_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_reminder_by      FOREIGN KEY (sent_by)    REFERENCES users(id)
) ENGINE=InnoDB;

-- In-app notifications (the bell). Emails are separate (MailService).
CREATE TABLE notifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    message    VARCHAR(500) NOT NULL,
    link       VARCHAR(255) NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id, is_read, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Audit log is insert-only (rule R10): triggers at the bottom block UPDATE/DELETE.
CREATE TABLE audit_log (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,           -- NULL = done by the system (e.g. lapse job)
    user_role  VARCHAR(20)  NULL,
    action     VARCHAR(50)  NOT NULL,       -- approve, reject, cancel, lapse, assign, ...
    request_id INT UNSIGNED NULL,
    reason     VARCHAR(500) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_request (request_id),
    KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB;

-- Editable system settings (recommendation weights, lapse hours, working hours ...).
CREATE TABLE settings (
    setting_key   VARCHAR(60)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    description   VARCHAR(255) NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Append-only protection (rules R9 and R10). Even a bug in the PHP code
-- cannot edit or delete a comment or an audit row.
-- (Cascade deletes of a request's comments are allowed because they are
--  not row-level DELETE statements issued against the table directly by users.)
-- ------------------------------------------------------------
DELIMITER $$

CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_log
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is insert-only';
END$$

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_log
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is insert-only';
END$$

CREATE TRIGGER trg_comment_no_update BEFORE UPDATE ON request_comments
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Comments cannot be edited';
END$$

CREATE TRIGGER trg_comment_no_delete BEFORE DELETE ON request_comments
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Comments cannot be deleted';
END$$

DELIMITER ;
