<?php
// ============================================================
// admin/welfare.php  -  View Layer: InternalTaskUI (SDD_CLASS_107) displayWelfareWeek() + AssignmentUI (SDD_CLASS_106)
//   UC006 Manage Welfare Duty + UC007 Assign Welfare Duty Task (includes UC009)
// Welfare duty is a 24-hour standby duty (12:00 am to 12:00 am) using a car or van, planned per week.
//   UC006A Create Schedule: a vehicle for each day -> Waiting for Driver; the vehicle is reserved for 24 hours
//   UC006B View by week      UC006C change the vehicle of a day     UC006D delete the duty of a day
//   UC007  a driver for each day (recommended drivers per day) -> Submit -> Driver Assigned -> Head of Admin (UC026)
// Past days cannot be changed or deleted (they are counted in the driver's task count).
// ============================================================
$current_page = 'welfare.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$me = currentUser();
$tc = new TaskController();
$monday = weekMonday(isValidDate((string)($_GET['week'] ?? $_POST['week'] ?? '')) ? ($_GET['week'] ?? $_POST['week']) : date('Y-m-d'));
$self = 'welfare.php?week=' . $monday;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $wk = $tc->getWelfareWeek($monday)['week'];
    $res = match ($act) {
        'create'      => $tc->createWelfareWeek($me, $monday, array_map('intval', (array)($_POST['vehicle'] ?? []))),
        'update'      => $tc->updateWelfareDay($monday, (string)($_POST['date'] ?? ''), (int)($_POST['vehicle_id'] ?? 0)),
        'delete'      => $tc->deleteWelfareDay((int)($_POST['task_id'] ?? 0)),
        'delete_week' => $wk ? $tc->deleteWelfareWeek((int)$wk['duty_id']) : wfFail('No schedule exists for the selected week.'),
        'assign'      => $wk ? (new AssignmentController())->assignWelfareDuty($me, (int)$wk['duty_id'], array_map('intval', (array)($_POST['driver'] ?? []))) : wfFail('No schedule exists for the selected week.'),
        default       => wfFail('Unknown action.'),
    };
    redirectWithResult($res, $self);
}

['week' => $week, 'days' => $days] = $tc->getWelfareWeek($monday);
$editDay = (string)($_GET['edit'] ?? '');
$prev = date('Y-m-d', strtotime("$monday -7 days")); $next = date('Y-m-d', strtotime("$monday +7 days"));
$assignable = array_filter($days, fn($t) => $t && in_array($t['status'], [Task::WAITING, Task::REJECTED], true) && strtotime($t['start_datetime']) > time());
$rs = new RecommendationService();

adminPageStart('Welfare Duty');
pageHeader('Welfare Duty', 'moon', '24-hour standby duty with a car or van, from 12:00 am each day. Welfare duty is counted in each driver\'s task count, so it rotates among drivers.');
?>
<div class="mb-5 flex flex-wrap items-center gap-2">
    <a class="btn btn-sm btn-outline" href="?week=<?= $prev ?>"><i class="fas fa-chevron-left"></i>Previous week</a>
    <form method="get" class="flex items-center gap-1"><label class="sr-only" for="week">Week</label><input type="date" class="input input-sm" id="week" name="week" value="<?= h($monday) ?>"><button class="btn btn-sm">Go</button></form>
    <a class="btn btn-sm btn-outline" href="?week=<?= $next ?>">Next week<i class="fas fa-chevron-right"></i></a>
    <span class="ms-2 font-semibold">Week of <?= h(date('d M Y', strtotime($monday))) ?> - <?= h(date('d M Y', strtotime("$monday +6 days"))) ?></span>
    <?php if ($week): ?><span class="badge <?= ['Approved' => 'badge-success', 'Waiting for Driver' => 'badge-warning', 'Rejected' => 'badge-error'][$week['status']] ?? 'badge-primary badge-outline' ?>"><?= h($week['status']) ?></span>
    <?php if (strtotime($week['week_start_date']) > time()): ?><form method="post" class="ms-auto"><input type="hidden" name="action" value="delete_week"><input type="hidden" name="week" value="<?= h($monday) ?>"><button class="btn btn-sm btn-outline btn-error" type="submit" data-confirm="Every day of this week is deleted and the vehicles are released. The drivers are notified." data-confirm-title="Delete this week?" data-confirm-button="Delete" data-confirm-tone="danger"><i class="fas fa-trash"></i>Delete week</button></form><?php endif; ?>
    <?php endif; ?>
</div>
<?php $rr = array_values(array_filter($days, fn($t) => $t && $t['status'] === Task::REJECTED && $t['reject_reason'])); if ($rr): ?><div class="alert alert-soft alert-warning mb-5"><i class="fas fa-rotate-left"></i><span>Returned by the Head of Admin: <?= h($rr[0]['reject_reason']) ?></span></div><?php endif; ?>

