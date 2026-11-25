<?php
// ============================================================
// SDD_CLASS_307 - VehicleReservation (Data Access Layer, SDD_PKG_300)
// Reads and writes vehicle reservations and counts the units left.
// A reservation holds one unit of a vehicle type for a request, from the departure time until one hour after the
// return time. The Administrative Assistant chooses the actual vehicle later (setVehicle).
// ============================================================

class VehicleReservation
{
    public const ACTIVE = 'Active';
    public const RELEASED = 'Released';

    /**
     * Active vehicles of the type minus the overlapping active reservations (SDD 5.29.2.1). Vehicles of the type used
     * by an internal task or welfare duty in the period are taken out as well, because those tasks also hold a vehicle.
     */
    public static function countUnitsLeft(string $type, string $start, string $end, int $exceptRequestID = 0): int
    {
        $total = Vehicle::countActive($type);
        $used = (int)Database::value("SELECT COUNT(*) FROM vehicle_reservation WHERE vehicle_type = ? AND status = 'Active' AND is_vendor = 0
                                        AND request_id <> ? AND start_datetime < ? AND end_datetime > ?", [$type, $exceptRequestID, $end, $start]);
        $tasks = (int)Database::value("SELECT COUNT(DISTINCT t.vehicle_id) FROM task t JOIN vehicle v ON v.vehicle_id = t.vehicle_id
                                         WHERE v.type = ? AND v.status = 'Active' AND t.task_source IN ('Internal', 'Welfare Duty') AND t.status <> 'Cancelled'
                                           AND t.start_datetime < ? AND t.end_datetime > ?", [$type, $end, $start]);
        return max($total - $used - $tasks, 0);
    }

    /**
     * Creates up to $quantity reservations inside the open transaction and returns the number reserved (SDD 5.29.2.2).
     * The vehicle rows of the type are locked first, so two users who submit at the same time cannot both get the last unit.
     */
    public static function reserve(int $requestID, string $type, int $quantity, string $start, string $end): int
    {
        Database::query("SELECT vehicle_id FROM vehicle WHERE type = ? FOR UPDATE", [$type]);
        Database::query("SELECT reservation_id FROM vehicle_reservation WHERE vehicle_type = ? AND status = 'Active' AND start_datetime < ? AND end_datetime > ? FOR UPDATE", [$type, $end, $start]);
        $n = min($quantity, self::countUnitsLeft($type, $start, $end));
        for ($i = 0; $i < $n; $i++) {
            Database::execute("INSERT INTO vehicle_reservation (request_id, vehicle_type, is_vendor, start_datetime, end_datetime, status) VALUES (?, ?, 0, ?, ?, 'Active')", [$requestID, $type, $start, $end]);
        }
        return $n;
    }

    /** Reserves bus units that must be hired from a vendor (Vendor Bus Needed). */
    public static function reserveVendor(int $requestID, string $type, int $quantity, string $start, string $end): void
    {
        for ($i = 0; $i < $quantity; $i++) {
            Database::execute("INSERT INTO vehicle_reservation (request_id, vehicle_type, is_vendor, start_datetime, end_datetime, status) VALUES (?, ?, 1, ?, ?, 'Active')", [$requestID, $type, $start, $end]);
        }
    }

    /** Sets all reservations of the request to Released. */
    public static function release(int $requestID): void
    {
        Database::execute("UPDATE vehicle_reservation SET status = 'Released' WHERE request_id = ?", [$requestID]);
    }

    /** Stores the vehicle chosen by the Administrative Assistant. */
    public static function setVehicle(int $reservationID, ?int $vehicleID): bool
    {
        Database::execute('UPDATE vehicle_reservation SET vehicle_id = ? WHERE reservation_id = ?', [$vehicleID, $reservationID]);
        return true;
    }

    public static function findByID(int $reservationID): ?array
    {
        return Database::queryOne('SELECT r.*, v.plate_no, v.brand, v.model, v.licence_required, v.capacity FROM vehicle_reservation r LEFT JOIN vehicle v ON v.vehicle_id = r.vehicle_id WHERE r.reservation_id = ?', [$reservationID]);
    }

    /** The reservations of a request (with the chosen vehicle), vendor units last. */
    public static function findByRequest(int $requestID): array
    {
        return Database::query('SELECT r.*, v.plate_no, v.brand, v.model, v.licence_required, v.capacity FROM vehicle_reservation r LEFT JOIN vehicle v ON v.vehicle_id = r.vehicle_id
                                WHERE r.request_id = ? ORDER BY r.is_vendor, r.reservation_id', [$requestID]);
    }

    /** Active reservations that hold this vehicle and have not ended. */
    public static function findUpcomingByVehicle(int $vehicleID): array
    {
        return Database::query("SELECT r.*, q.destination, q.status AS request_status, u.name AS requester_name FROM vehicle_reservation r
                                  JOIN request q ON q.request_id = r.request_id JOIN `user` u ON u.user_id = q.requester_id
                                 WHERE r.vehicle_id = ? AND r.status = 'Active' AND r.end_datetime > NOW() ORDER BY r.start_datetime", [$vehicleID]);
    }

    /** Active reservations of a type (any vehicle or none) that have not ended. */
    public static function findUpcomingByType(string $type): array
    {
        return Database::query("SELECT * FROM vehicle_reservation WHERE vehicle_type = ? AND status = 'Active' AND is_vendor = 0 AND end_datetime > NOW() ORDER BY start_datetime", [$type]);
    }
}
