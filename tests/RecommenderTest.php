<?php
/**
 * DriverRecommender (SRS UC018): the formula (pure), the ranking, and the filters on the demo data.
 * Database tests run in a transaction that is rolled back.
 */
return [
    'score: 100 * (wT*(1-tasks) + wW*(1-weekend) + wE*experience), between 0 and 100' => function () {
        assert_near(80.0, recommenderScore(0, 0, 0, 0.5, 0.3, 0.2));
        assert_near(100.0, recommenderScore(0, 0, 1, 0.5, 0.3, 0.2));
        assert_near(0.0, recommenderScore(1, 1, 0, 0.5, 0.3, 0.2));
        assert_near(70.0, recommenderScore(0, 1, 1, 0.5, 0.3, 0.2));
        assert_near(30.0, recommenderScore(1, 0, 0, 0.5, 0.3, 0.2));
        assert_near(20.0, recommenderScore(1, 1, 1, 0.5, 0.3, 0.2));
        assert_near(0.0, recommenderScore(2, 2, 0, 0.5, 0.3, 0.2), 0.01, 'never below 0');
        assert_near(100.0, recommenderScore(0, 0, 3, 0.5, 0.3, 0.2), 0.01, 'never above 100');
    },
    'the score takes tasks, weekend tasks and experience (three weights); more experience = higher score' => function () {
        assert_same(6, (new ReflectionFunction('recommenderScore'))->getNumberOfParameters());
        assert_same(4, (new ReflectionFunction('recommenderRank'))->getNumberOfParameters());
        assert_true(recommenderScore(0.2, 0.2, 0.9, 0.5, 0.3, 0.2) > recommenderScore(0.2, 0.2, 0.1, 0.5, 0.3, 0.2));
    },
    'experience years are calculated from the date joined' => function () {
        assert_near(5.0, driverExperienceYears('2021-01-01', '2026-01-01'), 0.1);
        assert_same(0.0, driverExperienceYears(null, '2026-01-01'));
        assert_same(0.0, driverExperienceYears('', '2026-01-01'));
        assert_same(0.0, driverExperienceYears('2030-01-01', '2026-01-01'), 'a date in the future counts as 0');
        assert_near(0.5, driverExperienceYears('2025-07-02', '2026-01-01'), 0.1);
    },
    'ranking: fewer tasks, fewer weekend tasks and more experience first' => function () {
        $r = recommenderRank([
            ['driver_id' => 1, 'name' => 'Busy',   'tasks' => 10, 'weekend' => 4, 'experience' => 1.0],
            ['driver_id' => 2, 'name' => 'Free',   'tasks' => 0,  'weekend' => 0, 'experience' => 2.0],
            ['driver_id' => 3, 'name' => 'Middle', 'tasks' => 5,  'weekend' => 2, 'experience' => 4.0],
        ], 0.5, 0.3, 0.2);
        assert_same([2, 3, 1], array_column($r, 'driver_id'));
        assert_same([1, 2, 3], array_column($r, 'rank'));
        assert_near(90.0, $r[0]['score']); assert_near(60.0, $r[1]['score']); assert_near(5.0, $r[2]['score']);
    },
    'ranking: experience breaks a difference only through its weight; a tie is broken by fewer tasks, then by name' => function () {
        $r = recommenderRank([
            ['driver_id' => 1, 'name' => 'Rookie', 'tasks' => 2, 'weekend' => 1, 'experience' => 0.5],
            ['driver_id' => 2, 'name' => 'Senior', 'tasks' => 2, 'weekend' => 1, 'experience' => 9.0],
        ], 0.5, 0.3, 0.2);
        assert_same([2, 1], array_column($r, 'driver_id'));
        $r = recommenderRank([['driver_id' => 1, 'name' => 'B', 'tasks' => 3, 'weekend' => 0, 'experience' => 1.0], ['driver_id' => 2, 'name' => 'A', 'tasks' => 3, 'weekend' => 0, 'experience' => 1.0]], 0.5, 0.3, 0.2);
        assert_same([2, 1], array_column($r, 'driver_id'));
        $r = recommenderRank([['driver_id' => 1, 'name' => 'B', 'tasks' => 0, 'weekend' => 0, 'experience' => 0.0], ['driver_id' => 2, 'name' => 'A', 'tasks' => 0, 'weekend' => 0, 'experience' => 0.0]], 0.5, 0.3, 0.2);
        assert_near(80.0, $r[0]['score']); assert_near(80.0, $r[1]['score']);
    },
    'the three weights 0.5 / 0.3 / 0.2 come from the settings' => function () {
        global $conn;
        $rec = recommendDrivers($conn, ['start' => '2034-07-04 09:00:00', 'end' => '2034-07-04 11:00:00', 'licence' => 'D']);
        assert_same(0.5, $rec['weights']['tasks']); assert_same(0.3, $rec['weights']['weekend']); assert_same(0.2, $rec['weights']['experience']);
        assert_true(!empty($rec['ranked']) && isset($rec['ranked'][0]['weekend'], $rec['ranked'][0]['experience']));
    },
    'weekend tasks are counted by driverMonthCounts (Saturday and Sunday only)' => function () {
        global $conn;
        $conn->query("DELETE FROM schedules WHERE driver_id = 1 AND trip_date LIKE '2034-07-%'");
        // 2034-07-01 is a Saturday, 2034-07-02 a Sunday, 2034-07-03 a Monday
        foreach (['2034-07-01', '2034-07-02', '2034-07-03'] as $d) {
            $conn->query("INSERT INTO schedules (driver_id, vehicle_id, trip_date, start_time, end_time, destination, status) VALUES (1, 5, '$d', '09:00', '11:00', 'T', 'approved')");
        }
        $c = driverMonthCounts($conn, '2034-07')[1];
        assert_same(3, (int)$c['tasks']); assert_same(2, (int)$c['weekend']);
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
