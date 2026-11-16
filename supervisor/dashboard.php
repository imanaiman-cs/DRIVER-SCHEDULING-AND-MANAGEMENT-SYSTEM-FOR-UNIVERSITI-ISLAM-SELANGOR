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
    <?= dashHeader($_SESSION['full_name'] ?? '', ['Head of Department', (string)($dept ?? ''), date('l, d F Y')],
        '<a class="btn btn-primary btn-sm" href="approvals.php"><i class="fas fa-stamp"></i>Vehicle approvals (' . $total . ')</a>'
      . '<a class="btn btn-sm" href="../staff/request_vehicle.php"><i class="fas fa-car-side"></i>Request a vehicle</a>') ?>
    <div class="grid-12 grid grid-cols-12 gap-4 mb-4">
        <div class="col-span-6 xl:col-span-4"><?= dashKpi('Waiting for my decision', $total, 'hourglass-half', 'warning', 'approvals.php', null, true, 'Requests of my own PTJ') ?></div>
        <div class="col-span-6 xl:col-span-4"><?= dashKpi('Approved this month', $approvedThisMonth, 'thumbs-up', 'success') ?></div>
        <div class="col-span-12 xl:col-span-4"><?= dashKpi('Rejected this month', $rejectedThisMonth, 'thumbs-down', 'danger') ?></div>
    </div>
    <div class="dash-card mb-6">
        <h6><span><i class="fas fa-stamp me-2 opacity-50"></i>To approve</span><a class="text-sm font-normal" href="approvals.php">Open approvals</a></h6>
        <?php if (!$pending): ?><?= dashEmpty('No request is waiting for you.', 'circle-check') ?><?php endif; ?>
        <?php foreach ($pending as $r): ?>
            <a class="dash-row no-underline text-base-content" href="approvals.php">
                <span><strong>#<?= (int)$r['request_id'] ?></strong> <?= dh($r['destination']) ?> <span class="text-base-content/60">&middot; <?= dh($r['staff_name']) ?></span></span>
                <span class="text-base-content/60"><?= requestPeriodText($r) ?> &middot; waiting <?= dashWaiting($r['waiting_h']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <h5 class="font-bold mb-4">My own requests</h5>
    <?php dashMyRequestsSection($conn, $uid); ?>
<?php adminPageEnd(); ?>
