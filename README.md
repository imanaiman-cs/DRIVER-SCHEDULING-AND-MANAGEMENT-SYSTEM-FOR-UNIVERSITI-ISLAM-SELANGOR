# Driver Scheduling & Management System — Universiti Islam Selangor (UIS)

A web system for the UIS Vehicle Section: staff request a vehicle (e-Kenderaan), the Head of Section approves,
the Vehicle Section (admin) assigns vehicle and driver, and drivers see their task sheet.

Plain PHP 8 + MySQL/MariaDB (mysqli), Bootstrap 5, SweetAlert2. No framework, no build step.

## Roles (five)

| Role | Stored as | What they do |
|------|-----------|--------------|
| Staff | `staff` | Request a **vehicle** (never a driver), track and cancel own requests, see notifications |
| Driver | `driver` | Everything staff can do, plus own confirmed task sheet (PDF), workload score, other duties |
| Head of Section (HoD) | `supervisor` | Everything staff can do, plus approve / reject / cancel requests where they are the approver, comment |
| Admin Assistant | `admin` | Everything staff can do, plus all admin work: process approved requests (assign vehicle + driver), schedules, drivers, vehicles, leave, welfare duty, internal tasks, reports, reminders, settings, user accounts, audit log |
| Head of Admin | `head_admin` | Everything an Admin Assistant can do, plus **approve driver assignments** (a request he assigns himself is confirmed at once) |

**Every role is also staff**: all of them can submit a vehicle request and follow / cancel their own
(*My Vehicle Requests* in the menu). A request goes to the approver of the requester
(staff -> their Head of Section; a Head of Section -> their own head). The approver is set per account in
*User Accounts*.

## Request flow

```
pending ──► approved ──► assigned ──► confirmed ──► completed
   │            │ (Head of Admin assigns himself: straight to confirmed)
   ├─► rejected └─► cancelled     └─► cancelled
   ├─► lapsed   (48 h without a decision, or the trip time has passed)
   └─► cancelled
```

| Status shown | Stored as | Who moves it there |
|--------------|-----------|--------------------|
| Pending Approval | `pending` | requester submits |
| Approved | `approved` | Head of Section |
| Driver Assigned | `assigned` | Admin Assistant assigns vehicle + driver (the Head of Admin is told) |
| Confirmed | `processed` | Head of Admin approves the assignment, or assigns it himself |
| Completed | `completed` | system, after the trip |

The driver sees a trip (and gets the e-mail) only once it is **Confirmed**. An assignment that is still not
confirmed when the trip time arrives is cancelled by the system with a reason.

Every status change goes through one function (`changeRequestStatus`), is written to the audit log and
notifies the people concerned (bell + email).

## Business rules

