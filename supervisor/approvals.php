<?php
// ============================================================
// UIS Driver Scheduling and Management System
// supervisor/approvals.php  -  Head of Department: UC013 Approve, UC014 Reject, UC015 Add Comment, cancel (UC012)
//
// The Head of Department sees and acts ONLY on requests of his own PTJ (he is the approver of the requester).
//   Reject needs a reason. Cancel needs a reason. A comment is optional with every action.
// ============================================================
$current_page = 'approvals.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireSupervisor();

$uid  = (int)$_SESSION['user_id'];
$dept = $_SESSION['department'] ?? null;
$tab  = (string)($_GET['tab'] ?? 'pending');
if (!in_array($tab, ['pending', 'active', 'history'], true)) { $tab = 'pending'; }

$where = match ($tab) {
    'pending' => "vr.status = 'pending'",
    'active'  => "vr.status IN ('approved', 'assigned', 'processed')",
    default   => "vr.status IN ('rejected', 'cancelled', 'lapsed', 'completed')",
};
// own PTJ: requests of people in his department, or of the Heads of Department he heads
$stmt = $conn->prepare(requestSelectSql() . " WHERE vr.supervisor_id = ? AND (u.role = 'supervisor' OR u.department <=> ?) AND $where
                        ORDER BY vr.depart_at, vr.request_id");
$stmt->bind_param('is', $uid, $dept);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$n = [];
$cs = $conn->prepare("SELECT CASE WHEN status = 'pending' THEN 'pending' WHEN status IN ('approved','assigned','processed') THEN 'active' ELSE 'history' END AS grp, COUNT(*) AS c
                        FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id
                       WHERE vr.supervisor_id = ? AND (u.role = 'supervisor' OR u.department <=> ?) GROUP BY grp");
$cs->bind_param('is', $uid, $dept);
$cs->execute();
foreach ($cs->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $n[$r['grp']] = (int)$r['c']; }
$cs->close();

$open = (int)($_GET['open'] ?? 0);
adminPageStart('Vehicle Approvals');
?>
    <h1 class="text-2xl font-extrabold tracking-tight mb-4"><i class="fas fa-stamp me-2 text-[0.8em] text-primary"></i>Vehicle Approvals <small class="text-base-content/60 text-base"><?= htmlspecialchars((string)$dept) ?></small></h1>
    <div role="tablist" class="tabs tabs-border mb-6 flex-nowrap overflow-x-auto">
        <a role="tab" class="tab gap-2 <?= $tab === 'pending' ? 'tab-active' : '' ?>" href="?tab=pending">Waiting for me <span class="font-mono text-xs text-base-content/60"><?= $n['pending'] ?? 0 ?></span></a>
        <a role="tab" class="tab gap-2 <?= $tab === 'active' ? 'tab-active' : '' ?>" href="?tab=active">Approved <span class="font-mono text-xs text-base-content/60"><?= $n['active'] ?? 0 ?></span></a>
        <a role="tab" class="tab gap-2 <?= $tab === 'history' ? 'tab-active' : '' ?>" href="?tab=history">History <span class="font-mono text-xs text-base-content/60"><?= $n['history'] ?? 0 ?></span></a>
    </div>

    <?php if (!$requests): ?>
        <div class="card border border-base-300 bg-base-100"><div class="card-body text-center text-base-content/60 py-12">Nothing here.</div></div>
    <?php endif; ?>

    <?php foreach ($requests as $r): $rid = (int)$r['request_id']; $rules = cancelRulesFor($r); $ret = 'supervisor/approvals.php?tab=' . $tab . '&open=' . $rid; ?>
    <details class="card border border-base-300 bg-base-100 mb-2" <?= $rid === $open || $tab === 'pending' && count($requests) === 1 ? 'open' : '' ?>>
        <summary class="card-body py-2" style="cursor:pointer;list-style:none;">
            <div class="flex flex-wrap justify-between items-center gap-2">
                <div>
                    <span class="font-semibold">#<?= $rid ?> &middot; <?= htmlspecialchars($r['staff_name']) ?> &middot; <?= htmlspecialchars($r['destination']) ?></span>
                    <div class="text-sm text-base-content/60"><?= requestPeriodText($r) ?> &middot; <?= (int)$r['passenger_count'] ?> pax
                        <?php if ($r['status'] === 'pending'): ?> &middot; submitted <?= max(0, (int)floor((time() - strtotime($r['created_at'])) / 86400)) ?> day(s) ago<?php endif; ?></div>
                </div>
                <div><?= requestStatusBadge($r['status']) ?></div>
            </div>
        </summary>
        <div class="card-body border-t border-base-300 pt-4">
            <?= requestDetailsHtml($conn, $r, ['show_after_confirmed' => true]) ?>

            <?php if ($r['status'] === 'pending'): ?>
            <div class="grid-12 grid grid-cols-12 gap-4 mt-2 border-t border-base-300 pt-4">
                <form method="post" action="<?= SITE_URL ?>/ajax/request_action.php" class="col-span-12 md:col-span-6">
                    <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                    <label class="mb-1 block text-sm font-medium" for="ac<?= $rid ?>">Approve <span class="text-base-content/60 font-normal">(comment optional)</span></label>
                    <textarea class="textarea w-full textarea-sm mb-2" id="ac<?= $rid ?>" name="comment" rows="2" maxlength="1000"></textarea>
                    <button class="btn btn-success btn-sm" data-confirm="The staff member and the Administrative Assistant will be told." data-confirm-title="Approve request #<?= $rid ?>?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve</button>
                </form>
                <form method="post" action="<?= SITE_URL ?>/ajax/request_action.php" class="col-span-12 md:col-span-6">
                    <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                    <label class="mb-1 block text-sm font-medium" for="rr<?= $rid ?>">Reject <span class="text-error">(reason required)</span></label>
                    <input class="input w-full input-sm mb-2" id="rr<?= $rid ?>" name="reason" maxlength="500" required placeholder="Reason">
                    <textarea class="textarea w-full textarea-sm mb-2" name="comment" rows="1" maxlength="1000" placeholder="Comment (optional)"></textarea>
                    <button class="btn btn-outline btn-error btn-sm"><i class="fas fa-xmark me-1"></i>Reject</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($rules['allowed']): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/cancel_request.php" class="mt-4 border-t border-base-300 pt-4">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <label class="mb-1 block text-sm font-medium" for="cr<?= $rid ?>">Cancel this request <span class="text-error">(reason required)</span></label>
                <div class="join w-full">
                    <input class="input w-full" id="cr<?= $rid ?>" name="reason" maxlength="500" required placeholder="Reason">
                    <button class="btn btn-outline btn-error" data-confirm="The request is cancelled, the vehicle is released and the people involved are told." data-confirm-title="Cancel request #<?= $rid ?>?" data-confirm-button="Cancel request" data-confirm-tone="danger">Cancel request</button>
                </div>
            </form>
            <?php endif; ?>

            <?php if (!in_array($r['status'], ['rejected', 'lapsed'], true)): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/add_comment.php" class="mt-4 border-t border-base-300 pt-4">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <label class="mb-1 block text-sm font-medium" for="cm<?= $rid ?>">Add a comment <span class="text-base-content/60 font-normal">(the staff member is told)</span></label>
                <div class="join w-full">
                    <input class="input w-full" id="cm<?= $rid ?>" name="comment" maxlength="1000" required>
                    <button class="btn btn-outline">Add comment</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
