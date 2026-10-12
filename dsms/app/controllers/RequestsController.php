<?php
/**
 * RequestsController - UC001 Create request, UC002 View request, UC003 Cancel request.
 *
 * The request form is a 4-step wizard:
 *   1. create    : when do you need a vehicle, and for how many people?
 *   2. vehicles  : cards of the fleet with availability for those dates (R4)
 *   3. details   : destination, purpose, documents (+ a confirm dialog before sending)
 *   4. store     : saves it. Everything is checked AGAIN here, because the earlier
 *                  steps only pass values in the URL and the browser can be edited.
 * Steps 1-3 only READ data (GET), so they need no CSRF token; step 4 is a POST with one.
 */
class RequestsController extends Controller
{
    private const PER_PAGE = 15;

    // ---------------------------------------------------------------- lists

    /** "My requests" - every role sees its own. */
    public function index(): void
    {
        Auth::requireLogin();
        $this->listing('mine');
    }

    /** All requests - Vehicle Section only. */
    public function all(): void
    {
        $this->adminOnly();
        $this->listing('all');
    }

    private function listing(string $scope): void
    {
        $filters = [
            'status' => (string)($_GET['status'] ?? ''),
            'q'      => trim((string)($_GET['q'] ?? '')),
        ];
        if ($scope === 'mine') {
            $filters['requester_id'] = Auth::id();
        }
        $page = max(1, $this->getInt('p'));
        [$rows, $total] = (new TripRequest())->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $this->view('requests/index', [
            'title' => $scope === 'all' ? 'All requests' : 'My requests',
            'scope' => $scope, 'rows' => $rows, 'total' => $total,
            'page' => $page, 'pages' => max(1, (int)ceil($total / self::PER_PAGE)), 'filters' => $filters,
        ]);
    }

    // ---------------------------------------------------------------- wizard

    /** Step 1: dates and number of passengers. */
    public function create(): void
    {
        Auth::requireLogin();
        $settings = new Setting();
        $this->view('requests/create', [
            'title'    => 'New vehicle request',
            'v'        => $_GET + ['dep_time' => '08:00', 'ret_time' => '17:00'],
            'errors'   => [],
            'minDays'  => $settings->int('MIN_DAYS_AHEAD', 2),
            'earliest' => (new DateTimeImmutable('today'))->modify('+' . $settings->int('MIN_DAYS_AHEAD', 2) . ' days')->format('Y-m-d'),
        ]);
    }

    /** Step 2: vehicle cards. Reached from step 1 with the trip in the URL. */
    public function vehicles(): void
    {
        Auth::requireLogin();
        [$trip, $errors] = $this->readTrip($_GET);
        if ($errors) {
            $settings = new Setting();
            $min = $settings->int('MIN_DAYS_AHEAD', 2);
            $this->view('requests/create', [
                'title' => 'New vehicle request', 'v' => $_GET, 'errors' => $errors, 'minDays' => $min,
                'earliest' => (new DateTimeImmutable('today'))->modify("+$min days")->format('Y-m-d'),
            ]);
            return;
        }
        $vehicles = (new AvailabilityService())->vehiclesForPeriod($trip['start_day'], $trip['end_day'], $trip['passengers']);
        // Usable vehicles first, then the ones that are not (shown so people understand why).
        usort($vehicles, fn($a, $b) => ($a['state'] !== 'available') <=> ($b['state'] !== 'available'));
        $this->view('requests/vehicles', ['title' => 'Choose a vehicle', 'trip' => $trip, 'vehicles' => $vehicles, 'query' => $this->tripQuery($trip)]);
    }

    /** Step 3: the details form for the chosen vehicle. */
    public function details(): void
    {
        Auth::requireLogin();
        [$trip, $errors] = $this->readTrip($_GET);
        $vehicleId = $this->getInt('vehicle_id');
        if (!$errors) {
            $errors = $this->vehicleErrors($vehicleId, $trip);
        }
        if ($errors) {
            $this->flashRedirect('danger', implode(' ', $errors), 'requests/create', $_GET);
        }
        $this->detailsForm($trip, $vehicleId, ['contact_phone' => $this->myPhone()], []);
    }

