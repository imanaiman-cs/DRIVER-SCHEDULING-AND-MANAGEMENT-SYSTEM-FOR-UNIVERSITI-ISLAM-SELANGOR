<?php
/**
 * The weighted driver score (the part to explain to the examiner) and its hard filters.
 * The first group needs no database; the second uses the demo data in a rolled-back transaction.
 */
$cand = fn(string $name, int $tasks, int $after, bool $welfare = false, array $warnings = []) =>
    ['driver_id' => crc32($name), 'name' => $name, 'tasks' => $tasks, 'after_hours' => $after, 'welfare' => $welfare, 'warnings' => $warnings];
$q = fn(string $sql) => $GLOBALS['conn']->query($sql);

return [
    // ---------------------------------------------------------------- the formula
    'score: a driver with no work scores 100' => function () {
        assert_near(100.0, recommenderScore(0, 0, false, 0.6, 0.4, 20));
    },
    'score: the busiest driver on both factors scores 0' => function () {
        assert_near(0.0, recommenderScore(1, 1, false, 0.6, 0.4, 20));
    },
    'score: worked example, norm 0.5 and 0.25 -> 60' => function () {
        // 100 - (0.6*0.5 + 0.4*0.25) * 100 = 100 - (0.30 + 0.10) * 100 = 60
        assert_near(60.0, recommenderScore(0.5, 0.25, false, 0.6, 0.4, 20));
    },
    'score: welfare duty takes 20 points off' => function () {
        assert_near(40.0, recommenderScore(0.5, 0.25, true, 0.6, 0.4, 20));
    },
    'score: stays between 0 and 100' => function () {
        assert_near(0.0, recommenderScore(1, 1, true, 0.6, 0.4, 20));
        assert_near(100.0, recommenderScore(0, 0, false, 0.6, 0.4, 0));
    },
    // ---------------------------------------------------------------- ranking
    'rank: normalises against the busiest candidate' => function () use ($cand) {
        $r = recommenderRank([$cand('A', 4, 2), $cand('B', 2, 0), $cand('C', 0, 0)], 0.6, 0.4, 20);
        $by = array_column($r, null, 'name');
        assert_near(0.0, $by['A']['score'], 0.01, 'A is busiest in both');
        assert_near(70.0, $by['B']['score'], 0.01, 'B: 100 - 0.6*0.5*100');
        assert_near(100.0, $by['C']['score'], 0.01, 'C has no work');
        assert_same(['C', 'B', 'A'], array_column($r, 'name'));
    },
    'rank: nobody busy -> everyone 100, ordered by name' => function () use ($cand) {
        $r = recommenderRank([$cand('Zed', 0, 0), $cand('Amir', 0, 0)], 0.6, 0.4, 20);
        assert_same(['Amir', 'Zed'], array_column($r, 'name'));
        assert_near(100.0, $r[0]['score']);
    },
    'rank: changing the weights changes the winner' => function () use ($cand) {
        $list = [$cand('A', 4, 0), $cand('B', 2, 2)];
        assert_same('A', recommenderRank($list, 0.6, 0.4, 20)[0]['name'], 'default weights');
        assert_same('B', recommenderRank($list, 0.9, 0.1, 20)[0]['name'], 'tasks matter more');
    },
    'rank: after-hours work is weighted extra' => function () use ($cand) {
        $r = recommenderRank([$cand('Evening', 3, 3), $cand('Daytime', 3, 0)], 0.6, 0.4, 20);
        assert_same('Daytime', $r[0]['name']);
    },
    'rank: a warning does not change the score, but listed after free drivers' => function () use ($cand) {
        $r = recommenderRank([$cand('Busy', 0, 0, false, ['Internal task']), $cand('Free', 5, 5)], 0.6, 0.4, 20);
        assert_same('Free', $r[0]['name']);
        assert_near(100.0, $r[1]['score'], 0.01, 'the driver with a warning still has a score');
    },
    // ---------------------------------------------------------------- hard filters (database)
    'R8: Top Management drivers are not offered for a normal trip' => function () {
        global $conn;
        $rec = recommendDrivers($conn, ['date' => '2033-03-09', 'start' => '09:00:00', 'end' => '11:00:00', 'vehicle_type' => 'Car']);
        $tm = array_column($conn->query("SELECT driver_id FROM drivers WHERE driver_type = 'top_management'")->fetch_all(MYSQLI_ASSOC), 'driver_id');
        assert_true($tm !== [], 'demo data has Top Management drivers');
        assert_same([], array_values(array_intersect(array_column($rec['ranked'], 'driver_id'), array_map('intval', $tm))));
    },
    'R8: a Top Management trip offers only Top Management drivers' => function () {
        global $conn;
        $rec = recommendDrivers($conn, ['date' => '2033-03-09', 'start' => '09:00:00', 'end' => '11:00:00', 'vehicle_type' => 'Car', 'trip_type' => 'top_management']);
        assert_true($rec['ranked'] !== []);
        $ids = implode(',', array_map('intval', array_column($rec['ranked'], 'driver_id')));
        assert_same('0', (string)$conn->query("SELECT COUNT(*) FROM drivers WHERE driver_id IN ($ids) AND driver_type <> 'top_management'")->fetch_row()[0]);
    },
    'R5: the licence class of the vehicle is required' => function () {
        global $conn;
        $rec = recommendDrivers($conn, ['date' => '2033-03-09', 'start' => '09:00:00', 'end' => '11:00:00', 'vehicle_type' => 'Bus']);
        foreach ($rec['ranked'] as $c) {
            assert_true(driverHasLicense($c['license_class'], requiredLicenseClasses('Bus')), $c['name'] . ' needs a bus licence');
        }
    },
    'a driver on leave that day is excluded with the reason' => function () {
        global $conn;
        $id = (int)$conn->query("SELECT driver_id FROM drivers WHERE status='active' AND driver_type='regular' LIMIT 1")->fetch_row()[0];
        $conn->query("INSERT INTO driver_leaves (driver_id, start_date, end_date, leave_type) VALUES ($id, '2033-03-08', '2033-03-10', 'mc')");
        $rec = recommendDrivers($conn, ['date' => '2033-03-09', 'start' => '09:00:00', 'end' => '11:00:00', 'vehicle_type' => 'Car']);
        assert_true(!in_array($id, array_column($rec['ranked'], 'driver_id')), 'not ranked');
        $why = array_column($rec['excluded'], 'reason', 'driver_id');
        assert_true(isset($why[$id]) && str_contains($why[$id], 'MC'), 'reason mentions MC');
    },
    'a driver already on a trip in that time slot is excluded' => function () {
        global $conn;
        $id = (int)$conn->query("SELECT driver_id FROM drivers WHERE status='active' AND driver_type='regular' LIMIT 1")->fetch_row()[0];
        $conn->query("INSERT INTO schedules (driver_id, trip_date, start_time, end_time, destination, status) VALUES ($id, '2033-03-09', '10:00:00', '12:00:00', 'T', 'approved')");
        $rec = recommendDrivers($conn, ['date' => '2033-03-09', 'start' => '11:00:00', 'end' => '13:00:00', 'vehicle_type' => 'Car']);
        assert_true(!in_array($id, array_column($rec['ranked'], 'driver_id')));
        $rec2 = recommendDrivers($conn, ['date' => '2033-03-09', 'start' => '12:00:00', 'end' => '14:00:00', 'vehicle_type' => 'Car']);
        assert_true(in_array($id, array_column($rec2['ranked'], 'driver_id')), 'a slot that only touches is fine');
    },
    // ---------------------------------------------------------------- workload counting
    'tasks count trips (not cancelled) and internal tasks' => function () {
        global $conn;
        $id = (int)$conn->query("SELECT driver_id FROM drivers WHERE status='active' AND driver_type='regular' LIMIT 1")->fetch_row()[0];
        $conn->query("INSERT INTO schedules (driver_id, trip_date, start_time, end_time, destination, status) VALUES
                      ($id, '2033-05-02', '09:00:00', '11:00:00', 'T', 'approved'),
                      ($id, '2033-05-03', '09:00:00', '11:00:00', 'T', 'completed'),
                      ($id, '2033-05-04', '09:00:00', '11:00:00', 'T', 'cancelled'),
                      ($id, '2033-06-02', '09:00:00', '11:00:00', 'T', 'approved')");
        $conn->query("INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, driver_id) VALUES ('refuel', '2033-05-05', '10:00:00', '11:00:00', $id)");
        $c = getMonthlyTaskCounts($conn, '2033-05');
        assert_same(3, $c[$id]['tasks'], '2 trips + 1 internal task; cancelled and other months not counted');
    },
    'after-hours counts weekends, early starts and late finishes' => function () {
        global $conn;
        $id = (int)$conn->query("SELECT driver_id FROM drivers WHERE status='active' AND driver_type='regular' LIMIT 1")->fetch_row()[0];
        $conn->query("INSERT INTO schedules (driver_id, trip_date, start_time, end_time, destination, status) VALUES
                      ($id, '2033-05-14', '09:00:00', '11:00:00', 'T', 'approved'),
                      ($id, '2033-05-16', '06:30:00', '08:30:00', 'T', 'approved'),
                      ($id, '2033-05-17', '16:00:00', '19:00:00', 'T', 'approved'),
                      ($id, '2033-05-18', '09:00:00', '11:00:00', 'T', 'approved')");
        $c = getMonthlyTaskCounts($conn, '2033-05');
        assert_same(4, $c[$id]['tasks']);
        assert_same(3, $c[$id]['weekend'], 'Saturday + early start + late finish');
    },
    'the least busy driver is recommended first' => function () {
        global $conn;
        $ids = array_map('intval', array_column($conn->query("SELECT driver_id FROM drivers WHERE status='active' AND driver_type='regular' LIMIT 3")->fetch_all(MYSQLI_ASSOC), 'driver_id'));
        foreach ([2, 3, 4, 5] as $d) {
            $conn->query("INSERT INTO schedules (driver_id, trip_date, start_time, end_time, destination, status) VALUES ({$ids[0]}, '2033-07-0$d', '09:00:00', '11:00:00', 'T', 'approved')");
        }
        $rec = recommendDrivers($conn, ['date' => '2033-07-20', 'start' => '09:00:00', 'end' => '11:00:00', 'vehicle_type' => 'Car']);
        $order = array_column($rec['ranked'], 'driver_id');
        assert_true($order[0] !== $ids[0], 'the busy driver is not first');
        assert_same($ids[0], (int)end($order), 'the busy driver is last');
    },
    'welfare duty that day lowers the score by the penalty' => function () {
        global $conn;
        $id = (int)$conn->query("SELECT driver_id FROM drivers WHERE status='active' AND driver_type='regular' LIMIT 1")->fetch_row()[0];
        $trip = ['date' => '2033-08-09', 'start' => '09:00:00', 'end' => '11:00:00', 'vehicle_type' => 'Car'];
        $before = array_column(recommendDrivers($conn, $trip)['ranked'], null, 'driver_id')[$id]['score'];
        $conn->query("INSERT INTO welfare_roster (duty_date, driver_id) VALUES ('2033-08-09', $id)");
        $after = array_column(recommendDrivers($conn, $trip)['ranked'], null, 'driver_id')[$id];
        assert_true($after['welfare']);
        assert_near($before - 20, $after['score'], 0.11);
    },
    'an internal task in the slot is a warning, not a block' => function () {
        global $conn;
        $id = (int)$conn->query("SELECT driver_id FROM drivers WHERE status='active' AND driver_type='regular' LIMIT 1")->fetch_row()[0];
        $conn->query("INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, driver_id) VALUES ('refuel', '2033-09-09', '10:00:00', '11:00:00', $id)");
        $rec = recommendDrivers($conn, ['date' => '2033-09-09', 'start' => '10:30:00', 'end' => '12:00:00', 'vehicle_type' => 'Car']);
        $c = array_column($rec['ranked'], null, 'driver_id')[$id] ?? null;
        assert_true($c !== null, 'still selectable');
        assert_same(1, count($c['warnings']));
    },
    'the weights come from the settings table' => function () {
        global $conn;
        $id = (int)$conn->query("SELECT driver_id FROM drivers WHERE status='active' AND driver_type='regular' LIMIT 1")->fetch_row()[0];
        $conn->query("INSERT INTO schedules (driver_id, trip_date, start_time, end_time, destination, status) VALUES ($id, '2033-10-02', '09:00:00', '11:00:00', 'T', 'approved')");
        $trip = ['date' => '2033-10-20', 'start' => '09:00:00', 'end' => '11:00:00', 'vehicle_type' => 'Car'];
        $default = array_column(recommendDrivers($conn, $trip)['ranked'], null, 'driver_id');
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('W_TASKS','0.2') ON DUPLICATE KEY UPDATE setting_value='0.2'");
        $GLOBALS['__settings_dirty'] = true;
        $changed = array_column(recommendDrivers($conn, $trip)['ranked'], null, 'driver_id');
        assert_true($changed[$id]['score'] > $default[$id]['score'] || count($default) === 1, 'a lower weight on tasks gives a higher score');
        $GLOBALS['__settings_dirty'] = true;
    },
];
