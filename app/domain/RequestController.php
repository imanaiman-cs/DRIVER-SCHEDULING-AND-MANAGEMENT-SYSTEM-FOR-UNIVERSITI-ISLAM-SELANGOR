<?php
// ============================================================
// SDD_CLASS_203 - RequestController (Domain Layer, SDD_PKG_200)
// Rules for checking availability, submitting (UC018, UC019), viewing (UC017) and cancelling (UC021) requests.
//
// Who approves a request: the head of the requester's department (its Head of Department, or the Head of Admin for
// BPI). A request from a head (Head of Department or Head of Admin) goes to the head of the parent department.
// A Personal request goes to the Administrative Assistant, then the Head of Admin.
// ============================================================

class RequestController
{
    /** Hours added after the return time for the reservation (1). */
    private int $bufferHours;
    /** Charge for a Personal request (RM3 per km). */
    private float $chargePerKm;
    private NotificationService $notifier;

    /** Remark written when the Assistant confirms the charge of a Personal Self Drive request (it then waits for the Head of Admin). */
    public const CHARGE_CONFIRMED = 'Distance and charge confirmed by the Administrative Assistant';

    public function __construct()
    {
        $this->bufferHours = (int)setting('BUFFER_HOURS');
        $this->chargePerKm = (float)setting('CHARGE_PER_KM');
        $this->notifier = new NotificationService();
    }

    public function getChargePerKm(): float { return $this->chargePerKm; }

    /** The end of a reservation: the return time + the buffer. */
    public function reservationEnd(string $return): string
    {
        return date('Y-m-d H:i:s', strtotime($return) + $this->bufferHours * 3600);
    }

    // ---------------------------------------------------------------- rules shared with other controllers

    /** The user who approves the request as head (null for a Personal request, or when there is no head). */
    public function approverOf(array $r): ?array
    {
        if ($r['purpose_type'] === Request::PERSONAL) { return null; }
        $requesterIsHead = in_array($r['requester_role'], [User::HOD, User::HOA], true);
        $dept = $requesterIsHead ? (int)($r['parent_department_id'] ?? 0) : (int)$r['department_id'];
        return Department::getHead($dept, (int)$r['requester_id']);
    }

    /** Who approves requests of this user: the head of the department, or of the parent department for a head. */
    public function approverForUser(array $user): ?array
    {
        $isHead = in_array($user['role'], [User::HOD, User::HOA], true);
        return Department::getHead($isHead ? (int)($user['parent_department_id'] ?? 0) : (int)$user['department_id'], (int)$user['user_id']);
    }

    /** Is the request waiting for this user's approval as head (UC025 main flow)? */
    public function isWaitingForHead(array $r, int $userID): bool
    {
        return $r['status'] === Request::PENDING && $r['purpose_type'] !== Request::PERSONAL && (int)($this->approverOf($r)['user_id'] ?? 0) === $userID;
    }

    /** Has the Assistant confirmed the charge of a Personal Self Drive request? */
    public function chargeConfirmed(array $r): bool
    {
        return RequestStatusHistory::hasRemark((int)$r['request_id'], self::CHARGE_CONFIRMED);
    }

    /** Is the request waiting for the Head of Admin as a request (UC025 A2)? */
    public function isWaitingForHoa(array $r): bool
    {
        return ($r['status'] === Request::APPROVED_BY_HOD && in_array($r['driver_option'], [Request::PAY_AND_CLAIM, Request::SELF_DRIVE], true))
            || ($r['status'] === Request::PENDING && $r['purpose_type'] === Request::PERSONAL && $r['driver_option'] === Request::SELF_DRIVE && $this->chargeConfirmed($r));
    }

    /** Is the request waiting for the Administrative Assistant (UC003)? */
    public function isWaitingForAssignment(array $r): bool
    {
        return ($r['status'] === Request::APPROVED_BY_HOD && $r['driver_option'] === Request::WITH_DRIVER)
            || ($r['status'] === Request::PENDING && $r['purpose_type'] === Request::PERSONAL && !$this->chargeConfirmed($r));
    }

    /** What the request is waiting for, in words. */
    public function waitingText(array $r): string
    {
        if ($r['status'] === Request::PENDING) {
            if ($r['purpose_type'] === Request::PERSONAL) { return $this->chargeConfirmed($r) ? 'Waiting for the Head of Admin' : 'Waiting for the Administrative Assistant'; }
            return 'Waiting for ' . ($this->approverOf($r)['name'] ?? 'the head of the department');
        }
        if ($r['status'] === Request::APPROVED_BY_HOD) { return $r['driver_option'] === Request::WITH_DRIVER ? 'Waiting for driver assignment' : 'Waiting for the Head of Admin'; }
        if ($r['status'] === Request::DRIVER_ASSIGNED) { return 'Waiting for the Head of Admin'; }
        return '';
    }

