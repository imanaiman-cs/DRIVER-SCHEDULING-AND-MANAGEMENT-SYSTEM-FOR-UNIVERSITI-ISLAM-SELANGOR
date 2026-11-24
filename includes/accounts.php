<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/accounts.php  -  user accounts
//
//   UC001A Create Driver Account     (Administrative Assistant; login details sent to the UIS email)
//   UC001C Update Staff and Driver Account (department, role; driver licence and start date; Pending Role A1)
//   UC001D Deactivate Staff and Driver Account (never deleted)
//   UC013  Register Account          (Staff and Head of Department; Head of Department role confirmed by the Assistant)
//   UC014  Send Verification Email   (a new code replaces any earlier code)
//   UC015  Reset Password
//   UC016  Manage Profile            (name, phone, address, password)
// The Administrative Assistant and Head of Admin accounts are created during the initial setup (database.sql).
// ============================================================

function departmentsList(mysqli $conn): array
{
    return dbAll($conn, 'SELECT d.*, p.department_name AS parent_name FROM departments d LEFT JOIN departments p ON p.department_id = d.parent_id ORDER BY d.department_name');
}

function staffNoTaken(mysqli $conn, string $staffNo, int $except = 0): bool
{
    return (bool)dbValue($conn, 'SELECT 1 FROM users WHERE staff_no = ? AND user_id <> ?', [$staffNo, $except]);
}

function emailTaken(mysqli $conn, string $email, int $except = 0): bool
{
    return (bool)dbValue($conn, 'SELECT 1 FROM users WHERE email = ? AND user_id <> ?', [strtolower($email), $except]);
}

/** A readable random password: 10 characters, letters and digits. */
function generatePassword(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
    $num = '23456789';
    $p = '';
    for ($i = 0; $i < 7; $i++) { $p .= $abc[random_int(0, strlen($abc) - 1)]; }
    for ($i = 0; $i < 3; $i++) { $p .= $num[random_int(0, strlen($num) - 1)]; }
    return str_shuffle($p);
}

/** Checks the driver part of a form. Returns [errors, licence list]. */
function checkDriverFields(array $in): array
{
    $errors = [];
    $lic = normaliseLicenceList((array)($in['licence_classes'] ?? []));
    if ($lic === '') { $errors['licence_classes'] = 'Select at least one licence class.'; }
    if (!isValidDate((string)($in['licence_expiry'] ?? ''))) { $errors['licence_expiry'] = 'Enter the licence expiry date.'; }
    if (!isValidDate((string)($in['start_date'] ?? ''))) { $errors['start_date'] = 'Enter the start date of service.'; }
    elseif ($in['start_date'] > date('Y-m-d')) { $errors['start_date'] = 'The start date of service cannot be in the future.'; }
    return [$errors, $lic];
}

