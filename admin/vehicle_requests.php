<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicle_requests.php  -  Administrative Assistant / Head of Admin: the requests and what to do with them
//
//   Approved          -> "Assign driver" (UC017, with the recommendation UC018); WITHOUT driver -> "Send to Head of Admin" (UC010 A3)
//   Driver Assigned   -> waits for the Head of Admin (admin/approve_assignments.php)
//   Confirmed         -> "Replace driver" (UC021) per task that has not started
//   Cancel (reason required) and Add comment are available on every active request.
// ============================================================
$current_page = 'vehicle_requests.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_without_driver') {      // UC010 A3 / UC019 A2
    $res = sendRequestWithoutDriverForApproval($conn, (int)($_POST['request_id'] ?? 0));
    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    header('Location: vehicle_requests.php?tab=' . ($res['ok'] ? 'assigned' : 'approved'));
    exit();
}

$tab = (string)($_GET['tab'] ?? 'approved');
$tabs = ['approved' => ['Approved', "vr.status = 'approved'"], 'assigned' => ['Driver Assigned', "vr.status = 'assigned'"],
         'processed' => ['Confirmed', "vr.status = 'processed'"], 'pending' => ['Pending', "vr.status = 'pending'"],
         'history' => ['History', "vr.status IN ('rejected','cancelled','lapsed','completed')"], 'all' => ['All', '1 = 1']];
if (!isset($tabs[$tab])) { $tab = 'approved'; }

$counts = [];
foreach ($conn->query("SELECT status, COUNT(*) AS n FROM vehicle_requests GROUP BY status")->fetch_all(MYSQLI_ASSOC) as $r) { $counts[$r['status']] = (int)$r['n']; }
$tabCount = fn(string $t) => match ($t) {
    'approved' => $counts['approved'] ?? 0, 'assigned' => $counts['assigned'] ?? 0, 'processed' => $counts['processed'] ?? 0, 'pending' => $counts['pending'] ?? 0,
    'history' => ($counts['rejected'] ?? 0) + ($counts['cancelled'] ?? 0) + ($counts['lapsed'] ?? 0) + ($counts['completed'] ?? 0), default => array_sum($counts),
};

$requests = $conn->query(requestSelectSql() . ' WHERE ' . $tabs[$tab][1] . ' ORDER BY ' . ($tab === 'history' || $tab === 'all' ? 'vr.request_id DESC' : 'vr.depart_at, vr.request_id'))->fetch_all(MYSQLI_ASSOC);
$open = (int)($_GET['open'] ?? 0);

adminPageStart('Vehicle Requests');
?>
    <h4 class="fw-bold mb-3"><i class="fas fa-file-signature text-success me-2"></i>Vehicle Requests</h4>
    <ul class="nav nav-pills mb-3 flex-wrap gap-1">
        <?php foreach ($tabs as $k => [$label]): ?>
            <li class="nav-item"><a class="nav-link py-1 <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><?= htmlspecialchars($label) ?> <span class="badge bg-light text-dark"><?= $tabCount($k) ?></span></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if (!$requests): ?><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">Nothing here.</div></div><?php endif; ?>

    <?php foreach ($requests as $r): $rid = (int)$r['request_id']; $rules = cancelRulesFor($r); $ret = 'admin/vehicle_requests.php?tab=' . $tab . '&open=' . $rid; ?>
    <details class="card border-0 shadow-sm mb-2" <?= $rid === $open ? 'open' : '' ?>>
        <summary class="card-body py-2" style="cursor:pointer;list-style:none;">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="fw-semibold">#<?= $rid ?> &middot; <?= htmlspecialchars($r['staff_name']) ?> &middot; <?= htmlspecialchars($r['destination']) ?></span>
                    <div class="small text-muted"><?= requestPeriodText($r) ?> &middot; <?= (int)$r['passenger_count'] ?> pax &middot; <?= htmlspecialchars((string)$r['plate_number']) ?></div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if (isRequestWithoutDriver($r)): ?><span class="badge bg-secondary">Without driver</span><?php endif; ?>
                    <?php if ($r['status'] === 'approved' && isRequestWithoutDriver($r)): ?>
                        <form method="post" class="d-inline" onclick="event.stopPropagation();"><input type="hidden" name="action" value="send_without_driver"><input type="hidden" name="request_id" value="<?= $rid ?>">
                            <button class="btn btn-sm btn-primary" data-confirm="The Head of Admin is asked to approve the use of the vehicle. No driver is assigned." data-confirm-title="Send to the Head of Admin?" data-confirm-button="Send" data-confirm-tone="primary"><i class="fas fa-paper-plane me-1"></i>Send to Head of Admin</button></form>
                    <?php elseif ($r['status'] === 'approved'): ?><a class="btn btn-sm btn-success" href="assign_driver.php?request_id=<?= $rid ?>" onclick="event.stopPropagation();"><i class="fas fa-user-plus me-1"></i>Assign driver</a><?php endif; ?>
                    <?= requestStatusBadge($r['status']) ?>
                </div>
            </div>
        </summary>
        <div class="card-body border-top pt-3">
            <?= requestDetailsHtml($conn, $r, ['show_assignment' => true]) ?>

            <?php if ($r['status'] === 'processed'): ?>
                <?php $ts = $conn->prepare("SELECT schedule_id, leg_no, start_dt FROM schedules WHERE request_id = ? AND status <> 'cancelled' AND vendor_name IS NULL AND start_dt > NOW() ORDER BY leg_no");
                      $ts->bind_param('i', $rid); $ts->execute(); $tasks = $ts->get_result()->fetch_all(MYSQLI_ASSOC); $ts->close(); ?>
                <?php foreach ($tasks as $t): ?>
                    <a class="btn btn-sm btn-outline-primary mt-2 me-1" href="replace_driver.php?schedule_id=<?= (int)$t['schedule_id'] ?>"><i class="fas fa-right-left me-1"></i>Replace driver (<?= (int)$t['leg_no'] === 2 ? 'return' : 'outbound' ?>)</a>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($r['status'] === 'assigned' && isHeadAdmin()): ?>
                <a class="btn btn-sm btn-primary mt-2" href="approve_assignments.php?open=<?= $rid ?>"><i class="fas fa-user-check me-1"></i>Approve or reject the assignment</a>
            <?php endif; ?>

            <?php if ($rules['allowed']): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/cancel_request.php" class="mt-3 border-top pt-3">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <label class="form-label small fw-semibold" for="cr<?= $rid ?>">Cancel this request <span class="text-danger">(reason required)</span></label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="cr<?= $rid ?>" name="reason" maxlength="500" required placeholder="Reason">
                    <button class="btn btn-outline-danger" data-confirm="The request is cancelled, the vehicle is released, the driver is removed and everybody involved is told." data-confirm-title="Cancel request #<?= $rid ?>?" data-confirm-button="Cancel request" data-confirm-tone="danger">Cancel request</button>
                </div>
            </form>
            <?php endif; ?>
            <?php if (!in_array($r['status'], ['rejected', 'lapsed'], true)): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/add_comment.php" class="mt-3 border-top pt-3">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <div class="input-group input-group-sm">
                    <input class="form-control" name="comment" maxlength="1000" required placeholder="Add a comment (the staff member is told)">
                    <button class="btn btn-outline-secondary">Add comment</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
