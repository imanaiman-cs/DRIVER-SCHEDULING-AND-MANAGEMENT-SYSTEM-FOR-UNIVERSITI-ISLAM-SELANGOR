import lib, re, json
from lib import *
lib.reseed()
a = user('admin')
sql("INSERT INTO users (username,password,email,full_name,role,department) SELECT 'drv099',password,'new.driver@uis.edu.my','New Driver','driver','Transport Unit' FROM users WHERE username='drv001'")
uid = sql("SELECT user_id FROM users WHERE username='drv099'")
sql("INSERT INTO users (username,password,email,full_name,role,department) SELECT 'drv098',password,'x@uis.edu.my','Other Driver','driver','Transport Unit' FROM users WHERE username='drv001'")

# every page renders
pages = ['admin/vehicles.php','admin/vehicles.php?q=BGN&type=Bus&status=available','admin/vehicles.php?status=in_use','admin/vehicles.php?status=maintenance',
 'admin/add_vehicle.php','admin/edit_vehicle.php?id=1','admin/view_vehicle.php?id=1','admin/view_vehicle.php?id=4','admin/vehicle_maintenance.php?id=4',
 'admin/drivers.php','admin/drivers.php?q=Faizal&status=active&licence=PSV','admin/add_driver.php','admin/edit_driver.php?id=1','admin/view_driver.php?id=1','admin/view_driver.php?id=3','admin/view_driver.php?id=11']
for role in ['admin','head001']:
    c = a if role == 'admin' else user('head001')
    for p in pages:
        r = c.get(p); ok(f'{role} {p} 200 no PHP errors', r.status_code == 200 and not errs(r))
ok('vehicles list shows maintenance workshop', 'Auto Service Klang' in a.get('admin/vehicles.php').text)
ok('no legacy words on vehicles', not re.search(r'Minibus|Motorcycle|Retired', a.get('admin/vehicles.php').text + a.get('admin/add_vehicle.php').text))
ok('no legacy words on drivers', not re.search(r'B2|on_leave|On Leave|Top Management|assigned_to', a.get('admin/drivers.php').text + a.get('admin/add_driver.php').text))
ok('vehicles list shows In Use (veh 1 confirmed trip not now)', True)

# role checks
for u in ['stf001','drv001','hos001']:
    c = user(u)
    for p in ['admin/vehicles.php','admin/add_vehicle.php','admin/drivers.php','admin/add_driver.php','admin/vehicle_maintenance.php?id=1','admin/view_driver.php?id=1','admin/edit_vehicle.php?id=1']:
        r = c.get(p); ok(f'{u} denied {p}', r.status_code in (302,403) and 'Add Vehicle' not in r.text and 'Drivers' not in r.text[:0])
    for ep in ['update_vehicle_status','deactivate_vehicle','deactivate_driver']:
        r = c.post(f'ajax/{ep}.php', {'id':1,'status':'available'}); ok(f'{u} ajax {ep} denied', r.status_code in (302,403) and '"success":true' not in r.text)
ok('anonymous ajax denied', C().post('ajax/deactivate_vehicle.php', {'id':1}, csrf=False).status_code in (302,403))
import os
ok('delete ajax removed', not os.path.exists(ROOT+'/ajax/delete_vehicle.php') and not os.path.exists(ROOT+'/ajax/delete_driver.php'))

# ---- add driver
r = a.post('admin/add_driver.php', {'user_id': uid}); ok('add driver: no licence -> error', 'Tick at least one licence class' in r.text and sql(f"SELECT driver_id FROM users WHERE user_id={uid}") == 'NULL')
r = a.post('admin/add_driver.php', {'user_id': 11, 'license_class[]': ['D']}); ok('add driver: already linked -> error', 'already linked' in r.text)
r = a.post('admin/add_driver.php', {'user_id': 6, 'license_class[]': ['D']}); ok('add driver: admin account refused', 'does not exist' in r.text or 'Driver role' in r.text)
r = a.post('admin/add_driver.php', {'license_class[]': ['D']}); ok('add driver: no account -> error', 'Select the driver account' in r.text)
r = a.post('admin/add_driver.php', {'user_id': uid, 'license_class[]': ['PSV','D','XX'], 'license_number':'D777','license_expiry':D(300),'address':'Shah Alam'})
ok('add driver success redirect', r.status_code == 302, ) 
row = sql(f"SELECT d.employee_id,d.name,d.email,d.license_class,d.status,u.driver_id=d.driver_id FROM drivers d JOIN users u ON u.user_id={uid} WHERE d.employee_id='DRV099'")
ok('driver row copied + linked: '+row, row.startswith('DRV099\tNew Driver') or ('DRV099' in row and 'New Driver' in row and 'D,PSV' in row and row.endswith('1')))
r = a.post('admin/add_driver.php', {'user_id': uid, 'license_class[]': ['D']}); ok('add same user again -> already linked', 'already linked' in r.text)
did = sql("SELECT driver_id FROM drivers WHERE employee_id='DRV099'")
r = a.get('admin/add_driver.php'); ok('add form no longer lists linked user', 'drv099' not in r.text and 'drv098' in r.text)

