<?php
// ============================================================
// SDD_CLASS_303 - Driver (Data Access Layer, SDD_PKG_300)
// Reads and writes driver details (table `driver`) and licence classes (table `driver_licence`).
// A driver is a User with the Driver role: driver_id = user_id.
// ============================================================

class Driver
{
    public const LICENCE_CLASSES = ['D' => 'Class D (car, MPV, van)', 'GDL' => 'GDL (lorry)', 'PSV' => 'PSV (bus)'];

    private const SELECT = "SELECT dr.*, u.name, u.staff_id, u.phone_no, u.email, u.status,
                                   (SELECT GROUP_CONCAT(l.licence_class ORDER BY FIELD(l.licence_class, 'D', 'GDL', 'PSV')) FROM driver_licence l WHERE l.driver_id = dr.driver_id) AS licence_classes
                              FROM driver dr JOIN `user` u ON u.user_id = dr.driver_id";

    /** Inserts the driver and licence rows. */
    public static function create(int $userID, array $licenceClasses, string $licenceExpiry, string $startDate): bool
    {
        Database::execute('INSERT INTO driver (driver_id, licence_expiry, start_date) VALUES (?, ?, ?)', [$userID, $licenceExpiry, $startDate]);
        self::saveLicences($userID, $licenceClasses);
        return true;
    }

    /** Saves changes to the licence details and the start date of service. */
    public static function update(int $driverID, array $licenceClasses, string $licenceExpiry, string $startDate): bool
    {
        Database::execute('UPDATE driver SET licence_expiry = ?, start_date = ? WHERE driver_id = ?', [$licenceExpiry, $startDate, $driverID]);
        self::saveLicences($driverID, $licenceClasses);
        return true;
    }

    private static function saveLicences(int $driverID, array $classes): void
    {
        Database::execute('DELETE FROM driver_licence WHERE driver_id = ?', [$driverID]);
        foreach (array_unique($classes) as $c) {
            if (isset(self::LICENCE_CLASSES[$c])) { Database::execute('INSERT INTO driver_licence (driver_id, licence_class) VALUES (?, ?)', [$driverID, $c]); }
        }
    }

    public static function findByID(int $driverID): ?array
    {
        return Database::queryOne(self::SELECT . ' WHERE dr.driver_id = ?', [$driverID]);
    }

    /** Active drivers. */
    public static function findActive(): array
    {
        return Database::query(self::SELECT . " WHERE u.status = 'Active' ORDER BY u.name");
    }

    /** Every driver (also inactive), for filters and reports. */
    public static function findAll(): array
    {
        return Database::query(self::SELECT . ' ORDER BY u.name');
    }

    /** Does the driver hold the licence class, and is the licence still valid on $onDate? */
    public static function hasLicence(array $driver, string $licenceClass, ?string $onDate = null): bool
    {
        $held = explode(',', (string)($driver['licence_classes'] ?? ''));
        return in_array($licenceClass, $held, true) && $driver['licence_expiry'] >= ($onDate ?? date('Y-m-d'));
    }

    /**
     * Is the driver free in [start, end)? No overlapping request or internal task that is not Cancelled or Rejected,
     * and no approved schedule change on those dates (SDD 5.25.2.1).
     */
    public static function isAvailable(int $driverID, string $start, string $end, array $exceptTaskIDs = [], int $exceptRequestID = 0): bool
    {
        return !Task::findOverlapping($driverID, $start, $end, $exceptTaskIDs, $exceptRequestID)
            && !ScheduleChange::isApprovedOn($driverID, substr($start, 0, 10), date('Y-m-d', strtotime($end) - 1));
    }

    /** Tasks this month: request tasks, internal tasks and welfare duty (SRS UC009 1.3). */
    public static function countTasks(int $driverID, string $month): int
    {
        return (int)(self::monthCounts($month)[$driverID]['tasks'] ?? 0);
    }

    /** Tasks this month that start on a Saturday or Sunday. */
    public static function countWeekendTasks(int $driverID, string $month): int
    {
        return (int)(self::monthCounts($month)[$driverID]['weekend'] ?? 0);
    }

    /** Years since the start date of service. */
    public static function getYearsOfExperience(array $driver, ?string $onDate = null): float
    {
        if (empty($driver['start_date'])) { return 0.0; }
        return max(0.0, round((strtotime($onDate ?? date('Y-m-d')) - strtotime($driver['start_date'])) / (365.25 * 86400), 1));
    }

    /** Is the driver on welfare duty during [start, end)? */
    public static function isOnWelfareDuty(int $driverID, string $start, string $end): bool
    {
        return (bool)Database::value("SELECT 1 FROM task WHERE driver_id = ? AND task_source = 'Welfare Duty' AND status IN ('Driver Assigned', 'Approved', 'Completed')
                                       AND start_datetime < ? AND end_datetime > ? LIMIT 1", [$driverID, $end, $start]);
    }

    /**
     * Counts of every driver for a month (Y-m): request, internal, welfare, weekend, tasks (total) and hours.
     * Tasks that are Driver Assigned, Approved or Completed are counted.
     */
    public static function monthCounts(string $month): array
    {
        static $cache = [];
        if (isset($cache[$month])) { return $cache[$month]; }
        $from = $month . '-01 00:00:00';
        $to = date('Y-m-d 00:00:00', strtotime($month . '-01 +1 month'));
        $out = [];
        foreach (Database::query("SELECT driver_id, task_source, COUNT(*) AS n, SUM(DAYOFWEEK(start_datetime) IN (1, 7)) AS wk,
                                         SUM(TIMESTAMPDIFF(MINUTE, start_datetime, end_datetime)) AS mins
                                    FROM task WHERE driver_id IS NOT NULL AND status IN ('Driver Assigned', 'Approved', 'Completed')
                                     AND start_datetime >= ? AND start_datetime < ? GROUP BY driver_id, task_source", [$from, $to]) as $r) {
            $d = (int)$r['driver_id'];
            $out[$d] ??= ['request' => 0, 'internal' => 0, 'welfare' => 0, 'weekend' => 0, 'tasks' => 0, 'hours' => 0.0];
            $key = ['Request' => 'request', 'Internal' => 'internal', 'Welfare Duty' => 'welfare'][$r['task_source']];
            $out[$d][$key] += (int)$r['n'];
            $out[$d]['weekend'] += (int)$r['wk'];
            $out[$d]['tasks'] += (int)$r['n'];
            $out[$d]['hours'] += round((int)$r['mins'] / 60, 1);
        }
        return $cache[$month] = $out;
    }
}
