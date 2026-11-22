<?php
// ============================================================
// includes/dashboard_my.php  -  the "my requests" part of the dashboard (staff, and every other role as a requester)
// "Emerald Pill" layout: a pass card with my counts, my next trip, recent activity, then my active requests as a table.
// The driver and vehicle are shown only after the request is Confirmed (same rule as My Requests).
// ============================================================

require_once __DIR__ . '/dashboard_ui.php';

function dashMyRequestsSection(mysqli $conn, int $uid): void
{
    $base = SITE_URL . '/staff/';
    $counts = [];
    foreach (dashRows($conn, 'SELECT status, COUNT(*) AS n FROM vehicle_requests WHERE staff_id = ? GROUP BY status', 'i', [$uid]) as $r) { $counts[$r['status']] = (int)$r['n']; }
    $active = dashMyActive($conn, $uid);
    $activity = dashActivity($conn, $uid, false, 6);
    $next = null;
    foreach ($active as $r) { if (in_array($r['status'], ['approved', 'assigned', 'processed'], true)) { $next = $r; break; } }
    $next ??= $active[0] ?? null;
    $sum = fn(array $keys) => array_sum(array_map(fn($k) => $counts[$k] ?? 0, $keys));
    ?>
    <div class="grid grid-cols-12 gap-5">
        <!-- Left: my requests as a pass card -->
        <section class="e-card col-span-12 md:col-span-6 xl:col-span-4">
            <div class="e-head"><h2>My requests</h2><a href="<?= $base ?>my_requests.php" class="e-icon-btn" aria-label="Open My Requests"><i class="fas fa-arrow-right"></i></a></div>
            <div class="e-accent">
                <div class="flex items-center justify-between text-xs"><span class="font-bold tracking-[0.18em]">UIS &middot; TRANSPORT</span><i class="fas fa-car-side" aria-hidden="true"></i></div>
                <div class="mt-5 text-5xl leading-none font-bold tabular-nums"><?= array_sum($counts) ?></div>
                <div class="mt-1 text-xs text-white/85">requests in total</div>
                <div class="mt-5 flex justify-between gap-2 text-[0.7rem] text-white/85">
                    <span>Confirmed <?= $sum(['processed', 'completed']) ?></span><span>Active <?= count($active) ?></span>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <a class="e-tile" href="<?= $base ?>my_requests.php?status=pending"><span class="e-tile-label">Waiting for approval</span><span class="font-medium tabular-nums"><?= $sum(['pending']) ?></span></a>
                <a class="e-tile" href="<?= $base ?>my_requests.php"><span class="e-tile-label">In progress</span><span class="font-medium tabular-nums"><?= $sum(['approved', 'assigned']) ?></span></a>
                <a class="e-tile" href="<?= $base ?>my_requests.php?status=processed"><span class="e-tile-label">Confirmed</span><span class="font-medium tabular-nums"><?= $sum(['processed']) ?></span></a>
                <a class="e-tile" href="<?= $base ?>my_requests.php"><span class="e-tile-label">Rejected / cancelled / lapsed</span><span class="font-medium tabular-nums"><?= $sum(['rejected', 'cancelled', 'lapsed']) ?></span></a>
            </div>
        </section>

        <!-- Middle: my next trip -->
        <section class="e-card col-span-12 flex flex-col md:col-span-6 xl:col-span-5">
            <div class="e-head"><h2><i class="fas fa-route me-2 text-base-content/60" aria-hidden="true"></i>My next trip</h2><?php if ($next): ?><?= requestStatusBadge($next['status']) ?><?php endif; ?></div>
            <?php if (!$next): ?>
                <?= dashEmpty('No upcoming trip.', 'route') ?><div class="text-center"><a class="btn btn-primary btn-sm" href="<?= $base ?>request_vehicle.php">Request a vehicle</a></div>
            <?php else: ?>
                <div class="text-2xl font-bold tracking-tight"><?= dh($next['destination']) ?></div>
                <div class="mt-1 text-sm text-base-content/65"><?= requestPeriodText($next) ?> &middot; <?= (int)$next['passenger_count'] ?> pax</div>
                <?= dashStepper($next['status']) ?>
                <div class="mt-auto pt-4">
                    <div class="rounded-lg bg-base-200 p-4 text-sm">
                        <?php if ($next['status'] === 'processed'):
                            $a = dashRows($conn, "SELECT DISTINCT d.name FROM schedules s JOIN drivers d ON d.driver_id = s.driver_id WHERE s.request_id = ? AND s.status <> 'cancelled'", 'i', [(int)$next['request_id']]); ?>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span><span class="block text-xs text-base-content/65">Driver</span><strong><?= dh(implode(', ', array_column($a, 'name')) ?: '-') ?></strong></span>
                                <?= plate($next['plate_number'] ?? '') ?: '-' ?>
                            </div>
                        <?php else: ?>
                            <i class="fas fa-circle-info me-1 text-base-content/60" aria-hidden="true"></i>The driver and vehicle are shown here once the request is Confirmed.
                        <?php endif; ?>
                    </div>
                    <a class="btn btn-sm mt-3" href="<?= $base ?>my_requests.php?open=<?= (int)$next['request_id'] ?>">View details</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- Right: what happened lately -->
        <section class="e-card col-span-12 xl:col-span-3">
            <div class="e-head"><h2>Recent activity</h2></div>
            <?php if (!$activity): ?><?= dashEmpty('No activity yet.', 'clock-rotate-left') ?><?php endif; ?>
            <ol class="space-y-3">
                <?php foreach ($activity as $a): ?>
                <li class="flex gap-3 text-sm">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                    <span class="min-w-0"><span class="block font-semibold"><?= dh(historyActionLabel($a['action'])) ?> &middot; #<?= (int)$a['request_id'] ?></span>
                        <span class="block truncate text-xs text-base-content/65"><?= dh($a['destination']) ?></span>
                        <span class="block text-xs text-base-content/65"><?= dh($a['who']) ?> &middot; <?= date('d M H:i', strtotime($a['created_at'])) ?></span></span>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <!-- Bottom: my active requests -->
        <section class="e-card col-span-12">
            <div class="e-head"><h2>My active requests</h2><a class="btn btn-sm" href="<?= $base ?>my_requests.php">See all</a></div>
            <?php if (!$active): ?><?= dashEmpty('Nothing in progress.', 'list-check') ?><?php else: ?>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Request</th><th>Destination</th><th>Trip</th><th>Progress</th><th>Status</th><th><span class="sr-only">Action</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($active as $r): $rid = (int)$r['request_id']; ?>
                        <tr>
                            <td><strong>#<?= $rid ?></strong></td>
                            <td class="min-w-48"><?= dh($r['destination']) ?></td>
                            <td class="whitespace-nowrap"><?= requestPeriodText($r) ?></td>
                            <td><?= dashProgress($r['status']) ?></td>
                            <td><?= requestStatusBadge($r['status']) ?></td>
                            <td class="text-right"><a class="btn btn-sm" href="<?= $base ?>my_requests.php?open=<?= $rid ?>" aria-label="View request #<?= $rid ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <?php
}
