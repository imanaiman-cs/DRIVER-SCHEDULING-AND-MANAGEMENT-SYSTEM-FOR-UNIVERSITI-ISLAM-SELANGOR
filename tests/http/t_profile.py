"""SRS 10 Nov UC016 Manage Profile (Staff, Driver, Assistant, Head of Admin, Head of Department): view, update name/phone/address, change password."""
import sys
from lib import *

reseed()
cnt = lambda q: int(sql(q))
who = {'stf001': 'Staff', 'drv005': 'Driver', 'admin': 'Administrative Assistant', 'head001': 'Head of Admin', 'hos002': 'Head of Department'}
for u, role in who.items():
    c = user(u); pg = c.get('profile.php')
    ok('%s: My Profile shows staff ID, name, UIS e-mail, phone, address, department and role' % role, pg.status_code == 200 and not errs(pg) and all(x in pg.text for x in ['Staff ID', 'UIS e-mail', 'Address', 'Department']) and role in pg.text and 'My Profile' in pg.text)
drv = user('drv005'); pg = drv.get('profile.php')
ok('a driver also sees the licence class, expiry date and start date of service, read-only', 'Licence class' in pg.text and 'Licence expiry date' in pg.text and 'Start date of service' in pg.text and 'name="license_class' not in drv.get('profile.php', edit=1).text)
ok('the old driver profile link goes to the new page', drv.get('driver/profile.php').headers['Location'].endswith('/profile.php'))
ok('anonymous visitors cannot open it', C().get('profile.php').status_code == 302)
s1 = user('stf001')
ok('Edit shows the name, phone and address only', all(x in s1.get('profile.php', edit=1).text for x in ['name="full_name"', 'name="phone"', 'name="address"']) and 'name="email"' not in s1.get('profile.php', edit=1).text)
r = s1.post('profile.php', {'action': 'update', 'full_name': 'Norliza', 'phone': '', 'address': ''}); ok('an empty field: fill in all required fields (A1.1)', 'fill in all required' in r.text)
r = s1.post('profile.php', {'action': 'update', 'full_name': 'Norliza binti Abdul Rahman', 'phone': 'abc', 'address': 'No 1 Jalan Setia'}); ok('an invalid phone number is refused', 'valid phone' in r.text)
orig = rows("SELECT full_name, email, department, role, username FROM users WHERE username='stf001'")
r = s1.post('profile.php', {'action': 'update', 'full_name': 'Norliza binti Abdul Rahman Jr', 'phone': '019-1234567', 'address': 'No 1 Jalan Setia, 40000 Shah Alam', 'email': 'hacker@evil.com', 'department': 'Rectory', 'role': 'head_admin', 'username': 'x'})
ok('updated: name, phone and address change', r.status_code == 302 and rows("SELECT full_name, phone, address FROM users WHERE username='stf001'") == [['Norliza binti Abdul Rahman Jr', '019-1234567', 'No 1 Jalan Setia, 40000 Shah Alam']])
now_ = rows("SELECT email, department, role, username FROM users WHERE username='stf001'")[0]
ok('the staff ID, e-mail, department and role are unchanged', now_ == [orig[0][1], orig[0][2], orig[0][3], orig[0][4]])
ok('the history log has it and the page shows the new name', cnt("SELECT COUNT(*) FROM audit_log WHERE action='profile_update'") == 1 and 'Jr' in s1.get('profile.php').text)
# driver: the driver record follows; licence fields in the post are ignored
before = rows("SELECT license_class, license_expiry FROM drivers WHERE driver_id=5")[0]
r = drv.post('profile.php', {'action': 'update', 'full_name': 'Siti Norzaharah binti Othman', 'phone': '017-5550000', 'address': 'Shah Alam', 'license_class[]': ['D', 'GDL', 'PSV'], 'license_expiry': '2099-01-01'})
ok('a driver updates the phone and address; the driver record follows; the licence cannot be changed here', r.status_code == 302 and sql("SELECT phone FROM drivers WHERE driver_id=5") == '017-5550000' and rows("SELECT license_class, license_expiry FROM drivers WHERE driver_id=5")[0] == before)
# password
pw = lambda c, **kw: c.post('profile.php', dict({'action': 'password', 'current_password': 'staff001@uis', 'new_password': 'Newpass123', 'confirm_password': 'Newpass123'}, **kw))
r = pw(s1, current_password='wrong'); ok('a wrong current password is refused (A2.1)', 'wrong or the new passwords do not match' in r.text and C().login('stf001', 'staff001@uis').status_code == 302)
r = pw(s1, confirm_password='Different1'); ok('new passwords that differ are refused', 'do not match' in r.text)
r = pw(s1, new_password='abc', confirm_password='abc'); ok('a weak password is refused', 'at least 8' in r.text or 'letters and digits' in r.text)
r = pw(s1, new_password='staff001@uis', confirm_password='staff001@uis'); ok('the same password is refused', 'different' in r.text)
r = pw(s1); ok('changed: the new password works, the old one does not, history without the password', r.status_code == 302 and C().login('stf001', 'Newpass123').status_code == 302 and C().login('stf001', 'staff001@uis').status_code == 200
   and cnt("SELECT COUNT(*) FROM audit_log WHERE action='password_changed'") == 1 and cnt("SELECT COUNT(*) FROM audit_log WHERE reason LIKE '%Newpass123%'") == 0)
ok('POST without CSRF is refused (419)', s1.post('profile.php', {'action': 'update', 'full_name': 'X Y Z', 'phone': '012-1111111', 'address': 'A'}, csrf=False).status_code == 419)
sys.exit(0 if summary() else 1)
