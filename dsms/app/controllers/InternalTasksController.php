<?php
/**
 * InternalTasksController - UC013 Internal tasks.
 * Jobs for drivers that are not passenger trips (refuelling, taking a vehicle to the workshop,
 * delivering documents...). They count in the driver's monthly workload, so the recommendation stays fair.
 */
class InternalTasksController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
        $from = $month . '-01';
        $model = new InternalTask();
        $this->view('internal_tasks/index', [
            'title' => 'Internal tasks', 'rows' => $model->between($from, date('Y-m-t', strtotime($from))), 'month' => $month,
            'prev' => date('Y-m', strtotime("$from -1 month")), 'next' => date('Y-m', strtotime("$from +1 month")),
        ]);
    }

    public function create(): void
    {
        $this->adminOnly();
        $this->form(['task_date' => date('Y-m-d'), 'start_time' => '09:00', 'end_time' => '10:00'], [], []);
    }

    private function form(array $v, array $errors, array $warnings): void
    {
        $model = new InternalTask();
        $this->view('internal_tasks/form', ['title' => 'New internal task', 'v' => $v, 'errors' => $errors, 'warnings' => $warnings, 'drivers' => $model->drivers(), 'vehicles' => $model->vehicles()]);
    }

    public function store(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new InternalTask();
        $d = [
            'task_type'   => $this->post('task_type'),
            'task_date'   => $this->post('task_date'),
            'start_time'  => $this->post('start_time'),
            'end_time'    => $this->post('end_time'),
            'driver_id'   => $this->postInt('driver_id'),
            'vehicle_id'  => $this->postInt('vehicle_id') ?: null,
            'location'    => mb_substr($this->post('location'), 0, 150) ?: null,
            'description' => mb_substr($this->post('description'), 0, 255) ?: null,
        ];
        $errors = []; $warnings = [];
        if (!isset(DriverTask::TYPES[$d['task_type']])) { $errors['task_type'] = 'Choose a task type.'; }
        if (!DriversController::validDate($d['task_date'])) { $errors['task_date'] = 'Enter a valid date.'; }
        foreach (['start_time', 'end_time'] as $k) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $d[$k])) { $errors[$k] = 'Use HH:MM.'; }
        }
        if (!isset($errors['start_time'], $errors['end_time']) && $d['end_time'] <= $d['start_time']) { $errors['end_time'] = 'The end time must be after the start time.'; }
        if (!in_array($d['driver_id'], array_map('intval', array_column($model->drivers(), 'id')), true)) { $errors['driver_id'] = 'Choose an active carpool driver.'; }
        if ($d['vehicle_id'] && !(new Vehicle())->find($d['vehicle_id'])) { $errors['vehicle_id'] = 'Unknown vehicle.'; }

        if (!$errors) {
            $av = new AvailabilityService();
            if ($leave = $av->driverLeave($d['driver_id'], $d['task_date'], $d['task_date'])) {
                $errors['driver_id'] = 'This driver is on ' . ($leave['type'] === 'mc' ? 'MC' : 'leave') . ' on that date.';
            } else {
                // R5: a time clash is a warning, the admin may still go ahead.
                $warnings = $av->driverClashes($d['driver_id'], "{$d['task_date']} {$d['start_time']}:00", "{$d['task_date']} {$d['end_time']}:00");
                if ($d['vehicle_id']) {
                    foreach ($model->vehicleHolds($d['vehicle_id'], $d['task_date']) as $h) {
                        $warnings[] = "The vehicle is booked that day by request #{$h['request_id']} ({$h['destination_name']}).";
                    }
                }
            }
        }
        if ($errors || ($warnings && empty($_POST['confirm_clash']))) {
            $this->form($d, $errors, $errors ? [] : $warnings);
            return;
        }

        $id = $model->create($d, (int)Auth::id());
        (new AuditLog())->record('internal_task', null, "Task #$id {$d['task_type']} on {$d['task_date']} for driver {$d['driver_id']}");
        $userId = (new AssignmentService())->userIdOfDriver($d['driver_id']);
        if ($userId) {
            NotificationService::notify($userId, 'New task: ' . DriverTask::TYPES[$d['task_type']] . ' on ' . fmt_date($d['task_date']) . ' ' . $d['start_time'] . '-' . $d['end_time'] . ($d['location'] ? ' at ' . $d['location'] : '') . '.', page_link('tasks/index'));
        }
        $this->flashRedirect('success', 'Internal task saved.', 'internal_tasks/index', ['month' => substr($d['task_date'], 0, 7)]);
    }

    public function delete(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new InternalTask();
        $task = $model->find($this->postInt('id'));
        if (!$task) {
            $this->flashRedirect('danger', 'Task not found.', 'internal_tasks/index');
        }
        $model->delete((int)$task['id']);
        (new AuditLog())->record('internal_task_delete', null, "Task #{$task['id']} {$task['task_type']} on {$task['task_date']}");
        $this->flashRedirect('success', 'Internal task removed.', 'internal_tasks/index', ['month' => substr($task['task_date'], 0, 7)]);
    }
}
