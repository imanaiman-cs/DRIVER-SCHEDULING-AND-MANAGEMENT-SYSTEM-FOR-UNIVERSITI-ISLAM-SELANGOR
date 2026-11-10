<?php
/** User accounts (includes/accounts.php): password rule, assignable roles, approvers. */
return [
    'password rule: 8 to 72 characters with letters and digits' => function () {
        assert_same(null, passwordProblem('Abcdef12'));
        assert_true(passwordProblem('Abc12') !== null, 'too short');
        assert_true(passwordProblem('abcdefgh') !== null, 'no digit');
        assert_true(passwordProblem('12345678') !== null, 'no letter');
        assert_true(passwordProblem(str_repeat('a1', 40)) !== null, 'too long');
    },
    'the Assistant cannot give the Assistant or Head of Admin role, the Head of Admin can give every role' => function () {
        assert_same(['staff', 'driver', 'supervisor'], array_keys(assignableRoles('admin')));
        assert_same(['staff', 'driver', 'supervisor', 'admin', 'head_admin'], array_keys(assignableRoles('head_admin')));
    },
    'the approver is the first active Head of Department of the PTJ; a Head of Department has none' => function () {
        global $conn;
        $conn->query("INSERT INTO users (username, password, email, full_name, role, department) VALUES ('t_acc_hod', 'x', 't_acc_hod@test.local', 'T Hod', 'supervisor', 'Test PTJ 77')");
        $hod = (int)$conn->insert_id;
        assert_same($hod, approverIdForDepartment($conn, 'Test PTJ 77', 'staff'));
        assert_same(null, approverIdForDepartment($conn, 'Test PTJ 77', 'supervisor'));
        assert_same(null, approverIdForDepartment($conn, 'Test PTJ 77', 'staff', $hod), 'the person themselves is skipped');
        $conn->query("UPDATE users SET is_active = 0 WHERE user_id = $hod");
        assert_same(null, approverIdForDepartment($conn, 'Test PTJ 77', 'staff'), 'an Inactive Head does not approve');
        assert_true(!in_array('Test PTJ 77', departmentChoices($conn), true), 'a PTJ without an active Head is not open for registration');
    },
];
