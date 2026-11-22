<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/reminders.php  -  UC016 Approval Reminders (automatic; this page only shows them)
//
// The Administrative Assistant sees the Pending requests, how long each has been waiting, when the automatic
// reminder was (or will be) sent to the Head of Department and the staff member, and when the request lapses.
// ============================================================
$current_page = 'reminders.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

// Read only: the reminder is sent by runRequestJobs() (cron/run_jobs.php and the pages), never from here.
$rows = $conn->query(
    "SELECT vr.request_id, vr.destination, vr.trip_date, vr.start_time, vr.created_at, u.full_name AS staff_name, u.department,
            hod.full_name AS hod_name,
            (SELECT MAX(r.sent_at) FROM reminders r WHERE r.request_id = vr.request_id) AS last_sent,
            (SELECT r.email_error FROM reminders r WHERE r.request_id = vr.request_id ORDER BY r.reminder_id DESC LIMIT 1) AS last_error
       FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id LEFT JOIN users hod ON hod.user_id = vr.supervisor_id
      WHERE vr.status = 'pending' ORDER BY vr.created_at"
)->fetch_all(MYSQLI_ASSOC);
$lapse  = lapseWindowText($conn);
$remind = reminderHours($conn);
$lapseH = max(1, getSettingInt($conn, 'LAPSE_HOURS'));

adminPageStart('Approval Reminders');
?>
    <h1 class="text-2xl font-extrabold tracking-tight mb-1"><i class="fas fa-bell-concierge me-2 text-[0.8em] text-primary"></i>Approval Reminders</h1>
    <p class="text-base-content/60 text-sm mb-4">Requests waiting for the Head of Department. After <?= $remind ?> hours the system reminds the Head of Department and the staff member automatically, in the system and by e-mail. A request still Pending after <?= htmlspecialchars($lapse) ?> lapses.</p>
    <div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto">
        <table class="table align-middle mb-0">
            <thead><tr><th>Request</th><th>Requester</th><th>Head of Department</th><th class="text-center">Hours pending</th><th>Reminder</th><th>Lapses</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $created = strtotime($r['created_at']);
                $hours   = max(0, (int)floor((time() - $created) / 3600));
                $due     = $created + $remind * 3600;
                $lapseAt = $created + $lapseH * 3600; ?>
                <tr>
                    <td><strong>#<?= (int)$r['request_id'] ?></strong> <?= htmlspecialchars($r['destination']) ?>
                        <div class="text-sm text-base-content/60"><?= date('d M Y', strtotime($r['trip_date'])) ?>, <?= substr($r['start_time'], 0, 5) ?></div></td>
                    <td><?= htmlspecialchars($r['staff_name']) ?><div class="text-sm text-base-content/60"><?= htmlspecialchars((string)$r['department']) ?></div></td>
                    <td><?= htmlspecialchars((string)$r['hod_name']) ?></td>
                    <td class="text-center"><span class="badge <?= $hours >= $remind ? 'badge-warning' : 'badge-ghost' ?>"><?= $hours ?></span></td>
                    <td class="text-sm">
                        <?php if ($r['last_sent']): ?>
                            <span class="e-status text-primary">Sent</span> <?= date('d M Y H:i', strtotime($r['last_sent'])) ?>
                            <?= $r['last_error'] ? '<div class="text-error">E-mail failed; the in-system reminder was sent.</div>' : '' ?>
                        <?php else: ?>
                            <span class="e-status text-base-content/70">Scheduled</span> <?= date('d M Y H:i', max($due, time())) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm whitespace-nowrap"><?= date('d M Y H:i', $lapseAt) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-base-content/60 py-6">No request is waiting for approval.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
<?php adminPageEnd(); ?>
