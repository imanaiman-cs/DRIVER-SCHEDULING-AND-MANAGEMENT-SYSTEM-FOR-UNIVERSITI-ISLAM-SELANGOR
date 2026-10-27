<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/fleet_helpers.php  -  shared rules of the vehicle and driver pages (SRS UC001-UC009).
// Included by admin/vehicles.php, drivers.php ... and by the ajax endpoints. It is a library: it prints nothing.
// ============================================================
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit(); }

const VEHICLE_ADMIN_STATUSES = ['available', 'maintenance', 'inactive'];   // "In Use" is set by the system only

/** The licence-class tick boxes as text: "D (car, van), PSV (bus)". */
function licenceText(?string $list): string
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', (string)$list))) as $c) {
        if (isset(LICENCE_CLASSES[$c])) { $out[] = $c; }
    }
    return $out ? implode(', ', $out) : '-';
}

/** Small coloured badges for the licence classes of a driver. */
function licenceBadges(?string $list): string
{
    $html = '';
    foreach (array_filter(array_map('trim', explode(',', (string)$list))) as $c) {
        if (isset(LICENCE_CLASSES[$c])) { $html .= '<span class="badge bg-light text-dark border me-1">' . htmlspecialchars($c) . '</span>'; }
    }
    return $html !== '' ? $html : '<span class="text-muted">-</span>';
}

/** Date + time of a trip row for messages. */
function fleetWhen(string $start, ?string $end = null): string
{
    $s = date('d M Y H:i', strtotime($start));
    return $end ? $s . ' - ' . (date('Y-m-d', strtotime($start)) === date('Y-m-d', strtotime($end)) ? date('H:i', strtotime($end)) : date('d M H:i', strtotime($end))) : $s;
}

// ------------------------------------------------------------
// Vehicles
// ------------------------------------------------------------

/**
 * Trips that would be hit when a vehicle goes out of service.
 * @param string|null $until Y-m-d: only trips that depart on or before the end of that day (null = all upcoming)
 * @param array $statuses request statuses to look at
 * @return array<int, array{kind:string, request_id:?int, text:string, url:?string}>
 */
function vehicleUpcomingTrips(mysqli $conn, int $vehicle_id, ?string $until = null): array
{
    $items = [];
    $seen = [];
    $limit = $until !== null ? $until . ' 23:59:59' : '9999-12-31 23:59:59';

    $stmt = $conn->prepare(
        "SELECT vr.request_id, vr.depart_at, vr.return_at, vr.destination, vr.status, u.full_name
           FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id
          WHERE vr.vehicle_id = ? AND vr.status IN ('pending','approved','assigned','processed')
            AND vr.depart_at <= ? AND vr.return_at > NOW() ORDER BY vr.depart_at"
    );
    $stmt->bind_param('is', $vehicle_id, $limit);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $seen[(int)$r['request_id']] = true;
        $items[] = ['kind' => 'request', 'request_id' => (int)$r['request_id'],
            'text' => 'Request #' . $r['request_id'] . ' (' . requestStatusLabel($r['status']) . '): ' . fleetWhen($r['depart_at'], $r['return_at'])
                    . ' to ' . $r['destination'] . ', requested by ' . $r['full_name'],
            'url' => 'vehicle_requests.php'];
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT s.schedule_id, s.request_id, s.start_dt, s.end_dt, s.destination FROM schedules s
          WHERE s.vehicle_id = ? AND s.status <> 'cancelled' AND s.end_dt > NOW() AND s.start_dt <= ? ORDER BY s.start_dt"
    );
    $stmt->bind_param('is', $vehicle_id, $limit);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if ($r['request_id'] !== null && isset($seen[(int)$r['request_id']])) { continue; }
        $items[] = ['kind' => 'schedule', 'request_id' => $r['request_id'] !== null ? (int)$r['request_id'] : null,
            'text' => 'Trip' . ($r['request_id'] ? ' of request #' . $r['request_id'] : '') . ': ' . fleetWhen($r['start_dt'], $r['end_dt']) . ' to ' . $r['destination'],
            'url' => null];
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT t.task_id, t.task_type, t.start_dt, t.end_dt, d.name FROM internal_tasks t JOIN drivers d ON d.driver_id = t.driver_id
          WHERE t.vehicle_id = ? AND t.status = 'active' AND t.end_dt > NOW() AND t.start_dt <= ? ORDER BY t.start_dt"
    );
    $stmt->bind_param('is', $vehicle_id, $limit);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = ['kind' => 'internal', 'request_id' => null,
            'text' => 'Internal task (' . str_replace('_', ' ', $r['task_type']) . '): ' . fleetWhen($r['start_dt'], $r['end_dt']) . ', driver ' . $r['name'],
            'url' => 'internal_tasks.php'];
    }
    $stmt->close();
    return $items;
}

