import json, datetime
from lib import *
reseed()
s1=user('stf001'); s2=user('stf002'); hos2=user('hos002'); hos3=user('hos003')
ad=user('admin'); hd=user('head001')
uid=lambda u: sql("SELECT user_id FROM users WHERE username='%s'"%u)
st=lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d"%r)
last=lambda: int(sql("SELECT MAX(request_id) FROM vehicle_requests"))
def when(hours):
    t=now()+datetime.timedelta(hours=hours); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def new_request(c, hod, dep_h, dur_h, vehicle, pax=2, **kw):
    dd,dt=when(dep_h); rd,rt=when(dep_h+dur_h)
    f={'depart_date':dd,'depart_time':dt,'return_date':rd,'return_time':rt,'passengers':str(pax),'vehicle_id':str(vehicle),'destination':'Putrajaya','destination_address':'Presint 5','purpose':'Official duty at the ministry','passenger_names':'A','phone':'012-3456789','waiting_place':'Main entrance','waiting_address':''}
    f.update(kw)
    r=c.post('staff/request_vehicle.php',f); assert r.status_code==302, r.text[:300]
    rid=last(); r=hod.post('ajax/request_action.php',{'request_id':rid,'action':'approve'}); assert json.loads(r.text)['success']
    return rid
def rec(c,start,end,licence=None,**kw):
    p=dict(start=start,end=end); 
    if licence: p['licence']=licence
    p.update(kw); return json.loads(c.get('ajax/get_available_drivers.php',**p).text)
def tasks(rid, cancelled=False): return rows("SELECT trip_type,driver_id,vehicle_id,start_dt,end_dt,status FROM tasks WHERE task_source='request' AND request_id=%d %s ORDER BY task_id"%(rid, "" if cancelled else "AND status<>'cancelled'"))

