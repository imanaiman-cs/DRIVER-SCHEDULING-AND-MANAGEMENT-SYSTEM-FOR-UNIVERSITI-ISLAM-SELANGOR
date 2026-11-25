<?php
// Business rules of the request (UC018, UC003) and the vehicle reservation - RequestController, AssignmentController,
// AccountController (Domain Layer) and VehicleReservation (Data Access Layer).
$rc = new RequestController();
$ac = new AssignmentController();
return [
    'outbound and return tasks last the task duration' => function () use ($ac) {
        $w = $ac->taskWindows(['depart_datetime' => '2026-12-01 08:00:00', 'return_datetime' => '2026-12-01 17:00:00'], 1);
        assert_same(['2026-12-01 08:00:00', '2026-12-01 09:00:00'], $w['Outbound']);
        assert_same(['2026-12-01 17:00:00', '2026-12-01 18:00:00'], $w['Return']);
    },
    'a short trip: the outbound task ends at the return time' => function () use ($ac) {
        $w = $ac->taskWindows(['depart_datetime' => '2026-12-01 08:00:00', 'return_datetime' => '2026-12-01 08:30:00'], 1);
        assert_same('2026-12-01 08:30:00', $w['Outbound'][1]);
    },
    'the reservation lasts until one hour after the return' => function () use ($rc) {
        assert_same('2026-12-01 18:00:00', $rc->reservationEnd('2026-12-01 17:00:00'));
    },
    'urgent when departing within two days' => function () use ($rc) {
        assert_true($rc->isUrgent(date('Y-m-d H:i:s', time() + 47 * 3600)));
        assert_true(!$rc->isUrgent(date('Y-m-d H:i:s', time() + 49 * 3600)));
    },
    'return must be later than departure' => function () use ($rc) {
        [$e] = $rc->checkPeriod(date('Y-m-d', strtotime('+2 day')), '10:00', date('Y-m-d', strtotime('+2 day')), '09:00');
        assert_true(isset($e['return']));
    },
    'driver options per vehicle type' => function () use ($rc) {
        $staff = User::findByID(8);
        $gdl = User::findByID(11);    // drv001: D, GDL, PSV
        assert_same([Request::WITH_DRIVER, Request::PAY_AND_CLAIM], $rc->driverOptionsFor($staff, 'Car'));
        assert_same([Request::WITH_DRIVER], $rc->driverOptionsFor($staff, 'Lorry'));
        assert_same([Request::WITH_DRIVER, Request::SELF_DRIVE], $rc->driverOptionsFor($gdl, 'Lorry'));
        assert_same([Request::WITH_DRIVER], $rc->driverOptionsFor($gdl, 'Bus'));
    },
    'Personal is for lorries only' => function () use ($rc) {
        assert_true(in_array(Request::PERSONAL, $rc->purposeTypesFor('Lorry'), true));
        assert_true(!in_array(Request::PERSONAL, $rc->purposeTypesFor('Car'), true));
    },
    'the approver is the head of the department; a head goes to the parent department' => function () use ($rc) {
        $approver = fn(int $uid) => (int)($rc->approverForUser(User::findByID($uid))['user_id'] ?? 0);
        assert_same(3, $approver(8), 'stf001 -> hos002');
        assert_same(1, $approver(3), 'hos002 (Faculty of Education) -> hos005 (Vice-Chancellor)');
        assert_same(7, $approver(6), 'Administrative Assistant (BPI) -> Head of Admin, head of BPI');
        assert_same(4, $approver(7), 'Head of Admin -> hos003, head of the Registrar Office (parent of BPI)');
        assert_same(7, $approver(11), 'a driver (BPI) -> Head of Admin');
    },
    'units left drop when a vehicle is reserved, and come back after the buffer' => function () use ($rc) {
        $s = date('Y-m-d 09:00:00', strtotime('+30 day')); $e = date('Y-m-d 12:00:00', strtotime('+30 day'));
        $before = $rc->checkAvailability($s, $e)['Van'];
        $rid = Request::create(['requester_id' => 8, 'department_id' => 3, 'vehicle_type' => 'Van', 'quantity' => 1, 'driver_option' => Request::WITH_DRIVER, 'purpose_type' => Request::OFFICIAL,
                                'purpose' => 'x', 'destination' => 'x', 'address' => 'x', 'waiting_location' => 'x', 'depart_datetime' => $s, 'return_datetime' => $e, 'no_of_passengers' => 1, 'is_urgent' => 0]);
        VehicleReservation::reserve($rid, 'Van', 1, $s, $rc->reservationEnd($e));
        assert_same($before - 1, $rc->checkAvailability($s, $e)['Van']);
        assert_same($before, $rc->checkAvailability($rc->reservationEnd($e), date('Y-m-d 18:00:00', strtotime('+30 day')))['Van']);
    },
    'Personal charge is RM3 per km' => function () {
        assert_near(180.0, (float)Request::calculateCharge(60));
    },
    'UIS email only' => function () {
        $a = new AccountController();
        assert_true($a->isUisEmail('ali@uis.edu.my'));
        assert_true(!$a->isUisEmail('ali@gmail.com'));
        assert_true(!$a->isUisEmail('ali@fakeuis.edu.my'));
    },
    'status history is written for every change' => function () {
        $rid = Request::create(['requester_id' => 8, 'department_id' => 3, 'vehicle_type' => 'Car', 'quantity' => 1, 'driver_option' => Request::WITH_DRIVER, 'purpose_type' => Request::OFFICIAL,
                                'purpose' => 'x', 'destination' => 'x', 'address' => 'x', 'waiting_location' => 'x', 'depart_datetime' => date('Y-m-d 09:00:00', strtotime('+9 day')),
                                'return_datetime' => date('Y-m-d 12:00:00', strtotime('+9 day')), 'no_of_passengers' => 1, 'is_urgent' => 0]);
        Request::updateStatus($rid, Request::APPROVED_BY_HOD, 3, 'ok');
        $h = RequestStatusHistory::findByRequest($rid);
        assert_same([Request::PENDING . '>' . Request::APPROVED_BY_HOD], array_map(fn($x) => $x['old_status'] . '>' . $x['new_status'], $h));
        assert_same(3, (int)$h[0]['changed_by']);
    },
];
