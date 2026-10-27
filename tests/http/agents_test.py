import re, json, zipfile, io, subprocess, datetime, sys
import lib
from lib import ok, sql, rows, D, user, errs
import pdfcheck

def main_html(r):
    i = r.text.find('id="mainContent"'); return r.text[i:] if i >= 0 else r.text
def table(html):
    t = html[html.find('<table'):html.find('</table>')]
    out = []
    for tr in re.findall(r'<tr[^>]*>(.*?)</tr>', t, re.S):
        out.append([re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', c)).strip() for c in re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', tr, re.S)])
    return out
def flash(r):
    m = re.search(r'alert[^>]*>(.*?)</div>', r.text, re.S)
    return re.sub(r'<[^>]+>', '', m.group(1)).strip() if m else ''
def count(q): return int(sql(q).split()[0])

lib.reseed()
admin = user('admin'); head = user('head001'); stf = user('stf001'); hod = user('hos001')
drv = {n: user('drv%03d' % n) for n in (1, 2, 3, 5, 6, 8, 9)}

# ---------------- role checks ----------------
for name, c in (('staff', stf), ('hod', hod), ('driver', drv[1])):
    for p in ('admin/internal_tasks.php', 'admin/welfare.php', 'admin/calendar.php', 'admin/reports.php'):
        r = c.get(p); ok(f'{name} cannot open {p}', r.status_code == 302 and 'Selangor' not in r.text)
    r = c.get('ajax/calendar_events.php', start=D(0), end=D(7)); ok(f'{name} calendar json 403', r.status_code == 403 and json.loads(r.text)['success'] is False)
    r = c.get('admin/reports.php', type='tasks', month=D(0)[:7], export='pdf'); ok(f'{name} cannot export report', r.status_code == 302)
anon = lib.C()
r = anon.get('ajax/calendar_events.php', start=D(0), end=D(7)); ok('anon calendar json 403', r.status_code == 403)
for p in ('admin/internal_tasks.php', 'driver/schedules.php', 'notifications.php', 'admin/reports.php'):
    ok('anon redirected from ' + p, anon.get(p).status_code == 302)
ok('admin cannot open driver/schedules.php', admin.get('driver/schedules.php').status_code == 302)
for p in ('admin/internal_tasks.php', 'admin/welfare.php', 'admin/calendar.php', 'admin/reports.php', 'notifications.php'):
    for nm, c in (('admin', admin), ('head_admin', head)):
        r = c.get(p); ok(f'{nm} opens {p}', r.status_code == 200 and not errs(r))

# ---------------- internal tasks ----------------
def add(c, **kw):
    d = dict(action='save', task_id='0', task_type='refuel', task_date=D(5), start_time='09:00', end_time='10:00', vehicle_id='5', driver_id='5', location='Petronas', description='Fill up'); d.update({k: str(v) for k, v in kw.items()})
    return c.post('admin/internal_tasks.php', d)
