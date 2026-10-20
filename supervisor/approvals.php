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
    <h4 class="fw-bold mb-3"><i class="fas fa-stamp text-success me-2"></i>Vehicle Approvals <small class="text-muted fs-6"><?= htmlspecialchars((string)$dept) ?></small></h4>
    <ul class="nav nav-pills mb-3 gap-1">
        <li class="nav-item"><a class="nav-link py-1 <?= $tab === 'pending' ? 'active' : '' ?>" href="?tab=pending">Waiting for me <span class="badge bg-light text-dark"><?= $n['pending'] ?? 0 ?></span></a></li>
        <li class="nav-item"><a class="nav-link py-1 <?= $tab === 'active' ? 'active' : '' ?>" href="?tab=active">Approved <span class="badge bg-light text-dark"><?= $n['active'] ?? 0 ?></span></a></li>
        <li class="nav-item"><a class="nav-link py-1 <?= $tab === 'history' ? 'active' : '' ?>" href="?tab=history">History <span class="badge bg-light text-dark"><?= $n['history'] ?? 0 ?></span></a></li>
    </ul>

    <?php if (!$requests): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">Nothing here.</div></div>
    <?php endif; ?>

    <?php foreach ($requests as $r): $rid = (int)$r['request_id']; $rules = cancelRulesFor($r); $ret = 'supervisor/approvals.php?tab=' . $tab . '&open=' . $rid; ?>
    <details class="card border-0 shadow-sm mb-2" <?= $rid === $open || $tab === 'pending' && count($requests) === 1 ? 'open' : '' ?>>
        <summary class="card-body py-2" style="cursor:pointer;list-style:none;">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="fw-semibold">#<?= $rid ?> &middot; <?= htmlspecialchars($r['staff_name']) ?> &middot; <?= htmlspecialchars($r['destination']) ?></span>
                    <div class="small text-muted"><?= requestPeriodText($r) ?> &middot; <?= (int)$r['passenger_count'] ?> pax
                        <?php if ($r['status'] === 'pending'): ?> &middot; submitted <?= max(0, (int)floor((time() - strtotime($r['created_at'])) / 86400)) ?> day(s) ago<?php endif; ?></div>
                </div>
                <div><?= requestStatusBadge($r['status']) ?></div>
            </div>
        </summary>
        <div class="card-body border-top pt-3">
            <?= requestDetailsHtml($conn, $r, ['show_after_confirmed' => true]) ?>

            <?php if ($r['status'] === 'pending'): ?>
            <div class="row g-3 mt-2 border-top pt-3">
                <form method="post" action="<?= SITE_URL ?>/ajax/request_action.php" class="col-md-6">
                    <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                    <label class="form-label small fw-semibold" for="ac<?= $rid ?>">Approve <span class="text-muted fw-normal">(comment optional)</span></label>
                    <textarea class="form-control form-control-sm mb-2" id="ac<?= $rid ?>" name="comment" rows="2" maxlength="1000"></textarea>
                    <button class="btn btn-success btn-sm" data-confirm="The staff member and the Administrative Assistant will be told." data-confirm-title="Approve request #<?= $rid ?>?" data-confirm-button="Approve" data-confirm-tone="primary"><i class="fas fa-check me-1"></i>Approve</button>
                </form>
                <form method="post" action="<?= SITE_URL ?>/ajax/request_action.php" class="col-md-6">
                    <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                    <label class="form-label small fw-semibold" for="rr<?= $rid ?>">Reject <span class="text-danger">(reason required)</span></label>
                    <input class="form-control form-control-sm mb-2" id="rr<?= $rid ?>" name="reason" maxlength="500" required placeholder="Reason">
                    <textarea class="form-control form-control-sm mb-2" name="comment" rows="1" maxlength="1000" placeholder="Comment (optional)"></textarea>
                    <button class="btn btn-outline-danger btn-sm"><i class="fas fa-xmark me-1"></i>Reject</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($rules['allowed']): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/cancel_request.php" class="mt-3 border-top pt-3">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <label class="form-label small fw-semibold" for="cr<?= $rid ?>">Cancel this request <span class="text-danger">(reason required)</span></label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="cr<?= $rid ?>" name="reason" maxlength="500" required placeholder="Reason">
                    <button class="btn btn-outline-danger" data-confirm="The request is cancelled, the vehicle is released and the people involved are told." data-confirm-title="Cancel request #<?= $rid ?>?" data-confirm-button="Cancel request" data-confirm-tone="danger">Cancel request</button>
                </div>
            </form>
            <?php endif; ?>

            <?php if (!in_array($r['status'], ['rejected', 'lapsed'], true)): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/add_comment.php" class="mt-3 border-top pt-3">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <label class="form-label small fw-semibold" for="cm<?= $rid ?>">Add a comment <span class="text-muted fw-normal">(the staff member is told)</span></label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="cm<?= $rid ?>" name="comment" maxlength="1000" required>
                    <button class="btn btn-outline-secondary">Add comment</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
