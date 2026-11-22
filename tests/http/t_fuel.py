"""Fuel level and odometer: the driver records them after a task on My Tasks; the vehicle page shows the
latest fuel level, the mileage and the service dates; the vehicle form takes mileage and the next service."""
import re
from lib import *

reseed()
SID = int(sql("SELECT s.schedule_id FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE s.driver_id=8 AND vr.status='completed' ORDER BY s.schedule_id LIMIT 1"))
VID = int(sql("SELECT vehicle_id FROM schedules WHERE schedule_id=%d" % SID))
FUTURE = int(sql("SELECT s.schedule_id FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE s.driver_id=1 AND vr.status='processed' AND s.start_dt > NOW() LIMIT 1"))

d8, d1, ad = user('drv008'), user('drv001'), user('admin')

# the form shows only on tasks that have started
past = d8.get('driver/schedules.php?tab=past')
ok('a past task with a vehicle has the fuel and odometer form', past.status_code == 200 and not errs(past) and 'name="fuel"' in past.text and 'value="%d"' % SID in past.text)
up = d1.get('driver/schedules.php')
ok('a task that has not started has no form', 'record_reading.php' not in up.text)

def rec(c, sid, fuel, odo, kind='trip'):
    return c.post('driver/record_reading.php', {'kind': kind, 'id': sid, 'fuel': fuel, 'odometer': odo, 'tab': 'past'})

mileage0 = int(sql("SELECT mileage FROM vehicles WHERE vehicle_id=%d" % VID))
r = rec(d8, SID, '1', str(mileage0 + 120))
ok('the driver saves fuel and odometer', r.status_code == 302 and 'schedules.php?tab=past' in r.headers.get('Location', ''))
ok('the reading is stored once for the task', sql("SELECT CONCAT(fuel_level,'|',odometer) FROM vehicle_readings WHERE schedule_id=%d" % SID) == '1|%d' % (mileage0 + 120))
ok('the vehicle keeps the latest fuel level, who and the mileage', sql("SELECT CONCAT(fuel_level,'|',fuel_updated_by,'|',mileage) FROM vehicles WHERE vehicle_id=%d" % VID) == '1|8|%d' % (mileage0 + 120))
rec(d8, SID, '2', str(mileage0 + 130))
ok('a second save corrects the same reading', sql("SELECT COUNT(*) FROM vehicle_readings WHERE schedule_id=%d" % SID) == '1' and sql("SELECT fuel_level FROM vehicle_readings WHERE schedule_id=%d" % SID) == '2')
ok('the form shows the saved values', 'value="2" required checked' in d8.get('driver/schedules.php?tab=past').text)

# refusals
SID2 = int(sql("SELECT s.schedule_id FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE s.driver_id=8 AND vr.status='completed' AND s.vehicle_id=%d AND s.schedule_id<>%d LIMIT 1" % (VID, SID)))
r = rec(d8, SID2, '3', '10'); r2 = d8.get('driver/schedules.php?tab=past')
ok('an odometer lower than the last reading is refused', 'cannot be lower than the last reading' in r2.text and sql("SELECT COUNT(*) FROM vehicle_readings WHERE schedule_id=%d" % SID2) == '0')
rec(d8, SID, '9', ''); ok('a fuel level outside 0-4 is refused', sql("SELECT fuel_level FROM vehicle_readings WHERE schedule_id=%d" % SID) == '2')
rec(d8, SID, '2', 'abc'); ok('a non-numeric odometer is refused', 'digits only' in d8.get('driver/schedules.php?tab=past').text)
rec(d1, SID, '4', ''); ok('another driver cannot record on this task', sql("SELECT fuel_level FROM vehicle_readings WHERE schedule_id=%d" % SID) == '2')
rec(d1, FUTURE, '4', ''); ok('a task that has not started is refused', sql("SELECT COUNT(*) FROM vehicle_readings WHERE schedule_id=%d" % FUTURE) == '0')
ok('staff cannot post a reading', user('stf001').post('driver/record_reading.php', {'kind': 'trip', 'id': SID, 'fuel': '4'}).status_code in (302, 403) and sql("SELECT fuel_level FROM vehicle_readings WHERE schedule_id=%d" % SID) == '2')
ok('the reading form needs the CSRF token (419)', d8.post('driver/record_reading.php', {'kind': 'trip', 'id': SID, 'fuel': '4'}, csrf=False).status_code == 419)

# vehicle page
page = ad.get('admin/view_vehicle.php?id=%d' % VID)
ok('vehicle page opens without errors', page.status_code == 200 and not errs(page))
ok('vehicle page shows the fuel gauge with the driver', '½ tank' in page.text and 'fuel-gauge' in page.text and sql("SELECT name FROM drivers WHERE driver_id=8") in page.text)
ok('vehicle page shows the mileage', '{:,} km'.format(mileage0 + 130) in page.text)
ok('vehicle page lists the maintenance history', 'Maintenance history' in ad.get('admin/view_vehicle.php?id=5').text and 'Brake pads' in ad.get('admin/view_vehicle.php?id=5').text)
ok('an overdue service is flagged', 'Overdue by' in ad.get('admin/view_vehicle.php?id=11').text)
ok('a vehicle without a fuel reading says so', 'Not recorded yet' in ad.get('admin/view_vehicle.php?id=4').text)
ok('the gallery shows the low fuel level', '¼' in ad.get('admin/vehicles.php').text or 'Almost empty' in ad.get('admin/vehicles.php').text)

# vehicle form: mileage and next service
form = ad.get('admin/edit_vehicle.php?id=6')
ok('the edit form has mileage and next service', 'name="mileage"' in form.text and 'name="next_maintenance"' in form.text)
cur = rows("SELECT plate_number, vehicle_type, brand, model, year, capacity, fuel_type FROM vehicles WHERE vehicle_id=6")[0]
r = ad.post('admin/edit_vehicle.php?id=6', {'plate_number': cur[0], 'vehicle_type': cur[1], 'brand': cur[2], 'model': cur[3], 'year': cur[4], 'capacity': cur[5], 'fuel_type': cur[6],
                                            'mileage': '30 500', 'next_maintenance': '2027-05-01', 'notes': ''})
ok('mileage and next service save', r.status_code == 302 and sql("SELECT CONCAT(mileage,'|',next_maintenance) FROM vehicles WHERE vehicle_id=6") == '30500|2027-05-01')
r = ad.post('admin/edit_vehicle.php?id=6', {'plate_number': cur[0], 'vehicle_type': cur[1], 'model': cur[3], 'capacity': cur[5], 'fuel_type': cur[6], 'mileage': 'x1', 'next_maintenance': ''})
ok('a bad mileage is refused', r.status_code == 200 and 'digits only' in r.text)

# back from the workshop: last service is today
ad.post('ajax/update_vehicle_status.php', {'id': 4, 'status': 'available'})
ok('closing maintenance records the last service', sql("SELECT last_maintenance = CURDATE() FROM vehicles WHERE vehicle_id=4") == '1')
reseed()
print(summary())