/** Closes the open maintenance row(s) of a vehicle (it is back in service). */
function closeVehicleMaintenance(mysqli $conn, int $vehicle_id): void
{
    $stmt = $conn->prepare('UPDATE vehicle_maintenance SET closed_at = NOW() WHERE vehicle_id = ? AND closed_at IS NULL');
    $stmt->bind_param('i', $vehicle_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * UC008 / UC009: sets the status an administrator may choose.
 * @return array{ok:bool, message:string, items:array}  items = trips to list (warning, or the reason it is refused)
 */
function setVehicleStatus(mysqli $conn, int $vehicle_id, string $status, string $workshop = '', string $return_date = ''): array
{
    if (!in_array($status, VEHICLE_ADMIN_STATUSES, true)) {
        return ['ok' => false, 'message' => 'Choose Available, Under Maintenance or Inactive. "In Use" is set by the system.', 'items' => []];
    }
    $workshop = mb_substr(trim($workshop), 0, 120);
    $items = [];
    if ($status === 'maintenance') {
        if ($workshop === '') { return ['ok' => false, 'message' => 'Enter the workshop name.', 'items' => []]; }
        if ($return_date === '' || !isValidDate($return_date)) { return ['ok' => false, 'message' => 'Enter the expected return date.', 'items' => []]; }
        if ($return_date < date('Y-m-d')) { return ['ok' => false, 'message' => 'The expected return date cannot be in the past.', 'items' => []]; }
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT plate_number, status FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
        $stmt->bind_param('i', $vehicle_id);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$v) { $conn->rollback(); return ['ok' => false, 'message' => 'Vehicle not found.', 'items' => []]; }

        if ($status === 'inactive') {
            $items = vehicleUpcomingTrips($conn, $vehicle_id);
            if ($items) {
                $conn->rollback();
                return ['ok' => false, 'message' => $v['plate_number'] . ' has upcoming trips, so it cannot be made Inactive. Replace the vehicle on these trips or wait until they are over.', 'items' => $items];
            }
        }
        if ($status === 'maintenance') {
            $items = vehicleUpcomingTrips($conn, $vehicle_id, $return_date);
            closeVehicleMaintenance($conn, $vehicle_id);
            $stmt = $conn->prepare('INSERT INTO vehicle_maintenance (vehicle_id, start_date, end_date, workshop) VALUES (?, CURDATE(), ?, ?)');
            $stmt->bind_param('iss', $vehicle_id, $return_date, $workshop);
            $stmt->execute();
            $stmt->close();
        } else {
            closeVehicleMaintenance($conn, $vehicle_id);
        }
        $stmt = $conn->prepare('UPDATE vehicles SET status = ? WHERE vehicle_id = ?');
        $stmt->bind_param('si', $status, $vehicle_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('setVehicleStatus: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Database error. Please try again.', 'items' => []];
    }
    auditLog($conn, 'vehicle_status', null, $v['plate_number'] . ': ' . $v['status'] . ' -> ' . $status . ($status === 'maintenance' ? " ($workshop, back $return_date)" : ''));
    $msg = $v['plate_number'] . ' is now ' . vehicleStatusLabel($status) . '.';
    if ($status === 'maintenance' && $items) {
        $msg .= ' Warning: ' . count($items) . ' upcoming trip(s) fall in the maintenance period.';
    }
    return ['ok' => true, 'message' => $msg, 'items' => $items];
}

// ------------------------------------------------------------
// Drivers
// ------------------------------------------------------------

/**
 * Upcoming work of a driver (UC004): request tasks, internal tasks, welfare duty days.
 * @return array<int, array{kind:string, request_id:?int, schedule_id:?int, text:string}>
 */
function driverUpcomingTasks(mysqli $conn, int $driver_id): array
{
    $items = [];
    $stmt = $conn->prepare(
        "SELECT s.schedule_id, s.request_id, s.leg_no, s.start_dt, s.end_dt, s.destination, v.plate_number
           FROM schedules s LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
          WHERE s.driver_id = ? AND s.status <> 'cancelled' AND s.end_dt > NOW() ORDER BY s.start_dt"
    );
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = ['kind' => 'request', 'request_id' => $r['request_id'] !== null ? (int)$r['request_id'] : null, 'schedule_id' => (int)$r['schedule_id'],
            'text' => ($r['request_id'] ? 'Request #' . $r['request_id'] . ' ' : 'Trip ') . ((int)$r['leg_no'] === 2 ? 'return task' : 'task') . ': '
                    . fleetWhen($r['start_dt'], $r['end_dt']) . ' to ' . $r['destination'] . ($r['plate_number'] ? ' (' . $r['plate_number'] . ')' : '')];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT task_type, start_dt, end_dt FROM internal_tasks WHERE driver_id = ? AND status = 'active' AND end_dt > NOW() ORDER BY start_dt");
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = ['kind' => 'internal', 'request_id' => null, 'schedule_id' => null,
            'text' => 'Internal task (' . str_replace('_', ' ', $r['task_type']) . '): ' . fleetWhen($r['start_dt'], $r['end_dt'])];
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT duty_date FROM welfare_roster WHERE driver_id = ? AND duty_date > CURDATE() ORDER BY duty_date');
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = ['kind' => 'welfare', 'request_id' => null, 'schedule_id' => null, 'text' => 'Welfare duty on ' . date('d M Y', strtotime($r['duty_date']))];
    }
    $stmt->close();
    // today's welfare duty (24 h) also still counts
    $stmt = $conn->prepare('SELECT duty_date FROM welfare_roster WHERE driver_id = ? AND duty_date = CURDATE()');
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row()) {
        array_unshift($items, ['kind' => 'welfare', 'request_id' => null, 'schedule_id' => null, 'text' => 'Welfare duty today (' . date('d M Y') . ')']);
    }
    $stmt->close();
    return $items;
}

