<?php
// ============================================================
// admin/assign_internal_task.php  -  Assign Internal Task (SRS 10 Nov UC005)
//
// A Waiting for Driver task gets a driver. The ranked recommendation (fewer tasks, fewer weekend tasks, more experience)
// is shown; a driver who is not the top recommendation can still be chosen after a warning that shows the task count (A1).
// Waiting -> Driver Assigned; the Head of Admin approves in Approve Assignments (UC026).
// ============================================================
$current_page = 'internal_tasks.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$id = (int)($_GET['id'] ?? $_POST['task_id'] ?? 0);
$stmt = $conn->prepare("SELECT t.*, v.plate_number, v.model, v.license_required FROM internal_tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id WHERE t.task_id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$task || $task['status'] !== 'waiting') {
    setFlash('warning', 'That task is not waiting for a driver.');
    header('Location: internal_tasks.php');
    exit();
}

$rec = recommendDrivers($conn, ['start' => $task['start_dt'], 'end' => $task['end_dt'], 'licence' => $task['license_required'] ?: null, 'except_internal' => $id]);
$top = $rec['ranked'][0]['driver_id'] ?? 0;
$byId = [];
foreach ($rec['ranked'] as $d) { $byId[(int)$d['driver_id']] = $d; }

$confirmFor = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver = (int)($_POST['driver_id'] ?? 0);
    if ($driver <= 0) {
        setFlash('danger', 'Choose a driver.');
    } elseif (!isset($byId[$driver])) {
        setFlash('danger', 'That driver cannot be chosen for this task.');
    } elseif ($driver !== (int)$top && empty($_POST['confirmed'])) {
        $confirmFor = $byId[$driver];                       // A1: show the warning first
    } else {
        $res = assignInternalTask($conn, $id, $driver);
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        header('Location: internal_tasks.php');
        exit();
    }
    if (!$confirmFor) { header('Location: assign_internal_task.php?id=' . $id); exit(); }
}

adminPageStart('Assign Internal Task');
?>
    <a class="small text-decoration-none" href="internal_tasks.php"><i class="fas fa-arrow-left me-1"></i>Internal tasks</a>
    <h4 class="fw-bold my-2"><i class="fas fa-user-plus text-success me-2"></i>Assign internal task &mdash; #<?= $id ?></h4>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <strong><?= htmlspecialchars(internalTaskTypeLabel($task['task_type'])) ?></strong> &middot; <?= date('D, d M Y', strtotime($task['task_date'])) ?> <?= substr($task['start_time'], 0, 5) ?>&ndash;<?= substr($task['end_time'], 0, 5) ?>
        &middot; <?= htmlspecialchars((string)($task['plate_number'] ?: 'no vehicle')) ?><?= $task['location'] ? ' &middot; ' . htmlspecialchars($task['location']) : '' ?>
        <?php if (!empty($task['reject_reason'])): ?><div class="text-danger mt-1">Last assignment was rejected: <?= htmlspecialchars($task['reject_reason']) ?></div><?php endif; ?>
    </div></div>

    <?php if ($confirmFor): ?>
    <div class="alert alert-warning">
        <strong>This driver is not the top recommendation.</strong>
        <?= htmlspecialchars($confirmFor['name']) ?> has <?= (int)$confirmFor['tasks'] ?> task(s) this month (<?= (int)$confirmFor['weekend'] ?> at weekends); the recommended driver is <?= htmlspecialchars($rec['ranked'][0]['name']) ?> with <?= (int)$rec['ranked'][0]['tasks'] ?>. Assign anyway?
        <form method="post" class="mt-2">
            <input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="driver_id" value="<?= (int)$confirmFor['driver_id'] ?>"><input type="hidden" name="confirmed" value="1">
            <button class="btn btn-warning btn-sm">Assign anyway</button> <a class="btn btn-outline-secondary btn-sm" href="assign_internal_task.php?id=<?= $id ?>">Choose again</a>
        </form>
    </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="task_id" value="<?= $id ?>">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <p class="small text-muted mb-2">Recommendation: fewer tasks this month, fewer weekend tasks and more experience rank higher. You make the final choice.</p>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead class="table-light"><tr><th></th><th>#</th><th>Driver</th><th class="text-center">Tasks this month</th><th class="text-center">Weekend tasks</th><th class="text-center">Experience (years)</th><th class="text-center">Score</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($rec['ranked'] as $d): ?>
                    <tr>
                        <td><input class="form-check-input" type="radio" name="driver_id" value="<?= (int)$d['driver_id'] ?>" id="d<?= (int)$d['driver_id'] ?>" required></td>
                        <td><?= (int)$d['rank'] ?></td>
                        <td><label for="d<?= (int)$d['driver_id'] ?>" class="mb-0"><?= htmlspecialchars($d['name']) ?></label> <span class="text-muted small"><?= htmlspecialchars((string)$d['license_class']) ?></span></td>
                        <td class="text-center"><?= (int)$d['tasks'] ?></td><td class="text-center"><?= (int)$d['weekend'] ?></td>
                        <td class="text-center"><?= number_format((float)$d['experience'], 1) ?></td>
                        <td class="text-center"><span class="badge bg-success"><?= number_format((float)$d['score'], 1) ?></span></td>
                        <td class="small"><?= $d['welfare'] ? '<span class="badge bg-warning text-dark">On welfare duty that day</span>' : '' ?><?= $d['rank'] === 1 ? ' <span class="badge bg-primary">Recommended</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rec['ranked']): ?><tr><td colspan="8" class="text-danger">No driver is available for this task.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <?php if ($rec['excluded']): ?>
            <details class="small"><summary class="text-muted" style="cursor:pointer;">Drivers who cannot be chosen (<?= count($rec['excluded']) ?>)</summary>
                <ul class="mb-0"><?php foreach ($rec['excluded'] as $x): ?><li><?= htmlspecialchars($x['name']) ?> &mdash; <?= htmlspecialchars($x['reason']) ?></li><?php endforeach; ?></ul>
            </details>
            <?php endif; ?>
        </div></div>
        <button class="btn btn-success" <?= $rec['ranked'] ? '' : 'disabled' ?>><i class="fas fa-user-check me-1"></i>Assign driver</button>
    </form>
<?php adminPageEnd(); ?>
