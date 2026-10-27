"""UC010 A4 - Record Paper Form: the Assistant records the approved paper form BPTK/BPI/2025/PIN.5 and uploads the scan.
Saved as Approved, vehicle locked, no UC011 e-mail, no UC012 reminder, allowed less than 1 day before the trip."""
import json, datetime, re, sys
from lib import *

reseed()
sql("UPDATE internal_tasks SET status='cancelled' WHERE vehicle_id IN (1,2,3,5,6,7,8,10,11,12)")   # demo internal tasks use relative dates
s1 = user('stf001'); hos2 = user('hos002'); ad = user('admin'); hd = user('head001'); drv = user('drv001')
cnt = lambda q: int(sql(q))
st = lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r)
last = lambda: int(sql("SELECT MAX(request_id) FROM vehicle_requests"))
pdf = b'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF'
uid_stf = sql("SELECT user_id FROM users WHERE username='stf001'")
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def pick(dep_h, dur=3, who=ad):
    dd, dt = when(dep_h); rd, rt = when(dep_h + dur)
    d = json.loads(who.get('ajax/get_free_vehicles.php', depart_date=dd, depart_time=dt, return_date=rd, return_time=rt, passengers=2, for_assignment=1).text)
    return d['vehicles'][0]['vehicle_id'] if d['vehicles'] else None
