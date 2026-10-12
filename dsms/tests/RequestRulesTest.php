<?php
/** R1 (two-day rule), R2 (one trip) and the day / leg calculations - no database needed. */
$now = new DateTimeImmutable('2026-05-04 16:00');     // a Monday afternoon

return [
    'R1: tomorrow is too soon and the message names the paper form' => function () use ($now) {
        $msg = RequestRules::leadTimeProblem(new DateTimeImmutable('2026-05-05 09:00'), $now, 2);
        assert_true($msg !== null, 'should be refused');
        assert_true(str_contains($msg, 'BPTK/BPI/2025/PIN.5'), 'message must mention the paper form');
    },
    'R1: two days ahead is allowed, even for an early-morning trip' => function () use ($now) {
        assert_same(null, RequestRules::leadTimeProblem(new DateTimeImmutable('2026-05-06 07:00'), $now, 2));
    },
    'R1: the number of days comes from the setting' => function () use ($now) {
        assert_same(null, RequestRules::leadTimeProblem(new DateTimeImmutable('2026-05-05 09:00'), $now, 1));
        assert_true(RequestRules::leadTimeProblem(new DateTimeImmutable('2026-05-06 09:00'), $now, 3) !== null);
    },
    'R2: return must be after departure' => function () {
        $d = new DateTimeImmutable('2026-06-01 09:00');
        assert_true(RequestRules::tripProblem($d, $d) !== null);
        assert_true(RequestRules::tripProblem($d, $d->modify('-1 hour')) !== null);
        assert_same(null, RequestRules::tripProblem($d, $d->modify('+1 hour')));
    },
    'R2: a trip longer than 14 days is refused' => function () {
        $d = new DateTimeImmutable('2026-06-01 09:00');
        assert_same(null, RequestRules::tripProblem($d, $d->modify('+13 days')));      // 14 calendar days
        assert_true(RequestRules::tripProblem($d, $d->modify('+14 days')) !== null);   // 15 calendar days
    },
    'R3: days() lists every calendar day, including the first and last' => function () {
        assert_same(['2026-06-01'], RequestRules::days(new DateTimeImmutable('2026-06-01 08:00'), new DateTimeImmutable('2026-06-01 17:00')));
        assert_same(['2026-06-01', '2026-06-02', '2026-06-03'], RequestRules::days(new DateTimeImmutable('2026-06-01 23:00'), new DateTimeImmutable('2026-06-03 01:00')));
    },
    'R6: legs are 2 hours at each end of the trip' => function () {
        $w = RequestRules::legWindows(new DateTimeImmutable('2026-06-01 08:00'), new DateTimeImmutable('2026-06-01 17:00'));
        assert_same('08:00-10:00', $w['departure'][0]->format('H:i') . '-' . $w['departure'][1]->format('H:i'));
        assert_same('15:00-17:00', $w['return'][0]->format('H:i') . '-' . $w['return'][1]->format('H:i'));
    },
    'R6: legs of a very short trip never leave the trip' => function () {
        $w = RequestRules::legWindows(new DateTimeImmutable('2026-06-01 09:00'), new DateTimeImmutable('2026-06-01 10:00'));
        assert_same('09:00-10:00', $w['departure'][0]->format('H:i') . '-' . $w['departure'][1]->format('H:i'));
        assert_same('09:00-10:00', $w['return'][0]->format('H:i') . '-' . $w['return'][1]->format('H:i'));
    },
    'parse() rejects impossible dates and times' => function () {
        assert_true(RequestRules::parse('2026-02-31', '10:00') === null);
        assert_true(RequestRules::parse('2026-02-10', '25:00') === null);
        assert_true(RequestRules::parse('', '') === null);
        assert_same('2026-02-10 10:30', RequestRules::parse('2026-02-10', '10:30')->format('Y-m-d H:i'));
    },
    'status map: allowed and forbidden moves' => function () {
        assert_true(RequestStatus::canMove('Pending Approval', 'Approved'));
        assert_true(RequestStatus::canMove('Approved', 'Confirmed'), 'self-drive goes straight to Confirmed');
        assert_true(RequestStatus::canMove('Driver Assigned', 'Confirmed'));
        assert_true(RequestStatus::canMove('Confirmed', 'Completed'));
        assert_true(!RequestStatus::canMove('Pending Approval', 'Confirmed'));
        assert_true(!RequestStatus::canMove('Rejected', 'Approved'));
        assert_true(!RequestStatus::canMove('Completed', 'Cancelled'));
        foreach (['Pending Approval', 'Approved', 'Driver Assigned', 'Confirmed'] as $s) {
            assert_true(RequestStatus::canMove($s, 'Cancelled'), "$s can be cancelled");
        }
    },
];
