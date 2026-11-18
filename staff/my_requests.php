<?php
// ============================================================
// UIS Driver Scheduling and Management System
// staff/my_requests.php  -  UC011 View Request Status  +  cancel own request (UC012)
//
// Every role is also Staff, so every role sees its OWN requests here.
// Shows the request details, the status history, the reason and comments of a rejection or cancellation,
// and the driver and vehicle ONLY after the assignment is Confirmed.
// ============================================================
$current_page = 'my_requests.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireRequester();

$uid = (int)$_SESSION['user_id'];
$filter = (string)($_GET['status'] ?? 'all');
$allowedFilters = ['all', 'pending', 'approved', 'assigned', 'processed', 'rejected', 'cancelled', 'lapsed', 'completed'];
if (!in_array($filter, $allowedFilters, true)) { $filter = 'all'; }

$sql = requestSelectSql() . ' WHERE vr.staff_id = ?';
if ($filter !== 'all') { $sql .= ' AND vr.status = ?'; }
$sql .= ' ORDER BY vr.created_at DESC, vr.request_id DESC';
$stmt = $conn->prepare($sql);
if ($filter !== 'all') { $stmt->bind_param('is', $uid, $filter); } else { $stmt->bind_param('i', $uid); }
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = [];
$cs = $conn->prepare('SELECT status, COUNT(*) AS n FROM vehicle_requests WHERE staff_id = ? GROUP BY status');
$cs->bind_param('i', $uid);
$cs->execute();
foreach ($cs->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $counts[$r['status']] = (int)$r['n']; }
$cs->close();

$open = (int)($_GET['open'] ?? 0);
adminPageStart('My Requests');
?>
    <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
        <h1 class="text-2xl font-extrabold tracking-tight mb-0"><i class="fas fa-list-check me-2 text-[0.8em] text-primary"></i>My Requests</h1>
        <a class="btn btn-success btn-sm" href="request_vehicle.php"><i class="fas fa-plus me-1"></i>New request</a>
    </div>

    <nav aria-label="My requests by status" class="tabs tabs-border mb-6 flex-nowrap overflow-x-auto">
        <a class="tab gap-2 <?= $filter === 'all' ? 'tab-active' : '' ?>" <?= $filter === 'all' ? 'aria-current="page"' : '' ?> href="?status=all">All <span class="font-mono text-xs text-base-content/60"><?= array_sum($counts) ?></span></a>
        <?php foreach (['pending', 'approved', 'assigned', 'processed', 'rejected', 'cancelled', 'lapsed', 'completed'] as $st): if (empty($counts[$st])) { continue; } ?>
            <a class="tab gap-2 <?= $filter === $st ? 'tab-active' : '' ?>" <?= $filter === $st ? 'aria-current="page"' : '' ?> href="?status=<?= $st ?>"><?= htmlspecialchars(requestStatusLabel($st)) ?> <span class="font-mono text-xs text-base-content/60"><?= $counts[$st] ?></span></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$requests): ?>
        <div class="card border border-base-300 bg-base-100"><div class="card-body text-center text-base-content/60 py-12">No requests yet.</div></div>
    <?php endif; ?>

    <?php foreach ($requests as $r): $rules = cancelRulesFor($r); ?>
    <details class="card border border-base-300 bg-base-100 mb-2" <?= (int)$r['request_id'] === $open ? 'open' : '' ?>>
        <summary class="card-body py-2" style="cursor:pointer;list-style:none;">
            <div class="flex flex-wrap justify-between items-center gap-2">
                <div>
                    <span class="font-semibold">#<?= (int)$r['request_id'] ?> &middot; <?= htmlspecialchars($r['destination']) ?></span>
                    <div class="text-sm text-base-content/60"><?= requestPeriodText($r) ?> &middot; <?= (int)$r['passenger_count'] ?> pax</div>
                </div>
                <div><?= requestStatusBadge($r['status']) ?></div>
            </div>
        </summary>
        <div class="card-body border-t border-base-300 pt-4">
            <?= requestDetailsHtml($conn, $r, ['show_after_confirmed' => true]) ?>
            <?php if ($rules['allowed']): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/cancel_request.php" class="mt-4 border-t border-base-300 pt-4">
                <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                <input type="hidden" name="return_to" value="staff/my_requests.php?open=<?= (int)$r['request_id'] ?>">
                <label class="mb-1 block text-sm font-medium" for="reason<?= (int)$r['request_id'] ?>">Cancel this request (the reason is optional)</label>
                <div class="join w-full">
                    <input class="input w-full" id="reason<?= (int)$r['request_id'] ?>" name="reason" maxlength="500" placeholder="Reason">
                    <button class="btn btn-outline btn-error" data-confirm="The request will be cancelled and the vehicle released." data-confirm-title="Cancel this request?" data-confirm-button="Cancel request" data-confirm-tone="danger">Cancel request</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
