# DSMS - Driver Scheduling and Management System

Final Year Project for the Vehicle Section, Universiti Islam Selangor (UIS).
Plain PHP 8 (simple MVC, no framework), PDO prepared statements, MySQL/MariaDB, Bootstrap 5,
FullCalendar, vanilla JavaScript, Composer (PHPMailer, Dompdf). Runs on XAMPP.

## What it does

Staff request a vehicle for one trip. Their head of department (HOD) approves. The Vehicle Section
then picks a driver for each leg of the trip with the help of a fair, explainable recommendation,
the head admin confirms, and the driver sees the job on a phone-friendly page.

**Roles** (every role is also a normal staff member who can request a vehicle):
`staff`, `driver`, `hod`, `admin_assistant`, `head_admin` (the last two together are the "Vehicle Section").

**Request status flow**

```
Pending Approval -> Approved        -> Driver Assigned -> Confirmed -> Completed
Pending Approval -> Rejected  (by the approver, with a reason)
Pending Approval -> Lapsed    (not approved within 48 hours)
Approved         -> Confirmed (self-drive: the requester drives, no driver needed)
Any active status -> Cancelled (a reason is required, only before departure)
```

## The business rules (where each one lives in the code)

| Rule | What it means | Code |
|---|---|---|
| R1 | Two-day rule: request at least 2 days ahead, otherwise use paper form BPTK/BPI/2025/PIN.5 | `RequestRules::leadTimeProblem` |
| R2 | One request = one trip = one vehicle, one departure and one return | `TripRequest::create`, `RequestRules::tripProblem` |
| R3 | The vehicle is held per calendar day; the database refuses a double booking | `vehicle_day_holds` `UNIQUE(vehicle_id, hold_date)`, `AvailabilityService::placeHolds` |
| R4 | A vehicle must be in service, not in maintenance, road tax valid, enough seats | `AvailabilityService::vehiclesForPeriod` |
| R5 | A driver clash in a time slot is only a **warning**; a wrong licence or leave is **blocked** | `AvailabilityService::driverClashes`, `AssignmentService::check` |
| R6 | A driver is chosen **per leg** (departure leg, return leg) | `request_legs`, `AssignmentService::assign` |
| R7 | Every driver change is logged with the reason | `driver_replacements`, `AssignmentService::changeDriver` |
| R8 | Management drivers are never used for normal requests | `DriverRecommender::recommend` |
| R9 | Comments cannot be edited or deleted | no edit/delete code + database triggers |
| R10 | The audit log is insert-only | `AuditLog` (no update/delete) + database triggers |
| R11 | Pending requests lapse after 48 h; confirmed trips become Completed automatically | `Jobs`, `cron/run_jobs.php` (also runs lightly on page loads) |

## The driver recommendation (how the score works)

For one trip leg, drivers are first **filtered** (active, carpool group, correct licence, not on leave),
then each remaining driver gets a score from 0 to 100 (higher = better choice):

```
score = 100 - ( W_TASKS * normTasks + W_AFTER_HOURS * normAfterHours ) * 100 - welfarePenalty
```

* `tasks` = trip legs + internal tasks the driver already has in that month
* `afterHours` = how many of those are on Saturday/Sunday, start before 08:00 or end after 17:30
* `normX` = the driver's number divided by the largest number among the candidates (0 to 1)
* `welfarePenalty` = 20 points if the driver is on welfare duty that day
* Defaults: `W_TASKS = 0.6`, `W_AFTER_HOURS = 0.4`, `WELFARE_PENALTY = 20`. They are in the `settings` table
  and can be changed on the **Settings** page (the two weights must add up to 1).

Worked example: a driver with half the maximum tasks (0.5) and a quarter of the maximum after-hours work (0.25):
`100 - (0.6*0.5 + 0.4*0.25)*100 = 60`. In plain words: *the driver with the fewest jobs this month scores highest,
and evenings and weekends count extra, so the work is shared fairly.* A clash is only a warning (R5), so drivers with a
clash are listed after the free ones. The code is `app/services/DriverRecommender.php`; the formula is a pure function,
covered by `tests/DriverRecommenderTest.php`.

## Run it on XAMPP

1. Install XAMPP (PHP 8.x) and start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Put this `dsms` folder in `C:\xampp\htdocs\` (so it is `C:\xampp\htdocs\dsms`).
3. Open **http://localhost/phpmyadmin** and import, in this order:
   1. `database/dsms.sql` (creates the `dsms` database and all tables)
   2. `database/seed.sql` (demo data)
4. In a terminal: `cd C:\xampp\htdocs\dsms` then `composer install` (PHPMailer and Dompdf).
5. Open **http://localhost/dsms/** and log in (see the accounts below).
6. If your MySQL has a password, create `app/config/database.local.php`:
   ```php
   <?php return ['user' => 'root', 'pass' => 'your-password'];
   ```
7. For a real server set `'debug' => false` in `app/config/config.local.php` (see "E-mail" below for that file).

## Folder structure

```
dsms/
  public/      the only folder the browser should reach (index.php = front controller, assets/)
  app/
    config/      config.php, database.php (override with *.local.php, which Git ignores)
    core/        Router, Auth, Session, Csrf, Controller, Model, View, Database, helpers
    controllers/ one class per area; one public method = one page/action
    models/      database access (PDO prepared statements only)
    services/    business rules: RequestRules, RequestStatus, AvailabilityService, DriverRecommender,
                 AssignmentService, Jobs, NotificationService, MailService, FileUpload, RequestPolicy
    views/       PHP templates (layouts/main.php wraps every page)
  database/    dsms.sql (structure), seed.sql (demo data), backup.php
  cron/        run_jobs.php (scheduled housekeeping)
  storage/     uploads, logs, backups - never directly reachable from the web
  tests/       plain PHP tests:  php tests/run.php
  docs/        TEST_CHECKLIST.md (manual test list for every step)
