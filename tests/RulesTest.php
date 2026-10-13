<?php
/** The status engine, day holds and the two-day text. Database tests are rolled back. */
return [
    'status map: allowed and forbidden moves' => function () {
        assert_true(canChangeRequestStatus('pending', 'approved'));
        assert_true(canChangeRequestStatus('pending', 'lapsed'));
        assert_true(canChangeRequestStatus('approved', 'processed'));
        assert_true(canChangeRequestStatus('processed', 'completed'));
        assert_true(!canChangeRequestStatus('pending', 'processed'), 'must be approved first');
        assert_true(!canChangeRequestStatus('rejected', 'approved'));
        assert_true(!canChangeRequestStatus('completed', 'cancelled'));
        foreach (['pending', 'approved', 'processed'] as $s) {
            assert_true(canChangeRequestStatus($s, 'cancelled'), "$s can be cancelled");
        }
    },
    'a rejected / cancelled / lapsed request is no longer active (the vehicle is released)' => function () {
        foreach (['rejected', 'cancelled', 'lapsed', 'completed'] as $s) { assert_true(!in_array($s, REQUEST_ACTIVE_STATUSES, true)); }
        foreach (['pending', 'approved', 'processed'] as $s) { assert_true(in_array($s, REQUEST_ACTIVE_STATUSES, true)); }
    },
    'R3: the same vehicle cannot be held twice for one day (UNIQUE key)' => function () {
        global $conn;
        $conn->query("INSERT INTO users (username, password, email, full_name, role) VALUES ('t_rules', 'x', 't@x.test', 'T', 'staff')");
        $uid = $conn->insert_id;
        $conn->query("INSERT INTO vehicle_requests (staff_id, vehicle_id, trip_date, start_time, end_time, destination, purpose, passenger_count) VALUES ($uid, 1, '2034-01-10', '09:00', '10:00', 'T', 'a long enough purpose text', 1)");
        $a = $conn->insert_id;
        $conn->query("INSERT INTO vehicle_requests (staff_id, vehicle_id, trip_date, start_time, end_time, destination, purpose, passenger_count) VALUES ($uid, 1, '2034-01-10', '14:00', '15:00', 'T', 'a long enough purpose text', 1)");
        $b = $conn->insert_id;
        holdVehiclesForRequest($conn, $a, [1], '2034-01-10');
        $threw = false;
        try { holdVehiclesForRequest($conn, $b, [1], '2034-01-10'); } catch (RuntimeException $e) { $threw = str_starts_with($e->getMessage(), 'Sorry,'); }
        assert_true($threw, 'second hold must be refused with a friendly message');
    },
    'R3/R4: held and maintenance vehicles are reported as blocked' => function () {
        global $conn;
        $conn->query("INSERT INTO users (username, password, email, full_name, role) VALUES ('t_rules2', 'x', 't2@x.test', 'T', 'staff')");
        $uid = $conn->insert_id;
        $conn->query("INSERT INTO vehicle_requests (staff_id, vehicle_id, trip_date, start_time, end_time, destination, purpose, passenger_count) VALUES ($uid, 1, '2034-02-10', '09:00', '10:00', 'T', 'a long enough purpose text', 1)");
        $rid = $conn->insert_id;
        holdVehiclesForRequest($conn, $rid, [1], '2034-02-10');
        $conn->query("INSERT INTO vehicle_maintenance (vehicle_id, start_date, end_date) VALUES (2, '2034-02-09', '2034-02-11')");
        $b = blockedVehicleIds($conn, '2034-02-10');
        assert_same('held', $b[1] ?? null);
        assert_same('maintenance', $b[2] ?? null);
        assert_true(!isset(blockedVehicleIds($conn, '2034-02-10', $rid)[1]), 'a request does not block itself');
        assert_true(!isset(blockedVehicleIds($conn, '2034-02-12')[2]), 'after the maintenance period');
    },
    'R10: the audit log refuses UPDATE and DELETE' => function () {
        global $conn;
        auditLog($conn, 'test', null, 'x', 0, 'system');
        foreach (["UPDATE audit_log SET action = 'x'", 'DELETE FROM audit_log'] as $sql) {
            $blocked = false;
            try { $conn->query($sql); } catch (mysqli_sql_exception $e) { $blocked = true; }
            assert_true($blocked, "$sql must be refused");
        }
    },
    'R9: comments cannot be changed or removed' => function () {
        global $conn;
        $conn->query("INSERT INTO users (username, password, email, full_name, role) VALUES ('t_rules3', 'x', 't3@x.test', 'T', 'staff')");
        $uid = $conn->insert_id;
        $conn->query("INSERT INTO vehicle_requests (staff_id, trip_date, start_time, end_time, destination, purpose, passenger_count) VALUES ($uid, '2034-03-10', '09:00', '10:00', 'T', 'a long enough purpose text', 1)");
        $rid = $conn->insert_id;
        $conn->query("INSERT INTO request_comments (request_id, user_id, user_role, comment) VALUES ($rid, $uid, 'staff', 'hello')");
        foreach (["UPDATE request_comments SET comment = 'x'", 'DELETE FROM request_comments'] as $sql) {
            $blocked = false;
            try { $conn->query($sql); } catch (mysqli_sql_exception $e) { $blocked = true; }
            assert_true($blocked, "$sql must be refused");
        }
    },
    'lapse text follows the setting' => function () {
        global $conn;
        assert_same('two days', lapseWindowText($conn));
    },
];
