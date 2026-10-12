# DSMS - Driver Scheduling and Management System

Final Year Project for the Vehicle Section, Universiti Islam Selangor (UIS).
Plain PHP 8 (simple MVC), PDO + MySQL/MariaDB, Bootstrap 5, vanilla JavaScript.

> **Build status: Step 1 of 9 - project skeleton, router, layout, database and demo data.**
> Login and the real features arrive in the next steps.

## Run it on XAMPP

1. Install XAMPP (PHP 8.x) and start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Put this `dsms` folder in `C:\xampp\htdocs\` (so it is `C:\xampp\htdocs\dsms`).
3. Open **http://localhost/phpmyadmin** and import, in this order:
   1. `database/dsms.sql` (creates the `dsms` database and all tables)
   2. `database/seed.sql` (demo data)
4. Open **http://localhost/dsms/** - you are sent to the system check page.
5. If your MySQL has a password, create `app/config/database.local.php`:
   ```php
   <?php return ['user' => 'root', 'pass' => 'your-password'];
   ```

## Folder structure

```
dsms/
  public/      the only folder the browser should reach (index.php = front controller)
  app/         config, core (Router, Auth, Csrf...), controllers, models, services, views
  database/    dsms.sql (structure) + seed.sql (demo data)
  cron/        run_jobs.php (added in Step 5)
  storage/     uploads (documents) and logs - never directly reachable from the web
  tests/       plain PHP test scripts (added in Steps 4-6)
```

## How a request flows (MVC in one paragraph)

The browser asks for `index.php?page=requests/view&id=5`. `public/index.php` loads
`app/bootstrap.php`, then the `Router` calls `RequestsController::view()`. The controller
checks the user's role, asks a **model** for data (every query is a PDO prepared statement)
and passes it to a **view** (a PHP template inside `layouts/main.php`).

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
`seed.sql`, so re-import it (after `dsms.sql`) to refresh the demo.

## Composer (needed from Step 5)

PHPMailer (email) and Dompdf (PDF report) are installed with Composer:

```
cd dsms
composer install
```
