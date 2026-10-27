<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/reminders.php  -  Send Approval Reminder by hand (the hourly job already sends ONE automatic reminder to the Head of Department after 1 day, UC012)
//
// The Administrative Assistant sees the Pending requests and how many days each has been waiting, and presses
// "Send Reminder". The reminder goes to the Head of Department AND the staff member, in the system and by e-mail,
// and the reminder date is recorded. If the e-mail fails the reminder is still saved and the error is shown.
// ============================================================
$current_page = 'reminders.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = sendApprovalReminder($conn, (int)($_POST['request_id'] ?? 0));
    setFlash($res['ok'] ? ($res['email_error'] ? 'warning' : 'success') : 'danger', $res['message']);
    header('Location: reminders.php');
    exit();
}

$rows = $conn->query(
    "SELECT vr.request_id, vr.destination, vr.trip_date, vr.start_time, vr.created_at, u.full_name AS staff_name, u.department,
            hod.full_name AS hod_name,
            (SELECT COUNT(*) FROM reminders r WHERE r.request_id = vr.request_id) AS reminders_sent,
            (SELECT MAX(r.sent_at) FROM reminders r WHERE r.request_id = vr.request_id) AS last_sent,
            (SELECT r.email_error FROM reminders r WHERE r.request_id = vr.request_id ORDER BY r.reminder_id DESC LIMIT 1) AS last_error
       FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id LEFT JOIN users hod ON hod.user_id = vr.supervisor_id
      WHERE vr.status = 'pending' ORDER BY vr.created_at"
)->fetch_all(MYSQLI_ASSOC);
$lapse = lapseWindowText($conn);

adminPageStart('Approval Reminders');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-bell-concierge text-success me-2"></i>Approval Reminders</h4>
    <p class="text-muted small">Requests waiting for the Head of Department. A request that is still Pending after <?= htmlspecialchars($lapse) ?> lapses. The system sends one reminder to the Head of Department after 1 day by itself; here you can remind again by hand.</p>
    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Request</th><th>Requester</th><th>Head of Department</th><th class="text-center">Days pending</th><th>Last reminder</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $days = max(0, (int)floor((time() - strtotime($r['created_at'])) / 86400)); ?>
                <tr>
                    <td><strong>#<?= (int)$r['request_id'] ?></strong> <?= htmlspecialchars($r['destination']) ?>
                        <div class="small text-muted"><?= date('d M Y', strtotime($r['trip_date'])) ?>, <?= substr($r['start_time'], 0, 5) ?></div></td>
                    <td><?= htmlspecialchars($r['staff_name']) ?><div class="small text-muted"><?= htmlspecialchars((string)$r['department']) ?></div></td>
                    <td><?= htmlspecialchars((string)$r['hod_name']) ?></td>
                    <td class="text-center"><span class="badge bg-<?= $days >= 1 ? 'warning text-dark' : 'secondary' ?>"><?= $days ?></span></td>
                    <td class="small"><?= $r['last_sent'] ? date('d M Y H:i', strtotime($r['last_sent'])) . ' (' . (int)$r['reminders_sent'] . 'x)' : '&mdash;' ?>
                        <?= $r['last_error'] ? '<div class="text-danger">e-mail failed</div>' : '' ?></td>
                    <td class="text-end">
                        <form method="post"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                            <button class="btn btn-sm btn-outline-success" data-confirm="The Head of Department and the staff member are reminded in the system and by e-mail." data-confirm-title="Send a reminder?" data-confirm-button="Send Reminder" data-confirm-tone="primary"><i class="fas fa-paper-plane me-1"></i>Send Reminder</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No request is waiting for approval.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
<?php adminPageEnd(); ?>
