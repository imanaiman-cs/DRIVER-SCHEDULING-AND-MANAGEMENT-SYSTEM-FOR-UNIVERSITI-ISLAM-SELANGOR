<?php
// ============================================================
// UIS Driver Scheduling and Management System
// staff/request_vehicle.php  -  UC010 Submit Vehicle Request
//
// Every role is also Staff, so every role can open this page.
//   1. the requester enters departure date + time, return date + time (all mandatory) and the passengers
//   2. the system shows ONLY vehicles that are free in that time period (not locked, not under maintenance or
//      inactive) and big enough
//   3. timing rule: less than 1 day ahead -> refused ("Please use the paper form BPTK/BPI/2025/PIN.5"),
//      less than 2 days ahead -> a warning that must be confirmed
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

$errors = [];
$f = ['depart_date' => '', 'depart_time' => '', 'return_date' => '', 'return_time' => '', 'passengers' => '', 'vehicle_id' => '',
      'destination' => '', 'destination_address' => '', 'purpose' => '', 'passenger_names' => '', 'phone' => (string)($me['phone'] ?? ''),
      'waiting_place' => WAITING_LOCATIONS[0], 'waiting_address' => '', 'confirm_short' => '', 'need_driver' => '1'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) { $f[$k] = trim((string)($_POST[$k] ?? $f[$k])); }
    $pax = (int)$f['passengers'];
    $vehicle_id = (int)$f['vehicle_id'];
    $needDriver = $f['need_driver'] === '0' ? 0 : 1;      // UC010 A3: Without Driver

    if (!$approver_id) {
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
    // Timing rule: < 1 day refused (nothing is saved), < 2 days needs the confirmation
    if (!$errors) {
        $timing = requestTimingRule($start);
        if ($timing['level'] === 'reject') {
            $errors[] = $timing['message'];
        } elseif ($timing['level'] === 'warn' && $f['confirm_short'] !== '1') {
            $errors[] = $timing['message'] . ' (tick the confirmation box).';
        }
    }
    if ($f['destination'] === '' || mb_strlen($f['destination']) > 255) { $errors[] = 'Destination name is required (max 255 characters).'; }
    if ($f['destination_address'] === '' || mb_strlen($f['destination_address']) > 500) { $errors[] = 'The full destination address is required (max 500 characters).'; }
    if (mb_strlen($f['purpose']) < 10) { $errors[] = 'Please describe the purpose (at least 10 characters).'; }
    if ($f['passenger_names'] === '') { $errors[] = 'Passenger names are required.'; }
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $f['phone'])) { $errors[] = 'Enter a valid phone number (digits, spaces, + - and brackets, 7 to 20 characters).'; }
    if (!in_array($f['waiting_place'], WAITING_LOCATIONS, true)) { $errors[] = 'Choose the waiting location.'; }
    if ($f['waiting_place'] === 'Other' && ($f['waiting_address'] === '' || mb_strlen($f['waiting_address']) > 255)) { $errors[] = 'Enter the address of the waiting location.'; }
    if ($vehicle_id <= 0) { $errors[] = 'Choose a vehicle.'; }

    // The vehicle must still be in the list of free vehicles for this period
    if (!$errors) {
        $freeIds = array_map('intval', array_column(availableVehicles($conn, $start, $end, $pax), 'vehicle_id'));
        if (!in_array($vehicle_id, $freeIds, true)) {
            $errors[] = 'That vehicle is not available for the period you chose (locked by another request, under maintenance, inactive or too small). Please choose another one.';
        }
    }

    // Documents: PDF, JPG or PNG, at most 5 MB each (checked by content on the server)
    $files = collectUploadedFiles('docs');
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'The form was too large and was not received. Each document must be 5 MB or smaller.';
    }
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
                                               passenger_count, need_driver, passenger_names, officer_name, officer_phone, waiting_place, waiting_address, supervisor_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $st_time = timeWithSeconds($f['depart_time']);
            $rt_time = timeWithSeconds($f['return_time']);
            $stmt->bind_param('iisssssssiisssssi', $staff_id, $vehicle_id, $f['depart_date'], $st_time, $f['return_date'], $rt_time,
                $f['destination'], $f['destination_address'], $f['purpose'], $pax, $needDriver, $f['passenger_names'], $full_name, $f['phone'],
                $f['waiting_place'], $wait_addr, $approver_id);
            $stmt->execute();
            $rid = (int)$stmt->insert_id;
            $stmt->close();

            lockVehiclePeriod($conn, $rid, $vehicle_id, $start, $end);     // atomic: refuses a vehicle that was just taken

            if ($saved) {
                $ds = $conn->prepare("INSERT INTO request_documents (request_id, doc_type, original_name, stored_path, mime_type, file_size) VALUES (?, 'other', ?, ?, ?, ?)");
                foreach ($saved as $d) {
                    $ds->bind_param('isssi', $rid, $d['name'], $d['path'], $d['mime'], $d['size']);
                    $ds->execute();
                }
                $ds->close();
            }
            $conn->commit();
            $inTx = false;

            auditLog($conn, 'submit', $rid, 'Request submitted');
            notifyUsers($conn, [$approver_id],
                "New vehicle request #$rid from $full_name to {$f['destination']} (" . date('d M Y H:i', strtotime($start)) . ') is waiting for your decision.',
                'supervisor/approvals.php');
            setFlash('success', 'Request #' . $rid . ' submitted. It is waiting for the approval of your Head of Department.');
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
                <div class="col-12 col-md-6"><label class="form-label small d-block">Do you need a driver? *</label>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="need_driver" id="nd1" value="1" <?= $f['need_driver'] !== '0' ? 'checked' : '' ?>><label class="form-check-label" for="nd1">With driver</label></div>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="need_driver" id="nd0" value="0" <?= $f['need_driver'] === '0' ? 'checked' : '' ?>><label class="form-check-label" for="nd0">Without driver</label></div>
                    <div class="form-text">Without a driver, no driver is assigned. The approval steps and the vehicle booking stay the same.</div></div>
            </div>
            <div id="timingNotice" class="mt-3" style="display:none;"></div>
            <div id="confirmRow" class="form-check mt-2" style="display:none;">
                <input class="form-check-input" type="checkbox" id="confirm_short" name="confirm_short" value="1" <?= $f['confirm_short'] === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="confirm_short">I understand and want to continue.</label>
            </div>
        </div></div>

        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6 class="fw-bold">2. Choose a vehicle <span class="fw-normal text-muted small">(only vehicles that are free in that period and big enough are shown)</span></h6>
            <div id="vehicleMsg" class="text-muted small">Enter the departure, the return and the number of passengers to see the available vehicles.</div>
            <div id="vehicleList" class="row g-2 mt-1"></div>
        </div></div>

        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6 class="fw-bold">3. Trip details</h6>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label small" for="destination">Destination (name) *</label>
                    <input class="form-control" id="destination" name="destination" maxlength="255" value="<?= htmlspecialchars($f['destination']) ?>" required></div>
                <div class="col-md-6"><label class="form-label small" for="destination_address">Destination (full address) *</label>
                    <input class="form-control" id="destination_address" name="destination_address" maxlength="500" value="<?= htmlspecialchars($f['destination_address']) ?>" required></div>
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
                <div class="col-12"><label class="form-label small" for="docs">Supporting documents <span class="text-muted">(PDF, JPG or PNG, up to 5 MB each, at most <?= MAX_SUPPORTING_DOCUMENTS ?>)</span></label>
                    <input type="file" class="form-control" id="docs" name="docs[]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple></div>
            </div>
        </div></div>

        <input type="hidden" name="vehicle_id" id="vehicle_id" value="<?= htmlspecialchars($f['vehicle_id']) ?>">
        <button class="btn btn-success" type="submit" id="submitBtn"><i class="fas fa-paper-plane me-1"></i>Submit request</button>
    </form>