n0 = count('select count(*) from internal_tasks'); notif0 = count('select count(*) from notifications')
r = add(admin); ok('add internal task redirects', r.status_code == 302)
tid = int(sql("select max(task_id) from internal_tasks"))
ok('task added', count('select count(*) from internal_tasks') == n0 + 1)
ok('task row right', rows(f"select task_type,status,driver_id,vehicle_id,start_time,end_time from internal_tasks where task_id={tid}") == [['refuel', 'active', '5', '5', '09:00:00', '10:00:00']])
ok('driver 5 notified of new task (in-system)', count(f"select count(*) from notifications where user_id=15 and message like 'New internal task%'") == 1)
ok('notification link driver/schedules.php', sql("select link from notifications where user_id=15 order by notification_id desc limit 1") == 'driver/schedules.php')
r = add(admin, start_time='09:30', end_time='10:30', vehicle_id=6); ok('driver clash refused (overlap)', r.status_code == 200 and count('select count(*) from internal_tasks') == n0 + 1 and 'Has an internal task' in r.text)
r = add(admin, start_time='09:30', end_time='10:30', driver_id=8); ok('vehicle clash refused (overlap)', count('select count(*) from internal_tasks') == n0 + 1 and 'invalid-feedback' in r.text and 'at that time' in r.text)
r = add(admin, start_time='10:00', end_time='11:00'); ok('adjacent slot allowed (start < end rule)', r.status_code == 302 and count('select count(*) from internal_tasks') == n0 + 2)
tid_adj = int(sql("select max(task_id) from internal_tasks"))
r = add(admin, vehicle_id=11, start_time='13:00', end_time='14:00'); ok('licence class refused (D driver, GDL lorry)', count('select count(*) from internal_tasks') == n0 + 2 and 'licence class' in r.text)
r = add(admin, driver_id=11, vehicle_id=5, start_time='15:00', end_time='16:00'); ok('inactive driver refused', count('select count(*) from internal_tasks') == n0 + 2)
r = add(admin, vehicle_id=4, driver_id=8, start_time='15:00', end_time='16:00'); ok('vehicle in maintenance refused', count('select count(*) from internal_tasks') == n0 + 2 and 'maintenance' in r.text)
r = add(admin, vehicle_id=3, driver_id=8, task_date=D(5), start_time='12:00', end_time='13:00'); ok('vehicle locked by request period refused', count('select count(*) from internal_tasks') == n0 + 2 and 'at that time' in r.text)
r = add(admin, task_date=D(-1)); ok('past date refused', count('select count(*) from internal_tasks') == n0 + 2)
r = add(admin, start_time='11:00', end_time='10:00', driver_id=8); ok('end before start refused', count('select count(*) from internal_tasks') == n0 + 2)
r = add(admin, task_type='hacker', driver_id=8, start_time='17:00', end_time='18:00'); ok('bad task type refused', count('select count(*) from internal_tasks') == n0 + 2)
nb = count('select count(*) from internal_tasks')
r = add(admin, driver_id=1, start_time='07:45', end_time='08:30', task_date=D(3), vehicle_id=5); ok('driver clash with a request task refused', count('select count(*) from internal_tasks') == nb and 'Already has a trip' in r.text)
# update: same task, only description (must not clash with itself)
r = add(admin, task_id=tid, description='Changed note'); ok('update without self-clash', r.status_code == 302 and sql(f"select description from internal_tasks where task_id={tid}") == 'Changed note')
ok('driver notified of change', count("select count(*) from notifications where user_id=15 and message like 'Your internal task was changed%'") == 1)
# update with a driver change
r = add(admin, task_id=tid, driver_id=8); ok('driver changed', sql(f"select driver_id from internal_tasks where task_id={tid}") == '8')
ok('old driver told "no longer assigned"', count("select count(*) from notifications where user_id=15 and message like 'You are no longer assigned%'") == 1)
ok('new driver told', count("select count(*) from notifications where user_id=18 and message like 'New internal task%'") == 1)
ok('update did not add a row', count('select count(*) from internal_tasks') == nb)
# edit page
r = admin.get('admin/internal_tasks.php', edit=tid); ok('edit form shows values', r.status_code == 200 and 'Update task #%d' % tid in r.text and 'Fill up' in r.text)
# cancel
r = admin.post('admin/internal_tasks.php', dict(action='cancel', task_id=tid, reason='')); ok('cancel needs a reason', sql(f"select status from internal_tasks where task_id={tid}") == 'active')
r = admin.post('admin/internal_tasks.php', dict(action='cancel', task_id=tid, reason='ab')); ok('too short reason refused', sql(f"select status from internal_tasks where task_id={tid}") == 'active')
r = admin.post('admin/internal_tasks.php', dict(action='cancel', task_id=tid, reason='Vehicle not needed')); 
ok('cancelled with reason (row kept)', rows(f"select status,cancel_reason,cancelled_at is not null from internal_tasks where task_id={tid}") == [['cancelled', 'Vehicle not needed', '1']])
ok('driver told about cancellation + reason', count("select count(*) from notifications where user_id=18 and message like '%cancelled%Vehicle not needed%'") == 1)
r = admin.post('admin/internal_tasks.php', dict(action='delete', task_id=tid)); ok('no delete action: row still exists', count(f"select count(*) from internal_tasks where task_id={tid}") == 1)
r = admin.get('admin/internal_tasks.php', edit=tid); ok('cancelled task cannot be edited', r.status_code == 302)
r = admin.post('admin/internal_tasks.php', dict(action='cancel', task_id=tid, reason='again please')); ok('cannot cancel twice', count("select count(*) from notifications where message like '%cancelled%again please%'") == 0)
r = add(admin, driver_id=8, vehicle_id=5, start_time='09:00', end_time='09:50'); ok('vehicle slot free again after cancel (overlaps cancelled task only)', r.status_code == 302)
r = admin.get('admin/internal_tasks.php', status='cancelled', when='upcoming'); ok('list shows cancelled badge and reason', 'Cancelled' in r.text and 'Vehicle not needed' in r.text and not errs(r))
r = admin.get('admin/internal_tasks.php', when='past'); ok('past tab lists past tasks', 'Workshop - send vehicle' in r.text)
r = admin.get('admin/internal_tasks.php', driver_id=9, from_=D(0)); fl = table(admin.get('admin/internal_tasks.php', driver_id=9).text); ok('driver filter lists their task only', len(fl) == 2 and 'Test drive' in fl[1][1])

