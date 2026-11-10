"""SRS summary 10 Nov 2026: staff register their own account; the Assistant / Head of Admin manage user accounts."""
import re, sys
from lib import *

reseed()
cnt = lambda q: int(sql(q))
anon = C()
ad = user('admin'); hd = user('head001'); s1 = user('stf001'); hos = user('hos002')
reg = dict(username='NEW001', full_name='Aminah binti Yusof', email='aminah@uis.edu.my', phone='012-3456789', department='Faculty of Education', password='Secret123', confirm_password='Secret123')
exists = lambda u: cnt("SELECT COUNT(*) FROM users WHERE username='%s'" % u)

# ---- self registration ----
pg = anon.get('register.php')
ok('the registration page opens without signing in and lists only PTJs with a Head of Department', pg.status_code == 200 and 'Faculty of Education' in pg.text and 'Rectory' in pg.text and not errs(pg))
ok('the login page links to it', 'register.php' in anon.get('login.php').text)
ok('POST without a CSRF token is refused (419)', anon.post('register.php', reg, csrf=False).status_code == 419 and not exists('NEW001'))
for name, bad in [('short password', dict(password='a1', confirm_password='a1')), ('no digits', dict(password='abcdefgh', confirm_password='abcdefgh')),
                  ('password mismatch', dict(confirm_password='Other1234')), ('bad e-mail', dict(email='nope')), ('empty name', dict(full_name='')),
                  ('bad staff ID', dict(username='a b')), ('PTJ not in the list', dict(department='Nowhere')), ('bad phone', dict(phone='abc')),
                  ('existing staff ID', dict(username='stf001')), ('existing e-mail', dict(email=sql("SELECT email FROM users WHERE username='stf001'")))]:
    r = anon.post('register.php', dict(reg, **bad))
    ok('registration refused: ' + name, r.status_code == 200 and not exists('NEW001') and 'invalid-feedback' in r.text)
r = anon.post('register.php', dict(reg, role='head_admin', is_active='1', supervisor_id='1'))
ok('registered: the role is always Staff (a posted role is ignored)', 'Your account was created' in r.text and sql("SELECT role FROM users WHERE username='NEW001'") == 'staff')
ok('the approver is the Head of Department of the PTJ, the account is Active, the password is hashed',
   sql("SELECT a.username FROM users u JOIN users a ON a.user_id=u.supervisor_id WHERE u.username='NEW001'") == 'hos002' and sql("SELECT is_active FROM users WHERE username='NEW001'") == '1' and sql("SELECT password LIKE '$%' FROM users WHERE username='NEW001'") == '1')
ok('the registration is in the history log', cnt("SELECT COUNT(*) FROM audit_log WHERE action='user_register' AND reason LIKE '%NEW001%'") == 1)
nu = C(); r = nu.login('NEW001', 'Secret123')
ok('the new staff member can sign in and sees the staff dashboard', r.status_code == 302 and 'staff/dashboard.php' in r.headers['Location'] and nu.get('staff/request_vehicle.php').status_code == 200)
ok('a signed-in user is sent away from the registration page', nu.get('register.php').status_code == 302)
ok('the new account cannot open admin pages', nu.get('admin/users.php').status_code == 302)

# ---- managing accounts: roles ----
for who, c in [('staff', s1), ('head of department', hos), ('new staff', nu)]:
    ok('%s cannot open User accounts (role check)' % who, c.get('admin/users.php').status_code == 302 and c.get('admin/add_user.php').status_code == 302 and c.get('admin/edit_user.php', id=1).status_code == 302)
lst = ad.get('admin/users.php')
ok('the Assistant sees the list with the new account and the menu entry', lst.status_code == 200 and 'NEW001' in lst.text and 'Users' in lst.text and not errs(lst))
ok('search by name and filter by role work', 'Aminah' in ad.get('admin/users.php', q='Aminah').text and 'drv001' not in ad.get('admin/users.php', q='Aminah').text and 'drv001' in ad.get('admin/users.php', role='driver').text and 'stf001' not in ad.get('admin/users.php', role='driver').text)
ok('Head of Admin only: the Assistant cannot edit an Assistant or Head of Admin account', ad.get('admin/edit_user.php', id=7).status_code == 302 and 'Edit' not in re.sub(r'.*head001', '', ad.get('admin/users.php', q='head001').text, flags=re.S)[:300])

