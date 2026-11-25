<?php
// ============================================================
// SDD_CLASS_207 - TaskController (Domain Layer, SDD_PKG_200)
// Rules for internal tasks (UC004: Workshop, Refuelling, Test Drive) and weekly welfare duty schedules (UC006: a
// 24-hour standby duty from 12:00 am, car or van). Both reserve the vehicle for their period.
// ============================================================

class TaskController
{
    private NotificationService $notifier;
    private RecommendationService $recommender;

    public function __construct()
    {
        $this->notifier = new NotificationService();
        $this->recommender = new RecommendationService();
    }

    // ---------------------------------------------------------------- UC004 Manage Internal Task

    public function getTask(int $taskID): ?array
    {
        return Task::findByID($taskID);
    }

    /** The report of a task, or null. */
    public function getReport(int $taskID): ?array
    {
        return TaskReport::findByTask($taskID);
    }

    /** The driver replacements of a task (latest first). */
    public function getReplacements(int $taskID): array
    {
        return DriverReplacement::findByTask($taskID);
    }

    public function listInternalTasks(array $filter = []): array
    {
        return Task::findInternal($filter);
    }

    /** Is this vehicle free for [start, end) (not held by a reservation or another task, and a unit of its type left)? */
    private function vehicleFree(array $v, string $start, string $end, int $exceptTaskID = 0): bool
    {
        $free = in_array((int)$v['vehicle_id'], array_map('intval', array_column(Vehicle::findFree($v['type'], $start, $end, 0, $exceptTaskID), 'vehicle_id')), true);
        if (!$free) { return false; }
        // a unit must also be left for the reservations of the type that have no vehicle yet
        $own = $exceptTaskID ? Task::findByID($exceptTaskID) : null;
        $ownCounts = $own && (int)$own['vehicle_id'] && Vehicle::findByID((int)$own['vehicle_id'])['type'] === $v['type'] && $own['start_datetime'] < $end && $own['end_datetime'] > $start;
        return VehicleReservation::countUnitsLeft($v['type'], $start, $end) + ($ownCounts ? 1 : 0) >= 1;
    }

    /** Checks the form of an internal task. Returns [errors, values]. */
    private function checkInternal(array $f): array
    {
        $errors = [];
        $type = (string)($f['task_type'] ?? '');
        if (!in_array($type, Task::INTERNAL_TYPES, true)) { $errors['task_type'] = 'Select the task type.'; }
        $start = joinDateTime((string)($f['task_date'] ?? ''), (string)($f['start_time'] ?? ''));
        $end = joinDateTime((string)($f['task_date'] ?? ''), (string)($f['end_time'] ?? ''));
        if (!$start) { $errors['start_time'] = 'Enter a valid date and start time.'; }
        if (!$end) { $errors['end_time'] = 'Enter a valid end time.'; }
        if ($start && $end && $end <= $start) { $errors['end_time'] = 'The end time must be after the start time.'; }
        if ($start && strtotime($start) < time() - 60) { $errors['task_date'] = 'The task cannot start in the past.'; }
        $desc = trim((string)($f['description'] ?? ''));
        if ($desc === '') { $errors['description'] = 'Enter the description.'; }
        $vid = (int)($f['vehicle_id'] ?? 0);
        if ($vid <= 0) { $errors['vehicle_id'] = 'Select the vehicle.'; }
        return [$errors, ['task_type' => $type, 'start_datetime' => $start, 'end_datetime' => $end, 'description' => mb_substr($desc, 0, 255), 'vehicle_id' => $vid]];
    }