```

## How a request flows (MVC in one paragraph)

The browser asks for `index.php?page=requests/show&id=5`. `public/index.php` loads `app/bootstrap.php`,
then the `Router` calls `RequestsController::show()` (only methods declared in that controller are reachable).
The controller checks the user's role, asks a **model** for data (every query is a PDO prepared statement),
and passes it to a **view** (a PHP template inside `layouts/main.php`). Business rules live in **services**, so the
same rule is used by every screen.

## Security summary

* **Role check on every action** (`Auth::requireRole`, `adminOnly`, `RequestPolicy`); the menu only hides links, the server decides.
* **CSRF token** on every state-changing POST (`Controller::requirePost`); logout is POST only.
* **XSS**: every output goes through `e()` (htmlspecialchars); the calendar and the confirm dialog insert text, never HTML.
* **SQL injection**: PDO prepared statements, emulation off.
* **Passwords**: bcrypt hashes, 8+ characters with a letter and a number, temporary passwords must be changed at first login.
* **Lockout** after 5 wrong passwords (an admin unlocks); generic login error message; session id renewed at login.
* **Session** cookie HttpOnly + SameSite, 15-minute inactivity timeout.
* **Uploads**: PDF/JPG/PNG only, size limit, real content checked, random file name, stored outside `public/`, downloaded only through a permission check.
* Security headers (`X-Frame-Options`, `nosniff`, `Referrer-Policy`), audit log for important actions.

## Test accounts

Password for **every** account: `Password123`

| Username | Role |
|---|---|
| staff1, staff2 | staff (approver: hod1) |
| hod1 | hod (approver: hod2) |
| hod2 | hod |
| admin1 | admin_assistant |
| headadmin1 | head_admin |
| driver1 ... driver16 | driver (1-12 carpool, 13-16 management) |

All names in the seed data are dummy. Demo dates are relative to the day you import
`seed.sql`, so re-import it (after `dsms.sql`) to refresh the demo. **Change these passwords before any real use.**

## Tests

```
php tests/run.php              # all: rules R1-R6, availability, status map, recommender score and filters, policies
php tests/run.php Recommender  # only the files whose name contains "Recommender"
```

The database tests run inside a transaction that is rolled back, so your data is not changed.
`docs/TEST_CHECKLIST.md` lists what to click through by hand for each build step.

## Backup

`php database/backup.php` writes `storage/backups/dsms_<date>.sql` (tables, data and the insert-only triggers).
Restore by importing that file in phpMyAdmin or with `mysql -u root < file.sql`.

## Known limits

* Notification e-mails need an SMTP account (otherwise they are written to `storage/logs/mail.log`).
* The calendar loads FullCalendar from a CDN, so it needs an internet connection.
* Uploads are limited by PHP too: raise `upload_max_filesize` / `post_max_size` in `php.ini` if you increase "Max upload size".

## Composer (details)

PHPMailer (email) and Dompdf (PDF report) are installed with Composer:

```
cd dsms
composer install
```

## E-mail (optional)

By default **no real e-mail is sent**: every notification e-mail is written to `storage/logs/mail.log`
so the demo works without a mail server. To send real e-mail create `app/config/config.local.php`
(it is ignored by Git, so your password is never committed):

```php
<?php
return [
    'app_url' => 'http://localhost/dsms/public',
    'mail' => [
        'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
        'username' => 'your.address@gmail.com', 'password' => 'your-app-password',
        'from_email' => 'your.address@gmail.com',
    ],
];
```

## Scheduled jobs (rule R11)

`cron/run_jobs.php` (1) lapses requests that were not approved in 48 hours, (2) cancels approved trips
that were never confirmed before they started, (3) marks confirmed trips as Completed after their return time, and
(4) sends the approver one automatic reminder after 24 hours (`REMINDER_AFTER_HOURS` in Settings).
The web pages also run the same jobs at most once every 5 minutes, so the demo works even without a scheduler.
For a real deployment run the script every 15 minutes.

**Windows (XAMPP) - Task Scheduler**

1. Open *Task Scheduler* -> *Create Basic Task* -> name it `DSMS jobs`.
2. Trigger: *Daily*, then in the task's *Triggers* tab choose *Repeat task every 15 minutes*.
3. Action: *Start a program*
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\dsms\cron\run_jobs.php`
4. Test it by hand: `C:\xampp\php\php.exe C:\xampp\htdocs\dsms\cron\run_jobs.php` prints e.g. `lapsed=1 expired=0 completed=2 reminded=1`.

**Linux** - add this line with `crontab -e`:  `*/15 * * * * php /var/www/dsms/cron/run_jobs.php`
