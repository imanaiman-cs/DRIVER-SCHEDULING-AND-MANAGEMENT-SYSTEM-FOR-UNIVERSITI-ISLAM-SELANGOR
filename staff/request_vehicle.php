<?php
// ============================================================
// UIS Driver Scheduling and Management System
// staff/request_vehicle.php  -  UC010 Submit Vehicle Request
//
// Every role is also Staff, so every role can open this page.
//   1. the requester enters departure date + time, return date + time (all mandatory) and the passengers
//   2. the system shows ONLY vehicles that are free in that time period (not locked, not under maintenance or
//      inactive) and big enough
//   3. timing rule (SRS 10 Nov): less than 2 days ahead -> the request is accepted and marked URGENT (a warning is shown);
//      the departure must be in the future. There is no paper form any more.
//   4. the chosen vehicle is locked for departure -> return (transaction + SELECT ... FOR UPDATE, so two people
//      submitting at the same moment cannot both get it)
// The request goes to the approver of THIS account (a staff member -> their Head of Department;
// a Head of Department -> his own head).
// ============================================================
$current_page = 'request_vehicle.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/upload.php';
requireRequester();

$staff_id  = (int)$_SESSION['user_id'];
$full_name = (string)($_SESSION['full_name'] ?? '');

// The approver (read fresh from the database: the staff system may have changed it)
$q = $conn->prepare("SELECT u.supervisor_id, u.phone FROM users u JOIN users a ON a.user_id = u.supervisor_id
                      WHERE u.user_id = ? AND a.role = 'supervisor' AND a.is_active = 1");
$q->bind_param('i', $staff_id);
$q->execute();
$me = $q->get_result()->fetch_assoc();
$q->close();
$approver_id = $me ? (int)$me['supervisor_id'] : null;
if (!$me) {
    $q = $conn->prepare('SELECT phone FROM users WHERE user_id = ?');
    $q->bind_param('i', $staff_id);
    $q->execute();
    $me = $q->get_result()->fetch_assoc() ?: ['phone' => ''];
    $q->close();
}

$canSelfDrive = canSelfDriveLorry($conn, $staff_id);   // UC018 A2: Self Drive is only for a lorry and a driver with the GDL licence
$errors = [];
$f = ['depart_date' => '', 'depart_time' => '', 'return_date' => '', 'return_time' => '', 'passengers' => '', 'vehicle_type' => '', 'quantity' => '1',
      'destination' => '', 'destination_address' => '', 'purpose' => '', 'passenger_names' => '', 'phone' => (string)($me['phone'] ?? ''),
      'waiting_place' => WAITING_LOCATIONS[0], 'waiting_address' => '', 'need_driver' => '1', 'request_type' => 'uis', 'own_vehicle_plate' => '', 'purpose_type' => 'official', 'distance_km' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) { $f[$k] = trim((string)($_POST[$k] ?? $f[$k])); }
    $pax = (int)$f['passengers'];
    $qty = (int)$f['quantity'];
    $payClaim = $f['request_type'] === 'pay_claim';   // own vehicle, claim the cost
    $rental = !$payClaim && $f['purpose_type'] === PURPOSE_RENTAL;   // UC018 A4: Personal Rental of a lorry, charged per km
    $km = (float)str_replace(',', '.', $f['distance_km']);
    if ($rental) {
        if ($f['vehicle_type'] !== 'Lorry') { $errors[] = 'Personal Rental is only for a lorry.'; }
        if ($km <= 0 || $km > 5000) { $errors[] = 'Enter the round-trip distance in kilometres (1 to 5000).'; }
        $f['need_driver'] = '1';
        if ($f['purpose'] === '') { $f['purpose'] = 'Personal Rental of a lorry'; }
        if ($f['passenger_names'] === '') { $f['passenger_names'] = $full_name; }
    }
    $needDriver = $payClaim || $f['need_driver'] === '0' ? 0 : 1;   // Without driver = Self Drive (or Pay and Claim)
    if (!$payClaim && $needDriver === 0 && !($f['vehicle_type'] === 'Lorry' && $canSelfDrive)) {
        $errors[] = 'Self Drive is only for a lorry and only if you are a driver with a GDL licence. Choose With driver.';
        $needDriver = 1;
    }
    $f['own_vehicle_plate'] = strtoupper(preg_replace('/\s+/', ' ', $f['own_vehicle_plate']));

    if (!$approver_id && !$rental) {   // a Personal Rental has no Head of Department: the Assistant, then the Head of Admin
        $errors[] = 'No approver is set for your account. Please contact the Vehicle Section.';
    }
    if (!isValidDate($f['depart_date']) || !isValidTime($f['depart_time']) || !isValidDate($f['return_date']) || !isValidTime($f['return_time'])) {
        $errors[] = 'Departure date and time and return date and time are all required.';
    }
    if ($pax < 1 || $pax > 100) { $errors[] = 'Enter the number of passengers (1 to 100).'; }
    $start = $end = '';
    if (!$errors) {
        $start = $f['depart_date'] . ' ' . timeWithSeconds($f['depart_time']);
        $end   = $f['return_date'] . ' ' . timeWithSeconds($f['return_time']);
        if (strtotime($end) <= strtotime($start)) { $errors[] = 'The return must be after the departure.'; }
    }
    if (!$errors && strtotime($start) <= time()) { $errors[] = 'The departure must be in the future.'; }
    // Timing rule: less than 2 days ahead is accepted and marked Urgent (SRS 10 Nov, UC018 A5)
    $urgent = 0;
    if (!$errors) {
        $urgent = requestTimingRule($start)['level'] === 'urgent' ? 1 : 0;
    }
    if ($f['destination'] === '' || mb_strlen($f['destination']) > 255) { $errors[] = 'Destination name is required (max 255 characters).'; }
    if ($f['destination_address'] === '' || mb_strlen($f['destination_address']) > 500) { $errors[] = 'The full destination address is required (max 500 characters).'; }
    if (!isset(PURPOSE_TYPES[$f['purpose_type']]) && !$rental) { $errors[] = 'Choose the purpose type (Official or Unofficial).'; }
    if (mb_strlen($f['purpose']) < 10) { $errors[] = 'Please describe the purpose (at least 10 characters).'; }
    if ($f['passenger_names'] === '') { $errors[] = 'Passenger names are required.'; }
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $f['phone'])) { $errors[] = 'Enter a valid phone number (digits, spaces, + - and brackets, 7 to 20 characters).'; }
    if (!in_array($f['waiting_place'], WAITING_LOCATIONS, true)) { $errors[] = 'Choose the waiting location.'; }
    if ($f['waiting_place'] === 'Other' && ($f['waiting_address'] === '' || mb_strlen($f['waiting_address']) > 255)) { $errors[] = 'Enter the address of the waiting location.'; }
    if ($payClaim) {
        $f['vehicle_type'] = ''; $qty = 1;                                    // no UIS vehicle, nothing is reserved
        if (!preg_match('/^[A-Z0-9][A-Z0-9 \-]{1,19}$/', $f['own_vehicle_plate'])) { $errors[] = 'Enter the registration number of your own vehicle (letters, digits, spaces and hyphens, up to 20 characters).'; }
    } elseif (!isset(VEHICLE_TYPES[$f['vehicle_type']])) { $errors[] = 'Choose a vehicle type.';
    } elseif ($qty < 1 || $qty > 20) { $errors[] = 'Enter the number of vehicles (1 to 20).'; }

    // (the quantity of the type is checked and reserved inside the transaction below: first submitted, first served)

    // Documents: PDF, JPG or PNG, at most 5 MB each (checked by content on the server)
    $files = collectUploadedFiles('docs');
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'The form was too large and was not received. Each document must be 5 MB or smaller.';
    }
    if (!$files && !$rental) { $errors[] = 'The supporting letter is required (PDF, JPG or PNG, up to 5 MB).'; }   // UC018 exception 3.2
    if (count($files) > MAX_SUPPORTING_DOCUMENTS) { $errors[] = 'You can attach at most ' . MAX_SUPPORTING_DOCUMENTS . ' documents.'; }
    $saved = [];
    if (!$errors) {
        foreach ($files as $file) {
            $res = saveUploadedDocument($file, MAX_DOCUMENT_BYTES);
            if (empty($res['ok'])) {
                foreach ($saved as $d) { deleteStoredDocument($d['path']); }
                $saved = [];
                $errors[] = 'Document "' . mb_strimwidth((string)($file['name'] ?? ''), 0, 60, '...') . '": ' . ($res['error'] ?? 'it could not be uploaded.');
                break;
            }
            $saved[] = ['name' => (string)$res['original_name'], 'path' => (string)$res['path'], 'mime' => (string)$res['mime'], 'size' => (int)$res['size']];
        }
    }

    if (!$errors) {
        $inTx = false;
        try {
            $conn->begin_transaction();
            $inTx = true;
            $wait_addr = $f['waiting_place'] === 'Other' ? $f['waiting_address'] : null;
            $stmt = $conn->prepare(
                "INSERT INTO vehicle_requests (staff_id, vehicle_id, trip_date, start_time, return_date, end_time, destination, destination_address, purpose,
                                               passenger_count, need_driver, passenger_names, officer_name, officer_phone, waiting_place, waiting_address, supervisor_id, request_type, own_vehicle_plate, is_urgent, purpose_type, vehicle_type, quantity, distance_km, charge)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $st_time = timeWithSeconds($f['depart_time']);
            $rt_time = timeWithSeconds($f['return_time']);
            $typeOrNull = $payClaim ? null : $f['vehicle_type'];
            $reqType = $payClaim ? 'pay_claim' : 'uis';
            $plateOrNull = $payClaim ? $f['own_vehicle_plate'] : null;
            $kmOrNull = $rental ? $km : null;
            $chargeOrNull = $rental ? rentalCharge($conn, $km) : null;
            $approverOrNull = $rental ? null : $approver_id;
            $stmt->bind_param('isssssssiisssssississidd', $staff_id, $f['depart_date'], $st_time, $f['return_date'], $rt_time,
                $f['destination'], $f['destination_address'], $f['purpose'], $pax, $needDriver, $f['passenger_names'], $full_name, $f['phone'],
                $f['waiting_place'], $wait_addr, $approverOrNull, $reqType, $plateOrNull, $urgent, $f['purpose_type'], $typeOrNull, $qty, $kmOrNull, $chargeOrNull);
            $stmt->execute();
            $rid = (int)$stmt->insert_id;
            $stmt->close();

            if (!$payClaim) { reserveVehicleType($conn, $rid, $f['vehicle_type'], $qty, $start, $end, $pax); }   // atomic: refuses when the last vehicle of the type was just taken
            if ($saved) {
                $docType = 'approval_letter';
                $ds = $conn->prepare("INSERT INTO request_documents (request_id, doc_type, original_name, stored_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($saved as $d) {
                    $ds->bind_param('issssi', $rid, $docType, $d['name'], $d['path'], $d['mime'], $d['size']);
                    $ds->execute();
                }
                $ds->close();
            }
            $conn->commit();
            $inTx = false;

            if ($rental) {                                                  // UC019 A1: the Assistant is told instead of the Head of Department
                $charge = rentalCharge($conn, $km);
                auditLog($conn, 'submit', $rid, 'Personal Rental request submitted (' . $km . ' km, estimated charge RM ' . number_format($charge, 2) . ')' . ($urgent ? ' - Urgent' : ''));
                $mailRes = notifyUsers($conn, assistantUserIds($conn), 'New ' . ($urgent ? 'URGENT ' : '') . "Personal Rental request #$rid from $full_name to {$f['destination']} (" . date('d M Y H:i', strtotime($start)) . ', ' . $km . ' km, estimated charge RM ' . number_format($charge, 2) . ') is waiting for you.', 'admin/vehicle_requests.php?tab=pending');
                markApprovalEmail($conn, $rid, $mailRes);
                setFlash('success', 'Request #' . $rid . ' submitted. Estimated charge: RM ' . number_format($charge, 2) . ' (' . $km . ' km x RM ' . number_format((float)getSetting($conn, 'RENTAL_RATE_PER_KM', '3.00'), 2) . '). It goes to the Administrative Assistant, then the Head of Admin.');
                header('Location: my_requests.php');
                exit();
            }
            auditLog($conn, 'submit', $rid, ($payClaim ? 'Pay and Claim request submitted (own vehicle ' . $f['own_vehicle_plate'] . ')' : 'Request submitted') . ($urgent ? ' - Urgent' : ''));
            $mailRes = notifyUsers($conn, [$approver_id],
                "New " . ($urgent ? 'URGENT ' : '') . ($payClaim ? 'Pay and Claim' : 'vehicle') . " request #$rid from $full_name to {$f['destination']} (" . date('d M Y H:i', strtotime($start)) . ') is waiting for your decision.',
                'supervisor/approvals.php');
            markApprovalEmail($conn, $rid, $mailRes);                  // UC019: record when the e-mail was sent
            setFlash('success', 'Request #' . $rid . ' submitted. It is waiting for the approval of your Head of Department' . ($payClaim ? ', then the Head of Admin.' : '.'));
            header('Location: my_requests.php');
            exit();
        } catch (Throwable $ex) {
            if ($inTx) { try { $conn->rollback(); } catch (Throwable $e2) {} }
            foreach ($saved as $d) { deleteStoredDocument($d['path']); }
            $errors[] = ($ex instanceof RuntimeException && str_starts_with($ex->getMessage(), 'Sorry,'))
                ? $ex->getMessage()
                : 'The request could not be saved. Please try again.';
            if (!($ex instanceof RuntimeException)) { error_log('request_vehicle: ' . $ex->getMessage()); }
        }
    }
}

