<?php
// UC009 Recommend Driver - RecommendationService (SDD_CLASS_208): 50% tasks this month, 30% weekend tasks, 20% experience.
$rs = new RecommendationService();
$max = ['tasks' => 4, 'weekend' => 2, 'years' => 10];
$at = fn(int $days, string $from, string $to) => [date("Y-m-d $from:00", strtotime("+$days day")), date("Y-m-d $to:00", strtotime("+$days day"))];
$ids = fn(array $rec) => array_map('intval', array_column($rec['ranked'], 'driver_id'));
return [
    'fewer tasks rank higher' => function () use ($rs, $max) {
        assert_true($rs->calculateScore(['tasks' => 1, 'weekend' => 0, 'years' => 5], $max) > $rs->calculateScore(['tasks' => 4, 'weekend' => 0, 'years' => 5], $max));
    },
    'fewer weekend tasks rank higher' => function () use ($rs, $max) {
        assert_true($rs->calculateScore(['tasks' => 2, 'weekend' => 0, 'years' => 5], $max) > $rs->calculateScore(['tasks' => 2, 'weekend' => 2, 'years' => 5], $max));
    },
    'more experience ranks higher' => function () use ($rs, $max) {
        assert_true($rs->calculateScore(['tasks' => 2, 'weekend' => 1, 'years' => 10], $max) > $rs->calculateScore(['tasks' => 2, 'weekend' => 1, 'years' => 2], $max));
    },
    'weights add up: best possible driver scores 1, worst 0' => function () use ($rs, $max) {
        assert_near(1.0, $rs->calculateScore(['tasks' => 0, 'weekend' => 0, 'years' => 10], $max));
        assert_near(0.0, $rs->calculateScore(['tasks' => 4, 'weekend' => 2, 'years' => 0], $max));
        assert_near(0.5, $rs->calculateScore(['tasks' => 0, 'weekend' => 2, 'years' => 0], $max), 0.001, 'tasks weigh 50%');
    },
    'ranked by score, the best RECOMMEND_TOP are recommended' => function () use ($rs, $at) {
        [$s, $e] = $at(3, '09:00', '10:00');
        $rec = $rs->recommendDrivers('D', $s, $e);
        $scores = array_column($rec['ranked'], 'score');
        $sorted = $scores; rsort($sorted);
        assert_same($sorted, $scores, 'sorted by score');
        assert_same((int)setting('RECOMMEND_TOP'), count(array_filter(array_column($rec['ranked'], 'recommended'))));
        assert_same(range(1, count($scores)), array_column($rec['ranked'], 'rank'));
    },
    'licence class per vehicle type' => function () {
        assert_same('D', licenceForVehicleType('Car')); assert_same('D', licenceForVehicleType('MPV')); assert_same('D', licenceForVehicleType('Van'));
        assert_same('PSV', licenceForVehicleType('Bus')); assert_same('GDL', licenceForVehicleType('Lorry'));
    },
    'an expired licence is not recommended' => function () use ($rs, $at, $ids) {
        [$s, $e] = $at(3, '09:00', '10:00');
        $rec = $rs->recommendDrivers('D', $s, $e);
        assert_true(!in_array(18, $ids($rec), true), 'drv008 (expired licence) must not be ranked');
        assert_true(in_array(18, array_map('intval', array_column($rec['excluded'], 'driver_id')), true));
    },
    'inactive drivers are left out' => function () use ($rs, $at, $ids) {
        [$s, $e] = $at(3, '09:00', '10:00');
        assert_true(!in_array(21, $ids($rs->recommendDrivers('D', $s, $e)), true), 'drv011 is inactive');
    },
    'drivers without the licence class are left out' => function () use ($rs, $at) {
        [$s, $e] = $at(3, '09:00', '10:00');
        foreach ($rs->recommendDrivers('PSV', $s, $e)['ranked'] as $c) {
            assert_true(Driver::hasLicence(User::findByID((int)$c['driver_id']), 'PSV'), $c['name'] . ' has PSV');
        }
    },
    'an approved schedule change removes the driver' => function () use ($rs, $ids) {
        $day = date('Y-m-d', strtotime('+20 day'));
        Database::execute("INSERT INTO schedule_change (driver_id, start_date, end_date, reason, status) VALUES (11, ?, ?, 'test', 'Approved')", [$day, $day]);
        assert_true(!in_array(11, $ids($rs->recommendDrivers('D', "$day 09:00:00", "$day 10:00:00")), true));
    },
    'a driver with an overlapping task is removed, a touching one is not' => function () use ($rs, $ids) {
        $day = date('Y-m-d', strtotime('+21 day'));
        Task::create(['task_source' => Task::INTERNAL, 'task_type' => 'Workshop', 'vehicle_id' => 1, 'driver_id' => 12, 'start_datetime' => "$day 09:00:00", 'end_datetime' => "$day 10:00:00", 'status' => Task::APPROVED]);
        assert_true(!in_array(12, $ids($rs->recommendDrivers('D', "$day 09:30:00", "$day 10:30:00")), true));
        assert_true(in_array(12, $ids($rs->recommendDrivers('D', "$day 10:00:00", "$day 11:00:00")), true));
    },
    'the driver being replaced is not offered' => function () use ($rs, $at, $ids) {
        [$s, $e] = $at(3, '09:00', '10:00');
        assert_true(!in_array(11, $ids($rs->recommendDrivers('D', $s, $e, ['exclude_driver' => 11])), true));
    },
];
