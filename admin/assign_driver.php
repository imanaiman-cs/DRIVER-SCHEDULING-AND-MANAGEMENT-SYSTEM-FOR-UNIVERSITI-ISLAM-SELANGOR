<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/assign_driver.php  -  UC017 Assign Driver (+ UC018 View Driver Recommendation)
//
// An Approved request has up to TWO tasks: the outbound trip and the return trip.
//   1. the recommendation for the outbound trip is always shown first (UC018); the administrator picks the driver
//   2. the return trip uses the same driver and vehicle, or a separate driver and vehicle (with its own recommendation)
// The system only recommends; it never assigns by itself, and a driver with a clashing task cannot be chosen.
// After assigning the request is "Driver Assigned" and the Head of Admin is notified (system + e-mail).
// ============================================================
$current_page = 'assign_driver.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/dashboard_ui.php';
requireAdmin();

$rid = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$stmt = $conn->prepare(requestSelectSql() . ' WHERE vr.request_id = ?');
$stmt->bind_param('i', $rid);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$req || $req['status'] !== 'approved') {
    setFlash('warning', 'That request is not waiting for a driver.');
    header('Location: vehicle_requests.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $out = (int)($_POST['out_driver'] ?? 0);
    $same = ($_POST['return_mode'] ?? 'same') === 'same';
    $retDriver = $same ? null : (int)($_POST['ret_driver'] ?? 0);
    $retVehicle = $same ? null : (int)($_POST['ret_vehicle'] ?? 0);
    if ($out <= 0) {
        setFlash('danger', 'Choose the driver of the outbound trip.');
    } elseif (!$same && ($retDriver <= 0 || $retVehicle <= 0)) {
        setFlash('danger', 'Choose the driver and the vehicle of the return trip, or use the same driver and vehicle.');
    } else {
        $res = assignRequestDrivers($conn, $rid, $out, $retDriver, $retVehicle);
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        if ($res['ok']) { header('Location: vehicle_requests.php?tab=assigned&open=' . $rid); exit(); }
    }
    header('Location: assign_driver.php?request_id=' . $rid);
    exit();
}

$win = requestTaskWindows($conn, $req);
$lq = $conn->prepare('SELECT license_required FROM vehicles WHERE vehicle_id = ?');
$vid = (int)$req['vehicle_id'];
$lq->bind_param('i', $vid);
$lq->execute();
$licence = $lq->get_result()->fetch_row()[0] ?? 'D';
$lq->close();
$rec = recommendDrivers($conn, ['start' => $win['out']['start_dt'], 'end' => $win['out']['end_dt'], 'licence' => $licence]);

// Each driver's week around the trip (other trips, internal tasks, welfare duty), for the week strip and the warnings
$outDate = substr($win['out']['start_dt'], 0, 10);
$retDate = substr($win['ret']['start_dt'], 0, 10);
$days = [];
for ($i = -2; $i <= 2; $i++) { $days[] = date('Y-m-d', strtotime("$outDate $i day")); }
if ($retDate > end($days)) { $days[] = $retDate; }
$busy = [];   // driver_id => date => 'trip' | 'task' | 'welfare'
$from = $days[0] . ' 00:00:00'; $to = end($days) . ' 23:59:59';
foreach ([
    ["SELECT driver_id, DATE(start_dt) AS d, 'trip' AS k FROM schedules WHERE status <> 'cancelled' AND request_id <> ? AND start_dt BETWEEN ? AND ?", 'iss', [$rid, $from, $to]],
    ["SELECT driver_id, DATE(start_dt) AS d, 'task' AS k FROM internal_tasks WHERE status = 'active' AND start_dt BETWEEN ? AND ? AND ? > 0", 'ssi', [$from, $to, 1]],
    ["SELECT driver_id, duty_date AS d, 'welfare' AS k FROM welfare_roster WHERE duty_date BETWEEN ? AND ? AND ? > 0", 'ssi', [$days[0], end($days), 1]],
] as [$sql, $t, $a]) {
    $st = $conn->prepare($sql); $st->bind_param($t, ...$a); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $b) { $busy[(int)$b['driver_id']][$b['d']] ??= $b['k']; }
    $st->close();
}
$ranked = $rec['ranked'];
$best = $ranked[0] ?? null;
$others = array_slice($ranked, 1);
$tone = ['bg-[#e3f0ea] text-[#0b5d3b]', 'bg-[#fbf3dc] text-[#7a5a0a]', 'bg-[#eae9ff] text-[#4338ca]', 'bg-[#ffeee6] text-[#c2410c]', 'bg-base-200 text-base-content'];
$initials = function (string $n): string {
    $p = array_values(array_filter(preg_split('/\s+/', $n), fn($w) => !in_array(strtolower($w), ['bin', 'binti'], true)));
    return strtoupper(substr($p[0] ?? '?', 0, 1) . substr($p[1] ?? '', 0, 1));
};
$maxTasks = max(1, ...array_map(fn($d) => (int)$d['tasks'], $ranked ?: [['tasks' => 1]]));

