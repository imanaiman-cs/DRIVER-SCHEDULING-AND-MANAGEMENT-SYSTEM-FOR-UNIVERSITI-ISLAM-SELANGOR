<?php
/**
 * ApprovalsController - UC004 Approve / reject a request.
 * The approver is the requester's HOD. (A requester with no HOD, such as an HOD themselves,
 * is approved by the head admin - see RequestPolicy::canApprove.)
 */
class ApprovalsController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('hod', 'head_admin');
        $user = Auth::user();
        $model = new TripRequest();
        // HOD: requests assigned to them. Head admin: requests that have no HOD above the requester.
        $filters = $user['role'] === 'hod' ? ['approver_id' => $user['id']] : ['no_approver' => true];
        [$pending] = $model->search($filters + ['status' => RequestStatus::PENDING], 100, 0);
        [$recent]  = $model->search($filters + ['decided' => true], 15, 0);
        $this->view('approvals/index', ['title' => 'Approvals', 'pending' => $pending, 'recent' => $recent, 'lapseHours' => (new Setting())->int('LAPSE_HOURS', 48)]);
    }

    public function approve(): void
    {
        Auth::requireRole('hod', 'head_admin');       // who you are is checked before anything else
        $this->requirePost();
        [$model, $request] = $this->loadForDecision();

        $user = Auth::user();
        $model->changeStatus((int)$request['id'], RequestStatus::APPROVED, 'approve', null, [], (int)$user['id'], $user['role']);

        $link = page_link('requests/show', ['id' => $request['id']]);
        NotificationService::notify((int)$request['requester_id'], "Your request #{$request['id']} to {$request['destination_name']} was approved by {$user['name']}. The Vehicle Section will arrange a driver.", $link);
        NotificationService::notify((new User())->activeAdminIds(), "Request #{$request['id']} ({$request['destination_name']}, " . fmt_datetime($request['departure_datetime']) . ') is approved and needs a driver.', $link);
        $this->flashRedirect('success', "Request #{$request['id']} approved.", 'approvals/index');
    }

    public function reject(): void
    {
        Auth::requireRole('hod', 'head_admin');
        $this->requirePost();
        [$model, $request] = $this->loadForDecision();

        $reason = $this->post('reason');
        if (mb_strlen($reason) < 3) {
            $this->flashRedirect('danger', 'Please enter the reason for rejecting.', 'requests/show', ['id' => $request['id']]);
        }
        $reason = mb_substr($reason, 0, 500);
        $user = Auth::user();
        $model->changeStatus((int)$request['id'], RequestStatus::REJECTED, 'reject', $reason, [], (int)$user['id'], $user['role']);

        NotificationService::notify((int)$request['requester_id'], "Your request #{$request['id']} was rejected by {$user['name']}: $reason", page_link('requests/show', ['id' => $request['id']]));
        $this->flashRedirect('success', "Request #{$request['id']} rejected.", 'approvals/index');
    }

    /** Load the request and check the current user may decide on it (role check on every action). */
    private function loadForDecision(): array
    {
        Auth::requireRole('hod', 'head_admin');
        $model = new TripRequest();
        $request = $model->find($this->postInt('id'));
        if (!$request || !RequestPolicy::canApprove(Auth::user(), $request)) {
            http_response_code(403);
            View::render('errors/403', ['title' => 'Access denied']);
            exit;
        }
        return [$model, $request];
    }
}
