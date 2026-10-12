<?php
/**
 * RemindersController - UC007 Remind approvers.
 * A request that is not approved lapses after 48 hours, so the Vehicle Section can nudge the
 * approver. Reminders are limited to one every 4 hours per request so nobody is spammed.
 */
class RemindersController extends Controller
{
    private const MIN_HOURS_BETWEEN = 4;

    public function index(): void
    {
        $this->adminOnly();
        $lapse = (new Setting())->int('LAPSE_HOURS', 48);
        $this->view('reminders/index', ['title' => 'Reminders', 'rows' => (new Reminder())->pendingWithStatus($lapse), 'lapseHours' => $lapse, 'minHours' => self::MIN_HOURS_BETWEEN]);
    }

    public function send(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $id = $this->postInt('id');
        $request = (new TripRequest())->find($id);
        if (!$request || $request['status'] !== RequestStatus::PENDING) {
            $this->flashRedirect('warning', 'That request is no longer waiting for approval.', 'reminders/index');
        }
        $reminders = new Reminder();
        $last = $reminders->lastSent($id);
        if ($last && strtotime($last) > time() - self::MIN_HOURS_BETWEEN * 3600) {
            $this->flashRedirect('warning', 'A reminder was already sent in the last ' . self::MIN_HOURS_BETWEEN . ' hours.', 'reminders/index');
        }

        $who = $request['approver_id'] ? [(int)$request['approver_id']] : (new User())->activeAdminIds(true);
        NotificationService::notify($who, "Reminder: request #$id from {$request['requester_name']} to {$request['destination_name']} is waiting for your approval and will lapse soon.", page_link('requests/show', ['id' => $id]));
        $reminders->add($id, (int)Auth::id());
        (new AuditLog())->record('reminder', $id, 'Reminder sent to the approver');
        $this->flashRedirect('success', "Reminder sent for request #$id.", 'reminders/index');
    }
}
