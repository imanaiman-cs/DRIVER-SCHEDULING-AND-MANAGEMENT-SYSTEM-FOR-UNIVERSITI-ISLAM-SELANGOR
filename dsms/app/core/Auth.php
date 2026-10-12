<?php
/**
 * Auth - who is logged in and what they may do.
 *
 * Step 1 only contains the read-only helpers. The login / logout /
 * lockout logic is added in Step 2.
 *
 * Roles (generalisation): staff < driver / hod / admin_assistant < head_admin.
 * EVERY role is also "staff", so "can submit a request" is true for all of them.
 */
class Auth
{
    public const ROLES = ['staff', 'driver', 'hod', 'admin_assistant', 'head_admin'];

    /** Put a user into the session after a successful login. */
    public static function login(array $user): void
    {
        Session::regenerate();                 // new session id: stops session fixation
        $_SESSION['user'] = [
            'id'                   => (int)$user['id'],
            'staff_id'             => $user['staff_id'],
            'name'                 => $user['name'],
            'email'                => $user['email'],
            'role'                 => $user['role'],
            'ptj_id'               => $user['ptj_id'] !== null ? (int)$user['ptj_id'] : null,
            'approver_id'          => $user['approver_id'] !== null ? (int)$user['approver_id'] : null,
            'must_change_password' => (bool)$user['must_change_password'],
        ];
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    /** Re-read the user's details from the database (e.g. after changing the password). */
    public static function refresh(): void
    {
        if (self::check()) {
            $fresh = (new User())->find(self::id());
            if ($fresh && $fresh['is_active'] && !$fresh['is_locked']) {
                self::login($fresh);
            } else {
                self::logout();
            }
        }
    }

    /** The logged-in user (array) or null. */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    /** True if the user has ANY of the given roles: Auth::hasRole('hod', 'head_admin'). */
    public static function hasRole(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    /** admin_assistant and head_admin share all admin functions. */
    public static function isAdmin(): bool
    {
        return self::hasRole('admin_assistant', 'head_admin');
    }

    /**
     * Controllers call this FIRST in every action that needs a login.
     * Hiding a menu item is not security; checking here, on the server, is.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            if (!Session::hasFlash()) {          // do not hide a more useful message (e.g. the timeout notice)
                Session::flash('warning', 'Please log in first.');
            }
            header('Location: ' . url('auth/login'));
            exit;
        }
    }

    /** Like requireLogin, but also checks the role. Everyone else gets 403 Forbidden. */
    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::hasRole(...$roles)) {
            http_response_code(403);
            View::render('errors/403', ['title' => 'Access denied']);
            exit;
        }
    }
}
