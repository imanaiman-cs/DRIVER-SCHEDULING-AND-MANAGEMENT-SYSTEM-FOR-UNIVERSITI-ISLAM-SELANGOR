<?php
// ============================================================
// driver/schedules.php  -  Driver "View Task" (SRS UC029). VIEW ONLY.
// Shows only the signed-in driver's own tasks:
//   * request tasks (outbound / return) once the request is Confirmed or Completed
//   * approved internal tasks
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
        "SELECT s.task_id, s.request_id, s.trip_type, s.start_dt, s.end_dt,
                COALESCE(vr.destination, s.location) AS destination, vr.destination_address,
                COALESCE(vr.purpose, s.description) AS purpose, vr.officer_name, vr.officer_phone,
                vr.waiting_place, vr.waiting_address,
                v.plate_number, v.brand, v.model, s.vehicle_id
           FROM tasks s
           JOIN vehicle_requests vr ON vr.request_id = s.request_id
           LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
          WHERE s.task_source = 'request' AND s.driver_id = ? AND s.status <> 'cancelled' AND s.end_dt $op ? AND " . SCHEDULE_CONFIRMED_ONLY_SQL . "
          ORDER BY s.start_dt $dir LIMIT $limit");
    $stmt->bind_param('is', $driver_id, $now);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rep = taskReportOf($conn, 'trip', (int)$r['task_id']);   // UC024: Create Report only once the task has started
        $tasks[] = ['upd' => $r['vehicle_id'] && ($rep || strtotime($r['start_dt']) <= time()) ? 'trip=' . (int)$r['task_id'] : '', 'reported' => (bool)$rep, 'kind' => 'trip', 'start' => $r['start_dt'], 'end' => $r['end_dt'], 'type' => legLabel($r['trip_type']),
                    'ref' => 'Request #' . $r['request_id'], 'vehicle' => vehicleHtml($r['plate_number'], $r['brand'], $r['model']),
                    'destination' => destinationText($r['destination'], $r['destination_address']), 'waiting' => waitingText($r['waiting_place'], $r['waiting_address']),
                    'purpose' => (string)$r['purpose'], 'officer' => (string)$r['officer_name'], 'phone' => (string)$r['officer_phone'], 'sheet' => (int)$r['task_id']];
    }
    $stmt->close();

    // internal tasks
    $stmt = $conn->prepare(
        "SELECT t.*, v.plate_number, v.brand, v.model FROM tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
          WHERE t.task_source = 'internal' AND t.driver_id = ? AND t.status = 'approved' AND t.end_dt $op ? ORDER BY t.start_dt $dir LIMIT $limit");
    $stmt->bind_param('is', $driver_id, $now);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rep = taskReportOf($conn, 'internal', (int)$r['task_id']);
        $tasks[] = ['upd' => $r['vehicle_id'] && ($rep || strtotime($r['start_dt']) <= time()) ? 'internal=' . (int)$r['task_id'] : '', 'reported' => (bool)$rep, 'kind' => 'internal', 'start' => $r['start_dt'], 'end' => $r['end_dt'], 'type' => internalTaskTypeLabel($r['task_type']),
                    'ref' => '', 'vehicle' => vehicleHtml($r['plate_number'], $r['brand'], $r['model']), 'destination' => (string)$r['location'], 'waiting' => '',
                    'purpose' => (string)$r['description'], 'officer' => '', 'phone' => '', 'sheet' => 0];
    }
    $stmt->close();

    // welfare duty days (a day is "past" once it is over)
    $stmt = $conn->prepare("SELECT w.task_id, w.task_date AS duty_date, w.vehicle_id, v.plate_number, v.brand, v.model FROM tasks w LEFT JOIN vehicles v ON v.vehicle_id = w.vehicle_id WHERE w.task_source = 'welfare' AND w.driver_id = ? AND w.status = 'approved' AND w.task_date " . ($tab === 'past' ? '<' : '>=') . " ? ORDER BY w.task_date $dir LIMIT $limit");
    $stmt->bind_param('is', $driver_id, $today);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rep = taskReportOf($conn, 'welfare', (int)$r['task_id']);            // UC024: the welfare duty can be reported too (the vehicle is needed)
        $tasks[] = ['upd' => $r['vehicle_id'] && ($rep || strtotime($r['duty_date']) <= time()) ? 'welfare=' . (int)$r['task_id'] : '', 'reported' => (bool)$rep,
                    'kind' => 'welfare', 'start' => $r['duty_date'] . ' 00:00:00', 'end' => date('Y-m-d H:i:s', strtotime($r['duty_date'] . ' +1 day')), 'type' => 'Welfare duty',
                    'ref' => '', 'vehicle' => $r['plate_number'] ? vehicleHtml($r['plate_number'], $r['brand'], $r['model']) : '', 'destination' => '', 'waiting' => '', 'purpose' => 'On duty for 24 hours (12 am to 12 am), ready for emergencies.',
                    'officer' => '', 'phone' => '', 'sheet' => 0];
    }
    $stmt->close();
    usort($tasks, fn($a, $b) => $tab === 'past' ? strcmp($b['start'], $a['start']) : strcmp($a['start'], $b['start']));
    $tasks = array_slice($tasks, 0, $limit);
}

adminPageStart('My Tasks');
?>
    <h1 class="text-2xl font-medium mb-1">My Tasks</h1>
    <p class="text-base-content/60 text-sm mb-4">Your trips, internal tasks and welfare duty. A trip appears here once the Head of Admin has confirmed it.</p>
    <div role="tablist" class="tabs tabs-border mb-6 flex-nowrap overflow-x-auto">
        <a role="tab" class="tab gap-2 <?= $tab === 'upcoming' ? 'tab-active' : '' ?>" href="?tab=upcoming">Upcoming Tasks</a>
        <a role="tab" class="tab gap-2 <?= $tab === 'past' ? 'tab-active' : '' ?>" href="?tab=past">Past Tasks</a>
    </div>
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
                        <?php if ($tab !== 'past'): ?><div class="mt-1"><?= tripCountdownHtml($t['start']) ?></div><?php endif; ?>
                    </div>
                    <span class="badge <?= ['trip' => 'badge-soft badge-primary', 'internal' => 'badge-ghost', 'welfare' => 'badge-soft badge-secondary'][$t['kind']] ?? 'badge-ghost' ?>"><?= $h($t['type']) ?></span>
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
                <div class="mt-3 flex flex-wrap gap-2">
                <?php if ($t['kind'] === 'trip' && $t['sheet']): ?><a class="btn btn-sm btn-outline btn-warning" href="schedule_change.php?type=withdraw&schedule_id=<?= (int)$t['sheet'] ?>"><i class="fas fa-calendar-xmark"></i>Request Schedule Change</a><?php endif; ?>
                <?php if (!empty($t['upd'])): ?><a class="btn btn-sm btn-outline btn-primary" href="task_report.php?<?= $h($t['upd']) ?>"><i class="fas fa-clipboard-check"></i><?= !empty($t['reported']) ? 'View Report' : 'Create Report' ?></a><?php endif; ?>
                </div>
            </div></div>
        </div>
    <?php endforeach; ?>
    </div>
<?php adminPageEnd(); ?>
