"""English / Bahasa Melayu switch (outside SRS v1.1): cookie + users.language, pages, JSON answers, forms, e-mails."""
import re, sys, json, subprocess, os, urllib.parse
from lib import *

reseed()
text = lambda r: re.sub(r'\s+', ' ', re.sub(r'<script.*?</script>|<style.*?</style>|<[^>]+>', ' ', r.text, flags=re.S))
lang = lambda u: sql("SELECT language FROM users WHERE username='%s'" % u)

# default: English, switch on the page
ad = user('admin')
pg = ad.get('admin/dashboard.php')
ok('default language is English: html lang=en, English menu, EN | BM switch shown', '<html lang="en"' in pg.text and 'Vehicle Requests' in text(pg) and 'set_language.php?lang=ms' in pg.text and lang('admin') == 'en')

# switch to Bahasa Melayu (the switch link carries the address of the current page)
link = re.search(r'href="[^"]*set_language\.php\?lang=ms&amp;back=([^"]*)"', pg.text)
back = urllib.parse.unquote(link.group(1))
r = ad.s.get(B + '/set_language.php', params={'lang': 'ms', 'back': back}, allow_redirects=False)
ok('set_language redirects back to the page and sets the cookie', r.status_code == 302 and r.headers['Location'] == back and back.endswith('/admin/dashboard.php') and 'dsms_lang=ms' in r.headers.get('Set-Cookie', ''))
ok('the choice is saved on the account', lang('admin') == 'ms')
pg = ad.get('admin/dashboard.php'); t = text(pg)
ok('dashboard in Bahasa Melayu: html lang=ms, menu, greeting, buttons', '<html lang="ms"' in pg.text and 'Papan pemuka' in t and 'Permohonan Kenderaan' in t and 'Log Keluar' in t and not errs(pg))
ok('the menu no longer shows the English labels', 'Vehicle Requests' not in t and 'Internal Tasks' not in t)
ok('dates use Malay day and month names', re.search(r'(Isnin|Selasa|Rabu|Khamis|Jumaat|Sabtu|Ahad), \d+ (Januari|Februari|Mac|April|Mei|Jun|Julai|Ogos|September|Oktober|November|Disember) \d{4}', t) is not None)
for p in ['admin/vehicle_requests.php?tab=all', 'admin/drivers.php', 'admin/vehicles.php', 'admin/internal_tasks.php', 'admin/welfare.php', 'admin/calendar.php', 'admin/reports.php', 'admin/settings.php', 'help.php', 'notifications.php', 'admin/add_driver.php', 'admin/record_paper_form.php', 'staff/request_vehicle.php']:
    q = ad.get(p)
    ok('%s opens in Bahasa Melayu without PHP errors' % p, q.status_code == 200 and not errs(q) and '<html lang="ms"' in q.text)
st = text(ad.get('admin/settings.php'))
ok('settings: labels and descriptions translated', 'Tetapan' in st and 'Pemberat' in st or 'pemberat' in st.lower())
ok('status words are translated in the request list', any(w in text(ad.get('admin/vehicle_requests.php', tab='all')) for w in ('Menunggu', 'Diluluskan', 'Disahkan')))

# forms keep working: attributes and values are not translated, CSRF intact
f = ad.get('admin/add_vehicle.php').text
ok('form field names, values and the CSRF token are untouched', 'name="plate_number"' in f and 'name="csrf_token"' in f and 'value="Car"' in f)
r = ad.post('admin/internal_tasks.php', {'action': 'delete', 'task_id': 999999, 'reason': 'abc'})
ok('POST with CSRF still works in Bahasa Melayu', r.status_code == 302)
r = ad.post('admin/internal_tasks.php', {'action': 'delete', 'task_id': 1, 'reason': 'abc'}, csrf=False)
ok('POST without CSRF is still refused (419)', r.status_code == 419)

