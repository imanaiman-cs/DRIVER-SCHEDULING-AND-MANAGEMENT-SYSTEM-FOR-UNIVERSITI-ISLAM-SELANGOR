"""Walks through docs/DEMO_GUIDE.md from start to end with the demo accounts."""
import sys, re, datetime
from lib import *
reseed()
cnt = lambda q: int(sql(q)); last = lambda: cnt("SELECT MAX(request_id) FROM vehicle_requests")
stf = user('stf001'); hod = user('hos002'); ad = user('admin'); hd = user('head001'); drv = user('drv001')
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
dd, dt = when(96); rd, rt = when(100)
f = {'request_type': 'uis', 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '3', 'vehicle_type': 'Car', 'quantity': '1',
     'destination': 'Putrajaya', 'destination_address': 'Presint 5', 'purpose': 'Attend the national higher education forum', 'purpose_type': 'official',
     'passenger_names': 'A', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': '1'}
r = stf.post('staff/request_vehicle.php', f); rid = last(); st = lambda: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % rid)
ok('1.1 staff submits (Pending)', r.status_code == 302 and st() == 'pending')
f2 = dict(f); d2, t2 = when(10); e2, u2 = when(14); f2.update(depart_date=d2, depart_time=t2, return_date=e2, return_time=u2); stf.post('staff/request_vehicle.php', f2); r2 = last()
ok('1.1 a trip under 2 days is marked Urgent', sql("SELECT is_urgent FROM vehicle_requests WHERE request_id=%d" % r2) == '1')
hod.post('ajax/request_action.php', {'request_id': rid, 'action': 'approve'}); ok('1.2 Head of Department approves', st() == 'approved')
pg = ad.get('admin/assign_driver.php', request_id=rid); veh = re.findall(r'name="vehicle_id" value="(\d+)"', pg.text)
ids = re.findall(r'name="out_driver" value="(\d+)"', pg.text); ok('1.3 assign page lists vehicles and recommended drivers', veh and len(ids) >= 2 and not errs(pg))
w = ad.post('admin/assign_driver.php', {'request_id': rid, 'out_driver': ids[-1], 'return_mode': 'same', 'vehicle_id': veh[0], 'warn': '1'}); ok('1.3 warning for a driver outside the recommendation', 'not the top recommendation' in w.text and st() == 'approved')
ad.post('admin/assign_driver.php', {'request_id': rid, 'out_driver': ids[0], 'return_mode': 'same', 'vehicle_id': veh[0], 'warn': '1'}); ok('1.3 assigned (Driver Assigned)', st() == 'assigned')
r = hd.get('admin/approve_assignments.php'); ok('1.4 Head of Admin sees it', 'Request #%d' % rid in r.text and not errs(r))
hd.post('admin/approve_assignments.php', {'request_id': rid, 'action': 'approve'}); ok('1.4 approved', st() == 'processed')
ok('1.4 requester notified', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001'") >= 1 and cnt("SELECT COUNT(*) FROM email_log") >= 1)
dname = sql("SELECT username FROM users WHERE driver_id=%s" % ids[0]); dc = user(dname) if dname in PW else None
if dc: r = dc.get('driver/schedules.php'); ok('1.5 the driver sees the task', 'Putrajaya' in r.text or 'Request #%d' % rid in r.text)
for p, q in (('staff/print_approval.php', {}),):
    pass
# 2 internal task
add = {'action': 'save', 'task_id': '0', 'task_type': 'refuel', 'task_date': D(9), 'start_time': '09:00', 'end_time': '10:00', 'vehicle_id': '5', 'location': 'Petronas', 'description': 'x'}
ad.post('admin/internal_tasks.php', add); tid = cnt("SELECT MAX(task_id) FROM internal_tasks"); ok('2 internal task Waiting for Driver', sql("SELECT status FROM internal_tasks WHERE task_id=%d" % tid) == 'waiting')
rec = re.findall(r'name="driver_id" value="(\d+)"', ad.get('admin/assign_internal_task.php', id=tid).text)
ad.post('admin/assign_internal_task.php', {'task_id': tid, 'driver_id': rec[0]}); hd.post('admin/approve_assignments.php', {'task_id': tid, 'action': 'approve'}); ok('2 assigned and approved', sql("SELECT status FROM internal_tasks WHERE task_id=%d" % tid) == 'active')
# 3 welfare
mon = (datetime.date.today() + datetime.timedelta(days=35)); mon = mon - datetime.timedelta(days=mon.weekday()); days = [(mon + datetime.timedelta(days=i)).isoformat() for i in range(7)]
ad.post('admin/welfare.php', dict(action='create', week_date=mon.isoformat(), **{'vehicle[%s]' % d: '6' for d in days})); ok('3 welfare week created', cnt("SELECT COUNT(*) FROM welfare_roster WHERE status='waiting' AND duty_date BETWEEN '%s' AND '%s'" % (days[0], days[6])) == 7)
pick = {}
page = ad.get('admin/assign_welfare.php', week=mon.isoformat()).text
for d in days: pick['driver[%s]' % d] = re.findall(r'name="driver\[%s\]" value="(\d+)"' % d, page)[0]
ad.post('admin/assign_welfare.php', dict(week=mon.isoformat(), confirmed='1', **pick)); hd.post('admin/approve_assignments.php', dict(week=mon.isoformat(), action='approve'))
ok('3 welfare assigned and approved', cnt("SELECT COUNT(*) FROM welfare_roster WHERE status='active' AND vehicle_id=6 AND duty_date BETWEEN '%s' AND '%s'" % (days[0], days[6])) == 7)
# 4 accounts and vehicles
ad.post('admin/add_driver.php', {'mode': 'register', 'username': 'DRV901', 'full_name': 'Demo Driver Baru', 'email': 'demo.driver@uis.edu.my', 'phone': '012-1112223', 'license_class[]': ['D'], 'license_expiry': '2031-01-01', 'date_joined': '2025-01-01'})
ok('4 driver registered with account', cnt("SELECT COUNT(*) FROM users u JOIN drivers d ON d.driver_id=u.driver_id WHERE u.username='DRV901'") == 1)
ad.post('admin/add_vehicle.php', {'plate_number': 'DEMO 1', 'vehicle_type': 'Car', 'model': 'Myvi', 'capacity': '5', 'license_required': 'D', 'mileage': '100', 'fuel_level': 'full'}); ok('4 vehicle added', cnt("SELECT COUNT(*) FROM vehicles WHERE plate_number='DEMO 1'") == 1)
vid = sql("SELECT vehicle_id FROM vehicles WHERE plate_number='DEMO 1'"); ad.post('admin/edit_vehicle.php', {'id': vid, 'action': 'usage', 'mileage': '150', 'fuel_level': 'half', 'status': 'available'}); ok('4 vehicle updated', sql("SELECT mileage FROM vehicles WHERE vehicle_id=%s" % vid) == '150')
# 5 pages
for c, p in ((stf,'staff/my_requests.php'),(hod,'supervisor/department_requests.php'),(ad,'admin/vehicle_requests.php?tab=all'),(ad,'admin/reports.php'),(ad,'admin/calendar.php'),(drv,'driver/schedule_change.php'),(hd,'admin/schedule_changes.php'),(ad,'admin/users.php'),(ad,'admin/dashboard.php'),(hd,'admin/dashboard.php'),(drv,'driver/dashboard.php'),(stf,'staff/dashboard.php'),(hod,'supervisor/dashboard.php')):
    r = c.get(p); ok('5 opens: ' + p, r.status_code == 200 and not errs(r))
sys.exit(0 if summary() else 1)