- **Two-day rule** — a request must be made at least 2 days before the trip (setting `MIN_DAYS_AHEAD`). Urgent trips use the paper form BPTK/BPI/2025/PIN.5.
- **Vehicle day holds** — a requested vehicle is held for that day; two active requests cannot hold the same vehicle on the same day. Released on reject / cancel / lapse.
- **Maintenance** — a vehicle under maintenance cannot be requested or scheduled.
- **Lapse** — a pending request lapses after 48 h (`LAPSE_HOURS`) or when the trip time passes; one automatic reminder goes to the Head of Section after 24 h.
- **Cancel / reject** need a reason (min. 3 characters). Cancel is possible only before the trip starts.
- **Driver recommender** — hard filters first (active, regular driver, licence class for the vehicle, not on leave/MC, no overlapping trip, max 8 h per day); then a score `100 − (0.6·tasks + 0.4·after-hours)·100 − welfare penalty` (weights and penalty are settings). Higher = fewer tasks this month = fairer to assign.
- **Cancel works** for Pending, Approved, Driver Assigned and Confirmed requests, before the trip starts; the vehicle (and the driver's task) is released.
- **Legs (multi-stop and multi-day trips)** — a request can have more legs, each with its own day, time and destination (up to 7). The same vehicle(s) are held on every leg's day, the Admin chooses the driver per leg (default: the same driver), and the request is Completed only after the last leg.
- **Mark as Self-Drive** — when a vehicle is free but there is no driver, the admin marks the request as self-drive: the vehicle is reserved, no driver is assigned or e-mailed, and the requester is told they drive.
- **Override of a clash** — normally a driver (or vehicle) that is already booked in the time slot cannot be chosen. An admin may knowingly assign a **driver** with a clashing task if a reason (min. 5 characters) is given: it is stored on the schedule, written to the audit log and the Head of Admin is told. Leave/MC, licence, inactive drivers and vehicle clashes can never be overridden.
- **Changing an assigned driver** needs a reason; it is logged and the requester is notified.
- **Welfare duty** — a driver on welfare duty is penalised in the score; the roster can be auto-filled fairly.
- **Audit log** — insert-only (database triggers refuse update/delete).

## Security

- Passwords are stored with bcrypt. The SHA-256 demo hashes in `database.sql` are upgraded at first sign-in.
- Account lock after 5 failed sign-ins (`MAX_FAILED_ATTEMPTS`); an admin unlocks it in **User Accounts**.
- Idle session timeout (`SESSION_TIMEOUT_MIN`, default 15 minutes).
- New accounts and reset passwords get a random temporary password, shown once; the user must change it at first sign-in.
- CSRF token on every POST (added automatically to forms, `fetch` and XHR by `assets/js/ui.js`).
- Prepared statements everywhere; output escaped; session cookie `HttpOnly` + `SameSite=Lax`; clickjacking / sniffing headers.
- `.htaccess` denies web access to `config/`, `includes/`, `cron/`, `tests/` and `*.sql`.
- Accounts are deactivated, never deleted, so old records keep their names.

## Install (XAMPP)

1. Copy the folder to `C:\xampp\htdocs\DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Import `database.sql` (phpMyAdmin → Import). It creates the database `uis_driver_db` with demo data.
4. Check `config/database.php` (host, user, password, `SITE_URL`).
5. Open `http://localhost/DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR/login.php`.

Demo accounts are in `LOGIN_ACCOUNTS.txt`. If you already have an older database, just open any page: the schema
is upgraded automatically (new tables and columns are added; nothing is dropped).

Email (optional): copy `config/mail.sample.php` to `config/mail.php` and fill in your SMTP details. Without it the
system still works; emails are only logged.

## Background jobs

Lapsing, completing and reminding run by themselves on page load (at most every 5 minutes). For exact timing also
run the job from the OS scheduler every 15 minutes:

```
php cron/run_jobs.php            (Windows Task Scheduler: C:\xampp\php\php.exe  cron\run_jobs.php)
```

## Settings (Admin → Settings)

`W_TASKS` 0.6, `W_AFTER_HOURS` 0.4, `WELFARE_PENALTY` 20, `MIN_DAYS_AHEAD` 2, `LAPSE_HOURS` 48,
`REMINDER_AFTER_HOURS` 24, `WORK_START` / `WORK_END`, `SESSION_TIMEOUT_MIN` 15, `MAX_FAILED_ATTEMPTS` 5.
The two weights must add up to 1.

## Locked yourself out?

```sql
UPDATE users SET is_locked = 0, failed_attempts = 0 WHERE username = 'admin';
```

## Tests

```
php tests/run.php        # rules + recommender, each test runs in a rolled-back transaction (needs the database)
```

## Folders

```
admin/ staff/ supervisor/ driver/   role pages
ajax/                               JSON endpoints used by the pages
includes/                           workflow.php (status engine, holds, audit, jobs), recommender.php, sidebar, mailer
config/                             database connection, schema upgrader, security checks
cron/run_jobs.php                   command-line job runner
tests/                              automated tests
```

## Known limits

A vehicle can never be double-booked, and a driver clash needs an explicit override with a reason.
The vehicles are the same on every leg of a request (only the driver can differ per leg).
An older database has no Head of Admin account: create one in *User Accounts* (role Head of Admin) and
set the approver of drivers/admins there too, so they can request vehicles.
