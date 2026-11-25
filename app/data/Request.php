<?php
// ============================================================
// SDD_CLASS_306 - Request (Data Access Layer, SDD_PKG_300)
// Reads and writes vehicle requests.
// ============================================================

class Request
{
    public const PENDING = 'Pending';
    public const APPROVED_BY_HOD = 'Approved by HoD';
    public const DRIVER_ASSIGNED = 'Driver Assigned';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const CANCELLED = 'Cancelled';
    public const LAPSED = 'Lapsed';
    public const COMPLETED = 'Completed';
    public const STATUSES = [self::PENDING, self::APPROVED_BY_HOD, self::DRIVER_ASSIGNED, self::APPROVED, self::REJECTED, self::CANCELLED, self::LAPSED, self::COMPLETED];
    /** A request in one of these still holds its reservation and can be cancelled. */
    public const ACTIVE_STATUSES = [self::PENDING, self::APPROVED_BY_HOD, self::DRIVER_ASSIGNED, self::APPROVED];

    public const WITH_DRIVER = 'With Driver';
    public const PAY_AND_CLAIM = 'Pay and Claim';
    public const SELF_DRIVE = 'Self Drive';
    public const OFFICIAL = 'Official';
    public const UNOFFICIAL = 'Unofficial';
    public const PERSONAL = 'Personal';

    /** The SELECT of requests with the requester, department and approver names. */
    public const SELECT = "SELECT r.*, u.name AS requester_name, u.staff_id AS requester_staff_id, u.phone_no AS requester_phone, u.email AS requester_email,
                                  u.role AS requester_role, d.department_name, d.parent_department_id,
                                  hu.name AS hod_name, au.name AS hoa_name, cu.name AS cancelled_by_name
                             FROM request r JOIN `user` u ON u.user_id = r.requester_id JOIN department d ON d.department_id = r.department_id
                             LEFT JOIN `user` hu ON hu.user_id = r.hod_id LEFT JOIN `user` au ON au.user_id = r.hoa_id LEFT JOIN `user` cu ON cu.user_id = r.cancelled_by";

    /** Inserts the request (Pending) and returns its ID. */
    public static function create(array $d): int
    {
        Database::execute("INSERT INTO request (requester_id, department_id, vehicle_type, quantity, driver_option, purpose_type, purpose, destination, address, waiting_location,
                             depart_datetime, return_datetime, no_of_passengers, supporting_doc, distance_km, charge, is_urgent, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')",
            [$d['requester_id'], $d['department_id'], $d['vehicle_type'], $d['quantity'], $d['driver_option'], $d['purpose_type'], $d['purpose'], $d['destination'],
             $d['address'], $d['waiting_location'], $d['depart_datetime'], $d['return_datetime'], $d['no_of_passengers'], $d['supporting_doc'] ?? null,
             $d['distance_km'] ?? null, $d['charge'] ?? null, (int)$d['is_urgent']]);
        return Database::lastInsertId();
    }

    public static function findByID(int $requestID, bool $forUpdate = false): ?array
    {
        return Database::queryOne(self::SELECT . ' WHERE r.request_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$requestID]);
    }

    /** My Request. */
    public static function findByRequester(int $userID, array $filter = []): array
    {
        return self::search('r.requester_id = ?', [$userID], $filter);
    }

    /**
     * Department Request: requests from the department (not the head's own), and requests of the heads of the departments
     * that report to it (they are approved here).
     */
    public static function findByDepartment(int $departmentID, int $headUserID, array $filter = []): array
    {
        [$sql, $p] = self::departmentScope($departmentID, $headUserID);
        return self::search($sql, $p, $filter);
    }

    public static function departmentScope(int $departmentID, int $headUserID): array
    {
        return ["((r.department_id = ? AND r.requester_id <> ?)
                  OR (r.department_id IN (SELECT department_id FROM department WHERE parent_department_id = ?) AND u.role IN ('Head of Department', 'Head of Admin')))",
                [$departmentID, $headUserID, $departmentID]];
    }

    /** All Request. */
    public static function findAll(array $filter = []): array
    {
        return self::search('1 = 1', [], $filter);
    }

