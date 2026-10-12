<?php
/**
 * AssignmentsController - UC008 Assign driver, UC009 Approve assignment, UC010 Self-drive,
 * UC011 Change driver.
 *
 * Vehicle Section (admin_assistant, head_admin) assigns and changes drivers;
 * only the head admin approves an assignment. Every action re-checks the role and the
 * request status on the server (the buttons in the views are only a convenience).
 */
class AssignmentsController extends Controller
{
    /** Approved requests that still need a driver. */
    public function index(): void
    {
        $this->adminOnly();
        [$rows] = (new TripRequest())->search(['status' => RequestStatus::APPROVED], 100, 0);
        $this->view('assignments/index', ['title' => 'Assign driver', 'rows' => $rows]);
    }

    /** The recommendation page: one table of ranked drivers for each leg. */
    public function assign(): void
    {
        $this->adminOnly();
        [$request, $legs] = $this->load($this->getInt('id'), [RequestStatus::APPROVED]);
        $this->view('assignments/assign', $this->formData('Assign driver - request #' . $request['id'], $request, $legs));
    }

    public function store(): void
    {
        $this->adminOnly();
        $this->requirePost();
        [$request, $legs] = $this->load($this->postInt('id'), [RequestStatus::APPROVED]);
        $choices = array_map('intval', (array)($_POST['driver'] ?? []));
        $service = new AssignmentService();

        $result = $service->checkAll($request, $legs, $choices);
        if ($result['errors']) {
            $this->showForm($request, $legs, $result['errors'], [], $choices);
            return;
        }
        if ($result['warnings'] && empty($_POST['confirm_clash'])) {
            $this->showForm($request, $legs, [], $result['warnings'], $choices);   // ask the admin to tick "assign anyway"
            return;
        }
        $actor = Auth::user();
        try {
            $service->assign($request, $legs, $choices, $actor);
        } catch (DomainException $e) {
            $this->flashRedirect('danger', 'This request has just been changed by someone else. Please check it again.', 'assignments/index');
        }

        NotificationService::notify((new User())->activeAdminIds(true), "Drivers were assigned to request #{$request['id']} ({$request['destination_name']}). Please approve the assignment.", page_link('assignments/approve'));
        $this->flashRedirect('success', "Drivers assigned to request #{$request['id']}. It now waits for the head admin to approve the assignment.", 'assignments/index');
    }

    /** The requester drives themselves: no driver, straight to Confirmed. */
    public function selfDrive(): void
    {
        $this->adminOnly();
        $this->requirePost();
        [$request] = $this->load($this->postInt('id'), [RequestStatus::APPROVED]);
        (new AssignmentService())->selfDrive($request, Auth::user());
        NotificationService::notify((int)$request['requester_id'], "Request #{$request['id']} is confirmed as self-drive: you will drive the vehicle {$request['reg_no']} yourself.", page_link('requests/show', ['id' => $request['id']]));
        $this->flashRedirect('success', "Request #{$request['id']} confirmed as self-drive.", 'assignments/index');
    }

    // ---------------------------------------------------------------- head admin approval

    public function approve(): void
    {
        Auth::requireRole('head_admin');
        $model = new TripRequest();
        [$rows] = $model->search(['status' => RequestStatus::ASSIGNED], 100, 0);
        foreach ($rows as &$r) { $r['legs'] = $model->legs((int)$r['id']); }
        unset($r);
        $this->view('assignments/approve', ['title' => 'Approve assignment', 'rows' => $rows]);
    }

    public function confirm(): void
    {
        Auth::requireRole('head_admin');
        $this->requirePost();
        [$request, $legs] = $this->load($this->postInt('id'), [RequestStatus::ASSIGNED]);
        (new AssignmentService())->confirm($request, Auth::user());

        $link = page_link('requests/show', ['id' => $request['id']]);
        $driverUsers = array_map('intval', array_filter(array_column($legs, 'driver_user_id')));
        NotificationService::notify((int)$request['requester_id'], "Request #{$request['id']} is confirmed. " . $this->driverSentence($legs), $link);
        NotificationService::notify($driverUsers, "You are assigned to request #{$request['id']}: {$request['destination_name']}, " . fmt_datetime($request['departure_datetime']) . '. Vehicle ' . $request['reg_no'] . '.', $link);
        $this->flashRedirect('success', "Request #{$request['id']} confirmed.", 'assignments/approve');
    }

    // ---------------------------------------------------------------- change driver (R7)

