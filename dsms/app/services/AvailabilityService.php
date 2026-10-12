<?php
/**
 * AvailabilityService - "is it free?" for vehicles (R3, R4) and drivers (R5).
 *
 * VEHICLES are booked per CALENDAR DAY. When a request is submitted we insert one row per
 * day into vehicle_day_holds, and the database itself refuses a second row for the same
 * vehicle and day (UNIQUE vehicle_id + hold_date). So even if two people press "Submit" at
 * the same instant, only one wins - no double booking is possible.
 *
 * DRIVERS are checked per TIME SLOT, because a driver can do a morning leg and an afternoon
 * leg on the same day. A clash is only a WARNING for the admin (R5); a missing licence is a block.
 */
class AvailabilityService extends Model
{
    // Reasons a vehicle cannot be chosen. The wizard shows these on the vehicle cards.
    public const OK          = 'available';
    public const BOOKED      = 'booked';
    public const MAINTENANCE = 'maintenance';
    public const ROAD_TAX    = 'road_tax';
    public const TOO_SMALL   = 'too_small';

    /**
     * Every active vehicle with its state for the period (R4).
     * Two small queries for the whole fleet instead of one per vehicle.
     *
     * @return array<int,array> vehicle rows plus: state, note
     */
    public function vehiclesForPeriod(string $startDate, string $endDate, int $passengers, int $exceptRequestId = 0): array
    {
        $vehicles = $this->fetchAll("SELECT * FROM vehicles WHERE status = 'Available' ORDER BY vehicle_type, capacity, reg_no");

        $booked = [];
        foreach ($this->fetchAll(
            'SELECT vehicle_id, MIN(hold_date) AS first_day FROM vehicle_day_holds
              WHERE hold_date BETWEEN ? AND ? AND request_id <> ? GROUP BY vehicle_id',
            [$startDate, $endDate, $exceptRequestId]
        ) as $row) {
            $booked[(int)$row['vehicle_id']] = $row['first_day'];
        }

        $maintenance = [];
        foreach ($this->fetchAll(
            'SELECT vehicle_id, MIN(start_date) AS s, MAX(end_date) AS e FROM vehicle_maintenance
              WHERE start_date <= ? AND end_date >= ? GROUP BY vehicle_id',
            [$endDate, $startDate]
        ) as $row) {
            $maintenance[(int)$row['vehicle_id']] = $row;
        }

        foreach ($vehicles as &$v) {
            $id = (int)$v['id'];
            if (isset($maintenance[$id])) {
                $v['state'] = self::MAINTENANCE;
                $v['note']  = 'In maintenance ' . fmt_date($maintenance[$id]['s']) . ' - ' . fmt_date($maintenance[$id]['e']);
            } elseif (isset($booked[$id])) {
                $v['state'] = self::BOOKED;
                $v['note']  = 'Already booked on ' . fmt_date($booked[$id]);
            } elseif ($v['road_tax_expiry'] !== null && $v['road_tax_expiry'] < $endDate) {
                $v['state'] = self::ROAD_TAX;
                $v['note']  = 'Road tax expires ' . fmt_date($v['road_tax_expiry']);
            } elseif ((int)$v['capacity'] < $passengers) {
                $v['state'] = self::TOO_SMALL;
                $v['note']  = 'Seats ' . (int)$v['capacity'] . ', you need ' . $passengers;
            } else {
                $v['state'] = self::OK;
                $v['note']  = '';
            }
        }
        unset($v);
        return $vehicles;
    }

    /** Why one vehicle cannot be used for the period, or null when it is free. */
    public function vehicleProblem(int $vehicleId, string $startDate, string $endDate, int $passengers, int $exceptRequestId = 0): ?string
    {
        foreach ($this->vehiclesForPeriod($startDate, $endDate, $passengers, $exceptRequestId) as $v) {
            if ((int)$v['id'] === $vehicleId) {
                return $v['state'] === self::OK ? null : $v['note'];
            }
        }
        return 'This vehicle is not in service.';
    }

    /**
     * R3: reserve the vehicle for every day of the trip. Must run inside the same transaction
     * as the request INSERT. If another request got there first the UNIQUE key fails and we
     * turn that into a friendly AvailabilityConflict.
     */
    public function placeHolds(int $requestId, int $vehicleId, array $days): void
    {
        try {
            foreach ($days as $day) {
                $this->execute('INSERT INTO vehicle_day_holds (vehicle_id, hold_date, request_id) VALUES (?, ?, ?)', [$vehicleId, $day, $requestId]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new AvailabilityConflict('Sorry, that vehicle was just booked by someone else for one of those days. Please choose another vehicle.');
            }
            throw $e;
        }
    }

    public function releaseHolds(int $requestId): void
    {
        $this->execute('DELETE FROM vehicle_day_holds WHERE request_id = ?', [$requestId]);
    }

    // ------------------------------------------------------------------ drivers

    /** Is the driver on leave / MC on any day of the period? Returns the leave row or null. */
    public function driverLeave(int $driverId, string $startDate, string $endDate): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM driver_leaves WHERE driver_id = ? AND start_date <= ? AND end_date >= ? LIMIT 1',
            [$driverId, $endDate, $startDate]
        );
    }

    /**
     * R5: other jobs of this driver that overlap the time slot (trip legs of requests that are
     * Driver Assigned / Confirmed, and internal tasks). Two slots overlap when each starts
     * before the other ends. A slot that only TOUCHES (one ends 10:00, next starts 10:00) is fine.
     *
     * @return string[] short human-readable descriptions
     */
    public function driverClashes(int $driverId, string $start, string $end, int $exceptRequestId = 0): array
    {
        $out = [];
        foreach ($this->fetchAll(
            "SELECT r.id, r.destination_name, l.leg_type, l.start_datetime, l.end_datetime
               FROM request_legs l JOIN requests r ON r.id = l.request_id
              WHERE l.driver_id = ? AND r.id <> ? AND r.status IN ('Driver Assigned','Confirmed')
                AND l.start_datetime < ? AND l.end_datetime > ?",
            [$driverId, $exceptRequestId, $end, $start]
        ) as $row) {
            $out[] = "Request #{$row['id']} ({$row['destination_name']}, {$row['leg_type']} " . fmt_datetime($row['start_datetime']) . ' - ' . fmt_time($row['end_datetime']) . ')';
        }
        foreach ($this->fetchAll(
            "SELECT task_type, task_date, start_time, end_time FROM internal_tasks
              WHERE driver_id = ? AND TIMESTAMP(task_date, start_time) < ? AND TIMESTAMP(task_date, end_time) > ?",
            [$driverId, $end, $start]
        ) as $row) {
            $out[] = 'Internal task: ' . str_replace('_', ' ', $row['task_type']) . ' on ' . fmt_date($row['task_date']) . ' ' . substr($row['start_time'], 0, 5) . '-' . substr($row['end_time'], 0, 5);
        }
        return $out;
    }

    public function driverHasLicence(int $driverId, string $licence): bool
    {
        return (bool)$this->fetchOne('SELECT id FROM driver_licences WHERE driver_id = ? AND licence_class = ?', [$driverId, $licence]);
    }
}