adminPageStart('Request a Vehicle');
?>
    <h4 class="fw-bold mb-3"><i class="fas fa-car-side text-success me-2"></i>Request a Vehicle</h4>
    <?php if (!$approver_id): ?>
        <div class="alert alert-warning">No approver is set for your account, so a request cannot be submitted. Please contact the Vehicle Section.</div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="requestForm" novalidate>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6 class="fw-bold">Type of request</h6>
            <div class="form-check"><input class="form-check-input" type="radio" name="request_type" id="rt_uis" value="uis" <?= $f['request_type'] !== 'pay_claim' ? 'checked' : '' ?>>
                <label class="form-check-label" for="rt_uis"><strong>UIS vehicle</strong> <span class="text-muted">with a driver, or without a driver (you drive it yourself: self drive)</span></label></div>
            <div class="form-check"><input class="form-check-input" type="radio" name="request_type" id="rt_pc" value="pay_claim" <?= $f['request_type'] === 'pay_claim' ? 'checked' : '' ?>>
                <label class="form-check-label" for="rt_pc"><strong>Pay and Claim</strong> <span class="text-muted">you use your own car and claim the cost. No UIS vehicle or driver is booked. Approved by your Head of Department, then the Head of Admin.</span></label></div>
            <div id="ownVehicleRow" class="mt-3" style="display:none;"><label class="form-label small" for="own_vehicle_plate">Registration number of your own vehicle *</label>
                <input class="form-control" id="own_vehicle_plate" name="own_vehicle_plate" maxlength="20" style="max-width:16rem;text-transform:uppercase" value="<?= htmlspecialchars($f['own_vehicle_plate']) ?>"></div>
        </div></div>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6 class="fw-bold">1. When and how many</h6>
            <div class="row g-3">
                <div class="col-6 col-md-3"><label class="form-label small" for="depart_date">Departure date *</label>
                    <input type="date" class="form-control" id="depart_date" name="depart_date" value="<?= htmlspecialchars($f['depart_date']) ?>" required></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="depart_time">Departure time *</label>
                    <input type="time" class="form-control" id="depart_time" name="depart_time" value="<?= htmlspecialchars($f['depart_time']) ?>" required></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="return_date">Return date *</label>
                    <input type="date" class="form-control" id="return_date" name="return_date" value="<?= htmlspecialchars($f['return_date']) ?>" required></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="return_time">Return time *</label>
                    <input type="time" class="form-control" id="return_time" name="return_time" value="<?= htmlspecialchars($f['return_time']) ?>" required></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="passengers">Number of passengers *</label>
                    <input type="number" class="form-control" id="passengers" name="passengers" min="1" max="100" value="<?= htmlspecialchars($f['passengers']) ?>" required></div>
                <div class="col-12 col-md-6" id="needDriverRow" data-can-self-drive="<?= $canSelfDrive ? '1' : '0' ?>"><label class="form-label small d-block">Do you need a driver? * <span class="text-muted">(Self Drive: lorry, GDL drivers only)</span></label>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="need_driver" id="nd1" value="1" <?= $f['need_driver'] !== '0' ? 'checked' : '' ?>><label class="form-check-label" for="nd1">With driver</label></div>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="need_driver" id="nd0" value="0" <?= $f['need_driver'] === '0' ? 'checked' : '' ?>><label class="form-check-label" for="nd0">Without driver</label></div>
                    <div class="form-text">Without a driver, no driver is assigned. The approval steps and the vehicle booking stay the same.</div></div>
            </div>
            <div id="timingNotice" class="mt-3" style="display:none;"></div>
        </div></div>

        <div class="card border-0 shadow-sm mb-3" id="vehicleCard"><div class="card-body">
            <h6 class="fw-bold">2. Choose a vehicle type <span class="fw-normal text-muted small">(the Vehicle Section chooses the exact vehicle; the type is reserved from departure until 1 hour after the return)</span></h6>
            <div id="vehicleMsg" class="text-muted small">Enter the departure, the return and the number of passengers to see the available vehicle types.</div>
            <div id="vehicleList" class="row g-2 mt-1"></div>
            <div class="row g-3 mt-1"><div class="col-6 col-md-3"><label class="form-label small" for="quantity">Number of vehicles *</label>
                <input type="number" class="form-control" id="quantity" name="quantity" min="1" max="20" value="<?= htmlspecialchars($f['quantity']) ?>"></div></div>
        </div></div>

        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6 class="fw-bold">3. Trip details</h6>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label small" for="destination">Destination (name) *</label>
                    <input class="form-control" id="destination" name="destination" maxlength="255" value="<?= htmlspecialchars($f['destination']) ?>" required></div>
                <div class="col-md-6"><label class="form-label small" for="destination_address">Destination (full address) *</label>
                    <input class="form-control" id="destination_address" name="destination_address" maxlength="500" value="<?= htmlspecialchars($f['destination_address']) ?>" required></div>
                <div class="col-12"><label class="form-label small d-block">Purpose type *</label>
                    <?php foreach (PURPOSE_TYPES as $k => $lbl): ?><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="purpose_type" id="pt_<?= $k ?>" value="<?= $k ?>" <?= $f['purpose_type'] === $k ? 'checked' : '' ?>><label class="form-check-label" for="pt_<?= $k ?>"><?= $lbl ?></label></div><?php endforeach; ?>
                    <div class="form-check form-check-inline" id="ptRentalWrap" style="display:none;"><input class="form-check-input" type="radio" name="purpose_type" id="pt_personal_rental" value="personal_rental" <?= $f['purpose_type'] === 'personal_rental' ? 'checked' : '' ?>><label class="form-check-label" for="pt_personal_rental">Personal Rental</label></div>
                    <div id="rentalRow" class="mt-2" style="display:none;"><label class="form-label small" for="distance_km">Round-trip distance (km) *</label>
                        <input type="number" step="0.1" min="0.1" max="5000" class="form-control" id="distance_km" name="distance_km" style="max-width:12rem" value="<?= htmlspecialchars($f['distance_km']) ?>">
                        <div class="form-text" id="rentalCharge">Charge: RM <?= htmlspecialchars(number_format((float)getSetting($conn, 'RENTAL_RATE_PER_KM', '3.00'), 2)) ?> per kilometre of the round trip. No supporting letter and no Head of Department: the Administrative Assistant confirms the distance, then the Head of Admin approves.</div></div>
                    <div class="form-text">Official: an official university programme. Unofficial: a programme that is not official. Both need a supporting letter.</div></div>
                <div class="col-12"><label class="form-label small" for="purpose">Purpose *</label>
                    <textarea class="form-control" id="purpose" name="purpose" rows="3" required><?= htmlspecialchars($f['purpose']) ?></textarea></div>
                <div class="col-12"><label class="form-label small" for="passenger_names">Passenger names *</label>
                    <textarea class="form-control" id="passenger_names" name="passenger_names" rows="2" required><?= htmlspecialchars($f['passenger_names']) ?></textarea></div>
                <div class="col-md-4"><label class="form-label small" for="phone">Phone number *</label>
                    <input class="form-control" id="phone" name="phone" maxlength="20" value="<?= htmlspecialchars($f['phone']) ?>" required></div>
                <div class="col-md-4"><label class="form-label small" for="waiting_place">Waiting location *</label>
                    <select class="form-select" id="waiting_place" name="waiting_place">
                        <?php foreach (WAITING_LOCATIONS as $w): ?><option <?= $f['waiting_place'] === $w ? 'selected' : '' ?>><?= htmlspecialchars($w) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-4" id="otherRow" style="display:none;"><label class="form-label small" for="waiting_address">Address of the waiting location *</label>
                    <input class="form-control" id="waiting_address" name="waiting_address" maxlength="255" value="<?= htmlspecialchars($f['waiting_address']) ?>"></div>
                <div class="col-12"><label class="form-label small" for="docs">Supporting letter * <span class="text-muted">(PDF, JPG or PNG, up to 5 MB each, at most <?= MAX_SUPPORTING_DOCUMENTS ?>)</span></label>
                    <input type="file" class="form-control" id="docs" name="docs[]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple required></div>
            </div>
        </div></div>

        <input type="hidden" name="vehicle_type" id="vehicle_type" value="<?= htmlspecialchars($f['vehicle_type']) ?>">
        <button class="btn btn-success" type="submit" id="submitBtn"><i class="fas fa-paper-plane me-1"></i>Submit request</button>
    </form>

