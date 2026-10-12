<?php
/**
 * Dashboard - the counts shown on each role's home page.
 */
class Dashboard extends Model
{
    private function count(string $sql, array $params = []): int
    {
        return (int)($this->fetchOne($sql, $params)['c'] ?? 0);
    }

    /** Counts for one user, depending on the role. Every role is also staff. */
    public function forUser(array $user): array
    {
        $id = (int)$user['id'];
        $out = [
            'my_pending'  => $this->count("SELECT COUNT(*) c FROM requests WHERE requester_id = ? AND status = 'Pending Approval'", [$id]),
            'my_upcoming' => $this->count("SELECT COUNT(*) c FROM requests WHERE requester_id = ? AND status IN ('Approved','Driver Assigned','Confirmed') AND return_datetime >= NOW()", [$id]),
            'my_total'    => $this->count('SELECT COUNT(*) c FROM requests WHERE requester_id = ?', [$id]),
        ];

        if ($user['role'] === 'hod') {
            $out['to_approve'] = $this->count("SELECT COUNT(*) c FROM requests WHERE approver_id = ? AND status = 'Pending Approval'", [$id]);
        }
        if ($user['role'] === 'head_admin') {
            // A requester with no HOD above them (e.g. an HOD) is approved by the head admin.
            $out['to_approve'] = $this->count("SELECT COUNT(*) c FROM requests WHERE approver_id IS NULL AND status = 'Pending Approval'");
        }
        if ($user['role'] === 'driver') {
            $driver = $this->fetchOne('SELECT id FROM drivers WHERE user_id = ?', [$id]);
            $did = (int)($driver['id'] ?? 0);
            $out['tasks_today'] = $this->count(
                "SELECT COUNT(*) c FROM request_legs l JOIN requests r ON r.id = l.request_id
                 WHERE l.driver_id = ? AND r.status = 'Confirmed' AND DATE(l.start_datetime) = CURDATE()", [$did])
                + $this->count('SELECT COUNT(*) c FROM internal_tasks WHERE driver_id = ? AND task_date = CURDATE()', [$did]);
            $out['tasks_upcoming'] = $this->count(
                "SELECT COUNT(*) c FROM request_legs l JOIN requests r ON r.id = l.request_id
                 WHERE l.driver_id = ? AND r.status = 'Confirmed' AND l.start_datetime > NOW()", [$did]);
            $out['welfare_today'] = $this->count('SELECT COUNT(*) c FROM welfare_roster WHERE driver_id = ? AND duty_date = CURDATE()', [$did]);
        }
        if (in_array($user['role'], ['admin_assistant', 'head_admin'], true)) {
            $out['awaiting_approval']  = $this->count("SELECT COUNT(*) c FROM requests WHERE status = 'Pending Approval'");
            $out['to_assign']          = $this->count("SELECT COUNT(*) c FROM requests WHERE status = 'Approved' AND is_self_drive = 0");
            $out['trips_today']        = $this->count("SELECT COUNT(*) c FROM requests WHERE status IN ('Driver Assigned','Confirmed') AND DATE(departure_datetime) <= CURDATE() AND DATE(return_datetime) >= CURDATE()");
            $out['vehicles_available'] = $this->count("SELECT COUNT(*) c FROM vehicles WHERE status = 'Available'");
        }
        if ($user['role'] === 'head_admin') {
            $out['to_approve_assignment'] = $this->count("SELECT COUNT(*) c FROM requests WHERE status = 'Driver Assigned'");
        }
        return $out;
    }
}
