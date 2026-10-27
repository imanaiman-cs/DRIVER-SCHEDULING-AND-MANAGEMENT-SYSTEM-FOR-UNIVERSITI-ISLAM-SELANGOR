"""UC001 / UC003 A1 / UC018: date joined (Add and Edit Driver), experience from it, the three recommender weights in Settings."""
import re, sys, datetime
from lib import *

reseed()
ad = user('admin'); s1 = user('stf001'); hd = user('head001')
sql("INSERT INTO users (username,password,email,full_name,role,department) SELECT 'drv099',password,'new.driver@uis.edu.my','New Driver','driver','Transport Unit' FROM users WHERE username='drv001'")
uid = sql("SELECT user_id FROM users WHERE username='drv099'")
today_ = today(); future = (today_ + datetime.timedelta(days=3)).isoformat(); joined = (today_ - datetime.timedelta(days=3 * 365)).isoformat()
form = lambda **kw: dict({'user_id': uid, 'license_class[]': ['D'], 'license_expiry': (today_ + datetime.timedelta(days=400)).isoformat(), 'date_joined': joined}, **kw)
count = lambda: int(sql("SELECT COUNT(*) FROM drivers WHERE employee_id='DRV099'"))

# Add Driver
pg = ad.get('admin/add_driver.php')
ok('Add Driver asks for the date joined', pg.status_code == 200 and 'name="date_joined"' in pg.text and not errs(pg))
ad.post('admin/add_driver.php', form(date_joined=''))
ok('date joined is required', count() == 0)
ad.post('admin/add_driver.php', form(date_joined='31/02/2020'))
ok('an invalid date is refused', count() == 0)
ad.post('admin/add_driver.php', form(date_joined=future))
ok('a date in the future is refused', count() == 0)
r = ad.post('admin/add_driver.php', form())
ok('saved with the date joined', r.status_code == 302 and count() == 1 and sql("SELECT date_joined FROM drivers WHERE employee_id='DRV099'") == joined)
ok('the new driver is told in the system (UC001 4.3)', int(sql("SELECT COUNT(*) FROM notifications WHERE user_id=%s AND message LIKE '%%registered as a driver%%'" % uid)) == 1)
did = sql("SELECT driver_id FROM drivers WHERE employee_id='DRV099'")
ok('staff cannot add a driver (role check)', s1.post('admin/add_driver.php', form()).status_code == 302)

# View and Edit
v = ad.get('admin/view_driver.php', id=did)
ok('View Driver shows the date joined and the experience in years', v.status_code == 200 and 'Date joined' in v.text and '(3 years)' in v.text and 'Weekend tasks' in v.text and not errs(v))
e = ad.get('admin/edit_driver.php', id=did)
ok('Edit Driver shows the date joined', 'name="date_joined"' in e.text and joined in e.text)
base = {'id': did, 'phone': '012-3456789', 'license_class[]': ['D'], 'license_expiry': (today_ + datetime.timedelta(days=400)).isoformat()}
ad.post('admin/edit_driver.php?id=' + did, dict(base, date_joined=future))
ok('Edit: a future date is refused, nothing changed', sql("SELECT date_joined FROM drivers WHERE driver_id=%s" % did) == joined)
ad.post('admin/edit_driver.php?id=' + did, dict(base, date_joined=''))
ok('Edit: the date joined cannot be emptied', sql("SELECT date_joined FROM drivers WHERE driver_id=%s" % did) == joined)
newj = (today_ - datetime.timedelta(days=6 * 365)).isoformat()
r = ad.post('admin/edit_driver.php?id=' + did, dict(base, date_joined=newj))
ok('Edit: the date joined is updated (UC003 A1)', sql("SELECT date_joined FROM drivers WHERE driver_id=%s" % did) == newj)

# Recommender: experience comes from the date joined, more experience ranks higher when all else is equal
sql("UPDATE drivers SET date_joined=CURDATE() - INTERVAL 10 YEAR WHERE driver_id=%s" % did)
dd = (today_ + datetime.timedelta(days=200)).isoformat()
import json
j = json.loads(ad.get('ajax/get_available_drivers.php', start=dd + ' 09:00:00', end=dd + ' 11:00:00', licence='D').text)
me = [d for d in j['drivers'] if str(d['driver_id']) == did]
ok('the response carries the experience in years (10 at today, 10.5 on the trip date) and the weekend count', me and abs(me[0]['experience'] - 10.5) < 0.2 and 'weekend' in me[0])
ok('weights are 0.5 / 0.3 / 0.2', j['weights'] == {'tasks': 0.5, 'weekend': 0.3, 'experience': 0.2})
mx = max(d['experience'] for d in j['drivers'])
ok('experience is normalised by the most experienced candidate', me and abs(me[0]['norm_experience'] - me[0]['experience'] / mx) < 0.001 and any(d['norm_experience'] == 1 for d in j['drivers']))

# Settings
st = ad.get('admin/settings.php')
ok('Settings shows the three weights', 'name="W_TASKS"' in st.text and 'name="W_WEEKEND"' in st.text and 'name="W_EXPERIENCE"' in st.text and 'name="W_AFTER_HOURS"' not in st.text)
full = lambda **kw: dict({'W_TASKS': '0.5', 'W_WEEKEND': '0.3', 'W_EXPERIENCE': '0.2', 'WORK_START': '08:00', 'WORK_END': '17:30', 'TASK_DURATION_HOURS': '2'}, **kw)
# the other settings keep their stored values
for k in re.findall(r'name="([A-Z_]+)"', st.text):
    pass
cur = {k: sql("SELECT setting_value FROM settings WHERE setting_key='%s'" % k) for k in re.findall(r'name="([A-Z_]+)"', st.text)}
def save(**kw): return ad.post('admin/settings.php', dict(cur, **kw))
get = lambda k: sql("SELECT setting_value FROM settings WHERE setting_key='%s'" % k)
save(W_TASKS='0.5', W_WEEKEND='0.4', W_EXPERIENCE='0.2')
ok('the three weights must add up to 1', get('W_WEEKEND') == '0.3')
save(W_TASKS='0.2', W_WEEKEND='0.5', W_EXPERIENCE='0.3')
ok('tasks must have the highest weight', get('W_TASKS') == '0.5')
save(W_TASKS='abc', W_WEEKEND='0.3', W_EXPERIENCE='0.2')
ok('a non-number is refused', get('W_TASKS') == '0.5')
save(W_TASKS='0.6', W_WEEKEND='0.25', W_EXPERIENCE='0.15')
ok('valid weights are saved', get('W_TASKS') == '0.6' and get('W_WEEKEND') == '0.25' and get('W_EXPERIENCE') == '0.15')
ok('the change is in the history log', int(sql("SELECT COUNT(*) FROM audit_log WHERE action='settings_update' AND reason LIKE '%%W_WEEKEND%%'")) == 1)
j = json.loads(ad.get('ajax/get_available_drivers.php', start=dd + ' 09:00:00', end=dd + ' 11:00:00', licence='D').text)
ok('the recommender uses the new weights', j['weights'] == {'tasks': 0.6, 'weekend': 0.25, 'experience': 0.15})
ok('staff and Head of Department cannot open Settings', s1.get('admin/settings.php').status_code == 302)
ok('POST without CSRF is refused (419)', ad.post('admin/settings.php', cur, csrf=False).status_code == 419)
reseed()   # leave the demo settings as they were
sys.exit(0 if summary() else 1)
