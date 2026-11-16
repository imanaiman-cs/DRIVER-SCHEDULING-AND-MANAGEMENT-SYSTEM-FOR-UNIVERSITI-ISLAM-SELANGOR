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
    'Department (class diagram): the head approves its people; a Head of Department goes to the head of the parent department' => function () {
        global $conn;
        $conn->query("INSERT INTO departments (department_name, parent_department_id) VALUES ('Test PTJ 77', (SELECT department_id FROM departments d WHERE department_name = 'Rectory'))");
        $dept = (int)$conn->insert_id;
        $conn->query("INSERT INTO users (username, password, email, full_name, role, department_id) VALUES ('t_acc_hod', 'x', 't_acc_hod@test.local', 'T Hod', 'supervisor', $dept)");
        $hod = (int)$conn->insert_id;
        $conn->query("INSERT INTO users (username, password, email, full_name, role, department_id) VALUES ('t_acc_stf', 'x', 't_acc_stf@test.local', 'T Staff', 'staff', $dept)");
        $stf = (int)$conn->insert_id;
        assert_same(null, approverIdForDepartment($conn, 'Test PTJ 77', 'staff'), 'no head yet');
        setDepartmentHead($conn, 'Test PTJ 77', $hod);
        assert_same($hod, approverIdForDepartment($conn, 'Test PTJ 77', 'staff'));
        assert_same($hod, approverIdForUser($conn, $stf), 'a staff member: the head of the department');
        $rector = (int)$conn->query("SELECT head_user_id FROM departments WHERE department_name = 'Rectory'")->fetch_row()[0];
        assert_same($rector, approverIdForDepartment($conn, 'Test PTJ 77', 'supervisor'), 'a Head of Department: the head of the parent department');
        assert_same($rector, approverIdForUser($conn, $hod));
        assert_same(null, approverIdForUser($conn, $rector), 'the head of the top department has no approver');
        assert_same(['user_id' => $hod, 'full_name' => 'T Hod'], array_map(fn($x) => is_numeric($x) ? (int)$x : $x, headOfDepartmentOf($conn, 'Test PTJ 77')));
        setDepartmentHead($conn, null, $hod);
        assert_same(null, headOfDepartmentOf($conn, 'Test PTJ 77'), 'the headship ended');
        setDepartmentHead($conn, 'Test PTJ 77', $hod);
        assert_same(null, approverIdForDepartment($conn, 'Test PTJ 77', 'staff', $hod), 'the person themselves is skipped');
        $conn->query("UPDATE users SET is_active = 0 WHERE user_id = $hod");
        assert_same(null, approverIdForDepartment($conn, 'Test PTJ 77', 'staff'), 'an Inactive Head does not approve');
        assert_true(!in_array('Test PTJ 77', departmentChoices($conn), true), 'a PTJ without an active Head is not open for registration');
    },
];
