<?php
/**
 * R3 / R4 / R5 against the real database (inside a transaction that is rolled back).
 * Uses seed.sql data, so run  database/dsms.sql + database/seed.sql  first.
 */
$svc = fn() => new AvailabilityService();
$db  = fn() => Database::connection();
/** Insert a minimal request for a vehicle on a day and return its id. */
$makeRequest = function (int $vehicleId, string $day, string $status = 'Pending Approval') use ($db): int {
    $db()->prepare("INSERT INTO requests (requester_id, approver_id, vehicle_id, destination_name, full_address, purpose, passenger_count,
                    contact_phone, departure_datetime, return_datetime, status, submitted_at)
                    VALUES (5, 2, ?, 'T', 'T', 'T', 1, '0123456789', ?, ?, ?, NOW())")
        ->execute([$vehicleId, "$day 09:00:00", "$day 17:00:00", $status]);
    return (int)$db()->lastInsertId();
};
$stateOf = function (array $list, int $id): string {
    foreach ($list as $v) { if ((int)$v['id'] === $id) { return $v['state']; } }
    return 'missing';
};

$tests = [
    'a vehicle with no hold and no maintenance is available' => function () use ($svc, $stateOf) {
        assert_same('available', $stateOf($svc()->vehiclesForPeriod('2031-01-10', '2031-01-10', 2), 1));
    },
    'R3: a hold on any day of the period makes the vehicle booked' => function () use ($svc, $makeRequest, $stateOf) {
        $rid = $makeRequest(1, '2031-02-11');
        $svc()->placeHolds($rid, 1, ['2031-02-11']);
        assert_same('booked', $stateOf($svc()->vehiclesForPeriod('2031-02-10', '2031-02-12', 2), 1), 'overlapping period');
        assert_same('available', $stateOf($svc()->vehiclesForPeriod('2031-02-12', '2031-02-13', 2), 1), 'next days are free');
    },
    'R3: the same vehicle cannot be held twice for one day (database UNIQUE key)' => function () use ($svc, $makeRequest) {
        $a = $makeRequest(2, '2031-03-05'); $b = $makeRequest(2, '2031-03-05');
        $svc()->placeHolds($a, 2, ['2031-03-05']);
        $threw = false;
        try { $svc()->placeHolds($b, 2, ['2031-03-05']); } catch (AvailabilityConflict $e) { $threw = true; }
        assert_true($threw, 'second hold must throw AvailabilityConflict');
    },
    'R3: releasing holds frees the vehicle again' => function () use ($svc, $makeRequest, $stateOf) {
        $rid = $makeRequest(3, '2031-04-01');
        $svc()->placeHolds($rid, 3, ['2031-04-01']);
        $svc()->releaseHolds($rid);
        assert_same('available', $stateOf($svc()->vehiclesForPeriod('2031-04-01', '2031-04-01', 1), 3));
    },
    'R3: a request can be moved without clashing with itself (exceptRequestId)' => function () use ($svc, $makeRequest, $stateOf) {
        $rid = $makeRequest(4, '2031-05-01');
        $svc()->placeHolds($rid, 4, ['2031-05-01']);
        assert_same('available', $stateOf($svc()->vehiclesForPeriod('2031-05-01', '2031-05-01', 1, $rid), 4));
    },
    'R4: maintenance blocks the vehicle' => function () use ($svc, $db, $stateOf) {
        $db()->exec("INSERT INTO vehicle_maintenance (vehicle_id, start_date, end_date) VALUES (5, '2031-06-10', '2031-06-12')");
        assert_same('maintenance', $stateOf($svc()->vehiclesForPeriod('2031-06-12', '2031-06-13', 1), 5), 'last maintenance day counts');
        assert_same('available', $stateOf($svc()->vehiclesForPeriod('2031-06-13', '2031-06-13', 1), 5));
    },
    'R4: too many passengers for the seats' => function () use ($svc, $stateOf) {
        assert_same('too_small', $stateOf($svc()->vehiclesForPeriod('2031-07-01', '2031-07-01', 6), 1));   // Camry has 3 seats
        assert_same('available', $stateOf($svc()->vehiclesForPeriod('2031-07-01', '2031-07-01', 6), 9));   // Urvan has 10
    },
    'R4: an inactive vehicle is not offered at all' => function () use ($svc, $db, $stateOf) {
        $db()->exec("UPDATE vehicles SET status = 'Inactive' WHERE id = 6");
        assert_same('missing', $stateOf($svc()->vehiclesForPeriod('2031-08-01', '2031-08-01', 1), 6));
    },
    'R4: expired road tax blocks the vehicle' => function () use ($svc, $db, $stateOf) {
        $db()->exec("UPDATE vehicles SET road_tax_expiry = '2031-08-31' WHERE id = 7");
        assert_same('road_tax', $stateOf($svc()->vehiclesForPeriod('2031-09-01', '2031-09-01', 1), 7));
        assert_same('available', $stateOf($svc()->vehiclesForPeriod('2031-08-31', '2031-08-31', 1), 7));
    },
    'R5: driver leave is detected' => function () use ($svc, $db) {
        $db()->exec("INSERT INTO driver_leaves (driver_id, start_date, end_date, type) VALUES (5, '2031-10-01', '2031-10-03', 'mc')");
        assert_true($svc()->driverLeave(5, '2031-10-03', '2031-10-04') !== null);
        assert_true($svc()->driverLeave(5, '2031-10-04', '2031-10-04') === null);
    },
    'R5: overlapping time slots clash, touching slots do not' => function () use ($svc, $makeRequest, $db) {
        $rid = $makeRequest(8, '2031-11-05', 'Confirmed');
        $db()->prepare("INSERT INTO request_legs (request_id, leg_type, driver_id, start_datetime, end_datetime) VALUES (?, 'departure', 6, '2031-11-05 09:00:00', '2031-11-05 11:00:00')")->execute([$rid]);
        assert_same(1, count($svc()->driverClashes(6, '2031-11-05 10:00:00', '2031-11-05 12:00:00')), 'overlap');
        assert_same(0, count($svc()->driverClashes(6, '2031-11-05 11:00:00', '2031-11-05 13:00:00')), 'touching');
        assert_same(0, count($svc()->driverClashes(6, '2031-11-05 13:00:00', '2031-11-05 15:00:00')), 'later same day is fine');
        assert_same(0, count($svc()->driverClashes(6, '2031-11-05 10:00:00', '2031-11-05 12:00:00', $rid)), 'ignores the request itself');
    },
    'R5: an internal task counts as a clash too' => function () use ($svc, $db) {
        $db()->exec("INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, driver_id, created_by) VALUES ('refuel', '2031-12-02', '10:00:00', '11:00:00', 7, 4)");
        assert_same(1, count($svc()->driverClashes(7, '2031-12-02 10:30:00', '2031-12-02 12:00:00')));
    },
    'R5: cancelled requests do not clash' => function () use ($svc, $makeRequest, $db) {
        $rid = $makeRequest(8, '2032-01-05', 'Cancelled');
        $db()->prepare("INSERT INTO request_legs (request_id, leg_type, driver_id, start_datetime, end_datetime) VALUES (?, 'departure', 8, '2032-01-05 09:00:00', '2032-01-05 11:00:00')")->execute([$rid]);
        assert_same(0, count($svc()->driverClashes(8, '2032-01-05 09:00:00', '2032-01-05 11:00:00')));
    },
    'R5: licence check' => function () use ($svc) {
        $any = Database::connection()->query('SELECT driver_id, licence_class FROM driver_licences LIMIT 1')->fetch();
        assert_true($svc()->driverHasLicence((int)$any['driver_id'], $any['licence_class']));
        assert_true(!$svc()->driverHasLicence((int)$any['driver_id'], 'NOPE'));
    },
];

// The seed vehicles have real road-tax dates; the tests use far-future dates, so push the expiry
// out of the way first (rolled back with everything else). The road-tax test sets its own date.
return array_map(fn($test) => function () use ($test) {
    Database::connection()->exec("UPDATE vehicles SET road_tax_expiry = '2040-01-01'");
    $test();
}, $tests);
