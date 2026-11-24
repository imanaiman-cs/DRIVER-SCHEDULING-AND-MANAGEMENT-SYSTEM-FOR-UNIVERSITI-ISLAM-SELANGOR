<?php
// ============================================================
// admin/approvals.php  -  Pending Approval of the Head of Admin
//   Requests        UC026 driver assignments of request tasks (Urgent first) + UC025 A2 Pay and Claim,
//                   Self Drive and Personal Self Drive requests
//   Internal tasks  UC026 driver assignment of internal tasks
//   Welfare duty    UC026 weekly welfare duty schedules
//   Replacements    UC026 driver replacements (UC008)
// Every task shows the assigned vehicles, drivers and each driver's task count this month.
// Reject needs a reason (UC028); a rejected assignment goes back to the Administrative Assistant.
// ============================================================
$current_page = 'approvals.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireHeadAdmin();

$tab = in_array($_GET['tab'] ?? '', ['internal', 'welfare', 'replacement'], true) ? $_GET['tab'] : 'request';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $reason = (string)($_POST['reason'] ?? '');
    $approve = str_ends_with($act, '_approve');
    $res = match (preg_replace('/_(approve|reject)$/', '', $act)) {
        'assign'      => $approve ? hoaApproveAssignment($conn, $id) : hoaRejectAssignment($conn, $id, $reason),
        'request'     => hoaDecideRequest($conn, $id, $approve, $reason),
        'internal'    => hoaDecideTask($conn, $id, $approve, $reason),
        'welfare'     => hoaDecideWelfare($conn, $id, $approve, $reason),
        'replacement' => hoaDecideReplacement($conn, $id, $approve, $reason),
        default       => wfFail('Unknown action.'),
    };
    redirectWithResult($res, 'approvals.php?tab=' . $tab);
}

$month = date('Y-m');
$countsCache = [];
$taskCount = function (int $driver_id, string $dt) use (&$countsCache, $conn): int {
    $m = substr($dt, 0, 7);
    $countsCache[$m] ??= driverMonthCounts($conn, $m);
    return (int)($countsCache[$m][$driver_id]['tasks'] ?? 0);
};
$buttons = function (string $kind, int $id, string $rejectText) {
    return '<form method="post" class="flex flex-wrap justify-end gap-2"><input type="hidden" name="id" value="' . $id . '">'
         . '<button class="btn btn-sm btn-success" name="action" value="' . $kind . '_approve" type="submit"><i class="fas fa-check"></i>Approve</button>'
         . '<button class="btn btn-sm btn-outline btn-error" name="action" value="' . $kind . '_reject" type="submit" data-reason="' . h($rejectText) . '" data-reason-title="Reject" data-reason-button="Reject"><i class="fas fa-xmark"></i>Reject</button></form>';
};

