<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/recommender.php  -  DriverRecommender (SRS UC018: View Driver Recommendation)
//
// The system only RECOMMENDS. The Administrative Assistant (or Head of Admin) makes the final
// choice; nothing here ever assigns a driver by itself.
//
// STEP 1 - LICENCE FILTER      cars and vans need class D, lorries need GDL, buses need PSV.
// STEP 2 - REMOVE CLASHES      a driver who already has a request task or an internal task that
//                              OVERLAPS the trip time is removed (and cannot be chosen at all).
//                              Inactive drivers are removed too.
// STEP 3 - COUNT THE MONTH     for the month of the trip, per remaining driver:
//                                tasks        = request tasks + internal tasks + welfare duty days
//                                weekendTasks = request/internal tasks on Saturday or Sunday
//                                experience   = years since the date the driver joined the Vehicle Section
//                              (cancelled tasks never count)
// STEP 4 - WEIGHTED SCORE      score = 100 * ( W_TASKS * (1 - normTasks) + W_WEEKEND * (1 - normWeekend) + W_EXPERIENCE * normExperience )
//                              normX = the driver's value / the largest value among the candidates.
//                              So the driver with the FEWEST tasks and the fewest weekend tasks, and the MOST experience, scores
//                              highest. The number of tasks has the highest weight (default 0.5 / 0.3 / 0.2, in Settings).
// STEP 5 - FLAG                a driver on welfare duty on the trip date is flagged (not removed).
//
// The two formula functions are PURE (no database) so tests/RecommenderTest.php can check them.
// ============================================================

/** One driver's score, 0..100, higher = better choice. Pure function. The three weights should add up to 1. */
function recommenderScore(float $normTasks, float $normWeekend, float $normExperience, float $wTasks, float $wWeekend, float $wExperience): float
{
    $score = 100 * ($wTasks * (1 - $normTasks) + $wWeekend * (1 - $normWeekend) + $wExperience * $normExperience);
    return round(max(0.0, min(100.0, $score)), 1);
}

/** Years of experience from the date joined (0 when the date is unknown or in the future). Pure function. */
function driverExperienceYears(?string $dateJoined, ?string $today = null): float
{
    if ($dateJoined === null || $dateJoined === '' || !isValidDate($dateJoined)) { return 0.0; }
    $days = (strtotime($today ?? date('Y-m-d')) - strtotime($dateJoined)) / 86400;
    return $days > 0 ? round($days / 365.25, 1) : 0.0;
}

/**
 * Normalises, scores and sorts the candidates. Pure function.
 * Each candidate needs: tasks (int), weekend (int), experience (years, float), name (string).
 * Order: highest score, then fewest tasks, then name (stable and easy to explain).
 */
function recommenderRank(array $candidates, float $wTasks, float $wWeekend, float $wExperience): array
{
    $maxT = 0; $maxW = 0; $maxE = 0.0;
    foreach ($candidates as $c) {
        $maxT = max($maxT, (int)$c['tasks']);
        $maxW = max($maxW, (int)$c['weekend']);
        $maxE = max($maxE, (float)$c['experience']);
    }
    foreach ($candidates as &$c) {
        $c['norm_tasks']      = $maxT > 0 ? $c['tasks'] / $maxT : 0.0;
        $c['norm_weekend']    = $maxW > 0 ? $c['weekend'] / $maxW : 0.0;
        $c['norm_experience'] = $maxE > 0 ? $c['experience'] / $maxE : 0.0;
        $c['score']           = recommenderScore($c['norm_tasks'], $c['norm_weekend'], $c['norm_experience'], $wTasks, $wWeekend, $wExperience);
    }
    unset($c);
    usort($candidates, fn($a, $b) => [-$a['score'], $a['tasks'], $a['name']] <=> [-$b['score'], $b['tasks'], $b['name']]);
    foreach ($candidates as $i => &$c) { $c['rank'] = $i + 1; }
    unset($c);
    return $candidates;
}

// ------------------------------------------------------------
// Licence classes
// ------------------------------------------------------------

const LICENCE_CLASSES = ['D' => 'D (car, van)', 'GDL' => 'GDL (lorry)', 'PSV' => 'PSV (bus)'];
const VEHICLE_TYPES   = ['Car' => 'Car', 'Van' => 'Van', 'Lorry' => 'Lorry', 'Bus' => 'Bus'];