/** UC001A. $in: staff_no, full_name, email, phone, licence_classes[], licence_expiry, start_date, department_id */
function createDriverAccount(mysqli $conn, array $in): array
{
    $errors = [];
    $staffNo = strtoupper(trim((string)($in['staff_no'] ?? '')));
    $name    = trim((string)($in['full_name'] ?? ''));
    $email   = strtolower(trim((string)($in['email'] ?? '')));
    $phone   = trim((string)($in['phone'] ?? ''));
    if ($staffNo === '') { $errors['staff_no'] = 'Enter the staff ID.'; }
    if ($name === '') { $errors['full_name'] = 'Enter the name.'; }
    if ($email === '') { $errors['email'] = 'Enter the UIS email.'; }
    elseif (!isUisEmail($conn, $email)) { $errors['email'] = 'Only UIS email is accepted.'; }
    if ($phone === '') { $errors['phone'] = 'Enter the phone number.'; }
    elseif (!preg_match('/^[0-9+\-\s]{9,20}$/', $phone)) { $errors['phone'] = 'Enter a valid phone number.'; }
    [$de, $lic] = checkDriverFields($in);
    $errors += $de;
    if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }
    if (staffNoTaken($conn, $staffNo) || emailTaken($conn, $email)) {
        return wfFail('The account already exists (the staff ID or email is already registered).', ['errors' => ['staff_no' => 'Already registered.']]);
    }
    $dept = (int)($in['department_id'] ?? 0) ?: (int)dbValue($conn, "SELECT department_id FROM departments WHERE department_name = 'Vehicle Section'") ?: null;
    $plain = generatePassword();
    $conn->begin_transaction();
    try {
        [, $uid] = dbExec($conn, "INSERT INTO users (staff_no, full_name, email, phone, password, role, department_id) VALUES (?, ?, ?, ?, ?, 'driver', ?)",
            [$staffNo, mb_substr($name, 0, 120), $email, $phone, password_hash($plain, PASSWORD_DEFAULT), $dept]);
        dbExec($conn, 'INSERT INTO drivers (user_id, licence_classes, licence_expiry, start_date) VALUES (?, ?, ?, ?)', [$uid, $lic, $in['licence_expiry'], $in['start_date']]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('createDriverAccount: ' . $e->getMessage());
        return wfFail('The account could not be created. Please try again.');
    }
    $u = loadUser($conn, $uid);
    $msg = "Your driver account for the UIS Driver Scheduling and Management System was created.\n\nStaff ID: $staffNo\nPassword: $plain\n\n"
         . "Sign in at " . SITE_URL . "/login.php . You may change the password in Profile > Change Password.";
    $res = sendUserEmail($conn, $u, '[UIS Vehicle Section] Your driver account', $msg, 'login.php', 'account');
    if ($res['status'] === 'sent') { return wfOk("The driver account of $name was created and the login details were sent to $email.", ['user_id' => $uid]); }
    return wfOk("The driver account of $name was created, but the email could not be sent. Give the driver the login details: staff ID $staffNo, password $plain.", ['user_id' => $uid, 'email_failed' => true]);
}

/** Is there already an active Head of Department in the department (other than $except)? */
function departmentHasHod(mysqli $conn, int $dept, int $except = 0): bool
{
    return hodOfDepartment($conn, $dept, $except) !== null;
}

/** UC001C. $in: department_id, role (staff|supervisor for staff accounts); for drivers also licence_classes[], licence_expiry, start_date */
function updateAccount(mysqli $conn, int $uid, array $in): array
{
    $u = loadUser($conn, $uid);
    if (!$u) { return wfFail('Unknown account.'); }
    $errors = [];
    $dept = (int)($in['department_id'] ?? 0);
    if ($dept <= 0 || !dbValue($conn, 'SELECT 1 FROM departments WHERE department_id = ?', [$dept])) { $errors['department_id'] = 'Select the department.'; }
    $role = $u['role'];
    if (in_array($u['role'], ['staff', 'supervisor'], true)) {
        $role = (string)($in['role'] ?? '');
        if (!in_array($role, ['staff', 'supervisor'], true)) { $errors['role'] = 'Select the role.'; }
    }
    $lic = null;
    if ($u['role'] === 'driver') {
        [$de, $lic] = checkDriverFields($in);
        $errors += $de;
    }
    if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }
    if ($role === 'supervisor' && departmentHasHod($conn, $dept, $uid)) {
        return wfFail('The department already has a Head of Department.', ['errors' => ['role' => 'The department already has a Head of Department.']]);
    }
    if ($u['role'] === 'supervisor' && $role !== 'supervisor' && dbValue($conn, "SELECT COUNT(*) FROM requests WHERE approver_id = ? AND status = 'pending'", [$uid])) {
        return wfFail('This Head of Department still has pending requests. They must be handled first.');
    }
    $changes = [];
    if ((int)$u['department_id'] !== $dept) { $changes[] = 'department: ' . dbValue($conn, 'SELECT department_name FROM departments WHERE department_id = ?', [$dept]); }
    if ($u['role'] !== $role) { $changes[] = 'role: ' . roleLabel($role); }
    dbExec($conn, 'UPDATE users SET department_id = ?, role = ?, pending_role = IF(? = \'supervisor\', NULL, pending_role) WHERE user_id = ?', [$dept, $role, $role, $uid]);
    if ($u['role'] === 'driver') {
        if ($u['licence_classes'] !== $lic) { $changes[] = 'licence classes: ' . str_replace(',', ', ', $lic); }
        if ($u['licence_expiry'] !== $in['licence_expiry']) { $changes[] = 'licence expiry: ' . fmtD($in['licence_expiry']); }
        if ($u['start_date'] !== $in['start_date']) { $changes[] = 'start date of service: ' . fmtD($in['start_date']); }
        dbExec($conn, 'UPDATE drivers SET licence_classes = ?, licence_expiry = ?, start_date = ? WHERE user_id = ?', [$lic, $in['licence_expiry'], $in['start_date'], $uid]);
    }
    if ($changes) {
        notifyUsers($conn, [$uid], 'Your account was updated by the Vehicle Section (' . implode('; ', $changes) . ').', 'profile.php', 'account');
    }
    return wfOk('The account was updated' . ($changes ? ' and the user was informed by email.' : '.'));
}

