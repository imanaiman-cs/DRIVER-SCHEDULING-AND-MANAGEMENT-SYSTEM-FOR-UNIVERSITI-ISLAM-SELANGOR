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
    $crew = [];
    if ($next && $next['status'] === 'processed') {
        $crew = array_column(dashRows($conn, "SELECT DISTINCT d.name FROM schedules s JOIN drivers d ON d.driver_id = s.driver_id WHERE s.request_id = ? AND s.status <> 'cancelled'", 'i', [(int)$next['request_id']]), 'name');
    }
    ?>
    <?= dashStats([
        ['label' => 'Waiting for approval', 'value' => $sum(['pending']), 'href' => $base . 'my_requests.php?status=pending'],
        ['label' => 'In progress', 'value' => $sum(['approved', 'assigned']), 'href' => $base . 'my_requests.php', 'hint' => 'Approved or Driver Assigned'],
        ['label' => 'Confirmed', 'value' => $sum(['processed']), 'href' => $base . 'my_requests.php?status=processed'],
        ['label' => 'Rejected, cancelled or lapsed', 'value' => $sum(['rejected', 'cancelled', 'lapsed']), 'href' => $base . 'my_requests.php'],
    ]) ?>
    <div class="grid-12 mb-6 grid grid-cols-12 gap-6">
        <div class="col-span-12 lg:col-span-5">
            <?php if (!$next): ?>
                <div class="dash-card">
                    <h6>Next trip</h6>
                    <?= dashEmpty('No upcoming trip') ?>
                    <div class="text-center"><a class="btn btn-primary btn-sm" href="<?= $base ?>request_vehicle.php">Request a vehicle</a></div>
                </div>
            <?php else: ?>
                <!-- The next trip, set like the trip slip the Vehicle Section issues -->
                <article class="dash-card">
                    <h6><span>Next trip</span><span class="font-mono font-normal text-base-content/60">#<?= (int)$next['request_id'] ?></span></h6>
                    <p class="text-xl font-medium leading-snug"><?= dh($next['destination']) ?></p>
                    <p class="font-mono text-sm text-base-content/70"><?= requestPeriodText($next) ?></p>
                    <dl class="mt-5 grid grid-cols-[6rem_1fr] gap-x-4 gap-y-3 text-sm">
                        <dt class="text-base-content/60">Passengers</dt><dd><?= (int)$next['passenger_count'] ?></dd>
                        <dt class="text-base-content/60">Status</dt><dd><?= dashStepper($next['status']) ?></dd>
                        <?php if ($next['status'] === 'processed'): ?>
                            <dt class="text-base-content/60">Driver</dt><dd><?= dh(implode(', ', $crew) ?: '-') ?></dd>
                            <dt class="text-base-content/60">Vehicle</dt><dd><?= plate($next['plate_number'] ?? '') ?: '-' ?></dd>
                        <?php else: ?>
                            <dt class="text-base-content/60">Driver</dt><dd class="text-base-content/60">Shown once Confirmed</dd>
                        <?php endif; ?>
                    </dl>
                    <a class="link link-primary mt-5 inline-block text-sm no-underline" href="<?= $base ?>my_requests.php?open=<?= (int)$next['request_id'] ?>">View request</a>
                </article>
            <?php endif; ?>
        </div>
        <div class="col-span-12 lg:col-span-7">
            <div class="dash-card">
                <h6><span>My active requests</span><a href="<?= $base ?>my_requests.php">See all</a></h6>
                <?php if (!$active): ?><?= dashEmpty('No requests in progress') ?><?php endif; ?>
                <div class="space-y-1">
                <?php foreach ($active as $r): ?>
                    <a class="dash-row block" href="<?= $base ?>my_requests.php?open=<?= (int)$r['request_id'] ?>">
                        <span class="block"><span class="font-mono text-base-content/60">#<?= (int)$r['request_id'] ?></span> <?= dh($r['destination']) ?> <span class="text-base-content/60">&middot; <?= requestPeriodText($r) ?></span></span>
                        <?= dashStepper($r['status']) ?>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="dash-card mb-6">
        <h6>Recent activity</h6>
        <?php if (!$activity): ?><?= dashEmpty('No activity yet') ?><?php endif; ?>
        <?php foreach ($activity as $a): ?>
            <div class="dash-row">
                <span><span class="font-medium"><?= dh(historyActionLabel($a['action'])) ?></span> <span class="font-mono text-base-content/60">#<?= (int)$a['request_id'] ?></span> <?= dh($a['destination']) ?></span>
                <span class="text-base-content/60"><?= dh($a['who']) ?>, <span class="font-mono"><?= date('d M H:i', strtotime($a['created_at'])) ?></span></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
