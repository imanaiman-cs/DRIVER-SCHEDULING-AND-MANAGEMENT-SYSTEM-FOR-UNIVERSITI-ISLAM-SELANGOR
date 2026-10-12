<?php
/**
 * AuthController - UC001 Login (+ logout and forced password change).
 *
 * No self-registration and no "forgot password": accounts are created and
 * reset only by an admin_assistant / head_admin (UC015).
 */
class AuthController extends Controller
{
    /** Login form (GET) and login check (POST). */
    public function login(): void
    {
        if (Auth::check()) {
            $this->redirect('home/index');
        }

        $error = null;
        $login = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePost();
            $login    = trim((string)($_POST['login'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $error    = $this->attempt($login, $password);
        }

        $this->view('auth/login', ['title' => 'Login', 'error' => $error, 'login' => $login], 'plain');
    }

    /** Returns an error message, or redirects on success. */
    private function attempt(string $login, string $password): ?string
    {
        $users    = new User();
        $limit    = (int)((new Setting())->get('MAX_FAILED_ATTEMPTS', '5'));
        $user     = $login !== '' ? $users->findByLogin($login) : null;
        $generic  = 'Invalid staff ID or password.';

        if (!$user || !$user['is_active']) {
            return $generic;             // same message: do not reveal which part was wrong
        }
        if ($user['is_locked']) {
            return 'This account is locked after too many failed attempts. Please contact the admin to unlock it.';
        }
        if (!password_verify($password, $user['password_hash'])) {
            $locked = $users->recordFailedAttempt((int)$user['id'], $limit);
            return $locked
                ? 'Too many failed attempts. The account is now locked. Please contact the admin.'
                : $generic;
        }

        $users->recordSuccessfulLogin((int)$user['id']);
        Auth::login($user);

        if ($user['must_change_password']) {
            Session::flash('info', 'Please change your temporary password before you continue.');
            $this->redirect('auth/change_password');
        }
        $this->redirect('home/index');
        return null;
    }

    /** Logout is a POST (with CSRF token) so a link on another site cannot log users out. */
    public function logout(): void
    {
        $this->requirePost();
        Auth::logout();
        Session::start();
        Session::flash('success', 'You have been logged out.');
        $this->redirect('auth/login');
    }

    /** Change password: used for the forced first change and any time later. */
    public function changePassword(): void
    {
        Auth::requireLogin();
        $error = null;
        $forced = (bool)(Auth::user()['must_change_password'] ?? false);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePost();
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            $users   = new User();
            $row     = $users->find(Auth::id());

            if (!$row || !password_verify($current, $row['password_hash'])) {
                $error = 'The current password is not correct.';
            } elseif ($new !== $confirm) {
                $error = 'The new password and the confirmation do not match.';
            } elseif ($new === $current) {
                $error = 'The new password must be different from the current one.';
            } elseif (($problem = User::passwordProblem($new)) !== null) {
                $error = $problem;
            } else {
                $users->updatePassword(Auth::id(), $new, false);
                Auth::refresh();
                Session::flash('success', 'Your password has been changed.');
                $this->redirect('home/index');
            }
        }

        $this->view('auth/change_password', ['title' => 'Change password', 'error' => $error, 'forced' => $forced]);
    }
}
