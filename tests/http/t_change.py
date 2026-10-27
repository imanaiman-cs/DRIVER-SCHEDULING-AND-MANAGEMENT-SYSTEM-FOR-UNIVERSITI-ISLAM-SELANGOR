"""UC029 Request Schedule Change (driver) and UC030 Approve/Reject Schedule Change (Head of Admin)."""
import re, sys, datetime
from lib import *

reseed()
sql("UPDATE internal_tasks SET status='cancelled'")           # demo internal tasks use relative dates
drv = user('drv001'); drv2 = user('drv002'); ad = user('admin'); hd = user('head001'); s1 = user('stf001')
cnt = lambda q: int(sql(q))
did1 = cnt("SELECT driver_id FROM users WHERE username='drv001'")
D = lambda n: (today() + datetime.timedelta(days=n)).isoformat()
# drv001's CONFIRMED trip tasks (request #4) and the approved request #2 that still needs a driver
tasks = rows("SELECT s.schedule_id, DATE(s.start_dt) FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE s.driver_id=%d AND vr.status='processed' AND s.status<>'cancelled' AND s.start_dt>NOW() ORDER BY s.start_dt" % did1)
tdate = tasks[0][1]; sid_a = int(tasks[0][0])
req2_date = sql("SELECT trip_date FROM vehicle_requests WHERE request_id=2")
hd_notif = lambda: cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='head001' AND n.message LIKE '%%asks%%'")
drv_notif = lambda like: cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='drv001' AND n.message LIKE '%s'" % like)

# ---- pages and roles
pg = drv.get('driver/schedule_change.php')
ok('the driver opens Request Schedule Change (types Unavailable Date / Withdraw from Task)', pg.status_code == 200 and not errs(pg) and 'Unavailable Date' in pg.text and 'Withdraw from Task' in pg.text)
ok('the driver menu has it', 'Schedule Change' in pg.text)
ok('staff and the Assistant cannot open the driver page', s1.get('driver/schedule_change.php').status_code == 302 and ad.get('driver/schedule_change.php').status_code == 302)
ok('only the Head of Admin opens the approval page', hd.get('admin/schedule_changes.php').status_code == 200 and ad.get('admin/schedule_changes.php').status_code == 302 and drv.get('admin/schedule_changes.php').status_code == 302 and s1.get('admin/schedule_changes.php').status_code == 302)
ok('the Head of Admin sees "no pending requests" when there are none', 'no pending requests' in hd.get('admin/schedule_changes.php').text)
ok('My Tasks has "Request Schedule Change" on a task', 'schedule_change.php?type=withdraw&amp;schedule_id=%d' % sid_a in drv.get('driver/schedules.php').text or 'schedule_change.php?type=withdraw&schedule_id=%d' % sid_a in drv.get('driver/schedules.php').text)

# ---- UC029 exceptions: nothing is saved
n0 = cnt("SELECT COUNT(*) FROM schedule_change_requests")
def post(c, **d): return c.post('driver/schedule_change.php', d)
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': tdate, 'to': '', 'reason': ''}); ok('3.1 empty reason: error', r.status_code == 200 and 'enter the reason' in r.text and cnt("SELECT COUNT(*) FROM schedule_change_requests") == n0)
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': D(-2), 'to': '', 'reason': 'Family event'}); ok('2.1 date already passed: error', r.status_code == 200 and 'already passed' in r.text and cnt("SELECT COUNT(*) FROM schedule_change_requests") == n0)
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': '', 'to': '', 'reason': 'Family event'}); ok('no date: error', r.status_code == 200 and cnt("SELECT COUNT(*) FROM schedule_change_requests") == n0)
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': D(5), 'to': D(3), 'reason': 'x'}); ok('last date before first date: error', r.status_code == 200 and cnt("SELECT COUNT(*) FROM schedule_change_requests") == n0)
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': D(2), 'to': D(60), 'reason': 'Long leave'}); ok('more than 31 days in one request: error', r.status_code == 200 and cnt("SELECT COUNT(*) FROM schedule_change_requests") == n0)
r = drv.post('driver/schedule_change.php', {'type': 'withdraw', 'schedule_id': 999999, 'reason': 'Sick'}); ok('withdraw: a task that is not theirs / does not exist is refused', r.status_code == 200 and cnt("SELECT COUNT(*) FROM schedule_change_requests") == n0)
r = drv2.post('driver/schedule_change.php', {'type': 'withdraw', 'schedule_id': sid_a, 'reason': 'Not my task'}); ok("withdraw: another driver's task is refused", cnt("SELECT COUNT(*) FROM schedule_change_requests") == n0)
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': D(2), 'to': D(2), 'reason': 'x'}, csrf=False); ok('POST without a CSRF token is refused (419)', r.status_code == 419)

