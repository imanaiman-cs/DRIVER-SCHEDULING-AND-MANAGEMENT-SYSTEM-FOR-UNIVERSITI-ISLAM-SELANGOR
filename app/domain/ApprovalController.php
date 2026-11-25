<?php
// ============================================================
// SDD_CLASS_204 - ApprovalController (Domain Layer, SDD_PKG_200)
// Approval rules for vehicle requests (UC025), driver assignments and replacements (UC026) and schedule changes (UC027).
// A rejection always needs a reason (UC028 Add Comment), saved with the date, time and the name of the user.
// ============================================================

class ApprovalController
{
    private NotificationService $notifier;
    private RequestController $requests;
    private RecommendationService $recommender;

    public function __construct()
    {
        $this->notifier = new NotificationService();
        $this->requests = new RequestController();
        $this->recommender = new RecommendationService();
    }

    /**
     * Items the user can approve. $type: head (requests waiting for the user as head), request (Head of Admin: requests
     * with a driver assignment, Pay and Claim, Self Drive, Personal Self Drive), internal, welfare, replacement, schedule.
     */
    public function listPending(array $user, string $type): array
    {
        $hoa = $user['role'] === User::HOA;
        return match ($type) {
            'head'        => $this->requests->listWaitingForHead($user),
            'request'     => $hoa ? array_values(array_filter(Request::search("r.status IN ('Pending', 'Approved by HoD', 'Driver Assigned')", [], ['order' => 'r.is_urgent DESC, r.depart_datetime'])['rows'],
                                 fn($r) => $r['status'] === Request::DRIVER_ASSIGNED || $this->requests->isWaitingForHoa($r))) : [],
            'internal'    => $hoa ? Task::findAssigned(Task::INTERNAL) : [],
            'welfare'     => $hoa ? WelfareDuty::findByStatus(WelfareDuty::DRIVER_ASSIGNED) : [],
            'replacement' => $hoa ? DriverReplacement::findPending() : [],
            'schedule'    => $hoa ? ScheduleChange::findPending() : [],
            default       => [],
        };
    }

    /** A driver's task count in the month of a date (shown with every assignment, UC026 2.1). */
    public function driverTaskCount(int $driverID, string $date): int
    {
        return Driver::countTasks($driverID, substr($date, 0, 7));
    }

    /** The counts for the menu badges. */
    public function countPending(array $user): array
    {
        $out = ['head' => count($this->listPending($user, 'head'))];
        if ($user['role'] === User::HOA) {
            foreach (['request', 'internal', 'welfare', 'replacement', 'schedule'] as $k) { $out[$k] = count($this->listPending($user, $k)); }
        }
        return $out;
    }

    // ---------------------------------------------------------------- UC025

    /** Approves or rejects a vehicle request (SDD 5.15.2.1). $decision: 'Approve' or 'Reject'. */
    public function decideRequest(array $user, int $requestID, string $decision, string $comment = ''): array
    {
        $comment = trim($comment);
        $uid = (int)$user['user_id'];
        Database::beginTransaction();
        $r = Request::findByID($requestID, true);
        $asHead = $r && $this->requests->isWaitingForHead($r, $uid);
        $asHoa = $r && $user['role'] === User::HOA && $this->requests->isWaitingForHoa($r);
        if (!$asHead && !$asHoa) { Database::rollback(); return wfFail('This request is not waiting for your approval.'); }
        if ($decision === 'Reject') {
            if ($comment === '') { Database::rollback(); return wfFail('The reason is required.'); }
            Request::updateStatus($requestID, Request::REJECTED, $uid, $comment, ['reject_reason' => mb_substr($comment, 0, 255), 'rejected_date' => date('Y-m-d H:i:s')]
                + ($asHead ? ['hod_id' => $uid] : ['hoa_id' => $uid]));
            VehicleReservation::release($requestID);
            Database::commit();
            $this->notifier->notify([(int)$r['requester_id']], "Your vehicle request #$requestID was rejected by {$user['name']}. Reason: $comment", 'staff/request.php?id=' . $requestID);
            return wfOk("Request #$requestID was rejected and the vehicle reservation was released.");
        }
        if ($asHead) {
            Request::updateStatus($requestID, Request::APPROVED_BY_HOD, $uid, $comment !== '' ? $comment : null, ['hod_id' => $uid, 'hod_approved_date' => date('Y-m-d H:i:s')]);
            Database::commit();
            if ($r['driver_option'] === Request::WITH_DRIVER) {
                $this->notifier->notifyRole(User::AA, ($r['is_urgent'] ? 'URGENT. ' : '') . 'Request ' . $this->requests->oneLine($r) . ' is waiting for driver assignment.', 'admin/assign_driver.php?id=' . $requestID);
            } else {
                $this->notifier->notifyRole(User::HOA, 'Request ' . $this->requests->oneLine($r) . " ({$r['driver_option']}) is waiting for your approval.", 'admin/approvals.php');
            }
            $this->notifier->notify([(int)$r['requester_id']], "Your vehicle request #$requestID was approved by the Head of Department.", 'staff/request.php?id=' . $requestID);
            return wfOk("Request #$requestID was approved.");
        }
        Request::updateStatus($requestID, Request::APPROVED, $uid, null, ['hoa_id' => $uid, 'hoa_approved_date' => date('Y-m-d H:i:s')]);
        Database::commit();
        $this->notifier->notify([(int)$r['requester_id']], "Your vehicle request #$requestID was approved by the Head of Admin. You can print the approval letter from the request.", 'staff/request.php?id=' . $requestID);
        $this->notifier->notifyRole(User::AA, "Request #$requestID ({$r['driver_option']}) was approved by the Head of Admin.", 'staff/request.php?id=' . $requestID);
        return wfOk("Request #$requestID was approved.");
    }

