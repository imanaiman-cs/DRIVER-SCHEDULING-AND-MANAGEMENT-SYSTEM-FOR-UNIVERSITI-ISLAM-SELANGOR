<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/replace_driver.php  -  UC021 Replace Driver
//
// Only for a task of a CONFIRMED request that has not started. The new driver must have no clash (the
// recommendation lists only drivers who are free) and a reason is required. The replacement is recorded
// (old driver, new driver, reason, date), the request goes back to "Driver Assigned" so the Head of Admin approves
// again, and the old driver, the new driver and the Head of Admin are notified.
// ============================================================
$current_page = 'replace_driver.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$sid = (int)($_GET['schedule_id'] ?? $_POST['schedule_id'] ?? 0);
$stmt = $conn->prepare(
    "SELECT s.*, d.name AS driver_name, v.plate_number, v.model, v.license_required, vr.status AS req_status, vr.destination AS req_destination
       FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id
       LEFT JOIN drivers d ON d.driver_id = s.driver_id LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
      WHERE s.schedule_id = ? AND s.status <> 'cancelled'"
);
$stmt->bind_param('i', $sid);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$task) {
    setFlash('danger', 'Task not found.');
    header('Location: vehicle_requests.php');
    exit();
}
$canReplace = $task['req_status'] === 'processed' && strtotime($task['start_dt']) > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canReplace) {
        setFlash('danger', 'Only a Confirmed task that has not started can have its driver replaced.');
    } else {
        $res = replaceTaskDriver($conn, $sid, (int)($_POST['new_driver'] ?? 0), (string)($_POST['reason'] ?? ''));
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        if ($res['ok']) { header('Location: vehicle_requests.php?tab=assigned&open=' . (int)$task['request_id']); exit(); }
    }
    header('Location: replace_driver.php?schedule_id=' . $sid);
    exit();
}

$rec = $canReplace ? recommendDrivers($conn, ['start' => $task['start_dt'], 'end' => $task['end_dt'], 'licence' => $task['license_required'], 'except_schedule' => $sid]) : ['ranked' => [], 'excluded' => [], 'weights' => ['tasks' => 0, 'outside' => 0]];
$hist = $conn->prepare("SELECT r.reason, r.changed_at, o.name AS old_name, n.name AS new_name FROM driver_replacements r
                         LEFT JOIN drivers o ON o.driver_id = r.previous_driver_id LEFT JOIN drivers n ON n.driver_id = r.new_driver_id
                        WHERE r.schedule_id = ? ORDER BY r.replacement_id DESC");
$hist->bind_param('i', $sid);
$hist->execute();
$history = $hist->get_result()->fetch_all(MYSQLI_ASSOC);
$hist->close();

adminPageStart('Replace Driver');
?>
    <a class="small text-decoration-none" href="vehicle_requests.php?tab=processed"><i class="fas fa-arrow-left me-1"></i>Vehicle requests</a>
    <h4 class="fw-bold my-2"><i class="fas fa-right-left text-success me-2"></i>Replace driver &mdash; request #<?= (int)$task['request_id'] ?>, <?= (int)$task['leg_no'] === 2 ? 'return' : 'outbound' ?> trip</h4>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <div><strong><?= htmlspecialchars($task['req_destination']) ?></strong> &middot; <?= date('D, d M Y H:i', strtotime($task['start_dt'])) ?>&ndash;<?= date('H:i', strtotime($task['end_dt'])) ?></div>
        <div>Vehicle: <?= htmlspecialchars((string)$task['plate_number']) ?> <?= htmlspecialchars((string)$task['model']) ?> (licence <?= htmlspecialchars((string)$task['license_required']) ?>)</div>
        <div>Current driver: <strong><?= htmlspecialchars((string)$task['driver_name']) ?></strong> &middot; Request status: <?= requestStatusBadge($task['req_status']) ?></div>
    </div></div>

    <?php if (!$canReplace): ?>
        <div class="alert alert-warning">The driver can be replaced only while the request is Confirmed and the task has not started.</div>
    <?php else: ?>
    <form method="post" class="card border-0 shadow-sm"><div class="card-body">
        <input type="hidden" name="schedule_id" value="<?= $sid ?>">
        <h6 class="fw-bold">Choose the new driver <span class="fw-normal text-muted small">(only drivers without a clash can be chosen)</span></h6>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead class="table-light"><tr><th></th><th>#</th><th>Driver</th><th class="text-center">Tasks this month</th><th class="text-center">Outside working hours</th><th class="text-center">Score</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rec['ranked'] as $d): if ((int)$d['driver_id'] === (int)$task['driver_id']) { continue; } ?>
                <tr>
                    <td><input class="form-check-input" type="radio" name="new_driver" value="<?= (int)$d['driver_id'] ?>" id="nd<?= (int)$d['driver_id'] ?>" required></td>
                    <td><?= (int)$d['rank'] ?></td>
                    <td><label for="nd<?= (int)$d['driver_id'] ?>" class="mb-0"><?= htmlspecialchars($d['name']) ?></label></td>
                    <td class="text-center"><?= (int)$d['tasks'] ?></td><td class="text-center"><?= (int)$d['outside'] ?></td>
                    <td class="text-center"><span class="badge bg-success"><?= number_format((float)$d['score'], 1) ?></span></td>
                    <td class="small"><?= $d['welfare'] ? '<span class="badge bg-warning text-dark">Welfare duty</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <label class="form-label small fw-semibold" for="reason">Reason for replacing the driver <span class="text-danger">*</span></label>
        <input class="form-control mb-3" id="reason" name="reason" maxlength="500" required>
        <button class="btn btn-primary" data-confirm="The request goes back to Driver Assigned and the Head of Admin must approve again." data-confirm-title="Replace the driver?" data-confirm-button="Replace" data-confirm-tone="primary"><i class="fas fa-right-left me-1"></i>Replace driver</button>
    </div></form>
    <?php endif; ?>

    <?php if ($history): ?>
    <div class="card border-0 shadow-sm mt-3"><div class="card-body small">
        <h6 class="fw-bold">Replacement history of this task</h6>
        <?php foreach ($history as $h): ?><div><?= date('d M Y H:i', strtotime($h['changed_at'])) ?> &middot; <?= htmlspecialchars((string)$h['old_name']) ?> &rarr; <?= htmlspecialchars((string)$h['new_name']) ?> &middot; <?= htmlspecialchars((string)$h['reason']) ?></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
<?php adminPageEnd(); ?>
