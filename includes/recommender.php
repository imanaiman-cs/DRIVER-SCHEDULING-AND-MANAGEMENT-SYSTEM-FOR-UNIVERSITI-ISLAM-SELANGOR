<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/recommender.php  -  DriverRecommender (weighted scoring)
//
// STEP 1 - HARD FILTERS. A driver is removed from the list (the reason is kept, so the admin can
//          see WHY) when they are:
//            - not active, or of the wrong type (Top Management drivers serve Top Management trips only; R8)
//            - missing the licence class the vehicle needs                          (R5: BLOCKED)
//            - on leave / MC on that date
//            - already on another trip in that time slot, or at the daily limit
// STEP 2 - SCORE the drivers that are left, 0..100, higher = better choice:
//
//     score = 100 - ( W_TASKS * normTasks + W_AFTER_HOURS * normAfterHours ) * 100 - welfarePenalty
//
//     tasks          = trips + internal tasks the driver already has in the trip's month
//     afterHours     = how many of those are on Sat/Sun, start before WORK_START or end after WORK_END
//     normTasks      = tasks / (largest task count among the candidates)             -> 0..1
//     normAfterHours = afterHours / (largest after-hours count among the candidates) -> 0..1
//     welfarePenalty = WELFARE_PENALTY points if the driver is on welfare duty that day
//
//   In words: the driver with the FEWEST jobs this month scores highest, so the work is shared
//   fairly; evenings and weekends count extra. W_TASKS (0.6), W_AFTER_HOURS (0.4) and
//   WELFARE_PENALTY (20) are in the settings table and the admin can change them.
// STEP 3 - WARN about a clash with an internal task (a warning, not a block). The admin decides.
//
// The two formula functions are PURE (no database), so tests/RecommenderTest.php can check them.
// ============================================================

/** One driver's score. Pure function. */
function recommenderScore(float $normTasks, float $normAfterHours, bool $onWelfare,
                          float $wTasks, float $wAfterHours, float $welfarePenalty): float
{
    $score = 100 - ($wTasks * $normTasks + $wAfterHours * $normAfterHours) * 100 - ($onWelfare ? $welfarePenalty : 0);
    return round(max(0.0, min(100.0, $score)), 1);
}

/**
 * Normalises, scores and sorts candidates. Pure function.
 * Each candidate needs: tasks (int), after_hours (int), welfare (bool), name (string), warnings (array).
 * Order: no warning first, then highest score, then fewest tasks, then name (stable and explainable).
 */
function recommenderRank(array $candidates, float $wTasks, float $wAfterHours, float $welfarePenalty): array
{
    $maxT = 0; $maxA = 0;
    foreach ($candidates as $c) {
        $maxT = max($maxT, (int)$c['tasks']);
        $maxA = max($maxA, (int)$c['after_hours']);
    }
    foreach ($candidates as &$c) {
        $c['norm_tasks'] = $maxT > 0 ? $c['tasks'] / $maxT : 0.0;
        $c['norm_after'] = $maxA > 0 ? $c['after_hours'] / $maxA : 0.0;
        $c['score'] = recommenderScore($c['norm_tasks'], $c['norm_after'], (bool)$c['welfare'], $wTasks, $wAfterHours, $welfarePenalty);
    }
    unset($c);
    usort($candidates, fn($a, $b) =>
        [count($a['warnings'] ?? []) > 0, -$a['score'], $a['tasks'], $a['name']]
        <=> [count($b['warnings'] ?? []) > 0, -$b['score'], $b['tasks'], $b['name']]);
    return $candidates;
}

/**
 * Recommends drivers for one trip.
 *
 * @param array $trip date, start, end (HH:MM[:SS]); optional vehicle_type, trip_type ('regular' default),
 *                    exclude_schedule_id (a schedule being edited does not clash with itself)
 * @return array{ranked: array, excluded: array, weights: array}
 */
