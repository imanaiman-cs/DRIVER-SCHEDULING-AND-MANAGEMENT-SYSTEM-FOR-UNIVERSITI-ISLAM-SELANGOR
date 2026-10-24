"""Dashboards: every role opens its own dashboard (fast, no PHP errors), shows the right numbers,
and nobody sees what is not theirs."""
import time, re, datetime
from lib import *

reseed()

def timed(c, path):
    t = time.time(); r = c.get(path); return r, time.time() - t

pages = {'admin': ('admin', 'admin/dashboard.php'), 'head001': ('head001', 'admin/dashboard.php'),
         'hos002': ('hos002', 'supervisor/dashboard.php'), 'stf001': ('stf001', 'staff/dashboard.php'),
         'drv001': ('drv001', 'driver/dashboard.php')}
S = {}
for k, (u, p) in pages.items():
    S[k] = user(u)
    r, dt = timed(S[k], p)
    ok('%s opens %s (200, no PHP errors, %.2f s)' % (u, p, dt), r.status_code == 200 and not errs(r) and dt < 3)
    ok('%s: dashboard has the hero and KPI cards' % u, 'dash-hero' in r.text and 'dash-kpi' in r.text)

# admin: charts, queues, alerts, fleet
r = S['admin'].get('admin/dashboard.php')
for t in ['chStatus', 'chMonthly', 'chDrivers', 'chVehicles', 'Needs your action', 'Alerts', 'Fleet now', 'Welfare duty today']:
    ok('admin dashboard shows ' + t, t in r.text)
ok('admin: Head of Admin has the approve link, the Assistant does not',
   'approve_assignments.php' in S['head001'].get('admin/dashboard.php').text and 'approve_assignments.php' not in r.text)
n_approved = int(rows("SELECT COUNT(*) FROM vehicle_requests WHERE status='approved'")[0][0])
m = re.search(r'dash-kpi-value">(\d+)[^<]*(?:<span[^>]*>.*?</span>)?\s*</div><div class="dash-kpi-label">Need a driver', r.text, re.S)
ok('admin: "Need a driver" equals the database (%d)' % n_approved, bool(m) and int(m.group(1)) == n_approved)

# a late-night problem: a request leaving within 24 h and still not Confirmed raises an alert
dep = now() + datetime.timedelta(hours=5); ret = dep + datetime.timedelta(hours=1)
sql("UPDATE vehicle_requests SET trip_date='%s', start_time='%s', return_date='%s', end_time='%s' WHERE status='approved' LIMIT 1"
    % (dep.date(), dep.strftime('%H:%M:%S'), ret.date(), ret.strftime('%H:%M:%S')))
r = S['admin'].get('admin/dashboard.php')
soon = rows("SELECT COUNT(*) FROM vehicle_requests WHERE status IN ('pending','approved','assigned') AND depart_at > NOW() AND depart_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)")[0][0]
ok('admin: alert for a trip leaving within 24 hours that is not Confirmed (%s in the data)' % soon, int(soon) >= 1 and 'leaves within 24 hours' in r.text)

# staff: only their own requests, driver and vehicle only after Confirmed
r = S['stf001'].get('staff/dashboard.php')
ok('staff does not see another staff member', 'Muhammad Syafiq' not in r.text and 'Siti Aishah' not in r.text)
drivers = [x[0] for x in rows("SELECT name FROM drivers")]
mine = rows("SELECT status FROM vehicle_requests WHERE staff_id=(SELECT user_id FROM users WHERE username='stf001') AND status IN ('pending','approved','assigned') AND return_at >= NOW()")
if mine and not rows("SELECT 1 FROM vehicle_requests WHERE staff_id=(SELECT user_id FROM users WHERE username='stf001') AND status='processed' AND return_at >= NOW()"):
    ok('staff: no driver name before Confirmed', not any(n in r.text for n in drivers))

# Head of Department: only their own PTJ in the "To approve" list
r = S['hos002'].get('supervisor/dashboard.php')
ids = [str(x[0]) for x in rows("SELECT vr.request_id FROM vehicle_requests vr JOIN users u ON u.user_id=vr.staff_id WHERE vr.status='pending' AND NOT (vr.supervisor_id=(SELECT user_id FROM users WHERE username='hos002') AND (u.role='supervisor' OR u.department <=> (SELECT department FROM users WHERE username='hos002')))")]
shown = set(re.findall(r'<strong>#(\d+)</strong>', r.text.split('My own requests')[0]))
ok('HoD: "To approve" lists only requests of their own PTJ', not (shown & set(ids)))
ok('HoD cannot open the admin dashboard', S['hos002'].get('admin/dashboard.php').url.endswith('supervisor/dashboard.php') or 'dash-hero' not in S['hos002'].get('admin/dashboard.php').text)

# driver: only Confirmed tasks
r = S['drv001'].get('driver/dashboard.php')
ok('driver dashboard is view only (no form, no button to change)', '<form' not in r.text.split('<main')[1])
shown_dest = set(re.findall(r'</span>([^<]+)</span>\s*<span class="text-muted">', r.text))
hidden = [x[0] for x in rows("SELECT vr.destination FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id JOIN drivers d ON d.driver_id=s.driver_id JOIN users u ON u.driver_id=d.driver_id WHERE u.username='drv001' AND vr.status NOT IN ('processed','completed')")]
ok('driver sees no task of a request that is not Confirmed', not any(h and h in r.text for h in hidden))

# non-admin roles cannot reach the others' dashboards
ok('staff cannot open the admin dashboard', 'dash-hero' not in S['stf001'].get('admin/dashboard.php').text)
ok('driver cannot open the staff-only supervisor dashboard', 'dash-hero' not in S['drv001'].get('supervisor/dashboard.php').text)

# landing page after login
for u, expect in [('hos002', 'supervisor/dashboard.php'), ('drv001', 'driver/dashboard.php'), ('stf001', 'staff/dashboard.php'), ('admin', 'admin/dashboard.php')]:
    c = C(); resp = c.login(u, PW[u])
    ok('%s lands on %s after login' % (u, expect), resp.headers.get('Location', '').endswith(expect))

import sys
sys.exit(0 if summary() else 1)
