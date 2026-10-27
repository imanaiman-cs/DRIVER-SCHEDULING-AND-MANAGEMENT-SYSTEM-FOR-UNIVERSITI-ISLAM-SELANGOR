"""UC007 A1 - the driver updates mileage, fuel level and condition of a vehicle used for their task."""
import re, sys
from lib import *

reseed()
# drv003 (driver of request #3's tasks after it is confirmed) and drv001 (driver of the confirmed request #4)
ad = user('admin'); hd = user('head001'); s1 = user('stf001')
cnt = lambda q: int(sql(q))
# a confirmed trip task of drv001
row = rows("SELECT s.schedule_id, s.vehicle_id FROM schedules s JOIN drivers d ON d.driver_id=s.driver_id JOIN users u ON u.driver_id=d.driver_id JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE u.username='drv001' AND vr.status='processed' AND s.status<>'cancelled' ORDER BY s.schedule_id LIMIT 1")[0]
sid, vid = int(row[0]), int(row[1])
drv = user('drv001'); drv2 = user('drv002'); drv3 = user('drv003')
plate = sql("SELECT plate_number FROM vehicles WHERE vehicle_id=%d" % vid)
last0 = cnt("SELECT mileage FROM vehicles WHERE vehicle_id=%d" % vid)
miles = last0 + 500
n_admin = lambda: cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin'")

# the button is on the task
pg = drv.get('driver/schedules.php')
ok('My Tasks shows "Update Vehicle" on the task with a vehicle', 'update_vehicle.php?trip=%d' % sid in pg.text)
fm = drv.get('driver/update_vehicle.php', trip=sid)
ok('the form shows ONLY mileage, fuel level and condition', fm.status_code == 200 and not errs(fm) and 'name="mileage"' in fm.text and 'name="fuel_level"' in fm.text and 'name="condition_issue"' in fm.text and 'plate_number' not in fm.text and 'name="capacity"' not in fm.text and plate in fm.text)

# only the vehicle of one of THEIR tasks
ok('another driver cannot open it for this task', drv2.get('driver/update_vehicle.php', trip=sid).status_code == 302)
ok('staff and the Assistant cannot open it', s1.get('driver/update_vehicle.php', trip=sid).status_code == 302 and ad.get('driver/update_vehicle.php', trip=sid).status_code == 302)
r = drv2.post('driver/update_vehicle.php', {'trip': sid, 'mileage': miles, 'fuel_level': 'full'})
ok('another driver cannot save it either', cnt("SELECT COUNT(*) FROM vehicle_usage_log") == 0 and cnt("SELECT mileage FROM vehicles WHERE vehicle_id=%d" % vid) == last0)
ok('a task that does not exist is refused', drv.get('driver/update_vehicle.php', trip=999999).status_code == 302)

# normal flow
a0 = n_admin()
r = drv.post('driver/update_vehicle.php', {'trip': sid, 'mileage': miles, 'fuel_level': 'half', 'condition_issue': ''})
ok('saved: back to My Tasks', r.status_code == 302)
log = rows("SELECT vehicle_id, driver_id, schedule_id, mileage, fuel_level, condition_issue, recorded_at FROM vehicle_usage_log")
ok('the update is saved with vehicle, driver, mileage, fuel level and time', len(log) == 1 and int(log[0][0]) == vid and int(log[0][3]) == miles and log[0][4] == 'half' and log[0][5] == 'NULL' and int(log[0][2]) == sid and log[0][6] != 'NULL')
ok('it is the driver of the task who made it', sql("SELECT d.name FROM vehicle_usage_log l JOIN drivers d ON d.driver_id=l.driver_id") == sql("SELECT d.name FROM drivers d JOIN users u ON u.driver_id=d.driver_id WHERE u.username='drv001'"))
ok('the vehicle mileage is updated', cnt("SELECT mileage FROM vehicles WHERE vehicle_id=%d" % vid) == miles)
ok('no condition issue: the Assistant is not notified', n_admin() == a0)

