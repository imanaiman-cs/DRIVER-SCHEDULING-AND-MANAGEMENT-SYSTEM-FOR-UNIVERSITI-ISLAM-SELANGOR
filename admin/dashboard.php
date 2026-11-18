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

$statusColour = ['pending' => '#d4a017', 'approved' => '#86efac', 'assigned' => '#38bdf8', 'processed' => '#10b981',
                 'rejected' => '#b91c1c', 'cancelled' => '#94a3b8', 'lapsed' => '#ea580c', 'completed' => '#cbd5e1'];
$doLabels = []; $doData = []; $doColours = [];
foreach ($statusColour as $k => $col) { if (!empty($byStatus[$k])) { $doLabels[] = requestStatusLabel($k); $doData[] = $byStatus[$k]; $doColours[] = $col; } }

$queueLink = isHeadAdmin() ? 'approve_assignments.php' : 'vehicle_requests.php?tab=assigned';
adminPageStart('Dashboard');
$trend = dashTrend($reqNow, $reqBefore);
$badTrend = dashTrend($badNow, $badBefore);
?>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4" data-dashboard>
        <h1 class="!text-[2rem] !font-medium"><?= dashGreeting() ?>, <?= dh(explode(' bin ', explode(' binti ', $_SESSION['full_name'] ?? '')[0])[0]) ?></h1>
        <div class="flex flex-wrap items-center gap-2">
            <span id="liveStamp" class="me-1 inline-flex items-center gap-1.5 text-xs text-base-content/65" title="The figures, queues, alerts and board refresh every minute"><span class="size-2 rounded-full bg-primary shadow-[0_0_8px_rgb(16_185_129/.9)]" aria-hidden="true"></span>Live &middot; updated <?= date('H:i') ?></span>
            <a class="btn" href="calendar.php"><i class="fas fa-calendar-days"></i>Calendar</a>
            <a class="btn" href="reports.php"><i class="fas fa-chart-column"></i>Reports</a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5">
        <!-- Left: the month, and what is waiting -->
        <div class="col-span-12 flex flex-col gap-5 xl:col-span-8">

            <section class="e-card relative overflow-hidden" id="live-hero" data-live>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs text-base-content/60">Requests this month &middot; <?= dh(roleLabel($_SESSION['role'])) ?></p>
                        <h2 class="mt-1 text-2xl font-medium tracking-tight">Keep every trip <span class="text-primary">on time</span></h2>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="drivers.php" class="hidden items-center gap-2 rounded-full bg-base-200 py-1 ps-1 pe-3 text-xs sm:flex" title="Active drivers">
                            <span class="flex -space-x-2">
                                <?php foreach ($crew as $i => $n): ?><span class="e-avatar e-avatar-<?= $i ?>"><?= dh(dashInitials($n)) ?></span><?php endforeach; ?>
                            </span>
                            <span class="font-medium"><?= $activeDrivers ?> drivers</span>
                        </a>
                        <a class="btn btn-neutral" href="vehicle_requests.php?tab=approved"><i class="fas fa-plus"></i>Assign drivers<?php if ($needDriver): ?><span class="badge badge-sm badge-primary"><?= count($needDriver) ?></span><?php endif; ?></a>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <span class="text-6xl font-light tracking-tight tabular-nums"><?= (int)$reqNow ?></span>
                    <?php if ($trend): ?><span class="e-chip <?= $trend['dir'] === 'down' ? 'e-chip-down' : '' ?>"><i class="fas fa-arrow-trend-<?= $trend['dir'] === 'down' ? 'down' : 'up' ?>"></i><?= $trend['pct'] === null ? 'New this month' : ($trend['pct'] > 0 ? '+' : '') . $trend['pct'] . '% vs last month' ?></span><?php endif; ?>
                </div>
                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <a class="e-tile" href="vehicle_requests.php?tab=processed"><span class="e-tile-label">Confirmed trips</span><span class="e-tile-value"><?= (int)($status['processed'] ?? 0) ?></span></a>
                    <a class="e-tile" href="vehicle_requests.php"><span class="e-tile-label">Rejected + lapsed this month</span><span class="e-tile-value"><?= (int)$badNow ?><?php if ($badTrend && $badTrend['pct'] === null): ?> <small class="text-xs font-normal text-base-content/65">none last month</small><?php endif; ?></span></a>
                    <a class="e-tile" href="vehicles.php"><span class="e-tile-label">Vehicles free now</span><span class="e-tile-value"><?= (int)$fleet['available'] ?> <small class="text-xs font-normal text-base-content/65"><?= (int)$fleet['in_use'] ?> in use, <?= (int)$fleet['maintenance'] ?> in maintenance</small></span></a>
                </div>
            </section>

            <div class="grid grid-cols-12 gap-5">
                <section class="e-card col-span-12 md:col-span-7" id="live-action" data-live>
                    <div class="e-head"><h2>Needs your action</h2><a href="vehicle_requests.php" class="e-icon-btn" aria-label="Open vehicle requests"><i class="fas fa-arrow-up-right-from-square"></i></a></div>
                    <?php if (!$needDriver && !$waitingHead && !$stalePending): ?><?= dashEmpty('Nothing is waiting. Well done.', 'circle-check') ?><?php endif; ?>
                    <?php foreach ([['Assign a driver', $needDriver, 'vehicle_requests.php?tab=approved', 'success'], ['Waiting for the Head of Admin', $waitingHead, $queueLink, 'info'], ['Pending over 24 hours (send a reminder)', $stalePending, 'reminders.php', 'warning']] as [$title, $list, $href, $tone]): if (!$list) { continue; } ?>
                        <div class="dash-group <?= $tone === 'warning' ? 'warn' : ($tone === 'info' ? 'info' : '') ?>"><?= dh($title) ?></div>
                        <?php foreach ($list as $r): $rid = (int)$r['request_id']; ?>
                            <div class="dash-row flex-nowrap">
                                <a class="min-w-0 flex-1 hover:underline" href="<?= dh($href) ?>"><strong>#<?= $rid ?></strong> <?= dh($r['destination']) ?> <span class="text-base-content/65">&middot; <?= dh($r['staff_name']) ?></span>
                                    <span class="block text-xs text-base-content/65"><?= requestPeriodText($r) ?> &middot; waiting <?= dashWaiting($r['waiting_h']) ?></span></a>
                                <?php if ($tone === 'success'): ?>
                                    <a class="btn btn-sm btn-primary" href="assign_driver.php?request_id=<?= $rid ?>" aria-label="Assign a driver to request #<?= $rid ?>"><i class="fas fa-user-plus"></i>Assign</a>
                                <?php elseif ($tone === 'info'): ?>
                                    <a class="btn btn-sm" href="<?= dh($queueLink) ?>" aria-label="Review request #<?= $rid ?>"><i class="fas fa-eye"></i>Review</a>
                                <?php else: ?>
                                    <form method="post" action="reminders.php"><input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="return" value="dashboard">
                                        <button class="btn btn-sm" type="submit" data-loading-text="Sending…" aria-label="Send a reminder for request #<?= $rid ?>"><i class="fas fa-paper-plane"></i>Remind</button></form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </section>

                <section class="e-accent col-span-12 md:col-span-5" id="live-progress" data-live>
                    <div class="e-head"><h2>In progress</h2><a href="vehicle_requests.php" class="e-icon-btn e-icon-btn-light" aria-label="Open vehicle requests"><i class="fas fa-arrow-right"></i></a></div>
                    <div class="flex items-baseline gap-2"><span class="text-6xl font-light tabular-nums"><?= $waitingTotal ?></span><span class="text-sm text-white/75">requests on their way to Confirmed</span></div>
                    <?php $max = max(1, count($needDriver), count($waitingHead), $status['pending'] ?? 0); ?>
                    <ul class="mt-6 space-y-3 text-sm">
                        <?php foreach ([['Need a driver', count($needDriver)], ['Waiting for the Head of Admin', count($waitingHead)], ['Pending with Heads of Department', (int)($status['pending'] ?? 0)]] as [$lbl, $n]): ?>
                        <li class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1"><span class="text-white/85"><?= dh($lbl) ?></span><span class="font-medium tabular-nums"><?= $n ?></span>
                            <span class="col-span-2 h-1.5 overflow-hidden rounded-full bg-white/20"><span class="block h-full rounded-full bg-white" style="width:<?= round($n / $max * 100) ?>%"></span></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-4 text-xs text-white/70"><?= count($stalePending) ?> pending over 24 hours</p>
                </section>
            </div>
        </div>

        <!-- Right: today -->
        <div class="col-span-12 flex flex-col gap-5 xl:col-span-4">
            <section class="e-card">
                <div class="e-head"><div><h2 id="dayTitle">Today</h2><p class="text-xs text-base-content/65" id="daySub"><?= date('l, j F Y') ?></p></div><a href="calendar.php" class="e-icon-btn" aria-label="Open calendar"><i class="fas fa-plus"></i></a></div>
                <div class="grid grid-cols-7 gap-1.5" role="group" aria-label="Pick a day this week">
                    <?php $mon = strtotime('monday this week'); for ($i = 0; $i < 7; $i++): $d = strtotime("+$i day", $mon); $isToday = date('Y-m-d', $d) === date('Y-m-d'); ?>
                    <button type="button" class="e-day <?= $isToday ? 'is-today' : '' ?>" data-day="<?= date('Y-m-d', $d) ?>" data-label="<?= date('l, j F Y', $d) ?>" aria-pressed="<?= $isToday ? 'true' : 'false' ?>"><span><?= date('D', $d) ?></span><b><?= date('j', $d) ?></b></button>
                    <?php endfor; ?>
                </div>
                <div class="mt-4 space-y-1" id="dayList" aria-live="polite">
                    <?php if (!$today): ?><?= dashEmpty('No trips or internal tasks today.', 'sun') ?><?php endif; ?>
                    <?php foreach (array_slice($today, 0, 6) as $t): ?>
                    <div class="flex items-start gap-3 rounded-2xl px-2 py-2 hover:bg-base-200">
                        <span class="mt-1.5 size-2 shrink-0 rounded-full <?= $t['kind'] === 'Internal' ? 'bg-base-content/30' : 'bg-primary' ?>"></span>
                        <span class="min-w-0 grow text-sm"><span class="block truncate font-medium"><?= dh($t['title']) ?></span>
                            <span class="block text-xs text-base-content/65"><?= date('H:i', strtotime($t['start'])) ?>–<?= date('H:i', strtotime($t['end'])) ?> &middot; <?= dh($t['who']) ?></span></span>
                        <?= plate($t['vehicle']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 flex items-center gap-2 rounded-2xl bg-base-200 px-3 py-2.5 text-sm"><i class="fas fa-hand-holding-heart text-primary"></i>Welfare duty today: <strong class="font-medium"><?= dh($welfare ?? 'not set') ?></strong></div>
            </section>

            <section class="e-card" id="live-alerts" data-live>
                <div class="e-head"><h2>Alerts</h2><?php if ($alerts): ?><span class="badge badge-error badge-sm"><?= count($alerts) ?></span><?php endif; ?></div>
                <?php if (!$alerts): ?><?= dashEmpty('All clear. No alerts.', 'shield-heart') ?><?php endif; ?>
                <?php foreach ($alerts as $a): ?>
                    <a class="dash-alert dash-alert-<?= dh($a['tone']) ?>" href="<?= dh($a['href']) ?>"><i class="fas fa-<?= dh($a['icon']) ?>"></i><span><?= dh($a['text']) ?></span></a>
                <?php endforeach; ?>
            </section>
        </div>

        <!-- Vehicles today -->
        <section class="e-card col-span-12" id="live-board" data-live>
            <div class="e-head"><div><h2>Vehicles today</h2><p class="text-xs text-base-content/65">Every vehicle across the working day</p></div><a href="calendar.php" class="btn btn-sm">Open calendar</a></div>
            <?= dashBoard($conn) ?>
        </section>

        <section class="e-card col-span-12 xl:col-span-8">
            <div class="e-head"><h2>Requests per month</h2><span class="text-xs text-base-content/65">last 6 months</span></div>
            <?= dashChart('chMonthly', 'line', array_keys($monthly), [['label' => 'Requests', 'data' => array_values($monthly), 'color' => '#34d399']], ['height' => 260]) ?>
        </section>

        <section class="e-dark col-span-12 xl:col-span-4" id="live-fleet" data-live>
            <div class="e-head"><h2>Fleet now</h2><a href="vehicles.php" class="e-icon-btn e-icon-btn-light" aria-label="Open vehicles"><i class="fas fa-arrow-right"></i></a></div>
            <div class="flex items-baseline gap-2"><span class="text-5xl font-light tabular-nums"><?= array_sum($fleet) ?></span><span class="text-sm text-white/65">vehicles</span></div>
            <?php $totalV = max(1, array_sum($fleet)); ?>
            <ul class="mt-6 space-y-3.5 text-sm">
                <?php foreach (['available' => '#34d399', 'in_use' => '#ffffff', 'maintenance' => '#d4a017', 'inactive' => '#71717a'] as $st => $col): ?>
                <li class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1.5"><span class="text-white/80"><?= dh(vehicleStatusLabel($st)) ?></span><span class="font-medium tabular-nums"><?= (int)$fleet[$st] ?></span>
                    <span class="col-span-2 h-1.5 overflow-hidden rounded-full bg-white/10"><span class="block h-full rounded-full" style="width:<?= round($fleet[$st] / $totalV * 100) ?>%;background:<?= $col ?>"></span></span></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="e-card col-span-12 lg:col-span-4">
            <div class="e-head"><h2>Requests by status</h2><span class="text-xs text-base-content/65">last 6 months</span></div>
            <?= $doData ? dashChart('chStatus', 'bar', $doLabels, [['label' => 'Requests', 'data' => $doData, 'colors' => $doColours]], ['horizontal' => true, 'height' => 260]) : dashEmpty('No requests yet.', 'inbox') ?>
        </section>
        <section class="e-card col-span-12 lg:col-span-4">
            <div class="e-head"><h2>Driver workload</h2><span class="text-xs text-base-content/65">this month</span></div>
            <?= $workload ? dashChart('chDrivers', 'bar', array_column($workload, 'name'), [
                ['label' => 'In working hours', 'data' => array_map(fn($w) => $w['tasks'] - $w['outside'], $workload), 'color' => '#34d399'],
                ['label' => 'Outside working hours', 'data' => array_column($workload, 'outside'), 'color' => '#d4a017']], ['stacked' => true, 'horizontal' => true, 'height' => 300]) : dashEmpty('No active drivers.', 'user-slash') ?>
            <p class="mt-2 text-xs text-base-content/60">The same counts the driver recommendation uses (welfare days count as tasks).</p>
        </section>
        <section class="e-card col-span-12 lg:col-span-4">
            <div class="e-head"><h2>Vehicle booked hours</h2><span class="text-xs text-base-content/65">this month</span></div>
            <?= array_sum(array_column($util, 'hours')) > 0 ? dashChart('chVehicles', 'bar', array_column($util, 'plate_number'), [['label' => 'Booked hours', 'data' => array_map('floatval', array_column($util, 'hours')), 'color' => '#38bdf8']], ['horizontal' => true, 'height' => 300]) : dashEmpty('No vehicle is booked this month yet.', 'car-side') ?>
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
                    var dot = e.kind === 'trip' ? 'bg-primary' : (e.kind === 'welfare' ? 'bg-secondary' : 'bg-base-content/30');
                    var when = e.kind === 'welfare' ? 'All day' : hm(e.start) + '–' + hm(e.end);
                    var name = e.kind === 'trip' && e.destination ? e.destination : e.title;
                    return '<div class="flex items-start gap-3 rounded-2xl px-2 py-2 hover:bg-base-200"><span class="mt-1.5 size-2 shrink-0 rounded-full ' + dot + '"></span>' +
                        '<span class="min-w-0 grow text-sm"><span class="block truncate font-medium">' + esc(name) + '</span><span class="block text-xs text-base-content/65">' + esc(when) + ' &middot; ' + esc(e.driver) + '</span></span>' +
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
