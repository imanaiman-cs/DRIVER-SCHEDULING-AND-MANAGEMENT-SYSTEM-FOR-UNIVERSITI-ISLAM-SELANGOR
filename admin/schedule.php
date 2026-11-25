<?php
// ============================================================
// admin/schedule.php  -  View Layer: ScheduleUI (SDD_CLASS_109) displayCalendar() - UC010 View Driver Schedule (replaces the monthly driver schedule spreadsheet)
//   Week (default): every driver for the current week - request tasks, internal tasks and welfare duty with the vehicle
//   A1 Month: the whole month      Filter by date, driver or vehicle      Select a task -> task details
//   Needs replacement: approved tasks whose driver has an approved schedule change or is inactive (UC008)
// ============================================================
$current_page = 'schedule.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$view = in_array($_GET['view'] ?? '', ['month', 'replace'], true) ? $_GET['view'] : 'week';
$date = isValidDate((string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
$fDriver = (int)($_GET['driver_id'] ?? 0);
$fVehicle = (int)($_GET['vehicle_id'] ?? 0);
if ($view === 'month') { $from = date('Y-m-01', strtotime($date)); $to = date('Y-m-d', strtotime("$from +1 month")); }
else { $from = weekMonday($date); $to = date('Y-m-d', strtotime("$from +7 days")); }

$report = new ReportController();
$tasks = $report->getSchedule($from, $to, ['driver_id' => $fDriver, 'vehicle_id' => $fVehicle]);
$replace = (new AssignmentController())->listWaiting('Replacement');
$drivers = $report->driverList();
$vehicles = (new VehicleController())->listVehicles();
$qs = fn(array $o = []) => '?' . http_build_query(array_filter(array_merge(['view' => $view, 'date' => $date, 'driver_id' => $fDriver ?: '', 'vehicle_id' => $fVehicle ?: ''], $o), fn($x) => $x !== '' && $x !== null));
$colour = fn(array $t) => match ($t['task_source']) { 'Welfare Duty' => 'border-warning bg-warning/10', 'Internal' => 'border-info bg-info/10', default => 'border-primary bg-primary/10' };
$chip = function (array $t) use ($colour) {
    return '<a href="task.php?id=' . (int)$t['task_id'] . '" class="mb-1 block rounded border-s-4 px-1.5 py-1 text-xs leading-tight hover:brightness-95 ' . $colour($t) . ($t['status'] === 'Driver Assigned' || $t['status'] === 'Waiting for Driver' ? ' border-dashed opacity-80' : '') . '">'
         . '<span class="font-semibold">' . ($t['task_source'] === 'Welfare Duty' ? '24 h' : h(date('H:i', strtotime($t['start_datetime'])))) . '</span> ' . h(taskTypeLabel($t)) . '<br>' . h($t['plate_no'] ?? '') . ($t['destination'] ? ' &middot; ' . h(mb_strimwidth($t['destination'], 0, 22, '…')) : '') . '</a>';
};

adminPageStart('Driver Schedule');
pageHeader('Driver Schedule', 'calendar-days', 'Request tasks, internal tasks and welfare duty of every driver, with the vehicle of each task.');
echo tabsNav(['week' => 'Week', 'month' => 'Month', 'replace' => 'Needs replacement'], $view, fn($k) => $qs(['view' => $k]), ['replace' => count($replace)]);

if ($view === 'replace'):
?>
<p class="mb-3 text-sm text-base-content/65">The driver of these approved tasks has an approved schedule change on that date, asked to be removed, or is no longer active. Replace the driver; the Head of Admin approves the replacement.</p>
<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Date and time</th><th>Task</th><th>Vehicle</th><th>Driver</th><th>Why</th><th></th></tr></thead><tbody>
<?php foreach ($replace as $t): ?>
<tr><?= taskRowCells($t) ?><td><?= h($t['driver_name']) ?></td><td class="text-sm"><?= $t['driver_status'] !== 'Active' ? 'The driver account is inactive.' : 'Schedule change: ' . h($t['change_reason']) ?></td>
    <td class="text-end"><a class="btn btn-sm btn-primary" href="replace_driver.php?task=<?= (int)$t['task_id'] ?>">Replace Driver</a></td></tr>
<?php endforeach; ?>
<?php if (!$replace): ?><?= emptyRow(6, 'No task needs a new driver.') ?><?php endif; ?>
</tbody></table></div></div>
<?php else: ?>
<form method="get" class="mb-4 flex flex-wrap items-end gap-2">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <a class="btn btn-sm btn-outline" href="<?= h($qs(['date' => date('Y-m-d', strtotime($view === 'month' ? "$from -1 month" : "$from -7 days"))])) ?>" aria-label="Previous"><i class="fas fa-chevron-left"></i></a>
    <div><label class="mb-1 block text-xs font-medium" for="date">Date</label><input type="date" class="input input-sm" id="date" name="date" value="<?= h($date) ?>"></div>
    <a class="btn btn-sm btn-outline" href="<?= h($qs(['date' => date('Y-m-d', strtotime($view === 'month' ? "$from +1 month" : "$from +7 days"))])) ?>" aria-label="Next"><i class="fas fa-chevron-right"></i></a>
    <div><label class="mb-1 block text-xs font-medium" for="driver_id">Driver</label><select class="select select-sm" id="driver_id" name="driver_id"><option value="">All drivers</option>
        <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['driver_id'] ?>" <?= $fDriver === (int)$d['driver_id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="mb-1 block text-xs font-medium" for="vehicle_id">Vehicle</label><select class="select select-sm" id="vehicle_id" name="vehicle_id"><option value="">All vehicles</option>
        <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= $fVehicle === (int)$v['vehicle_id'] ? 'selected' : '' ?>><?= h($v['plate_no'] . ' (' . $v['type'] . ')') ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter"></i>Filter</button><a class="btn btn-sm btn-ghost" href="?view=<?= h($view) ?>">Today</a>
</form>
<p class="mb-3 text-sm font-semibold"><?= h(date('d M Y', strtotime($from))) ?> - <?= h(date('d M Y', strtotime("$to -1 day"))) ?>
    <span class="ms-3 text-xs font-normal"><span class="me-1 inline-block size-2.5 rounded-sm bg-primary"></span>Request task <span class="ms-2 me-1 inline-block size-2.5 rounded-sm bg-info"></span>Internal task <span class="ms-2 me-1 inline-block size-2.5 rounded-sm bg-warning"></span>Welfare duty &middot; dashed = waiting for approval</span></p>
<?php if (!$tasks): ?><div class="dash-empty"><i class="fas fa-inbox"></i>No record is found.</div><?php endif; ?>
<?php if ($tasks && $view === 'week'):
    $byDriver = [];
    foreach ($tasks as $t) { $byDriver[$t['driver_id'] ? (int)$t['driver_id'] : 0][substr($t['start_datetime'], 0, 10)][] = $t; }
    $names = array_column($drivers, 'name', 'driver_id');
    uksort($byDriver, fn($a, $b) => strcmp($names[$a] ?? 'zzz', $names[$b] ?? 'zzz'));
?>
<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table table-sm table-fixed min-w-[960px]">
<thead><tr><th class="w-40">Driver</th><?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("$from +$i day")); ?><th class="<?= $d === date('Y-m-d') ? 'bg-primary/10' : '' ?>"><?= h(date('D d M', strtotime($d))) ?></th><?php endfor; ?></tr></thead><tbody>
<?php foreach ($byDriver as $did => $days): ?>
<tr class="align-top"><td class="font-medium"><?= $did ? h($names[$did] ?? '-') : '<span class="text-warning">Waiting for driver</span>' ?></td>
<?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("$from +$i day")); ?><td><?php foreach ($days[$d] ?? [] as $t) { echo $chip($t); } ?></td><?php endfor; ?></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php elseif ($tasks): ?>
<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>Date and time</th><th>Task</th><th>Vehicle</th><th>Driver</th><th>Destination</th><th>Status</th></tr></thead><tbody>
<?php $last = ''; foreach ($tasks as $t): $d = substr($t['start_datetime'], 0, 10); if ($d !== $last): $last = $d; ?>
<tr class="bg-base-200"><td colspan="6" class="font-semibold"><?= h(date('l, d M Y', strtotime($d))) ?></td></tr><?php endif; ?>
<tr class="cursor-pointer hover:bg-base-200/60" onclick="location.href='task.php?id=<?= (int)$t['task_id'] ?>'"><?= taskRowCells($t) ?><td><?= h($t['driver_name'] ?? '-') ?></td><td><?= h($t['destination'] ?? $t['description'] ?? '') ?></td><td><?= taskStatusBadge($t['status']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php endif; ?>
<?php adminPageEnd(); ?>
