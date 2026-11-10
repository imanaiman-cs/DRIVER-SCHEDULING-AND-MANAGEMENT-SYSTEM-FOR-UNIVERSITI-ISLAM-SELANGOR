# HTTP regression suites

End-to-end checks that drive the real pages with `requests` (login, CSRF, forms, AJAX, PDF/Excel exports).
They need Python 3 with `requests`, PHP 8 and MySQL/MariaDB. Use a **private database and server** so demo data can be reseeded freely:

```
mysql -uroot -e "CREATE DATABASE dsms_test"
cd tests/http
UIST_DB=dsms_test python3 -c "import lib; lib.reseed()"        # loads ../../database.sql into dsms_test
DSMS_DB=dsms_test php -S 127.0.0.1:8201 -t ../.. &              # server on the private database
UIST_BASE=http://127.0.0.1:8201 UIST_DB=dsms_test python3 t_bc.py
```

| Script | Covers |
|---|---|
| `t_bc.py` | SRS B + C: request form rules, vehicle lock, cancel, HoD approval, reminders |
| `t_vendor.py` | UC017 A2: vendor bus instead of a UIS PSV driver, approval, UIS bus released when Confirmed |
| `t_change.py` | UC029 / UC030: schedule change requests, approval, unavailable dates, Replace Driver as Driver Request |
| `t_multi.py` | UC003 A1: a request for several vehicles (a vehicle and a driver for each, with and without driver) |
| `t_report.py` | UC024: the driver's task report (create, view, update, problem, vehicle updated, internal task) |
| `t_reset.py` | UC015 Reset Password with a verification code |
| `t_profile.py` | UC016 My Profile for every role: view, update, change password |
| `t_paper.py` | UC010 A4: Record Paper Form (Approved, vehicle lock, no UC011/UC012, < 1 day allowed, validations) |
| `t_rental.py` | Personal Rental (lorry, RM3/km, Assistant then Head of Admin) and the approval letters |
| `t_reserve.py` | Reservation by vehicle type and quantity, the Assistant chooses the vehicle, release on cancel/reject |
| `t_purpose.py` | Purpose type, required supporting letter, vehicle type MPV |
| `t_users.py` | UC013/UC014 Register Account with a verification code, UC001 user accounts (add, edit, reset password, deactivate), one Head of Department per PTJ |
| `t_lang.py` | English / Bahasa Melayu switch: pages, JSON, forms/CSRF, cookie + account, login page, e-mail language |
| `t_joined.py` | UC001 / UC003 A1 / UC018: date joined (Add / Edit Driver), experience, the three recommender weights in Settings |
| `t_nodriver.py` | UC010 A3: request without driver, send to Head of Admin, approve / reject, jobs |
| `t_d.py` | SRS D: recommender, assignment, Head of Admin approval, replace driver |
| `agentv_test.py`, `agents_test.py` | SRS E–H: vehicles, drivers, internal tasks (Waiting for Driver, assign, Head of Admin approval), weekly welfare duty (create, assign, approve), calendar, driver view, reports, notifications |
| `t_nfr.py` | SRS J: role checks on every page, CSRF, idle timeout (waits ~62 s), upload rules, response times |
| `t_dash.py` | Dashboards of every role: numbers match the database, scope per role, landing page after login |
| `pdfcheck.py` | helper that validates generated PDF files |

Each script prints `[passed, failed]` at the end. `UIST_ROOT` overrides the project folder; `UIST_PHPLOG` is the PHP server log read by `agentv_test.py`.
Unit tests that run inside PHP are in the parent folder: `php tests/run.php`.