    /** Which driver options the user may pick for a vehicle type (Pay and Claim for a car; Self Drive for a lorry, GDL drivers only). */
    public function driverOptionsFor(array $user, string $type): array
    {
        $opts = [Request::WITH_DRIVER];
        if ($type === 'Car') { $opts[] = Request::PAY_AND_CLAIM; }
        if ($type === 'Lorry' && $user['role'] === User::DRIVER && Driver::hasLicence($user, 'GDL')) { $opts[] = Request::SELF_DRIVE; }
        return $opts;
    }

    /** Purpose types of a vehicle type (Personal for lorries only). */
    public function purposeTypesFor(string $type): array
    {
        return $type === 'Lorry' ? [Request::OFFICIAL, Request::UNOFFICIAL, Request::PERSONAL] : [Request::OFFICIAL, Request::UNOFFICIAL];
    }

    // ---------------------------------------------------------------- UC018 Submit Vehicle Request

    /** Checks the trip period. Returns [errors, depart, return]. */
    public function checkPeriod(string $dDate, string $dTime, string $rDate, string $rTime): array
    {
        $errors = [];
        $depart = joinDateTime($dDate, $dTime); $return = joinDateTime($rDate, $rTime);
        if (!$depart) { $errors['depart'] = 'Enter a valid departure date and time.'; }
        if (!$return) { $errors['return'] = 'Enter a valid return date and time.'; }
        if ($depart && strtotime($depart) <= time()) { $errors['depart'] = 'The departure date and time must be in the future.'; }
        if ($depart && $return && strtotime($return) <= strtotime($depart)) { $errors['return'] = 'The return date and time must be later than the departure date and time.'; }
        return [$errors, $depart, $return];
    }

    /** Returns the units left of each vehicle type for the period (SDD 5.14.2.1). */
    public function checkAvailability(string $depart, string $return): array
    {
        $units = [];
        foreach (Vehicle::TYPES as $type) { $units[$type] = VehicleReservation::countUnitsLeft($type, $depart, $this->reservationEnd($return)); }
        return $units;
    }

    /** Is the departure within the urgent window (two days)? */
    public function isUrgent(string $depart): bool
    {
        return strtotime($depart) - time() < (int)setting('URGENT_HOURS') * 3600;
    }

