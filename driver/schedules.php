<?php
// ============================================================
// driver/schedules.php  -  Driver "View Task" (SRS UC029). VIEW ONLY.
// Shows only the signed-in driver's own tasks:
//   * request tasks (outbound / return) once the request is Confirmed or Completed
//   * active internal tasks
//   * welfare duty days
// Upcoming tasks in date order; "Past Tasks" tab for the rest.
// ============================================================
$current_page = 'schedules.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
requireDriver();

$driver_id = (int)($_SESSION['driver_id'] ?? 0);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tab = ($_GET['tab'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$op = $tab === 'past' ? '<' : '>=';           // compared with the end of the task
$dir = $tab === 'past' ? 'DESC' : 'ASC';
$limit = $tab === 'past' ? 100 : 200;

$tasks = [];
if ($driver_id > 0) {
    // request tasks (Confirmed / Completed requests only)
    $stmt = $conn->prepare(
        "SELECT s.schedule_id, s.request_id, s.leg_no, s.start_dt, s.end_dt,
                COALESCE(vr.destination, s.destination) AS destination, vr.destination_address,
                COALESCE(vr.purpose, s.purpose) AS purpose, COALESCE(vr.officer_name, s.officer_name) AS officer_name,
                COALESCE(vr.officer_phone, s.officer_phone) AS officer_phone,
                COALESCE(vr.waiting_place, s.waiting_place) AS waiting_place, vr.waiting_address,
                v.plate_number, v.brand, v.model, s.vehicle_id
           FROM schedules s
           JOIN vehicle_requests vr ON vr.request_id = s.request_id
           LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
          WHERE s.driver_id = ? AND s.status <> 'cancelled' AND s.end_dt $op ? AND " . SCHEDULE_CONFIRMED_ONLY_SQL . "
          ORDER BY s.start_dt $dir LIMIT $limit");
    $stmt->bind_param('is', $driver_id, $now);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $tasks[] = ['upd' => $r['vehicle_id'] ? 'trip=' . (int)$r['schedule_id'] : '', 'kind' => 'trip', 'start' => $r['start_dt'], 'end' => $r['end_dt'], 'type' => legLabel($r['leg_no'] !== null ? (int)$r['leg_no'] : null),
                    'ref' => 'Request #' . $r['request_id'], 'vehicle' => vehicleText($r['plate_number'], $r['brand'], $r['model']),
                    'destination' => destinationText($r['destination'], $r['destination_address']), 'waiting' => waitingText($r['waiting_place'], $r['waiting_address']),
                    'purpose' => (string)$r['purpose'], 'officer' => (string)$r['officer_name'], 'phone' => (string)$r['officer_phone'], 'sheet' => (int)$r['schedule_id']];
    }
    $stmt->close();

    // internal tasks
    $stmt = $conn->prepare(
        "SELECT t.*, v.plate_number, v.brand, v.model FROM internal_tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
          WHERE t.driver_id = ? AND t.status = 'active' AND t.end_dt $op ? ORDER BY t.start_dt $dir LIMIT $limit");
    $stmt->bind_param('is', $driver_id, $now);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $tasks[] = ['upd' => $r['vehicle_id'] ? 'internal=' . (int)$r['task_id'] : '', 'kind' => 'internal', 'start' => $r['start_dt'], 'end' => $r['end_dt'], 'type' => internalTaskTypeLabel($r['task_type']),
                    'ref' => '', 'vehicle' => vehicleText($r['plate_number'], $r['brand'], $r['model']), 'destination' => (string)$r['location'], 'waiting' => '',
                    'purpose' => (string)$r['description'], 'officer' => '', 'phone' => '', 'sheet' => 0];
    }
    $stmt->close();

    // welfare duty days (a day is "past" once it is over)
    $stmt = $conn->prepare("SELECT duty_date FROM welfare_roster WHERE driver_id = ? AND duty_date " . ($tab === 'past' ? '<' : '>=') . " ? ORDER BY duty_date $dir LIMIT $limit");
    $stmt->bind_param('is', $driver_id, $today);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $tasks[] = ['kind' => 'welfare', 'start' => $r['duty_date'] . ' 00:00:00', 'end' => date('Y-m-d H:i:s', strtotime($r['duty_date'] . ' +1 day')), 'type' => 'Welfare duty',
                    'ref' => '', 'vehicle' => '', 'destination' => '', 'waiting' => '', 'purpose' => 'On duty for 24 hours (12 am to 12 am), ready for emergencies.',
                    'officer' => '', 'phone' => '', 'sheet' => 0];
    }
    $stmt->close();
    usort($tasks, fn($a, $b) => $tab === 'past' ? strcmp($b['start'], $a['start']) : strcmp($a['start'], $b['start']));
    $tasks = array_slice($tasks, 0, $limit);
}

adminPageStart('My Tasks');
?>
<style>
    .task-card { border: 0; border-left: 6px solid #198754; border-radius: .75rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .task-card.internal { border-left-color: #0d6efd; } .task-card.welfare { border-left-color: #6f42c1; }
    .task-card .when { font-size: 1.15rem; font-weight: 700; }
    .task-card dt { font-weight: 600; color: #555; }
    .task-card dd { margin-bottom: .4rem; font-size: 1.02rem; overflow-wrap: anywhere; }
</style>
    <h4 class="fw-bold mb-1"><i class="fas fa-route text-success me-2"></i>My Tasks</h4>
    <p class="text-muted small mb-3">Your trips, internal tasks and welfare duty. A trip appears here once the Head of Admin has confirmed it.</p>
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?= $tab === 'upcoming' ? 'active' : '' ?>" href="?tab=upcoming">Upcoming Tasks</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'past' ? 'active' : '' ?>" href="?tab=past">Past Tasks</a></li>
    </ul>
    <?php if (!$tasks): ?>
        <div class="alert alert-light border text-center py-4"><i class="fas fa-mug-hot fa-2x text-muted mb-2 d-block"></i>
            <?= $tab === 'past' ? 'You have no past tasks.' : 'You have no upcoming tasks.' ?></div>
    <?php endif; ?>
    <div class="row g-3">
    <?php foreach ($tasks as $t):
        $s = strtotime($t['start']); $e = strtotime($t['end']); ?>
        <div class="col-12 col-lg-6">
            <div class="card task-card <?= $h($t['kind']) ?>"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <div class="when"><?= $h(date('D, d M Y', $s)) ?></div>
                        <div class="fs-5"><?= $t['kind'] === 'welfare' ? 'All day (24 hours)' : $h(date('H:i', $s) . ' - ' . date('H:i', $e)) ?></div>
                    </div>
                    <span class="badge fs-6 <?= $t['kind'] === 'trip' ? 'bg-success' : ($t['kind'] === 'internal' ? 'bg-primary' : '') ?>" <?= $t['kind'] === 'welfare' ? 'style="background:#6f42c1"' : '' ?>><?= $h($t['type']) ?></span>
                </div>
                <dl class="mb-0">
                    <?php if ($t['ref']): ?><dt>Request</dt><dd><?= $h($t['ref']) ?></dd><?php endif; ?>
                    <?php if ($t['vehicle']): ?><dt>Vehicle</dt><dd><?= $h($t['vehicle']) ?></dd><?php endif; ?>
                    <?php if ($t['destination']): ?><dt><?= $t['kind'] === 'trip' ? 'Destination' : 'Place' ?></dt><dd><?= $h($t['destination']) ?></dd><?php endif; ?>
                    <?php if ($t['waiting']): ?><dt>Waiting location</dt><dd><?= $h($t['waiting']) ?></dd><?php endif; ?>
                    <?php if ($t['purpose']): ?><dt><?= $t['kind'] === 'trip' ? 'Purpose' : 'Details' ?></dt><dd><?= $h($t['purpose']) ?></dd><?php endif; ?>
                    <?php if ($t['officer']): ?><dt>Officer</dt><dd><?= $h($t['officer']) ?>
                        <?php if ($t['phone']): ?><br><a href="tel:<?= $h(preg_replace('/[^0-9+]/', '', $t['phone'])) ?>"><i class="fas fa-phone me-1"></i><?= $h($t['phone']) ?></a><?php endif; ?></dd><?php endif; ?>
                </dl>
                <?php if (!empty($t['upd'])): ?><a class="btn btn-sm btn-outline-success mt-2 me-1" href="update_vehicle.php?<?= $h($t['upd']) ?>"><i class="fas fa-gauge-high me-1"></i>Update Vehicle</a><?php endif; ?>
                <?php if ($t['sheet']): ?><a class="btn btn-sm btn-outline-secondary mt-2" href="task_sheet.php?id=<?= (int)$t['sheet'] ?>" target="_blank" rel="noopener"><i class="fas fa-print me-1"></i>Task sheet</a><?php endif; ?>
            </div></div>
        </div>
    <?php endforeach; ?>
    </div>
<?php adminPageEnd(); ?>