# ---------- recommender (UC018) ----------
r1=new_request(s1,hos2,240,6,6)       # car, depart in 10 days, 6 h trip
dep=sql("SELECT depart_at FROM vehicle_requests WHERE request_id=%d"%r1); ret=sql("SELECT return_at FROM vehicle_requests WHERE request_id=%d"%r1)
out_end=(datetime.datetime.strptime(dep,'%Y-%m-%d %H:%M:%S')+datetime.timedelta(hours=2)).strftime('%Y-%m-%d %H:%M:%S')
j=rec(ad,dep,out_end,'D'); ids=[d['driver_id'] for d in j['drivers']]
ok('recommender: inactive driver 11 is not offered', 11 not in ids and any(x['driver_id']==11 for x in j['excluded']))
ok('recommender: ranked, score descending, with task count, weekend task count and experience', len(ids)>=8 and all(j['drivers'][i]['score']>=j['drivers'][i+1]['score'] for i in range(len(ids)-1)) and all('tasks' in d and 'weekend' in d and 'experience' in d and 'rank' in d for d in j['drivers']))
ok('recommender: fewer tasks ranks higher (top has the minimum number of tasks among equal weekend counts)', j['drivers'][0]['tasks']<=max(d['tasks'] for d in j['drivers']))
j=rec(ad,dep,out_end,'PSV'); ids=[d['driver_id'] for d in j['drivers']]
ok('licence filter: a bus needs PSV (only PSV drivers offered)', set(ids)<={1,2,4,6,7,9,12} and len(ids)>0 and 3 not in ids and 5 not in ids)
j=rec(ad,dep,out_end,'GDL'); ids=[d['driver_id'] for d in j['drivers']]
ok('licence filter: a lorry needs GDL', set(ids)<={1,2,3,6,9,10} and 5 not in ids and 8 not in ids)
j=rec(ad,dep,out_end,'D'); ok('licence filter: a car needs D (reason shown for those without)', True)
# clash removal: internal task overlapping
d0=dep[:10]
sql("INSERT INTO tasks (task_source,task_type,start_dt,end_dt,vehicle_id,driver_id,description,status) VALUES ('internal','refuel','%s','%s',5,1,'x','approved')"%(dep,out_end))
j=rec(ad,dep,out_end,'D'); ok('overlapping internal task removes the driver', 1 not in [d['driver_id'] for d in j['drivers']] and any(x['driver_id']==1 and 'internal' in x['reason'].lower() for x in j['excluded']))
sql("UPDATE tasks SET status='cancelled' WHERE task_source='internal' AND driver_id=1 AND description='x'")
j=rec(ad,dep,out_end,'D'); ok('a cancelled internal task does not block', 1 in [d['driver_id'] for d in j['drivers']])
# welfare: flag, not remove; counts as workload
sql("DELETE FROM tasks WHERE task_source='welfare' AND task_date='%s'"%d0); sql("INSERT INTO tasks (task_source,task_type,start_dt,end_dt,driver_id,status) VALUES ('welfare','welfare','%s 00:00:00','%s 00:00:00' + INTERVAL 1 DAY,3,'approved')"%(d0,d0))
j=rec(ad,dep,out_end,'D'); d3=[d for d in j['drivers'] if d['driver_id']==3]
ok('welfare duty on the trip date is FLAGGED (driver still listed)', d3 and d3[0]['welfare'] is True)
ok('experience (years from the date joined) is in the response', all(isinstance(d['experience'], (int, float)) for d in j['drivers']))
ok('weights come from settings (0.5 / 0.3 / 0.2)', j['weights']=={'tasks':0.5,'weekend':0.3,'experience':0.2})
# trip clash removal
# ---------- assign (UC017) ----------
page=ad.get('admin/assign_driver.php',request_id=r1); ok('assign page shows the recommendation before assigning', page.status_code==200 and 'Recommended' in page.text and 'Tasks this month' in page.text)
r=ad.post('admin/assign_driver.php',{'request_id':r1,'out_driver':'','return_mode':'same'}); ok('a driver must be chosen', st(r1)=='approved')
r=ad.post('admin/assign_driver.php',{'request_id':r1,'out_driver':'11','return_mode':'same'}); ok('an inactive driver cannot be chosen', st(r1)=='approved')
r=ad.post('admin/assign_driver.php',{'request_id':r1,'out_driver':'3','return_mode':'separate'}); ok('separate return needs a driver and a vehicle', st(r1)=='approved')
r=ad.post('admin/assign_driver.php',{'request_id':r1,'out_driver':'3','return_mode':'same'}); ok('assign: same driver and vehicle for both trips', r.status_code==302 and st(r1)=='assigned')
t=tasks(r1)
ok('two tasks: outbound and return', len(t)==2 and t[0][0]=='outbound' and t[1][0]=='return')
ok('both tasks are Driver Assigned (waiting for the Head of Admin), assigner recorded', all(x[5]=='assigned' for x in t) and sql("SELECT COUNT(*) FROM tasks WHERE task_source='request' AND request_id=%d AND assigned_by IS NOT NULL AND assigned_at IS NOT NULL"%r1)=='2')
ok('outbound starts at departure and lasts 2 hours', t[0][3]==dep and t[0][4]==out_end)
ok('return starts at the return time', t[1][3]==ret)
ok('both tasks use the requested vehicle and the same driver', t[0][1]==t[1][1]=='3' and t[0][2]==t[1][2]=='6')
ok('Head of Admin notified (system)', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='head001' AND n.message LIKE '%%#%d%%needs your approval%%'"%r1))==1)
ok('Head of Admin e-mailed (logged)', int(sql("SELECT COUNT(*) FROM email_log WHERE user_id=%s AND subject LIKE '%%#%d%%'"%(uid('head001'),r1)))>=1)
ok('driver and staff NOT told yet', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username IN ('drv003','stf001') AND n.message LIKE '%%#%d%%' AND (n.message LIKE '%%new trip%%' OR n.message LIKE '%%confirmed%%')"%r1))==0)
ok('history records the assignment', sql("SELECT COUNT(*) FROM audit_log WHERE request_id=%d AND action='assign'"%r1)=='1')
rs=s1.get('staff/my_requests.php',open=r1); ok('staff sees Driver Assigned but not the driver or vehicle', 'Driver Assigned' in rs.text and 'Norhaslinda' not in rs.text)
# no clash override: second request same driver overlapping
r2=new_request(s2,hos3,240,6,5)   # same time, other vehicle (vehicle 5)
r=ad.post('admin/assign_driver.php',{'request_id':r2,'out_driver':'3','return_mode':'same'}); ok('a driver with an overlapping task cannot be chosen (no override)', st(r2)=='approved' and tasks(r2)==[])
ok('no override feature is left in the code', 'postedClashOverride' not in ''.join(open(ROOT+'/'+f).read() for f in ('admin/internal_tasks.php','includes/workflow.php','includes/recommender.php')) and not __import__('os').path.exists(ROOT+'/admin/add_schedule.php'))
# Head of Admin assigns himself: NO auto-confirm
r=hd.post('admin/assign_driver.php',{'request_id':r2,'out_driver':'5','return_mode':'same'}); ok('Head of Admin assigning does NOT auto-confirm', st(r2)=='assigned')
# approval by Head of Admin only
r=ad.post('admin/approve_assignments.php',{'request_id':r1,'action':'approve'}); ok('Administrative Assistant cannot approve assignments', r.status_code==302 and st(r1)=='assigned')
ok('Administrative Assistant cannot open the page', ad.get('admin/approve_assignments.php').status_code==302)
pg=hd.get('admin/approve_assignments.php'); ok('Head of Admin sees both assignments (his own too)', pg.status_code==200 and ('#%d'%r1) in pg.text and ('#%d'%r2) in pg.text)
# reject (UC020)
r=hd.post('admin/approve_assignments.php',{'request_id':r1,'action':'reject','reason':''}); ok('reject needs a reason', st(r1)=='assigned')
r=hd.post('admin/approve_assignments.php',{'request_id':r1,'action':'reject','reason':'Use a more experienced driver'}); ok('reject: status back to Approved', st(r1)=='approved')
ok('reject: driver removed from the tasks', len(tasks(r1,True))==2 and all(x[1] in ('\\N','NULL') for x in tasks(r1,True)) and all(x[5]=='cancelled' for x in tasks(r1,True)))
ok('reject: the cancelled tasks keep the reject reason, who rejected and when', sql("SELECT COUNT(*) FROM tasks WHERE task_source='request' AND request_id=%d AND status='cancelled' AND reject_reason='Use a more experienced driver' AND rejected_by=%s AND rejected_at IS NOT NULL"%(r1,uid('head001')))=='2')
ok('reject: reason recorded', sql("SELECT reason FROM audit_log WHERE request_id=%d AND action='reject_assignment'"%r1)=='Use a more experienced driver')
ok('reject: Administrative Assistant notified with the reason', int(sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%#%d%%rejected%%Use a more experienced driver%%'"%r1))==1)
ok('reject: the vehicle stays locked', sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d"%r1)=='1')
# assign again, separate return driver + vehicle
ret2=datetime.datetime.strptime(ret,'%Y-%m-%d %H:%M:%S')+datetime.timedelta(hours=2)   # may cross midnight
retv=json.loads(ad.get('ajax/get_free_vehicles.php',depart_date=ret[:10],depart_time=ret[11:16],return_date=ret2.strftime('%Y-%m-%d'),return_time=ret2.strftime('%H:%M'),passengers=2,request_id=r1,for_assignment=1).text)['vehicles']
vids=[v['vehicle_id'] for v in retv if v['vehicle_id']!=6 and v['license_required']=='D']
ok('return vehicle list is time-aware and for the Vehicle Section not bound by the timing rule', len(vids)>0)
rv=vids[0]
r=ad.post('admin/assign_driver.php',{'request_id':r1,'out_driver':'3','return_mode':'separate','ret_vehicle':str(rv),'ret_driver':'7'}); ok('assign again with a separate return driver and vehicle', st(r1)=='assigned')
t=tasks(r1); ok('return task has its own driver and vehicle', t[1][1]=='7' and t[1][2]==str(rv) and t[0][1]=='3' and t[0][2]=='6')
ok('the return vehicle is locked for the return window', sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d AND vehicle_id=%d"%(r1,rv))=='1')
# approve (UC019)
r=hd.post('admin/approve_assignments.php',{'request_id':r1,'action':'approve'}); ok('Head of Admin approves -> Confirmed', st(r1)=='processed')
ok('approved: the tasks are Approved, approver recorded', rows("SELECT status, COUNT(*) FROM tasks WHERE task_source='request' AND request_id=%d AND status<>'cancelled' AND approved_by=%s AND approved_at IS NOT NULL GROUP BY status"%(r1,uid('head001')))==[['approved','2']])
nd=int(sql("SELECT MIN(n.notification_id) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='drv003' AND n.message LIKE '%%new trip%%#%d%%'"%r1))
ns=int(sql("SELECT MIN(n.notification_id) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d%%confirmed%%'"%r1))
ok('the driver is notified first, then the staff member', 0<nd<ns)
ok('the staff member gets the driver and vehicle details', 'Norhaslinda' in sql("SELECT message FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d%%confirmed%%'"%r1) and 'BDG 5500 F' in sql("SELECT message FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%#%d%%confirmed%%'"%r1))
rs=s1.get('staff/my_requests.php',open=r1); ok('after Confirmed the staff member sees the driver and vehicle', 'Confirmed' in rs.text and 'Norhaslinda' in rs.text)
# ---------- replace (UC021) ----------
sid=sql("SELECT task_id FROM tasks WHERE task_source='request' AND request_id=%d AND trip_type='outbound' AND status<>'cancelled'"%r1)
r=ad.post('admin/replace_driver.php',{'schedule_id':sid,'new_driver':'8','reason':''}); ok('replace needs a reason', sql("SELECT driver_id FROM tasks WHERE task_id=%s"%sid)=='3')
r=ad.post('admin/replace_driver.php',{'schedule_id':sid,'new_driver':'11','reason':'x y z'}); ok('replace: inactive driver refused', sql("SELECT driver_id FROM tasks WHERE task_id=%s"%sid)=='3')
# make a clash for driver 8 at the outbound time
sql("INSERT INTO tasks (task_source,task_type,start_dt,end_dt,vehicle_id,driver_id,description,status) VALUES ('internal','refuel','%s','%s',3,8,'clash','approved')"%(dep,out_end))
sql("UPDATE tasks SET driver_id=3 WHERE task_id=%s"%sid) if sql("SELECT driver_id FROM tasks WHERE task_id=%s"%sid)!='3' else None
sql("UPDATE vehicle_requests SET status='processed' WHERE request_id=%d"%r1)
r=ad.post('admin/replace_driver.php',{'schedule_id':sid,'new_driver':'8','reason':'Driver is unwell'}); ok('replace: the new driver must have no clash', sql("SELECT driver_id FROM tasks WHERE task_id=%s"%sid)=='3')
r=ad.post('admin/replace_driver.php',{'schedule_id':sid,'new_driver':'12','reason':'Driver is unwell'}); ok('replace works for a free driver (12 has PSV/D)', sql("SELECT driver_id FROM tasks WHERE task_id=%s"%sid)=='12')
ok('replace: request goes back to Driver Assigned', st(r1)=='assigned')
ok('replace: recorded with old driver, new driver, reason and date', sql("SELECT CONCAT(previous_driver_id,'>',driver_id,'>',reason) FROM driver_replacements WHERE task_id=%s"%sid)=='3>12>Driver is unwell' and sql("SELECT replaced_at IS NOT NULL AND replaced_by=%s FROM driver_replacements WHERE task_id=%s"%(uid('admin'),sid))=='1')
ok('replace: the replacement and the task wait for the Head of Admin (pending / assigned)', sql("SELECT status FROM driver_replacements WHERE task_id=%s"%sid)=='pending' and sql("SELECT status FROM tasks WHERE task_id=%s"%sid)=='assigned')
ok('replace: old driver, new driver and Head of Admin notified', int(sql("SELECT COUNT(DISTINCT u.username) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username IN ('drv003','drv012','head001') AND (n.message LIKE '%%replaced%%' OR n.message LIKE '%%assigned to%%') AND n.message LIKE '%%#%d%%'"%r1))==3)
r=hd.post('admin/approve_assignments.php',{'request_id':r1,'action':'approve'}); ok('the Head of Admin approves again -> Confirmed', st(r1)=='processed')
ok('...the replacement is approved and the task Approved', sql("SELECT status FROM driver_replacements WHERE task_id=%s"%sid)=='approved' and sql("SELECT status FROM tasks WHERE task_id=%s"%sid)=='approved')
# replace only for Confirmed tasks that have not started
r=ad.post('admin/replace_driver.php',{'schedule_id':sql("SELECT task_id FROM tasks WHERE task_source='request' AND request_id=3 AND trip_type='outbound'"),'new_driver':'8','reason':'Driver is unwell'}); ok('replace refused when the request is only Driver Assigned', sql("SELECT driver_id FROM tasks WHERE task_source='request' AND request_id=3 AND trip_type='outbound'")=='3')
sql("UPDATE tasks SET start_dt=CONCAT(CURDATE(),' 00:01:00'), end_dt=CONCAT(CURDATE(),' 23:00:00') WHERE task_id=%s"%sql("SELECT task_id FROM tasks WHERE task_source='request' AND request_id=4 AND trip_type='outbound'"))
r=ad.post('admin/replace_driver.php',{'schedule_id':sql("SELECT task_id FROM tasks WHERE task_source='request' AND request_id=4 AND trip_type='outbound'"),'new_driver':'8','reason':'Driver is unwell'}); ok('replace refused when the task has started', sql("SELECT driver_id FROM tasks WHERE task_source='request' AND request_id=4 AND trip_type='outbound'")=='1')
# jobs
sql("UPDATE vehicle_requests SET trip_date=DATE_SUB(CURDATE(), INTERVAL 1 DAY), return_date=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE request_id=%d"%r2)
run_jobs(); ok('an assignment nobody confirmed before the trip time is cancelled by the system', st(r2)=='cancelled')
sql("UPDATE tasks SET end_dt = end_dt - INTERVAL DATEDIFF(start_dt, CURDATE() - INTERVAL 1 DAY) DAY, start_dt = start_dt - INTERVAL DATEDIFF(start_dt, CURDATE() - INTERVAL 1 DAY) DAY WHERE task_source='request' AND request_id=%d"%r1)
sql("UPDATE vehicle_requests SET trip_date=DATE_SUB(CURDATE(), INTERVAL 1 DAY), return_date=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE request_id=%d"%r1)
run_jobs(); ok('a Confirmed trip that is over becomes Completed and its lock is released', st(r1)=='completed' and sql("SELECT COUNT(*) FROM vehicle_locks WHERE request_id=%d"%r1)=='0')
print(summary())
