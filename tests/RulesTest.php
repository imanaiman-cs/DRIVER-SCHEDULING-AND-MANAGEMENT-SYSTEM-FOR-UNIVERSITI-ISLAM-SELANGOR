<?php
/**
 * The business rules of the SRS: status flow, timing rule, vehicle lock per time period, availability,
 * driver clashes, task windows, labels. Database tests run in a transaction that is rolled back.
 * (They only call functions that do not open a transaction of their own.)
 */
function t_user(string $role = 'staff', ?string $dept = 'Test PTJ'): int
{
    global $conn;
    static $n = 0; $n++;
    $u = 't_' . $role . '_' . $n . '_' . mt_rand(1000, 9999);
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, role, department) VALUES (?, 'x', ?, ?, ?, ?)");
    $mail = $u . '@test.test';
    $stmt->bind_param('sssss', $u, $mail, $u, $role, $dept);
    $stmt->execute();
    return (int)$stmt->insert_id;
}
function t_request(int $staff, int $vehicle, string $depart, string $return, string $status = 'pending'): int
{
    global $conn;
    $d = explode(' ', $depart); $r = explode(' ', $return);
    $stmt = $conn->prepare("INSERT INTO vehicle_requests (staff_id, vehicle_id, trip_date, start_time, return_date, end_time, destination, purpose, passenger_count, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'T', 'a long enough purpose', 1, ?)");
    $stmt->bind_param('iisssss', $staff, $vehicle, $d[0], $d[1], $r[0], $r[1], $status);
    $stmt->execute();
    return (int)$stmt->insert_id;
}
function t_vehicle(string $type = 'Car', int $cap = 5, string $status = 'available'): int
{
    global $conn;
    static $n = 0; $n++;
    $plate = 'TST' . mt_rand(100, 999) . $n;
    $lic = licenceForVehicleType($type);
    $stmt = $conn->prepare("INSERT INTO vehicles (plate_number, vehicle_type, model, capacity, status, license_required) VALUES (?, ?, 'Test', ?, ?, ?)");
    $stmt->bind_param('ssiss', $plate, $type, $cap, $status, $lic);
    $stmt->execute();
    return (int)$stmt->insert_id;
}

