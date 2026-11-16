<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/schedule_changes.php  -  UC030 Approve/Reject Schedule Change (Head of Admin only)
//
// Shows the Pending requests of the drivers with the driver's tasks on the selected dates. Approve (no comment, UC027) or
// Reject (the reason is required, UC028; the task stays with the driver). Approving marks the driver unavailable on the dates and tells the
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
        $_SESSION['replace_queue'] = ['change_id' => $cid, 'tasks' => array_map(fn($t) => (int)$t['task_id'], $res['tasks'])];
        header('Location: replace_driver.php?schedule_id=' . (int)$res['tasks'][0]['task_id'] . '&change_id=' . $cid);
        exit();
    }
    header('Location: schedule_changes.php');
    exit();
}

$pending = $conn->query("SELECT r.*, d.name AS driver_name FROM schedule_changes r JOIN drivers d ON d.driver_id = r.driver_id WHERE r.status = 'pending' ORDER BY r.created_at")->fetch_all(MYSQLI_ASSOC);
$decided = $conn->query("SELECT r.*, d.name AS driver_name FROM schedule_changes r JOIN drivers d ON d.driver_id = r.driver_id WHERE r.status <> 'pending' ORDER BY COALESCE(r.approved_at, r.rejected_at, r.created_at) DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
adminPageStart('Schedule Changes');
?>
    <h1 class="text-2xl font-medium mb-1">Schedule Change Requests</h1>
    <p class="text-base-content/60 text-sm mb-4">Requests of the drivers to be unavailable on some dates or to be withdrawn from a task.</p>
    <?php if (!$pending): ?><div class="card border border-base-300 bg-base-100"><div class="card-body text-center text-base-content/60 py-12"><i class="fas fa-circle-check fa-2x text-success mb-2"></i><div>There are no pending requests.</div></div></div><?php endif; ?>
    <?php foreach ($pending as $r): $dates = scheduleChangeDatesOf($conn, (int)$r['change_id']); $tasks = scheduleChangeAffectedTasks($conn, $r); $cid = (int)$r['change_id']; ?>
    <div class="card border border-base-300 bg-base-100 mb-4"><div class="card-body">
        <div class="flex flex-wrap justify-between gap-2 mb-1">
            <div class="font-bold">Request #<?= $cid ?> &middot; <?= $h($r['driver_name']) ?></div>
            <span class="badge badge-soft badge-warning">Pending</span></div>
        <div><strong><?= $r['change_type'] === 'withdraw' ? 'Withdraw from a task' : 'Unavailable' ?></strong> &middot; <?= $h(scheduleChangeDatesText($dates)) ?></div>
        <div class="text-sm text-base-content/60 mb-2">Reason: <?= $h($r['reason']) ?> &middot; sent <?= $h(date('d M Y H:i', strtotime($r['created_at']))) ?></div>
        <div class="text-sm font-semibold">The driver's tasks <?= $r['change_type'] === 'withdraw' ? 'for this request' : 'on these dates' ?></div>
        <?php if (!$tasks): ?><div class="text-sm text-base-content/60 mb-2">None.</div><?php endif; ?>
        <?php foreach ($tasks as $t): ?><div class="text-sm">&bull; <?= $h(date('D, d M H:i', strtotime($t['start_dt']))) ?> &middot; <?= $t['trip_type'] === 'return' ? 'Return' : 'Outbound' ?> &middot; <?= $h($t['destination']) ?> (request #<?= (int)$t['request_id'] ?>, <?= $h(requestStatusLabel($t['req_status'])) ?>)</div><?php endforeach; ?>
        <form method="post" class="grid-12 grid grid-cols-12 gap-2 mt-2">
            <input type="hidden" name="change_id" value="<?= $cid ?>">
            <div class="col-span-12 md:col-span-7"><input class="input input-sm w-full" name="comment" maxlength="500" placeholder="Reason (required when rejecting)"></div>
            <div class="col-span-12 md:col-span-5 flex gap-2">
                <button class="btn btn-success btn-sm" name="decision" value="approve" data-confirm="The driver is marked unavailable and told. <?= $tasks ? 'You will replace the driver on the tasks above.' : '' ?>" data-confirm-title="Approve request #<?= $cid ?>?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve</button>
                <button class="btn btn-outline btn-error btn-sm" name="decision" value="reject"><i class="fas fa-xmark me-1"></i>Reject</button></div>
        </form>
    </div></div>
    <?php endforeach; ?>
    <?php if ($decided): ?>
    <h2 class="text-lg font-medium mt-6 mb-2">Decided recently</h2>
    <?php foreach ($decided as $r): ?><div class="text-sm border-t border-base-300 py-1">#<?= (int)$r['change_id'] ?> &middot; <?= $h($r['driver_name']) ?> &middot; <?= $r['change_type'] === 'withdraw' ? 'withdraw' : 'unavailable' ?> &middot; <strong><?= $h(ucfirst($r['status'])) ?></strong> <?= $h(date('d M H:i', strtotime((string)($r['approved_at'] ?? $r['rejected_at'] ?? $r['created_at'])))) ?><?= $r['reject_reason'] ? ' &middot; ' . $h($r['reject_reason']) : '' ?></div><?php endforeach; ?>
    <?php endif; ?>
<?php adminPageEnd(); ?>