    // ---------------------------------------------------------------- UC026

    /**
     * Approves or rejects the driver assignment of tasks (SDD 5.15.2.2): request tasks, an internal task or the days of
     * a welfare week. A rejected task goes back to the Administrative Assistant with the reason.
     */
    public function decideAssignment(array $user, array $taskIDs, string $decision, string $comment = ''): array
    {
        $comment = trim($comment);
        if ($decision === 'Reject' && $comment === '') { return wfFail('The reason is required.'); }
        $uid = (int)$user['user_id'];
        $tasks = array_values(array_filter(array_map(fn($id) => Task::findByID((int)$id), $taskIDs), fn($t) => $t && $t['status'] === Task::DRIVER_ASSIGNED));
        if (!$tasks) { return wfFail('Nothing is waiting for approval.'); }
        if ($decision === 'Approve') {
            foreach ($tasks as $t) {
                if (!$t['driver_id']) { continue; }   // vendor bus
                if ($why = $this->recommender->driverUnavailableReason((int)$t['driver_id'], (string)$t['licence_required'], $t['start_datetime'], $t['end_datetime'], array_map('intval', $taskIDs), (int)$t['request_id'])) {
                    return wfFail($t['driver_name'] . " is no longer available: $why.");
                }
            }
        }
        Database::beginTransaction();
        foreach ($tasks as $t) { Task::updateStatus((int)$t['task_id'], $decision === 'Approve' ? Task::APPROVED : Task::REJECTED, $uid, $decision === 'Approve' ? null : $comment); }
        $first = $tasks[0];
        if ($first['task_source'] === Task::REQUEST) {
            $rid = (int)$first['request_id'];
            $r = Request::findByID($rid);
            if ($decision === 'Approve') {
                $left = array_filter(Task::findByRequest($rid), fn($x) => $x['status'] !== Task::APPROVED);
                if (!$left) { Request::updateStatus($rid, Request::APPROVED, $uid, null, ['hoa_id' => $uid, 'hoa_approved_date' => date('Y-m-d H:i:s')]); }
            } else {
                foreach (Task::findByRequest($rid) as $x) { Task::updateStatus((int)$x['task_id'], Task::REJECTED, $uid, $comment); }
                Request::updateStatus($rid, $r['purpose_type'] === Request::PERSONAL ? Request::PENDING : Request::APPROVED_BY_HOD, $uid, 'Driver assignment returned: ' . $comment);
            }
            Database::commit();
            if ($decision === 'Approve') {
                $lines = [];
                foreach (Task::findByRequest($rid) as $x) { $lines[] = $x['trip_type'] . ': ' . ($x['driver_name'] ?? $x['vendor_name'] . ' (vendor)') . ' (' . ($x['plate_no'] ?? $x['vendor_plate_no']) . ')'; }
                $this->notifier->notify([(int)$r['requester_id']], "Your vehicle request #$rid was approved. " . implode('; ', $lines) . '.', 'staff/request.php?id=' . $rid);
                foreach ($tasks as $t) {
                    if (!$t['driver_id']) { continue; }
                    $this->notifier->notify([(int)$t['driver_id']], 'New ' . strtolower((string)$t['trip_type']) . ' task: ' . fmtDT($t['start_datetime']) . ', ' . $t['plate_no'] . ', to ' . $r['destination']
                        . '. Wait at ' . $r['waiting_location'] . '. Officer: ' . $r['requester_name'] . ' ' . $r['requester_phone'] . '.', 'driver/task.php?id=' . $t['task_id']);
                }
                $this->notifier->notifyRole(User::AA, "The driver assignment of request #$rid was approved by the Head of Admin.", 'staff/request.php?id=' . $rid);
                return wfOk("Request #$rid was approved. The requester and the drivers were notified.");
            }
            $this->notifier->notifyRole(User::AA, "The Head of Admin returned the driver assignment of request #$rid. Reason: $comment. Please assign the drivers again.", 'admin/assign_driver.php?id=' . $rid);
            return wfOk("The assignment of request #$rid was returned to the Administrative Assistant. The vehicle reservation is kept.");
        }
        if ($first['task_source'] === Task::WELFARE) {
            WelfareDuty::updateStatus((int)$first['duty_id'], $decision === 'Approve' ? WelfareDuty::APPROVED : WelfareDuty::REJECTED);
            Database::commit();
            $week = fmtD($first['week_start_date']);
            if ($decision === 'Approve') {
                foreach ($tasks as $t) { $this->notifier->notify([(int)$t['driver_id']], 'Welfare duty (24 hours) on ' . date('l, d M Y', strtotime($t['start_datetime'])) . ', vehicle ' . $t['plate_no'] . '.', 'driver/task.php?id=' . $t['task_id']); }
                $this->notifier->notifyRole(User::AA, "The Head of Admin approved the welfare duty schedule of the week of $week.", 'admin/welfare.php?week=' . $first['week_start_date']);
                return wfOk('The welfare duty schedule was approved and the drivers were notified.');
            }
            $this->notifier->notifyRole(User::AA, "The Head of Admin returned the welfare duty schedule of the week of $week. Reason: $comment", 'admin/welfare.php?week=' . $first['week_start_date']);
            return wfOk('The schedule was returned to the Administrative Assistant.');
        }
        Database::commit();
        $label = $first['task_type'] . ', ' . fmtPeriod($first['start_datetime'], $first['end_datetime']);
        if ($decision === 'Approve') {
            $this->notifier->notify([(int)$first['driver_id']], "New internal task: $label, {$first['plate_no']}. {$first['description']}", 'driver/task.php?id=' . $first['task_id']);
            $this->notifier->notifyRole(User::AA, "The Head of Admin approved internal task $label ({$first['driver_name']}).", 'admin/internal_tasks.php');
            return wfOk('The internal task was approved and the driver was notified.');
        }
        $this->notifier->notifyRole(User::AA, "The Head of Admin returned the driver assignment of internal task $label. Reason: $comment", 'admin/internal_tasks.php?assign=' . $first['task_id']);
        return wfOk('The task was returned to the Administrative Assistant.');
    }

