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
                v.plate_number, v.brand, v.model
           FROM schedules s
           JOIN vehicle_requests vr ON vr.request_id = s.request_id
           LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
          WHERE s.driver_id = ? AND s.status <> 'cancelled' AND s.end_dt $op ? AND " . SCHEDULE_CONFIRMED_ONLY_SQL . "
          ORDER BY s.start_dt $dir LIMIT $limit");
    $stmt->bind_param('is', $driver_id, $now);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $tasks[] = ['kind' => 'trip', 'start' => $r['start_dt'], 'end' => $r['end_dt'], 'type' => legLabel($r['leg_no'] !== null ? (int)$r['leg_no'] : null),
                    'ref' => 'Request #' . $r['request_id'], 'vehicle' => vehicleHtml($r['plate_number'], $r['brand'], $r['model']),
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
        $tasks[] = ['kind' => 'internal', 'start' => $r['start_dt'], 'end' => $r['end_dt'], 'type' => internalTaskTypeLabel($r['task_type']),
                    'ref' => '', 'vehicle' => vehicleHtml($r['plate_number'], $r['brand'], $r['model']), 'destination' => (string)$r['location'], 'waiting' => '',
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
    <h1 class="text-2xl font-extrabold tracking-tight mb-1"><i class="fas fa-route me-2 text-[0.8em] text-primary"></i>My Tasks</h1>
    <p class="text-base-content/60 text-sm mb-4">Your trips, internal tasks and welfare duty. A trip appears here once the Head of Admin has confirmed it.</p>
    <nav aria-label="Upcoming or past tasks" class="tabs tabs-border mb-6 flex-nowrap overflow-x-auto">
        <a class="tab gap-2 <?= $tab === 'upcoming' ? 'tab-active' : '' ?>" <?= $tab === 'upcoming' ? 'aria-current="page"' : '' ?> href="?tab=upcoming">Upcoming Tasks</a>
        <a class="tab gap-2 <?= $tab === 'past' ? 'tab-active' : '' ?>" <?= $tab === 'past' ? 'aria-current="page"' : '' ?> href="?tab=past">Past Tasks</a>
    </nav>
    <?php if (!$tasks): ?>
        <p class="dash-empty rounded-box border border-base-300"><?= $tab === 'past' ? 'No past tasks' : 'No upcoming tasks' ?></p>
    <?php endif; ?>
    <div class="grid-12 grid grid-cols-12 gap-4">
    <?php foreach ($tasks as $t):
        $s = strtotime($t['start']); $e = strtotime($t['end']); ?>
        <div class="col-span-12 lg:col-span-6">
            <div class="card h-full border border-base-300 bg-base-100"><div class="card-body">
                <div class="flex justify-between items-start gap-2 mb-2">
                    <div>
                        <time class="block text-lg font-medium" datetime="<?= date('Y-m-d', $s) ?>"><?= $h(date('D, d M Y', $s)) ?></time>
                        <div class="font-mono text-base-content/70"><?= $t['kind'] === 'welfare' ? 'All day (24 hours)' : $h(date('H:i', $s) . '–' . date('H:i', $e)) ?></div>
                    </div>
                    <span class="badge <?= ['trip' => 'badge-primary', 'internal' => 'badge-ghost', 'welfare' => 'badge-secondary'][$t['kind']] ?? 'badge-ghost' ?>"><?= $h($t['type']) ?></span>
                </div>
                <dl class="mt-2 grid grid-cols-[8rem_1fr] gap-x-4 gap-y-2 text-sm [&_dt]:text-base-content/60 [&_dd]:break-words">
                    <?php if ($t['ref']): ?><dt>Request</dt><dd><?= $h($t['ref']) ?></dd><?php endif; ?>
                    <?php if ($t['vehicle']): ?><dt>Vehicle</dt><dd><?= $t['vehicle'] ?></dd><?php endif; ?>
                    <?php if ($t['destination']): ?><dt><?= $t['kind'] === 'trip' ? 'Destination' : 'Place' ?></dt><dd><?= $h($t['destination']) ?></dd><?php endif; ?>
                    <?php if ($t['waiting']): ?><dt>Waiting location</dt><dd><?= $h($t['waiting']) ?></dd><?php endif; ?>
                    <?php if ($t['purpose']): ?><dt><?= $t['kind'] === 'trip' ? 'Purpose' : 'Details' ?></dt><dd><?= $h($t['purpose']) ?></dd><?php endif; ?>
                    <?php if ($t['officer']): ?><dt>Officer</dt><dd><?= $h($t['officer']) ?>
                        <?php if ($t['phone']): ?><br><a href="tel:<?= $h(preg_replace('/[^0-9+]/', '', $t['phone'])) ?>"><i class="fas fa-phone me-1"></i><?= $h($t['phone']) ?></a><?php endif; ?></dd><?php endif; ?>
                </dl>
                <?php if ($t['sheet']): ?><a class="btn btn-sm btn-outline mt-2" href="task_sheet.php?id=<?= (int)$t['sheet'] ?>" target="_blank" rel="noopener"><i class="fas fa-print me-1"></i>Task sheet</a><?php endif; ?>
            </div></div>
        </div>
    <?php endforeach; ?>
    </div>
<?php adminPageEnd(); ?>