# ---------------- welfare ----------------
M = '2026-12'
ex = lambda **kw: dict(action='generate', month=M, **{('use[%s]' % k): '1' for k in (4, 2, 7)}, **kw)
data = {'action': 'generate', 'month': M, 'use[4]': '1', 'use[2]': '1', 'use[7]': '1', 'use[11]': '1', 'order[4]': '1', 'order[2]': '2', 'order[7]': '3', 'order[11]': '4'}
r = admin.post('admin/welfare.php', data)
got = rows("select duty_date, driver_id from welfare_roster where duty_date like '2026-12-%' order by duty_date")
ok('welfare generated for all 31 days', len(got) == 31)
ok('rotation order 4,2,7 repeats (inactive driver 11 not used)', [g[1] for g in got[:7]] == ['4', '2', '7', '4', '2', '7', '4'] and '11' not in {g[1] for g in got})
ok('drivers told about their duty days', count("select count(*) from notifications where user_id in (12,14,17) and message like 'You are on welfare duty%'") == 3)
r = admin.post('admin/welfare.php', dict(action='generate', month=M, **{'use[1]': '1', 'order[1]': '1'}))
ok('fill-only keeps existing days', sql("select count(distinct driver_id) from welfare_roster where duty_date like '2026-12-%'") == '3')
r = admin.post('admin/welfare.php', dict(action='generate', month=M, replace_existing='1', **{'use[1]': '1', 'use[3]': '1', 'order[1]': '1', 'order[3]': '2'}))
ok('regenerate replaces the month', sql("select group_concat(distinct driver_id order by driver_id) from welfare_roster where duty_date like '2026-12-%'") == '1,3' and count("select count(*) from welfare_roster where duty_date like '2026-12-%'") == 31)
ok('removed drivers told', count("select count(*) from notifications where user_id=14 and message like '%no longer on welfare duty%'") >= 1)
admin.post('admin/welfare.php', dict(action='generate', month=M, replace_existing='1')); ok('no driver chosen: refused, month unchanged', 'at least one' in admin.get('admin/welfare.php', month=M).text and sql("select group_concat(distinct driver_id order by driver_id) from welfare_roster where duty_date like '2026-12-%'") == '1,3')
day = '2026-12-10'; cur = sql(f"select driver_id from welfare_roster where duty_date='{day}'")
newd = '5' if cur != '5' else '6'
n1 = count('select count(*) from notifications')
r = admin.post('admin/welfare.php', dict(action='update', month=M, duty_date=day, driver_id=newd, reason='')); ok('day update needs a reason', sql(f"select driver_id from welfare_roster where duty_date='{day}'") == cur)
r = admin.post('admin/welfare.php', dict(action='update', month=M, duty_date=day, driver_id=newd, reason='Annual leave'))
ok('day updated with reason + replaced_from', rows(f"select driver_id,replaced_from_driver_id,replacement_reason from welfare_roster where duty_date='{day}'") == [[newd, cur, 'Annual leave']])
ok('both drivers notified', count('select count(*) from notifications') == n1 + 2 and count(f"select count(*) from notifications where user_id={10+int(cur)} and message like '%no longer on welfare duty%Annual leave%'") == 1 and count(f"select count(*) from notifications where user_id={10+int(newd)} and message like '%on welfare duty%'") >= 1)
ok('other days untouched', count("select count(*) from welfare_roster where duty_date like '2026-12-%'") == 31)
r = admin.post('admin/welfare.php', dict(action='update', month=M, duty_date=day, driver_id=11, reason='x is inactive')); ok('inactive driver refused', sql(f"select driver_id from welfare_roster where duty_date='{day}'") == newd)
r = admin.post('admin/welfare.php', dict(action='update', month=M, duty_date=D(-2), driver_id=5, reason='past day')); ok('past day refused', sql(f"select driver_id from welfare_roster where duty_date='{D(-2)}'") == '9')
r = admin.get('admin/welfare.php', month=M); ok('welfare page shows replacement reason', 'Annual leave' in r.text and not errs(r))

# ---------------- calendar ----------------
def cal(c, s, e, **kw):
    r = c.get('ajax/calendar_events.php', start=s, end=e, **kw); assert r.status_code == 200, r.text[:200]; return json.loads(r.text)['events']
