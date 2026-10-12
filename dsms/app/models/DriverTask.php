<?php
/** DriverTask - everything a driver has to do: trip legs of confirmed trips plus internal tasks. */
class DriverTask extends Model
{
    public const TYPES = [
        'workshop_send' => 'Send vehicle to workshop', 'workshop_collect' => 'Collect vehicle from workshop',
        'refuel' => 'Refuel', 'test_drive' => 'Test drive', 'road_tax' => 'Road tax / inspection',
        'document_delivery' => 'Document delivery', 'other' => 'Other',
    ];

    public function driverIdOfUser(int $userId): ?int
    {
        $row = $this->fetchOne('SELECT id FROM drivers WHERE user_id = ?', [$userId]);
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Tasks between two dates (inclusive), earliest first.
     * Trip legs only appear once the request is Confirmed (or Completed); an unconfirmed
     * assignment is not yet a duty. Each row: kind (trip|internal), start, end, title, details...
     */
    public function between(int $driverId, string $fromDate, string $toDate): array
    {
        $trips = $this->fetchAll(
            "SELECT 'trip' AS kind, l.start_datetime AS start, l.end_datetime AS finish, l.leg_type, r.id AS request_id, r.status,
                    r.destination_name, r.full_address, r.passenger_count, r.contact_phone, pp.name AS pickup_name,
                    v.reg_no, CONCAT(v.brand, ' ', v.model) AS vehicle_name, ru.name AS requester_name, ru.phone AS requester_phone
               FROM request_legs l
               JOIN requests r ON r.id = l.request_id
               JOIN vehicles v ON v.id = r.vehicle_id
               JOIN users ru ON ru.id = r.requester_id
               LEFT JOIN pickup_points pp ON pp.id = r.pickup_point_id
              WHERE l.driver_id = ? AND r.status IN ('Confirmed','Completed')
                AND DATE(l.start_datetime) BETWEEN ? AND ?",
            [$driverId, $fromDate, $toDate]
        );
        $internal = $this->fetchAll(
            "SELECT 'internal' AS kind, TIMESTAMP(t.task_date, t.start_time) AS start, TIMESTAMP(t.task_date, t.end_time) AS finish,
                    t.task_type, t.location, t.description, v.reg_no, CONCAT(v.brand, ' ', v.model) AS vehicle_name
               FROM internal_tasks t LEFT JOIN vehicles v ON v.id = t.vehicle_id
              WHERE t.driver_id = ? AND t.task_date BETWEEN ? AND ?",
            [$driverId, $fromDate, $toDate]
        );
        $all = array_merge($trips, $internal);
        usort($all, fn($a, $b) => strcmp($a['start'], $b['start']));
        return $all;
    }

    /** Welfare duty dates of a driver from a date on. */
    public function welfareDates(int $driverId, string $fromDate, string $toDate): array
    {
        return array_column($this->fetchAll('SELECT duty_date FROM welfare_roster WHERE driver_id = ? AND duty_date BETWEEN ? AND ? ORDER BY duty_date', [$driverId, $fromDate, $toDate]), 'duty_date');
    }

    /** Totals for the month, so a driver can see their own workload. */
    public function monthSummary(int $driverId, string $month): array
    {
        $from = $month . '-01'; $to = date('Y-m-t', strtotime($from));
        $tasks = $this->between($driverId, $from, $to);
        $after = 0;
        $settings = new Setting();
        $ws = $settings->get('WORK_START', '08:00'); $we = $settings->get('WORK_END', '17:30');
        foreach ($tasks as $t) {
            $dow = (int)date('N', strtotime($t['start']));
            if ($dow >= 6 || substr($t['start'], 11, 5) < $ws || substr($t['finish'], 11, 5) > $we || substr($t['finish'], 0, 10) > substr($t['start'], 0, 10)) { $after++; }
        }
        return ['tasks' => count($tasks), 'after_hours' => $after];
    }
}
