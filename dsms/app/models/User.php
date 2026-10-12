<?php
/**
 * User - the `users` table (everyone who can log in).
 */
class User extends Model
{
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** Login accepts the staff ID (e.g. "staff1") or the e-mail address. */
    public function findByLogin(string $login): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE staff_id = ? OR email = ? LIMIT 1', [$login, $login]);
    }

    /**
     * Wrong password: count it, and lock the account when the limit is reached.
     * WHY lock: stops someone guessing passwords one after another (brute force).
     * Only an administrator can unlock (UC015).
     * @return bool true when this attempt locked the account
     */
    public function recordFailedAttempt(int $id, int $limit): bool
    {
        $this->execute('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);
        $row = $this->fetchOne('SELECT failed_attempts FROM users WHERE id = ?', [$id]);
        if ($row && (int)$row['failed_attempts'] >= $limit) {
            $this->execute('UPDATE users SET is_locked = 1 WHERE id = ?', [$id]);
            return true;
        }
        return false;
    }

    public function recordSuccessfulLogin(int $id): void
    {
        $this->execute('UPDATE users SET failed_attempts = 0, last_login_at = NOW() WHERE id = ?', [$id]);
    }

    /** Save a new password (always stored as a bcrypt hash, never as plain text). */
    public function updatePassword(int $id, string $plain, bool $mustChange): void
    {
        $this->execute(
            'UPDATE users SET password_hash = ?, must_change_password = ?, failed_attempts = 0 WHERE id = ?',
            [password_hash($plain, PASSWORD_DEFAULT), $mustChange ? 1 : 0, $id]
        );
    }

    /** Rule: at least 8 characters with letters AND numbers. Returns an error text or null. */
    public static function passwordProblem(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'The password must be at least 8 characters long.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return 'The password must contain both letters and numbers.';
        }
        return null;
    }
}
