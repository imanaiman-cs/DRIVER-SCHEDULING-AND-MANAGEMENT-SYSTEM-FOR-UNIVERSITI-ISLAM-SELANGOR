<?php
/**
 * DriverRecommender - suggests which driver should take a trip LEG (R6).
 *
 * STEP 1 - HARD FILTERS. A driver is removed from the list (and the reason is kept, so the
 * admin can see why) when they are:
 *     - not an active driver, or in the Management group (R8: management drivers serve top management only)
 *     - missing the licence class the vehicle needs (R5: wrong licence is BLOCKED)
 *     - on leave / MC on the leg's date
 *
 * STEP 2 - SCORE the drivers that are left, 0..100, higher = better choice:
 *
 *     score = 100 - ( W_TASKS * normTasks + W_AFTER_HOURS * normAfterHours ) * 100 - welfarePenalty
 *
 *     tasks          = trip legs + internal tasks the driver already has in the leg's month
 *     afterHours     = how many of those are after hours (Saturday/Sunday, before WORK_START or after WORK_END)
 *     normTasks      = tasks / (largest task count among the candidates)       -> 0..1
 *     normAfterHours = afterHours / (largest after-hours count among candidates) -> 0..1
 *     welfarePenalty = WELFARE_PENALTY points if the driver is on the welfare roster that day
 *
 * In words: the driver with the FEWEST jobs this month scores highest, so the work is shared fairly;
 * after-hours work counts extra so nobody carries all the evenings and weekends.
 * W_TASKS, W_AFTER_HOURS (default 0.6 + 0.4 = 1) and WELFARE_PENALTY (default 20) live in the
 * settings table, so the admin can tune them without any code change.
 *
 * A time clash (R5) does NOT change the score; it is a warning. Drivers with a clash are only
 * listed after the free ones, because suggesting a busy driver first would be unhelpful.
 */
class DriverRecommender extends Model
{
    /** Pure function (no database): the formula above for ONE driver. Kept separate so it can be unit-tested. */
    public static function score(float $normTasks, float $normAfterHours, bool $onWelfare, float $wTasks, float $wAfterHours, float $welfarePenalty): float
    {
        $score = 100 - ($wTasks * $normTasks + $wAfterHours * $normAfterHours) * 100 - ($onWelfare ? $welfarePenalty : 0);
        return round(max(0.0, min(100.0, $score)), 1);
    }

    /**
     * Pure function: normalise, score and sort a list of candidates.
     * Each candidate needs: tasks (int), after_hours (int), welfare (bool), name (string), clashes (array).
     * Sorting: no clash first, then highest score, then fewest tasks, then name (so the order is stable and explainable).
     */
    public static function rank(array $candidates, float $wTasks, float $wAfterHours, float $welfarePenalty): array
    {
        $maxTasks = 0; $maxAfter = 0;
        foreach ($candidates as $c) {
            $maxTasks = max($maxTasks, (int)$c['tasks']);
            $maxAfter = max($maxAfter, (int)$c['after_hours']);
        }
        foreach ($candidates as &$c) {
            $c['norm_tasks'] = $maxTasks > 0 ? $c['tasks'] / $maxTasks : 0.0;
            $c['norm_after'] = $maxAfter > 0 ? $c['after_hours'] / $maxAfter : 0.0;
            $c['score'] = self::score($c['norm_tasks'], $c['norm_after'], (bool)$c['welfare'], $wTasks, $wAfterHours, $welfarePenalty);
        }
        unset($c);
        usort($candidates, fn($a, $b) =>
            [count($a['clashes'] ?? []) > 0, -$a['score'], $a['tasks'], $a['name']]
            <=> [count($b['clashes'] ?? []) > 0, -$b['score'], $b['tasks'], $b['name']]);
        return $candidates;
    }