$requests = dbAll($conn, requestSelectSql() . ' WHERE ' . SQL_WAITING_HOA . ' ORDER BY r.is_urgent DESC, r.depart_at');
$internal = dbAll($conn, "SELECT t.*, v.plate_number, v.brand, v.model, u.full_name AS driver_name FROM tasks t JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                          JOIN drivers d ON d.driver_id = t.driver_id JOIN users u ON u.user_id = d.user_id
                          WHERE t.task_source = 'internal' AND t.status = 'driver_assigned' ORDER BY t.start_dt");
$weeks = dbAll($conn, "SELECT * FROM welfare_weeks WHERE status = 'driver_assigned' ORDER BY week_start_date");
$repls = dbAll($conn, "SELECT rp.*, pu.full_name AS prev_name, nu.full_name AS new_name, cu.full_name AS created_name
                         FROM driver_replacements rp JOIN drivers nd ON nd.driver_id = rp.new_driver_id JOIN users nu ON nu.user_id = nd.user_id
                         LEFT JOIN drivers pd ON pd.driver_id = rp.previous_driver_id LEFT JOIN users pu ON pu.user_id = pd.user_id
                         LEFT JOIN users cu ON cu.user_id = rp.created_by WHERE rp.status = 'pending' ORDER BY rp.date_time");

adminPageStart('Pending Approval');
pageHeader('Pending Approval', 'stamp', 'Approve or reject driver assignments, welfare duty schedules, driver replacements, and requests that need no UIS driver. You know which drivers are on leave: check that the drivers are available.');
echo tabsNav(['request' => 'Requests', 'internal' => 'Internal tasks', 'welfare' => 'Welfare duty', 'replacement' => 'Driver replacements'], $tab, fn($k) => '?tab=' . $k,
    ['request' => count($requests), 'internal' => count($internal), 'welfare' => count($weeks), 'replacement' => count($repls)]);

if ($tab === 'request') {
    if (!$requests) { echo '<div class="dash-empty"><i class="fas fa-circle-check"></i>Nothing is waiting for your approval.</div>'; }
    foreach ($requests as $r) {
        $id = (int)$r['request_id'];
        echo '<section class="card mb-4 border border-base-300 bg-base-100"><div class="card-body gap-2"><div class="flex flex-wrap items-start justify-between gap-3"><div>'
           . '<a class="text-base font-bold link-hover" href="../staff/request.php?id=' . $id . '">Request #' . $id . '</a>' . urgentBadge($r) . ' ' . requestStatusBadge($r['status'])
           . '<div class="text-sm">' . h($r['requester_name']) . ' (' . h($r['department_name'] ?? '-') . ') &rarr; ' . h($r['destination']) . '</div>'
           . '<div class="text-xs text-base-content/65">' . h(fmtPeriod($r['depart_at'], $r['return_at'])) . ' &middot; ' . h(requestVehicleSummary($r)) . ' &middot; ' . h(purposeTypeLabel($r['purpose_type'])) . ': ' . h($r['purpose']) . '</div></div>';
        if ($r['status'] === 'driver_assigned') {
            echo $buttons('assign', $id, 'The task goes back to the Administrative Assistant to assign another driver. The request is not rejected and the vehicle reservation is kept.') . '</div>';
            echo '<div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>Vehicle</th><th>Trip</th><th>Time</th><th>Driver</th><th class="text-end">Tasks this month</th></tr></thead><tbody>';
            foreach (requestTasks($conn, $id) as $t) {
                echo '<tr><td>' . plate($t['plate_number']) . '</td><td>' . h(ucfirst($t['trip_type'])) . '</td><td class="whitespace-nowrap">' . h(fmtPeriod($t['start_dt'], $t['end_dt'])) . '</td><td>' . h($t['driver_name']) . '</td><td class="text-end">' . $taskCount((int)$t['driver_id'], $t['start_dt']) . '</td></tr>';
            }
            foreach (array_filter(requestVehicles($conn, $id), fn($v) => $v['is_vendor']) as $v) {
                echo '<tr><td><span class="badge badge-warning badge-sm">Vendor</span> ' . plate($v['vendor_plate']) . '</td><td colspan="4" class="text-xs">' . h($v['vendor_name']) . ' (vendor driver)</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo $buttons('request', $id, 'The requester is told the reason.') . '</div>';
            $extra = $r['purpose_type'] === 'personal' ? 'Personal: ' . number_format((float)$r['distance_km'], 1) . ' km, charge ' . money((float)$r['charge']) . ' (confirmed by the Administrative Assistant).' : 'Approved by the Head of Department ' . h($r['hod_approved_name'] ?? '') . ' on ' . h(fmtDT($r['hod_approved_at'])) . '.';
            echo '<p class="text-sm">' . h(driverOptionLabel($r['driver_option'])) . ': no UIS driver is needed. ' . $extra . '</p>';
        }
        echo '</div></section>';
    }
} elseif ($tab === 'internal') {
    echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Date and time</th><th>Task</th><th>Vehicle</th><th>Driver</th><th>Description</th><th></th></tr></thead><tbody>';
    foreach ($internal as $t) {
        echo '<tr>' . taskRowCells($t) . '<td>' . h($t['driver_name']) . '<div class="text-xs text-base-content/60">' . $taskCount((int)$t['driver_id'], $t['start_dt']) . ' task(s) this month</div></td><td>' . h($t['description']) . '</td><td>'
           . $buttons('internal', (int)$t['task_id'], 'The task goes back to the Administrative Assistant to assign another driver.') . '</td></tr>';
    }
    if (!$internal) { echo emptyRow(6, 'No internal task is waiting for approval.'); }
    echo '</tbody></table></div></div>';
} elseif ($tab === 'welfare') {
    if (!$weeks) { echo '<div class="dash-empty"><i class="fas fa-circle-check"></i>No welfare duty schedule is waiting for approval.</div>'; }
    foreach ($weeks as $w) {
        $days = welfareDays($conn, $w, $w['week_start_date']);
        echo '<section class="card mb-4 border border-base-300 bg-base-100"><div class="card-body gap-2"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-base font-bold">Week of ' . h(date('d M Y', strtotime($w['week_start_date']))) . '</h2>'
           . $buttons('welfare', (int)$w['welfare_id'], 'The schedule goes back to the Administrative Assistant.') . '</div>';
        echo '<div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>Day</th><th>Vehicle</th><th>Driver</th><th class="text-end">Tasks this month</th><th>Status</th></tr></thead><tbody>';
        foreach ($days as $d => $t) {
            echo '<tr><td class="whitespace-nowrap">' . h(date('l, d M', strtotime($d))) . '</td>' . ($t ? '<td>' . plate($t['plate_number']) . '</td><td>' . h($t['driver_name'] ?? '-') . '</td><td class="text-end">' . ($t['driver_id'] ? $taskCount((int)$t['driver_id'], $t['start_dt']) : '') . '</td><td>' . taskStatusBadge($t['status']) . '</td>' : '<td colspan="4" class="text-base-content/50">No duty</td>') . '</tr>';
        }
        echo '</tbody></table></div></div></section>';
    }
} else {
    echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Task</th><th>Previous driver</th><th>New driver</th><th>Reason</th><th></th></tr></thead><tbody>';
    foreach ($repls as $rp) {
        $t = loadTask($conn, (int)$rp['task_id']);
        echo '<tr><td>' . h(taskTypeLabel($t)) . ($t['request_id'] ? ' <a class="link text-xs" href="../staff/request.php?id=' . (int)$t['request_id'] . '">#' . (int)$t['request_id'] . '</a>' : '') . '<div class="text-xs text-base-content/60">' . h(fmtPeriod($t['start_dt'], $t['end_dt'])) . ' &middot; ' . h($t['plate_number']) . '</div></td>'
           . '<td>' . h($rp['prev_name'] ?? '-') . '</td><td>' . h($rp['new_name']) . '<div class="text-xs text-base-content/60">' . $taskCount((int)$rp['new_driver_id'], $t['start_dt']) . ' task(s) this month</div></td>'
           . '<td>' . h($rp['reason']) . '<div class="text-xs text-base-content/60">' . h($rp['created_name'] ?? '') . ', ' . h(fmtDT($rp['date_time'])) . '</div></td><td>'
           . $buttons('replacement', (int)$rp['replacement_id'], 'The Administrative Assistant is told the reason. The previous driver stays on the task.') . '</td></tr>';
    }
    if (!$repls) { echo emptyRow(5, 'No driver replacement is waiting for approval.'); }
    echo '</tbody></table></div></div>';
}
adminPageEnd();