# JSON answers
r = ad.post('ajax/request_action.php', {'request_id': 1, 'action': 'zzz'})
j = json.loads(r.text)
ok('AJAX answers are translated', r.headers['Content-Type'].startswith('application/json') and not j.get('success') and 'Unknown action' not in r.text and j.get('message'))

# downloads stay untouched (PDF/Excel are not translated)
pdf = ad.get('admin/reports.php', type='tasks', month=D(0)[:7], export='pdf')
ok('PDF export is still a valid PDF', pdf.content[:5] == b'%PDF-')

# invalid language is ignored, open redirect is refused
ad.s.get(B + '/set_language.php', params={'lang': 'fr', 'back': back}, allow_redirects=False)
ok('an unknown language falls back to English', lang('admin') == 'en')
for evil in ['https://evil.example/', '//evil.example/', '/\\evil.example']:
    r = ad.s.get(B + '/set_language.php', params={'lang': 'ms', 'back': evil}, allow_redirects=False)
    ok('redirect target %s is refused' % evil, r.status_code == 302 and 'evil' not in r.headers['Location'])
ad.s.get(B + '/set_language.php', params={'lang': 'en', 'back': back}, allow_redirects=False)

# login page: choice made before login is kept; a new browser gets the language of the account
c = C()
lp = c.s.get(B + '/login.php')
ok('login page has the switch', 'set_language.php?lang=ms' in lp.text)
c.s.get(B + '/set_language.php', params={'lang': 'ms', 'back': back.replace('admin/dashboard.php', 'login.php')}, allow_redirects=False)
ok('login page in Bahasa Melayu', 'Log masuk' in text(c.s.get(B + '/login.php')) or 'Kata laluan' in text(c.s.get(B + '/login.php')))
c.login('stf001', PW['stf001'])
ok('chosen on the login page: saved on the account and used after login', lang('stf001') == 'ms' and 'Papan pemuka' in text(c.get('staff/dashboard.php')) or 'Permohonan' in text(c.get('staff/dashboard.php')))
n = C(); n.login('stf001', PW['stf001'])
ok('a new browser without cookie gets the language of the account', '<html lang="ms"' in n.get('staff/dashboard.php').text)
sql("UPDATE users SET language='ms' WHERE username='drv001'")
d = user('drv001')
ok('the driver portal follows users.language', '<html lang="ms"' in d.get('driver/dashboard.php').text and not errs(d.get('driver/dashboard.php')))

# notifications: stored in English, shown translated; e-mail in the language of the receiver
sql("UPDATE users SET language='en' WHERE username='stf002'")
sql("DELETE FROM email_log")
php = lambda code: subprocess.run(['php', '-r', 'define("SKIP_REQUEST_JOBS",1); require "%s/config/database.php"; %s' % (ROOT, code)], capture_output=True, text=True, env=dict(os.environ, DSMS_DB=DB)).stdout
php('$a = (int)$conn->query("SELECT user_id FROM users WHERE username=\'stf001\'")->fetch_row()[0]; $b = (int)$conn->query("SELECT user_id FROM users WHERE username=\'stf002\'")->fetch_row()[0]; notifyUsers($conn, [$a, $b], "Your request #5 was approved by your Head of Department.", null);')
subs = rows("SELECT u.username, e.subject FROM email_log e JOIN users u ON u.user_id=e.user_id ORDER BY u.username") if rows("SHOW COLUMNS FROM email_log LIKE 'user_id'") else []
msg = sql("SELECT message FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='stf001' ORDER BY notification_id DESC LIMIT 1")
ok('the in-system notification is stored in English', msg == 'Your request #5 was approved by your Head of Department.')
ok('e-mail subject: Bahasa Melayu for a user with ms, English for en', subs and any(u == 'stf001' and 'diluluskan' in s for u, s in subs) and any(u == 'stf002' and 'was approved' in s for u, s in subs))
nl = text(n.get('notifications.php'))
ok('the notification list shows it in Bahasa Melayu', 'diluluskan' in nl and 'Ketua Jabatan' in nl)
import subprocess, os
sys.exit(0 if summary() else 1)
