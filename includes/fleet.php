<?php
// ============================================================
// includes/fleet.php  -  UC002 Manage Vehicle
//   UC002A Add Vehicle (Active)   UC002C Update Vehicle (current fuel level, current mileage, status)
//   UC002D Deactivate Vehicle     A vehicle is never deleted; a deactivated vehicle is not offered for requests.
//   Changing to Under Maintenance or deactivating while the vehicle has upcoming bookings is refused: the bookings
//   are listed and each one is moved to another vehicle first (changeBookingVehicle, includes/tasks.php).
// ============================================================

function loadVehicle(mysqli $conn, int $id): ?array
{
    return dbOne($conn, 'SELECT * FROM vehicles WHERE vehicle_id = ?', [$id]);
}

function addVehicle(mysqli $conn, array $in): array
{
    $errors = [];
    $plateNo = strtoupper(preg_replace('/\s+/', ' ', trim((string)($in['plate_number'] ?? ''))));
    $type = (string)($in['vehicle_type'] ?? '');
    $brand = trim((string)($in['brand'] ?? '')); $model = trim((string)($in['model'] ?? ''));
    $fuel = (string)($in['fuel_type'] ?? ''); $cap = (int)($in['capacity'] ?? 0);
    $lic = (string)($in['licence_required'] ?? ''); $mil = trim((string)($in['current_mileage'] ?? '')); $lvl = (string)($in['fuel_level'] ?? '');
    if ($plateNo === '') { $errors['plate_number'] = 'Enter the plate number.'; }
    if (!in_array($type, VEHICLE_TYPES, true)) { $errors['vehicle_type'] = 'Select the vehicle type.'; }
    if ($brand === '') { $errors['brand'] = 'Enter the brand.'; }
    if ($model === '') { $errors['model'] = 'Enter the model.'; }
    if (!in_array($fuel, FUEL_TYPES, true)) { $errors['fuel_type'] = 'Select the fuel type.'; }
    if ($cap < 1 || $cap > 100) { $errors['capacity'] = 'Enter the seating capacity.'; }
    if (!isset(LICENCE_CLASSES[$lic])) { $errors['licence_required'] = 'Select the required licence class.'; }
    if ($mil === '' || !ctype_digit($mil)) { $errors['current_mileage'] = 'Enter the current mileage.'; }
    if (!in_array($lvl, FUEL_LEVELS, true)) { $errors['fuel_level'] = 'Select the current fuel level.'; }
    if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }
    if (dbValue($conn, 'SELECT 1 FROM vehicles WHERE plate_number = ?', [$plateNo])) {
        return wfFail('The vehicle already exists.', ['errors' => ['plate_number' => 'This plate number is already registered.']]);
    }
    [, $id] = dbExec($conn, "INSERT INTO vehicles (plate_number, vehicle_type, brand, model, fuel_type, capacity, licence_required, current_mileage, fuel_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
        [$plateNo, $type, mb_substr($brand, 0, 50), mb_substr($model, 0, 50), $fuel, $cap, $lic, (int)$mil, $lvl]);
    return wfOk("The vehicle $plateNo was added with the Active status.", ['vehicle_id' => $id]);
}

/** UC002C: only the current fuel level, current mileage and status (Active / Under Maintenance). */
function updateVehicle(mysqli $conn, int $id, array $in): array
{
    $v = loadVehicle($conn, $id);
    if (!$v || $v['status'] === 'inactive') { return wfFail('Only an active vehicle can be updated.'); }
    $errors = [];
    $mil = trim((string)($in['current_mileage'] ?? '')); $lvl = (string)($in['fuel_level'] ?? ''); $st = (string)($in['status'] ?? '');
    if ($mil === '' || !ctype_digit($mil)) { $errors['current_mileage'] = 'Enter the current mileage.'; }
    elseif ((int)$mil < (int)$v['current_mileage']) { $errors['current_mileage'] = 'The mileage is not valid: it is lower than the current mileage (' . number_format((int)$v['current_mileage']) . ' km).'; }
    if (!in_array($lvl, FUEL_LEVELS, true)) { $errors['fuel_level'] = 'Select the fuel level.'; }
    if (!in_array($st, ['active', 'maintenance'], true)) { $errors['status'] = 'Select the status.'; }
    if ($errors) { return wfFail('Please correct the highlighted fields.', ['errors' => $errors]); }
    if ($st === 'maintenance' && $v['status'] !== 'maintenance' && ($b = vehicleUpcomingBookings($conn, $id))) {
        return wfFail('The vehicle has ' . count($b) . ' upcoming booking(s). Assign another vehicle to each booking first.', ['bookings' => $b]);
    }
    dbExec($conn, 'UPDATE vehicles SET current_mileage = ?, fuel_level = ?, status = ? WHERE vehicle_id = ?', [(int)$mil, $lvl, $st, $id]);
    return wfOk('The vehicle ' . $v['plate_number'] . ' was updated.');
}

function deactivateVehicle(mysqli $conn, int $id): array
{
    $v = loadVehicle($conn, $id);
    if (!$v || $v['status'] === 'inactive') { return wfFail('The vehicle is already inactive.'); }
    if ($b = vehicleUpcomingBookings($conn, $id)) {
        return wfFail('The vehicle has ' . count($b) . ' upcoming booking(s). Assign another vehicle to each booking first.', ['bookings' => $b]);
    }
    dbExec($conn, "UPDATE vehicles SET status = 'inactive' WHERE vehicle_id = ?", [$id]);
    return wfOk('The vehicle ' . $v['plate_number'] . ' was deactivated. It is no longer shown when a request is submitted.');
}

/** Other vehicles a booking could move to (same type for a request; car or van for welfare duty; any for an internal task). */
function bookingAlternatives(mysqli $conn, array $b, array $vehicle): array
{
    if ($b['kind'] === 'request') {
        $rid = (int)dbValue($conn, 'SELECT request_id FROM request_vehicles WHERE rv_id = ?', [$b['id']]);
        return freeVehicles($conn, $vehicle['vehicle_type'], $b['start'], $b['end'], $rid);
    }
    $list = freeVehicles($conn, null, $b['start'], $b['end'], 0, $b['id']);
    if ($b['kind'] === 'welfare') { $list = array_values(array_filter($list, fn($x) => in_array($x['vehicle_type'], WELFARE_VEHICLE_TYPES, true))); }
    return array_values(array_filter($list, fn($x) => (int)$x['vehicle_id'] !== (int)$vehicle['vehicle_id']));
}
