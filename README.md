# Driver Scheduling & Management System — Universiti Islam Selangor (UIS)

A web system for the UIS Vehicle Section, built on the final SRS (use cases UC001–UC028) and the SDD (three-layer design,
SDD_CLASS_101–314, the 14-table data dictionary of `dsms_db`).
Staff request vehicles, the Head of Department approves, the Administrative Assistant assigns the vehicles and drivers
with a driver recommendation, the Head of Admin approves, and drivers see their tasks and report each trip.

Plain PHP 8 + MySQL/MariaDB (PDO). No PHP framework. Tailwind CSS + DaisyUI (built file committed, no Node needed on the server).
Use case → class → file map: `docs/SRS_TRACEABILITY.md`.

## Architecture (SDD)

```
View Layer          the pages (admin/ staff/ supervisor/ driver/ + register, login, profile, notifications) and includes/ui.php
                    -> call the Domain Layer only (no SQL in a page)
Domain Layer        app/domain/   AccountController, VehicleController, RequestController, ApprovalController,
                                  AssignmentController, RecommendationService, TaskController, DriverTaskController,
                                  ReportController, NotificationService, ScheduledJob      (SDD_CLASS_201-211)
Data Access Layer   app/data/     Database (PDO) + one class per table: User, Driver, Department, Vehicle, Request,
                                  VehicleReservation, RequestStatusHistory, Task, WelfareDuty, TaskReport,
                                  ScheduleChange, DriverReplacement, Notification          (SDD_CLASS_301-314)
```

The classes are loaded automatically (`config/database.php`). The rules (weights, hours, charge) are in `config/settings.php`.

## Roles

| Role | Stored as | Main use cases |
|------|-----------|----------------|
| Staff | `Staff` | Register (UC013), New Request (UC018), My Request (UC017), Cancel (UC021), Profile (UC016) |
| Head of Department | `Head of Department` | Department Request: approve / reject (UC025, UC028) + everything Staff does. Confirmed by the Assistant. One per department. |
| Driver | `Driver` | My Task (UC023), Task Report (UC024), Schedule Change (UC022) + request vehicles (Self Drive lorry with GDL) |
| Administrative Assistant | `Administrative Assistant` | New Request / My Request (UC018, UC017) like Staff; User Account (UC001), Vehicle (UC002), Assign Request Task (UC003), Internal Task (UC004/005), Welfare Duty (UC006/007), Replace Driver (UC008), Driver Schedule (UC010), Report (UC011/012) |
| Head of Admin | `Head of Admin` | Everything the Assistant does (including making requests) + Pending Approval (UC025 A2, UC026) and Schedule Change (UC027). Head of BPI: approves the requests of BPI staff (the Assistant and the drivers) |

Staff and Heads of Department register themselves (UIS email + verification code). Drivers are created by the Assistant
(login details e-mailed). The Assistant and Head of Admin accounts come with `database.sql`. Demo accounts: `LOGIN_ACCOUNTS.txt`.

## Request flow (UC018 → UC025 → UC003 → UC026)

```
With Driver      Pending ─HoD─► Approved by HoD ─Assistant─► Driver Assigned ─Head of Admin─► Approved ─► Completed
                                                              ◄── rejected assignment (reason) ──┘
Pay and Claim /  Pending ─HoD─► Approved by HoD ─Head of Admin─► Approved
Self Drive
Personal (lorry) Pending ─Assistant (driver, or confirm charge)─► Driver Assigned / ─Head of Admin─► Approved
Pending ─► Rejected (reason) | Lapsed (no HoD decision in 2 days)      any active status ─► Cancelled (reason)
```

- **Check availability first**: departure + return date/time, then each vehicle type (car, MPV, van, bus, lorry) with the units left.
- **Reservation on submit** (first submitted, first served): one `vehicle_reservation` row per unit of the type, from the departure until 1 hour after the return; the Assistant picks the actual vehicle at assignment (UC003). Released when rejected, cancelled or lapsed. Pay and Claim reserves nothing. Missing buses are reserved as vendor buses (vendor name and plate recorded at assignment).
- **Purpose type**: Official / Unofficial need the supporting letter (PDF/JPG/PNG, 5 MB); Personal (lorry only) needs the round-trip distance, charged RM3/km, and goes to the Assistant instead of the HoD.
- **Urgent**: departure less than 2 days away; shown at the top of every queue. No paper form.
- **Who can request**: every role (Staff, HoD, Driver, Administrative Assistant, Head of Admin). The Assistant and the drivers belong to BPI, so their requests go to the head of BPI, the Head of Admin; the Head of Admin's own request goes to the head of the parent department (Registrar Office).
- **Approver**: the head of the requester's department (HoD, or the Head of Admin for BPI); a head's own request goes to the head of the parent department.
- **Approval email** on submit (retried hourly if it failed), **one reminder email** after 1 day, **Lapsed** after 2 days.
- **Tasks**: each vehicle has an outbound task at the departure and a return task at the return time, 1 hour each by default (the Assistant can change it for long trips). The driver is free between them.
- **Approval letter**: approved Pay and Claim and Personal requests can be printed / saved as PDF.

