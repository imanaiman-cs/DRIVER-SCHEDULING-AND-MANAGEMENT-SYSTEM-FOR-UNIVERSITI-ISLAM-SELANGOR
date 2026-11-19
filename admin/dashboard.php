<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/dashboard.php  -  Administrative Assistant / Head of Admin: what needs attention, trends, today, fleet and drivers
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/dashboard_ui.php';
requireAdmin();

$thisMonth = date('Y-m');
$lastMonth = date('Y-m', strtotime('first day of last month'));
$reqNow = dashRequestsInMonth($conn, $thisMonth);
$reqBefore = dashRequestsInMonth($conn, $lastMonth);
$badNow = dashRequestsInMonth($conn, $thisMonth, ['rejected', 'lapsed']);
$badBefore = dashRequestsInMonth($conn, $lastMonth, ['rejected', 'lapsed']);

$status = [];
foreach (dashRows($conn, 'SELECT status, COUNT(*) AS n FROM vehicle_requests GROUP BY status') as $r) { $status[$r['status']] = (int)$r['n']; }
$needDriver = dashQueue($conn, 'approved');
$waitingHead = dashQueue($conn, 'assigned');
$stalePending = dashQueue($conn, 'pending', 5, 24);
$fleet = dashFleet($conn);
$alerts = dashAlerts($conn);
$welfare = dashWelfareToday($conn);
$workload = array_slice(dashDriverWorkload($conn), 0, 10);
$util = dashVehicleUtilisation($conn);
$monthly = dashMonthlyRequests($conn, 6);
$byStatus = dashStatusCounts($conn, 6);
$activeDrivers = (int)dashValue($conn, "SELECT COUNT(*) FROM drivers WHERE status = 'active'");
$crew = array_column(dashRows($conn, "SELECT name FROM drivers WHERE status = 'active' ORDER BY name LIMIT 4"), 'name');
$today = dashToday($conn);
$waitingTotal = count($needDriver) + count($waitingHead) + ($status['pending'] ?? 0);

$statusColour = ['pending' => '#d4a017', 'approved' => '#13a86a', 'assigned' => '#1d4ed8', 'processed' => '#0b5d3b',
                 'rejected' => '#b91c1c', 'cancelled' => '#94a3b8', 'lapsed' => '#ea580c', 'completed' => '#64748b'];
$doLabels = []; $doData = []; $doColours = [];
foreach ($statusColour as $k => $col) { if (!empty($byStatus[$k])) { $doLabels[] = requestStatusLabel($k); $doData[] = $byStatus[$k]; $doColours[] = $col; } }

