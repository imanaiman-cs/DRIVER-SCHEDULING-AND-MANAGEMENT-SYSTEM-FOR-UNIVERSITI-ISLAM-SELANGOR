<?php
// ============================================================
// SDD_CLASS_201 - AccountController (Domain Layer, SDD_PKG_200)
// Rules for registration (UC013), verification codes (UC014), login, password reset (UC015), profiles (UC016) and
// account management (UC001). Verification codes are kept in the server session for 15 minutes, never in the database.
// ============================================================

class AccountController
{
    /** Failed logins allowed before the account is locked (5). */
    private int $maxAttempts;
    /** Minutes before an idle session ends (15). */
    private int $idleTimeout;
    private NotificationService $notifier;

    public function __construct()
    {
        $this->maxAttempts = (int)setting('MAX_FAILED_ATTEMPTS');
        $this->idleTimeout = (int)setting('IDLE_TIMEOUT_MIN');
        $this->notifier = new NotificationService();
    }

    public function getIdleTimeout(): int
    {
        return $this->idleTimeout;
    }

    // ---------------------------------------------------------------- checks

    /** Is it a UIS email? */
    public function isUisEmail(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        foreach ((array)setting('UIS_EMAIL_DOMAINS') as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) { return true; }
        }
        return false;
    }

    /** At least 8 characters with letters and numbers. Returns the problem, or null. */
    public function passwordProblem(string $p): ?string
    {
        if (mb_strlen($p) < 8) { return 'The password must be at least 8 characters.'; }
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) { return 'The password must contain letters and numbers.'; }
        return null;
    }

    /** Active drivers whose licence expires within 30 days (or has expired). */
    public function expiringLicences(): array
    {
        return array_values(array_filter(Driver::findActive(), fn($d) => $d['licence_expiry'] < date('Y-m-d', strtotime('+30 days'))));
    }

    /** Active departments, for the registration and account forms. */
    public function listDepartments(): array
    {
        return Department::findAll();
    }

    // ---------------------------------------------------------------- UC014 Send Verification Email

    /**
     * Checks the staff ID and UIS email, keeps a code in the session and e-mails it (SDD 5.12.2.1).
     * $purpose: 'Register' or 'Reset Password'. While e-mail is switched off the result also carries 'dev_code'.
     */
    public function requestCode(string $staffID, string $email, string $purpose): array
    {
        $staffID = trim($staffID); $email = strtolower(trim($email));
        if ($staffID === '' || $email === '') { return wfFail('Enter your staff ID and UIS email.'); }
        if (!$this->isUisEmail($email)) { return wfFail('Only UIS email is accepted.'); }
        if ($purpose === 'Register' && User::exists($staffID, $email)) { return wfFail('The account already exists. Use Forgot Password if you cannot sign in.'); }
        if ($purpose === 'Reset Password') {
            $u = User::findByEmail($email);
            if (!$u || strcasecmp($u['staff_id'], $staffID) !== 0 || $u['status'] !== User::ACTIVE) { return wfFail('The account is not found. Check the staff ID and email.'); }
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $mins = (int)setting('CODE_EXPIRY_MINUTES');
        $_SESSION['verify'] = ['code_hash' => password_hash($code, PASSWORD_DEFAULT), 'staff_id' => $staffID, 'email' => $email, 'purpose' => $purpose,
                               'expires' => time() + $mins * 60, 'attempts' => 0, 'verified' => false];
        $what = $purpose === 'Register' ? 'register your account' : 'reset your password';
        $sent = $this->notifier->sendEmail($email, 'DSMS verification code', "Your verification code to $what is $code.\nIt expires in $mins minutes. If you did not ask for it, ignore this email.");
        if (!$sent && mailEnabled()) { unset($_SESSION['verify']); return wfFail('The email cannot be sent. Please try again later.'); }
        $out = wfOk("A verification code was sent to $email. It expires in $mins minutes.");
        if (!mailEnabled()) { $out['dev_code'] = $code; }
        return $out;
    }

    /** Checks the code against the one kept in the session and its expiry time (SDD 5.12.2.4). */
    public function verifyCode(string $code): array
    {
        $v = $_SESSION['verify'] ?? null;
        if (!$v || time() > $v['expires']) { return wfFail('The code has expired. Request a new one (Resend Code).'); }
        if ($v['attempts'] >= 5) { return wfFail('Too many wrong codes. Request a new one (Resend Code).'); }
        if (!password_verify(trim($code), $v['code_hash'])) {
            $_SESSION['verify']['attempts']++;
            return wfFail('Verification failed. Please try again.');
        }
        $_SESSION['verify']['verified'] = true;
        unset($_SESSION['verify']['code_hash']);
        return wfOk('Verified.');
    }

    /** The verified staff ID and email of the session for a purpose, or null. */
    public function verifiedFor(string $purpose): ?array
    {
        $v = $_SESSION['verify'] ?? null;
        return ($v && $v['purpose'] === $purpose && !empty($v['verified']) && time() <= $v['expires'] + 1800) ? $v : null;
    }

    public function clearVerification(): void
    {
        unset($_SESSION['verify']);
    }

    // ---------------------------------------------------------------- UC013 Register Account

    /** Creates a Staff account; a Head of Department role is sent for confirmation (SDD 5.12.2.2). */
    public function register(array $formData): array
    {
        $v = $this->verifiedFor('Register');
        if (!$v) { return wfFail('Please verify your email first.'); }
        $errors = [];
        $name = trim((string)($formData['name'] ?? ''));
        $phone = trim((string)($formData['phone_no'] ?? ''));
        $dept = (int)($formData['department_id'] ?? 0);
        $role = ($formData['role'] ?? User::STAFF) === User::HOD ? User::HOD : User::STAFF;
        if ($name === '') { $errors['name'] = 'Enter your name.'; }
        if (!preg_match('/^[0-9+\-\s]{9,20}$/', $phone)) { $errors['phone_no'] = 'Enter a valid phone number.'; }
        if (!$dept || !Department::findByID($dept)) { $errors['department_id'] = 'Select your department.'; }
        $p1 = (string)($formData['password'] ?? ''); $p2 = (string)($formData['password_confirm'] ?? '');
        if ($pp = $this->passwordProblem($p1)) { $errors['password'] = $pp; }
        elseif ($p1 !== $p2) { $errors['password_confirm'] = 'The passwords do not match.'; }
        if (!$errors && $role === User::HOD && Department::getHead($dept)) { $errors['role'] = 'The department already has a Head of Department.'; }
        if ($errors) { return wfFail('Please correct the highlighted fields.', ['errors' => $errors]); }
        if (User::exists($v['staff_id'], $v['email'])) { return wfFail('The account already exists.'); }
        $uid = User::create(['staff_id' => $v['staff_id'], 'name' => mb_substr($name, 0, 150), 'email' => $v['email'], 'phone_no' => $phone,
                             'password_hash' => password_hash($p1, PASSWORD_DEFAULT), 'department_id' => $dept, 'role' => User::STAFF,
                             'pending_role' => $role === User::HOD ? User::HOD : null]);
        $this->clearVerification();
        if ($role === User::HOD) {
            $dn = Department::findByID($dept)['department_name'];
            $this->notifier->notifyRole(User::AA, "$name ({$v['staff_id']}) registered as Head of Department of $dn. Please confirm the role.", 'admin/users.php?tab=pending');
            return wfOk('Your account was created. You can use the system as Staff until the Administrative Assistant confirms your Head of Department role.', ['user_id' => $uid]);
        }
        return wfOk('Your account was created. You can now sign in.', ['user_id' => $uid]);
    }

    // ---------------------------------------------------------------- login

    /** Checks the password, counts failed attempts and starts the session (SDD 5.12.2.3). Login is by staff ID or UIS email. */
    public function login(string $staffID, string $password): array
    {
        $generic = 'Invalid staff ID or password. Please try again.';
        if (trim($staffID) === '' || $password === '') { return wfFail('Please enter both your staff ID and password.'); }
        $user = User::findByStaffID($staffID) ?? User::findByEmail($staffID);
        if (!$user) { return wfFail($generic); }
        if ($user['status'] !== User::ACTIVE) { return wfFail('This account is not active. Please contact the Vehicle Section.'); }
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $left = max(1, (int)ceil((strtotime($user['locked_until']) - time()) / 60));
            return wfFail("The account is locked after too many failed attempts. Please try again in $left minute(s), or use Forgot Password.");
        }
        if (!password_verify($password, $user['password_hash'])) {
            $n = (int)$user['failed_attempts'] + 1;
            if ($n >= $this->maxAttempts) {
                User::update((int)$user['user_id'], ['failed_attempts' => 0, 'locked_until' => date('Y-m-d H:i:s', time() + (int)setting('LOCKOUT_MINUTES') * 60)]);
                return wfFail('Too many failed attempts. The account is locked for ' . setting('LOCKOUT_MINUTES') . ' minutes.');
            }
            User::update((int)$user['user_id'], ['failed_attempts' => $n]);
            return wfFail($generic);
        }
        User::update((int)$user['user_id'], ['failed_attempts' => 0, 'locked_until' => null]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['name'];
        $_SESSION['department_id'] = (int)$user['department_id'];
        $_SESSION['last_activity'] = time();
        return wfOk('Signed in.', ['user' => $user]);
    }

    // ---------------------------------------------------------------- UC015 Reset Password

    /** Sets a new password after the code is verified. */
    public function resetPassword(string $newPw, string $confirm): array
    {
        $v = $this->verifiedFor('Reset Password');
        if (!$v) { return wfFail('Please verify your email first.'); }
        if ($p = $this->passwordProblem($newPw)) { return wfFail($p, ['errors' => ['password' => $p]]); }
        if ($newPw !== $confirm) { return wfFail('The passwords do not match.', ['errors' => ['password_confirm' => 'The passwords do not match.']]); }
        $u = User::findByEmail($v['email']);
        if (!$u) { return wfFail('The account is not found.'); }
        User::update((int)$u['user_id'], ['password_hash' => password_hash($newPw, PASSWORD_DEFAULT), 'failed_attempts' => 0, 'locked_until' => null]);
        $this->clearVerification();
        return wfOk('Your password was changed. You can now sign in.');
    }

    // ---------------------------------------------------------------- UC016 Manage Profile

    public function getProfile(int $userID): ?array
    {
        return User::findByID($userID);
    }

    /** Updates the user's own name, phone number and address. */
    public function updateProfile(int $userID, array $formData): array
    {
        $errors = [];
        $name = trim((string)($formData['name'] ?? ''));
        $phone = trim((string)($formData['phone_no'] ?? ''));
        $addr = trim((string)($formData['address'] ?? ''));
        if ($name === '') { $errors['name'] = 'Enter your name.'; }
        if (!preg_match('/^[0-9+\-\s]{9,20}$/', $phone)) { $errors['phone_no'] = 'Enter a valid phone number (digits, spaces, + and -).'; }
        if (mb_strlen($addr) > 255) { $errors['address'] = 'The address is too long.'; }
        if ($errors) { return wfFail('Please fill in all required fields correctly.', ['errors' => $errors]); }
        User::update($userID, ['name' => mb_substr($name, 0, 150), 'phone_no' => $phone, 'address' => $addr !== '' ? $addr : null]);
        $_SESSION['full_name'] = $name;
        return wfOk('Your profile was updated.');
    }

    /** Checks the current password and saves the new one. */
    public function changePassword(int $userID, string $oldPw, string $newPw, string $confirm): array
    {
        $u = User::findByID($userID);
        if (!$u || !password_verify($oldPw, $u['password_hash'])) { return wfFail('The current password is wrong.', ['errors' => ['current_password' => 'The current password is wrong.']]); }
        if ($p = $this->passwordProblem($newPw)) { return wfFail($p, ['errors' => ['new_password' => $p]]); }
        if ($newPw !== $confirm) { return wfFail('The new passwords do not match.', ['errors' => ['confirm_password' => 'The new passwords do not match.']]); }
        User::update($userID, ['password_hash' => password_hash($newPw, PASSWORD_DEFAULT)]);
        return wfOk('Your password was changed.');
    }

    // ---------------------------------------------------------------- UC001 Manage Staff and Driver Account

    /** Accounts that match the filter (q, role, pending, limit, offset). Returns ['rows', 'total']. */
    public function listAccounts(array $filter): array
    {
        return ['rows' => User::findAll($filter), 'total' => User::countAll($filter)];
    }

    /** Checks the driver part of a form. Returns [errors, licence classes]. */
    private function checkDriverFields(array $in): array
    {
        $errors = [];
        $lic = array_values(array_intersect(array_keys(Driver::LICENCE_CLASSES), (array)($in['licence_classes'] ?? [])));
        if (!$lic) { $errors['licence_classes'] = 'Select at least one licence class.'; }
        if (!isValidDate((string)($in['licence_expiry'] ?? ''))) { $errors['licence_expiry'] = 'Enter the licence expiry date.'; }
        if (!isValidDate((string)($in['start_date'] ?? ''))) { $errors['start_date'] = 'Enter the start date of service.'; }
        elseif ($in['start_date'] > date('Y-m-d')) { $errors['start_date'] = 'The start date of service cannot be in the future.'; }
        return [$errors, $lic];
    }

    /** UC001A: creates a driver account (BPI) and e-mails the default password. */
    public function createDriverAccount(array $formData): array
    {
        $errors = [];
        $staffID = trim((string)($formData['staff_id'] ?? ''));
        $name = trim((string)($formData['name'] ?? ''));
        $email = strtolower(trim((string)($formData['email'] ?? '')));
        $phone = trim((string)($formData['phone_no'] ?? ''));
        if ($staffID === '') { $errors['staff_id'] = 'Enter the staff ID.'; }
        if ($name === '') { $errors['name'] = 'Enter the name.'; }
        if ($email === '') { $errors['email'] = 'Enter the UIS email.'; } elseif (!$this->isUisEmail($email)) { $errors['email'] = 'Only UIS email is accepted.'; }
        if (!preg_match('/^[0-9+\-\s]{9,20}$/', $phone)) { $errors['phone_no'] = 'Enter a valid phone number.'; }
        [$de, $lic] = $this->checkDriverFields($formData);
        $errors += $de;
        if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }
        if (User::exists($staffID, $email)) { return wfFail('The account already exists (the staff ID or email is already registered).', ['errors' => ['staff_id' => 'Already registered.']]); }
        $dept = (int)($formData['department_id'] ?? 0) ?: (int)(User::findByID((int)$_SESSION['user_id'])['department_id'] ?? 0);
        $plain = $this->generatePassword();
        Database::beginTransaction();
        try {
            $uid = User::create(['staff_id' => $staffID, 'name' => mb_substr($name, 0, 150), 'email' => $email, 'phone_no' => $phone,
                                 'password_hash' => password_hash($plain, PASSWORD_DEFAULT), 'department_id' => $dept, 'role' => User::DRIVER]);
            Driver::create($uid, $lic, $formData['licence_expiry'], $formData['start_date']);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            appLog('error', 'createDriverAccount: ' . $e->getMessage());
            return wfFail('The account could not be created. Please try again.');
        }
        $sent = $this->notifier->sendEmail($email, 'DSMS: your driver account', "Salam $name,\n\nYour driver account for the UIS Driver Scheduling and Management System was created.\n\n"
            . "Staff ID: $staffID\nPassword: $plain\n\nSign in at " . SITE_URL . "/login.php . You may change the password in Profile > Change Password.");
        if ($sent || !mailEnabled()) {
            return wfOk("The driver account of $name was created and the login details were sent to $email." . (!mailEnabled() ? " (E-mail is switched off: staff ID $staffID, password $plain.)" : ''), ['user_id' => $uid]);
        }
        return wfOk("The driver account of $name was created, but the email could not be sent. Give the driver the login details: staff ID $staffID, password $plain.", ['user_id' => $uid, 'email_failed' => true]);
    }

    private function generatePassword(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz'; $num = '23456789'; $p = '';
        for ($i = 0; $i < 7; $i++) { $p .= $abc[random_int(0, strlen($abc) - 1)]; }
        for ($i = 0; $i < 3; $i++) { $p .= $num[random_int(0, strlen($num) - 1)]; }
        return str_shuffle($p);
    }

    /** UC001C: the department and role of a staff account; the department, licence and start date of a driver. */
    public function updateAccount(int $userID, array $formData): array
    {
        $u = User::findByID($userID);
        if (!$u) { return wfFail('Unknown account.'); }
        $errors = [];
        $dept = (int)($formData['department_id'] ?? 0);
        if (!$dept || !Department::findByID($dept)) { $errors['department_id'] = 'Select the department.'; }
        $role = $u['role'];
        if (in_array($u['role'], [User::STAFF, User::HOD], true)) {
            $role = (string)($formData['role'] ?? '');
            if (!in_array($role, [User::STAFF, User::HOD], true)) { $errors['role'] = 'Select the role.'; }
        }
        $lic = [];
        if ($u['role'] === User::DRIVER) { [$de, $lic] = $this->checkDriverFields($formData); $errors += $de; }
        if ($errors) { return wfFail('Please fill in all required fields.', ['errors' => $errors]); }
        if ($role === User::HOD && Department::getHead($dept, $userID)) {
            return wfFail('The department already has a Head of Department.', ['errors' => ['role' => 'The department already has a Head of Department.']]);
        }
        if ($u['role'] === User::HOD && $role !== User::HOD && $this->pendingAsHead($u)) {
            return wfFail('This Head of Department still has pending requests. They must be handled first.');
        }
        $changes = [];
        if ((int)$u['department_id'] !== $dept) { $changes[] = 'department: ' . Department::findByID($dept)['department_name']; }
        if ($u['role'] !== $role) { $changes[] = 'role: ' . $role; }
        User::update($userID, ['department_id' => $dept, 'role' => $role] + ($role === User::HOD ? ['pending_role' => null] : []));
        if ($u['role'] === User::DRIVER) {
            if ($u['licence_classes'] !== implode(',', $lic)) { $changes[] = 'licence classes: ' . implode(', ', $lic); }
            if ($u['licence_expiry'] !== $formData['licence_expiry']) { $changes[] = 'licence expiry: ' . fmtD($formData['licence_expiry']); }
            if ($u['start_date'] !== $formData['start_date']) { $changes[] = 'start date of service: ' . fmtD($formData['start_date']); }
            Driver::update($userID, $lic, $formData['licence_expiry'], $formData['start_date']);
        }
        if ($changes) { $this->notifier->notify([$userID], 'Your account was updated by the Vehicle Section (' . implode('; ', $changes) . ').', 'profile.php'); }
        return wfOk('The account was updated' . ($changes ? ' and the user was informed by email.' : '.'));
    }

    /** UC001C A1: confirms or rejects a pending Head of Department role. */
    public function confirmRole(int $userID, bool $approve): array
    {
        $u = User::findByID($userID);
        if (!$u || $u['pending_role'] !== User::HOD) { return wfFail('This user is not waiting for a role confirmation.'); }
        if ($approve) {
            if (Department::getHead((int)$u['department_id'], $userID)) { return wfFail('The department already has a Head of Department.'); }
            User::update($userID, ['role' => User::HOD, 'pending_role' => null]);
            $this->notifier->notify([$userID], 'Your Head of Department role for ' . $u['department_name'] . ' was confirmed. You can now approve vehicle requests of your department.', 'supervisor/approvals.php');
            return wfOk($u['name'] . ' is now the Head of Department of ' . $u['department_name'] . '.');
        }
        User::update($userID, ['pending_role' => null]);
        $this->notifier->notify([$userID], 'Your Head of Department role was not confirmed. You can continue to use the system as Staff.', 'profile.php');
        return wfOk('The Head of Department role of ' . $u['name'] . ' was rejected. The user stays as Staff.');
    }

    /** Pending requests that wait for this head (UC001D exception 3.2). */
    private function pendingAsHead(array $u): int
    {
        $rc = new RequestController();
        return count(array_filter(Request::findByDepartment((int)$u['department_id'], (int)$u['user_id'], ['status' => Request::PENDING])['rows'],
            fn($r) => $rc->isWaitingForHead($r, (int)$u['user_id'])));
    }

    /** UC001D exceptions: why the account cannot be deactivated now, with the tasks to replace first. */
    public function deactivateBlockers(array $u): array
    {
        if ($u['role'] === User::DRIVER) {
            $tasks = Task::findByDriver((int)$u['user_id'], date('Y-m-d H:i:s'), null, [Task::DRIVER_ASSIGNED, Task::APPROVED]);
            if ($tasks) { return ['message' => 'The driver has upcoming tasks. Replace the driver first (Replace Driver).', 'tasks' => $tasks]; }
        }
        if (in_array($u['role'], [User::HOD, User::HOA], true) && ($n = $this->pendingAsHead($u))) {
            return ['message' => "This head has $n pending request(s). The pending requests must be handled first.", 'tasks' => []];
        }
        return [];
    }

    /** Sets an account to Inactive. */
    public function deactivateAccount(int $userID): array
    {
        $u = User::findByID($userID);
        if (!$u) { return wfFail('Unknown account.'); }
        if ($userID === (int)$_SESSION['user_id']) { return wfFail('You cannot deactivate your own account.'); }
        if ($u['status'] !== User::ACTIVE) { return wfFail('The account is already inactive.'); }
        if ($b = $this->deactivateBlockers($u)) { return wfFail($b['message'], ['tasks' => $b['tasks']]); }
        User::deactivate($userID);
        return wfOk('The account of ' . $u['name'] . ' was deactivated. The user can no longer log in; all records remain.');
    }

    /** Makes an inactive account active again. */
    public function activateAccount(int $userID): array
    {
        $u = User::findByID($userID);
        if (!$u) { return wfFail('Unknown account.'); }
        if ($u['role'] === User::HOD && Department::getHead((int)$u['department_id'], $userID)) { return wfFail('The department already has an active Head of Department.'); }
        User::update($userID, ['status' => User::ACTIVE, 'failed_attempts' => 0, 'locked_until' => null]);
        return wfOk('The account of ' . $u['name'] . ' was activated again.');
    }
}