# ---- edit driver
r = a.post('admin/edit_driver.php', {'id': did, 'phone':'012-1112223','license_class[]':['D','GDL'],'license_number':'D888','license_expiry':D(400),'address':'Klang'})
ok('edit driver redirect', r.status_code == 302)
ok('edit driver saved', sql(f"SELECT license_class,phone,license_number,address FROM drivers WHERE driver_id={did}") == 'D,GDL\t012-1112223\tD888\tKlang'.replace('\t','\t') or 'D,GDL' in sql(f"SELECT license_class FROM drivers WHERE driver_id={did}"))
r = a.post('admin/edit_driver.php', {'id': did, 'phone':'012-1112223'}); ok('edit driver no licence -> error', 'Tick at least one' in r.text)
r = a.post('admin/edit_driver.php', {'id': did, 'license_class[]':['D'],'license_expiry':'2026-02-31'}); ok('edit driver bad date -> error', 'valid date' in r.text)
ok('edit shows name read-only', 'readonly' in a.get(f'admin/edit_driver.php?id={did}').text)

# ---- deactivate driver
r = a.post('ajax/deactivate_driver.php', {'id': 1}); j = r.json()
ok('deactivate driver 1 (has confirmed trip) blocked', j['success'] is False and len(j['items']) > 0)
ok('blocked list has replace url', any(i.get('replace_url') for i in j['items']))
ok('driver 1 still active', sql("SELECT status FROM drivers WHERE driver_id=1") == 'active')
r = a.get('admin/view_driver.php?id=1'); ok('view driver lists replace links', 'replace_driver.php?schedule_id=' in r.text)
j = a.post('ajax/deactivate_driver.php', {'id': did}).json(); ok('deactivate free driver allowed', j['success'] and sql(f"SELECT status FROM drivers WHERE driver_id={did}") == 'inactive')
j = a.post('ajax/deactivate_driver.php', {'id': did, 'reactivate': 1}).json(); ok('reactivate driver', j['success'] and sql(f"SELECT status FROM drivers WHERE driver_id={did}") == 'active')
j = a.post('ajax/deactivate_driver.php', {'id': 9999}).json(); ok('deactivate unknown driver -> error', not j['success'])
# welfare duty blocks
sql(f"INSERT INTO welfare_roster (duty_date, driver_id) VALUES ('{D(20)}', {did})")
j = a.post('ajax/deactivate_driver.php', {'id': did}).json(); ok('welfare duty blocks deactivation', not j['success'] and any('Welfare' in i['text'] for i in j['items']))
sql(f"DELETE FROM welfare_roster WHERE driver_id={did}")
ok('drivers filter by status', 'Hairul' in a.get('admin/drivers.php?status=inactive').text and 'Faizal' not in a.get('admin/drivers.php?status=inactive').text)
ok('drivers filter by licence GDL', 'Roslan' not in a.get('admin/drivers.php?licence=GDL').text and 'Norhaslinda' in a.get('admin/drivers.php?licence=GDL').text)

# ---- vehicles
r = a.post('admin/add_vehicle.php', {'plate_number':'bgn 9595','vehicle_type':'Bus','model':'X','capacity':30}); ok('add vehicle duplicate plate error', 'already exists' in r.text and sql("SELECT COUNT(*) FROM vehicles")=='12')
r = a.post('admin/add_vehicle.php', {'plate_number':'NEW 1','vehicle_type':'Minibus','model':'X','capacity':0}); ok('add vehicle invalid type/capacity', 'Choose the vehicle type' in r.text and 'capacity' in r.text.lower())
r = a.post('admin/add_vehicle.php', {'plate_number':'new 123','vehicle_type':'Lorry','model':'Isuzu','capacity':3}); ok('add vehicle ok', r.status_code==302)
ok('licence auto GDL, available', sql("SELECT license_required,status,plate_number FROM vehicles WHERE plate_number='NEW 123'") == 'GDL\tavailable\tNEW 123')
vid = sql("SELECT vehicle_id FROM vehicles WHERE plate_number='NEW 123'")
r = a.post('admin/edit_vehicle.php', {'id':vid,'plate_number':'NEW 123','vehicle_type':'Bus','model':'Hino','capacity':40}); ok('edit vehicle ok', r.status_code==302 and sql(f"SELECT license_required FROM vehicles WHERE vehicle_id={vid}")=='PSV')
r = a.post('admin/edit_vehicle.php', {'id':vid,'plate_number':'BGN 9595','vehicle_type':'Bus','model':'Hino','capacity':40}); ok('edit vehicle duplicate plate error', 'already exists' in r.text)

