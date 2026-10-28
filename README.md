# Driver Scheduling & Management System — Universiti Islam Selangor (UIS)

A web system for the UIS Vehicle Section: staff request a vehicle, the Head of Department approves, the
Administrative Assistant assigns a driver (with a recommendation), the Head of Admin confirms, and drivers see
their tasks. Aligned with the final SRS.

Plain PHP 8 + MySQL/MariaDB (mysqli), Bootstrap 5. No framework, no build step.

## Roles (five)

| Role | Stored as | What they do |
|------|-----------|--------------|
| Staff | `staff` | Request a vehicle (never a driver), see status, cancel own requests, notifications |
| Driver | `driver` | View-only task page (only **Confirmed** tasks), own profile (phone, licence classes and expiry), plus everything Staff can do |
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
- **Request form** — destination, purpose, passengers, optional supporting document (PDF/JPG/PNG, max 5 MB), one vehicle per request, and **With driver / Without driver** (UC010 A3).
- **Vendor bus (UC017 A2)** — when the bus needs a PSV licence and no UIS driver with a PSV licence is free for a task, the Assistant records a **vendor bus** (vendor name, bus registration number, vendor driver name) instead of a UIS driver, for the outbound trip (and the return trip uses the same vendor bus unless chosen otherwise) or for the return trip only. It goes to the Head of Admin like any assignment. The vendor tasks have no UIS driver and no UIS vehicle; when everything is vendor and the request is **Confirmed**, the UIS bus lock is released so the bus can be requested by others. Quotation and payment stay outside DSMS. Replace Driver does not apply to a vendor task.
- **Schedule change (UC029, UC030)** — *Schedule Change* (driver): **Unavailable Date** (one or more days; the page lists the driver's tasks on them) or **Withdraw from Task** (one confirmed task, also from *My Tasks*). A reason is required, past dates and a second pending request for the same date or task are refused. The Head of Admin is told (system + e-mail) and decides in *Schedule Changes* (comment required when rejecting; the task stays with the driver). Approving an Unavailable Date marks the driver unavailable: not recommended (UC018), cannot be assigned or used as a replacement, cannot be set on welfare duty that day (the welfare rotation skips them). Approved requests open *Replace Driver* for each affected Confirmed task with the reason *Driver Request*; because the Head of Admin does it, the task stays Confirmed. Withdraw requests apply to trip tasks.
- **Update vehicle by the driver (UC007 A1)** — in *My Tasks* the driver clicks *Update Vehicle* on a task and enters the current mileage, fuel level and any condition issue (only for a vehicle used in one of their own tasks). The mileage must not be lower than the last one. It is saved with the driver's name, date and time in `vehicle_usage_log` and becomes the vehicle's mileage; a condition issue notifies the Assistant. The Assistant sees the history on the vehicle page.
- **Driver profile (UC002, UC003)** — a driver opens *My Profile*, sees name, staff ID, phone, licence classes and expiry, and can update phone, licence classes (D, GDL, PSV) and licence expiry. No class ticked or an expiry date in the past is refused. The Assistant is told when the classes change. The Assistant / Head of Admin update any driver in *Drivers* (the driver is told).
- **Record Paper Form (UC010 A4)** — *Record Paper Form* (Assistant or Head of Admin): enter the details of the approved paper form BPTK/BPI/2025/PIN.5 for a staff member and upload the scan (PDF/JPG/PNG, max 5 MB). The request is saved as **Approved** and the vehicle is locked (it clashes with no online request). No approval e-mail to the Head of Department and no reminder; allowed less than 1 day before the trip, but not in the past. History shows "Recorded from the approved paper form".
- **Without driver (UC010 A3, UC019 A2)** — same approval by the Head of Department and the same vehicle lock, but no driver step and no driver tasks. After the Head of Department approves, the Assistant clicks *Send to Head of Admin* (status Driver Assigned); the Head of Admin approves the use of the vehicle (Confirmed) or rejects it with a reason (back to Approved). The hourly job completes it after the return time or cancels it if nobody confirmed it before the trip.
- **Tasks** — each request creates an outbound task (at departure) and a return task (at return), each lasting `TASK_DURATION_HOURS` (default 2 h). Drivers are scheduled on tasks, not for the whole trip.
- **Recommender** — only drivers holding the licence class for the vehicle (D car/van, GDL lorry, PSV bus), without an overlapping task or internal task; ranked by a weighted score (UC018): tasks this month (`W_TASKS` 0.5, the highest), weekend tasks, Saturday and Sunday only (`W_WEEKEND` 0.3) and experience in years, calculated from the date the driver joined the Vehicle Section (`W_EXPERIENCE` 0.2). Fewer tasks and fewer weekend tasks rank higher, more experience ranks higher. The date joined is entered in Add Driver and edited in Edit Driver; the old column `drivers.experience_years` is kept but no longer used. Drivers on welfare duty are flagged. No auto-assign and no override: the Assistant picks, the Head of Admin always approves.
- **Delete internal task (UC024)** — only before it starts; a reason is required, the driver is told (in-system + e-mail), the driver and vehicle are free again and the task no longer counts in any workload. It is a soft delete: the row stays in `internal_tasks` with status `cancelled` and the reason, and the interface calls it Deleted (filter *Deleted*). The history log keeps `internal_task_delete`.
- **Cancel** — staff may give a reason; HoD and administrators must. Never after the trip has started.
- **Reminder (UC012)** — the hourly job sends ONE reminder (e-mail + in the system) to the Head of Department when a request is still Pending after 1 day, and records it in `reminders`. The Assistant can still remind by hand (Approval Reminders page).
- **Vehicles** — statuses Available / In Use (computed) / Under Maintenance / Inactive. Maintenance asks for the workshop and the expected return date. Vehicles and drivers are deactivated, never deleted.
- **Notifications** — always saved in the system **and** sent by e-mail; a failed e-mail is written to `email_log` and the in-system notification is kept.
- **Look and feel** — `assets/css/uis-theme-premium.css` (design system export) with `assets/css/uis-polish.css` on top: deep UIS green sidebar, gradient hero banners with readable white text, tinted KPI icon tiles, soft rounded cards, pill status badges, dark-mode toggle (header on desktop, top bar on phone). All three CSS files are loaded after `style.css` in `includes/header.php`, `includes/admin_page.php` and `admin/settings.php`; remove the two extra `<link>` lines to go back to the plain look.
- **Bilingual interface: English / Bahasa Melayu (outside the SRS)** — the *EN | BM* switch is in the sidebar, the mobile top bar and the login page. The choice is kept in the cookie `dsms_lang` and on the account (`users.language`, migration `2026_11_user_language`); a language chosen on the login page is saved at login, and a new browser gets the language of the account. The pages stay written in English: `includes/i18n.php` translates the finished page (text, placeholders, titles, texts in scripts, AJAX answers) with the dictionary `includes/lang/ms.php`. A text that is missing from the dictionary simply stays English. To add or fix a translation, edit `includes/lang/ms.php` (English text => Malay); entries with a space also work as fragments of longer messages. Notification e-mails are sent in the language of the receiver; in-system notifications are stored in English and shown in the viewer's language. PDF/Excel exports, the printed task sheet header and the user's own data (names, destinations, comments) are not translated.
- **WhatsApp (optional, outside the SRS)** — SRS v1.1 lists only in-system and e-mail notifications. The code stays but is **off by default**: while `enabled` is false nothing is sent and nothing is written to `whatsapp_log`. If it is switched on, drivers are also told on WhatsApp through an OpenWA server (setup: `docs/OPENWA_SETUP.md`). Copy `config/whatsapp.sample.php` to `config/whatsapp.php`, fill in the server address, API key and session id, set `enabled` to true. Attempts are written to `whatsapp_log`; a failure never stops the in-system notification or the e-mail. OpenWA is not an official WhatsApp API, so the sending number may be blocked: use a dedicated number.
- **Reports** (UC031/UC032) — six monthly reports, each on screen, printed, PDF or Excel: tasks per driver, weekend tasks per driver, outside working hours per driver (kept in addition to the SRS list), welfare duty days per driver, driver replacements (with reason and who made it) and vehicle usage. A month without data shows "No data for the selected month".
- **History** — each request keeps its own history (the `auditLog()` function); there is no audit-log page.

## Dashboards

Every role has its own start page: Administrative Assistant / Head of Admin (KPI cards with month-on-month trend, action queue, alerts, status / monthly / driver-workload / vehicle-hours charts, today's timeline, fleet), Head of Department (requests to approve for their own PTJ + their own requests), Staff (next trip, progress of each request, recent activity) and Driver (today, next 7 days, month counts; view only). Charts use Chart.js from a CDN; without internet the same numbers are shown as small tables.

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

**Real server (VPS):** see `docs/VPS_SETUP.md` (Apache, PHP, MariaDB, HTTPS, cron, OpenWA). Server settings go in `config/local.php` (copy `config/local.sample.php`; not uploaded to GitHub).

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

It reminds the Head of Department once after 1 day, lapses unhandled Pending requests after 2 days, cancels unconfirmed assignments when the trip starts, and completes finished trips.
It also runs opportunistically on page load.

## Settings (Admin → Settings)

`W_TASKS`, `W_WEEKEND`, `W_EXPERIENCE` (must add up to 1, tasks the highest; an old `W_AFTER_HOURS` row stays in existing databases but is not used), `WORK_START` 08:00, `WORK_END` 17:30, `TASK_DURATION_HOURS` 2,
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
