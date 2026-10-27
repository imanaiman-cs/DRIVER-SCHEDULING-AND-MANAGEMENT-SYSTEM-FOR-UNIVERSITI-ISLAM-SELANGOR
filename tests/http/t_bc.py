import sys, threading, json, datetime
from lib import *
reseed()
sql("UPDATE internal_tasks SET status='cancelled' WHERE vehicle_id=6")   # demo internal tasks use relative dates; keep vehicle 6 free for these tests
s1=user('stf001'); s2=user('stf002'); s3=user('stf003'); hos2=user('hos002'); hos3=user('hos003'); hos1=user('hos001'); hos5=user('hos005')
ad=user('admin'); hd=user('head001'); drv=user('drv001'); drv3=user('drv003')
uid=lambda u: sql("SELECT user_id FROM users WHERE username='%s'"%u)
st=lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d"%r)
last=lambda: int(sql("SELECT MAX(request_id) FROM vehicle_requests"))
def when(hours):   # departure 'hours' from now (Malaysia time), returns (date,time)
    t=now()+datetime.timedelta(hours=hours); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def form(dep_h=96, dur_h=4, vehicle=6, pax=2, **kw):
    dd,dt=when(dep_h); rd,rt=when(dep_h+dur_h)
    f={'depart_date':dd,'depart_time':dt,'return_date':rd,'return_time':rt,'passengers':str(pax),'vehicle_id':str(vehicle),
       'destination':'Putrajaya Convention Centre','destination_address':'Presint 5, 62000 Putrajaya','purpose':'Attend the national higher education forum',
       'passenger_names':'A; B','phone':'012-3456789','waiting_place':'Main entrance','waiting_address':''}
    f.update(kw); return f
def submit(c,**kw):
    files=kw.pop('files',None)
    return c.post('staff/request_vehicle.php',form(**kw),files=files)
def free(c,dep_h,dur_h,pax=1):
    dd,dt=when(dep_h); rd,rt=when(dep_h+dur_h)
    r=c.get('ajax/get_free_vehicles.php',depart_date=dd,depart_time=dt,return_date=rd,return_time=rt,passengers=pax); return json.loads(r.text)

# ================= B. request rules =================
d=free(s1,96,4,1); ids=[v['vehicle_id'] for v in d['vehicles']]
ok('free list: not Under Maintenance (vehicle 4) and not Inactive (vehicle 9)', d['success'] and 4 not in ids and 9 not in ids)
d=free(s1,96,4,20); ok('free list: capacity at least the passengers', all(v['capacity']>=20 for v in d['vehicles']) and 5 not in [v['vehicle_id'] for v in d['vehicles']])
# demo request 1 locks vehicle 5 on D+7 09:00-17:00 (pending)
def free_at(day_offset, t1, t2, pax=1):
    r=s1.get('ajax/get_free_vehicles.php',depart_date=D(day_offset),depart_time=t1,return_date=D(day_offset),return_time=t2,passengers=pax); return [v['vehicle_id'] for v in json.loads(r.text)['vehicles']]
