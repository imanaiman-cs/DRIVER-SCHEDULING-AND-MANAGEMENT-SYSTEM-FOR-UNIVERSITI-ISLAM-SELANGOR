"""SRS summary 10 Nov: Pay and Claim request (own vehicle): no UIS vehicle, no lock, no driver; Head of Department then Head of Admin."""
import json, datetime, sys
from lib import *

reseed()
s1 = user('stf001'); hos2 = user('hos002'); ad = user('admin'); hd = user('head001'); drv = user('drv001')
st = lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r)
last = lambda: int(sql("SELECT MAX(request_id) FROM vehicle_requests"))
cnt = lambda q: int(sql(q))
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def form(dep_h, **kw):
    dd, dt = when(dep_h); rd, rt = when(dep_h + 4)
    f = {'request_type': 'pay_claim', 'own_vehicle_plate': 'wxy 1234', 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '1',
         'destination': 'Kementerian Pendidikan Tinggi', 'destination_address': 'Presint 2, 62100 Putrajaya', 'purpose': 'Attend the monthly coordination meeting',
         'passenger_names': 'Self', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': ''}
    f.update(kw); return f
before = cnt("SELECT COUNT(*) FROM vehicle_requests")

pg = s1.get('staff/request_vehicle.php')
ok('the form offers UIS vehicle and Pay and Claim, with the own vehicle field', 'name="request_type"' in pg.text and 'Pay and Claim' in pg.text and 'name="own_vehicle_plate"' in pg.text and not errs(pg))
r = s1.post('staff/request_vehicle.php', form(96, own_vehicle_plate=''))
ok('the registration number of the own vehicle is required', cnt("SELECT COUNT(*) FROM vehicle_requests") == before and 'own vehicle' in r.text)
r = s1.post('staff/request_vehicle.php', form(96, own_vehicle_plate='!!'))
ok('an invalid registration number is refused', cnt("SELECT COUNT(*) FROM vehicle_requests") == before)
r = s1.post('staff/request_vehicle.php', form(6))
ok('the 1-day timing rule applies to Pay and Claim too', cnt("SELECT COUNT(*) FROM vehicle_requests") == before)

r = s1.post('staff/request_vehicle.php', form(96)); rA = last()
ok('submitted: Pending, type pay_claim, no UIS vehicle, without driver, plate in capitals', r.status_code == 302 and st(rA) == 'pending'
   and rows("SELECT request_type, vehicle_id IS NULL, need_driver, own_vehicle_plate FROM vehicle_requests WHERE request_id=%d" % rA) == [['pay_claim', '1', '0', 'WXY 1234']])
ok('no vehicle is locked', cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rA) == 0)
ok('the Head of Department is told it is a Pay and Claim request', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='hos002' AND n.message LIKE '%%Pay and Claim request #%d%%'" % rA) == 1)
ok('the HoD sees the own vehicle and the badge', 'WXY 1234' in hos2.get('supervisor/approvals.php').text and 'Pay and Claim' in hos2.get('supervisor/approvals.php').text)
ok('a second Pay and Claim request for the same time is allowed (nothing is locked)', s1.post('staff/request_vehicle.php', form(96)).status_code == 302)
rB = last()

# HoD approves -> goes straight to the Head of Admin
r = hos2.post('ajax/request_action.php', {'request_id': rA, 'action': 'approve'})
ok('the Head of Department approves: the request goes straight to Driver Assigned (Head of Admin queue)', json.loads(r.text)['success'] and st(rA) == 'assigned')
ok('history: approved by the HoD, then sent to the Head of Admin by the system', cnt("SELECT COUNT(*) FROM audit_log WHERE request_id=%d AND action='approve'" % rA) == 1 and cnt("SELECT COUNT(*) FROM audit_log WHERE request_id=%d AND action='assign' AND user_role='system'" % rA) == 1)
ok('the staff member is told it waits for the Head of Admin', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d%%Pay and Claim%%Head of Admin%%'" % rA) == 1)
ok('the Head of Admin is told; the Assistant is not asked to assign a driver', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='head001' AND n.message LIKE '%%#%d%%Pay and Claim%%needs your approval%%'" % rA) == 1
   and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%#%d %%needs a driver%%'" % rA) == 0)
pg = hd.get('admin/approve_assignments.php')
ok('the Head of Admin sees it with the own vehicle', pg.status_code == 200 and ('Request #%d' % rA) in pg.text and 'Pay and Claim' in pg.text and 'WXY 1234' in pg.text and not errs(pg))
r = ad.post('admin/approve_assignments.php', {'request_id': rA, 'action': 'approve'})
ok('the Assistant cannot approve (only the Head of Admin)', st(rA) == 'assigned')
r = ad.post('admin/approve_assignments.php', {'request_id': rA, 'action': 'approve'}, csrf=False)
ok('POST without CSRF is refused (419)', r.status_code == 419 and st(rA) == 'assigned')
r = hd.post('admin/approve_assignments.php', {'request_id': rA, 'action': 'approve'})
ok('the Head of Admin approves: Confirmed, no tasks, no vehicle lock', st(rA) == 'processed' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=%d" % rA) == 0 and cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rA) == 0)
ok('the staff member may use the own vehicle and claim; no driver is told', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d%%confirmed%%own vehicle%%'" % rA) == 1
   and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.role='driver' AND n.message LIKE '%%#%d %%'" % rA) == 0)
mine = s1.get('staff/my_requests.php', open=rA).text
ok('the staff member sees Confirmed, the own vehicle and no UIS driver', 'WXY 1234' in mine and 'Pay and Claim' in mine and not errs(s1.get('staff/my_requests.php', open=rA)))
ok('the driver page shows nothing for it', ('#%d' % rA) not in drv.get('driver/schedules.php').text)
sql("UPDATE vehicle_requests SET trip_date=CURDATE() - INTERVAL 1 DAY, return_date=CURDATE() - INTERVAL 1 DAY, start_time='08:00:00', end_time='12:00:00' WHERE request_id=%d" % rA)
run_jobs(); ok('the hourly job completes it after the return time', st(rA) == 'completed')

# reject path: ends the request
hos2.post('ajax/request_action.php', {'request_id': rB, 'action': 'approve'})
r = hd.post('admin/approve_assignments.php', {'request_id': rB, 'action': 'reject', 'reason': ''})
ok('reject needs a reason', st(rB) == 'assigned')
r = hd.post('admin/approve_assignments.php', {'request_id': rB, 'action': 'reject', 'reason': 'Use the UIS pool vehicle instead'})
ok('reject: Rejected for good, the staff member and the Assistant are told with the reason', st(rB) == 'rejected'
   and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d%%rejected by the Head of Admin%%pool vehicle%%'" % rB) == 1
   and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%#%d%%Pay and Claim%%rejected%%'" % rB) == 1)
# cancel
r = s1.post('staff/request_vehicle.php', form(120)); rC = last()
r = s1.post('ajax/cancel_request.php', {'request_id': rC, 'reason': 'Meeting moved'})
ok('the staff member can cancel a Pending Pay and Claim request', st(rC) == 'cancelled')
# lapse
r = s1.post('staff/request_vehicle.php', form(150)); rD = last()
sql("UPDATE vehicle_requests SET created_at = NOW() - INTERVAL 3 DAY WHERE request_id=%d" % rD)
run_jobs(); ok('pending for 2 days: Lapsed like any request', st(rD) == 'lapsed')
# a normal UIS request is unchanged
n0 = cnt("SELECT COUNT(*) FROM vehicle_requests")
r = s1.post('staff/request_vehicle.php', form(100, request_type='uis', own_vehicle_plate='WXY 1234', vehicle_id='', need_driver='1'))
ok('a UIS vehicle request still needs a vehicle (an own plate does not replace it)', cnt("SELECT COUNT(*) FROM vehicle_requests") == n0 and 'Choose a vehicle' in r.text)
ok('the Assistant list shows the Pay and Claim badge, no PHP errors', 'Pay and Claim' in ad.get('admin/vehicle_requests.php', tab='all').text and not errs(ad.get('admin/vehicle_requests.php', tab='all')))
sys.exit(0 if summary() else 1)