<script>
(function () {
    var ids = ['depart_date', 'depart_time', 'return_date', 'return_time', 'passengers'];
    var el = function (i) { return document.getElementById(i); };
    var chosen = <?= json_encode((int)$f['vehicle_id']) ?>;
    var timer = null, seq = 0;
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }

    function search() {
        var q = new URLSearchParams({ depart_date: el('depart_date').value, depart_time: el('depart_time').value,
            return_date: el('return_date').value, return_time: el('return_time').value, passengers: el('passengers').value });
        if (ids.some(function (i) { return !el(i).value; })) { return; }
        var mine = ++seq;
        fetch('<?= SITE_URL ?>/ajax/get_free_vehicles.php?' + q.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (mine !== seq) { return; }
                var notice = el('timingNotice'), confirmRow = el('confirmRow'), msg = el('vehicleMsg'), list = el('vehicleList');
                list.innerHTML = '';
                notice.style.display = 'none'; confirmRow.style.display = 'none';
                if (!d.success) { msg.textContent = d.message; return; }
                if (d.timing === 'reject') {
                    notice.className = 'alert alert-danger mt-3'; notice.textContent = d.notice; notice.style.display = '';
                    msg.textContent = 'This request cannot be submitted online.'; return;
                }
                if (d.timing === 'warn') {
                    notice.className = 'alert alert-warning mt-3'; notice.textContent = d.notice; notice.style.display = ''; confirmRow.style.display = '';
                }
                msg.textContent = d.vehicles.length ? d.vehicles.length + ' vehicle(s) available for this period.' : 'No vehicle is available for this period. Try other times or fewer passengers.';
                d.vehicles.forEach(function (v) {
                    var col = document.createElement('div');
                    col.className = 'col-12 col-md-6 col-lg-4';
                    col.innerHTML = '<label class="card h-100 p-2" style="cursor:pointer;"><div class="form-check">' +
                        '<input class="form-check-input" type="radio" name="vehicle_choice" value="' + v.vehicle_id + '"' + (chosen === v.vehicle_id ? ' checked' : '') + '>' +
                        '<span class="fw-semibold ms-1">' + esc(v.plate_number) + '</span></div>' +
                        '<div class="small text-muted ms-4">' + esc(v.vehicle_type) + ' &middot; ' + esc(v.model) + ' &middot; ' + v.capacity + ' seats &middot; licence ' + esc(v.license_required) + '</div></label>';
                    col.querySelector('input').addEventListener('change', function () { chosen = v.vehicle_id; el('vehicle_id').value = v.vehicle_id; });
                    list.appendChild(col);
                });
                if (!d.vehicles.some(function (v) { return v.vehicle_id === chosen; })) { chosen = 0; el('vehicle_id').value = ''; }
            })
            .catch(function () { el('vehicleMsg').textContent = 'Could not load the vehicles. Please try again.'; });
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(search, 250); }
    ids.forEach(function (i) { el(i).addEventListener('change', schedule); el(i).addEventListener('input', schedule); });

    var wp = el('waiting_place');
    function syncOther() { el('otherRow').style.display = wp.value === 'Other' ? '' : 'none'; }
    wp.addEventListener('change', syncOther); syncOther();
    search();
})();
</script>
<?php adminPageEnd(); ?>
