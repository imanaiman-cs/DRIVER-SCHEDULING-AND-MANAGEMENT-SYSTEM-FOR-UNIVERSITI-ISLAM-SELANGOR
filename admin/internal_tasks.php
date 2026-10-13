<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/internal_tasks.php  –  jobs for drivers that are not passenger trips
// (refuelling, workshop, road tax, document delivery ...).
// They count in the driver's monthly workload, so the recommendation stays fair.
// A driver on leave is refused; a time clash with a trip is a WARNING (tick "Save anyway").
// ============================================================
$current_page = 'internal_tasks.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

const TASK_TYPES = [
    'workshop_send' => 'Send vehicle to workshop', 'workshop_collect' => 'Collect vehicle from workshop',
    'refuel' => 'Refuel', 'test_drive' => 'Test drive', 'road_tax' => 'Road tax / inspection',
    'document_delivery' => 'Document delivery', 'other' => 'Other',
];

$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$drivers  = regularActiveDrivers($conn);
$vehicles = $conn->query('SELECT vehicle_id, plate_number, brand, model FROM vehicles ORDER BY plate_number')->fetch_all(MYSQLI_ASSOC);
$old = ['task_type' => 'refuel', 'task_date' => date('Y-m-d'), 'start_time' => '09:00', 'end_time' => '10:00', 'driver_id' => 0, 'vehicle_id' => 0, 'location' => '', 'description' => ''];
$errors = []; $warnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'delete') {
        $id = (int)($_POST['task_id'] ?? 0);
        $r = $conn->query("SELECT task_type, task_date FROM internal_tasks WHERE task_id = $id")->fetch_assoc();
        if ($r) {
            $conn->query("DELETE FROM internal_tasks WHERE task_id = $id");
            auditLog($conn, 'internal_task_delete', null, "Task #$id {$r['task_type']} on {$r['task_date']}");
            setFlash('success', 'Internal task removed.');
        }
        header('Location: internal_tasks.php?month=' . urlencode($month));
        exit();
    }
    if ($act === 'add') {
        $old = [
            'task_type'   => trim($_POST['task_type'] ?? ''), 'task_date' => trim($_POST['task_date'] ?? ''),
            'start_time'  => trim($_POST['start_time'] ?? ''), 'end_time' => trim($_POST['end_time'] ?? ''),
            'driver_id'   => (int)($_POST['driver_id'] ?? 0), 'vehicle_id' => (int)($_POST['vehicle_id'] ?? 0),
            'location'    => mb_substr(trim($_POST['location'] ?? ''), 0, 150), 'description' => mb_substr(trim($_POST['description'] ?? ''), 0, 255),
        ];
        if (!isset(TASK_TYPES[$old['task_type']])) { $errors['task_type'] = 'Choose a task type.'; }
        if (!validDateStr($old['task_date'])) { $errors['task_date'] = 'Enter a valid date.'; }
        foreach (['start_time', 'end_time'] as $k) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $old[$k])) { $errors[$k] = 'Use HH:MM.'; }
        }
        if (!isset($errors['start_time'], $errors['end_time']) && $old['end_time'] <= $old['start_time']) { $errors['end_time'] = 'The end time must be after the start time.'; }
        if (!in_array($old['driver_id'], array_map('intval', array_column($drivers, 'driver_id')), true)) { $errors['driver_id'] = 'Choose an active regular driver.'; }
        if ($old['vehicle_id'] && !in_array($old['vehicle_id'], array_map('intval', array_column($vehicles, 'vehicle_id')), true)) { $errors['vehicle_id'] = 'Unknown vehicle.'; }

        if (!$errors) {
            if ($lv = driverLeaveOn($conn, $old['driver_id'], $old['task_date'])) {
                $errors['driver_id'] = 'This driver is on ' . ($lv['leave_type'] === 'mc' ? 'MC' : 'leave') . ' on that date.';
            } else {
                $q = $conn->prepare("SELECT schedule_id, destination, start_time, end_time FROM schedules
                                      WHERE driver_id = ? AND trip_date = ? AND status NOT IN ('cancelled') AND start_time < ? AND end_time > ?");
                $e = $old['end_time'] . ':00'; $s = $old['start_time'] . ':00';
                $q->bind_param('isss', $old['driver_id'], $old['task_date'], $e, $s);
                $q->execute();
                foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
                    $warnings[] = "The driver already has Schedule #{$t['schedule_id']} ({$t['destination']}, " . substr($t['start_time'], 0, 5) . '-' . substr($t['end_time'], 0, 5) . ').';
                }
                $q->close();
                $q = $conn->prepare('SELECT task_type, start_time, end_time FROM internal_tasks WHERE driver_id = ? AND task_date = ? AND start_time < ? AND end_time > ?');
                $q->bind_param('isss', $old['driver_id'], $old['task_date'], $e, $s);
                $q->execute();
                foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
                    $warnings[] = 'The driver already has another internal task (' . TASK_TYPES[$t['task_type']] . ', ' . substr($t['start_time'], 0, 5) . '-' . substr($t['end_time'], 0, 5) . ').';
                }
                $q->close();
                if ($old['vehicle_id'] && isset(blockedVehicleIds($conn, $old['task_date'])[$old['vehicle_id']])) {
                    $warnings[] = 'The vehicle is booked that day by a request or is in maintenance.';
                }
            }
        }
        if (!$errors && (!$warnings || !empty($_POST['confirm_clash']))) {
            $vid = $old['vehicle_id'] ?: null; $loc = $old['location'] ?: null; $desc = $old['description'] ?: null; $uid = (int)$_SESSION['user_id'];
            $s = $old['start_time'] . ':00'; $e = $old['end_time'] . ':00';
            $ins = $conn->prepare('INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, vehicle_id, driver_id, location, description, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $ins->bind_param('ssssiissi', $old['task_type'], $old['task_date'], $s, $e, $vid, $old['driver_id'], $loc, $desc, $uid);
            $ins->execute();
            $tid = $ins->insert_id;
            $ins->close();
            auditLog($conn, 'internal_task', null, "Task #$tid {$old['task_type']} on {$old['task_date']} for driver {$old['driver_id']}");
            if ($u = userIdOfDriver($conn, $old['driver_id'])) {
                notifyUsers($conn, [$u], 'New task: ' . TASK_TYPES[$old['task_type']] . ' on ' . date('d M Y', strtotime($old['task_date'])) . ' ' . $old['start_time'] . '-' . $old['end_time'] . ($loc ? ' at ' . $loc : '') . '.', 'driver/dashboard.php');
            }
            setFlash('success', 'Internal task saved.');
            header('Location: internal_tasks.php?month=' . urlencode(substr($old['task_date'], 0, 7)));
            exit();
        }
    }
}

