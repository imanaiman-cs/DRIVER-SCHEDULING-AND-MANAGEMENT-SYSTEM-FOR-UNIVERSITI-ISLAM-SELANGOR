<?php
// ============================================================
// admin/assign_welfare.php  -  Assign Welfare Duty Task (SRS 10 Nov UC007)
//
// For a week that is Waiting for Driver: the recommended drivers of each day (24 hours, licence of the vehicle) are listed and one driver is
// chosen for EVERY day. A driver who is not the top recommendation gives a warning with the task count first (A1).
// Submit -> Driver Assigned and the Head of Admin is told (UC026).
// ============================================================
$current_page = 'welfare.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$week = isValidDate((string)($_GET['week'] ?? $_POST['week'] ?? '')) ? welfareWeekStart($_GET['week'] ?? $_POST['week']) : '';
$rows = $week ? array_filter(welfareWeekRows($conn, $week), fn($r) => $r['status'] === 'waiting') : [];
if (!$rows) {
    setFlash('warning', 'That week is not waiting for drivers.');
    header('Location: welfare.php');
    exit();
}
$rec = [];
foreach ($rows as $date => $r) { $rec[$date] = welfareDayRecommendation($conn, $r); }

$warn = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pick = array_map('intval', (array)($_POST['driver'] ?? []));
    $err = null;
    foreach ($rows as $date => $r) {
        if (empty($rec[$date]['ranked'])) { $err = 'No driver is available on ' . date('D d M', strtotime($date)) . '.'; break; }        // 3.2
        if (($pick[$date] ?? 0) <= 0) { $err = 'Every day needs a driver (' . date('D d M', strtotime($date)) . ').'; break; }               // 3.1
    }
    if ($err) {
        setFlash('danger', $err);
        header('Location: assign_welfare.php?week=' . urlencode($week));
        exit();
    }
    foreach ($rows as $date => $r) {
        $top = (int)$rec[$date]['ranked'][0]['driver_id'];
        if ($pick[$date] !== $top) {                              // A1: outside the top recommendation
            foreach ($rec[$date]['ranked'] as $d) { if ((int)$d['driver_id'] === $pick[$date]) { $warn[$date] = $d; } }
        }
    }
    if (!$warn || !empty($_POST['confirmed'])) {
        $res = assignWelfareWeek($conn, $week, $pick);
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        header('Location: ' . ($res['ok'] ? 'welfare.php?week=' : 'assign_welfare.php?week=') . urlencode($week));
        exit();
    }
}

adminPageStart('Assign Welfare Duty');
?>
    <a class="text-sm no-underline" href="welfare.php?week=<?= $h($week) ?>"><i class="fas fa-arrow-left me-1"></i>Welfare duty</a>
    <h1 class="text-2xl font-medium my-2">Assign welfare duty &mdash; week of <?= $h(date('d M Y', strtotime($week))) ?></h1>

    <?php if ($warn): ?>
    <div class="alert alert-soft alert-warning mb-4"><div><strong>Some drivers are not the top recommendation.</strong>
        <ul class="mb-2"><?php foreach ($warn as $date => $d): ?><li><?= $h(date('D d M', strtotime($date))) ?>: <?= $h($d['name']) ?> has <?= (int)$d['tasks'] ?> task(s) this month (the recommended driver has <?= (int)$rec[$date]['ranked'][0]['tasks'] ?>).</li><?php endforeach; ?></ul>
        <form method="post">
            <input type="hidden" name="week" value="<?= $h($week) ?>"><input type="hidden" name="confirmed" value="1">
            <?php foreach ($_POST['driver'] as $date => $id): ?><input type="hidden" name="driver[<?= $h($date) ?>]" value="<?= (int)$id ?>"><?php endforeach; ?>
            <button class="btn btn-warning btn-sm">Confirm and submit</button> <a class="btn btn-ghost btn-sm" href="assign_welfare.php?week=<?= $h($week) ?>">Choose again</a>
        </form>
    </div></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="week" value="<?= $h($week) ?>">
        <?php foreach ($rows as $date => $r): ?>
        <div class="card border border-base-300 bg-base-100 mb-4"><div class="card-body">
            <h6 class="font-bold"><?= $h(date('D, d M', strtotime($date))) ?> <span class="font-normal text-base-content/60 text-sm">24 hours &middot; <?= plate($r['plate_number'] ?? null) ?> <?= $h((string)($r['model'] ?? '')) ?></span></h6>
            <?php if ($r['reject_reason']): ?><div class="text-sm text-error mb-1">Last assignment was rejected: <?= $h($r['reject_reason']) ?></div><?php endif; ?>
            <?php if (!$rec[$date]['ranked']): ?><div class="text-error text-sm">No driver is available on this day.</div><?php else: ?>
            <div class="overflow-x-auto"><table class="table table-sm align-middle mb-0">
                <thead><tr><th></th><th>#</th><th>Driver</th><th class="text-center">Tasks this month</th><th class="text-center">Weekend tasks</th><th class="text-center">Experience (years)</th><th class="text-center">Score</th></tr></thead>
                <tbody>
                <?php foreach ($rec[$date]['ranked'] as $d): $id = (int)$d['driver_id']; ?>
                    <tr>
                        <td><input class="radio radio-sm radio-primary" type="radio" name="driver[<?= $h($date) ?>]" value="<?= $id ?>" id="d<?= $h($date) ?>_<?= $id ?>" <?= (int)($_POST['driver'][$date] ?? 0) === $id ? 'checked' : '' ?>></td>
                        <td><?= (int)$d['rank'] ?></td>
                        <td><label for="d<?= $h($date) ?>_<?= $id ?>" class="mb-0"><?= $h($d['name']) ?></label><?= $d['rank'] === 1 ? ' <span class="badge badge-soft badge-primary">Recommended</span>' : '' ?></td>
                        <td class="text-center"><?= (int)$d['tasks'] ?></td><td class="text-center"><?= (int)$d['weekend'] ?></td>
                        <td class="text-center"><?= number_format((float)$d['experience'], 1) ?></td>
                        <td class="text-center"><span class="badge badge-soft badge-success"><?= number_format((float)$d['score'], 1) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div></div>
        <?php endforeach; ?>
        <button class="btn btn-success"><i class="fas fa-paper-plane me-1"></i>Submit to the Head of Admin</button>
    </form>
<?php adminPageEnd(); ?>
