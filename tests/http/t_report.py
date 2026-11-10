"""SRS 10 Nov UC024: the driver's task report (actual times, end mileage, fuel level, condition, problem) updates the vehicle."""
import re, sys, datetime
from lib import *

reseed()
ad = user('admin'); s1 = user('stf001')
cnt = lambda q: int(sql(q))
row = rows("SELECT s.schedule_id, s.vehicle_id FROM schedules s JOIN drivers d ON d.driver_id=s.driver_id JOIN users u ON u.driver_id=d.driver_id JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE u.username='drv001' AND vr.status='processed' AND s.status<>'cancelled' ORDER BY s.schedule_id LIMIT 1")[0]
sid, vid = int(row[0]), int(row[1])
drv = user('drv001'); drv2 = user('drv002')
last0 = cnt("SELECT mileage FROM vehicles WHERE vehicle_id=%d" % vid)
n_admin = lambda: cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin'")
T = lambda d: (now() + datetime.timedelta(hours=d)).strftime('%Y-%m-%dT%H:%M')

# before the task starts: no Create Report (exception 1.1)
pg = drv.get('driver/schedules.php')
ok('before the departure time the task has no Create Report button', 'task_report.php?trip=%d' % sid not in pg.text)
ok('and the report page is refused', drv.get('driver/task_report.php', trip=sid).status_code == 302)
# the task starts: yesterday
sql("UPDATE schedules SET trip_date=CURDATE() - INTERVAL 1 DAY, start_time='08:00:00', end_time='10:00:00' WHERE schedule_id=%d" % sid)
ok('once started the task shows Create Report (Past Tasks, A3 view)', 'Create Report' in drv.get('driver/schedules.php', tab='past').text)
fm = drv.get('driver/task_report.php', trip=sid)
ok('the form has actual times, end mileage, fuel level, condition', fm.status_code == 200 and not errs(fm) and all(x in fm.text for x in ['name="actual_depart"', 'name="actual_return"', 'name="end_mileage"', 'name="fuel_level"', 'name="vehicle_condition"', 'name="problem_type"']))
ok('another driver, staff and the Assistant cannot open it', drv2.get('driver/task_report.php', trip=sid).status_code == 302 and s1.get('driver/task_report.php', trip=sid).status_code == 302 and ad.get('driver/task_report.php', trip=sid).status_code == 302)
ok('the old Update Vehicle link goes to the report', drv.get('driver/update_vehicle.php', trip=sid).headers['Location'].endswith('task_report.php?trip=%d' % sid))
base = {'trip': sid, 'actual_depart': T(-30), 'actual_return': T(-27), 'end_mileage': str(last0 + 120), 'fuel_level': 'half', 'vehicle_condition': 'ok'}
post = lambda c=drv, **kw: c.post('driver/task_report.php', dict(base, **kw))
r = post(end_mileage=''); ok('a required field is empty: refused (3.1)', cnt("SELECT COUNT(*) FROM task_reports") == 0 and 'fill in all required' in r.text)
r = post(end_mileage=str(last0 - 1)); ok('a mileage lower than the current mileage is refused (3.2)', cnt("SELECT COUNT(*) FROM task_reports") == 0 and 'not valid' in r.text)
r = post(actual_return=T(-40)); ok('the return must be after the departure', cnt("SELECT COUNT(*) FROM task_reports") == 0)
r = post(vehicle_condition='problem', problem_type='Tyre', problem_desc='', can_be_used='1'); ok('a problem needs a description', cnt("SELECT COUNT(*) FROM task_reports") == 0)
r = post(vehicle_condition='problem', problem_type='Wheels', problem_desc='x', can_be_used='1'); ok('an unknown problem type is refused', cnt("SELECT COUNT(*) FROM task_reports") == 0)
r = drv2.post('driver/task_report.php', dict(base)); ok('another driver cannot save it', cnt("SELECT COUNT(*) FROM task_reports") == 0)
r = drv.post('driver/task_report.php', dict(base), csrf=False); ok('POST without CSRF is refused (419)', r.status_code == 419 and cnt("SELECT COUNT(*) FROM task_reports") == 0)

