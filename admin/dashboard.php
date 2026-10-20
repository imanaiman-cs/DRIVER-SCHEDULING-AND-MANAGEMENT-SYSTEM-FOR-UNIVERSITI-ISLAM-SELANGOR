<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/dashboard.php  -  Administrative Assistant / Head of Admin: what needs attention today
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$c = [];
foreach ($conn->query("SELECT status, COUNT(*) AS n FROM vehicle_requests GROUP BY status")->fetch_all(MYSQLI_ASSOC) as $r) { $c[$r['status']] = (int)$r['n']; }
$vehicles = [];
foreach ($conn->query("SELECT status, COUNT(*) AS n FROM vehicles GROUP BY status")->fetch_all(MYSQLI_ASSOC) as $r) { $vehicles[$r['status']] = (int)$r['n']; }
$drivers = (int)$conn->query("SELECT COUNT(*) FROM drivers WHERE status = 'active'")->fetch_row()[0];

$today = $conn->query(
    "SELECT s.start_dt, s.end_dt, s.leg_no, s.destination, d.name AS driver, v.plate_number, vr.status AS req_status, vr.request_id
       FROM schedules s LEFT JOIN drivers d ON d.driver_id = s.driver_id LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
       LEFT JOIN vehicle_requests vr ON vr.request_id = s.request_id
      WHERE s.status <> 'cancelled' AND DATE(s.start_dt) = CURDATE() ORDER BY s.start_dt"
)->fetch_all(MYSQLI_ASSOC);
$internal = $conn->query(
    "SELECT t.task_type, t.start_dt, t.end_dt, d.name AS driver, t.description FROM internal_tasks t JOIN drivers d ON d.driver_id = t.driver_id
      WHERE t.status = 'active' AND t.task_date = CURDATE() ORDER BY t.start_dt"
)->fetch_all(MYSQLI_ASSOC);
$welfare = $conn->query("SELECT d.name FROM welfare_roster w JOIN drivers d ON d.driver_id = w.driver_id WHERE w.duty_date = CURDATE()")->fetch_row()[0] ?? null;

$tiles = [
    ['Needs a driver', $c['approved'] ?? 0, 'vehicle_requests.php?tab=approved', 'success'],
    ['Driver Assigned (waiting for the Head of Admin)', $c['assigned'] ?? 0, isHeadAdmin() ? 'approve_assignments.php' : 'vehicle_requests.php?tab=assigned', 'info'],
    ['Pending approval (Head of Department)', $c['pending'] ?? 0, 'reminders.php', 'warning'],
    ['Confirmed trips', $c['processed'] ?? 0, 'vehicle_requests.php?tab=processed', 'primary'],
];
adminPageStart('Dashboard');
?>
    <h4 class="fw-bold mb-3">Welcome, <?= htmlspecialchars($_SESSION['full_name'] ?? '') ?> <span class="badge bg-success fs-6 align-middle"><?= htmlspecialchars(roleLabel($_SESSION['role'])) ?></span></h4>
    <div class="row g-3 mb-3">
        <?php foreach ($tiles as [$label, $n, $href, $tone]): ?>
        <div class="col-6 col-lg-3"><a href="<?= $href ?>" class="text-decoration-none"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="fs-2 fw-bold text-<?= $tone ?>"><?= (int)$n ?></div><div class="small text-muted"><?= htmlspecialchars($label) ?></div>
        </div></div></a></div>
        <?php endforeach; ?>
    </div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h6 class="fw-bold">Today (<?= date('D, d M Y') ?>)</h6>
                <?php if (!$today && !$internal): ?><div class="text-muted">No trips or internal tasks today.</div><?php endif; ?>
                <?php foreach ($today as $t): ?>
                    <div class="d-flex flex-wrap justify-content-between gap-2 py-1 border-top small">
                        <span><strong><?= date('H:i', strtotime($t['start_dt'])) ?>-<?= date('H:i', strtotime($t['end_dt'])) ?></strong> <?= (int)$t['leg_no'] === 2 ? 'Return' : ((int)$t['leg_no'] === 1 ? 'Outbound' : 'Trip') ?> &middot; <?= htmlspecialchars($t['destination']) ?></span>
                        <span class="text-muted"><?= htmlspecialchars((string)$t['driver']) ?> &middot; <?= htmlspecialchars((string)$t['plate_number']) ?><?= $t['req_status'] ? ' &middot; ' . htmlspecialchars(requestStatusLabel($t['req_status'])) : '' ?></span>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($internal as $t): ?>
                    <div class="d-flex flex-wrap justify-content-between gap-2 py-1 border-top small">
                        <span><strong><?= date('H:i', strtotime($t['start_dt'])) ?>-<?= date('H:i', strtotime($t['end_dt'])) ?></strong> Internal task &middot; <?= htmlspecialchars(str_replace('_', ' ', $t['task_type'])) ?></span>
                        <span class="text-muted"><?= htmlspecialchars($t['driver']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="small text-muted border-top pt-2 mt-1">Welfare duty today: <strong><?= htmlspecialchars((string)($welfare ?? 'not set')) ?></strong></div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body small">
                <h6 class="fw-bold">Fleet</h6>
                <div><?= $drivers ?> active driver(s)</div>
                <?php foreach (['available', 'maintenance', 'inactive'] as $st): ?>
                    <div><span class="badge <?= vehicleStatusBadgeClass($st) ?>"><?= htmlspecialchars(vehicleStatusLabel($st)) ?></span> <?= $vehicles[$st] ?? 0 ?> vehicle(s)</div>
                <?php endforeach; ?>
            </div></div>
        </div>
    </div>
<?php adminPageEnd(); ?>
