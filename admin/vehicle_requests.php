<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicle_requests.php  -  Administrative Assistant / Head of Admin: the requests and what to do with them
//
// Layout: a list on the left (search, status tabs, an "urgent" marker for trips leaving within 24 hours that are not
// Confirmed) and the chosen request on the right (summary, the next action, progress, details, history, comments).
//
//   Approved          -> "Assign driver" (UC017, with the recommendation UC018)
//   Driver Assigned   -> waits for the Head of Admin (admin/approve_assignments.php)
//   Confirmed         -> "Replace driver" (UC021) per task that has not started
//   Cancel (reason required) and Add comment are available on every active request.
// ============================================================
$current_page = 'vehicle_requests.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/dashboard_ui.php';
requireAdmin();

$tab = (string)($_GET['tab'] ?? 'approved');
$tabs = ['approved' => ['Needs a driver', "vr.status = 'approved'"], 'assigned' => ['Driver Assigned', "vr.status = 'assigned'"],
         'processed' => ['Confirmed', "vr.status = 'processed'"], 'pending' => ['Pending', "vr.status = 'pending'"],
         'history' => ['History', "vr.status IN ('rejected','cancelled','lapsed','completed')"], 'all' => ['All', '1 = 1']];
if (!isset($tabs[$tab])) { $tab = 'approved'; }
$q = trim((string)($_GET['q'] ?? ''));

$counts = [];
foreach ($conn->query("SELECT status, COUNT(*) AS n FROM vehicle_requests GROUP BY status")->fetch_all(MYSQLI_ASSOC) as $r) { $counts[$r['status']] = (int)$r['n']; }
$tabCount = fn(string $t) => match ($t) {
    'approved' => $counts['approved'] ?? 0, 'assigned' => $counts['assigned'] ?? 0, 'processed' => $counts['processed'] ?? 0, 'pending' => $counts['pending'] ?? 0,
    'history' => ($counts['rejected'] ?? 0) + ($counts['cancelled'] ?? 0) + ($counts['lapsed'] ?? 0) + ($counts['completed'] ?? 0), default => array_sum($counts),
};

// The list: the tab, narrowed by the search (request number, staff name, destination)
$sql = requestSelectSql() . ' WHERE ' . $tabs[$tab][1];
$types = ''; $args = [];
if ($q !== '') {
    $sql .= ' AND (vr.request_id = ? OR u.full_name LIKE ? OR vr.destination LIKE ?)';
    $like = '%' . $q . '%';
    $types = 'iss'; $args = [(int)ltrim($q, '#'), $like, $like];
}
$sql .= ' ORDER BY ' . ($tab === 'history' || $tab === 'all' ? 'vr.request_id DESC' : 'vr.depart_at, vr.request_id');
$st = $conn->prepare($sql);
if ($types) { $st->bind_param($types, ...$args); }
$st->execute();
$requests = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// The request shown on the right: ?open=ID (any status, so links from elsewhere keep working), else the first in the list
$open = (int)($_GET['open'] ?? 0);
$sel = null;
foreach ($requests as $r) { if ((int)$r['request_id'] === $open) { $sel = $r; } }
if (!$sel && $open > 0) {
    $st = $conn->prepare(requestSelectSql() . ' WHERE vr.request_id = ?');
    $st->bind_param('i', $open);
    $st->execute();
    $sel = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}
$sel ??= $requests[0] ?? null;

/** Leaving within 24 hours and not Confirmed yet? */
$isUrgent = fn(array $r) => in_array($r['status'], ['pending', 'approved', 'assigned'], true)
    && strtotime(requestDepartDT($r)) > time() && strtotime(requestDepartDT($r)) <= time() + 86400;
$hoursLeft = fn(array $r) => max(1, (int)ceil((strtotime(requestDepartDT($r)) - time()) / 3600));
$link = fn(array $extra = []) => '?' . http_build_query(array_filter(['tab' => $tab, 'q' => $q] + $extra, fn($v) => $v !== '' && $v !== null));

