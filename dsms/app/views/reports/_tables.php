<?php
/** The report tables, shared by the screen page and the PDF. Needs $report. */
$t = $report['totals'];
?>
<h2 class="rep-h">1. Summary</h2>
<table class="table table-sm rep-table">
    <tr><th>Trips confirmed / completed</th><td><?= (int)$t['trips'] ?></td><th>Passengers carried</th><td><?= (int)$t['passengers'] ?></td></tr>
    <tr><th>Vehicle-days used</th><td><?= (int)$t['vehicle_days'] ?></td><th>Self-drive trips</th><td><?= (int)$t['self_drive'] ?></td></tr>
    <tr><th>All requests this month</th><td><?= (int)$report['total_requests'] ?></td><th>Driver changes</th><td><?= (int)$report['driver_changes'] ?></td></tr>
</table>
<table class="table table-sm rep-table">
    <thead><tr><?php foreach ($report['statuses'] as $s => $n): ?><th><?= e($s) ?></th><?php endforeach; ?></tr></thead>
    <tbody><tr><?php foreach ($report['statuses'] as $n): ?><td><?= (int)$n ?></td><?php endforeach; ?></tr></tbody>
</table>

<h2 class="rep-h">2. Vehicle use</h2>
<table class="table table-sm rep-table">
    <thead><tr><th>Reg. no</th><th>Vehicle</th><th>Type</th><th>Trips</th><th>Days used</th><th>Passengers</th></tr></thead>
    <tbody>
    <?php foreach ($report['vehicles'] as $v): ?>
        <tr><td><?= e($v['reg_no']) ?></td><td><?= e($v['name']) ?></td><td><?= e($v['vehicle_type']) ?></td><td><?= (int)$v['trips'] ?></td><td><?= (int)$v['days_used'] ?></td><td><?= (int)$v['passengers'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2 class="rep-h">3. Driver workload</h2>
<p class="rep-note">Tasks = trip legs + internal tasks (same counting as the driver recommendation). After-hours = Saturday/Sunday, early start or late finish.</p>
<table class="table table-sm rep-table">
    <thead><tr><th>Driver</th><th>Group</th><th>Trip legs</th><th>Internal tasks</th><th>Total tasks</th><th>After-hours</th><th>Welfare days</th></tr></thead>
    <tbody>
    <?php foreach ($report['drivers'] as $d): ?>
        <tr><td><?= e($d['name']) ?></td><td><?= e(ucfirst($d['driver_group'])) ?></td><td><?= (int)$d['trip_legs'] ?></td><td><?= (int)$d['internal_tasks'] ?></td>
            <td><strong><?= (int)$d['tasks'] ?></strong></td><td><?= (int)$d['after_hours'] ?></td><td><?= (int)$d['welfare_days'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2 class="rep-h">4. Requests by department</h2>
<table class="table table-sm rep-table">
    <thead><tr><th>Department</th><th>Requests</th><th>Trips made</th><th>Passengers</th></tr></thead>
    <tbody>
    <?php foreach ($report['ptj'] as $p): ?>
        <tr><td><?= e($p['name']) ?></td><td><?= (int)$p['requests'] ?></td><td><?= (int)$p['trips'] ?></td><td><?= (int)$p['passengers'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$report['ptj']): ?><tr><td colspan="4">No requests this month.</td></tr><?php endif; ?>
    </tbody>
</table>

<h2 class="rep-h">5. All requests this month</h2>
<table class="table table-sm rep-table">
    <thead><tr><th>No.</th><th>Requester</th><th>Destination</th><th>Vehicle</th><th>Departure</th><th>Return</th><th>Driver(s)</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($report['requests'] as $r): ?>
        <tr><td>#<?= (int)$r['id'] ?></td><td><?= e($r['requester_name']) ?></td><td><?= e($r['destination_name']) ?></td><td><?= e($r['reg_no']) ?></td>
            <td><?= e(fmt_datetime($r['departure_datetime'])) ?></td><td><?= e(fmt_datetime($r['return_datetime'])) ?></td>
            <td><?= e($r['is_self_drive'] ? 'Self-drive' : ($r['drivers'] ?? '-')) ?></td><td><?= e($r['status']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$report['requests']): ?><tr><td colspan="8">No requests this month.</td></tr><?php endif; ?>
    </tbody>
</table>
