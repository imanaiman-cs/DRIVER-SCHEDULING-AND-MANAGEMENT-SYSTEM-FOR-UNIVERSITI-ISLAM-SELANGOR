<?php
// ============================================================
// admin/approvals.php  -  View Layer: ApprovalUI (SDD_CLASS_105) for the Head of Admin
//   BPI requests      UC025: requests from BPI (the Assistant, the drivers) - the Head of Admin approves as head of BPI
//   Requests          UC026 driver assignments (Urgent first) + UC025 A2 Pay and Claim, Self Drive, Personal Self Drive
//   Internal tasks    UC026 driver assignment of internal tasks
//   Welfare duty      UC026 weekly welfare duty schedules
//   Replacements      UC026 driver replacements (UC008)
// Every assignment shows the vehicles, drivers and each driver's task count this month. Reject needs a reason (UC028).
// ============================================================
$current_page = 'approvals.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireHeadAdmin();

$me = currentUser();
$ap = new ApprovalController();
$rc = new RequestController();
$tab = in_array($_GET['tab'] ?? '', ['head', 'internal', 'welfare', 'replacement'], true) ? $_GET['tab'] : 'request';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $reason = (string)($_POST['reason'] ?? '');
    $decision = str_ends_with($act, '_approve') ? 'Approve' : 'Reject';
    $kind = preg_replace('/_(approve|reject)$/', '', $act);
    $res = match ($kind) {
        'request'     => $ap->decideRequest($me, $id, $decision, $reason),
        'assign'      => $ap->decideAssignment($me, array_column($rc->getRequestDetails($id)['tasks'] ?? [], 'task_id'), $decision, $reason),
        'internal'    => $ap->decideAssignment($me, [$id], $decision, $reason),
        'welfare'     => $ap->decideAssignment($me, array_column((new TaskController())->getWelfareWeekByID($id), 'task_id'), $decision, $reason),
        'replacement' => $ap->decideReplacement($me, $id, $decision, $reason),
        default       => wfFail('Unknown action.'),
    };
    redirectWithResult($res, 'approvals.php?tab=' . $tab);
}

$lists = [];
foreach (['head', 'request', 'internal', 'welfare', 'replacement'] as $k) { $lists[$k] = $ap->listPending($me, $k); }
$buttons = function (string $kind, int $id, string $rejectText) {
    return '<form method="post" class="flex flex-wrap justify-end gap-2"><input type="hidden" name="id" value="' . $id . '">'
         . '<button class="btn btn-sm btn-success" name="action" value="' . $kind . '_approve" type="submit"><i class="fas fa-check"></i>Approve</button>'
         . '<button class="btn btn-sm btn-outline btn-error" name="action" value="' . $kind . '_reject" type="submit" data-reason="' . h($rejectText) . '" data-reason-title="Reject" data-reason-button="Reject"><i class="fas fa-xmark"></i>Reject</button></form>';
};

adminPageStart('Pending Approval');
pageHeader('Pending Approval', 'stamp', 'Approve or reject driver assignments, welfare duty schedules, driver replacements and requests. You know which drivers are on leave: check that the drivers are available.');
echo tabsNav(['request' => 'Requests', 'head' => 'BPI requests', 'internal' => 'Internal tasks', 'welfare' => 'Welfare duty', 'replacement' => 'Driver replacements'], $tab, fn($k) => '?tab=' . $k,
    array_map('count', $lists));