$first = $month . '-01';
$stmt = $conn->prepare("SELECT t.*, d.name AS driver_name, v.plate_number FROM internal_tasks t
                          JOIN drivers d ON d.driver_id = t.driver_id LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                         WHERE DATE_FORMAT(t.task_date, '%Y-%m') = ? ORDER BY t.task_date, t.start_time");
$stmt->bind_param('s', $month);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . htmlspecialchars($errors[$k]) . '</div>' : '';
adminPageStart('Internal Tasks');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-screwdriver-wrench text-success me-2"></i>Internal Tasks</h4>
    <p class="text-muted small">Jobs for drivers that are not passenger trips. They count in the driver's monthly workload.</p>
    <?php if ($warnings): ?>
    <div class="alert alert-warning"><strong>Please check:</strong>
        <ul class="mb-2"><?php foreach ($warnings as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_clash" value="1" id="confirm_clash" form="task-form">
            <label class="form-check-label" for="confirm_clash">Save anyway</label></div>
    </div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">New task</h6>
                <form method="post" id="task-form" class="row g-2">
                    <input type="hidden" name="action" value="add">
                    <div class="col-12"><label class="form-label small" for="task_type">Task</label>
                        <select class="form-select form-select-sm" id="task_type" name="task_type">
                            <?php foreach (TASK_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $old['task_type'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
                        </select><?= $err('task_type') ?></div>
                    <div class="col-12"><label class="form-label small" for="driver_id">Driver</label>
                        <select class="form-select form-select-sm" id="driver_id" name="driver_id" required><option value="">Choose...</option>
                            <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['driver_id'] ?>" <?= (int)$old['driver_id'] === (int)$d['driver_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
                        </select><?= $err('driver_id') ?></div>
                    <div class="col-12"><label class="form-label small" for="task_date">Date</label><input type="date" class="form-control form-control-sm" id="task_date" name="task_date" value="<?= htmlspecialchars($old['task_date']) ?>" required><?= $err('task_date') ?></div>
                    <div class="col-6"><label class="form-label small" for="start_time">From</label><input type="time" class="form-control form-control-sm" id="start_time" name="start_time" value="<?= htmlspecialchars($old['start_time']) ?>" required><?= $err('start_time') ?></div>
                    <div class="col-6"><label class="form-label small" for="end_time">To</label><input type="time" class="form-control form-control-sm" id="end_time" name="end_time" value="<?= htmlspecialchars($old['end_time']) ?>" required><?= $err('end_time') ?></div>
                    <div class="col-12"><label class="form-label small" for="vehicle_id">Vehicle (optional)</label>
                        <select class="form-select form-select-sm" id="vehicle_id" name="vehicle_id"><option value="">None</option>
                            <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= (int)$old['vehicle_id'] === (int)$v['vehicle_id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['plate_number'] . ' - ' . trim($v['brand'] . ' ' . $v['model'])) ?></option><?php endforeach; ?>
                        </select><?= $err('vehicle_id') ?></div>
                    <div class="col-12"><label class="form-label small" for="location">Location (optional)</label><input class="form-control form-control-sm" id="location" name="location" maxlength="150" value="<?= htmlspecialchars($old['location']) ?>"></div>
                    <div class="col-12"><label class="form-label small" for="description">Note (optional)</label><input class="form-control form-control-sm" id="description" name="description" maxlength="255" value="<?= htmlspecialchars($old['description']) ?>"></div>
                    <div class="col-12"><button class="btn btn-success btn-sm" type="submit">Save task</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-lg-8">
            <div class="d-flex align-items-center gap-2 mb-2">
                <a class="btn btn-sm btn-outline-success" href="?month=<?= date('Y-m', strtotime("$first -1 month")) ?>">&laquo;</a>
                <strong><?= date('F Y', strtotime($first)) ?></strong>
                <a class="btn btn-sm btn-outline-success" href="?month=<?= date('Y-m', strtotime("$first +1 month")) ?>">&raquo;</a>
            </div>
            <div class="card border-0 shadow-sm"><div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Time</th><th>Task</th><th>Driver</th><th>Vehicle</th><th>Location / note</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $t): ?>
                        <tr>
                            <td class="text-nowrap"><?= date('d M', strtotime($t['task_date'])) ?></td>
                            <td class="text-nowrap"><?= substr($t['start_time'], 0, 5) ?>-<?= substr($t['end_time'], 0, 5) ?></td>
                            <td><?= htmlspecialchars(TASK_TYPES[$t['task_type']] ?? $t['task_type']) ?></td>
                            <td><?= htmlspecialchars($t['driver_name']) ?></td><td><?= htmlspecialchars($t['plate_number'] ?? '-') ?></td>
                            <td class="small"><?= htmlspecialchars(trim(($t['location'] ?? '') . ' ' . ($t['description'] ?? ''))) ?></td>
                            <td class="text-end"><form method="post" class="d-inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" value="<?= (int)$t['task_id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" data-confirm="Remove this task?" data-confirm-title="Remove?" data-confirm-button="Remove" data-confirm-tone="danger">Remove</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No internal tasks this month.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
<?php adminPageEnd(); ?>
