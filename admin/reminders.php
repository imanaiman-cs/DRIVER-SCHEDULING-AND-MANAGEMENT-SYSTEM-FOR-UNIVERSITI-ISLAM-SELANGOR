<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/reminders.php  –  remind the Head of Section about requests that wait for a decision
//
// A request that is not decided lapses after 48 hours, so the transport unit can nudge the
// approver. One reminder per request every 4 hours at most, so nobody is spammed.
// (The system also sends one automatic reminder after 24 hours.)
// ============================================================
$page_title   = 'Reminders';
$current_page = 'reminders.php';
require_once '../config/database.php';
require_once '../includes/mailer.php';
requireAdmin();

const REMINDER_MIN_HOURS = 4;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['request_id'] ?? 0);
    $stmt = $conn->prepare("SELECT vr.request_id, vr.supervisor_id, vr.destination, vr.status, s.full_name,
                                   (SELECT MAX(sent_at) FROM reminders r WHERE r.request_id = vr.request_id) AS last_sent
                              FROM vehicle_requests vr JOIN users s ON s.user_id = vr.staff_id WHERE vr.request_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req || $req['status'] !== 'pending') {
        setFlash('warning', 'That request is no longer waiting for a decision.');
    } elseif (empty($req['supervisor_id'])) {
        setFlash('warning', 'That request has no Head of Section to remind.');
    } elseif ($req['last_sent'] && strtotime($req['last_sent']) > time() - REMINDER_MIN_HOURS * 3600) {
        setFlash('warning', 'A reminder was already sent in the last ' . REMINDER_MIN_HOURS . ' hours.');
    } else {
        notifyUsers($conn, [(int)$req['supervisor_id']],
            "Reminder: request #{$id} from {$req['full_name']} to {$req['destination']} is waiting for your decision and will lapse soon.",
            'supervisor/approvals.php');
        $uid = (int)$_SESSION['user_id'];
        $ins = $conn->prepare('INSERT INTO reminders (request_id, sent_by) VALUES (?, ?)');
        $ins->bind_param('ii', $id, $uid);
        $ins->execute();
        $ins->close();
        auditLog($conn, 'reminder', $id, 'Reminder sent to the Head of Section');
        setFlash('success', "Reminder sent for request #{$id}.");
    }
    header('Location: reminders.php');
    exit();
}

$lapse = max(1, getSettingInt($conn, 'LAPSE_HOURS'));
$rows = $conn->query("SELECT vr.request_id, vr.destination, vr.trip_date, vr.created_at, s.full_name AS staff_name, h.full_name AS supervisor_name,
                             TIMESTAMPDIFF(HOUR, vr.created_at, NOW()) AS hours_waiting,
                             (SELECT COUNT(*) FROM reminders r WHERE r.request_id = vr.request_id) AS reminders_sent,
                             (SELECT MAX(sent_at) FROM reminders r WHERE r.request_id = vr.request_id) AS last_sent
                        FROM vehicle_requests vr
                        JOIN users s ON s.user_id = vr.staff_id
                        LEFT JOIN users h ON h.user_id = vr.supervisor_id
                       WHERE vr.status = 'pending' ORDER BY vr.created_at")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reminders | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>
<main class="main-content p-4" id="mainContent">
    <?php showFlash(); ?>
    <h4 class="fw-bold mb-1"><i class="fas fa-bell-concierge text-success me-2"></i>Reminders</h4>
    <p class="text-muted small">Requests still waiting for the Head of Section. They lapse after <?= $lapse ?> hours.
        You can send one reminder every <?= REMINDER_MIN_HOURS ?> hours per request.</p>
    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>No.</th><th>Requester</th><th>Destination</th><th>Trip date</th><th>Head of Section</th><th>Waiting</th><th>Reminders</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $left = $lapse - (int)$r['hours_waiting'];
                $recent = $r['last_sent'] && strtotime($r['last_sent']) > time() - REMINDER_MIN_HOURS * 3600; ?>
                <tr>
                    <td>#<?= (int)$r['request_id'] ?></td>
                    <td><?= htmlspecialchars($r['staff_name']) ?></td>
                    <td><?= htmlspecialchars($r['destination']) ?></td>
                    <td class="text-nowrap"><?= date('d M Y', strtotime($r['trip_date'])) ?></td>
                    <td><?= htmlspecialchars($r['supervisor_name'] ?? '-') ?></td>
                    <td><?= (int)$r['hours_waiting'] ?> h <span class="text-muted small">(<?= $left > 0 ? $left . ' h left' : 'lapsing' ?>)</span></td>
                    <td><?= (int)$r['reminders_sent'] ?><?= $r['last_sent'] ? ' <span class="text-muted small">last ' . date('d M, H:i', strtotime($r['last_sent'])) . '</span>' : '' ?></td>
                    <td class="text-end">
                        <form method="post" class="d-inline"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                            <button class="btn btn-sm btn-outline-success" <?= ($recent || empty($r['supervisor_name'])) ? 'disabled' : '' ?>>
                                <i class="fas fa-paper-plane me-1"></i>Send reminder</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No request is waiting for a decision.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</main>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
