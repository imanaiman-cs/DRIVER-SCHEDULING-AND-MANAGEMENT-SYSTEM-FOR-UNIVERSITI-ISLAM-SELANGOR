<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/tasks.php  -  tasks other than the request flow
//
//   UC004/UC005  internal tasks (Workshop, Refuelling, Test Drive): Waiting for Driver -> Driver Assigned -> Approved -> Completed
//   UC006/UC007  weekly welfare duty (24-hour standby, car or van): same statuses, per week and per day
//   UC008        replace the driver of an approved task (Head of Admin approves)
//   UC022/UC027  schedule change of a driver (Head of Admin approves)
//   UC024        task report (mileage, fuel level, vehicle condition)
//   UC026        Head of Admin approval of internal tasks and welfare weeks (request tasks: includes/requests.php)
//   UC002C/D     upcoming bookings of a vehicle, and moving a booking to another vehicle
// ============================================================

/** One task with vehicle, driver, request and welfare details, or null. */
function loadTask(mysqli $conn, int $id, bool $forUpdate = false): ?array
{
    return dbOne($conn, "SELECT t.*, v.plate_number, v.brand, v.model, v.vehicle_type, v.licence_required, v.current_mileage, v.fuel_level AS vehicle_fuel_level,
                                u.full_name AS driver_name, u.phone AS driver_phone, dr.user_id AS driver_user_id,
                                r.destination, r.address, r.waiting_location, r.purpose, r.passengers, r.requester_id, r.status AS request_status,
                                ru.full_name AS officer_name, ru.phone AS officer_phone, w.week_start_date, w.status AS week_status
                           FROM tasks t
                           LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                           LEFT JOIN drivers dr ON dr.driver_id = t.driver_id LEFT JOIN users u ON u.user_id = dr.user_id
                           LEFT JOIN requests r ON r.request_id = t.request_id LEFT JOIN users ru ON ru.user_id = r.requester_id
                           LEFT JOIN welfare_weeks w ON w.welfare_id = t.welfare_id
                          WHERE t.task_id = ?" . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
}

/** "Workshop, Tue 25 Nov 2026 9:00 am - 10:00 am, BDF 4400" */
function taskText(array $t): string
{
    return taskTypeLabel($t) . ', ' . fmtPeriod($t['start_dt'], $t['end_dt']) . (!empty($t['plate_number']) ? ', ' . $t['plate_number'] : '');
}

/** A pending replacement of a task, or null. */
function pendingReplacement(mysqli $conn, int $task_id): ?array
{
    return dbOne($conn, "SELECT rp.*, u.full_name AS new_driver_name FROM driver_replacements rp JOIN drivers d ON d.driver_id = rp.new_driver_id
                           JOIN users u ON u.user_id = d.user_id WHERE rp.task_id = ? AND rp.status = 'pending' LIMIT 1", [$task_id]);
}

// ------------------------------------------------------------
// UC004 Manage Internal Task, UC005 Assign Internal Task
// ------------------------------------------------------------

/** Adds (id 0) or updates an internal task. $in: task_type, task_date, start_time, end_time, description, vehicle_id */
function saveInternalTask(mysqli $conn, int $id, array $in): array
{
    $errors = [];
    $type = (string)($in['task_type'] ?? '');
    if (!isset(INTERNAL_TASK_TYPES[$type])) { $errors['task_type'] = 'Select the task type.'; }
    $start = joinDateTime((string)($in['task_date'] ?? ''), (string)($in['start_time'] ?? ''));
    $end   = joinDateTime((string)($in['task_date'] ?? ''), (string)($in['end_time'] ?? ''));
    if (!$start) { $errors['start_time'] = 'Enter a valid date and start time.'; }
    if (!$end) { $errors['end_time'] = 'Enter a valid end time.'; }
    if ($start && $end && $end <= $start) { $errors['end_time'] = 'The end time must be after the start time.'; }
    if ($start && strtotime($start) < time() - 60) { $errors['task_date'] = 'The task cannot start in the past.'; }
    $desc = trim((string)($in['description'] ?? ''));
    if ($desc === '') { $errors['description'] = 'Enter the description.'; }
    $vid = (int)($in['vehicle_id'] ?? 0);
    if ($vid <= 0) { $errors['vehicle_id'] = 'Select the vehicle.'; }
    if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }

    $uid = (int)$_SESSION['user_id'];
    $conn->begin_transaction();
    try {
        $old = $id ? loadTask($conn, $id, true) : null;
        if ($id && (!$old || $old['task_source'] !== 'internal' || in_array($old['status'], ['completed', 'cancelled'], true))) {
            $conn->rollback(); return wfFail('This task can no longer be updated.');
        }
        $veh = dbOne($conn, 'SELECT * FROM vehicles WHERE vehicle_id = ? FOR UPDATE', [$vid]);
        if (!$veh) { $conn->rollback(); return wfFail('Unknown vehicle.', ['errors' => ['vehicle_id' => 'Unknown vehicle.']]); }
        if ($why = vehicleBusyReason($conn, $vid, $start, $end, 0, $id)) {
            $conn->rollback();
            return wfFail('The vehicle is not available for that period (' . $why . ').', ['errors' => ['vehicle_id' => 'Not available: ' . $why . '.']]);
        }
        if ($old && $old['driver_id'] && ($why = driverUnavailableReason($conn, (int)$old['driver_id'], $veh['licence_required'], $start, $end, 0, $id))) {
            $conn->rollback();
            return wfFail('The assigned driver ' . $old['driver_name'] . ' cannot do the new period: ' . $why . '.');
        }
        if ($id) {
            dbExec($conn, 'UPDATE tasks SET task_type = ?, vehicle_id = ?, start_dt = ?, end_dt = ?, description = ? WHERE task_id = ?', [$type, $vid, $start, $end, mb_substr($desc, 0, 255), $id]);
        } else {
            [, $id] = dbExec($conn, "INSERT INTO tasks (task_source, task_type, vehicle_id, start_dt, end_dt, description, status, created_by) VALUES ('internal', ?, ?, ?, ?, ?, 'waiting', ?)",
                [$type, $vid, $start, $end, mb_substr($desc, 0, 255), $uid]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('saveInternalTask: ' . $e->getMessage());
        return wfFail('The task could not be saved. Please try again.');
    }
    if ($old && $old['driver_id']) {
        $new = loadTask($conn, $id);
        notifyUsers($conn, [(int)$old['driver_user_id']], 'Your internal task was updated. Now: ' . taskText($new) . '.', 'driver/task.php?id=' . $id);
    }
    return wfOk($old ? 'The task was updated' . ($old['driver_id'] ? ' and the driver was notified.' : '.') : 'The task was added with the Waiting for Driver status and the vehicle was reserved.', ['task_id' => $id]);
}

function deleteInternalTask(mysqli $conn, int $id): array
{
    $t = loadTask($conn, $id);
    if (!$t || $t['task_source'] !== 'internal') { return wfFail('Unknown task.'); }
    if ($t['status'] === 'completed') { return wfFail('A completed task cannot be deleted because it is counted in the driver\'s task count.'); }
    if (dbValue($conn, 'SELECT 1 FROM task_reports WHERE task_id = ?', [$id])) { return wfFail('A task with a report cannot be deleted.'); }
    dbExec($conn, 'DELETE FROM tasks WHERE task_id = ?', [$id]);
    if ($t['driver_id']) {
        notifyUsers($conn, [(int)$t['driver_user_id']], 'Your internal task was deleted: ' . taskText($t) . '.', 'driver/tasks.php');
    }
    return wfOk('The task was deleted and the vehicle reservation was released.');
}

/** UC005: assign a driver to an internal task (Waiting for Driver -> Driver Assigned). */
function assignInternalTask(mysqli $conn, int $id, int $driver_id): array
{
    $conn->begin_transaction();
    $t = loadTask($conn, $id, true);
    if (!$t || $t['task_source'] !== 'internal' || $t['status'] !== 'waiting') { $conn->rollback(); return wfFail('This task is not waiting for a driver.'); }
    if ($driver_id <= 0) { $conn->rollback(); return wfFail('Select a driver.'); }
    if ($why = driverUnavailableReason($conn, $driver_id, $t['licence_required'], $t['start_dt'], $t['end_dt'], 0, $id)) {
        $conn->rollback(); return wfFail(driverName($conn, $driver_id) . ' cannot take this task: ' . $why . '.');
    }
    dbExec($conn, "UPDATE tasks SET driver_id = ?, status = 'driver_assigned', reject_reason = NULL WHERE task_id = ?", [$driver_id, $id]);
    $conn->commit();
    notifyUsers($conn, headAdminUserIds($conn), 'Driver assignment of internal task ' . taskText($t) . ' (' . driverName($conn, $driver_id) . ') is waiting for your approval.', 'admin/approvals.php?tab=internal');
    return wfOk('The driver was assigned and the task was sent to the Head of Admin for approval.');
}

/** UC026 for an internal task. */
function hoaDecideTask(mysqli $conn, int $id, bool $approve, string $reason = ''): array
{
    $reason = trim($reason);
    $conn->begin_transaction();
    $t = loadTask($conn, $id, true);
    if (!$t || $t['task_source'] !== 'internal' || $t['status'] !== 'driver_assigned') { $conn->rollback(); return wfFail('This task is not waiting for approval.'); }
    if ($approve) {
        dbExec($conn, "UPDATE tasks SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE task_id = ?", [(int)$_SESSION['user_id'], $id]);
        $conn->commit();
        notifyUsers($conn, [(int)$t['driver_user_id']], 'New internal task: ' . taskText($t) . '. ' . $t['description'], 'driver/task.php?id=' . $id, 'assigned');
        notifyUsers($conn, assistantUserIds($conn), 'The Head of Admin approved internal task ' . taskText($t) . ' (' . $t['driver_name'] . ').', 'admin/internal_tasks.php');
        return wfOk('The internal task was approved and the driver was notified.');
    }
    if ($reason === '') { $conn->rollback(); return wfFail('The reason is required.'); }
    dbExec($conn, "UPDATE tasks SET status = 'waiting', driver_id = NULL, reject_reason = ?, rejected_at = NOW() WHERE task_id = ?", [mb_substr($reason, 0, 500), $id]);
    $conn->commit();
    notifyUsers($conn, assistantUserIds($conn), 'The Head of Admin returned the driver assignment of internal task ' . taskText($t) . ". Reason: $reason", 'admin/internal_tasks.php?assign=' . $id);
    return wfOk('The task was returned to the Administrative Assistant.');
}

// ------------------------------------------------------------
// UC006 Manage Welfare Duty, UC007 Assign Welfare Duty Task
// ------------------------------------------------------------

/** The 24-hour window of a welfare duty day. */
function welfareWindow(mysqli $conn, string $date): array
{
    $s = $date . ' ' . substr(getSetting($conn, 'WELFARE_START') . ':00', 0, 8);
    return [$s, date('Y-m-d H:i:s', strtotime($s . ' +1 day'))];
}

function loadWelfareWeek(mysqli $conn, string $monday): ?array
{
    return dbOne($conn, 'SELECT * FROM welfare_weeks WHERE week_start_date = ?', [$monday]);
}

/** The duty days of a week: [Y-m-d => task row (with vehicle and driver) or null] for the seven days. */
function welfareDays(mysqli $conn, ?array $week, string $monday): array
{
    $days = [];
    for ($i = 0; $i < 7; $i++) { $days[date('Y-m-d', strtotime("$monday +$i day"))] = null; }
    if ($week) {
        foreach (dbAll($conn, "SELECT t.*, v.plate_number, v.brand, v.model, v.vehicle_type, v.licence_required, u.full_name AS driver_name, dr.user_id AS driver_user_id
                                 FROM tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id LEFT JOIN drivers dr ON dr.driver_id = t.driver_id
                                 LEFT JOIN users u ON u.user_id = dr.user_id WHERE t.welfare_id = ? AND t.status <> 'cancelled' ORDER BY t.start_dt", [(int)$week['welfare_id']]) as $t) {
            $days[substr($t['start_dt'], 0, 10)] = $t;
        }
    }
    return $days;
}

/** Has the duty of this day started (it can no longer be changed)? */
function welfareDayPassed(mysqli $conn, string $date): bool
{
    return strtotime(welfareWindow($conn, $date)[0]) <= time();
}

/** Car or van that may be used for welfare duty? */
function checkWelfareVehicle(mysqli $conn, int $vid, string $date, int $exceptTask = 0): ?string
{
    $v = dbOne($conn, 'SELECT * FROM vehicles WHERE vehicle_id = ?', [$vid]);
    if (!$v) { return 'Unknown vehicle.'; }
    if (!in_array($v['vehicle_type'], WELFARE_VEHICLE_TYPES, true)) { return 'Only cars and vans can be used for welfare duty.'; }
    [$s, $e] = welfareWindow($conn, $date);
    $why = vehicleBusyReason($conn, $vid, $s, $e, 0, $exceptTask);
    return $why ? $v['plate_number'] . ' is not available on ' . fmtD($date) . ' (' . $why . ').' : null;
}

/** UC006A: create the schedule of a week. $vehicles = [Y-m-d => vehicle_id] (days that have passed are skipped). */
function createWelfareWeek(mysqli $conn, string $monday, array $vehicles): array
{
    if (weekMonday($monday) !== $monday) { return wfFail('Select a week.'); }
    if ($monday < weekMonday(date('Y-m-d'))) { return wfFail('A schedule cannot be created for a past week.'); }
    if (loadWelfareWeek($conn, $monday)) { return wfFail('A schedule already exists for the selected week.'); }
    $plan = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("$monday +$i day"));
        if (welfareDayPassed($conn, $d)) { continue; }
        $vid = (int)($vehicles[$d] ?? 0);
        if ($vid <= 0) { return wfFail('Select the vehicle for ' . date('l, d M', strtotime($d)) . '.'); }
        $plan[$d] = $vid;
    }
    if (!$plan) { return wfFail('Every day of this week has passed.'); }
    $conn->begin_transaction();
    try {
        foreach ($plan as $d => $vid) {
            dbOne($conn, 'SELECT vehicle_id FROM vehicles WHERE vehicle_id = ? FOR UPDATE', [$vid]);
            if ($why = checkWelfareVehicle($conn, $vid, $d)) { $conn->rollback(); return wfFail($why); }
        }
        [, $wid] = dbExec($conn, "INSERT INTO welfare_weeks (week_start_date, status, created_by) VALUES (?, 'waiting', ?)", [$monday, (int)$_SESSION['user_id']]);
        foreach ($plan as $d => $vid) {
            [$s, $e] = welfareWindow($conn, $d);
            dbExec($conn, "INSERT INTO tasks (task_source, task_type, welfare_id, vehicle_id, start_dt, end_dt, status, created_by) VALUES ('welfare', 'welfare', ?, ?, ?, ?, 'waiting', ?)",
                [$wid, $vid, $s, $e, (int)$_SESSION['user_id']]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('createWelfareWeek: ' . $e->getMessage());
        return wfFail('The schedule could not be saved. Please try again.');
    }
    return wfOk('The welfare duty schedule was saved with the Waiting for Driver status and the vehicles were reserved.');
}

/** UC006C: change the vehicle of a day (or set the vehicle of a day that has none). */
function updateWelfareDay(mysqli $conn, string $monday, string $date, int $vid): array
{
    $week = loadWelfareWeek($conn, $monday);
    if (!$week) { return wfFail('No schedule exists for the selected week.'); }
    if (welfareDayPassed($conn, $date)) { return wfFail('The duty of this day has started and cannot be changed.'); }
    $days = welfareDays($conn, $week, $monday);
    if (!array_key_exists($date, $days)) { return wfFail('That day is not in the week.'); }
    $t = $days[$date];
    $conn->begin_transaction();
    dbOne($conn, 'SELECT vehicle_id FROM vehicles WHERE vehicle_id = ? FOR UPDATE', [$vid]);
    if ($why = checkWelfareVehicle($conn, $vid, $date, $t ? (int)$t['task_id'] : 0)) { $conn->rollback(); return wfFail($why); }
    if ($t) {
        if ($t['driver_id']) {
            $lic = dbValue($conn, 'SELECT licence_required FROM vehicles WHERE vehicle_id = ?', [$vid]);
            if ($why = driverUnavailableReason($conn, (int)$t['driver_id'], $lic, $t['start_dt'], $t['end_dt'], 0, (int)$t['task_id'])) {
                $conn->rollback(); return wfFail($t['driver_name'] . ' cannot use this vehicle: ' . $why . '.');
            }
        }
        dbExec($conn, 'UPDATE tasks SET vehicle_id = ? WHERE task_id = ?', [$vid, (int)$t['task_id']]);
    } else {
        [$s, $e] = welfareWindow($conn, $date);
        dbExec($conn, "INSERT INTO tasks (task_source, task_type, welfare_id, vehicle_id, start_dt, end_dt, status, created_by) VALUES ('welfare', 'welfare', ?, ?, ?, ?, 'waiting', ?)",
            [(int)$week['welfare_id'], $vid, $s, $e, (int)$_SESSION['user_id']]);
        dbExec($conn, "UPDATE welfare_weeks SET status = 'waiting' WHERE welfare_id = ?", [(int)$week['welfare_id']]);
    }
    $conn->commit();
    if ($t && $t['driver_id']) {
        $plateNo = dbValue($conn, 'SELECT plate_number FROM vehicles WHERE vehicle_id = ?', [$vid]);
        notifyUsers($conn, [(int)$t['driver_user_id']], 'The vehicle of your welfare duty on ' . fmtD($date) . " was changed to $plateNo.", 'driver/task.php?id=' . (int)$t['task_id']);
    }
    return wfOk('The welfare duty was updated' . ($t && $t['driver_id'] ? ' and the driver was notified.' : '.'));
}

/** UC006D: delete the duty of one day. */
function deleteWelfareDay(mysqli $conn, int $task_id): array
{
    $t = loadTask($conn, $task_id);
    if (!$t || $t['task_source'] !== 'welfare') { return wfFail('Unknown duty.'); }
    if (strtotime($t['start_dt']) <= time() || $t['status'] === 'completed') { return wfFail('A past welfare duty cannot be deleted because it is counted in the driver\'s task count.'); }
    dbExec($conn, 'DELETE FROM tasks WHERE task_id = ?', [$task_id]);
    if ($t['driver_id']) { notifyUsers($conn, [(int)$t['driver_user_id']], 'Your welfare duty on ' . fmtD(substr($t['start_dt'], 0, 10)) . ' was deleted.', 'driver/tasks.php'); }
    syncWelfareWeekStatus($conn, (int)$t['welfare_id']);
    return wfOk('The welfare duty was deleted and the vehicle reservation was released.');
}

/** The week's status follows its days: any day waiting -> Waiting; any driver assigned -> Driver Assigned; else Approved. */
function syncWelfareWeekStatus(mysqli $conn, int $wid): void
{
    $st = array_column(dbAll($conn, "SELECT DISTINCT status FROM tasks WHERE welfare_id = ? AND status <> 'cancelled'", [$wid]), 'status');
    $new = in_array('waiting', $st, true) ? 'waiting' : (in_array('driver_assigned', $st, true) ? 'driver_assigned' : ($st ? 'approved' : 'waiting'));
    dbExec($conn, 'UPDATE welfare_weeks SET status = ? WHERE welfare_id = ?', [$new, $wid]);
}

/** UC007: assign a driver to every waiting day of a week and send the week to the Head of Admin. $drivers = [task_id => driver_id] */
function assignWelfareWeek(mysqli $conn, string $monday, array $drivers): array
{
    $week = loadWelfareWeek($conn, $monday);
    if (!$week) { return wfFail('No schedule exists for the selected week.'); }
    $days = array_filter(welfareDays($conn, $week, $monday), fn($t) => $t && $t['status'] === 'waiting' && strtotime($t['start_dt']) > time());
    if (!$days) { return wfFail('No day of this week is waiting for a driver.'); }
    foreach ($days as $t) {
        if ((int)($drivers[(int)$t['task_id']] ?? 0) <= 0) { return wfFail('Every day needs a driver.'); }
    }
    $conn->begin_transaction();
    foreach ($days as $d => $t) {
        $did = (int)$drivers[(int)$t['task_id']];
        if ($why = driverUnavailableReason($conn, $did, $t['licence_required'], $t['start_dt'], $t['end_dt'], 0, (int)$t['task_id'])) {
            $conn->rollback(); return wfFail(driverName($conn, $did) . ' cannot take the duty on ' . fmtD($d) . ': ' . $why . '.');
        }
        dbExec($conn, "UPDATE tasks SET driver_id = ?, status = 'driver_assigned', reject_reason = NULL WHERE task_id = ?", [$did, (int)$t['task_id']]);
    }
    dbExec($conn, "UPDATE welfare_weeks SET status = 'driver_assigned', reject_reason = NULL WHERE welfare_id = ?", [(int)$week['welfare_id']]);
    $conn->commit();
    notifyUsers($conn, headAdminUserIds($conn), 'The welfare duty schedule of the week of ' . fmtD($monday) . ' is waiting for your approval.', 'admin/approvals.php?tab=welfare');
    return wfOk('The drivers were assigned and the schedule was sent to the Head of Admin for approval.');
}

/** UC026 for a welfare week. */
function hoaDecideWelfare(mysqli $conn, int $wid, bool $approve, string $reason = ''): array
{
    $reason = trim($reason);
    $week = dbOne($conn, 'SELECT * FROM welfare_weeks WHERE welfare_id = ?', [$wid]);
    if (!$week || $week['status'] !== 'driver_assigned') { return wfFail('This schedule is not waiting for approval.'); }
    $days = array_filter(welfareDays($conn, $week, $week['week_start_date']), fn($t) => $t && $t['status'] === 'driver_assigned');
    if ($approve) {
        dbExec($conn, "UPDATE tasks SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE welfare_id = ? AND status = 'driver_assigned'", [(int)$_SESSION['user_id'], $wid]);
        dbExec($conn, "UPDATE welfare_weeks SET approved_at = NOW(), approved_by = ? WHERE welfare_id = ?", [(int)$_SESSION['user_id'], $wid]);
        syncWelfareWeekStatus($conn, $wid);
        foreach ($days as $d => $t) {
            notifyUsers($conn, [(int)$t['driver_user_id']], 'Welfare duty (24 hours) on ' . date('l, d M Y', strtotime($d)) . ' from ' . date('g:i a', strtotime($t['start_dt'])) . ', vehicle ' . $t['plate_number'] . '.', 'driver/task.php?id=' . (int)$t['task_id'], 'assigned');
        }
        notifyUsers($conn, assistantUserIds($conn), 'The Head of Admin approved the welfare duty schedule of the week of ' . fmtD($week['week_start_date']) . '.', 'admin/welfare.php?week=' . $week['week_start_date']);
        return wfOk('The welfare duty schedule was approved and the drivers were notified.');
    }
    if ($reason === '') { return wfFail('The reason is required.'); }
    dbExec($conn, "UPDATE tasks SET status = 'waiting', reject_reason = ?, rejected_at = NOW() WHERE welfare_id = ? AND status = 'driver_assigned'", [mb_substr($reason, 0, 500), $wid]);
    dbExec($conn, "UPDATE welfare_weeks SET status = 'waiting', reject_reason = ?, rejected_at = NOW() WHERE welfare_id = ?", [mb_substr($reason, 0, 500), $wid]);
    notifyUsers($conn, assistantUserIds($conn), 'The Head of Admin returned the welfare duty schedule of the week of ' . fmtD($week['week_start_date']) . ". Reason: $reason", 'admin/welfare.php?week=' . $week['week_start_date']);
    return wfOk('The schedule was returned to the Administrative Assistant.');
}

// ------------------------------------------------------------
// UC008 Replace Driver
// ------------------------------------------------------------

/** Can the driver of this task be replaced now (approved, not started, no replacement waiting)? */
function canReplaceDriver(mysqli $conn, array $t): bool
{
    return $t['status'] === 'approved' && $t['driver_id'] && strtotime($t['start_dt']) > time() && !pendingReplacement($conn, (int)$t['task_id']);
}

function requestReplacement(mysqli $conn, int $task_id, int $new_driver, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') { return wfFail('The reason is required.'); }
    $t = loadTask($conn, $task_id);
    if (!$t || !canReplaceDriver($conn, $t)) { return wfFail('The driver of this task cannot be replaced (it must be approved, not started, and without a replacement waiting).'); }
    if ($new_driver === (int)$t['driver_id']) { return wfFail('Select a different driver.'); }
    if ($why = driverUnavailableReason($conn, $new_driver, $t['licence_required'], $t['start_dt'], $t['end_dt'], 0, $task_id)) {
        return wfFail(driverName($conn, $new_driver) . ' cannot take this task: ' . $why . '.');
    }
    dbExec($conn, 'INSERT INTO driver_replacements (task_id, previous_driver_id, new_driver_id, reason, created_by) VALUES (?, ?, ?, ?, ?)',
        [$task_id, (int)$t['driver_id'], $new_driver, mb_substr($reason, 0, 500), (int)$_SESSION['user_id']]);
    if ($t['request_id']) { addHistory($conn, (int)$t['request_id'], 'replacement', null, ucfirst((string)$t['trip_type']) . ' task: ' . $t['driver_name'] . ' -> ' . driverName($conn, $new_driver) . ". Reason: $reason"); }
    notifyUsers($conn, headAdminUserIds($conn), 'Driver replacement for ' . taskText($t) . ': ' . $t['driver_name'] . ' -> ' . driverName($conn, $new_driver) . " is waiting for your approval. Reason: $reason", 'admin/approvals.php?tab=replacement');
    return wfOk('The replacement was recorded and sent to the Head of Admin for approval.');
}

/** UC026 for a driver replacement. */
function hoaDecideReplacement(mysqli $conn, int $rid, bool $approve, string $reason = ''): array
{
    $reason = trim($reason);
    $rp = dbOne($conn, "SELECT * FROM driver_replacements WHERE replacement_id = ? AND status = 'pending'", [$rid]);
    if (!$rp) { return wfFail('This replacement is not waiting for approval.'); }
    $t = loadTask($conn, (int)$rp['task_id']);
    $uid = (int)$_SESSION['user_id'];
    if ($approve) {
        if (!$t || $t['status'] !== 'approved' || strtotime($t['start_dt']) <= time()) { return wfFail('The task has started or is no longer approved.'); }
        if ($why = driverUnavailableReason($conn, (int)$rp['new_driver_id'], $t['licence_required'], $t['start_dt'], $t['end_dt'], 0, (int)$t['task_id'])) {
            return wfFail(driverName($conn, (int)$rp['new_driver_id']) . ' is no longer available: ' . $why . '.');
        }
        $conn->begin_transaction();
        dbExec($conn, "UPDATE driver_replacements SET status = 'approved', decided_by = ?, decided_at = NOW() WHERE replacement_id = ?", [$uid, $rid]);
        dbExec($conn, 'UPDATE tasks SET driver_id = ?, approved_at = NOW(), approved_by = ? WHERE task_id = ?', [(int)$rp['new_driver_id'], $uid, (int)$t['task_id']]);
        $conn->commit();
        $newName = driverName($conn, (int)$rp['new_driver_id']);
        if ($t['request_id']) { addHistory($conn, (int)$t['request_id'], 'replacement_approved', null, $t['driver_name'] . " -> $newName"); }
        notifyUsers($conn, [userIdOfDriver($conn, (int)$rp['previous_driver_id'])], 'You were replaced on ' . taskText($t) . '. Reason: ' . $rp['reason'], 'driver/tasks.php');
        $new = loadTask($conn, (int)$t['task_id']);
        notifyUsers($conn, [userIdOfDriver($conn, (int)$rp['new_driver_id'])], 'New task (replacement): ' . taskText($new)
            . ($new['destination'] ? ', to ' . $new['destination'] . '. Officer: ' . $new['officer_name'] . ' ' . ($new['officer_phone'] ?? '') : '') . '.', 'driver/task.php?id=' . (int)$t['task_id'], 'assigned');
        if ($t['requester_id']) {
            notifyUsers($conn, [(int)$t['requester_id']], 'The driver of the ' . $t['trip_type'] . ' trip of your request #' . $t['request_id'] . " was changed to $newName.", 'staff/request.php?id=' . $t['request_id']);
        }
        notifyUsers($conn, assistantUserIds($conn), 'The Head of Admin approved the driver replacement on ' . taskText($t) . " ($newName).", 'admin/schedule.php');
        return wfOk('The replacement was approved. The previous driver, the new driver and the requester were notified.');
    }
    if ($reason === '') { return wfFail('The reason is required.'); }
    dbExec($conn, "UPDATE driver_replacements SET status = 'rejected', reject_reason = ?, decided_by = ?, decided_at = NOW() WHERE replacement_id = ?", [mb_substr($reason, 0, 500), $uid, $rid]);
    if ($t && $t['request_id']) { addHistory($conn, (int)$t['request_id'], 'replacement_rejected', null, $reason); }
    notifyUsers($conn, assistantUserIds($conn), 'The Head of Admin rejected the driver replacement on ' . ($t ? taskText($t) : 'a task') . ". Reason: $reason", $t ? 'admin/replace_driver.php?task=' . (int)$t['task_id'] : 'admin/schedule.php');
    return wfOk('The replacement was rejected.');
}

/**
 * Approved tasks that need a new driver: the driver has an approved schedule change on that date (UC027 3.3) or
 * asked to be removed from the task, or the driver is no longer active. Tasks with a replacement waiting are left out.
 */
function tasksNeedingReplacement(mysqli $conn): array
{
    $sc = "FROM schedule_changes sc WHERE sc.driver_id = t.driver_id AND sc.status = 'approved'
             AND (sc.task_id = t.task_id OR (sc.start_date <= DATE(DATE_SUB(t.end_dt, INTERVAL 1 SECOND)) AND sc.end_date >= DATE(t.start_dt)))";
    return dbAll($conn, "SELECT t.*, v.plate_number, u.full_name AS driver_name, u.status AS driver_status,
                                (SELECT sc.reason $sc ORDER BY sc.change_id DESC LIMIT 1) AS change_reason
                           FROM tasks t JOIN drivers dr ON dr.driver_id = t.driver_id JOIN users u ON u.user_id = dr.user_id
                           LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                          WHERE t.status = 'approved' AND t.start_dt > NOW() AND (EXISTS (SELECT 1 $sc) OR u.status <> 'active')
                            AND NOT EXISTS (SELECT 1 FROM driver_replacements rp WHERE rp.task_id = t.task_id AND rp.status = 'pending')
                          ORDER BY t.start_dt");
}

// ------------------------------------------------------------
// UC022 Request Schedule Change, UC027 Approve/Reject Schedule Change
// ------------------------------------------------------------

function submitScheduleChange(mysqli $conn, int $driver_id, string $from, string $to, string $reason, int $task_id = 0): array
{
    $reason = trim($reason);
    if ($task_id) {
        $t = loadTask($conn, $task_id);
        if (!$t || (int)$t['driver_id'] !== $driver_id || !in_array($t['status'], ['driver_assigned', 'approved'], true) || strtotime($t['start_dt']) <= time()) {
            return wfFail('You can only ask to be removed from your own upcoming task.');
        }
        $from = substr($t['start_dt'], 0, 10);
        $to   = date('Y-m-d', strtotime($t['end_dt']) - 1);
    }
    if ($reason === '' || !isValidDate($from) || !isValidDate($to)) { return wfFail('Please fill in all required fields.'); }
    if ($to < $from) { [$from, $to] = [$to, $from]; }
    if ($from < date('Y-m-d')) { return wfFail('The selected date has already passed.'); }
    [, $id] = dbExec($conn, 'INSERT INTO schedule_changes (driver_id, task_id, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)',
        [$driver_id, $task_id ?: null, $from, $to, mb_substr($reason, 0, 500)]);
    $what = $task_id ? 'asks to be removed from ' . taskText($t) : 'cannot work on ' . fmtD($from) . ($to !== $from ? ' - ' . fmtD($to) : '');
    notifyUsers($conn, headAdminUserIds($conn), 'Schedule change: ' . driverName($conn, $driver_id) . " $what. Reason: $reason", 'admin/schedule_changes.php?id=' . $id);
    return wfOk('Your schedule change request was sent to the Head of Admin.');
}

function cancelScheduleChange(mysqli $conn, int $id, int $driver_id): array
{
    [$n] = dbExec($conn, "UPDATE schedule_changes SET status = 'cancelled' WHERE change_id = ? AND driver_id = ? AND status = 'pending'", [$id, $driver_id]);
    return $n ? wfOk('The schedule change request was cancelled.') : wfFail('Only a Pending request can be cancelled.');
}

/** The driver's tasks (not cancelled, not completed) on the dates of a schedule change. */
function scheduleChangeAffectedTasks(mysqli $conn, array $sc): array
{
    return dbAll($conn, "SELECT t.*, v.plate_number FROM tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                          WHERE t.driver_id = ? AND t.status IN ('driver_assigned','approved')
                            AND (t.task_id = ? OR (DATE(t.start_dt) <= ? AND DATE(DATE_SUB(t.end_dt, INTERVAL 1 SECOND)) >= ?))
                          ORDER BY t.start_dt", [(int)$sc['driver_id'], (int)($sc['task_id'] ?? 0), $sc['end_date'], $sc['start_date']]);
}

function hoaDecideScheduleChange(mysqli $conn, int $id, bool $approve, string $reason = ''): array
{
    $reason = trim($reason);
    $sc = dbOne($conn, "SELECT * FROM schedule_changes WHERE change_id = ? AND status = 'pending'", [$id]);
    if (!$sc) { return wfFail('This schedule change is not pending.'); }
    $uid = (int)$_SESSION['user_id'];
    $driverUser = userIdOfDriver($conn, (int)$sc['driver_id']);
    $dates = fmtD($sc['start_date']) . ($sc['end_date'] !== $sc['start_date'] ? ' - ' . fmtD($sc['end_date']) : '');
    if ($approve) {
        dbExec($conn, "UPDATE schedule_changes SET status = 'approved', decided_by = ?, decided_at = NOW() WHERE change_id = ?", [$uid, $id]);
        $affected = scheduleChangeAffectedTasks($conn, $sc);
        notifyUsers($conn, [$driverUser], "Your schedule change for $dates was approved. You will not be given tasks on those dates.", 'driver/schedule_change.php');
        if ($affected) {
            $list = implode('; ', array_map('taskText', $affected));
            notifyUsers($conn, assistantUserIds($conn), driverName($conn, (int)$sc['driver_id']) . " has an approved schedule change for $dates. Please replace the driver of: $list.", 'admin/schedule.php?view=replace');
        }
        return wfOk('The schedule change was approved.' . ($affected ? ' ' . count($affected) . ' task(s) were sent to the Administrative Assistant to replace the driver.' : ''));
    }
    if ($reason === '') { return wfFail('The reason is required.'); }
    dbExec($conn, "UPDATE schedule_changes SET status = 'rejected', reject_reason = ?, rejected_date = NOW(), decided_by = ?, decided_at = NOW() WHERE change_id = ?", [mb_substr($reason, 0, 500), $uid, $id]);
    notifyUsers($conn, [$driverUser], "Your schedule change for $dates was rejected. Reason: $reason", 'driver/schedule_change.php');
    return wfOk('The schedule change was rejected.');
}

// ------------------------------------------------------------
// UC024 Manage Assigned Task Report
// ------------------------------------------------------------

function loadTaskReport(mysqli $conn, int $task_id): ?array
{
    return dbOne($conn, 'SELECT * FROM task_reports WHERE task_id = ?', [$task_id]);
}

/** May the driver create the report now (own approved task whose departure time has passed, no report yet)? */
function canCreateReport(mysqli $conn, array $t, int $driver_id): bool
{
    return (int)$t['driver_id'] === $driver_id && $t['status'] === 'approved' && strtotime($t['start_dt']) <= time() && !loadTaskReport($conn, (int)$t['task_id']);
}

/** Creates (no $report) or updates a task report. $in: actual_depart, actual_return (Y-m-d\TH:i), end_mileage, fuel_level, vehicle_condition, problem_type, problem_desc, can_be_used */
function saveTaskReport(mysqli $conn, array $t, int $driver_id, array $in, ?array $photo, ?array $report = null): array
{
    if ((int)$t['driver_id'] !== $driver_id) { return wfFail('This is not your task.'); }
    if (!$report && !canCreateReport($conn, $t, $driver_id)) { return wfFail('The report cannot be created for this task.'); }
    $errors = [];
    $dep = str_replace('T', ' ', (string)($in['actual_depart'] ?? ''));
    $ret = str_replace('T', ' ', (string)($in['actual_return'] ?? ''));
    $depOk = strtotime($dep) !== false && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $dep);
    $retOk = strtotime($ret) !== false && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $ret);
    if (!$depOk) { $errors['actual_depart'] = 'Enter the actual departure time.'; }
    if (!$retOk) { $errors['actual_return'] = 'Enter the actual return time.'; }
    if ($depOk && $retOk && strtotime($ret) < strtotime($dep)) { $errors['actual_return'] = 'The return time must be after the departure time.'; }
    $startMileage = $report ? (int)$report['start_mileage'] : (int)$t['current_mileage'];
    $end = trim((string)($in['end_mileage'] ?? ''));
    if ($end === '' || !ctype_digit($end)) { $errors['end_mileage'] = 'Enter the end mileage.'; }
    elseif ((int)$end < $startMileage) { $errors['end_mileage'] = 'The mileage is not valid: it is lower than ' . number_format($startMileage) . ' km.'; }
    $fuel = (string)($in['fuel_level'] ?? '');
    if (!in_array($fuel, FUEL_LEVELS, true)) { $errors['fuel_level'] = 'Select the fuel level.'; }
    $cond = ($in['vehicle_condition'] ?? '') === 'problem' ? 'problem' : (($in['vehicle_condition'] ?? '') === 'good' ? 'good' : '');
    if ($cond === '') { $errors['vehicle_condition'] = 'Select the vehicle condition.'; }
    $ptype = null; $pdesc = null; $usable = 1;
    if ($cond === 'problem') {
        $ptype = (string)($in['problem_type'] ?? '');
        if (!in_array($ptype, PROBLEM_TYPES, true)) { $errors['problem_type'] = 'Select the problem type.'; }
        $pdesc = trim((string)($in['problem_desc'] ?? ''));
        if ($pdesc === '') { $errors['problem_desc'] = 'Describe the problem.'; }
        if (!isset($in['can_be_used']) || !in_array($in['can_be_used'], ['0', '1'], true)) { $errors['can_be_used'] = 'Say whether the vehicle can still be used.'; }
        else { $usable = (int)$in['can_be_used']; }
    }
    if ($errors) { return wfFail('Please fill in all required fields correctly.', ['errors' => $errors]); }

    $photoPath = $report['photo'] ?? null;
    if ($cond === 'problem' && $photo && (int)($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $up = saveUploadedImage($photo, 'reports', 5242880);
        if (!$up['ok']) { return wfFail($up['error'], ['errors' => ['photo' => $up['error']]]); }
        $photoPath = $up['path'];
    }
    if ($cond === 'good') { $photoPath = null; }

    $tid = (int)$t['task_id'];
    $conn->begin_transaction();
    try {
        if ($report) {
            dbExec($conn, 'UPDATE task_reports SET actual_depart = ?, actual_return = ?, end_mileage = ?, fuel_level = ?, vehicle_condition = ?, problem_type = ?, problem_desc = ?, photo = ?, can_be_used = ? WHERE report_id = ?',
                [$dep, $ret, (int)$end, $fuel, $cond, $ptype, $pdesc ? mb_substr($pdesc, 0, 500) : null, $photoPath, $usable, (int)$report['report_id']]);
        } else {
            dbExec($conn, 'INSERT INTO task_reports (task_id, driver_id, actual_depart, actual_return, start_mileage, end_mileage, fuel_level, vehicle_condition, problem_type, problem_desc, photo, can_be_used)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tid, $driver_id, $dep, $ret, $startMileage, (int)$end, $fuel, $cond, $ptype, $pdesc ? mb_substr($pdesc, 0, 500) : null, $photoPath, $usable]);
            dbExec($conn, "UPDATE tasks SET status = 'completed' WHERE task_id = ?", [$tid]);
        }
        // the vehicle follows its latest report
        $latest = (int)dbValue($conn, 'SELECT tr.task_id FROM task_reports tr JOIN tasks tt ON tt.task_id = tr.task_id WHERE tt.vehicle_id = ? ORDER BY tr.actual_return DESC, tr.report_id DESC LIMIT 1', [(int)$t['vehicle_id']]);
        if ($latest === $tid) {
            dbExec($conn, 'UPDATE vehicles SET current_mileage = ?, fuel_level = ? WHERE vehicle_id = ?', [(int)$end, $fuel, (int)$t['vehicle_id']]);
        }
        if ($cond === 'problem' && !$usable) {
            dbExec($conn, "UPDATE vehicles SET status = 'maintenance' WHERE vehicle_id = ? AND status = 'active'", [(int)$t['vehicle_id']]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('saveTaskReport: ' . $e->getMessage());
        return wfFail('The report could not be saved. Please try again.');
    }
    $msg = ($report ? 'Task report updated: ' : 'Task report: ') . taskText($t) . ' by ' . $t['driver_name'] . '. End mileage ' . number_format((int)$end) . " km, fuel $fuel.";
    if ($cond === 'problem') { $msg = 'VEHICLE PROBLEM (' . $ptype . ') on ' . $t['plate_number'] . ': ' . $pdesc . '. ' . ($usable ? 'The vehicle can still be used.' : 'The vehicle cannot be used and was set Under Maintenance.') . ' ' . $msg; }
    notifyUsers($conn, assistantUserIds($conn), $msg, 'admin/task.php?id=' . $tid, $cond === 'problem' ? 'problem' : 'report');
    return wfOk($report ? 'The report was updated.' : 'The report was saved and the task is Completed.');
}

// ------------------------------------------------------------
// Vehicle bookings (UC002C exception 3.2, UC002D exception 3.1)
// ------------------------------------------------------------

/** Upcoming bookings of a vehicle: request reservations, internal tasks and welfare days that have not ended. */
function vehicleUpcomingBookings(mysqli $conn, int $vid): array
{
    $out = [];
    foreach (dbAll($conn, "SELECT rv.rv_id, rv.start_dt, rv.end_dt, r.request_id, r.destination, r.status, u.full_name
                             FROM request_vehicles rv JOIN requests r ON r.request_id = rv.request_id JOIN users u ON u.user_id = r.requester_id
                            WHERE rv.vehicle_id = ? AND rv.active = 1 AND rv.end_dt > NOW() AND r.status IN ('pending','hod_approved','driver_assigned','approved')
                            ORDER BY rv.start_dt", [$vid]) as $b) {
        $out[] = ['kind' => 'request', 'id' => (int)$b['rv_id'], 'start' => $b['start_dt'], 'end' => $b['end_dt'],
                  'label' => 'Request #' . $b['request_id'] . ' (' . requestStatusLabel($b['status']) . '), ' . $b['full_name'] . ' to ' . $b['destination'],
                  'link' => 'staff/request.php?id=' . $b['request_id']];
    }
    foreach (dbAll($conn, "SELECT t.* FROM tasks t WHERE t.vehicle_id = ? AND t.task_source IN ('internal','welfare') AND t.status IN ('waiting','driver_assigned','approved')
                            AND t.end_dt > NOW() ORDER BY t.start_dt", [$vid]) as $t) {
        $out[] = ['kind' => $t['task_source'], 'id' => (int)$t['task_id'], 'start' => $t['start_dt'], 'end' => $t['end_dt'],
                  'label' => taskTypeLabel($t) . ($t['description'] ? ': ' . $t['description'] : ''), 'link' => 'admin/task.php?id=' . $t['task_id']];
    }
    usort($out, fn($a, $b) => strcmp($a['start'], $b['start']));
    return $out;
}

/** Moves one booking to another vehicle (same type for a request; car or van for welfare duty). */
function changeBookingVehicle(mysqli $conn, string $kind, int $id, int $newVid): array
{
    $nv = dbOne($conn, 'SELECT * FROM vehicles WHERE vehicle_id = ?', [$newVid]);
    if (!$nv) { return wfFail('Select another vehicle.'); }
    $conn->begin_transaction();
    dbOne($conn, 'SELECT vehicle_id FROM vehicles WHERE vehicle_id = ? FOR UPDATE', [$newVid]);
    if ($kind === 'request') {
        $rv = dbOne($conn, 'SELECT rv.*, r.vehicle_type FROM request_vehicles rv JOIN requests r ON r.request_id = rv.request_id WHERE rv.rv_id = ? AND rv.active = 1', [$id]);
        if (!$rv) { $conn->rollback(); return wfFail('Unknown booking.'); }
        if ($nv['vehicle_type'] !== $rv['vehicle_type']) { $conn->rollback(); return wfFail('Select a vehicle of the same type (' . $rv['vehicle_type'] . ').'); }
        if ($why = vehicleBusyReason($conn, $newVid, $rv['start_dt'], $rv['end_dt'], (int)$rv['request_id'])) { $conn->rollback(); return wfFail($nv['plate_number'] . ' is not available: ' . $why . '.'); }
        foreach (dbAll($conn, "SELECT * FROM tasks WHERE rv_id = ? AND status IN ('driver_assigned','approved')", [$id]) as $t) {
            $lic = dbValue($conn, 'SELECT licence_classes FROM drivers WHERE driver_id = ?', [(int)$t['driver_id']]);
            if (!driverHoldsLicence($lic, $nv['licence_required'])) { $conn->rollback(); return wfFail('The assigned driver does not hold the licence for ' . $nv['plate_number'] . '.'); }
        }
        $oldPlate = dbValue($conn, 'SELECT plate_number FROM vehicles WHERE vehicle_id = ?', [(int)$rv['vehicle_id']]);
        dbExec($conn, 'UPDATE request_vehicles SET vehicle_id = ? WHERE rv_id = ?', [$newVid, $id]);
        dbExec($conn, 'UPDATE tasks SET vehicle_id = ? WHERE rv_id = ?', [$newVid, $id]);
        $conn->commit();
        addHistory($conn, (int)$rv['request_id'], 'vehicle_changed', null, "$oldPlate -> {$nv['plate_number']}");
        $users = [(int)dbValue($conn, 'SELECT requester_id FROM requests WHERE request_id = ?', [(int)$rv['request_id']])];
        foreach (dbAll($conn, "SELECT driver_id FROM tasks WHERE rv_id = ? AND status = 'approved'", [$id]) as $t) { $users[] = userIdOfDriver($conn, (int)$t['driver_id']); }
        notifyUsers($conn, $users, 'The vehicle of request #' . $rv['request_id'] . " was changed from $oldPlate to {$nv['plate_number']}.", 'staff/request.php?id=' . $rv['request_id']);
        return wfOk("The booking was moved to {$nv['plate_number']}.");
    }
    $t = loadTask($conn, $id, true);
    if (!$t || $t['task_source'] !== $kind) { $conn->rollback(); return wfFail('Unknown booking.'); }
    if ($kind === 'welfare' && !in_array($nv['vehicle_type'], WELFARE_VEHICLE_TYPES, true)) { $conn->rollback(); return wfFail('Only cars and vans can be used for welfare duty.'); }
    if ($why = vehicleBusyReason($conn, $newVid, $t['start_dt'], $t['end_dt'], 0, $id)) { $conn->rollback(); return wfFail($nv['plate_number'] . ' is not available: ' . $why . '.'); }
    if ($t['driver_id'] && !driverHoldsLicence((string)dbValue($conn, 'SELECT licence_classes FROM drivers WHERE driver_id = ?', [(int)$t['driver_id']]), $nv['licence_required'])) {
        $conn->rollback(); return wfFail('The assigned driver does not hold the licence for ' . $nv['plate_number'] . '.');
    }
    dbExec($conn, 'UPDATE tasks SET vehicle_id = ? WHERE task_id = ?', [$newVid, $id]);
    $conn->commit();
    if ($t['driver_id']) { notifyUsers($conn, [(int)$t['driver_user_id']], 'The vehicle of your task ' . taskText($t) . " was changed to {$nv['plate_number']}.", 'driver/task.php?id=' . $id); }
    return wfOk("The booking was moved to {$nv['plate_number']}.");
}

/** The tasks a driver still has (not started, not cancelled), for UC001D exception 3.1. */
function driverUpcomingTasks(mysqli $conn, int $driver_id): array
{
    return dbAll($conn, "SELECT t.*, v.plate_number FROM tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                          WHERE t.driver_id = ? AND t.status IN ('driver_assigned','approved') AND t.end_dt > NOW() ORDER BY t.start_dt", [$driver_id]);
}
