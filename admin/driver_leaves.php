<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/driver_leaves.php  –  leave / MC periods of one driver
//
// A driver on leave is not offered for trips on those days. If the driver already has
// trips or welfare duty in the period, they are listed as a warning so the admin can
// change the driver.
// ============================================================
$current_page = 'driver_leaves.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$driver_id = (int)($_GET['id'] ?? $_POST['driver_id'] ?? 0);
$stmt = $conn->prepare('SELECT driver_id, name, employee_id FROM drivers WHERE driver_id = ?');
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$driver = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$driver) {
    setFlash('danger', 'Driver not found.');
    header('Location: drivers.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        $start = trim($_POST['start_date'] ?? '');
        $end   = trim($_POST['end_date'] ?? '');
        $type  = ($_POST['leave_type'] ?? '') === 'mc' ? 'mc' : 'leave';
        $note  = mb_substr(trim($_POST['note'] ?? ''), 0, 255);
        if (!validDateStr($start) || !validDateStr($end) || $end < $start) {
            setFlash('danger', 'Please enter a valid start date and an end date that is not before it.');
        } else {
            $ins = $conn->prepare('INSERT INTO driver_leaves (driver_id, start_date, end_date, leave_type, note) VALUES (?, ?, ?, ?, ?)');
            $ins->bind_param('issss', $driver_id, $start, $end, $type, $note);
            $ins->execute();
            $ins->close();
            auditLog($conn, 'driver_leave', null, "{$driver['employee_id']}: $type $start to $end");

            $msg = '';
            $q = $conn->prepare("SELECT schedule_id, destination, trip_date, start_time FROM schedules
                                  WHERE driver_id = ? AND trip_date BETWEEN ? AND ? AND status NOT IN ('cancelled','completed') ORDER BY trip_date");
            $q->bind_param('iss', $driver_id, $start, $end);
            $q->execute();
            $trips = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();
            if ($trips) {
                $msg .= ' this driver is already assigned to: ' . implode('; ', array_map(
                    fn($t) => 'Schedule #' . $t['schedule_id'] . ' (' . $t['destination'] . ', ' . date('d M Y', strtotime($t['trip_date'])) . ' ' . substr($t['start_time'], 0, 5) . ')', $trips)) . '. Please change the driver.';
            }
            $q = $conn->prepare('SELECT duty_date FROM welfare_roster WHERE driver_id = ? AND duty_date BETWEEN ? AND ? ORDER BY duty_date');
            $q->bind_param('iss', $driver_id, $start, $end);
            $q->execute();
            $duty = array_column($q->get_result()->fetch_all(MYSQLI_ASSOC), 'duty_date');
            $q->close();
            if ($duty) {
                $msg .= ($trips ? ' Also,' : '') . ' this driver is on welfare duty on ' . implode(', ', array_map(fn($d) => date('d M', strtotime($d)), $duty)) . '. Please replace them in the Welfare roster.';
            }
            setFlash($msg ? 'warning' : 'success', $msg ? 'Leave saved, but' . $msg : 'Leave saved.');
        }
    } elseif ($act === 'delete') {
        $lid = (int)($_POST['leave_id'] ?? 0);
        $del = $conn->prepare('DELETE FROM driver_leaves WHERE leave_id = ? AND driver_id = ?');
        $del->bind_param('ii', $lid, $driver_id);
        $del->execute();
        $del->close();
        setFlash('success', 'Leave removed.');
    }
    header('Location: driver_leaves.php?id=' . $driver_id);
    exit();
}

$rows = $conn->query("SELECT * FROM driver_leaves WHERE driver_id = {$driver_id} ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
adminPageStart('Driver Leave');
?>
    <a class="small text-decoration-none" href="drivers.php"><i class="fas fa-arrow-left me-1"></i>All drivers</a>
    <h4 class="fw-bold my-2"><i class="fas fa-umbrella-beach text-success me-2"></i>Leave &amp; MC &mdash; <?= htmlspecialchars($driver['name']) ?>
        <small class="text-muted fs-6"><?= htmlspecialchars($driver['employee_id']) ?></small></h4>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Add leave / MC</h6>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add"><input type="hidden" name="driver_id" value="<?= $driver_id ?>">
                    <div class="col-6"><label class="form-label small" for="start_date">From</label><input type="date" class="form-control form-control-sm" id="start_date" name="start_date" required></div>
                    <div class="col-6"><label class="form-label small" for="end_date">To</label><input type="date" class="form-control form-control-sm" id="end_date" name="end_date" required></div>
                    <div class="col-12"><label class="form-label small" for="leave_type">Type</label>
                        <select class="form-select form-select-sm" id="leave_type" name="leave_type"><option value="leave">Leave</option><option value="mc">MC (medical certificate)</option></select></div>
                    <div class="col-12"><label class="form-label small" for="note">Note (optional)</label><input class="form-control form-control-sm" id="note" name="note" maxlength="255"></div>
                    <div class="col-12"><button class="btn btn-success btn-sm" type="submit">Add</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm"><div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>From</th><th>To</th><th>Type</th><th>Note</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $l): ?>
                        <tr>
                            <td class="text-nowrap"><?= date('d M Y', strtotime($l['start_date'])) ?></td>
                            <td class="text-nowrap"><?= date('d M Y', strtotime($l['end_date'])) ?></td>
                            <td><?= $l['leave_type'] === 'mc' ? 'MC' : 'Leave' ?></td>
                            <td class="small"><?= htmlspecialchars($l['note'] ?? '') ?></td>
                            <td class="text-end"><form method="post" class="d-inline"><input type="hidden" name="action" value="delete">
                                <input type="hidden" name="driver_id" value="<?= $driver_id ?>"><input type="hidden" name="leave_id" value="<?= (int)$l['leave_id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" data-confirm="Remove this leave record?" data-confirm-title="Remove?" data-confirm-button="Remove" data-confirm-tone="danger">Remove</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No leave recorded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
<?php adminPageEnd(); ?>
