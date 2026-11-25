<?php
// ============================================================
// admin/task.php  -  View Layer: ScheduleUI (SDD_CLASS_109) displayTaskDetails() - UC010 step 3
// Any task: details, report, replacements, and Replace Driver (UC008).
// ============================================================
$current_page = 'task.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$tc = new TaskController();
$ac = new AssignmentController();
$t = $tc->getTask((int)($_GET['id'] ?? 0));
if (!$t) { setFlash('danger', 'No record is found.'); header('Location: schedule.php'); exit(); }
$id = (int)$t['task_id'];
$report = $tc->getReport($id);
$repls = $tc->getReplacements($id);
$pending = array_values(array_filter($repls, fn($x) => $x['status'] === 'Pending'));
$actions = '<a class="btn btn-ghost" href="schedule.php?date=' . substr($t['start_datetime'], 0, 10) . '"><i class="fas fa-arrow-left"></i>Schedule</a>';
if ($t['request_id']) { $actions .= '<a class="btn btn-outline" href="../staff/request.php?id=' . (int)$t['request_id'] . '">Request #' . (int)$t['request_id'] . '</a>'; }
if ($t['task_source'] === Task::WELFARE) { $actions .= '<a class="btn btn-outline" href="welfare.php?week=' . h($t['week_start_date']) . '">Welfare week</a>'; }
if ($t['task_source'] === Task::INTERNAL && in_array($t['status'], [Task::WAITING, Task::REJECTED], true)) { $actions .= '<a class="btn btn-primary" href="internal_tasks.php?assign=' . $id . '">Assign Driver</a>'; }
if ($ac->canReplace($t)) { $actions .= '<a class="btn btn-primary" href="replace_driver.php?task=' . $id . '"><i class="fas fa-user-pen"></i>Replace Driver</a>'; }
adminPageStart('Task');
pageHeader(taskTypeLabel($t), 'route', fmtPeriod($t['start_datetime'], $t['end_datetime']), $actions);
if ($pending) { echo '<div class="alert alert-soft alert-info mb-5"><i class="fas fa-hourglass-half"></i><span>Replacement by ' . h($pending[0]['new_driver_name']) . ' is waiting for the Head of Admin.</span></div>'; }
?>
<div class="grid grid-cols-12 gap-5">
<div class="col-span-12 lg:col-span-6">
<?php cardStart('Task details', 'circle-info'); ?>
<dl>
    <?= dlRow('Status', taskStatusBadge($t['status'])) ?>
    <?= dlRow('Date and time', h(fmtPeriod($t['start_datetime'], $t['end_datetime']))) ?>
    <?= dlRow('Vehicle', $t['vendor_name'] ? plate($t['vendor_plate_no']) . ' ' . h($t['vendor_name']) . ' (vendor)' : plate($t['plate_no']) . ' ' . h(($t['brand'] ?? '') . ' ' . ($t['model'] ?? ''))) ?>
    <?= dlRow('Driver', h($t['driver_name'] ?? '-') . ($t['driver_phone'] ? ' &middot; ' . h($t['driver_phone']) : '')) ?>
    <?php if ($t['task_source'] === Task::REQUEST): ?>
        <?= dlRow('Destination', h($t['destination']) . '<div class="text-xs">' . h($t['address']) . '</div>') ?>
        <?= dlRow('Waiting location', h($t['waiting_location'])) ?>
        <?= dlRow('Officer', h($t['officer_name']) . ' ' . h($t['officer_phone'] ?? '')) ?>
    <?php else: ?><?= dlRow('Description', h($t['description'] ?: '-')) ?><?php endif; ?>
    <?php if ($t['reject_reason']): ?><?= dlRow('Returned by the Head of Admin', '<span class="text-warning">' . h($t['reject_reason']) . '</span> <span class="text-xs">' . h(fmtDT($t['rejected_date'])) . '</span>') ?><?php endif; ?>
    <?php if ($t['approved_date'] && $t['status'] !== Task::REJECTED): ?><?= dlRow('Approved', h(fmtDT($t['approved_date']))) ?><?php endif; ?>
</dl>
<?php cardEnd(); ?>
</div>
<div class="col-span-12 lg:col-span-6">
<?php cardStart('Task report', 'clipboard-check'); ?>
<?php if (!$report): ?><p class="text-sm text-base-content/60">No report yet.</p><?php else: ?>
<dl>
    <?= dlRow('Actual times', h(fmtPeriod($report['actual_depart'], $report['actual_return']))) ?>
    <?= dlRow('End mileage', number_format((int)$report['end_mileage']) . ' km') ?>
    <?= dlRow('Fuel level', h($report['fuel_level'])) ?>
    <?= dlRow('Condition', $report['vehicle_condition'] === 'Good' ? '<span class="badge badge-success">Good</span>' : '<span class="badge badge-error">Problem: ' . h($report['problem_type']) . '</span> ' . h($report['problem_desc']) . '<div class="text-xs">' . ($report['can_be_used'] ? 'Can still be used' : 'Cannot be used (set Under Maintenance)') . '</div>') ?>
    <?php if ($report['photo']): ?><?= dlRow('Photo', '<a target="_blank" rel="noopener" href="' . h(SITE_URL . '/' . $report['photo']) . '"><img class="max-h-40 rounded" src="' . h(SITE_URL . '/' . $report['photo']) . '" alt="Photo of the problem"></a>') ?><?php endif; ?>
    <?= dlRow('Submitted', h(fmtDT($report['submitted_date']))) ?>
</dl>
<?php endif; cardEnd(); ?>
<?php if ($repls): cardStart('Driver replacements', 'user-pen'); foreach ($repls as $rp): ?>
<div class="border-b border-base-200 py-2 text-sm last:border-0"><?= h($rp['old_driver_name']) ?> &rarr; <strong><?= h($rp['new_driver_name']) ?></strong> <?= decisionBadge($rp['status']) ?>
<div class="text-xs text-base-content/65"><?= h($rp['reason']) ?> &middot; <?= h(fmtDT($rp['date_time'])) ?><?= $rp['approved_by_name'] ? ' &middot; ' . h($rp['approved_by_name']) : '' ?></div></div>
<?php endforeach; cardEnd(); endif; ?>
</div>
</div>
<?php adminPageEnd(); ?>
