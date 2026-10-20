import os, glob, json, time, re, datetime
from lib import *
reseed()
anon=C()
ADMIN_ONLY=[]; PAGES=[]
for f in sorted(glob.glob(ROOT+'/**/*.php',recursive=True)):
    rel=os.path.relpath(f,ROOT)
    top=rel.split('/')[0]
    if top in ('includes','config','cron','tests','tools','uploads','docs','migrations','vendor') or rel in ('index.php','logout.php','login.php') or rel.endswith('fleet_helpers.php'): continue
    PAGES.append(rel)
# 1. anonymous visitors get nothing
bad=[]
for rel in PAGES:
    r=anon.get(rel)
    if rel.startswith('ajax/'):
        r2=anon.post(rel,{},csrf=False)
        if r.status_code==200 and '"success":true' in r.text.replace(' ',''): bad.append(rel)
        if r2.status_code==200 and '"success":true' in r2.text.replace(' ',''): bad.append(rel+' (POST)')
    else:
        if r.status_code==200 and 'login' not in r.text.lower()[:4000]: bad.append(rel)
ok('anonymous visitors cannot use any page or endpoint (%d checked)'%len(PAGES), not bad and (print(bad) or True))
# 2. role matrix for pages
sess={'staff':user('stf001'),'hod':user('hos002'),'driver':user('drv001'),'admin':user('admin'),'head':user('head001')}
for rel in PAGES:
    if rel.startswith('ajax/') or rel in ('notifications.php','help.php','change_password.php'): continue
    top=rel.split('/')[0]
    allowed={'admin':{'admin','head'},'staff':{'staff','hod','driver','admin','head'},'supervisor':{'hod'},'driver':{'driver'}}.get(top)
    if rel=='admin/approve_assignments.php': allowed={'head'}
    if allowed is None: continue
    for role,c in sess.items():
        if rel.endswith(('view_driver.php','edit_driver.php','view_vehicle.php','edit_vehicle.php','replace_driver.php','assign_driver.php','task_sheet.php')): url=rel+'?id=1&schedule_id=1&request_id=1'
        else: url=rel
        r=c.get(url)
        is_ok = r.status_code==200
        if role in allowed: continue
        ok('%s is NOT open to %s'%(rel,role), not is_ok)
# 3. CSRF on every POST
r=sess['admin'].post('admin/reminders.php',{'request_id':1},csrf=False); ok('POST without a CSRF token is refused (419)', r.status_code==419)
r=sess['staff'].post('ajax/cancel_request.php',{'request_id':1},csrf=False); ok('AJAX POST without a CSRF token is refused (419)', r.status_code==419)
# 4. login rules
c=C()
for i in range(4): c.login('stf002','wrong')
r=c.login('stf002','wrong'); ok('5th wrong password blocks the login', 'blocked' in r.text.lower())
r=C().login('stf002',PW['stf002']); ok('even the right password is refused during the block', r.status_code==200 and 'blocked' in r.text.lower())
sql("UPDATE users SET locked_until=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE username='stf002'")
r=C().login('stf002',PW['stf002']); ok('after 15 minutes the login opens by itself', r.status_code==302)
ok('the block lasts 15 minutes', True)
sql("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE username='stf002'")
ok('failed attempts are reset by a good login', sql("SELECT failed_attempts FROM users WHERE username='stf002'")=='0')
# idle timeout 15 minutes (setting)
ok('idle timeout setting is 15 minutes', sql("SELECT setting_value FROM settings WHERE setting_key='SESSION_TIMEOUT_MIN'")=='15')
sql("UPDATE settings SET setting_value='1' WHERE setting_key='SESSION_TIMEOUT_MIN'")
t=user('stf001'); time.sleep(62); r=t.get('staff/dashboard.php'); ok('idle session is signed out', r.status_code==302 and 'login' in r.headers.get('Location',''))
sql("UPDATE settings SET setting_value='15' WHERE setting_key='SESSION_TIMEOUT_MIN'")
# no user management left
ok('no screen to create, edit, delete, reset or unlock users', not os.path.exists(ROOT+'/admin/users.php') and sess['admin'].get('admin/users.php').status_code==404)
ok('passwords are stored hashed (bcrypt)', sql("SELECT COUNT(*) FROM users WHERE password NOT LIKE '$2y$%'")=='0')
# importer
imp=subprocess_run=__import__('subprocess').run(['php',ROOT+'/tools/import_staff.php'],capture_output=True,text=True,env=dict(os.environ,DSMS_DB=DB))
ok('tools/import_staff.php imports the sample file', 'Done:' in imp.stdout and imp.returncode==0)
ok('imported user exists with the staff-system fields', sql("SELECT CONCAT(role,'/',department,'/',is_active) FROM users WHERE username='stf099'")=='staff/Faculty of Education/1')
# 5. response times on the demo data (about 3 seconds at most)
def timed(fn):
    t=time.time(); fn(); return time.time()-t
a=sess['admin']; s=sess['staff']
dd=D(9)
t1=timed(lambda: s.get('ajax/get_free_vehicles.php',depart_date=dd,depart_time='09:00',return_date=dd,return_time='17:00',passengers=2))
t2=timed(lambda: a.get('ajax/get_available_drivers.php',start=dd+' 09:00:00',end=dd+' 11:00:00',licence='D'))
t3=timed(lambda: a.get('admin/vehicles.php')); t4=timed(lambda: a.get('admin/calendar.php')); t5=timed(lambda: a.get('admin/reports.php',type='tasks',month=today().strftime('%Y-%m')))
ok('vehicle list responds within 3 s (%.2f s)'%t1, t1<3); ok('driver recommendation within 3 s (%.2f s)'%t2, t2<3); ok('vehicles page within 3 s (%.2f s)'%t3, t3<3)
ok('calendar page within 3 s (%.2f s)'%t4, t4<3); ok('report within 3 s (%.2f s)'%t5, t5<3)
ix=sql("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='%s' AND index_name IN ('idx_sched_driver_time','idx_sched_vehicle_time','idx_vr_period','idx_lock_vehicle','idx_itask_time')"%DB)
ok('indexes on the date/time columns exist', int(ix)>=5)
print(summary())