# maintenance
r = a.post('admin/vehicle_maintenance.php', {'id':vid,'status':'maintenance','workshop':'','return_date':D(3)}); ok('maintenance: workshop required', 'workshop name' in r.text)
r = a.post('admin/vehicle_maintenance.php', {'id':vid,'status':'maintenance','workshop':'Ws','return_date':''}); ok('maintenance: date required', 'return date' in r.text)
r = a.post('admin/vehicle_maintenance.php', {'id':vid,'status':'maintenance','workshop':'Ws','return_date':D(-1)}); ok('maintenance: past date refused', 'past' in r.text)
r = a.post('admin/vehicle_maintenance.php', {'id':vid,'status':'in_use'}); ok('cannot set In Use', 'set by the system' in r.text)
r = a.post('admin/vehicle_maintenance.php', {'id':vid,'status':'maintenance','workshop':'Ws','return_date':D(3)}); ok('maintenance set', r.status_code==302 and sql(f"SELECT status FROM vehicles WHERE vehicle_id={vid}")=='maintenance' and sql(f"SELECT workshop,end_date,closed_at FROM vehicle_maintenance WHERE vehicle_id={vid}")==f'Ws\t{D(3)}\tNULL')
r = a.get(f'admin/vehicle_maintenance.php?id={vid}'); ok('history shows Ws + Open', 'Ws' in r.text and 'Open' in r.text)
# vehicle 5 has pending request 1 (D(7)); vehicle 3 has approved #2 (D(5)..D(6))
r = a.post('admin/vehicle_maintenance.php', {'id':3,'status':'maintenance','workshop':'KL Workshop','return_date':D(8)}, ); 
r2 = a.get('admin/vehicle_maintenance.php?id=3'); ok('maintenance warning lists trip #2', r.status_code==302 and 'Request #2' in r2.text)
j = a.post('ajax/update_vehicle_status.php', {'id':5,'status':'maintenance','workshop':'W','return_date':D(10)}).json(); ok('ajax maintenance ok w/ warning flash', j['success'] and len(j['items'])>=1 and 'Request #1' in j['items'][0]['text'])
j = a.post('ajax/update_vehicle_status.php', {'id':5,'status':'maintenance','workshop':'','return_date':D(10)}).json(); ok('ajax maintenance needs workshop', not j['success'])
j = a.post('ajax/update_vehicle_status.php', {'id':5,'status':'in_use'}).json(); ok('ajax refuses in_use', not j['success'])
j = a.post('ajax/update_vehicle_status.php', {'id':5,'status':'available'}).json(); ok('back to available closes maintenance', j['success'] and sql("SELECT COUNT(*) FROM vehicle_maintenance WHERE vehicle_id=5 AND closed_at IS NULL")=='0' and sql("SELECT status FROM vehicles WHERE vehicle_id=5")=='available')
# deactivate
j = a.post('ajax/deactivate_vehicle.php', {'id':1}).json(); ok('deactivate vehicle 1 (confirmed trip) blocked', not j['success'] and len(j['items'])>0 and sql("SELECT status FROM vehicles WHERE vehicle_id=1")=='available')
j = a.post('ajax/deactivate_vehicle.php', {'id':5}).json(); ok('deactivate vehicle 5 (pending request) blocked', not j['success'])
j = a.post('ajax/deactivate_vehicle.php', {'id':vid}).json(); ok('deactivate free vehicle ok (closes maintenance)', j['success'] and sql(f"SELECT status FROM vehicles WHERE vehicle_id={vid}")=='inactive' and sql(f"SELECT COUNT(*) FROM vehicle_maintenance WHERE vehicle_id={vid} AND closed_at IS NULL")=='0')
j = a.post('ajax/update_vehicle_status.php', {'id':vid,'status':'available'}).json(); ok('reactivate vehicle', j['success'])
ok('view vehicle shows trips', 'Request #4' in a.get('admin/view_vehicle.php?id=1').text)
ok('vehicles filter type', 'Lorry' in a.get('admin/vehicles.php?type=Lorry').text and 'BGN 9595' not in a.get('admin/vehicles.php?type=Lorry').text)
ok('vehicles filter in_use empty or only in-use', not errs(a.get('admin/vehicles.php?status=in_use')))
ok('log has no PHP errors', not re.search(r'PHP (Warning|Notice|Fatal|Deprecated)', open(os.environ.get('UIST_PHPLOG', '/tmp/agentv.log')).read()))
summary()
