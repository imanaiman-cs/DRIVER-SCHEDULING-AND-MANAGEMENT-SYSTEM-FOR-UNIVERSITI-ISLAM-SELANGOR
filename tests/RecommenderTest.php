<?php
/**
 * DriverRecommender (SRS UC018): the formula (pure), the ranking, and the filters on the demo data.
 * Database tests run in a transaction that is rolled back.
 */
return [
    'score: 100 - (0.6 * tasks + 0.4 * outside-hours) * 100, between 0 and 100' => function () {
        assert_near(100.0, recommenderScore(0, 0, 0.6, 0.4));
        assert_near(0.0, recommenderScore(1, 1, 0.6, 0.4));
        assert_near(40.0, recommenderScore(1, 0, 0.6, 0.4));
        assert_near(60.0, recommenderScore(0, 1, 0.6, 0.4));
        assert_near(60.0, recommenderScore(0.5, 0.25, 0.6, 0.4));
        assert_near(0.0, recommenderScore(2, 2, 0.6, 0.4), 0.01, 'never below 0');
    },
    'the score has no experience and no welfare-penalty parameter' => function () {
        assert_same(4, (new ReflectionFunction('recommenderScore'))->getNumberOfParameters());
        assert_same(3, (new ReflectionFunction('recommenderRank'))->getNumberOfParameters());
    },
    'ranking: fewer tasks first, counts normalised by the largest among the candidates' => function () {
        $r = recommenderRank([
            ['driver_id' => 1, 'name' => 'Busy',   'tasks' => 10, 'outside' => 4],
            ['driver_id' => 2, 'name' => 'Free',   'tasks' => 0,  'outside' => 0],
            ['driver_id' => 3, 'name' => 'Middle', 'tasks' => 5,  'outside' => 2],
        ], 0.6, 0.4);
        assert_same([2, 3, 1], array_column($r, 'driver_id'));
        assert_same([1, 2, 3], array_column($r, 'rank'));
        assert_near(100.0, $r[0]['score']); assert_near(50.0, $r[1]['score']); assert_near(0.0, $r[2]['score']);
    },
    'ranking: a tie is broken by fewer tasks, then by name; nobody has work -> everybody 100' => function () {
        $r = recommenderRank([['driver_id' => 1, 'name' => 'B', 'tasks' => 3, 'outside' => 0], ['driver_id' => 2, 'name' => 'A', 'tasks' => 3, 'outside' => 0]], 0.6, 0.4);
        assert_same([2, 1], array_column($r, 'driver_id'));
        $r = recommenderRank([['driver_id' => 1, 'name' => 'B', 'tasks' => 0, 'outside' => 0], ['driver_id' => 2, 'name' => 'A', 'tasks' => 0, 'outside' => 0]], 0.6, 0.4);
        assert_near(100.0, $r[0]['score']); assert_near(100.0, $r[1]['score']);
    },
    'outside-hours work weighs less than the total (weights 0.6 / 0.4 come from the settings)' => function () {
        global $conn;
        $rec = recommendDrivers($conn, ['start' => '2034-07-04 09:00:00', 'end' => '2034-07-04 11:00:00', 'licence' => 'D']);
        assert_same(0.6, $rec['weights']['tasks']); assert_same(0.4, $rec['weights']['outside']);
    },
    'licence filter: car D, lorry GDL, bus PSV; inactive drivers are left out with a reason' => function () {
        global $conn;
        $ids = fn($rec) => array_map('intval', array_column($rec['ranked'], 'driver_id'));
        $d = recommendDrivers($conn, ['start' => '2034-07-04 09:00:00', 'end' => '2034-07-04 11:00:00', 'licence' => 'D']);
        assert_true(!in_array(11, $ids($d), true), 'driver 11 is inactive');
        assert_true(in_array(11, array_map('intval', array_column($d['excluded'], 'driver_id')), true));
        $psv = recommendDrivers($conn, ['start' => '2034-07-04 09:00:00', 'end' => '2034-07-04 11:00:00', 'licence' => 'PSV']);
        foreach ($psv['ranked'] as $x) { assert_true(driverHoldsLicence($x['license_class'], 'PSV'), $x['name'] . ' has PSV'); }
        assert_true(!in_array(5, $ids($psv), true), 'driver 5 holds only D');
        $gdl = recommendDrivers($conn, ['start' => '2034-07-04 09:00:00', 'end' => '2034-07-04 11:00:00', 'licence' => 'GDL']);
        foreach ($gdl['ranked'] as $x) { assert_true(driverHoldsLicence($x['license_class'], 'GDL'), $x['name'] . ' has GDL'); }
    },
    'a driver with an overlapping task or internal task is removed, an adjacent one is not' => function () {
        global $conn;
        $ids = fn($rec) => array_map('intval', array_column($rec['ranked'], 'driver_id'));
        $conn->query("INSERT INTO schedules (driver_id, vehicle_id, trip_date, start_time, end_time, destination, status) VALUES (1, 5, '2034-07-05', '09:00', '11:00', 'T', 'approved')");
        $conn->query("INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, vehicle_id, driver_id, description) VALUES ('refuel', '2034-07-05', '10:00', '12:00', 6, 2, 'x')");
        $t = ['start' => '2034-07-05 10:00:00', 'end' => '2034-07-05 12:00:00', 'licence' => 'D'];
        $rec = recommendDrivers($conn, $t);
        assert_true(!in_array(1, $ids($rec), true), 'overlapping request task');
        assert_true(!in_array(2, $ids($rec), true), 'overlapping internal task');
        $rec = recommendDrivers($conn, ['start' => '2034-07-05 11:00:00', 'end' => '2034-07-05 13:00:00', 'licence' => 'D']);
        assert_true(in_array(1, $ids($rec), true), 'adjacent is fine (11:00 = the end of the other task)');
    },
    'month counts: request tasks + internal tasks + welfare days; cancelled ones never count; outside hours counted apart' => function () {
        global $conn;
        $base = driverMonthCounts($conn, '2034-08')[9] ?? ['tasks' => 0, 'outside' => 0, 'welfare' => 0];
        $conn->query("INSERT INTO schedules (driver_id, vehicle_id, trip_date, start_time, end_time, destination, status) VALUES
            (9, 5, '2034-08-02', '09:00', '11:00', 'T', 'approved'),
            (9, 5, '2034-08-03', '06:30', '08:30', 'early', 'approved'),
            (9, 5, '2034-08-05', '10:00', '12:00', 'saturday', 'approved'),
            (9, 5, '2034-08-07', '10:00', '12:00', 'cancelled', 'cancelled')");
        $conn->query("INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, vehicle_id, driver_id, description, status) VALUES
            ('refuel', '2034-08-08', '18:00', '19:00', 6, 9, 'late', 'active'), ('refuel', '2034-08-09', '10:00', '11:00', 6, 9, 'gone', 'cancelled')");
        $conn->query("INSERT INTO welfare_roster (duty_date, driver_id) VALUES ('2034-08-12', 9)");
        $c = driverMonthCounts($conn, '2034-08')[9];
        assert_same($base['tasks'] + 5, $c['tasks'], '3 request tasks + 1 internal task + 1 welfare day');
        assert_same($base['outside'] + 3, $c['outside'], 'early start, Saturday and the evening internal task; welfare is not outside hours');
        assert_same($base['welfare'] + 1, $c['welfare']);
    },
    'welfare duty on the trip date is FLAGGED, not removed' => function () {
        global $conn;
        $conn->query("INSERT INTO welfare_roster (duty_date, driver_id) VALUES ('2034-09-12', 3) ON DUPLICATE KEY UPDATE driver_id = 3");
        $rec = recommendDrivers($conn, ['start' => '2034-09-12 09:00:00', 'end' => '2034-09-12 11:00:00', 'licence' => 'D']);
        $d3 = array_values(array_filter($rec['ranked'], fn($x) => (int)$x['driver_id'] === 3));
        assert_true(count($d3) === 1 && $d3[0]['welfare'] === true, 'driver 3 is listed with the welfare flag');
        foreach ($rec['ranked'] as $x) { assert_true(array_key_exists('tasks', $x) && array_key_exists('outside', $x), 'task count and outside-hours count are shown'); }
    },
];
