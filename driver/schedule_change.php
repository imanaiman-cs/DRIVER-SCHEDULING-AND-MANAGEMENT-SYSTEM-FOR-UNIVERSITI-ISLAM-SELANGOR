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
    "SELECT s.task_id, s.trip_type, s.start_dt, s.end_dt, vr.destination FROM tasks s JOIN vehicle_requests vr ON vr.request_id = s.request_id
      WHERE s.task_source = 'request' AND s.driver_id = ? AND s.status <> 'cancelled' AND vr.status = 'processed' AND s.start_dt > NOW() ORDER BY s.start_dt LIMIT 100"
);
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$myTasks = $driver_id > 0 ? $stmt->get_result()->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {          // UC022 notes: cancel a Pending request
    $res = cancelScheduleChangeRequest($conn, (int)($_POST['change_id'] ?? 0), $driver_id);
    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    header('Location: schedule_change.php');
    exit();
}
// the form field keeps its old name "schedule_id"; its value is now a tasks.task_id
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

$stmt = $conn->prepare('SELECT r.*, r.start_date AS d1, r.end_date AS d2 FROM schedule_changes r WHERE r.driver_id = ? ORDER BY r.change_id DESC LIMIT 20');
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$mine = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
adminPageStart('Schedule Change');
?>
    <h1 class="text-2xl font-medium mb-1">Request Schedule Change</h1>
    <p class="text-base-content/60 text-sm mb-4">Tell the Vehicle Section which dates you cannot work, or ask to be withdrawn from a task. The Head of Admin decides. A task stays with you until the request is approved.</p>
    <?php if ($error): ?><div class="alert alert-soft alert-error mb-4" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <form method="post" class="card border border-base-300 bg-base-100 mb-6" novalidate><div class="card-body gap-4">
        <fieldset><legend class="mb-2 text-sm font-medium">Request type</legend>
            <div class="flex flex-wrap gap-x-6 gap-y-3">
                <label class="flex items-center gap-2" for="t_un"><input class="radio radio-primary" type="radio" name="type" id="t_un" value="unavailable" <?= $f['type'] === 'unavailable' ? 'checked' : '' ?>>Unavailable Date</label>
                <label class="flex items-center gap-2" for="t_wd"><input class="radio radio-primary" type="radio" name="type" id="t_wd" value="withdraw" <?= $f['type'] === 'withdraw' ? 'checked' : '' ?>>Withdraw from Task</label>
            </div></fieldset>
        <div id="boxUn" class="grid grid-cols-2 gap-4">
            <div><label class="mb-1 block text-sm font-medium" for="from">From date *</label><input type="date" class="input w-full" id="from" name="from" min="<?= date('Y-m-d') ?>" value="<?= $h($f['from']) ?>"></div>
            <div><label class="mb-1 block text-sm font-medium" for="to">To date <span class="font-normal text-base-content/60">(leave empty for one day)</span></label><input type="date" class="input w-full" id="to" name="to" min="<?= date('Y-m-d') ?>" value="<?= $h($f['to']) ?>"></div>
            <div class="col-span-2"><div id="tasksOnDates" class="text-sm"></div></div>
        </div>
        <fieldset id="boxWd"><legend class="mb-2 text-sm font-medium">Task *</legend>
            <?php if (!$myTasks): ?><p class="text-sm text-base-content/60">You have no confirmed task to withdraw from.</p><?php endif; ?>
            <div class="space-y-2">
            <?php foreach ($myTasks as $t): ?>
                <label class="flex items-start gap-3 rounded-box border border-base-300 p-3 has-[:checked]:border-primary has-[:checked]:bg-primary/5" for="s<?= (int)$t['task_id'] ?>">
                    <input class="radio radio-primary mt-0.5" type="radio" name="schedule_id" id="s<?= (int)$t['task_id'] ?>" value="<?= (int)$t['task_id'] ?>" <?= (string)$t['task_id'] === $f['schedule_id'] ? 'checked' : '' ?>>
                    <span><time class="block font-medium" datetime="<?= date('Y-m-d\TH:i', strtotime($t['start_dt'])) ?>"><?= $h(date('D, d M Y H:i', strtotime($t['start_dt']))) ?></time>
                        <span class="text-sm text-base-content/70"><?= $t['trip_type'] === 'return' ? 'Return' : 'Outbound' ?> &middot; <?= $h($t['destination']) ?></span></span></label>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <div><label class="mb-1 block text-sm font-medium" for="reason">Reason *</label><textarea class="textarea w-full" id="reason" name="reason" rows="3" maxlength="500"><?= $h($f['reason']) ?></textarea></div>
        <button class="btn btn-primary btn-lg w-full sm:w-auto" type="submit">Submit</button>
    </div></form>

    <h2 class="text-lg font-medium mb-2">My requests</h2>
    <?php if (!$mine): ?><p class="text-sm text-base-content/60">You have not made a request yet.</p><?php endif; ?>
    <?php foreach ($mine as $r): $cls = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'rejected' => 'badge-error', 'cancelled' => 'badge-neutral'][$r['status']] ?? 'badge-neutral'; ?>
        <div class="card border border-base-300 bg-base-100 mb-2"><div class="card-body gap-1 py-3">
            <div class="flex justify-between gap-2">
                <div><strong><?= $r['change_type'] === 'withdraw' ? 'Withdraw from task' : 'Unavailable' ?></strong> &middot;
                    <?= $r['d1'] === $r['d2'] ? $h(date('d M Y', strtotime($r['d1']))) : $h(date('d M', strtotime($r['d1'])) . ' - ' . date('d M Y', strtotime($r['d2']))) ?></div>
                <span class="badge badge-soft <?= $cls ?>"><?= $h(ucfirst($r['status'])) ?></span></div>
            <div class="text-sm text-base-content/60">Reason: <?= $h($r['reason']) ?></div>
            <?php if ($r['status'] === 'pending'): ?><form method="post" class="mt-1"><input type="hidden" name="action" value="cancel"><input type="hidden" name="change_id" value="<?= (int)$r['change_id'] ?>"><button class="btn btn-sm btn-outline btn-error" data-confirm="The Head of Admin will no longer see this request." data-confirm-title="Cancel this request?" data-confirm-button="Cancel request">Cancel request</button></form><?php endif; ?>
            <?php if ($r['reject_reason']): ?><div class="text-sm">Head of Admin: <?= $h($r['reject_reason']) ?></div><?php endif; ?>
        </div></div>
    <?php endforeach; ?>
