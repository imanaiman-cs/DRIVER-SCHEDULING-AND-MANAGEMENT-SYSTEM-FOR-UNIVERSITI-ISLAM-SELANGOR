"""Class diagram attributes: Request.approvedBy / rejectReason / rejectedBy / rejectedDate, Notification.emailSent, Department head."""
import sys, json, datetime
from lib import *
cnt = lambda q: int(sql(q))
reseed()
s1 = user('stf001'); hos = user('hos002')
hos_id = sql("SELECT user_id FROM users WHERE username='hos002'")
def submit(h):
    t = now() + datetime.timedelta(hours=h); r = t + datetime.timedelta(hours=4)
    s1.post('staff/request_vehicle.php', {'depart_date': t.strftime('%Y-%m-%d'), 'depart_time': t.strftime('%H:%M'), 'return_date': r.strftime('%Y-%m-%d'), 'return_time': r.strftime('%H:%M'),
        'passengers': '2', 'vehicle_id': '6', 'destination': 'Putrajaya', 'destination_address': 'Presint 5', 'purpose': 'Official duty at the ministry',
        'passenger_names': 'A', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': ''})
    return cnt("SELECT MAX(request_id) FROM vehicle_requests")

# Department: the request of a staff member goes to the head of the department
r1 = submit(200)
ok('Department: the approver is the head of the staff member\'s department', sql("SELECT supervisor_id FROM vehicle_requests WHERE request_id=%d" % r1) == hos_id
   and sql("SELECT head_user_id FROM departments d JOIN users u ON u.department_id=d.department_id WHERE u.username='stf001'") == hos_id)
ok('Request: nobody approved or rejected it yet', sql("SELECT CONCAT_WS('|', approved_by, rejected_by, rejected_at, reject_reason) FROM vehicle_requests WHERE request_id=%d" % r1) == '')

# approvedBy
hos.post('ajax/request_action.php', {'request_id': r1, 'action': 'approve'})
ok('Request.approvedBy: the Head of Department who approved', sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r1) == 'approved'
   and sql("SELECT approved_by FROM vehicle_requests WHERE request_id=%d" % r1) == hos_id and sql("SELECT rejected_by IS NULL FROM vehicle_requests WHERE request_id=%d" % r1) == '1')

# rejectReason, rejectedBy, rejectedDate
r2 = submit(230)
hos.post('ajax/request_action.php', {'request_id': r2, 'action': 'reject', 'reason': 'Use the faculty van'})
ok('Request.rejectReason / rejectedBy / rejectedDate are saved', sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r2) == 'rejected'
   and sql("SELECT CONCAT(reject_reason, '|', rejected_by, '|', rejected_at IS NOT NULL, '|', approved_by IS NULL) FROM vehicle_requests WHERE request_id=%d" % r2) == 'Use the faculty van|%s|1|1' % hos_id)
ok('the requester sees the reason', 'Use the faculty van' in s1.get('staff/my_requests.php', open=r2).text)

# Notification.emailSent: e-mail is switched off in the tests, so it stays 0; every notification has the column
ok('Notification.emailSent: 0 while e-mail sending is off (the e-mail is logged as skipped/failed)',
   cnt("SELECT COUNT(*) FROM notifications WHERE message LIKE '%%#%d%%'" % r2) >= 1 and cnt("SELECT COUNT(*) FROM notifications WHERE email_sent=1") == 0
   and cnt("SELECT COUNT(*) FROM email_log WHERE subject LIKE '%%#%d%%' AND status IN ('skipped','failed')" % r2) >= 1)
sys.exit(0 if summary() else 1)
