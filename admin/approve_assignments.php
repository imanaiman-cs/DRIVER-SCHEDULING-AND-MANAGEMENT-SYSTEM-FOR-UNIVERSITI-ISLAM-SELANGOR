<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/approve_assignments.php  -  UC019 Approve Driver Assignment / UC020 Reject Driver Assignment
//
// Only the Head of Admin. EVERY assignment is approved here, also when the Head of Admin made it himself
// (there is no automatic confirmation).
//   Approve -> Confirmed: the driver is notified first, then the staff member (with driver and vehicle details)
//   Reject  -> a reason is required; the driver is removed from the tasks, the request goes back to Approved,
//              the reason is recorded and the Administrative Assistant is notified
// ============================================================
$current_page = 'approve_assignments.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireHeadAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)($_POST['request_id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'approve') {
        $res = approveAssignment($conn, $rid);
        setFlash($res['ok'] ? 'success' : 'danger', $res['ok'] ? "Request #$rid is Confirmed. The driver and the staff member have been told." : $res['message']);
    } elseif ($act === 'reject') {
        $res = rejectAssignment($conn, $rid, (string)($_POST['reason'] ?? ''));
        setFlash($res['ok'] ? 'success' : 'danger', $res['ok'] ? "The assignment of request #$rid was rejected. The Administrative Assistant must assign again." : $res['message']);
    }
    header('Location: approve_assignments.php');
    exit();
}

$requests = $conn->query(requestSelectSql() . " WHERE vr.status = 'assigned' ORDER BY vr.depart_at, vr.request_id")->fetch_all(MYSQLI_ASSOC);
$open = (int)($_GET['open'] ?? 0);

adminPageStart('Approve Assignments');
?>
    <h1 class="text-2xl font-medium mb-1">Approve Assignments</h1>
    <p class="text-base-content/60 text-sm">Vehicles and drivers chosen by the Administrative Assistant. The driver and the staff member are told only when you approve.</p>

    <?php if (!$requests): ?><div class="card border border-base-300 bg-base-100"><div class="card-body text-center text-base-content/60 py-12"><i class="fas fa-circle-check fa-2x text-success mb-2"></i><div>Nothing is waiting for your approval.</div></div></div><?php endif; ?>

    <?php foreach ($requests as $r): $rid = (int)$r['request_id']; ?>
    <div class="card border border-base-300 bg-base-100 mb-4"><div class="card-body">
        <div class="flex flex-wrap justify-between gap-2 mb-2">
            <div class="font-bold">Request #<?= $rid ?> &middot; <?= htmlspecialchars($r['destination']) ?></div>
            <?= requestStatusBadge($r['status']) ?>
        </div>
        <?= requestDetailsHtml($conn, $r, ['show_assignment' => true]) ?>
        <div class="grid-12 grid grid-cols-12 gap-4 mt-1 border-t border-base-300 pt-4">
            <form method="post" class="col-span-12 md:col-span-5">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="approve">
                <button class="btn btn-success" data-confirm="The driver is told first, then the staff member." data-confirm-title="Approve this assignment?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve (Confirm)</button>
            </form>
            <form method="post" class="col-span-12 md:col-span-7">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="reject">
                <div class="join w-full">
                    <input class="input w-full" name="reason" maxlength="500" required placeholder="Reason for rejecting (required)">
                    <button class="btn btn-outline btn-error"><i class="fas fa-xmark me-1"></i>Reject</button>
                </div>
            </form>
        </div>
    </div></div>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
