<?php
/**
 * CalendarFeed - what each person may see on the calendar.
 *   admin  : every active request, internal tasks and the welfare roster
 *   hod    : requests of their own PTJ, their own and the ones they approve
 *   driver : trips they drive, their internal tasks and their welfare duty (plus their own requests)
 *   staff  : their own requests only
 * The filtering is done HERE, in the query, so a person can never receive data they may not see
 * (hiding things in JavaScript would not be secure).
 */
class CalendarFeed extends Model
{
    /** Requests that are still relevant on a calendar (rejected / lapsed / cancelled ones are gone). */
    private const SHOWN = "('Pending Approval','Approved','Driver Assigned','Confirmed','Completed')";

    public function requests(array $user, string $start, string $end, int $vehicleId = 0): array
    {
        $where = ['r.status IN ' . self::SHOWN, 'r.departure_datetime < ?', 'r.return_datetime >= ?'];
        $args  = [$end, $start];
        $uid = (int)$user['id'];
        switch ($user['role']) {
            case 'admin_assistant':
            case 'head_admin':
                break;
            case 'hod':
                $where[] = '(r.requester_id = ? OR r.approver_id = ? OR ru.ptj_id <=> ?)';
                array_push($args, $uid, $uid, $user['ptj_id']);
                break;
            case 'driver':
                $where[] = '(r.requester_id = ? OR (r.status IN (\'Confirmed\',\'Completed\') AND EXISTS (
                               SELECT 1 FROM request_legs l JOIN drivers d ON d.id = l.driver_id WHERE l.request_id = r.id AND d.user_id = ?)))';
                array_push($args, $uid, $uid);
                break;
            default:
                $where[] = 'r.requester_id = ?';
                $args[] = $uid;
        }
        if ($vehicleId > 0 && in_array($user['role'], ['admin_assistant', 'head_admin'], true)) {
            $where[] = 'r.vehicle_id = ?';
            $args[] = $vehicleId;
        }
        return $this->fetchAll(
            'SELECT r.id, r.destination_name, r.status, r.departure_datetime, r.return_datetime, v.reg_no, ru.name AS requester_name
               FROM requests r JOIN vehicles v ON v.id = r.vehicle_id JOIN users ru ON ru.id = r.requester_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY r.departure_datetime LIMIT 500',
            $args
        );
    }

    public function internalTasks(array $user, string $start, string $end): array
    {
        [$extra, $args] = $this->driverScope($user);
        if ($extra === null) { return []; }
        return $this->fetchAll(
            "SELECT t.id, t.task_type, t.task_date, t.start_time, t.end_time, u.name AS driver_name
               FROM internal_tasks t JOIN drivers d ON d.id = t.driver_id JOIN users u ON u.id = d.user_id
              WHERE t.task_date >= ? AND t.task_date < ? $extra ORDER BY t.task_date, t.start_time LIMIT 500",
            array_merge([substr($start, 0, 10), substr($end, 0, 10)], $args)
        );
    }

    public function welfare(array $user, string $start, string $end): array
    {
        [$extra, $args] = $this->driverScope($user, 'w.driver_id');
        if ($extra === null) { return []; }
        return $this->fetchAll(
            "SELECT w.duty_date, u.name AS driver_name
               FROM welfare_roster w JOIN drivers d ON d.id = w.driver_id JOIN users u ON u.id = d.user_id
              WHERE w.duty_date >= ? AND w.duty_date < ? $extra ORDER BY w.duty_date LIMIT 500",
            array_merge([substr($start, 0, 10), substr($end, 0, 10)], $args)
        );
    }

    /** Admin: no extra filter. Driver: only their own rows. Everyone else: nothing ([null, []]). */
    private function driverScope(array $user, string $column = 't.driver_id'): array
    {
        if (in_array($user['role'], ['admin_assistant', 'head_admin'], true)) {
            return ['', []];
        }
        if ($user['role'] === 'driver') {
            $row = $this->fetchOne('SELECT id FROM drivers WHERE user_id = ?', [(int)$user['id']]);
            return $row ? ["AND $column = ?", [(int)$row['id']]] : [null, []];
        }
        return [null, []];
    }

    public function vehicles(): array
    {
        return $this->fetchAll('SELECT id, reg_no, brand, model FROM vehicles ORDER BY reg_no');
    }
}
