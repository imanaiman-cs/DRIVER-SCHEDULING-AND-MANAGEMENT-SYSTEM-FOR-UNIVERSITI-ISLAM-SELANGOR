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

/** Every PTJ (department) known in the system (UC013 step 4: the user selects the department). */
function allDepartments(mysqli $conn): array
{
    $r = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department <> '' ORDER BY department");
    return array_column($r->fetch_all(MYSQLI_ASSOC), 'department');
}

/** UC001 A2.1 / UC013 4.2: the active Head of Department of a PTJ, other than $exceptUser (null when there is none). */
function headOfDepartmentOf(mysqli $conn, string $department, ?int $exceptUser = null): ?array
{
    $stmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE role = 'supervisor' AND is_active = 1 AND department = ? AND user_id <> ? LIMIT 1");
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
    $r = $conn->query("SELECT DISTINCT department FROM users WHERE role = 'supervisor' AND is_active = 1 AND department IS NOT NULL AND department <> '' ORDER BY department");
    return array_column($r->fetch_all(MYSQLI_ASSOC), 'department');
}

/** The Head of Department who approves the requests of a PTJ (the first active one); null for a Head of Department. */
function approverIdForDepartment(mysqli $conn, ?string $department, string $role, ?int $exceptUser = null): ?int
{
    if ($role === 'supervisor' || $department === null || $department === '') { return null; }
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'supervisor' AND department = ? AND is_active = 1 AND user_id <> ? ORDER BY user_id LIMIT 1");
    $except = (int)$exceptUser;
    $stmt->bind_param('si', $department, $except);
    $stmt->execute();
    $id = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return $id === null ? null : (int)$id;
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
    if ($v['department'] === '' || mb_strlen($v['department']) > 100) { $e['department'] = 'Choose or enter the PTJ (department).'; }
    return [$v, $e];
}

/** Saves a new account. Returns the new user_id. The approver is the Head of Department of the PTJ. */
function createAccount(mysqli $conn, array $v, string $role, string $password): int
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $approver = approverIdForDepartment($conn, $v['department'], $role);
    if ($role === 'supervisor') {                       // the request of a Head of Department goes to the head of that Head of Department
        $r = $conn->query("SELECT supervisor_id FROM users WHERE role = 'supervisor' AND supervisor_id IS NOT NULL GROUP BY supervisor_id ORDER BY COUNT(*) DESC LIMIT 1");
        $approver = $r->fetch_row()[0] ?? null;
        $approver = $approver !== null ? (int)$approver : null;
    }
    $phone = $v['phone'] !== '' ? $v['phone'] : null;
    $stmt = $conn->prepare('INSERT INTO users (username, password, email, full_name, role, department, supervisor_id, phone, is_active, language) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
    $lang = currentLanguage();
    $stmt->bind_param('ssssssiss', $v['username'], $hash, $v['email'], $v['full_name'], $role, $v['department'], $approver, $phone, $lang);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

/**
 * After a Head of Department was added, moved or made Inactive: people of the PTJ whose approver is missing or no longer
 * an active Head of Department of that PTJ get the current one, and so do their Pending requests.
 */
function refreshApprovers(mysqli $conn, ?string $department): void
{
    if ($department === null || $department === '') { return; }
    $hod = approverIdForDepartment($conn, $department, 'staff');
    $stmt = $conn->prepare("UPDATE users u LEFT JOIN users a ON a.user_id = u.supervisor_id AND a.role = 'supervisor' AND a.is_active = 1 AND a.department <=> u.department
                               SET u.supervisor_id = ? WHERE u.department = ? AND u.role <> 'supervisor' AND a.user_id IS NULL");
    $stmt->bind_param('is', $hod, $department);
    $stmt->execute();
    $stmt->close();
    if ($hod !== null) {
        $stmt = $conn->prepare("UPDATE vehicle_requests vr JOIN users s ON s.user_id = vr.staff_id
                                   LEFT JOIN users a ON a.user_id = vr.supervisor_id AND a.role = 'supervisor' AND a.is_active = 1
                                   SET vr.supervisor_id = ? WHERE vr.status = 'pending' AND s.department = ? AND s.role <> 'supervisor' AND a.user_id IS NULL");
        $stmt->bind_param('is', $hod, $department);
        $stmt->execute();
        $stmt->close();
    }
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
