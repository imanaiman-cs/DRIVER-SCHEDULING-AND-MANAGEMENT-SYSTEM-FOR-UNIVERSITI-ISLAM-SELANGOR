<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/approve_assignments.php  -  UC019 Approve Driver Assignment / UC020 Reject Driver Assignment
//
// Only the Head of Admin. EVERY assignment is approved here, also when the Head of Admin made it himself
// (there is no automatic confirmation).
//   Approve -> Confirmed: the driver is notified first, then the staff member (with driver and vehicle details)
//   A request WITHOUT driver (UC019 A2) shows only the vehicle; approving it confirms the use of the vehicle
//   Reject  -> a reason is required; the driver is removed from the tasks, the request goes back to Approved,
//              the reason is recorded and the Administrative Assistant is notified
// ============================================================
$current_page = 'approve_assignments.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
requireHeadAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)($_POST['request_id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    $tid = (int)($_POST['task_id'] ?? 0);
    $wk = (string)($_POST['week'] ?? '');
    if (isValidDate($wk)) {   // UC026: welfare duty week
        $wk = welfareWeekStart($wk);
        $res = $act === 'approve' ? approveWelfareWeek($conn, $wk, (int)$_SESSION['user_id']) : rejectWelfareWeek($conn, $wk, (string)($_POST['reason'] ?? ''));
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($tid > 0) {          // UC026: internal task
        $res = $act === 'approve' ? approveInternalTask($conn, $tid, (int)$_SESSION['user_id']) : rejectInternalTask($conn, $tid, (string)($_POST['reason'] ?? ''));
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($act === 'approve') {
        $res = approveAssignment($conn, $rid);
        setFlash($res['ok'] ? 'success' : 'danger', $res['ok'] ? "Request #$rid is Confirmed. The driver (if any) and the staff member have been told." : $res['message']);
    } elseif ($act === 'reject') {
        $res = rejectAssignment($conn, $rid, (string)($_POST['reason'] ?? ''));
        setFlash($res['ok'] ? 'success' : 'danger', $res['ok'] ? "Request #$rid was rejected. The Administrative Assistant has been told." : $res['message']);
    }
    header('Location: approve_assignments.php');
    exit();
}

$requests = $conn->query(requestSelectSql() . " WHERE vr.status = 'assigned' ORDER BY vr.is_urgent DESC, vr.depart_at, vr.request_id")->fetch_all(MYSQLI_ASSOC);
$open = (int)($_GET['open'] ?? 0);
$welfareWeeks = $conn->query("SELECT DISTINCT DATE_SUB(task_date, INTERVAL WEEKDAY(task_date) DAY) AS monday FROM tasks WHERE task_source = 'welfare' AND status = 'assigned' ORDER BY monday")->fetch_all(MYSQLI_ASSOC);
$tasks = $conn->query("SELECT t.*, d.name AS driver_name, v.plate_number FROM tasks t JOIN drivers d ON d.driver_id = t.driver_id LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                        WHERE t.task_source = 'internal' AND t.status = 'assigned' ORDER BY t.start_dt")->fetch_all(MYSQLI_ASSOC);

adminPageStart('Approve Assignments');
?>
    <h1 class="text-2xl font-medium mb-1">Approve Assignments</h1>
    <p class="text-base-content/60 text-sm mb-4">Vehicles and drivers chosen by the Administrative Assistant, and Pay and Claim requests (own vehicle) sent by the Head of Department. The driver and the staff member are told only when you approve. Rejecting a Pay and Claim request ends it.</p>

    <?php if (!$requests && !$tasks && !$welfareWeeks): ?><div class="card border border-base-300 bg-base-100"><div class="card-body text-center text-base-content/60 py-12"><i class="fas fa-circle-check fa-2x text-success mb-2"></i><div>Nothing is waiting for your approval.</div></div></div><?php endif; ?>

    <?php foreach ($requests as $r): $rid = (int)$r['request_id']; ?>
    <div class="card border border-base-300 bg-base-100 mb-4"><div class="card-body">
        <div class="flex flex-wrap justify-between gap-2 mb-2">
            <div class="font-bold">Request #<?= $rid ?> &middot; <?= htmlspecialchars($r['destination']) ?></div>
            <span><?= !empty($r['is_urgent']) ? '<span class="badge badge-soft badge-error me-1">Urgent</span>' : '' ?><?= isPersonalRental($r) ? '<span class="badge badge-soft badge-info me-1">Personal Rental</span>' : '' ?><?= isPayClaimRequest($r) ? '<span class="badge badge-soft badge-warning me-1">Pay and Claim</span>' : (isRequestWithoutDriver($r) ? '<span class="badge badge-soft badge-neutral me-1">Without driver</span>' : '') ?><?= requestStatusBadge($r['status']) ?></span>
        </div>
        <?= requestDetailsHtml($conn, $r, ['show_assignment' => true]) ?>
        <?php $cnt = driverMonthCounts($conn, substr(requestDepartDT($r), 0, 7));          // UC026 step 2.1: each driver's task count this month
              $dq = $conn->prepare("SELECT DISTINCT d.driver_id, d.name FROM tasks s JOIN drivers d ON d.driver_id = s.driver_id WHERE s.task_source = 'request' AND s.request_id = ? AND s.status <> 'cancelled'");
              $dq->bind_param('i', $rid); $dq->execute(); $ds = $dq->get_result()->fetch_all(MYSQLI_ASSOC); $dq->close(); ?>
        <?php if ($ds): ?><div class="text-sm text-base-content/60 mt-2">Tasks this month: <?php foreach ($ds as $d): ?><span class="badge badge-ghost me-1"><?= htmlspecialchars($d['name']) ?>: <?= (int)($cnt[(int)$d['driver_id']]['tasks'] ?? 0) ?></span><?php endforeach; ?></div><?php endif; ?>
        <div class="grid-12 grid grid-cols-12 gap-4 mt-1 border-t border-base-300 pt-4">
            <form method="post" class="md:col-span-5">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="approve">
                <button class="btn btn-success" data-confirm="<?= isPayClaimRequest($r) ? 'The staff member is told that they may use their own vehicle and make the claim.' : (isRequestWithoutDriver($r) ? 'The staff member is told that the vehicle may be used without a driver.' : 'The driver is told first, then the staff member.') ?>" data-confirm-title="Approve this assignment?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve (Confirm)</button>
            </form>
            <form method="post" class="col-span-12 md:col-span-7">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="reject">
                <div class="join w-full">
                    <input class="input w-full join-item" name="reason" maxlength="500" required placeholder="Reason for rejecting (required)">
                    <button class="btn btn-outline btn-error join-item"><i class="fas fa-xmark me-1"></i>Reject</button>
                </div>
            </form>
        </div>
    </div></div>
    <?php endforeach; ?>

    <?php if ($tasks): ?><h2 class="text-lg font-medium mt-6 mb-2"><i class="fas fa-wrench text-success me-2"></i>Internal tasks</h2><?php endif; ?>
    <?php foreach ($tasks as $t): $tid = (int)$t['task_id']; ?>
    <div class="card border border-base-300 bg-base-100 mb-4"><div class="card-body">
        <div class="flex flex-wrap justify-between gap-2 mb-2">
            <div class="font-bold">Internal task #<?= $tid ?> &middot; <?= htmlspecialchars(internalTaskTypeLabel($t['task_type'])) ?></div>
            <span class="badge badge-soft badge-info">Driver Assigned</span>
        </div>
        <div class="text-sm text-base-content/60 mb-2"><?= date('D, d M Y', strtotime($t['task_date'])) ?> <?= date('H:i', strtotime($t['start_dt'])) ?>&ndash;<?= date('H:i', strtotime($t['end_dt'])) ?> &middot; <?= $t['plate_number'] ? plate($t['plate_number']) : 'no vehicle' ?> &middot; Driver: <strong><?= htmlspecialchars($t['driver_name']) ?></strong> (<?= (int)(driverMonthCounts($conn, substr($t['task_date'], 0, 7))[(int)$t['driver_id']]['tasks'] ?? 0) ?> tasks this month)<?= $t['location'] ? ' &middot; ' . htmlspecialchars($t['location']) : '' ?></div>
        <div class="grid-12 grid grid-cols-12 gap-4 border-t border-base-300 pt-4">
            <form method="post" class="md:col-span-5">
                <input type="hidden" name="task_id" value="<?= $tid ?>"><input type="hidden" name="action" value="approve">
                <button class="btn btn-success" data-confirm="The driver is told about the task." data-confirm-title="Approve this internal task?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve</button>
            </form>
            <form method="post" class="md:col-span-7">
                <input type="hidden" name="task_id" value="<?= $tid ?>"><input type="hidden" name="action" value="reject">
                <div class="join w-full">
                    <input class="input w-full join-item" name="reason" maxlength="255" required placeholder="Reason for rejecting (required)">
                    <button class="btn btn-outline btn-error join-item"><i class="fas fa-xmark me-1"></i>Reject</button>
                </div>
            </form>
        </div>
    </div></div>
    <?php endforeach; ?>

    <?php if ($welfareWeeks): ?><h2 class="text-lg font-medium mt-6 mb-2"><i class="fas fa-moon text-success me-2"></i>Welfare duty</h2><?php endif; ?>
    <?php foreach ($welfareWeeks as $ww): $mon = $ww['monday']; $wrows = welfareWeekRows($conn, $mon); $wc = driverMonthCounts($conn, substr($mon, 0, 7)); ?>
    <div class="card border border-base-300 bg-base-100 mb-4"><div class="card-body">
        <div class="flex flex-wrap justify-between gap-2 mb-2">
            <div class="font-bold">Welfare duty &middot; week of <?= htmlspecialchars(date('d M Y', strtotime($mon))) ?></div>
            <span class="badge badge-soft badge-info">Driver Assigned</span>
        </div>
        <div class="overflow-x-auto"><table class="table table-sm align-middle">
            <thead><tr><th>Date</th><th>Vehicle</th><th>Driver</th><th class="text-center">Tasks this month</th></tr></thead>
            <tbody>
            <?php foreach ($wrows as $date => $r): ?>
                <tr><td><?= htmlspecialchars(date('D, d M', strtotime($date))) ?></td><td><?= plate($r['plate_number'] ?? null) ?> <?= htmlspecialchars((string)($r['model'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)$r['driver_name']) ?></td><td class="text-center"><?= (int)($wc[(int)$r['driver_id']]['tasks'] ?? 0) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="grid-12 grid grid-cols-12 gap-4 border-t border-base-300 pt-4">
            <form method="post" class="md:col-span-5">
                <input type="hidden" name="week" value="<?= htmlspecialchars($mon) ?>"><input type="hidden" name="action" value="approve">
                <button class="btn btn-success" data-confirm="Each driver is told their duty days." data-confirm-title="Approve this welfare duty?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve</button>
            </form>
            <form method="post" class="md:col-span-7">
                <input type="hidden" name="week" value="<?= htmlspecialchars($mon) ?>"><input type="hidden" name="action" value="reject">
                <div class="join w-full">
                    <input class="input w-full join-item" name="reason" maxlength="255" required placeholder="Reason for rejecting (required)">
                    <button class="btn btn-outline btn-error join-item"><i class="fas fa-xmark me-1"></i>Reject</button>
                </div>
            </form>
        </div>
    </div></div>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
