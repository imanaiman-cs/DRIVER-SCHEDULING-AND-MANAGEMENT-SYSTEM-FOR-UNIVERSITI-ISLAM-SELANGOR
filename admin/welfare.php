<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/welfare.php  –  welfare duty roster (one 24-hour duty driver per day)
//
// The duty driver is kept free for emergencies, so the driver recommendation takes points
// off their score that day. "Fill empty days" builds a fair rotation: the driver with the
// fewest duty days comes first, drivers on leave are skipped, and nobody gets two days in a row
// when someone else is free.
// ============================================================
$current_page = 'welfare.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? $_POST['month'] ?? '')) ? ($_GET['month'] ?? $_POST['month']) : date('Y-m');
$first = $month . '-01';
$days  = (int)date('t', strtotime($first));
$drivers = regularActiveDrivers($conn);

/** Roster rows of the month, keyed by date. */
function welfareMonth(mysqli $conn, string $month): array
{
    $stmt = $conn->prepare("SELECT w.*, d.name AS driver_name, p.name AS replaced_name
                              FROM welfare_roster w JOIN drivers d ON d.driver_id = w.driver_id
                              LEFT JOIN drivers p ON p.driver_id = w.replaced_from_driver_id
                             WHERE DATE_FORMAT(w.duty_date, '%Y-%m') = ?");
    $stmt->bind_param('s', $month);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $out[$r['duty_date']] = $r; }
    $stmt->close();
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act  = $_POST['action'] ?? '';
    $date = trim($_POST['duty_date'] ?? '');
    $ids  = array_map('intval', array_column($drivers, 'driver_id'));

    if ($act === 'set' && validDateStr($date)) {
        $did = (int)($_POST['driver_id'] ?? 0);
        $existing = $conn->query("SELECT * FROM welfare_roster WHERE duty_date = '" . $conn->real_escape_string($date) . "'")->fetch_assoc();
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);
        if (!in_array($did, $ids, true)) {
            setFlash('danger', 'Choose an active regular driver.');
        } elseif ($lv = driverLeaveOn($conn, $did, $date)) {
            setFlash('danger', 'That driver is on ' . ($lv['leave_type'] === 'mc' ? 'MC' : 'leave') . ' on ' . date('d M Y', strtotime($date)) . '.');
        } elseif ($existing && (int)$existing['driver_id'] === $did) {
            setFlash('warning', 'That driver is already on duty that day.');
        } elseif ($existing && mb_strlen($reason) < 3) {
            setFlash('danger', 'Please enter the reason for replacing the duty driver.');
        } else {
            $from = $existing ? (int)$existing['driver_id'] : null;
            $rs   = $existing ? $reason : null;
            $ins = $conn->prepare('INSERT INTO welfare_roster (duty_date, driver_id, replaced_from_driver_id, replacement_reason) VALUES (?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id), replaced_from_driver_id = VALUES(replaced_from_driver_id), replacement_reason = VALUES(replacement_reason)');
            $ins->bind_param('siis', $date, $did, $from, $rs);
            $ins->execute();
            $ins->close();
            auditLog($conn, $existing ? 'welfare_replace' : 'welfare_set', null, "Welfare duty $date: driver $did" . ($existing ? " (replacing $from: $reason)" : ''));
            if ($u = userIdOfDriver($conn, $did)) { notifyUsers($conn, [$u], 'You are on welfare duty on ' . date('d M Y', strtotime($date)) . '.', 'driver/dashboard.php'); }
            if ($from && ($u = userIdOfDriver($conn, $from))) { notifyUsers($conn, [$u], 'You are no longer on welfare duty on ' . date('d M Y', strtotime($date)) . '.', 'driver/dashboard.php'); }
            setFlash('success', 'Welfare duty saved for ' . date('d M Y', strtotime($date)) . '.');
        }
    } elseif ($act === 'clear' && validDateStr($date)) {
        $del = $conn->prepare('DELETE FROM welfare_roster WHERE duty_date = ?');
        $del->bind_param('s', $date);
        $del->execute();
        $del->close();
        auditLog($conn, 'welfare_clear', null, "Welfare duty cleared for $date");
        setFlash('success', 'Duty cleared.');
    } elseif ($act === 'generate') {
        if (!$drivers) {
            setFlash('danger', 'There are no active regular drivers.');
        } else {
            // duty days per driver in the 60 days before the month and in the month itself
            $counts = [];
            $stmt = $conn->prepare('SELECT driver_id, COUNT(*) c FROM welfare_roster WHERE duty_date BETWEEN DATE_SUB(?, INTERVAL 60 DAY) AND LAST_DAY(?) GROUP BY driver_id');
            $stmt->bind_param('ss', $first, $first);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $counts[(int)$r['driver_id']] = (int)$r['c']; }
            $stmt->close();
            $roster = welfareMonth($conn, $month);
            $prev = null; $filled = 0; $unfilled = [];
            $ins = $conn->prepare('INSERT INTO welfare_roster (duty_date, driver_id) VALUES (?, ?)');
            for ($d = 1; $d <= $days; $d++) {
                $date = sprintf('%s-%02d', $month, $d);
                if (isset($roster[$date])) { $prev = (int)$roster[$date]['driver_id']; continue; }
                $best = null;
                foreach ($drivers as $dr) {
                    $id = (int)$dr['driver_id'];
                    if (driverLeaveOn($conn, $id, $date)) { continue; }
                    $key = [($counts[$id] ?? 0) + ($id === $prev ? 100 : 0), $dr['name']];     // +100: only if nobody else is free
                    if ($best === null || $key < $best[0]) { $best = [$key, $id]; }
                }
                if ($best === null) { $unfilled[] = $d; $prev = null; continue; }
                $ins->bind_param('si', $date, $best[1]);
                $ins->execute();
                $counts[$best[1]] = ($counts[$best[1]] ?? 0) + 1;
                $prev = $best[1]; $filled++;
            }
            $ins->close();
            auditLog($conn, 'welfare_generate', null, "Welfare roster $month: $filled day(s) filled");
            setFlash($unfilled ? 'warning' : 'success', "Filled $filled day(s)." . ($unfilled ? ' No driver was free on day(s): ' . implode(', ', $unfilled) . '.' : ''));
        }
    }
    header('Location: welfare.php?month=' . urlencode($month));
    exit();
}