    /**
     * Search with the filter: status, from, to (departure date), q (ID, destination or requester), order, limit, offset.
     * Returns ['rows' => [...], 'total' => n].
     */
    public static function search(string $scope, array $params, array $filter = []): array
    {
        $w = [$scope]; $p = $params;
        if (!empty($filter['status'])) { $w[] = 'r.status = ?'; $p[] = $filter['status']; }
        if (!empty($filter['from'])) { $w[] = 'r.depart_datetime >= ?'; $p[] = $filter['from'] . ' 00:00:00'; }
        if (!empty($filter['to'])) { $w[] = 'r.depart_datetime <= ?'; $p[] = $filter['to'] . ' 23:59:59'; }
        if (!empty($filter['q'])) { $w[] = '(r.request_id = ? OR r.destination LIKE ? OR u.name LIKE ?)'; $p[] = (int)ltrim($filter['q'], '#'); $p[] = '%' . $filter['q'] . '%'; $p[] = '%' . $filter['q'] . '%'; }
        if (!empty($filter['where'])) { $w[] = $filter['where']; }
        $where = ' WHERE ' . implode(' AND ', $w);
        $total = (int)Database::value('SELECT COUNT(*) FROM request r JOIN `user` u ON u.user_id = r.requester_id' . $where, $p);
        $order = $filter['order'] ?? 'r.date_submitted DESC, r.request_id DESC';
        $limit = isset($filter['limit']) ? ' LIMIT ' . (int)$filter['limit'] . ' OFFSET ' . (int)($filter['offset'] ?? 0) : '';
        return ['rows' => Database::query(self::SELECT . $where . " ORDER BY $order$limit", $p), 'total' => $total];
    }

    /** Changes the status and writes RequestStatusHistory. $extra: other columns to set at the same time. */
    public static function updateStatus(int $requestID, string $newStatus, ?int $userID, ?string $remark = null, array $extra = []): bool
    {
        $old = Database::value('SELECT status FROM request WHERE request_id = ?', [$requestID]);
        $set = ['status = ?']; $p = [$newStatus];
        foreach ($extra as $k => $v) { $set[] = "`$k` = ?"; $p[] = $v; }
        $p[] = $requestID;
        Database::execute('UPDATE request SET ' . implode(', ', $set) . ' WHERE request_id = ?', $p);
        RequestStatusHistory::record($requestID, $old, $newStatus, $userID, $remark);
        return true;
    }

    /** Saves other columns (distance and charge, reminder flag). */
    public static function update(int $requestID, array $fields): bool
    {
        $allowed = ['distance_km', 'charge', 'reminder_sent', 'supporting_doc'];
        $set = []; $p = [];
        foreach ($fields as $k => $v) { if (in_array($k, $allowed, true)) { $set[] = "`$k` = ?"; $p[] = $v; } }
        if (!$set) { return false; }
        $p[] = $requestID;
        Database::execute('UPDATE request SET ' . implode(', ', $set) . ' WHERE request_id = ?', $p);
        return true;
    }

    /** Pending requests (not Personal) submitted more than $hours hours ago. */
    public static function findPendingOlderThan(int $hours): array
    {
        return Database::query(self::SELECT . " WHERE r.status = 'Pending' AND r.purpose_type <> 'Personal' AND r.date_submitted <= DATE_SUB(NOW(), INTERVAL ? HOUR)", [$hours]);
    }

    /** Requests that are still not approved when their departure time comes. */
    public static function findUnapprovedDeparted(): array
    {
        return Database::query(self::SELECT . " WHERE r.status IN ('Pending', 'Approved by HoD', 'Driver Assigned') AND r.depart_datetime <= NOW()");
    }

    /** Approved requests whose return time has passed. */
    public static function findApprovedReturned(): array
    {
        return Database::query(self::SELECT . " WHERE r.status = 'Approved' AND r.return_datetime <= NOW()");
    }

    /** distanceKm x the charge per km (RM3). */
    public static function calculateCharge(float $distanceKm, float $chargePerKm = 3.0): float
    {
        return round($distanceKm * $chargePerKm, 2);
    }
}
