"""SRS 10 Nov UC018 / UC003: a request reserves a vehicle TYPE (quantity, departure -> return + 1 h); the Assistant chooses the vehicle when assigning."""
import json, datetime, re, sys
from lib import *

reseed()
sql("UPDATE internal_tasks SET status='cancelled'")
s1 = user('stf001'); hos2 = user('hos002'); ad = user('admin'); hd = user('head001')
cnt = lambda q: int(sql(q)); last = lambda: cnt("SELECT MAX(request_id) FROM vehicle_requests")
st = lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r)
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def new(dep_h, vtype='Car', qty='1', pax='2', **kw):
    dd, dt = when(dep_h); rd, rt = when(dep_h + 4)
    f = {'request_type': 'uis', 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': pax, 'vehicle_type': vtype, 'quantity': qty,
         'destination': 'Putrajaya', 'destination_address': 'Presint 5', 'purpose': 'Attend the national higher education forum', 'purpose_type': 'official',
         'passenger_names': 'A', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': '1'}
    f.update(kw); r = s1.post('staff/request_vehicle.php', f); return r, last()
ok('the form shows vehicle TYPES with a quantity, not single vehicles', 'name="quantity"' in s1.get('staff/request_vehicle.php').text and 'get_vehicle_types.php' in s1.get('staff/request_vehicle.php').text)
r, rid = new(240)
ok('submitted: Pending, type Car x1, no vehicle chosen, one reservation row', r.status_code == 302 and rows("SELECT vehicle_type, quantity, vehicle_id IS NULL FROM vehicle_requests WHERE request_id=%d" % rid) == [['Car', '1', '1']] and cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rid) == 0)
r2, rid2 = new(240, qty='2', pax='4')
ok('quantity 2 needs 2 free: only 1 car is left, refused', r2.status_code == 200 and 'only 1' in r2.text)
r3, rid3 = new(240, vtype='Bus', qty='2', pax='60')
ok('quantity 2 buses are reserved as 2 rows', r3.status_code == 302 and cnt("SELECT COUNT(*) FROM vehicle_reservations WHERE request_id=%d" % rid3) == 2 and sql("SELECT quantity FROM vehicle_requests WHERE request_id=%d" % rid3) == '2')
hos2.post('ajax/request_action.php', {'request_id': rid, 'action': 'approve'})
ok('Approved by Head of Department', st(rid) == 'approved')

# the Assistant chooses the vehicle
pg = ad.get('admin/assign_driver.php', request_id=rid)
cars = re.findall(r'name="vehicle_id" value="(\d+)"', pg.text)
ok('the assign page offers the free CARS only (not the Bus, Van or the inactive car)', pg.status_code == 200 and sorted(cars) == ['5', '6'] and 'the request reserved a Car' in pg.text and not errs(pg))
drv = sql("SELECT driver_id FROM drivers WHERE status='active' AND FIND_IN_SET('D', license_class) LIMIT 1")
base = {'request_id': rid, 'out_driver': drv, 'return_mode': 'same'}
r = ad.post('admin/assign_driver.php', base, **{})
ok('no vehicle chosen: refused, still Approved', st(rid) == 'approved')
r = ad.post('admin/assign_driver.php', dict(base, vehicle_id='1'))
ok('a vehicle of another type (a Bus) is refused', st(rid) == 'approved')
r = ad.post('admin/assign_driver.php', dict(base, vehicle_id='9'))
ok('an inactive car is refused', st(rid) == 'approved')
sql("INSERT INTO internal_tasks (task_type,task_date,start_time,end_time,vehicle_id,driver_id,description,status) SELECT 'refuel', trip_date, start_time, end_time, 5, 12, 'blocks car 5', 'active' FROM vehicle_requests WHERE request_id=%d" % rid)
r = ad.post('admin/assign_driver.php', dict(base, vehicle_id='5'))
ok('a car that has an internal task in the period is refused', st(rid) == 'approved')
r = ad.post('admin/assign_driver.php', dict(base, vehicle_id='6'))
ok('the free car is chosen and assigned: Driver Assigned, the request now has the vehicle', r.status_code == 302 and st(rid) == 'assigned' and sql("SELECT vehicle_id FROM vehicle_requests WHERE request_id=%d" % rid) == '6')
ok('the vehicle is locked from departure to return + 1 hour and the reservation row points to it',
   sql("SELECT TIMESTAMPDIFF(MINUTE, vr.return_at, l.end_dt) FROM vehicle_locks l JOIN vehicle_requests vr ON vr.request_id=l.request_id WHERE l.request_id=%d" % rid) == '60'
   and sql("SELECT vehicle_id FROM vehicle_reservations WHERE request_id=%d" % rid) == '6')
ok('both tasks use the chosen vehicle', sql("SELECT COUNT(*) FROM schedules WHERE request_id=%d AND vehicle_id=6 AND status<>'cancelled'" % rid) == '2')
ok('the request page shows the type and the chosen plate', 'Car' in ad.get('admin/vehicle_requests.php', tab='assigned').text)
hd.post('admin/approve_assignments.php', {'request_id': rid, 'action': 'approve'})
ok('the Head of Admin approves: Approved', st(rid) == 'processed')
# cancel releases the reservation and the lock
s1.post('ajax/cancel_request.php', {'request_id': rid, 'reason': 'Trip postponed'})
ok('cancel: reservation and lock are released', cnt("SELECT COUNT(*) FROM vehicle_reservations WHERE request_id=%d" % rid) == 0 and cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rid) == 0)
r4, rid4 = new(240)
ok('the car type is free again after the cancellation', r4.status_code == 302)
# reject by Head of Department releases it
hos2.post('ajax/request_action.php', {'request_id': rid4, 'action': 'reject', 'reason': 'Not needed this week'})
ok('rejected: the reservation is released', cnt("SELECT COUNT(*) FROM vehicle_reservations WHERE request_id=%d" % rid4) == 0)
# Self drive without driver: the Assistant chooses the vehicle when sending
r5, rid5 = new(300, vtype='Lorry', pax='1', need_driver='0')
hos2.post('ajax/request_action.php', {'request_id': rid5, 'action': 'approve'})
r = ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rid5})
ok('without driver: sending needs a vehicle', st(rid5) == 'approved')
r = ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rid5, 'vehicle_id': '5'})
ok('without driver: a car cannot be chosen for a Lorry request', st(rid5) == 'approved')
r = ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rid5, 'vehicle_id': '11'})
ok('without driver: the lorry is chosen, locked and the request is sent to the Head of Admin', st(rid5) == 'assigned' and sql("SELECT vehicle_id FROM vehicle_requests WHERE request_id=%d" % rid5) == '11' and cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rid5) == 1)
sys.exit(0 if summary() else 1)