<script>
(function () {
    var ids = ['depart_date', 'depart_time', 'return_date', 'return_time', 'passengers'];
    var el = function (i) { return document.getElementById(i); };
    var chosen = <?= json_encode($f['vehicle_type']) ?>;
    var timer = null, seq = 0;
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }

    function search() {
        var q = new URLSearchParams({ depart_date: el('depart_date').value, depart_time: el('depart_time').value,
            return_date: el('return_date').value, return_time: el('return_time').value, passengers: el('passengers').value, quantity: el('quantity').value || '1' });
        if (ids.some(function (i) { return !el(i).value; })) { return; }
        var mine = ++seq;
        fetch('<?= SITE_URL ?>/ajax/get_vehicle_types.php?' + q.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (mine !== seq) { return; }
                var notice = el('timingNotice'), msg = el('vehicleMsg'), list = el('vehicleList');
                list.innerHTML = '';
                notice.style.display = 'none';
                if (!d.success) { msg.textContent = d.message; return; }
                if (d.timing === 'urgent') {
                    notice.className = 'alert alert-warning mt-3'; notice.textContent = d.notice; notice.style.display = '';
                }
                msg.textContent = 'Choose the type of vehicle. The number shows how many are free for your trip.';
                d.types.forEach(function (t) {
                    var col = document.createElement('div');
                    col.className = 'col-6 col-md-4 col-lg-2';
                    col.innerHTML = '<label class="card h-100 p-2 text-center' + (t.enough ? '' : ' bg-light text-muted') + '" style="cursor:' + (t.enough ? 'pointer' : 'not-allowed') + ';">' +
                        '<input class="form-check-input mx-auto" type="radio" name="vehicle_choice" value="' + esc(t.type) + '"' + (t.enough ? '' : ' disabled') + (chosen === t.type ? ' checked' : '') + '>' +
                        '<span class="fw-semibold">' + esc(t.label) + '</span>' +
                        '<span class="small">' + (t.enough ? t.free + ' free' : (t.free === 0 ? 'none free' : (t.can_seat ? 'only ' + t.free + ' free' : 'too few seats'))) + '</span></label>';
                    var inp = col.querySelector('input');
                    inp.addEventListener('change', function () { chosen = t.type; el('vehicle_type').value = t.type; syncType(); });
                    list.appendChild(col);
                });
                if (!d.types.some(function (t) { return t.type === chosen && t.enough; })) { chosen = ''; el('vehicle_type').value = ''; }
                syncType();
            })
            .catch(function () { el('vehicleMsg').textContent = 'Could not load the vehicle types. Please try again.'; });
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(search, 250); }
    ids.concat(['quantity']).forEach(function (i) { el(i).addEventListener('change', schedule); el(i).addEventListener('input', schedule); });

    function syncType() {                       // Pay and Claim: no UIS vehicle and no driver choice, but the own vehicle
        var pc = el('rt_pc') && el('rt_pc').checked;
        el('vehicleCard').style.display = pc ? 'none' : '';
        var sd = el('needDriverRow').getAttribute('data-can-self-drive') === '1' && el('vehicle_type').value === 'Lorry';
        el('needDriverRow').style.display = (!pc && sd) ? '' : 'none';
        if (!sd) { el('nd1').checked = true; }                       // Self Drive only for a lorry and a GDL driver
        el('ownVehicleRow').style.display = pc ? '' : 'none';
        var lorry = !pc && el('vehicle_type').value === 'Lorry', rent = lorry && el('pt_personal_rental').checked;
        el('ptRentalWrap').style.display = lorry ? '' : 'none';
        if (!lorry && el('pt_personal_rental').checked) { el('pt_official').checked = true; rent = false; }
        el('rentalRow').style.display = rent ? '' : 'none';
        el('docs').required = !rent;
        updateCharge();
    }
    var rate = <?= json_encode((float)getSetting($conn, 'RENTAL_RATE_PER_KM', '3.00')) ?>;
    function updateCharge() {
        var km = parseFloat(el('distance_km').value);
        el('rentalCharge').textContent = (km > 0 ? 'Estimated charge: RM ' + (km * rate).toFixed(2) + ' (' + km + ' km x RM ' + rate.toFixed(2) + '). ' : 'Charge: RM ' + rate.toFixed(2) + ' per kilometre of the round trip. ') +
            'No supporting letter and no Head of Department: the Administrative Assistant confirms the distance, then the Head of Admin approves.';
    }
    el('distance_km').addEventListener('input', updateCharge);
    document.querySelectorAll('input[name=purpose_type]').forEach(function (r) { r.addEventListener('change', syncType); });
    ['rt_uis', 'rt_pc'].forEach(function (i) { if (el(i)) { el(i).addEventListener('change', syncType); } });
    syncType();

    var wp = el('waiting_place');
    function syncOther() { el('otherRow').style.display = wp.value === 'Other' ? '' : 'none'; }
    wp.addEventListener('change', syncOther); syncOther();
    search();
})();
</script>
<?php adminPageEnd(); ?>
