<?php
// ============================================================
// SDD_CLASS_208 - DriverTaskController (Domain Layer, SDD_PKG_200)
// Rules for the driver's tasks (UC023), task reports (UC024) and schedule change requests (UC022).
// ============================================================

class DriverTaskController
{
    private NotificationService $notifier;

    public function __construct()
    {
        $this->notifier = new NotificationService();
    }

    /** The driver's tasks. $period: upcoming (approved, not ended), report (approved, started, no report) or past (completed). */
    public function listAssignedTasks(int $driverID, string $period): array
    {
        $now = date('Y-m-d H:i:s');
        return match ($period) {
            'past'   => Task::findByDriver($driverID, null, null, [Task::COMPLETED], 'DESC'),
            'report' => array_values(array_filter(Task::findByDriver($driverID, null, $now, [Task::APPROVED]), fn($t) => !TaskReport::findByTask((int)$t['task_id']))),
            default  => Task::findByDriver($driverID, $now, null, [Task::APPROVED]),
        };
    }

    /** The driver's counts this month: request, internal, welfare, weekend, tasks, hours. */
    public function monthCounts(int $driverID): array
    {
        return Driver::monthCounts(date('Y-m'))[$driverID] ?? ['request' => 0, 'internal' => 0, 'welfare' => 0, 'weekend' => 0, 'tasks' => 0, 'hours' => 0];
    }

    /** The pending schedule change that names this task, or null. */
    public function pendingChangeForTask(int $driverID, int $taskID): ?array
    {
        foreach (ScheduleChange::findByDriver($driverID) as $sc) { if ($sc['status'] === ScheduleChange::PENDING && (int)$sc['task_id'] === $taskID) { return $sc; } }
        return null;
    }

    /** The full details of one task of the driver, or null. */
    public function getTaskDetails(int $driverID, int $taskID): ?array
    {
        $t = Task::findByID($taskID);
        return ($t && (int)$t['driver_id'] === $driverID && in_array($t['status'], [Task::APPROVED, Task::COMPLETED, Task::DRIVER_ASSIGNED], true)) ? $t : null;
    }

    public function getReport(int $taskID): ?array
    {
        return TaskReport::findByTask($taskID);
    }

    /** May the driver create the report now (own approved task whose departure time has passed, no report yet)? */
    public function canCreateReport(array $t, int $driverID): bool
    {
        return (int)$t['driver_id'] === $driverID && $t['status'] === Task::APPROVED && strtotime($t['start_datetime']) <= time() && !TaskReport::findByTask((int)$t['task_id']);
    }

    /** May the driver still correct the report (on the day it was submitted)? */
    public function canUpdateReport(array $report): bool
    {
        return substr($report['submitted_date'], 0, 10) === date('Y-m-d');
    }

    /** Checks the report form. Returns [errors, values]. $minMileage: the vehicle's mileage before the trip. */
    private function checkReport(array $f, int $minMileage): array
    {
        $errors = [];
        $dep = str_replace('T', ' ', (string)($f['actual_depart'] ?? ''));
        $ret = str_replace('T', ' ', (string)($f['actual_return'] ?? ''));
        $okD = (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $dep) && strtotime($dep);
        $okR = (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $ret) && strtotime($ret);
        if (!$okD) { $errors['actual_depart'] = 'Enter the actual departure time.'; }
        if (!$okR) { $errors['actual_return'] = 'Enter the actual return time.'; }
        if ($okD && $okR && strtotime($ret) <= strtotime($dep)) { $errors['actual_return'] = 'The return time must be later than the departure time.'; }
        $end = trim((string)($f['end_mileage'] ?? ''));
        if ($end === '' || !ctype_digit($end)) { $errors['end_mileage'] = 'Enter the end mileage.'; }
        elseif ((int)$end < $minMileage) { $errors['end_mileage'] = 'The mileage is not valid: it is lower than ' . number_format($minMileage) . ' km.'; }
        $fuel = (string)($f['fuel_level'] ?? '');
        if (!in_array($fuel, Vehicle::FUEL_LEVELS, true)) { $errors['fuel_level'] = 'Select the fuel level.'; }
        $cond = in_array($f['vehicle_condition'] ?? '', ['Good', 'Problem'], true) ? $f['vehicle_condition'] : '';
        if ($cond === '') { $errors['vehicle_condition'] = 'Select the vehicle condition.'; }
        $d = ['actual_depart' => $dep, 'actual_return' => $ret, 'end_mileage' => (int)$end, 'fuel_level' => $fuel, 'vehicle_condition' => $cond,
              'problem_type' => null, 'problem_desc' => null, 'can_be_used' => 1];
        if ($cond === 'Problem') {
            $d['problem_type'] = (string)($f['problem_type'] ?? '');
            if (!in_array($d['problem_type'], TaskReport::PROBLEM_TYPES, true)) { $errors['problem_type'] = 'Select the problem type.'; }
            $d['problem_desc'] = mb_substr(trim((string)($f['problem_desc'] ?? '')), 0, 255);
            if ($d['problem_desc'] === '') { $errors['problem_desc'] = 'Describe the problem.'; }
            if (!in_array($f['can_be_used'] ?? '', ['0', '1'], true)) { $errors['can_be_used'] = 'Say whether the vehicle can still be used.'; } else { $d['can_be_used'] = (int)$f['can_be_used']; }
        }
        return [$errors, $d];
    }

