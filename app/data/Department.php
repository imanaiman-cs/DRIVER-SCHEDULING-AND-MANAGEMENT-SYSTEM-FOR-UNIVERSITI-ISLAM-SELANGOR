<?php
// ============================================================
// SDD_CLASS_304 - Department (Data Access Layer, SDD_PKG_300)
// Reads departments and the department each one reports to.
// ============================================================

class Department
{
    /** Active departments. */
    public static function findAll(): array
    {
        return Database::query("SELECT d.*, p.department_name AS parent_name FROM department d LEFT JOIN department p ON p.department_id = d.parent_department_id
                                 WHERE d.status = 'Active' ORDER BY d.department_name");
    }

    public static function findByID(int $departmentID): ?array
    {
        return Database::queryOne('SELECT * FROM department WHERE department_id = ?', [$departmentID]);
    }

    /**
     * The head of the department: its active Head of Department, or the Head of Admin for BPI.
     * $exceptUserID leaves one user out (the head is never asked to approve their own request).
     */
    public static function getHead(?int $departmentID, int $exceptUserID = 0): ?array
    {
        if (!$departmentID) { return null; }
        return Database::queryOne("SELECT * FROM `user` WHERE department_id = ? AND role IN ('Head of Department', 'Head of Admin') AND status = 'Active' AND user_id <> ?
                                   ORDER BY FIELD(role, 'Head of Department', 'Head of Admin'), user_id LIMIT 1", [$departmentID, $exceptUserID]);
    }

    /** The parent department, or null for top management. */
    public static function getParent(int $departmentID): ?array
    {
        return Database::queryOne('SELECT p.* FROM department d JOIN department p ON p.department_id = d.parent_department_id WHERE d.department_id = ?', [$departmentID]);
    }

    /** IDs of the departments that report to this one. */
    public static function findChildren(int $departmentID): array
    {
        return array_map('intval', array_column(Database::query('SELECT department_id FROM department WHERE parent_department_id = ?', [$departmentID]), 'department_id'));
    }
}