/** UC004: deactivate ($active=false) or reactivate a driver. @return array{ok:bool, message:string, items:array} */
function setDriverActive(mysqli $conn, int $driver_id, bool $active): array
{
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT name, status FROM drivers WHERE driver_id = ? FOR UPDATE');
        $stmt->bind_param('i', $driver_id);
        $stmt->execute();
        $d = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$d) { $conn->rollback(); return ['ok' => false, 'message' => 'Driver not found.', 'items' => []]; }
        if (!$active) {
            $items = driverUpcomingTasks($conn, $driver_id);
            if ($items) {
                $conn->rollback();
                return ['ok' => false, 'message' => $d['name'] . ' has upcoming tasks and cannot be deactivated. Please replace the driver on these tasks first.', 'items' => $items];
            }
        }
        $to = $active ? 'active' : 'inactive';
        $stmt = $conn->prepare('UPDATE drivers SET status = ? WHERE driver_id = ?');
        $stmt->bind_param('si', $to, $driver_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('setDriverActive: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Database error. Please try again.', 'items' => []];
    }
    auditLog($conn, $active ? 'driver_activate' : 'driver_deactivate', null, $d['name']);
    return ['ok' => true, 'message' => $d['name'] . ($active ? ' is active again.' : ' is now inactive.'), 'items' => []];
}

// ------------------------------------------------------------
// Output helpers
// ------------------------------------------------------------

/** A <ul> of the blocking / warning items. Request tasks of a driver link to the replace-driver page. */
function fleetItemsList(array $items, bool $withReplaceLink = false): string
{
    if (!$items) { return ''; }
    $html = '<ul class="mb-0 mt-2 small">';
    foreach ($items as $i) {
        $html .= '<li>' . htmlspecialchars($i['text']);
        if ($withReplaceLink && !empty($i['schedule_id']) && $i['kind'] === 'request') {
            $html .= ' <a href="replace_driver.php?schedule_id=' . (int)$i['schedule_id'] . '" class="fw-semibold">Replace driver</a>';
        }
        $html .= '</li>';
    }
    return $html . '</ul>';
}

/**
 * JSON endpoints: only the Administrative Assistant and the Head of Admin; POST only.
 * (The CSRF token is checked centrally by config/database.php.)
 */
function fleetAjaxGuard(): void
{
    header('Content-Type: application/json');
    if (!isLoggedIn() || !isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not allowed to do this.']);
        exit();
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Bad request.']);
        exit();
    }
}

/**
 * Script for the buttons <button data-fleet-url="..." data-fleet-id="N" [data-fleet-extra="k=v&k2=v2"] data-fleet-confirm="Question?">.
 * A refusal is shown inside <div id="fleetResult"> with the list of blocking items.
 */
