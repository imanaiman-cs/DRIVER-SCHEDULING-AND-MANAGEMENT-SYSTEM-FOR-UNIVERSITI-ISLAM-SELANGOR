<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicle_requests.php  -  Administrative Assistant / Head of Admin: the requests and what to do with them
//
//   Approved          -> "Assign driver" (UC017, with the recommendation UC018)
//   Driver Assigned   -> waits for the Head of Admin (admin/approve_assignments.php)
//   Confirmed         -> "Replace driver" (UC021) per task that has not started
//   Cancel (reason required) and Add comment are available on every active request.
// ============================================================
$current_page = 'vehicle_requests.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$tab = (string)($_GET['tab'] ?? 'approved');
$tabs = ['approved' => ['Needs a driver', "vr.status = 'approved'"], 'assigned' => ['Driver Assigned', "vr.status = 'assigned'"],
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
    <h1 class="text-2xl font-medium mb-4">Vehicle Requests</h1>
    <div role="tablist" class="tabs tabs-border mb-6 flex-nowrap overflow-x-auto">
        <?php foreach ($tabs as $k => [$label]): ?>
            <a role="tab" class="tab gap-2 <?= $tab === $k ? 'tab-active' : '' ?>" href="?tab=<?= $k ?>"><?= htmlspecialchars($label) ?> <span class="font-mono text-xs text-base-content/60"><?= $tabCount($k) ?></span></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$requests): ?><div class="card border border-base-300 bg-base-100"><div class="card-body text-center text-base-content/60 py-12">Nothing here.</div></div><?php endif; ?>

    <?php foreach ($requests as $r): $rid = (int)$r['request_id']; $rules = cancelRulesFor($r); $ret = 'admin/vehicle_requests.php?tab=' . $tab . '&open=' . $rid; ?>
    <details class="card border border-base-300 bg-base-100 mb-2" <?= $rid === $open ? 'open' : '' ?>>
        <summary class="card-body py-2" style="cursor:pointer;list-style:none;">
            <div class="flex flex-wrap justify-between items-center gap-2">
                <div>
                    <span class="font-semibold">#<?= $rid ?> &middot; <?= htmlspecialchars($r['staff_name']) ?> &middot; <?= htmlspecialchars($r['destination']) ?></span>
                    <div class="text-sm text-base-content/60"><?= requestPeriodText($r) ?> &middot; <?= (int)$r['passenger_count'] ?> pax<?= $r['plate_number'] ? ' &middot; ' . plate($r['plate_number']) : '' ?></div>
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($r['status'] === 'approved'): ?><a class="btn btn-sm btn-success" href="assign_driver.php?request_id=<?= $rid ?>" onclick="event.stopPropagation();"><i class="fas fa-user-plus me-1"></i>Assign driver</a><?php endif; ?>
                    <?= requestStatusBadge($r['status']) ?>
                </div>
            </div>
        </summary>
        <div class="card-body border-t border-base-300 pt-4">
            <?= requestDetailsHtml($conn, $r, ['show_assignment' => true]) ?>

            <?php if ($r['status'] === 'processed'): ?>
                <?php $ts = $conn->prepare("SELECT schedule_id, leg_no, start_dt FROM schedules WHERE request_id = ? AND status <> 'cancelled' AND start_dt > NOW() ORDER BY leg_no");
                      $ts->bind_param('i', $rid); $ts->execute(); $tasks = $ts->get_result()->fetch_all(MYSQLI_ASSOC); $ts->close(); ?>
                <?php foreach ($tasks as $t): ?>
                    <a class="btn btn-sm btn-outline btn-primary mt-2 me-1" href="replace_driver.php?schedule_id=<?= (int)$t['schedule_id'] ?>"><i class="fas fa-right-left me-1"></i>Replace driver (<?= (int)$t['leg_no'] === 2 ? 'return' : 'outbound' ?>)</a>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($r['status'] === 'assigned' && isHeadAdmin()): ?>
                <a class="btn btn-sm btn-primary mt-2" href="approve_assignments.php?open=<?= $rid ?>"><i class="fas fa-user-check me-1"></i>Approve or reject the assignment</a>
            <?php endif; ?>

            <?php if ($rules['allowed']): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/cancel_request.php" class="mt-4 border-t border-base-300 pt-4">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <label class="mb-1 block text-sm font-medium" for="cr<?= $rid ?>">Cancel this request <span class="text-error">(reason required)</span></label>
                <div class="join w-full">
                    <input class="input w-full" id="cr<?= $rid ?>" name="reason" maxlength="500" required placeholder="Reason">
                    <button class="btn btn-outline btn-error" data-confirm="The request is cancelled, the vehicle is released, the driver is removed and everybody involved is told." data-confirm-title="Cancel request #<?= $rid ?>?" data-confirm-button="Cancel request" data-confirm-tone="danger">Cancel request</button>
                </div>
            </form>
            <?php endif; ?>
            <?php if (!in_array($r['status'], ['rejected', 'lapsed'], true)): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/add_comment.php" class="mt-4 border-t border-base-300 pt-4">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <div class="join w-full">
                    <input class="input w-full" name="comment" maxlength="1000" required placeholder="Add a comment (the staff member is told)">
                    <button class="btn btn-outline">Add comment</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
