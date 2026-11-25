<?php
// ============================================================
// SDD_CLASS_205 - AssignmentController (Domain Layer, SDD_PKG_200)
// Rules for assigning drivers to request tasks (UC003), internal tasks (UC005) and welfare duty (UC007), recording
// vendor buses (UC003 A3) and replacing drivers (UC008). Every assignment goes to the Head of Admin (UC026).
// ============================================================

class AssignmentController
{
    private NotificationService $notifier;
    private RecommendationService $recommender;
    private RequestController $requests;

    public function __construct()
    {
        $this->notifier = new NotificationService();
        $this->recommender = new RecommendationService();
        $this->requests = new RequestController();
    }

    /** Requests (source Request), internal tasks (Internal) or tasks needing a new driver (Replacement) waiting for the Assistant. */
    public function listWaiting(string $source): array
    {
        if ($source === 'Request') {
            $rows = Request::search("((r.status = 'Approved by HoD' AND r.driver_option = 'With Driver') OR (r.status = 'Pending' AND r.purpose_type = 'Personal'))", [],
                ['order' => 'r.is_urgent DESC, r.depart_datetime'])['rows'];
            return array_values(array_filter($rows, fn($r) => $this->requests->isWaitingForAssignment($r)));
        }
        if ($source === 'Replacement') { return Task::findNeedingReplacement(); }
        return Task::findWaiting($source === 'Welfare Duty' ? Task::WELFARE : Task::INTERNAL);
    }

    /** The outbound and return task periods of a request for a task length of $hours. */
    public function taskWindows(array $r, float $hours): array
    {
        $len = (int)round($hours * 3600);
        $d = strtotime($r['depart_datetime']); $ret = strtotime($r['return_datetime']);
        return ['Outbound' => [date('Y-m-d H:i:s', $d), date('Y-m-d H:i:s', min($d + $len, $ret))],
                'Return'   => [date('Y-m-d H:i:s', $ret), date('Y-m-d H:i:s', $ret + $len)]];
    }

