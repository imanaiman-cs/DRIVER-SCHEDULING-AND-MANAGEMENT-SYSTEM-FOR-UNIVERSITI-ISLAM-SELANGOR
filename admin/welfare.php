<?php
// ============================================================
// admin/welfare.php  -  Welfare duty (SRS UC026 Set, UC027 Update)
//
// One driver is on welfare duty for 24 hours (12 am to 12 am) each day.
//  * Set: choose a month and the drivers in rotation order; one driver per day is generated.
//  * Update: change the driver of ONE day with a reason; both drivers are notified.
// Welfare days count as tasks in the workload (driverMonthCounts). Days before today are never changed.
// ============================================================
$current_page = 'welfare.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$month = (string)($_GET['month'] ?? $_POST['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { $month = date('Y-m'); }
$first = $month . '-01';
$days  = (int)date('t', strtotime($first));
$drivers = activeDrivers($conn);
$activeIds = array_map('intval', array_column($drivers, 'driver_id'));
$today = date('Y-m-d');

/** Roster rows of a month, keyed by date. */
function welfareMonth(mysqli $conn, string $month): array
{
    $from = $month . '-01'; $to = date('Y-m-t', strtotime($from));
    $stmt = $conn->prepare("SELECT w.*, d.name AS driver_name, p.name AS replaced_name
                              FROM welfare_roster w JOIN drivers d ON d.driver_id = w.driver_id
                              LEFT JOIN drivers p ON p.driver_id = w.replaced_from_driver_id
                             WHERE w.duty_date BETWEEN ? AND ?");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $out[$r['duty_date']] = $r; }
    $stmt->close();
    return $out;
}

/** Tells every driver whose welfare days changed: $changes = [driver_id => ['add' => [dates], 'remove' => [dates]]]. */
function notifyWelfareChanges(mysqli $conn, array $changes): void
{
    $fmt = fn(array $d) => implode(', ', array_map(fn($x) => date('d M', strtotime($x)), $d));
    foreach ($changes as $driver_id => $c) {
        $u = userIdOfDriver($conn, (int)$driver_id);
        if (!$u) { continue; }
        $parts = [];
        if ($c['add'])    { $parts[] = 'You are on welfare duty (24 hours) on: ' . $fmt($c['add']) . '.'; }
        if ($c['remove']) { $parts[] = 'You are no longer on welfare duty on: ' . $fmt($c['remove']) . '.'; }
        if ($parts) { notifyUsers($conn, [$u], implode(' ', $parts), 'driver/schedules.php'); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'generate') {
        // chosen drivers in rotation order
        $picked = [];
        foreach ((array)($_POST['use'] ?? []) as $id => $on) {
            $id = (int)$id;
            if (in_array($id, $activeIds, true)) { $picked[$id] = (int)(($_POST['order'][$id] ?? 0)); }
        }
        asort($picked);                                   // stable: keeps list order for equal numbers
        $rotation = array_keys($picked);
        $replaceAll = !empty($_POST['replace_existing']);
        if (!$rotation) {
            setFlash('danger', 'Choose at least one active driver for the rotation.');
        } else {
            $roster = welfareMonth($conn, $month);
            $changes = [];
            $conn->begin_transaction();
            try {
                $ins = $conn->prepare('INSERT INTO welfare_roster (duty_date, driver_id) VALUES (?, ?)
                                       ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id), replaced_from_driver_id = NULL, replacement_reason = NULL');
                $n = 0; $noDriver = [];
                for ($d = 1; $d <= $days; $d++) {
                    $date = sprintf('%s-%02d', $month, $d);
                    if ($date < $today) { continue; }
                    $existing = $roster[$date] ?? null;
                    if ($existing && !$replaceAll) { continue; }
                    $did = null;                       // the next driver of the rotation who is not marked unavailable that day (UC030)
                    for ($k = 0; $k < count($rotation); $k++) {
                        $cand = $rotation[($d - 1 + $k) % count($rotation)];
                        if (driverUnavailableReason($conn, $cand, $date . ' 00:00:00', $date . ' 23:59:59') === null) { $did = $cand; break; }
                    }
                    if ($did === null) { $noDriver[] = date('d M', strtotime($date)); continue; }
                    if ($existing && (int)$existing['driver_id'] === $did && $existing['replaced_from_driver_id'] === null) { continue; }
                    $ins->bind_param('si', $date, $did);
                    $ins->execute();
                    $n++;
                    $changes[$did]['add'][] = $date;
                    if ($existing && (int)$existing['driver_id'] !== $did) { $changes[(int)$existing['driver_id']]['remove'][] = $date; }
                }
                $ins->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('welfare generate: ' . $e->getMessage());
                setFlash('danger', 'Database error. Nothing was changed.');
                header('Location: welfare.php?month=' . urlencode($month));
                exit();
            }
            foreach ($changes as &$c) { $c += ['add' => [], 'remove' => []]; }
            unset($c);
            auditLog($conn, 'welfare_generate', null, "Welfare duty $month: $n day(s) set, rotation of " . count($rotation) . ' driver(s)');
            notifyWelfareChanges($conn, $changes);
            setFlash($n ? 'success' : 'warning', $n ? "Welfare duty set for $n day(s) of " . date('F Y', strtotime($first)) . '. The drivers were notified.' . ($noDriver ? ' No driver could be set on ' . implode(', ', $noDriver) . ' (all marked unavailable).' : '')
                                                   : 'Nothing to set: every day is already filled or in the past. Tick "Regenerate days that already have a driver" to replace them.');
        }
    } elseif ($act === 'update') {
        $date = trim($_POST['duty_date'] ?? '');
        $did  = (int)($_POST['driver_id'] ?? 0);
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);
        $roster = isValidDate($date) ? welfareMonth($conn, substr($date, 0, 7)) : [];
        $existing = $roster[$date] ?? null;
        if (!isValidDate($date) || $date < $today) {
            setFlash('danger', 'Choose a day that is today or later.');
        } elseif (!in_array($did, $activeIds, true)) {
            setFlash('danger', 'Choose an active driver.');
        } elseif (($why = driverUnavailableReason($conn, $did, $date . ' 00:00:00', $date . ' 23:59:59')) !== null) {
            setFlash('danger', 'That driver cannot be set on welfare duty that day: ' . strtolower($why) . ' (schedule change request approved). Choose another driver.');   // UC025 3.1
        } elseif ($existing && (int)$existing['driver_id'] === $did) {
            setFlash('warning', 'That driver is already on duty that day.');
        } elseif ($existing && mb_strlen($reason) < 3) {
            setFlash('danger', 'Please give the reason for changing the duty driver.');
        } else {
            $from = $existing ? (int)$existing['driver_id'] : null;
            $rs   = $existing ? $reason : null;
            $stmt = $conn->prepare('INSERT INTO welfare_roster (duty_date, driver_id, replaced_from_driver_id, replacement_reason) VALUES (?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id), replaced_from_driver_id = VALUES(replaced_from_driver_id), replacement_reason = VALUES(replacement_reason)');
            $stmt->bind_param('siis', $date, $did, $from, $rs);
            $stmt->execute();
            $stmt->close();
            auditLog($conn, 'welfare_update', null, "Welfare duty $date: driver $did" . ($from ? " replaces $from. Reason: $reason" : ''));
            $nice = date('d M Y', strtotime($date));
            if ($u = userIdOfDriver($conn, $did)) { notifyUsers($conn, [$u], "You are on welfare duty (24 hours) on $nice." . ($rs ? " Reason for the change: $rs" : ''), 'driver/schedules.php'); }
            if ($from && ($u = userIdOfDriver($conn, $from))) { notifyUsers($conn, [$u], "You are no longer on welfare duty on $nice. Reason: $rs", 'driver/schedules.php'); }
            setFlash('success', "Welfare duty on $nice updated. " . ($from ? 'Both drivers were notified.' : 'The driver was notified.'));
        }
    }
    header('Location: welfare.php?month=' . urlencode($month));
    exit();
}

$roster = welfareMonth($conn, $month);
$prev_m = date('Y-m', strtotime("$first -1 month"));
$next_m = date('Y-m', strtotime("$first +1 month"));
$count = [];
foreach ($roster as $r) { $count[$r['driver_name']] = ($count[$r['driver_name']] ?? 0) + 1; }
arsort($count);
adminPageStart('Welfare Duty');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-moon text-success me-2"></i>Welfare Duty</h4>
    <p class="text-muted small mb-3">One driver is on welfare duty for 24 hours (12 am to 12 am) each day. Welfare days count in the driver's workload. Days before today are never changed.</p>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h6 class="fw-bold mb-3">Set welfare duty for a month</h6>
        <form method="post" class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold" for="gen_month">Month</label>
                <input type="month" class="form-control" id="gen_month" name="month" value="<?= $h($month) ?>" required>
            </div>
            <div class="col-12 col-md-9">
                <div class="form-label fw-semibold">Drivers in rotation order <span class="text-muted fw-normal small">(tick the drivers and number them: 1 is on duty on day 1)</span></div>
                <div class="row g-2">
                <?php foreach ($drivers as $i => $d): $id = (int)$d['driver_id']; ?>
                    <div class="col-12 col-sm-6 col-lg-4">
                        <div class="input-group input-group-sm">
                            <div class="input-group-text"><input class="form-check-input mt-0" type="checkbox" name="use[<?= $id ?>]" value="1" checked aria-label="Use <?= $h($d['name']) ?>"></div>
                            <input type="number" min="1" max="99" class="form-control" style="max-width:4.5rem" name="order[<?= $id ?>]" value="<?= $i + 1 ?>" aria-label="Order of <?= $h($d['name']) ?>">
                            <span class="form-control text-truncate" title="<?= $h($d['name']) ?>"><?= $h($d['name']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                <input type="hidden" name="action" value="generate">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="replace_existing" name="replace_existing" value="1">
                    <label class="form-check-label" for="replace_existing">Regenerate days that already have a driver</label></div>
                <button class="btn btn-uis-primary" type="submit"><i class="fas fa-wand-magic-sparkles me-1"></i>Generate the month</button>
            </div>
        </form>
    </div></div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a class="btn btn-sm btn-outline-success" href="?month=<?= $prev_m ?>" aria-label="Previous month">&laquo;</a>
        <strong><?= $h(date('F Y', strtotime($first))) ?></strong>
        <a class="btn btn-sm btn-outline-success" href="?month=<?= $next_m ?>" aria-label="Next month">&raquo;</a>
        <?php if ($count): ?><span class="small text-muted ms-2"><?php foreach ($count as $n => $c): ?><span class="badge bg-light text-dark border me-1"><?= $h($n) ?>: <?= (int)$c ?></span><?php endforeach; ?></span><?php endif; ?>
    </div>
    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Day</th><th>Driver on duty</th><th class="text-end">Update</th></tr></thead>
            <tbody>
            <?php for ($d = 1; $d <= $days; $d++):
                $date = sprintf('%s-%02d', $month, $d); $w = $roster[$date] ?? null; ?>
                <tr class="<?= $date === $today ? 'table-active' : '' ?>">
                    <td class="text-nowrap"><?= date('d M', strtotime($date)) ?></td>
                    <td class="<?= (int)date('N', strtotime($date)) >= 6 ? 'text-muted' : '' ?>"><?= date('D', strtotime($date)) ?></td>
                    <td><?php if ($w): ?><strong><?= $h($w['driver_name']) ?></strong>
                        <?php if ($w['replaced_name']): ?><div class="small text-muted">Replaced <?= $h($w['replaced_name']) ?>: <?= $h($w['replacement_reason']) ?></div><?php endif; ?>
                        <?php else: ?><span class="text-muted">Not set</span><?php endif; ?></td>
                    <td class="text-end">
                        <?php if ($date >= $today): ?>
                        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#dayModal"
                                data-date="<?= $date ?>" data-nice="<?= $h(date('D, d M Y', strtotime($date))) ?>" data-driver="<?= $w ? (int)$w['driver_id'] : 0 ?>" data-has="<?= $w ? 1 : 0 ?>">
                            <?= $w ? 'Change' : 'Set' ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div></div>

    <div class="modal fade" id="dayModal" tabindex="-1" aria-labelledby="dayTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
            <input type="hidden" name="action" value="update"><input type="hidden" name="month" value="<?= $h($month) ?>"><input type="hidden" name="duty_date" id="dayDate">
            <div class="modal-header"><h5 class="modal-title" id="dayTitle">Welfare duty</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="dayDriver">Driver on duty</label>
                <select class="form-select mb-3" id="dayDriver" name="driver_id" required>
                    <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['driver_id'] ?>"><?= $h($d['name']) ?></option><?php endforeach; ?>
                </select>
                <div id="reasonBox">
                    <label class="form-label fw-semibold" for="dayReason">Reason for the change</label>
                    <textarea class="form-control" id="dayReason" name="reason" rows="2" maxlength="500"></textarea>
                    <div class="form-text">Both the old and the new driver are notified.</div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button><button class="btn btn-uis-primary" type="submit">Save</button></div>
        </form></div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var m = document.getElementById('dayModal');
        m.addEventListener('show.bs.modal', function (e) {
            var b = e.relatedTarget, has = b.dataset.has === '1';
            document.getElementById('dayDate').value = b.dataset.date;
            document.getElementById('dayTitle').textContent = b.dataset.nice;
            if (b.dataset.driver !== '0') { document.getElementById('dayDriver').value = b.dataset.driver; }
            var r = document.getElementById('dayReason'); r.value = ''; r.required = has;
            document.getElementById('reasonBox').style.display = has ? '' : 'none';
        });
    });
    </script>
<?php adminPageEnd(); ?>
