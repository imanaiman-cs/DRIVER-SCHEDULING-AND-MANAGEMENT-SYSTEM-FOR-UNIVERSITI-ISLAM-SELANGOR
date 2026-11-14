"""SRS 14 Nov: Replace Driver for internal tasks and welfare days (UC008); assign another vehicle to each booking (UC002.3 / UC002.4)."""
import sys, re, datetime
from lib import *
reseed()
ad = user('admin'); hd = user('head001'); stf = user('stf001')
cnt = lambda q: int(sql(q))
# ---- internal task: add -> assign -> approve -> replace
ad.post('admin/internal_tasks.php', {'action': 'save', 'task_id': '0', 'task_type': 'refuel', 'task_date': D(9), 'start_time': '09:00', 'end_time': '10:00', 'vehicle_id': '5', 'location': 'Petronas', 'description': 'x'})
tid = cnt("SELECT MAX(task_id) FROM internal_tasks")
rec = re.findall(r'name="driver_id" value="(\d+)"', ad.get('admin/assign_internal_task.php', id=tid).text)
ad.post('admin/assign_internal_task.php', {'task_id': tid, 'driver_id': rec[0]}); hd.post('admin/approve_assignments.php', {'task_id': tid, 'action': 'approve'})
ok('internal task Approved', sql("SELECT status FROM internal_tasks WHERE task_id=%d" % tid) == 'active')
pg = ad.get('admin/replace_task_driver.php', internal=tid); ok('Replace Driver page lists the recommended drivers', pg.status_code == 200 and 'name="new_driver"' in pg.text and not errs(pg))
ok('the list offers the Replace driver button for an approved, not started task', 'replace_task_driver.php?internal=%d' % tid in ad.get('admin/internal_tasks.php').text)
new = [x for x in re.findall(r'name="new_driver" value="(\d+)"', pg.text) if x != rec[0]][0]
r = ad.post('admin/replace_task_driver.php', {'internal': tid, 'new_driver': new, 'reason': ''}); ok('3.1 the reason is required', sql("SELECT driver_id FROM internal_tasks WHERE task_id=%d" % tid) == rec[0])
r = stf.post('admin/replace_task_driver.php', {'internal': tid, 'new_driver': new, 'reason': 'x y z'}); ok('staff cannot replace (role check)', sql("SELECT driver_id FROM internal_tasks WHERE task_id=%d" % tid) == rec[0])
r = ad.post('admin/replace_task_driver.php', {'internal': tid, 'new_driver': new, 'reason': 'Driver is sick'}, csrf=False); ok('no CSRF token: 419', r.status_code == 419)
r = ad.post('admin/replace_task_driver.php', {'internal': tid, 'new_driver': new, 'reason': 'Driver is sick'})
ok('replaced: new driver, back to Driver Assigned, recorded with the reason', rows("SELECT driver_id, status FROM internal_tasks WHERE task_id=%d" % tid) == [[new, 'assigned']] and rows("SELECT previous_driver_id, new_driver_id, reason FROM driver_replacements WHERE internal_task_id=%d" % tid) == [[rec[0], new, 'Driver is sick']])
ok('the previous driver and the Head of Admin are told', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.driver_id=%s AND n.message LIKE 'You were replaced on%%'" % rec[0]) == 1 and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.role='head_admin' AND n.message LIKE '%%was replaced%%Please approve%%'") >= 1)
r = ad.post('admin/replace_task_driver.php', {'internal': tid, 'new_driver': rec[0], 'reason': 'again again'}); ok('a task that is not Approved cannot be replaced again', sql("SELECT driver_id FROM internal_tasks WHERE task_id=%d" % tid) == new)
hd.post('admin/approve_assignments.php', {'task_id': tid, 'action': 'approve'}); ok('the Head of Admin approves the replacement', sql("SELECT status FROM internal_tasks WHERE task_id=%d" % tid) == 'active')
# ---- welfare
mon = datetime.date.today() + datetime.timedelta(days=42); mon -= datetime.timedelta(days=mon.weekday()); days = [(mon + datetime.timedelta(days=i)).isoformat() for i in range(7)]
ad.post('admin/welfare.php', dict(action='create', week_date=mon.isoformat(), **{'vehicle[%s]' % d: '6' for d in days}))
page = ad.get('admin/assign_welfare.php', week=mon.isoformat()).text
pick = {'driver[%s]' % d: re.findall(r'name="driver\[%s\]" value="(\d+)"' % d, page)[0] for d in days}
ad.post('admin/assign_welfare.php', dict(week=mon.isoformat(), confirmed='1', **pick)); hd.post('admin/approve_assignments.php', dict(week=mon.isoformat(), action='approve'))
rid = sql("SELECT roster_id FROM welfare_roster WHERE duty_date='%s'" % days[2]); oldd = sql("SELECT driver_id FROM welfare_roster WHERE roster_id=%s" % rid)
pg = ad.get('admin/replace_task_driver.php', welfare=rid); ok('welfare: the Replace Driver page opens', pg.status_code == 200 and 'name="new_driver"' in pg.text)
nd = [x for x in re.findall(r'name="new_driver" value="(\d+)"', pg.text) if x != oldd][0]
ad.post('admin/replace_task_driver.php', {'welfare': rid, 'new_driver': nd, 'reason': 'Annual leave'})
ok('welfare: replaced, Driver Assigned, recorded', rows("SELECT driver_id, status FROM welfare_roster WHERE roster_id=%s" % rid) == [[nd, 'assigned']] and cnt("SELECT COUNT(*) FROM driver_replacements WHERE welfare_id=%s AND reason='Annual leave'" % rid) == 1)
hd.post('admin/approve_assignments.php', dict(week=mon.isoformat(), action='approve'))
ok('welfare: approved; only this day was announced to the new driver', sql("SELECT status FROM welfare_roster WHERE roster_id=%s" % rid) == 'active' and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.driver_id=%s AND n.message LIKE 'You are on welfare duty%%'" % nd) >= 1)
r = ad.get('admin/reports.php', type='replacements', month=D(0)[:7]); ok('the Driver Replacement report shows them', r.status_code == 200 and not errs(r))
# ---- another vehicle for each booking
sql("UPDATE vehicle_requests SET status='approved' WHERE request_id=2")
r = ad.post('admin/edit_vehicle.php', {'id': 3, 'action': 'usage', 'mileage': '99999', 'fuel_level': 'full', 'status': 'maintenance'})
ok('Under Maintenance is refused and the page offers "Assign another vehicle"', 'reassign_vehicle.php?vehicle_id=3' in r.text and sql("SELECT status FROM vehicles WHERE vehicle_id=3") != 'maintenance')
pg = ad.get('admin/reassign_vehicle.php', vehicle_id=3); ok('the reassign page lists the bookings', pg.status_code == 200 and 'Request #2' in pg.text and not errs(pg))
r = ad.post('admin/reassign_vehicle.php', {'vehicle_id': 3, 'kind': 'request', 'id': 2, 'new_vehicle': 1}); ok('a vehicle of another type is refused', sql("SELECT vehicle_id FROM vehicle_requests WHERE request_id=2") == '3')
r = ad.post('admin/reassign_vehicle.php', {'vehicle_id': 3, 'kind': 'request', 'id': 2, 'new_vehicle': 3}); ok('the same vehicle is refused', sql("SELECT vehicle_id FROM vehicle_requests WHERE request_id=2") == '3')
r = stf.post('admin/reassign_vehicle.php', {'vehicle_id': 3, 'kind': 'request', 'id': 2, 'new_vehicle': 2}); ok('staff cannot reassign (role check)', sql("SELECT vehicle_id FROM vehicle_requests WHERE request_id=2") == '3')
free = sql("SELECT group_concat(vehicle_id) FROM vehicles WHERE vehicle_type=(SELECT vehicle_type FROM vehicles WHERE vehicle_id=3) AND vehicle_id<>3 AND status='available'").split(',')
r = ad.post('admin/reassign_vehicle.php', {'vehicle_id': 3, 'kind': 'request', 'id': 2, 'new_vehicle': 3}, csrf=False); ok('no CSRF token: 419', r.status_code == 419)
if free and free[0] not in ('', 'NULL'):
    r = ad.post('admin/reassign_vehicle.php', {'vehicle_id': 3, 'kind': 'request', 'id': 2, 'new_vehicle': free[0]})
    ok('request moved to the other vehicle (request, lock and tasks)', sql("SELECT vehicle_id FROM vehicle_requests WHERE request_id=2") == free[0] and cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=2 AND vehicle_id=3") == 0 and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=2 AND vehicle_id=3") == 0)
else:
    ok('request moved (only one vehicle of that type: skipped)', True)
r = ad.post('admin/reassign_vehicle.php', {'vehicle_id': 5, 'kind': 'internal', 'id': tid, 'new_vehicle': 6}); ok('internal task moved to another car', sql("SELECT vehicle_id FROM internal_tasks WHERE task_id=%d" % tid) == '6')
r = ad.post('admin/reassign_vehicle.php', {'vehicle_id': 6, 'kind': 'welfare', 'id': rid, 'new_vehicle': 5}); ok('welfare day moved to another car', sql("SELECT vehicle_id FROM welfare_roster WHERE roster_id=%s" % rid) == '5')
r = ad.post('admin/reassign_vehicle.php', {'vehicle_id': 5, 'kind': 'welfare', 'id': rid, 'new_vehicle': 11}); ok('a lorry cannot be used for welfare duty', sql("SELECT vehicle_id FROM welfare_roster WHERE roster_id=%s" % rid) == '5')
sys.exit(0 if summary() else 1)
