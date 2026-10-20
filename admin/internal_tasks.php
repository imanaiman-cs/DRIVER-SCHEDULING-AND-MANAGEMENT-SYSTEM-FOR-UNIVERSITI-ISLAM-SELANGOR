<?php
// ============================================================
// admin/internal_tasks.php  -  Internal tasks (SRS UC022 Add, UC023 View, UC024 Update, UC025 Cancel)
//
// Workshop runs, refuelling, test drives and document delivery. A task is never deleted: it is
// CANCELLED with a reason. Drivers and vehicles are checked with the overlap rule
// (start < other_end AND end > other_start). The driver is notified of every add / change / cancel.
// ============================================================
$current_page = 'internal_tasks.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$drivers  = activeDrivers($conn);
$vehicles = $conn->query("SELECT vehicle_id, plate_number, brand, model, license_required FROM vehicles WHERE status <> 'inactive' ORDER BY plate_number")->fetch_all(MYSQLI_ASSOC);

/** One internal task (with driver / vehicle names), or null. */
function loadInternalTask(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT t.*, d.name AS driver_name, v.plate_number, v.brand, v.model
                              FROM internal_tasks t JOIN drivers d ON d.driver_id = t.driver_id
                              LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id WHERE t.task_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Why the vehicle cannot be used for the task, or null. The task being updated does not clash with itself. */
function internalVehicleReason(mysqli $conn, int $vehicle_id, string $start, string $end, int $except_task): ?string
{
    $why = vehicleUnavailableReason($conn, $vehicle_id, $start, $end);
    if ($why !== 'internal') { return $why; }
    // 'internal' is the last check: recheck it without the task itself
    $stmt = $conn->prepare("SELECT 1 FROM internal_tasks WHERE vehicle_id = ? AND task_id <> ? AND status = 'active' AND start_dt < ? AND end_dt > ? LIMIT 1");
    $stmt->bind_param('iiss', $vehicle_id, $except_task, $end, $start);
    $stmt->execute();
    $hit = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $hit ? 'internal' : null;
}

function taskText(array $t): string
{
    return internalTaskTypeLabel($t['task_type']) . ' on ' . date('d M Y', strtotime($t['task_date'])) . ' ' . substr($t['start_time'], 0, 5) . '-' . substr($t['end_time'], 0, 5)
         . ($t['plate_number'] ? ' (' . $t['plate_number'] . ')' : '');
}

$editId = (int)($_GET['edit'] ?? 0);
$form = ['task_type' => 'refuel', 'task_date' => date('Y-m-d'), 'start_time' => '09:00', 'end_time' => '10:00', 'driver_id' => 0, 'vehicle_id' => 0, 'location' => '', 'description' => ''];
$errors = [];

if ($editId) {
    $et = loadInternalTask($conn, $editId);
    if (!$et || $et['status'] !== 'active' || strtotime($et['end_dt']) < time()) {
        setFlash('warning', 'Only an active task that has not ended can be updated.');
        header('Location: internal_tasks.php');
        exit();
    }
    $form = array_intersect_key($et, $form);
    $form['start_time'] = substr($form['start_time'], 0, 5);
    $form['end_time']   = substr($form['end_time'], 0, 5);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['task_id'] ?? 0);
    $by  = (int)$_SESSION['user_id'];

    if ($act === 'cancel') {
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
        $t = loadInternalTask($conn, $id);
        if (!$t || $t['status'] !== 'active') {
            setFlash('danger', 'That task is not active.');
        } elseif (mb_strlen($reason) < 3) {
            setFlash('danger', 'Please give the reason for cancelling the task.');
        } else {
            $stmt = $conn->prepare("UPDATE internal_tasks SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE task_id = ? AND status = 'active'");
            $stmt->bind_param('si', $reason, $id);
            $stmt->execute();
            $stmt->close();
            auditLog($conn, 'internal_task_cancel', null, "Internal task #$id cancelled: $reason");
            if ($u = userIdOfDriver($conn, (int)$t['driver_id'])) {
                notifyUsers($conn, [$u], 'Your internal task was cancelled: ' . taskText($t) . '. Reason: ' . $reason, 'driver/schedules.php');
            }
            setFlash('success', 'The task was cancelled and the driver was notified.');
        }
        header('Location: internal_tasks.php');
        exit();
    }

    if ($act === 'save') {
        $form = [
            'task_type'   => trim($_POST['task_type'] ?? ''), 'task_date' => trim($_POST['task_date'] ?? ''),
            'start_time'  => trim($_POST['start_time'] ?? ''), 'end_time' => trim($_POST['end_time'] ?? ''),
            'driver_id'   => (int)($_POST['driver_id'] ?? 0), 'vehicle_id' => (int)($_POST['vehicle_id'] ?? 0),
            'location'    => mb_substr(trim($_POST['location'] ?? ''), 0, 150), 'description' => mb_substr(trim($_POST['description'] ?? ''), 0, 255),
        ];
        $editId = $id;
        $old = $id ? loadInternalTask($conn, $id) : null;
        if ($id && (!$old || $old['status'] !== 'active')) { $errors['general'] = 'That task is not active and cannot be updated.'; }
        $typeOk = isset(INTERNAL_TASK_TYPES[$form['task_type']]) || ($old && $old['task_type'] === $form['task_type']);
        if (!$typeOk) { $errors['task_type'] = 'Choose a task type.'; }
        if (!isValidDate($form['task_date'])) { $errors['task_date'] = 'Enter a valid date.'; }
        elseif ($form['task_date'] < date('Y-m-d')) { $errors['task_date'] = 'The date cannot be in the past.'; }
        foreach (['start_time', 'end_time'] as $k) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $form[$k])) { $errors[$k] = 'Use HH:MM.'; }
        }
        if (!isset($errors['start_time']) && !isset($errors['end_time']) && $form['end_time'] <= $form['start_time']) { $errors['end_time'] = 'The end time must be after the start time.'; }
        if ($form['vehicle_id'] <= 0) { $errors['vehicle_id'] = 'Choose a vehicle.'; }
        if ($form['driver_id'] <= 0) { $errors['driver_id'] = 'Choose a driver.'; }

        if (!$errors) {
            $start = $form['task_date'] . ' ' . $form['start_time'] . ':00';
            $end   = $form['task_date'] . ' ' . $form['end_time'] . ':00';
            if (strtotime($end) < time()) { $errors['task_date'] = 'The task has already ended.'; }
        }
        if (!$errors) {
            $conn->begin_transaction();
            try {
                // lock the vehicle and the driver so two people cannot book them at the same moment
                $q = $conn->prepare('SELECT status, license_required, plate_number FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
                $q->bind_param('i', $form['vehicle_id']);
                $q->execute();
                $veh = $q->get_result()->fetch_assoc();
                $q->close();
                $q = $conn->prepare('SELECT name, status, license_class FROM drivers WHERE driver_id = ? FOR UPDATE');
                $q->bind_param('i', $form['driver_id']);
                $q->execute();
                $drv = $q->get_result()->fetch_assoc();
                $q->close();

                if (!$veh) {
                    $errors['vehicle_id'] = 'Unknown vehicle.';
                } elseif (($why = internalVehicleReason($conn, $form['vehicle_id'], $start, $end, $id)) !== null) {
                    $errors['vehicle_id'] = 'The vehicle ' . vehicleUnavailableText($why) . ' at that time.';
                }
                if (!$drv || $drv['status'] !== 'active') {
                    $errors['driver_id'] = 'Choose an active driver.';
                } elseif ($veh && !driverHoldsLicence($drv['license_class'], $veh['license_required'])) {
                    $errors['driver_id'] = $drv['name'] . ' does not hold licence class ' . $veh['license_required'] . ', which ' . $veh['plate_number'] . ' needs.';
                } elseif (($why = driverClashReason($conn, $form['driver_id'], $start, $end, 0, $id)) !== null) {
                    $errors['driver_id'] = $drv['name'] . ': ' . $why . '.';
                }

                if ($errors) { $conn->rollback(); }
                else {
                    $loc = $form['location'] !== '' ? $form['location'] : null;
                    $desc = $form['description'] !== '' ? $form['description'] : null;
                    if ($id) {
                        $stmt = $conn->prepare("UPDATE internal_tasks SET task_type = ?, task_date = ?, start_time = ?, end_time = ?, vehicle_id = ?, driver_id = ?, location = ?, description = ?
                                                 WHERE task_id = ? AND status = 'active'");
                        $stmt->bind_param('ssssiissi', $form['task_type'], $form['task_date'], $form['start_time'], $form['end_time'], $form['vehicle_id'], $form['driver_id'], $loc, $desc, $id);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO internal_tasks (task_type, task_date, start_time, end_time, vehicle_id, driver_id, location, description, created_by)
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param('ssssiissi', $form['task_type'], $form['task_date'], $form['start_time'], $form['end_time'], $form['vehicle_id'], $form['driver_id'], $loc, $desc, $by);
                    }
                    $stmt->execute();
                    $newId = $id ?: (int)$stmt->insert_id;
                    $stmt->close();
                    $conn->commit();

                    auditLog($conn, $id ? 'internal_task_update' : 'internal_task_add', null, "Internal task #$newId: {$form['task_type']} {$form['task_date']} driver {$form['driver_id']}");
                    $new = loadInternalTask($conn, $newId);
                    $newUser = userIdOfDriver($conn, $form['driver_id']);
                    if (!$id) {
                        if ($newUser) { notifyUsers($conn, [$newUser], 'New internal task: ' . taskText($new) . '.', 'driver/schedules.php'); }
                    } else {
                        if ((int)$old['driver_id'] !== $form['driver_id']) {
                            if ($u = userIdOfDriver($conn, (int)$old['driver_id'])) { notifyUsers($conn, [$u], 'You are no longer assigned to the internal task: ' . taskText($old) . '.', 'driver/schedules.php'); }
                            if ($newUser) { notifyUsers($conn, [$newUser], 'New internal task: ' . taskText($new) . '.', 'driver/schedules.php'); }
                        } elseif ($newUser) {
                            notifyUsers($conn, [$newUser], 'Your internal task was changed. Now: ' . taskText($new) . '.', 'driver/schedules.php');
                        }
                    }
                    setFlash('success', $id ? 'The task was updated and the driver was notified.' : 'The task was added and the driver was notified.');
                    header('Location: internal_tasks.php');
                    exit();
                }
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('internal task: ' . $e->getMessage());
                $errors['general'] = 'Database error. Please try again.';
            }
        }
    }
}

