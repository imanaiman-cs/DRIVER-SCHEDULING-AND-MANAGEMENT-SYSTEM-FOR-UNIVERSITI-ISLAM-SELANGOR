<?php
// ============================================================
// SDD_CLASS_311 - TaskReport (Data Access Layer, SDD_PKG_300)
// Reads and writes task reports. A report is never deleted because it updated the vehicle.
// ============================================================

class TaskReport
{
    public const PROBLEM_TYPES = ['Plate Number', 'Tyre', 'Lights', 'Body Damage', 'Engine', 'Others'];

    /** Inserts the report and returns its ID. */
    public static function create(array $d): int
    {
        Database::execute('INSERT INTO task_report (task_id, actual_depart, actual_return, end_mileage, fuel_level, vehicle_condition, problem_type, problem_desc, photo, can_be_used)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['task_id'], $d['actual_depart'], $d['actual_return'], $d['end_mileage'], $d['fuel_level'], $d['vehicle_condition'], $d['problem_type'] ?? null,
             $d['problem_desc'] ?? null, $d['photo'] ?? null, (int)$d['can_be_used']]);
        return Database::lastInsertId();
    }

    public static function findByTask(int $taskID): ?array
    {
        return Database::queryOne('SELECT * FROM task_report WHERE task_id = ?', [$taskID]);
    }

    public static function findByID(int $reportID): ?array
    {
        return Database::queryOne('SELECT * FROM task_report WHERE report_id = ?', [$reportID]);
    }

    /** Saves changes. */
    public static function update(int $reportID, array $d): bool
    {
        Database::execute('UPDATE task_report SET actual_depart = ?, actual_return = ?, end_mileage = ?, fuel_level = ?, vehicle_condition = ?, problem_type = ?, problem_desc = ?, photo = ?, can_be_used = ?
                           WHERE report_id = ?',
            [$d['actual_depart'], $d['actual_return'], $d['end_mileage'], $d['fuel_level'], $d['vehicle_condition'], $d['problem_type'] ?? null,
             $d['problem_desc'] ?? null, $d['photo'] ?? null, (int)$d['can_be_used'], $reportID]);
        return true;
    }

    /** The end mileage of the vehicle's report before this one (the mileage before the trip), or null. */
    public static function previousMileage(int $vehicleID, int $exceptReportID, string $before): ?int
    {
        $v = Database::value('SELECT tr.end_mileage FROM task_report tr JOIN task t ON t.task_id = tr.task_id WHERE t.vehicle_id = ? AND tr.report_id <> ? AND tr.actual_return <= ?
                              ORDER BY tr.actual_return DESC LIMIT 1', [$vehicleID, $exceptReportID, $before]);
        return $v === null ? null : (int)$v;
    }

    /** Is this the vehicle's latest report? */
    public static function isLatestForVehicle(int $reportID, int $vehicleID): bool
    {
        return (int)Database::value('SELECT tr.report_id FROM task_report tr JOIN task t ON t.task_id = tr.task_id WHERE t.vehicle_id = ? ORDER BY tr.actual_return DESC, tr.report_id DESC LIMIT 1', [$vehicleID]) === $reportID;
    }

    /** The latest reports of a vehicle. */
    public static function findByVehicle(int $vehicleID, int $limit = 10): array
    {
        return Database::query('SELECT tr.*, t.task_source, t.task_type, t.trip_type, t.request_id, u.name AS driver_name FROM task_report tr JOIN task t ON t.task_id = tr.task_id
                                LEFT JOIN `user` u ON u.user_id = t.driver_id WHERE t.vehicle_id = ? ORDER BY tr.actual_return DESC LIMIT ' . (int)$limit, [$vehicleID]);
    }

    /** Reports with a vehicle problem in the last days. */
    public static function findRecentProblems(int $days = 14): array
    {
        return Database::query("SELECT tr.*, t.vehicle_id, v.plate_no, u.name AS driver_name FROM task_report tr JOIN task t ON t.task_id = tr.task_id JOIN vehicle v ON v.vehicle_id = t.vehicle_id
                                LEFT JOIN `user` u ON u.user_id = t.driver_id WHERE tr.vehicle_condition = 'Problem' AND tr.submitted_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
                                ORDER BY tr.submitted_date DESC LIMIT 5", [$days]);
    }
}