/** The licence class a vehicle type needs: Car/Van -> D, Lorry -> GDL, Bus -> PSV. */
function licenceForVehicleType(string $vehicle_type): string
{
    return match ($vehicle_type) {
        'Lorry' => 'GDL',
        'Bus', 'Minibus' => 'PSV',
        default => 'D',
    };
}

/** Does the driver's licence list (e.g. 'D,PSV') include the class? */
function driverHoldsLicence(?string $licence_list, string $required): bool
{
    if ($licence_list === null || $licence_list === '') { return false; }
    return in_array(strtoupper($required), array_map('trim', explode(',', strtoupper($licence_list))), true);
}

/** Keeps only valid classes, in the fixed order D, GDL, PSV. '' when none is valid. */
function normaliseLicenceList(array $classes): string
{
    $ok = array_values(array_intersect(array_keys(LICENCE_CLASSES), array_map('strtoupper', $classes)));
    return implode(',', $ok);
}

// ------------------------------------------------------------
// Clashes and workload
// ------------------------------------------------------------

/**
 * Why a driver cannot take a task in [start, end] (Y-m-d H:i:s), or null when free.
 * A clash = another non-cancelled request task or an active internal task that overlaps:
 *     start < other_end AND end > other_start
 * There is no override: a driver with a clash can never be chosen.
 */
function driverClashReason(mysqli $conn, int $driver_id, string $start, string $end,
                           int $except_schedule = 0, int $except_internal = 0): ?string
{
    $stmt = $conn->prepare(
        "SELECT start_dt, end_dt FROM schedules
          WHERE driver_id = ? AND status <> 'cancelled' AND schedule_id <> ?
            AND start_dt < ? AND end_dt > ? LIMIT 1"
    );
    $stmt->bind_param('iiss', $driver_id, $except_schedule, $end, $start);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return 'Already has a trip ' . date('d M H:i', strtotime($row['start_dt'])) . ' - ' . date('H:i', strtotime($row['end_dt']));
    }
    $stmt = $conn->prepare(
        "SELECT start_dt, end_dt FROM internal_tasks
          WHERE driver_id = ? AND status = 'active' AND task_id <> ?
            AND start_dt < ? AND end_dt > ? LIMIT 1"
    );
    $stmt->bind_param('iiss', $driver_id, $except_internal, $end, $start);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return 'Has an internal task ' . date('d M H:i', strtotime($row['start_dt'])) . ' - ' . date('H:i', strtotime($row['end_dt']));
    }
    return null;
}

/** Is a task "outside working hours"? Saturday/Sunday, before WORK_START or after WORK_END. */
function isOutsideWorkingHours(mysqli $conn, string $date, string $start_time, string $end_time): bool
{
    $ws = substr(getSetting($conn, 'WORK_START'), 0, 5) . ':00';
    $we = substr(getSetting($conn, 'WORK_END'), 0, 5) . ':00';
    return in_array((int)date('N', strtotime($date)), [6, 7], true) || $start_time < $ws || $end_time > $we;
}

/**
 * Every driver's workload in a month (one query):
 *   tasks   = request tasks + internal tasks + welfare duty days
 *   outside = request tasks + internal tasks outside working hours (welfare days are not counted here)
 *   weekend = request tasks + internal tasks on Saturday or Sunday (welfare days are not counted here)
 *   welfare = welfare duty days
 * @return array<int, array{tasks:int, outside:int, weekend:int, welfare:int}> keyed by driver_id
 */