    private function detailsForm(array $trip, int $vehicleId, array $values, array $errors): void
    {
        $vehicle = (new Vehicle())->find($vehicleId);
        $this->view('requests/details', [
            'title' => 'Request details', 'trip' => $trip, 'vehicle' => $vehicle,
            'v' => $values, 'errors' => $errors, 'pickupPoints' => (new PickupPoint())->active(),
            'maxMb' => (new Setting())->int('MAX_UPLOAD_MB', 5),
            'approver' => $this->approverFor(Auth::id()),
        ]);
    }

    /** Step 4: validate everything again, then save. */
    public function store(): void
    {
        Auth::requireLogin();
        $this->requirePost();
        $uid = Auth::id();

        [$trip, $errors] = $this->readTrip($_POST);
        $vehicleId = $this->postInt('vehicle_id');
        if ($errors) {
            $this->flashRedirect('danger', implode(' ', $errors), 'requests/create', $this->tripQuery($trip ?: []));
        }

        $d = [
            'destination_name' => $this->post('destination_name'),
            'full_address'     => $this->post('full_address'),
            'purpose'          => $this->post('purpose'),
            'passenger_names'  => $this->post('passenger_names'),
            'contact_phone'    => $this->post('contact_phone'),
            'pickup_point_id'  => $this->postInt('pickup_point_id'),
        ];
        $e = [];
        if ($d['destination_name'] === '' || mb_strlen($d['destination_name']) > 150) { $e['destination_name'] = 'Enter the destination (up to 150 characters).'; }
        if ($d['full_address'] === '')  { $e['full_address'] = 'Enter the full address of the destination.'; }
        if ($d['purpose'] === '')       { $e['purpose'] = 'Enter the purpose of the trip.'; }
        if (!preg_match('/^[0-9+\-\s()]{7,30}$/', $d['contact_phone'])) { $e['contact_phone'] = 'Enter a valid phone number.'; }
        if (mb_strlen($d['passenger_names']) > 2000) { $e['passenger_names'] = 'Passenger names are too long.'; }
        if (!(new PickupPoint())->isActive($d['pickup_point_id'])) { $e['pickup_point_id'] = 'Choose a pickup point.'; }
        foreach ($this->vehicleErrors($vehicleId, $trip) as $msg) { $e['vehicle'] = $msg; }

        $approver = $this->approverFor($uid);

        // Documents: check them all BEFORE storing any, so a bad file does not leave half an upload behind.
        $config  = $GLOBALS['config'];
        $maxMb   = (new Setting())->int('MAX_UPLOAD_MB', (int)$config['upload_max_mb']);
        $files   = FileUpload::collect('documents');
        if (count($files) > 5) { $e['documents'] = 'You can upload at most 5 documents.'; }
        foreach ($files as $file) {
            if ($msg = FileUpload::problem($file, $maxMb, $config['upload_allowed'])) { $e['documents'] = $msg; break; }
        }
        if ($e) {
            $this->detailsForm($trip, $vehicleId, $_POST, $e);
            return;
        }

        $stored = [];
        try {
            foreach ($files as $file) {
                $stored[] = FileUpload::store($file, $config['upload_dir'], $config['upload_allowed']);
            }
            $requestId = (new TripRequest())->create([
                'requester_id' => $uid, 'approver_id' => $approver['id'] ?? null, 'vehicle_id' => $vehicleId,
                'destination_name' => $d['destination_name'], 'full_address' => $d['full_address'], 'purpose' => $d['purpose'],
                'passenger_count' => $trip['passengers'], 'passenger_names' => $d['passenger_names'] ?: null,
                'contact_phone' => $d['contact_phone'], 'pickup_point_id' => $d['pickup_point_id'],
                'departure' => $trip['departure'], 'return' => $trip['return'],
            ], $stored, new AvailabilityService());
        } catch (AvailabilityConflict $ex) {
            $this->discardFiles($stored);
            $this->flashRedirect('danger', $ex->getMessage(), 'requests/vehicles', $this->tripQuery($trip));
        } catch (Throwable $ex) {
            $this->discardFiles($stored);
            throw $ex;
        }

        (new AuditLog())->record('submit', $requestId, 'Request submitted');
        $link = page_link('requests/show', ['id' => $requestId]);
        $who  = Auth::user()['name'];
        if ($approver) {
            NotificationService::notify((int)$approver['id'], "New vehicle request #$requestId from $who is waiting for your approval.", $link);
        }
        $this->flashRedirect('success', "Request #$requestId was submitted and is waiting for approval. You will be notified when it is decided.", 'requests/show', ['id' => $requestId]);
    }

