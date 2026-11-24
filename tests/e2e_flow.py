#!/usr/bin/env python3
"""
End-to-end check of the SRS flows over HTTP (UC001-UC028).

    mysql -uroot < database.sql                      # fresh demo database (this script re-imports it)
    php -S 127.0.0.1:8300 -t .                       # with config/local.php: return ['site_url' => 'http://127.0.0.1:8300'];
    python3 tests/e2e_flow.py                        # BASE=http://127.0.0.1:8300 by default

It re-creates the database (without the demo section), then drives the system as each role and fails on the first broken step or PHP warning.
"""
import os, re, subprocess, sys, datetime as dt
import requests

BASE = os.environ.get('BASE', 'http://127.0.0.1:8300')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.environ.get('DSMS_DB', 'uis_driver_db')
PASSED = 0


def sql(q):
    out = subprocess.run(['mysql', '-uroot', '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    if out.returncode:
        raise SystemExit('SQL failed: ' + out.stderr)
    return [line.split('\t') for line in out.stdout.strip().splitlines() if line]


def one(q):
    rows = sql(q)
    return rows[0][0] if rows else None


def ok(cond, msg):
    global PASSED
    if not cond:
        raise SystemExit('FAIL: ' + msg)
    PASSED += 1
    print('  ok  ' + msg)


BAD = re.compile(r'(Warning|Fatal error|Notice|Deprecated|Uncaught|Parse error)\b.*?(on line|in /)', re.S)


class User:
    def __init__(self, login, password):
        self.s = requests.Session()
        self.login = login
        r = self.s.get(BASE + '/login.php')
        tok = re.search(r'name="csrf_token" value="([^"]+)"', r.text).group(1)
        r = self.s.post(BASE + '/login.php', data={'username': login, 'password': password, 'csrf_token': tok})
        ok('Log Out' in r.text, f'{login} signs in')
        self.token = tok

    def get(self, path, expect=200):
        r = self.s.get(BASE + '/' + path)
        ok(r.status_code == expect, f'{self.login} GET {path} -> {r.status_code}')
        m = BAD.search(r.text)
        ok(not m, f'{path}: no PHP warning' + (': ' + r.text[max(0, m.start() - 100):m.end() + 200] if m else ''))
        t = re.search(r'window\.UIS_CSRF = "([^"]+)"', r.text)
        if t:
            self.token = t.group(1)
        return r.text

    def post(self, path, data, files=None):
        data = dict(data)
        data['csrf_token'] = self.token
        r = self.s.post(BASE + '/' + path, data=data, files=files)
        m = BAD.search(r.text)
        ok(not m, f'POST {path}: no PHP warning' + (': ' + r.text[max(0, m.start() - 200):m.end() + 200] if m else ''))
        return r.text


def flash(html):
    m = re.search(r'role="alert"><i[^>]*></i><span>(.*?)</span>', html, re.S)
    return m.group(1) if m else ''


def main():
    base = open(f'{ROOT}/database.sql').read()
    base = base.split('-- ============================================================\n-- DEMO DATA')[0]   # base data only: the demo trips would book the test vehicles
    subprocess.run(['mysql', '-uroot'], input=base, text=True, check=True)
    if DB != 'uis_driver_db':
        raise SystemExit('database.sql creates uis_driver_db')
    day = lambda n: (dt.date.today() + dt.timedelta(days=n)).isoformat()
    pdf = ('letter.pdf', b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n', 'application/pdf')

    print('\nPages of every role')
    pages = {
        ('stf001', 'staff001@uis'): ['staff/dashboard.php', 'staff/request_vehicle.php', 'staff/my_requests.php', 'profile.php', 'notifications.php', 'help.php'],
        ('hos002', 'hos002@uis'): ['supervisor/dashboard.php', 'supervisor/approvals.php', 'supervisor/approvals.php?tab=all'],
        ('drv001', 'driver001@uis'): ['driver/dashboard.php', 'driver/tasks.php', 'driver/tasks.php?view=past', 'driver/schedule_change.php'],
        ('admin', 'admin123'): ['admin/dashboard.php', 'admin/vehicle_requests.php', 'admin/assign_driver.php', 'admin/schedule.php', 'admin/schedule.php?view=month',
                                'admin/schedule.php?view=replace', 'admin/internal_tasks.php', 'admin/welfare.php', 'admin/users.php', 'admin/users.php?tab=pending',
                                'admin/add_driver.php', 'admin/view_user.php?id=11', 'admin/edit_user.php?id=11', 'admin/edit_user.php?id=8', 'admin/vehicles.php',
                                'admin/add_vehicle.php', 'admin/view_vehicle.php?id=1', 'admin/edit_vehicle.php?id=1', 'admin/reports.php', 'admin/reports.php?type=replacement',
                                'admin/reports.php?type=vehicle', 'admin/settings.php'],
        ('head001', 'head001@uis'): ['admin/dashboard.php', 'admin/approvals.php', 'admin/approvals.php?tab=internal', 'admin/approvals.php?tab=welfare',
                                     'admin/approvals.php?tab=replacement', 'admin/schedule_changes.php', 'admin/schedule_changes.php?tab=history'],
    }
    for (u, p), paths in pages.items():
        usr = User(u, p)
        for path in paths:
            usr.get(path)
    ok(User('stf001', 'staff001@uis').s.get(BASE + '/admin/users.php', allow_redirects=False).status_code == 302, 'staff cannot open admin pages')

    print('\nUC018 Submit (With Driver, car) -> UC025 HoD -> UC003 assign -> UC026 HoA -> UC023/UC024 driver')
    stf = User('stf001', 'staff001@uis')
    html = stf.get(f'staff/request_vehicle.php?depart_date={day(5)}&depart_time=09:00&return_date={day(5)}&return_time=16:00')
    ok('units left' in html, 'availability shows units left per type')
    stf.post('staff/request_vehicle.php', {'depart_date': day(5), 'depart_time': '09:00', 'return_date': day(5), 'return_time': '16:00',
              'vehicle_type': 'Car', 'quantity': '1', 'driver_option': 'with_driver', 'purpose_type': 'official', 'purpose': 'Meeting',
              'destination': 'MOHE Putrajaya', 'address': 'Presint 5, Putrajaya', 'waiting_location': 'Main entrance', 'passengers': '2'}, files={'document': pdf})
    rid = one("SELECT MAX(request_id) FROM requests")
    ok(one(f"SELECT status FROM requests WHERE request_id={rid}") == 'pending', 'request saved as Pending')
    ok(one(f"SELECT approver_id FROM requests WHERE request_id={rid}") == '3', 'approver is the HoD of the department (hos002)')
    ok(one(f"SELECT COUNT(*) FROM request_vehicles WHERE request_id={rid} AND active=1") == '1', 'one car reserved')
    ok(one(f"SELECT COUNT(*) FROM notifications WHERE user_id=3 AND link LIKE '%id={rid}'") == '1', 'HoD notified (UC019)')
    # missing supporting letter
    h2 = stf.post('staff/request_vehicle.php', {'depart_date': day(5), 'depart_time': '09:00', 'return_date': day(5), 'return_time': '16:00',
              'vehicle_type': 'Car', 'quantity': '1', 'driver_option': 'with_driver', 'purpose_type': 'official', 'purpose': 'x',
              'destination': 'x', 'address': 'x', 'waiting_location': 'x', 'passengers': '1'})
    ok('supporting letter is required' in h2, 'Official without supporting letter is refused')

    hod = User('hos002', 'hos002@uis')
    hod.get(f'staff/request.php?id={rid}')
    hod.post('staff/request.php', {'id': rid, 'action': 'hod_approve'})
    ok(one(f"SELECT status FROM requests WHERE request_id={rid}") == 'hod_approved', 'HoD approved')

    adm = User('admin', 'admin123')
    html = adm.get(f'admin/assign_driver.php?id={rid}')
    rv = one(f"SELECT rv_id FROM request_vehicles WHERE request_id={rid}")
    opts = re.findall(r'<option value="(\d+)" data-tasks', html)
    ok(len(opts) >= 2, 'recommended drivers listed')
    adm.post('admin/assign_driver.php', {'id': rid, 'task_hours': '1', f'plan[{rv}][outbound]': opts[0], f'same[{rv}]': '1'})
    ok(one(f"SELECT status FROM requests WHERE request_id={rid}") == 'driver_assigned', 'Driver Assigned')
    ok(one(f"SELECT COUNT(*) FROM tasks WHERE request_id={rid}") == '2', 'outbound and return tasks created')
    hoa = User('head001', 'head001@uis')
    hoa.get('admin/approvals.php')
    hoa.post('admin/approvals.php', {'id': rid, 'action': 'assign_reject', 'reason': 'Driver on leave'})
    ok(one(f"SELECT status FROM requests WHERE request_id={rid}") == 'hod_approved', 'HoA reject returns the task to the Assistant (UC026 A1)')
    ok(one(f"SELECT COUNT(*) FROM request_vehicles WHERE request_id={rid} AND active=1") == '1', 'reservation kept after a returned assignment')
    adm.get(f'admin/assign_driver.php?id={rid}')
    adm.post('admin/assign_driver.php', {'id': rid, 'task_hours': '1', f'plan[{rv}][outbound]': opts[1], f'plan[{rv}][return]': opts[0]})
    hoa.get('admin/approvals.php')
    hoa.post('admin/approvals.php', {'id': rid, 'action': 'assign_approve'})
    ok(one(f"SELECT status FROM requests WHERE request_id={rid}") == 'approved', 'HoA approved: request Approved')
    did = opts[1]
    duser = one(f"SELECT u.staff_no FROM drivers d JOIN users u ON u.user_id=d.user_id WHERE d.driver_id={did}")
    tid = one(f"SELECT task_id FROM tasks WHERE request_id={rid} AND trip_type='outbound'")
    drv = User(duser, 'driver%03d@uis' % int(duser[3:]))
    ok(f'task.php?id={tid}' in drv.get('driver/tasks.php'), 'driver sees the approved task')
    drv.get(f'driver/task.php?id={tid}')

    print('\nUC008 Replace Driver -> UC026')
    adm.get(f'admin/replace_driver.php?task={tid}')
    newd = one(f"SELECT d.driver_id FROM drivers d JOIN users u ON u.user_id=d.user_id WHERE u.status='active' AND d.driver_id NOT IN ({opts[0]},{opts[1]}) AND d.licence_expiry > CURDATE() LIMIT 1")
    adm.post('admin/replace_driver.php', {'task': tid, 'driver_id': newd, 'reason': 'Medical leave'})
    rpl = one(f"SELECT replacement_id FROM driver_replacements WHERE task_id={tid}")
    ok(rpl is not None, 'replacement recorded')
    hoa.post('admin/approvals.php?tab=replacement', {'id': rpl, 'action': 'replacement_approve'})
    ok(one(f"SELECT driver_id FROM tasks WHERE task_id={tid}") == newd, 'replacement approved: task has the new driver')

    print('\nUC024 Create / Update Task Report')
    sql(f"UPDATE tasks SET start_dt = DATE_SUB(NOW(), INTERVAL 2 HOUR), end_dt = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE task_id={tid}")
    nuser = one(f"SELECT u.staff_no FROM drivers d JOIN users u ON u.user_id=d.user_id WHERE d.driver_id={newd}")
    nd = User(nuser, 'driver%03d@uis' % int(nuser[3:]))
    nd.get(f'driver/task.php?id={tid}&report=1')
    mil = int(one(f"SELECT current_mileage FROM vehicles v JOIN tasks t ON t.vehicle_id=v.vehicle_id WHERE t.task_id={tid}"))
    h3 = nd.post('driver/task.php', {'id': tid, 'action': 'report', 'actual_depart': day(0) + 'T08:00', 'actual_return': day(0) + 'T09:00',
                                     'end_mileage': str(mil - 5), 'fuel_level': '1/2', 'vehicle_condition': 'good'})
    ok('mileage is not valid' in h3, 'lower end mileage refused')
    nd.post('driver/task.php', {'id': tid, 'action': 'report', 'actual_depart': day(0) + 'T08:00', 'actual_return': day(0) + 'T09:00',
                                'end_mileage': str(mil + 80), 'fuel_level': '1/2', 'vehicle_condition': 'problem', 'problem_type': 'Tyre',
                                'problem_desc': 'Flat tyre', 'can_be_used': '0'})
    ok(one(f"SELECT status FROM tasks WHERE task_id={tid}") == 'completed', 'task Completed')
    ok(one(f"SELECT current_mileage FROM vehicles v JOIN tasks t ON t.vehicle_id=v.vehicle_id WHERE t.task_id={tid}") == str(mil + 80), 'vehicle mileage updated')
    ok(one(f"SELECT v.status FROM vehicles v JOIN tasks t ON t.vehicle_id=v.vehicle_id WHERE t.task_id={tid}") == 'maintenance', 'unusable vehicle set Under Maintenance')
    nd.post('driver/task.php', {'id': tid, 'action': 'report', 'actual_depart': day(0) + 'T08:00', 'actual_return': day(0) + 'T09:10',
                                'end_mileage': str(mil + 85), 'fuel_level': '1/4', 'vehicle_condition': 'good'})
    ok(one(f"SELECT end_mileage FROM task_reports WHERE task_id={tid}") == str(mil + 85), 'report updated (UC024C)')

    print('\nUC021 Cancel, UC025 Reject, Lapse')
    stf.post('staff/request_vehicle.php', {'depart_date': day(6), 'depart_time': '09:00', 'return_date': day(6), 'return_time': '12:00',
              'vehicle_type': 'Van', 'quantity': '1', 'driver_option': 'with_driver', 'purpose_type': 'unofficial', 'purpose': 'Visit',
              'destination': 'Klang', 'address': 'Klang', 'waiting_location': 'Lobby', 'passengers': '5'}, files={'document': pdf})
    r2 = one("SELECT MAX(request_id) FROM requests")
    stf.post('staff/request.php', {'id': r2, 'action': 'cancel', 'reason': ''})
    ok(one(f"SELECT status FROM requests WHERE request_id={r2}") == 'pending', 'cancel without a reason refused')
    stf.post('staff/request.php', {'id': r2, 'action': 'cancel', 'reason': 'Trip postponed'})
    ok(one(f"SELECT status FROM requests WHERE request_id={r2}") == 'cancelled', 'cancelled with a reason')
    ok(one(f"SELECT COUNT(*) FROM request_vehicles WHERE request_id={r2} AND active=1") == '0', 'reservation released')
    stf.post('staff/request_vehicle.php', {'depart_date': day(6), 'depart_time': '09:00', 'return_date': day(6), 'return_time': '12:00',
              'vehicle_type': 'MPV', 'quantity': '1', 'driver_option': 'with_driver', 'purpose_type': 'official', 'purpose': 'Visit',
              'destination': 'Klang', 'address': 'Klang', 'waiting_location': 'Lobby', 'passengers': '5'}, files={'document': pdf})
    r3 = one("SELECT MAX(request_id) FROM requests")
    hod.post('staff/request.php', {'id': r3, 'action': 'hod_reject', 'reason': 'Use the faculty van'})
    ok(one(f"SELECT status FROM requests WHERE request_id={r3}") == 'rejected', 'HoD rejected with a reason')
    stf.post('staff/request_vehicle.php', {'depart_date': day(9), 'depart_time': '09:00', 'return_date': day(9), 'return_time': '12:00',
              'vehicle_type': 'MPV', 'quantity': '1', 'driver_option': 'with_driver', 'purpose_type': 'official', 'purpose': 'Visit',
              'destination': 'Klang', 'address': 'Klang', 'waiting_location': 'Lobby', 'passengers': '5'}, files={'document': pdf})
    r4 = one("SELECT MAX(request_id) FROM requests")
    sql(f"UPDATE requests SET created_at = DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE request_id={r4}")
    subprocess.run(['php', ROOT + '/cron/run_jobs.php'], check=True, capture_output=True)
    ok(one(f"SELECT reminder_sent_at IS NOT NULL FROM requests WHERE request_id={r4}") == '1', 'reminder email after one day (UC020)')
    sql(f"UPDATE requests SET created_at = DATE_SUB(NOW(), INTERVAL 49 HOUR) WHERE request_id={r4}")
    subprocess.run(['php', ROOT + '/cron/run_jobs.php'], check=True, capture_output=True)
    ok(one(f"SELECT status FROM requests WHERE request_id={r4}") == 'lapsed', 'lapsed after two days')

    print('\nUC018 A1 Pay and Claim, A5 vendor buses, A2/A3 Personal Self Drive lorry')
    stf.post('staff/request_vehicle.php', {'depart_date': day(7), 'depart_time': '09:00', 'return_date': day(7), 'return_time': '12:00',
              'vehicle_type': 'Car', 'driver_option': 'pay_claim', 'purpose_type': 'official', 'purpose': 'Seminar',
              'destination': 'Shah Alam', 'address': 'Shah Alam', 'waiting_location': '-', 'passengers': '1'}, files={'document': pdf})
    r5 = one("SELECT MAX(request_id) FROM requests")
    ok(one(f"SELECT COUNT(*) FROM request_vehicles WHERE request_id={r5}") == '0', 'Pay and Claim reserves no vehicle')
    hod.post('staff/request.php', {'id': r5, 'action': 'hod_approve'})
    hoa.post('admin/approvals.php', {'id': r5, 'action': 'request_approve'})
    ok(one(f"SELECT status FROM requests WHERE request_id={r5}") == 'approved', 'Pay and Claim approved by the HoA (UC025 A2)')
    ok('APPROVAL OF VEHICLE USE' in stf.get(f'staff/approval_letter.php?id={r5}'), 'approval letter printable (UC017 A3)')

    stf.post('staff/request_vehicle.php', {'depart_date': day(8), 'depart_time': '07:00', 'return_date': day(8), 'return_time': '18:00',
              'vehicle_type': 'Bus', 'quantity': '5', 'driver_option': 'with_driver', 'purpose_type': 'official', 'purpose': 'Student trip',
              'destination': 'Melaka', 'address': 'Melaka', 'waiting_location': 'Main entrance', 'passengers': '200'}, files={'document': pdf})
    r6 = one("SELECT MAX(request_id) FROM requests")
    ok(one(f"SELECT COUNT(*) FROM request_vehicles WHERE request_id={r6} AND is_vendor=1") == '2', 'missing buses marked Vendor Bus Needed')
    hod.post('staff/request.php', {'id': r6, 'action': 'hod_approve'})
    html = adm.get(f'admin/assign_driver.php?id={r6}')
    data = {'id': r6, 'task_hours': '2'}
    used = set()
    for rvid, vendor in sql(f"SELECT rv_id, is_vendor FROM request_vehicles WHERE request_id={r6}"):
        if vendor == '1':
            data[f'plan[{rvid}][vendor_name]'] = 'Syarikat Bas Maju'
            data[f'plan[{rvid}][vendor_plate]'] = 'VND ' + rvid
            continue
        sel = re.search(r'name="plan\[' + rvid + r'\]\[outbound\]".*?</select>', html, re.S).group(0)
        pick = [x for x in re.findall(r'<option value="(\d+)" data-tasks', sel) if x not in used][0]
        used.add(pick)
        data[f'plan[{rvid}][outbound]'] = pick
        data[f'same[{rvid}]'] = '1'
    adm.post('admin/assign_driver.php', data)
    ok(one(f"SELECT status FROM requests WHERE request_id={r6}") == 'driver_assigned', 'bus request assigned with vendor buses')
    ok(one(f"SELECT COUNT(*) FROM request_vehicles WHERE request_id={r6} AND vendor_plate IS NOT NULL") == '2', 'vendor details recorded')

    d1 = User('drv001', 'driver001@uis')
    ok('self_drive' in d1.get(f'staff/request_vehicle.php?depart_date={day(10)}&depart_time=08:00&return_date={day(10)}&return_time=17:00'), 'Self Drive offered to a GDL driver')
    ok('data-options="with_driver"' in stf.get(f'staff/request_vehicle.php?depart_date={day(10)}&depart_time=08:00&return_date={day(10)}&return_time=17:00')
       and 'self_drive"' not in re.search(r'value="Lorry".*?>', stf.get(f'staff/request_vehicle.php?depart_date={day(10)}&depart_time=08:00&return_date={day(10)}&return_time=17:00')).group(0),
       'Self Drive not offered to other users')
    d1.post('staff/request_vehicle.php', {'depart_date': day(10), 'depart_time': '08:00', 'return_date': day(10), 'return_time': '17:00',
             'vehicle_type': 'Lorry', 'driver_option': 'self_drive', 'purpose_type': 'personal', 'purpose': 'Moving house',
             'destination': 'Kajang', 'address': 'Kajang', 'waiting_location': 'Vehicle yard', 'passengers': '1', 'distance_km': '60'})
    r7 = one("SELECT MAX(request_id) FROM requests")
    ok(one(f"SELECT CONCAT(approver_id IS NULL, charge) FROM requests WHERE request_id={r7}") == '1180.00', 'Personal: no HoD, charge 60 km x RM3')
    adm.post('admin/assign_driver.php', {'id': r7, 'task_hours': '1', 'distance_km': '70'})
    ok(one(f"SELECT charge FROM requests WHERE request_id={r7}") == '210.00', 'Assistant corrected the distance and the charge')
    hoa.post('admin/approvals.php', {'id': r7, 'action': 'request_approve'})
    ok(one(f"SELECT status FROM requests WHERE request_id={r7}") == 'approved', 'Personal Self Drive approved by the HoA')

    print('\nUC004/UC005 internal task, UC006/UC007 welfare duty')
    adm.post('admin/internal_tasks.php', {'action': 'save', 'task_id': '0', 'task_type': 'workshop', 'task_date': day(4), 'start_time': '09:00', 'end_time': '11:00',
                                          'vehicle_id': '2', 'description': 'Service'})
    it = one("SELECT MAX(task_id) FROM tasks WHERE task_source='internal'")
    ok(one(f"SELECT status FROM tasks WHERE task_id={it}") == 'waiting', 'internal task Waiting for Driver')
    html = adm.get(f'admin/internal_tasks.php?assign={it}')
    pick = re.findall(r'<option value="(\d+)" data-tasks', html)[0]
    adm.post('admin/internal_tasks.php', {'action': 'assign', 'task_id': it, 'driver_id': pick})
    hoa.post('admin/approvals.php?tab=internal', {'id': it, 'action': 'internal_approve'})
    ok(one(f"SELECT status FROM tasks WHERE task_id={it}") == 'approved', 'internal task approved')
    nxt = (dt.date.today() + dt.timedelta(days=7 - dt.date.today().weekday())).isoformat()
    vans = {(dt.date.fromisoformat(nxt) + dt.timedelta(days=i)).isoformat(): ('2' if i % 2 else '6') for i in range(7)}
    data = {'action': 'create', 'week': nxt}
    data.update({f'vehicle[{d}]': v for d, v in vans.items()})
    adm.post(f'admin/welfare.php?week={nxt}', data)
    ok(one(f"SELECT COUNT(*) FROM tasks t JOIN welfare_weeks w ON w.welfare_id=t.welfare_id WHERE w.week_start_date='{nxt}'") == '7', 'welfare week created (7 days)')
    html = adm.get(f'admin/welfare.php?week={nxt}')
    data = {'action': 'assign', 'week': nxt}
    for tsk, sel in re.findall(r'name="driver\[(\d+)\]".*?>(.*?)</select>', html, re.S):
        data[f'driver[{tsk}]'] = re.findall(r'<option value="(\d+)" data-tasks', sel)[0]
    adm.post('admin/welfare.php', data)
    wid = one(f"SELECT welfare_id FROM welfare_weeks WHERE week_start_date='{nxt}'")
    ok(one(f"SELECT status FROM welfare_weeks WHERE welfare_id={wid}") == 'driver_assigned', 'welfare drivers assigned')
    hoa.post('admin/approvals.php?tab=welfare', {'id': wid, 'action': 'welfare_approve'})
    ok(one(f"SELECT status FROM welfare_weeks WHERE welfare_id={wid}") == 'approved', 'welfare week approved')

    print('\nUC022/UC027 schedule change')
    dvid = one(f"SELECT driver_id FROM tasks WHERE task_id={it}")
    du = one(f"SELECT u.staff_no FROM drivers d JOIN users u ON u.user_id=d.user_id WHERE d.driver_id={dvid}")
    dd = User(du, 'driver%03d@uis' % int(du[3:]))
    dd.post('driver/schedule_change.php', {'action': 'new', 'start_date': day(4), 'end_date': day(4), 'reason': 'Family matter'})
    sc = one("SELECT MAX(change_id) FROM schedule_changes")
    hoa.post('admin/schedule_changes.php', {'id': sc, 'action': 'approve'})
    ok(one(f"SELECT status FROM schedule_changes WHERE change_id={sc}") == 'approved', 'schedule change approved')
    ok(f'replace_driver.php?task={it}' in adm.get('admin/schedule.php?view=replace'), 'affected task listed for replacement')
    html = adm.get(f'admin/replace_driver.php?task={it}')
    ok(f'<option value="{dvid}"' not in html, 'driver with the schedule change is not offered')

    print('\nUC001 accounts, UC013 register, UC015 reset, UC016 profile')
    adm.post('admin/add_driver.php', {'staff_no': 'DRV013', 'full_name': 'Test Driver', 'email': 'test.driver@uis.edu.my', 'phone': '012-1112222',
                                      'licence_classes[]': ['D', 'GDL'], 'licence_expiry': day(400), 'start_date': '2020-01-01'})
    ok(one("SELECT role FROM users WHERE staff_no='DRV013'") == 'driver', 'driver account created (UC001A)')
    adm.post('admin/users.php', {'action': 'confirm_role', 'user_id': '23'})
    ok(one("SELECT role FROM users WHERE user_id=23") == 'supervisor', 'pending Head of Department role confirmed')
    h4 = adm.post('admin/users.php', {'action': 'deactivate', 'user_id': newd and one(f"SELECT user_id FROM drivers WHERE driver_id={dvid}")})
    ok(one(f"SELECT u.status FROM drivers d JOIN users u ON u.user_id=d.user_id WHERE d.driver_id={dvid}") == 'active', 'driver with upcoming tasks not deactivated')
    reg = requests.Session()
    page = reg.get(BASE + '/register.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', page).group(1)
    page = reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'request_code', 'staff_no': 'STF900', 'email': 'new.staff@gmail.com'}).text
    ok('Only UIS email is accepted' in page, 'non-UIS email refused')
    page = reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'request_code', 'staff_no': 'STF900', 'email': 'new.staff@uis.edu.my'}).text
    code = re.search(r'your code is (\d{6})', page).group(1)
    reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'verify', 'code': '000000' if code != '000000' else '111111'})
    page = reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'verify', 'code': code}).text
    page = reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'register', 'full_name': 'New Staff', 'phone': '012-3334444', 'department_id': '3',
                                                    'role': 'supervisor', 'password': 'abc12345', 'password_confirm': 'abc12345'}).text
    ok('already has a Head of Department' in page, 'HoD for a department that has one is refused')
    reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'register', 'full_name': 'New Staff', 'phone': '012-3334444', 'department_id': '7',
                                             'role': 'supervisor', 'password': 'abc12345', 'password_confirm': 'abc12345'})
    ok(one("SELECT CONCAT(role, '/', pending_role) FROM users WHERE staff_no='STF900'") == 'staff/supervisor', 'registered as Staff with the HoD role pending')
    User('STF900', 'abc12345')
    fp = requests.Session()
    page = fp.get(BASE + '/forgot_password.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', page).group(1)
    page = fp.post(BASE + '/forgot_password.php', data={'csrf_token': tok, 'action': 'request_code', 'staff_no': 'STF900', 'email': 'new.staff@uis.edu.my'}).text
    code = re.search(r'your code is (\d{6})', page).group(1)
    fp.post(BASE + '/forgot_password.php', data={'csrf_token': tok, 'action': 'verify', 'code': code})
    fp.post(BASE + '/forgot_password.php', data={'csrf_token': tok, 'action': 'save', 'password': 'newpass123', 'password_confirm': 'newpass123'})
    u9 = User('STF900', 'newpass123')
    u9.get('profile.php')
    u9.post('profile.php', {'action': 'profile', 'full_name': 'New Staff Name', 'phone': '012-9998888', 'address': 'Bangi'})
    ok(one("SELECT full_name FROM users WHERE staff_no='STF900'") == 'New Staff Name', 'profile updated (UC016B)')
    u9.post('profile.php', {'action': 'password', 'current_password': 'newpass123', 'new_password': 'final1234', 'confirm_password': 'final1234'})
    User('STF900', 'final1234')

    print('\nUC002 vehicles, UC011/UC012 reports')
    adm.post('admin/add_vehicle.php', {'plate_number': 'VAA 1234', 'vehicle_type': 'Van', 'brand': 'Toyota', 'model': 'Hiace', 'fuel_type': 'Diesel', 'capacity': '12',
                                       'licence_required': 'D', 'current_mileage': '1000', 'fuel_level': 'Full'})
    vid = one("SELECT vehicle_id FROM vehicles WHERE plate_number='VAA 1234'")
    ok(vid is not None, 'vehicle added')
    adm.post('admin/edit_vehicle.php', {'vehicle_id': vid, 'current_mileage': '900', 'fuel_level': 'Full', 'status': 'active'})
    ok(one(f"SELECT current_mileage FROM vehicles WHERE vehicle_id={vid}") == '1000', 'lower mileage refused')
    welfare_vid = one(f"SELECT vehicle_id FROM tasks WHERE welfare_id={wid} LIMIT 1")
    adm.post('admin/vehicles.php', {'action': 'deactivate', 'vehicle_id': welfare_vid})
    ok(one(f"SELECT status FROM vehicles WHERE vehicle_id={welfare_vid}") == 'active', 'vehicle with upcoming bookings not deactivated')
    for t in ['workload', 'replacement', 'vehicle']:
        for ex in ['pdf', 'xlsx']:
            r = adm.s.get(f'{BASE}/admin/reports.php?type={t}&month={dt.date.today():%Y-%m}&export={ex}')
            ok(r.status_code == 200 and len(r.content) > 300 and ('pdf' in r.headers.get('Content-Type', '') or 'sheet' in r.headers.get('Content-Type', '')), f'report {t} exported as {ex}')

    print(f'\n{PASSED} checks passed')


if __name__ == '__main__':
    main()