adminPageStart('Assign Driver');
?>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <a class="text-sm text-base-content/65 no-underline hover:underline" href="vehicle_requests.php?open=<?= $rid ?>"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Vehicle requests</a>
            <h1 class="mt-1">Assign a driver</h1>
        </div>
        <p class="text-sm text-base-content/65"><i class="fas fa-wand-magic-sparkles me-1.5 text-primary" aria-hidden="true"></i>The system recommends &mdash; you decide</p>
    </div>

    <form method="post" id="assignForm" class="pb-28">
        <input type="hidden" name="request_id" value="<?= $rid ?>">
        <div class="grid grid-cols-12 items-start gap-5">

            <!-- The request and its two legs -->
            <section class="e-card col-span-12 xl:col-span-4" aria-label="Request">
                <div class="e-accent">
                    <div class="flex items-center justify-between gap-2 text-xs"><span class="font-bold tracking-[0.18em]">REQUEST #<?= $rid ?></span><span class="rounded-full bg-white/20 px-2.5 py-0.5 font-semibold"><?= htmlspecialchars(requestStatusLabel($req['status'])) ?></span></div>
                    <h2 class="mt-4 text-[1.35rem] leading-tight font-bold text-white"><?= htmlspecialchars($req['destination']) ?></h2>
                    <div class="mt-1 text-sm text-white/85"><?= requestPeriodText($req) ?></div>
                    <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-white/85">
                        <span><i class="fas fa-user me-1" aria-hidden="true"></i><?= htmlspecialchars($req['staff_name']) ?></span>
                        <span><i class="fas fa-users me-1" aria-hidden="true"></i><?= (int)$req['passenger_count'] ?> pax</span>
                        <span><i class="fas fa-van-shuttle me-1" aria-hidden="true"></i><?= htmlspecialchars((string)$req['plate_number']) ?></span>
                    </div>
                </div>

                <ol class="relative ms-4 mt-5 border-s-2 border-dashed border-primary/25 text-sm">
                    <li class="relative ms-6 pb-5">
                        <span class="absolute -start-[2.15rem] grid size-8 place-items-center rounded-full bg-primary text-primary-content ring-4 ring-base-100"><i class="fas fa-arrow-right text-xs" aria-hidden="true"></i></span>
                        <div class="flex items-center justify-between gap-2"><b>Outbound trip</b><?= plate($req['plate_number']) ?></div>
                        <div class="text-xs text-base-content/65"><?= date('D, d M', strtotime($win['out']['start_dt'])) ?> &middot; <?= substr($win['out']['start'], 0, 5) ?>&ndash;<?= substr($win['out']['end'], 0, 5) ?> &middot; licence <?= htmlspecialchars($licence) ?></div>
                    </li>
                    <li class="relative ms-6">
                        <span class="absolute -start-[2.15rem] grid size-8 place-items-center rounded-full border-2 border-primary bg-base-100 text-primary ring-4 ring-base-100"><i class="fas fa-arrow-left text-xs" aria-hidden="true"></i></span>
                        <b>Return trip</b>
                        <div class="text-xs text-base-content/65"><?= date('D, d M', strtotime($win['ret']['start_dt'])) ?> &middot; <?= substr($win['ret']['start'], 0, 5) ?>&ndash;<?= substr($win['ret']['end'], 0, 5) ?></div>
                        <fieldset class="mt-2.5">
                            <legend class="sr-only">Return trip</legend>
                            <div class="flex flex-col gap-1 rounded-2xl bg-base-200 p-1 text-xs font-semibold min-[480px]:flex-row min-[480px]:rounded-full">
                                <label class="flex-1 cursor-pointer rounded-full px-2 py-1.5 text-center whitespace-nowrap text-base-content/70 has-[:checked]:bg-base-100 has-[:checked]:text-base-content has-[:checked]:shadow-[var(--ember-shadow)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-primary">
                                    <input class="sr-only" type="radio" name="return_mode" value="same" checked><i class="fas fa-link me-1 text-primary" aria-hidden="true"></i>Same driver &amp; vehicle</label>
                                <label class="flex-1 cursor-pointer rounded-full px-2 py-1.5 text-center whitespace-nowrap text-base-content/70 has-[:checked]:bg-base-100 has-[:checked]:text-base-content has-[:checked]:shadow-[var(--ember-shadow)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-primary">
                                    <input class="sr-only" type="radio" name="return_mode" value="separate">Different</label>
                            </div>
                        </fieldset>
                        <div id="sepBox" class="mt-3 hidden">
                            <label class="mb-1 block text-xs font-medium" for="ret_vehicle">Return vehicle (free in the return period, enough seats)</label>
                            <select class="select select-sm w-full" id="ret_vehicle" name="ret_vehicle"><option value="">Loading&hellip;</option></select>
                            <div id="retDrivers" class="mt-3 text-xs text-base-content/65" aria-live="polite">Choose a vehicle to see the recommendation for the return trip.</div>
                        </div>
                    </li>
                </ol>
                <div class="mt-5 rounded-2xl bg-base-200 p-3 text-xs text-base-content/65"><b class="text-base-content">Purpose</b> &middot; <?= htmlspecialchars($req['purpose']) ?></div>
            </section>

            <!-- The drivers -->
            <div class="col-span-12 xl:col-span-8" role="radiogroup" aria-label="Driver of the outbound trip">
                <?php if (!$best): ?>
                    <section class="e-card"><?= dashEmpty('No driver is available for this trip. See the list below for the reasons.', 'user-slash') ?></section>
                <?php else: $bid = (int)$best['driver_id']; ?>
                <label class="e-card group relative block cursor-pointer overflow-hidden ring-2 ring-transparent has-[:checked]:ring-primary has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
                    <input class="sr-only" type="radio" name="out_driver" value="<?= $bid ?>" data-name="<?= htmlspecialchars($best['name']) ?>" data-initials="<?= $initials($best['name']) ?>" checked required>
                    <span class="pointer-events-none absolute -end-16 -top-16 size-56 rounded-full bg-primary/10" aria-hidden="true"></span>
                    <span class="relative flex flex-wrap items-start gap-5">
                        <span class="relative shrink-0">
                            <span class="grid size-20 place-items-center rounded-full bg-gradient-to-br from-[#0b5d3b] to-[#13a86a] text-2xl font-bold text-white"><?= $initials($best['name']) ?></span>
                            <span class="absolute -end-1 -bottom-1 grid size-9 place-items-center rounded-full bg-base-100 text-sm font-bold text-primary shadow-[0_0_0_2px_var(--color-primary)]" title="Score"><?= rtrim(rtrim(number_format((float)$best['score'], 1), '0'), '.') ?></span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2"><span class="rounded-full bg-primary px-2.5 py-0.5 text-[0.68rem] font-bold tracking-wide text-primary-content"><i class="fas fa-star me-1" aria-hidden="true"></i>Recommended</span><span class="text-xs text-base-content/65">Rank 1 of <?= count($ranked) ?></span></span>
                            <span class="mt-1.5 block text-2xl font-bold tracking-tight"><?= htmlspecialchars($best['name']) ?></span>
                            <span class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-primary"><i class="fas fa-check me-1" aria-hidden="true"></i>Free at the trip time</span>
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-primary"><i class="fas fa-check me-1" aria-hidden="true"></i><?= (int)$best['tasks'] ?> <?= (int)$best['tasks'] === 1 ? 'task' : 'tasks' ?> this month</span>
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-primary"><i class="fas fa-check me-1" aria-hidden="true"></i>Licence <?= htmlspecialchars(str_replace(',', ' · ', (string)$best['license_class'])) ?></span>
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-primary"><i class="fas fa-check me-1" aria-hidden="true"></i><?= (int)$best['outside'] ?> outside working hours</span>
                                <?php if ($best['welfare']): ?><span class="rounded-full bg-secondary/20 px-3 py-1"><i class="fas fa-hand-holding-heart me-1" aria-hidden="true"></i>On welfare duty that day</span><?php endif; ?>
                            </span>
                        </span>
                        <span class="w-full sm:w-auto">
                            <span class="block text-xs text-base-content/65">This week</span>
                            <span class="mt-1.5 flex gap-1">
                                <?php foreach ($days as $d): $trip = $d === $outDate || $d === $retDate; $k = $busy[$bid][$d] ?? null; ?>
                                <span class="relative grid w-10 place-items-center rounded-xl py-1.5 text-[0.68rem] leading-tight <?= $trip ? 'bg-primary text-primary-content' : 'bg-base-200 text-base-content/65' ?>"><?= date('D', strtotime($d)) ?><b class="<?= $trip ? '' : 'text-base-content' ?>"><?= date('j', strtotime($d)) ?></b>
                                    <?php if ($k): ?><span class="absolute -top-0.5 -end-0.5 size-2.5 rounded-full <?= $k === 'welfare' ? 'bg-secondary' : 'bg-base-content' ?> ring-2 ring-base-100" title="<?= $k === 'welfare' ? 'Welfare duty' : 'Another task' ?>"></span><?php endif; ?></span>
                                <?php endforeach; ?>
                            </span>
                            <span class="mt-1 block text-[0.68rem] text-base-content/65"><?= empty($busy[$bid]) ? 'No other task this week' : 'Dot: another task or welfare duty' ?></span>
                        </span>
                    </span>
                    <span class="relative mt-5 flex flex-wrap gap-2">
                        <span class="btn btn-primary pointer-events-none hidden group-has-[:checked]:inline-flex"><i class="fas fa-circle-check" aria-hidden="true"></i>Chosen</span>
                        <span class="btn pointer-events-none group-has-[:checked]:hidden">Choose this driver</span>
                        <?php if (!empty($best['phone'])): ?><span class="btn pointer-events-none"><i class="fas fa-phone" aria-hidden="true"></i><?= htmlspecialchars($best['phone']) ?></span><?php endif; ?>
                    </span>
                </label>

                <?php if ($others): ?>
                <div class="mt-5 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold">Or choose another driver <span class="font-normal text-base-content/65">&middot; <?= count($others) ?> available</span></h2>
                    <div class="flex rounded-full bg-base-100 p-1 text-xs font-semibold shadow-[var(--ember-shadow)]" role="group" aria-label="Sort drivers">
                        <button type="button" class="rounded-full px-3 py-1 aria-pressed:bg-[var(--uis-ink)] aria-pressed:text-[var(--uis-ink-content)]" data-sort="rank" aria-pressed="true">Best match</button>
                        <button type="button" class="rounded-full px-3 py-1 aria-pressed:bg-[var(--uis-ink)] aria-pressed:text-[var(--uis-ink-content)]" data-sort="tasks" aria-pressed="false">Fewest tasks</button>
                        <button type="button" class="rounded-full px-3 py-1 aria-pressed:bg-[var(--uis-ink)] aria-pressed:text-[var(--uis-ink-content)]" data-sort="name" aria-pressed="false">A&ndash;Z</button>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" id="driverGrid">
                    <?php foreach ($others as $i => $d): $did = (int)$d['driver_id']; $retBusy = $retDate !== $outDate && isset($busy[$did][$retDate]); $sc = rtrim(rtrim(number_format((float)$d['score'], 1), '0'), '.'); ?>
                    <label class="e-card group relative block cursor-pointer p-4 ring-2 ring-transparent transition-transform hover:-translate-y-0.5 has-[:checked]:ring-primary has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary <?= $i >= 6 ? 'hidden' : '' ?>"
                           data-rank="<?= (int)$d['rank'] ?>" data-tasks="<?= (int)$d['tasks'] ?>" data-name="<?= htmlspecialchars($d['name']) ?>" <?= $i >= 6 ? 'data-more' : '' ?>>
                        <input class="sr-only" type="radio" name="out_driver" value="<?= $did ?>" data-name="<?= htmlspecialchars($d['name']) ?>" data-initials="<?= $initials($d['name']) ?>">
                        <span class="flex items-center gap-3">
                            <span class="relative grid size-12 shrink-0 place-items-center rounded-full text-sm font-bold <?= $tone[$i % count($tone)] ?>"><?= $initials($d['name']) ?>
                                <span class="absolute -end-2 -bottom-1.5 grid h-5 min-w-7 place-items-center rounded-full bg-base-100 px-1 text-[0.62rem] font-bold text-base-content shadow-[0_0_0_1.5px_var(--color-primary)]" title="Score"><?= $sc ?></span></span>
                            <span class="min-w-0"><span class="line-clamp-2 block leading-snug font-semibold"><?= htmlspecialchars($d['name']) ?></span><span class="block text-xs text-base-content/65"><?= htmlspecialchars(str_replace(',', ' · ', (string)$d['license_class'])) ?></span></span>
                            <span class="ms-auto hidden size-6 shrink-0 place-items-center rounded-full bg-primary text-primary-content group-has-[:checked]:grid"><i class="fas fa-check text-[0.6rem]" aria-hidden="true"></i></span>
                        </span>
                        <?php if ($d['welfare']): ?>
                            <span class="mt-3 block rounded-full bg-secondary/20 px-3 py-1 text-center text-xs font-semibold"><i class="fas fa-hand-holding-heart me-1" aria-hidden="true"></i>On welfare duty that day</span>
                        <?php elseif ($retBusy): ?>
                            <span class="mt-3 block rounded-full bg-error/10 px-3 py-1 text-center text-xs font-semibold text-error"><i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>Busy on the return day</span>
                        <?php else: ?>
                            <span class="mt-3 flex items-center gap-2 text-xs text-base-content/65"><span class="w-24 shrink-0"><?= (int)$d['tasks'] ?> <?= (int)$d['tasks'] === 1 ? 'task' : 'tasks' ?> &middot; <?= (int)$d['outside'] ?> out</span>
                                <span class="h-1.5 flex-1 rounded-full bg-base-200"><span class="block h-1.5 rounded-full bg-primary" style="width:<?= max(4, round((int)$d['tasks'] / $maxTasks * 100)) ?>%"></span></span></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-center gap-4 text-sm">
                    <?php if (count($others) > 6): ?><button type="button" class="btn btn-sm btn-ghost" id="showMore">Show <?= count($others) - 6 ?> more <i class="fas fa-chevron-down" aria-hidden="true"></i></button><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($rec['excluded']): ?>
                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer text-center text-base-content/65">Drivers who cannot be chosen (<?= count($rec['excluded']) ?>)</summary>
                    <ul class="mt-2 space-y-1 rounded-2xl bg-base-100 p-4 shadow-[var(--ember-shadow)]"><?php foreach ($rec['excluded'] as $x): ?><li><b><?= htmlspecialchars($x['name']) ?></b> &mdash; <?= htmlspecialchars($x['reason']) ?></li><?php endforeach; ?></ul>
                </details>
                <?php endif; ?>
                <p class="mt-4 text-xs text-base-content/65">Recommendation: fewer tasks this month and fewer outside working hours rank higher (weights <?= htmlspecialchars((string)$rec['weights']['tasks']) ?> / <?= htmlspecialchars((string)$rec['weights']['outside']) ?>). Tasks this month count trips, internal tasks and welfare duty days.</p>
            </div>
        </div>

        <!-- The choice so far, and the button -->
        <div class="sticky bottom-4 z-20 mt-6 flex flex-wrap items-center gap-3 rounded-[28px] bg-[var(--uis-ink)] py-2 ps-3 pe-2 text-sm text-[var(--uis-ink-content)] shadow-xl sm:rounded-full">
            <span class="hidden -space-x-2 sm:flex" aria-hidden="true"><span class="grid size-9 place-items-center rounded-full bg-[#0e7a4e] text-xs font-bold text-white ring-2 ring-[var(--uis-ink)]" id="sumOutAv"><?= $best ? $initials($best['name']) : '?' ?></span><span class="grid size-9 place-items-center rounded-full bg-[#0e7a4e] text-xs font-bold text-white ring-2 ring-[var(--uis-ink)]" id="sumRetAv"><?= $best ? $initials($best['name']) : '?' ?></span></span>
            <span class="min-w-0 flex-1 truncate text-xs sm:text-sm" aria-live="polite"><span class="opacity-70">Outbound</span> <b id="sumOut"><?= $best ? htmlspecialchars($best['name']) : '—' ?></b> <span class="max-sm:hidden"><span class="opacity-40">|</span> <span class="opacity-70">Return</span> <b id="sumRet">same driver &amp; vehicle</b></span></span>
            <span class="hidden text-xs opacity-70 lg:inline">Next: the Head of Admin approves</span>
            <button class="btn border-0 bg-[#0e7a4e] text-white hover:bg-[#0b5d3b]" <?= $best ? '' : 'disabled' ?>>Assign &amp; send <i class="fas fa-paper-plane" aria-hidden="true"></i></button>
        </div>
    </form>