    // ---------------------------------------------------------------- view / cancel / documents

    public function show(): void
    {
        Auth::requireLogin();
        [$model, $request, $legs] = $this->loadVisible($this->getInt('id'));
        $user = Auth::user();
        $this->view('requests/show', [
            'title'     => 'Request #' . $request['id'],
            'r'         => $request,
            'legs'      => $legs,
            'documents' => $model->documents((int)$request['id']),
            'comments'  => (new RequestComment())->forRequest((int)$request['id']),
            'canCancel' => RequestPolicy::canCancel($user, $request),
            'showDrivers' => in_array($request['status'], [RequestStatus::ASSIGNED, RequestStatus::CONFIRMED, RequestStatus::COMPLETED], true),
            'timeline'  => $this->timeline($request),
        ]);
    }

    /** Cancel with a required reason. Frees the vehicle (and the drivers) for other people. */
    public function cancel(): void
    {
        Auth::requireLogin();
        $this->requirePost();
        $id = $this->postInt('id');
        [$model, $request, $legs] = $this->loadVisible($id);
        $user = Auth::user();

        if (!RequestPolicy::canCancel($user, $request)) {
            $this->flashRedirect('danger', 'This request can no longer be cancelled (only active requests that have not started can be).', 'requests/show', ['id' => $id]);
        }
        $reason = $this->post('reason');
        if (mb_strlen($reason) < 3) {
            $this->flashRedirect('danger', 'Please enter the reason for cancelling.', 'requests/show', ['id' => $id]);
        }
        $reason = mb_substr($reason, 0, 500);

        $people = $model->peopleOf($request);
        $model->changeStatus($id, RequestStatus::CANCELLED, 'cancel', $reason, [
            'cancelled_by' => $user['id'], 'cancelled_by_role' => $user['role'],
        ], (int)$user['id'], $user['role']);

        // Tell everyone involved except the person who did it.
        NotificationService::notify(
            array_diff($people, [(int)$user['id']]),
            "Request #$id ({$request['destination_name']}) was cancelled by {$user['name']}: $reason",
            page_link('requests/show', ['id' => $id])
        );
        $this->flashRedirect('success', "Request #$id was cancelled.", 'requests/show', ['id' => $id]);
    }

