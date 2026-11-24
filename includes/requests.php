<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/requests.php  -  the vehicle request (SRS UC003, UC017-UC021, UC025, UC026)
//
// Status flow (stored value -> label)
//   With Driver        pending -> hod_approved -> driver_assigned -> approved -> completed
//   Pay and Claim /    pending -> hod_approved -> approved (Head of Admin, UC025 A2) -> completed
//   Self Drive
//   Personal (lorry)   pending -> (Administrative Assistant assigns the driver) driver_assigned -> approved
//                      pending -> (Self Drive: Assistant confirms the charge) -> approved (Head of Admin)
//   any active status  -> cancelled (UC021, reason required)       pending -> rejected (Head of Department)
//   pending            -> lapsed (no Head of Department decision within 2 days, or the departure passed)
//   driver_assigned    -> hod_approved / pending (the Head of Admin returns the assignment, UC026 A1)
//
// Vehicles are reserved when the request is submitted (first submitted, first served), from the departure time
// until one hour after the return time. The reservation is released when the request is rejected, cancelled or
// lapses. Pay and Claim reserves nothing. Missing buses are marked Vendor Bus Needed.
// ============================================================

// ------------------------------------------------------------
// Loading
// ------------------------------------------------------------

/** The SELECT used for one or many requests (requester, department, approver names). */
function requestSelectSql(): string
{
    return "SELECT r.*, u.full_name AS requester_name, u.staff_no AS requester_staff_no, u.phone AS requester_phone, u.email AS requester_email,
                   u.role AS requester_role, d.department_name, ap.full_name AS approver_name,
                   hb.full_name AS hod_approved_name, ha.full_name AS hoa_approved_name, rb.full_name AS rejected_by_name, cb.full_name AS cancelled_by_name
              FROM requests r
              JOIN users u ON u.user_id = r.requester_id
              LEFT JOIN departments d ON d.department_id = r.department_id
              LEFT JOIN users ap ON ap.user_id = r.approver_id
              LEFT JOIN users hb ON hb.user_id = r.hod_approved_by
              LEFT JOIN users ha ON ha.user_id = r.hoa_approved_by
              LEFT JOIN users rb ON rb.user_id = r.rejected_by
              LEFT JOIN users cb ON cb.user_id = r.cancelled_by";
}

