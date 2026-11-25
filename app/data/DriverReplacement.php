<?php
// ============================================================
// SDD_CLASS_313 - DriverReplacement (Data Access Layer, SDD_PKG_300)
// Reads and writes driver replacements. Every replacement is kept for the Driver Replacement report.
// ============================================================

class DriverReplacement
{
    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';

    private const SELECT = 'SELECT rp.*, ou.name AS old_driver_name, nu.name AS new_driver_name, au.name AS approved_by_name
                              FROM driver_replacement rp JOIN `user` ou ON ou.user_id = rp.old_driver_id JOIN `user` nu ON nu.user_id = rp.new_driver_id
                              LEFT JOIN `user` au ON au.user_id = rp.approved_by';

    /** Inserts the replacement (Pending) and returns its ID. */
    public static function create(int $taskID, int $oldDriverID, int $newDriverID, string $reason): int
    {
        Database::execute("INSERT INTO driver_replacement (task_id, old_driver_id, new_driver_id, reason, status) VALUES (?, ?, ?, ?, 'Pending')",
            [$taskID, $oldDriverID, $newDriverID, mb_substr($reason, 0, 255)]);
        return Database::lastInsertId();
    }

    public static function findByID(int $replacementID): ?array
    {
        return Database::queryOne(self::SELECT . ' WHERE rp.replacement_id = ?', [$replacementID]);
    }

    public static function findPending(): array
    {
        return Database::query(self::SELECT . " WHERE rp.status = 'Pending' ORDER BY rp.date_time");
    }

    public static function findPendingByTask(int $taskID): ?array
    {
        return Database::queryOne(self::SELECT . " WHERE rp.task_id = ? AND rp.status = 'Pending'", [$taskID]);
    }

    public static function findByTask(int $taskID): array
    {
        return Database::query(self::SELECT . ' WHERE rp.task_id = ? ORDER BY rp.date_time DESC', [$taskID]);
    }

    /** Approves or rejects the replacement. */
    public static function updateStatus(int $replacementID, string $newStatus, int $userID): bool
    {
        return Database::execute("UPDATE driver_replacement SET status = ?, approved_by = ? WHERE replacement_id = ? AND status = 'Pending'", [$newStatus, $userID, $replacementID]) > 0;
    }

    /** Replacements of a month (Y-m), for the Driver Replacement report. */
    public static function findByMonth(string $month): array
    {
        return Database::query('SELECT rp.*, ou.name AS old_driver_name, nu.name AS new_driver_name, t.task_source, t.task_type, t.trip_type, t.request_id, t.start_datetime
                                  FROM driver_replacement rp JOIN `user` ou ON ou.user_id = rp.old_driver_id JOIN `user` nu ON nu.user_id = rp.new_driver_id
                                  JOIN task t ON t.task_id = rp.task_id WHERE rp.date_time >= ? AND rp.date_time < ? ORDER BY rp.date_time',
            [$month . '-01 00:00:00', date('Y-m-d 00:00:00', strtotime($month . '-01 +1 month'))]);
    }
}
