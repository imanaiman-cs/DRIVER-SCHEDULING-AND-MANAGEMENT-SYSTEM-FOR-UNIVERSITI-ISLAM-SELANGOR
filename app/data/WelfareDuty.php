<?php
// ============================================================
// SDD_CLASS_310 - WelfareDuty (Data Access Layer, SDD_PKG_300)
// Reads and writes weekly welfare duty schedules. The days are tasks with the source Welfare Duty.
// ============================================================

class WelfareDuty
{
    public const WAITING = 'Waiting for Driver';
    public const DRIVER_ASSIGNED = 'Driver Assigned';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';

    /** Inserts the week (Waiting for Driver) and returns its ID. */
    public static function create(string $weekStart, int $createdBy): int
    {
        Database::execute("INSERT INTO welfare_duty (week_start_date, status, created_by) VALUES (?, 'Waiting for Driver', ?)", [$weekStart, $createdBy]);
        return Database::lastInsertId();
    }

    public static function findByWeek(string $weekStart): ?array
    {
        return Database::queryOne('SELECT * FROM welfare_duty WHERE week_start_date = ?', [$weekStart]);
    }

    public static function findByID(int $dutyID): ?array
    {
        return Database::queryOne('SELECT * FROM welfare_duty WHERE duty_id = ?', [$dutyID]);
    }

    /** Weeks with the status (e.g. Driver Assigned for the Head of Admin). */
    public static function findByStatus(string $status): array
    {
        return Database::query('SELECT * FROM welfare_duty WHERE status = ? ORDER BY week_start_date', [$status]);
    }

    public static function updateStatus(int $dutyID, string $newStatus): bool
    {
        return Database::execute('UPDATE welfare_duty SET status = ? WHERE duty_id = ?', [$newStatus, $dutyID]) >= 0;
    }

    /** Deletes a week that has not started (with its days). */
    public static function delete(int $dutyID): bool
    {
        Database::execute('DELETE FROM task WHERE duty_id = ?', [$dutyID]);
        return Database::execute('DELETE FROM welfare_duty WHERE duty_id = ?', [$dutyID]) > 0;
    }
}