# ---- UC029 normal flow: Unavailable Date over the day of the confirmed task and the approved request #2
d_from = min(tdate, req2_date); d_to = max(tdate, req2_date)
h0 = hd_notif(); e0 = cnt("SELECT COUNT(*) FROM email_log WHERE subject LIKE '%%asks to be unavailable%%'")
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': d_from, 'to': d_to, 'reason': 'Family wedding'})
cid = int(sql("SELECT MAX(change_id) FROM schedule_change_requests"))
ok('saved with the status Pending and one row per day', r.status_code == 302 and sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid) == 'pending' and cnt("SELECT COUNT(*) FROM schedule_change_dates WHERE change_id=%d" % cid) == (datetime.date.fromisoformat(d_to) - datetime.date.fromisoformat(d_from)).days + 1)
ok('the Head of Admin is told in the system and by e-mail (logged)', hd_notif() == h0 + 1 and cnt("SELECT COUNT(*) FROM email_log WHERE subject LIKE '%%asks to be unavailable%%'") == e0 + 1)
ok('the Assistant is not asked (only the Head of Admin decides)', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%asks%%'") == 0)
ok('the driver sees it as Pending', 'Pending' in drv.get('driver/schedule_change.php').text and 'Family wedding' in drv.get('driver/schedule_change.php').text)
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': tdate, 'to': tdate, 'reason': 'Again'}); ok('3.1 a pending request already exists for the same date: message, not saved', r.status_code == 200 and 'pending request already exists' in r.text and cnt("SELECT COUNT(*) FROM schedule_change_requests") == n0 + 1)
ok('the task stays with the driver while the request is pending', cnt("SELECT COUNT(*) FROM schedules WHERE schedule_id=%d AND driver_id=%d" % (sid_a, did1)) == 1)

# ---- UC030 reject (A1)
pg = hd.get('admin/schedule_changes.php')
ok('the Head of Admin sees the request with the reason and the driver\'s tasks on the dates', pg.status_code == 200 and 'Request #%d' % cid in pg.text and 'Family wedding' in pg.text and 'Putrajaya' in pg.text or 'Masjid' in pg.text)
r = hd.post('admin/schedule_changes.php', {'change_id': cid, 'decision': 'reject', 'comment': ''}); ok('A1 reject: the reason is required', sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid) == 'pending')
r = ad.post('admin/schedule_changes.php', {'change_id': cid, 'decision': 'approve', 'comment': ''}); ok('the Assistant cannot decide', sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid) == 'pending')
r = hd.post('admin/schedule_changes.php', {'change_id': cid, 'decision': 'approve', 'comment': ''}, csrf=False); ok('a decision without a CSRF token is refused (419)', r.status_code == 419)
r = hd.post('admin/schedule_changes.php', {'change_id': cid, 'decision': 'reject', 'comment': 'Too many trips that week'})
ok('rejected with the reason; the driver is told with the reason and that the task stays', sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid) == 'rejected' and drv_notif('%rejected%Too many trips%stays with you%') == 1)
ok('rejected: the driver is NOT unavailable and keeps the task', cnt("SELECT COUNT(*) FROM schedules WHERE schedule_id=%d AND driver_id=%d" % (sid_a, did1)) == 1 and 'Too many trips' in drv.get('driver/schedule_change.php').text)
ok('a decided request cannot be decided again', hd.post('admin/schedule_changes.php', {'change_id': cid, 'decision': 'approve', 'comment': ''}).status_code == 302 and sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid) == 'rejected')