<script>
(function () {
    var tasks = <?= json_encode(array_map(fn($t) => ['d' => substr($t['start_dt'], 0, 10), 'when' => date('D, d M H:i', strtotime($t['start_dt'])), 'leg' => $t['trip_type'] === 'return' ? 'Return' : 'Outbound', 'dest' => $t['destination']], $myTasks)) ?>;
    var un = document.getElementById('boxUn'), wd = document.getElementById('boxWd');
    function sync() { var w = document.getElementById('t_wd').checked; wd.style.display = w ? '' : 'none'; un.style.display = w ? 'none' : ''; }
    function show() {
        var a = document.getElementById('from').value, b = document.getElementById('to').value || a, box = document.getElementById('tasksOnDates');
        if (!a) { box.textContent = ''; return; }
        var hit = tasks.filter(function (t) { return t.d >= a && t.d <= b; });
        box.innerHTML = hit.length ? '<div class="alert alert-soft alert-info block">You have tasks on these dates:<ul class="my-1 list-disc ps-5">' + hit.map(function (t) { return '<li>' + t.when + ' &middot; ' + t.leg + ' &middot; ' + String(t.dest).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</li>'; }).join('') + '</ul>The Head of Admin will replace you on them if the request is approved.</div>' : '<span class="text-base-content/60">You have no confirmed task on these dates.</span>';
    }
    ['t_un', 't_wd'].forEach(function (i) { document.getElementById(i).addEventListener('change', sync); });
    ['from', 'to'].forEach(function (i) { document.getElementById(i).addEventListener('change', show); });
    sync(); show();
})();
</script>
<?php adminPageEnd(); ?>