return [
    'status flow follows the SRS' => function () {
        assert_true(canChangeRequestStatus('pending', 'approved'));
        assert_true(canChangeRequestStatus('pending', 'rejected'));
        assert_true(canChangeRequestStatus('pending', 'lapsed'));
        assert_true(canChangeRequestStatus('approved', 'assigned'), 'Approved -> Driver Assigned');
        assert_true(canChangeRequestStatus('assigned', 'processed'), 'Driver Assigned -> Confirmed (Head of Admin approves)');
        assert_true(canChangeRequestStatus('assigned', 'approved'), 'rejecting the assignment goes back to Approved');
        assert_true(canChangeRequestStatus('processed', 'assigned'), 'replacing a driver goes back to Driver Assigned');
        assert_true(canChangeRequestStatus('processed', 'completed'));
        assert_true(!canChangeRequestStatus('approved', 'processed'), 'NO auto-confirm: Approved cannot jump to Confirmed');
        assert_true(!canChangeRequestStatus('pending', 'assigned'));
        assert_true(!canChangeRequestStatus('rejected', 'approved'));
        assert_true(!canChangeRequestStatus('lapsed', 'approved'));
        assert_true(!canChangeRequestStatus('completed', 'cancelled'));
        foreach (['pending', 'approved', 'assigned', 'processed'] as $s) { assert_true(canChangeRequestStatus($s, 'cancelled'), "$s can be cancelled"); }
    },
    'exact labels of the roles and statuses' => function () {
        assert_same('Head of Department', roleLabel('supervisor'));
        assert_same('Administrative Assistant', roleLabel('admin'));
        assert_same('Head of Admin', roleLabel('head_admin'));
        assert_same('Staff', roleLabel('staff'));
        assert_same('Driver', roleLabel('driver'));
        assert_same(['Pending', 'Approved by Head of Department', 'Driver Assigned', 'Approved', 'Rejected', 'Cancelled', 'Lapsed'],
            array_map('requestStatusLabel', ['pending', 'approved', 'assigned', 'processed', 'rejected', 'cancelled', 'lapsed']));
    },
    'a request that is rejected, cancelled, lapsed or completed no longer needs its vehicle' => function () {
        foreach (['rejected', 'cancelled', 'lapsed', 'completed'] as $s) { assert_true(!in_array($s, REQUEST_ACTIVE_STATUSES, true)); }
        foreach (['pending', 'approved', 'assigned', 'processed'] as $s) { assert_true(in_array($s, REQUEST_ACTIVE_STATUSES, true)); }
    },
    'timing rule (SRS 10 Nov): under 2 days is accepted and marked Urgent, otherwise ok; nothing is refused' => function () {
        $at = fn(float $h) => date('Y-m-d H:i:s', time() + (int)($h * 3600));
        foreach ([5, 23.5, 24.5, 47] as $h) { assert_same('urgent', requestTimingRule($at($h))['level'], "$h hours"); }
        assert_same('ok', requestTimingRule($at(49))['level']);
        assert_true(strpos(requestTimingRule($at(2))['message'], 'Urgent') !== false);
        assert_same('ok', requestTimingRule($at(300))['level']);
    },
    'strict date and time checks' => function () {
        assert_true(isValidDate('2026-02-28')); assert_true(!isValidDate('2026-02-31')); assert_true(!isValidDate('26-2-3'));
        assert_true(isValidTime('08:30')); assert_true(isValidTime('23:59:59')); assert_true(!isValidTime('25:61')); assert_true(!isValidTime('8:30'));
    },
    'task windows: outbound at departure, return at the return time' => function () {
        global $conn;
        $req = ['trip_date' => '2034-03-01', 'start_time' => '09:00:00', 'return_date' => '2034-03-01', 'end_time' => '17:00:00'];
        $w = requestTaskWindows($conn, $req);       // TASK_DURATION_HOURS = 2
        assert_same('2034-03-01 09:00:00', $w['out']['start_dt']);
        assert_same('2034-03-01 11:00:00', $w['out']['end_dt']);
        assert_same('2034-03-01 17:00:00', $w['ret']['start_dt']);
        assert_same('2034-03-01 19:00:00', $w['ret']['end_dt']);
        $short = requestTaskWindows($conn, ['trip_date' => '2034-03-01', 'start_time' => '09:00:00', 'return_date' => '2034-03-01', 'end_time' => '10:00:00']);
        assert_same('2034-03-01 10:00:00', $short['out']['end_dt'], 'the outbound task never runs past the return');
        $late = requestTaskWindows($conn, ['trip_date' => '2034-03-01', 'start_time' => '09:00:00', 'return_date' => '2034-03-03', 'end_time' => '23:00:00']);
        assert_same('2034-03-03', $late['ret']['date']);
        assert_same('23:59:59', $late['ret']['end'], 'never past midnight');
    },
    'vehicle lock is per TIME PERIOD, not per day' => function () {
        global $conn;
        $v = t_vehicle(); $staff = t_user();
        $a = t_request($staff, $v, '2034-04-10 09:00:00', '2034-04-10 12:00:00');
        lockVehiclePeriod($conn, $a, $v, '2034-04-10 09:00:00', '2034-04-10 12:00:00');
        assert_same('locked', vehicleUnavailableReason($conn, $v, '2034-04-10 11:00:00', '2034-04-10 13:00:00'), 'overlap');
        assert_same('locked', vehicleUnavailableReason($conn, $v, '2034-04-10 08:00:00', '2034-04-10 09:30:00'), 'overlap at the start');
        assert_same('locked', vehicleUnavailableReason($conn, $v, '2034-04-10 09:30:00', '2034-04-10 10:00:00'), 'inside');
        assert_same(null, vehicleUnavailableReason($conn, $v, '2034-04-10 12:00:00', '2034-04-10 15:00:00'), 'starts exactly when the lock ends');
        assert_same(null, vehicleUnavailableReason($conn, $v, '2034-04-10 06:00:00', '2034-04-10 09:00:00'), 'ends exactly when the lock starts');
        assert_same(null, vehicleUnavailableReason($conn, $v, '2034-04-10 14:00:00', '2034-04-10 16:00:00'), 'the same day at another time is free');
        assert_same(null, vehicleUnavailableReason($conn, $v, '2034-04-10 10:00:00', '2034-04-10 11:00:00', $a), 'its own request does not block itself');
        $b = t_request($staff, $v, '2034-04-10 10:00:00', '2034-04-10 11:00:00');
        $threw = false;
        try { lockVehiclePeriod($conn, $b, $v, '2034-04-10 10:00:00', '2034-04-10 11:00:00'); } catch (RuntimeException $e) { $threw = str_starts_with($e->getMessage(), 'Sorry,'); }
        assert_true($threw, 'a second lock on an overlapping period is refused with a friendly message');
        $c = t_request($staff, $v, '2034-04-10 14:00:00', '2034-04-10 16:00:00');
        lockVehiclePeriod($conn, $c, $v, '2034-04-10 14:00:00', '2034-04-10 16:00:00');   // does not throw
        releaseVehicleLocks($conn, $a);
        assert_same(null, vehicleUnavailableReason($conn, $v, '2034-04-10 09:00:00', '2034-04-10 12:00:00'), 'released');
    },
    'availableVehicles shows only free, working vehicles with enough seats' => function () {
        global $conn;
        $free = t_vehicle('Car', 5); $small = t_vehicle('Car', 2); $inactive = t_vehicle('Car', 5, 'inactive'); $maint = t_vehicle('Car', 5, 'maintenance');
        $locked = t_vehicle('Car', 5); $staff = t_user();
        $conn->query("INSERT INTO vehicle_maintenance (vehicle_id, start_date, end_date, workshop) VALUES ($maint, '2034-05-01', '2034-05-10', 'W')");
        $rid = t_request($staff, $locked, '2034-05-12 09:00:00', '2034-05-12 12:00:00');
        lockVehiclePeriod($conn, $rid, $locked, '2034-05-12 09:00:00', '2034-05-12 12:00:00');
        $ids = fn($rows) => array_map('intval', array_column($rows, 'vehicle_id'));
        $list = $ids(availableVehicles($conn, '2034-05-12 10:00:00', '2034-05-12 11:00:00', 4));
        assert_true(in_array($free, $list, true), 'free vehicle offered');
        assert_true(!in_array($small, $list, true), 'capacity below the passengers');
        assert_true(!in_array($inactive, $list, true), 'inactive');
        assert_true(in_array($maint, $list, true), 'maintenance ended 10 May, so a 12 May period is allowed');
        assert_true(!in_array($locked, $list, true), 'locked in the period');
        $after = $ids(availableVehicles($conn, '2034-05-12 13:00:00', '2034-05-12 14:00:00', 4));
        assert_true(in_array($locked, $after, true), 'the locked vehicle is free again after the lock');
        $early = $ids(availableVehicles($conn, '2034-05-05 09:00:00', '2034-05-05 11:00:00', 4));
        assert_true(!in_array($maint, $early, true), 'still under maintenance before the expected return date');
        assert_true(in_array($maint, $ids(availableVehicles($conn, '2034-05-12 13:00:00', '2034-05-12 14:00:00', 4)), true), 'back in service after the expected return date');
    },
    'driver clash: tasks and internal tasks overlap, adjacent and cancelled ones do not' => function () {
        global $conn;
        $conn->query("INSERT INTO tasks (driver_id, vehicle_id, task_source, task_type, start_dt, end_dt, location, status) VALUES (3, 5, 'request', 'trip', '2034-06-01 09:00', '2034-06-01 11:00', 'T', 'approved')");
        $sid = (int)$conn->insert_id;
        assert_true(driverClashReason($conn, 3, '2034-06-01 10:00:00', '2034-06-01 12:00:00') !== null, 'overlap');
        assert_same(null, driverClashReason($conn, 3, '2034-06-01 11:00:00', '2034-06-01 13:00:00'), 'adjacent');
        assert_same(null, driverClashReason($conn, 3, '2034-06-01 10:00:00', '2034-06-01 12:00:00', $sid), 'the task itself is excluded (replace driver)');
        $conn->query("UPDATE tasks SET status = 'cancelled' WHERE task_id = $sid");
        assert_same(null, driverClashReason($conn, 3, '2034-06-01 10:00:00', '2034-06-01 12:00:00'), 'cancelled task');
        $conn->query("INSERT INTO tasks (task_type, task_source, start_dt, end_dt, vehicle_id, driver_id, description, status) VALUES ('refuel', 'internal', '2034-06-02 14:00', '2034-06-02 15:00', 5, 4, 'x', 'approved')");
        $tid = (int)$conn->insert_id;
        assert_true(driverClashReason($conn, 4, '2034-06-02 14:30:00', '2034-06-02 16:00:00') !== null, 'internal task overlaps');
        $conn->query("UPDATE tasks SET status = 'cancelled' WHERE task_id = $tid");
        assert_same(null, driverClashReason($conn, 4, '2034-06-02 14:30:00', '2034-06-02 16:00:00'), 'cancelled internal task');
    },
    'outside working hours: before 08:00, after 17:30 and weekends' => function () {
        global $conn;
        assert_true(!isOutsideWorkingHours($conn, '2034-06-07', '08:00:00', '17:30:00'), 'a Wednesday 08:00-17:30');
        assert_true(isOutsideWorkingHours($conn, '2034-06-07', '07:30:00', '09:00:00'), 'before 8.00 am');
        assert_true(isOutsideWorkingHours($conn, '2034-06-07', '16:00:00', '18:00:00'), 'after 5.30 pm');
        assert_true(isOutsideWorkingHours($conn, '2034-06-10', '10:00:00', '11:00:00'), 'Saturday');
        assert_true(isOutsideWorkingHours($conn, '2034-06-11', '10:00:00', '11:00:00'), 'Sunday');
    },
    'cancel rules (UC021): a reason is required for everyone, never after the trip started' => function () {
        global $conn;
        $staff = t_user(); $hod = t_user('supervisor'); $adm = t_user('admin'); $other = t_user();
        $v = t_vehicle();
        $future = t_request($staff, $v, date('Y-m-d H:i:s', time() + 5 * 86400), date('Y-m-d H:i:s', time() + 5 * 86400 + 7200), 'approved');
        $conn->query("UPDATE vehicle_requests SET supervisor_id = $hod WHERE request_id = $future");
        $req = $conn->query("SELECT * FROM vehicle_requests WHERE request_id = $future")->fetch_assoc();
        $_SESSION = ['user_id' => $staff, 'role' => 'staff'];
        $r = cancelRulesFor($req); assert_true($r['allowed'] && $r['reason_required'], 'staff: own request, reason required');
        $_SESSION = ['user_id' => $other, 'role' => 'staff'];
        assert_true(!cancelRulesFor($req)['allowed'], "staff cannot cancel someone else's request");
        $_SESSION = ['user_id' => $hod, 'role' => 'supervisor'];
        $r = cancelRulesFor($req); assert_true($r['allowed'] && $r['reason_required'], 'Head of Department: reason required');
        $_SESSION = ['user_id' => $adm, 'role' => 'admin'];
        $r = cancelRulesFor($req); assert_true($r['allowed'] && $r['reason_required'], 'Administrative Assistant: reason required');
        $started = $req; $started['trip_date'] = date('Y-m-d', time() - 86400); $started['start_time'] = '08:00:00';
        assert_true(!cancelRulesFor($started)['allowed'], 'not after the trip started');
        $done = $req; $done['status'] = 'rejected';
        assert_true(!cancelRulesFor($done)['allowed'], 'only active requests');
        $_SESSION = [];
    },
    'the Head of Department owns only requests of their own PTJ' => function () {
        $_SESSION = ['user_id' => 77, 'role' => 'supervisor', 'department' => 'Faculty of Education'];
        assert_true(hodOwnsRequest(['supervisor_id' => 77, 'staff_department' => 'Faculty of Education', 'staff_role' => 'staff']));
        assert_true(!hodOwnsRequest(['supervisor_id' => 77, 'staff_department' => 'Registrar Office', 'staff_role' => 'staff']), 'another PTJ');
        assert_true(!hodOwnsRequest(['supervisor_id' => 78, 'staff_department' => 'Faculty of Education', 'staff_role' => 'staff']), 'not the approver');
        assert_true(hodOwnsRequest(['supervisor_id' => 77, 'staff_department' => 'Transport Unit', 'staff_role' => 'supervisor']), 'a Head of Department he heads');
        $_SESSION = [];
    },
    'licence classes: car/van D, lorry GDL, bus PSV' => function () {
        assert_same('D', licenceForVehicleType('Car')); assert_same('D', licenceForVehicleType('Van'));
        assert_same('GDL', licenceForVehicleType('Lorry')); assert_same('PSV', licenceForVehicleType('Bus'));
        assert_true(driverHoldsLicence('D,PSV', 'PSV')); assert_true(!driverHoldsLicence('D,PSV', 'GDL')); assert_true(!driverHoldsLicence(null, 'D'));
        assert_same('D,GDL,PSV', normaliseLicenceList(['psv', 'D', 'gdl', 'X'])); assert_same('', normaliseLicenceList(['X']));
    },
    'UC012 automatic reminder: only the Head of Department is told, the sender is the system, the date is recorded' => function () {
        global $conn;
        $hod = t_user('supervisor'); $staff = t_user(); $v = t_vehicle();
        $rid = t_request($staff, $v, '2034-07-10 09:00:00', '2034-07-10 12:00:00');
        $conn->query("UPDATE vehicle_requests SET supervisor_id = $hod, created_at = DATE_SUB(NOW(), INTERVAL 26 HOUR) WHERE request_id = $rid");
        $res = sendApprovalReminder($conn, $rid, true);
        assert_true($res['ok'], 'reminder sent');
        $n = $conn->query("SELECT user_id FROM notifications WHERE message LIKE 'Reminder: request #$rid %'")->fetch_all(MYSQLI_ASSOC);
        assert_same(1, count($n), 'one in-system notification');
        assert_same($hod, (int)$n[0]['user_id'], 'it goes to the Head of Department, not the staff member');
        $row = $conn->query("SELECT sent_by FROM reminders WHERE request_id = $rid")->fetch_assoc();
        assert_same(null, $row['sent_by'], 'no sender: the system sent it');
        assert_same(1, (int)$conn->query("SELECT COUNT(*) FROM email_log WHERE user_id = $hod AND subject LIKE '%Reminder: request #$rid%'")->fetch_row()[0], 'the e-mail is logged even when sending is switched off');
        $conn->query("UPDATE vehicle_requests SET status = 'approved' WHERE request_id = $rid");
        assert_true(!sendApprovalReminder($conn, $rid, true)['ok'], 'no reminder for a request that is not Pending');
    },
    'UC010 A3 without driver: the flag is read from the request row' => function () {
        assert_true(isRequestWithoutDriver(['need_driver' => 0]));
        assert_true(isRequestWithoutDriver(['need_driver' => '0']));
        assert_true(!isRequestWithoutDriver(['need_driver' => 1]));
        assert_true(!isRequestWithoutDriver([]), 'a row without the column counts as With driver');
    },
    'UC030 unavailable dates: only an APPROVED Unavailable Date request blocks the driver on those days' => function () {
        global $conn;
        $dr = (int)$conn->query("SELECT driver_id FROM drivers WHERE status = 'active' ORDER BY driver_id LIMIT 1")->fetch_row()[0];
        $stmt = $conn->prepare("INSERT INTO schedule_changes (driver_id, change_type, start_date, end_date, reason, status) VALUES (?, 'unavailable', '2034-08-02', '2034-08-03', 'test', 'pending')");
        $stmt->bind_param('i', $dr); $stmt->execute(); $cid = (int)$stmt->insert_id; $stmt->close();
        assert_same(['2034-08-02', '2034-08-03'], scheduleChangeDatesOf($conn, $cid), 'the days of the range');
        assert_same(null, driverUnavailableReason($conn, $dr, '2034-08-02 09:00:00', '2034-08-02 11:00:00'), 'pending: not unavailable');
        $conn->query("UPDATE schedule_changes SET status = 'rejected' WHERE change_id = $cid");
        assert_same(null, driverUnavailableReason($conn, $dr, '2034-08-02 09:00:00', '2034-08-02 11:00:00'), 'rejected: not unavailable');
        $conn->query("UPDATE schedule_changes SET status = 'approved' WHERE change_id = $cid");
        assert_true(driverUnavailableReason($conn, $dr, '2034-08-02 09:00:00', '2034-08-02 11:00:00') !== null, 'approved: unavailable that day');
        assert_true(driverUnavailableReason($conn, $dr, '2034-08-03 23:00:00', '2034-08-04 02:00:00') !== null, 'a task that runs into an unavailable day');
        assert_same(null, driverUnavailableReason($conn, $dr, '2034-08-04 09:00:00', '2034-08-04 11:00:00'), 'the next day is free');
        assert_same('Marked unavailable on 03 Aug 2034', driverUnavailableReason($conn, $dr, '2034-08-03 09:00:00', '2034-08-05 11:00:00'), 'a longer task: the first unavailable day is named');
        assert_same(null, driverUnavailableReason($conn, $dr + 1000, '2034-08-02 09:00:00', '2034-08-02 11:00:00'), 'another driver is not affected');
        $conn->query("UPDATE schedule_changes SET change_type = 'withdraw' WHERE change_id = $cid");
        assert_same(null, driverUnavailableReason($conn, $dr, '2034-08-02 09:00:00', '2034-08-02 11:00:00'), 'a withdraw request never makes the driver unavailable');
    },
    'UC029 the days of a request' => function () {
        assert_same(['2034-08-02', '2034-08-03', '2034-08-04'], scheduleChangeDates('2034-08-02', '2034-08-04'));
        assert_same(['2034-08-02'], scheduleChangeDates('2034-08-02', '2034-08-02'));
        assert_same([], scheduleChangeDates('2034-08-04', '2034-08-02'), 'backwards');
        assert_same([], scheduleChangeDates('nonsense', '2034-08-02'));
        assert_true(count(scheduleChangeDates('2034-01-01', '2034-12-31')) > SCHEDULE_CHANGE_MAX_DAYS, 'a long range is cut so the caller can refuse it');
    },
    'UC019 the time the approval e-mail was sent is recorded; nothing is retried while e-mail is off' => function () {
        global $conn;
        $staff = t_user('staff'); $veh = t_vehicle();
        $rid = t_request($staff, $veh, '2034-09-02 09:00:00', '2034-09-02 12:00:00');
        assert_true($conn->query("SELECT approval_email_at FROM vehicle_requests WHERE request_id = $rid")->fetch_row()[0] === null, 'new request: not sent yet');
        markApprovalEmail($conn, $rid, ['sent' => 0, 'skipped' => 1]);
        assert_true($conn->query("SELECT approval_email_at FROM vehicle_requests WHERE request_id = $rid")->fetch_row()[0] === null, 'skipped: still empty (it will be tried again)');
        markApprovalEmail($conn, $rid, ['sent' => 1]);
        assert_true($conn->query("SELECT approval_email_at FROM vehicle_requests WHERE request_id = $rid")->fetch_row()[0] !== null, 'sent: date and time recorded');
        assert_same(0, retryApprovalEmails($conn), 'e-mail sending is switched off: no retry');
    },
    'request journey: the stepper follows the status and the kind of request' => function () {
        $base = ['request_type' => 'uis', 'purpose_type' => 'official', 'need_driver' => 1, 'reviewed_at' => null];
        $st = fn(array $r) => implode(',', array_map(fn($s) => $s['label'] . ':' . $s['state'], requestJourney($r)['steps']));
        assert_same('Submitted:done,Head of Department:current,Vehicle Section:todo,Head of Admin:todo,Trip:todo,Completed:todo', $st($base + ['status' => 'pending']));
        assert_same('Submitted:done,Head of Department:done,Vehicle Section:current,Head of Admin:todo,Trip:todo,Completed:todo', $st($base + ['status' => 'approved']));
        assert_same('Submitted:done,Head of Department:done,Vehicle Section:done,Head of Admin:done,Trip:current,Completed:todo', $st($base + ['status' => 'processed']));
        assert_true(!str_contains($st($base + ['status' => 'completed']), 'todo') && !str_contains($st($base + ['status' => 'completed']), 'current'), 'completed: every step is done');
        $j = requestJourney($base + ['status' => 'lapsed']);
        assert_same('lapsed', $j['ended']); assert_same('failed', $j['steps'][1]['state'], 'a lapsed request stopped at the Head of Department');
        assert_same('Submitted:done,Head of Department:current,Head of Admin:todo,Trip:todo,Completed:todo', $st(['request_type' => 'pay_claim', 'status' => 'pending'] + $base), 'Pay and Claim has no Vehicle Section step');
        assert_same('Submitted:done,Vehicle Section:current,Head of Admin:todo,Trip:todo,Completed:todo', $st(['purpose_type' => 'personal_rental', 'status' => 'pending'] + $base), 'a Personal Rental has no Head of Department step');
        assert_true(str_contains(requestJourneyHtml($base + ['status' => 'assigned'], true), 'Now: Head of Admin'), 'the rail names the stage');
        assert_same('', tripCountdownHtml(date('Y-m-d H:i:s', time() - 60)), 'no countdown for a trip that started');
        assert_true(str_contains(tripCountdownHtml(date('Y-m-d H:i:s', time() + 3 * 86400 + 60)), 'in 3 days'));
    },
    'WhatsApp: numbers are normalised; switched off nothing is sent or logged; only drivers get WhatsApp' => function () {
        global $conn;
        assert_same('60123456789', whatsappNumber('012-345 6789'));
        assert_same('60123456789', whatsappNumber('+60 12-345 6789'));
        assert_same(null, whatsappNumber('123'));
        $before = (int)$conn->query('SELECT COUNT(*) FROM whatsapp_log')->fetch_row()[0];
        assert_same('skipped', sendWhatsApp($conn, null, '0123456789', 'x')['status']);
        assert_true(!whatsappEnabled(), 'WhatsApp is off in the test setup');
        assert_same($before, (int)$conn->query('SELECT COUNT(*) FROM whatsapp_log')->fetch_row()[0], 'switched off: no row is written');
        assert_true(whatsappForRole('driver'), 'drivers get WhatsApp');
        foreach (['staff', 'supervisor', 'admin', 'head_admin', null] as $r) { assert_true(!whatsappForRole($r), ($r ?? 'null') . ' gets e-mail only'); }
    },
    'class diagram: one tasks table; a WelfareDuty week follows its days; a replacement waits for the Head of Admin' => function () {
        global $conn;
        // one Task class: request tasks, internal tasks and welfare days live in one table
        $src = array_column($conn->query("SELECT DISTINCT task_source FROM tasks ORDER BY task_source")->fetch_all(), 0);
        assert_same(['request', 'internal', 'welfare'], $src, 'the demo data has the three task sources');
        // WelfareDuty: one row per week, status follows the days, a week with no day left is removed
        $monday = '2034-10-02';
        assert_same($monday, welfareWeekStart('2034-10-05'));
        $conn->query("INSERT INTO welfare_duties (week_start_date, status) VALUES ('$monday', 'waiting')");
        $duty = (int)$conn->insert_id;
        $conn->query("INSERT INTO tasks (task_source, duty_id, task_type, vehicle_id, start_dt, end_dt, status) VALUES
                      ('welfare', $duty, 'welfare', 5, '2034-10-02 00:00:00', '2034-10-03 00:00:00', 'waiting'),
                      ('welfare', $duty, 'welfare', 6, '2034-10-03 00:00:00', '2034-10-04 00:00:00', 'approved')");
        $st = fn() => (string)($conn->query("SELECT status FROM welfare_duties WHERE duty_id = $duty")->fetch_row()[0] ?? 'gone');
        syncWelfareDutyStatus($conn, $duty);
        assert_same('waiting', $st(), 'a day without a driver');
        $conn->query("UPDATE tasks SET status = 'assigned', driver_id = 3 WHERE duty_id = $duty AND status = 'waiting'");
        syncWelfareDutyStatus($conn, $duty);
        assert_same('assigned', $st(), 'a day waits for the Head of Admin');
        $conn->query("UPDATE tasks SET status = 'approved' WHERE duty_id = $duty");
        syncWelfareDutyStatus($conn, $duty);
        assert_same('approved', $st());
        assert_same(2, count(welfareWeekRows($conn, $monday)), 'the week has its days (1..7)');
        $dup = false;
        try { $conn->query("INSERT INTO tasks (task_source, task_type, start_dt, end_dt) VALUES ('welfare', 'welfare', '2034-10-02 00:00:00', '2034-10-03 00:00:00')"); }
        catch (mysqli_sql_exception $e) { $dup = true; }
        assert_true($dup, 'only one welfare duty per day');
        $conn->query("DELETE FROM tasks WHERE duty_id = $duty");
        syncWelfareDutyStatus($conn, $duty);
        assert_same('gone', $st(), 'a week without days is removed, so it can be created again');
        // DriverReplacement.status: pending until the Head of Admin decides
        $conn->query("INSERT INTO tasks (task_source, task_type, driver_id, vehicle_id, start_dt, end_dt, status) VALUES ('internal', 'refuel', 4, 5, '2034-10-09 09:00:00', '2034-10-09 10:00:00', 'assigned')");
        $t1 = (int)$conn->insert_id;
        $conn->query("INSERT INTO tasks (task_source, task_type, driver_id, vehicle_id, start_dt, end_dt, status) VALUES ('internal', 'refuel', 5, 5, '2034-10-10 09:00:00', '2034-10-10 10:00:00', 'assigned')");
        $t2 = (int)$conn->insert_id;
        $conn->query("INSERT INTO driver_replacements (task_id, previous_driver_id, driver_id, reason, status) VALUES ($t1, 2, 4, 'Sick', 'pending'), ($t2, 2, 5, 'Leave', 'pending')");
        settleDriverReplacements($conn, [$t1], 'approved');
        settleDriverReplacements($conn, [$t2], 'rejected');
        $rs = $conn->query("SELECT task_id, status FROM driver_replacements WHERE task_id IN ($t1, $t2) ORDER BY task_id")->fetch_all();
        assert_same([[(string)$t1, 'approved'], [(string)$t2, 'rejected']], $rs);
        // a rejected request assignment: the tasks are cancelled and keep who rejected them, when and why
        $_SESSION = ['user_id' => 7, 'role' => 'head_admin'];
        $drivers = cancelRequestTasks($conn, 3, 'Use a more experienced driver');
        $_SESSION = [];
        sort($drivers);
        assert_same([3, 5], $drivers, 'the drivers of request 3 are returned for the notice');
        $t = $conn->query("SELECT COUNT(*), MIN(status), MIN(reject_reason), MIN(rejected_by), SUM(rejected_at IS NULL), SUM(driver_id IS NULL) FROM tasks WHERE request_id = 3")->fetch_row();
        assert_same(['2', 'cancelled', 'Use a more experienced driver', '7', '0', '2'], $t);
    },
];