function loadRequest(mysqli $conn, int $id, bool $forUpdate = false): ?array
{
    return dbOne($conn, requestSelectSql() . ' WHERE r.request_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
}

/** The vehicles a request reserved (with vehicle details), in order. */
function requestVehicles(mysqli $conn, int $request_id): array
{
    return dbAll($conn, 'SELECT rv.*, v.plate_number, v.brand, v.model, v.vehicle_type, v.licence_required, v.capacity
                           FROM request_vehicles rv LEFT JOIN vehicles v ON v.vehicle_id = rv.vehicle_id
                          WHERE rv.request_id = ? ORDER BY rv.is_vendor, rv.rv_id', [$request_id]);
}

/** The tasks of a request (with driver and vehicle). */
function requestTasks(mysqli $conn, int $request_id): array
{
    return dbAll($conn, "SELECT t.*, u.full_name AS driver_name, u.phone AS driver_phone, v.plate_number, v.brand, v.model
                           FROM tasks t LEFT JOIN drivers dr ON dr.driver_id = t.driver_id LEFT JOIN users u ON u.user_id = dr.user_id
                           LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                          WHERE t.request_id = ? AND t.status <> 'cancelled' ORDER BY t.rv_id, t.start_dt", [$request_id]);
}

/** "Bus x 2, With Driver" */
function requestVehicleSummary(array $r): string
{
    return $r['vehicle_type'] . ((int)$r['quantity'] > 1 ? ' x ' . (int)$r['quantity'] : '') . ', ' . driverOptionLabel($r['driver_option']);
}

/**
 * Can the signed-in user see this request? Requester, the approving Head of Department (or the Head of Department
 * of the request's department), the Administrative Assistant and Head of Admin, and a driver with an approved task on it.
 */
function requestVisibleTo(mysqli $conn, array $r, array $me): bool
{
    $uid = (int)$me['user_id'];
    if ((int)$r['requester_id'] === $uid) { return true; }
    if (in_array($me['role'], ['admin', 'head_admin'], true)) { return true; }
    if ($me['role'] === 'supervisor' && ((int)$r['approver_id'] === $uid || ((int)$r['department_id'] === (int)$me['department_id']))) { return true; }
    if ($me['role'] === 'driver' && !empty($me['driver_id'])) {
        return (bool)dbValue($conn, "SELECT 1 FROM tasks WHERE request_id = ? AND driver_id = ? AND status IN ('approved','completed')", [(int)$r['request_id'], (int)$me['driver_id']]);
    }
    return false;
}

// ------------------------------------------------------------
// Availability and reservation
// ------------------------------------------------------------

/** End of a reservation: the return time + RESERVATION_BUFFER_HOURS. */
function reservationEnd(mysqli $conn, string $return_at): string
{
    return date('Y-m-d H:i:s', strtotime($return_at) + (int)round(getSettingFloat($conn, 'RESERVATION_BUFFER_HOURS') * 3600));
}

/**
 * Why a vehicle cannot be used in [$start, $end), or null when it is free.
 * Overlap rule everywhere: start < other_end AND end > other_start.
 */
function vehicleBusyReason(mysqli $conn, int $vehicle_id, string $start, string $end, int $exceptRequest = 0, int $exceptTask = 0): ?string
{
    $v = dbOne($conn, 'SELECT status FROM vehicles WHERE vehicle_id = ?', [$vehicle_id]);
    if (!$v) { return 'unknown vehicle'; }
    if ($v['status'] === 'maintenance') { return 'under maintenance'; }
    if ($v['status'] === 'inactive') { return 'inactive'; }
    $r = dbOne($conn, "SELECT rv.request_id FROM request_vehicles rv WHERE rv.vehicle_id = ? AND rv.active = 1 AND rv.request_id <> ?
                          AND rv.start_dt < ? AND rv.end_dt > ? LIMIT 1", [$vehicle_id, $exceptRequest, $end, $start]);
    if ($r) { return 'reserved for request #' . $r['request_id']; }
    $t = dbOne($conn, "SELECT task_id, task_source FROM tasks WHERE vehicle_id = ? AND task_source IN ('internal','welfare') AND status <> 'cancelled'
                          AND task_id <> ? AND start_dt < ? AND end_dt > ? LIMIT 1", [$vehicle_id, $exceptTask, $end, $start]);
    if ($t) { return $t['task_source'] === 'welfare' ? 'reserved for welfare duty' : 'reserved for an internal task'; }
    return null;
}

/** Active vehicles of a type that are free in [$start, $end). */
function freeVehicles(mysqli $conn, ?string $type, string $start, string $end, int $exceptRequest = 0, int $exceptTask = 0, bool $lock = false): array
{
    $sql = "SELECT v.* FROM vehicles v WHERE v.status = 'active'" . ($type ? ' AND v.vehicle_type = ?' : '') . "
              AND NOT EXISTS (SELECT 1 FROM request_vehicles rv WHERE rv.vehicle_id = v.vehicle_id AND rv.active = 1 AND rv.request_id <> ? AND rv.start_dt < ? AND rv.end_dt > ?)
              AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.vehicle_id = v.vehicle_id AND t.task_source IN ('internal','welfare') AND t.status <> 'cancelled'
                              AND t.task_id <> ? AND t.start_dt < ? AND t.end_dt > ?)
            ORDER BY v.capacity, v.plate_number" . ($lock ? ' FOR UPDATE' : '');
    $p = $type ? [$type] : [];
    array_push($p, $exceptRequest, $end, $start, $exceptTask, $end, $start);
    return dbAll($conn, $sql, $p);
}

/** UC018 step 2.2: the units left of each vehicle type for the trip period. */
function unitsLeftByType(mysqli $conn, string $depart, string $return): array
{
    $end = reservationEnd($conn, $return);
    $out = array_fill_keys(VEHICLE_TYPES, 0);
    foreach (freeVehicles($conn, null, $depart, $end) as $v) { $out[$v['vehicle_type']]++; }
    return $out;
}

/** Releases the reservations of a request. */
function releaseReservation(mysqli $conn, int $request_id): void
{
    dbExec($conn, 'UPDATE request_vehicles SET active = 0 WHERE request_id = ?', [$request_id]);
}

/** Is the request Urgent (departure within URGENT_HOURS)? */
function isUrgentDeparture(mysqli $conn, string $depart, ?int $from = null): bool
{
    return strtotime($depart) - ($from ?? time()) < getSettingInt($conn, 'URGENT_HOURS') * 3600;
}

// ------------------------------------------------------------
// UC018 Submit Vehicle Request
// ------------------------------------------------------------

/** Which driver options a user may pick for a vehicle type (UC018 A1, A2). */
function driverOptionsFor(array $me, string $type): array
{
    $opts = ['with_driver'];
    if ($type === 'Car') { $opts[] = 'pay_claim'; }
    if ($type === 'Lorry' && $me['role'] === 'driver' && driverHoldsLicence($me['licence_classes'] ?? '', 'GDL')
        && !empty($me['licence_expiry']) && $me['licence_expiry'] >= date('Y-m-d')) {
        $opts[] = 'self_drive';
    }
    return $opts;
}

/** Which purpose types a vehicle type allows (Personal for lorries only). */
function purposeTypesFor(string $type): array
{
    return $type === 'Lorry' ? ['official', 'unofficial', 'personal'] : ['official', 'unofficial'];
}

/**
 * Checks the trip period. Returns [errors, depart, return].
 */
function checkTripPeriod(string $dDate, string $dTime, string $rDate, string $rTime): array
{
    $errors = [];
    $depart = joinDateTime($dDate, $dTime);
    $return = joinDateTime($rDate, $rTime);
    if (!$depart) { $errors['depart'] = 'Enter a valid departure date and time.'; }
    if (!$return) { $errors['return'] = 'Enter a valid return date and time.'; }
    if ($depart && strtotime($depart) <= time()) { $errors['depart'] = 'The departure date and time must be in the future.'; }
    if ($depart && $return && strtotime($return) <= strtotime($depart)) { $errors['return'] = 'The return date and time must be later than the departure date and time.'; }
    return [$errors, $depart, $return];
}

/**
 * Saves a new request (UC018 step 4). $in holds the form values, $file the supporting letter ($_FILES entry) or null.
 * @return array ['ok', 'message', 'errors' => [field => text], 'request_id']
 */
function submitRequest(mysqli $conn, array $me, array $in, ?array $file): array
{
    $errors = [];
    [$pe, $depart, $return] = checkTripPeriod($in['depart_date'] ?? '', $in['depart_time'] ?? '', $in['return_date'] ?? '', $in['return_time'] ?? '');
    $errors += $pe;
    $type = (string)($in['vehicle_type'] ?? '');
    if (!in_array($type, VEHICLE_TYPES, true)) { $errors['vehicle_type'] = 'Select a vehicle type.'; }
    $option = (string)($in['driver_option'] ?? 'with_driver');
    if ($type && !in_array($option, driverOptionsFor($me, $type), true)) { $errors['driver_option'] = 'This option is not available for the selected vehicle type.'; }
    $qty = max(1, (int)($in['quantity'] ?? 1));
    if ($option !== 'with_driver') { $qty = 1; }
    if ($qty > 20) { $errors['quantity'] = 'Enter a quantity of 20 or less.'; }
    $ptype = (string)($in['purpose_type'] ?? '');
    if ($type && !in_array($ptype, purposeTypesFor($type), true)) { $errors['purpose_type'] = 'Select the purpose type.'; }
    $purpose     = trim((string)($in['purpose'] ?? ''));
    $destination = trim((string)($in['destination'] ?? ''));
    $address     = trim((string)($in['address'] ?? ''));
    $waiting     = trim((string)($in['waiting_location'] ?? ''));
    $passengers  = (int)($in['passengers'] ?? 0);
    foreach (['purpose' => $purpose, 'destination' => $destination, 'address' => $address, 'waiting_location' => $waiting] as $k => $v) {
        if ($v === '') { $errors[$k] = 'This field is required.'; }
    }
    if ($passengers < 1) { $errors['passengers'] = 'Enter the number of passengers.'; }
    $distance = null; $charge = null;
    if ($ptype === 'personal') {
        $distance = (float)($in['distance_km'] ?? 0);
        if ($distance <= 0) { $errors['distance_km'] = 'Enter the round-trip distance in kilometres.'; }
        $charge = round($distance * getSettingFloat($conn, 'CHARGE_PER_KM'), 2);
    }
    $hasFile = $file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (in_array($ptype, ['official', 'unofficial'], true) && !$hasFile) { $errors['document'] = 'The supporting letter is required.'; }

    // Who approves: the Head of Department (parent department for a Head of Department); a Personal request goes to the Assistant.
    $approver = null;
    if ($ptype !== 'personal' && !$errors) {
        $approver = approverFor($conn, $me);
        if (!$approver) {
            $errors['general'] = ($me['role'] === 'supervisor' ? 'The parent department has no Head of Department in the system yet.' : 'Your department has no Head of Department in the system yet.')
                               . ' Please contact the Vehicle Section.';
        }
    }
    if ($errors) { return wfFail('Please correct the highlighted fields.', ['errors' => $errors]); }

    $doc = null;
    if ($hasFile) {
        $doc = saveUploadedDocument($file, MAX_DOCUMENT_BYTES);
        if (!$doc['ok']) { return wfFail($doc['error'], ['errors' => ['document' => $doc['error']]]); }
    }

    $resEnd = reservationEnd($conn, $return);
    $urgent = isUrgentDeparture($conn, $depart) ? 1 : 0;
    $hours  = getSettingFloat($conn, 'TASK_DURATION_HOURS');
    $uid    = (int)$me['user_id'];
    $dept   = $me['department_id'] !== null ? (int)$me['department_id'] : null;

    $conn->begin_transaction();
    try {
        $reserve = [];
        $vendor  = 0;
        if ($option !== 'pay_claim') {
            // lock the vehicles of the type, then look again: two people cannot get the same vehicle
            $free = freeVehicles($conn, $type, $depart, $resEnd, 0, 0, true);
            $free = freeVehicles($conn, $type, $depart, $resEnd);
            if (count($free) < $qty) {
                if ($type !== 'Bus') {
                    $conn->rollback();
                    if ($doc) { deleteStoredDocument($doc['path']); }
                    return wfFail('The vehicles are no longer available. Please select the vehicle again.', ['errors' => ['vehicle_type' => 'Not enough vehicles are available (' . count($free) . ' left).'], 'restart' => true]);
                }
                $vendor = $qty - count($free);
            }
            $reserve = array_slice($free, 0, $qty - $vendor);
        }
        [, $id] = dbExec($conn, 'INSERT INTO requests (requester_id, department_id, approver_id, vehicle_type, quantity, driver_option, purpose_type, purpose, destination, address,
                                   waiting_location, depart_at, return_at, passengers, distance_km, charge, is_urgent, task_hours)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, $dept, $approver, $type, $qty, $option, $ptype, mb_substr($purpose, 0, 500), mb_substr($destination, 0, 200), mb_substr($address, 0, 500),
             mb_substr($waiting, 0, 200), $depart, $return, $passengers, $distance, $charge, $urgent, $hours]);
        foreach ($reserve as $v) {
            dbExec($conn, 'INSERT INTO request_vehicles (request_id, vehicle_id, start_dt, end_dt) VALUES (?, ?, ?, ?)', [$id, (int)$v['vehicle_id'], $depart, $resEnd]);
        }
        for ($i = 0; $i < $vendor; $i++) {
            dbExec($conn, 'INSERT INTO request_vehicles (request_id, vehicle_id, is_vendor, start_dt, end_dt) VALUES (?, NULL, 1, ?, ?)', [$id, $depart, $resEnd]);
        }
        if ($doc) {
            dbExec($conn, 'INSERT INTO request_documents (request_id, doc_type, original_name, stored_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, 'supporting_letter', $doc['original_name'], $doc['path'], $doc['mime'], (int)$doc['size']]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        if ($doc) { deleteStoredDocument($doc['path']); }
        error_log('submitRequest: ' . $e->getMessage());
        return wfFail('The request could not be saved. Please try again.', ['errors' => ['general' => 'Database error.']]);
    }

    addHistory($conn, $id, 'submitted', 'pending', $urgent ? 'Urgent request' : null);
    $req = loadRequest($conn, $id);
    sendApprovalEmail($conn, $req, true);
    $msg = 'Your request #' . $id . ' was submitted' . ($ptype === 'personal' ? ' to the Administrative Assistant.' : ' to ' . $req['approver_name'] . ' for approval.');
    if ($vendor) { $msg .= " $vendor bus(es) will be hired from a vendor."; }
    return wfOk($msg, ['request_id' => $id]);
}

// ------------------------------------------------------------
// UC019 Send Approval Email, UC020 Send Approval Reminder Email
// ------------------------------------------------------------

/** Who must act on a new request: the Head of Department, or the Administrative Assistants for a Personal request. */
function approvalRecipients(mysqli $conn, array $r): array
{
    return $r['purpose_type'] === 'personal' ? assistantUserIds($conn) : ($r['approver_id'] ? [(int)$r['approver_id']] : []);
}

function requestOneLine(array $r): string
{
    return '#' . $r['request_id'] . ' from ' . $r['requester_name'] . ($r['department_name'] ? ' (' . $r['department_name'] . ')' : '')
         . ': ' . requestVehicleSummary($r) . ' to ' . $r['destination'] . ', ' . fmtPeriod($r['depart_at'], $r['return_at']);
}

/**
 * UC019: e-mail (and, the first time, an in-system notification) to the approver. The time is recorded when the
 * e-mail did not fail; a failed e-mail is tried again by the hourly job.
 */
function sendApprovalEmail(mysqli $conn, array $r, bool $withNotification): void
{
    $to = approvalRecipients($conn, $r);
    if (!$to) { return; }
    $msg = ($r['is_urgent'] ? 'URGENT. ' : '') . ($r['purpose_type'] === 'personal' ? 'New Personal lorry request ' : 'New vehicle request waiting for your approval ')
         . requestOneLine($r) . '. Purpose: ' . $r['purpose'] . '.';
    $link = 'staff/request.php?id=' . (int)$r['request_id'];
    $failed = 0;
    if ($withNotification) {
        $res = notifyUsers($conn, $to, $msg, $link, 'approval');
        $failed = $res['failed'];
    } else {
        foreach ($to as $uid) {
            $u = loadUser($conn, $uid);
            if ($u && sendUserEmail($conn, $u, '[UIS Vehicle Section] Vehicle request #' . $r['request_id'], $msg, $link, 'approval')['status'] === 'failed') { $failed++; }
        }
    }
    if (!$failed) { dbExec($conn, 'UPDATE requests SET approval_email_at = NOW() WHERE request_id = ?', [(int)$r['request_id']]); }
}

// ------------------------------------------------------------
// UC025 Approve/Reject Vehicle Request
// ------------------------------------------------------------

/** Is the request waiting for this Head of Department? */
function waitingForHod(array $r, int $uid): bool
{
    return $r['status'] === 'pending' && $r['purpose_type'] !== 'personal' && (int)$r['approver_id'] === $uid;
}

/** Is the request waiting for the Head of Admin's approval as a request (UC025 A2)? */
function waitingForHoaRequest(array $r): bool
{
    return ($r['status'] === 'hod_approved' && in_array($r['driver_option'], ['pay_claim', 'self_drive'], true))
        || ($r['status'] === 'pending' && $r['purpose_type'] === 'personal' && $r['driver_option'] === 'self_drive' && !empty($r['charge_confirmed_at']));
}

/** Is the request waiting for the Administrative Assistant (UC003)? */
function waitingForAssignment(array $r): bool
{
    return ($r['status'] === 'hod_approved' && $r['driver_option'] === 'with_driver')
        || ($r['status'] === 'pending' && $r['purpose_type'] === 'personal' && empty($r['charge_confirmed_at']));
}

/** The SQL conditions of the three queues (table alias r). */
const SQL_WAITING_ASSIGNMENT = "((r.status = 'hod_approved' AND r.driver_option = 'with_driver') OR (r.status = 'pending' AND r.purpose_type = 'personal' AND r.charge_confirmed_at IS NULL))";
const SQL_WAITING_HOA = "(r.status = 'driver_assigned' OR (r.status = 'hod_approved' AND r.driver_option IN ('pay_claim','self_drive'))
                          OR (r.status = 'pending' AND r.purpose_type = 'personal' AND r.driver_option = 'self_drive' AND r.charge_confirmed_at IS NOT NULL))";

function hodDecide(mysqli $conn, int $id, bool $approve, string $reason = ''): array
{
    $uid = (int)$_SESSION['user_id'];
    $reason = trim($reason);
    $conn->begin_transaction();
    $r = loadRequest($conn, $id, true);
    if (!$r || !waitingForHod($r, $uid)) { $conn->rollback(); return wfFail('This request is not waiting for your approval.'); }
    if (!$approve && $reason === '') { $conn->rollback(); return wfFail('The reason is required.'); }
    if ($approve) {
        dbExec($conn, "UPDATE requests SET status = 'hod_approved', hod_approved_at = NOW(), hod_approved_by = ? WHERE request_id = ?", [$uid, $id]);
        $conn->commit();
        addHistory($conn, $id, 'hod_approved', 'hod_approved', $reason !== '' ? $reason : null);
        notifyUsers($conn, [(int)$r['requester_id']], "Your vehicle request #$id was approved by the Head of Department.", 'staff/request.php?id=' . $id);
        if ($r['driver_option'] === 'with_driver') {
            notifyUsers($conn, assistantUserIds($conn), ($r['is_urgent'] ? 'URGENT. ' : '') . 'Request ' . requestOneLine($r) . ' is waiting for driver assignment.', 'admin/assign_driver.php?id=' . $id);
        } else {
            notifyUsers($conn, headAdminUserIds($conn), 'Request ' . requestOneLine($r) . ' (' . driverOptionLabel($r['driver_option']) . ') is waiting for your approval.', 'admin/approvals.php');
        }
        return wfOk("Request #$id was approved.");
    }
    dbExec($conn, "UPDATE requests SET status = 'rejected', reject_reason = ?, rejected_at = NOW(), rejected_by = ? WHERE request_id = ?", [mb_substr($reason, 0, 500), $uid, $id]);
    releaseReservation($conn, $id);
    $conn->commit();
    addHistory($conn, $id, 'rejected', 'rejected', $reason);
    notifyUsers($conn, [(int)$r['requester_id']], "Your vehicle request #$id was rejected by the Head of Department. Reason: $reason", 'staff/request.php?id=' . $id);
    return wfOk("Request #$id was rejected and the vehicle reservation was released.");
}

/** UC025 A2: the Head of Admin approves or rejects a Pay and Claim, Self Drive or Personal Self Drive request. */
function hoaDecideRequest(mysqli $conn, int $id, bool $approve, string $reason = ''): array
{
    $uid = (int)$_SESSION['user_id'];
    $reason = trim($reason);
    $conn->begin_transaction();
    $r = loadRequest($conn, $id, true);
    if (!$r || !waitingForHoaRequest($r)) { $conn->rollback(); return wfFail('This request is not waiting for your approval.'); }
    if (!$approve && $reason === '') { $conn->rollback(); return wfFail('The reason is required.'); }
    if ($approve) {
        dbExec($conn, "UPDATE requests SET status = 'approved', hoa_approved_at = NOW(), hoa_approved_by = ? WHERE request_id = ?", [$uid, $id]);
        $conn->commit();
        addHistory($conn, $id, 'approved', 'approved');
        $letter = in_array($r['driver_option'], ['pay_claim'], true) || $r['purpose_type'] === 'personal' ? ' You can print the approval letter from the request.' : '';
        notifyUsers($conn, [(int)$r['requester_id']], "Your vehicle request #$id was approved by the Head of Admin.$letter", 'staff/request.php?id=' . $id);
        notifyUsers($conn, assistantUserIds($conn), "Request #$id (" . driverOptionLabel($r['driver_option']) . ') was approved by the Head of Admin.', 'staff/request.php?id=' . $id);
        return wfOk("Request #$id was approved.");
    }
    dbExec($conn, "UPDATE requests SET status = 'rejected', reject_reason = ?, rejected_at = NOW(), rejected_by = ? WHERE request_id = ?", [mb_substr($reason, 0, 500), $uid, $id]);
    releaseReservation($conn, $id);
    $conn->commit();
    addHistory($conn, $id, 'rejected', 'rejected', $reason);
    notifyUsers($conn, [(int)$r['requester_id']], "Your vehicle request #$id was rejected by the Head of Admin. Reason: $reason", 'staff/request.php?id=' . $id);
    return wfOk("Request #$id was rejected.");
}

// ------------------------------------------------------------
// UC003 Assign Request Task
// ------------------------------------------------------------

/** The outbound and return task windows of a request for a task length of $hours. */
function requestTaskWindows(array $r, float $hours): array
{
    $len = (int)round($hours * 3600);
    $d = strtotime($r['depart_at']); $ret = strtotime($r['return_at']);
    return [
        'outbound' => [date('Y-m-d H:i:s', $d), date('Y-m-d H:i:s', min($d + $len, $ret))],
        'return'   => [date('Y-m-d H:i:s', $ret), date('Y-m-d H:i:s', $ret + $len)],
    ];
}

/**
 * Saves the driver assignment of a request (UC003 step 5).
 *   $plan = [rv_id => ['outbound' => driver_id, 'return' => driver_id, 'vendor_name' => .., 'vendor_plate' => ..], ...]
 *   $hours = task length; $distance = corrected distance (Personal) or null.
 */
function assignRequest(mysqli $conn, int $id, array $plan, float $hours, ?float $distance = null): array
{
    $uid = (int)$_SESSION['user_id'];
    if ($hours < 0.5 || $hours > 24) { return wfFail('The task duration must be between 0.5 and 24 hours.'); }
    $conn->begin_transaction();
    try {
        $r = loadRequest($conn, $id, true);
        if (!$r || !waitingForAssignment($r)) { $conn->rollback(); return wfFail('This request is not waiting for driver assignment.'); }
        if ($r['purpose_type'] === 'personal' && $distance !== null) {
            if ($distance <= 0) { $conn->rollback(); return wfFail('Enter a valid distance.'); }
            if (abs($distance - (float)$r['distance_km']) > 0.001) {
                $charge = round($distance * getSettingFloat($conn, 'CHARGE_PER_KM'), 2);
                dbExec($conn, 'UPDATE requests SET distance_km = ?, charge = ? WHERE request_id = ?', [$distance, $charge, $id]);
                addHistory($conn, $id, 'distance_corrected', null, 'Distance ' . $r['distance_km'] . ' km corrected to ' . $distance . ' km (' . money($charge) . ')');
            }
        }
        // A4 Personal Self Drive: no driver, the charge goes to the Head of Admin
        if ($r['purpose_type'] === 'personal' && $r['driver_option'] === 'self_drive') {
            dbExec($conn, 'UPDATE requests SET charge_confirmed_at = NOW(), assigned_by = ? WHERE request_id = ?', [$uid, $id]);
            $conn->commit();
            addHistory($conn, $id, 'charge_confirmed', null);
            notifyUsers($conn, headAdminUserIds($conn), 'Personal Self Drive request ' . requestOneLine(loadRequest($conn, $id)) . ' is waiting for your approval.', 'admin/approvals.php');
            return wfOk("The charge of request #$id was confirmed and sent to the Head of Admin.");
        }

        $win = requestTaskWindows($r, $hours);
        $rvs = requestVehicles($conn, $id);
        $used = [];   // driver_id => [[start, end], ...] inside this assignment
        $rows = [];
        foreach ($rvs as $rv) {
            if (!$rv['active']) { continue; }
            $p = $plan[(int)$rv['rv_id']] ?? [];
            if ($rv['is_vendor']) {
                $vn = trim((string)($p['vendor_name'] ?? '')); $vp = strtoupper(trim((string)($p['vendor_plate'] ?? '')));
                if ($vn === '' || $vp === '') { $conn->rollback(); return wfFail('Enter the vendor name and bus plate number for each vendor bus.'); }
                dbExec($conn, 'UPDATE request_vehicles SET vendor_name = ?, vendor_plate = ? WHERE rv_id = ?', [mb_substr($vn, 0, 150), mb_substr($vp, 0, 20), (int)$rv['rv_id']]);
                continue;
            }
            foreach (['outbound', 'return'] as $leg) {
                $did = (int)($p[$leg] ?? 0);
                if ($did <= 0) { $conn->rollback(); return wfFail('Select a driver for the ' . $leg . ' trip of ' . $rv['plate_number'] . '.'); }
                [$s, $e] = $win[$leg];
                $why = driverUnavailableReason($conn, $did, $rv['licence_required'], $s, $e, $id);
                if ($why) { $conn->rollback(); return wfFail(driverName($conn, $did) . " cannot take the $leg trip of {$rv['plate_number']}: $why."); }
                foreach ($used[$did] ?? [] as [$us, $ue]) {
                    if ($s < $ue && $e > $us) { $conn->rollback(); return wfFail(driverName($conn, $did) . ' is selected for two tasks at the same time.'); }
                }
                $used[$did][] = [$s, $e];
                $rows[] = [$leg, (int)$rv['rv_id'], (int)$rv['vehicle_id'], $did, $s, $e];
            }
        }
        dbExec($conn, "DELETE FROM tasks WHERE request_id = ? AND task_source = 'request'", [$id]);
        foreach ($rows as [$leg, $rvId, $vid, $did, $s, $e]) {
            dbExec($conn, "INSERT INTO tasks (task_source, task_type, trip_type, request_id, rv_id, vehicle_id, driver_id, start_dt, end_dt, status, created_by)
                           VALUES ('request', 'trip', ?, ?, ?, ?, ?, ?, ?, 'driver_assigned', ?)", [$leg, $id, $rvId, $vid, $did, $s, $e, $uid]);
        }
        dbExec($conn, "UPDATE requests SET status = 'driver_assigned', assigned_at = NOW(), assigned_by = ?, task_hours = ? WHERE request_id = ?", [$uid, $hours, $id]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('assignRequest: ' . $e->getMessage());
        return wfFail('The assignment could not be saved. Please try again.');
    }
    addHistory($conn, $id, 'driver_assigned', 'driver_assigned');
    notifyUsers($conn, headAdminUserIds($conn), ($r['is_urgent'] ? 'URGENT. ' : '') . 'Driver assignment of request ' . requestOneLine($r) . ' is waiting for your approval.', 'admin/approvals.php');
    return wfOk("The drivers of request #$id were assigned and sent to the Head of Admin for approval.");
}

// ------------------------------------------------------------
// UC026 Approve/Reject Driver Assignment (request tasks)
// ------------------------------------------------------------

function hoaApproveAssignment(mysqli $conn, int $id): array
{
    $uid = (int)$_SESSION['user_id'];
    $conn->begin_transaction();
    $r = loadRequest($conn, $id, true);
    if (!$r || $r['status'] !== 'driver_assigned') { $conn->rollback(); return wfFail('This request has no driver assignment waiting for approval.'); }
    dbExec($conn, "UPDATE tasks SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE request_id = ? AND status = 'driver_assigned'", [$uid, $id]);
    dbExec($conn, "UPDATE requests SET status = 'approved', hoa_approved_at = NOW(), hoa_approved_by = ?, assign_reject_reason = NULL WHERE request_id = ?", [$uid, $id]);
    $conn->commit();
    addHistory($conn, $id, 'approved', 'approved');
    $tasks = requestTasks($conn, $id);
    $lines = [];
    foreach ($tasks as $t) { $lines[] = ucfirst($t['trip_type']) . ': ' . $t['driver_name'] . ' (' . $t['plate_number'] . ')'; }
    notifyUsers($conn, [(int)$r['requester_id']], "Your vehicle request #$id was approved. " . implode('; ', $lines) . '.', 'staff/request.php?id=' . $id);
    foreach ($tasks as $t) {
        notifyUsers($conn, [userIdOfDriver($conn, (int)$t['driver_id'])], 'New ' . $t['trip_type'] . ' task: ' . fmtDT($t['start_dt']) . ', ' . $t['plate_number']
            . ', to ' . $r['destination'] . '. Wait at ' . $r['waiting_location'] . '. Officer: ' . $r['requester_name'] . ' ' . ($r['requester_phone'] ?? '') . '.', 'driver/task.php?id=' . (int)$t['task_id'], 'assigned');
    }
    notifyUsers($conn, assistantUserIds($conn), "The driver assignment of request #$id was approved by the Head of Admin.", 'staff/request.php?id=' . $id);
    return wfOk("Request #$id was approved. The requester and the drivers were notified.");
}

function hoaRejectAssignment(mysqli $conn, int $id, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') { return wfFail('The reason is required.'); }
    $conn->begin_transaction();
    $r = loadRequest($conn, $id, true);
    if (!$r || $r['status'] !== 'driver_assigned') { $conn->rollback(); return wfFail('This request has no driver assignment waiting for approval.'); }
    $back = $r['purpose_type'] === 'personal' ? 'pending' : 'hod_approved';
    dbExec($conn, "DELETE FROM tasks WHERE request_id = ? AND task_source = 'request'", [$id]);
    dbExec($conn, 'UPDATE requests SET status = ?, assign_reject_reason = ? WHERE request_id = ?', [$back, mb_substr($reason, 0, 500), $id]);
    $conn->commit();
    addHistory($conn, $id, 'assignment_rejected', $back, $reason);
    notifyUsers($conn, assistantUserIds($conn), "The Head of Admin returned the driver assignment of request #$id. Reason: $reason. Please assign the drivers again.", 'admin/assign_driver.php?id=' . $id);
    return wfOk("The assignment of request #$id was returned to the Administrative Assistant. The vehicle reservation is kept.");
}

// ------------------------------------------------------------
// UC021 Cancel Vehicle Request
// ------------------------------------------------------------

/** May this user cancel this request now? */
function canCancelRequest(array $r, array $me): bool
{
    if (!in_array($r['status'], REQUEST_ACTIVE_STATUSES, true) || strtotime($r['depart_at']) <= time()) { return false; }
    if ((int)$r['requester_id'] === (int)$me['user_id']) { return true; }
    if (in_array($me['role'], ['admin', 'head_admin'], true)) { return true; }
    return $me['role'] === 'supervisor' && ((int)$r['approver_id'] === (int)$me['user_id'] || (int)$r['department_id'] === (int)$me['department_id']);
}

function cancelRequest(mysqli $conn, int $id, array $me, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') { return wfFail('The reason is required.'); }
    $conn->begin_transaction();
    $r = loadRequest($conn, $id, true);
    if (!$r || !canCancelRequest($r, $me)) { $conn->rollback(); return wfFail('This request cannot be cancelled.'); }
    $drivers = array_filter(array_map('intval', array_column(requestTasks($conn, $id), 'driver_id')));
    dbExec($conn, "UPDATE requests SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW(), cancelled_by = ? WHERE request_id = ?", [mb_substr($reason, 0, 500), (int)$me['user_id'], $id]);
    releaseReservation($conn, $id);
    dbExec($conn, "UPDATE tasks SET status = 'cancelled' WHERE request_id = ? AND status <> 'completed'", [$id]);
    dbExec($conn, "UPDATE driver_replacements SET status = 'rejected', reject_reason = 'The request was cancelled.', decided_at = NOW()
                    WHERE status = 'pending' AND task_id IN (SELECT task_id FROM tasks WHERE request_id = ?)", [$id]);
    $conn->commit();
    addHistory($conn, $id, 'cancelled', 'cancelled', $reason);
    $who = $me['full_name'] . ' (' . roleLabel($me['role']) . ')';
    $msg = "Vehicle request #$id (" . $r['destination'] . ', ' . fmtDT($r['depart_at']) . ") was cancelled by $who. Reason: $reason";
    $users = array_merge([(int)$r['requester_id']], $r['approver_id'] ? [(int)$r['approver_id']] : [], assistantUserIds($conn));
    foreach ($drivers as $d) { $users[] = userIdOfDriver($conn, $d); }
    $users = array_diff(array_unique(array_filter($users)), [(int)$me['user_id']]);
    notifyUsers($conn, $users, $msg, 'staff/request.php?id=' . $id);
    return wfOk("Request #$id was cancelled and the vehicle reservation was released.");
}

// ------------------------------------------------------------
// Approval letter (UC017 A3): approved Pay and Claim or Personal request
// ------------------------------------------------------------
function hasApprovalLetter(array $r): bool
{
    return in_array($r['status'], ['approved', 'completed'], true) && ($r['driver_option'] === 'pay_claim' || $r['purpose_type'] === 'personal');
}

// ------------------------------------------------------------
// Background jobs (cron/run_jobs.php hourly, and lightly on page loads)
//   1. approval emails that failed are sent again (UC019)
//   2. reminder after REMINDER_HOURS, once (UC020)
//   3. lapse after LAPSE_HOURS without a Head of Department decision; anything not approved when the trip starts lapses too
//   4. approved requests whose return time has passed are Completed
// ------------------------------------------------------------
function runRequestJobs(mysqli $conn): array
{
    $out = ['emails' => 0, 'reminders' => 0, 'lapsed' => 0, 'completed' => 0];

    foreach (dbAll($conn, requestSelectSql() . " WHERE r.status = 'pending' AND r.approval_email_at IS NULL AND r.depart_at > NOW() LIMIT 50") as $r) {
        sendApprovalEmail($conn, $r, false);
        $out['emails']++;
    }

    $remind = getSettingInt($conn, 'REMINDER_HOURS');
    $lapse  = getSettingInt($conn, 'LAPSE_HOURS');
    foreach (dbAll($conn, requestSelectSql() . " WHERE r.status = 'pending' AND r.purpose_type <> 'personal' AND r.reminder_sent_at IS NULL
                                                   AND r.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR) AND r.created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                                                   AND r.depart_at > NOW() LIMIT 50", [$remind, $lapse]) as $r) {
        $lapseAt = min(strtotime($r['created_at']) + $lapse * 3600, strtotime($r['depart_at']));
        $left = max(1, (int)round(($lapseAt - time()) / 3600));
        $u = $r['approver_id'] ? loadUser($conn, (int)$r['approver_id']) : null;
        if (!$u) { continue; }
        $msg = 'Reminder: vehicle request ' . requestOneLine($r) . " is still waiting for your approval. It lapses in about $left hour(s) if it is not approved.";
        $res = sendUserEmail($conn, $u, '[UIS Vehicle Section] Reminder: request #' . $r['request_id'] . ' waiting for approval', $msg, 'staff/request.php?id=' . $r['request_id'], 'reminder');
        if ($res['status'] !== 'failed') {
            dbExec($conn, 'UPDATE requests SET reminder_sent_at = NOW() WHERE request_id = ?', [(int)$r['request_id']]);
            addHistory($conn, (int)$r['request_id'], 'reminder', null, 'Reminder email sent to ' . $u['full_name'], null, 'system');
            $out['reminders']++;
        }
    }

    $toLapse = dbAll($conn, requestSelectSql() . " WHERE (r.status = 'pending' AND r.purpose_type <> 'personal' AND r.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR))
                                                      OR (r.status IN ('pending','hod_approved','driver_assigned') AND r.depart_at <= NOW()) LIMIT 100", [$lapse]);
    foreach ($toLapse as $r) {
        $id = (int)$r['request_id'];
        $hodLapse = $r['status'] === 'pending' && $r['purpose_type'] !== 'personal' && strtotime($r['created_at']) <= time() - $lapse * 3600;
        $why = $hodLapse ? "The Head of Department did not approve the request within $lapse hours." : 'The request was not approved before the departure time.';
        [$n] = dbExec($conn, "UPDATE requests SET status = 'lapsed' WHERE request_id = ? AND status = ?", [$id, $r['status']]);
        if (!$n) { continue; }
        releaseReservation($conn, $id);
        dbExec($conn, "UPDATE tasks SET status = 'cancelled' WHERE request_id = ? AND status <> 'completed'", [$id]);
        addHistory($conn, $id, 'lapsed', 'lapsed', $why, null, 'system');
        notifyUsers($conn, [(int)$r['requester_id']], "Your vehicle request #$id has lapsed. $why The vehicle reservation was released.", 'staff/request.php?id=' . $id);
        $out['lapsed']++;
    }

    foreach (dbAll($conn, "SELECT request_id FROM requests WHERE status = 'approved' AND return_at <= NOW() LIMIT 200") as $r) {
        $id = (int)$r['request_id'];
        [$n] = dbExec($conn, "UPDATE requests SET status = 'completed' WHERE request_id = ? AND status = 'approved'", [$id]);
        if ($n) { addHistory($conn, $id, 'completed', 'completed', null, null, 'system'); $out['completed']++; }
    }
    return $out;
}

/** Runs the jobs at most once every 5 minutes (called on page loads; cron/run_jobs.php runs them hourly). */
function runRequestJobsThrottled(mysqli $conn): void
{
    $last = (int)getSetting($conn, 'JOBS_LAST_RUN', '0');
    if (time() - $last < 300) { return; }
    $lock = $conn->query("SELECT GET_LOCK('uis_jobs', 0)")->fetch_row()[0] ?? 0;
    if ((int)$lock !== 1) { return; }
    try {
        saveSetting($conn, 'JOBS_LAST_RUN', (string)time());
        runRequestJobs($conn);
    } finally {
        $conn->query("SELECT RELEASE_LOCK('uis_jobs')");
    }
}
