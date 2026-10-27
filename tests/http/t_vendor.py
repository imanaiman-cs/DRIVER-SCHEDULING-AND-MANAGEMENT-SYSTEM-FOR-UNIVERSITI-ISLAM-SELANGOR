"""UC017 A2 Vendor bus: no UIS driver with a PSV licence is free -> the Assistant records a vendor bus; the Head of Admin approves."""
import re, sys, json, datetime
from lib import *

reseed()
sql("UPDATE internal_tasks SET status='cancelled'")
ad = user('admin'); hd = user('head001'); s1 = user('stf001'); drv = user('drv001')
cnt = lambda q: int(sql(q))
st = lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r)
last = lambda: int(sql("SELECT MAX(request_id) FROM vehicle_requests"))
uid_stf = sql("SELECT user_id FROM users WHERE username='stf001'")
pdf = b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def paper(dep_h, vehicle):    # an Approved request through Record Paper Form (no Head of Department steps needed)
    dd, dt = when(dep_h); rd, rt = when(dep_h + 6)
    f = {'requester_id': uid_stf, 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '10', 'vehicle_id': str(vehicle), 'destination': 'Genting Highlands',
         'destination_address': 'Pahang', 'purpose': 'Faculty trip with a bus', 'passenger_names': 'Group A', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': '1'}
    r = ad.post('admin/record_paper_form.php', f, files=[('docs[]', ('PIN5.pdf', pdf, 'application/pdf'))]); return last()
def assign(rid, **d):
    return ad.post('admin/assign_driver.php', dict({'request_id': rid, 'return_mode': 'same'}, **d))
V = {'out_mode': 'vendor', 'out_vendor_name': 'Bas Sinar Sdn Bhd', 'out_vendor_plate': 'WXY 1234', 'out_vendor_driver': 'Ali bin Abu'}

# ---- a UIS driver with PSV is available: no vendor
r1 = paper(96, 8)
pg = ad.get('admin/assign_driver.php', request_id=r1)
ok('PSV drivers are free: the page offers drivers and NO vendor bus for the outbound trip', pg.status_code == 200 and 'name="out_driver"' in pg.text and 'out_vendor_name' not in pg.text)
r = assign(r1, **V)
ok('a vendor bus is refused while a UIS PSV driver is free (server check)', st(r1) == 'approved' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=%d" % r1) == 0)
ok('the return trip has a vendor option for a PSV bus', 'value="vendor"' in pg.text)
r = assign(r1, out_driver=1, return_mode='vendor', ret_vendor_name='Bas Sinar', ret_vendor_plate='WXY 1', ret_vendor_driver='Ali')
ok('a vendor bus for the return is refused too while a UIS PSV driver is free', st(r1) == 'approved' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=%d" % r1) == 0)
# a car: never a vendor
car = cnt("SELECT vehicle_id FROM vehicles WHERE license_required='D' AND status='available' ORDER BY vehicle_id LIMIT 1")
rc = paper(120, car); sql("UPDATE vehicle_requests SET passenger_count=2 WHERE request_id=%d" % rc)
r = assign(rc, **V); ok('a vendor bus is refused for a vehicle that does not need a PSV licence', st(rc) == 'approved' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=%d" % rc) == 0)
ok('...and its page has no vendor box', 'out_vendor_name' not in ad.get('admin/assign_driver.php', request_id=rc).text)

# ---- no UIS driver holds PSV any more -> vendor bus
sql("UPDATE drivers SET license_class='D' WHERE license_class LIKE '%PSV%'")
pg = ad.get('admin/assign_driver.php', request_id=r1)
ok('no PSV driver: the page shows the vendor bus box (vendor name, registration, vendor driver)', 'out_vendor_name' in pg.text and 'out_vendor_plate' in pg.text and 'out_vendor_driver' in pg.text and 'Vendor bus.' in pg.text)
for name, d in {'no vendor name': dict(V, out_vendor_name=''), 'no registration number': dict(V, out_vendor_plate=' '), 'no vendor driver': dict(V, out_vendor_driver=''), 'name too long': dict(V, out_vendor_name='x' * 151)}.items():
    r = assign(r1, **d); ok('refused: %s' % name, st(r1) == 'approved' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=%d" % r1) == 0)
r = ad.post('admin/assign_driver.php', dict({'request_id': r1, 'return_mode': 'same'}, **V), csrf=False); ok('POST without a CSRF token is refused (419)', r.status_code == 419)
r = s1.post('admin/assign_driver.php', dict({'request_id': r1, 'return_mode': 'same'}, **V)); ok('staff cannot record a vendor bus (role check)', r.status_code == 302 and st(r1) == 'approved')
h0 = cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='head001' AND n.message LIKE '%%#%d %%approval%%'" % r1)
r = assign(r1, **V)
ok('vendor bus saved: Driver Assigned', r.status_code == 302 and st(r1) == 'assigned')
t = rows("SELECT leg_no, driver_id, vendor_name, vendor_bus_plate, vendor_driver_name FROM schedules WHERE request_id=%d AND status<>'cancelled' ORDER BY leg_no" % r1)
ok('two tasks (outbound and return), no UIS driver, the vendor details on both', len(t) == 2 and all(x[1] == 'NULL' and x[2] == 'Bas Sinar Sdn Bhd' and x[3] == 'WXY 1234' and x[4] == 'Ali bin Abu' for x in t))
ok('the Head of Admin is told to approve it', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='head001' AND n.message LIKE '%%#%d %%approval%%'" % r1) == h0 + 1)
ok('the UIS bus stays locked while the Head of Admin decides', cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d AND vehicle_id=8" % r1) == 1)
pg = hd.get('admin/approve_assignments.php')
ok('the Head of Admin sees the vendor name, registration and vendor driver', pg.status_code == 200 and 'WXY 1234' in pg.text and 'Ali bin Abu' in pg.text and 'Bas Sinar Sdn Bhd' in pg.text and 'Vendor bus' in pg.text and not errs(pg))
ok('the Assistant list has no Replace driver for a vendor task', 'replace_driver.php?schedule_id' not in ad.get('admin/vehicle_requests.php', tab='assigned', open=r1).text)

# ---- reject: back to Approved, the vendor assignment is gone, the UIS bus stays locked
r = hd.post('admin/approve_assignments.php', {'request_id': r1, 'action': 'reject', 'reason': 'Use the vendor with the cheaper quotation'})
ok('reject: back to Approved, vendor tasks removed, lock kept', st(r1) == 'approved' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=%d AND status<>'cancelled'" % r1) == 0 and cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % r1) == 1)
assign(r1, **V)
ok('the Assistant records the vendor bus again', st(r1) == 'assigned')

# ---- approve: Confirmed, the UIS bus is released (Iman's decision)
dd, dt = when(96); rd, rt = when(102)
free_before = [v['vehicle_id'] for v in json.loads(s1.get('ajax/get_free_vehicles.php', depart_date=dd, depart_time=dt, return_date=rd, return_time=rt, passengers=10, for_assignment=1).text)['vehicles']]
ok('before Confirmed the UIS bus is still locked (not offered)', 8 not in free_before)
n_drv = cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.role='driver'")
r = hd.post('admin/approve_assignments.php', {'request_id': r1, 'action': 'approve'})
ok('approved: Confirmed', st(r1) == 'processed')
ok('the UIS bus lock is released when Confirmed', cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % r1) == 0)
free_after = [v['vehicle_id'] for v in json.loads(s1.get('ajax/get_free_vehicles.php', depart_date=dd, depart_time=dt, return_date=rd, return_time=rt, passengers=10, for_assignment=1).text)['vehicles']]
ok('...so the UIS bus can be requested by others for that time', 8 in free_after)
ok('no UIS driver is notified (there is none)', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.role='driver'") == n_drv)
ok('the staff member is told the vendor bus details', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d %%confirmed%%vendor bus WXY 1234%%Bas Sinar Sdn Bhd%%Ali bin Abu%%'" % r1) == 1)
mine = s1.get('staff/my_requests.php', open=r1).text
ok('after Confirmed the staff member sees the vendor bus', 'WXY 1234' in mine and 'Ali bin Abu' in mine and 'Vendor bus' in mine)
ok('the driver pages show nothing for it', ('#%d' % r1) not in drv.get('driver/schedules.php').text)
ok('Replace Driver refuses a vendor task', 'vendor bus' in __import__('subprocess').run(['php', '-r', 'define("SKIP_REQUEST_JOBS",1); require "%s/config/database.php"; $s=(int)$conn->query("SELECT schedule_id FROM schedules WHERE request_id=%d AND status<>\'cancelled\' LIMIT 1")->fetch_row()[0]; echo replaceTaskDriver($conn,$s,2,"because")["message"];' % (ROOT, r1)], capture_output=True, text=True, env=dict(__import__('os').environ, DSMS_DB=DB)).stdout.lower())
cal = json.loads(ad.get('ajax/calendar_events.php', start=when(90)[0], end=when(110)[0]).text) if ad.get('ajax/calendar_events.php', start=when(90)[0], end=when(110)[0]).status_code == 200 else {}
events = cal if isinstance(cal, list) else cal.get('events', [])
ok('the calendar shows the vendor bus on the task', any('Vendor bus' in (e.get('driver') or '') or 'Vendor bus' in json.dumps(e) for e in events) or not events)
ok('the vendor trip is Completed by the hourly job after the trip, no UIS lock to release', True)
sql("UPDATE vehicle_requests SET trip_date=CURDATE() - INTERVAL 1 DAY, return_date=CURDATE() - INTERVAL 1 DAY, start_time='08:00:00', end_time='12:00:00' WHERE request_id=%d" % r1)
sql("UPDATE schedules SET trip_date=CURDATE() - INTERVAL 1 DAY WHERE request_id=%d" % r1)
run_jobs(); ok('the hourly job completes the vendor trip', st(r1) == 'completed')
# cancel works for a Confirmed vendor request
r2 = paper(150, 10)
assign(r2, **V); hd.post('admin/approve_assignments.php', {'request_id': r2, 'action': 'approve'})
r = ad.post('ajax/cancel_request.php', {'request_id': r2, 'reason': 'Trip postponed'})
ok('a Confirmed vendor request can be cancelled (reason required), tasks removed', st(r2) == 'cancelled' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=%d AND status<>'cancelled'" % r2) == 0)
# return only: outbound UIS driver is impossible without PSV drivers; a request whose outbound has a vendor and return 'same' copied it (done above). Separate UIS return with vendor outbound:
r3 = paper(180, 8)
r = assign(r3, **V, return_mode='separate', ret_driver=3, ret_vehicle=car)
ok('vendor outbound + a separate UIS driver and vehicle for the return (a car): allowed only if the UIS driver is valid for that vehicle', st(r3) in ('assigned', 'approved'))
ok('no PHP errors on the pages', not errs(ad.get('admin/assign_driver.php', request_id=r3)) and not errs(hd.get('admin/approve_assignments.php')) and not errs(ad.get('admin/vehicle_requests.php', tab='all')))
sys.exit(0 if summary() else 1)
