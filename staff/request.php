<?php
// ============================================================
// staff/request.php  -  one request (UC017 step 3) and what the signed-in user can do with it
//   Requester / HoD / Assistant / Head of Admin  Cancel Request with a reason (UC021)
//   Head of Department                           Approve / Reject with a reason (UC025, UC028)
//   Head of Admin                                Approve / Reject Pay and Claim, Self Drive, Personal Self Drive (UC025 A2)
//                                                Approve / Reject the driver assignment (UC026)
//   Administrative Assistant                     Assign Request Task (UC003)
//   Pay and Claim / Personal, approved           Print Approval Letter (UC017 A3)
// ============================================================
$current_page = 'request.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireLogin();

$me = currentUser();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$r = $id ? loadRequest($conn, $id) : null;
if (!$r || !requestVisibleTo($conn, $r, $me)) {
    setFlash('danger', 'That request was not found.');
    header('Location: ' . roleHomeUrl());
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $reason = (string)($_POST['reason'] ?? '');
    $isHoa = $me['role'] === 'head_admin';
    $res = match ($act) {
        'cancel'          => cancelRequest($conn, $id, $me, $reason),
        'hod_approve'     => hodDecide($conn, $id, true),
        'hod_reject'      => hodDecide($conn, $id, false, $reason),
        'hoa_approve'     => $isHoa ? hoaDecideRequest($conn, $id, true) : wfFail('Only the Head of Admin can do this.'),
        'hoa_reject'      => $isHoa ? hoaDecideRequest($conn, $id, false, $reason) : wfFail('Only the Head of Admin can do this.'),
        'assign_approve'  => $isHoa ? hoaApproveAssignment($conn, $id) : wfFail('Only the Head of Admin can do this.'),
        'assign_reject'   => $isHoa ? hoaRejectAssignment($conn, $id, $reason) : wfFail('Only the Head of Admin can do this.'),
        default           => wfFail('Unknown action.'),
    };
    redirectWithResult($res, 'request.php?id=' . $id);
}

$uid = (int)$me['user_id'];
$actions = '';
if (hasApprovalLetter($r) && ((int)$r['requester_id'] === $uid || in_array($me['role'], ['admin', 'head_admin', 'supervisor'], true))) {
    $actions .= '<a class="btn btn-outline" href="approval_letter.php?id=' . $id . '" target="_blank" rel="noopener"><i class="fas fa-print"></i>Print Approval Letter</a>';
}
if (canCancelRequest($r, $me)) {
    $actions .= '<form method="post"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline btn-error" type="submit" data-reason="The reason is shown in the status history and sent to everyone involved." data-reason-title="Cancel request #' . $id . '?" data-reason-button="Cancel Request"><i class="fas fa-ban"></i>Cancel Request</button></form>';
}
$back = match ($me['role']) { 'admin', 'head_admin' => ((int)$r['requester_id'] === $uid ? 'my_requests.php' : '../admin/vehicle_requests.php'), 'supervisor' => ((int)$r['requester_id'] === $uid ? 'my_requests.php' : '../supervisor/approvals.php'), default => 'my_requests.php' };

adminPageStart('Request #' . $id);
pageHeader('Request #' . $id, 'file-lines', requestVehicleSummary($r) . ' to ' . $r['destination'], '<a class="btn btn-ghost" href="' . h($back) . '"><i class="fas fa-arrow-left"></i>Back</a>' . $actions);

// What is waiting for this user
$panel = '';
if (waitingForHod($r, $uid)) {
    $panel = '<p class="text-sm">This request is waiting for your approval as Head of Department. Check the details and the supporting letter.</p>'
           . '<form method="post" class="mt-3 flex flex-wrap gap-2"><button class="btn btn-success" name="action" value="hod_approve" type="submit"><i class="fas fa-check"></i>Approve</button>'
           . '<button class="btn btn-outline btn-error" name="action" value="hod_reject" type="submit" data-reason="The requester is told the reason. The vehicle reservation is released." data-reason-title="Reject request #' . $id . '?" data-reason-button="Reject"><i class="fas fa-xmark"></i>Reject</button></form>';
} elseif ($me['role'] === 'head_admin' && waitingForHoaRequest($r)) {
    $panel = '<p class="text-sm">This ' . h(driverOptionLabel($r['driver_option'])) . ($r['purpose_type'] === 'personal' ? ' Personal' : '') . ' request is waiting for your approval. No UIS driver is needed.</p>'
           . '<form method="post" class="mt-3 flex flex-wrap gap-2"><button class="btn btn-success" name="action" value="hoa_approve" type="submit"><i class="fas fa-check"></i>Approve</button>'
           . '<button class="btn btn-outline btn-error" name="action" value="hoa_reject" type="submit" data-reason="The requester is told the reason." data-reason-title="Reject request #' . $id . '?" data-reason-button="Reject"><i class="fas fa-xmark"></i>Reject</button></form>';
} elseif ($me['role'] === 'head_admin' && $r['status'] === 'driver_assigned') {
    $counts = driverMonthCounts($conn, substr($r['depart_at'], 0, 7));
    $list = '';
    foreach (requestTasks($conn, $id) as $t) {
        $c = $counts[(int)$t['driver_id']]['tasks'] ?? 0;
        $list .= '<li>' . h(ucfirst($t['trip_type'])) . ' ' . plate($t['plate_number']) . ': <strong>' . h($t['driver_name']) . '</strong> <span class="text-xs text-base-content/60">(' . $c . ' task(s) this month)</span></li>';
    }
    $panel = '<p class="text-sm">The Administrative Assistant assigned the drivers below. Check that they are available (for example, not on leave).</p><ul class="my-2 ms-5 list-disc text-sm">' . $list . '</ul>'
           . '<form method="post" class="mt-3 flex flex-wrap gap-2"><button class="btn btn-success" name="action" value="assign_approve" type="submit"><i class="fas fa-check"></i>Approve</button>'
           . '<button class="btn btn-outline btn-error" name="action" value="assign_reject" type="submit" data-reason="The task goes back to the Administrative Assistant to assign another driver. The request is not rejected and the vehicle reservation is kept." data-reason-title="Reject the driver assignment?" data-reason-button="Reject"><i class="fas fa-xmark"></i>Reject</button></form>';
} elseif (in_array($me['role'], ['admin', 'head_admin'], true) && waitingForAssignment($r)) {
    $panel = '<p class="text-sm">This request is waiting for driver assignment' . ($r['assign_reject_reason'] ? ' (the Head of Admin returned the last assignment: ' . h($r['assign_reject_reason']) . ')' : '') . '.</p>'
           . '<a class="btn btn-primary mt-3" href="../admin/assign_driver.php?id=' . $id . '"><i class="fas fa-user-plus"></i>Assign Request Task</a>';
}
if ($panel !== '') {
    echo '<div class="alert alert-soft alert-info mb-5 block"><div class="font-semibold"><i class="fas fa-hand-point-right me-1"></i>Your action</div>' . $panel . '</div>';
}
echo requestDetailsHtml($conn, $r, $me);
adminPageEnd();