function fleetActionScript(): void
{
    ?>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-fleet-url]');
    if (!b) { return; }
    e.preventDefault();
    var q = b.getAttribute('data-fleet-confirm');
    if (q && !window.confirm(q)) { return; }
    var body = new URLSearchParams(b.getAttribute('data-fleet-extra') || '');
    body.set('id', b.getAttribute('data-fleet-id'));
    b.disabled = true;
    fetch(b.getAttribute('data-fleet-url'), {method: 'POST', body: body, credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.success) { window.location.reload(); return; }
            b.disabled = false;
            var box = document.getElementById('fleetResult');
            if (!box) { window.alert(j.message); return; }
            box.innerHTML = '';
            var a = document.createElement('div');
            a.className = 'alert alert-danger';
            a.setAttribute('role', 'alert');
            a.appendChild(document.createTextNode(j.message));
            if (j.items && j.items.length) {
                var ul = document.createElement('ul'); ul.className = 'mb-0 mt-2 small';
                j.items.forEach(function (it) {
                    var li = document.createElement('li'); li.appendChild(document.createTextNode(it.text));
                    if (it.replace_url) { var l = document.createElement('a'); l.href = it.replace_url; l.className = 'fw-semibold ms-1'; l.textContent = 'Replace driver'; li.appendChild(l); }
                    ul.appendChild(li);
                });
                a.appendChild(ul);
            }
            box.appendChild(a);
            box.scrollIntoView({behavior: 'smooth', block: 'center'});
        })
        .catch(function () { b.disabled = false; window.alert('The request failed. Please try again.'); });
});
</script>
<?php
}

// ------------------------------------------------------------
// Vehicle form (UC005 Add / UC007 Update): one form, one validation
// ------------------------------------------------------------

const FUEL_TYPES = ['Petrol', 'Diesel', 'Electric', 'Hybrid'];

/** Cleans the posted vehicle fields. */
function vehicleInput(array $p): array
{
    return [
        'plate_number' => strtoupper(preg_replace('/\s+/', ' ', trim((string)($p['plate_number'] ?? '')))),
        'vehicle_type' => trim((string)($p['vehicle_type'] ?? '')),
        'brand'        => mb_substr(trim((string)($p['brand'] ?? '')), 0, 50),
        'model'        => mb_substr(trim((string)($p['model'] ?? '')), 0, 50),
        'year'         => trim((string)($p['year'] ?? '')),
        'capacity'     => trim((string)($p['capacity'] ?? '')),
        'fuel_type'    => trim((string)($p['fuel_type'] ?? 'Petrol')),
        'notes'        => mb_substr(trim((string)($p['notes'] ?? '')), 0, 1000),
    ];
}

/** @return array<string,string> field => message */
function validateVehicle(mysqli $conn, array $in, int $except_id = 0): array
{
    $e = [];
    if ($in['plate_number'] === '') { $e['plate_number'] = 'Enter the registration number.'; }
    elseif (!preg_match('/^[A-Z0-9 \-]{2,20}$/', $in['plate_number'])) { $e['plate_number'] = 'Use letters, digits, spaces and hyphens only (up to 20 characters).'; }
    else {
        $stmt = $conn->prepare('SELECT vehicle_id FROM vehicles WHERE plate_number = ? AND vehicle_id <> ?');
        $stmt->bind_param('si', $in['plate_number'], $except_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) { $e['plate_number'] = 'A vehicle with registration number ' . $in['plate_number'] . ' already exists.'; }
        $stmt->close();
    }
    if (!isset(VEHICLE_TYPES[$in['vehicle_type']])) { $e['vehicle_type'] = 'Choose the vehicle type.'; }
    if ($in['model'] === '') { $e['model'] = 'Enter the model.'; }
    if (!preg_match('/^\d{1,3}$/', $in['capacity']) || (int)$in['capacity'] < 1) { $e['capacity'] = 'Passenger capacity must be a whole number of 1 or more.'; }
    if ($in['year'] !== '' && (!preg_match('/^\d{4}$/', $in['year']) || (int)$in['year'] < 1980 || (int)$in['year'] > (int)date('Y') + 1)) { $e['year'] = 'Enter a valid year.'; }
    if (!in_array($in['fuel_type'], FUEL_TYPES, true)) { $e['fuel_type'] = 'Choose the fuel type.'; }
    return $e;
}

