<?php
/**
 * TasksController - UC012 Driver tasks (a phone-friendly list for drivers).
 * A driver only ever sees their OWN tasks: the driver id comes from the logged-in user, never from the URL.
 */
class TasksController extends Controller
{
    private const RANGES = ['today' => 'Today', 'week' => 'Next 7 days', 'month' => 'This month', 'past' => 'Last 14 days'];

    public function index(): void
    {
        Auth::requireRole('driver');
        $model = new DriverTask();
        $driverId = $model->driverIdOfUser((int)Auth::id());
        if (!$driverId) {
            $this->view('errors/403', ['title' => 'No driver profile']);
            return;
        }
        $range = array_key_exists($_GET['range'] ?? '', self::RANGES) ? $_GET['range'] : 'week';
        $today = date('Y-m-d');
        [$from, $to] = match ($range) {
            'today' => [$today, $today],
            'month' => [date('Y-m-01'), date('Y-m-t')],
            'past'  => [date('Y-m-d', strtotime('-14 days')), date('Y-m-d', strtotime('-1 day'))],
            default => [$today, date('Y-m-d', strtotime('+7 days'))],
        };
        $tasks = $model->between($driverId, $from, $to);
        if ($range === 'past') { $tasks = array_reverse($tasks); }
        $this->view('tasks/index', [
            'title' => 'My tasks', 'tasks' => $tasks, 'range' => $range, 'ranges' => self::RANGES, 'today' => $today,
            'summary' => $model->monthSummary($driverId, date('Y-m')),
            'welfare' => $model->welfareDates($driverId, $today, date('Y-m-d', strtotime('+30 days'))),
        ]);
    }
}