// ---------- list ----------
$when   = ($_GET['when'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
$fStat  = in_array($_GET['status'] ?? '', ['active', 'cancelled'], true) ? $_GET['status'] : '';
$fDrv   = (int)($_GET['driver_id'] ?? 0);
$fFrom  = isValidDate((string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
$fTo    = isValidDate((string)($_GET['to'] ?? '')) ? $_GET['to'] : '';
$where = [$when === 'past' ? 't.end_dt < ?' : 't.end_dt >= ?']; $p = [date('Y-m-d H:i:s')];
if ($fStat) { $where[] = 't.status = ?'; $p[] = $fStat; }
if ($fDrv)  { $where[] = 't.driver_id = ?'; $p[] = $fDrv; }
if ($fFrom) { $where[] = 't.task_date >= ?'; $p[] = $fFrom; }
if ($fTo)   { $where[] = 't.task_date <= ?'; $p[] = $fTo; }
$W = implode(' AND ', $where);
$stmt = $conn->prepare("SELECT COUNT(*) FROM internal_tasks t WHERE $W");
bindDynamic($stmt, $p); $stmt->execute();
$total = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
$pg = paginate($total, max(1, (int)($_GET['page'] ?? 1)), 15);
$order = $when === 'past' ? 'DESC' : 'ASC';
$stmt = $conn->prepare("SELECT t.*, d.name AS driver_name, v.plate_number, v.brand, v.model
                          FROM internal_tasks t JOIN drivers d ON d.driver_id = t.driver_id LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                         WHERE $W ORDER BY t.start_dt $order, t.task_id $order LIMIT ? OFFSET ?");
$p2 = array_merge($p, [$pg['rows_per_page'], $pg['offset']]);
bindDynamic($stmt, $p2); $stmt->execute();
$tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$qs = fn(array $o = []) => '?' . http_build_query(array_filter(array_merge(['when' => $when, 'status' => $fStat, 'driver_id' => $fDrv ?: '', 'from' => $fFrom, 'to' => $fTo], $o), fn($v) => $v !== '' && $v !== null));

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
adminPageStart('Internal Tasks');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-wrench text-success me-2"></i>Internal Tasks</h4>
    <p class="text-muted small mb-3">Workshop runs, refuelling, test drives and document delivery. They count in the driver's workload. A task is never deleted: cancel it with a reason.</p>

    <div class="card border-0 shadow-sm mb-4" id="taskForm">
        <div class="card-body">
            <h6 class="fw-bold mb-3"><?= $editId ? 'Update task #' . (int)$editId : 'Add a task' ?></h6>
            <?php if (!empty($errors['general'])): ?><div class="alert alert-danger py-2"><?= $h($errors['general']) ?></div><?php endif; ?>
            <form method="post" class="row g-3" novalidate>
                <input type="hidden" name="action" value="save"><input type="hidden" name="task_id" value="<?= (int)$editId ?>">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold" for="task_type">Task type</label>
                    <select class="form-select <?= isset($errors['task_type']) ? 'is-invalid' : '' ?>" id="task_type" name="task_type" required>
                        <?php foreach (INTERNAL_TASK_TYPES as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $form['task_type'] === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
                        <?php if (!isset(INTERNAL_TASK_TYPES[$form['task_type']])): ?><option value="<?= $h($form['task_type']) ?>" selected><?= $h(internalTaskTypeLabel($form['task_type'])) ?></option><?php endif; ?>
                    </select>
                    <div class="invalid-feedback"><?= $h($errors['task_type'] ?? '') ?></div>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold" for="task_date">Date</label>
                    <input type="date" class="form-control <?= isset($errors['task_date']) ? 'is-invalid' : '' ?>" id="task_date" name="task_date" min="<?= date('Y-m-d') ?>" value="<?= $h($form['task_date']) ?>" required>
                    <div class="invalid-feedback"><?= $h($errors['task_date'] ?? '') ?></div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold" for="start_time">Start</label>
                    <input type="time" class="form-control <?= isset($errors['start_time']) ? 'is-invalid' : '' ?>" id="start_time" name="start_time" value="<?= $h($form['start_time']) ?>" required>
                    <div class="invalid-feedback"><?= $h($errors['start_time'] ?? '') ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold" for="end_time">End</label>
                    <input type="time" class="form-control <?= isset($errors['end_time']) ? 'is-invalid' : '' ?>" id="end_time" name="end_time" value="<?= $h($form['end_time']) ?>" required>
                    <div class="invalid-feedback"><?= $h($errors['end_time'] ?? '') ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" for="vehicle_id">Vehicle</label>
                    <select class="form-select <?= isset($errors['vehicle_id']) ? 'is-invalid' : '' ?>" id="vehicle_id" name="vehicle_id" required>
                        <option value="">Choose a vehicle</option>
                        <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= (int)$form['vehicle_id'] === (int)$v['vehicle_id'] ? 'selected' : '' ?>><?= $h(vehicleText($v['plate_number'], $v['brand'], $v['model'])) ?> - licence <?= $h($v['license_required']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback"><?= $h($errors['vehicle_id'] ?? '') ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" for="driver_id">Driver</label>
                    <select class="form-select <?= isset($errors['driver_id']) ? 'is-invalid' : '' ?>" id="driver_id" name="driver_id" required>
                        <option value="">Choose a driver</option>
                        <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['driver_id'] ?>" <?= (int)$form['driver_id'] === (int)$d['driver_id'] ? 'selected' : '' ?>><?= $h($d['name']) ?> (<?= $h($d['license_class']) ?>)</option><?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback"><?= $h($errors['driver_id'] ?? '') ?></div>
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label fw-semibold" for="location">Place (optional)</label>
                    <input class="form-control" id="location" name="location" maxlength="150" value="<?= $h($form['location']) ?>" placeholder="e.g. Workshop name, fuel station">
                </div>
                <div class="col-12 col-md-7">
                    <label class="form-label fw-semibold" for="description">Description</label>
                    <input class="form-control" id="description" name="description" maxlength="255" value="<?= $h($form['description']) ?>">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-uis-primary" type="submit"><i class="fas fa-floppy-disk me-1"></i><?= $editId ? 'Save changes' : 'Add task' ?></button>
                    <?php if ($editId): ?><a class="btn btn-outline-secondary" href="internal_tasks.php">Cancel editing</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?= $when === 'upcoming' ? 'active' : '' ?>" href="<?= $qs(['when' => 'upcoming', 'page' => null]) ?>">Upcoming</a></li>
        <li class="nav-item"><a class="nav-link <?= $when === 'past' ? 'active' : '' ?>" href="<?= $qs(['when' => 'past', 'page' => null]) ?>">Past</a></li>
    </ul>
    <form method="get" class="row g-2 mb-3 align-items-end">
        <input type="hidden" name="when" value="<?= $h($when) ?>">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="f_status">Status</label>
            <select class="form-select form-select-sm" id="f_status" name="status"><option value="">All</option><option value="active" <?= $fStat === 'active' ? 'selected' : '' ?>>Active</option><option value="cancelled" <?= $fStat === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-0" for="f_driver">Driver</label>
            <select class="form-select form-select-sm" id="f_driver" name="driver_id"><option value="">All drivers</option>
                <?php foreach ($conn->query('SELECT driver_id, name FROM drivers ORDER BY name')->fetch_all(MYSQLI_ASSOC) as $d): ?><option value="<?= (int)$d['driver_id'] ?>" <?= $fDrv === (int)$d['driver_id'] ? 'selected' : '' ?>><?= $h($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="f_from">From</label><input type="date" class="form-control form-control-sm" id="f_from" name="from" value="<?= $h($fFrom) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="f_to">To</label><input type="date" class="form-control form-control-sm" id="f_to" name="to" value="<?= $h($fTo) ?>"></div>
        <div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-sm btn-outline-success">Filter</button><a class="btn btn-sm btn-outline-secondary" href="internal_tasks.php?when=<?= $h($when) ?>">Reset</a></div>
    </form>

    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Date and time</th><th>Task</th><th>Vehicle</th><th>Driver</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $t): $canChange = $t['status'] === 'active' && strtotime($t['end_dt']) >= time(); ?>
                <tr>
                    <td class="text-nowrap"><?= $h(date('D, d M Y', strtotime($t['task_date']))) ?><div class="small text-muted"><?= $h(substr($t['start_time'], 0, 5) . ' - ' . substr($t['end_time'], 0, 5)) ?></div></td>
                    <td><strong><?= $h(internalTaskTypeLabel($t['task_type'])) ?></strong>
                        <?php if ($t['location'] || $t['description']): ?><div class="small text-muted"><?= $h(trim($t['location'] . ' ' . $t['description'])) ?></div><?php endif; ?></td>
                    <td class="text-nowrap"><?= $h(vehicleText($t['plate_number'], $t['brand'], $t['model'])) ?></td>
                    <td><?= $h($t['driver_name']) ?></td>
                    <td><?php if ($t['status'] === 'cancelled'): ?><span class="badge bg-danger">Cancelled</span>
                        <div class="small text-muted mt-1"><?= $h($t['cancel_reason']) ?></div>
                        <?php else: ?><span class="badge bg-success">Active</span><?php endif; ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($canChange): ?>
                            <a class="btn btn-sm btn-outline-success" href="internal_tasks.php?edit=<?= (int)$t['task_id'] ?>#taskForm"><i class="fas fa-pen"></i><span class="d-none d-md-inline ms-1">Update</span></a>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal" data-id="<?= (int)$t['task_id'] ?>" data-label="<?= $h(taskText($t)) ?>"><i class="fas fa-ban"></i><span class="d-none d-md-inline ms-1">Cancel</span></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tasks): ?><tr><td colspan="6" class="text-center text-muted py-4">No <?= $when ?> tasks found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
    <?php if ($pg['total_pages'] > 1): ?>
    <nav class="mt-3"><ul class="pagination pagination-sm flex-wrap">
        <?php for ($i = 1; $i <= $pg['total_pages']; $i++): ?><li class="page-item <?= $i === $pg['current_page'] ? 'active' : '' ?>"><a class="page-link" href="<?= $qs(['page' => $i]) ?>"><?= $i ?></a></li><?php endfor; ?>
    </ul></nav>
    <?php endif; ?>

    <div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
            <input type="hidden" name="action" value="cancel"><input type="hidden" name="task_id" id="cancelId" value="">
            <div class="modal-header"><h5 class="modal-title" id="cancelTitle">Cancel this task?</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p class="mb-2" id="cancelLabel"></p>
                <label class="form-label fw-semibold" for="cancelReason">Reason for cancelling</label>
                <textarea class="form-control" id="cancelReason" name="reason" rows="2" maxlength="255" required minlength="3"></textarea>
                <div class="form-text">The driver is told the reason.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep the task</button><button class="btn btn-danger" type="submit">Cancel the task</button></div>
        </form></div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var m = document.getElementById('cancelModal');
        m.addEventListener('show.bs.modal', function (e) {
            document.getElementById('cancelId').value = e.relatedTarget.dataset.id;
            document.getElementById('cancelLabel').textContent = e.relatedTarget.dataset.label;
            document.getElementById('cancelReason').value = '';
        });
    });
    </script>
<?php adminPageEnd(); ?>