function driverMonthCounts(mysqli $conn, string $month): array
{
    $ws = substr(getSetting($conn, 'WORK_START'), 0, 5) . ':00';
    $we = substr(getSetting($conn, 'WORK_END'), 0, 5) . ':00';
    $from = $month . '-01';
    $to   = date('Y-m-t', strtotime($from));
    $stmt = $conn->prepare(
        "SELECT driver_id, COUNT(*) AS tasks, COALESCE(SUM(out_h), 0) AS outside, COALESCE(SUM(wk), 0) AS weekend, COALESCE(SUM(is_w), 0) AS welfare FROM (
             SELECT driver_id, (DAYOFWEEK(trip_date) IN (1, 7) OR start_time < ? OR end_time > ?) AS out_h, (DAYOFWEEK(trip_date) IN (1, 7)) AS wk, 0 AS is_w
               FROM schedules WHERE driver_id IS NOT NULL AND status <> 'cancelled' AND trip_date BETWEEN ? AND ?
             UNION ALL
             SELECT driver_id, (DAYOFWEEK(task_date) IN (1, 7) OR start_time < ? OR end_time > ?) AS out_h, (DAYOFWEEK(task_date) IN (1, 7)) AS wk, 0 AS is_w
               FROM internal_tasks WHERE status = 'active' AND task_date BETWEEN ? AND ?
             UNION ALL
             SELECT driver_id, 0 AS out_h, 0 AS wk, 1 AS is_w FROM welfare_roster WHERE duty_date BETWEEN ? AND ?
         ) x GROUP BY driver_id"
    );
    $stmt->bind_param('ssssssssss', $ws, $we, $from, $to, $ws, $we, $from, $to, $from, $to);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[(int)$r['driver_id']] = ['tasks' => (int)$r['tasks'], 'outside' => (int)$r['outside'], 'weekend' => (int)$r['weekend'], 'welfare' => (int)$r['welfare']];
    }
    $stmt->close();
    return $out;
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

/**
 * UC018: recommends drivers for one task.
 *
 * @param array $task start (Y-m-d H:i:s), end, licence ('D'|'GDL'|'PSV'|null = any),
 *                    except_schedule (a task being replaced does not clash with itself)
 * @return array{ranked: array, excluded: array, weights: array}
 *   ranked   : driver_id, name, employee_id, phone, license_class, tasks, weekend, experience (years), welfare (flag), score, rank
 *   excluded : driver_id, name, reason
 */
function recommendDrivers(mysqli $conn, array $task): array
{
    $start = $task['start'];
    $end   = $task['end'];
    $need  = $task['licence'] ?? null;
    $except = (int)($task['except_schedule'] ?? 0);
    $w = ['tasks' => (float)getSetting($conn, 'W_TASKS'), 'weekend' => (float)getSetting($conn, 'W_WEEKEND'), 'experience' => (float)getSetting($conn, 'W_EXPERIENCE')];
    $sum = $w['tasks'] + $w['weekend'] + $w['experience'];
    if ($sum > 0 && abs($sum - 1.0) > 0.0001) { foreach ($w as $k => $x) { $w[$k] = $x / $sum; } }      // always on a 0-100 scale, whatever the weights are

    $drivers = $conn->query("SELECT driver_id, name, employee_id, phone, license_class, status, date_joined FROM drivers ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    $counts  = driverMonthCounts($conn, substr($start, 0, 7));
    $welfare = [];
    for ($d = strtotime(substr($start, 0, 10)); $d <= strtotime(substr($end, 0, 10)); $d += 86400) {
        $welfare += welfareDriverIdsOn($conn, date('Y-m-d', $d));
    }

    $candidates = []; $excluded = [];
    foreach ($drivers as $d) {
        $id = (int)$d['driver_id'];
        $why = null;
        if ($d['status'] !== 'active') {
            $why = 'Not an active driver';
        } elseif ($need !== null && !driverHoldsLicence($d['license_class'], $need)) {
            $why = 'Licence class ' . $need . ' needed';
        } else {
            $why = driverUnavailableReason($conn, $id, $start, $end) ?? driverClashReason($conn, $id, $start, $end, $except);   // UC018 1.3: marked unavailable (UC030)
        }
        if ($why !== null) {
            $excluded[] = ['driver_id' => $id, 'name' => $d['name'], 'reason' => $why];
            continue;
        }
        $c = $counts[$id] ?? ['tasks' => 0, 'outside' => 0, 'weekend' => 0, 'welfare' => 0];
        $candidates[] = [
            'driver_id' => $id, 'name' => $d['name'], 'employee_id' => $d['employee_id'], 'phone' => $d['phone'],
            'license_class' => $d['license_class'],
            'tasks' => $c['tasks'], 'outside' => $c['outside'], 'weekend' => $c['weekend'], 'experience' => driverExperienceYears($d['date_joined'], substr($start, 0, 10)), 'welfare_days' => $c['welfare'],
            'welfare' => isset($welfare[$id]),                       // on welfare duty on the trip date: FLAG
        ];
    }
    return ['ranked' => recommenderRank($candidates, $w['tasks'], $w['weekend'], $w['experience']), 'excluded' => $excluded, 'weights' => $w];
}