# exceptions
r = drv.post('driver/update_vehicle.php', {'trip': sid, 'mileage': miles - 1, 'fuel_level': 'full'})
ok('mileage lower than the last recorded mileage: error naming the last value, nothing saved', r.status_code == 200 and 'lower than the last recorded mileage (%d km)' % miles in r.text and cnt("SELECT COUNT(*) FROM vehicle_usage_log") == 1)
ok('the same mileage is accepted (not lower)', drv.post('driver/update_vehicle.php', {'trip': sid, 'mileage': miles, 'fuel_level': 'quarter'}).status_code == 302 and cnt("SELECT COUNT(*) FROM vehicle_usage_log") == 2)
for name, d in {'empty mileage': {'mileage': '', 'fuel_level': 'full'}, 'text mileage': {'mileage': 'abc', 'fuel_level': 'full'}, 'decimal mileage': {'mileage': '12.5', 'fuel_level': 'full'},
                'negative mileage': {'mileage': '-5', 'fuel_level': 'full'}, 'no fuel level': {'mileage': miles + 10, 'fuel_level': ''}, 'invented fuel level': {'mileage': miles + 10, 'fuel_level': 'rocket'},
                'issue too long': {'mileage': miles + 10, 'fuel_level': 'full', 'condition_issue': 'x' * 501}}.items():
    r = drv.post('driver/update_vehicle.php', dict(d, trip=sid)); ok('refused: %s' % name, r.status_code == 200 and cnt("SELECT COUNT(*) FROM vehicle_usage_log") == 2)
r = drv.post('driver/update_vehicle.php', {'trip': sid, 'mileage': miles + 5, 'fuel_level': 'full'}, csrf=False); ok('POST without a CSRF token is refused (419)', r.status_code == 419 and cnt("SELECT COUNT(*) FROM vehicle_usage_log") == 2)

# condition issue -> the Assistant is told
a1 = n_admin()
r = drv.post('driver/update_vehicle.php', {'trip': sid, 'mileage': miles + 20, 'fuel_level': 'three_quarters', 'condition_issue': 'Engine warning light is on'})
ok('condition issue saved', r.status_code == 302 and cnt("SELECT COUNT(*) FROM vehicle_usage_log WHERE condition_issue='Engine warning light is on'") == 1)
ok('the Administrative Assistant is told about the condition issue', n_admin() == a1 + 1 and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%Engine warning light%%%s%%'" % plate) in (0, 1) and cnt("SELECT COUNT(*) FROM notifications WHERE message LIKE '%%reported a condition issue on %s%%'" % plate) == 1)
ok('the Head of Admin is not the one notified (the Assistant is)', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='head001' AND n.message LIKE '%%condition issue%%'") == 0)

# the Assistant sees the mileage history
vv = ad.get('admin/view_vehicle.php', id=vid)
ok('the Assistant sees the usage updates with the driver name and the issue', vv.status_code == 200 and 'Usage updates by drivers' in vv.text and 'Engine warning light is on' in vv.text and 'Ahmad Faizal' in vv.text and not errs(vv))

# a trip of a request that is not Confirmed cannot be updated: use request #3 (Driver Assigned) tasks
r3 = rows("SELECT s.schedule_id FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE vr.status='assigned' AND s.driver_id IS NOT NULL ORDER BY s.schedule_id LIMIT 1")
if r3:
    sid3 = int(r3[0][0]); who = sql("SELECT u.username FROM schedules s JOIN users u ON u.driver_id=s.driver_id WHERE s.schedule_id=%d" % sid3)
    c = user(who)
    ok('a task whose request is not Confirmed yet cannot be updated', c.get('driver/update_vehicle.php', trip=sid3).status_code == 302)
# an internal task with a vehicle
it = rows("SELECT t.task_id, t.vehicle_id, u.username FROM internal_tasks t JOIN users u ON u.driver_id=t.driver_id WHERE t.status='active' AND t.vehicle_id IS NOT NULL ORDER BY t.task_id LIMIT 1")
if it:
    tid, tv, who = int(it[0][0]), int(it[0][1]), it[0][2]
    c = user(who); cur = cnt("SELECT mileage FROM vehicles WHERE vehicle_id=%d" % tv)
    r = c.post('driver/update_vehicle.php', {'internal': tid, 'mileage': cur + 15, 'fuel_level': 'full'})
    ok('a vehicle used for an INTERNAL task of the driver can be updated too', r.status_code == 302 and cnt("SELECT COUNT(*) FROM vehicle_usage_log WHERE internal_task_id=%d" % tid) == 1)
sys.exit(0 if summary() else 1)