$roster = welfareMonth($conn, $month);
$prev_m = date('Y-m', strtotime("$first -1 month"));
$next_m = date('Y-m', strtotime("$first +1 month"));
adminPageStart('Welfare Duty');
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-heart-pulse text-success me-2"></i>Welfare Duty Roster</h4>
        <form method="post"><input type="hidden" name="action" value="generate"><input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
            <button class="btn btn-success btn-sm" data-confirm="Fill the empty days of this month automatically?" data-confirm-title="Fill the roster?" data-confirm-button="Fill empty days"><i class="fas fa-wand-magic-sparkles me-1"></i>Fill empty days automatically</button></form>
    </div>
    <p class="text-muted small">One driver is on 24-hour welfare duty each day and is kept free for emergencies. On duty, a driver gets a lower score in the driver recommendation.</p>
    <div class="d-flex align-items-center gap-2 mb-3">
        <a class="btn btn-sm btn-outline-success" href="?month=<?= $prev_m ?>">&laquo;</a>
        <strong><?= date('F Y', strtotime($first)) ?></strong>
        <a class="btn btn-sm btn-outline-success" href="?month=<?= $next_m ?>">&raquo;</a>
    </div>
    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Day</th><th>Driver on duty</th><th>Set / replace</th></tr></thead>
            <tbody>
            <?php for ($d = 1; $d <= $days; $d++):
                $date = sprintf('%s-%02d', $month, $d); $w = $roster[$date] ?? null; ?>
                <tr class="<?= $date === date('Y-m-d') ? 'table-active' : '' ?>">
                    <td class="text-nowrap"><?= date('d M', strtotime($date)) ?></td>
                    <td class="<?= (int)date('N', strtotime($date)) >= 6 ? 'text-muted' : '' ?>"><?= date('D', strtotime($date)) ?></td>
                    <td><?php if ($w): ?><strong><?= htmlspecialchars($w['driver_name']) ?></strong>
                        <?= $w['replaced_name'] ? '<div class="small text-muted">replaced ' . htmlspecialchars($w['replaced_name']) . ': ' . htmlspecialchars($w['replacement_reason'] ?? '') . '</div>' : '' ?>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                    <td>
                        <form method="post" class="row g-1">
                            <input type="hidden" name="action" value="set"><input type="hidden" name="duty_date" value="<?= $date ?>"><input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                            <div class="col-auto"><select class="form-select form-select-sm" name="driver_id" aria-label="Driver for <?= $date ?>">
                                <?php foreach ($drivers as $dr): ?><option value="<?= (int)$dr['driver_id'] ?>" <?= $w && (int)$w['driver_id'] === (int)$dr['driver_id'] ? 'selected' : '' ?>><?= htmlspecialchars($dr['name']) ?></option><?php endforeach; ?>
                            </select></div>
                            <?php if ($w): ?><div class="col-auto"><input class="form-control form-control-sm" name="reason" placeholder="Reason if replacing" maxlength="500" aria-label="Reason"></div><?php endif; ?>
                            <div class="col-auto"><button class="btn btn-sm btn-outline-success" type="submit"><?= $w ? 'Replace' : 'Set' ?></button></div>
                            <?php if ($w): ?><div class="col-auto"><button class="btn btn-sm btn-outline-secondary" name="action" value="clear">Clear</button></div><?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div></div>
<?php adminPageEnd(); ?>
