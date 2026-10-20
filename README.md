# Driver Scheduling & Management System — Universiti Islam Selangor (UIS)

A web system for the UIS Vehicle Section: staff request a vehicle, the Head of Department approves, the
Administrative Assistant assigns a driver (with a recommendation), the Head of Admin confirms, and drivers see
their tasks. Aligned with the final SRS.

Plain PHP 8 + MySQL/MariaDB (mysqli), Bootstrap 5. No framework, no build step.

## Roles (five)

| Role | Stored as | What they do |
|------|-----------|--------------|
| Staff | `staff` | Request a vehicle (never a driver), see status, cancel own requests, notifications |
| Driver | `driver` | View-only task page (only **Confirmed** tasks), plus everything Staff can do |
| Head of Department (HoD) | `supervisor` | Approve / reject (reason required) / cancel requests of **their own PTJ**, add comments |
| Administrative Assistant | `admin` | Process Approved requests (recommended driver), Replace Driver, vehicles + maintenance, drivers, internal tasks, welfare duty, calendar, manual reminders, reports, settings |
| Head of Admin | `head_admin` | Everything the Assistant can do, plus **approve / reject every driver assignment** |

Every role is also Staff and can request a vehicle. Accounts are synchronised from the UIS staff system
(`tools/import_staff.php`); there is no user management, temporary password or manual unlock.
Demo accounts: `LOGIN_ACCOUNTS.txt`.

## Request flow

```
Pending ──HoD──► Approved ──Assistant──► Driver Assigned ──Head of Admin──► Confirmed ──► Completed
   │                │                          │ (rejected assignment: back to Approved)
   ├─► Rejected     └─► Cancelled              └─► Cancelled
   ├─► Lapsed  (no HoD decision within 2 days, checked hourly)
   └─► Cancelled
```

Status labels shown: Pending, Approved, Driver Assigned, Confirmed (`processed`), Rejected, Cancelled, Lapsed, Completed.
The requester sees the driver and vehicle only after **Confirmed**; a driver sees a task only after Confirmed.

## Business rules (summary)

- **Dates and times** — departure and return are both date + time. Less than 1 day ahead is rejected (use the paper form BPTK/BPI/2025/PIN.5); less than 2 days ahead shows a warning.
- **Vehicle lock per time period** — a vehicle is locked from departure to return. Overlap rule: `start < other_end AND end > other_start`. The lock is taken atomically (transaction + `SELECT … FOR UPDATE`).
- **Request form** — destination, purpose, passengers, optional supporting document (PDF/JPG/PNG, max 5 MB), one vehicle per request.
- **Tasks** — each request creates an outbound task (at departure) and a return task (at return), each lasting `TASK_DURATION_HOURS` (default 2 h). Drivers are scheduled on tasks, not for the whole trip.
- **Recommender** — only drivers holding the licence class for the vehicle (D car/van, GDL lorry, PSV bus), without an overlapping task or internal task; ranked by tasks this month and tasks outside working hours (weights `W_TASKS` 0.6 / `W_AFTER_HOURS` 0.4). Drivers on welfare duty are flagged. No auto-assign and no override: the Assistant picks, the Head of Admin always approves.
- **Cancel** — staff may give a reason; HoD and administrators must. Never after the trip has started.
- **Reminder** — manual only (Assistant → HoD); there is no automatic reminder.
- **Vehicles** — statuses Available / In Use (computed) / Under Maintenance / Inactive. Maintenance asks for the workshop and the expected return date. Vehicles and drivers are deactivated, never deleted.
- **Notifications** — always saved in the system **and** sent by e-mail; a failed e-mail is written to `email_log` and the in-system notification is kept.
- **WhatsApp (optional)** — drivers are also told on WhatsApp through an OpenWA server (open-wa EASY API). Copy `config/whatsapp.sample.php` to `config/whatsapp.php`, fill in the server address and API key, set `enabled` to true. Attempts are written to `whatsapp_log`; a failure never stops the in-system notification or the e-mail. OpenWA is not an official WhatsApp API, so the sending number may be blocked: use a dedicated number.
- **Reports** — four monthly reports (PDF, Excel, print).
- **History** — each request keeps its own history (the `auditLog()` function); there is no audit-log page.

## Security

- bcrypt passwords (old SHA-256 demo hashes are upgraded at first sign-in).
- 5 failed sign-ins block the account for 15 minutes (`MAX_FAILED_ATTEMPTS`, `LOCKOUT_MINUTES`); it unblocks by itself.
- Idle logout after 15 minutes (`SESSION_TIMEOUT_MIN`).
- Role check on every page and endpoint, CSRF token on every POST, prepared statements for every query, upload type/size checks.
- `.htaccess` denies web access to `config/`, `includes/`, `cron/`, `tests/`, `migrations/` and `*.sql`.

## Install (XAMPP)

1. Copy the folder to `C:\xampp\htdocs\DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR`.
2. Start **Apache** and **MySQL**.
3. Import `database.sql` (phpMyAdmin → Import). It creates `uis_driver_db` with the final schema and demo data.
4. Check `config/database.php` (host, user, password, `SITE_URL`).
5. Open `…/login.php`.

**Existing database from the previous release:** import `migrations/2026_10_align_srs.sql` (or just open any page: it is applied once automatically and recorded in `schema_migrations`). Back up first; the migration also drops tables of removed features.

E-mail: copy `config/mail.sample.php` to `config/mail.php` and fill in the SMTP details (PHPMailer). Without it, e-mails fail into `email_log` and notifications still work in the system.

## Importing staff

```
php tools/import_staff.php path/to/staff.csv       # see tools/staff_sample.csv for the columns
```

Add Driver (Admin → Drivers) picks an existing user with the Driver role and records the licence classes D / GDL / PSV.

## Background job (hourly)

```
php cron/run_jobs.php        # Windows Task Scheduler: C:\xampp\php\php.exe cron\run_jobs.php, every hour
```

It lapses unhandled Pending requests after 2 days, cancels unconfirmed assignments when the trip starts, and completes finished trips.
It also runs opportunistically on page load.

## Settings (Admin → Settings)

`W_TASKS`, `W_AFTER_HOURS` (must add up to 1), `WORK_START` 08:00, `WORK_END` 17:30, `TASK_DURATION_HOURS` 2,
`LAPSE_HOURS` 48, `SESSION_TIMEOUT_MIN` 15, `MAX_FAILED_ATTEMPTS` 5, `LOCKOUT_MINUTES` 15.

## Tests

```
php tests/run.php            # rules + recommender; each test is rolled back (needs the database)
tests/http/                  # end-to-end suites over HTTP, see tests/http/README.md
```

## Folders

```
admin/ staff/ supervisor/ driver/   role pages
ajax/                               JSON / action endpoints
includes/                           workflow.php (status engine, locks, notifications, jobs), recommender.php, mailer, sidebar, exports
config/                             database connection, session + security
cron/run_jobs.php                   command-line job runner
migrations/                         SQL for existing databases
tools/                              staff importer
tests/                              unit tests (php) and HTTP suites
```

## Assumptions and limits

- The SRS both asks to remove drivers on welfare duty and to flag them; welfare duty lasts all day, so they are **flagged**, not removed.
- Outside working hours = starts before 08:00, ends after 17:30, or Saturday/Sunday.
- A vehicle under maintenance can be requested for periods starting after its expected return date.
- Task length is a setting (the SRS gives none); one vehicle per request.
- Manual schedule add/edit and auto-assign were removed: trips come from requests only.
- Details of the design decisions: `docs/SRS_ALIGNMENT_PLAN.md`.