    /** Download a supporting document - only for people who may see the request. */
    public function document(): void
    {
        Auth::requireLogin();
        $model = new TripRequest();
        $doc = $model->document($this->getInt('id'));
        if (!$doc) {
            $this->notFound();
        }
        $this->loadVisible((int)$doc['request_id']);

        $path = $GLOBALS['config']['upload_dir'] . '/' . basename($doc['file_path']);   // basename: never trust a path
        if (!is_file($path)) {
            $this->notFound();
        }
        header('Content-Type: ' . $doc['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $doc['original_name']) . '"');
        readfile($path);
        exit;
    }

    // ---------------------------------------------------------------- helpers

    /** Load a request and make sure the current user may see it (403 otherwise, 404 if missing). */
    private function loadVisible(int $id): array
    {
        $model   = new TripRequest();
        $request = $id ? $model->find($id) : null;
        if (!$request) {
            $this->notFound();
        }
        $legs = $model->legs($id);
        $driverUserIds = array_map('intval', array_filter(array_column($legs, 'driver_user_id')));
        if (!RequestPolicy::canView(Auth::user(), $request, $driverUserIds)) {
            http_response_code(403);
            View::render('errors/403', ['title' => 'Access denied']);
            exit;
        }
        return [$model, $request, $legs];
    }

    private function notFound(): void
    {
        http_response_code(404);
        View::render('errors/404', ['title' => 'Not found']);
        exit;
    }

    /**
     * Read and validate the "when and how many" part. Used by steps 1-4.
     * @return array [trip|null, errors[]]
     */
    private function readTrip(array $src): array
    {
        $settings = new Setting();
        $dep = RequestRules::parse((string)($src['dep_date'] ?? ''), (string)($src['dep_time'] ?? ''));
        $ret = RequestRules::parse((string)($src['ret_date'] ?? ''), (string)($src['ret_time'] ?? ''));
        $pax = (int)($src['passengers'] ?? 0);
        $errors = [];
        if (!$dep) { $errors['dep_date'] = 'Enter a valid departure date and time.'; }
        if (!$ret) { $errors['ret_date'] = 'Enter a valid return date and time.'; }
        if ($pax < 1 || $pax > 100) { $errors['passengers'] = 'Enter the number of passengers (1 to 100).'; }
        if (!$errors) {
            if ($m = RequestRules::leadTimeProblem($dep, new DateTimeImmutable(), $settings->int('MIN_DAYS_AHEAD', 2))) { $errors['dep_date'] = $m; }
            elseif ($m = RequestRules::tripProblem($dep, $ret)) { $errors['ret_date'] = $m; }
        }
        if ($errors) {
            return [null, $errors];
        }
        return [[
            'departure' => $dep, 'return' => $ret, 'passengers' => $pax,
            'start_day' => $dep->format('Y-m-d'), 'end_day' => $ret->format('Y-m-d'),
        ], []];
    }

    private function tripQuery(array $trip): array
    {
        if (!$trip) { return []; }
        return [
            'dep_date' => $trip['departure']->format('Y-m-d'), 'dep_time' => $trip['departure']->format('H:i'),
            'ret_date' => $trip['return']->format('Y-m-d'),    'ret_time' => $trip['return']->format('H:i'),
            'passengers' => $trip['passengers'],
        ];
    }

    /** R4 re-check of the chosen vehicle. */
    private function vehicleErrors(int $vehicleId, array $trip): array
    {
        if ($vehicleId < 1) { return ['Please choose a vehicle.']; }
        $problem = (new AvailabilityService())->vehicleProblem($vehicleId, $trip['start_day'], $trip['end_day'], $trip['passengers']);
        return $problem ? ["That vehicle cannot be used: $problem"] : [];
    }

    /** The person who approves this user's requests (their HOD); null if none, then a head admin decides. */
    private function approverFor(int $userId): ?array
    {
        $me = (new User())->find($userId);
        return $me && $me['approver_id'] ? (new User())->find((int)$me['approver_id']) : null;
    }

    private function myPhone(): string
    {
        return (string)((new User())->find(Auth::id())['phone'] ?? '');
    }

    private function discardFiles(array $stored): void
    {
        foreach ($stored as $s) {
            @unlink($GLOBALS['config']['upload_dir'] . '/' . $s['file_path']);
        }
    }

    /** Status history from the timestamps on the request itself. */
    private function timeline(array $r): array
    {
        $t = [['Submitted', $r['submitted_at'], $r['requester_name'], null]];
        if ($r['approved_at'])  { $t[] = ['Approved', $r['approved_at'], $r['approver_name'] ?? 'Head admin', null]; }
        if ($r['status'] === RequestStatus::REJECTED) { $t[] = ['Rejected', $r['updated_at'], $r['approver_name'] ?? 'Head admin', $r['status_reason']]; }
        if ($r['status'] === RequestStatus::LAPSED)   { $t[] = ['Lapsed', $r['updated_at'], 'System', $r['status_reason']]; }
        if ($r['assigned_at'])  { $t[] = ['Driver assigned', $r['assigned_at'], 'Vehicle Section', null]; }
        if ($r['confirmed_at']) { $t[] = ['Confirmed', $r['confirmed_at'], 'Head admin', null]; }
        if ($r['completed_at']) { $t[] = ['Completed', $r['completed_at'], 'System', null]; }
        if ($r['cancelled_at']) { $t[] = ['Cancelled', $r['cancelled_at'], ($r['cancelled_by_name'] ?? 'Unknown') . ($r['cancelled_by_role'] ? ' (' . role_label($r['cancelled_by_role']) . ')' : ''), $r['status_reason']]; }
        usort($t, fn($a, $b) => strcmp($a[1], $b[1]));
        return $t;
    }
}
