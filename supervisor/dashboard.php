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
    <?= dashHeader('Today, ' . date('l j F'),
        '<a class="btn btn-primary btn-sm" href="approvals.php">Vehicle approvals<span class="font-mono">' . $total . '</span></a>'
      . '<a class="btn btn-sm" href="../staff/request_vehicle.php">Request a vehicle</a>') ?>
    <?= dashStats([
        ['label' => 'Waiting for my decision', 'value' => $total, 'href' => 'approvals.php', 'hint' => 'Requests from ' . ($dept ?: 'my department')],
        ['label' => 'Approved this month', 'value' => $approvedThisMonth],
        ['label' => 'Rejected this month', 'value' => $rejectedThisMonth],
    ]) ?>
    <div class="dash-card mb-6">
        <h6><span>To approve</span><a href="approvals.php">Open approvals</a></h6>
        <?php if (!$pending): ?><?= dashEmpty('No requests waiting for your approval') ?><?php endif; ?>
        <?php foreach ($pending as $r): ?>
            <a class="dash-row" href="approvals.php">
                <span><span class="font-mono text-base-content/60">#<?= (int)$r['request_id'] ?></span> <?= dh($r['destination']) ?> <span class="text-base-content/60">&middot; <?= dh($r['staff_name']) ?></span></span>
                <span class="text-base-content/60"><?= requestPeriodText($r) ?>, waiting <?= dashWaiting($r['waiting_h']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <h2 class="mb-4 text-lg font-medium">My own requests</h2>
    <?php dashMyRequestsSection($conn, $uid); ?>
<?php adminPageEnd(); ?>