/** UC001C A1: confirm or reject the Head of Department role a user asked for at registration. */
function decidePendingRole(mysqli $conn, int $uid, bool $confirm): array
{
    $u = loadUser($conn, $uid);
    if (!$u || $u['pending_role'] !== 'supervisor') { return wfFail('This user is not waiting for a role confirmation.'); }
    if ($confirm) {
        if (departmentHasHod($conn, (int)$u['department_id'], $uid)) { return wfFail('The department already has a Head of Department.'); }
        dbExec($conn, "UPDATE users SET role = 'supervisor', pending_role = NULL WHERE user_id = ?", [$uid]);
        notifyUsers($conn, [$uid], 'Your Head of Department role for ' . $u['department_name'] . ' was confirmed. You can now approve vehicle requests of your department.', 'supervisor/approvals.php', 'account');
        return wfOk($u['full_name'] . ' is now the Head of Department of ' . $u['department_name'] . '.');
    }
    dbExec($conn, 'UPDATE users SET pending_role = NULL WHERE user_id = ?', [$uid]);
    notifyUsers($conn, [$uid], 'Your Head of Department role was not confirmed. You can continue to use the system as Staff.', 'profile.php', 'account');
    return wfOk('The Head of Department role of ' . $u['full_name'] . ' was rejected. The user stays as Staff.');
}

/** UC001D exceptions: why the account cannot be deactivated now, with the tasks to replace first. */
function deactivateBlockers(mysqli $conn, array $u): array
{
    if ($u['role'] === 'driver' && $u['driver_id']) {
        $tasks = driverUpcomingTasks($conn, (int)$u['driver_id']);
        if ($tasks) { return ['message' => 'The driver has upcoming tasks. Replace the driver first (Replace Driver).', 'tasks' => $tasks]; }
    }
    if ($u['role'] === 'supervisor') {
        $n = (int)dbValue($conn, "SELECT COUNT(*) FROM requests WHERE approver_id = ? AND status = 'pending'", [(int)$u['user_id']]);
        if ($n) { return ['message' => "The Head of Department has $n pending request(s). The pending requests must be handled first.", 'tasks' => []]; }
    }
    return [];
}

function setAccountActive(mysqli $conn, int $uid, bool $active): array
{
    $u = loadUser($conn, $uid);
    if (!$u) { return wfFail('Unknown account.'); }
    if ($uid === (int)$_SESSION['user_id']) { return wfFail('You cannot deactivate your own account.'); }
    if (!$active) {
        if ($u['status'] !== 'active') { return wfFail('The account is already inactive.'); }
        if ($b = deactivateBlockers($conn, $u)) { return wfFail($b['message'], ['tasks' => $b['tasks']]); }
        dbExec($conn, "UPDATE users SET status = 'inactive' WHERE user_id = ?", [$uid]);
        return wfOk('The account of ' . $u['full_name'] . ' was deactivated. The user can no longer log in; all records remain.');
    }
    if ($u['role'] === 'supervisor' && departmentHasHod($conn, (int)$u['department_id'], $uid)) {
        return wfFail('The department already has an active Head of Department.');
    }
    dbExec($conn, "UPDATE users SET status = 'active', failed_attempts = 0, locked_until = NULL WHERE user_id = ?", [$uid]);
    return wfOk('The account of ' . $u['full_name'] . ' was activated again.');
}

// ------------------------------------------------------------
// UC014 Send Verification Email
// ------------------------------------------------------------

/**
 * Creates a 6-digit code for (email, purpose), replacing any earlier one, and e-mails it.
 * @return array ok, message, and 'dev_code' when e-mail sending is switched off (so the system can be tried locally)
 */