# ---- add account ----
new = dict(username='DRV099', role='driver', full_name='Hafiz bin Ramli', email='hafiz@uis.edu.my', phone='019-1112222', department='Transport Unit', password='Driver1234', confirm_password='Driver1234')
ok('Add account page opens', ad.get('admin/add_user.php').status_code == 200)
ok('add: POST without CSRF is refused', ad.post('admin/add_user.php', new, csrf=False).status_code == 419)
r = ad.post('admin/add_user.php', dict(new, role='admin'))
ok('the Assistant cannot create an Assistant account', not exists('DRV099') and 'cannot create' in r.text)
r = ad.post('admin/add_user.php', dict(new, password='short'))
ok('add: a weak password is refused', not exists('DRV099'))
r = ad.post('admin/add_user.php', dict(new, username='stf001'))
ok('add: a duplicate staff ID is refused', cnt("SELECT COUNT(*) FROM users WHERE username='stf001'") == 1 and 'already has an account' in r.text)
r = ad.post('admin/add_user.php', new)
ok('the driver account is created and the Assistant goes on to Add Driver', r.status_code == 302 and 'add_driver.php?user_id=' in r.headers['Location'] and sql("SELECT role FROM users WHERE username='DRV099'") == 'driver')
ok('the new person is told in the system and the history log has it', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='DRV099'") == 1 and cnt("SELECT COUNT(*) FROM audit_log WHERE action='user_add' AND reason LIKE '%DRV099%'") == 1)
ok('the new driver account is on the Add Driver list (no driver record yet)', 'Hafiz bin Ramli' in ad.get('admin/add_driver.php').text)
r = hd.post('admin/add_user.php', dict(new, username='ADM099', role='admin', email='adm99@uis.edu.my', full_name='Aisyah Admin'))
ok('the Head of Admin can create an Administrative Assistant', r.status_code == 302 and sql("SELECT role FROM users WHERE username='ADM099'") == 'admin')

# ---- edit, reset password ----
uid = sql("SELECT user_id FROM users WHERE username='NEW001'")
ok('Edit page opens with the values', 'Aminah binti Yusof' in ad.get('admin/edit_user.php', id=uid).text)
r = ad.post('admin/edit_user.php', dict(id=uid, action='save', full_name='Aminah Yusof', email='stf001x@uis.edu.my', phone='', department='Registrar Office', role='staff'))
ok('edit: details and PTJ saved, the approver follows the new PTJ', r.status_code == 302 and sql("SELECT full_name FROM users WHERE username='NEW001'") == 'Aminah Yusof'
   and sql("SELECT a.username FROM users u JOIN users a ON a.user_id=u.supervisor_id WHERE u.username='NEW001'") == 'hos003')
r = ad.post('admin/edit_user.php', dict(id=uid, action='save', full_name='Aminah Yusof', email=sql("SELECT email FROM users WHERE username='stf001'"), phone='', department='Registrar Office', role='staff'))
ok('edit: an e-mail used by another account is refused', 'already used' in r.text)
r = ad.post('admin/edit_user.php', dict(id=uid, action='save', full_name='Aminah Yusof', email='stf001x@uis.edu.my', phone='', department='Registrar Office', role='head_admin'))
ok('edit: the Assistant cannot make someone Head of Admin', sql("SELECT role FROM users WHERE username='NEW001'") == 'staff')
r = ad.post('admin/edit_user.php', dict(id=sql("SELECT user_id FROM users WHERE username='drv001'"), action='save', full_name='Mohd Hafizuddin', email='d1@uis.edu.my', phone='', department='Transport Unit', role='staff'))
ok('edit: the role of an account linked to a driver record stays Driver', sql("SELECT role FROM users WHERE username='drv001'") == 'driver')
r = ad.post('admin/edit_user.php', dict(id=uid, action='password', password='x', confirm_password='x'))
ok('reset: a weak password is refused', 'at least 8' in r.text)
r = ad.post('admin/edit_user.php', dict(id=uid, action='password', password='Fresh1234', confirm_password='Fresh1234'))
ok('reset: saved, the person is told, history logged', r.status_code == 302 and cnt("SELECT COUNT(*) FROM audit_log WHERE action='user_password_reset'") == 1 and cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='NEW001' AND n.message LIKE '%password was reset%'") == 1)
ok('reset: the new password works, the old one does not', C().login('NEW001', 'Secret123').status_code == 200 and C().login('NEW001', 'Fresh1234').status_code == 302)
ok('the password is never written to the history log', cnt("SELECT COUNT(*) FROM audit_log WHERE reason LIKE '%Fresh1234%' OR reason LIKE '%Secret123%'") == 0)

# ---- deactivate / activate ----
def act(c, a, u): return c.post('admin/users.php', dict(user_id=u, action=a))
r = act(ad, 'deactivate', uid)
ok('deactivate: the account is Inactive, nothing is deleted, history logged', sql("SELECT is_active FROM users WHERE username='NEW001'") == '0' and cnt("SELECT COUNT(*) FROM audit_log WHERE action='user_deactivate'") == 1)
ok('an Inactive account cannot sign in', C().login('NEW001', 'Fresh1234').status_code == 200)
act(ad, 'activate', uid)
ok('activate: the person can sign in again', sql("SELECT is_active FROM users WHERE username='NEW001'") == '1' and C().login('NEW001', 'Fresh1234').status_code == 302)
act(ad, 'deactivate', sql("SELECT user_id FROM users WHERE username='admin'"))
ok('nobody can make their own account Inactive', sql("SELECT is_active FROM users WHERE username='admin'") == '1')
act(ad, 'deactivate', sql("SELECT user_id FROM users WHERE username='head001'"))
ok('the Assistant cannot make the Head of Admin Inactive', sql("SELECT is_active FROM users WHERE username='head001'") == '1')
act(ad, 'deactivate', sql("SELECT user_id FROM users WHERE username='drv002'"))
ok('an active driver account cannot be made Inactive here (make the driver Inactive first)', sql("SELECT is_active FROM users WHERE username='drv002'") == '1')
# Head of Department with Pending requests and no other HoD in the PTJ
sql("UPDATE vehicle_requests SET status='pending' WHERE request_id=1")
hod = sql("SELECT supervisor_id FROM vehicle_requests WHERE request_id=1")
act(ad, 'deactivate', hod)
ok('a Head of Department with Pending requests and no other Head in the PTJ cannot be made Inactive', sql("SELECT is_active FROM users WHERE user_id=%s" % hod) == '1')
r = ad.post('admin/add_user.php', dict(new, username='HOD099', role='supervisor', email='hod99@uis.edu.my', full_name='Second Head', department=sql("SELECT department FROM users WHERE user_id=%s" % hod)))
act(ad, 'deactivate', hod)
ok('with a second Head of Department, the first can be made Inactive and the Pending requests move to the second',
   sql("SELECT is_active FROM users WHERE user_id=%s" % hod) == '0' and sql("SELECT a.username FROM vehicle_requests vr JOIN users a ON a.user_id=vr.supervisor_id WHERE vr.request_id=1") == 'HOD099')
ok('staff of that PTJ now have the second Head as approver', sql("SELECT a.username FROM users u JOIN users a ON a.user_id=u.supervisor_id WHERE u.username='stf001'") == 'HOD099' or sql("SELECT department FROM users WHERE username='stf001'") != sql("SELECT department FROM users WHERE username='HOD099'"))
ok('role check and CSRF on the action', s1.post('admin/users.php', dict(user_id=uid, action='deactivate')).status_code == 302 and ad.post('admin/users.php', dict(user_id=uid, action='deactivate'), csrf=False).status_code == 419 and sql("SELECT is_active FROM users WHERE username='NEW001'") == '1')
ok('no PHP errors on the account pages', not any(errs(ad.get(p)) for p in ['admin/users.php', 'admin/add_user.php', 'admin/edit_user.php?id=%s' % uid]))
sys.exit(0 if summary() else 1)
