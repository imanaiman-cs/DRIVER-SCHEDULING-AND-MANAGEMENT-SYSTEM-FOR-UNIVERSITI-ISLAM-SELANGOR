<?php
/**
 * CalendarController - UC019 Calendar (FullCalendar).
 * The page is just a container; FullCalendar asks calendar/events for JSON for the visible dates.
 */
class CalendarController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $isAdmin = RequestPolicy::isAdmin($user);
        $this->view('calendar/index', [
            'title'    => 'Calendar',
            'isAdmin'  => $isAdmin,
            'showExtras' => $isAdmin || $user['role'] === 'driver',
            'vehicles' => $isAdmin ? (new CalendarFeed())->vehicles() : [],
            // Pinned version of FullCalendar from a CDN (the page still works if you host it yourself).
            'scripts'  => ['https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js', asset('js/calendar.js')],
        ]);
    }

    /** JSON feed: ?start=2026-05-01&end=2026-06-12 (FullCalendar sends ISO dates). */
    public function events(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $start = $this->isoParam('start');
        $end   = $this->isoParam('end');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$start || !$end || strtotime($end) - strtotime($start) > 100 * 86400) {      // a bad or huge range is refused
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date range']);
            return;
        }
        $feed = new CalendarFeed();
        $events = [];

        foreach ($feed->requests($user, $start, $end, $this->getInt('vehicle')) as $r) {
            $events[] = [
                'id'      => 'r' . $r['id'],
                'title'   => '#' . $r['id'] . ' ' . $r['destination_name'] . ' (' . $r['reg_no'] . ')',
                'start'   => date('Y-m-d\TH:i:s', strtotime($r['departure_datetime'])),
                'end'     => date('Y-m-d\TH:i:s', strtotime($r['return_datetime'])),
                'url'     => url('requests/show', ['id' => $r['id']]),
                'classNames' => ['ev-request', 'ev-' . strtolower(str_replace(' ', '-', $r['status']))],
                'extendedProps' => ['status' => $r['status'], 'requester' => $r['requester_name']],
            ];
        }
        if (($_GET['tasks'] ?? '1') !== '0') {
            foreach ($feed->internalTasks($user, $start, $end) as $t) {
                $events[] = [
                    'id' => 't' . $t['id'],
                    'title' => 'Task: ' . (DriverTask::TYPES[$t['task_type']] ?? $t['task_type']) . ' - ' . $t['driver_name'],
                    'start' => $t['task_date'] . 'T' . substr($t['start_time'], 0, 8),
                    'end'   => $t['task_date'] . 'T' . substr($t['end_time'], 0, 8),
                    'classNames' => ['ev-internal'],
                    'extendedProps' => ['status' => 'Internal task'],
                ];
            }
        }
        if (($_GET['welfare'] ?? '1') !== '0') {
            foreach ($feed->welfare($user, $start, $end) as $w) {
                $events[] = [
                    'id' => 'w' . $w['duty_date'], 'title' => 'Welfare duty: ' . $w['driver_name'],
                    'start' => $w['duty_date'], 'allDay' => true,
                    'classNames' => ['ev-welfare'], 'extendedProps' => ['status' => 'Welfare duty'],
                ];
            }
        }
        echo json_encode($events, JSON_UNESCAPED_UNICODE);
    }

    /** FullCalendar sends "2026-05-01T00:00:00+08:00" or "2026-05-01"; keep only a safe local date-time. */
    private function isoParam(string $key): ?string
    {
        $v = (string)($_GET[$key] ?? '');
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}:\d{2}))?/', $v, $m) || !checkdate((int)substr($m[1], 5, 2), (int)substr($m[1], 8, 2), (int)substr($m[1], 0, 4))) {
            return null;
        }
        return $m[1] . ' ' . ($m[2] ?? '00:00:00');
    }
}
