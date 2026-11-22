<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicle_requests.php  -  Administrative Assistant / Head of Admin: the requests and what to do with them
//
// Layout: a full-width table (search, status tabs) with a Remark per request (what is happening to it now: who it waits
// for, the automatic reminder, an urgent trip, the reason it was rejected or cancelled) and the action in the row.
// A row opens the request in a pop-up in the middle of the content area (?open=ID): summary, action, progress, details, history, comments.
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
$sql = 'SELECT x.*, (SELECT GROUP_CONCAT(DISTINCT d.name ORDER BY s.leg_no SEPARATOR \', \') FROM schedules s JOIN drivers d ON d.driver_id = s.driver_id
                         WHERE s.request_id = x.request_id AND s.status <> \'cancelled\') AS driver_names,
               (SELECT MAX(r.sent_at) FROM reminders r WHERE r.request_id = x.request_id) AS reminder_sent
          FROM (' . requestSelectSql() . ' WHERE ' . $tabs[$tab][1];
$types = ''; $args = [];
if ($q !== '') {
    $sql .= ' AND (vr.request_id = ? OR u.full_name LIKE ? OR vr.destination LIKE ?)';
    $like = '%' . $q . '%';
    $types = 'iss'; $args = [(int)ltrim($q, '#'), $like, $like];
}
$sql .= ') x ORDER BY ' . ($tab === 'history' || $tab === 'all' ? 'x.request_id DESC' : 'x.depart_at, x.request_id');
$st = $conn->prepare($sql);
if ($types) { $st->bind_param($types, ...$args); }
$st->execute();
$requests = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// The request shown in the drawer: ?open=ID (any status, so links from elsewhere keep working)
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

/** Leaving within 24 hours and not Confirmed yet? */
$isUrgent = fn(array $r) => in_array($r['status'], ['pending', 'approved', 'assigned'], true)
    && strtotime(requestDepartDT($r)) > time() && strtotime(requestDepartDT($r)) <= time() + 86400;
$hoursLeft = fn(array $r) => max(1, (int)ceil((strtotime(requestDepartDT($r)) - time()) / 3600));
$link = fn(array $extra = []) => '?' . http_build_query(array_filter(['tab' => $tab, 'q' => $q] + $extra, fn($v) => $v !== '' && $v !== null));
$remindH = reminderHours($conn);

/** Remark: what is happening to the request now, as [tone, icon, text]. */
$remark = function (array $r) use ($isUrgent, $hoursLeft, $remindH): array {
    if ($isUrgent($r)) { return ['error', 'clock', 'Leaves in ' . $hoursLeft($r) . ' h and is not Confirmed yet']; }
    $reason = trim((string)($r['status_reason'] ?? ''));
    return match ($r['status']) {
        'pending'   => ['warning', 'bell', $r['reminder_sent']
                          ? 'Head of Department to approve; reminder sent ' . date('d M', strtotime($r['reminder_sent']))
                          : 'Head of Department to approve; reminder on ' . date('d M, H:i', max(time(), strtotime($r['created_at']) + $remindH * 3600))],
        'approved'  => ['primary', 'user-plus', 'Approved; needs a driver'],
        'assigned'  => ['info', 'hourglass-half', isHeadAdmin() ? 'Waiting for your approval' : 'Head of Admin to approve'],
        'processed' => ['success', 'circle-check', 'Confirmed; driver and staff told'],
        'completed' => ['success', 'flag-checkered', 'Trip completed'],
        'rejected'  => ['error', 'circle-xmark', 'Rejected' . ($reason !== '' ? ': ' . $reason : '')],
        'cancelled' => ['error', 'ban', 'Cancelled' . ($reason !== '' ? ': ' . $reason : '')],
        'lapsed'    => ['muted', 'hourglass-end', 'Lapsed: not approved in time'],
        default     => ['muted', 'circle-info', requestStatusLabel($r['status'])],
    };
};

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

    <section class="card overflow-hidden" aria-label="Requests">
        <?php if (!$requests): ?>
            <?= dashEmpty($q !== '' ? 'No request matches your search.' : 'Nothing here.', $q !== '' ? 'magnifying-glass' : 'inbox') ?>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table vr-table">
                <thead><tr><th>Request</th><th>Destination &amp; requester</th><th>Trip</th><th>Vehicle &amp; driver</th><th>Remark</th><th><span class="sr-only">Action</span></th></tr></thead>
                <tbody>
                <?php foreach ($requests as $r): $rid = (int)$r['request_id']; $on = $sel && (int)$sel['request_id'] === $rid; [$tone, $icon, $text] = $remark($r);
                      $dep = strtotime(requestDepartDT($r)); $retT = strtotime(requestReturnDT($r)); $openHref = $link(['open' => $rid]); ?>
                    <tr class="vr-row <?= $on ? 'is-open' : '' ?>" data-href="<?= htmlspecialchars($openHref) ?>">
                        <td class="whitespace-nowrap"><a class="font-bold hover:underline" href="<?= htmlspecialchars($openHref) ?>" <?= $on ? 'aria-current="true"' : '' ?>>#<?= $rid ?></a>
                            <?php if ($tab === 'all' || $tab === 'history'): ?><div class="mt-1"><?= requestStatusBadge($r['status']) ?></div><?php endif; ?></td>
                        <td class="min-w-56"><div class="font-semibold"><?= htmlspecialchars($r['destination']) ?></div>
                            <div class="text-xs text-base-content/70"><?= htmlspecialchars($r['staff_name']) ?> · <?= htmlspecialchars((string)$r['staff_department']) ?></div>
                            <div class="text-xs text-base-content/70"><?= (int)$r['passenger_count'] ?> pax<?= $r['waiting_place'] ? ' · ' . htmlspecialchars($r['waiting_place']) : '' ?></div></td>
                        <td class="whitespace-nowrap"><?= date('D d M', $dep) ?><div class="text-xs text-base-content/70"><?= date('H:i', $dep) ?> &rarr; <?= date('Y-m-d', $dep) === date('Y-m-d', $retT) ? date('H:i', $retT) : date('d M H:i', $retT) ?></div></td>
                        <td class="min-w-36 max-w-52"><?= $r['plate_number'] ? '<span class="plate">' . htmlspecialchars($r['plate_number']) . '</span>' : '<span class="text-base-content/70">&mdash;</span>' ?>
                            <?php if ($r['driver_names']): ?><div class="text-xs text-base-content/70"><?= htmlspecialchars($r['driver_names']) ?></div><?php endif; ?></td>
                        <td class="min-w-48 max-w-72"><span class="vr-remark vr-t-<?= $tone ?>"><i class="fas fa-<?= $icon ?>" aria-hidden="true"></i><span><?= htmlspecialchars($text) ?></span></span></td>
                        <td class="text-right whitespace-nowrap">
                            <?php if ($r['status'] === 'approved'): ?>
                                <a class="btn btn-sm btn-primary" href="assign_driver.php?request_id=<?= $rid ?>" aria-label="Assign a driver to request #<?= $rid ?>">Assign</a>
                            <?php elseif ($r['status'] === 'assigned' && isHeadAdmin()): ?>
                                <a class="btn btn-sm btn-primary" href="approve_assignments.php?open=<?= $rid ?>" aria-label="Approve the assignment of request #<?= $rid ?>">Approve</a>
                            <?php else: ?>
                                <a class="btn btn-sm" href="<?= htmlspecialchars($openHref) ?>" aria-label="View request #<?= $rid ?>">View</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

        <!-- The chosen request -->
        <?php if ($sel): $rid = (int)$sel['request_id']; $rules = cancelRulesFor($sel); $ret = 'admin/vehicle_requests.php' . $link(['open' => $rid]); $urgent = $isUrgent($sel); $closeHref = $link(); ?>
        <div class="vr-drawer" id="reqDrawer" role="dialog" aria-modal="true" aria-labelledby="reqTitle" data-close-href="<?= htmlspecialchars($closeHref) ?>">
        <a class="vr-backdrop" href="<?= htmlspecialchars($closeHref) ?>" tabindex="-1" aria-hidden="true"></a>
        <article class="vr-panel flex min-w-0 flex-col gap-5" aria-labelledby="reqTitle">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm text-base-content/70">Request #<?= $rid ?></span>
                <a class="btn btn-sm" id="drawerClose" href="<?= htmlspecialchars($closeHref) ?>"><i class="fas fa-xmark"></i>Close</a>
            </div>

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
                <div class="mt-4 flex items-start gap-3 rounded-lg bg-error/10 px-4 py-3 text-sm text-error" role="note"><i class="fas fa-triangle-exclamation mt-0.5" aria-hidden="true"></i><span><strong>Leaves in <?= $hoursLeft($sel) ?> h</strong> and is not Confirmed yet.</span></div>
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
                    <?php if ($sel['status'] === 'pending'):
                        // The approval reminder is automatic (runRequestJobs); show when it went out or will go out.
                        $rs = $conn->prepare('SELECT MAX(sent_at) FROM reminders WHERE request_id = ?');
                        $rs->bind_param('i', $rid); $rs->execute(); $remSent = $rs->get_result()->fetch_row()[0]; $rs->close();
                        $remDue = max(time(), strtotime($sel['created_at']) + reminderHours($conn) * 3600); ?>
                        <span class="text-sm text-base-content/70"><i class="fas fa-bell me-1" aria-hidden="true"></i><?= $remSent
                            ? 'Reminder sent to the Head of Department on ' . date('d M Y, H:i', strtotime($remSent))
                            : 'Automatic reminder to the Head of Department on ' . date('d M Y, H:i', $remDue) ?></span>
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
        </div>

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
<script>
document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open-dialog]');
    if (o) { var d = document.getElementById(o.getAttribute('data-open-dialog')); if (d) { d.showModal(); var f = d.querySelector('textarea, input:not([type=hidden])'); if (f) { f.focus(); } } return; }
    var c = e.target.closest('[data-close-dialog]');
    if (c) { c.closest('dialog').close(); return; }
    // A click anywhere on a row opens the request (links and buttons in the row keep their own target)
    var row = e.target.closest('tr[data-href]');
    if (row && !e.target.closest('a, button, input, label')) { location.href = row.getAttribute('data-href'); }
});
(function () {
    var drawer = document.getElementById('reqDrawer');
    if (!drawer) { return; }
    document.body.appendChild(drawer);   // above the top bar; centred on the content area beside the sidebar (see app.css)
    document.documentElement.classList.add('overflow-hidden');
    var close = document.getElementById('drawerClose'); if (close) { close.focus(); }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !document.querySelector('dialog[open]')) { location.href = drawer.getAttribute('data-close-href'); }
    });
})();
</script>
<?php adminPageEnd(); ?>
