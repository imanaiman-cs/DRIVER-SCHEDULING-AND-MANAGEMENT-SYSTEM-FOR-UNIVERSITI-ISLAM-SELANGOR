<?php
// ============================================================
// UIS Driver Scheduling and Management System
// staff/dashboard.php  -  the staff member's start page: the status of the latest requests
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireRequester();   // every role is also staff

$uid = (int)$_SESSION['user_id'];
$counts = [];
$cs = $conn->prepare('SELECT status, COUNT(*) AS n FROM vehicle_requests WHERE staff_id = ? GROUP BY status');
$cs->bind_param('i', $uid);
$cs->execute();
foreach ($cs->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $counts[$r['status']] = (int)$r['n']; }
$cs->close();

$stmt = $conn->prepare(requestSelectSql() . ' WHERE vr.staff_id = ? ORDER BY vr.created_at DESC, vr.request_id DESC LIMIT 5');
$stmt->bind_param('i', $uid);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$tiles = [['Waiting for approval', ['pending'], 'warning'], ['Approved', ['approved', 'assigned'], 'success'], ['Confirmed', ['processed'], 'primary'], ['Finished', ['completed'], 'dark'],
          ['Rejected / cancelled / lapsed', ['rejected', 'cancelled', 'lapsed'], 'secondary']];
adminPageStart('Dashboard');
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-0">Welcome, <?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></h4>
            <div class="text-muted small"><?= htmlspecialchars((string)($_SESSION['department'] ?? 'Universiti Islam Selangor')) ?></div>
        </div>
        <a class="btn btn-success" href="request_vehicle.php"><i class="fas fa-car-side me-1"></i>Request a vehicle</a>
    </div>
    <div class="row g-3 mb-3">
        <?php foreach ($tiles as [$label, $statuses, $tone]): $total = 0; foreach ($statuses as $s) { $total += $counts[$s] ?? 0; } ?>
        <div class="col-6 col-md"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="fs-3 fw-bold text-<?= $tone ?>"><?= $total ?></div><div class="small text-muted"><?= htmlspecialchars($label) ?></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-between"><h6 class="fw-bold">Latest requests</h6><a class="small" href="my_requests.php">See all</a></div>
        <?php if (!$recent): ?><div class="text-muted">You have not made a request yet.</div><?php endif; ?>
        <?php foreach ($recent as $r): ?>
            <a class="d-flex flex-wrap justify-content-between gap-2 py-2 border-top text-decoration-none text-dark" href="my_requests.php?open=<?= (int)$r['request_id'] ?>">
                <span><strong>#<?= (int)$r['request_id'] ?></strong> <?= htmlspecialchars($r['destination']) ?> <span class="text-muted small">&middot; <?= requestPeriodText($r) ?></span></span>
                <?= requestStatusBadge($r['status']) ?>
            </a>
        <?php endforeach; ?>
    </div></div>
<?php adminPageEnd(); ?>
