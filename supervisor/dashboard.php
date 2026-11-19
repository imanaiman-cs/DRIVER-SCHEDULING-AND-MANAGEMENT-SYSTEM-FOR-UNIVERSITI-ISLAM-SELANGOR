<?php
// ============================================================
// UIS Driver Scheduling and Management System
// supervisor/dashboard.php  -  Head of Department: requests to decide, then my own requests
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/dashboard_my.php';
requireSupervisor();

$uid = (int)$_SESSION['user_id'];
$dept = $_SESSION['department'] ?? null;
$pending = dashHodPending($conn, $uid, $dept, 5);
$total = (int)dashValue($conn, "SELECT COUNT(*) FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id
                                 WHERE vr.status = 'pending' AND vr.supervisor_id = ? AND (u.role = 'supervisor' OR u.department IS NULL OR u.department <=> ?)", 'is', [$uid, $dept]);
$decided = dashRows($conn, "SELECT status, COUNT(*) AS n FROM vehicle_requests WHERE supervisor_id = ? AND reviewed_at >= ? AND status <> 'pending' GROUP BY status", 'is', [$uid, date('Y-m-01 00:00:00')]);
$decidedBy = array_column($decided, 'n', 'status');
$approvedThisMonth = array_sum(array_map(fn($k) => (int)($decidedBy[$k] ?? 0), ['approved', 'assigned', 'processed', 'completed']));
$rejectedThisMonth = (int)($decidedBy['rejected'] ?? 0);
adminPageStart('Dashboard');
?>
    <?= dashHeader([['Request a vehicle', '../staff/request_vehicle.php', 'car-side', false], ['Vehicle approvals (' . $total . ')', 'approvals.php', 'stamp', true]], 'Head of Department · ' . (string)($dept ?? '') ) ?>

    <div class="mb-8 grid grid-cols-12 gap-5">
        <section class="e-card col-span-12 lg:col-span-4">
            <div class="e-head"><h2>Waiting for my decision</h2><a href="approvals.php" class="e-icon-btn" aria-label="Open approvals"><i class="fas fa-arrow-right"></i></a></div>
            <div class="e-accent">
                <div class="flex items-center justify-between text-xs"><span class="font-bold tracking-[0.18em]">UIS &middot; APPROVALS</span><i class="fas fa-stamp" aria-hidden="true"></i></div>
                <div class="mt-5 text-5xl leading-none font-bold tabular-nums"><?= $total ?></div>
                <div class="mt-1 text-xs text-white/85">Requests of my own PTJ</div>
                <div class="mt-5 text-[0.7rem] text-white/85"><?= dh((string)($dept ?? '')) ?></div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="e-tile"><span class="e-tile-label">Approved this month</span><span class="font-medium tabular-nums"><?= $approvedThisMonth ?></span></div>
                <div class="e-tile"><span class="e-tile-label">Rejected this month</span><span class="font-medium tabular-nums"><?= $rejectedThisMonth ?></span></div>
            </div>
        </section>

        <section class="e-card col-span-12 lg:col-span-8">
            <div class="e-head"><h2>To approve</h2><a class="btn btn-sm" href="approvals.php">Open approvals</a></div>
            <?php if (!$pending): ?><?= dashEmpty('No request is waiting for you.', 'circle-check') ?><?php else: ?>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Request</th><th>Destination</th><th>Staff</th><th>Trip</th><th>Waiting</th><th><span class="sr-only">Action</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($pending as $r): $rid = (int)$r['request_id']; ?>
                        <tr>
                            <td><strong>#<?= $rid ?></strong></td>
                            <td class="min-w-40"><?= dh($r['destination']) ?></td>
                            <td><?= dh($r['staff_name']) ?></td>
                            <td class="whitespace-nowrap"><?= requestPeriodText($r) ?></td>
                            <td class="whitespace-nowrap"><span class="e-status text-warning"><?= dashWaiting($r['waiting_h']) ?></span></td>
                            <td class="text-right"><a class="btn btn-sm btn-primary" href="approvals.php" aria-label="Review request #<?= $rid ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <h2 class="mb-4 text-xl font-bold tracking-tight">My own requests</h2>
    <?php dashMyRequestsSection($conn, $uid); ?>
<?php adminPageEnd(); ?>
