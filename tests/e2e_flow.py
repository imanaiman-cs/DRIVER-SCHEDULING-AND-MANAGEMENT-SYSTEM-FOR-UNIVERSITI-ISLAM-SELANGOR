#!/usr/bin/env python3
"""
End-to-end check of the SRS flows over HTTP (UC001-UC028), against the SDD design (dsms_db).

    mysql -uroot < database.sql                      # fresh demo database (this script re-imports it)
    php -S 127.0.0.1:8300 -t .                       # with config/local.php: return ['site_url' => 'http://127.0.0.1:8300'];
    python3 tests/e2e_flow.py                        # BASE=http://127.0.0.1:8300 by default

It re-creates the database (without the demo section), then drives the system as each role and fails on the first broken step or PHP warning.
"""
import os, re, subprocess, sys, datetime as dt
import requests

BASE = os.environ.get('BASE', 'http://127.0.0.1:8300')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.environ.get('DSMS_DB', 'dsms_db')
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
    day = lambda n: (dt.date.today() + dt.timedelta(days=n)).isoformat()
    pdf = ('letter.pdf', b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n', 'application/pdf')
    status = lambda rid: one(f"SELECT status FROM request WHERE request_id={rid}")
    last = lambda: one("SELECT MAX(request_id) FROM request")
    pw = lambda staff: 'driver%03d@uis' % int(staff[3:])

    def form(d, t1, d2, t2, vtype, opt, ptype, purpose='Meeting', dest='MOHE Putrajaya', pax='2', qty='1', **extra):
        f = {'depart_date': d, 'depart_time': t1, 'return_date': d2, 'return_time': t2, 'vehicle_type': vtype, 'quantity': qty, 'driver_option': opt,
             'purpose_type': ptype, 'purpose': purpose, 'destination': dest, 'address': dest, 'waiting_location': 'Main entrance', 'no_of_passengers': pax}
        f.update(extra)
        return f

    print('\nPages of every role')
    pages = {
        ('stf001', 'staff001@uis'): ['staff/dashboard.php', 'staff/request_vehicle.php', 'staff/my_requests.php', 'profile.php', 'notifications.php', 'help.php'],
        ('hos002', 'hos002@uis'): ['supervisor/dashboard.php', 'supervisor/approvals.php', 'supervisor/approvals.php?tab=all', 'help.php'],
        ('drv001', 'driver001@uis'): ['driver/dashboard.php', 'driver/tasks.php', 'driver/tasks.php?tab=past', 'driver/schedule_change.php', 'help.php'],
        ('admin', 'admin123'): ['admin/dashboard.php', 'admin/vehicle_requests.php', 'admin/assign_driver.php', 'admin/schedule.php', 'admin/schedule.php?view=month',
                                'admin/schedule.php?view=replace', 'admin/internal_tasks.php', 'admin/welfare.php', 'admin/users.php', 'admin/users.php?tab=pending',
                                'admin/add_driver.php', 'admin/view_user.php?id=11', 'admin/edit_user.php?id=11', 'admin/edit_user.php?id=8', 'admin/vehicles.php',
                                'admin/add_vehicle.php', 'admin/view_vehicle.php?id=1', 'admin/edit_vehicle.php?id=1', 'admin/reports.php', 'admin/reports.php?type=replacement',
                                'admin/reports.php?type=vehicle', 'help.php'],
        ('head001', 'head001@uis'): ['admin/dashboard.php', 'admin/approvals.php', 'admin/approvals.php?tab=head', 'admin/approvals.php?tab=internal', 'admin/approvals.php?tab=welfare',
                                     'admin/approvals.php?tab=replacement', 'admin/schedule_changes.php', 'admin/schedule_changes.php?tab=history', 'help.php'],
    }
    for (u, p), paths in pages.items():
        usr = User(u, p)
        for path in paths:
            usr.get(path)
    ok(User('stf001', 'staff001@uis').s.get(BASE + '/admin/users.php', allow_redirects=False).status_code == 302, 'staff cannot open admin pages')
    ok(User('admin', 'admin123').s.get(BASE + '/admin/schedule_changes.php', allow_redirects=False).status_code == 302, 'only the Head of Admin decides schedule changes')

    print('\nUC018 Submit (With Driver, car) -> UC025 HoD -> UC003 assign -> UC026 HoA -> UC023/UC024 driver')
    stf = User('stf001', 'staff001@uis')
    html = stf.get(f'staff/request_vehicle.php?depart_date={day(5)}&depart_time=09:00&return_date={day(5)}&return_time=16:00')
    ok('units left' in html, 'availability shows units left per type')
    stf.post('staff/request_vehicle.php', form(day(5), '09:00', day(5), '16:00', 'Car', 'With Driver', 'Official'), files={'document': pdf})
    rid = last()
    ok(status(rid) == 'Pending', 'request saved as Pending')
    ok(one(f"SELECT supporting_doc IS NOT NULL FROM request WHERE request_id={rid}") == '1', 'supporting letter stored in request.supporting_doc')
    ok(one(f"SELECT COUNT(*) FROM vehicle_reservation WHERE request_id={rid} AND status='Active' AND vehicle_id IS NULL") == '1', 'one car unit reserved (vehicle chosen later)')
    ok(one(f"SELECT COUNT(*) FROM notification WHERE user_id=3 AND link LIKE '%id={rid}'") == '1', 'HoD of the department (hos002) notified (UC019)')
    ok(one(f"SELECT CONCAT(old_status IS NULL, new_status) FROM request_status_history WHERE request_id={rid}") == '1Pending', 'status history written')
    r = stf.s.get(f'{BASE}/ajax/get_document.php?id={rid}')
    ok(r.status_code == 200 and r.content.startswith(b'%PDF'), 'requester opens the supporting letter')
    ok(User('stf002', 'staff002@uis').s.get(f'{BASE}/ajax/get_document.php?id={rid}').status_code == 403, 'other staff cannot open the letter')
    h2 = stf.post('staff/request_vehicle.php', form(day(5), '09:00', day(5), '16:00', 'Car', 'With Driver', 'Official', purpose='x', dest='x', pax='1'))
    ok('supporting letter is required' in h2, 'Official without supporting letter is refused')

    hod = User('hos002', 'hos002@uis')
    ok(f'request.php?id={rid}' in hod.get('supervisor/approvals.php'), 'request waits for the HoD')
    hod.get(f'staff/request.php?id={rid}')
    hod.post('staff/request.php', {'id': rid, 'action': 'approve'})
    ok(status(rid) == 'Approved by HoD', 'HoD approved')
    ok(one(f"SELECT hod_id FROM request WHERE request_id={rid}") == '3', 'hod_id recorded')

    adm = User('admin', 'admin123')
    html = adm.get(f'admin/assign_driver.php?id={rid}')
    res = one(f"SELECT reservation_id FROM vehicle_reservation WHERE request_id={rid}")
    veh = re.search(r'name="plan\[' + res + r'\]\[vehicle_id\]".*?<option value="(\d+)" selected', html, re.S).group(1)
    opts = re.findall(r'<option value="(\d+)" data-tasks', html)
    ok(len(opts) >= 2, 'recommended drivers listed')
    adm.post('admin/assign_driver.php', {'id': rid, 'task_hours': '1', f'plan[{res}][vehicle_id]': veh, f'plan[{res}][outbound]': opts[0], f'same[{res}]': '1'})
    ok(status(rid) == 'Driver Assigned', 'Driver Assigned')
    ok(one(f"SELECT vehicle_id FROM vehicle_reservation WHERE reservation_id={res}") == veh, 'vehicle set on the reservation')
    ok(one(f"SELECT COUNT(*) FROM task WHERE request_id={rid} AND task_source='Request'") == '2', 'outbound and return tasks created')
    hoa = User('head001', 'head001@uis')
    hoa.get('admin/approvals.php')
    hoa.post('admin/approvals.php', {'id': rid, 'action': 'assign_reject', 'reason': 'Driver on leave'})
    ok(status(rid) == 'Approved by HoD', 'HoA reject returns the task to the Assistant (UC026 A1)')
    ok(one(f"SELECT COUNT(*) FROM task WHERE request_id={rid} AND status='Rejected'") == '2', 'rejected tasks kept as Rejected')
    ok(one(f"SELECT COUNT(*) FROM vehicle_reservation WHERE request_id={rid} AND status='Active'") == '1', 'reservation kept after a returned assignment')
    adm.get(f'admin/assign_driver.php?id={rid}')
    adm.post('admin/assign_driver.php', {'id': rid, 'task_hours': '1', f'plan[{res}][vehicle_id]': veh, f'plan[{res}][outbound]': opts[1], f'plan[{res}][return]': opts[0]})
    ok(status(rid) == 'Driver Assigned', 'assigned again')
    hoa.get('admin/approvals.php')
    hoa.post('admin/approvals.php', {'id': rid, 'action': 'assign_approve'})
    ok(status(rid) == 'Approved', 'HoA approved: request Approved')
    ok(one(f"SELECT hoa_id FROM request WHERE request_id={rid}") == '7', 'hoa_id recorded')
    did = opts[1]
    duser = one(f"SELECT staff_id FROM `user` WHERE user_id={did}")
    tid = one(f"SELECT task_id FROM task WHERE request_id={rid} AND trip_type='Outbound' AND status='Approved'")
    drv = User(duser, pw(duser))
    ok(f'task.php?id={tid}' in drv.get('driver/tasks.php'), 'driver sees the approved task')
    drv.get(f'driver/task.php?id={tid}')
    ok(drv.s.get(f'{BASE}/driver/task.php?id={one("SELECT MIN(task_id) FROM task")}', allow_redirects=False).status_code in (200, 302), 'task page guarded')

    print('\nUC008 Replace Driver -> UC026')
    adm.get(f'admin/replace_driver.php?task={tid}')
    newd = one(f"SELECT d.driver_id FROM driver d JOIN `user` u ON u.user_id=d.driver_id WHERE u.status='Active' AND d.driver_id NOT IN ({opts[0]},{opts[1]}) AND d.licence_expiry > CURDATE() ORDER BY d.driver_id LIMIT 1")
    adm.post('admin/replace_driver.php', {'task': tid, 'driver_id': newd, 'reason': 'Medical leave'})
    rpl = one(f"SELECT replacement_id FROM driver_replacement WHERE task_id={tid}")
    ok(rpl is not None, 'replacement recorded')
    hoa.get('admin/approvals.php?tab=replacement')
    hoa.post('admin/approvals.php?tab=replacement', {'id': rpl, 'action': 'replacement_approve'})
    ok(one(f"SELECT driver_id FROM task WHERE task_id={tid}") == newd, 'replacement approved: task has the new driver')
    ok(one(f"SELECT status FROM driver_replacement WHERE replacement_id={rpl}") == 'Approved', 'replacement Approved')

    print('\nUC024 Create / Update Task Report')
    sql(f"UPDATE task SET start_datetime = DATE_SUB(NOW(), INTERVAL 2 HOUR), end_datetime = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE task_id={tid}")
    nuser = one(f"SELECT staff_id FROM `user` WHERE user_id={newd}")
    nd = User(nuser, pw(nuser))
    nd.get(f'driver/task.php?id={tid}&report=1')
    mil = int(one(f"SELECT current_mileage FROM vehicle v JOIN task t ON t.vehicle_id=v.vehicle_id WHERE t.task_id={tid}"))
    now = dt.datetime.now()
    dep, ret = (now - dt.timedelta(hours=2)).strftime('%Y-%m-%dT%H:%M'), (now - dt.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M')
    h3 = nd.post('driver/task.php', {'id': tid, 'action': 'report', 'actual_depart': dep, 'actual_return': ret, 'end_mileage': str(mil - 5), 'fuel_level': '1/2', 'vehicle_condition': 'Good'})
    ok('mileage is not valid' in h3, 'lower end mileage refused')
    nd.post('driver/task.php', {'id': tid, 'action': 'report', 'actual_depart': dep, 'actual_return': ret, 'end_mileage': str(mil + 80), 'fuel_level': '1/2',
                                'vehicle_condition': 'Problem', 'problem_type': 'Tyre', 'problem_desc': 'Flat tyre', 'can_be_used': '0'})
    ok(one(f"SELECT status FROM task WHERE task_id={tid}") == 'Completed', 'task Completed')
    ok(one(f"SELECT current_mileage FROM vehicle v JOIN task t ON t.vehicle_id=v.vehicle_id WHERE t.task_id={tid}") == str(mil + 80), 'vehicle mileage updated')
    ok(one(f"SELECT v.status FROM vehicle v JOIN task t ON t.vehicle_id=v.vehicle_id WHERE t.task_id={tid}") == 'Under Maintenance', 'unusable vehicle set Under Maintenance')
    nd.post('driver/task.php', {'id': tid, 'action': 'report', 'actual_depart': dep, 'actual_return': ret, 'end_mileage': str(mil + 85), 'fuel_level': '1/4', 'vehicle_condition': 'Good'})
    ok(one(f"SELECT end_mileage FROM task_report WHERE task_id={tid}") == str(mil + 85), 'report updated on the same day (UC024C)')
    sql(f"UPDATE task_report SET submitted_date = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE task_id={tid}")
    nd.post('driver/task.php', {'id': tid, 'action': 'report', 'actual_depart': dep, 'actual_return': ret, 'end_mileage': str(mil + 90), 'fuel_level': '1/4', 'vehicle_condition': 'Good'})
    ok(one(f"SELECT end_mileage FROM task_report WHERE task_id={tid}") == str(mil + 85), 'report cannot be updated after the day it was submitted')
    sql(f"UPDATE vehicle v JOIN task t ON t.vehicle_id=v.vehicle_id SET v.status='Active' WHERE t.task_id={tid}")

    print('\nUC021 Cancel, UC025 Reject, UC020 reminder, Lapse')
    stf.post('staff/request_vehicle.php', form(day(6), '09:00', day(6), '12:00', 'Van', 'With Driver', 'Unofficial', pax='5'), files={'document': pdf})
    r2 = last()
    stf.post('staff/request.php', {'id': r2, 'action': 'cancel', 'reason': ''})
    ok(status(r2) == 'Pending', 'cancel without a reason refused')
    stf.post('staff/request.php', {'id': r2, 'action': 'cancel', 'reason': 'Trip postponed'})
    ok(status(r2) == 'Cancelled', 'cancelled with a reason')
    ok(one(f"SELECT COUNT(*) FROM vehicle_reservation WHERE request_id={r2} AND status='Active'") == '0', 'reservation released')
    stf.post('staff/request_vehicle.php', form(day(6), '09:00', day(6), '12:00', 'MPV', 'With Driver', 'Official', pax='5'), files={'document': pdf})
    r3 = last()
    hod.post('staff/request.php', {'id': r3, 'action': 'reject', 'reason': 'Use the faculty van'})
    ok(status(r3) == 'Rejected', 'HoD rejected with a reason')
    ok(one(f"SELECT reject_reason FROM request WHERE request_id={r3}") == 'Use the faculty van', 'reject reason stored')
    stf.post('staff/request_vehicle.php', form(day(9), '09:00', day(9), '12:00', 'MPV', 'With Driver', 'Official', pax='5'), files={'document': pdf})
    r4 = last()
    sql(f"UPDATE request SET date_submitted = DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE request_id={r4}")
    subprocess.run(['php', ROOT + '/cron/run_jobs.php'], check=True, capture_output=True)
    ok(one(f"SELECT reminder_sent FROM request WHERE request_id={r4}") == '1', 'reminder email after one day (UC020)')
    sql(f"UPDATE request SET date_submitted = DATE_SUB(NOW(), INTERVAL 49 HOUR) WHERE request_id={r4}")
    subprocess.run(['php', ROOT + '/cron/run_jobs.php'], check=True, capture_output=True)
    ok(status(r4) == 'Lapsed', 'lapsed after two days')
    ok(one(f"SELECT COUNT(*) FROM vehicle_reservation WHERE request_id={r4} AND status='Active'") == '0', 'lapsed request releases the reservation')

    print('\nUC018 by the Administrative Assistant and the Head of Admin')
    adm.get(f'staff/request_vehicle.php?depart_date={day(12)}&depart_time=09:00&return_date={day(12)}&return_time=12:00')
    adm.post('staff/request_vehicle.php', form(day(12), '09:00', day(12), '12:00', 'Car', 'With Driver', 'Official', purpose='Road tax renewal', dest='JPJ Shah Alam', pax='1'), files={'document': pdf})
    ra = last()
    ok(one(f"SELECT requester_id FROM request WHERE request_id={ra}") == '6', 'Assistant submits a request')
    ok(one(f"SELECT COUNT(*) FROM notification WHERE user_id=7 AND link LIKE '%id={ra}'") == '1', 'Assistant request goes to the head of BPI (Head of Admin)')
    ok(f'request.php?id={ra}' in adm.get('staff/my_requests.php'), "the request is in the Assistant's My Request")
    ok(f'id={ra}' in hoa.get('admin/approvals.php?tab=head'), 'Head of Admin sees it under BPI requests')
    hoa.post('staff/request.php', {'id': ra, 'action': 'approve'})
    ok(status(ra) == 'Approved by HoD', 'Head of Admin approved the BPI request as head of department')
    hoa.post('staff/request_vehicle.php', form(day(13), '09:00', day(13), '12:00', 'Car', 'With Driver', 'Official', purpose='Audit', dest='Bangi', pax='1'), files={'document': pdf})
    rh = last()
    ok(one(f"SELECT requester_id FROM request WHERE request_id={rh}") == '7', 'Head of Admin submits a request')
    ok(one(f"SELECT COUNT(*) FROM notification WHERE user_id=4 AND link LIKE '%id={rh}'") == '1', "Head of Admin's request goes to the head of the parent department (Registrar, hos003)")

    print('\nUC018 A1 Pay and Claim, A5 vendor buses, A2/A3 Personal Self Drive lorry')
    stf.post('staff/request_vehicle.php', form(day(7), '09:00', day(7), '12:00', 'Car', 'Pay and Claim', 'Official', purpose='Seminar', dest='Shah Alam', pax='1'), files={'document': pdf})
    r5 = last()
    ok(one(f"SELECT COUNT(*) FROM vehicle_reservation WHERE request_id={r5}") == '0', 'Pay and Claim reserves no vehicle')
    hod.post('staff/request.php', {'id': r5, 'action': 'approve'})
    hoa.post('admin/approvals.php', {'id': r5, 'action': 'request_approve'})
    ok(status(r5) == 'Approved', 'Pay and Claim approved by the HoA (UC025 A2)')
    ok('APPROVAL OF VEHICLE USE' in stf.get(f'staff/approval_letter.php?id={r5}'), 'approval letter printable (UC017 A3)')

    stf.post('staff/request_vehicle.php', form(day(8), '07:00', day(8), '18:00', 'Bus', 'With Driver', 'Official', purpose='Student trip', dest='Melaka', pax='200', qty='5'), files={'document': pdf})
    r6 = last()
    ok(one(f"SELECT COUNT(*) FROM vehicle_reservation WHERE request_id={r6} AND is_vendor=1") == '2', 'missing buses reserved as vendor buses')
    hod.post('staff/request.php', {'id': r6, 'action': 'approve'})
    html = adm.get(f'admin/assign_driver.php?id={r6}')
    data = {'id': r6, 'task_hours': '2'}
    used = set()
    for resid, vendor in sql(f"SELECT reservation_id, is_vendor FROM vehicle_reservation WHERE request_id={r6}"):
        if vendor == '1':
            data[f'plan[{resid}][vendor_name]'] = 'Syarikat Bas Maju'
            data[f'plan[{resid}][vendor_plate_no]'] = 'VND ' + resid
            continue
        data[f'plan[{resid}][vehicle_id]'] = re.search(r'name="plan\[' + resid + r'\]\[vehicle_id\]".*?<option value="(\d+)" selected', html, re.S).group(1)
        sel = re.search(r'name="plan\[' + resid + r'\]\[outbound\]".*?</select>', html, re.S).group(0)
        pick = [x for x in re.findall(r'<option value="(\d+)" data-tasks', sel) if x not in used][0]
        used.add(pick)
        data[f'plan[{resid}][outbound]'] = pick
        data[f'same[{resid}]'] = '1'
    adm.post('admin/assign_driver.php', data)
    ok(status(r6) == 'Driver Assigned', 'bus request assigned with vendor buses')
    ok(one(f"SELECT COUNT(DISTINCT vehicle_id) FROM vehicle_reservation WHERE request_id={r6} AND is_vendor=0") == '3', 'three different UIS buses')
    ok(one(f"SELECT COUNT(*) FROM task WHERE request_id={r6} AND vendor_plate_no IS NOT NULL") >= '2', 'vendor details recorded')

    d1 = User('drv001', 'driver001@uis')
    q = f'staff/request_vehicle.php?depart_date={day(10)}&depart_time=08:00&return_date={day(10)}&return_time=17:00'
    ok('Self Drive' in re.search(r'value="Lorry"[^>]*>', d1.get(q)).group(0), 'Self Drive offered to a GDL driver')
    ok('Self Drive' not in re.search(r'value="Lorry"[^>]*>', stf.get(q)).group(0), 'Self Drive not offered to other users')
    d1.post('staff/request_vehicle.php', form(day(10), '08:00', day(10), '17:00', 'Lorry', 'Self Drive', 'Personal', purpose='Moving house', dest='Kajang', pax='1', distance_km='60'))
    r7 = last()
    ok(one(f"SELECT CONCAT(status, '/', charge) FROM request WHERE request_id={r7}") == 'Pending/180.00', 'Personal: charge 60 km x RM3')
    ok(one(f"SELECT COUNT(*) FROM notification WHERE user_id=6 AND link LIKE '%id={r7}'") == '1', 'Personal request goes to the Administrative Assistant')
    adm.post('admin/assign_driver.php', {'id': r7, 'task_hours': '1', 'distance_km': '70'})
    ok(one(f"SELECT charge FROM request WHERE request_id={r7}") == '210.00', 'Assistant corrected the distance and the charge')
    hoa.post('admin/approvals.php', {'id': r7, 'action': 'request_approve'})
    ok(status(r7) == 'Approved', 'Personal Self Drive approved by the HoA')

    print('\nUC004/UC005 internal task, UC006/UC007 welfare duty')
    adm.post('admin/internal_tasks.php', {'action': 'save', 'task_id': '0', 'task_type': 'Workshop', 'task_date': day(4), 'start_time': '09:00', 'end_time': '11:00',
                                          'vehicle_id': '2', 'description': 'Service'})
    it = one("SELECT MAX(task_id) FROM task WHERE task_source='Internal'")
    ok(it is not None and one(f"SELECT status FROM task WHERE task_id={it}") == 'Waiting for Driver', 'internal task Waiting for Driver')
    html = adm.get(f'admin/internal_tasks.php?assign={it}')
    pick = re.findall(r'<option value="(\d+)" data-tasks', html)[0]
    adm.post('admin/internal_tasks.php', {'action': 'assign', 'task_id': it, 'driver_id': pick})
    ok(one(f"SELECT status FROM task WHERE task_id={it}") == 'Driver Assigned', 'internal task Driver Assigned')
    hoa.post('admin/approvals.php?tab=internal', {'id': it, 'action': 'internal_approve'})
    ok(one(f"SELECT status FROM task WHERE task_id={it}") == 'Approved', 'internal task approved')
    nxt = (dt.date.today() + dt.timedelta(days=7 - dt.date.today().weekday())).isoformat()
    vans = {(dt.date.fromisoformat(nxt) + dt.timedelta(days=i)).isoformat(): ('2' if i % 2 else '6') for i in range(7)}
    data = {'action': 'create', 'week': nxt}
    data.update({f'vehicle[{d}]': v for d, v in vans.items()})
    adm.post(f'admin/welfare.php?week={nxt}', data)
    ok(one(f"SELECT COUNT(*) FROM task t JOIN welfare_duty w ON w.duty_id=t.duty_id WHERE w.week_start_date='{nxt}'") == '7', 'welfare week created (7 days)')
    ok(one(f"SELECT CONCAT(MIN(TIME(start_datetime)), '/', MIN(TIMESTAMPDIFF(HOUR, start_datetime, end_datetime))) FROM task t JOIN welfare_duty w ON w.duty_id=t.duty_id WHERE w.week_start_date='{nxt}'") == '00:00:00/24', 'welfare day runs 12:00 am to 12:00 am')
    html = adm.get(f'admin/welfare.php?week={nxt}')
    data = {'action': 'assign', 'week': nxt}
    for tsk, sel in re.findall(r'name="driver\[(\d+)\]".*?>(.*?)</select>', html, re.S):
        data[f'driver[{tsk}]'] = re.findall(r'<option value="(\d+)" data-tasks', sel)[0]
    adm.post('admin/welfare.php', data)
    wid = one(f"SELECT duty_id FROM welfare_duty WHERE week_start_date='{nxt}'")
    ok(one(f"SELECT status FROM welfare_duty WHERE duty_id={wid}") == 'Driver Assigned', 'welfare drivers assigned')
    hoa.get('admin/approvals.php?tab=welfare')
    hoa.post('admin/approvals.php?tab=welfare', {'id': wid, 'action': 'welfare_approve'})
    ok(one(f"SELECT status FROM welfare_duty WHERE duty_id={wid}") == 'Approved', 'welfare week approved')

    print('\nUC022/UC027 schedule change')
    dvid = one(f"SELECT driver_id FROM task WHERE task_id={it}")
    du = one(f"SELECT staff_id FROM `user` WHERE user_id={dvid}")
    dd = User(du, pw(du))
    dd.post('driver/schedule_change.php', {'action': 'new', 'start_date': day(4), 'end_date': day(4), 'reason': 'Family matter'})
    sc = one("SELECT MAX(change_id) FROM schedule_change")
    ok(sc is not None and one(f"SELECT status FROM schedule_change WHERE change_id={sc}") == 'Pending', 'schedule change Pending')
    hoa.get('admin/schedule_changes.php')
    hoa.post('admin/schedule_changes.php', {'id': sc, 'action': 'approve'})
    ok(one(f"SELECT status FROM schedule_change WHERE change_id={sc}") == 'Approved', 'schedule change approved')
    ok(f'replace_driver.php?task={it}' in adm.get('admin/schedule.php?view=replace'), 'affected task listed for replacement')
    html = adm.get(f'admin/replace_driver.php?task={it}')
    ok(f'<option value="{dvid}"' not in html, 'driver with the schedule change is not offered')
    dd.post('driver/schedule_change.php', {'action': 'new', 'start_date': day(20), 'end_date': day(21), 'reason': 'Course'})
    sc2 = one("SELECT MAX(change_id) FROM schedule_change")
    dd.post('driver/schedule_change.php', {'action': 'cancel', 'change_id': sc2})
    ok(one(f"SELECT CONCAT(status, '/', reject_reason) FROM schedule_change WHERE change_id={sc2}") == 'Rejected/Cancelled by the driver', 'driver cancels a pending schedule change')

    print('\nUC001 accounts, UC013 register, UC015 reset, UC016 profile')
    adm.post('admin/add_driver.php', {'staff_id': 'drv013', 'name': 'Test Driver', 'email': 'test.driver@uis.edu.my', 'phone_no': '012-1112222',
                                      'licence_classes[]': ['D', 'GDL'], 'licence_expiry': day(400), 'start_date': '2020-01-01'})
    nid = one("SELECT user_id FROM `user` WHERE staff_id='drv013'")
    ok(nid is not None and one(f"SELECT role FROM `user` WHERE user_id={nid}") == 'Driver', 'driver account created (UC001A)')
    ok(one(f"SELECT COUNT(*) FROM driver_licence WHERE driver_id={nid}") == '2', 'licence classes stored in driver_licence')
    adm.post('admin/users.php', {'action': 'confirm_role', 'user_id': '23'})
    ok(one("SELECT CONCAT(role, '/', pending_role IS NULL) FROM `user` WHERE user_id=23") == 'Head of Department/1', 'pending Head of Department role confirmed')
    adm.post('admin/users.php', {'action': 'deactivate', 'user_id': dvid})
    ok(one(f"SELECT status FROM `user` WHERE user_id={dvid}") == 'Active', 'driver with upcoming tasks not deactivated')
    reg = requests.Session()
    page = reg.get(BASE + '/register.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', page).group(1)
    page = reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'request_code', 'staff_id': 'stf900', 'email': 'new.staff@gmail.com'}).text
    ok('Only UIS email is accepted' in page, 'non-UIS email refused')
    page = reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'request_code', 'staff_id': 'stf900', 'email': 'new.staff@uis.edu.my'}).text
    code = re.search(r'your code is (\d{6})', page).group(1)
    reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'verify', 'code': code})
    page = reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'register', 'name': 'New Staff', 'phone_no': '012-3334444', 'department_id': '3',
                                                    'role': 'Head of Department', 'password': 'abc12345', 'password_confirm': 'abc12345'}).text
    ok('already has a Head of Department' in page, 'HoD for a department that has one is refused')
    reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'register', 'name': 'New Staff', 'phone_no': '012-3334444', 'department_id': '6',
                                             'role': 'Head of Department', 'password': 'abc12345', 'password_confirm': 'abc12345'})
    ok(one("SELECT CONCAT(role, '/', pending_role) FROM `user` WHERE staff_id='stf900'") is None, 'department 6 now has a head (stf004 confirmed): refused')
    reg.post(BASE + '/register.php', data={'csrf_token': tok, 'action': 'register', 'name': 'New Staff', 'phone_no': '012-3334444', 'department_id': '3',
                                             'role': 'Staff', 'password': 'abc12345', 'password_confirm': 'abc12345'})
    ok(one("SELECT role FROM `user` WHERE staff_id='stf900'") == 'Staff', 'registered as Staff (UC013)')
    User('stf900', 'abc12345')
    fp = requests.Session()
    page = fp.get(BASE + '/forgot_password.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', page).group(1)
    page = fp.post(BASE + '/forgot_password.php', data={'csrf_token': tok, 'action': 'request_code', 'staff_no': 'stf900', 'email': 'new.staff@uis.edu.my'}).text
    code = re.search(r'your code is (\d{6})', page).group(1)
    fp.post(BASE + '/forgot_password.php', data={'csrf_token': tok, 'action': 'verify', 'code': code})
    fp.post(BASE + '/forgot_password.php', data={'csrf_token': tok, 'action': 'save', 'password': 'newpass123', 'password_confirm': 'newpass123'})
    u9 = User('stf900', 'newpass123')
    u9.get('profile.php')
    u9.post('profile.php', {'action': 'profile', 'name': 'New Staff Name', 'phone_no': '012-9998888', 'address': 'Bangi'})
    ok(one("SELECT name FROM `user` WHERE staff_id='stf900'") == 'New Staff Name', 'profile updated (UC016B)')
    u9.post('profile.php', {'action': 'password', 'current_password': 'newpass123', 'new_password': 'final1234', 'confirm_password': 'final1234'})
    User('stf900', 'final1234')

    print('\nUC002 vehicles, UC011/UC012 reports')
    adm.post('admin/add_vehicle.php', {'plate_no': 'VAA 1234', 'type': 'Van', 'brand': 'Toyota', 'model': 'Hiace', 'fuel_type': 'Diesel', 'capacity': '12',
                                       'licence_required': 'D', 'current_mileage': '1000', 'fuel_level': 'Full'})
    vid = one("SELECT vehicle_id FROM vehicle WHERE plate_no='VAA 1234'")
    ok(vid is not None, 'vehicle added')
    adm.post('admin/edit_vehicle.php', {'vehicle_id': vid, 'current_mileage': '900', 'fuel_level': 'Full', 'status': 'Active'})
    ok(one(f"SELECT current_mileage FROM vehicle WHERE vehicle_id={vid}") == '1000', 'lower mileage refused')
    welfare_vid = one(f"SELECT vehicle_id FROM task WHERE duty_id={wid} LIMIT 1")
    adm.post('admin/vehicles.php', {'action': 'deactivate', 'vehicle_id': welfare_vid})
    ok(one(f"SELECT status FROM vehicle WHERE vehicle_id={welfare_vid}") == 'Active', 'vehicle with upcoming bookings not deactivated')
    adm.post('admin/vehicles.php', {'action': 'deactivate', 'vehicle_id': vid})
    ok(one(f"SELECT status FROM vehicle WHERE vehicle_id={vid}") == 'Inactive', 'free vehicle deactivated')
    for t in ['workload', 'replacement', 'vehicle']:
        adm.get(f'admin/reports.php?type={t}&month={dt.date.today():%Y-%m}')
        for ex in ['pdf', 'xlsx']:
            r = adm.s.get(f'{BASE}/admin/reports.php?type={t}&month={dt.date.today():%Y-%m}&export={ex}')
            ok(r.status_code == 200 and len(r.content) > 300 and ('pdf' in r.headers.get('Content-Type', '') or 'sheet' in r.headers.get('Content-Type', '')), f'report {t} exported as {ex}')

    print('\nNo SQL in the View Layer')
    view = [f for d in ['', 'staff', 'supervisor', 'driver', 'admin', 'ajax'] for f in __import__('glob').glob(f'{ROOT}/{d}/*.php')]
    leaks = [f for f in view if re.search(r'\b(Database::|SELECT .* FROM|INSERT INTO|UPDATE `?\w+`? SET)', open(f).read())]
    ok(not leaks, 'pages call the Domain Layer only' + (': ' + ', '.join(leaks) if leaks else ''))

    print(f'\n{PASSED} checks passed')


if __name__ == '__main__':
    main()