    /**
     * Validates the request, reserves the vehicles and sends the approval email (SDD 5.14.2.2).
     * $file: the supporting letter ($_FILES entry) or null.
     */
    public function submitRequest(array $user, array $f, ?array $file): array
    {
        $errors = [];
        [$pe, $depart, $return] = $this->checkPeriod((string)($f['depart_date'] ?? ''), (string)($f['depart_time'] ?? ''), (string)($f['return_date'] ?? ''), (string)($f['return_time'] ?? ''));
        $errors += $pe;
        $type = (string)($f['vehicle_type'] ?? '');
        if (!in_array($type, Vehicle::TYPES, true)) { $errors['vehicle_type'] = 'Select a vehicle type.'; }
        $option = (string)($f['driver_option'] ?? Request::WITH_DRIVER);
        if ($type && !in_array($option, $this->driverOptionsFor($user, $type), true)) { $errors['driver_option'] = 'This option is not available for the selected vehicle type.'; }
        $qty = max(1, (int)($f['quantity'] ?? 1));
        if ($option !== Request::WITH_DRIVER) { $qty = 1; }
        if ($qty > 20) { $errors['quantity'] = 'Enter a quantity of 20 or less.'; }
        $ptype = (string)($f['purpose_type'] ?? '');
        if ($type && !in_array($ptype, $this->purposeTypesFor($type), true)) { $errors['purpose_type'] = 'Select the purpose type.'; }
        $vals = [];
        foreach (['purpose' => 255, 'destination' => 150, 'address' => 255, 'waiting_location' => 150] as $k => $max) {
            $vals[$k] = mb_substr(trim((string)($f[$k] ?? '')), 0, $max);
            if ($vals[$k] === '') { $errors[$k] = 'This field is required.'; }
        }
        $passengers = (int)($f['no_of_passengers'] ?? 0);
        if ($passengers < 1) { $errors['no_of_passengers'] = 'Enter the number of passengers.'; }
        $distance = null; $charge = null;
        if ($ptype === Request::PERSONAL) {
            $distance = round((float)($f['distance_km'] ?? 0), 1);
            if ($distance <= 0) { $errors['distance_km'] = 'Enter the round-trip distance in kilometres.'; }
            $charge = Request::calculateCharge($distance, $this->chargePerKm);
        }
        $hasFile = $file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (in_array($ptype, [Request::OFFICIAL, Request::UNOFFICIAL], true) && !$hasFile) { $errors['document'] = 'The supporting letter is required.'; }
        if ($ptype !== Request::PERSONAL && !$errors && !$this->approverForUser($user)) {
            $errors['general'] = 'Your department has no head in the system yet to approve the request. Please contact the Vehicle Section.';
        }
        if ($errors) { return wfFail('Please correct the highlighted fields.', ['errors' => $errors]); }

        $doc = null;
        if ($hasFile && $ptype !== Request::PERSONAL) {
            $doc = saveUploadedDocument($file, 5242880);
            if (!$doc['ok']) { return wfFail($doc['error'], ['errors' => ['document' => $doc['error']]]); }
        }
        $resEnd = $this->reservationEnd($return);
        $vendor = 0;
        Database::beginTransaction();
        try {
            $id = Request::create(['requester_id' => (int)$user['user_id'], 'department_id' => (int)$user['department_id'], 'vehicle_type' => $type, 'quantity' => $qty,
                'driver_option' => $option, 'purpose_type' => $ptype, 'depart_datetime' => $depart, 'return_datetime' => $return, 'no_of_passengers' => $passengers,
                'supporting_doc' => $doc['path'] ?? null, 'distance_km' => $distance, 'charge' => $charge, 'is_urgent' => $this->isUrgent($depart) ? 1 : 0] + $vals);
            if ($option !== Request::PAY_AND_CLAIM) {
                $n = VehicleReservation::reserve($id, $type, $qty, $depart, $resEnd);
                if ($n < $qty) {
                    if ($type !== 'Bus') {
                        Database::rollback();
                        if ($doc) { deleteStoredDocument($doc['path']); }
                        return wfFail('The vehicles are no longer available. Please select the vehicle again.', ['errors' => ['vehicle_type' => "Not enough vehicles are available ($n left)."]]);
                    }
                    $vendor = $qty - $n;
                    VehicleReservation::reserveVendor($id, $type, $vendor, $depart, $resEnd);
                }
            }
            RequestStatusHistory::record($id, null, Request::PENDING, (int)$user['user_id'], $this->isUrgent($depart) ? 'Urgent request' : null);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            if ($doc) { deleteStoredDocument($doc['path']); }
            appLog('error', 'submitRequest: ' . $e->getMessage());
            return wfFail('The request could not be saved. Please try again.');
        }

        $r = Request::findByID($id);
        $line = $this->oneLine($r);
        if ($ptype === Request::PERSONAL) {
            $this->notifier->notifyRole(User::AA, ($r['is_urgent'] ? 'URGENT. ' : '') . "New Personal lorry request $line.", 'admin/assign_driver.php?id=' . $id);
            $to = 'the Administrative Assistant';
        } else {
            $head = $this->approverOf($r);
            $this->notifier->notify([(int)$head['user_id']], ($r['is_urgent'] ? 'URGENT. ' : '') . "New vehicle request waiting for your approval $line.", 'staff/request.php?id=' . $id);
            $to = $head['name'] . ' for approval';
        }
        return wfOk("Your request #$id was submitted to $to." . ($vendor ? " $vendor bus(es) will be hired from a vendor." : ''), ['request_id' => $id]);
    }

    /** "#12 from Name (Department): Car x 1, With Driver to X, period" */
    public function oneLine(array $r): string
    {
        return '#' . $r['request_id'] . ' from ' . $r['requester_name'] . ' (' . $r['department_name'] . '): ' . $r['vehicle_type'] . ' x ' . $r['quantity'] . ', '
             . $r['driver_option'] . ' to ' . $r['destination'] . ', ' . fmtPeriod($r['depart_datetime'], $r['return_datetime']);
    }

    // ---------------------------------------------------------------- UC017 View Request

    /** My Request, Department Request or All Request. Returns ['rows', 'total']. */
    public function listRequests(array $user, string $scope, array $filter = []): array
    {
        if ($scope === 'department') { return Request::findByDepartment((int)$user['department_id'], (int)$user['user_id'], $filter); }
        if ($scope === 'all' && in_array($user['role'], [User::AA, User::HOA], true)) { return Request::findAll($filter); }
        return Request::findByRequester((int)$user['user_id'], $filter);
    }

    /** Requests waiting for this head's approval (Urgent first). */
    public function listWaitingForHead(array $user): array
    {
        $rows = Request::findByDepartment((int)$user['department_id'], (int)$user['user_id'], ['status' => Request::PENDING, 'order' => 'r.is_urgent DESC, r.date_submitted'])['rows'];
        return array_values(array_filter($rows, fn($r) => $this->isWaitingForHead($r, (int)$user['user_id'])));
    }

    /** Returns the request with its status history, reservations and tasks. */
    public function getRequestDetails(int $requestID): ?array
    {
        $r = Request::findByID($requestID);
        if (!$r) { return null; }
        $r['history'] = RequestStatusHistory::findByRequest($requestID);
        $r['reservations'] = VehicleReservation::findByRequest($requestID);
        $r['tasks'] = Task::findByRequest($requestID);
        return $r;
    }

