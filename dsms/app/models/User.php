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

    // ---- used by UC015 Manage User Account ---------------------------------

    /** List with optional search text, role and status filters. */
    public function search(string $text = '', string $role = '', string $status = ''): array
    {
        $sql = 'SELECT u.*, p.code AS ptj_code, a.name AS approver_name
                FROM users u
                LEFT JOIN ptj p ON p.id = u.ptj_id
                LEFT JOIN users a ON a.id = u.approver_id
                WHERE 1 = 1';
        $params = [];
        if ($text !== '') {
            $sql .= ' AND (u.name LIKE ? OR u.staff_id LIKE ? OR u.email LIKE ?)';
            $like = '%' . $text . '%';
            array_push($params, $like, $like, $like);
        }
        if ($role !== '' && in_array($role, Auth::ROLES, true)) {
            $sql .= ' AND u.role = ?';
            $params[] = $role;
        }
        if ($status === 'locked') {
            $sql .= ' AND u.is_locked = 1';
        } elseif ($status === 'inactive') {
            $sql .= ' AND u.is_active = 0';
        } elseif ($status === 'active') {
            $sql .= ' AND u.is_active = 1 AND u.is_locked = 0';
        }
        return $this->fetchAll($sql . ' ORDER BY u.name', $params);
    }

    /** People who can be chosen as approver: active Heads of Department. */
    public function approverCandidates(int $exceptId = 0): array
    {
        return $this->fetchAll(
            "SELECT id, name, staff_id FROM users WHERE role = 'hod' AND is_active = 1 AND id <> ? ORDER BY name", [$exceptId]
        );
    }

    public function staffIdTaken(string $staffId, int $exceptId = 0): bool
    {
        return (bool)$this->fetchOne('SELECT id FROM users WHERE staff_id = ? AND id <> ?', [$staffId, $exceptId]);
    }

    public function emailTaken(string $email, int $exceptId = 0): bool
    {
        return (bool)$this->fetchOne('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $exceptId]);
    }

    /** Create an account with a temporary password (the user must change it at first login). */
    public function create(array $d, string $tempPassword): int
    {
        $this->execute(
            'INSERT INTO users (staff_id, name, email, phone, role, ptj_id, approver_id, password_hash, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [$d['staff_id'], $d['name'], $d['email'], $d['phone'], $d['role'], $d['ptj_id'], $d['approver_id'], password_hash($tempPassword, PASSWORD_DEFAULT)]
        );
        $id = (int)$this->db->lastInsertId();
        $this->syncDriverRow($id, $d['role']);
        return $id;
    }

    public function updateDetails(int $id, array $d): void
    {
        $this->execute(
            'UPDATE users SET staff_id = ?, name = ?, email = ?, phone = ?, role = ?, ptj_id = ?, approver_id = ? WHERE id = ?',
            [$d['staff_id'], $d['name'], $d['email'], $d['phone'], $d['role'], $d['ptj_id'], $d['approver_id'], $id]
        );
        $this->syncDriverRow($id, $d['role']);
    }

    /** A user with role "driver" must have a row in `drivers`; leaving the role deactivates it (history is kept). */
    private function syncDriverRow(int $userId, string $role): void
    {
        if ($role === 'driver') {
            $this->execute('INSERT INTO drivers (user_id) VALUES (?) ON DUPLICATE KEY UPDATE is_active = 1', [$userId]);
        } else {
            $this->execute('UPDATE drivers SET is_active = 0 WHERE user_id = ?', [$userId]);
        }
    }

    /**
     * Deactivate instead of deleting (history and audit rows must keep pointing at the user).
     * A driver account also switches its driver row, so a deactivated driver is never recommended.
     */
    public function setActive(int $id, bool $active): void
    {
        $this->execute('UPDATE users SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
        $this->execute('UPDATE drivers SET is_active = ? WHERE user_id = ?', [$active ? 1 : 0, $id]);
    }

    public function unlock(int $id): void
    {
        $this->execute('UPDATE users SET is_locked = 0, failed_attempts = 0 WHERE id = ?', [$id]);
    }

    /** Random temporary password that always satisfies the password rule (letters + numbers). */
    public static function generateTempPassword(): string
    {
        $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $pw = '';
        for ($i = 0; $i < 6; $i++) { $pw .= $letters[random_int(0, strlen($letters) - 1)]; }
        for ($i = 0; $i < 3; $i++) { $pw .= $digits[random_int(0, strlen($digits) - 1)]; }
        return str_shuffle($pw);
    }
}
