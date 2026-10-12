<?php
/**
 * Session - starts the PHP session safely and enforces the inactivity timeout.
 *
 * Security choices (each one is easy to explain to an examiner):
 *  - HttpOnly cookie  : JavaScript cannot read the session id (limits XSS damage).
 *  - SameSite=Lax     : the cookie is not sent on cross-site POSTs (helps against CSRF).
 *  - use_strict_mode  : PHP refuses a session id it did not create (stops session fixation).
 *  - inactivity timeout (default 15 min): an unattended screen logs itself out.
 */
class Session
{
    public static function start(int $timeoutMinutes = 15): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),   // only force "secure" when the site really runs on HTTPS
        ]);
        session_name('DSMSSESSID');
        session_start();

        // Inactivity timeout: if the last request was too long ago, wipe the session.
        $now = time();
        if (isset($_SESSION['_last_activity']) && ($now - $_SESSION['_last_activity']) > $timeoutMinutes * 60) {
            self::destroy();
            session_start();
            $_SESSION['_flash'] = ['warning' => 'You were logged out after ' . $timeoutMinutes . ' minutes of inactivity.'];
        }
        $_SESSION['_last_activity'] = $now;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** New session id after login (prevents session fixation). */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** One-time messages shown after a redirect: Session::flash('success', 'Saved.') */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type] = $message;
    }

    /** Read and clear all flash messages. */
    public static function takeFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }
}