## Recommend Driver (UC009)

Only active drivers with a valid (not expired) licence of the class the vehicle needs (D car/MPV/van, GDL lorry, PSV bus),
without a request or internal task in the task period and without an approved schedule change on that date. They are
ranked by a weighted score: tasks this month 50% (fewer is better), weekend tasks this month 30% (fewer is better),
years of service 20% (more is better). Drivers on welfare duty that day are marked. The best 3 are *Recommended*;
choosing another driver shows a warning with the driver's task count and needs a confirmation. Weights are settings.

## Other rules

- **Internal tasks** (Workshop, Refuelling, Test Drive) and **weekly welfare duty** (24 h from 12:00 am, car or van) reserve the vehicle, get a driver from the recommendation, and are approved by the Head of Admin. Past or completed ones cannot be deleted (they count in the task count).
- **Replace Driver** (approved task, not started, reason required) → Head of Admin approves → previous driver, new driver and requester are notified. Every replacement is in the Driver Replacement report.
- **Schedule Change**: a driver gives dates they cannot work (or asks to be removed from a task); once approved they are not recommended on those dates and their tasks appear under *Driver Schedule → Needs replacement*.
- **Task report**: actual times, end mileage (not lower than before), fuel level, condition; a problem (Plate Number, Tyre, Lights, Body Damage, Engine, Others) with description and photo; *cannot be used* sets the vehicle Under Maintenance. The vehicle's mileage and fuel level follow its latest report.
- **Vehicles** and **accounts** are never deleted, only deactivated. A vehicle with upcoming bookings cannot go Under Maintenance or be deactivated until each booking is moved to another vehicle (on the vehicle page). A driver with upcoming tasks or a HoD with pending requests cannot be deactivated.
- **Notifications** are always saved in the system **and** e-mailed (e-mails and WhatsApp messages are logged in `logs/`). Drivers can also get WhatsApp through OpenWA (`docs/OPENWA_SETUP.md`, `config/whatsapp.sample.php`).
- **Reports** (month): Driver Workload, Driver Replacement, Vehicle Usage — on screen, print, PDF, Excel.
- **History**: every status change of a request is stored with the reason, user, date and time (insert-only table `request_status_history`).

## Security

- bcrypt passwords; registration and password reset use a 6-digit code sent to the UIS email (expires after 15 minutes, 5 tries).
- 5 failed sign-ins block the account for 15 minutes (`MAX_FAILED_ATTEMPTS`, `LOCKOUT_MINUTES`); it unblocks by itself.
- Idle logout after 15 minutes (`IDLE_TIMEOUT_MIN`).
- Role check on every page and endpoint, CSRF token on every POST, prepared statements for every query, upload type/size checks.
- `.htaccess` denies web access to `config/`, `includes/`, `cron/`, `tests/`, `tools/` and `*.sql`.

## Install (XAMPP)

1. Copy the folder to `C:\xampp\htdocs\DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR`.
2. Start **Apache** and **MySQL**.
3. Import `database.sql` (phpMyAdmin → Import). It **re-creates** `dsms_db` with the schema, the base data and demo
   data dated relative to the day of the import. A database of an earlier release must be re-imported (the schema changed
   completely); the system shows a notice until it is.
4. Check `config/database.php` (host, user, password, `SITE_URL`), or put them in `config/local.php` (copy `config/local.sample.php`).
5. Open `…/login.php`.

E-mail: copy `config/mail.sample.php` to `config/mail.php` and fill in the SMTP details (PHPMailer). While e-mail is
switched off, notifications still work in the system, and the register / reset pages show the verification code on
screen (test mode) so the system can be tried locally.

**Real server (VPS):** see `docs/VPS_SETUP.md`.

## Background job (hourly)

```
php cron/run_jobs.php        # Windows Task Scheduler: C:\xampp\php\php.exe cron\run_jobs.php, every hour
```

