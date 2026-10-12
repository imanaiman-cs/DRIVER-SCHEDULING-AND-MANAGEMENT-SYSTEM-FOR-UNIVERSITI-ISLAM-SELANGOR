<?php
/** Who may view / cancel / approve a request (RequestPolicy), and the password rule. No database needed. */
$user = fn(int $id, string $role, ?int $ptj = null) => ['id' => $id, 'role' => $role, 'ptj_id' => $ptj];
$req  = fn(array $over = []) => $over + ['id' => 1, 'requester_id' => 10, 'approver_id' => 20, 'requester_ptj_id' => 5, 'status' => 'Pending Approval', 'departure_datetime' => '2030-01-10 09:00:00'];
$now  = new DateTimeImmutable('2030-01-01 10:00');

return [
    'view: the requester, the approver and admins can; a stranger cannot' => function () use ($user, $req) {
        assert_true(RequestPolicy::canView($user(10, 'staff'), $req()));
        assert_true(RequestPolicy::canView($user(20, 'hod'), $req()));
        assert_true(RequestPolicy::canView($user(30, 'admin_assistant'), $req()));
        assert_true(RequestPolicy::canView($user(31, 'head_admin'), $req()));
        assert_true(!RequestPolicy::canView($user(99, 'staff'), $req()));
    },
    'view: an HOD sees their own department only' => function () use ($user, $req) {
        assert_true(RequestPolicy::canView($user(21, 'hod', 5), $req()));
        assert_true(!RequestPolicy::canView($user(22, 'hod', 6), $req()));
        assert_true(!RequestPolicy::canView($user(23, 'hod', null), $req()));
    },
    'view: an assigned driver sees the trip only once it is assigned / confirmed' => function () use ($user, $req) {
        assert_true(!RequestPolicy::canView($user(40, 'driver'), $req(['status' => 'Approved']), [40]));
        assert_true(RequestPolicy::canView($user(40, 'driver'), $req(['status' => 'Confirmed']), [40]));
        assert_true(!RequestPolicy::canView($user(41, 'driver'), $req(['status' => 'Confirmed']), [40]));
    },
    'cancel: requester, their approver, same-PTJ HOD and admins; nobody else' => function () use ($user, $req, $now) {
        assert_true(RequestPolicy::canCancel($user(10, 'staff'), $req(), $now));
        assert_true(RequestPolicy::canCancel($user(20, 'hod', 9), $req(), $now), 'approver');
        assert_true(RequestPolicy::canCancel($user(21, 'hod', 5), $req(), $now), 'same PTJ');
        assert_true(!RequestPolicy::canCancel($user(22, 'hod', 6), $req(), $now), 'other PTJ');
        assert_true(RequestPolicy::canCancel($user(30, 'admin_assistant'), $req(), $now));
        assert_true(!RequestPolicy::canCancel($user(99, 'staff'), $req(), $now));
        assert_true(!RequestPolicy::canCancel($user(40, 'driver'), $req(), $now));
    },
    'cancel: only active requests that have not started' => function () use ($user, $req, $now) {
        foreach (['Rejected', 'Lapsed', 'Completed', 'Cancelled'] as $s) {
            assert_true(!RequestPolicy::canCancel($user(10, 'staff'), $req(['status' => $s]), $now), "$s cannot be cancelled");
        }
        foreach (['Pending Approval', 'Approved', 'Driver Assigned', 'Confirmed'] as $s) {
            assert_true(RequestPolicy::canCancel($user(10, 'staff'), $req(['status' => $s]), $now), "$s can be cancelled");
        }
        assert_true(!RequestPolicy::canCancel($user(10, 'staff'), $req(['departure_datetime' => '2030-01-01 09:00:00']), $now), 'already departed');
    },
    'approve: the approver HOD, or a head admin when the requester has no HOD' => function () use ($user, $req) {
        assert_true(RequestPolicy::canApprove($user(20, 'hod'), $req()));
        assert_true(!RequestPolicy::canApprove($user(21, 'hod'), $req()), 'a different HOD');
        assert_true(!RequestPolicy::canApprove($user(31, 'head_admin'), $req()), 'head admin when an HOD exists');
        assert_true(RequestPolicy::canApprove($user(31, 'head_admin'), $req(['approver_id' => null])));
        assert_true(!RequestPolicy::canApprove($user(30, 'admin_assistant'), $req(['approver_id' => null])));
        assert_true(!RequestPolicy::canApprove($user(10, 'staff'), $req()), 'never the requester');
        assert_true(!RequestPolicy::canApprove($user(20, 'hod'), $req(['status' => 'Approved'])), 'only while pending');
    },
    'password rule: at least 8 characters with a letter and a number' => function () {
        assert_true(User::passwordProblem('abc12') !== null, 'too short');
        assert_true(User::passwordProblem('abcdefgh') !== null, 'no number');
        assert_true(User::passwordProblem('12345678') !== null, 'no letter');
        assert_same(null, User::passwordProblem('Abcdef12'));
    },
    'temporary passwords always satisfy the rule' => function () {
        for ($i = 0; $i < 50; $i++) { assert_same(null, User::passwordProblem(User::generateTempPassword())); }
    },
];
