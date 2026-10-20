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
        assert_same(['Pending', 'Approved', 'Driver Assigned', 'Confirmed', 'Rejected', 'Cancelled', 'Lapsed'],
            array_map('requestStatusLabel', ['pending', 'approved', 'assigned', 'processed', 'rejected', 'cancelled', 'lapsed']));
    },
    'a request that is rejected, cancelled, lapsed or completed no longer needs its vehicle' => function () {
        foreach (['rejected', 'cancelled', 'lapsed', 'completed'] as $s) { assert_true(!in_array($s, REQUEST_ACTIVE_STATUSES, true)); }
        foreach (['pending', 'approved', 'assigned', 'processed'] as $s) { assert_true(in_array($s, REQUEST_ACTIVE_STATUSES, true)); }
    },
    'timing rule: under 1 day is refused, under 2 days warns, otherwise ok' => function () {
        $at = fn(float $h) => date('Y-m-d H:i:s', time() + (int)($h * 3600));
        assert_same('reject', requestTimingRule($at(5))['level']);
        assert_same('reject', requestTimingRule($at(23.5))['level']);
        assert_same('warn', requestTimingRule($at(24.5))['level']);
        assert_same('warn', requestTimingRule($at(47))['level']);
        assert_same('ok', requestTimingRule($at(49))['level'], 'no hard rejection at 2 days');
        assert_same('Please use the paper form BPTK/BPI/2025/PIN.5', requestTimingRule($at(2))['message']);
        assert_same('reject', requestTimingRule($at(-3))['level'], 'a past departure');
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
        $conn->query("INSERT INTO schedules (driver_id, vehicle_id, trip_date, start_time, end_time, destination, status) VALUES (3, 5, '2034-06-01', '09:00', '11:00', 'T', 'approved')");
        $sid = (int)$conn->insert_id;
        assert_true(driverClashReason($conn, 3, '2034-06-01 10:00:00', '2034-06-01 12:00:00') !== null, 'overlap');
        assert_same(null, driverClashReason($conn, 3, '2034-06-01 11:00:00', '2034-06-01 13:00:00'), 'adjacent');
        assert_same(null, driverClashReason($conn, 3, '2034-06-01 10:00:00', '2034-06-01 12:00:00', $sid), 'the task itself is excluded (replace driver)');
        $conn->query("UPDATE schedules SET status = 'cancelled' WHERE schedule_id = $sid");
        assert_same(null, driverClashReason($conn, 3, '2034-06-01 10:00:00', '2034-06-01 12:00:00'), 'cancelled task');
        $conn->query("INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, vehicle_id, driver_id, description) VALUES ('refuel', '2034-06-02', '14:00', '15:00', 5, 4, 'x')");
        $tid = (int)$conn->insert_id;
        assert_true(driverClashReason($conn, 4, '2034-06-02 14:30:00', '2034-06-02 16:00:00') !== null, 'internal task overlaps');
        $conn->query("UPDATE internal_tasks SET status = 'cancelled' WHERE task_id = $tid");
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
    'cancel rules: staff reason optional, Head of Department and administrators must give a reason, never after the trip started' => function () {
        global $conn;
        $staff = t_user(); $hod = t_user('supervisor'); $adm = t_user('admin'); $other = t_user();
        $v = t_vehicle();
        $future = t_request($staff, $v, date('Y-m-d H:i:s', time() + 5 * 86400), date('Y-m-d H:i:s', time() + 5 * 86400 + 7200), 'approved');
        $conn->query("UPDATE vehicle_requests SET supervisor_id = $hod WHERE request_id = $future");
        $req = $conn->query("SELECT * FROM vehicle_requests WHERE request_id = $future")->fetch_assoc();
        $_SESSION = ['user_id' => $staff, 'role' => 'staff'];
        $r = cancelRulesFor($req); assert_true($r['allowed'] && !$r['reason_required'], 'staff: own request, reason optional');
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
    'WhatsApp numbers are normalised and a missing number is skipped, not an error' => function () {
        global $conn;
        assert_same('60123456789', whatsappNumber('012-345 6789'));
        assert_same('60123456789', whatsappNumber('+60 12-345 6789'));
        assert_same(null, whatsappNumber('123'));
        $res = sendWhatsApp($conn, null, '', 'x');
        assert_same('skipped', $res['status']);
    },
];