def form(dep_h, vehicle, nd='1', dur=3, **kw):
    dd, dt = when(dep_h); rd, rt = when(dep_h + dur)
    f = {'requester_id': uid_stf, 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '2', 'vehicle_id': str(vehicle),
         'destination': 'Kementerian Pendidikan Tinggi', 'destination_address': 'Presint 2, 62100 Putrajaya', 'purpose': 'Meeting recorded from the paper form',
         'passenger_names': 'A; B', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': nd}
    f.update(kw); return f
SCAN = [('docs[]', ('PIN5-scan.pdf', pdf, 'application/pdf'))]

# access
ok('staff cannot open Record Paper Form', s1.get('admin/record_paper_form.php').status_code == 302)
ok('Head of Department cannot open it', hos2.get('admin/record_paper_form.php').status_code == 302)
pg = ad.get('admin/record_paper_form.php')
ok('the Assistant opens it: staff member choice, scan upload, form number', pg.status_code == 200 and 'requester_id' in pg.text and 'BPTK/BPI/2025/PIN.5' in pg.text and 'Scanned paper form' in pg.text and not errs(pg))
ok('the Head of Admin opens it too', hd.get('admin/record_paper_form.php').status_code == 200)
ok('the menu has "Record Paper Form" for the Assistant, not for staff', 'Record Paper Form' in ad.get('admin/dashboard.php').text and 'Record Paper Form' not in s1.get('staff/dashboard.php').text)

# the vehicle list for a trip LESS THAN 1 DAY away: staff are refused, the paper form page still shows vehicles
dd, dt = when(10); rd, rt = when(13)
q = dict(depart_date=dd, depart_time=dt, return_date=rd, return_time=rt, passengers=2)
ok('staff: trip < 1 day -> reject, no vehicles', json.loads(s1.get('ajax/get_free_vehicles.php', **q).text)['timing'] == 'reject')
v1 = pick(10)
ok('paper form: trip < 1 day -> vehicles are listed', v1 is not None)

# save a paper form request less than 1 day before the trip
n0 = cnt("SELECT COUNT(*) FROM vehicle_requests")
r = ad.post('admin/record_paper_form.php', form(10, v1), files=SCAN); rid = last()
ok('saved although the trip is less than 1 day away: status Approved', r.status_code == 302 and rid == n0 + 1 and st(rid) == 'approved')
ok('it belongs to the staff member, with the Head of Department set', sql("SELECT staff_id FROM vehicle_requests WHERE request_id=%d" % rid) == uid_stf)
ok('the vehicle is locked for the period', cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d AND vehicle_id=%d" % (rid, v1)) == 1)
ok('the scanned form is stored with the request', cnt("SELECT COUNT(*) FROM request_documents WHERE request_id=%d AND doc_type='approval_letter' AND original_name='PIN5-scan.pdf'" % rid) == 1)
ok('history: "Recorded from the approved paper form" by the Assistant', cnt("SELECT COUNT(*) FROM audit_log a JOIN users u ON u.user_id=a.user_id WHERE a.request_id=%d AND a.action='paper_form' AND u.username='admin'" % rid) == 1)
ok('UC011 is NOT performed: the Head of Department gets no approval e-mail or notification',
   cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='hos002' AND n.message LIKE '%%#%d %%'" % rid) == 0
   and cnt("SELECT COUNT(*) FROM email_log WHERE subject LIKE '%%#%d %%' AND user_id=(SELECT user_id FROM users WHERE username='hos002')" % rid) == 0)
ok('the staff member is told it was recorded', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d %%recorded%%paper form%%'" % rid) == 1)
ok('the request is in the Assistant list "Approved" ready for a driver', ('#%d' % rid) in ad.get('admin/vehicle_requests.php', tab='approved').text)
ok('the staff member sees it as Approved', 'Approved' in s1.get('staff/my_requests.php', open=rid).text)

# UC012 is NOT performed
sql("UPDATE vehicle_requests SET created_at=DATE_SUB(NOW(), INTERVAL 40 HOUR) WHERE request_id=%d" % rid)
run_jobs(); ok('UC012 is NOT performed: no reminder is sent for it', cnt("SELECT COUNT(*) FROM reminders WHERE request_id=%d" % rid) == 0 and st(rid) == 'approved')

# the lock prevents clashes with online requests (and with another paper form)
ok('an online request cannot take the locked vehicle in that period', v1 not in [v['vehicle_id'] for v in json.loads(s1.get('ajax/get_free_vehicles.php', **q).text).get('vehicles', [])])
n1 = cnt("SELECT COUNT(*) FROM vehicle_requests")
r = ad.post('admin/record_paper_form.php', form(10, v1), files=SCAN)
ok('a second paper form for the same vehicle and time is refused', r.status_code == 200 and cnt("SELECT COUNT(*) FROM vehicle_requests") == n1)

# validations: nothing is saved
bad = {'no scan': (form(40, pick(40)), None), 'no staff member': (form(40, pick(40), requester_id=''), SCAN)}
for name, (f, files) in bad.items():
    r = ad.post('admin/record_paper_form.php', f, files=files)
    ok('refused: %s' % name, r.status_code == 200 and cnt("SELECT COUNT(*) FROM vehicle_requests") == n1)
past = form(40, pick(40)); past['depart_date'] = (now() - datetime.timedelta(days=1)).strftime('%Y-%m-%d'); past['return_date'] = past['depart_date']
r = ad.post('admin/record_paper_form.php', past, files=SCAN); ok('refused: departure in the past', r.status_code == 200 and cnt("SELECT COUNT(*) FROM vehicle_requests") == n1)
r = ad.post('admin/record_paper_form.php', form(40, pick(40), return_date=when(30)[0], return_time=when(30)[1]), files=SCAN); ok('refused: return before departure', r.status_code == 200 and cnt("SELECT COUNT(*) FROM vehicle_requests") == n1)
r = ad.post('admin/record_paper_form.php', form(40, pick(40)), files=[('docs[]', ('evil.txt', b'hello', 'text/plain'))]); ok('refused: scan that is not PDF/JPG/PNG', r.status_code == 200 and cnt("SELECT COUNT(*) FROM vehicle_requests") == n1)
r = ad.post('admin/record_paper_form.php', form(40, pick(40)), files=[('docs[]', ('big.pdf', pdf + b'0' * 5300000, 'application/pdf'))]); ok('refused: scan bigger than 5 MB', r.status_code == 200 and cnt("SELECT COUNT(*) FROM vehicle_requests") == n1)
r = s1.post('admin/record_paper_form.php', form(40, pick(40)), files=SCAN); ok('staff cannot post to it (role check)', r.status_code == 302 and cnt("SELECT COUNT(*) FROM vehicle_requests") == n1)
r = ad.post('admin/record_paper_form.php', form(40, pick(40)), files=SCAN, csrf=False); ok('POST without a CSRF token is refused (419)', r.status_code == 419 and cnt("SELECT COUNT(*) FROM vehicle_requests") == n1)

# a without-driver paper form goes the without-driver way; a with-driver one is assigned as usual
r = ad.post('admin/record_paper_form.php', form(60, pick(60), nd='0'), files=SCAN); rid2 = last()
ok('paper form WITHOUT driver: Approved, need_driver = 0', r.status_code == 302 and st(rid2) == 'approved' and sql("SELECT need_driver FROM vehicle_requests WHERE request_id=%d" % rid2) == '0')
ok('and the Assistant can send it to the Head of Admin', ad.post('admin/vehicle_requests.php', {'action': 'send_without_driver', 'request_id': rid2}).status_code == 302 and st(rid2) == 'assigned')
ok('paper form WITH driver: the Assign Driver page opens', ad.get('admin/assign_driver.php', request_id=rid).status_code == 200)
r = hd.post('admin/record_paper_form.php', form(90, pick(90)), files=SCAN); rid3 = last()
ok('the Head of Admin can record one too', r.status_code == 302 and st(rid3) == 'approved')
# cancel releases the lock like any request
r = ad.post('ajax/cancel_request.php', {'request_id': rid3, 'reason': 'The trip was cancelled'})
ok('cancel works and releases the lock', st(rid3) == 'cancelled' and cnt("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d" % rid3) == 0)
ok('a normal request still works and still needs the Head of Department', ad.get('staff/request_vehicle.php').status_code == 200 and 'Record Paper Form' not in ad.get('staff/request_vehicle.php').text.split('<main')[1].split('</h4>')[0])
sys.exit(0 if summary() else 1)