ev = cal(admin, D(0), D(8))
kinds = {e['kind'] for e in ev}
ok('calendar has trip, internal and welfare', kinds == {'trip', 'internal', 'welfare'})
t4 = [e for e in ev if e.get('request_id') == 4]
ok('trip: both legs of request #4 with Outbound/Return and status', sorted(e['leg'] for e in t4) == ['Outbound trip', 'Return trip'] and t4[0]['request_status'] == 'Confirmed')
ok('trip details: destination + address, officer, waiting', 'Masjid Sultan Salahuddin' in t4[0]['destination'] and 'Shah Alam' in t4[0]['destination'] and 'Norliza' in t4[0]['officer'] and t4[0]['purpose'] and t4[0]['vehicle'] and t4[0]['driver'])
t3 = [e for e in ev if e.get('request_id') == 3]
ok('assigned request #3 shown to the admin with status Driver Assigned', t3 and t3[0]['request_status'] == 'Driver Assigned' and any('Lobby of Block B' in e['waiting'] for e in t3))
ok('internal task in calendar (document delivery D+1)', any(e['kind'] == 'internal' and e['title'] == 'Document delivery' for e in ev))
ok('cancelled internal task not in calendar', not any(e['kind'] == 'internal' and e['id'] == 'i%d' % tid for e in ev))
ok('welfare day in calendar', any(e['kind'] == 'welfare' and e['start'].startswith(D(0)) and e['driver_id'] == 1 for e in ev))
f = cal(admin, D(0), D(8), driver_id=1)
ok('driver filter only that driver', f and all(e['driver_id'] == 1 for e in f) and {e['kind'] for e in f} >= {'trip', 'welfare'})
f = cal(admin, D(0), D(8), vehicle_id=1)
ok('vehicle filter only that vehicle (no welfare)', f and all(e['vehicle_id'] == 1 for e in f) and not any(e['kind'] == 'welfare' for e in f))
ok('cancelled schedule hidden', (sql(f"update schedules set status='cancelled' where schedule_id=2") or True) and not any(e['id'] == 't2' for e in cal(admin, D(0), D(8))))
sql("update schedules set status='approved' where schedule_id=2")
ok('range overlap rule: event ending exactly at range start excluded', not any(e['id'] == 't4' for e in cal(admin, D(4), D(6))))
r = admin.get('ajax/calendar_events.php', start='bad', end='x'); ok('bad dates -> 400 json', r.status_code == 400)
r = admin.get('admin/calendar.php'); ok('calendar page', r.status_code == 200 and 'calDriver' in r.text and not errs(r) and 'cdn.jsdelivr.net/npm/fullcalendar' not in r.text)
r = head.get('ajax/calendar_events.php', start=D(0), end=D(3)); ok('head admin may use calendar json', r.status_code == 200)

