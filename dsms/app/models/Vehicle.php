<?php
/** Vehicle - fleet records and maintenance periods. Vehicles are never hard-deleted (requests refer to them). */
class Vehicle extends Model
{
    public const TYPES = ['Sedan', 'MPV', 'Van', 'Bus', 'Lorry'];

    /**
     * Which licence a driver needs is a property of the vehicle TYPE, so it is derived
     * instead of typed in by hand (one less thing for an admin to get wrong).
     */
    public static function licenceForType(string $type): string
    {
        return ['Bus' => 'PSV', 'Lorry' => 'GDL'][$type] ?? 'D';
    }

    public function all(): array
    {
        return $this->fetchAll(
            "SELECT v.*,
                    (SELECT COUNT(*) FROM vehicle_maintenance m
                      WHERE m.vehicle_id = v.id AND CURDATE() BETWEEN m.start_date AND m.end_date) AS in_maintenance
               FROM vehicles v ORDER BY v.status, v.vehicle_type, v.reg_no"
        );
    }

    public function find(int $id): ?array { return $this->fetchOne('SELECT * FROM vehicles WHERE id = ?', [$id]); }

    public function regTaken(string $reg, int $exceptId = 0): bool
    {
        return (bool)$this->fetchOne('SELECT id FROM vehicles WHERE reg_no = ? AND id <> ?', [$reg, $exceptId]);
    }

    public function save(int $id, array $d): int
    {
        $args = [$d['reg_no'], $d['brand'], $d['model'], $d['vehicle_type'], $d['capacity'],
                 self::licenceForType($d['vehicle_type']), $d['road_tax_expiry'], $d['status']];
        if ($id) {
            $this->execute('UPDATE vehicles SET reg_no=?, brand=?, model=?, vehicle_type=?, capacity=?, required_licence=?, road_tax_expiry=?, status=? WHERE id=?', [...$args, $id]);
            return $id;
        }
        $this->execute('INSERT INTO vehicles (reg_no, brand, model, vehicle_type, capacity, required_licence, road_tax_expiry, status) VALUES (?,?,?,?,?,?,?,?)', $args);
        return (int)$this->db->lastInsertId();
    }

    public function maintenance(int $vehicleId): array
    {
        return $this->fetchAll('SELECT * FROM vehicle_maintenance WHERE vehicle_id = ? ORDER BY start_date DESC', [$vehicleId]);
    }

    public function addMaintenance(int $vehicleId, string $start, string $end, ?string $workshop, ?string $note): void
    {
        $this->execute(
            'INSERT INTO vehicle_maintenance (vehicle_id, start_date, end_date, workshop, note) VALUES (?, ?, ?, ?, ?)',
            [$vehicleId, $start, $end, $workshop, $note]
        );
    }

    public function deleteMaintenance(int $id, int $vehicleId): void
    {
        $this->execute('DELETE FROM vehicle_maintenance WHERE id = ? AND vehicle_id = ?', [$id, $vehicleId]);
    }

    /** Requests that still need this vehicle during a period (used for the maintenance / inactive warning). */
    public function affectedRequests(int $vehicleId, string $start, string $end): array
    {
        return $this->fetchAll(
            "SELECT r.id, r.destination_name, r.status, r.departure_datetime, r.return_datetime, u.name AS requester
               FROM requests r JOIN users u ON u.id = r.requester_id
              WHERE r.vehicle_id = ? AND r.status IN ('Pending Approval','Approved','Driver Assigned','Confirmed')
                AND DATE(r.departure_datetime) <= ? AND DATE(r.return_datetime) >= ?
              ORDER BY r.departure_datetime",
            [$vehicleId, $end, $start]
        );
    }
}
