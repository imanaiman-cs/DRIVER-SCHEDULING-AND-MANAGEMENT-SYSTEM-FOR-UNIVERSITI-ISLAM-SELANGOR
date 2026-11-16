"""WhatsApp through OpenWA: only drivers get WhatsApp; every other role gets the in-system notification and e-mail (fake OpenWA server)."""
import sys, os, json, subprocess, time, datetime, tempfile
from lib import *
cfg = ROOT + '/config/whatsapp.php'
flog = os.path.join(tempfile.gettempdir(), 'fake_openwa.jsonl')
def write_cfg(**kw):
    c = {'enabled': 'true', 'base_url': "'http://127.0.0.1:2786'", 'api_key': "'test-key'", 'session_id': "'uis'", 'country_code': "'60'", 'timeout': '3', 'redirect_to': "''"}
    c.update(kw)
    open(cfg, 'w').write('<?php return [' + ', '.join("'%s' => %s" % (k, v) for k, v in c.items()) + '];')
def sent(): return [json.loads(l) for l in open(flog)] if os.path.exists(flog) else []
cnt = lambda q: int(sql(q))
st = lambda r: sql("SELECT status FROM vehicle_requests WHERE request_id=%d" % r)
reseed()
if os.path.exists(flog): os.remove(flog)
srv = subprocess.Popen(['php', '-S', '127.0.0.1:2786', ROOT + '/tests/http/fake_openwa.php'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(1)
try:
    s1 = user('stf001'); hos = user('hos002'); ad = user('admin'); hd = user('head001')
    # everybody has a mobile number, so only the role decides who gets WhatsApp
    sql("UPDATE users SET phone='012-345 6789' WHERE username='hos002'")
    sql("UPDATE users SET phone='019-888 7777' WHERE username='stf001'")
    sql("UPDATE users SET phone='013-222 3333' WHERE role IN ('admin','head_admin')")
    def trip(h, driver):
        """Staff request -> HoD approves -> Assistant assigns the driver -> Head of Admin approves (driver is told now)."""
        t = now() + datetime.timedelta(hours=h); r = t + datetime.timedelta(hours=4)
        s1.post('staff/request_vehicle.php', {'depart_date': t.strftime('%Y-%m-%d'), 'depart_time': t.strftime('%H:%M'), 'return_date': r.strftime('%Y-%m-%d'), 'return_time': r.strftime('%H:%M'),
            'passengers': '2', 'vehicle_id': '6', 'destination': 'Putrajaya', 'destination_address': 'Presint 5', 'purpose': 'Official duty at the ministry',
            'passenger_names': 'A', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': ''})
        rid = cnt("SELECT MAX(request_id) FROM vehicle_requests")
        hos.post('ajax/request_action.php', {'request_id': rid, 'action': 'approve'})
        ad.post('admin/assign_driver.php', {'request_id': rid, 'out_driver': str(driver), 'return_mode': 'same'})
        hd.post('admin/approve_assignments.php', {'request_id': rid, 'action': 'approve'})
        return rid
    # switched off (no config file): nothing is sent or logged
    if os.path.exists(cfg): os.remove(cfg)
    r0 = trip(200, 3)
    ok('switched off: nothing goes to WhatsApp and nothing is logged', st(r0) == 'processed' and cnt("SELECT COUNT(*) FROM whatsapp_log") == 0 and not sent())
    # switched on
    write_cfg()
    rid = trip(240, 3)
    ok('the whole flow ran (request Approved)', st(rid) == 'processed')
    drv = [m for m in sent() if m['body']['chatId'] == '601134567890@c.us']   # drv003: 011-34567890
    ok('the driver gets the new trip on WhatsApp', len(drv) >= 1 and all(('#%d' % rid) in m['body']['text'] for m in drv))
    ok('sent to the OpenWA session with the API key, logged as sent', drv and drv[0]['session'] == 'uis' and cnt("SELECT COUNT(*) FROM whatsapp_log WHERE status='sent' AND to_number='601134567890'") >= 1)
    ok('only drivers: staff, Head of Department, Assistant and Head of Admin get no WhatsApp',
       all(m['body']['chatId'] == '601134567890@c.us' for m in sent()) and cnt("SELECT COUNT(*) FROM whatsapp_log l JOIN users u ON u.user_id=l.user_id WHERE u.role<>'driver'") == 0)
    ok('the others still get the in-system notification and the e-mail',
       all(cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='%s' AND n.message LIKE '%%#%d%%'" % (u, rid)) >= 1 for u in ('hos002', 'stf001', 'head001'))
       and all(cnt("SELECT COUNT(*) FROM email_log e JOIN users u ON u.user_id=e.user_id WHERE u.username='%s' AND e.subject LIKE '%%#%d%%'" % (u, rid)) >= 1 for u in ('hos002', 'stf001', 'head001')))
    ok('the driver still gets the in-system notification and the e-mail too',
       cnt("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id=n.user_id WHERE u.username='drv003' AND n.message LIKE '%%#%d%%'" % rid) >= 1
       and cnt("SELECT COUNT(*) FROM email_log e JOIN users u ON u.user_id=e.user_id WHERE u.username='drv003' AND e.subject LIKE '%%#%d%%'" % rid) >= 1)
    # a driver without a phone number: skipped and logged, nothing breaks
    sql("UPDATE users SET phone=NULL WHERE username='drv005'"); sql("UPDATE drivers SET phone='' WHERE driver_id=5")
    r2 = trip(260, 5)
    ok('a driver without a mobile number is skipped and logged, the trip still goes ahead', st(r2) == 'processed' and cnt("SELECT COUNT(*) FROM whatsapp_log l JOIN users u ON u.user_id=l.user_id WHERE u.username='drv005' AND l.status='skipped'") >= 1)
    # server down: failed is logged, the trip is still Approved
    write_cfg(base_url="'http://127.0.0.1:2799'")
    r3 = trip(280, 3)
    ok('OpenWA unreachable: the trip is still Approved, the failure is logged', st(r3) == 'processed' and cnt("SELECT COUNT(*) FROM whatsapp_log WHERE status='failed'") >= 1)
    ok('the dashboard shows the WhatsApp failure as an alert', 'WhatsApp message(s) failed' in ad.get('admin/dashboard.php').text)
    # redirect for testing
    write_cfg(redirect_to="'60111111111'")
    n0 = len(sent()); trip(300, 3)
    ok('redirect_to sends every message to the test number', len(sent()) > n0 and all(m['body']['chatId'] == '60111111111@c.us' for m in sent()[n0:]))
finally:
    if os.path.exists(cfg): os.remove(cfg)
    srv.terminate()
sys.exit(0 if summary() else 1)