# ---------------- driver page ----------------
r = drv[1].get('driver/schedules.php'); h = main_html(r)
ok('driver1 page ok', r.status_code == 200 and not errs(r))
ok('driver1 sees request #4 outbound and return', 'Outbound trip' in h and 'Return trip' in h and 'Request #4' in h)
ok('driver1 sees full address, waiting, purpose, officer + phone', 'Persiaran Masjid, Seksyen 14' in h and 'Norliza binti Abdul Rahman' in h and '012-300 0001' in h and 'Waiting location' in h and 'Main entrance' in h)
ok('driver1 sees vehicle plate + model', 'BGN 9595' in h)
ok('driver1 sees welfare day today', 'Welfare duty' in h)
ok('driver1 does not see request #3 (other driver, not confirmed)', 'Kementerian' not in h and 'Request #3' not in h)
ok('view only: no form / confirm / status button in content', '<form' not in h and 'Confirm' not in h and 'in_progress' not in h and 'update_schedule_status' not in h and 'type="submit"' not in h)
r = drv[3].get('driver/schedules.php'); h3 = main_html(r)
ok('driver3: request #3 tasks hidden (Driver Assigned)', 'Kementerian' not in h3 and 'Request #3' not in h3 and 'Outbound' not in h3)
sql("update vehicle_requests set status='processed' where request_id=3")
h3 = main_html(drv[3].get('driver/schedules.php'))
ok('driver3 sees own outbound only once Confirmed (not driver5 return)', 'Outbound trip' in h3 and 'Return trip' not in h3 and 'Lobby of Block B' in h3 and 'No. 2, Menara 2' in h3)
h5 = main_html(drv[5].get('driver/schedules.php'))
ok('driver5 sees only own return trip', 'Return trip' in h5 and 'Outbound trip' not in h5)
ok('driver1 still does not see request #3', 'Request #3' not in main_html(drv[1].get('driver/schedules.php')))
sql("update vehicle_requests set status='assigned' where request_id=3")
h9 = main_html(drv[9].get('driver/schedules.php'))
ok('driver9 sees own internal task (test drive), not others', 'Test drive' in h9 and 'Document delivery' not in h9 and 'Request #' not in h9)
ok('driver5 sees the refuelling task given by admin', 'Refuelling' in main_html(drv[5].get('driver/schedules.php')) and 'Petronas' in main_html(drv[5].get('driver/schedules.php')))
ok('cancelled internal task not shown to driver 8', 'Vehicle not needed' not in main_html(drv[8].get('driver/schedules.php')))
hp = main_html(drv[8].get('driver/schedules.php', tab='past'))
ok('past tab shows completed request #8 tasks for driver 8', 'Past Tasks' in hp and 'Request #8' in hp and 'Outbound trip' in hp)
ok('past tab hides upcoming', 'Request #4' not in main_html(drv[1].get('driver/schedules.php', tab='past')))
ok('past tab shows past internal task (workshop send, driver 2)', 'Workshop - send vehicle' in main_html(drv[2].get('driver/schedules.php', tab='past')))
ok('legacy schedules without a request are hidden', 'Request #' not in main_html(drv[6].get('driver/schedules.php', tab='past')))
r = drv[1].get('driver/dashboard.php'); ok('driver dashboard is its own page (today, next 7 days) and links to My Tasks', r.status_code == 200 and 'dash-hero' in r.text and 'driver/schedules.php' not in r.url and 'schedules.php' in r.text)
dates = re.findall(r'class="when">[A-Za-z]{3}, (\d\d [A-Za-z]{3} \d{4})', main_html(drv[1].get('driver/schedules.php')))
ds = [datetime.datetime.strptime(d, '%d %b %Y') for d in dates]; ok('upcoming sorted by date', ds == sorted(ds) and len(ds) >= 3)
# task sheet
ok('driver opens own confirmed task sheet', drv[1].get('driver/task_sheet.php', id=3).status_code == 200 and 'PERSIARAN MASJID' in drv[1].get('driver/task_sheet.php', id=3).text)
ok('driver cannot open unconfirmed request task sheet', drv[3].get('driver/task_sheet.php', id=2).status_code == 302)
ok('driver cannot open another driver task sheet', drv[2].get('driver/task_sheet.php', id=3).status_code == 302)
ok('admin opens any task sheet', admin.get('admin/task_sheet.php', id=2).status_code == 200 and not errs(admin.get('admin/task_sheet.php', id=2)))

# ---------------- notifications ----------------
sql("delete from notifications where user_id=8")
sql("insert into notifications (user_id,message,link,is_read,created_at) values (8,'old read','staff/my_requests.php',1,now()), (8,'unread older','staff/my_requests.php',0,now() - interval 2 hour), (8,'unread newer','staff/my_requests.php?x=1',0,now() - interval 1 hour), (8,'external','https://evil.example.com/x',0,now()), (8,'protocol relative','//evil.example.com',0,now()), (8,'js','javascript:alert(1)',0,now()), (8,'dots','../../etc/passwd',0,now()), (8,'nolink',NULL,0,now()), (8,'backslash','\\\\evil.example.com',0,now())")
r = stf.get('notifications.php'); h = main_html(r)
msgs = re.findall(r'<div class="(?:fw-semibold)?">([^<]+)</div>', h)
ok('notifications page ok', r.status_code == 200 and not errs(r))
pos = {m: h.find(m) for m in ('unread newer', 'unread older', 'old read')}
ok('unread first, newest first, read last', pos['unread newer'] < pos['unread older'] < pos['old read'] and pos['unread newer'] > 0)
ok('shows count of new', '8 new' in h)
nid = lambda m: int(sql(f"select notification_id from notifications where user_id=8 and message='{m}'"))
r = stf.get('notifications.php', open=nid('unread newer'))
ok('open marks read and goes to the link', r.status_code == 302 and r.headers['Location'].endswith('/staff/my_requests.php?x=1') and sql(f"select is_read from notifications where notification_id={nid('unread newer')}") == '1')
for m in ('external', 'protocol relative', 'js', 'dots', 'nolink', 'backslash'):
    r = stf.get('notifications.php', open=nid(m))
    loc = r.headers.get('Location', '')
    ok(f'unsafe link refused ({m})', r.status_code == 302 and loc.endswith('/notifications.php') and 'evil' not in loc and sql(f"select is_read from notifications where notification_id={nid(m)}") == '1')
