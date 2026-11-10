"""SRS 10 Nov UC018: purpose type Official / Unofficial and the required supporting letter; UC002: vehicle type MPV."""
import sys, datetime, json
from lib import *

reseed()
s1 = user('stf001'); ad = user('admin')
cnt = lambda q: int(sql(q)); last = lambda: cnt("SELECT MAX(request_id) FROM vehicle_requests")
def when(h):
    t = now() + datetime.timedelta(hours=h); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
dd, dt = when(96); rd, rt = when(100)
vid = json.loads(s1.get('ajax/get_free_vehicles.php', depart_date=dd, depart_time=dt, return_date=rd, return_time=rt, passengers=2).text)['vehicles'][0]['vehicle_id']
f = lambda **kw: dict({'request_type': 'uis', 'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '2', 'vehicle_id': str(vid), 'destination': 'Putrajaya', 'destination_address': 'Presint 5',
     'purpose': 'Attend the national higher education forum', 'purpose_type': 'official', 'passenger_names': 'A; B', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': '', 'need_driver': '1'}, **kw)
n0 = cnt("SELECT COUNT(*) FROM vehicle_requests")
pg = s1.get('staff/request_vehicle.php')
ok('the form asks for the purpose type and marks the supporting letter as required', 'name="purpose_type"' in pg.text and 'Official' in pg.text and 'Unofficial' in pg.text and 'Supporting letter *' in pg.text and not errs(pg))
r = s1.post('staff/request_vehicle.php', f(), files=[])
ok('no supporting letter: refused (UC018 3.2), nothing saved', cnt("SELECT COUNT(*) FROM vehicle_requests") == n0 and 'supporting letter is required' in r.text)
r = s1.post('staff/request_vehicle.php', f(purpose_type='zzz'))
ok('an unknown purpose type is refused', cnt("SELECT COUNT(*) FROM vehicle_requests") == n0 and 'purpose type' in r.text)
r = s1.post('staff/request_vehicle.php', f(purpose_type='personal_rental'))
ok('Personal Rental is not offered yet on this form', cnt("SELECT COUNT(*) FROM vehicle_requests") == n0)
r = s1.post('staff/request_vehicle.php', f(purpose_type='unofficial')); rid = last()
ok('Unofficial with a letter is saved with its purpose type and the letter as an approval_letter document', r.status_code == 302 and rows("SELECT purpose_type FROM vehicle_requests WHERE request_id=%d" % rid) == [['unofficial']]
   and cnt("SELECT COUNT(*) FROM request_documents WHERE request_id=%d AND doc_type='approval_letter'" % rid) == 1)
ok('the request shows its purpose type', 'Unofficial' in s1.get('staff/my_requests.php', open=rid).text)
r = s1.post('staff/request_vehicle.php', f(request_type='pay_claim', own_vehicle_plate='WXY 1234'), files=[])
ok('Pay and Claim needs the supporting letter too', cnt("SELECT COUNT(*) FROM vehicle_requests") == rid and 'supporting letter is required' in r.text)
# MPV
pg = ad.get('admin/add_vehicle.php')
ok('Add Vehicle offers MPV', '<option value="MPV"' in pg.text)
r = ad.post('admin/add_vehicle.php', {'plate_number': 'MPV 7001', 'vehicle_type': 'MPV', 'model': 'Alphard', 'capacity': '7', 'brand': 'Toyota', 'year': '2024', 'fuel_type': 'Petrol', 'notes': ''})
ok('an MPV is saved with licence class D', rows("SELECT vehicle_type, license_required FROM vehicles WHERE plate_number='MPV 7001'") == [['MPV', 'D']])
sys.exit(0 if summary() else 1)