function issueVerificationCode(mysqli $conn, string $purpose, string $staffNo, string $email, string $name = ''): array
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $mins = max(1, getSettingInt($conn, 'CODE_EXPIRY_MINUTES'));
    dbExec($conn, 'INSERT INTO verification_codes (purpose, staff_no, email, code_hash, expires_at, attempts) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0)
                   ON DUPLICATE KEY UPDATE staff_no = VALUES(staff_no), code_hash = VALUES(code_hash), expires_at = VALUES(expires_at), attempts = 0, created_at = NOW()',
        [$purpose, $staffNo, $email, password_hash($code, PASSWORD_DEFAULT), $mins]);
    $what = $purpose === 'register' ? 'register your account' : 'reset your password';
    $html = '<p>Salam' . ($name ? ' ' . htmlspecialchars($name) : '') . ',</p><p>Your verification code to ' . $what . ' is:</p><p style="font-size:24px;font-weight:bold;letter-spacing:4px">' . $code
          . "</p><p>The code expires in $mins minutes. If you did not ask for it, ignore this email.</p><p>UIS Vehicle Section</p>";
    $text = "Your verification code to $what is $code. It expires in $mins minutes.";
    $res = sendMail($conn, $email, '[UIS Vehicle Section] Verification code', $html, $text, ['kind' => 'verification']);
    if ($res['status'] === 'failed') { return wfFail('The email cannot be sent. Please try again later.'); }
    $out = wfOk("A verification code was sent to $email. It expires in $mins minutes.");
    if ($res['status'] === 'skipped' && !mailEnabled()) { $out['dev_code'] = $code; }
    return $out;
}

/** Checks a code. A code may be tried 5 times. */
function checkVerificationCode(mysqli $conn, string $purpose, string $email, string $code): array
{
    $row = dbOne($conn, 'SELECT * FROM verification_codes WHERE purpose = ? AND email = ?', [$purpose, $email]);
    if (!$row) { return wfFail('The code has expired or was not received. Select Resend Code.'); }
    if (strtotime($row['expires_at']) < time()) { return wfFail('The code has expired. Select Resend Code.'); }
    if ((int)$row['attempts'] >= 5) { return wfFail('Too many wrong codes. Select Resend Code.'); }
    if (!password_verify(trim($code), $row['code_hash'])) {
        dbExec($conn, 'UPDATE verification_codes SET attempts = attempts + 1 WHERE code_id = ?', [(int)$row['code_id']]);
        return wfFail('Verification failed. Please try again.');
    }
    return wfOk('Verified.');
}

function consumeVerificationCode(mysqli $conn, string $purpose, string $email): void
{
    dbExec($conn, 'DELETE FROM verification_codes WHERE purpose = ? AND email = ?', [$purpose, $email]);
}

// ------------------------------------------------------------
// UC013 Register Account
// ------------------------------------------------------------

/** Step 2: staff ID and UIS email not registered, UIS email. */
function registerStartProblem(mysqli $conn, string $staffNo, string $email): ?string
{
    if ($staffNo === '' || $email === '') { return 'Enter your staff ID and UIS email.'; }
    if (!isUisEmail($conn, $email)) { return 'Only UIS email is accepted.'; }
    if (staffNoTaken($conn, $staffNo) || emailTaken($conn, $email)) { return 'The account already exists. Use Forgot Password if you cannot sign in.'; }
    return null;
}

