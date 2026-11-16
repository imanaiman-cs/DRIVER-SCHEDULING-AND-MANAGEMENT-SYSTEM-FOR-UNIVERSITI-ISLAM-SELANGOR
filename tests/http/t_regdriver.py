"""SRS 10 Nov UC001 step 3: the Assistant registers a driver; the account gets a default password that is e-mailed."""
import re, sys
from lib import *
reseed()
ad = user('admin'); stf = user('stf001')
cnt = lambda q: int(sql(q))
base = {'mode': 'register', 'username': 'DRV900', 'full_name': 'Hafiz bin Ramli', 'email': 'hafiz@uis.edu.my', 'phone': '012-9876543', 'license_class[]': ['D'], 'license_expiry': '2030-01-01', 'date_joined': '2024-05-01', 'license_number': 'D123', 'address': 'Bangi'}
r = stf.get('admin/add_driver.php'); ok('staff cannot open Register Driver (role check)', r.status_code in (302, 403))
r = ad.get('admin/add_driver.php'); ok('the form asks for staff ID, UIS e-mail, licence expiry and start date', r.status_code == 200 and 'Register Driver' in r.text and 'name="username"' in r.text and 'name="date_joined"' in r.text and not errs(r))
n0 = cnt("SELECT COUNT(*) FROM drivers")
r = ad.post('admin/add_driver.php', dict(base, full_name='')); ok('3.2: an empty required field is refused', cnt("SELECT COUNT(*) FROM drivers") == n0)
r = ad.post('admin/add_driver.php', dict(base, **{'license_expiry': ''})); ok('3.2: the licence expiry date is required', cnt("SELECT COUNT(*) FROM drivers") == n0)
r = ad.post('admin/add_driver.php', dict(base, email='hafiz@gmail.com')); ok('only a UIS e-mail is accepted', cnt("SELECT COUNT(*) FROM drivers") == n0 and 'UIS e-mail' in r.text)
r = ad.post('admin/add_driver.php', dict(base, username='drv001')); ok('3.1: a staff ID that is already registered is refused', cnt("SELECT COUNT(*) FROM drivers") == n0 and 'already' in r.text)
r = ad.post('admin/add_driver.php', base, csrf=False); ok('no CSRF token: 419', r.status_code == 419)
r = ad.post('admin/add_driver.php', base); ok('registered: driver record + Driver account linked', r.status_code == 302 and rows("SELECT d.name, d.license_class, d.date_joined, u.role, u.is_active, u.driver_id = d.driver_id FROM users u JOIN drivers d ON d.driver_id = u.driver_id WHERE u.username='DRV900'") == [['Hafiz bin Ramli', 'D', '2024-05-01', 'driver', '1', '1']])
body = sql("SELECT body_html FROM email_log WHERE to_email='hafiz@uis.edu.my' ORDER BY 1 DESC LIMIT 1")
pw = re.search(r'Default password: <strong>([A-Za-z0-9]+)', body)
ok('the login details are e-mailed (logged) with a default password', pw is not None and 'DRV900' in body)
from lib import C
c = C(); r = c.login('DRV900', pw.group(1) if pw else 'x'); ok('the driver can sign in with the default password', r.status_code == 302 and 'driver' in r.headers.get('Location', ''))
r = c.get('driver/schedules.php'); ok('and reaches My Tasks', r.status_code == 200)
ok('the stored password is hashed, not the default text', pw is not None and sql("SELECT password FROM users WHERE username='DRV900'") != pw.group(1))
sys.exit(0 if summary() else 1)
