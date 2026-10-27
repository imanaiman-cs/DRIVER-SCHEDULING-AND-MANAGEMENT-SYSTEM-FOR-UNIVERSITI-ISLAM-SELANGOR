<?php
// ============================================================
// UIS Driver Scheduling and Management System
// driver/schedule_change.php  -  UC029 Request Schedule Change
//
//   Unavailable Date: the driver chooses the date or dates they cannot work (the page shows their tasks on those dates)
//   Withdraw from Task: the driver chooses one assigned (Confirmed) task; can also be started from My Tasks
// The request is saved as Pending and the Head of Admin is told (system + e-mail). The driver cannot cancel a task
// directly: the task stays with the driver until the request is approved.
// ============================================================
$current_page = 'schedule_change.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireDriver();

$driver_id = (int)($_SESSION['driver_id'] ?? 0);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// the driver's coming Confirmed trip tasks (for "Withdraw from Task" and to show the tasks on the chosen dates)
$stmt = $conn->prepare(
    "SELECT s.schedule_id, s.leg_no, s.start_dt, s.end_dt, vr.destination FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id
      WHERE s.driver_id = ? AND s.status <> 'cancelled' AND vr.status = 'processed' AND s.start_dt > NOW() ORDER BY s.start_dt LIMIT 100"
);
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$myTasks = $driver_id > 0 ? $stmt->get_result()->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$f = ['type' => in_array($_GET['type'] ?? '', ['withdraw'], true) ? 'withdraw' : 'unavailable', 'from' => '', 'to' => '', 'schedule_id' => (string)(int)($_GET['schedule_id'] ?? 0), 'reason' => ''];
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) { $f[$k] = trim((string)($_POST[$k] ?? '')); }
    $f['type'] = $f['type'] === 'withdraw' ? 'withdraw' : 'unavailable';
    $dates = [];
    if ($f['type'] === 'unavailable') {
        $to = $f['to'] === '' ? $f['from'] : $f['to'];
        $dates = scheduleChangeDates($f['from'], $to);
        if (!$dates) { $error = 'Choose the date or dates (the last date cannot be before the first).'; }
        elseif (count($dates) > SCHEDULE_CHANGE_MAX_DAYS) { $error = 'Choose at most ' . SCHEDULE_CHANGE_MAX_DAYS . ' days in one request.'; }
    }
    if (!$error) {
        $res = createScheduleChangeRequest($conn, $driver_id, $f['type'], $dates, (int)$f['schedule_id'] ?: null, $f['reason']);
        if ($res['ok']) { setFlash('success', $res['message']); header('Location: schedule_change.php'); exit(); }
        $error = $res['message'];
    }
}