/** Step 4. $in: full_name, phone, department_id, role (staff|supervisor), password, password_confirm */
function registerAccount(mysqli $conn, string $staffNo, string $email, array $in): array
{
    $errors = [];
    $name = trim((string)($in['full_name'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));
    $dept = (int)($in['department_id'] ?? 0);
    $role = ($in['role'] ?? 'staff') === 'supervisor' ? 'supervisor' : 'staff';
    if ($name === '') { $errors['full_name'] = 'Enter your name.'; }
    if ($phone === '' || !preg_match('/^[0-9+\-\s]{9,20}$/', $phone)) { $errors['phone'] = 'Enter a valid phone number.'; }
    if ($dept <= 0 || !dbValue($conn, 'SELECT 1 FROM departments WHERE department_id = ?', [$dept])) { $errors['department_id'] = 'Select your department.'; }
    $p1 = (string)($in['password'] ?? ''); $p2 = (string)($in['password_confirm'] ?? '');
    if ($pp = passwordProblem($p1)) { $errors['password'] = $pp; }
    elseif ($p1 !== $p2) { $errors['password_confirm'] = 'The passwords do not match.'; }
    if (!$errors && $role === 'supervisor' && departmentHasHod($conn, $dept)) { $errors['role'] = 'The department already has a Head of Department.'; }
    if ($errors) { return wfFail('Please correct the highlighted fields.', ['errors' => $errors]); }
    if ($p = registerStartProblem($conn, $staffNo, $email)) { return wfFail($p); }
    [, $uid] = dbExec($conn, "INSERT INTO users (staff_no, full_name, email, phone, password, role, pending_role, department_id) VALUES (?, ?, ?, ?, ?, 'staff', ?, ?)",
        [$staffNo, mb_substr($name, 0, 120), $email, $phone, password_hash($p1, PASSWORD_DEFAULT), $role === 'supervisor' ? 'supervisor' : null, $dept]);
    consumeVerificationCode($conn, 'register', $email);
    if ($role === 'supervisor') {
        $dn = dbValue($conn, 'SELECT department_name FROM departments WHERE department_id = ?', [$dept]);
        notifyUsers($conn, assistantUserIds($conn), "$name ($staffNo) registered as Head of Department of $dn. Please confirm the role.", 'admin/users.php?tab=pending', 'account');
        return wfOk('Your account was created. You can use the system as Staff until the Administrative Assistant confirms your Head of Department role.', ['user_id' => $uid]);
    }
    return wfOk('Your account was created. You can now sign in.', ['user_id' => $uid]);
}

// ------------------------------------------------------------
// UC015 Reset Password, UC016 Manage Profile
// ------------------------------------------------------------

/** The active account with this staff ID and email, or null. */
function accountByStaffAndEmail(mysqli $conn, string $staffNo, string $email): ?array
{
    return dbOne($conn, "SELECT * FROM users WHERE staff_no = ? AND email = ? AND status = 'active'", [$staffNo, $email]);
}

function setPassword(mysqli $conn, int $uid, string $plain): void
{
    dbExec($conn, 'UPDATE users SET password = ?, failed_attempts = 0, locked_until = NULL WHERE user_id = ?', [password_hash($plain, PASSWORD_DEFAULT), $uid]);
}

/** UC016B: name, phone number and address. */
function updateProfile(mysqli $conn, int $uid, array $in): array
{
    $errors = [];
    $name = trim((string)($in['full_name'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));
    $addr = trim((string)($in['address'] ?? ''));
    if ($name === '') { $errors['full_name'] = 'Enter your name.'; }
    if ($phone === '' || !preg_match('/^[0-9+\-\s]{9,20}$/', $phone)) { $errors['phone'] = 'Enter a valid phone number (digits, spaces, + and -).'; }
    if (mb_strlen($addr) > 255) { $errors['address'] = 'The address is too long.'; }
    if ($errors) { return wfFail('Please fill in all required fields correctly.', ['errors' => $errors]); }
    dbExec($conn, 'UPDATE users SET full_name = ?, phone = ?, address = ? WHERE user_id = ?', [mb_substr($name, 0, 120), $phone, $addr !== '' ? $addr : null, $uid]);
    $_SESSION['full_name'] = $name;
    return wfOk('Your profile was updated.');
}

/** UC016C */
function changePassword(mysqli $conn, int $uid, string $current, string $new, string $confirm): array
{
    $u = dbOne($conn, 'SELECT password FROM users WHERE user_id = ?', [$uid]);
    if (!$u || !password_verify($current, $u['password'])) { return wfFail('The current password is wrong.', ['errors' => ['current_password' => 'The current password is wrong.']]); }
    if ($p = passwordProblem($new)) { return wfFail($p, ['errors' => ['new_password' => $p]]); }
    if ($new !== $confirm) { return wfFail('The new passwords do not match.', ['errors' => ['confirm_password' => 'The new passwords do not match.']]); }
    setPassword($conn, $uid, $new);
    return wfOk('Your password was changed.');
}
