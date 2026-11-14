"""Gaps found by the line-by-line check against the 14 Nov use cases."""
import sys, re, datetime
from lib import *
reseed()
ad = user('admin'); hd = user('head001'); d1 = user('drv001'); d2 = user('drv002'); stf = user('stf001')
cnt = lambda q: int(sql(q))
# UC022 notes: the driver cancels a Pending schedule change request
d = D(20)
d1.post('driver/schedule_change.php', {'type': 'unavailable', 'from': d, 'to': d, 'reason': 'Family event'})
cid = cnt("SELECT MAX(change_id) FROM schedule_change_requests")
pg = d1.get('driver/schedule_change.php'); ok('a Pending request shows Cancel request', 'Cancel request' in pg.text and not errs(pg))
r = d2.post('driver/schedule_change.php', {'action': 'cancel', 'change_id': cid}); ok("another driver cannot cancel it", sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid) == 'pending')
r = d1.post('driver/schedule_change.php', {'action': 'cancel', 'change_id': cid}, csrf=False); ok('no CSRF token: 419', r.status_code == 419)
r = d1.post('driver/schedule_change.php', {'action': 'cancel', 'change_id': cid}); ok('the driver cancels it', sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid) == 'cancelled')
ok('the Head of Admin no longer sees it as pending', 'Family event' not in hd.get('admin/schedule_changes.php').text or 'cancelled' in hd.get('admin/schedule_changes.php').text.lower())
r = d1.post('driver/schedule_change.php', {'action': 'cancel', 'change_id': cid}); ok('a cancelled request cannot be cancelled again', sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid) == 'cancelled')
# UC002.2: current mileage and fuel level on the vehicle details
sql("UPDATE vehicles SET mileage=4321, fuel_level='half' WHERE vehicle_id=5")
pg = ad.get('admin/view_vehicle.php', id=5); ok('vehicle details show the current mileage and fuel level', '4321 km' in pg.text and '1/2' in pg.text and 'Current fuel level' in pg.text and not errs(pg))
# UC004.2: filter by task type
r = ad.get('admin/internal_tasks.php', task_type='test_drive'); ok('internal tasks can be filtered by task type', 'f_type' in r.text and 'Test Drive' in r.text and 'Refuelling</strong>' not in r.text and not errs(r))
r = ad.get('admin/internal_tasks.php', task_type='workshop', status='cancelled', when='past'); ok('no match -> "No record is found."', 'No record is found.' in r.text)
# UC001.2: a driver account shows licence details; no match message
pg = ad.get('admin/users.php', role='driver'); ok('the user list shows the licence class, expiry and start date of a driver', 'Licence ' in pg.text and 'in service since' in pg.text)
ok('no user matches the search -> "No record is found."', 'No record is found.' in ad.get('admin/users.php', q='zzzznone').text)
# UC026 2.1: the drivers' task count on the approval page
rid = cnt("SELECT request_id FROM vehicle_requests WHERE status='assigned' LIMIT 1")
pg = hd.get('admin/approve_assignments.php'); ok('Approve Assignments shows each driver\'s task count this month', 'Tasks this month' in pg.text and not errs(pg))
# UC008 A1: deactivating a driver lists internal tasks / welfare days with a Replace driver link
ad.post('admin/internal_tasks.php', {'action': 'save', 'task_id': '0', 'task_type': 'refuel', 'task_date': D(9), 'start_time': '09:00', 'end_time': '10:00', 'vehicle_id': '5', 'location': 'P', 'description': 'x'})
tid = cnt("SELECT MAX(task_id) FROM internal_tasks")
sql("DELETE FROM schedules WHERE driver_id=12"); sql("DELETE FROM welfare_roster WHERE driver_id=12"); sql("DELETE FROM internal_tasks WHERE driver_id=12")
sql("UPDATE internal_tasks SET driver_id=12, status='active' WHERE task_id=%d" % tid)
j = ad.post('ajax/deactivate_driver.php', {'id': 12}).json()
ok('the blocked list gives a Replace driver link for an approved internal task', not j['success'] and any((i.get('replace_url') or '').startswith('replace_task_driver.php?internal=%d' % tid) for i in j['items']))
pg = ad.get('admin/view_driver.php', id=12); ok('the driver page lists it with the Replace driver link', 'replace_task_driver.php?internal=%d' % tid in pg.text)
# UC021 A1: the Head of Department cancels from Department Request
hos = user('hos002'); rq = cnt("SELECT request_id FROM vehicle_requests WHERE status IN ('pending','approved') AND trip_date > CURDATE() AND supervisor_id=(SELECT user_id FROM users WHERE username='hos002') LIMIT 1")
pg = hos.get('supervisor/department_requests.php'); ok('Department Request offers Cancel with a required reason', 'name="reason"' in pg.text and 'Cancel this request' in pg.text)
r = hos.post('ajax/cancel_request.php', {'request_id': rq, 'reason': ''}); ok('3.1: an empty reason is refused', sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % rq) != 'cancelled')
r = hos.post('ajax/cancel_request.php', {'request_id': rq, 'reason': 'Budget freeze'}); ok('the Head of Department cancels it', sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % rq) == 'cancelled')
sys.exit(0 if summary() else 1)
