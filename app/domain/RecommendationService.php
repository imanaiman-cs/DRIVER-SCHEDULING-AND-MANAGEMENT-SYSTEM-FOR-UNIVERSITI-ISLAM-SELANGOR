<?php
// ============================================================
// SDD_CLASS_206 - RecommendationService (Domain Layer, SDD_PKG_200)
// UC009 Recommend Driver: licence filter, availability filter and the weighted score.
//   1.1 active drivers with a valid licence of the class the vehicle needs
//   1.2 no request or internal task in the task period, no approved schedule change on that date
//   1.3 tasks this month (request, internal, welfare duty) and weekend tasks
//   1.4 score = 0.5 x (fewer tasks) + 0.3 x (fewer weekend tasks) + 0.2 x (more experience)
//   1.5 ranked list; drivers on welfare duty that day are marked
// The best RECOMMEND_TOP drivers are Recommended; choosing another driver needs a confirmation (UC003 A2).
// ============================================================

class RecommendationService
{
    private float $weightTasks;
    private float $weightWeekend;
    private float $weightExperience;

    public function __construct()
    {
        $this->weightTasks = (float)setting('W_TASKS');
        $this->weightWeekend = (float)setting('W_WEEKEND');
        $this->weightExperience = (float)setting('W_EXPERIENCE');
    }

    /**
     * Returns available drivers ranked by score (SDD 5.17.2.1).
     * $licence: the licence class the vehicle needs. $o: except_task (int[]), except_request, exclude_driver.
     * @return array{ranked: array, excluded: array}
     */
    public function recommendDrivers(string $licence, string $start, string $end, array $o = []): array
    {
        $month = substr($start, 0, 7);
        $counts = Driver::monthCounts($month);
        $cands = []; $excluded = [];
        foreach (Driver::findActive() as $d) {
            $id = (int)$d['driver_id'];
            if ($id === (int)($o['exclude_driver'] ?? 0)) { continue; }
            if ($why = $this->unavailableReason($d, $licence, $start, $end, (array)($o['except_task'] ?? []), (int)($o['except_request'] ?? 0))) {
                $excluded[] = ['driver_id' => $id, 'name' => $d['name'], 'reason' => $why];
                continue;
            }
            $c = $counts[$id] ?? ['request' => 0, 'internal' => 0, 'welfare' => 0, 'weekend' => 0, 'tasks' => 0, 'hours' => 0];
            $cands[] = ['driver_id' => $id, 'name' => $d['name'], 'phone' => $d['phone_no'], 'years' => Driver::getYearsOfExperience($d, substr($start, 0, 10)),
                        'on_welfare' => Driver::isOnWelfareDuty($id, $start, $end)] + $c;
        }
        $max = ['tasks' => max(1, ...array_merge([0], array_column($cands, 'tasks'))), 'weekend' => max(1, ...array_merge([0], array_column($cands, 'weekend'))),
                'years' => max(1, ...array_merge([0], array_column($cands, 'years')))];
        foreach ($cands as &$c) { $c['score'] = $this->calculateScore($c, $max); }
        unset($c);
        usort($cands, fn($a, $b) => [$b['score'], $a['tasks'], $a['name']] <=> [$a['score'], $b['tasks'], $b['name']]);
        $top = (int)setting('RECOMMEND_TOP');
        foreach ($cands as $i => &$c) { $c['rank'] = $i + 1; $c['recommended'] = $i < $top; }
        unset($c);
        return ['ranked' => $cands, 'excluded' => $excluded];
    }

    /**
     * The weighted score of one driver, between 0 and 1 (SDD 5.17.2.2).
     * $c: tasks, weekend, years of the driver. $max: the highest of each among the drivers (at least 1).
     */
    public function calculateScore(array $c, array $max): float
    {
        $taskPart = 1 - $c['tasks'] / max(1, $max['tasks']);
        $weekendPart = 1 - $c['weekend'] / max(1, $max['weekend']);
        $experiencePart = $c['years'] / max(1, $max['years']);
        return round($this->weightTasks * $taskPart + $this->weightWeekend * $weekendPart + $this->weightExperience * $experiencePart, 4);
    }

    /**
     * Why a driver cannot take a task, or null. Every assignment checks it, so a driver who fails the filters can never
     * be saved, even when chosen outside the recommendation.
     */
    public function unavailableReason(array $driver, string $licence, string $start, string $end, array $exceptTaskIDs = [], int $exceptRequestID = 0): ?string
    {
        if ($driver['status'] !== User::ACTIVE) { return 'the account is not active'; }
        if (!in_array($licence, explode(',', (string)$driver['licence_classes']), true)) { return "no $licence licence"; }
        if ($driver['licence_expiry'] < substr($end, 0, 10)) { return 'the licence has expired (' . fmtD($driver['licence_expiry']) . ')'; }
        if (ScheduleChange::isApprovedOn((int)$driver['driver_id'], substr($start, 0, 10), date('Y-m-d', strtotime($end) - 1))) { return 'approved schedule change on that date'; }
        if ($c = Task::findOverlapping((int)$driver['driver_id'], $start, $end, $exceptTaskIDs, $exceptRequestID)) {
            $t = $c[0];
            return 'already has ' . ($t['task_source'] === Task::REQUEST ? 'a request task' . ($t['request_id'] ? ' (#' . $t['request_id'] . ')' : '') : 'an internal task') . ' at ' . fmtPeriod($t['start_datetime'], $t['end_datetime']);
        }
        return null;
    }

    /** The reason for one driver by ID. */
    public function driverUnavailableReason(int $driverID, string $licence, string $start, string $end, array $exceptTaskIDs = [], int $exceptRequestID = 0): ?string
    {
        $d = Driver::findByID($driverID);
        return $d ? $this->unavailableReason($d, $licence, $start, $end, $exceptTaskIDs, $exceptRequestID) : 'unknown driver';
    }
}
