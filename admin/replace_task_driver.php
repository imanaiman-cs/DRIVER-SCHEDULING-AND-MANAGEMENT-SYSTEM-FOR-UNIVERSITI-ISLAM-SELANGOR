<?php
// ============================================================
// admin/replace_task_driver.php  -  Replace Driver of an internal task or a welfare duty day (SRS 14 Nov UC008)
// ?internal=<task_id> or ?welfare=<roster_id>. The task must be Approved and not started. The recommended drivers are shown (UC009), a reason is
// required, the replacement is recorded, the task returns to Driver Assigned and the Head of Admin approves (UC026).
// ============================================================
$current_page = 'replace_driver.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$kind = isset($_REQUEST['welfare']) ? 'welfare' : 'internal';
$id = (int)($_REQUEST[$kind] ?? 0);
if ($kind === 'internal') {
    $q = $conn->prepare("SELECT t.*, d.name AS driver_name, v.plate_number, v.model, v.license_required FROM internal_tasks t LEFT JOIN drivers d ON d.driver_id = t.driver_id LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id WHERE t.task_id = ?");
} else {
    $q = $conn->prepare("SELECT w.*, TIMESTAMP(w.duty_date) AS start_dt, DATE_ADD(TIMESTAMP(w.duty_date), INTERVAL 1 DAY) AS end_dt, d.name AS driver_name, v.plate_number, v.model, v.license_required FROM welfare_roster w LEFT JOIN drivers d ON d.driver_id = w.driver_id LEFT JOIN vehicles v ON v.vehicle_id = w.vehicle_id WHERE w.roster_id = ?");
}
$q->bind_param('i', $id);
$q->execute();
$t = $q->get_result()->fetch_assoc();
$q->close();
if (!$t) { setFlash('danger', 'Task not found.'); header('Location: ' . ($kind === 'internal' ? 'internal_tasks.php' : 'welfare.php')); exit(); }
$back = $kind === 'internal' ? 'internal_tasks.php' : 'welfare.php?week=' . urlencode(welfareWeekStart($t['duty_date']));
$can = $t['status'] === 'active' && $t['driver_id'] && strtotime($t['start_dt']) > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can) { setFlash('danger', 'Only an Approved task that has not started can have its driver replaced.'); }
    else {
        $res = replaceNonRequestDriver($conn, $kind, $id, (int)($_POST['new_driver'] ?? 0), (string)($_POST['reason'] ?? ''));
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        if ($res['ok']) { header('Location: ' . $back); exit(); }
    }
    header('Location: replace_task_driver.php?' . $kind . '=' . $id);
    exit();
}
$rec = $can ? recommendDrivers($conn, ['start' => $t['start_dt'], 'end' => $t['end_dt'], 'licence' => $t['license_required'] ?: null]) : ['ranked' => []];
$col = $kind === 'internal' ? 'internal_task_id' : 'welfare_id';
$hist = $conn->prepare("SELECT r.reason, r.changed_at, o.name AS old_name, n.name AS new_name FROM driver_replacements r LEFT JOIN drivers o ON o.driver_id = r.previous_driver_id LEFT JOIN drivers n ON n.driver_id = r.new_driver_id WHERE r.$col = ? ORDER BY r.replacement_id DESC");
$hist->bind_param('i', $id);
$hist->execute();
$history = $hist->get_result()->fetch_all(MYSQLI_ASSOC);
$hist->close();

adminPageStart('Replace Driver');
?>
    <a class="small text-decoration-none" href="<?= $h($back) ?>"><i class="fas fa-arrow-left me-1"></i>Back</a>
    <h4 class="fw-bold my-2"><i class="fas fa-right-left text-success me-2"></i>Replace driver &mdash; <?= $kind === 'internal' ? 'internal task #' . $id : 'welfare duty' ?></h4>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <div><strong><?= $kind === 'internal' ? $h(internalTaskTypeLabel($t['task_type'])) : 'Welfare duty (24 hours)' ?></strong> &middot; <?= $h(date('D, d M Y', strtotime($t['start_dt']))) ?><?= $kind === 'internal' ? ' ' . $h(substr($t['start_time'], 0, 5) . '-' . substr($t['end_time'], 0, 5)) : '' ?></div>
        <div>Vehicle: <?= $h((string)$t['plate_number']) ?> <?= $h((string)$t['model']) ?> &middot; Current driver: <strong><?= $h((string)$t['driver_name']) ?></strong> &middot; Status: <?= $kind === 'internal' ? $h(internalTaskStatusLabel($t['status'])) : $h(welfareStatusLabel($t['status'])) ?></div>
    </div></div>
    <?php if (!$can): ?>
        <div class="alert alert-warning">The driver can be replaced only while the task is Approved and has not started.</div>
    <?php else: ?>
    <form method="post" class="card border-0 shadow-sm"><div class="card-body">
        <input type="hidden" name="<?= $kind ?>" value="<?= $id ?>">
        <h6 class="fw-bold">Choose the new driver <span class="fw-normal text-muted small">(only drivers without a clash can be chosen)</span></h6>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead class="table-light"><tr><th></th><th>#</th><th>Driver</th><th class="text-center">Tasks this month</th><th class="text-center">Weekend tasks</th><th class="text-center">Experience (years)</th><th class="text-center">Score</th></tr></thead>
            <tbody>
            <?php foreach ($rec['ranked'] as $d): if ((int)$d['driver_id'] === (int)$t['driver_id']) { continue; } ?>
                <tr><td><input class="form-check-input" type="radio" name="new_driver" value="<?= (int)$d['driver_id'] ?>" id="nd<?= (int)$d['driver_id'] ?>" required></td>
                    <td><?= (int)$d['rank'] ?></td><td><label for="nd<?= (int)$d['driver_id'] ?>" class="mb-0"><?= $h($d['name']) ?></label></td>
                    <td class="text-center"><?= (int)$d['tasks'] ?></td><td class="text-center"><?= (int)$d['weekend'] ?></td><td class="text-center"><?= number_format((float)$d['experience'], 1) ?></td>
                    <td class="text-center"><span class="badge bg-success"><?= number_format((float)$d['score'], 1) ?></span></td></tr>
            <?php endforeach; ?>
            <?php if (!$rec['ranked']): ?><tr><td colspan="7" class="text-danger">No driver is available for this task.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <label class="form-label small fw-semibold" for="reason">Reason for replacing the driver <span class="text-danger">*</span></label>
        <input class="form-control mb-3" id="reason" name="reason" maxlength="500" required>
        <button class="btn btn-primary" data-confirm="The task goes back to Driver Assigned and the Head of Admin must approve again." data-confirm-title="Replace the driver?" data-confirm-button="Replace" data-confirm-tone="primary"><i class="fas fa-right-left me-1"></i>Replace driver</button>
    </div></form>
    <?php endif; ?>
    <?php if ($history): ?><div class="card border-0 shadow-sm mt-3"><div class="card-body small"><h6 class="fw-bold">Replacement history</h6>
        <?php foreach ($history as $r): ?><div><?= $h(date('d M Y H:i', strtotime($r['changed_at']))) ?> &middot; <?= $h((string)$r['old_name']) ?> &rarr; <?= $h((string)$r['new_name']) ?> &middot; <?= $h($r['reason']) ?></div><?php endforeach; ?>
    </div></div><?php endif; ?>
<?php adminPageEnd(); ?>
