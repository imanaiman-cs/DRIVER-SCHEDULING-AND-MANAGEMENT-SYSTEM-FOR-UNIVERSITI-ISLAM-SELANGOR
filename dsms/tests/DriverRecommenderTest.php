<?php
/**
 * The weighted scoring (the part to explain to the examiner) and the hard filters.
 * The first group needs no database; the second group uses the seed data in a rolled-back transaction.
 */
$cand = fn(string $name, int $tasks, int $after, bool $welfare = false, array $clashes = []) =>
    ['id' => crc32($name), 'name' => $name, 'tasks' => $tasks, 'after_hours' => $after, 'welfare' => $welfare, 'clashes' => $clashes];
$db = fn() => Database::connection();

/** A Confirmed request with one leg for a driver, to create workload for the tests. */
$addLeg = function (int $driverId, string $start, string $end, string $status = 'Confirmed') use ($db): int {
    $day = substr($start, 0, 10);
    $db()->prepare("INSERT INTO requests (requester_id, approver_id, vehicle_id, destination_name, full_address, purpose, passenger_count, contact_phone, departure_datetime, return_datetime, status, submitted_at)
                    VALUES (5, 2, 1, 'T', 'T', 'T', 1, '0123456789', ?, ?, ?, NOW())")->execute([$start, $end, $status]);
    $rid = (int)$db()->lastInsertId();
    $db()->prepare("INSERT INTO request_legs (request_id, leg_type, driver_id, start_datetime, end_datetime) VALUES (?, 'departure', ?, ?, ?)")->execute([$rid, $driverId, $start, $end]);
    return $rid;
};
$leg = ['start_datetime' => '2033-03-09 09:00:00', 'end_datetime' => '2033-03-09 11:00:00'];   // a Wednesday
$ids = function (array $list): array { return array_column($list, 'id'); };

return [
    // ---------------------------------------------------------------- the formula
    'score: a driver with no work scores 100' => function () {
        assert_near(100.0, DriverRecommender::score(0, 0, false, 0.6, 0.4, 20));
    },
    'score: the busiest driver on both factors scores 0' => function () {
        assert_near(0.0, DriverRecommender::score(1, 1, false, 0.6, 0.4, 20));
    },
    'score: weights 0.6 / 0.4 worked example (norm 0.5 and 0.25 -> 60)' => function () {
        // 100 - (0.6*0.5 + 0.4*0.25) * 100 = 100 - (0.30 + 0.10) * 100 = 60
        assert_near(60.0, DriverRecommender::score(0.5, 0.25, false, 0.6, 0.4, 20));
    },
    'score: welfare penalty takes 20 points off' => function () {
        assert_near(40.0, DriverRecommender::score(0.5, 0.25, true, 0.6, 0.4, 20));
    },
    'score: never goes below 0 or above 100' => function () {
        assert_near(0.0, DriverRecommender::score(1, 1, true, 0.6, 0.4, 20));
        assert_near(100.0, DriverRecommender::score(0, 0, false, 0.6, 0.4, 0));
    },
    // ---------------------------------------------------------------- ranking
    'rank: normalises against the busiest candidate' => function () use ($cand) {
        $r = DriverRecommender::rank([$cand('A', 4, 2), $cand('B', 2, 0), $cand('C', 0, 0)], 0.6, 0.4, 20);
        $byName = array_column($r, null, 'name');
        assert_near(0.0, $byName['A']['score'], 0.01, 'A is busiest in both');
        assert_near(70.0, $byName['B']['score'], 0.01, 'B: 100 - 0.6*0.5*100');
        assert_near(100.0, $byName['C']['score'], 0.01, 'C has no work');
        assert_same(['C', 'B', 'A'], array_column($r, 'name'));
    },
    'rank: when nobody has work everyone scores 100 and order is by name' => function () use ($cand) {
        $r = DriverRecommender::rank([$cand('Zed', 0, 0), $cand('Amir', 0, 0)], 0.6, 0.4, 20);
        assert_same(['Amir', 'Zed'], array_column($r, 'name'));
        assert_near(100.0, $r[0]['score']);
    },
    'rank: changing the weights changes the winner' => function () use ($cand) {
        $list = [$cand('A', 4, 0), $cand('B', 2, 2)];
        assert_same('A', DriverRecommender::rank($list, 0.6, 0.4, 20)[0]['name'], 'default weights');
        assert_same('B', DriverRecommender::rank($list, 0.9, 0.1, 20)[0]['name'], 'tasks matter more');
    },
    'rank: after-hours work is weighted extra' => function () use ($cand) {
        // Same number of tasks; the one who did the after-hours work ranks lower.
        $r = DriverRecommender::rank([$cand('Evening', 3, 3), $cand('Daytime', 3, 0)], 0.6, 0.4, 20);
        assert_same('Daytime', $r[0]['name']);
    },
    'rank: a clash is only a warning, but clashing drivers are listed after free ones' => function () use ($cand) {
        $r = DriverRecommender::rank([$cand('Busy', 0, 0, false, ['Request #9']), $cand('Free', 5, 5)], 0.6, 0.4, 20);
        assert_same('Free', $r[0]['name']);
        assert_true(isset($r[1]['score']), 'the clashing driver still has a score');
    },
    // ---------------------------------------------------------------- hard filters (database)
    'R8: management drivers are never recommended' => function () use ($ids) {
        $rec = (new DriverRecommender())->recommend(['start_datetime' => '2033-03-09 09:00:00', 'end_datetime' => '2033-03-09 11:00:00'], 'D');
        $mgmt = array_column(Database::connection()->query("SELECT id FROM drivers WHERE driver_group = 'management'")->fetchAll(), 'id');
        assert_true($mgmt !== [], 'seed has management drivers');
        assert_same([], array_values(array_intersect($ids($rec['ranked']), $mgmt)));
        assert_true(count($rec['excluded']) >= count($mgmt));
    },
    'R5: a driver without the licence is excluded (bus needs PSV)' => function () use ($ids) {
        $rec = (new DriverRecommender())->recommend(['start_datetime' => '2033-03-09 09:00:00', 'end_datetime' => '2033-03-09 11:00:00'], 'PSV');
        assert_true($rec['ranked'] !== [], 'some drivers have PSV');
        $psv = array_column(Database::connection()->query("SELECT driver_id FROM driver_licences WHERE licence_class = 'PSV'")->fetchAll(), 'driver_id');
        foreach ($ids($rec['ranked']) as $id) { assert_true(in_array($id, $psv), "driver $id has PSV"); }
    },
    'a driver on leave that day is excluded with the reason' => function () use ($db, $ids, $leg) {
        $db()->exec("INSERT INTO driver_leaves (driver_id, start_date, end_date, type) VALUES (1, '2033-03-08', '2033-03-10', 'mc')");
        $rec = (new DriverRecommender())->recommend($leg, 'D');
        assert_true(!in_array(1, $ids($rec['ranked'])), 'driver 1 not ranked');
        $why = array_column($rec['excluded'], 'reason', 'id');
        assert_true(isset($why[1]) && str_contains($why[1], 'MC'), 'reason mentions MC');
    },
    'an inactive driver is excluded' => function () use ($db, $ids, $leg) {
        $db()->exec('UPDATE drivers SET is_active = 0 WHERE id = 2');
        assert_true(!in_array(2, $ids((new DriverRecommender())->recommend($leg, 'D')['ranked'])));
    },
    // ---------------------------------------------------------------- workload counting
    'tasks this month count Assigned, Confirmed and Completed legs but not Cancelled or Pending' => function () use ($addLeg, $leg) {
        $addLeg(3, '2033-03-02 09:00:00', '2033-03-02 11:00:00', 'Confirmed');
        $addLeg(3, '2033-03-03 09:00:00', '2033-03-03 11:00:00', 'Completed');
        $addLeg(3, '2033-03-04 09:00:00', '2033-03-04 11:00:00', 'Driver Assigned');
        $addLeg(3, '2033-03-05 09:00:00', '2033-03-05 11:00:00', 'Cancelled');
        $addLeg(3, '2033-03-06 09:00:00', '2033-03-06 11:00:00', 'Pending Approval');
        $addLeg(3, '2033-04-02 09:00:00', '2033-04-02 11:00:00', 'Confirmed');       // another month
        $c = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[3];
        assert_same(3, $c['tasks']);
    },
    'after-hours counts weekends, early starts and late finishes' => function () use ($addLeg, $leg) {
        $addLeg(4, '2033-03-12 09:00:00', '2033-03-12 11:00:00');   // Saturday
        $addLeg(4, '2033-03-14 06:30:00', '2033-03-14 08:30:00');   // Monday, starts before 08:00
        $addLeg(4, '2033-03-15 16:00:00', '2033-03-15 19:00:00');   // Tuesday, ends after 17:30
        $addLeg(4, '2033-03-16 09:00:00', '2033-03-16 11:00:00');   // Wednesday, normal hours
        $c = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[4];
        assert_same(4, $c['tasks']);
        assert_same(3, $c['after_hours']);
    },
    'internal tasks count as work too' => function () use ($db, $leg) {
        $db()->exec("INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, driver_id, created_by) VALUES ('refuel', '2033-03-07', '10:00:00', '11:00:00', 5, 4)");
        $c = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[5];
        assert_same(1, $c['tasks']);
    },
    'the request being assigned is not counted against the driver' => function () use ($addLeg, $leg) {
        $rid = $addLeg(6, '2033-03-09 09:00:00', '2033-03-09 11:00:00', 'Driver Assigned');
        $with    = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[6]['tasks'];
        $without = array_column((new DriverRecommender())->recommend($leg, 'D', $rid)['ranked'], null, 'id')[6]['tasks'];
        assert_same(1, $with); assert_same(0, $without);
    },
    'the least busy driver is recommended first' => function () use ($addLeg, $leg) {
        foreach ([2, 3, 4, 5] as $day) { $addLeg(1, "2033-03-0$day 09:00:00", "2033-03-0$day 11:00:00"); }
        $r = (new DriverRecommender())->recommend($leg, 'D')['ranked'];
        assert_true($r[0]['id'] !== 1 && $r[0]['tasks'] <= $r[count($r) - 1]['tasks']);
        assert_same(1, end($r)['id'], 'the busy driver is last');
    },
    'welfare duty that day lowers the score by the penalty' => function () use ($db, $leg) {
        $before = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[7]['score'];
        $db()->exec("INSERT INTO welfare_roster (duty_date, driver_id) VALUES ('2033-03-09', 7)");
        $after = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[7];
        assert_true($after['welfare']);
        assert_near($before - 20, $after['score'], 0.11);
    },
    'a time clash is reported but the driver stays selectable' => function () use ($addLeg, $leg) {
        $addLeg(8, '2033-03-09 10:00:00', '2033-03-09 12:00:00');
        $c = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[8];
        assert_same(1, count($c['clashes']));
    },
    'the weights come from the settings table' => function () use ($db, $addLeg, $leg) {
        $addLeg(9, '2033-03-02 09:00:00', '2033-03-02 11:00:00');
        $default = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[9]['score'];
        $db()->exec("UPDATE settings SET setting_value = '0.2' WHERE setting_key = 'W_TASKS'");
        $changed = array_column((new DriverRecommender())->recommend($leg, 'D')['ranked'], null, 'id')[9]['score'];
        assert_true($changed > $default, 'lower weight on tasks gives a higher score');
    },
];