    /** UC004A: adds an internal task (Waiting for Driver) and reserves the vehicle. */
    public function addInternalTask(array $user, array $f): array
    {
        [$errors, $d] = $this->checkInternal($f);
        if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }
        Database::beginTransaction();
        $v = Vehicle::findByID($d['vehicle_id'], true);
        if (!$v || !$this->vehicleFree($v, $d['start_datetime'], $d['end_datetime'])) {
            Database::rollback();
            return wfFail('The vehicle is not available for that period.', ['errors' => ['vehicle_id' => 'Not available for that period.']]);
        }
        $id = Task::create(['task_source' => Task::INTERNAL, 'status' => Task::WAITING] + $d);
        Database::commit();
        return wfOk('The task was added with the Waiting for Driver status and the vehicle was reserved.', ['task_id' => $id]);
    }

    /** UC004C: updates an internal task that is not completed; the assigned driver is notified. */
    public function updateInternalTask(int $taskID, array $f): array
    {
        $old = Task::findByID($taskID);
        if (!$old || $old['task_source'] !== Task::INTERNAL || in_array($old['status'], [Task::COMPLETED, Task::CANCELLED], true)) { return wfFail('This task can no longer be updated.'); }
        [$errors, $d] = $this->checkInternal($f);
        if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }
        Database::beginTransaction();
        $v = Vehicle::findByID($d['vehicle_id'], true);
        if (!$v || !$this->vehicleFree($v, $d['start_datetime'], $d['end_datetime'], $taskID)) {
            Database::rollback();
            return wfFail('The vehicle is not available for the new period.', ['errors' => ['vehicle_id' => 'Not available for that period.']]);
        }
        if ($old['driver_id'] && in_array($old['status'], [Task::DRIVER_ASSIGNED, Task::APPROVED], true)
            && ($why = $this->recommender->driverUnavailableReason((int)$old['driver_id'], $v['licence_required'], $d['start_datetime'], $d['end_datetime'], [$taskID]))) {
            Database::rollback();
            return wfFail("The assigned driver {$old['driver_name']} cannot do the new period: $why.");
        }
        Task::update($taskID, $d);
        Database::commit();
        if ($old['driver_id'] && in_array($old['status'], [Task::DRIVER_ASSIGNED, Task::APPROVED], true)) {
            $this->notifier->notify([(int)$old['driver_id']], 'Your internal task was updated. Now: ' . $d['task_type'] . ', ' . fmtPeriod($d['start_datetime'], $d['end_datetime']) . ', ' . $v['plate_no'] . '.', 'driver/task.php?id=' . $taskID);
            return wfOk('The task was updated and the driver was notified.');
        }
        return wfOk('The task was updated.');
    }

    /** UC004D: cancels an internal task that is not completed and releases the vehicle (records are never deleted). */
    public function deleteInternalTask(int $taskID): array
    {
        $t = Task::findByID($taskID);
        if (!$t || $t['task_source'] !== Task::INTERNAL) { return wfFail('Unknown task.'); }
        if ($t['status'] === Task::COMPLETED || TaskReport::findByTask($taskID)) { return wfFail("A completed task cannot be deleted because it is counted in the driver's task count."); }
        if ($t['status'] === Task::CANCELLED) { return wfFail('The task is already deleted.'); }
        Task::updateStatus($taskID, Task::CANCELLED);
        if ($t['driver_id'] && in_array($t['status'], [Task::DRIVER_ASSIGNED, Task::APPROVED], true)) {
            $this->notifier->notify([(int)$t['driver_id']], 'Your internal task was deleted: ' . $t['task_type'] . ', ' . fmtPeriod($t['start_datetime'], $t['end_datetime']) . '.', 'driver/tasks.php');
        }
        return wfOk('The task was deleted and the vehicle reservation was released.');
    }

    // ---------------------------------------------------------------- UC006 Manage Welfare Duty

    /** The 24-hour period of a welfare duty day: 12:00 am to 12:00 am the next day. */
    public function welfareWindow(string $date): array
    {
        return [$date . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($date . ' +1 day'))];
    }

    /** Has the duty of this day started (it can no longer be changed)? */
    public function dayPassed(string $date): bool
    {
        return strtotime($this->welfareWindow($date)[0]) <= time();
    }

    /** Cars and vans free for the duty of a day. */
    public function welfareVehicles(string $date, int $exceptTaskID = 0): array
    {
        [$s, $e] = $this->welfareWindow($date);
        return array_values(array_filter(Vehicle::findFree(null, $s, $e, 0, $exceptTaskID), fn($v) => in_array($v['type'], ['Car', 'Van'], true) && $this->vehicleFree($v, $s, $e, $exceptTaskID)));
    }

    /** UC006A: creates the seven days of a week (days that have passed are skipped) - SDD 5.18.2.1. */
    public function createWelfareWeek(array $user, string $weekStart, array $vehicles): array
    {
        if (weekMonday($weekStart) !== $weekStart) { return wfFail('Select a week.'); }
        if ($weekStart < weekMonday(date('Y-m-d'))) { return wfFail('A schedule cannot be created for a past week.'); }
        if (WelfareDuty::findByWeek($weekStart)) { return wfFail('A schedule already exists for the selected week.'); }
        $plan = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("$weekStart +$i day"));
            if ($this->dayPassed($d)) { continue; }
            $vid = (int)($vehicles[$d] ?? 0);
            if ($vid <= 0) { return wfFail('Select the vehicle for ' . date('l, d M', strtotime($d)) . '.'); }
            $plan[$d] = $vid;
        }
        if (!$plan) { return wfFail('Every day of this week has passed.'); }
        Database::beginTransaction();
        try {
            foreach ($plan as $d => $vid) {
                $v = Vehicle::findByID($vid, true);
                [$s, $e] = $this->welfareWindow($d);
                if (!$v || !in_array($v['type'], ['Car', 'Van'], true)) { Database::rollback(); return wfFail('Only cars and vans can be used for welfare duty.'); }
                if (!$this->vehicleFree($v, $s, $e)) { Database::rollback(); return wfFail($v['plate_no'] . ' is not available on ' . fmtD($d) . '.'); }
                $plan[$d] = $v;
            }
            $dutyID = WelfareDuty::create($weekStart, (int)$user['user_id']);
            foreach ($plan as $d => $v) {
                [$s, $e] = $this->welfareWindow($d);
                Task::create(['task_source' => Task::WELFARE, 'duty_id' => $dutyID, 'vehicle_id' => (int)$v['vehicle_id'], 'start_datetime' => $s, 'end_datetime' => $e,
                              'description' => '24-hour welfare duty', 'status' => Task::WAITING]);
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            appLog('error', 'createWelfareWeek: ' . $e->getMessage());
            return wfFail('The schedule could not be saved. Please try again.');
        }
        return wfOk('The welfare duty schedule was saved with the Waiting for Driver status and the vehicles were reserved.');
    }

    /** Returns the week and its seven days [Y-m-d => task or null]. */
    public function getWelfareWeek(string $weekStart): array
    {
        $week = WelfareDuty::findByWeek($weekStart);
        $days = [];
        for ($i = 0; $i < 7; $i++) { $days[date('Y-m-d', strtotime("$weekStart +$i day"))] = null; }
        if ($week) { foreach (Task::findByDuty((int)$week['duty_id']) as $t) { $days[substr($t['start_datetime'], 0, 10)] = $t; } }
        return ['week' => $week, 'days' => $days];
    }

    /** The days (tasks) of a week by its ID. */
    public function getWelfareWeekByID(int $dutyID): array
    {
        return Task::findByDuty($dutyID);
    }

    /** UC006C: changes the vehicle of a day (or sets the vehicle of a day without duty). The assigned driver is notified. */
    public function updateWelfareDay(string $weekStart, string $date, int $vehicleID): array
    {
        ['week' => $week, 'days' => $days] = $this->getWelfareWeek($weekStart);
        if (!$week) { return wfFail('No schedule exists for the selected week.'); }
        if (!array_key_exists($date, $days)) { return wfFail('That day is not in the week.'); }
        if ($this->dayPassed($date)) { return wfFail('The duty of this day has started and cannot be changed.'); }
        $t = $days[$date];
        Database::beginTransaction();
        $v = Vehicle::findByID($vehicleID, true);
        [$s, $e] = $this->welfareWindow($date);
        if (!$v || !in_array($v['type'], ['Car', 'Van'], true)) { Database::rollback(); return wfFail('Only cars and vans can be used for welfare duty.'); }
        if (!$this->vehicleFree($v, $s, $e, $t ? (int)$t['task_id'] : 0)) { Database::rollback(); return wfFail($v['plate_no'] . ' is not available on ' . fmtD($date) . '.'); }
        if ($t) {
            if ($t['driver_id'] && !Driver::hasLicence(Driver::findByID((int)$t['driver_id']), $v['licence_required'], $date)) { Database::rollback(); return wfFail($t['driver_name'] . ' cannot drive ' . $v['plate_no'] . '.'); }
            Task::update((int)$t['task_id'], ['vehicle_id' => $vehicleID]);
        } else {
            Task::create(['task_source' => Task::WELFARE, 'duty_id' => (int)$week['duty_id'], 'vehicle_id' => $vehicleID, 'start_datetime' => $s, 'end_datetime' => $e,
                          'description' => '24-hour welfare duty', 'status' => Task::WAITING]);
            WelfareDuty::updateStatus((int)$week['duty_id'], WelfareDuty::WAITING);
        }
        Database::commit();
        if ($t && $t['driver_id'] && in_array($t['status'], [Task::DRIVER_ASSIGNED, Task::APPROVED], true)) {
            $this->notifier->notify([(int)$t['driver_id']], 'The vehicle of your welfare duty on ' . fmtD($date) . " was changed to {$v['plate_no']}.", 'driver/task.php?id=' . $t['task_id']);
            return wfOk('The welfare duty was updated and the driver was notified.');
        }
        return wfOk('The welfare duty was updated.');
    }

    /** UC006D: deletes the duty of one day that has not started (cancelled; the vehicle is released). */
    public function deleteWelfareDay(int $taskID): array
    {
        $t = Task::findByID($taskID);
        if (!$t || $t['task_source'] !== Task::WELFARE || $t['status'] === Task::CANCELLED) { return wfFail('Unknown duty.'); }
        if (strtotime($t['start_datetime']) <= time()) { return wfFail("A past welfare duty cannot be deleted because it is counted in the driver's task count."); }
        Task::updateStatus($taskID, Task::CANCELLED);
        if ($t['driver_id'] && in_array($t['status'], [Task::DRIVER_ASSIGNED, Task::APPROVED], true)) {
            $this->notifier->notify([(int)$t['driver_id']], 'Your welfare duty on ' . fmtD($t['start_datetime']) . ' was deleted.', 'driver/tasks.php');
        }
        return wfOk('The welfare duty was deleted and the vehicle reservation was released.');
    }

    /** Deletes a week that has not started. */
    public function deleteWelfareWeek(int $dutyID): array
    {
        $week = WelfareDuty::findByID($dutyID);
        if (!$week) { return wfFail('Unknown week.'); }
        if (strtotime($week['week_start_date']) <= time()) { return wfFail('A week that has started cannot be deleted.'); }
        $drivers = array_filter(array_map(fn($t) => in_array($t['status'], [Task::DRIVER_ASSIGNED, Task::APPROVED], true) ? (int)$t['driver_id'] : 0, Task::findByDuty($dutyID)));
        WelfareDuty::delete($dutyID);
        $this->notifier->notify($drivers, 'Your welfare duty in the week of ' . fmtD($week['week_start_date']) . ' was deleted.', 'driver/tasks.php');
        return wfOk('The welfare duty schedule of the week was deleted.');
    }
}
