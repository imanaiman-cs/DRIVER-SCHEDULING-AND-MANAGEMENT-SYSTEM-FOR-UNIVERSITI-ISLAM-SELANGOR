<?php
/**
 * Report - the numbers for the monthly report.
 * A trip belongs to the month in which it DEPARTS. A "task" is counted the same way as in the
 * driver recommendation (a trip leg of a Driver Assigned / Confirmed / Completed request, or an
 * internal task), so the report and the scores always tell the same story.
 */
class Report extends Model
{
    public function build(string $month): array
    {
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));
        $next = date('Y-m-d', strtotime("$from +1 month"));
        $settings = new Setting();
        $ws = $settings->get('WORK_START', '08:00') . ':00';
        $we = $settings->get('WORK_END', '17:30') . ':00';

        $statuses = [];
        foreach (RequestStatus::ALL as $s) { $statuses[$s] = 0; }
        foreach ($this->fetchAll('SELECT status, COUNT(*) c FROM requests WHERE departure_datetime >= ? AND departure_datetime < ? GROUP BY status', [$from, $next]) as $r) {
            $statuses[$r['status']] = (int)$r['c'];
        }

        $totals = $this->fetchOne(
            "SELECT COUNT(*) AS trips, COALESCE(SUM(passenger_count), 0) AS passengers,
                    COALESCE(SUM(DATEDIFF(return_datetime, departure_datetime) + 1), 0) AS vehicle_days,
                    COALESCE(SUM(is_self_drive), 0) AS self_drive
               FROM requests WHERE status IN ('Confirmed','Completed') AND departure_datetime >= ? AND departure_datetime < ?",
            [$from, $next]
        );

        $vehicles = $this->fetchAll(
            "SELECT v.reg_no, CONCAT(v.brand, ' ', v.model) AS name, v.vehicle_type,
                    COUNT(r.id) AS trips, COALESCE(SUM(r.passenger_count), 0) AS passengers,
                    COALESCE(SUM(DATEDIFF(r.return_datetime, r.departure_datetime) + 1), 0) AS days_used
               FROM vehicles v
               LEFT JOIN requests r ON r.vehicle_id = v.id AND r.status IN ('Confirmed','Completed')
                    AND r.departure_datetime >= ? AND r.departure_datetime < ?
              GROUP BY v.id ORDER BY trips DESC, v.reg_no",
            [$from, $next]
        );

        $afterLeg  = "(DAYOFWEEK(l.start_datetime) IN (1,7) OR TIME(l.start_datetime) < '$ws' OR TIME(l.end_datetime) > '$we' OR DATE(l.end_datetime) > DATE(l.start_datetime))";
        $afterTask = "(DAYOFWEEK(t.task_date) IN (1,7) OR t.start_time < '$ws' OR t.end_time > '$we')";
        $drivers = $this->fetchAll(
            "SELECT d.id, u.name, d.driver_group,
                    (SELECT COUNT(*) FROM request_legs l JOIN requests r ON r.id = l.request_id
                      WHERE l.driver_id = d.id AND r.status IN ('Driver Assigned','Confirmed','Completed') AND l.start_datetime >= ? AND l.start_datetime < ?) AS trip_legs,
                    (SELECT COUNT(*) FROM internal_tasks t WHERE t.driver_id = d.id AND t.task_date >= ? AND t.task_date < ?) AS internal_tasks,
                    (SELECT COUNT(*) FROM request_legs l JOIN requests r ON r.id = l.request_id
                      WHERE l.driver_id = d.id AND r.status IN ('Driver Assigned','Confirmed','Completed') AND l.start_datetime >= ? AND l.start_datetime < ? AND $afterLeg) AS after_legs,
                    (SELECT COUNT(*) FROM internal_tasks t WHERE t.driver_id = d.id AND t.task_date >= ? AND t.task_date < ? AND $afterTask) AS after_tasks,
                    (SELECT COUNT(*) FROM welfare_roster w WHERE w.driver_id = d.id AND w.duty_date >= ? AND w.duty_date < ?) AS welfare_days
               FROM drivers d JOIN users u ON u.id = d.user_id
              WHERE d.is_active = 1 ORDER BY d.driver_group, u.name",
            [$from, $next, $from, $next, $from, $next, $from, $next, $from, $next]
        );
        foreach ($drivers as &$d) {
            $d['tasks']       = (int)$d['trip_legs'] + (int)$d['internal_tasks'];
            $d['after_hours'] = (int)$d['after_legs'] + (int)$d['after_tasks'];
        }
        unset($d);

        $ptj = $this->fetchAll(
            "SELECT COALESCE(p.name, 'No department') AS name, COUNT(*) AS requests, COALESCE(SUM(r.passenger_count), 0) AS passengers,
                    SUM(r.status IN ('Confirmed','Completed')) AS trips
               FROM requests r JOIN users u ON u.id = r.requester_id LEFT JOIN ptj p ON p.id = u.ptj_id
              WHERE r.departure_datetime >= ? AND r.departure_datetime < ? GROUP BY p.id ORDER BY requests DESC, name",
            [$from, $next]
        );

        $requests = $this->fetchAll(
            "SELECT r.id, r.destination_name, r.status, r.departure_datetime, r.return_datetime, r.passenger_count, r.is_self_drive,
                    ru.name AS requester_name, v.reg_no,
                    (SELECT GROUP_CONCAT(DISTINCT u.name SEPARATOR ', ') FROM request_legs l JOIN drivers d ON d.id = l.driver_id JOIN users u ON u.id = d.user_id WHERE l.request_id = r.id) AS drivers
               FROM requests r JOIN users ru ON ru.id = r.requester_id JOIN vehicles v ON v.id = r.vehicle_id
              WHERE r.departure_datetime >= ? AND r.departure_datetime < ? ORDER BY r.departure_datetime, r.id",
            [$from, $next]
        );

        $changes = (int)$this->fetchOne('SELECT COUNT(*) c FROM driver_replacements WHERE changed_at >= ? AND changed_at < ?', [$from, $next])['c'];

        return [
            'month' => $month, 'from' => $from, 'to' => $to,
            'statuses' => $statuses, 'totals' => $totals, 'vehicles' => $vehicles, 'drivers' => $drivers,
            'ptj' => $ptj, 'requests' => $requests, 'driver_changes' => $changes,
            'total_requests' => array_sum($statuses),
        ];
    }
}