    /**
     * Can the user see the request? The requester, the head who approves it or the head of its department, the
     * Administrative Assistant and the Head of Admin, and a driver with an approved task on it.
     */
    public function isVisibleTo(array $r, array $user): bool
    {
        $uid = (int)$user['user_id'];
        if ((int)$r['requester_id'] === $uid || in_array($user['role'], [User::AA, User::HOA], true)) { return true; }
        if ($user['role'] === User::HOD && ((int)$r['department_id'] === (int)$user['department_id'] || (int)($this->approverOf($r)['user_id'] ?? 0) === $uid)) { return true; }
        if ($user['role'] === User::DRIVER) {
            foreach (Task::findByRequest((int)$r['request_id']) as $t) { if ((int)$t['driver_id'] === $uid && in_array($t['status'], [Task::APPROVED, Task::COMPLETED], true)) { return true; } }
        }
        return false;
    }

    // ---------------------------------------------------------------- UC021 Cancel Vehicle Request

    /** May the user cancel the request now? */
    public function canCancel(array $r, array $user): bool
    {
        if (!in_array($r['status'], Request::ACTIVE_STATUSES, true) || strtotime($r['depart_datetime']) <= time()) { return false; }
        $uid = (int)$user['user_id'];
        if ((int)$r['requester_id'] === $uid || in_array($user['role'], [User::AA, User::HOA], true)) { return true; }
        return $user['role'] === User::HOD && ((int)$r['department_id'] === (int)$user['department_id'] || (int)($this->approverOf($r)['user_id'] ?? 0) === $uid);
    }

    /** Cancels the request, releases the vehicles and notifies everyone involved (SDD 5.14.2.3). */
    public function cancelRequest(array $user, int $requestID, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') { return wfFail('The reason is required.'); }
        Database::beginTransaction();
        $r = Request::findByID($requestID, true);
        if (!$r || !$this->canCancel($r, $user)) { Database::rollback(); return wfFail('This request cannot be cancelled.'); }
        $tasks = Task::findByRequest($requestID);
        Request::updateStatus($requestID, Request::CANCELLED, (int)$user['user_id'], $reason, ['cancel_reason' => mb_substr($reason, 0, 255), 'cancelled_by' => (int)$user['user_id'], 'cancelled_date' => date('Y-m-d H:i:s')]);
        VehicleReservation::release($requestID);
        foreach ($tasks as $t) {
            if ($t['status'] !== Task::COMPLETED) { Task::updateStatus((int)$t['task_id'], Task::CANCELLED); }
            if ($p = DriverReplacement::findPendingByTask((int)$t['task_id'])) { DriverReplacement::updateStatus((int)$p['replacement_id'], DriverReplacement::REJECTED, (int)$user['user_id']); }
        }
        Database::commit();
        $users = array_merge([(int)$r['requester_id'], (int)($this->approverOf($r)['user_id'] ?? 0)], array_column(User::findByRole(User::AA), 'user_id'), array_column($tasks, 'driver_id'));
        $users = array_diff(array_unique(array_filter(array_map('intval', $users))), [(int)$user['user_id']]);
        $this->notifier->notify($users, "Vehicle request #$requestID (" . $r['destination'] . ', ' . fmtDT($r['depart_datetime']) . ") was cancelled by {$user['name']} ({$user['role']}). Reason: $reason", 'staff/request.php?id=' . $requestID);
        return wfOk("Request #$requestID was cancelled and the vehicle reservation was released.");
    }

    // ---------------------------------------------------------------- UC017 A3 Print Approval Letter

    /** Does the request have an approval letter (an approved Pay and Claim or Personal request)? */
    public function hasApprovalLetter(array $r): bool
    {
        return in_array($r['status'], [Request::APPROVED, Request::COMPLETED], true) && ($r['driver_option'] === Request::PAY_AND_CLAIM || $r['purpose_type'] === Request::PERSONAL);
    }

    /** Builds the approval letter data for an approved Pay and Claim or Personal request. */
    public function getApprovalLetter(int $requestID, array $user): ?array
    {
        $r = $this->getRequestDetails($requestID);
        if (!$r || !$this->isVisibleTo($r, $user) || !$this->hasApprovalLetter($r)) { return null; }
        if ($r['purpose_type'] === Request::PERSONAL) {
            foreach ($r['history'] as $h) { if (str_starts_with((string)$h['remark'], self::CHARGE_CONFIRMED) || ($h['new_status'] === Request::DRIVER_ASSIGNED)) { $r['confirmed_at'] = $h['changed_at']; } }
        }
        return $r;
    }
}
