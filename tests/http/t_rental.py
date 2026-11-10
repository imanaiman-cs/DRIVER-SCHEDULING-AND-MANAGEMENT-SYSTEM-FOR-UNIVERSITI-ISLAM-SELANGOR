"""SRS 10 Nov UC018 A4 / UC003 A5 / UC017 A3-A4: Personal Rental of a lorry (RM3 per km, Assistant then Head of Admin) and the approval letters."""
import json, datetime, re, sys
from lib import *

reseed()
sql("UPDATE internal_tasks SET status='cancelled'")
s1 = user('stf001'); s2 = user('stf002'); hos2 = user('hos002'); hos3 = user('hos003'); ad = user('admin'); hd = user('head001')
cnt = lambda q: int(sql(q)); last = lambda: cnt("SELECT MAX(request_id) FROM vehicle_requests")
st = lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r)
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def rent(dep_h, **kw):
    dd, dt = when(dep_h); rd, rt = when(dep_h + 6)
    f = {'request_type': 'uis', 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '1', 'vehicle_type': 'Lorry', 'quantity': '1',
         'destination': 'Seremban', 'destination_address': 'Jalan Dato Bandar Tunggal, 70000 Seremban', 'purpose_type': 'personal_rental', 'distance_km': '120',
         'purpose': '', 'passenger_names': '', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': ''}
    f.update(kw); r = s1.post('staff/request_vehicle.php', f, files=[]); return r, last()

pg = s1.get('staff/request_vehicle.php')
ok('the form has the Personal Rental purpose type and the distance field; charge rate shown', 'id="pt_personal_rental"' in pg.text and 'name="distance_km"' in pg.text and 'RM 3.00 per kilometre' in pg.text and not errs(pg))
n0 = cnt("SELECT COUNT(*) FROM vehicle_requests")
r, _ = rent(96, vehicle_type='Car'); ok('Personal Rental is only for a lorry', cnt("SELECT COUNT(*) FROM vehicle_requests") == n0 and 'only for a lorry' in r.text)
r, _ = rent(96, distance_km='0'); ok('the distance is required', cnt("SELECT COUNT(*) FROM vehicle_requests") == n0 and 'distance' in r.text)
r, rid = rent(96)
ok('submitted without a letter or purpose: Pending, no Head of Department, 120 km, charge RM 360.00', r.status_code == 302 and st(rid) == 'pending'
   and rows("SELECT supervisor_id IS NULL, distance_km, charge, purpose_type, need_driver FROM vehicle_requests WHERE request_id=%d" % rid) == [['1', '120.0', '360.00', 'personal_rental', '1']])
ok('a lorry is reserved by type', cnt("SELECT COUNT(*) FROM vehicle_reservations WHERE request_id=%d AND vehicle_type='Lorry'" % rid) == 1)
ok('the Assistant is told (with the charge); the Head of Department is not', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE 'New Personal Rental request #%d%%RM 360.00%%'" % rid) == 1
   and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.role='supervisor' AND n.message LIKE '%%#%d%%'" % rid) == 0)
r = hos2.post('ajax/request_action.php', {'request_id': rid, 'action': 'approve'}); ok('a Head of Department cannot approve it (not assigned to anyone)', st(rid) == 'pending')
ok('the requester sees the rental, distance and charge', 'Personal Rental' in s1.get('staff/my_requests.php', open=rid).text and 'RM 360.00' in s1.get('staff/my_requests.php', open=rid).text)
tab = ad.get('admin/vehicle_requests.php', tab='pending').text
ok('the Assistant sees it under Pending with Personal Rental and an Assign button', 'Personal Rental' in tab and ('assign_driver.php?request_id=%d' % rid) in tab)

# the Assistant assigns, confirming the distance
pg = ad.get('admin/assign_driver.php', request_id=rid)
ok('the assign page asks to confirm the distance and offers the free lorries', pg.status_code == 200 and 'name="distance_km"' in pg.text and sorted(re.findall(r'name="vehicle_id" value="(\d+)"', pg.text)) == ['11', '12'] and not errs(pg))
drv = sql("SELECT driver_id FROM drivers WHERE status='active' AND FIND_IN_SET('GDL', license_class) LIMIT 1")
base = {'request_id': rid, 'out_driver': drv, 'return_mode': 'same', 'vehicle_id': '11'}
ad.post('admin/assign_driver.php', dict(base, distance_km='0')); ok('a zero distance is refused', st(rid) == 'pending')
r = ad.post('admin/assign_driver.php', dict(base, distance_km='150.5'))
ok('assigned: Driver Assigned straight from Pending, distance corrected to 150.5 km, charge RM 451.50', r.status_code == 302 and st(rid) == 'assigned'
   and rows("SELECT distance_km, charge FROM vehicle_requests WHERE request_id=%d" % rid) == [['150.5', '451.50']])
ok('the Head of Admin is told it is a Personal Rental with the charge', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='head001' AND n.message LIKE '%%#%d%%Personal Rental%%RM 451.50%%'" % rid) == 1)
pg = hd.get('admin/approve_assignments.php'); ok('the Head of Admin sees the Personal Rental and the charge', 'Personal Rental' in pg.text and 'RM 451.50' in pg.text)
ok('no letter before the approval', s1.get('staff/print_approval.php', id=rid).status_code in (302, 404))
r = hd.post('admin/approve_assignments.php', {'request_id': rid, 'action': 'approve'}); ok('the Head of Admin approves: Approved', st(rid) == 'processed')
# letters
let = s1.get('staff/print_approval.php', id=rid)
ok('Print Rental Approval: the requester gets the letter with the distance, the charge and the approver', let.status_code == 200 and 'Personal Rental Approval' in let.text and '150.5 km' in let.text and 'RM 451.50' in let.text and 'Rozaimi' in let.text or 'Head of Admin' in let.text)
ok('the Assistant and the Head of Admin can print it too', ad.get('staff/print_approval.php', id=rid).status_code == 200 and hd.get('staff/print_approval.php', id=rid).status_code == 200)
ok('another staff member cannot open it', s2.get('staff/print_approval.php', id=rid).status_code in (302, 404) and 'Personal Rental Approval' not in s2.get('staff/print_approval.php', id=rid).text)
ok('the request page offers the Print Rental Approval link', 'Print Rental Approval' in s1.get('staff/my_requests.php', open=rid).text)
ok('anonymous visitors cannot open the letter', C().get('staff/print_approval.php', id=rid).status_code == 302)
# rate setting
sql("UPDATE settings SET setting_value='4.00' WHERE setting_key='RENTAL_RATE_PER_KM'")
r, rid2 = rent(200, distance_km='100'); ok('the rate is a setting: RM 4.00 per km gives RM 400.00', sql("SELECT charge FROM vehicle_requests WHERE request_id=%d" % rid2) == '400.00')
# jobs: reminder must not fail (no Head of Department), lapse works
sql("UPDATE vehicle_requests SET created_at = NOW() - INTERVAL 30 HOUR WHERE request_id=%d" % rid2)
out = run_jobs(); ok('the hourly job runs with a Pending rental (no reminder to a Head of Department)', out.returncode == 0 and cnt("SELECT COUNT(*) FROM reminders WHERE request_id=%d" % rid2) == 0)
sql("UPDATE vehicle_requests SET created_at = NOW() - INTERVAL 3 DAY WHERE request_id=%d" % rid2)
run_jobs(); ok('not handled by the Assistant within 2 days: Lapsed and the lorry is released', st(rid2) == 'lapsed' and cnt("SELECT COUNT(*) FROM vehicle_reservations WHERE request_id=%d" % rid2) == 0)
# reject by the Head of Admin goes back to the Assistant
r, rid3 = rent(240); ad.post('admin/assign_driver.php', {'request_id': rid3, 'out_driver': drv, 'return_mode': 'same', 'vehicle_id': '12', 'distance_km': '120'})
hd.post('admin/approve_assignments.php', {'request_id': rid3, 'action': 'reject', 'reason': 'Distance looks too short'})
ok('rejected by the Head of Admin: back to the Assistant with the reason', st(rid3) == 'approved')
sys.exit(0 if summary() else 1)