    /** The task of a replacement. */
    public function replacementTask(array $replacement): ?array
    {
        return Task::findByID((int)$replacement['task_id']);
    }

    /** UC026 for a driver replacement (UC008). */
    public function decideReplacement(array $user, int $replacementID, string $decision, string $comment = ''): array
    {
        $comment = trim($comment);
        $rp = DriverReplacement::findByID($replacementID);
        if (!$rp || $rp['status'] !== DriverReplacement::PENDING) { return wfFail('This replacement is not waiting for approval.'); }
        $t = Task::findByID((int)$rp['task_id']);
        $uid = (int)$user['user_id'];
        if ($decision === 'Approve') {
            if ($t['status'] !== Task::APPROVED || strtotime($t['start_datetime']) <= time()) { return wfFail('The task has started or is no longer approved.'); }
            if ($why = $this->recommender->driverUnavailableReason((int)$rp['new_driver_id'], $t['licence_required'], $t['start_datetime'], $t['end_datetime'], [(int)$t['task_id']])) {
                return wfFail($rp['new_driver_name'] . " is no longer available: $why.");
            }
            Database::beginTransaction();
            DriverReplacement::updateStatus($replacementID, DriverReplacement::APPROVED, $uid);
            Task::update((int)$t['task_id'], ['driver_id' => (int)$rp['new_driver_id'], 'approved_by' => $uid, 'approved_date' => date('Y-m-d H:i:s')]);
            if ($t['request_id']) {
                $r = Request::findByID((int)$t['request_id']);
                RequestStatusHistory::record((int)$t['request_id'], $r['status'], $r['status'], $uid, "Driver replacement approved: {$rp['old_driver_name']} -> {$rp['new_driver_name']}");
            }
            Database::commit();
            $when = fmtPeriod($t['start_datetime'], $t['end_datetime']);
            $this->notifier->notify([(int)$rp['old_driver_id']], "You were replaced on the task of $when ({$t['plate_no']}). Reason: {$rp['reason']}", 'driver/tasks.php');
            $this->notifier->notify([(int)$rp['new_driver_id']], "New task (replacement): $when, {$t['plate_no']}" . ($t['destination'] ? ", to {$t['destination']}. Officer: {$t['officer_name']} {$t['officer_phone']}" : '') . '.', 'driver/task.php?id=' . $t['task_id']);
            if ($t['requester_id']) { $this->notifier->notify([(int)$t['requester_id']], 'The driver of the ' . strtolower((string)$t['trip_type']) . " trip of your request #{$t['request_id']} was changed to {$rp['new_driver_name']}.", 'staff/request.php?id=' . $t['request_id']); }
            $this->notifier->notifyRole(User::AA, "The Head of Admin approved the driver replacement on $when ({$rp['new_driver_name']}).", 'admin/task.php?id=' . $t['task_id']);
            return wfOk('The replacement was approved. The previous driver, the new driver and the requester were notified.');
        }
        if ($comment === '') { return wfFail('The reason is required.'); }
        DriverReplacement::updateStatus($replacementID, DriverReplacement::REJECTED, $uid);
        if ($t['request_id']) {
            $r = Request::findByID((int)$t['request_id']);
            RequestStatusHistory::record((int)$t['request_id'], $r['status'], $r['status'], $uid, 'Driver replacement rejected: ' . $comment);
        }
        $this->notifier->notifyRole(User::AA, 'The Head of Admin rejected the driver replacement on ' . fmtPeriod($t['start_datetime'], $t['end_datetime']) . ". Reason: $comment", 'admin/replace_driver.php?task=' . $t['task_id']);
        return wfOk('The replacement was rejected.');
    }

