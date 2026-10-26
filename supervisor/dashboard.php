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
    <div class="dash-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h4 class="fw-bold mb-2"><?= dashGreeting() ?>, <?= dh($_SESSION['full_name'] ?? '') ?></h4>
            <div><?= dashChips([['user-tie', 'Head of Department'], ['building-columns', (string)($dept ?? '')], ['calendar-day', date('l, d F Y')]]) ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light" href="approvals.php"><i class="fas fa-stamp me-1"></i>Vehicle approvals (<?= $total ?>)</a>
            <a class="btn btn-outline-light" href="../staff/request_vehicle.php"><i class="fas fa-car-side me-1"></i>Request a vehicle</a>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-4"><?= dashKpi('Waiting for my decision', $total, 'hourglass-half', 'warning', 'approvals.php', null, true, 'Requests of my own PTJ') ?></div>
        <div class="col-6 col-xl-4"><?= dashKpi('Approved this month', $approvedThisMonth, 'thumbs-up', 'success') ?></div>
        <div class="col-12 col-xl-4"><?= dashKpi('Rejected this month', $rejectedThisMonth, 'thumbs-down', 'danger') ?></div>
    </div>
    <div class="dash-card mb-4">
        <h6><span><i class="fas fa-stamp text-success me-1"></i>To approve</span><a class="small fw-normal" href="approvals.php">Open approvals</a></h6>
        <?php if (!$pending): ?><?= dashEmpty('No request is waiting for you.', 'circle-check') ?><?php endif; ?>
        <?php foreach ($pending as $r): ?>
            <a class="dash-row text-decoration-none text-dark" href="approvals.php">
                <span><strong>#<?= (int)$r['request_id'] ?></strong> <?= dh($r['destination']) ?> <span class="text-muted">&middot; <?= dh($r['staff_name']) ?></span></span>
                <span class="text-muted"><?= requestPeriodText($r) ?> &middot; waiting <?= dashWaiting($r['waiting_h']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <h5 class="fw-bold mb-3">My own requests</h5>
    <?php dashMyRequestsSection($conn, $uid); ?>
<?php adminPageEnd(); ?>
