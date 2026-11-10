"""SRS 10 Nov UC003 A1: a request for several vehicles - the Assistant chooses a vehicle and a driver for EACH vehicle."""
import datetime, re, sys
from lib import *

reseed()
sql("UPDATE internal_tasks SET status='cancelled'")
s1 = user('stf001'); hos2 = user('hos002'); ad = user('admin'); hd = user('head001')
cnt = lambda q: int(sql(q)); last = lambda: cnt("SELECT MAX(request_id) FROM vehicle_requests")
st = lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r)
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def new(dep_h, qty='2', **kw):
    dd, dt = when(dep_h); rd, rt = when(dep_h + 4)
    f = {'request_type': 'uis', 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '4', 'vehicle_type': 'Car', 'quantity': qty,
         'destination': 'Putrajaya', 'destination_address': 'Presint 5', 'purpose': 'Attend the national higher education forum', 'purpose_type': 'official',
         'passenger_names': 'A', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': '1'}
    f.update(kw); s1.post('staff/request_vehicle.php', f); return last()

rid = new(240)
ok('2 cars are reserved as 2 rows', cnt("SELECT COUNT(*) FROM vehicle_reservations WHERE request_id=%d" % rid) == 2)
hos2.post('ajax/request_action.php', {'request_id': rid, 'action': 'approve'})
pg = ad.get('admin/assign_driver.php', request_id=rid)
ok('the assign page asks for the other vehicle and its driver', 'Other vehicles' in pg.text and 'name="extra_vehicle[]"' in pg.text and 'name="extra_driver[]"' in pg.text and not errs(pg))
drivers = sql("SELECT GROUP_CONCAT(driver_id) FROM drivers WHERE status='active' AND FIND_IN_SET('D', license_class)").split(',')
d1, d2 = drivers[0], drivers[1]
base = {'request_id': rid, 'out_driver': d1, 'return_mode': 'same', 'vehicle_id': '5'}
ad.post('admin/assign_driver.php', base)
ok('the second vehicle and driver are required: refused', st(rid) == 'approved')
ad.post('admin/assign_driver.php', dict(base, **{'extra_vehicle[]': '5', 'extra_driver[]': d2}))
ok('the same vehicle twice is refused', st(rid) == 'approved')
ad.post('admin/assign_driver.php', dict(base, **{'extra_vehicle[]': '1', 'extra_driver[]': d2}))
ok('a vehicle of another type (Bus) is refused', st(rid) == 'approved')
ad.post('admin/assign_driver.php', dict(base, **{'extra_vehicle[]': '6', 'extra_driver[]': d1}))
ok('the same driver for both vehicles is refused (clash)', st(rid) == 'approved' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=%d" % rid) == 0)
ad.post('admin/assign_driver.php', dict(base, **{'extra_vehicle[]': '6', 'extra_driver[]': d2}))
ok('both vehicles assigned: Driver Assigned', st(rid) == 'assigned')
ok('4 tasks: outbound + return for each vehicle', rows("SELECT leg_no, vehicle_id, driver_id FROM schedules WHERE request_id=%d ORDER BY leg_no, vehicle_id" % rid) == [['1', '5', d1], ['1', '6', d2], ['2', '5', d1], ['2', '6', d2]])
ok('both vehicles locked and both reservation rows filled', cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rid) == 2 and cnt("SELECT COUNT(*) FROM vehicle_reservations WHERE request_id=%d AND vehicle_id IS NOT NULL" % rid) == 2)
r = hd.get('admin/approve_assignments.php'); ok('the Head of Admin sees both vehicles', r.status_code == 200 and not errs(r))
hd.post('admin/approve_assignments.php', {'request_id': rid, 'action': 'approve'})
ok('approved: Approved status', st(rid) == 'processed')

# without driver: every vehicle needs a vehicle
sql("UPDATE vehicle_requests SET status='cancelled' WHERE request_id=%d" % rid)
sql("DELETE FROM vehicle_locks WHERE request_id=%d" % rid)
sql("DELETE FROM vehicle_reservations WHERE request_id=%d" % rid)
drv1 = user('drv001'); hos1 = user('hos001')
dd, dt = when(300); rd, rt = when(304)
f = {'request_type': 'uis', 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '2', 'vehicle_type': 'Lorry', 'quantity': '2',
     'destination': 'Putrajaya', 'destination_address': 'Presint 5', 'purpose': 'Move the faculty equipment', 'purpose_type': 'official', 'passenger_names': 'A', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': '0'}
drv1.post('staff/request_vehicle.php', f); rid2 = last()
ok('2 lorries reserved', cnt("SELECT COUNT(*) FROM vehicle_reservations WHERE request_id=%d" % rid2) == 2)
hos1.post('ajax/request_action.php', {'request_id': rid2, 'action': 'approve'})
ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rid2, 'vehicle_id': '11'})
ok('without driver: the second vehicle is required', st(rid2) == 'approved')
ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rid2, 'vehicle_id': '11', 'extra_vehicle[]': '11'})
ok('without driver: the same lorry twice is refused', st(rid2) == 'approved')
ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rid2, 'vehicle_id': '11', 'extra_vehicle[]': '12'})
ok('without driver: both lorries chosen, sent to the Head of Admin', st(rid2) == 'assigned' and cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rid2) == 2)
sys.exit(0 if summary() else 1)
