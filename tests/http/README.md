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
| `t_d.py` | SRS D: recommender, assignment, Head of Admin approval, replace driver |
| `agentv_test.py`, `agents_test.py` | SRS E–H: vehicles, drivers, internal tasks, welfare, calendar, driver view, reports, notifications |
| `t_nfr.py` | SRS J: role checks on every page, CSRF, idle timeout (waits ~62 s), upload rules, response times |
| `pdfcheck.py` | helper that validates generated PDF files |

Each script prints `[passed, failed]` at the end. `UIST_ROOT` overrides the project folder; `UIST_PHPLOG` is the PHP server log read by `agentv_test.py`.
Unit tests that run inside PHP are in the parent folder: `php tests/run.php`.