$queueLink = isHeadAdmin() ? 'approve_assignments.php' : 'vehicle_requests.php?tab=assigned';
adminPageStart('Dashboard');
$trend = dashTrend($reqNow, $reqBefore);
$badTrend = dashTrend($badNow, $badBefore);
?>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4" data-dashboard>
        <div>
            <h1><?= dashGreeting() ?>, <?= dh(explode(' bin ', explode(' binti ', $_SESSION['full_name'] ?? '')[0])[0]) ?></h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span id="liveStamp" class="me-1 inline-flex items-center gap-1.5 text-xs text-base-content/65" title="The figures, queues, alerts and board refresh every minute"><span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>Live &middot; updated <?= date('H:i') ?></span>
            <a class="btn" href="calendar.php"><i class="fas fa-calendar-days"></i>Calendar</a>
            <a class="btn btn-neutral" href="vehicle_requests.php?tab=approved"><i class="fas fa-plus"></i>Assign drivers<?php if ($needDriver): ?> &middot; <?= count($needDriver) ?><?php endif; ?></a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5">
        <!-- Left: the month as a pass card, and the fleet -->
        <div class="col-span-12 flex flex-col gap-5 md:col-span-6 xl:col-span-3">
            <section class="e-card" id="live-hero" data-live>
                <div class="e-head"><h2>Requests this month</h2><a href="vehicle_requests.php" class="e-icon-btn" aria-label="Open vehicle requests"><i class="fas fa-arrow-right"></i></a></div>
                <div class="e-accent">
                    <div class="flex items-center justify-between text-xs"><span class="font-bold tracking-[0.18em]">UIS &middot; TRANSPORT</span><i class="fas fa-route" aria-hidden="true"></i></div>
                    <div class="mt-5 text-5xl leading-none font-bold tabular-nums"><?= (int)$reqNow ?></div>
                    <div class="mt-1 text-xs text-white/85">requests &middot; <?= date('M Y') ?></div>
                    <div class="mt-5 flex justify-between gap-2 text-[0.7rem] text-white/85">
                        <a class="hover:underline" href="vehicle_requests.php?tab=processed">Confirmed <?= (int)($status['processed'] ?? 0) ?></a>
                        <a class="hover:underline" href="vehicle_requests.php">Rejected <?= (int)$badNow ?></a>
                        <a class="hover:underline" href="vehicles.php">Free <?= (int)$fleet['available'] ?></a>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-between gap-2">
                    <?php if ($trend): ?>
                        <div><div class="text-lg font-bold tabular-nums"><?= $trend['pct'] === null ? 'New' : ($trend['pct'] > 0 ? '+' : '') . $trend['pct'] . '%' ?></div><div class="text-xs text-base-content/65"><?= $trend['pct'] === null ? 'none last month' : 'vs last month' ?></div></div>
                        <span class="e-chip <?= $trend['dir'] === 'down' ? 'e-chip-down' : '' ?>"><i class="fas fa-arrow-trend-<?= $trend['dir'] === 'down' ? 'down' : 'up' ?>"></i><?= $trend['dir'] === 'down' ? 'falling' : 'rising' ?></span>
                    <?php else: ?>
                        <div class="text-xs text-base-content/65">No requests this month or last month.</div>
                    <?php endif; ?>
                </div>
                <a href="drivers.php" class="mt-4 flex items-center gap-2 rounded-full bg-base-200 py-1 ps-1 pe-3 text-xs" title="Active drivers">
                    <span class="flex -space-x-2"><?php foreach ($crew as $i => $n): ?><span class="e-avatar e-avatar-<?= $i ?>"><?= dh(dashInitials($n)) ?></span><?php endforeach; ?></span>
                    <span class="font-semibold"><?= $activeDrivers ?> drivers</span>
                </a>
            </section>

            <section class="e-card" id="live-fleet" data-live>
                <div class="e-head"><h2>Fleet now</h2><span class="text-xs text-base-content/65"><?= array_sum($fleet) ?> vehicles</span></div>
                <?php $totalV = max(1, array_sum($fleet)); ?>
                <ul class="space-y-3 text-sm">
                    <?php foreach (['available' => 'bg-primary', 'in_use' => 'bg-info', 'maintenance' => 'bg-secondary', 'inactive' => 'bg-base-content/30'] as $st => $col): ?>
                    <li><div class="flex justify-between"><span><?= dh(vehicleStatusLabel($st)) ?></span><b class="tabular-nums"><?= (int)$fleet[$st] ?></b></div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-base-200"><div class="h-2 rounded-full <?= $col ?>" style="width:<?= round($fleet[$st] / $totalV * 100) ?>%"></div></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>

        <!-- Middle: the trend, and what is on its way -->
        <section class="e-card col-span-12 flex flex-col md:col-span-6 xl:col-span-6">
            <div class="e-head"><h2><i class="fas fa-chart-column me-2 text-base-content/60" aria-hidden="true"></i>Requests per month</h2><span class="text-xs text-base-content/65">last 6 months</span></div>
            <?php $mMax = max(1, max($monthly)); $mKeys = array_keys($monthly); $mLast = end($mKeys); ?>
            <div id="chMonthly" class="flex min-h-64 flex-1 items-end gap-3 border-b border-dashed border-base-300 pb-2 sm:gap-5" role="img"
                 aria-label="Requests per month: <?= dh(implode(', ', array_map(fn($k, $v) => "$k $v", $mKeys, $monthly))) ?>">
                <?php foreach ($monthly as $label => $n): $isNow = $label === $mLast; $h = max(4, round($n / $mMax * 100)); ?>
                <div class="flex h-full flex-1 flex-col items-center justify-end gap-2">
                    <?php if ($isNow): ?><span class="rounded-full bg-[var(--uis-ink)] px-2 py-0.5 text-[0.7rem] font-semibold whitespace-nowrap text-[var(--uis-ink-content)]"><?= $n ?><?= $trend && $trend['pct'] !== null ? ' &middot; ' . ($trend['pct'] > 0 ? '+' : '') . $trend['pct'] . '%' : '' ?></span><?php else: ?><span class="text-[0.7rem] text-base-content/65 tabular-nums"><?= $n ?></span><?php endif; ?>
                    <div class="w-full max-w-16 rounded-full <?= $isNow ? 'bg-primary' : 'e-stripe' ?>" style="height:<?= $h ?>%"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-2 flex gap-3 sm:gap-5" aria-hidden="true">
                <?php foreach ($mKeys as $label): ?><span class="flex-1 text-center text-xs <?= $label === $mLast ? 'font-semibold' : 'text-base-content/65' ?>"><?= dh(explode(' ', $label)[0]) ?></span><?php endforeach; ?>
            </div>
            <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3" id="live-progress" data-live>
                <a class="e-tile" href="vehicle_requests.php?tab=approved"><span class="e-tile-label">Need a driver</span><span class="font-medium tabular-nums"><?= count($needDriver) ?></span></a>
                <a class="e-tile" href="<?= dh($queueLink) ?>"><span class="e-tile-label">Waiting for the Head of Admin</span><span class="font-medium tabular-nums"><?= count($waitingHead) ?></span></a>
                <a class="e-tile" href="reminders.php"><span class="e-tile-label">Pending with Heads of Department</span><span class="font-medium tabular-nums"><?= (int)($status['pending'] ?? 0) ?></span></a>
            </div>
            <p class="mt-3 text-xs text-base-content/65"><?= count($stalePending) ?> pending over 24 hours</p>
        </section>

        <!-- Right: today, welfare duty, alerts -->
        <div class="col-span-12 flex flex-col gap-5 xl:col-span-3">
            <section class="e-card">
                <div class="e-head"><div><h2 id="dayTitle">Today</h2><p class="text-xs text-base-content/65" id="daySub"><?= date('l, j F Y') ?></p></div><a href="calendar.php" class="e-icon-btn" aria-label="Open calendar"><i class="fas fa-calendar-days"></i></a></div>
                <div class="grid grid-cols-7 gap-1" role="group" aria-label="Pick a day this week">
                    <?php $mon = strtotime('monday this week'); for ($i = 0; $i < 7; $i++): $d = strtotime("+$i day", $mon); $isToday = date('Y-m-d', $d) === date('Y-m-d'); ?>
                    <button type="button" class="e-day <?= $isToday ? 'is-today' : '' ?>" data-day="<?= date('Y-m-d', $d) ?>" data-label="<?= date('l, j F Y', $d) ?>" aria-pressed="<?= $isToday ? 'true' : 'false' ?>"><span><?= date('D', $d) ?></span><b><?= date('j', $d) ?></b></button>
                    <?php endfor; ?>
                </div>
                <div class="mt-3 space-y-0.5" id="dayList" aria-live="polite">
                    <?php if (!$today): ?><?= dashEmpty('No trips or internal tasks today.', 'sun') ?><?php endif; ?>
                    <?php foreach (array_slice($today, 0, 6) as $t): ?>
                    <div class="flex items-start gap-3 rounded-xl px-1.5 py-2 hover:bg-base-200">
                        <span class="w-10 shrink-0 pt-0.5 text-xs font-semibold text-base-content/65 tabular-nums"><?= date('H:i', strtotime($t['start'])) ?></span>
                        <span class="min-w-0 grow text-sm"><span class="block truncate font-semibold"><?= dh($t['title']) ?></span>
                            <span class="block truncate text-xs text-base-content/65"><?= dh($t['who']) ?></span></span>
                        <?= plate($t['vehicle']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="e-card">
                <div class="e-head mb-3"><h2>Welfare duty today</h2><a href="welfare.php" class="e-icon-btn" aria-label="Open welfare duty"><i class="fas fa-arrow-right"></i></a></div>
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-secondary/20 text-sm font-bold"><?= dh($welfare ? dashInitials($welfare) : '—') ?></span>
                    <div class="min-w-0"><div class="truncate text-sm font-semibold"><?= dh($welfare ?? 'not set') ?></div><div class="text-xs text-base-content/65">24 hours &middot; on call</div></div>
                </div>
            </section>

            <section class="e-card" id="live-alerts" data-live>
                <div class="e-head"><h2>Alerts</h2><?php if ($alerts): ?><span class="badge badge-error badge-sm"><?= count($alerts) ?></span><?php endif; ?></div>
                <?php if (!$alerts): ?><?= dashEmpty('All clear. No alerts.', 'shield-heart') ?><?php endif; ?>
                <?php foreach ($alerts as $a): ?>
                    <a class="dash-alert dash-alert-<?= dh($a['tone']) ?>" href="<?= dh($a['href']) ?>"><i class="fas fa-<?= dh($a['icon']) ?>"></i><span><?= dh($a['text']) ?></span></a>
                <?php endforeach; ?>
            </section>
        </div>

        <!-- Needs your action: one table, the action on each row -->
        <section class="e-card col-span-12" id="live-action" data-live>
            <div class="e-head"><h2>Needs your action</h2><a href="vehicle_requests.php" class="btn btn-sm">See all</a></div>
            <?php $rows = []; foreach ([[$needDriver, 'success', 'Need a driver'], [$waitingHead, 'info', 'Waiting for the Head of Admin'], [$stalePending, 'warning', 'Pending over 24 hours']] as [$list, $tone, $label]) { foreach ($list as $r) { $rows[] = [$r, $tone, $label]; } } ?>
            <?php if (!$rows): ?><?= dashEmpty('Nothing is waiting. Well done.', 'circle-check') ?><?php else: ?>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Request</th><th>Destination</th><th>Staff</th><th>Trip</th><th>Waiting</th><th>Status</th><th><span class="sr-only">Action</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as [$r, $tone, $label]): $rid = (int)$r['request_id']; $href = $tone === 'success' ? 'vehicle_requests.php?tab=approved' : ($tone === 'info' ? $queueLink : 'reminders.php'); ?>
                        <tr>
                            <td><a class="hover:underline" href="<?= dh($href) ?>"><strong>#<?= $rid ?></strong></a></td>
                            <td class="min-w-48"><?= dh($r['destination']) ?></td>
                            <td><?= dh($r['staff_name']) ?></td>
                            <td class="whitespace-nowrap"><?= requestPeriodText($r) ?></td>
                            <td class="whitespace-nowrap"><?= dashWaiting($r['waiting_h']) ?></td>
                            <td><span class="e-status text-<?= $tone === 'success' ? 'primary' : ($tone === 'info' ? 'info' : 'warning') ?>"><?= dh($label) ?></span></td>
                            <td class="text-right">
                                <?php if ($tone === 'success'): ?>
                                    <a class="btn btn-sm btn-primary" href="assign_driver.php?request_id=<?= $rid ?>" aria-label="Assign a driver to request #<?= $rid ?>">Assign</a>
                                <?php elseif ($tone === 'info'): ?>
                                    <a class="btn btn-sm" href="<?= dh($queueLink) ?>" aria-label="Review request #<?= $rid ?>">Review</a>
                                <?php else: ?>
                                    <form method="post" action="reminders.php"><input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return" value="dashboard">
                                        <button class="btn btn-sm" type="submit" data-loading-text="Sending…" aria-label="Send a reminder for request #<?= $rid ?>">Remind</button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <!-- Vehicles today -->
        <section class="e-card col-span-12" id="live-board" data-live>
            <div class="e-head"><div><h2>Vehicles today</h2><p class="text-xs text-base-content/65">Every vehicle across the working day</p></div><a href="calendar.php" class="btn btn-sm">Open calendar</a></div>
            <?= dashBoard($conn) ?>
        </section>

        <section class="e-card col-span-12 lg:col-span-4">
            <div class="e-head"><h2>Requests by status</h2><span class="text-xs text-base-content/65">last 6 months</span></div>
            <?= $doData ? dashChart('chStatus', 'bar', $doLabels, [['label' => 'Requests', 'data' => $doData, 'colors' => $doColours]], ['horizontal' => true, 'height' => 260]) : dashEmpty('No requests yet.', 'inbox') ?>
        </section>
        <section class="e-card col-span-12 lg:col-span-4">
            <div class="e-head"><h2>Driver workload</h2><span class="text-xs text-base-content/65">this month</span></div>
            <?= $workload ? dashChart('chDrivers', 'bar', array_column($workload, 'name'), [
                ['label' => 'In working hours', 'data' => array_map(fn($w) => $w['tasks'] - $w['outside'], $workload), 'color' => '#0b5d3b'],
                ['label' => 'Outside working hours', 'data' => array_column($workload, 'outside'), 'color' => '#d4a017']], ['stacked' => true, 'horizontal' => true, 'height' => 300]) : dashEmpty('No active drivers.', 'user-slash') ?>
            <p class="mt-2 text-xs text-base-content/65">The same counts the driver recommendation uses (welfare days count as tasks).</p>
        </section>
        <section class="e-card col-span-12 lg:col-span-4">
            <div class="e-head"><h2>Vehicle booked hours</h2><span class="text-xs text-base-content/65">this month</span></div>
            <?= array_sum(array_column($util, 'hours')) > 0 ? dashChart('chVehicles', 'bar', array_column($util, 'plate_number'), [['label' => 'Booked hours', 'data' => array_map('floatval', array_column($util, 'hours')), 'color' => '#13a86a']], ['horizontal' => true, 'height' => 300]) : dashEmpty('No vehicle is booked this month yet.', 'car-side') ?>
        </section>
    </div>
    <?php dashChartScript(); ?>
