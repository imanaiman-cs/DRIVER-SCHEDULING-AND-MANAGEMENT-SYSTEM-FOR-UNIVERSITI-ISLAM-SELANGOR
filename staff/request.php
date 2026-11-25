<?php
// ============================================================
// staff/request.php  -  View Layer: RequestUI (SDD_CLASS_104) displayRequestDetails(), displayCancelForm()
//                       and ApprovalUI (SDD_CLASS_105) submitDecision() for one request
//   everyone allowed           Cancel Request with a reason (UC021)            -> RequestController::cancelRequest
//   head of the department     Approve / Reject with a reason (UC025, UC028)    -> ApprovalController::decideRequest
//   Head of Admin              Approve / Reject Pay and Claim, Self Drive, Personal Self Drive (UC025 A2)
//                              Approve / Reject the driver assignment (UC026)    -> ApprovalController::decideAssignment
//   Administrative Assistant   Assign Request Task (UC003)
//   approved Pay and Claim / Personal: Print Approval Letter (UC017 A3)
// ============================================================
$current_page = 'request.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireLogin();

$me = currentUser();
$rc = new RequestController();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$r = $id ? $rc->getRequestDetails($id) : null;
if (!$r || !$rc->isVisibleTo($r, $me)) {
    setFlash('danger', 'That request was not found.');
    header('Location: ' . roleHomeUrl());
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ap = new ApprovalController();
    $act = (string)($_POST['action'] ?? '');
    $reason = (string)($_POST['reason'] ?? '');
    $res = match ($act) {
        'cancel'          => $rc->cancelRequest($me, $id, $reason),
        'approve'         => $ap->decideRequest($me, $id, 'Approve'),
        'reject'          => $ap->decideRequest($me, $id, 'Reject', $reason),
        'assign_approve'  => $me['role'] === User::HOA ? $ap->decideAssignment($me, array_column($r['tasks'], 'task_id'), 'Approve') : wfFail('Only the Head of Admin can do this.'),
        'assign_reject'   => $me['role'] === User::HOA ? $ap->decideAssignment($me, array_column($r['tasks'], 'task_id'), 'Reject', $reason) : wfFail('Only the Head of Admin can do this.'),
        default           => wfFail('Unknown action.'),
    };
    redirectWithResult($res, 'request.php?id=' . $id);
}

$uid = (int)$me['user_id'];
$isAdmin = in_array($me['role'], [User::AA, User::HOA], true);
$actions = '';
if ($rc->hasApprovalLetter($r) && ((int)$r['requester_id'] === $uid || $isAdmin || $me['role'] === User::HOD)) {
    $actions .= '<a class="btn btn-outline" href="approval_letter.php?id=' . $id . '" target="_blank" rel="noopener"><i class="fas fa-print"></i>Print Approval Letter</a>';
}
if ($rc->canCancel($r, $me)) {
    $actions .= '<form method="post"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline btn-error" type="submit" data-reason="The reason is shown in the status history and sent to everyone involved." data-reason-title="Cancel request #' . $id . '?" data-reason-button="Cancel Request"><i class="fas fa-ban"></i>Cancel Request</button></form>';
}
$back = (int)$r['requester_id'] === $uid ? 'my_requests.php' : ($isAdmin ? '../admin/vehicle_requests.php' : '../supervisor/approvals.php');

adminPageStart('Request #' . $id);
pageHeader('Request #' . $id, 'file-lines', $r['vehicle_type'] . ' x ' . $r['quantity'] . ', ' . $r['driver_option'] . ' to ' . $r['destination'], '<a class="btn btn-ghost" href="' . h($back) . '"><i class="fas fa-arrow-left"></i>Back</a>' . $actions);

$decide = function (string $text, string $approve, string $reject, string $rejectText) {
    return '<p class="text-sm">' . $text . '</p><form method="post" class="mt-3 flex flex-wrap gap-2"><button class="btn btn-success" name="action" value="' . $approve . '" type="submit"><i class="fas fa-check"></i>Approve</button>'
         . '<button class="btn btn-outline btn-error" name="action" value="' . $reject . '" type="submit" data-reason="' . h($rejectText) . '" data-reason-title="Reject" data-reason-button="Reject"><i class="fas fa-xmark"></i>Reject</button></form>';
};
$panel = '';
if ($rc->isWaitingForHead($r, $uid)) {
    $panel = $decide('This request is waiting for your approval as the head of the department. Check the details and the supporting letter.', 'approve', 'reject', 'The requester is told the reason. The vehicle reservation is released.');
} elseif ($me['role'] === User::HOA && $rc->isWaitingForHoa($r)) {
    $panel = $decide('This ' . h($r['driver_option']) . ($r['purpose_type'] === Request::PERSONAL ? ' Personal' : '') . ' request is waiting for your approval. No UIS driver is needed.', 'approve', 'reject', 'The requester is told the reason.');
} elseif ($me['role'] === User::HOA && $r['status'] === Request::DRIVER_ASSIGNED) {
    $list = '';
    foreach ($r['tasks'] as $t) {
        $list .= '<li>' . h($t['trip_type']) . ' ' . plate($t['plate_no'] ?? $t['vendor_plate_no']) . ': <strong>' . h($t['driver_name'] ?? $t['vendor_name'] . ' (vendor)') . '</strong>'
               . ($t['driver_id'] ? ' <span class="text-xs text-base-content/60">(' . (new ApprovalController())->driverTaskCount((int)$t['driver_id'], $t['start_datetime']) . ' task(s) this month)</span>' : '') . '</li>';
    }
    $panel = $decide('The Administrative Assistant assigned the vehicles and drivers below. Check that the drivers are available (for example, not on leave).</p><ul class="my-2 ms-5 list-disc text-sm">' . $list . '</ul><p class="text-sm">',
        'assign_approve', 'assign_reject', 'The task goes back to the Administrative Assistant to assign another driver. The request is not rejected and the vehicle reservation is kept.');
} elseif ($isAdmin && $rc->isWaitingForAssignment($r)) {
    $panel = '<p class="text-sm">This request is waiting for driver assignment.</p><a class="btn btn-primary mt-3" href="../admin/assign_driver.php?id=' . $id . '"><i class="fas fa-user-plus"></i>Assign Request Task</a>';
}
if ($panel !== '') { echo '<div class="alert alert-soft alert-info mb-5 block"><div class="font-semibold"><i class="fas fa-hand-point-right me-1"></i>Your action</div>' . $panel . '</div>'; }
echo requestDetailsHtml($r, $me, $rc);
adminPageEnd();
