"""HTTP test helpers for the SRS-aligned DSMS.
Environment (so several people can test in parallel without touching each other's data):
  UIST_BASE  base url of the php server        default http://127.0.0.1:8095
  UIST_DB    database name                      default uis_driver_db
  UIST_ROOT  project folder
Start a private server+database:  see tests in the plan doc (php -S 127.0.0.1:PORT -t ROOT with env DSMS_DB=NAME)
"""
import requests, re, subprocess, os, datetime, zoneinfo, glob
B = os.environ.get('UIST_BASE', 'http://127.0.0.1:8095')
DB = os.environ.get('UIST_DB', 'uis_driver_db')
ROOT = os.environ.get('UIST_ROOT') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
def sql(q):
    r = subprocess.run(['mysql', '-uroot', "--init-command=SET time_zone='+08:00'", DB, '-N', '-e', q], capture_output=True, text=True)
    return (r.stdout + r.stderr).strip().replace('\n', ' | ')
def rows(q):
    r = subprocess.run(['mysql', '-uroot', "--init-command=SET time_zone='+08:00'", DB, '-N', '-e', q], capture_output=True, text=True)
    return [l.split('\t') for l in r.stdout.strip().split('\n') if l != '']
def reseed():
    src = open(ROOT + '/database.sql').read().replace('uis_driver_db', DB)
    subprocess.run(['mysql', '-uroot'], input=src, text=True, check=True)
    for f in glob.glob('/tmp/uis_jobs_last_run_*'):
        os.remove(f)
class C:
    def __init__(s):
        s.s = requests.Session(); s.tok = None
    def get(s, path, **p): return s.s.get(B + '/' + path, params=p, allow_redirects=False)
    def token(s):
        if not s.tok:
            m = re.search(r'name="csrf_token" value="([0-9a-f]+)"', s.s.get(B + '/login.php', allow_redirects=False).text)
            s.tok = m.group(1) if m else ''
        return s.tok
    def post(s, path, data=None, files=None, csrf=True, **p):
        h = {'X-CSRF-Token': s.token()} if csrf else {}
        return s.s.post(B + '/' + path, params=p, data=data or {}, files=files, headers=h, allow_redirects=False)
    def login(s, u, pw):
        return s.s.post(B + '/login.php', data={'username': u, 'password': pw, 'csrf_token': s.token()}, allow_redirects=False)
PW = {'admin': 'admin123', 'head001': 'head001@uis'}
for i in range(1, 6): PW['hos%03d' % i] = 'hos%03d@uis' % i
for i in range(1, 4): PW['stf%03d' % i] = 'staff%03d@uis' % i
for i in range(1, 13): PW['drv%03d' % i] = 'driver%03d@uis' % i
def user(u):
    c = C(); r = c.login(u, PW[u]); assert r.status_code == 302, (u, r.status_code); return c
def errs(r): return bool(re.search(r'Warning:|Notice:|Fatal error|Parse error|Undefined|Deprecated:', r.text))
def run_jobs():
    env = dict(os.environ, DSMS_DB=DB)
    return subprocess.run(['php', ROOT + '/cron/run_jobs.php'], capture_output=True, text=True, env=env)
TZ = zoneinfo.ZoneInfo('Asia/Kuala_Lumpur')
def now(): return datetime.datetime.now(TZ).replace(tzinfo=None)
def today(): return now().date()
def D(n): return (today() + datetime.timedelta(days=n)).isoformat()
_n = [0, 0]
def ok(msg, cond):
    _n[0 if cond else 1] += 1; print(('PASS ' if cond else 'FAIL ') + msg)
def summary(): print(_n); return _n[1] == 0
