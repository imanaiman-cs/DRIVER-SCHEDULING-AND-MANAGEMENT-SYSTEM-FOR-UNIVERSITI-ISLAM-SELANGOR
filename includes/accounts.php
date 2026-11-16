<?php
// ============================================================
// includes/accounts.php  -  user accounts (SRS summary of 10 Nov 2026)
//   * Staff register their own account (register.php).
//   * The Administrative Assistant and the Head of Admin manage user accounts (admin/users.php): create the driver
//     account (the Assistant then registers it as a driver in Add Driver), change details, reset a password,
//     make an account Inactive. Accounts are never deleted; the history stays.
//   * Only the Head of Admin may create or change Administrative Assistant and Head of Admin accounts.
// ============================================================

const ACCOUNT_ROLES = ['staff' => 'Staff', 'driver' => 'Driver', 'supervisor' => 'Head of Department', 'admin' => 'Administrative Assistant', 'head_admin' => 'Head of Admin'];

/** The roles an account manager may give: the Assistant never creates another Assistant or Head of Admin. */
function assignableRoles(string $actorRole): array
{
    return $actorRole === 'head_admin' ? ACCOUNT_ROLES : array_diff_key(ACCOUNT_ROLES, ['admin' => 1, 'head_admin' => 1]);
}

/** Null when the password is acceptable, else the message. */
function passwordProblem(string $password): ?string
{
    if (strlen($password) < 8) { return 'The password must have at least 8 characters.'; }
    if (strlen($password) > 72) { return 'The password can have at most 72 characters.'; }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) { return 'The password must have letters and digits.'; }
    return null;
}

// ------------------------------------------------------------
// Departments (class Department, PTJ): a user belongs to one department; a department is headed by one Head of Department
// (departments.head_user_id). The request of a Head of Department goes to the head of the parent department (UC019, Iman 16 Nov).
// ------------------------------------------------------------

/** Every PTJ (department) known in the system, by name (UC013 step 4: the user selects the department). */
function allDepartments(mysqli $conn): array
{
    $r = $conn->query('SELECT department_name FROM departments ORDER BY department_name');
    return array_column($r->fetch_all(MYSQLI_ASSOC), 'department_name');
}

/** The department_id of a department name, or null. */
function departmentIdByName(mysqli $conn, ?string $name): ?int
{
    if ($name === null || trim($name) === '') { return null; }
    $stmt = $conn->prepare('SELECT department_id FROM departments WHERE department_name = ?');
    $n = trim($name);
    $stmt->bind_param('s', $n);
    $stmt->execute();
    $id = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return $id === null ? null : (int)$id;
}