other = int(sql("select min(notification_id) from notifications where user_id<>8")) if count("select count(*) from notifications where user_id<>8") else 0
if other:
    stf.get('notifications.php', open=other); ok("cannot open someone else's notification", sql(f"select is_read from notifications where notification_id={other}") == '0')
sql("update notifications set is_read=0 where user_id=8 and message in ('unread older')")
r = stf.post('notifications.php', dict(action='read_all')); ok('mark all as read', sql("select count(*) from notifications where user_id=8 and is_read=0") == '0')
sql("insert into notifications (user_id,message,link) select 8, concat('bulk ', seq), NULL from (select @r:=@r+1 seq from information_schema.columns c, (select @r:=0) i limit 45) t")
r = stf.get('notifications.php', page=2); ok('pagination page 2', r.status_code == 200 and 'page=3' in r.text)
ok('hod/driver/admin can use notifications page', all(c.get('notifications.php').status_code == 200 for c in (hod, drv[1], admin, head)))
ok('notifyUsers keeps working (in-system + e-mail log)', count("select count(*) from notifications where user_id=15") >= 2)

# ---------------- reports ----------------
M = D(0)[:7]
ws, we = '08:00:00', '17:30:00'
a, b = M + '-01', sql(f"select last_day('{M}-01')")
def rep(t, **kw): return admin.get('admin/reports.php', type=t, month=M, **kw)
r = rep('tasks'); tb = table(main_html(r))
exp = {int(x[0]): x for x in rows(f"""select d.driver_id, d.name, d.employee_id,
   (select count(*) from schedules s where s.driver_id=d.driver_id and s.status<>'cancelled' and s.trip_date between '{a}' and '{b}'),
   (select count(*) from internal_tasks t where t.driver_id=d.driver_id and t.status='active' and t.task_date between '{a}' and '{b}') from drivers d""")}
ok('tasks per driver table', r.status_code == 200 and not errs(r) and tb[0][:5] == ['Driver', 'Employee ID', 'Request tasks', 'Internal tasks', 'Total tasks'])
good = True; seen = 0
for line in tb[1:-1]:
    e = [x for x in exp.values() if x[1] == line[0]][0]
    if line[2:] != [e[3], e[4], str(int(e[3]) + int(e[4]))]: good = False; print('  mismatch', line, e)
    seen += 1
ok('tasks per driver numbers match SQL', good and seen >= 10)
tot = sum(int(x[3]) + int(x[4]) for x in exp.values() if any(l[0] == x[1] for l in tb[1:-1]))
ok('tasks totals row', tb[-1][0] == 'Total' and tb[-1][4] == str(tot))
r = rep('outside'); tb = table(main_html(r))
expo = {x[0]: x for x in rows(f"""select d.name,
   (select count(*) from schedules s where s.driver_id=d.driver_id and s.status<>'cancelled' and s.trip_date between '{a}' and '{b}') + (select count(*) from internal_tasks t where t.driver_id=d.driver_id and t.status='active' and t.task_date between '{a}' and '{b}'),
   (select count(*) from schedules s where s.driver_id=d.driver_id and s.status<>'cancelled' and s.trip_date between '{a}' and '{b}' and (dayofweek(s.trip_date) in (1,7) or s.start_time<'{ws}' or s.end_time>'{we}')) +
   (select count(*) from internal_tasks t where t.driver_id=d.driver_id and t.status='active' and t.task_date between '{a}' and '{b}' and (dayofweek(t.task_date) in (1,7) or t.start_time<'{ws}' or t.end_time>'{we}')) from drivers d""")}
good = all(l[2] == expo[l[0]][1] and l[3] == expo[l[0]][2] for l in tb[1:-1]); ok('outside working hours numbers match SQL', good and len(tb) > 5 and any(l[3] != '0' for l in tb[1:-1]))
r = rep('weekend'); tb = table(main_html(r))
expk = {x[0]: x for x in rows(f"""select d.name,
   (select count(*) from schedules s where s.driver_id=d.driver_id and s.status<>'cancelled' and s.trip_date between '{a}' and '{b}') + (select count(*) from internal_tasks t where t.driver_id=d.driver_id and t.status='active' and t.task_date between '{a}' and '{b}'),
   (select count(*) from schedules s where s.driver_id=d.driver_id and s.status<>'cancelled' and s.trip_date between '{a}' and '{b}' and dayofweek(s.trip_date) in (1,7)) +
   (select count(*) from internal_tasks t where t.driver_id=d.driver_id and t.status='active' and t.task_date between '{a}' and '{b}' and dayofweek(t.task_date) in (1,7)) from drivers d""")}