<script>
(function () {
    var base = '<?= SITE_URL ?>/ajax/';
    var R = { start: <?= json_encode($win['ret']['start_dt']) ?>, end: <?= json_encode($win['ret']['end_dt']) ?>, rid: <?= $rid ?>, pax: <?= (int)$req['passenger_count'] ?> };
    var vehicles = [];
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
    function ini(n) { return n.split(/\s+/).filter(function (w) { return !/^(bin|binti)$/i.test(w); }).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase(); }
    var sepBox = document.getElementById('sepBox'), sumOut = document.getElementById('sumOut'), sumRet = document.getElementById('sumRet');
    var sumOutAv = document.getElementById('sumOutAv'), sumRetAv = document.getElementById('sumRetAv');

    function summary() {
        var o = document.querySelector('input[name=out_driver]:checked');
        var sep = document.querySelector('input[name=return_mode]:checked').value === 'separate';
        if (o) { sumOut.textContent = o.dataset.name; sumOutAv.textContent = o.dataset.initials; }
        var r = document.querySelector('input[name=ret_driver]:checked');
        if (!sep) { sumRet.textContent = 'same driver & vehicle'; sumRetAv.textContent = o ? o.dataset.initials : '?'; }
        else { sumRet.textContent = r ? r.dataset.name : 'not chosen yet'; sumRetAv.textContent = r ? ini(r.dataset.name) : '?'; }
    }
    document.addEventListener('change', function (e) { if (e.target.name === 'out_driver' || e.target.name === 'ret_driver' || e.target.name === 'return_mode') { summary(); } });

    document.querySelectorAll('input[name=return_mode]').forEach(function (r) {
        r.addEventListener('change', function () {
            var sep = this.value === 'separate' && this.checked;
            sepBox.classList.toggle('hidden', !sep);
            document.getElementById('ret_vehicle').required = sep;
            if (sep) { loadVehicles(); }
        });
    });

    // sort and "show more" for the driver cards
    var grid = document.getElementById('driverGrid');
    document.querySelectorAll('[data-sort]').forEach(function (b) {
        b.addEventListener('click', function () {
            var key = b.dataset.sort;
            document.querySelectorAll('[data-sort]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
            Array.prototype.slice.call(grid.children).sort(function (x, y) {
                if (key === 'name') { return x.dataset.name.localeCompare(y.dataset.name); }
                return (+x.dataset[key] - +y.dataset[key]) || (+x.dataset.rank - +y.dataset.rank);
            }).forEach(function (c) { grid.appendChild(c); });
        });
    });
    var more = document.getElementById('showMore');
    if (more) { more.addEventListener('click', function () { grid.querySelectorAll('[data-more]').forEach(function (c) { c.classList.remove('hidden'); }); more.remove(); }); }

    function loadVehicles() {
        if (vehicles.length) { return; }
        var s = R.start.split(' '), e = R.end.split(' ');
        var q = new URLSearchParams({ depart_date: s[0], depart_time: s[1].slice(0, 5), return_date: e[0], return_time: e[1].slice(0, 5), passengers: R.pax, request_id: R.rid, for_assignment: 1 });
        fetch(base + 'get_free_vehicles.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            vehicles = d.vehicles || [];
            var sel = document.getElementById('ret_vehicle');
            sel.innerHTML = '<option value="">Choose a vehicle</option>' + vehicles.map(function (v) {
                return '<option value="' + v.vehicle_id + '" data-lic="' + esc(v.license_required) + '">' + esc(v.plate_number) + ' - ' + esc(v.model) + ' (' + esc(v.vehicle_type) + ', ' + v.capacity + ' seats)</option>';
            }).join('');
            sel.addEventListener('change', loadDrivers);
        });
    }
    function loadDrivers() {
        var sel = document.getElementById('ret_vehicle'), box = document.getElementById('retDrivers');
        if (!sel.value) { box.textContent = 'Choose a vehicle to see the recommendation for the return trip.'; summary(); return; }
        var q = new URLSearchParams({ start: R.start, end: R.end, licence: sel.options[sel.selectedIndex].getAttribute('data-lic') });
        fetch(base + 'get_available_drivers.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success || !d.drivers.length) { box.innerHTML = '<span class="text-error">No driver is available for the return trip.</span>'; return; }
            box.innerHTML = '<fieldset><legend class="mb-1.5 font-medium text-base-content">Driver of the return trip</legend><div class="space-y-1.5">' + d.drivers.slice(0, 6).map(function (x) {
                return '<label class="flex cursor-pointer items-center gap-2.5 rounded-xl bg-base-200 px-3 py-2 text-base-content has-[:checked]:bg-primary/10 has-[:checked]:ring-1 has-[:checked]:ring-primary">' +
                       '<input class="radio radio-xs radio-primary" type="radio" name="ret_driver" value="' + x.driver_id + '" data-name="' + esc(x.name) + '" required>' +
                       '<span class="min-w-0 flex-1"><span class="block truncate font-semibold">' + esc(x.name) + '</span><span class="block text-base-content/65">' + x.tasks + (x.tasks === 1 ? ' task' : ' tasks') + ' &middot; score ' + Number(x.score).toFixed(0) + (x.welfare ? ' &middot; welfare duty' : '') + '</span></span>' +
                       (x.rank === 1 ? '<span class="rounded-full bg-primary px-2 py-0.5 text-[0.62rem] font-bold text-primary-content">Recommended</span>' : '') + '</label>';
            }).join('') + '</div></fieldset>';
            summary();
        });
    }
})();
</script>
<?php adminPageEnd(); ?>