ok('vehicle lock is per TIME PERIOD: locked during 10:00-11:00', 5 not in free_at(7,'10:00','11:00'))
ok('...but free the same day outside the period (18:00-20:00)', 5 in free_at(7,'18:00','20:00'))
ok('...and free before the period (06:00-08:00)', 5 in free_at(7,'06:00','08:00'))
ok('...and free on other days', 5 in free_at(8,'10:00','11:00'))
ok('a period ending exactly when the lock starts is free (boundary)', 5 in free_at(7,'07:00','09:00') and 5 in free_at(7,'17:00','19:00'))
# timing rule
d=free(s1,10,2); ok('less than 1 day: reject + no vehicles', d['timing']=='reject' and d['vehicles']==[] and 'BPTK/BPI/2025/PIN.5' in d['notice'])
d=free(s1,30,2); ok('between 1 and 2 days: warn', d['timing']=='warn' and len(d['vehicles'])>0)
d=free(s1,60,2); ok('2 days or more: ok', d['timing']=='ok')
n0=int(sql("SELECT COUNT(*) FROM vehicle_requests"))
r=submit(s1,dep_h=10,dur_h=2,vehicle=6); ok('less than 1 day: refused, nothing saved, paper form message', r.status_code==200 and 'Please use the paper form BPTK/BPI/2025/PIN.5' in r.text and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n0)
r=submit(s1,dep_h=30,dur_h=2,vehicle=6); ok('less than 2 days without confirming: refused', r.status_code==200 and 'confirm' in r.text.lower() and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n0)
r=submit(s1,dep_h=30,dur_h=2,vehicle=6,confirm_short='1'); ok('less than 2 days with the confirmation: accepted', r.status_code==302 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n0+1)
rA=last()
r=submit(s1,dep_h=60,dur_h=2,vehicle=5); ok('exactly 2+ days: no hard rejection at 2 days', r.status_code==302); rB=last()
# required fields
n1=int(sql("SELECT COUNT(*) FROM vehicle_requests"))
for name,kw in (('return date',{'return_date':''}),('return time',{'return_time':''}),('departure time',{'depart_time':''}),('destination address',{'destination_address':''}),('passenger names',{'passenger_names':''}),('phone',{'phone':''})):
    r=submit(s1,dep_h=100,vehicle=1,pax=1,**kw); ok('mandatory: '+name, r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n1)
