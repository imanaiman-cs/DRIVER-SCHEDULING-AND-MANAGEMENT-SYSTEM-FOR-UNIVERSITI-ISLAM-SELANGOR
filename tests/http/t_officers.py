"""Contact officers replace passenger names: up to three officers (name + phone) on the request form, the
requester filled in by default; shown on the request details, the driver's task card, the task sheet and the
driver's WhatsApp (numbered, with No. Tel), as in the Administrative Assistant's own messages."""
import datetime, os, subprocess
from lib import *

reseed()
s1, ad = user('stf001'), user('admin')
last = lambda: int(sql("SELECT MAX(request_id) FROM vehicle_requests"))
def when(hours):
    t = now() + datetime.timedelta(hours=hours); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def form(dep_h, vehicle, officers, **kw):
    dd, dt = when(dep_h); rd, rt = when(dep_h + 3)
    f = {'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '2', 'vehicle_id': str(vehicle),
         'destination': 'SCCC, Shah Alam', 'destination_address': 'Shah Alam Convention Centre, Persiaran Sukan, Seksyen 13, 40100 Shah Alam',
         'purpose': 'Hantar dan ambil pegawai', 'waiting_place': 'Main entrance', 'waiting_address': ''}
    f.update(kw)
    f['officer_name[]'] = [o[0] for o in officers]; f['officer_phone[]'] = [o[1] for o in officers]
    return f

page = s1.get('staff/request_vehicle.php')
ok('the form asks for officers, not passenger names', 'Officers to contact' in page.text and 'name="officer_name[]"' in page.text and 'name="passenger_names"' not in page.text)
me = sql("SELECT full_name FROM users WHERE username='stf001'")
ok('the requester is filled in as the first officer', 'value="%s"' % me in page.text)

two = [('Dr Mohd Izzuddin bin Mohd Pisol', '012-111 2233'), ('Muhammad Zubair bin Zairuddin', '013-444 5566')]
r = s1.post('staff/request_vehicle.php', form(200, 6, two))
rid = last()
ok('two officers are saved in order', r.status_code == 302 and sql("SELECT REPLACE(officer_name, '\\n', '|') FROM vehicle_requests WHERE request_id=%d" % rid) == 'Dr Mohd Izzuddin bin Mohd Pisol|Muhammad Zubair bin Zairuddin'
   and sql("SELECT REPLACE(officer_phone, '\\n', '|') FROM vehicle_requests WHERE request_id=%d" % rid) == '012-111 2233|013-444 5566')
n0 = int(sql("SELECT COUNT(*) FROM vehicle_requests"))
r = s1.post('staff/request_vehicle.php', form(230, 5, [('A Officer', '012-0000000'), ('B Officer', '012-0000001'), ('C Officer', '012-0000002'), ('D Officer', '012-0000003')]))
ok('at most three officers are kept', r.status_code == 302 and sql("SELECT LENGTH(officer_name)-LENGTH(REPLACE(officer_name, '\\n', ''))+1 FROM vehicle_requests WHERE request_id=%d" % last()) == '3')
n0 = int(sql("SELECT COUNT(*) FROM vehicle_requests"))
r = s1.post('staff/request_vehicle.php', form(260, 3, [('Pegawai Satu', 'abc')]))
ok('an officer without a valid phone is refused', r.status_code == 200 and 'Officer 1: enter a valid phone number' in r.text and int(sql("SELECT COUNT(*) FROM vehicle_requests")) == n0)
r = s1.post('staff/request_vehicle.php', form(260, 3, [('', '012-3456789')]))
ok('an officer without a name is refused', r.status_code == 200 and 'Officer 1: enter the name' in r.text and int(sql("SELECT COUNT(*) FROM vehicle_requests")) == n0)
r = s1.post('staff/request_vehicle.php', form(260, 3, [('', '')]))
ok('at least one officer is needed', r.status_code == 200 and 'Enter at least one officer to contact' in r.text)
ok('the entered officers are kept on the form after an error', 'value="Pegawai Satu"' in s1.post('staff/request_vehicle.php', form(260, 3, [('Pegawai Satu', 'abc')])).text)

view = ad.get('admin/vehicle_requests.php?tab=all&open=%d' % rid)
ok('request details list both officers with tel: links', 'Officers to contact' in view.text and 'Muhammad Zubair bin Zairuddin' in view.text and 'href="tel:0134445566"' in view.text)

# a confirmed seeded trip with two officers: driver card, task sheet, WhatsApp text
sid = int(sql("SELECT s.schedule_id FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE s.driver_id=1 AND vr.status='processed' LIMIT 1"))
rq = int(sql("SELECT request_id FROM schedules WHERE schedule_id=%d" % sid))
sql("UPDATE vehicle_requests SET officer_name='Dr Mohd Izzuddin bin Mohd Pisol\\nMuhammad Zubair bin Zairuddin', officer_phone='012-111 2233\\n013-444 5566' WHERE request_id=%d" % rq)
d1 = user('drv001')
card = d1.get('driver/schedules.php')
ok('driver task card shows "Officers" with both', '>Officers<' in card.text and 'Muhammad Zubair bin Zairuddin' in card.text and 'tel:0121112233' in card.text)
sheet = d1.get('driver/task_sheet.php?id=%d' % sid)
ok('task sheet numbers the officers and the phones', '1) DR MOHD IZZUDDIN BIN MOHD PISOL<br>2) MUHAMMAD ZUBAIR BIN ZAIRUDDIN' in sheet.text and '1) 012-111 2233<br>2) 013-444 5566' in sheet.text)
php = "require 'config/database.php'; require_once 'includes/schedule_view.php'; echo driverTaskWhatsAppText(taskSheetRow($conn, %d));" % sid
env = dict(os.environ, DSMS_DB=DB)
txt = subprocess.run(['php', '-r', php], cwd=ROOT, capture_output=True, text=True, env=env).stdout
ok('WhatsApp starts with TUGASAN PEMANDU', txt.startswith('*TUGASAN PEMANDU*'))
ok('WhatsApp numbers the officers', '*Pegawai :*\n1) DR MOHD IZZUDDIN BIN MOHD PISOL\n2) MUHAMMAD ZUBAIR BIN ZAIRUDDIN' in txt)
ok('WhatsApp has No. Tel numbered', '*No. Tel :*\n1) 012-111 2233\n2) 013-444 5566' in txt)
sql("UPDATE vehicle_requests SET officer_name='Norliza binti Abdul Rahman', officer_phone='012-300 0001' WHERE request_id=%d" % rq)
txt = subprocess.run(['php', '-r', php], cwd=ROOT, capture_output=True, text=True, env=env).stdout
ok('one officer: no numbering', '*Pegawai :*\nNORLIZA BINTI ABDUL RAHMAN\n*No. Tel :*\n012-300 0001' in txt)
reseed()
print(summary())