/** UC001 A2.1 / UC013 4.2: the active Head of Department of a PTJ, other than $exceptUser (null when there is none). */
function headOfDepartmentOf(mysqli $conn, string $department, ?int $exceptUser = null): ?array
{
    $stmt = $conn->prepare("SELECT u.user_id, u.full_name FROM departments d JOIN users u ON u.user_id = d.head_user_id
                             WHERE d.department_name = ? AND u.role = 'supervisor' AND u.is_active = 1 AND u.user_id <> ? LIMIT 1");
    $except = (int)$exceptUser;
    $stmt->bind_param('si', $department, $except);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** The PTJs (departments) that have an active Head of Department: the only ones a request can be approved in. */
function departmentChoices(mysqli $conn): array
{
    $r = $conn->query("SELECT d.department_name FROM departments d JOIN users u ON u.user_id = d.head_user_id
                        WHERE u.role = 'supervisor' AND u.is_active = 1 ORDER BY d.department_name");
    return array_column($r->fetch_all(MYSQLI_ASSOC), 'department_name');
}

/**
 * The approver of a request made by someone of a PTJ: the active head of the department; for a Head of Department ($role 'supervisor')
 * the active head of the parent department. $exceptUser is never returned (nobody approves their own request). Null when there is none.
 */
function approverIdForDepartment(mysqli $conn, ?string $department, string $role, ?int $exceptUser = null): ?int
{
    if ($department === null || $department === '') { return null; }
    $sql = $role === 'supervisor'
        ? "SELECT u.user_id FROM departments d JOIN departments p ON p.department_id = d.parent_department_id JOIN users u ON u.user_id = p.head_user_id
            WHERE d.department_name = ? AND u.role = 'supervisor' AND u.is_active = 1 AND u.user_id <> ?"
        : "SELECT u.user_id FROM departments d JOIN users u ON u.user_id = d.head_user_id
            WHERE d.department_name = ? AND u.role = 'supervisor' AND u.is_active = 1 AND u.user_id <> ?";
    $stmt = $conn->prepare($sql);
    $except = (int)$exceptUser;
    $stmt->bind_param('si', $department, $except);
    $stmt->execute();
    $id = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return $id === null ? null : (int)$id;
}

/** UC019 1.1: the approver of a request made by this user (see approverIdForDepartment); the head of a department is treated as a Head of Department. */
function approverIdForUser(mysqli $conn, int $userId): ?int
{
    $stmt = $conn->prepare('SELECT u.role, d.department_name, (d.head_user_id <=> u.user_id) AS is_head FROM users u LEFT JOIN departments d ON d.department_id = u.department_id WHERE u.user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u) { return null; }
    $asHead = $u['role'] === 'supervisor' || (int)$u['is_head'] === 1;
    return approverIdForDepartment($conn, $u['department_name'], $asHead ? 'supervisor' : 'staff', $userId);
}

/** Makes $userId the head of $department; the user stops being the head of any other department. setDepartmentHead($conn, null, $id) only ends $id's headship. */
function setDepartmentHead(mysqli $conn, ?string $department, ?int $userId): void
{
    if ($userId !== null) {
        $stmt = $conn->prepare('UPDATE departments SET head_user_id = NULL WHERE head_user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    if ($department === null || $department === '') { return; }
    $stmt = $conn->prepare('UPDATE departments SET head_user_id = ? WHERE department_name = ?');
    $stmt->bind_param('is', $userId, $department);
    $stmt->execute();
    $stmt->close();
}

/**
 * Checks the fields of an account form. $p is $_POST.
 * @return array{0: array, 1: array} values, errors
 */
function accountInput(mysqli $conn, array $p, bool $creating, ?int $editId = null): array
{
    $v = [
        'username'   => trim((string)($p['username'] ?? '')),
        'full_name'  => preg_replace('/\s+/', ' ', trim((string)($p['full_name'] ?? ''))),
        'email'      => trim((string)($p['email'] ?? '')),
        'phone'      => trim((string)($p['phone'] ?? '')),
        'department' => trim((string)($p['department'] ?? '')),
        'role'       => (string)($p['role'] ?? 'staff'),
    ];
    $e = [];
    if ($creating) {
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $v['username'])) { $e['username'] = 'The staff ID must be 3 to 50 letters, digits, dots, dashes or underscores.'; }
        else {
            $q = $conn->prepare('SELECT 1 FROM users WHERE username = ?'); $q->bind_param('s', $v['username']); $q->execute();
            if ($q->get_result()->fetch_row()) { $e['username'] = 'This staff ID already has an account.'; }
            $q->close();
        }
    }
    if (mb_strlen($v['full_name']) < 3 || mb_strlen($v['full_name']) > 100) { $e['full_name'] = 'Enter the full name (3 to 100 characters).'; }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 100) { $e['email'] = 'Enter a valid e-mail address.'; }
    else {
        $q = $conn->prepare('SELECT 1 FROM users WHERE email = ? AND user_id <> ?'); $id = (int)$editId; $q->bind_param('si', $v['email'], $id); $q->execute();
        if ($q->get_result()->fetch_row()) { $e['email'] = 'This e-mail address is already used by another account.'; }
        $q->close();
    }
    if ($v['phone'] !== '' && !preg_match('/^[0-9 +()\-]{7,20}$/', $v['phone'])) { $e['phone'] = 'Enter a valid phone number (digits, spaces, + - and brackets, 7 to 20 characters).'; }
    if ($v['department'] === '' || departmentIdByName($conn, $v['department']) === null) { $e['department'] = 'Choose the PTJ (department) from the list.'; }
    return [$v, $e];
}

/** Saves a new account in its department. A new Head of Department becomes the head of the department when it has none. */
function createAccount(mysqli $conn, array $v, string $role, string $password): int
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $dept = departmentIdByName($conn, $v['department']);
    $phone = $v['phone'] !== '' ? $v['phone'] : null;
    $stmt = $conn->prepare('INSERT INTO users (username, password, email, full_name, role, department_id, phone, is_active, language) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
    $lang = currentLanguage();
    $stmt->bind_param('sssssiss', $v['username'], $hash, $v['email'], $v['full_name'], $role, $dept, $phone, $lang);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    if ($role === 'supervisor' && !headOfDepartmentOf($conn, $v['department'], $id)) { setDepartmentHead($conn, $v['department'], $id); }
    return $id;
}

/**
 * After a Head of Department was added, moved or made Inactive: the Pending requests of the PTJ (and of the heads of its child departments)
 * whose approver is missing or no longer the right one get the current approver.
 */
function refreshApprovers(mysqli $conn, ?string $department): void
{
    if ($department === null || $department === '') { return; }
    $stmt = $conn->prepare("SELECT vr.request_id, vr.staff_id, vr.supervisor_id FROM vehicle_requests vr JOIN users s ON s.user_id = vr.staff_id
                              JOIN departments d ON d.department_id = s.department_id LEFT JOIN departments p ON p.department_id = d.parent_department_id
                             WHERE vr.status = 'pending' AND vr.supervisor_id IS NOT NULL AND (d.department_name = ? OR (p.department_name = ? AND d.head_user_id = s.user_id))");
    $stmt->bind_param('ss', $department, $department);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $upd = $conn->prepare('UPDATE vehicle_requests SET supervisor_id = ? WHERE request_id = ?');
    foreach ($rows as $r) {
        $now = approverIdForUser($conn, (int)$r['staff_id']);
        if ($now !== null && $now !== (int)$r['supervisor_id']) {
            $rid = (int)$r['request_id'];
            $upd->bind_param('ii', $now, $rid);
            $upd->execute();
        }
    }
    $upd->close();
}

/** Pending requests waiting for this Head of Department. */
function pendingRequestsOfApprover(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM vehicle_requests WHERE supervisor_id = ? AND status = 'pending'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
}
