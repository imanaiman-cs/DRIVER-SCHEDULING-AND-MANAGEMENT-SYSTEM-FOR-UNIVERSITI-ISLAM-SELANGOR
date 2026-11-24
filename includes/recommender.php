<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/recommender.php  -  UC009 Recommend Driver
//
//   1.1 keep only ACTIVE drivers who hold a VALID (not expired) licence of the class the vehicle needs
//       (D: car, MPV, van; GDL: lorry; PSV: bus)
//   1.2 remove drivers who already have a request task or an internal task during the task period, or an
//       APPROVED schedule change on that date
//   1.3 count each driver's tasks this month (request tasks, internal tasks and welfare duty) and weekend tasks
//   1.4 weighted score: tasks this month 50% (fewer is better), weekend tasks 30% (fewer is better),
//       years of experience from the start date of service 20% (more is better)
//   1.5 ranked list with the task counts; drivers on welfare duty that day are MARKED (not removed)
// The best RECOMMEND_TOP drivers are "recommended"; choosing another driver needs a confirmation (UC003 A2).
// The weights are settings (W_TASKS, W_WEEKEND, W_EXPERIENCE).
// ============================================================

/** Task statuses that keep a driver busy and count in the workload. */
const BUSY_TASK_STATUSES_SQL = "('driver_assigned','approved','completed')";

/**
 * The weighted score of one driver (0..1, higher is better).
 * Each part is normalised against the largest value among the candidates.
 */
function recommenderScore(int $tasks, int $weekend, float $years, int $maxTasks, int $maxWeekend, float $maxYears, array $w): float
{
    $t = $maxTasks > 0 ? 1 - $tasks / $maxTasks : 1.0;
    $we = $maxWeekend > 0 ? 1 - $weekend / $maxWeekend : 1.0;
    $e = $maxYears > 0 ? $years / $maxYears : 0.0;
    return round($w['tasks'] * $t + $w['weekend'] * $we + $w['experience'] * $e, 4);
}

/** Sorts candidates by score (best first); ties: fewer tasks, then name. Adds rank and recommended. */
function recommenderRank(array $candidates, int $top): array
{
    usort($candidates, fn($a, $b) => [$b['score'], $a['tasks'], $a['name']] <=> [$a['score'], $b['tasks'], $b['name']]);
    foreach ($candidates as $i => &$c) { $c['rank'] = $i + 1; $c['recommended'] = $i < $top; }
    return $candidates;
}

function recommenderWeights(mysqli $conn): array
{
    return ['tasks' => getSettingFloat($conn, 'W_TASKS'), 'weekend' => getSettingFloat($conn, 'W_WEEKEND'), 'experience' => getSettingFloat($conn, 'W_EXPERIENCE')];
}

/** Years of service on a date. */
function experienceYears(?string $startDate, string $onDate): float
{
    if (!$startDate) { return 0.0; }
    return max(0.0, round((strtotime($onDate) - strtotime($startDate)) / (365.25 * 86400), 1));
}

/**
 * Task counts of every driver for a month (Y-m): request tasks, internal tasks, welfare duties, weekend tasks, total.
 * @return array<int, array{request:int, internal:int, welfare:int, weekend:int, tasks:int}>
 */
