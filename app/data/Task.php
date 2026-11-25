<?php
// ============================================================
// SDD_CLASS_309 - Task (Data Access Layer, SDD_PKG_300)
// Reads and writes tasks from requests (outbound and return trips), internal tasks and welfare duty.
// ============================================================

class Task
{
    public const REQUEST = 'Request';
    public const INTERNAL = 'Internal';
    public const WELFARE = 'Welfare Duty';
    public const INTERNAL_TYPES = ['Workshop', 'Refuelling', 'Test Drive', 'Other'];

    public const WAITING = 'Waiting for Driver';
    public const DRIVER_ASSIGNED = 'Driver Assigned';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const COMPLETED = 'Completed';
    public const CANCELLED = 'Cancelled';
    /** Statuses that keep a driver busy and count in the driver's workload. */
    public const BUSY = [self::DRIVER_ASSIGNED, self::APPROVED, self::COMPLETED];

    private const COLS = "t.*, v.plate_no, v.brand, v.model, v.type AS vehicle_type, v.licence_required, v.current_mileage, v.fuel_level AS vehicle_fuel_level,
                                  du.name AS driver_name, du.phone_no AS driver_phone,
                                  r.destination, r.address, r.waiting_location, r.purpose, r.no_of_passengers, r.requester_id, r.status AS request_status,
                                  ru.name AS officer_name, ru.phone_no AS officer_phone, w.week_start_date, w.status AS duty_status, du.status AS driver_status";
    private const JOINS = "FROM task t
                             LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
                             LEFT JOIN `user` du ON du.user_id = t.driver_id
                             LEFT JOIN request r ON r.request_id = t.request_id LEFT JOIN `user` ru ON ru.user_id = r.requester_id
                             LEFT JOIN welfare_duty w ON w.duty_id = t.duty_id";
    public const SELECT = 'SELECT ' . self::COLS . ' ' . self::JOINS;