adminPageStart('Vehicle Requests');
?>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <h1>Vehicle Requests</h1>
        <form method="get" class="w-full sm:w-80" role="search">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <label class="input w-full">
                <i class="fas fa-magnifying-glass text-base-content/60" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" class="grow" placeholder="Request no., staff or destination" aria-label="Search requests">
            </label>
        </form>
    </div>

    <nav aria-label="Requests by status" class="tabs tabs-border mb-5 flex-nowrap overflow-x-auto">
        <?php foreach ($tabs as $k => [$label]): ?>
            <a class="tab gap-2 <?= $tab === $k ? 'tab-active' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?> href="?<?= http_build_query(array_filter(['tab' => $k, 'q' => $q])) ?>"><?= htmlspecialchars($label) ?> <span class="text-xs tabular-nums opacity-70"><?= $tabCount($k) ?></span></a>
        <?php endforeach; ?>
    </nav>

    <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <!-- The list -->
        <section class="e-card p-2 <?= $open > 0 ? 'max-lg:hidden' : '' ?>" aria-label="Requests">
            <?php if (!$requests): ?>
                <?= dashEmpty($q !== '' ? 'No request matches your search.' : 'Nothing here.', $q !== '' ? 'magnifying-glass' : 'inbox') ?>
            <?php endif; ?>
            <ul class="flex flex-col gap-1 lg:max-h-[calc(100dvh-15rem)] lg:overflow-y-auto">
                <?php foreach ($requests as $r): $rid = (int)$r['request_id']; $on = $sel && (int)$sel['request_id'] === $rid; $urgent = $isUrgent($r); ?>
                <li>
                    <a href="<?= htmlspecialchars($link(['open' => $rid])) ?>" class="block rounded-2xl px-3 py-3 transition-colors <?= $on ? 'bg-primary/10 ring-1 ring-primary/30' : 'hover:bg-base-200' ?>" <?= $on ? 'aria-current="true"' : '' ?>>
                        <div class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-2 text-xs font-semibold text-base-content/65">#<?= $rid ?>
                                <?php if ($urgent): ?><span class="inline-flex items-center gap-1 rounded-full bg-error/10 px-2 py-0.5 text-error"><i class="fas fa-clock" aria-hidden="true"></i>Leaves in <?= $hoursLeft($r) ?> h</span><?php endif; ?>
                            </span>
                            <?= requestStatusBadge($r['status']) ?>
                        </div>
                        <div class="mt-1 truncate font-semibold"><?= htmlspecialchars($r['destination']) ?></div>
                        <div class="mt-0.5 flex items-center justify-between gap-2 text-xs text-base-content/65">
                            <span class="truncate"><?= htmlspecialchars($r['staff_name']) ?></span>
                            <span class="shrink-0"><?= date('d M H:i', strtotime(requestDepartDT($r))) ?></span>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- The chosen request -->
        <?php if ($sel): $rid = (int)$sel['request_id']; $rules = cancelRulesFor($sel); $ret = 'admin/vehicle_requests.php' . $link(['open' => $rid]); $urgent = $isUrgent($sel); ?>
        <article class="flex min-w-0 flex-col gap-5 <?= $open > 0 ? '' : 'max-lg:hidden' ?>" aria-labelledby="reqTitle">
            <a class="btn btn-sm self-start lg:hidden" href="<?= htmlspecialchars($link()) ?>"><i class="fas fa-arrow-left"></i>Back to the list</a>

            <section class="e-card">
                <div class="e-accent">
                    <div class="flex items-center justify-between gap-2 text-xs"><span class="font-bold tracking-[0.18em]">REQUEST #<?= $rid ?></span><span class="rounded-full bg-white/20 px-2.5 py-0.5 font-semibold"><?= htmlspecialchars(requestStatusLabel($sel['status'])) ?></span></div>
                    <h2 id="reqTitle" class="mt-4 text-2xl font-bold tracking-tight text-white"><?= htmlspecialchars($sel['destination']) ?></h2>
                    <div class="mt-1 text-sm text-white/85"><?= requestPeriodText($sel) ?></div>
                    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs text-white/85">
                        <span><i class="fas fa-user me-1" aria-hidden="true"></i><?= htmlspecialchars($sel['staff_name']) ?></span>
                        <span><i class="fas fa-users me-1" aria-hidden="true"></i><?= (int)$sel['passenger_count'] ?> pax</span>
                        <?php if ($sel['plate_number']): ?><span><i class="fas fa-van-shuttle me-1" aria-hidden="true"></i><?= htmlspecialchars($sel['plate_number']) ?></span><?php endif; ?>
                    </div>
                </div>

                <?php if ($urgent): ?>
                <div class="mt-4 flex items-start gap-3 rounded-2xl bg-error/10 px-4 py-3 text-sm text-error" role="note"><i class="fas fa-triangle-exclamation mt-0.5" aria-hidden="true"></i><span><strong>Leaves in <?= $hoursLeft($sel) ?> h</strong> and is not Confirmed yet.</span></div>
                <?php endif; ?>

                <!-- The next action -->
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <?php if ($sel['status'] === 'approved'): ?>
                        <a class="btn btn-primary" href="assign_driver.php?request_id=<?= $rid ?>"><i class="fas fa-user-plus"></i>Assign driver</a>
                    <?php endif; ?>
                    <?php if ($sel['status'] === 'assigned'): ?>
                        <?php if (isHeadAdmin()): ?>
                            <a class="btn btn-primary" href="approve_assignments.php?open=<?= $rid ?>"><i class="fas fa-user-check"></i>Approve or reject the assignment</a>
                        <?php else: ?>
                            <span class="text-sm text-base-content/65"><i class="fas fa-hourglass-half me-1" aria-hidden="true"></i>Waiting for the Head of Admin</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($sel['status'] === 'processed'):
                        $ts = $conn->prepare("SELECT schedule_id, leg_no FROM schedules WHERE request_id = ? AND status <> 'cancelled' AND start_dt > NOW() ORDER BY leg_no");
                        $ts->bind_param('i', $rid); $ts->execute(); $tasks = $ts->get_result()->fetch_all(MYSQLI_ASSOC); $ts->close(); ?>
                        <?php foreach ($tasks as $t): ?>
                            <a class="btn" href="replace_driver.php?schedule_id=<?= (int)$t['schedule_id'] ?>"><i class="fas fa-right-left"></i>Replace driver (<?= (int)$t['leg_no'] === 2 ? 'return' : 'outbound' ?>)</a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($sel['status'] === 'pending'): ?>
                        <a class="btn" href="reminders.php"><i class="fas fa-bell-concierge"></i>Approval Reminders</a>
                    <?php endif; ?>
                    <?php if ($rules['allowed']): ?>
                        <button type="button" class="btn btn-ghost ms-auto text-error" data-open-dialog="cancelDialog"><i class="fas fa-ban"></i>Cancel request</button>
                    <?php endif; ?>
                </div>

                <?php if (in_array($sel['status'], ['pending', 'approved', 'assigned', 'processed', 'completed'], true)): ?>
                <div class="mt-2"><?= dashStepper($sel['status']) ?></div>
                <?php endif; ?>
            </section>

            <section class="e-card">
                <div class="e-head"><h2>Details</h2></div>
                <?= requestDetailsHtml($conn, $sel, ['show_assignment' => true]) ?>

                <?php if (!in_array($sel['status'], ['rejected', 'lapsed'], true)): ?>
                <form method="post" action="<?= SITE_URL ?>/ajax/add_comment.php" class="mt-5 border-t border-base-300 pt-5">
                    <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                    <label class="mb-1.5 block text-sm font-medium" for="cm<?= $rid ?>">Add a comment (the staff member is told)</label>
                    <div class="join w-full">
                        <input class="input join-item w-full" id="cm<?= $rid ?>" name="comment" maxlength="1000" required>
                        <button class="btn join-item">Add comment</button>
                    </div>
                </form>
                <?php endif; ?>
            </section>
        </article>

        <?php if ($rules['allowed']): ?>
        <dialog id="cancelDialog" class="modal" aria-labelledby="cancelTitle">
            <form method="post" action="<?= SITE_URL ?>/ajax/cancel_request.php" class="modal-box">
                <input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($ret) ?>">
                <h3 id="cancelTitle" class="text-lg font-bold">Cancel request #<?= $rid ?>?</h3>
                <p class="mt-2 text-sm text-base-content/65">The request is cancelled, the vehicle is released, the driver is removed and everybody involved is told.</p>
                <label class="mt-4 mb-1.5 block text-sm font-medium" for="cr<?= $rid ?>">Reason for cancelling <span class="text-error">(reason required)</span></label>
                <textarea class="textarea w-full" id="cr<?= $rid ?>" name="reason" maxlength="500" rows="3" required></textarea>
                <div class="modal-action">
                    <button type="button" class="btn" data-close-dialog>Keep the request</button>
                    <button class="btn btn-error">Cancel request</button>
                </div>
            </form>
            <form method="dialog" class="modal-backdrop"><button>Close</button></form>
        </dialog>
        <?php endif; ?>
        <?php endif; ?>
    </div>
<script>
document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open-dialog]');
    if (o) { var d = document.getElementById(o.getAttribute('data-open-dialog')); if (d) { d.showModal(); var f = d.querySelector('textarea, input:not([type=hidden])'); if (f) { f.focus(); } } return; }
    var c = e.target.closest('[data-close-dialog]');
    if (c) { c.closest('dialog').close(); }
});
</script>
<?php adminPageEnd(); ?>