ok('weekend tasks per driver: headers', tb[0][:4] == ['Driver', 'Employee ID', 'Total tasks', 'Weekend tasks'])
ok('weekend tasks numbers match SQL (Saturday and Sunday only)', len(tb) > 5 and all(l[2] == expk[l[0]][1] and l[3] == expk[l[0]][2] for l in tb[1:-1]))
ok('weekend tasks totals row', tb[-1][0] == 'Total' and tb[-1][3] == str(sum(int(l[3]) for l in tb[1:-1])))
r = rep('welfare'); tb = table(main_html(r))
expw = {x[0]: x[1] for x in rows(f"select d.name, (select count(*) from welfare_roster w where w.driver_id=d.driver_id and w.duty_date between '{a}' and '{b}') from drivers d")}
ok('welfare days numbers match SQL', all(l[2] == expw[l[0]] for l in tb[1:-1]) and tb[-1][2] == str(sum(int(l[2]) for l in tb[1:-1])) and int(tb[-1][2]) == count(f"select count(*) from welfare_roster where duty_date between '{a}' and '{b}'"))
r = rep('vehicle'); tb = table(main_html(r))
expv = {x[0]: x for x in rows(f"""select concat_ws(' ', v.plate_number, v.brand, v.model),
   (select count(*) from schedules s where s.vehicle_id=v.vehicle_id and s.status<>'cancelled' and s.trip_date between '{a}' and '{b}'),
   round(coalesce((select sum(timestampdiff(minute,s.start_dt,s.end_dt)) from schedules s where s.vehicle_id=v.vehicle_id and s.status<>'cancelled' and s.trip_date between '{a}' and '{b}'),0)/60,2),
   round(coalesce((select sum(timestampdiff(minute,t.start_dt,t.end_dt)) from internal_tasks t where t.vehicle_id=v.vehicle_id and t.status='active' and t.task_date between '{a}' and '{b}'),0)/60,2),
   coalesce((select sum(greatest(0, datediff(least(m.end_date,'{b}'), greatest(m.start_date,'{a}'))+1)) from vehicle_maintenance m where m.vehicle_id=v.vehicle_id and m.start_date<='{b}' and m.end_date>='{a}'),0) from vehicles v""")}
num = lambda s: float(s)
good = True
for l in tb[1:-1]:
    e = expv[l[0]]
    if not (int(l[2]) == int(e[1]) and abs(num(l[3]) - num(e[2])) < .01 and abs(num(l[4]) - num(e[3])) < .01 and int(l[5]) == int(float(e[4]))): good = False; print('  mismatch', l, e)
ok('vehicle usage numbers match SQL (trips, hours, internal hours, maintenance days)', good and len(tb) > 5)
ok('maintenance days counted for WB 3322 D', any(l[0].startswith('WB 3322') and int(l[5]) > 0 for l in tb[1:-1]))
# driver replacements (UC020 records, UC031)
sql("DELETE FROM driver_replacements")
sid = sql("SELECT schedule_id FROM schedules WHERE driver_id IS NOT NULL AND status<>'cancelled' LIMIT 1")
sql("INSERT INTO driver_replacements (schedule_id, previous_driver_id, new_driver_id, reason, changed_by, changed_at) VALUES (%s, 1, 2, 'Driver fell sick', 5, '%s 10:30:00')" % (sid, a))
r = rep('replacements'); tb = table(main_html(r))
ok('driver replacements: headers', r.status_code == 200 and not errs(r) and tb[0] == ['Date changed', 'Request', 'Trip date', 'Previous driver', 'New driver', 'Reason', 'Changed by'])
ok('driver replacements: the row shows previous driver, new driver, reason and who', len(tb) == 3 and 'Driver fell sick' in tb[1][5] and tb[1][3] != tb[1][4] and tb[-1][0] == 'Total: 1')
sql("INSERT INTO driver_replacements (schedule_id, previous_driver_id, new_driver_id, reason, changed_by, changed_at) VALUES (%s, 2, 1, 'Next month', 5, DATE_ADD('%s', INTERVAL 40 DAY))" % (sid, a))
ok('driver replacements: only the selected month', len(table(main_html(rep('replacements')))) == 3)
for ex in ('pdf', 'xlsx'):
    rr = rep('replacements', export=ex)
    ok('driver replacements export %s works' % ex, rr.status_code == 200 and (rr.content[:5] == b'%PDF-' if ex == 'pdf' else rr.content[:2] == b'PK'))
