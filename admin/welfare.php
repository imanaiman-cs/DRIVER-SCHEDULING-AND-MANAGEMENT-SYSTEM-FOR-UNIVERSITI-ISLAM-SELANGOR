<?php
// ============================================================
// admin/welfare.php  -  Manage Welfare Duty (SRS 10 Nov UC006)
//
// Welfare duty is a WEEKLY schedule: 24 hours a day, a car or van for each day. The Assistant creates the week with a vehicle for each day
// (status Waiting for Driver, the vehicle is reserved), can view, change the vehicle of a day (A2) or delete a day (A3).
// The drivers are chosen in Assign Welfare Duty (UC007, assign_welfare.php); the Head of Admin approves (UC026).
// ============================================================
$current_page = 'welfare.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$today = date('Y-m-d');
$ph = "'" . implode("','", WELFARE_VEHICLE_TYPES) . "'";
$vehicles = $conn->query("SELECT vehicle_id, plate_number, vehicle_type, model FROM vehicles WHERE status <> 'inactive' AND vehicle_type IN ($ph) ORDER BY plate_number")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $week = (string)($_POST['week'] ?? '');
    if ($act === 'create') {
        $res = createWelfareWeek($conn, welfareWeekStart(isValidDate((string)($_POST['week_date'] ?? '')) ? $_POST['week_date'] : $today), array_map('intval', (array)($_POST['vehicle'] ?? [])));
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        if ($res['ok']) { $week = welfareWeekStart($_POST['week_date']); }
    } elseif ($act === 'vehicle') {
        $res = updateWelfareVehicle($conn, (int)($_POST['task_id'] ?? 0), (int)($_POST['vehicle_id'] ?? 0));
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($act === 'delete') {
        $res = deleteWelfareDay($conn, (int)($_POST['task_id'] ?? 0));
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    }
    header('Location: welfare.php' . (isValidDate($week) ? '?week=' . urlencode($week) : ''));
    exit();
}

$week = isValidDate((string)($_GET['week'] ?? '')) ? welfareWeekStart($_GET['week']) : '';
$newWeek = isValidDate((string)($_GET['new'] ?? '')) ? welfareWeekStart($_GET['new']) : welfareWeekStart(date('Y-m-d', strtotime('+7 days')));

// the weeks that have a schedule (from this week on, and the last few)
$weeks = $conn->query("SELECT DATE_SUB(task_date, INTERVAL WEEKDAY(task_date) DAY) AS monday, COUNT(*) AS days, SUM(driver_id IS NOT NULL) AS with_driver,
                              SUM(status = 'waiting') AS waiting, SUM(status = 'assigned') AS assigned, SUM(status = 'approved') AS approved
                         FROM tasks WHERE task_source = 'welfare' AND status <> 'cancelled' AND task_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) GROUP BY monday ORDER BY monday")->fetch_all(MYSQLI_ASSOC);
$rows = $week ? welfareWeekRows($conn, $week) : [];
$weekStatus = function (array $w): string { return $w['waiting'] > 0 ? 'Waiting for Driver' : ($w['assigned'] > 0 ? 'Driver Assigned' : 'Approved'); };