function recommendDrivers(mysqli $conn, array $trip): array
{
    $date  = $trip['date'];
    $start = $trip['start'];
    $end   = $trip['end'];
    $type  = in_array($trip['trip_type'] ?? 'regular', ['top_management', 'regular'], true) ? ($trip['trip_type'] ?? 'regular') : 'regular';
    $excl  = (int)($trip['exclude_schedule_id'] ?? 0);
    $required = !empty($trip['vehicle_type']) ? requiredLicenseClasses((string)$trip['vehicle_type']) : null;

    $w = [
        'tasks'   => (float)getSetting($conn, 'W_TASKS'),
        'after'   => (float)getSetting($conn, 'W_AFTER_HOURS'),
        'welfare' => (float)getSetting($conn, 'WELFARE_PENALTY'),
    ];

    $drivers = $conn->query("SELECT driver_id, name, employee_id, phone, experience_years, license_class, driver_type, status
                               FROM drivers ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    $counts  = getMonthlyTaskCounts($conn, substr($date, 0, 7));

    $welfare = [];
    $r = $conn->query("SELECT driver_id FROM welfare_roster WHERE duty_date = '" . $conn->real_escape_string($date) . "'");
    while ($r && ($x = $r->fetch_assoc())) { $welfare[(int)$x['driver_id']] = true; }

    $leaves = [];
    $stmt = $conn->prepare('SELECT driver_id, leave_type, start_date, end_date FROM driver_leaves WHERE start_date <= ? AND end_date >= ?');
    $stmt->bind_param('ss', $date, $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $lv) { $leaves[(int)$lv['driver_id']] = $lv; }
    $stmt->close();

    // Trips already booked in the slot, and how many hours each driver already drives that day
    $busy = []; $hours = [];
    $stmt = $conn->prepare("SELECT driver_id, schedule_id, start_time, end_time FROM schedules
                             WHERE trip_date = ? AND driver_id IS NOT NULL AND status <> 'cancelled'");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $sc) {
        if ((int)$sc['schedule_id'] === $excl) { continue; }
        $did = (int)$sc['driver_id'];
        $hours[$did] = ($hours[$did] ?? 0) + (strtotime($sc['end_time']) - strtotime($sc['start_time'])) / 3600;
        if ($sc['start_time'] < $end && $sc['end_time'] > $start) { $busy[$did] = true; }
    }
    $stmt->close();

    // Internal tasks that overlap the slot: a WARNING only
    $task_clash = [];
    $stmt = $conn->prepare('SELECT driver_id, task_type, start_time, end_time FROM internal_tasks WHERE task_date = ? AND start_time < ? AND end_time > ?');
    $stmt->bind_param('sss', $date, $end, $start);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
        $task_clash[(int)$t['driver_id']][] = 'Internal task ' . str_replace('_', ' ', $t['task_type']) . ' ' . substr($t['start_time'], 0, 5) . '-' . substr($t['end_time'], 0, 5);
    }
    $stmt->close();

    $trip_hours = max(0.0, (strtotime($end) - strtotime($start)) / 3600);
    $candidates = []; $excluded = [];
    foreach ($drivers as $d) {
        $id = (int)$d['driver_id'];
        $why = null;
        if ($d['status'] !== 'active') {
            $why = $d['status'] === 'on_leave' ? 'Marked as on leave' : 'Not an active driver';
        } elseif (($d['driver_type'] ?? 'regular') !== $type) {
            $why = $type === 'regular' ? 'Top Management driver (only for Top Management trips)' : 'Regular driver (this is a Top Management trip)';
        } elseif ($required !== null && !driverHasLicense($d['license_class'] ?? null, $required)) {
            $why = 'Licence class ' . implode('/', $required) . ' needed';
        } elseif (isset($leaves[$id])) {
            $why = 'On ' . ($leaves[$id]['leave_type'] === 'mc' ? 'MC' : 'leave') . ' ' . date('d M', strtotime($leaves[$id]['start_date'])) . ' - ' . date('d M', strtotime($leaves[$id]['end_date']));
        } elseif (isset($busy[$id])) {
            $why = 'Already on a trip at this time';
        } elseif (($hours[$id] ?? 0) + $trip_hours > 8) {
            $why = 'Would exceed 8 hours that day';
        }
        if ($why !== null) {
            $excluded[] = ['driver_id' => $id, 'name' => $d['name'], 'reason' => $why];
            continue;
        }
        $c = $counts[$id] ?? ['tasks' => 0, 'weekend' => 0];
        $candidates[] = [
            'driver_id' => $id, 'name' => $d['name'], 'employee_id' => $d['employee_id'], 'phone' => $d['phone'],
            'license_class' => $d['license_class'], 'experience_years' => $d['experience_years'],
            'tasks' => $c['tasks'], 'after_hours' => $c['weekend'],
            'welfare' => isset($welfare[$id]),
            'warnings' => $task_clash[$id] ?? [],
            'daily_trips' => 0, 'daily_hours' => round($hours[$id] ?? 0, 1),
        ];
    }
    return ['ranked' => recommenderRank($candidates, $w['tasks'], $w['after'], $w['welfare']), 'excluded' => $excluded, 'weights' => $w];
}

/** Leave / MC of a driver on a date, or null. (Cached per date, so loops stay cheap.) */
function driverLeaveOn(mysqli $conn, int $driver_id, string $date): ?array
{
    static $cache = [];
    if (!isset($cache[$date])) {
        $cache[$date] = [];
        $stmt = $conn->prepare('SELECT driver_id, leave_type, start_date, end_date FROM driver_leaves WHERE start_date <= ? AND end_date >= ?');
        $stmt->bind_param('ss', $date, $date);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $cache[$date][(int)$r['driver_id']] = $r; }
        $stmt->close();
    }
    return $cache[$date][$driver_id] ?? null;
}

/** Drivers on welfare duty on a date: [driver_id => true]. */
function welfareDriverIdsOn(mysqli $conn, string $date): array
{
    $out = [];
    $stmt = $conn->prepare('SELECT driver_id FROM welfare_roster WHERE duty_date = ?');
    $stmt->bind_param('s', $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $out[(int)$r['driver_id']] = true; }
    $stmt->close();
    return $out;
}

/** Is a trip "after hours"? Saturday/Sunday, before the office opens or after it closes. */
function isAfterHoursTrip(mysqli $conn, string $date, string $start, string $end): bool
{
    $ws = getSetting($conn, 'WORK_START') . ':00';
    $we = getSetting($conn, 'WORK_END') . ':00';
    return in_array((int)date('N', strtotime($date)), [6, 7], true) || $start < $ws || $end > $we;
}