    // ---------------------------------------------------------------- UC027

    /** Schedule changes that were approved or rejected (latest first). */
    public function listDecidedScheduleChanges(): array
    {
        return ScheduleChange::findDecided();
    }

    /** The tasks of the driver on the dates of a schedule change (and the task it names). */
    public function affectedTasks(array $sc): array
    {
        $tasks = Task::findByDriver((int)$sc['driver_id'], $sc['start_date'] . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($sc['end_date'] . ' +1 day')), [Task::DRIVER_ASSIGNED, Task::APPROVED]);
        if ($sc['task_id'] && !in_array((int)$sc['task_id'], array_map('intval', array_column($tasks, 'task_id')), true) && ($t = Task::findByID((int)$sc['task_id']))) { $tasks[] = $t; }
        return $tasks;
    }

    /** Approves or rejects a schedule change. */
    public function decideScheduleChange(array $user, int $changeID, string $decision, string $comment = ''): array
    {
        $comment = trim($comment);
        $sc = ScheduleChange::findByID($changeID);
        if (!$sc || $sc['status'] !== ScheduleChange::PENDING) { return wfFail('This schedule change is not pending.'); }
        $dates = fmtD($sc['start_date']) . ($sc['end_date'] !== $sc['start_date'] ? ' - ' . fmtD($sc['end_date']) : '');
        if ($decision === 'Approve') {
            ScheduleChange::updateStatus($changeID, ScheduleChange::APPROVED, (int)$user['user_id']);
            $affected = $this->affectedTasks($sc);
            $this->notifier->notify([(int)$sc['driver_id']], "Your schedule change for $dates was approved. You will not be given tasks on those dates.", 'driver/schedule_change.php');
            if ($affected) {
                $list = implode('; ', array_map(fn($t) => fmtPeriod($t['start_datetime'], $t['end_datetime']) . ' ' . $t['plate_no'], $affected));
                $this->notifier->notifyRole(User::AA, "{$sc['driver_name']} has an approved schedule change for $dates. Please replace the driver of: $list.", 'admin/schedule.php?view=replace');
            }
            return wfOk('The schedule change was approved.' . ($affected ? ' ' . count($affected) . ' task(s) were sent to the Administrative Assistant to replace the driver.' : ''));
        }
        if ($comment === '') { return wfFail('The reason is required.'); }
        ScheduleChange::updateStatus($changeID, ScheduleChange::REJECTED, (int)$user['user_id'], $comment);
        $this->notifier->notify([(int)$sc['driver_id']], "Your schedule change for $dates was rejected. Reason: $comment", 'driver/schedule_change.php');
        return wfOk('The schedule change was rejected.');
    }
}