    /** Inserts the task and returns its ID. */
    public static function create(array $d): int
    {
        Database::execute('INSERT INTO task (task_source, task_type, trip_type, request_id, duty_id, vehicle_id, driver_id, vendor_name, vendor_plate_no,
                             start_datetime, end_datetime, description, status, assigned_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['task_source'], $d['task_type'] ?? null, $d['trip_type'] ?? null, $d['request_id'] ?? null, $d['duty_id'] ?? null, $d['vehicle_id'] ?? null,
             $d['driver_id'] ?? null, $d['vendor_name'] ?? null, $d['vendor_plate_no'] ?? null, $d['start_datetime'], $d['end_datetime'],
             $d['description'] ?? null, $d['status'] ?? self::WAITING, $d['assigned_by'] ?? null]);
        return Database::lastInsertId();
    }

    public static function findByID(int $taskID, bool $forUpdate = false): ?array
    {
        return Database::queryOne(self::SELECT . ' WHERE t.task_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$taskID]);
    }

    /** Tasks waiting for a driver (Waiting for Driver, or returned by the Head of Admin) of one source. */
    public static function findWaiting(string $source): array
    {
        return Database::query(self::SELECT . " WHERE t.task_source = ? AND t.status IN ('Waiting for Driver', 'Rejected') AND t.start_datetime > NOW() ORDER BY t.start_datetime", [$source]);
    }

    /** Tasks of one source with the Driver Assigned status (waiting for the Head of Admin). */
    public static function findAssigned(string $source): array
    {
        return Database::query(self::SELECT . " WHERE t.task_source = ? AND t.status = 'Driver Assigned' ORDER BY t.start_datetime", [$source]);
    }

    /** Tasks of a driver in a period (by start), with the given statuses. */
    public static function findByDriver(int $driverID, ?string $from = null, ?string $to = null, array $statuses = self::BUSY, string $order = 'ASC'): array
    {
        $w = ['t.driver_id = ?', 't.status IN (' . Database::placeholders($statuses) . ')']; $p = array_merge([$driverID], $statuses);
        if ($from) { $w[] = 't.end_datetime > ?'; $p[] = $from; }
        if ($to) { $w[] = 't.start_datetime < ?'; $p[] = $to; }
        return Database::query(self::SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY t.start_datetime ' . ($order === 'DESC' ? 'DESC' : 'ASC'), $p);
    }

    /** All tasks (not cancelled or rejected) in a period, for the schedule. Filter: driver_id, vehicle_id. */
    public static function findByWeek(string $start, string $end, array $filter = []): array
    {
        $w = ["t.status NOT IN ('Cancelled', 'Rejected')", 't.start_datetime < ?', 't.end_datetime > ?', 't.vendor_name IS NULL']; $p = [$end, $start];
        if (!empty($filter['driver_id'])) { $w[] = 't.driver_id = ?'; $p[] = (int)$filter['driver_id']; }
        if (!empty($filter['vehicle_id'])) { $w[] = 't.vehicle_id = ?'; $p[] = (int)$filter['vehicle_id']; }
        return Database::query(self::SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY t.start_datetime', $p);
    }

    /** The current tasks of a request (rejected and cancelled ones are kept as records but left out). */
    public static function findByRequest(int $requestID, bool $all = false): array
    {
        return Database::query(self::SELECT . ' WHERE t.request_id = ?' . ($all ? '' : " AND t.status NOT IN ('Rejected', 'Cancelled')") . ' ORDER BY t.vehicle_id IS NULL, t.vehicle_id, t.start_datetime', [$requestID]);
    }

    /** The days of a welfare duty week. */
    public static function findByDuty(int $dutyID): array
    {
        return Database::query(self::SELECT . " WHERE t.duty_id = ? AND t.status <> 'Cancelled' ORDER BY t.start_datetime", [$dutyID]);
    }

    /** Internal tasks with a filter: date, task_type, status. */
    public static function findInternal(array $filter = []): array
    {
        $w = ["t.task_source = 'Internal'"]; $p = [];
        if (!empty($filter['date'])) { $w[] = 'DATE(t.start_datetime) = ?'; $p[] = $filter['date']; }
        if (!empty($filter['task_type'])) { $w[] = 't.task_type = ?'; $p[] = $filter['task_type']; }
        if (!empty($filter['status'])) { $w[] = 't.status = ?'; $p[] = $filter['status']; }
        else { $w[] = "t.status <> 'Cancelled'"; }
        if (empty($filter['date']) && empty($filter['status'])) { $w[] = "(t.end_datetime >= DATE_SUB(NOW(), INTERVAL 30 DAY) OR t.status IN ('Waiting for Driver', 'Driver Assigned', 'Rejected'))"; }
        return Database::query(self::SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY t.start_datetime DESC LIMIT 200', $p);
    }

    /** Sets the driver and vehicle and the Driver Assigned status. */
    public static function assignDriver(int $taskID, int $driverID, ?int $vehicleID, int $userID): bool
    {
        Database::execute("UPDATE task SET driver_id = ?, vehicle_id = COALESCE(?, vehicle_id), status = 'Driver Assigned', assigned_by = ?, reject_reason = NULL, rejected_date = NULL WHERE task_id = ?",
            [$driverID, $vehicleID, $userID, $taskID]);
        return true;
    }

    /** Approves, rejects, completes or cancels the task. */
    public static function updateStatus(int $taskID, string $newStatus, ?int $userID = null, ?string $reason = null): bool
    {
        if ($newStatus === self::APPROVED) {
            Database::execute("UPDATE task SET status = 'Approved', approved_by = ?, approved_date = NOW() WHERE task_id = ?", [$userID, $taskID]);
        } elseif ($newStatus === self::REJECTED) {
            Database::execute("UPDATE task SET status = 'Rejected', approved_by = ?, reject_reason = ?, rejected_date = NOW() WHERE task_id = ?", [$userID, $reason !== null ? mb_substr($reason, 0, 255) : null, $taskID]);
        } else {
            Database::execute('UPDATE task SET status = ? WHERE task_id = ?', [$newStatus, $taskID]);
        }
        return true;
    }

    /** Saves other columns (vehicle, driver, times, description, vendor, type). */
    public static function update(int $taskID, array $fields): bool
    {
        $allowed = ['task_type', 'vehicle_id', 'driver_id', 'start_datetime', 'end_datetime', 'description', 'vendor_name', 'vendor_plate_no', 'status', 'approved_by', 'approved_date'];
        $set = []; $p = [];
        foreach ($fields as $k => $v) { if (in_array($k, $allowed, true)) { $set[] = "`$k` = ?"; $p[] = $v; } }
        if (!$set) { return false; }
        $p[] = $taskID;
        Database::execute('UPDATE task SET ' . implode(', ', $set) . ' WHERE task_id = ?', $p);
        return true;
    }

    /**
     * Request tasks and internal tasks of the driver that overlap [start, end) and are not Cancelled or Rejected.
     * Welfare duty does not block a driver (the driver is only marked, SRS UC009).
     */
    public static function findOverlapping(int $driverID, string $start, string $end, array $exceptTaskIDs = [], int $exceptRequestID = 0): array
    {
        $ex = $exceptTaskIDs ?: [0];
        return Database::query(self::SELECT . " WHERE t.driver_id = ? AND t.task_source IN ('Request', 'Internal') AND t.status IN ('Driver Assigned', 'Approved', 'Completed')
                                 AND t.task_id NOT IN (" . Database::placeholders($ex) . ") AND NOT (t.request_id IS NOT NULL AND t.request_id = ?)
                                 AND t.start_datetime < ? AND t.end_datetime > ? ORDER BY t.start_datetime",
            array_merge([$driverID], $ex, [$exceptRequestID, $end, $start]));
    }

    /** Internal tasks and welfare duty days that hold the vehicle and have not ended. */
    public static function findUpcomingByVehicle(int $vehicleID): array
    {
        return Database::query(self::SELECT . " WHERE t.vehicle_id = ? AND t.task_source IN ('Internal', 'Welfare Duty') AND t.status IN ('Waiting for Driver', 'Driver Assigned', 'Approved', 'Rejected')
                                 AND t.end_datetime > NOW() ORDER BY t.start_datetime", [$vehicleID]);
    }

    /**
     * Approved tasks that need a new driver: the driver has an approved schedule change on that date or asked to be
     * removed from the task, or the driver is no longer active. Tasks with a replacement waiting are left out.
     */
    public static function findNeedingReplacement(): array
    {
        $sc = "FROM schedule_change sc WHERE sc.driver_id = t.driver_id AND sc.status = 'Approved'
                 AND (sc.task_id = t.task_id OR (sc.start_date <= DATE(DATE_SUB(t.end_datetime, INTERVAL 1 SECOND)) AND sc.end_date >= DATE(t.start_datetime)))";
        return Database::query('SELECT ' . self::COLS . ", (SELECT sc.reason $sc ORDER BY sc.change_id DESC LIMIT 1) AS change_reason " . self::JOINS . "
                                 WHERE t.status = 'Approved' AND t.start_datetime > NOW() AND t.driver_id IS NOT NULL
                                   AND (EXISTS (SELECT 1 $sc) OR du.status <> 'Active')
                                   AND NOT EXISTS (SELECT 1 FROM driver_replacement rp WHERE rp.task_id = t.task_id AND rp.status = 'Pending') ORDER BY t.start_datetime");
    }
}