    /**
     * Creates the outbound and return tasks with the chosen vehicles and drivers (SDD 5.16.2.1).
     * $assignments = [reservationID => ['vehicle_id', 'outbound', 'return', 'vendor_name', 'vendor_plate_no'], ...]
     * $hours: task length (1 hour by default; longer for a long-distance trip). $distance: corrected distance of a Personal request.
     */
    public function assignRequestTask(array $user, int $requestID, array $assignments, float $hours, ?float $distance = null): array
    {
        if ($hours < 0.5 || $hours > 24) { return wfFail('The task duration must be between 0.5 and 24 hours.'); }
        $uid = (int)$user['user_id'];
        Database::beginTransaction();
        try {
            $r = Request::findByID($requestID, true);
            if (!$r || !$this->requests->isWaitingForAssignment($r)) { Database::rollback(); return wfFail('This request is not waiting for driver assignment.'); }
            if ($r['purpose_type'] === Request::PERSONAL && $distance !== null && abs($distance - (float)$r['distance_km']) > 0.001) {
                if ($distance <= 0) { Database::rollback(); return wfFail('Enter a valid distance.'); }
                $charge = Request::calculateCharge($distance, $this->requests->getChargePerKm());
                Request::update($requestID, ['distance_km' => $distance, 'charge' => $charge]);
                RequestStatusHistory::record($requestID, $r['status'], $r['status'], $uid, 'Distance corrected from ' . $r['distance_km'] . ' km to ' . $distance . ' km (' . money($charge) . ')');
            }
            // UC003 A4: Personal Self Drive - no driver; the charge goes to the Head of Admin
            if ($r['purpose_type'] === Request::PERSONAL && $r['driver_option'] === Request::SELF_DRIVE) {
                RequestStatusHistory::record($requestID, $r['status'], $r['status'], $uid, RequestController::CHARGE_CONFIRMED);
                Database::commit();
                $this->notifier->notifyRole(User::HOA, 'Personal Self Drive request ' . $this->requests->oneLine(Request::findByID($requestID)) . ' is waiting for your approval.', 'admin/approvals.php');
                return wfOk("The charge of request #$requestID was confirmed and sent to the Head of Admin.");
            }
            $win = $this->taskWindows($r, $hours);
            $used = []; $plan = [];
            foreach (VehicleReservation::findByRequest($requestID) as $res) {
                if ($res['status'] !== VehicleReservation::ACTIVE) { continue; }
                $a = $assignments[(int)$res['reservation_id']] ?? [];
                if ($res['is_vendor']) {
                    $vn = trim((string)($a['vendor_name'] ?? '')); $vp = strtoupper(trim((string)($a['vendor_plate_no'] ?? '')));
                    if ($vn === '' || $vp === '') { Database::rollback(); return wfFail('Enter the vendor name and bus plate number for each vendor bus.'); }
                    $plan[] = ['vendor', $res, $vn, $vp];
                    continue;
                }
                $vid = (int)($a['vehicle_id'] ?? 0);
                $free = array_map('intval', array_column(Vehicle::findFree($res['vehicle_type'], $res['start_datetime'], $res['end_datetime'], (int)$res['reservation_id']), 'vehicle_id'));
                if (!$vid || !in_array($vid, $free, true)) { Database::rollback(); return wfFail('Select a free ' . $res['vehicle_type'] . ' for each reserved unit.'); }
                if (in_array($vid, array_column($plan, 4), true)) { Database::rollback(); return wfFail('The same vehicle is selected twice.'); }
                $vehicle = Vehicle::findByID($vid);
                $drivers = [];
                foreach (['Outbound', 'Return'] as $leg) {
                    $did = (int)($a[strtolower($leg)] ?? 0);
                    if ($did <= 0) { Database::rollback(); return wfFail("Select a driver for the " . strtolower($leg) . " trip of {$vehicle['plate_no']}."); }
                    [$s, $e] = $win[$leg];
                    if ($why = $this->recommender->driverUnavailableReason($did, $vehicle['licence_required'], $s, $e, [], $requestID)) {
                        Database::rollback(); return wfFail(Driver::findByID($did)['name'] . ' cannot take the ' . strtolower($leg) . " trip of {$vehicle['plate_no']}: $why.");
                    }
                    foreach ($used[$did] ?? [] as [$us, $ue]) {
                        if ($s < $ue && $e > $us) { Database::rollback(); return wfFail(Driver::findByID($did)['name'] . ' is selected for two tasks at the same time.'); }
                    }
                    $used[$did][] = [$s, $e];
                    $drivers[$leg] = $did;
                }
                $plan[] = ['vehicle', $res, $drivers, $vehicle, $vid];
            }
            foreach (Task::findByRequest($requestID) as $old) { Task::updateStatus((int)$old['task_id'], Task::CANCELLED); }
            foreach ($plan as $p) {
                if ($p[0] === 'vendor') { $this->recordVendorBus($requestID, (int)$p[1]['reservation_id'], $p[2], $p[3], $win, $uid); continue; }
                [, $res, $drivers, , $vid] = $p;
                VehicleReservation::setVehicle((int)$res['reservation_id'], $vid);
                foreach (['Outbound', 'Return'] as $leg) {
                    $tid = Task::create(['task_source' => Task::REQUEST, 'trip_type' => $leg, 'request_id' => $requestID, 'vehicle_id' => $vid,
                                         'start_datetime' => $win[$leg][0], 'end_datetime' => $win[$leg][1], 'status' => Task::WAITING]);
                    Task::assignDriver($tid, $drivers[$leg], $vid, $uid);
                }
            }
            Request::updateStatus($requestID, Request::DRIVER_ASSIGNED, $uid);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            appLog('error', 'assignRequestTask: ' . $e->getMessage());
            return wfFail('The assignment could not be saved. Please try again.');
        }
        $this->notifier->notifyRole(User::HOA, ($r['is_urgent'] ? 'URGENT. ' : '') . 'Driver assignment of request ' . $this->requests->oneLine($r) . ' is waiting for your approval.', 'admin/approvals.php');
        return wfOk("The drivers of request #$requestID were assigned and sent to the Head of Admin for approval.");
    }

    /** Records a vendor bus for a Vendor Bus Needed unit: an outbound and a return task with the vendor and no UIS driver. */
    public function recordVendorBus(int $requestID, int $reservationID, string $vendorName, string $plateNo, array $win, int $userID): bool
    {
        foreach (['Outbound', 'Return'] as $leg) {
            Task::create(['task_source' => Task::REQUEST, 'trip_type' => $leg, 'request_id' => $requestID, 'vendor_name' => mb_substr($vendorName, 0, 150),
                          'vendor_plate_no' => mb_substr($plateNo, 0, 15), 'start_datetime' => $win[$leg][0], 'end_datetime' => $win[$leg][1],
                          'description' => 'Vendor bus (reservation #' . $reservationID . ')', 'status' => Task::DRIVER_ASSIGNED, 'assigned_by' => $userID]);
        }
        return true;
    }

