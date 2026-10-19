<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/approve_assignments.php  –  UC011: the Head of Admin approves driver assignments
//
// The Admin Assistant assigns vehicle + driver: the request becomes "Driver Assigned".
// Only the Head of Admin can turn it into "Confirmed" (stored as 'processed'); that is the
// moment the driver sees the trip and the requester is told it is final.
// ============================================================
$current_page = 'approve_assignments.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/mailer.php';
requireHeadAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $rid = (int)($_POST['request_id'] ?? 0);
    $row = $conn->query("SELECT trip_date, start_time, status FROM vehicle_requests WHERE request_id = $rid")->fetch_assoc();
    $legRows = $conn->query("SELECT driver_id, self_drive FROM schedules WHERE request_id = $rid AND status <> 'cancelled'")->fetch_all(MYSQLI_ASSOC);
    $noDriver = array_filter($legRows, fn($l) => $l['driver_id'] === null && !(int)$l['self_drive']);
    if (!$row) {
        setFlash('danger', 'Request not found.');
    } elseif ($row['status'] !== 'assigned') {
        setFlash('danger', "Request #$rid is not waiting for approval any more (it is now " . requestStatusLabel($row['status']) . ').');
    } elseif (!$legRows) {
        setFlash('danger', "Request #$rid has no schedule yet, so it cannot be confirmed.");
    } elseif ($noDriver) {
        setFlash('danger', "Request #$rid still has a vehicle without a driver. Edit the schedule and choose a driver (or mark the request as self-drive) first.");
    } elseif (strtotime($row['trip_date'] . ' ' . $row['start_time']) <= time()) {
        setFlash('danger', 'The trip time has already passed, so it can no longer be confirmed.');
    } else {
        $ids = requestScheduleIds($conn, $rid);
        $res = changeRequestStatus($conn, $rid, 'processed');
        if ($res['ok']) {
            // e-mail the drivers now that the trip is final (a mail problem never undoes the approval)
            try {
                $withDriver = $ids ? array_column($conn->query("SELECT schedule_id FROM schedules WHERE driver_id IS NOT NULL AND schedule_id IN (" . implode(',', array_map('intval', $ids)) . ")")->fetch_all(MYSQLI_ASSOC), 'schedule_id') : [];
                if ($withDriver) { notifyDriversAssigned($conn, array_map('intval', $withDriver)); }
            } catch (Throwable $e) {
                error_log('approve_assignments mail: ' . $e->getMessage());
            }
            setFlash('success', "Request #$rid is confirmed. The driver and the requester have been told.");
        } else {
            setFlash('danger', $res['message']);
        }
    }
    header('Location: approve_assignments.php');
    exit();
}

$requests = $conn->query("SELECT vr.*, u.full_name AS staff_name, u.department
                            FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id
                           WHERE vr.status = 'assigned'
                           ORDER BY vr.trip_date, vr.start_time")->fetch_all(MYSQLI_ASSOC);
foreach ($requests as &$r) {
    $ids = requestScheduleIds($conn, (int)$r['request_id']);
    $r['legs'] = $ids ? $conn->query("SELECT sc.schedule_id, sc.status, d.name AS driver_name, v.plate_number, v.vehicle_type
                                        FROM schedules sc LEFT JOIN drivers d ON d.driver_id = sc.driver_id
                                        LEFT JOIN vehicles v ON v.vehicle_id = sc.vehicle_id
                                       WHERE sc.schedule_id IN (" . implode(',', array_map('intval', $ids)) . ") AND sc.status <> 'cancelled'")->fetch_all(MYSQLI_ASSOC) : [];
}
unset($r);

adminPageStart('Approve Driver Assignments');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-user-check text-success me-2"></i>Approve Driver Assignments</h4>
    <p class="text-muted small">The Admin Assistant has assigned these vehicles and drivers. Confirm each one so the driver and the requester can rely on it.
        To change the driver first, open the schedule and edit it; to stop the trip, use Cancel in Vehicle Requests.</p>

    <?php if (!$requests): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
            <i class="fas fa-circle-check fa-2x text-success mb-2"></i><div>Nothing is waiting for your approval.</div></div></div>
    <?php endif; ?>

    <?php foreach ($requests as $r): ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div>
                <div class="fw-bold">Request #<?= (int)$r['request_id'] ?> &mdash; <?= htmlspecialchars($r['destination']) ?></div>
                <div class="small text-muted">
                    <?= date('D, d M Y', strtotime($r['trip_date'])) ?>, <?= substr($r['start_time'], 0, 5) ?>&ndash;<?= substr($r['end_time'], 0, 5) ?>
                    &bull; <?= (int)$r['passenger_count'] ?> passenger(s)
                    &bull; Requested by <?= htmlspecialchars($r['staff_name']) ?><?= $r['department'] ? ' (' . htmlspecialchars($r['department']) . ')' : '' ?>
                </div>
                <div class="small mt-1"><?= htmlspecialchars($r['purpose']) ?></div>
            </div>
            <form method="post" class="align-self-start">
                <input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                <button class="btn btn-success btn-sm" data-confirm="The driver will see this trip and the requester will be told it is confirmed." data-confirm-title="Confirm this assignment?" data-confirm-button="Confirm" data-confirm-tone="primary">
                    <i class="fas fa-check me-1"></i>Approve &amp; confirm</button>
            </form>
        </div>
        <table class="table table-sm mt-2 mb-0">
            <thead class="table-light"><tr><th>Vehicle</th><th>Driver</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($r['legs'] as $l): ?>
                <tr>
                    <td><?= htmlspecialchars(($l['plate_number'] ?? '—') . ($l['vehicle_type'] ? ' (' . $l['vehicle_type'] . ')' : '')) ?></td>
                    <td><?= $l['driver_name'] ? htmlspecialchars($l['driver_name']) : '<span class="text-danger">No driver assigned</span>' ?></td>
                    <td class="text-end"><a class="small" href="edit_schedule.php?id=<?= (int)$l['schedule_id'] ?>">Edit schedule</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$r['legs']): ?><tr><td colspan="3" class="text-muted">No schedule found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