/** The form fields. $v = current values, $errors = messages, $photo = stored photo path (edit). */
function vehicleFormFields(array $v, array $errors, ?string $photo = null): void
{
    $err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . htmlspecialchars($errors[$k]) . '</div>' : '';
    $val = fn(string $k) => htmlspecialchars((string)($v[$k] ?? ''));
    $licByType = [];
    foreach (VEHICLE_TYPES as $t => $_) { $licByType[$t] = licenceForVehicleType($t); }
    ?>
    <div class="row g-3">
        <div class="col-12 col-md-6"><label class="form-label" for="plate_number">Registration number <span class="text-danger">*</span></label>
            <input class="form-control" id="plate_number" name="plate_number" maxlength="20" required value="<?= $val('plate_number') ?>" style="text-transform:uppercase"><?= $err('plate_number') ?></div>
        <div class="col-12 col-md-6"><label class="form-label" for="vehicle_type">Type <span class="text-danger">*</span></label>
            <select class="form-select" id="vehicle_type" name="vehicle_type" required><option value="">Choose...</option>
                <?php foreach (VEHICLE_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($v['vehicle_type'] ?? '') === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
            </select><?= $err('vehicle_type') ?></div>
        <div class="col-12 col-md-6"><label class="form-label" for="model">Model <span class="text-danger">*</span></label>
            <input class="form-control" id="model" name="model" maxlength="50" required value="<?= $val('model') ?>"><?= $err('model') ?></div>
        <div class="col-6 col-md-3"><label class="form-label" for="capacity">Passenger capacity <span class="text-danger">*</span></label>
            <input type="number" min="1" max="999" class="form-control" id="capacity" name="capacity" required value="<?= $val('capacity') ?>"><?= $err('capacity') ?></div>
        <div class="col-6 col-md-3"><label class="form-label" for="licence_shown">Licence required</label>
            <input class="form-control" id="licence_shown" readonly tabindex="-1" aria-describedby="licHelp" value="<?= htmlspecialchars(isset(VEHICLE_TYPES[$v['vehicle_type'] ?? '']) ? licenceForVehicleType($v['vehicle_type']) : '-') ?>">
            <div class="form-text" id="licHelp">Set by the type: Car/Van D, Lorry GDL, Bus PSV.</div></div>
        <div class="col-12"><a class="small text-decoration-none" data-bs-toggle="collapse" href="#moreFields" role="button"><i class="fas fa-chevron-down me-1"></i>More details (optional)</a></div>
        <div class="col-12 collapse <?= ($v['brand'] ?? '') . ($v['year'] ?? '') . ($v['notes'] ?? '') !== '' || isset($errors['year']) || isset($errors['fuel_type']) ? 'show' : '' ?>" id="moreFields"><div class="row g-3">
            <div class="col-12 col-md-4"><label class="form-label" for="brand">Brand</label><input class="form-control" id="brand" name="brand" maxlength="50" value="<?= $val('brand') ?>"></div>
            <div class="col-6 col-md-4"><label class="form-label" for="year">Year</label><input class="form-control" id="year" name="year" inputmode="numeric" maxlength="4" value="<?= $val('year') ?>"><?= $err('year') ?></div>
            <div class="col-6 col-md-4"><label class="form-label" for="fuel_type">Fuel</label>
                <select class="form-select" id="fuel_type" name="fuel_type"><?php foreach (FUEL_TYPES as $f): ?><option <?= ($v['fuel_type'] ?? 'Petrol') === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select><?= $err('fuel_type') ?></div>
            <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000"><?= $val('notes') ?></textarea></div>
            <div class="col-12"><label class="form-label" for="photo">Photo</label>
                <?php if ($photo): ?><div class="mb-1"><img src="<?= htmlspecialchars(vehiclePhotoUrl($photo)) ?>" alt="Vehicle photo" style="max-height:80px" class="rounded border"></div><?php endif; ?>
                <input type="file" class="form-control" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"><?= $err('photo') ?>
                <div class="form-text">JPG, PNG or WebP, up to 2 MB.</div></div>
        </div></div>
    </div>
    <script>
    (function () {
        var map = <?= json_encode($licByType) ?>, t = document.getElementById('vehicle_type'), l = document.getElementById('licence_shown');
        t.addEventListener('change', function () { l.value = map[t.value] || '-'; });
    })();
    </script>
    <?php
}

// ------------------------------------------------------------
// Driver form fields shared by Add (UC001) and Update (UC003)
// ------------------------------------------------------------

/** Cleans and checks licence classes, licence number / expiry, address (and phone when $withPhone). @return array{0:array,1:array} [values, errors] */
function driverInput(array $p, bool $withPhone, bool $rejectPastExpiry = false, bool $requireJoined = false): array
{
    $classes = $p['license_class'] ?? [];
    $v = [
        'license_class'  => normaliseLicenceList(is_array($classes) ? $classes : []),
        'license_number' => mb_substr(trim((string)($p['license_number'] ?? '')), 0, 50),
        'license_expiry' => trim((string)($p['license_expiry'] ?? '')),
        'date_joined'    => trim((string)($p['date_joined'] ?? '')),
        'address'        => mb_substr(trim((string)($p['address'] ?? '')), 0, 500),
        'phone'          => mb_substr(trim((string)($p['phone'] ?? '')), 0, 20),
    ];
    $e = [];
    if ($v['license_class'] === '') { $e['license_class'] = 'Tick at least one licence class (D, GDL or PSV).'; }
    if ($v['license_expiry'] !== '' && !isValidDate($v['license_expiry'])) { $e['license_expiry'] = 'Enter a valid date.'; }
    elseif ($rejectPastExpiry && $v['license_expiry'] !== '' && $v['license_expiry'] < date('Y-m-d')) { $e['license_expiry'] = 'The licence expiry date has already passed.'; }   // UC003 exception 3.1
    if ($requireJoined) {      // UC001 step 3 / UC003 A1: the experience in the driver recommendation is calculated from this date
        if ($v['date_joined'] === '') { $e['date_joined'] = 'Enter the date the driver joined the Vehicle Section.'; }
        elseif (!isValidDate($v['date_joined']) || $v['date_joined'] > date('Y-m-d')) { $e['date_joined'] = 'Enter a valid date that is not in the future.'; }
    }
    if ($withPhone && $v['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $v['phone'])) { $e['phone'] = 'Enter a valid phone number.'; }
    return [$v, $e];
}

/** Licence ticks + optional fields. */
function driverFormFields(array $v, array $errors, bool $withPhone, ?string $photo = null, bool $withJoined = false): void
{
    $err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . htmlspecialchars($errors[$k]) . '</div>' : '';
    $val = fn(string $k) => htmlspecialchars((string)($v[$k] ?? ''));
    $have = array_filter(explode(',', (string)($v['license_class'] ?? '')));
    ?>
    <div class="row g-3">
        <div class="col-12"><span class="form-label d-block">Licence classes <span class="text-danger">*</span></span>
            <?php foreach (LICENCE_CLASSES as $k => $l): ?>
                <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="license_class[]" id="lc_<?= $k ?>" value="<?= $k ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="lc_<?= $k ?>"><?= htmlspecialchars($l) ?></label></div>
            <?php endforeach; ?><?= $err('license_class') ?></div>
        <?php if ($withPhone): ?>
        <div class="col-12 col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="20" value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
        <?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label" for="license_number">Licence number</label><input class="form-control" id="license_number" name="license_number" maxlength="50" value="<?= $val('license_number') ?>"></div>
        <div class="col-12 col-md-6"><label class="form-label" for="license_expiry">Licence expiry date</label><input type="date" class="form-control" id="license_expiry" name="license_expiry" value="<?= $val('license_expiry') ?>"><?= $err('license_expiry') ?></div>
        <?php if ($withJoined): ?>
        <div class="col-12 col-md-6"><label class="form-label" for="date_joined">Date joined the Vehicle Section <span class="text-danger">*</span></label><input type="date" class="form-control" id="date_joined" name="date_joined" max="<?= date('Y-m-d') ?>" value="<?= $val('date_joined') ?>"><?= $err('date_joined') ?>
            <div class="form-text">Used to calculate the experience in the driver recommendation.</div></div>
        <?php endif; ?>
        <div class="col-12"><label class="form-label" for="address">Address</label><textarea class="form-control" id="address" name="address" rows="2" maxlength="500"><?= $val('address') ?></textarea></div>
        <div class="col-12"><label class="form-label" for="photo">Photo</label>
            <?php if ($photo): ?><div class="mb-1"><img src="<?= htmlspecialchars(driverPhotoUrl($photo)) ?>" alt="Driver photo" style="max-height:80px" class="rounded border"></div><?php endif; ?>
            <input type="file" class="form-control" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"><?= $err('photo') ?>
            <div class="form-text">JPG, PNG or WebP, up to 2 MB.</div></div>
    </div>
    <?php
}