adminPageStart('Welfare Duty');
?>
    <h1 class="text-2xl font-medium mb-1">Welfare Duty</h1>
    <p class="text-base-content/60 text-sm mb-4">Welfare duty is a 24-hour standby duty with a car or van, scheduled by week. Create the week with a vehicle for each day, then assign the drivers. The Head of Admin approves.</p>

    <div class="card border border-base-300 bg-base-100 mb-6"><div class="card-body">
        <h6 class="font-bold mb-4">Create schedule</h6>
        <form method="get" class="grid-12 grid grid-cols-12 gap-2 items-end mb-4">
            <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="new">Any date in the week</label>
                <input type="date" class="input w-full" id="new" name="new" value="<?= $h($newWeek) ?>" min="<?= $h($today) ?>"></div>
            <div class="col-span-12 md:col-span-3"><button class="btn btn-outline btn-success">Show the week</button></div>
        </form>
        <form method="post">
            <input type="hidden" name="action" value="create"><input type="hidden" name="week_date" value="<?= $h($newWeek) ?>">
            <div class="font-semibold mb-2">Week of <?= $h(date('d M Y', strtotime($newWeek))) ?></div>
            <div class="grid-12 grid grid-cols-12 gap-2">
            <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("$newWeek +$i days")); if ($d < $today) { continue; } ?>
                <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                    <label class="mb-1 block text-sm font-medium" for="veh<?= $i ?>"><?= $h(date('D, d M', strtotime($d))) ?></label>
                    <select class="select select-sm w-full" id="veh<?= $i ?>" name="vehicle[<?= $h($d) ?>]" required>
                        <option value="">Choose a vehicle</option>
                        <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>"><?= $h($v['plate_number']) ?> &middot; <?= $h($v['vehicle_type']) ?> <?= $h($v['model']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endfor; ?>
            </div>
            <button class="btn btn-primary mt-4" type="submit"><i class="fas fa-floppy-disk me-1"></i>Save</button>
        </form>
    </div></div>

    <h2 class="text-lg font-medium mb-2">Schedules</h2>
    <div class="card border border-base-300 bg-base-100 mb-6"><div class="overflow-x-auto">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Week</th><th>Days</th><th>Status</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($weeks as $w): ?>
                <tr>
                    <td><?= $h(date('d M', strtotime($w['monday']))) ?> &ndash; <?= $h(date('d M Y', strtotime($w['monday'] . ' +6 days'))) ?></td>
                    <td><?= (int)$w['days'] ?></td>
                    <td><?= $h($weekStatus($w)) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline" href="?week=<?= $h($w['monday']) ?>">View</a>
                        <?php if ($w['waiting'] > 0): ?><a class="btn btn-sm btn-success" href="assign_welfare.php?week=<?= $h($w['monday']) ?>">Assign drivers</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$weeks): ?><tr><td colspan="4" class="text-base-content/60">No schedule yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>

    <?php if ($week): ?>
    <h2 class="text-lg font-medium mb-2">Week of <?= $h(date('d M Y', strtotime($week))) ?></h2>
    <?php if (!$rows): ?><div class="alert alert-soft mb-4">No record is found: there is no schedule for this week.</div><?php endif; ?>
    <div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Date</th><th>Vehicle</th><th>Driver</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("$week +$i days")); $w = $rows[$d] ?? null; ?>
                <tr>
                    <td class="whitespace-nowrap"><?= $h(date('D, d M', strtotime($d))) ?></td>
                    <?php if ($w): ?>
                    <td><?= $w['plate_number'] ? plate($w['plate_number']) . ' ' . $h($w['model']) : '<span class="text-base-content/60">&mdash;</span>' ?></td>
                    <td><?= $w['driver_name'] ? '<strong>' . $h($w['driver_name']) . '</strong>' : '<span class="text-base-content/60">Not assigned yet</span>' ?><?= $w['reject_reason'] ? '<div class="text-sm text-error">Rejected: ' . $h($w['reject_reason']) . '</div>' : '' ?></td>
                    <td><span class="badge badge-soft <?= $w['status'] === 'approved' ? 'badge-success' : ($w['status'] === 'assigned' ? 'badge-info' : 'badge-warning') ?>"><?= $h(welfareStatusLabel($w['status'])) ?></span></td>
                    <td class="text-end">
                        <?php if ($d >= $today): ?>
                        <form method="post" class="inline-flex gap-1">
                            <input type="hidden" name="week" value="<?= $h($week) ?>"><input type="hidden" name="task_id" value="<?= (int)$w['task_id'] ?>">
                            <select class="select select-sm w-auto" name="vehicle_id" aria-label="Vehicle for <?= $h($d) ?>">
                                <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= (int)$v['vehicle_id'] === (int)$w['vehicle_id'] ? 'selected' : '' ?>><?= $h($v['plate_number']) ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline btn-success" name="action" value="vehicle">Edit</button>
                            <?php if ($w['status'] === 'approved' && $w['driver_id'] && $d > $today): ?><a class="btn btn-sm btn-outline btn-primary" href="replace_task_driver.php?welfare=<?= (int)$w['task_id'] ?>">Replace driver</a><?php endif; ?>
                            <button class="btn btn-sm btn-outline btn-error" name="action" value="delete" data-confirm="The vehicle is released<?= $w['driver_id'] ? ' and the driver is told' : '' ?>." data-confirm-title="Delete this duty day?" data-confirm-button="Delete">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php else: ?><td colspan="4" class="text-base-content/60">No duty</td><?php endif; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div></div>
    <?php endif; ?>
<?php adminPageEnd(); ?>
