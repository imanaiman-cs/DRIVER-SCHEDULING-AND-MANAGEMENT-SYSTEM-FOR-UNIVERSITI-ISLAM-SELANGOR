<?php
// ============================================================
// SDD_CLASS_312 - ScheduleChange (Data Access Layer, SDD_PKG_300)
// Reads and writes schedule change requests of drivers.
// ============================================================

class ScheduleChange
{
    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';

    /** Inserts the change (Pending) and returns its ID. */
    public static function create(array $d): int
    {
        Database::execute("INSERT INTO schedule_change (driver_id, task_id, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, 'Pending')",
            [$d['driver_id'], $d['task_id'] ?? null, $d['start_date'], $d['end_date'], mb_substr($d['reason'], 0, 255)]);
        return Database::lastInsertId();
    }

    private const SELECT = 'SELECT sc.*, u.name AS driver_name, au.name AS approved_by_name FROM schedule_change sc JOIN `user` u ON u.user_id = sc.driver_id
                            LEFT JOIN `user` au ON au.user_id = sc.approved_by';

    public static function findByID(int $changeID): ?array
    {
        return Database::queryOne(self::SELECT . ' WHERE sc.change_id = ?', [$changeID]);
    }

    /** Pending changes. */
    public static function findPending(): array
    {
        return Database::query(self::SELECT . " WHERE sc.status = 'Pending' ORDER BY sc.start_date");
    }

    /** Decided changes (latest first). */
    public static function findDecided(int $limit = 100): array
    {
        return Database::query(self::SELECT . " WHERE sc.status <> 'Pending' ORDER BY sc.decision_date DESC LIMIT " . (int)$limit);
    }

    public static function findByDriver(int $driverID): array
    {
        return Database::query(self::SELECT . ' WHERE sc.driver_id = ? ORDER BY sc.change_id DESC', [$driverID]);
    }

    /** Approves or rejects the change. */
    public static function updateStatus(int $changeID, string $newStatus, ?int $userID, ?string $reason = null): bool
    {
        return Database::execute("UPDATE schedule_change SET status = ?, approved_by = ?, reject_reason = ?, decision_date = NOW() WHERE change_id = ? AND status = 'Pending'",
            [$newStatus, $userID, $reason !== null ? mb_substr($reason, 0, 255) : null, $changeID]) > 0;
    }

    /** Does the driver have an approved change on the date (or on any date of a period)? */
    public static function isApprovedOn(int $driverID, string $date, ?string $endDate = null): bool
    {
        return (bool)Database::value("SELECT 1 FROM schedule_change WHERE driver_id = ? AND status = 'Approved' AND start_date <= ? AND end_date >= ? LIMIT 1",
            [$driverID, $endDate ?? $date, $date]);
    }
}