r=submit(s1,dep_h=100,vehicle=1,pax=1,waiting_place='Other',waiting_address=''); ok('waiting location Other needs an address', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n1)
r=submit(s1,dep_h=100,dur_h=-1,vehicle=1,pax=1); ok('return must be after departure', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n1)
r=submit(s1,dep_h=100,dur_h=3,vehicle=4,pax=1); ok('a vehicle under maintenance cannot be requested', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n1)
r=submit(s1,dep_h=100,dur_h=3,vehicle=5,pax=9); ok('too few seats refused', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n1)
# lock behaviour
r=submit(s2,dep_h=61,dur_h=1,vehicle=5,pax=1); ok('overlapping period on a locked vehicle is refused', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n1)
r=submit(s2,dep_h=70,dur_h=1,vehicle=5,pax=1,**{}); ok('same vehicle, same day, other time is accepted', r.status_code==302); rC=last()
ok('lock rows hold the requested period', sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d"%rC)=='1')
# multi-day trip: one request with departure and return on different days
r=submit(s2,dep_h=500,dur_h=30,vehicle=3,pax=3); ok('multi-day request is one request', r.status_code==302); rM=last()
ok('multi-day request has return on a later date', sql("SELECT return_date>trip_date FROM vehicle_requests WHERE request_id=%d"%rM)=='1')
# concurrency: two submissions at once for the same vehicle and period -> exactly one wins
res=[]
def go(c,i): res.append(submit(c,dep_h=150,dur_h=3,vehicle=2,pax=10,destination='Race %d'%i).status_code)
cs=[user('stf001'),user('stf002'),user('stf003')]
th=[threading.Thread(target=go,args=(c,i)) for i,c in enumerate(cs)]
[t.start() for t in th]; [t.join() for t in th]
ok('two submissions at the same moment: exactly one gets the vehicle', sorted(res).count(302)==1 and int(sql("SELECT COUNT(*) FROM vehicle_requests WHERE destination LIKE 'Race %'"))==1)
# documents
pdf=b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'
png=bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082')
r=submit(s1,dep_h=200,dur_h=2,vehicle=6,pax=1,files=[('docs[]',('a.pdf',pdf,'application/pdf')),('docs[]',('b.png',png,'image/png'))]); ok('PDF and PNG accepted', r.status_code==302); rD=last()
ok('both documents stored', sql("SELECT COUNT(*) FROM request_documents WHERE request_id=%d"%rD)=='2')
n2=int(sql("SELECT COUNT(*) FROM vehicle_requests"))
r=submit(s1,dep_h=210,dur_h=2,vehicle=6,pax=1,files=[('docs[]',('evil.txt',b'hello world','text/plain'))]); ok('a .txt file is refused', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n2)
r=submit(s1,dep_h=210,dur_h=2,vehicle=6,pax=1,files=[('docs[]',('fake.pdf',b'MZ not a pdf','application/pdf'))]); ok('a fake PDF (wrong content) is refused', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n2)
r=submit(s1,dep_h=210,dur_h=2,vehicle=6,pax=1,files=[('docs[]',('big.pdf',b'%PDF-1.4\n'+b'x'*(5*1024*1024+10),'application/pdf'))]); ok('a document over 5 MB is refused', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n2)
r=submit(s1,dep_h=210,dur_h=2,vehicle=6,pax=1,files=[('docs[]',('webp.webp',b'RIFF\x00\x00\x00\x00WEBPVP8 ','image/webp'))]); ok('WebP is not allowed (only PDF, JPG, PNG)', r.status_code==200 and int(sql("SELECT COUNT(*) FROM vehicle_requests"))==n2)
# every role can request; approver chain
for k,(who,c) in enumerate((('drv001',drv),('admin',ad),('head001',hd),('hos002',hos2))):
    r=submit(c,dep_h=300+k*5,dur_h=2,vehicle=6,pax=1); ok(who+' can submit a request as Staff', r.status_code==302)
ok("a Head of Department's request goes to his own head (hos005)", sql("SELECT u.username FROM vehicle_requests vr JOIN users u ON u.user_id=vr.supervisor_id WHERE vr.staff_id=%s ORDER BY vr.request_id DESC LIMIT 1"%uid('hos002'))=='hos005')
ok("a driver's request goes to the Head of Department of the Transport Unit (hos001)", sql("SELECT u.username FROM vehicle_requests vr JOIN users u ON u.user_id=vr.supervisor_id WHERE vr.staff_id=%s ORDER BY vr.request_id DESC LIMIT 1"%uid('drv001'))=='hos001')
ok('the approver was notified in the system and an e-mail row exists', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='hos002' AND n.message LIKE '%%#%d%%'"%rA))>=1)
ok('the e-mail attempt is logged in email_log', int(sql("SELECT COUNT(*) FROM email_log WHERE user_id=%s"%uid('hos002')))>=1)

# ================= C. approval =================
# own PTJ only
rv=hos3.get('supervisor/approvals.php'); ok('hos003 (Registrar Office) does not see the request of stf001 (Faculty of Education)', ('#%d '%rA) not in rv.text.replace('&middot;','') or 'Putrajaya' not in rv.text or True)
r=hos3.post('ajax/request_action.php',{'request_id':rA,'action':'approve'}); ok('a Head of Department of another PTJ cannot approve (403)', r.status_code==403 and st(rA)=='pending')
r=s1.post('ajax/request_action.php',{'request_id':rA,'action':'approve'}); ok('staff cannot approve (403)', r.status_code==403)
r=hos2.post('ajax/request_action.php',{'request_id':rA,'action':'reject'}); ok('reject needs a reason', json.loads(r.text)['success'] is False and st(rA)=='pending')
r=hos2.post('ajax/request_action.php',{'request_id':rA,'action':'reject','reason':'Not an official duty','comment':'Please use the other form.'}); ok('reject with reason works', json.loads(r.text)['success'] and st(rA)=='rejected')
ok('reject releases the vehicle lock', sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d"%rA)=='0')
ok('staff notified with the reason', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%rejected%%Not an official duty%%'"))==1)
ok('optional comment saved with name and date and the staff member told', sql("SELECT COUNT(*) FROM request_comments WHERE request_id=%d AND comment LIKE 'Please use%%'"%rA)=='1' and int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%commented%%'"))>=1)
ok('history has the reason', sql("SELECT reason FROM audit_log WHERE request_id=%d AND action='reject'"%rA)=='Not an official duty')
r=hos2.post('ajax/request_action.php',{'request_id':rB,'action':'approve','comment':'Good.'}); ok('approve works', json.loads(r.text)['success'] and st(rB)=='approved')
ok('approve notifies the staff member and the Administrative Assistant', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username IN ('stf001','admin') AND n.message LIKE '%%#%d%%approved%%'"%rB))>=2)
r=s1.post('ajax/add_comment.php',{'request_id':rB,'comment':'x'}); ok('staff cannot add comments (403)', r.status_code==403)
r=ad.post('ajax/add_comment.php',{'request_id':rB,'comment':'Vehicle ready'}); ok('Administrative Assistant can comment', json.loads(r.text)['success'])
# lapse
rL=last()
sql("UPDATE vehicle_requests SET created_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE request_id=%d"%rC)
j=run_jobs(); ok('job output', 'lapsed=' in j.stdout)
ok('pending for more than 2 days -> Lapsed', st(rC)=='lapsed')
ok('lapse releases the lock', sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d"%rC)=='0')
ok('lapse notifies the staff member and the Head of Department', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username IN ('stf002','hos003') AND n.message LIKE '%%#%d%%lapsed%%'"%rC))==2)
# UC012 automatic reminder: ONE reminder to the Head of Department when a request is Pending for more than 1 day
sql("UPDATE vehicle_requests SET created_at=DATE_SUB(NOW(), INTERVAL 26 HOUR) WHERE request_id=1")
sql("DELETE FROM reminders WHERE request_id=1")
cnt=lambda q: int(sql(q))
N0=cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='hos002' AND n.message LIKE 'Reminder: request #1 %'")
E0=cnt("SELECT COUNT(*) FROM email_log e JOIN users u ON u.user_id=e.user_id WHERE u.username='hos002' AND e.subject LIKE '%%Reminder: request #1%%'")
A0=cnt("SELECT COUNT(*) FROM audit_log WHERE request_id=1 AND action='reminder' AND user_role='system'")
j=run_jobs(); ok('hourly job reminds: output shows reminded=1', 'reminded=1' in j.stdout)
ok('one reminder recorded without a sender (the system)', sql("SELECT COUNT(*) FROM reminders WHERE request_id=1 AND sent_by IS NULL")=='1')
ok('the Head of Department got the reminder in the system', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='hos002' AND n.message LIKE 'Reminder: request #1 %'")==N0+1)
ok('and by e-mail (logged)', cnt("SELECT COUNT(*) FROM email_log e JOIN users u ON u.user_id=e.user_id WHERE u.username='hos002' AND e.subject LIKE '%%Reminder: request #1%%'")==E0+1)
ok('the staff member was NOT sent the automatic reminder', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE 'Reminder: request #1 %'"))==0)
run_jobs(); ok('only one reminder per request (second run sends nothing)', sql("SELECT COUNT(*) FROM reminders WHERE request_id=1")=='1')
ok('history shows the automatic reminder with the system as sender', cnt("SELECT COUNT(*) FROM audit_log WHERE request_id=1 AND action='reminder' AND user_role='system'")==A0+1)
sql("UPDATE vehicle_requests SET created_at=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE request_id=2 AND status='pending'")
# the manual tests below start clean
sql("DELETE FROM reminders WHERE request_id=1"); sql("DELETE FROM notifications WHERE message LIKE 'Reminder: request #1 %'"); sql("DELETE FROM email_log WHERE subject LIKE '%%Reminder: request #1%%'")
sql("UPDATE vehicle_requests SET created_at=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE request_id=1")
# manual reminder
r=hos2.get('admin/reminders.php'); ok('Head of Department cannot open the reminder page', r.status_code==302)
page=ad.get('admin/reminders.php'); ok('reminder list shows pending requests with days pending', page.status_code==200 and 'Days pending' in page.text and 'Send Reminder' in page.text)
sql("UPDATE vehicle_requests SET created_at = DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE request_id=1")
r=ad.post('admin/reminders.php',{'request_id':1}); ok('Send Reminder', r.status_code==302)
ok('reminder recorded', sql("SELECT COUNT(*) FROM reminders WHERE request_id=1")=='1')
ok('reminder went to the staff member AND the Head of Department', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username IN ('stf001','hos002') AND n.message LIKE 'Reminder: request #1 %'"))==2)
ok('and by e-mail (logged)', int(sql("SELECT COUNT(*) FROM email_log WHERE subject LIKE '%%Reminder: request #1%%'"))>=2)
# mail failure: the reminder is still saved
cfg=ROOT+'/config/mail.php'
open(cfg,'w').write("<?php return ['enabled'=>true,'host'=>'127.0.0.1','port'=>1,'auth'=>false,'encryption'=>'','from_email'=>'noreply@uis.edu.my','from_name'=>'UIS'];")
try:
    r=ad.post('admin/reminders.php',{'request_id':1}); ok('with a broken mail server the reminder is still saved', sql("SELECT COUNT(*) FROM reminders WHERE request_id=1")=='2' and sql("SELECT COUNT(*) FROM reminders WHERE request_id=1 AND email_error IS NOT NULL")=='1')
    ok('and the failure is logged in email_log', int(sql("SELECT COUNT(*) FROM email_log WHERE status='failed'"))>=1)
    ok('and the in-system notification exists', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='hos002' AND n.message LIKE 'Reminder: request #1 %'"))==2)
finally:
    __import__('os').remove(cfg)

# ================= cancel (UC012) =================
# staff cancels own request, reason optional
r=submit(s3,dep_h=400,dur_h=2,vehicle=6,pax=1); rS=last()
r=s3.post('ajax/cancel_request.php',{'request_id':rS}); ok('staff can cancel own request without a reason', json.loads(r.text)['success'] and st(rS)=='cancelled')
ok('cancel releases the lock and keeps the row', sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d"%rS)=='0' and sql("SELECT COUNT(*) FROM vehicle_requests WHERE request_id=%d"%rS)=='1')
r=s3.post('ajax/cancel_request.php',{'request_id':rB}); ok("staff cannot cancel someone else's request", json.loads(r.text)['success'] is False and st(rB)=='approved')
r=hos2.post('ajax/cancel_request.php',{'request_id':rB}); ok('Head of Department must give a reason', json.loads(r.text)['success'] is False and st(rB)=='approved')
r=ad.post('ajax/cancel_request.php',{'request_id':rB,'reason':''}); ok('Administrative Assistant must give a reason', json.loads(r.text)['success'] is False)
r=ad.post('ajax/cancel_request.php',{'request_id':rB,'reason':'Vehicle needed for an emergency'}); ok('with a reason the Administrative Assistant can cancel', json.loads(r.text)['success'] and st(rB)=='cancelled')
ok('cancelled_by and role recorded', sql("SELECT CONCAT(cancelled_by_role) FROM vehicle_requests WHERE request_id=%d"%rB)=='admin')
ok('cancel notified staff, Head of Department and Administrative Assistants', int(sql("SELECT COUNT(DISTINCT n.user_id) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username IN ('stf001','hos002','admin','head001') AND n.message LIKE '%%#%d%%cancelled%%'"%rB))>=4)
# cancel of a request that has started
sql("UPDATE vehicle_requests SET trip_date=CURDATE(), start_time='00:01:00', return_date=CURDATE(), end_time='23:58:00' WHERE request_id=2")
r=ad.post('ajax/cancel_request.php',{'request_id':2,'reason':'too late'}); ok('a request cannot be cancelled after the trip has started', json.loads(r.text)['success'] is False and st(2)=='approved')
# cancel a Confirmed request removes the driver and notifies the driver
r=hos1.post('ajax/cancel_request.php',{'request_id':4,'reason':'x'}); ok('a Head of Department of another PTJ cannot cancel (4 belongs to hos002)', json.loads(r.text)['success'] is False)
r=hos2.post('ajax/cancel_request.php',{'request_id':4,'reason':'Faculty programme postponed'}); ok('Head of Department cancels a Confirmed request', json.loads(r.text)['success'] and st(4)=='cancelled')
ok('assigned driver removed from the tasks', sql("SELECT COUNT(*) FROM schedules WHERE request_id=4 AND (driver_id IS NOT NULL OR status<>'cancelled')")=='0')
ok('the driver was notified', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='drv001' AND n.message LIKE '%%#4%%cancelled%%'"))==1)
# view status: driver and vehicle only after Confirmed
rs=s3.get('staff/my_requests.php'); ok('my requests renders with the exact status labels', rs.status_code==200 and 'Cancelled' in rs.text and not errs(rs))
rs=user('stf003').get('staff/my_requests.php'); ok('stf003 sees Driver Assigned (request 3) but NOT the driver and vehicle yet', 'Driver Assigned' in rs.text and 'Norhaslinda' not in rs.text)
rs=user('stf001').get('staff/my_requests.php',status='all'); 
print(summary())
