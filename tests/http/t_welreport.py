"""SRS 10 Nov UC024: the driver reports a welfare duty day (vehicle mileage / fuel level updated, never deleted)."""
import sys
from lib import *
reseed()
ad = user('admin'); d1 = user('drv001'); d2 = user('drv002')
cnt = lambda q: int(sql(q))
def welfare_day(days):
    """an approved welfare duty day 'days' from today for driver 1 / vehicle 5 (with its welfare_duties week); returns its task id"""
    d = "DATE_ADD(CURDATE(), INTERVAL %d DAY)" % days
    sql("DELETE FROM tasks WHERE task_source='welfare' AND task_date=%s" % d)
    sql("INSERT IGNORE INTO welfare_duties (week_start_date, status) VALUES (%s - INTERVAL WEEKDAY(%s) DAY, 'approved')" % (d, d))
    sql("INSERT INTO tasks (task_source, duty_id, task_type, driver_id, vehicle_id, start_dt, end_dt, status) SELECT 'welfare', duty_id, 'welfare', 1, 5, TIMESTAMP(%s), TIMESTAMP(%s + INTERVAL 1 DAY), 'approved' FROM welfare_duties WHERE week_start_date = %s - INTERVAL WEEKDAY(%s) DAY" % (d, d, d, d))
    return sql("SELECT task_id FROM tasks WHERE task_source='welfare' AND task_date=%s" % d)
rid = welfare_day(0)
km = cnt("SELECT mileage FROM vehicles WHERE vehicle_id=5")
r = d1.get('driver/schedules.php'); ok('My Tasks offers Create Report on the welfare duty day', 'welfare=%s' % rid in r.text and 'Create Report' in r.text and not errs(r))
r = d2.get('driver/task_report.php', welfare=rid); ok('another driver cannot open the report (only the driver of the duty)', r.status_code == 302)
r = d1.get('driver/task_report.php', welfare=rid); ok('the report form opens', r.status_code == 200 and 'Task report' in r.text and not errs(r))
now_ = now(); dep = now_.replace(hour=0, minute=5).strftime('%Y-%m-%dT%H:%M'); ret = now_.strftime('%Y-%m-%dT%H:%M')
f = {'welfare': rid, 'actual_depart': dep, 'actual_return': ret, 'end_mileage': str(km + 40), 'fuel_level': 'half', 'vehicle_condition': 'ok'}
r = d1.post('driver/task_report.php', dict(f, end_mileage=str(km - 5))); ok('a mileage lower than the current mileage is refused', cnt("SELECT COUNT(*) FROM task_reports WHERE task_id=%s" % rid) == 0)
r = d1.post('driver/task_report.php', f); ok('report saved for the welfare duty', r.status_code == 302 and cnt("SELECT COUNT(*) FROM task_reports WHERE task_id=%s" % rid) == 1)
ok('the vehicle mileage and fuel level are updated', rows("SELECT mileage, fuel_level FROM vehicles WHERE vehicle_id=5") == [[str(km + 40), 'half']])
r = d1.get('driver/task_report.php', welfare=rid); ok('View Report shows it', 'End mileage' in r.text and 'View' not in 'x')
r = d1.post('driver/task_report.php', dict(f, end_mileage=str(km + 55), fuel_level='quarter', welfare=rid)); ok('the report can be updated (one report per duty day)', cnt("SELECT COUNT(*) FROM task_reports WHERE task_id=%s" % rid) == 1 and sql("SELECT end_mileage FROM task_reports WHERE task_id=%s" % rid) == str(km + 55))
r = d1.get('driver/schedules.php'); ok('then it shows View Report', 'View Report' in r.text)
ok('the Assistant was told', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.role='admin' AND n.message LIKE '%reported%' OR n.message LIKE '%report of a task%'") >= 1)
rid2 = welfare_day(20)
r = d1.get('driver/task_report.php', welfare=rid2); ok('exception 1.1: a duty day that has not started cannot be reported', r.status_code == 302)
sys.exit(0 if summary() else 1)
