<?php
// UC009 Recommend Driver: weights 50% tasks this month, 30% weekend tasks, 20% experience.
return [
    'fewer tasks rank higher' => function () {
        $w = ['tasks' => 0.5, 'weekend' => 0.3, 'experience' => 0.2];
        assert_true(recommenderScore(1, 0, 5, 4, 2, 10, $w) > recommenderScore(4, 0, 5, 4, 2, 10, $w));
    },
    'fewer weekend tasks rank higher' => function () {
        $w = ['tasks' => 0.5, 'weekend' => 0.3, 'experience' => 0.2];
        assert_true(recommenderScore(2, 0, 5, 4, 2, 10, $w) > recommenderScore(2, 2, 5, 4, 2, 10, $w));
    },
    'more experience ranks higher' => function () {
        $w = ['tasks' => 0.5, 'weekend' => 0.3, 'experience' => 0.2];
        assert_true(recommenderScore(2, 1, 10, 4, 2, 10, $w) > recommenderScore(2, 1, 2, 4, 2, 10, $w));
    },
    'weights add up: best possible driver scores 1' => function () {
        assert_near(1.0, recommenderScore(0, 0, 10, 4, 2, 10, ['tasks' => 0.5, 'weekend' => 0.3, 'experience' => 0.2]));
        assert_near(0.0, recommenderScore(4, 2, 0, 4, 2, 10, ['tasks' => 0.5, 'weekend' => 0.3, 'experience' => 0.2]));
    },
    'the best RECOMMEND_TOP drivers are recommended' => function () {
        $r = recommenderRank([['name' => 'A', 'score' => 0.2, 'tasks' => 3], ['name' => 'B', 'score' => 0.9, 'tasks' => 0], ['name' => 'C', 'score' => 0.5, 'tasks' => 1]], 2);
        assert_same(['B', 'C', 'A'], array_column($r, 'name'));
        assert_same([true, true, false], array_column($r, 'recommended'));
    },
    'licence class per vehicle type' => function () {
        assert_same('D', licenceForVehicleType('Car')); assert_same('D', licenceForVehicleType('MPV')); assert_same('D', licenceForVehicleType('Van'));
        assert_same('PSV', licenceForVehicleType('Bus')); assert_same('GDL', licenceForVehicleType('Lorry'));
    },
    'an expired licence is not recommended' => function () {
        global $conn;
        $s = date('Y-m-d 09:00:00', strtotime('+3 day')); $e = date('Y-m-d 10:00:00', strtotime('+3 day'));
        $rec = recommendDrivers($conn, ['licence' => 'D', 'start' => $s, 'end' => $e]);
        assert_true(!in_array(8, array_column($rec['ranked'], 'driver_id'), true), 'driver 8 (expired licence) must not be ranked');
        assert_true(in_array(8, array_column($rec['excluded'], 'driver_id'), true));
    },
    'drivers without the licence class are left out' => function () {
        global $conn;
        $s = date('Y-m-d 09:00:00', strtotime('+3 day')); $e = date('Y-m-d 10:00:00', strtotime('+3 day'));
        foreach (recommendDrivers($conn, ['licence' => 'PSV', 'start' => $s, 'end' => $e])['ranked'] as $c) {
            assert_true(driverHoldsLicence(dbValue($conn, 'SELECT licence_classes FROM drivers WHERE driver_id = ?', [$c['driver_id']]), 'PSV'));
        }
    },
    'an approved schedule change removes the driver' => function () {
        global $conn;
        $day = date('Y-m-d', strtotime('+20 day'));
        dbExec($conn, "INSERT INTO schedule_changes (driver_id, start_date, end_date, reason, status) VALUES (1, ?, ?, 'test', 'approved')", [$day, $day]);
        $rec = recommendDrivers($conn, ['licence' => 'D', 'start' => "$day 09:00:00", 'end' => "$day 10:00:00"]);
        assert_true(!in_array(1, array_column($rec['ranked'], 'driver_id'), true));
    },
    'a driver with an overlapping task is removed, a touching one is not' => function () {
        global $conn;
        $day = date('Y-m-d', strtotime('+21 day'));
        dbExec($conn, "INSERT INTO tasks (task_source, task_type, vehicle_id, driver_id, start_dt, end_dt, status) VALUES ('internal', 'workshop', 1, 2, ?, ?, 'approved')", ["$day 09:00:00", "$day 10:00:00"]);
        assert_true(!in_array(2, array_column(recommendDrivers($conn, ['licence' => 'D', 'start' => "$day 09:30:00", 'end' => "$day 10:30:00"])['ranked'], 'driver_id'), true));
        assert_true(in_array(2, array_column(recommendDrivers($conn, ['licence' => 'D', 'start' => "$day 10:00:00", 'end' => "$day 11:00:00"])['ranked'], 'driver_id'), true));
    },
];
