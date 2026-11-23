"""Address suggestions and map links: the request form is set up for suggestions, picked coordinates are
stored (and refused when out of range or for a hand-typed waiting place), Google Maps / Waze buttons appear
on the request details, the driver's tasks and the task sheet, and the provider is a setting."""
import datetime
from lib import *

reseed()
s1, ad, hd = user('stf001'), user('admin'), user('head001')
last = lambda: int(sql("SELECT MAX(request_id) FROM vehicle_requests"))
def when(hours):
    t = now() + datetime.timedelta(hours=hours); return t.strftime('%Y-%m-%d'), t.strftime('%H:%M')
def form(dep_h=200, dur_h=3, vehicle=6, **kw):
    dd, dt = when(dep_h); rd, rt = when(dep_h + dur_h)
    f = {'depart_date': dd, 'depart_time': dt, 'return_date': rd, 'return_time': rt, 'passengers': '2', 'vehicle_id': str(vehicle),
         'destination': 'Putrajaya International Convention Centre',
         'destination_address': 'Putrajaya International Convention Centre, Lebuh Gemilang, 62200 Precinct 5, Federal Territory of Putrajaya',
         'purpose': 'Attend the national forum', 'passenger_names': 'A; B', 'phone': '012-3456789', 'waiting_place': 'Main entrance', 'waiting_address': ''}
    f.update(kw); return f

# the form
page = s1.get('staff/request_vehicle.php')
ok('the request form is set up for OpenStreetMap suggestions', 'data-addr-provider="photon"' in page.text and 'address-suggest.js' in page.text
   and 'data-addr-suggest' in page.text and 'id="dest_lat"' in page.text)
ok('no Google key is sent to the browser', 'data-addr-key=""' in page.text)

# coordinates are stored with the request
r = s1.post('staff/request_vehicle.php', form(dest_lat='2.894461', dest_lng='101.677136'))
rid = last()
ok('a request with picked coordinates is saved', r.status_code == 302 and sql("SELECT CONCAT(dest_lat,',',dest_lng) FROM vehicle_requests WHERE request_id=%d" % rid) == '2.894461,101.677136')
r = s1.post('staff/request_vehicle.php', form(dep_h=230, vehicle=5, dest_lat='95', dest_lng='101.6'))
ok('out-of-range coordinates are dropped, the request is still saved', r.status_code == 302 and sql("SELECT dest_lat IS NULL AND dest_lng IS NULL FROM vehicle_requests WHERE request_id=%d" % last()) == '1')
r = s1.post('staff/request_vehicle.php', form(dep_h=260, vehicle=3, dest_lat='abc', dest_lng='1'))
ok('text in a coordinate is ignored', r.status_code == 302 and sql("SELECT dest_lat IS NULL FROM vehicle_requests WHERE request_id=%d" % last()) == '1')
r = s1.post('staff/request_vehicle.php', form(dep_h=290, vehicle=12, waiting_place='Main entrance', wait_lat='3.07', wait_lng='101.51'))
ok('waiting coordinates are kept only for an "Other" address', r.status_code == 302 and sql("SELECT wait_lat IS NULL FROM vehicle_requests WHERE request_id=%d" % last()) == '1')
r = s1.post('staff/request_vehicle.php', form(dep_h=320, vehicle=11, waiting_place='Other', waiting_address='Seksyen 7 LRT station', wait_lat='3.0738', wait_lng='101.5183'))
ok('"Other" waiting address with coordinates is saved', r.status_code == 302 and sql("SELECT CONCAT(wait_lat,',',wait_lng) FROM vehicle_requests WHERE request_id=%d" % last()) == '3.073800,101.518300')

