<?php
/**
 * Csrf - protects every POST form against Cross-Site Request Forgery.
 *
 * IDEA: each form carries a secret random token that only OUR pages know.
 * A malicious site cannot read it, so it cannot make a logged-in user's browser
 * submit a valid form by itself. The server rejects any POST without the token.
 */
class Csrf
{
    /** Get (or create) the token for this session. */
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** Hidden input to put inside every <form method="post">. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    /** True when the posted token matches the session token (hash_equals avoids timing leaks). */
    public static function verify(?string $posted): bool
    {
        return is_string($posted) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $posted);
    }
}
