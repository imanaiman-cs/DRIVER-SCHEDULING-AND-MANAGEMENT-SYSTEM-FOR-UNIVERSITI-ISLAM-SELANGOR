"""SRS 10 Nov UC002: the vehicle form (licence class, mileage, fuel level) and Update Vehicle (fuel, mileage, status only)."""
import sys
from lib import *
reseed()
ad = user('admin'); stf = user('stf001')
cnt = lambda q: int(sql(q))
f = {'plate_number': 'WXY 100', 'vehicle_type': 'Van', 'model': 'Hiace', 'capacity': '10', 'license_required': 'D', 'mileage': '500', 'fuel_level': 'full'}
r = ad.get('admin/add_vehicle.php'); ok('the add form asks for licence class, mileage and fuel level', 'name="license_required"' in r.text and 'name="mileage"' in r.text and 'name="fuel_level"' in r.text and not errs(r))
for k in ('license_required', 'mileage', 'fuel_level'):
    d = dict(f); d.pop(k); ad.post('admin/add_vehicle.php', d); ok('3.2: %s is required' % k, cnt("SELECT COUNT(*) FROM vehicles WHERE plate_number='WXY 100'") == 0)
ad.post('admin/add_vehicle.php', dict(f, license_required='X')); ok('an unknown licence class is refused', cnt("SELECT COUNT(*) FROM vehicles WHERE plate_number='WXY 100'") == 0)
r = ad.post('admin/add_vehicle.php', f); ok('saved Active with mileage, fuel level and the chosen licence class', r.status_code == 302 and rows("SELECT status, license_required, mileage, fuel_level FROM vehicles WHERE plate_number='WXY 100'") == [['available', 'D', '500', 'full']])
vid = sql("SELECT vehicle_id FROM vehicles WHERE plate_number='WXY 100'")
r = ad.post('admin/add_vehicle.php', f); ok('3.1: a registered plate number is refused', 'already exists' in r.text)
r = ad.get('admin/edit_vehicle.php', id=vid); ok('Update Vehicle shows mileage, fuel level and status (Active / Under Maintenance)', r.status_code == 200 and 'Under Maintenance' in r.text and 'Current mileage' in r.text and not errs(r))
r = ad.post('admin/edit_vehicle.php', {'id': vid, 'action': 'usage', 'mileage': '100', 'fuel_level': 'half', 'status': 'available'}); ok('A2.1: a mileage lower than the current mileage is refused', 'not valid' in r.text and sql("SELECT mileage FROM vehicles WHERE vehicle_id=%s" % vid) == '500')
r = ad.post('admin/edit_vehicle.php', {'id': vid, 'action': 'usage', 'mileage': '650', 'fuel_level': 'half', 'status': 'available'}); ok('fuel level and mileage updated', r.status_code == 302 and sql("SELECT concat(mileage,'/',fuel_level) FROM vehicles WHERE vehicle_id=%s" % vid) == '650/half')
r = ad.post('admin/edit_vehicle.php', {'id': vid, 'action': 'usage', 'mileage': '650', 'fuel_level': 'half', 'status': 'maintenance'}); ok('status changed to Under Maintenance', sql("SELECT status FROM vehicles WHERE vehicle_id=%s" % vid) == 'maintenance')
r = ad.post('admin/edit_vehicle.php', {'id': vid, 'action': 'usage', 'mileage': '650', 'fuel_level': 'full', 'status': 'available'}); ok('back to Active', sql("SELECT concat(status,'/',fuel_level) FROM vehicles WHERE vehicle_id=%s" % vid) == 'available/full')
r = stf.post('admin/edit_vehicle.php', {'id': vid, 'action': 'usage', 'mileage': '9999', 'fuel_level': 'empty', 'status': 'available'}); ok('staff cannot update a vehicle (role check)', sql("SELECT mileage FROM vehicles WHERE vehicle_id=%s" % vid) == '650')
r = ad.post('admin/edit_vehicle.php', {'id': vid, 'action': 'usage', 'mileage': '9999', 'fuel_level': 'empty', 'status': 'available'}, csrf=False); ok('no CSRF token: 419', r.status_code == 419)
sys.exit(0 if summary() else 1)
