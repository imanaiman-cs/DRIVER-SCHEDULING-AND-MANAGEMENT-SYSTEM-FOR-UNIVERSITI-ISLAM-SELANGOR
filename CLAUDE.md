# CLAUDE.md — DSMS (Driver Scheduling and Management System, UIS Vehicle Section)

Final year project (UiTM) by Muhammad Nur Iman Aiman. The requirements are in **`docs/DSMS_Use_Case_Description_14Nov.pdf`**
(Use Case Descriptions, revised 14 November 2026, UC001–UC028 with sub use cases). It is the source of truth and replaces
`docs/SRS.md` (v1.1) and `docs/SRS_dev_10Nov.md`, which are kept only as history. Read the matching use case (normal flow, alternatives,
exceptions, notes) before changing code, and follow it step by step. Anything the use cases do not describe is out of scope.

`docs/UC_14Nov_GAPS.md` lists what was changed for the 14 Nov revision and what is still open.
`docs/SRS_ALIGNMENT_PLAN.md` describes the earlier alignment work and the shared API; it is still valid for the API.

## Stack and running

- Plain PHP 8 + MySQL/MariaDB (mysqli), Bootstrap 5, XAMPP on Windows. No framework, no Composer, no build step. Keep it that way.
- Database `uis_driver_db`: full schema + demo data in `database.sql`; changes for existing databases go in `migrations/`.
- App URL: `http://localhost/DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR/login.php`. Demo accounts: `LOGIN_ACCOUNTS.txt`.
- Hourly jobs: `php cron/run_jobs.php` (also run lightly on page load).
- Tests: `php tests/run.php` (rolled back, needs the database). HTTP suites: `tests/http/`.

## Roles (SRS name → stored key)

| SRS role | Key | Notes |
|---|---|---|
| Staff | `staff` | Every role is also Staff and can request a vehicle |
| Driver | `driver` | Smartphone users; keep their pages very simple |
| Head of Department | `supervisor` | Approves requests of their own PTJ only |
| Administrative Assistant | `admin` | Main user |
| Head of Admin | `head_admin` | Everything the Assistant can do + approves driver assignments and schedule changes |

## Rules that must not break

- Prepared statements for every query; CSRF token on every POST; role check on every page and AJAX endpoint.
- Use the shared helpers in `includes/workflow.php`, `includes/recommender.php` and `config/database.php` (listed in
  `docs/SRS_ALIGNMENT_PLAN.md`). Do not write a second version of an existing helper.
- Status changes go through `changeRequestStatus()`; notifications through `notifyUsers()` (in-system + email, always).
- Vehicle lock per time period, overlap rule `start < other_end AND end > other_start`, taken inside a transaction with `SELECT … FOR UPDATE`.
- Request timing: less than 1 day before the trip → rejected (paper form BPTK/BPI/2025/PIN.5); less than 2 days → warning.
  Pending after 1 day → automatic reminder email to the HoD (UC012); pending after 2 days → Lapsed, lock released.
- Drivers and vehicles are deactivated, never deleted. Every approval, rejection, cancellation, assignment and replacement is
  recorded with user, time and reason (`auditLog()`), and these records are never edited.
- Uploads: PDF/JPG/PNG only, max 5 MB.
- New pages use `includes/admin_page.php` (`adminPageStart()` / `adminPageEnd()`) and are added to `includes/sidebar.php` for the right roles.

## How to work in this repo

- One use case (or one gap item) per change. Mention the UC number in the commit message, e.g. `UC029 Request Schedule Change`.
- Database change → update `database.sql` **and** add a new file in `migrations/` (never edit an old migration).
- After each change: run `php tests/run.php`, add a test for the new rule, and check the use case exceptions by hand.
- Update `README.md` when a business rule changes.
- If the SRS and the real procedure seem to disagree, do not guess: stop and write the question down for Iman.
- Interface text is in English, short and plain.
