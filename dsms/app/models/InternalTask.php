<?php
/** InternalTask - Vehicle Section jobs that are not passenger trips (refuel, workshop, document delivery...). They count as driver workload. */
class InternalTask extends Model
{
    public function between(string $from, string $to): array
    {
        return $this->fetchAll(
            "SELECT t.*, u.name AS driver_name, v.reg_no, cb.name AS created_by_name
               FROM internal_tasks t
               JOIN drivers d ON d.id = t.driver_id JOIN users u ON u.id = d.user_id
               LEFT JOIN vehicles v ON v.id = t.vehicle_id
               JOIN users cb ON cb.id = t.created_by
              WHERE t.task_date BETWEEN ? AND ? ORDER BY t.task_date, t.start_time",
            [$from, $to]
        );
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM internal_tasks WHERE id = ?', [$id]);
    }

    public function create(array $d, int $createdBy): int
    {
        $this->execute(
            'INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, vehicle_id, driver_id, location, description, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
            [$d['task_type'], $d['task_date'], $d['start_time'], $d['end_time'], $d['vehicle_id'], $d['driver_id'], $d['location'], $d['description'], $createdBy]
        );
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM internal_tasks WHERE id = ?', [$id]);
    }

    /** Active carpool drivers for the drop-down (management drivers are not used for internal tasks either). */
    public function drivers(): array
    {
        return $this->fetchAll("SELECT d.id, u.name FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.is_active = 1 AND u.is_active = 1 AND d.driver_group = 'carpool' ORDER BY u.name");
    }

    public function vehicles(): array
    {
        return $this->fetchAll("SELECT id, reg_no, brand, model FROM vehicles ORDER BY reg_no");
    }

    /** Requests that hold this vehicle on the day (a warning only: an internal task is usually short). */
    public function vehicleHolds(int $vehicleId, string $day): array
    {
        return $this->fetchAll(
            'SELECT h.request_id, r.destination_name FROM vehicle_day_holds h JOIN requests r ON r.id = h.request_id WHERE h.vehicle_id = ? AND h.hold_date = ?',
            [$vehicleId, $day]
        );
    }
}
