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
    if ($tid > 0) {          // UC026: internal task
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
$tasks = $conn->query("SELECT t.*, d.name AS driver_name, v.plate_number FROM internal_tasks t JOIN drivers d ON d.driver_id = t.driver_id LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                        WHERE t.status = 'assigned' ORDER BY t.start_dt")->fetch_all(MYSQLI_ASSOC);

adminPageStart('Approve Assignments');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-user-check text-success me-2"></i>Approve Assignments</h4>
    <p class="text-muted small">Vehicles and drivers chosen by the Administrative Assistant, and Pay and Claim requests (own vehicle) sent by the Head of Department. The driver and the staff member are told only when you approve. Rejecting a Pay and Claim request ends it.</p>

    <?php if (!$requests && !$tasks): ?><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5"><i class="fas fa-circle-check fa-2x text-success mb-2"></i><div>Nothing is waiting for your approval.</div></div></div><?php endif; ?>

    <?php foreach ($requests as $r): $rid = (int)$r['request_id']; ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <div class="fw-bold">Request #<?= $rid ?> &middot; <?= htmlspecialchars($r['destination']) ?></div>
            <span><?= !empty($r['is_urgent']) ? '<span class="badge bg-danger me-1">Urgent</span>' : '' ?><?= isPersonalRental($r) ? '<span class="badge bg-info text-dark me-1">Personal Rental</span>' : '' ?><?= isPayClaimRequest($r) ? '<span class="badge bg-warning text-dark me-1">Pay and Claim</span>' : (isRequestWithoutDriver($r) ? '<span class="badge bg-secondary me-1">Without driver</span>' : '') ?><?= requestStatusBadge($r['status']) ?></span>
        </div>
        <?= requestDetailsHtml($conn, $r, ['show_assignment' => true]) ?>
        <div class="row g-3 mt-1 border-top pt-3">
            <form method="post" class="col-md-5">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="approve">
                <button class="btn btn-success" data-confirm="<?= isPayClaimRequest($r) ? 'The staff member is told that they may use their own vehicle and make the claim.' : (isRequestWithoutDriver($r) ? 'The staff member is told that the vehicle may be used without a driver.' : 'The driver is told first, then the staff member.') ?>" data-confirm-title="Approve this assignment?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve (Confirm)</button>
            </form>
            <form method="post" class="col-md-7">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="reject">
                <div class="input-group input-group-sm">
                    <input class="form-control" name="reason" maxlength="500" required placeholder="Reason for rejecting (required)">
                    <button class="btn btn-outline-danger"><i class="fas fa-xmark me-1"></i>Reject</button>
                </div>
            </form>
        </div>
    </div></div>
    <?php endforeach; ?>

    <?php if ($tasks): ?><h5 class="fw-bold mt-4 mb-2"><i class="fas fa-wrench text-success me-2"></i>Internal tasks</h5><?php endif; ?>
    <?php foreach ($tasks as $t): $tid = (int)$t['task_id']; ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <div class="fw-bold">Internal task #<?= $tid ?> &middot; <?= htmlspecialchars(internalTaskTypeLabel($t['task_type'])) ?></div>
            <span class="badge bg-info text-dark">Driver Assigned</span>
        </div>
        <div class="small text-muted mb-2"><?= date('D, d M Y', strtotime($t['task_date'])) ?> <?= substr($t['start_time'], 0, 5) ?>&ndash;<?= substr($t['end_time'], 0, 5) ?> &middot; <?= htmlspecialchars((string)($t['plate_number'] ?: 'no vehicle')) ?> &middot; Driver: <strong><?= htmlspecialchars($t['driver_name']) ?></strong><?= $t['location'] ? ' &middot; ' . htmlspecialchars($t['location']) : '' ?></div>
        <div class="row g-3 border-top pt-3">
            <form method="post" class="col-md-5">
                <input type="hidden" name="task_id" value="<?= $tid ?>"><input type="hidden" name="action" value="approve">
                <button class="btn btn-success" data-confirm="The driver is told about the task." data-confirm-title="Approve this internal task?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve</button>
            </form>
            <form method="post" class="col-md-7">
                <input type="hidden" name="task_id" value="<?= $tid ?>"><input type="hidden" name="action" value="reject">
                <div class="input-group input-group-sm">
                    <input class="form-control" name="reason" maxlength="255" required placeholder="Reason for rejecting (required)">
                    <button class="btn btn-outline-danger"><i class="fas fa-xmark me-1"></i>Reject</button>
                </div>
            </form>
        </div>
    </div></div>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