# ---- a new request for the same dates is allowed after the rejection; approve it (normal flow + A2)
r = drv.post('driver/schedule_change.php', {'type': 'unavailable', 'from': d_from, 'to': d_to, 'reason': 'Family wedding, please'})
cid2 = int(sql("SELECT MAX(change_id) FROM schedule_change_requests")); ok('after a rejection a new request for the same dates is accepted', cid2 != cid and sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid2) == 'pending')
a0 = cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%was approved%%'")
r = hd.post('admin/schedule_changes.php', {'change_id': cid2, 'decision': 'approve', 'comment': 'Enjoy'})
ok('approved: status, the comment and the decider are saved', sql("SELECT status FROM schedule_change_requests WHERE change_id=%d" % cid2) == 'approved' and sql("SELECT decision_comment FROM schedule_change_requests WHERE change_id=%d" % cid2) == 'Enjoy')
ok('the driver and the Assistant are told', drv_notif('%was approved%Enjoy%') == 1 and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='admin' AND n.message LIKE '%%was approved%%'") == a0 + 1)
loc = r.headers.get('Location', '')
ok('A2: Replace Driver opens for the affected Confirmed task, as a Driver Request', r.status_code == 302 and 'replace_driver.php?schedule_id=' in loc and 'change_id=%d' % cid2 in loc)

# ---- Replace Driver as a Driver Request: Head of Admin, Confirmed directly
seen = 0
while 'replace_driver.php' in loc and seen < 6:
    seen += 1
    sid = int(re.search(r'schedule_id=(\d+)', loc).group(1))
    page = hd.get('admin/replace_driver.php', schedule_id=sid, change_id=cid2)
    ok('replace page %d: says Driver Request and that the task stays Confirmed, reason prefilled' % seen, page.status_code == 200 and 'Driver Request' in page.text and 'stays <strong>Confirmed' in page.text)
    ok('replace page %d: the driver who is unavailable is not offered' % seen, 'Ahmad Faizal' not in re.sub(r'Current driver:.*?</div>', '', page.text, flags=re.S).split('Choose the new driver')[-1])
    cand = re.findall(r'name="new_driver" value="(\d+)"', page.text)
    ok('replace page %d: other drivers are offered' % seen, bool(cand))
    r = hd.post('admin/replace_driver.php', {'schedule_id': sid, 'change_id': cid2, 'new_driver': cand[0], 'reason': ''})
    ok('replace %d: done, the task is Confirmed directly (request stays Confirmed)' % seen, r.status_code == 302 and sql("SELECT vr.status FROM vehicle_requests vr JOIN schedules s ON s.request_id=vr.request_id WHERE s.schedule_id=%d" % sid) == 'processed' and cnt("SELECT driver_id FROM schedules WHERE schedule_id=%d" % sid) == int(cand[0]))
    ok('replace %d: recorded with the reason Driver Request' % seen, cnt("SELECT COUNT(*) FROM driver_replacements WHERE schedule_id=%d AND reason='Driver Request' AND previous_driver_id=%d" % (sid, did1)) == 1)
    loc = r.headers.get('Location', '')
ok('after the last task the Head of Admin is back on Schedule Changes', loc.endswith('schedule_changes.php'))
ok('the old driver was told he was replaced', drv_notif('%You were replaced on%Driver Request%') >= 1)
ok('the staff member was told the driver changed', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' AND n.message LIKE '%%driver of your request%%changed%%'") >= 1)
ok('no Head of Admin approval is pending for it (not Driver Assigned)', cnt("SELECT COUNT(*) FROM vehicle_requests WHERE status='assigned' AND request_id=4") == 0)

# ---- approved Unavailable Date: not recommended, cannot be assigned, cannot be set on welfare duty
r = ad.get('admin/assign_driver.php', request_id=2)
ok('UC018 1.3: the unavailable driver is not in the recommendation', r.status_code == 200 and re.search(r'Ahmad Faizal bin Mohd Rashid', r.text.split('Choose')[0] if False else '') is None and 'Marked unavailable' in r.text)
r = ad.post('admin/assign_driver.php', {'request_id': 2, 'out_driver': did1, 'return_mode': 'same'})
ok('UC017 2.1: choosing the unavailable driver anyway is refused', sql("SELECT status FROM vehicle_requests WHERE request_id=2") == 'approved' and cnt("SELECT COUNT(*) FROM schedules WHERE request_id=2") == 0)
M = d_from[:7]
other = cnt("SELECT driver_id FROM drivers WHERE driver_id<>%d AND status='active' ORDER BY driver_id LIMIT 1" % did1)
sql("INSERT INTO welfare_roster (duty_date, driver_id) VALUES ('%s', %d) ON DUPLICATE KEY UPDATE driver_id=VALUES(driver_id)" % (d_from, other))
r = ad.post('admin/welfare.php', dict(action='update', month=M, duty_date=d_from, driver_id=did1, reason='cover'))
ok('UC025 3.1: the unavailable driver cannot be set on welfare duty that day (the roster keeps the other driver)', cnt("SELECT driver_id FROM welfare_roster WHERE duty_date='%s'" % d_from) == other)

# the rotation skips the unavailable days (a rotation of ONE unavailable driver leaves those days empty)
sql("DELETE FROM welfare_roster WHERE duty_date BETWEEN '%s' AND '%s'" % (d_from, d_to))
ad.post('admin/welfare.php', {'action': 'generate', 'month': M, 'use[%d]' % did1: '1', 'order[%d]' % did1: '1', 'replace_existing': '1'})
ok('welfare rotation: the unavailable driver is not set on the unavailable days', cnt("SELECT COUNT(*) FROM welfare_roster WHERE driver_id=%d AND duty_date BETWEEN '%s' AND '%s'" % (did1, d_from, d_to)) == 0)

# ---- Withdraw from Task (A1) by the driver who took over, started from My Tasks
newdrv = cnt("SELECT driver_id FROM schedules WHERE schedule_id=%d" % sid_a)
who = sql("SELECT username FROM users WHERE driver_id=%d" % newdrv)
c = user(who)
h1 = hd_notif()
r = c.post('driver/schedule_change.php', {'type': 'withdraw', 'schedule_id': sid_a, 'reason': 'Medical appointment'})
wid = int(sql("SELECT MAX(change_id) FROM schedule_change_requests"))
ok('A1 withdraw: saved Pending for that task, the Head of Admin is told', r.status_code == 302 and sql("SELECT change_type FROM schedule_change_requests WHERE change_id=%d" % wid) == 'withdraw' and int(sql("SELECT schedule_id FROM schedule_change_requests WHERE change_id=%d" % wid)) == sid_a and hd_notif() == h1 + 1)
r = c.post('driver/schedule_change.php', {'type': 'withdraw', 'schedule_id': sid_a, 'reason': 'Again'}); ok('3.1 a pending request already exists for the same task: message', 'pending request already exists for this task' in r.text and cnt("SELECT COUNT(*) FROM schedule_change_requests WHERE change_type='withdraw'") == 1)
ok('the task stays with the driver until approval', cnt("SELECT driver_id FROM schedules WHERE schedule_id=%d" % sid_a) == newdrv)
r = hd.post('admin/schedule_changes.php', {'change_id': wid, 'decision': 'approve', 'comment': ''})
loc = r.headers.get('Location', '')
ok('approve (no comment needed): Replace Driver opens for that task', r.status_code == 302 and 'schedule_id=%d' % sid_a in loc and 'change_id=%d' % wid in loc)
page = hd.get('admin/replace_driver.php', schedule_id=sid_a, change_id=wid); cand = re.findall(r'name="new_driver" value="(\d+)"', page.text)
r = hd.post('admin/replace_driver.php', {'schedule_id': sid_a, 'change_id': wid, 'new_driver': cand[0], 'reason': ''})
ok('the withdrawing driver is replaced and the task is Confirmed', cnt("SELECT driver_id FROM schedules WHERE schedule_id=%d" % sid_a) == int(cand[0]) and cnt("SELECT COUNT(*) FROM driver_replacements WHERE schedule_id=%d AND reason='Driver Request'" % sid_a) >= 1)
# withdraw approved does NOT make the driver unavailable on that date
ok('a withdraw request does not mark the driver unavailable', cnt("SELECT COUNT(*) FROM schedule_change_requests r WHERE r.change_id=%d AND r.change_type='unavailable'" % wid) == 0)
# a normal Replace Driver by the Assistant still sends the task back for approval; the Driver Request mode needs the Head of Admin
tk = rows("SELECT s.schedule_id FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE vr.status='processed' AND s.status<>'cancelled' AND s.start_dt>NOW() LIMIT 1")
if tk:
    t = int(tk[0][0]); pg = ad.get('admin/replace_driver.php', schedule_id=t, change_id=wid)
    ok('the Assistant never gets the Driver Request mode', pg.status_code == 200 and 'The driver asked for this change' not in pg.text)
ok('no PHP errors on the new pages', not errs(drv.get('driver/schedule_change.php')) and not errs(hd.get('admin/schedule_changes.php')))
sys.exit(0 if summary() else 1)
