<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/schedule_changes.php  -  UC030 Approve/Reject Schedule Change (Head of Admin only)
//
// Shows the Pending requests of the drivers with the driver's tasks on the selected dates. Approve (with an optional comment, UC016) or
// Reject (the comment is required; the task stays with the driver). Approving marks the driver unavailable on the dates and tells the
// driver and the Administrative Assistant. When the driver has Confirmed tasks to give up, Replace Driver (UC020) opens for each of them
// with the reason "Driver Request"; because the Head of Admin does it, the task stays Confirmed.
// ============================================================
$current_page = 'schedule_changes.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireHeadAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = (int)($_POST['change_id'] ?? 0);
    $res = decideScheduleChange($conn, $cid, (string)($_POST['decision'] ?? ''), (string)($_POST['comment'] ?? ''));
    setFlash($res['ok'] ? 'success' : 'danger', $res['ok'] ? "Request #$cid " . ($_POST['decision'] === 'approve' ? 'approved. The driver and the Administrative Assistant were told.' : 'rejected. The driver was told. The task stays with the driver.') : $res['message']);
    if ($res['ok'] && !empty($res['tasks'])) {                      // UC030 A2: Replace Driver for each affected task
        $_SESSION['replace_queue'] = ['change_id' => $cid, 'tasks' => array_map(fn($t) => (int)$t['schedule_id'], $res['tasks'])];
        header('Location: replace_driver.php?schedule_id=' . (int)$res['tasks'][0]['schedule_id'] . '&change_id=' . $cid);
        exit();
    }
    header('Location: schedule_changes.php');
    exit();
}

$pending = $conn->query("SELECT r.*, d.name AS driver_name FROM schedule_change_requests r JOIN drivers d ON d.driver_id = r.driver_id WHERE r.status = 'pending' ORDER BY r.created_at")->fetch_all(MYSQLI_ASSOC);
$decided = $conn->query("SELECT r.*, d.name AS driver_name FROM schedule_change_requests r JOIN drivers d ON d.driver_id = r.driver_id WHERE r.status <> 'pending' ORDER BY r.decided_at DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
adminPageStart('Schedule Changes');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-calendar-xmark text-success me-2"></i>Schedule Change Requests</h4>
    <p class="text-muted small">Requests of the drivers to be unavailable on some dates or to be withdrawn from a task.</p>
    <?php if (!$pending): ?><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5"><i class="fas fa-circle-check fa-2x text-success mb-2"></i><div>There are no pending requests.</div></div></div><?php endif; ?>
    <?php foreach ($pending as $r): $dates = scheduleChangeDatesOf($conn, (int)$r['change_id']); $tasks = scheduleChangeAffectedTasks($conn, $r); $cid = (int)$r['change_id']; ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-1">
            <div class="fw-bold">Request #<?= $cid ?> &middot; <?= $h($r['driver_name']) ?></div>
            <span class="badge bg-warning text-dark">Pending</span></div>
        <div><strong><?= $r['change_type'] === 'withdraw' ? 'Withdraw from a task' : 'Unavailable' ?></strong> &middot; <?= $h(scheduleChangeDatesText($dates)) ?></div>
        <div class="small text-muted mb-2">Reason: <?= $h($r['reason']) ?> &middot; sent <?= $h(date('d M Y H:i', strtotime($r['created_at']))) ?></div>
        <div class="small fw-semibold">The driver's tasks <?= $r['change_type'] === 'withdraw' ? 'for this request' : 'on these dates' ?></div>
        <?php if (!$tasks): ?><div class="small text-muted mb-2">None.</div><?php endif; ?>
        <?php foreach ($tasks as $t): ?><div class="small">&bull; <?= $h(date('D, d M H:i', strtotime($t['start_dt']))) ?> &middot; <?= (int)$t['leg_no'] === 2 ? 'Return' : 'Outbound' ?> &middot; <?= $h($t['destination']) ?> (request #<?= (int)$t['request_id'] ?>, <?= $h(requestStatusLabel($t['req_status'])) ?>)</div><?php endforeach; ?>
        <form method="post" class="row g-2 mt-2">
            <input type="hidden" name="change_id" value="<?= $cid ?>">
            <div class="col-12 col-md-7"><input class="form-control form-control-sm" name="comment" maxlength="500" placeholder="Comment (required when rejecting)"></div>
            <div class="col-12 col-md-5 d-flex gap-2">
                <button class="btn btn-success btn-sm" name="decision" value="approve" data-confirm="The driver is marked unavailable and told. <?= $tasks ? 'You will replace the driver on the tasks above.' : '' ?>" data-confirm-title="Approve request #<?= $cid ?>?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve</button>
                <button class="btn btn-outline-danger btn-sm" name="decision" value="reject"><i class="fas fa-xmark me-1"></i>Reject</button></div>
        </form>
    </div></div>
    <?php endforeach; ?>
    <?php if ($decided): ?>
    <h6 class="fw-bold mt-4">Decided recently</h6>
    <?php foreach ($decided as $r): ?><div class="small border-top py-1">#<?= (int)$r['change_id'] ?> &middot; <?= $h($r['driver_name']) ?> &middot; <?= $r['change_type'] === 'withdraw' ? 'withdraw' : 'unavailable' ?> &middot; <strong><?= $h(ucfirst($r['status'])) ?></strong> <?= $h(date('d M H:i', strtotime((string)$r['decided_at']))) ?><?= $r['decision_comment'] ? ' &middot; ' . $h($r['decision_comment']) : '' ?></div><?php endforeach; ?>
    <?php endif; ?>
<?php adminPageEnd(); ?>
