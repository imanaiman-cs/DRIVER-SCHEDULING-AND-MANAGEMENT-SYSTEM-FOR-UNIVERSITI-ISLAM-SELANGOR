"""UC010 A3 - a request WITHOUT driver: same approval flow and vehicle lock, but no driver is assigned.
Flow built (Iman's decision): HoD approves -> Assistant sends it to the Head of Admin -> Head of Admin approves the use of the vehicle (Confirmed)."""
import json, datetime, re
from lib import *

reseed()
sql("UPDATE internal_tasks SET status='cancelled' WHERE vehicle_id=6")   # demo internal tasks use relative dates
s1 = user('stf001'); hos2 = user('hos002'); ad = user('admin'); hd = user('head001'); drv = user('drv001')
st = lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r)
last = lambda: int(sql("SELECT MAX(request_id) FROM vehicle_requests"))
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def pick(dep_h):   # a vehicle that is free for the period (demo data uses relative dates, so do not hard-code one)
    dd, dt = when(dep_h); rd, rt = when(dep_h + 4)
    d = json.loads(s1.get('ajax/get_free_vehicles.php', depart_date=dd, depart_time=dt, return_date=rd, return_time=rt, passengers=2).text)
    return d['vehicles'][0]['vehicle_id']
def form(dep_h, nd, vehicle=None):
    vehicle = vehicle or pick(dep_h)
    dd, dt = when(dep_h); rd, rt = when(dep_h + 4)
    return {'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '2', 'vehicle_id': str(vehicle),
            'destination': 'Putrajaya Convention Centre', 'destination_address': 'Presint 5, 62000 Putrajaya', 'purpose': 'Attend the national higher education forum',
            'passenger_names': 'A; B', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': nd}

# the form
page = s1.get('staff/request_vehicle.php')
ok('the form asks With driver / Without driver (With driver is the default)', 'name="need_driver"' in page.text and 'Without driver' in page.text and 'value="1" checked' in page.text)

# submit WITHOUT driver
r = s1.post('staff/request_vehicle.php', form(96, '0')); rA = last()
ok('submitted without driver: saved Pending, need_driver = 0', r.status_code == 302 and st(rA) == 'pending' and sql("SELECT need_driver FROM vehicle_requests WHERE request_id=%d" % rA) == '0')
ok('the vehicle is locked for the period (same as with a driver)', sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rA) == '1')
# submit WITH driver (default) and an unexpected value -> with driver
r = s1.post('staff/request_vehicle.php', form(120, '1')); rB = last()
ok('with driver: need_driver = 1', sql("SELECT need_driver FROM vehicle_requests WHERE request_id=%d" % rB) == '1')
r = s1.post('staff/request_vehicle.php', form(150, 'banana')); rC = last()
ok('an unexpected value counts as With driver', sql("SELECT need_driver FROM vehicle_requests WHERE request_id=%d" % rC) == '1')

# Head of Department approves (same approval flow)
r = hos2.post('ajax/request_action.php', {'request_id': rA, 'action': 'approve'})
ok('the Head of Department approves it like any request', json.loads(r.text)['success'] and st(rA) == 'approved')
ok('the Administrative Assistant is told it is without driver and must be sent to the Head of Admin',
   int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%#%d%%without driver%%'" % rA)) >= 1)

# no driver step
r = ad.get('admin/assign_driver.php', request_id=rA)
ok('Assign Driver page refuses a without-driver request', r.status_code == 302 and st(rA) == 'approved')
tab = ad.get('admin/vehicle_requests.php', tab='approved').text
ok('the Assistant sees "Without driver" and "Send to Head of Admin" for it, "Assign driver" only for the others',
   'Send to Head of Admin' in tab and 'Without driver' in tab)
ok('a without-driver request cannot be assigned a driver through the workflow function',
   'without driver' in __import__('subprocess').run(['php', '-r', 'define("SKIP_REQUEST_JOBS",1); require "%s/config/database.php"; echo assignRequestDrivers($conn, %d, 1)["message"];' % (ROOT, rA)],
        capture_output=True, text=True, env=dict(__import__('os').environ, DSMS_DB=DB)).stdout.lower())

# role checks and CSRF on the new action
r = s1.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rA})
ok('staff cannot send it (role check)', r.status_code == 302 and st(rA) == 'approved')
r = hd.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rB})
ok('a with-driver request cannot be sent without a driver', st(rB) == 'pending')
r = ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rA}, csrf=False)
ok('POST without a CSRF token is refused (419)', r.status_code == 419 and st(rA) == 'approved')