# map links
view = ad.get('admin/vehicle_requests.php?tab=all&open=%d' % rid)
ok('request details: Google Maps link uses the coordinates', 'google.com/maps/search/?api=1&amp;query=2.894461%2C101.677136' in view.text)
ok('request details: Waze link navigates to the coordinates', 'waze.com/ul?ll=2.894461%2C101.677136&amp;navigate=yes' in view.text)
other = ad.get('admin/vehicle_requests.php?tab=all&open=%d' % last())
ok('an "Other" waiting place gets its own map links', other.text.count('waze.com/ul?') >= 2)
old = ad.get('admin/vehicle_requests.php?tab=all&open=1')
ok('a request without coordinates links by its address', 'waze.com/ul?q=' in old.text and 'google.com/maps/search/?api=1&amp;query=Putrajaya' in old.text)
ok('the links open in a new tab', 'target="_blank" rel="noopener"' in view.text)

# the driver's task card and task sheet (seeded confirmed trip)
sid = int(sql("SELECT s.schedule_id FROM schedules s JOIN vehicle_requests vr ON vr.request_id=s.request_id WHERE s.driver_id=1 AND vr.status='processed' LIMIT 1"))
rq = int(sql("SELECT request_id FROM schedules WHERE schedule_id=%d" % sid))
sql("UPDATE vehicle_requests SET dest_lat=2.9990, dest_lng=101.7083 WHERE request_id=%d" % rq)
d1 = user('drv001')
tasks = d1.get('driver/schedules.php')
ok('driver task card has Maps and Waze for the destination', 'waze.com/ul?ll=2.999%2C101.7083' in tasks.text and 'Maps</a>' in tasks.text)
sheet = d1.get('driver/task_sheet.php?id=%d' % sid)
ok('driver task sheet has the map buttons (not printed)', sheet.status_code == 200 and not errs(sheet) and 'waze.com/ul?ll=2.999%2C101.7083' in sheet.text)
ok('admin task sheet has the map buttons', 'waze.com/ul?' in ad.get('admin/task_sheet.php?id=%d' % sid).text)

# the setting
base = {'W_TASKS': '0.6', 'W_AFTER_HOURS': '0.4', 'LAPSE_HOURS': '48', 'REMINDER_HOURS': '24', 'WORK_START': '08:00', 'WORK_END': '17:30',
        'TASK_DURATION_HOURS': '2', 'SESSION_TIMEOUT_MIN': '15', 'MAX_FAILED_ATTEMPTS': '5', 'LOCKOUT_MINUTES': '15'}
r = ad.post('admin/settings.php', dict(base, ADDRESS_SUGGEST='google', GOOGLE_MAPS_KEY=''))
ok('Google without a key is refused', r.status_code == 200 and 'Enter the Google Maps API key' in r.text)
r = ad.post('admin/settings.php', dict(base, ADDRESS_SUGGEST='google', GOOGLE_MAPS_KEY='bad key!'))
ok('a key with odd characters is refused', 'letters, digits' in r.text)
r = ad.post('admin/settings.php', dict(base, ADDRESS_SUGGEST='nope', GOOGLE_MAPS_KEY=''))
ok('an unknown provider is refused', 'Choose one of the options' in r.text)
r = ad.post('admin/settings.php', dict(base, ADDRESS_SUGGEST='google', GOOGLE_MAPS_KEY='AIzaTestKey_123-abc'))
ok('Google with a key saves', r.status_code == 302 and sql("SELECT setting_value FROM settings WHERE setting_key='ADDRESS_SUGGEST'") == 'google')
page = s1.get('staff/request_vehicle.php')
ok('the form then uses Google with the key', 'data-addr-provider="google"' in page.text and 'data-addr-key="AIzaTestKey_123-abc"' in page.text)
ad.post('admin/settings.php', dict(base, ADDRESS_SUGGEST='off', GOOGLE_MAPS_KEY=''))
page = s1.get('staff/request_vehicle.php')
ok('Off: no suggestions script, the form still works', 'address-suggest.js' not in page.text and 'name="destination"' in page.text)
ok('settings page shows the address options', 'OpenStreetMap (free, no key)' in ad.get('admin/settings.php').text)
reseed()
print(summary())
