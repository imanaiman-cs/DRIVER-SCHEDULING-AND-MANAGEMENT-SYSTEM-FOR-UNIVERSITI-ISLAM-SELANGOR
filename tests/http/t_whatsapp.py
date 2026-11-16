"""WhatsApp through OpenWA: every notifyUsers() event also goes to WhatsApp when switched on (fake OpenWA server)."""
import sys, os, json, subprocess, time, datetime, tempfile
from lib import *
cfg = ROOT + '/config/whatsapp.php'
flog = os.path.join(tempfile.gettempdir(), 'fake_openwa.jsonl')
def write_cfg(**kw):
    c = {'enabled': 'true', 'base_url': "'http://127.0.0.1:2786'", 'api_key': "'test-key'", 'session_id': "'uis'", 'country_code': "'60'", 'timeout': '3', 'redirect_to': "''",
         'roles': "['staff', 'driver', 'supervisor', 'admin', 'head_admin']"}
    c.update(kw)
    open(cfg, 'w').write('<?php return [' + ', '.join("'%s' => %s" % (k, v) for k, v in c.items()) + '];')
def sent(): return [json.loads(l) for l in open(flog)] if os.path.exists(flog) else []
cnt = lambda q: int(sql(q))
reseed()
if os.path.exists(flog): os.remove(flog)
srv = subprocess.Popen(['php', '-S', '127.0.0.1:2786', ROOT + '/tests/http/fake_openwa.php'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(1)
try:
    s1 = user('stf001'); hos = user('hos002'); ad = user('admin')
    sql("UPDATE users SET phone='012-345 6789' WHERE username='hos002'")
    def submit(h):
        t = now() + datetime.timedelta(hours=h); r = t + datetime.timedelta(hours=4)
        s1.post('staff/request_vehicle.php', {'request_type': 'uis', 'depart_date': t.strftime('%Y-%m-%d'), 'depart_time': t.strftime('%H:%M'), 'return_date': r.strftime('%Y-%m-%d'), 'return_time': r.strftime('%H:%M'),
            'passengers': '2', 'vehicle_type': 'Car', 'quantity': '1', 'destination': 'Putrajaya', 'destination_address': 'Presint 5', 'purpose': 'Attend the national higher education forum',
            'purpose_type': 'official', 'passenger_names': 'A', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': '1'})
        return cnt("SELECT MAX(request_id) FROM vehicle_requests")
    # switched off (no config file): nothing is sent or logged
    if os.path.exists(cfg): os.remove(cfg)
    submit(200)
    ok('switched off: nothing goes to WhatsApp and nothing is logged', cnt("SELECT COUNT(*) FROM whatsapp_log") == 0 and not sent())
    # switched on: a new request reaches the Head of Department on WhatsApp (UC019)
    write_cfg()
    rid = submit(240)
    hod_msgs = [m for m in sent() if m['body']['chatId'] == '60123456789@c.us']
    ok('UC019: the Head of Department gets the new request on WhatsApp (number 012-345 6789 -> 60123456789)', len(hod_msgs) == 1 and ('#%d' % rid) in hod_msgs[0]['body']['text'] and 'supervisor/approvals.php' in hod_msgs[0]['body']['text'])
    ok('sent to the OpenWA session with the API key, logged as sent', hod_msgs and hod_msgs[0]['session'] == 'uis' and cnt("SELECT COUNT(*) FROM whatsapp_log WHERE status='sent' AND to_number='60123456789'") == 1)
    ok('the e-mail and the in-system notification still go out', cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='hos002' AND n.message LIKE '%%#%d%%'" % rid) == 1 and cnt("SELECT COUNT(*) FROM email_log WHERE subject LIKE '%%#%d%%'" % rid) >= 1)
    # approval: the requester (staff) is told on WhatsApp too, if a number is saved
    sql("UPDATE users SET phone='019-888 7777' WHERE username='stf001'")
    sql("UPDATE users SET phone=NULL WHERE role='admin'")
    hos.post('ajax/request_action.php', {'request_id': rid, 'action': 'approve'})
    ok('UC025: approval reaches the requester on WhatsApp', any(m['body']['chatId'] == '60198887777@c.us' and ('#%d' % rid) in m['body']['text'] for m in sent()))
    # a user without a phone number: skipped and logged, nothing breaks
    ok('a user without a mobile number is skipped and logged', cnt("SELECT COUNT(*) FROM whatsapp_log WHERE status='skipped'") >= 1)
    # roles: only drivers
    write_cfg(roles="['driver']")
    n0 = len(sent()); rid2 = submit(260)
    ok('roles limited to drivers: the Head of Department gets no WhatsApp', len(sent()) == n0)
    # server down: failed is logged, the request is still saved and e-mailed
    write_cfg(base_url="'http://127.0.0.1:2799'")
    rid3 = submit(280)
    ok('OpenWA unreachable: the request is still saved, the failure is logged', sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % rid3) == 'pending' and cnt("SELECT COUNT(*) FROM whatsapp_log WHERE status='failed'") >= 1)
    ok('the dashboard shows the WhatsApp failure as an alert', 'WhatsApp message(s) failed' in ad.get('admin/dashboard.php').text)
    # redirect for testing
    write_cfg(redirect_to="'60111111111'")
    n0 = len(sent()); submit(300)
    ok('redirect_to sends every message to the test number', len(sent()) > n0 and all(m['body']['chatId'] == '60111111111@c.us' for m in sent()[n0:]))
finally:
    if os.path.exists(cfg): os.remove(cfg)
    srv.terminate()
sys.exit(0 if summary() else 1)