    /**
     * Recommend drivers for one leg.
     * @param array $leg            a request_legs row (start_datetime, end_datetime)
     * @param string $requiredLicence the vehicle's required_licence (D, GDL or PSV)
     * @return array{ranked: array, excluded: array, weights: array}
     */
    public function recommend(array $leg, string $requiredLicence, int $exceptRequestId = 0): array
    {
        $settings = new Setting();
        $w = [
            'tasks'   => (float)($settings->get('W_TASKS', '0.6')),
            'after'   => (float)($settings->get('W_AFTER_HOURS', '0.4')),
            'welfare' => (float)($settings->get('WELFARE_PENALTY', '20')),
        ];
        $workStart = $settings->get('WORK_START', '08:00') . ':00';
        $workEnd   = $settings->get('WORK_END', '17:30') . ':00';

        $start = $leg['start_datetime']; $end = $leg['end_datetime'];
        $day   = substr($start, 0, 10);
        $monthStart = substr($start, 0, 7) . '-01';
        $monthEnd   = date('Y-m-d', strtotime($monthStart . ' +1 month'));
        $availability = new AvailabilityService();

        // Every driver, with the facts the filters need.
        $drivers = $this->fetchAll(
            "SELECT d.id, d.driver_group, d.is_active, u.name, u.phone, u.is_active AS user_active,
                    (SELECT GROUP_CONCAT(licence_class) FROM driver_licences l WHERE l.driver_id = d.id) AS licences
               FROM drivers d JOIN users u ON u.id = d.user_id ORDER BY u.name"
        );

        $counts = $this->monthlyCounts($monthStart, $monthEnd, $workStart, $workEnd, $exceptRequestId);
        $welfare = array_flip(array_column($this->fetchAll('SELECT driver_id FROM welfare_roster WHERE duty_date = ?', [$day]), 'driver_id'));

        $candidates = []; $excluded = [];
        foreach ($drivers as $d) {
            $id = (int)$d['id'];
            $why = null;
            if (!$d['is_active'] || !$d['user_active']) {
                $why = 'Not an active driver';
            } elseif ($d['driver_group'] !== 'carpool') {
                $why = 'Management driver (not used for normal requests)';
            } elseif (!in_array($requiredLicence, explode(',', (string)$d['licences']), true)) {
                $why = "No $requiredLicence licence";
            } elseif ($leave = $availability->driverLeave($id, $day, substr($end, 0, 10))) {
                $why = 'On ' . ($leave['type'] === 'mc' ? 'MC' : 'leave') . ' ' . fmt_date($leave['start_date']) . ' - ' . fmt_date($leave['end_date']);
            }
            if ($why !== null) {
                $excluded[] = ['id' => $id, 'name' => $d['name'], 'reason' => $why];
                continue;
            }
            $candidates[] = [
                'id' => $id, 'name' => $d['name'], 'phone' => $d['phone'],
                'tasks' => $counts[$id]['tasks'] ?? 0,
                'after_hours' => $counts[$id]['after_hours'] ?? 0,
                'welfare' => isset($welfare[$id]),
                'clashes' => $availability->driverClashes($id, $start, $end, $exceptRequestId),   // R5: warning only
            ];
        }
        return [
            'ranked'   => self::rank($candidates, $w['tasks'], $w['after'], $w['welfare']),
            'excluded' => $excluded,
            'weights'  => $w,
        ];
    }

    /**
     * Tasks and after-hours tasks per driver inside a month.
     * A "task" is a trip leg of a request that is Driver Assigned / Confirmed / Completed, or an internal task.
     * After-hours = Saturday/Sunday, or starting before WORK_START, or ending after WORK_END (or past midnight).
     *
     * @return array<int,array{tasks:int,after_hours:int}>
     */
    private function monthlyCounts(string $from, string $to, string $workStart, string $workEnd, int $exceptRequestId): array
    {
        $afterLegs = "(DAYOFWEEK(l.start_datetime) IN (1,7) OR TIME(l.start_datetime) < ? OR TIME(l.end_datetime) > ? OR DATE(l.end_datetime) > DATE(l.start_datetime))";
        $afterTask = "(DAYOFWEEK(t.task_date) IN (1,7) OR t.start_time < ? OR t.end_time > ?)";
        $rows = $this->fetchAll(
            "SELECT driver_id, COUNT(*) AS tasks, SUM(is_after) AS after_hours FROM (
                SELECT l.driver_id, IF($afterLegs, 1, 0) AS is_after
                  FROM request_legs l JOIN requests r ON r.id = l.request_id
                 WHERE l.driver_id IS NOT NULL AND r.id <> ? AND r.status IN ('Driver Assigned','Confirmed','Completed')
                   AND l.start_datetime >= ? AND l.start_datetime < ?
                UNION ALL
                SELECT t.driver_id, IF($afterTask, 1, 0) AS is_after
                  FROM internal_tasks t WHERE t.task_date >= ? AND t.task_date < ?
             ) x GROUP BY driver_id",
            [$workStart, $workEnd, $exceptRequestId, $from, $to, $workStart, $workEnd, $from, $to]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['driver_id']] = ['tasks' => (int)$r['tasks'], 'after_hours' => (int)$r['after_hours']];
        }
        return $out;
    }
}