# A normal report
a0 = n_admin()
r = post(); ok('saved: back to My Tasks', r.status_code == 302 and cnt("SELECT COUNT(*) FROM task_reports") == 1)
rep = rows("SELECT driver_id, vehicle_id, start_mileage, end_mileage, fuel_level, vehicle_condition FROM task_reports")[0]
ok('the report has the driver, vehicle, start and end mileage, fuel level', int(rep[1]) == vid and int(rep[2]) == last0 and int(rep[3]) == last0 + 120 and rep[4] == 'half' and rep[5] == 'ok')
ok('the vehicle mileage and fuel level are updated, the status stays', rows("SELECT mileage, fuel_level, status FROM vehicles WHERE vehicle_id=%d" % vid) == [[str(last0 + 120), 'half', 'available']])
ok('the task is Completed', sql("SELECT status FROM schedules WHERE schedule_id=%d" % sid) == 'completed')
ok('the Assistant is told (no problem: a normal notification)', n_admin() == a0 + 1 and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE 'PROBLEM%%'") == 0)
ok('the vehicle page shows the usage', 'half' in ad.get('admin/view_vehicle.php', id=vid).text.lower() or str(last0 + 120) in ad.get('admin/view_vehicle.php', id=vid).text)
# view and update
pg = drv.get('driver/task_report.php', trip=sid)
ok('View: the report is shown with an Edit button and no delete', pg.status_code == 200 and str(last0 + 120) in pg.text and 'Edit' in pg.text and 'cannot be deleted' in pg.text and 'name="end_mileage"' not in pg.text)
ok('there is no way to delete a report', drv.post('driver/task_report.php', {'trip': sid, 'action': 'delete'}).status_code in (200, 302) and cnt("SELECT COUNT(*) FROM task_reports") == 1)
ok('Past Tasks now offers View Report', 'View Report' in drv.get('driver/schedules.php', tab='past').text)
r = post(end_mileage=str(last0 - 5)); ok('an update below the start mileage of the report is refused', cnt("SELECT end_mileage FROM task_reports") == last0 + 120)
a1 = n_admin()
r = post(end_mileage=str(last0 + 150), fuel_level='quarter'); ok('A4 Update: the report and the vehicle change, the Assistant is told again', r.status_code == 302 and cnt("SELECT end_mileage FROM task_reports") == last0 + 150
   and rows("SELECT mileage, fuel_level FROM vehicles WHERE vehicle_id=%d" % vid) == [[str(last0 + 150), 'quarter']] and n_admin() == a1 + 1 and cnt("SELECT COUNT(*) FROM task_reports") == 1)
ok('the history log has the report and the update', cnt("SELECT COUNT(*) FROM audit_log WHERE action='task_report'") == 1 and cnt("SELECT COUNT(*) FROM audit_log WHERE action='task_report_update'") >= 1)

# A1 / A2 problem, vehicle cannot be used
png = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082')
a2 = n_admin()
r = drv.post('driver/task_report.php', dict(base, end_mileage=str(last0 + 170), vehicle_condition='problem', problem_type='Engine', problem_desc='Engine light is on and it overheats', can_be_used='0'), files=[('photo', ('p.png', png, 'image/png'))])
ok('a problem that stops the vehicle: Under Maintenance, the photo is stored', r.status_code == 302 and sql("SELECT status FROM vehicles WHERE vehicle_id=%d" % vid) == 'maintenance' and cnt("SELECT COUNT(*) FROM task_reports WHERE photo IS NOT NULL AND problem_type='Engine' AND can_be_used=0") == 1)
ok('the Assistant is told about the PROBLEM and that it is Under Maintenance', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE 'PROBLEM%%Engine%%Under Maintenance%%'") == 1)
ok('the vehicle is no longer offered for new requests of its type in that period', vid not in [int(x) for x in re.findall(r'name="vehicle_id" value="(\d+)"', ad.get('admin/assign_driver.php', request_id=2).text)])
r = drv.post('driver/task_report.php', dict(base, end_mileage=str(last0 + 170), vehicle_condition='problem', problem_type='Engine', problem_desc='Checked: it is fine to drive', can_be_used='1'))
ok('an update that says it can be used puts the vehicle back to Available', sql("SELECT status FROM vehicles WHERE vehicle_id=%d" % vid) == 'available')
r = drv.post('driver/task_report.php', dict(base, end_mileage=str(last0 + 175), vehicle_condition='problem', problem_type='Tyre', problem_desc='A tyre needs replacing', can_be_used='1'), files=[('photo', ('x.pdf', b'%PDF-1.4', 'application/pdf'))])
ok('a photo that is not an image is refused', 'JPG, PNG or WebP' in r.text or cnt("SELECT end_mileage FROM task_reports") == last0 + 170)
# an internal task report
sql("INSERT INTO internal_tasks (task_type,task_date,start_time,end_time,vehicle_id,driver_id,description,status) VALUES ('refuel', CURDATE() - INTERVAL 1 DAY, '07:00:00', '08:00:00', 6, 1, 'Fill up', 'active')")
tid = cnt("SELECT MAX(task_id) FROM internal_tasks")
m6 = cnt("SELECT mileage FROM vehicles WHERE vehicle_id=6")
r = drv.post('driver/task_report.php', {'internal': tid, 'actual_depart': T(-30), 'actual_return': T(-29), 'end_mileage': str(m6 + 15), 'fuel_level': 'full', 'vehicle_condition': 'ok'})
ok('an internal task can be reported too and updates its vehicle', r.status_code == 302 and rows("SELECT mileage, fuel_level FROM vehicles WHERE vehicle_id=6") == [[str(m6 + 15), 'full']] and cnt("SELECT COUNT(*) FROM task_reports WHERE internal_task_id=%d" % tid) == 1)
ok('no PHP errors on the pages', not errs(drv.get('driver/task_report.php', trip=sid)) and not errs(drv.get('driver/schedules.php')))
sys.exit(0 if summary() else 1)