# Assistant sends it to the Head of Admin
r = ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rA})
ok('the Assistant sends it: status Driver Assigned, NO tasks created', st(rA) == 'assigned' and sql("SELECT COUNT(*) FROM schedules WHERE request_id=%d" % rA) == '0')
ok('the Head of Admin is told in the system', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='head001' AND n.message LIKE '%%#%d%%without driver%%'" % rA)) >= 1)
pg = hd.get('admin/approve_assignments.php')
ok('the Head of Admin sees it with the vehicle details only', pg.status_code == 200 and ('Request #%d' % rA) in pg.text and 'Without driver' in pg.text and 'no driver is assigned' in pg.text)
ok('the staff member sees no driver or Head of Admin step in the history', 'Driver assigned' not in s1.get('staff/my_requests.php', open=rA).text)

# reject path
r = hd.post('admin/approve_assignments.php', {'request_id': rA, 'action': 'reject', 'reason': ''})
ok('reject needs a reason', st(rA) == 'assigned')
r = hd.post('admin/approve_assignments.php', {'request_id': rA, 'action': 'reject', 'reason': 'Vehicle is needed by the Rector'})
ok('reject: back to Approved, the Assistant is told with the reason', st(rA) == 'approved' and int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%without driver%%rejected%%Rector%%'")) >= 1)
ok('the vehicle stays locked after the rejection', sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rA) == '1')

# approve path
ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rA})
r = ad.post('admin/approve_assignments.php', {'request_id': rA, 'action': 'approve'})
ok('the Assistant cannot approve (only the Head of Admin)', st(rA) == 'assigned')
r = hd.post('admin/approve_assignments.php', {'request_id': rA, 'action': 'approve'})
ok('the Head of Admin approves the use of the vehicle: Confirmed, still no tasks', st(rA) == 'processed' and sql("SELECT COUNT(*) FROM schedules WHERE request_id=%d" % rA) == '0')
ok('the staff member is told they may use the vehicle without a driver',
   int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d%%confirmed%%without a driver%%'" % rA)) == 1)
ok('no driver is notified', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.role='driver' AND n.message LIKE '%%#%d %%'" % rA)) == 0)
mine = s1.get('staff/my_requests.php', open=rA).text
ok('after Confirmed the staff member sees the vehicle and "Without driver"', 'Without driver' in mine and 'no driver is assigned' in mine)
ok('the driver page shows nothing for this request', ('#%d' % rA) not in drv.get('driver/schedules.php').text)

# the hourly job: Confirmed trips are completed, Approved/Driver Assigned ones that nobody confirmed are cancelled
sql("UPDATE vehicle_requests SET trip_date=CURDATE() - INTERVAL 1 DAY, return_date=CURDATE() - INTERVAL 1 DAY, start_time='08:00:00', end_time='12:00:00' WHERE request_id=%d" % rA)
run_jobs(); ok('the hourly job completes a finished without-driver trip and releases the lock', st(rA) == 'completed' and sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rA) == '0')
# approved, not sent in time -> cancelled by the system
hos2.post('ajax/request_action.php', {'request_id': rC, 'action': 'approve'})
sql("UPDATE vehicle_requests SET need_driver=0 WHERE request_id=%d" % rC)
sql("UPDATE vehicle_requests SET trip_date=CURDATE() - INTERVAL 1 DAY, return_date=CURDATE() - INTERVAL 1 DAY, start_time='08:00:00', end_time='12:00:00' WHERE request_id=%d" % rC)
run_jobs(); ok('a without-driver request nobody confirmed before the trip time is cancelled by the system', st(rC) == 'cancelled')

# cancel works as for any request
hos2.post('ajax/request_action.php', {'request_id': rB, 'action': 'approve'})
sql("UPDATE vehicle_requests SET need_driver=0 WHERE request_id=%d" % rB)
r = ad.post('ajax/cancel_request.php', {'request_id': rB, 'reason': 'Trip postponed'})
ok('the Assistant can cancel a without-driver request (reason required) and the lock is released', st(rB) == 'cancelled' and sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rB) == '0')

# dashboard
sql("UPDATE vehicle_requests SET status='approved', need_driver=0 WHERE request_id=3")
dash = ad.get('admin/dashboard.php').text
ok('the admin dashboard lists it under "Without driver: send to the Head of Admin"', 'Without driver: send to the Head of Admin' in dash)
ok('no PHP errors on the pages', not re.search(r'Warning:|Fatal error|Undefined', dash) and not errs(ad.get('admin/vehicle_requests.php', tab='all')) and not errs(hd.get('admin/approve_assignments.php')))
import sys
sys.exit(0 if summary() else 1)
