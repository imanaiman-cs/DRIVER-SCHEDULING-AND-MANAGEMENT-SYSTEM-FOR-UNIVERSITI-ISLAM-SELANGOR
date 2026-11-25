<?php
// ============================================================
// SDD_CLASS_305 - Vehicle (Data Access Layer, SDD_PKG_300)
// Reads and writes vehicles. A vehicle is never deleted: it is deactivated.
// ============================================================

class Vehicle
{
    public const TYPES = ['Car', 'MPV', 'Van', 'Bus', 'Lorry'];
    public const FUEL_TYPES = ['Petrol', 'Diesel'];
    public const FUEL_LEVELS = ['Full', '3/4', '1/2', '1/4', 'Empty'];
    public const ACTIVE = 'Active';
    public const MAINTENANCE = 'Under Maintenance';
    public const INACTIVE = 'Inactive';

    /** Vehicles that match the filter (type, status). */
    public static function findAll(array $filter = []): array
    {
        $w = []; $p = [];
        if (!empty($filter['type'])) { $w[] = 'type = ?'; $p[] = $filter['type']; }
        if (!empty($filter['status'])) { $w[] = 'status = ?'; $p[] = $filter['status']; }
        return Database::query('SELECT * FROM vehicle' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY FIELD(status, 'Active', 'Under Maintenance', 'Inactive'), FIELD(type, 'Car', 'MPV', 'Van', 'Bus', 'Lorry'), plate_no", $p);
    }

    public static function findByID(int $vehicleID, bool $forUpdate = false): ?array
    {
        return Database::queryOne('SELECT * FROM vehicle WHERE vehicle_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$vehicleID]);
    }

    public static function findByPlate(string $plateNo): ?array
    {
        return Database::queryOne('SELECT * FROM vehicle WHERE plate_no = ?', [$plateNo]);
    }

    /** Inserts the vehicle (Active) and returns its ID. */
    public static function create(array $d): int
    {
        Database::execute("INSERT INTO vehicle (plate_no, type, brand, model, fuel_type, capacity, licence_required, current_mileage, fuel_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')",
            [$d['plate_no'], $d['type'], $d['brand'], $d['model'], $d['fuel_type'], (int)$d['capacity'], $d['licence_required'], (int)$d['current_mileage'], $d['fuel_level']]);
        return Database::lastInsertId();
    }

    /** Saves the current fuel level, current mileage and status. */
    public static function update(int $vehicleID, array $fields): bool
    {
        $set = []; $p = [];
        foreach (['current_mileage', 'fuel_level', 'status'] as $k) {
            if (array_key_exists($k, $fields)) { $set[] = "$k = ?"; $p[] = $fields[$k]; }
        }
        if (!$set) { return false; }
        $p[] = $vehicleID;
        Database::execute('UPDATE vehicle SET ' . implode(', ', $set) . ' WHERE vehicle_id = ?', $p);
        return true;
    }

    /** Sets the status to Inactive. */
    public static function deactivate(int $vehicleID): bool
    {
        return Database::execute("UPDATE vehicle SET status = 'Inactive' WHERE vehicle_id = ?", [$vehicleID]) > 0;
    }

    /** Counts active vehicles of a type. */
    public static function countActive(string $type): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM vehicle WHERE type = ? AND status = 'Active'", [$type]);
    }

    /** Updates mileage, fuel level and status after a task report (a vehicle that cannot be used goes Under Maintenance). */
    public static function updateFromReport(int $vehicleID, int $mileage, string $fuelLevel, bool $canBeUsed): void
    {
        Database::execute('UPDATE vehicle SET current_mileage = ?, fuel_level = ? WHERE vehicle_id = ?', [$mileage, $fuelLevel, $vehicleID]);
        if (!$canBeUsed) { Database::execute("UPDATE vehicle SET status = 'Under Maintenance' WHERE vehicle_id = ? AND status = 'Active'", [$vehicleID]); }
    }

    /**
     * Active vehicles (of a type, or of any type) that are free in [start, end): not chosen for another active reservation
     * and not used by an internal task or welfare duty in that period.
     */
    public static function findFree(?string $type, string $start, string $end, int $exceptReservationID = 0, int $exceptTaskID = 0): array
    {
        $p = [];
        $sql = "SELECT v.* FROM vehicle v WHERE v.status = 'Active'";
        if ($type) { $sql .= ' AND v.type = ?'; $p[] = $type; }
        $sql .= " AND NOT EXISTS (SELECT 1 FROM vehicle_reservation r WHERE r.vehicle_id = v.vehicle_id AND r.status = 'Active' AND r.reservation_id <> ?
                                     AND r.start_datetime < ? AND r.end_datetime > ?)
                  AND NOT EXISTS (SELECT 1 FROM task t WHERE t.vehicle_id = v.vehicle_id AND t.task_source IN ('Internal', 'Welfare Duty')
                                     AND t.status <> 'Cancelled' AND t.task_id <> ? AND t.start_datetime < ? AND t.end_datetime > ?)
                  ORDER BY FIELD(v.type, 'Car', 'MPV', 'Van', 'Bus', 'Lorry'), v.capacity, v.plate_no";
        array_push($p, $exceptReservationID, $end, $start, $exceptTaskID, $end, $start);
        return Database::query($sql, $p);
    }
}