<?php if (!$week): ?>
    <?php if ($monday < weekMonday(date('Y-m-d'))): ?>
        <div class="dash-empty"><i class="fas fa-inbox"></i>No record is found for this week.</div>
    <?php else: cardStart('Create Schedule', 'plus'); ?>
    <p class="mb-3 text-sm text-base-content/65">Select the vehicle (car or van) for each day. The vehicle is reserved for the 24-hour duty.</p>
    <form method="post" novalidate><input type="hidden" name="action" value="create"><input type="hidden" name="week" value="<?= h($monday) ?>">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ($days as $d => $t): $passed = $tc->dayPassed($d); $vs = $passed ? [] : $tc->welfareVehicles($d); ?>
            <div class="rounded-lg border border-base-300 p-3"><label class="mb-1 block text-sm font-semibold" for="v<?= $d ?>"><?= h(date('l, d M', strtotime($d))) ?></label>
            <?php if ($passed): ?><span class="text-xs text-base-content/60">Passed</span><?php else: ?>
                <select class="select select-sm w-full" id="v<?= $d ?>" name="vehicle[<?= $d ?>]" required><option value="">Select the vehicle</option>
                    <?php foreach ($vs as $i => $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= $i === 0 ? 'selected' : '' ?>><?= h($v['plate_no'] . ' ' . $v['type'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select>
                <?php if (!$vs): ?><div class="mt-1 text-xs text-error">No car or van is available.</div><?php endif; ?>
            <?php endif; ?></div>
        <?php endforeach; ?>
        </div>
        <button class="btn btn-primary mt-4" type="submit"><i class="fas fa-floppy-disk"></i>Save</button>
    </form>
    <?php cardEnd(); endif; ?>
<?php else: ?>
<form method="post" data-outside-check novalidate id="assignForm"><input type="hidden" name="action" value="assign"><input type="hidden" name="week" value="<?= h($monday) ?>"></form>
<div class="card mb-5 border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table">
<thead><tr><th>Day</th><th>Vehicle</th><th>Driver</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach ($days as $d => $t): $passed = $tc->dayPassed($d); ?>
<tr>
    <td class="whitespace-nowrap font-medium"><?= h(date('l, d M', strtotime($d))) ?><?= isWeekend($d) ? ' <span class="badge badge-ghost badge-xs">weekend</span>' : '' ?></td>
    <td>
    <?php if ($editDay === $d && !$passed): $vs = $tc->welfareVehicles($d, $t ? (int)$t['task_id'] : 0); ?>
        <form method="post" class="flex gap-1"><input type="hidden" name="action" value="update"><input type="hidden" name="week" value="<?= h($monday) ?>"><input type="hidden" name="date" value="<?= h($d) ?>">
            <select class="select select-sm" name="vehicle_id" required aria-label="Vehicle"><?php foreach ($vs as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= $t && (int)$t['vehicle_id'] === (int)$v['vehicle_id'] ? 'selected' : '' ?>><?= h($v['plate_no'] . ' ' . $v['type']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm btn-primary" type="submit">Save</button><a class="btn btn-sm btn-ghost" href="<?= h($self) ?>">Cancel</a></form>
    <?php elseif ($t): ?><?= plate($t['plate_no']) ?> <span class="text-xs text-base-content/60"><?= h($t['vehicle_type']) ?></span>
    <?php else: ?><span class="text-base-content/50">No duty</span><?php endif; ?>
    </td>
    <td class="min-w-72">
    <?php if ($t && in_array($t['status'], [Task::WAITING, Task::REJECTED], true) && !$passed):
        $rec = $rs->recommendDrivers($t['licence_required'], $t['start_datetime'], $t['end_datetime'], ['except_task' => [(int)$t['task_id']]]); ?>
        <select class="select select-sm w-full" name="driver[<?= (int)$t['task_id'] ?>]" form="assignForm" aria-label="Driver for <?= h($d) ?>"><?= driverOptionsHtml($rec, (int)$t['driver_id']) ?></select>
        <?php if (!$rec['ranked']): ?><div class="text-xs text-error">No driver is available on that day.</div><?php endif; ?>
    <?php elseif ($t): ?><?= h($t['driver_name'] ?? '-') ?><?php endif; ?>
    </td>
    <td><?= $t ? taskStatusBadge($t['status']) : '' ?></td>
    <td class="text-end whitespace-nowrap"><?php if (!$passed && $editDay !== $d): ?>
        <a class="btn btn-sm btn-outline" href="<?= h($self) ?>&edit=<?= $d ?>"><i class="fas fa-pen"></i><?= $t ? 'Edit' : 'Add' ?></a>
        <?php if ($t): ?><form method="post" class="inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="week" value="<?= h($monday) ?>"><input type="hidden" name="task_id" value="<?= (int)$t['task_id'] ?>">
            <button class="btn btn-sm btn-outline btn-error" type="submit" aria-label="Delete" data-confirm="The duty on <?= h(date('l, d M', strtotime($d))) ?> is deleted and the vehicle reservation released<?= $t['driver_id'] ? '; the driver is notified' : '' ?>." data-confirm-title="Delete this duty?" data-confirm-button="Confirm" data-confirm-tone="danger"><i class="fas fa-trash"></i></button></form><?php endif; ?>
    <?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php if ($assignable): ?>
<div class="flex flex-wrap items-center gap-3"><button class="btn btn-primary" type="submit" form="assignForm"><i class="fas fa-paper-plane"></i>Submit to the Head of Admin</button>
<span class="text-sm text-base-content/65">Every day waiting for a driver needs a driver. Drivers are ranked per day by tasks this month, weekend tasks and experience; drivers with an approved schedule change are not offered.</span></div>
<?php endif; ?>
<?php endif; ?>
<?php adminPageEnd(); ?>
