<?php
// ============================================================
// supervisor/department_requests.php  -  Department Request (SRS 10 Nov UC017 A1)
//
// The Head of Department sees ALL requests of his own department (any status), filtered by status or departure date, with the details and the
// status history. He acts on pending requests in Vehicle Approvals; this list is for viewing.
// ============================================================
$current_page = 'department_requests.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireSupervisor();

$uid  = (int)$_SESSION['user_id'];
$dept = $_SESSION['department'] ?? null;
[$fSql, $fTypes, $fParams, $fv] = requestListFilter($_GET);
$stmt = $conn->prepare(requestSelectSql() . " WHERE udp.department_name <=> ?$fSql ORDER BY vr.trip_date DESC, vr.request_id DESC");
$stmt->bind_param('s' . $fTypes, $dept, ...$fParams);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$open = (int)($_GET['open'] ?? 0);

adminPageStart('Department Requests');
?>
    <h1 class="text-2xl font-medium mb-4">Department Requests <small class="text-base-content/60 text-base"><?= htmlspecialchars((string)$dept) ?></small></h1>
    <?php requestFilterForm($fv); ?>
    <?php if (!$requests): ?><div class="card border border-base-300 bg-base-100"><div class="card-body text-center text-base-content/60 py-12">No record is found.</div></div><?php endif; ?>
    <?php foreach ($requests as $r): $rid = (int)$r['request_id']; ?>
    <details class="card border border-base-300 bg-base-100 mb-2" <?= $rid === $open ? 'open' : '' ?>>
        <summary class="card-body py-2" style="cursor:pointer;list-style:none;">
            <div class="flex flex-wrap justify-between items-center gap-2">
                <div>
                    <span class="font-semibold">#<?= $rid ?> &middot; <?= htmlspecialchars($r['staff_name']) ?> &middot; <?= htmlspecialchars($r['destination']) ?></span>
                    <div class="text-sm text-base-content/60"><?= requestPeriodText($r) ?> &middot; <?= htmlspecialchars((string)($r['vehicle_type'] ?: ($r['request_type'] === 'pay_claim' ? 'Pay and Claim' : ''))) ?> &middot; <?= (int)$r['passenger_count'] ?> pax</div>
                    <div class="mt-1 flex flex-wrap items-center gap-2"><?= requestJourneyHtml($r, true) ?><?= tripCountdownHtml(requestDepartDT($r), in_array($r['status'], REQUEST_ACTIVE_STATUSES, true)) ?></div>
                </div>
                <div class="flex gap-1"><?= !empty($r['is_urgent']) ? '<span class="badge badge-soft badge-error">Urgent</span>' : '' ?><?= requestStatusBadge($r['status']) ?></div>
            </div>
        </summary>
        <div class="card-body border-t border-base-300 pt-4"><?= requestDetailsHtml($conn, $r, ['show_after_confirmed' => true]) ?>
            <?php $rules = cancelRulesFor($r); if ($rules['allowed']): ?>
            <form method="post" action="<?= SITE_URL ?>/ajax/cancel_request.php" class="mt-4 border-t border-base-300 pt-4">          <!-- UC021 A1: the Head of Department cancels a request of the department -->
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="supervisor/department_requests.php?open=<?= $rid ?>">
                <label class="mb-1 block text-sm font-medium" for="cr<?= $rid ?>">Cancel this request <span class="text-error">(reason required)</span></label>
                <div class="join w-full"><input class="input join-item w-full" id="cr<?= $rid ?>" name="reason" maxlength="500" required placeholder="Reason">
                    <button class="btn btn-outline btn-error join-item" data-confirm="The request is cancelled, the reservation is released and the people involved are told." data-confirm-title="Cancel request #<?= $rid ?>?" data-confirm-button="Cancel request" data-confirm-tone="danger">Cancel request</button></div>
            </form>
            <?php endif; ?></div>
    </details>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
