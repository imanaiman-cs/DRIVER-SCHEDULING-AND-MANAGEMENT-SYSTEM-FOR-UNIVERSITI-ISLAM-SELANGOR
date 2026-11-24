<?php
// Business rules of the request (UC018, UC003) and the vehicle reservation.
return [
    'outbound and return tasks last the task duration' => function () {
        $w = requestTaskWindows(['depart_at' => '2026-12-01 08:00:00', 'return_at' => '2026-12-01 17:00:00'], 1);
        assert_same(['2026-12-01 08:00:00', '2026-12-01 09:00:00'], $w['outbound']);
        assert_same(['2026-12-01 17:00:00', '2026-12-01 18:00:00'], $w['return']);
    },
    'a short trip: the outbound task ends at the return time' => function () {
        $w = requestTaskWindows(['depart_at' => '2026-12-01 08:00:00', 'return_at' => '2026-12-01 08:30:00'], 1);
        assert_same('2026-12-01 08:30:00', $w['outbound'][1]);
    },
    'the reservation lasts until one hour after the return' => function () {
        global $conn;
        assert_same('2026-12-01 18:00:00', reservationEnd($conn, '2026-12-01 17:00:00'));
    },
    'urgent when departing within two days' => function () {
        global $conn;
        assert_true(isUrgentDeparture($conn, date('Y-m-d H:i:s', time() + 47 * 3600)));
        assert_true(!isUrgentDeparture($conn, date('Y-m-d H:i:s', time() + 49 * 3600)));
    },
    'return must be later than departure' => function () {
        [$e] = checkTripPeriod(date('Y-m-d', strtotime('+2 day')), '10:00', date('Y-m-d', strtotime('+2 day')), '09:00');
        assert_true(isset($e['return']));
    },
    'driver options per vehicle type' => function () {
        $staff = ['role' => 'staff'];
        $gdl = ['role' => 'driver', 'licence_classes' => 'D,GDL', 'licence_expiry' => date('Y-m-d', strtotime('+1 year'))];
        assert_same(['with_driver', 'pay_claim'], driverOptionsFor($staff, 'Car'));
        assert_same(['with_driver'], driverOptionsFor($staff, 'Lorry'));
        assert_same(['with_driver', 'self_drive'], driverOptionsFor($gdl, 'Lorry'));
        assert_same(['with_driver'], driverOptionsFor($gdl, 'Bus'));
    },
    'Personal is for lorries only' => function () {
        assert_true(in_array('personal', purposeTypesFor('Lorry'), true));
        assert_true(!in_array('personal', purposeTypesFor('Car'), true));
    },
    'a Head of Department is approved by the head of the parent department' => function () {
        global $conn;
        assert_same(1, approverFor($conn, loadUser($conn, 3)));   // hos002 (Faculty of Education) -> hos005 (Vice-Chancellor's Office)
        assert_same(3, approverFor($conn, loadUser($conn, 8)));   // stf001 -> hos002
    },
    'units left drop when a vehicle is reserved' => function () {
        global $conn;
        $s = date('Y-m-d 09:00:00', strtotime('+30 day')); $e = date('Y-m-d 12:00:00', strtotime('+30 day'));
        $before = unitsLeftByType($conn, $s, $e)['Van'];
        dbExec($conn, "INSERT INTO requests (requester_id, vehicle_type, purpose, destination, address, waiting_location, depart_at, return_at) VALUES (8, 'Van', 'x', 'x', 'x', 'x', ?, ?)", [$s, $e]);
        dbExec($conn, 'INSERT INTO request_vehicles (request_id, vehicle_id, start_dt, end_dt) VALUES (LAST_INSERT_ID(), 6, ?, ?)', [$s, reservationEnd($conn, $e)]);
        assert_same($before - 1, unitsLeftByType($conn, $s, $e)['Van']);
        assert_same($before, unitsLeftByType($conn, reservationEnd($conn, $e), date('Y-m-d 18:00:00', strtotime('+30 day')))['Van']);
    },
    'UIS email only' => function () {
        global $conn;
        assert_true(isUisEmail($conn, 'ali@uis.edu.my'));
        assert_true(!isUisEmail($conn, 'ali@gmail.com'));
        assert_true(!isUisEmail($conn, 'ali@fakeuis.edu.my'));
    },
];