<script>
(function () {
    'use strict';
    var todayKey = <?= json_encode(date('Y-m-d')) ?>, picked = todayKey;
    var list = document.getElementById('dayList'), title = document.getElementById('dayTitle'), sub = document.getElementById('daySub');
    var todayHtml = list.innerHTML;
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function hm(iso) { return iso.substr(11, 5); }

    // ── Today: pick any day of this week ──
    function showDay(btn) {
        document.querySelectorAll('[data-day]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        picked = btn.dataset.day;
        title.textContent = picked === todayKey ? 'Today' : btn.dataset.label.split(',')[0];
        sub.textContent = btn.dataset.label;
        if (picked === todayKey) { list.innerHTML = todayHtml; return; }
        list.setAttribute('aria-busy', 'true');
        list.innerHTML = '<p class="dash-empty"><span class="loading loading-spinner loading-sm"></span></p>';
        var next = new Date(picked + 'T00:00:00'); next.setDate(next.getDate() + 1);
        var end = next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0') + '-' + String(next.getDate()).padStart(2, '0');
        fetch('../ajax/calendar_events.php?start=' + picked + '&end=' + end, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.success) { throw new Error(j.message); }
                var ev = j.events.filter(function (e) { return e.start.substr(0, 10) <= picked && e.end.substr(0, 10) >= picked; });
                ev.sort(function (a, b) { return (a.kind === 'welfare' ? -1 : 0) - (b.kind === 'welfare' ? -1 : 0) || (a.start < b.start ? -1 : 1); });
                if (!ev.length) { list.innerHTML = '<p class="dash-empty">Nothing scheduled on this day.</p>'; return; }
                list.innerHTML = ev.slice(0, 8).map(function (e) {
                    var plateNo = (e.vehicle || '').split(' (')[0];
                    var name = e.kind === 'trip' && e.destination ? e.destination : e.title;
                    return '<div class="flex items-start gap-3 rounded-xl px-1.5 py-2 hover:bg-base-200"><span class="w-10 shrink-0 pt-0.5 text-xs font-semibold text-base-content/65 tabular-nums">' + esc(e.kind === 'welfare' ? 'All day' : hm(e.start)) + '</span>' +
                        '<span class="min-w-0 grow text-sm"><span class="block truncate font-semibold">' + esc(name) + '</span><span class="block truncate text-xs text-base-content/65">' + esc(e.driver) + '</span></span>' +
                        (plateNo ? '<span class="plate">' + esc(plateNo) + '</span>' : '') + '</div>';
                }).join('') + (ev.length > 8 ? '<a class="link link-primary block px-2 text-sm" href="calendar.php">+' + (ev.length - 8) + ' more in the calendar</a>' : '');
            })
            .catch(function () { list.innerHTML = '<p class="dash-empty">Could not load this day. <a class="link link-primary" href="calendar.php">Open the calendar</a></p>'; })
            .finally(function () { list.removeAttribute('aria-busy'); });
    }
    document.querySelectorAll('[data-day]').forEach(function (b) { b.addEventListener('click', function () { showDay(b); }); });

    // ── Live: refresh the figures, queues, alerts and board every minute (paused when the tab is hidden) ──
    var stamp = document.getElementById('liveStamp'), last = Date.now(), busy = false;
    function refresh() {
        if (busy || document.hidden) { return; }
        busy = true;
        fetch(window.location.href, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                document.querySelectorAll('[data-live]').forEach(function (el) {
                    if (el.contains(document.activeElement)) { return; }          // never pull a control from under the user
                    var fresh = doc.getElementById(el.id);
                    if (fresh && fresh.innerHTML !== el.innerHTML) { el.innerHTML = fresh.innerHTML; }
                });
                var t = doc.getElementById('liveStamp'); if (t) { stamp.innerHTML = t.innerHTML; }
                todayHtml = doc.getElementById('dayList') ? doc.getElementById('dayList').innerHTML : todayHtml;
                if (picked === todayKey && !list.contains(document.activeElement)) { list.innerHTML = todayHtml; }
                last = Date.now();
            })
            .catch(function () { stamp.lastChild.textContent = 'Offline · showing the last figures'; })
            .finally(function () { busy = false; });
    }
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - last > 60000) { refresh(); } });
})();
</script>
<?php adminPageEnd(); ?>