# no data for the month (UC031 exception 3.1)
e = admin.get('admin/reports.php', type='replacements', month='2020-01'); ok('no data: the message is shown, no PHP errors', 'No data for the selected month' in e.text and not errs(e))
ok('no data: PDF and Excel still download', admin.get('admin/reports.php', type='replacements', month='2020-01', export='pdf').content[:5] == b'%PDF-' and admin.get('admin/reports.php', type='tasks', month='2020-01', export='xlsx').content[:2] == b'PK')
ok('the six report types of UC031 (tasks, weekend, outside hours, welfare, replacements, vehicle)', len(re.findall(r'<option value="(tasks|weekend|outside|welfare|replacements|vehicle)"', rep('tasks').text)) == 6 and 'report_driver' not in rep('tasks').text)
ok('bad type falls back', admin.get('admin/reports.php', type='zzz', month='bad').status_code == 200)
# export pdf / xlsx
for t in ('tasks', 'weekend', 'outside', 'welfare', 'replacements', 'vehicle'):
    r = rep(t, export='pdf'); good, info = pdfcheck.check_pdf(r.content)
    ok(f'{t} PDF valid (header, xref offsets, stream lengths)', r.headers['Content-Type'] == 'application/pdf' and 'attachment' in r.headers['Content-Disposition'] and good, ) 
    if not good: print('  ', info)
    scr = [l[0] for l in table(main_html(rep(t)))[1:-1]]
    ok(f'{t} PDF contains the same rows as the screen', all(s.split()[0] in info['text'] for s in scr[:6]) and 'Total' in info['text'])
    r = rep(t, export='xlsx'); z = zipfile.ZipFile(io.BytesIO(r.content))
    ok(f'{t} XLSX opens as a zip with the OOXML parts', z.testzip() is None and {'[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml'} <= set(z.namelist()) and 'spreadsheetml.sheet' in r.headers['Content-Type'] and 'attachment' in r.headers['Content-Disposition'] and r.content[:2] == b'PK')
    x = z.read('xl/worksheets/sheet1.xml').decode()
    cells = re.findall(r'<c r="([A-Z]+)(\d+)"[^>]*?(?:/>|>(?:<is><t[^>]*>(.*?)</t></is>|<v>(.*?)</v>)</c>)', x)
    sheetrows = {}
    for col, rw, s, v in cells: sheetrows.setdefault(int(rw), {})[col] = s or v
    hdr = [rr for rr in sheetrows.values() if rr.get('A') in ('Driver', 'Vehicle', 'Date changed')][0]
    first = [rr for rr in sheetrows.values() if rr.get('A') and rr.get('A') == (scr[0].replace('&', '&amp;'))]
    ok(f'{t} XLSX has headers, data and a numeric total', bool(hdr) and bool(first) and any((rr.get('A') or '').startswith('Total') for rr in sheetrows.values()))
    ok(f'{t} export starts with file bytes only (no HTML before)', r.content[:2] == b'PK' and rep(t, export='pdf').content[:5] == b'%PDF-')
ok('xlsx numbers are real numbers', re.search(r'<c r="C5"[^>]*><v>\d+</v>', zipfile.ZipFile(io.BytesIO(rep('tasks', export='xlsx').content)).read('xl/worksheets/sheet1.xml').decode()) is not None)
ok('csrf not injected into download', b'csrf_token' not in rep('tasks', export='pdf').content and b'csrf_token' not in rep('tasks', export='xlsx').content)

# ---------------- cleanup of removed things ----------------
import os
gone = 'admin/messages.php driver/messages.php ajax/send_message.php admin/driver_leaves.php admin/audit_log.php admin/schedules.php admin/add_schedule.php admin/edit_schedule.php admin/view_schedule.php admin/auto_assign.php admin/report_driver.php admin/report_workload.php admin/report_vehicle.php admin/report_monthly.php driver/calendar.php ajax/delete_schedule.php ajax/update_schedule_status.php ajax/check_conflict.php ajax/get_busy_resources.php ajax/check_vehicles_free.php assets/js/schedule-busy.js'.split()
ok('out-of-scope files deleted', not any(os.path.exists(lib.ROOT + '/' + g) for g in gone))
ok('php -l on my files', all(subprocess.run(['php', '-l', lib.ROOT + '/' + f], capture_output=True).returncode == 0 for f in 'admin/internal_tasks.php admin/welfare.php admin/calendar.php admin/reports.php admin/task_sheet.php driver/schedules.php driver/dashboard.php driver/task_sheet.php notifications.php ajax/calendar_events.php includes/report_export.php includes/schedule_view.php'.split()))
print(lib._n)
sys.exit(0 if lib.summary() else 1)
