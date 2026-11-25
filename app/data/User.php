<?php
// ============================================================
// SDD_CLASS_302 - User (Data Access Layer, SDD_PKG_300)
// Reads and writes user accounts in the `user` table.
// ============================================================

class User
{
    public const STAFF   = 'Staff';
    public const HOD     = 'Head of Department';
    public const AA      = 'Administrative Assistant';
    public const HOA     = 'Head of Admin';
    public const DRIVER  = 'Driver';
    public const ROLES   = [self::STAFF, self::HOD, self::AA, self::HOA, self::DRIVER];
    public const ACTIVE  = 'Active';
    public const INACTIVE = 'Inactive';

    /** The SELECT of one user with the department and, for a driver, the driver details. */
    private const SELECT = "SELECT u.*, d.department_name, d.parent_department_id, dr.driver_id, dr.licence_expiry, dr.start_date,
                                   (SELECT GROUP_CONCAT(l.licence_class ORDER BY FIELD(l.licence_class, 'D', 'GDL', 'PSV')) FROM driver_licence l WHERE l.driver_id = dr.driver_id) AS licence_classes
                              FROM `user` u JOIN department d ON d.department_id = u.department_id
                              LEFT JOIN driver dr ON dr.driver_id = u.user_id";

    public static function findByID(int $userID): ?array
    {
        return Database::queryOne(self::SELECT . ' WHERE u.user_id = ?', [$userID]);
    }

    public static function findByStaffID(string $staffID): ?array
    {
        return Database::queryOne(self::SELECT . ' WHERE u.staff_id = ?', [trim($staffID)]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::queryOne(self::SELECT . ' WHERE u.email = ?', [strtolower(trim($email))]);
    }

    /** Inserts the user and returns the new ID. $data: staff_id, name, email, phone_no, address, password_hash, department_id, role, pending_role */
    public static function create(array $data): int
    {
        Database::execute('INSERT INTO `user` (staff_id, name, email, phone_no, address, password_hash, department_id, role, pending_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$data['staff_id'], $data['name'], strtolower($data['email']), $data['phone_no'], $data['address'] ?? null, $data['password_hash'],
             (int)$data['department_id'], $data['role'] ?? self::STAFF, $data['pending_role'] ?? null]);
        return Database::lastInsertId();
    }

    /** Saves changes to the given columns. */
    public static function update(int $userID, array $fields): bool
    {
        $allowed = ['name', 'phone_no', 'address', 'password_hash', 'department_id', 'role', 'pending_role', 'failed_attempts', 'locked_until', 'status'];
        $set = []; $params = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) { continue; }
            $set[] = "`$k` = ?"; $params[] = $v;
        }
        if (!$set) { return false; }
        $params[] = $userID;
        Database::execute('UPDATE `user` SET ' . implode(', ', $set) . ' WHERE user_id = ?', $params);
        return true;
    }

    /** Sets the status to Inactive (an account is never deleted). */
    public static function deactivate(int $userID): bool
    {
        return Database::execute("UPDATE `user` SET status = 'Inactive' WHERE user_id = ?", [$userID]) > 0;
    }

    /** Users with a pending role (UC001C A1). */
    public static function findPendingRoles(): array
    {
        return Database::query(self::SELECT . " WHERE u.pending_role IS NOT NULL AND u.status = 'Active' ORDER BY u.name");
    }

    /** Active users with the role. */
    public static function findByRole(string $roleName): array
    {
        return Database::query(self::SELECT . " WHERE u.role = ? AND u.status = 'Active' ORDER BY u.name", [$roleName]);
    }

    /** Accounts that match the filter: q (staff ID or name), role, status, pending (true), limit, offset. */
    public static function findAll(array $filter = []): array
    {
        [$where, $params] = self::filterSql($filter);
        $limit = isset($filter['limit']) ? ' LIMIT ' . (int)$filter['limit'] . ' OFFSET ' . (int)($filter['offset'] ?? 0) : '';
        return Database::query(self::SELECT . $where . " ORDER BY u.status, u.name$limit", $params);
    }

    public static function countAll(array $filter = []): int
    {
        [$where, $params] = self::filterSql($filter);
        return (int)Database::value('SELECT COUNT(*) FROM `user` u' . $where, $params);
    }

    private static function filterSql(array $filter): array
    {
        $w = []; $p = [];
        if (!empty($filter['q'])) { $w[] = '(u.staff_id LIKE ? OR u.name LIKE ?)'; $p[] = '%' . $filter['q'] . '%'; $p[] = '%' . $filter['q'] . '%'; }
        if (!empty($filter['role'])) { $w[] = 'u.role = ?'; $p[] = $filter['role']; }
        if (!empty($filter['pending'])) { $w[] = "u.pending_role IS NOT NULL AND u.status = 'Active'"; }
        return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /** Is the staff ID or email already registered (by another user)? */
    public static function exists(string $staffID, string $email, int $exceptUserID = 0): bool
    {
        return (bool)Database::value('SELECT 1 FROM `user` WHERE (staff_id = ? OR email = ?) AND user_id <> ?', [trim($staffID), strtolower(trim($email)), $exceptUserID]);
    }
}