if ($tab === 'head') {
    echo '<p class="mb-3 text-sm text-base-content/65">Requests from BPI (the Administrative Assistant and the drivers) wait for you as the head of BPI. Open a request to approve or reject it.</p>';
    echo requestListTable($lists['head'], true, $rc, 'No BPI request is waiting for your approval.');
} elseif ($tab === 'request') {
    if (!$lists['request']) { echo '<div class="dash-empty"><i class="fas fa-circle-check"></i>Nothing is waiting for your approval.</div>'; }
    foreach ($lists['request'] as $r) {
        $id = (int)$r['request_id'];
        $d = $rc->getRequestDetails($id);
        echo '<section class="card mb-4 border border-base-300 bg-base-100"><div class="card-body gap-2"><div class="flex flex-wrap items-start justify-between gap-3"><div>'
           . '<a class="text-base font-bold link-hover" href="../staff/request.php?id=' . $id . '">Request #' . $id . '</a>' . urgentBadge($r) . ' ' . requestStatusBadge($r['status'])
           . '<div class="text-sm">' . h($r['requester_name']) . ' (' . h($r['department_name']) . ') &rarr; ' . h($r['destination']) . '</div>'
           . '<div class="text-xs text-base-content/65">' . h(fmtPeriod($r['depart_datetime'], $r['return_datetime'])) . ' &middot; ' . h($r['vehicle_type'] . ' x ' . $r['quantity'] . ', ' . $r['driver_option']) . ' &middot; ' . h($r['purpose_type'] . ': ' . $r['purpose']) . '</div></div>';
        if ($r['status'] === Request::DRIVER_ASSIGNED) {
            echo $buttons('assign', $id, 'The task goes back to the Administrative Assistant to assign another driver. The request is not rejected and the vehicle reservation is kept.') . '</div>';
            echo '<div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>Vehicle</th><th>Trip</th><th>Time</th><th>Driver</th><th class="text-end">Tasks this month</th></tr></thead><tbody>';
            foreach ($d['tasks'] as $t) {
                echo '<tr><td>' . plate($t['plate_no'] ?? $t['vendor_plate_no']) . ($t['vendor_name'] ? ' <span class="badge badge-warning badge-xs">vendor</span>' : '') . '</td><td>' . h($t['trip_type']) . '</td><td class="whitespace-nowrap">' . h(fmtPeriod($t['start_datetime'], $t['end_datetime'])) . '</td>'
                   . '<td>' . h($t['driver_name'] ?? $t['vendor_name'] . ' (vendor driver)') . '</td><td class="text-end">' . ($t['driver_id'] ? $ap->driverTaskCount((int)$t['driver_id'], $t['start_datetime']) : '') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo $buttons('request', $id, 'The requester is told the reason.') . '</div>';
            $extra = $r['purpose_type'] === Request::PERSONAL ? 'Personal: ' . number_format((float)$r['distance_km'], 1) . ' km, charge ' . money((float)$r['charge']) . ' (confirmed by the Administrative Assistant).'
                : 'Approved by ' . h($r['hod_name'] ?? 'the head of the department') . ' on ' . h(fmtDT($r['hod_approved_date'])) . '.';
            echo '<p class="text-sm">' . h($r['driver_option']) . ': no UIS driver is needed. ' . $extra . '</p>';
        }
        echo '</div></section>';
    }
} elseif ($tab === 'internal') {
    echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Date and time</th><th>Task</th><th>Vehicle</th><th>Driver</th><th>Description</th><th></th></tr></thead><tbody>';
    foreach ($lists['internal'] as $t) {
        echo '<tr>' . taskRowCells($t) . '<td>' . h($t['driver_name']) . '<div class="text-xs text-base-content/60">' . $ap->driverTaskCount((int)$t['driver_id'], $t['start_datetime']) . ' task(s) this month</div></td><td>' . h($t['description']) . '</td><td>'
           . $buttons('internal', (int)$t['task_id'], 'The task goes back to the Administrative Assistant to assign another driver.') . '</td></tr>';
    }
    if (!$lists['internal']) { echo emptyRow(6, 'No internal task is waiting for approval.'); }
    echo '</tbody></table></div></div>';
} elseif ($tab === 'welfare') {
    if (!$lists['welfare']) { echo '<div class="dash-empty"><i class="fas fa-circle-check"></i>No welfare duty schedule is waiting for approval.</div>'; }
    $tc = new TaskController();
    foreach ($lists['welfare'] as $w) {
        $days = $tc->getWelfareWeek($w['week_start_date'])['days'];
        echo '<section class="card mb-4 border border-base-300 bg-base-100"><div class="card-body gap-2"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-base font-bold">Week of ' . h(date('d M Y', strtotime($w['week_start_date']))) . '</h2>'
           . $buttons('welfare', (int)$w['duty_id'], 'The schedule goes back to the Administrative Assistant.') . '</div>';
        echo '<div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>Day</th><th>Vehicle</th><th>Driver</th><th class="text-end">Tasks this month</th><th>Status</th></tr></thead><tbody>';
        foreach ($days as $d => $t) {
            echo '<tr><td class="whitespace-nowrap">' . h(date('l, d M', strtotime($d))) . '</td>' . ($t ? '<td>' . plate($t['plate_no']) . '</td><td>' . h($t['driver_name'] ?? '-') . '</td><td class="text-end">'
               . ($t['driver_id'] ? $ap->driverTaskCount((int)$t['driver_id'], $t['start_datetime']) : '') . '</td><td>' . taskStatusBadge($t['status']) . '</td>' : '<td colspan="4" class="text-base-content/50">No duty</td>') . '</tr>';
        }
        echo '</tbody></table></div></div></section>';
    }
} else {
    echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Task</th><th>Old driver</th><th>New driver</th><th>Reason</th><th></th></tr></thead><tbody>';
    foreach ($lists['replacement'] as $rp) {
        $t = $ap->replacementTask($rp);
        echo '<tr><td>' . h(taskTypeLabel($t)) . ($t['request_id'] ? ' <a class="link text-xs" href="../staff/request.php?id=' . (int)$t['request_id'] . '">#' . (int)$t['request_id'] . '</a>' : '') . '<div class="text-xs text-base-content/60">' . h(fmtPeriod($t['start_datetime'], $t['end_datetime'])) . ' &middot; ' . h($t['plate_no']) . '</div></td>'
           . '<td>' . h($rp['old_driver_name']) . '</td><td>' . h($rp['new_driver_name']) . '<div class="text-xs text-base-content/60">' . $ap->driverTaskCount((int)$rp['new_driver_id'], $t['start_datetime']) . ' task(s) this month</div></td>'
           . '<td>' . h($rp['reason']) . '<div class="text-xs text-base-content/60">' . h(fmtDT($rp['date_time'])) . '</div></td><td>'
           . $buttons('replacement', (int)$rp['replacement_id'], 'The Administrative Assistant is told the reason. The old driver stays on the task.') . '</td></tr>';
    }
    if (!$lists['replacement']) { echo emptyRow(5, 'No driver replacement is waiting for approval.'); }
    echo '</tbody></table></div></div>';
}
adminPageEnd();