function driverMonthCounts(mysqli $conn, string $month): array
{
    $from = $month . '-01 00:00:00';
    $to   = date('Y-m-d 00:00:00', strtotime($month . '-01 +1 month'));
    $rows = dbAll($conn, "SELECT driver_id, task_source, SUM(1) AS n, SUM(DAYOFWEEK(start_dt) IN (1, 7)) AS wk
                            FROM tasks WHERE driver_id IS NOT NULL AND status IN " . BUSY_TASK_STATUSES_SQL . " AND start_dt >= ? AND start_dt < ?
                           GROUP BY driver_id, task_source", [$from, $to]);
    $out = [];
    foreach ($rows as $r) {
        $d = (int)$r['driver_id'];
        $out[$d] ??= ['request' => 0, 'internal' => 0, 'welfare' => 0, 'weekend' => 0, 'tasks' => 0];
        $out[$d][$r['task_source']] += (int)$r['n'];
        $out[$d]['weekend'] += (int)$r['wk'];
        $out[$d]['tasks'] += (int)$r['n'];
    }
    return $out;
}

/** The approved schedule change of a driver that covers any date of [$start, $end), or null. */
function driverScheduleChangeOn(mysqli $conn, int $driver_id, string $start, string $end): ?array
{
    $d1 = substr($start, 0, 10);
    $d2 = date('Y-m-d', strtotime($end) - 1);
    return dbOne($conn, "SELECT * FROM schedule_changes WHERE driver_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ? LIMIT 1", [$driver_id, $d2, $d1]);
}

/** The first request task or internal task of the driver that overlaps [$start, $end), or null. */
function driverClash(mysqli $conn, int $driver_id, string $start, string $end, int $exceptRequest = 0, int $exceptTask = 0): ?array
{
    return dbOne($conn, "SELECT t.task_id, t.task_source, t.task_type, t.trip_type, t.request_id, t.start_dt, t.end_dt FROM tasks t
                          WHERE t.driver_id = ? AND t.task_source IN ('request','internal') AND t.status IN " . BUSY_TASK_STATUSES_SQL . "
                            AND t.task_id <> ? AND NOT (t.request_id IS NOT NULL AND t.request_id = ?)
                            AND t.start_dt < ? AND t.end_dt > ? LIMIT 1", [$driver_id, $exceptTask, $exceptRequest, $end, $start]);
}

/**
 * Why a driver cannot take a task, or null. Used by every assignment (UC003, UC005, UC007, UC008) so a driver who
 * fails the UC009 filters can never be saved, even when chosen outside the recommendation.
 */
function driverUnavailableReason(mysqli $conn, int $driver_id, string $licence, string $start, string $end, int $exceptRequest = 0, int $exceptTask = 0): ?string
{
    $d = dbOne($conn, 'SELECT d.*, u.status, u.full_name FROM drivers d JOIN users u ON u.user_id = d.user_id WHERE d.driver_id = ?', [$driver_id]);
    if (!$d) { return 'unknown driver'; }
    if ($d['status'] !== 'active') { return 'the account is not active'; }
    if (!driverHoldsLicence($d['licence_classes'], $licence)) { return "no $licence licence"; }
    if ($d['licence_expiry'] < substr($end, 0, 10)) { return 'the licence has expired (' . fmtD($d['licence_expiry']) . ')'; }
    if ($sc = driverScheduleChangeOn($conn, $driver_id, $start, $end)) { return 'approved schedule change (' . fmtD($sc['start_date']) . ($sc['end_date'] !== $sc['start_date'] ? ' - ' . fmtD($sc['end_date']) : '') . ')'; }
    if ($c = driverClash($conn, $driver_id, $start, $end, $exceptRequest, $exceptTask)) {
        return 'already has a ' . ($c['task_source'] === 'request' ? 'request task' . ($c['request_id'] ? ' (#' . $c['request_id'] . ')' : '') : 'internal task') . ' at ' . fmtPeriod($c['start_dt'], $c['end_dt']);
    }
    return null;
}

/** Driver ids on welfare duty during [$start, $end). */
function welfareDriverIdsDuring(mysqli $conn, string $start, string $end): array
{
    return array_map('intval', array_column(dbAll($conn, "SELECT DISTINCT driver_id FROM tasks WHERE task_source = 'welfare' AND driver_id IS NOT NULL
                                                              AND status <> 'cancelled' AND start_dt < ? AND end_dt > ?", [$end, $start]), 'driver_id'));
}

/**
 * UC009. $task: licence (D|GDL|PSV), start, end, except_request, except_task, exclude_driver (the current driver in UC008).
 * @return array{ranked: array, excluded: array}
 *   ranked:   driver_id, name, phone, tasks, request, internal, welfare, weekend, years, score, rank, recommended, on_welfare
 *   excluded: driver_id, name, reason
 */
function recommendDrivers(mysqli $conn, array $task): array
{
    $start = $task['start']; $end = $task['end'];
    $month = substr($start, 0, 7);
    $counts = driverMonthCounts($conn, $month);
    $welfare = array_flip(welfareDriverIdsDuring($conn, $start, $end));
    $drivers = dbAll($conn, "SELECT d.driver_id, d.start_date, u.full_name AS name, u.phone FROM drivers d JOIN users u ON u.user_id = d.user_id
                              WHERE u.status = 'active' ORDER BY u.full_name");
    $cands = []; $excluded = [];
    foreach ($drivers as $d) {
        $id = (int)$d['driver_id'];
        if ($id === (int)($task['exclude_driver'] ?? 0)) { continue; }
        $why = driverUnavailableReason($conn, $id, $task['licence'], $start, $end, (int)($task['except_request'] ?? 0), (int)($task['except_task'] ?? 0));
        if ($why !== null) { $excluded[] = ['driver_id' => $id, 'name' => $d['name'], 'reason' => $why]; continue; }
        $c = $counts[$id] ?? ['request' => 0, 'internal' => 0, 'welfare' => 0, 'weekend' => 0, 'tasks' => 0];
        $cands[] = ['driver_id' => $id, 'name' => $d['name'], 'phone' => $d['phone'], 'years' => experienceYears($d['start_date'], substr($start, 0, 10)),
                    'on_welfare' => isset($welfare[$id])] + $c;
    }
    $maxT = $cands ? max(array_column($cands, 'tasks')) : 0;
    $maxW = $cands ? max(array_column($cands, 'weekend')) : 0;
    $maxY = $cands ? max(array_column($cands, 'years')) : 0.0;
    $w = recommenderWeights($conn);
    foreach ($cands as &$c) { $c['score'] = recommenderScore($c['tasks'], $c['weekend'], $c['years'], $maxT, $maxW, (float)$maxY, $w); }
    unset($c);
    return ['ranked' => recommenderRank($cands, max(1, getSettingInt($conn, 'RECOMMEND_TOP'))), 'excluded' => $excluded];
}

/**
 * HTML <option>s for a driver picker: recommended drivers first, then the others (marked "outside the recommendation";
 * the page asks for a confirmation with the task count, UC003 A2). Excluded drivers are not offered.
 */
function driverOptionsHtml(array $rec, int $selected = 0): string
{
    $h = '<option value="">Select a driver</option>';
    $groups = ['Recommended' => array_filter($rec['ranked'], fn($c) => $c['recommended']),
               'Outside the recommendation' => array_filter($rec['ranked'], fn($c) => !$c['recommended'])];
    foreach ($groups as $label => $list) {
        if (!$list) { continue; }
        $h .= '<optgroup label="' . h($label) . '">';
        foreach ($list as $c) {
            $txt = '#' . $c['rank'] . ' ' . $c['name'] . ' - ' . $c['tasks'] . ' tasks, ' . $c['weekend'] . ' weekend, ' . $c['years'] . ' yrs'
                 . ($c['on_welfare'] ? ' (on welfare duty)' : '');
            $h .= '<option value="' . (int)$c['driver_id'] . '" data-tasks="' . (int)$c['tasks'] . '" data-name="' . h($c['name']) . '" data-outside="' . ($c['recommended'] ? '0' : '1') . '"'
                . ((int)$c['driver_id'] === $selected ? ' selected' : '') . '>' . h($txt) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}

/** The ranked table shown next to a picker (UC009 1.5). */
function recommendationTableHtml(array $rec, string $caption = ''): string
{
    if (!$rec['ranked']) {
        return '<div class="alert alert-soft alert-warning text-sm"><i class="fas fa-triangle-exclamation"></i><span>No suitable driver is available for this period.</span></div>';
    }
    $h = '<div class="overflow-x-auto"><table class="table table-sm">' . ($caption !== '' ? '<caption class="text-start text-xs text-base-content/65 pb-1">' . h($caption) . '</caption>' : '')
       . '<thead><tr><th>#</th><th>Driver</th><th class="text-end">Tasks this month</th><th class="text-end">Weekend</th><th class="text-end">Experience</th><th class="text-end">Score</th></tr></thead><tbody>';
    foreach ($rec['ranked'] as $c) {
        $h .= '<tr class="' . ($c['recommended'] ? 'bg-success/5' : '') . '"><td>' . (int)$c['rank'] . '</td><td>' . h($c['name'])
            . ($c['recommended'] ? ' <span class="badge badge-success badge-xs">Recommended</span>' : '')
            . ($c['on_welfare'] ? ' <span class="badge badge-warning badge-xs" title="On welfare duty on that date">Welfare duty</span>' : '')
            . '<div class="text-xs text-base-content/60">' . (int)$c['request'] . ' request, ' . (int)$c['internal'] . ' internal, ' . (int)$c['welfare'] . ' welfare</div></td>'
            . '<td class="text-end">' . (int)$c['tasks'] . '</td><td class="text-end">' . (int)$c['weekend'] . '</td><td class="text-end">' . h($c['years']) . ' yrs</td>'
            . '<td class="text-end font-medium">' . number_format($c['score'] * 100, 0) . '%</td></tr>';
    }
    $h .= '</tbody></table></div>';
    if ($rec['excluded']) {
        $h .= '<details class="mt-2 text-xs text-base-content/70"><summary class="cursor-pointer">' . count($rec['excluded']) . ' driver(s) not available</summary><ul class="ms-4 mt-1 list-disc">';
        foreach ($rec['excluded'] as $x) { $h .= '<li>' . h($x['name']) . ': ' . h($x['reason']) . '</li>'; }
        $h .= '</ul></details>';
    }
    return $h;
}