    /** UC024A: saves the task report and updates the vehicle (SDD 5.19.2.1). */
    public function submitReport(int $driverID, int $taskID, array $f, ?array $photo): array
    {
        $t = Task::findByID($taskID);
        if (!$t || !$this->canCreateReport($t, $driverID)) { return wfFail('The report cannot be created for this task.'); }
        [$errors, $d] = $this->checkReport($f, (int)$t['current_mileage']);
        if ($errors) { return wfFail('Please fill in all required fields correctly.', ['errors' => $errors]); }
        if ($d['vehicle_condition'] === 'Problem' && $photo && (int)($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = saveUploadedImage($photo, 'reports', 5242880);
            if (!$up['ok']) { return wfFail($up['error'], ['errors' => ['photo' => $up['error']]]); }
            $d['photo'] = $up['path'];
        }
        Database::beginTransaction();
        try {
            TaskReport::create(['task_id' => $taskID] + $d);
            Vehicle::updateFromReport((int)$t['vehicle_id'], $d['end_mileage'], $d['fuel_level'], (bool)$d['can_be_used']);
            Task::updateStatus($taskID, Task::COMPLETED);
            if ($t['request_id'] && !array_filter(Task::findByRequest((int)$t['request_id']), fn($x) => $x['status'] !== Task::COMPLETED && $x['driver_id'])) {
                $r = Request::findByID((int)$t['request_id']);
                if ($r['status'] === Request::APPROVED) { Request::updateStatus((int)$t['request_id'], Request::COMPLETED, null, 'Every task of the request was reported'); }
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            appLog('error', 'submitReport: ' . $e->getMessage());
            return wfFail('The report could not be saved. Please try again.');
        }
        $this->tellAssistant($t, $d, false);
        return wfOk('The report was saved and the task is Completed.');
    }

    /** UC024C: corrects a report (on the day it was submitted); the vehicle follows its latest report. */
    public function updateReport(int $driverID, int $reportID, array $f, ?array $photo): array
    {
        $rep = TaskReport::findByID($reportID);
        $t = $rep ? Task::findByID((int)$rep['task_id']) : null;
        if (!$rep || !$t || (int)$t['driver_id'] !== $driverID) { return wfFail('This is not your report.'); }
        if (!$this->canUpdateReport($rep)) { return wfFail('A report can only be corrected on the day it was submitted.'); }
        $before = TaskReport::previousMileage((int)$t['vehicle_id'], $reportID, $rep['actual_depart']) ?? 0;
        [$errors, $d] = $this->checkReport($f, $before);
        if ($errors) { return wfFail('Please fill in all required fields correctly.', ['errors' => $errors]); }
        $d['photo'] = $d['vehicle_condition'] === 'Problem' ? $rep['photo'] : null;
        if ($d['vehicle_condition'] === 'Problem' && $photo && (int)($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = saveUploadedImage($photo, 'reports', 5242880);
            if (!$up['ok']) { return wfFail($up['error'], ['errors' => ['photo' => $up['error']]]); }
            $d['photo'] = $up['path'];
        }
        TaskReport::update($reportID, $d);
        if (TaskReport::isLatestForVehicle($reportID, (int)$t['vehicle_id'])) {
            Vehicle::updateFromReport((int)$t['vehicle_id'], $d['end_mileage'], $d['fuel_level'], (bool)$d['can_be_used']);
        }
        $this->tellAssistant($t, $d, true);
        return wfOk('The report was updated.');
    }

    private function tellAssistant(array $t, array $d, bool $updated): void
    {
        $what = ($t['task_source'] === Task::REQUEST ? 'Request task' : ($t['task_source'] === Task::WELFARE ? 'Welfare duty' : $t['task_type'])) . ', ' . fmtDT($t['start_datetime']);
        $msg = ($updated ? 'Task report updated: ' : 'Task report: ') . "$what by {$t['driver_name']}. End mileage " . number_format($d['end_mileage']) . " km, fuel {$d['fuel_level']}.";
        if ($d['vehicle_condition'] === 'Problem') {
            $msg = "VEHICLE PROBLEM ({$d['problem_type']}) on {$t['plate_no']}: {$d['problem_desc']}. " . ($d['can_be_used'] ? 'It can still be used.' : 'It cannot be used and is now Under Maintenance.') . ' ' . $msg;
        }
        $this->notifier->notifyRole(User::AA, $msg, 'admin/task.php?id=' . $t['task_id']);
    }

    // ---------------------------------------------------------------- UC022 Request Schedule Change

    public function listScheduleChanges(int $driverID): array
    {
        return ScheduleChange::findByDriver($driverID);
    }

    /**
     * Saves a schedule change and sends it to the Head of Admin (SDD 5.19.2.2). $f: start_date, end_date, reason, task_id
     * (Request Change from a task: the dates are the task's).
     */
    public function requestScheduleChange(int $driverID, array $f): array
    {
        $reason = trim((string)($f['reason'] ?? ''));
        $taskID = (int)($f['task_id'] ?? 0);
        $start = (string)($f['start_date'] ?? ''); $end = (string)($f['end_date'] ?? '') ?: $start;
        if ($taskID) {
            $t = Task::findByID($taskID);
            if (!$t || (int)$t['driver_id'] !== $driverID || !in_array($t['status'], [Task::DRIVER_ASSIGNED, Task::APPROVED], true) || strtotime($t['start_datetime']) <= time()) {
                return wfFail('You can only ask to be removed from your own upcoming task.');
            }
            $start = substr($t['start_datetime'], 0, 10); $end = date('Y-m-d', strtotime($t['end_datetime']) - 1);
        }
        if ($reason === '' || !isValidDate($start) || !isValidDate($end)) { return wfFail('Please fill in all required fields.'); }
        if ($end < $start) { return wfFail('The end date cannot be earlier than the start date.'); }
        if ($start < date('Y-m-d')) { return wfFail('The selected date has already passed.'); }
        if (!$taskID) {
            $affected = Task::findByDriver($driverID, $start . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($end . ' +1 day')), [Task::DRIVER_ASSIGNED, Task::APPROVED]);
            $taskID = (int)($affected[0]['task_id'] ?? 0);
        }
        ScheduleChange::create(['driver_id' => $driverID, 'task_id' => $taskID ?: null, 'start_date' => $start, 'end_date' => $end, 'reason' => $reason]);
        $name = Driver::findByID($driverID)['name'];
        $this->notifier->notifyRole(User::HOA, "Schedule change: $name cannot work on " . fmtD($start) . ($end !== $start ? ' - ' . fmtD($end) : '') . ". Reason: $reason", 'admin/schedule_changes.php');
        return wfOk('Your schedule change request was sent to the Head of Admin.');
    }

    /** The driver cancels a Pending request (recorded as Rejected with the remark, records are never deleted). */
    public function cancelScheduleChange(int $driverID, int $changeID): array
    {
        $sc = ScheduleChange::findByID($changeID);
        if (!$sc || (int)$sc['driver_id'] !== $driverID || $sc['status'] !== ScheduleChange::PENDING) { return wfFail('Only a Pending request can be cancelled.'); }
        ScheduleChange::updateStatus($changeID, ScheduleChange::REJECTED, $driverID, 'Cancelled by the driver');
        return wfOk('The schedule change request was cancelled.');
    }
}