$stmt = $conn->prepare('SELECT r.*, (SELECT MIN(change_date) FROM schedule_change_dates WHERE change_id = r.change_id) AS d1, (SELECT MAX(change_date) FROM schedule_change_dates WHERE change_id = r.change_id) AS d2 FROM schedule_change_requests r WHERE r.driver_id = ? ORDER BY r.change_id DESC LIMIT 20');
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$mine = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
adminPageStart('Schedule Change');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-calendar-xmark text-success me-2"></i>Request Schedule Change</h4>
    <p class="text-muted small mb-3">Tell the Vehicle Section which dates you cannot work, or ask to be withdrawn from a task. The Head of Admin decides. A task stays with you until the request is approved.</p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <form method="post" class="card border-0 shadow-sm mb-4" novalidate><div class="card-body">
        <div class="mb-3"><span class="form-label d-block fw-semibold">Request type</span>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="type" id="t_un" value="unavailable" <?= $f['type'] === 'unavailable' ? 'checked' : '' ?>><label class="form-check-label" for="t_un">Unavailable Date</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="type" id="t_wd" value="withdraw" <?= $f['type'] === 'withdraw' ? 'checked' : '' ?>><label class="form-check-label" for="t_wd">Withdraw from Task</label></div></div>
        <div id="boxUn" class="row g-3 mb-3">
            <div class="col-6 col-md-3"><label class="form-label" for="from">From date *</label><input type="date" class="form-control" id="from" name="from" min="<?= date('Y-m-d') ?>" value="<?= $h($f['from']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="to">To date <span class="text-muted small">(leave empty for one day)</span></label><input type="date" class="form-control" id="to" name="to" min="<?= date('Y-m-d') ?>" value="<?= $h($f['to']) ?>"></div>
            <div class="col-12"><div id="tasksOnDates" class="small"></div></div>
        </div>
        <div id="boxWd" class="mb-3">
            <label class="form-label fw-semibold">Task *</label>
            <?php if (!$myTasks): ?><div class="text-muted small">You have no confirmed task to withdraw from.</div><?php endif; ?>
            <?php foreach ($myTasks as $t): ?>
                <div class="form-check"><input class="form-check-input" type="radio" name="schedule_id" id="s<?= (int)$t['schedule_id'] ?>" value="<?= (int)$t['schedule_id'] ?>" <?= (string)$t['schedule_id'] === $f['schedule_id'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="s<?= (int)$t['schedule_id'] ?>"><?= $h(date('D, d M Y H:i', strtotime($t['start_dt']))) ?> &middot; <?= (int)$t['leg_no'] === 2 ? 'Return' : 'Outbound' ?> &middot; <?= $h($t['destination']) ?></label></div>
            <?php endforeach; ?>
        </div>
        <div class="mb-3"><label class="form-label fw-semibold" for="reason">Reason *</label><textarea class="form-control" id="reason" name="reason" rows="3" maxlength="500"><?= $h($f['reason']) ?></textarea></div>
        <button class="btn btn-success btn-lg" type="submit">Submit</button>
    </div></form>

    <h5 class="fw-bold mb-2">My requests</h5>
    <?php if (!$mine): ?><div class="text-muted small">You have not made a request yet.</div><?php endif; ?>
    <?php foreach ($mine as $r): $cls = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger'][$r['status']]; ?>
        <div class="card border-0 shadow-sm mb-2"><div class="card-body py-2">
            <div class="d-flex justify-content-between gap-2">
                <div><strong><?= $r['change_type'] === 'withdraw' ? 'Withdraw from task' : 'Unavailable' ?></strong> &middot;
                    <?= $r['d1'] === $r['d2'] ? $h(date('d M Y', strtotime($r['d1']))) : $h(date('d M', strtotime($r['d1'])) . ' - ' . date('d M Y', strtotime($r['d2']))) ?></div>
                <span class="badge bg-<?= $cls ?>"><?= $h(ucfirst($r['status'])) ?></span></div>
            <div class="small text-muted">Reason: <?= $h($r['reason']) ?></div>
            <?php if ($r['decision_comment']): ?><div class="small">Head of Admin: <?= $h($r['decision_comment']) ?></div><?php endif; ?>
        </div></div>
    <?php endforeach; ?>
<script>
(function () {
    var tasks = <?= json_encode(array_map(fn($t) => ['d' => substr($t['start_dt'], 0, 10), 'when' => date('D, d M H:i', strtotime($t['start_dt'])), 'leg' => (int)$t['leg_no'] === 2 ? 'Return' : 'Outbound', 'dest' => $t['destination']], $myTasks)) ?>;
    var un = document.getElementById('boxUn'), wd = document.getElementById('boxWd');
    function sync() { var w = document.getElementById('t_wd').checked; wd.style.display = w ? '' : 'none'; un.style.display = w ? 'none' : ''; }
    function show() {
        var a = document.getElementById('from').value, b = document.getElementById('to').value || a, box = document.getElementById('tasksOnDates');
        if (!a) { box.textContent = ''; return; }
        var hit = tasks.filter(function (t) { return t.d >= a && t.d <= b; });
        box.innerHTML = hit.length ? '<div class="alert alert-info py-2 mb-0">You have tasks on these dates:<ul class="mb-0">' + hit.map(function (t) { return '<li>' + t.when + ' &middot; ' + t.leg + ' &middot; ' + String(t.dest).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</li>'; }).join('') + '</ul>The Head of Admin will replace you on them if the request is approved.</div>' : '<span class="text-muted">You have no confirmed task on these dates.</span>';
    }
    ['t_un', 't_wd'].forEach(function (i) { document.getElementById(i).addEventListener('change', sync); });
    ['from', 'to'].forEach(function (i) { document.getElementById(i).addEventListener('change', show); });
    sync(); show();
})();
</script>
<?php adminPageEnd(); ?>