Re-sends failed approval e-mails, sends the one-day reminder, lapses requests, completes finished trips. It also runs on page loads (at most every 5 minutes).

## Settings (`config/settings.php`)

`W_TASKS` 0.5, `W_WEEKEND` 0.3, `W_EXPERIENCE` 0.2 (add up to 1), `RECOMMEND_TOP` 3, `LAPSE_HOURS` 48, `REMINDER_HOURS` 24,
`URGENT_HOURS` 48, `TASK_DURATION_HOURS` 1, `BUFFER_HOURS` 1, `CHARGE_PER_KM` 3.00, `UIS_EMAIL_DOMAINS` uis.edu.my,
`CODE_EXPIRY_MINUTES` 15, `IDLE_TIMEOUT_MIN` 15, `MAX_FAILED_ATTEMPTS` 5, `LOCKOUT_MINUTES` 15.

## Styling

The pages use [Tailwind CSS 4](https://tailwindcss.com) and [DaisyUI 5](https://daisyui.com) components (drawer, menu,
card, stats, table, tabs, modal, badge, alert). There is no Bootstrap and no jQuery.

- **Source:** `assets/css/src/app.css`, with the `uis` DaisyUI theme (UIS green as the one accent colour, a grey
  ramp for surfaces), the IBM Plex fonts and the few app-specific classes (dashboard panels, number plates).
- **Built file:** `assets/css/app.css`, which is committed and linked by every page.
- **Rebuild after changing classes** in a PHP file or the source CSS (Tailwind only keeps classes it finds in the files):
  ```
  npm install
  npm run build:css      # or: npm run watch:css while editing
  ```
- **Dialogs:** `assets/js/ui.js` gives `UIS.confirm()`, `UIS.alert()`, `UIS.askReason()` and `data-confirm="…"` on
  links/buttons, built on DaisyUI's `<dialog class="modal">`.
- **Plates:** print a registration number with `plate($plateNumber)` (PHP), which sets it like a number plate.
- **Design system:** colours, type, components, rules and the pre-delivery checklist are in
  `design-system/uis-driver-management/MASTER.md` (page overrides in `pages/`). Read it before adding a page.
- **Design skills for Claude Code:** `.claude/skills/` holds the [UI/UX Pro Max](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill)
  skills (v2.15.0, MIT, see `.claude/skills/LICENSE-ui-ux-pro-max.txt`), installed with `npx ui-ux-pro-max-cli init --ai claude`.
  Any Claude Code session opened in this project picks them up. Update with `npx ui-ux-pro-max-cli@latest init --ai claude --force`.

## Tests

```
php tests/run.php            # rules + recommender (each test is rolled back; needs the database)
python3 tests/e2e_flow.py    # every use case over HTTP: re-imports the base data, then plays each role (needs php -S and config/local.php, see the file)
```

## Demo data

`database.sql` ends with a generated DEMO DATA section. To rebuild it: import `database.sql` without that section, run
`php tools/seed_demo.php` (creates the demo through the Domain Layer controllers), then `python3 tools/make_demo_sql.py`.

## Folders

```
admin/ staff/ supervisor/ driver/   role pages            register.php forgot_password.php profile.php   account pages
app/domain/                         Domain Layer (controllers and services)
app/data/                           Data Access Layer (one class per table, PDO)
includes/                           ui.php (shared view helpers), sidebar, mailer, WhatsApp, upload, report_export (PDF/Excel)
ajax/get_document.php               access-checked download of the supporting letter (request.supporting_doc)
config/                             database.php (connection, autoload, session + security), settings.php (rules)
logs/                               e-mail and WhatsApp logs (not served)
cron/run_jobs.php                   hourly jobs
tools/                              demo data generator
tests/                              unit tests (php) and the end-to-end flow (python)
```

## Assumptions (where the SRS leaves room)

- A request that is still not approved when its departure time comes also lapses (it can no longer be served).
- The Head of Admin returning a driver assignment sets its tasks to Rejected (kept for the record); the Assistant assigns again. The request keeps its reservation.
- "Recommended" = the best `RECOMMEND_TOP` drivers of the ranked list; drivers failing the UC009 filters can never be chosen.
- A replacement keeps the previous driver on the task until the Head of Admin approves it.
- A welfare duty day runs 24 hours from 12:00 am. Welfare duty does not block a driver from request tasks (it is marked), but a driver with a request or internal task that day is not recommended for the duty.
- Request completion is by time (return passed); a task is completed by its report.