    public function change(): void
    {
        $this->adminOnly();
        $id = $this->getInt('id');
        if ($id) {
            [$request, $legs] = $this->load($id, [RequestStatus::ASSIGNED, RequestStatus::CONFIRMED]);
            $data = $this->formData('Change driver - request #' . $id, $request, $legs, true);
            $data['replacements'] = (new AssignmentService())->replacements($id);
            $this->view('assignments/change', $data);
            return;
        }
        $model = new TripRequest();
        $rows = [];
        foreach ([RequestStatus::ASSIGNED, RequestStatus::CONFIRMED] as $status) {
            [$part] = $model->search(['status' => $status], 100, 0);
            $rows = array_merge($rows, $part);
        }
        $rows = array_values(array_filter($rows, fn($r) => $r['departure_datetime'] > date('Y-m-d H:i:s') || $r['return_datetime'] > date('Y-m-d H:i:s')));
        usort($rows, fn($a, $b) => strcmp($a['departure_datetime'], $b['departure_datetime']));
        foreach ($rows as &$r) { $r['legs'] = $model->legs((int)$r['id']); }
        unset($r);
        $this->view('assignments/change_list', ['title' => 'Change driver', 'rows' => $rows]);
    }

    public function updateDriver(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $id = $this->postInt('id');
        [$request, $legs] = $this->load($id, [RequestStatus::ASSIGNED, RequestStatus::CONFIRMED]);
        $leg = null;
        foreach ($legs as $l) { if ((int)$l['id'] === $this->postInt('leg_id')) { $leg = $l; } }
        $newDriver = $this->postInt('driver_id');
        $reason = $this->post('reason');

        $back = ['id' => $id];
        if (!$leg) {
            $this->flashRedirect('danger', 'Leg not found.', 'assignments/change', $back);
        }
        if ($leg['start_datetime'] <= date('Y-m-d H:i:s')) {
            $this->flashRedirect('danger', 'This leg has already started, so its driver can no longer be changed.', 'assignments/change', $back);
        }
        if (mb_strlen($reason) < 3) {
            $this->flashRedirect('danger', 'Please enter the reason for changing the driver (it is logged).', 'assignments/change', $back);
        }
        if ($newDriver === (int)$leg['driver_id']) {
            $this->flashRedirect('warning', 'That driver is already assigned to this leg.', 'assignments/change', $back);
        }
        $service = new AssignmentService();
        $result = $service->check($leg, $request, $newDriver);
        if ($result['errors']) {
            $this->flashRedirect('danger', implode(' ', $result['errors']), 'assignments/change', $back);
        }
        if ($result['warnings'] && empty($_POST['confirm_clash'])) {
            $this->flashRedirect('warning', implode(' ', $result['warnings']) . ' Tick "Assign anyway" if you still want this driver.', 'assignments/change', $back);
        }

        $reason = mb_substr($reason, 0, 500);
        try {
            $service->changeDriver($request, $leg, $newDriver, $reason, Auth::user());
        } catch (DomainException $e) {
            $this->flashRedirect('danger', $e->getMessage(), 'assignments/change', $back);
        }

        $link = page_link('requests/show', ['id' => $id]);
        $text = ucfirst($leg['leg_type']) . " leg of request #$id ({$request['destination_name']})";
        NotificationService::notify((int)$request['requester_id'], "The driver of the $text was changed. Reason: $reason", $link);
        if ($request['status'] === RequestStatus::CONFIRMED) {
            NotificationService::notify($leg['driver_user_id'] ? (int)$leg['driver_user_id'] : [], "You are no longer assigned to the $text.", $link);
            NotificationService::notify($service->userIdOfDriver($newDriver) ?? [], "You are now assigned to the $text on " . fmt_datetime($leg['start_datetime']) . '.', $link);
        }
        $this->flashRedirect('success', 'Driver changed and recorded in the change log.', 'assignments/change', $back);
    }

    // ---------------------------------------------------------------- helpers

    /** Load the request and its legs; refuse (flash + redirect) unless it is in one of the expected statuses. */
    private function load(int $id, array $statuses): array
    {
        $model = new TripRequest();
        $request = $id ? $model->find($id) : null;
        if (!$request) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found']);
            exit;
        }
        if (!in_array($request['status'], $statuses, true)) {
            $this->flashRedirect('warning', "Request #$id is \"{$request['status']}\", so this action is not available.", 'assignments/index');
        }
        return [$request, $model->legs($id)];
    }

    private function formData(string $title, array $request, array $legs, bool $onlyFuture = false, array $errors = [], array $warnings = [], array $choices = []): array
    {
        $recommender = new DriverRecommender();
        $recs = [];
        foreach ($legs as $leg) {
            $recs[(int)$leg['id']] = $recommender->recommend($leg, $request['required_licence'], (int)$request['id']);
        }
        return ['title' => $title, 'r' => $request, 'legs' => $legs, 'recs' => $recs, 'errors' => $errors, 'warnings' => $warnings, 'choices' => $choices];
    }

    private function showForm(array $request, array $legs, array $errors, array $warnings, array $choices): void
    {
        $this->view('assignments/assign', $this->formData('Assign driver - request #' . $request['id'], $request, $legs, false, $errors, $warnings, $choices));
    }

    private function driverSentence(array $legs): string
    {
        $parts = [];
        foreach ($legs as $l) {
            if ($l['driver_name']) { $parts[] = ucfirst($l['leg_type']) . ': ' . $l['driver_name'] . ' (' . ($l['driver_phone'] ?? 'no phone') . ')'; }
        }
        return $parts ? 'Driver - ' . implode('; ', $parts) . '.' : '';
    }
}