    /** UC005: assigns a driver to an internal task (Waiting for Driver, or returned by the Head of Admin). */
    public function assignInternalTask(array $user, int $taskID, int $driverID): array
    {
        $t = Task::findByID($taskID);
        if (!$t || $t['task_source'] !== Task::INTERNAL || !in_array($t['status'], [Task::WAITING, Task::REJECTED], true)) { return wfFail('This task is not waiting for a driver.'); }
        if ($driverID <= 0) { return wfFail('Select a driver.'); }
        if ($why = $this->recommender->driverUnavailableReason($driverID, $t['licence_required'], $t['start_datetime'], $t['end_datetime'], [$taskID])) {
            return wfFail(Driver::findByID($driverID)['name'] . " cannot take this task: $why.");
        }
        Task::assignDriver($taskID, $driverID, null, (int)$user['user_id']);
        $this->notifier->notifyRole(User::HOA, 'Driver assignment of internal task ' . $t['task_type'] . ', ' . fmtPeriod($t['start_datetime'], $t['end_datetime']) . ' (' . Driver::findByID($driverID)['name'] . ') is waiting for your approval.', 'admin/approvals.php?tab=internal');
        return wfOk('The driver was assigned and the task was sent to the Head of Admin for approval.');
    }

    /** UC007: assigns a driver to each waiting day of a welfare duty week and sends the week to the Head of Admin. */
    public function assignWelfareDuty(array $user, int $dutyID, array $driverIDs): array
    {
        $week = WelfareDuty::findByID($dutyID);
        if (!$week) { return wfFail('No schedule exists for the selected week.'); }
        $days = array_filter(Task::findByDuty($dutyID), fn($t) => in_array($t['status'], [Task::WAITING, Task::REJECTED], true) && strtotime($t['start_datetime']) > time());
        if (!$days) { return wfFail('No day of this week is waiting for a driver.'); }
        foreach ($days as $t) { if ((int)($driverIDs[(int)$t['task_id']] ?? 0) <= 0) { return wfFail('Every day needs a driver.'); } }
        Database::beginTransaction();
        foreach ($days as $t) {
            $did = (int)$driverIDs[(int)$t['task_id']];
            if ($why = $this->recommender->driverUnavailableReason($did, $t['licence_required'], $t['start_datetime'], $t['end_datetime'], [(int)$t['task_id']])) {
                Database::rollback();
                return wfFail(Driver::findByID($did)['name'] . ' cannot take the duty on ' . fmtD($t['start_datetime']) . ": $why.");
            }
            Task::assignDriver((int)$t['task_id'], $did, null, (int)$user['user_id']);
        }
        WelfareDuty::updateStatus($dutyID, WelfareDuty::DRIVER_ASSIGNED);
        Database::commit();
        $this->notifier->notifyRole(User::HOA, 'The welfare duty schedule of the week of ' . fmtD($week['week_start_date']) . ' is waiting for your approval.', 'admin/approvals.php?tab=welfare');
        return wfOk('The drivers were assigned and the schedule was sent to the Head of Admin for approval.');
    }

    /** Can the driver of this task be replaced now (approved, not started, no replacement waiting)? */
    public function canReplace(array $t): bool
    {
        return $t['status'] === Task::APPROVED && $t['driver_id'] && strtotime($t['start_datetime']) > time() && !DriverReplacement::findPendingByTask((int)$t['task_id']);
    }

    /** UC008: records the replacement with the reason and sends it to the Head of Admin (SDD 5.16.2.2). */
    public function replaceDriver(array $user, int $taskID, int $newDriverID, string $reason): array
    {
        $reason = trim($reason);
        $t = Task::findByID($taskID);
        if ($reason === '') { return wfFail('The reason is required.'); }
        if (!$t || !$this->canReplace($t)) { return wfFail('The driver of this task cannot be replaced (it must be approved, not started, and without a replacement waiting).'); }
        if ($newDriverID === (int)$t['driver_id']) { return wfFail('Select a different driver.'); }
        if ($why = $this->recommender->driverUnavailableReason($newDriverID, $t['licence_required'], $t['start_datetime'], $t['end_datetime'], [$taskID])) {
            return wfFail(Driver::findByID($newDriverID)['name'] . " cannot take this task: $why.");
        }
        DriverReplacement::create($taskID, (int)$t['driver_id'], $newDriverID, $reason);
        $new = Driver::findByID($newDriverID)['name'];
        if ($t['request_id']) {
            $r = Request::findByID((int)$t['request_id']);
            RequestStatusHistory::record((int)$t['request_id'], $r['status'], $r['status'], (int)$user['user_id'], 'Driver replacement (' . strtolower((string)$t['trip_type']) . "): {$t['driver_name']} -> $new. Reason: $reason");
        }
        $this->notifier->notifyRole(User::HOA, 'Driver replacement on ' . fmtPeriod($t['start_datetime'], $t['end_datetime']) . " ({$t['plate_no']}): {$t['driver_name']} -> $new is waiting for your approval. Reason: $reason", 'admin/approvals.php?tab=replacement');
        return wfOk('The replacement was recorded and sent to the Head of Admin for approval.');
    }
}
