<?php
// ============================================================
// includes/dashboard_my.php  -  the "my requests" part of the dashboard (staff, and every other role as a requester)
// KPIs, my next trip, the active requests with their progress, and the latest activity.
// The driver and vehicle are shown only after the request is Confirmed (same rule as My Requests).
// ============================================================

require_once __DIR__ . '/dashboard_ui.php';

function dashMyRequestsSection(mysqli $conn, int $uid): void
{
    $base = SITE_URL . '/staff/';
    $counts = [];
    foreach (dashRows($conn, 'SELECT status, COUNT(*) AS n FROM vehicle_requests WHERE staff_id = ? GROUP BY status', 'i', [$uid]) as $r) { $counts[$r['status']] = (int)$r['n']; }
    $active = dashMyActive($conn, $uid);
    $activity = dashActivity($conn, $uid);
    $next = null;
    foreach ($active as $r) { if (in_array($r['status'], ['approved', 'assigned', 'processed'], true)) { $next = $r; break; } }
    $next ??= $active[0] ?? null;
    $sum = fn(array $keys) => array_sum(array_map(fn($k) => $counts[$k] ?? 0, $keys));
    ?>
    <div class="grid-12 grid grid-cols-12 gap-4 mb-4">
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Waiting for approval', $sum(['pending']), 'hourglass-half', 'warning', $base . 'my_requests.php?status=pending') ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('In progress', $sum(['approved', 'assigned']), 'person-walking-arrow-right', 'info', $base . 'my_requests.php', null, true, 'Approved or Driver Assigned') ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Confirmed', $sum(['processed']), 'circle-check', 'primary', $base . 'my_requests.php?status=processed') ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Rejected / cancelled / lapsed', $sum(['rejected', 'cancelled', 'lapsed']), 'ban', 'secondary', $base . 'my_requests.php') ?></div>
    </div>
    <div class="grid-12 grid grid-cols-12 gap-4 mb-4">
        <div class="col-span-12 lg:col-span-5">
            <div class="dash-card">
                <h6><span><i class="fas fa-route me-2 opacity-50"></i>My next trip</span></h6>
                <?php if (!$next): ?>
                    <?= dashEmpty('No upcoming trip.', 'route') ?><div class="text-center"><a class="btn btn-success btn-sm" href="<?= $base ?>request_vehicle.php">Request a vehicle</a></div>
                <?php else: ?>
                    <div class="text-lg font-bold"><?= dh($next['destination']) ?></div>
                    <div class="text-base-content/60 text-sm mb-1"><?= requestPeriodText($next) ?> &middot; <?= (int)$next['passenger_count'] ?> pax</div>
                    <?= dashStepper($next['status']) ?>
                    <div class="text-sm mt-4 dash-next">
                        <?php if ($next['status'] === 'processed'):
                            $a = dashRows($conn, "SELECT DISTINCT d.name FROM schedules s JOIN drivers d ON d.driver_id = s.driver_id WHERE s.request_id = ? AND s.status <> 'cancelled'", 'i', [(int)$next['request_id']]); ?>
                            <i class="fas fa-user-tie me-1 text-success"></i>Driver: <strong><?= dh(implode(', ', array_column($a, 'name')) ?: '-') ?></strong>
                            &middot; <i class="fas fa-car-side me-1 text-success"></i><strong><?= dh($next['plate_number'] ?? '-') ?></strong>
                        <?php else: ?>
                            <i class="fas fa-circle-info me-1 text-base-content/60"></i>The driver and vehicle are shown here once the request is Confirmed.
                        <?php endif; ?>
                    </div>
                    <a class="text-sm inline-block mt-2" href="<?= $base ?>my_requests.php?open=<?= (int)$next['request_id'] ?>">View details</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-span-12 lg:col-span-7">
            <div class="dash-card">
                <h6><span><i class="fas fa-list-check me-2 opacity-50"></i>My active requests</span><a class="text-sm font-normal" href="<?= $base ?>my_requests.php">See all</a></h6>
                <?php if (!$active): ?><?= dashEmpty('Nothing in progress.', 'list-check') ?><?php endif; ?>
                <?php foreach ($active as $r): ?>
                    <a class="block no-underline text-base-content py-2 border-t border-base-300" href="<?= $base ?>my_requests.php?open=<?= (int)$r['request_id'] ?>">
                        <div class="flex flex-wrap justify-between gap-2">
                            <span><strong>#<?= (int)$r['request_id'] ?></strong> <?= dh($r['destination']) ?> <span class="text-base-content/60 text-sm">&middot; <?= requestPeriodText($r) ?></span></span>
                            <?= requestStatusBadge($r['status']) ?>
                        </div>
                        <?= dashStepper($r['status']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="dash-card mb-4">
        <h6><span><i class="fas fa-clock-rotate-left me-2 opacity-50"></i>Recent activity</span></h6>
        <?php if (!$activity): ?><?= dashEmpty('No activity yet.', 'clock-rotate-left') ?><?php endif; ?>
        <?php foreach ($activity as $a): ?>
            <div class="dash-row">
                <span><strong><?= dh(historyActionLabel($a['action'])) ?></strong> &middot; #<?= (int)$a['request_id'] ?> <?= dh($a['destination']) ?></span>
                <span class="text-base-content/60"><?= dh($a['who']) ?> &middot; <?= date('d M H:i', strtotime($a['created_at'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
