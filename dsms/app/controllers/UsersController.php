<?php
/**
 * UsersController - UC015 Manage User Account.
 * Only admin_assistant / head_admin. No self-registration and no "forgot
 * password": the admin creates accounts and hands out temporary passwords.
 */
class UsersController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $text = trim((string)($_GET['q'] ?? ''));
        $role = (string)($_GET['role'] ?? '');
        $status = (string)($_GET['status'] ?? '');
        $this->view('users/index', [
            'title' => 'Users', 'rows' => (new User())->search($text, $role, $status),
            'q' => $text, 'role' => $role, 'status' => $status,
        ]);
    }

    public function create(): void
    {
        $this->adminOnly();
        $this->form(null, []);
    }

    public function edit(): void
    {
        $this->adminOnly();
        $user = (new User())->find($this->getInt('id'));
        if (!$user) {
            $this->flashRedirect('danger', 'User not found.', 'users/index');
        }
        $this->form($user, $user);
    }

    private function form(?array $user, array $old, array $errors = []): void
    {
        $model = new User();
        $this->view('users/form', [
            'title'     => $user ? 'Edit user' : 'New user',
            'user'      => $user,
            'old'       => $old,
            'errors'    => $errors,
            'ptjs'      => (new Ptj())->all(),
            'approvers' => $model->approverCandidates((int)($user['id'] ?? 0)),
        ]);
    }

    /** Create or update (the form posts here). */
    public function save(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new User();
        $id    = $this->postInt('id');
        $existing = $id ? $model->find($id) : null;
        if ($id && !$existing) {
            $this->flashRedirect('danger', 'User not found.', 'users/index');
        }

        $d = [
            'staff_id'    => $this->post('staff_id'),
            'name'        => $this->post('name'),
            'email'       => strtolower($this->post('email')),
            'phone'       => $this->post('phone') ?: null,
            'role'        => $this->post('role'),
            'ptj_id'      => $this->postInt('ptj_id') ?: null,
            'approver_id' => $this->postInt('approver_id') ?: null,
        ];

        $errors = [];
        if ($d['staff_id'] === '' || !preg_match('/^[A-Za-z0-9._-]{2,30}$/', $d['staff_id'])) {
            $errors['staff_id'] = 'Staff ID: 2-30 letters, numbers, dot, dash or underscore.';
        } elseif ($model->staffIdTaken($d['staff_id'], $id)) {
            $errors['staff_id'] = 'This staff ID is already used.';
        }
        if ($d['name'] === '') {
            $errors['name'] = 'The name is required.';
        }
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid e-mail address.';
        } elseif ($model->emailTaken($d['email'], $id)) {
            $errors['email'] = 'This e-mail is already used.';
        }
        if (!in_array($d['role'], Auth::ROLES, true)) {
            $errors['role'] = 'Choose a role.';
        }
        if ($d['approver_id']) {
            $approver = $model->find($d['approver_id']);
            if (!$approver || $approver['role'] !== 'hod' || $d['approver_id'] === $id) {
                $errors['approver_id'] = 'The approver must be another Head of Department.';
            }
        }
        // Do not let the last admin lock themselves out of the admin functions.
        if ($existing && $existing['id'] === Auth::id() && !in_array($d['role'], ['admin_assistant', 'head_admin'], true)) {
            $errors['role'] = 'You cannot remove your own admin role.';
        }

        if ($errors) {
            $this->form($existing, $d + ['id' => $id], $errors);
            return;
        }

        if ($existing) {
            $model->updateDetails($id, $d);
            (new AuditLog())->record('user_update', null, "Updated account {$d['staff_id']} ({$d['role']})");
            $this->flashRedirect('success', 'User updated.', 'users/index');
        }

        $temp = User::generateTempPassword();
        $model->create($d, $temp);
        (new AuditLog())->record('user_create', null, "Created account {$d['staff_id']} ({$d['role']})");
        // Shown once, on screen: the admin hands it to the user. Only the hash is stored.
        $this->flashRedirect('success',
            "Account created for {$d['name']}. Temporary password: $temp (the user must change it at first login). Please note it now; it will not be shown again.",
            'users/index');
    }

    /** Reset password: new temporary password, user must change it. */
    public function resetPassword(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new User();
        $user = $model->find($this->postInt('id'));
        if (!$user) {
            $this->flashRedirect('danger', 'User not found.', 'users/index');
        }
        $temp = User::generateTempPassword();
        $model->updatePassword((int)$user['id'], $temp, true);
        $model->unlock((int)$user['id']);
        (new AuditLog())->record('password_reset', null, "Reset password of {$user['staff_id']}");
        $this->flashRedirect('success',
            "Password reset for {$user['name']}. Temporary password: $temp (must be changed at next login). It will not be shown again.",
            'users/index');
    }

    public function unlock(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $id = $this->postInt('id');
        (new User())->unlock($id);
        (new AuditLog())->record('user_unlock', null, "Unlocked account id $id");
        $this->flashRedirect('success', 'Account unlocked.', 'users/index');
    }

    /** Deactivate / activate. There is no hard delete: requests and logs refer to users. */
    public function toggle(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new User();
        $user = $model->find($this->postInt('id'));
        if (!$user) {
            $this->flashRedirect('danger', 'User not found.', 'users/index');
        }
        if ((int)$user['id'] === Auth::id()) {
            $this->flashRedirect('warning', 'You cannot deactivate your own account.', 'users/index');
        }
        $model->setActive((int)$user['id'], !$user['is_active']);
        (new AuditLog())->record($user['is_active'] ? 'user_deactivate' : 'user_activate', null, "Account {$user['staff_id']}");
        $this->flashRedirect('success', $user['is_active'] ? 'Account deactivated.' : 'Account activated.', 'users/index');
    }
}
