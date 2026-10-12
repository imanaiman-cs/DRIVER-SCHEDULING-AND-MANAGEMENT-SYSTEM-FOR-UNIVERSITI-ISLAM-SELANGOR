<?php
/**
 * WelfareController - UC014 Welfare duty roster.
 * One driver is on 24-hour welfare duty each day. The page can fill a month automatically
 * (fair rotation) and lets the admin set, replace (with a reason) or clear a day.
 */
class WelfareController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $month = $this->month((string)($_GET['month'] ?? ''));
        $model = new WelfareRoster();
        $this->view('welfare/index', [
            'title' => 'Welfare duty', 'month' => $month, 'roster' => $model->month($month), 'drivers' => $model->drivers(),
            'prev' => date('Y-m', strtotime("$month-01 -1 month")), 'next' => date('Y-m', strtotime("$month-01 +1 month")),
            'days' => (int)date('t', strtotime("$month-01")),
        ]);
    }

    /** Set or replace the driver of one day. Replacing needs a reason, which is kept. */
    public function set(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $date = $this->post('duty_date');
        $driverId = $this->postInt('driver_id');
        $back = ['month' => substr($date, 0, 7)];
        if (!DriversController::validDate($date)) {
            $this->flashRedirect('danger', 'Invalid date.', 'welfare/index');
        }
        $model = new WelfareRoster();
        if (!in_array($driverId, array_map('intval', array_column($model->drivers(), 'id')), true)) {
            $this->flashRedirect('danger', 'Choose an active carpool driver.', 'welfare/index', $back);
        }
        if ($leave = (new AvailabilityService())->driverLeave($driverId, $date, $date)) {
            $this->flashRedirect('danger', 'That driver is on ' . ($leave['type'] === 'mc' ? 'MC' : 'leave') . ' on ' . fmt_date($date) . '.', 'welfare/index', $back);
        }
        $existing = $model->forDate($date);
        if ($existing && (int)$existing['driver_id'] === $driverId) {
            $this->flashRedirect('warning', 'That driver is already on duty that day.', 'welfare/index', $back);
        }
        $reason = null; $from = null;
        if ($existing) {
            $reason = $this->post('reason');
            if (mb_strlen($reason) < 3) {
                $this->flashRedirect('danger', 'Please enter the reason for replacing the duty driver.', 'welfare/index', $back);
            }
            $reason = mb_substr($reason, 0, 500);
            $from = (int)$existing['driver_id'];
        }
        $model->set($date, $driverId, $from, $reason);
        (new AuditLog())->record($existing ? 'welfare_replace' : 'welfare_set', null, "Welfare duty $date: driver $driverId" . ($reason ? " (replacing $from: $reason)" : ''));
        $userId = (new AssignmentService())->userIdOfDriver($driverId);
        if ($userId) {
            NotificationService::notify($userId, 'You are on welfare duty on ' . fmt_date($date) . '.', page_link('tasks/index'));
        }
        if ($from && ($old = (new AssignmentService())->userIdOfDriver($from))) {
            NotificationService::notify($old, 'You are no longer on welfare duty on ' . fmt_date($date) . '.', page_link('tasks/index'));
        }
        $this->flashRedirect('success', 'Welfare duty saved for ' . fmt_date($date) . '.', 'welfare/index', $back);
    }

    public function clear(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $date = $this->post('duty_date');
        if (DriversController::validDate($date)) {
            (new WelfareRoster())->clear($date);
            (new AuditLog())->record('welfare_clear', null, "Welfare duty cleared for $date");
        }
        $this->flashRedirect('success', 'Duty cleared.', 'welfare/index', ['month' => substr($date, 0, 7)]);
    }

    /**
     * Fill the EMPTY days of a month in a fair rotation:
     * each day goes to the driver with the fewest duty days in the last 60 days and this month,
     * skipping drivers on leave, and avoiding the same driver two days in a row when possible.
     */
    public function generate(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $month = $this->month($this->post('month'));
        $model = new WelfareRoster();
        $av = new AvailabilityService();
        $drivers = $model->drivers();
        if (!$drivers) {
            $this->flashRedirect('danger', 'There are no active carpool drivers.', 'welfare/index', ['month' => $month]);
        }
        $first = "$month-01";
        $counts = $model->dutyCounts(date('Y-m-d', strtotime("$first -60 days")), date('Y-m-t', strtotime($first)));
        $roster = $model->month($month);
        $previous = null; $filled = 0; $unfilled = [];
        for ($day = 1, $days = (int)date('t', strtotime($first)); $day <= $days; $day++) {
            $date = sprintf('%s-%02d', $month, $day);
            if (isset($roster[$date])) { $previous = (int)$roster[$date]['driver_id']; continue; }
            $best = null;
            foreach ($drivers as $d) {
                $id = (int)$d['id'];
                if ($av->driverLeave($id, $date, $date)) { continue; }
                $key = [($counts[$id] ?? 0) + ($id === $previous ? 100 : 0), $d['name']];   // +100: only if nobody else is free
                if ($best === null || $key < $best[0]) { $best = [$key, $id]; }
            }
            if ($best === null) { $unfilled[] = $day; $previous = null; continue; }
            $model->set($date, $best[1]);
            $counts[$best[1]] = ($counts[$best[1]] ?? 0) + 1;
            $previous = $best[1]; $filled++;
        }
        (new AuditLog())->record('welfare_generate', null, "Welfare roster $month: $filled day(s) filled");
        $msg = "Filled $filled day(s) of " . date('F Y', strtotime($first)) . '.';
        $this->flashRedirect($unfilled ? 'warning' : 'success', $unfilled ? "$msg No driver was free on day(s): " . implode(', ', $unfilled) . '.' : $msg, 'welfare/index', ['month' => $month]);
    }

    private function month(string $value): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : date('Y-m');
    }
}
