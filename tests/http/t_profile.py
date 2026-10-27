"""UC002 My Profile and UC003 Update Driver Profile (by the driver, and by the Assistant: A1)."""
import re, sys, datetime
from lib import *

reseed()
drv = user('drv005'); drv2 = user('drv002'); s1 = user('stf001'); ad = user('admin'); hd = user('head001')
cnt = lambda q: int(sql(q))
did = sql("SELECT driver_id FROM drivers WHERE employee_id=(SELECT employee_id FROM drivers d JOIN users u ON u.driver_id=d.driver_id WHERE u.username='drv005')")
row = lambda: rows("SELECT license_class, phone, license_expiry FROM drivers WHERE driver_id=%s" % did)[0]
future = (today() + datetime.timedelta(days=400)).isoformat(); past = (today() - datetime.timedelta(days=2)).isoformat()

# UC002 view
pg = drv.get('driver/profile.php')
ok('My Profile shows name, staff ID, phone, licence classes and expiry', pg.status_code == 200 and not errs(pg) and 'Staff ID' in pg.text and 'Licence expiry date' in pg.text and 'Siti Norzaharah' in pg.text)
ok('the driver menu has My Profile', 'My Profile' in pg.text)
ok('other roles cannot open the driver profile page', s1.get('driver/profile.php').status_code == 302 and ad.get('driver/profile.php').status_code == 302)
ok('a driver sees only their own profile (not the other driver)', 'Siti Norzaharah' in pg.text and 'Mohd Hafizuddin' not in pg.text)
ok('Update opens the form with only phone, licence classes and expiry editable', 'name="license_class[]"' in drv.get('driver/profile.php', edit=1).text and 'name="license_number"' not in drv.get('driver/profile.php', edit=1).text)

# UC003 normal flow
before = row()
n0 = cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin'")
r = drv.post('driver/profile.php', {'phone': '019-9998888', 'license_class[]': ['D', 'PSV'], 'license_expiry': future})
now_ = row()
ok('saved: phone, classes and expiry updated', r.status_code == 302 and now_ == ['D,PSV', '019-9998888', future])
ok('the Assistant is told in the system that the licence classes changed', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin'") == n0 + 1)
ok('the change is recorded in the history log', cnt("SELECT COUNT(*) FROM audit_log WHERE action='driver_update' AND reason LIKE '%%Siti Norzaharah%%their own profile%%'") == 1)
n1 = cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin'")
drv.post('driver/profile.php', {'phone': '019-1112222', 'license_class[]': ['D', 'PSV'], 'license_expiry': future})
ok('phone only: saved, no notification to the Assistant (classes unchanged)', row()[1] == '019-1112222' and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin'") == n1)

# exceptions: nothing saved
snap = row()
r = drv.post('driver/profile.php', {'phone': '019-1112222', 'license_expiry': future}); ok('no licence class selected: error, nothing saved', r.status_code == 200 and 'at least one licence class' in r.text and row() == snap)
r = drv.post('driver/profile.php', {'phone': '019-1112222', 'license_class[]': ['D'], 'license_expiry': past}); ok('licence expiry already passed: error, nothing saved', r.status_code == 200 and 'already passed' in r.text and row() == snap)
r = drv.post('driver/profile.php', {'phone': 'abc', 'license_class[]': ['D'], 'license_expiry': future}); ok('invalid phone: error, nothing saved', r.status_code == 200 and row() == snap)
r = drv.post('driver/profile.php', {'phone': '019-1112222', 'license_class[]': ['D', 'XYZ'], 'license_expiry': future}); ok('an invented licence class is dropped, valid ones saved', row()[0] == 'D')
r = drv.post('driver/profile.php', {'phone': '019-1112222', 'license_class[]': ['D', 'PSV'], 'license_expiry': future}, csrf=False); ok('POST without a CSRF token is refused (419)', r.status_code == 419)
ok('Cancel does not change anything', drv.get('driver/profile.php').status_code == 200)
# a driver cannot change someone else (the page has no id parameter; posting one is ignored)
other = rows("SELECT license_class FROM drivers WHERE driver_id<>%s ORDER BY driver_id" % did)
drv.post('driver/profile.php', {'driver_id': 1, 'id': 1, 'phone': '019-0000000', 'license_class[]': ['D', 'PSV'], 'license_expiry': future})
ok('posting another driver id changes only the signed-in driver', rows("SELECT license_class FROM drivers WHERE driver_id<>%s ORDER BY driver_id" % did) == other)

# the recommender follows the licence classes: without PSV the driver is no longer offered for a bus
sql("UPDATE drivers SET license_class='D' WHERE driver_id=%s" % did)
# UC003 A1: the Assistant updates a driver and the driver is told
n2 = cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='drv005'")
r = ad.post('admin/edit_driver.php', {'id': did, 'phone': '019-5554444', 'license_class[]': ['D', 'GDL'], 'license_expiry': future, 'license_number': 'L123', 'address': 'Shah Alam'})
ok('A1: the Assistant updates the driver', r.status_code == 302 and row()[:2] == ['D,GDL', '019-5554444'])
ok('A1: the driver is told in the system', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='drv005'") == n2 + 1)
r = ad.post('admin/edit_driver.php', {'id': did, 'phone': '019-5554444', 'license_class[]': ['D'], 'license_expiry': past})
ok('A1: an expired licence date is refused for the Assistant too', r.status_code == 200 and row()[2] == future)
r = hd.post('admin/edit_driver.php', {'id': did, 'phone': '019-6667777', 'license_class[]': ['D'], 'license_expiry': future})
ok('the Head of Admin can update too', r.status_code == 302 and row()[1] == '019-6667777')
ok('staff cannot use the admin edit page', s1.get('admin/edit_driver.php', id=did).status_code == 302)
# UC002 A1: list and profile of all drivers for the Assistant
lst = ad.get('admin/drivers.php'); vw = ad.get('admin/view_driver.php', id=did)
ok('UC002 A1: the Assistant sees all drivers with licence classes and tasks this month, and a driver profile with upcoming tasks', 'Tasks this month' in lst.text and vw.status_code == 200 and 'Upcoming tasks' in vw.text)
sys.exit(0 if summary() else 1)
