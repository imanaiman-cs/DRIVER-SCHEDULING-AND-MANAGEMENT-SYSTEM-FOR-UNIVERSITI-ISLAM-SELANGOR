"""My Profile (settings-style page): every section opens for every role, contact details save, the password
changes here and on change_password.php, the licence section is only for drivers."""
from lib import *

reseed()

# every section opens without PHP errors
for u in ('admin', 'head001', 'hos002', 'stf001', 'drv001'):
    c = user(u)
    for s in ('profile', 'contact', 'licence', 'security', 'display'):
        r = c.get('profile.php?s=' + s)
        ok('%s: profile section %s opens' % (u, s), r.status_code == 200 and not errs(r) and 'id="pfTitle"' in r.text)

drv, st = user('drv001'), user('stf001')
r = drv.get('profile.php?s=licence'); ok('driver sees the Driving licence section', 'DRIVING LICENCE' in r.text and 'font-bold">Driving licence<' in r.text)
r = st.get('profile.php?s=licence')
ok('staff has no licence section (falls back to Profile)', 'DRIVING LICENCE' not in r.text and 'href="profile.php?s=licence"' not in r.text and 'font-bold">Profile<' in r.text)
ok('profile data is read only', '<input' not in st.get('profile.php?s=profile').text.split('id="pfTitle"')[1].split('</section>')[0])

# contact details
r = drv.post('profile.php?s=contact', {'phone': '013-111 2222', 'address': 'No. 5, Jalan Mawar, Shah Alam'})
ok('contact saves and returns to the contact section', r.status_code == 302 and 'profile.php?s=contact' in r.headers.get('Location', ''))
ok('phone and address stored for the driver', sql("SELECT CONCAT(u.phone,'|',d.phone,'|',d.address) FROM users u JOIN drivers d ON d.driver_id=u.driver_id WHERE u.username='drv001'") == '013-111 2222|013-111 2222|No. 5, Jalan Mawar, Shah Alam')
r = drv.post('profile.php?s=contact', {'phone': 'abc'})
ok('an invalid phone is refused on the contact section', r.status_code == 200 and 'Enter a valid phone number.' in r.text and 'font-bold">Contact<' in r.text)

# password, on the Security section
def pw(c, cur, new, conf=None):
    return c.post('profile.php?s=security', {'action': 'password', 'current_password': cur, 'new_password': new, 'confirm_password': conf if conf is not None else new})
r = pw(st, 'wrong', 'Abcdefg12'); ok('wrong current password refused', r.status_code == 200 and 'Your current password is not correct.' in r.text)
r = pw(st, 'staff001@uis', 'short1');  ok('a short password refused', 'at least 8 characters' in r.text)
r = pw(st, 'staff001@uis', 'abcdefghij'); ok('a password without a number refused', 'a letter and a number' in r.text)
r = pw(st, 'staff001@uis', 'Abcdefg12', 'Abcdefg13'); ok('a mismatched confirmation refused', 'do not match' in r.text)
r = pw(st, 'staff001@uis', 'Abcdefg12'); ok('a good password is changed', r.status_code == 302 and 'profile.php?s=security' in r.headers.get('Location', ''))
ok('the new password signs in', C().login('stf001', 'Abcdefg12').status_code == 302)
ok('the old password no longer signs in', C().login('stf001', 'staff001@uis').status_code != 302)
ok('the change is in the audit log', int(sql("SELECT COUNT(*) FROM audit_log WHERE action='password_changed'")) >= 1)
r = st.post('profile.php?s=security', {'action': 'password', 'current_password': 'Abcdefg12', 'new_password': 'Zyxwvut98', 'confirm_password': 'Zyxwvut98'}, csrf=False)
ok('the password form needs the CSRF token (419)', r.status_code == 419)

# change_password.php works too (it called a missing passwordProblem() before)
h = user('hos002')
r = h.post('change_password.php', {'current_password': 'hos002@uis', 'new_password': 'Newpass123', 'confirm_password': 'Newpass123'})
ok('change_password.php changes the password without a PHP error', r.status_code == 200 and not errs(r) and 'Your password has been changed' in r.text)
reseed()
print(summary())
