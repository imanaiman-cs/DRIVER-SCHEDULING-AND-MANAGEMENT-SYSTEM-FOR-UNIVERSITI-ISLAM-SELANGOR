<?php
// ============================================================
// SDD_CLASS_209 - ReportController (Domain Layer, SDD_PKG_200)
// Builds the driver schedule (UC010) and the monthly reports (UC011), and exports them as PDF or Excel (UC012).
// ============================================================

class ReportController
{
    public const TYPES = ['workload' => 'Driver Workload', 'replacement' => 'Driver Replacement', 'vehicle' => 'Vehicle Usage'];

    /** All tasks of the period for the calendar. Filter: driver_id, vehicle_id. */
    public function getSchedule(string $from, string $to, array $filter = []): array
    {
        return Task::findByWeek($from . ' 00:00:00', $to . ' 00:00:00', $filter);
    }

    /** Every driver (also inactive), for the schedule filter. */
    public function driverList(): array
    {
        return Driver::findAll();
    }

    /**
     * Builds a report (SDD 5.20.2.1).
     * @return array{title:string, month:string, headers:string[], rows:array, totals:array, widths:int[], note:string}
     */
    public function generateReport(string $type, string $month): array
    {
        $rep = ['title' => self::TYPES[$type] ?? $type, 'month' => $month, 'headers' => [], 'rows' => [], 'totals' => [], 'widths' => [], 'note' => ''];
        if ($type === 'workload') {
            $counts = Driver::monthCounts($month);
            $rep['headers'] = ['Driver', 'Staff ID', 'Request tasks', 'Internal tasks', 'Welfare duties', 'Total tasks', 'Weekend tasks', 'Total hours'];
            $rep['widths'] = [30, 12, 12, 12, 12, 11, 12, 11];
            $rep['note'] = 'Tasks assigned, approved or completed in the month (an outbound and a return trip are two request tasks). Weekend tasks start on a Saturday or Sunday.';
            foreach (Driver::findAll() as $d) {
                $c = $counts[(int)$d['driver_id']] ?? ['request' => 0, 'internal' => 0, 'welfare' => 0, 'weekend' => 0, 'tasks' => 0, 'hours' => 0.0];
                if ($d['status'] !== User::ACTIVE && !$c['tasks']) { continue; }
                $rep['rows'][] = [$d['name'], $d['staff_id'], $c['request'], $c['internal'], $c['welfare'], $c['tasks'], $c['weekend'], (float)$c['hours']];
            }
        } elseif ($type === 'replacement') {
            $rep['headers'] = ['Date', 'Task', 'Old driver', 'New driver', 'Reason', 'Status'];
            $rep['widths'] = [14, 26, 18, 18, 30, 11];
            $rep['note'] = 'Every driver replacement recorded in the month, with the reason and the approval status.';
            foreach (DriverReplacement::findByMonth($month) as $r) {
                $task = ($r['task_source'] === Task::REQUEST ? 'Request #' . $r['request_id'] . ' (' . strtolower((string)$r['trip_type']) . ')' : ($r['task_source'] === Task::WELFARE ? 'Welfare duty' : $r['task_type'])) . ', ' . date('d M H:i', strtotime($r['start_datetime']));
                $rep['rows'][] = [date('d M Y', strtotime($r['date_time'])), $task, $r['old_driver_name'], $r['new_driver_name'], $r['reason'], $r['status']];
            }
        } else {
            $rep['headers'] = ['Vehicle', 'Type', 'Trips', 'Total mileage (km)', 'Last fuel level', 'Status'];
            $rep['widths'] = [32, 10, 10, 18, 16, 16];
            $rep['note'] = 'Trips are the tasks with a task report in the month; the mileage is the distance between the reports of each vehicle. The fuel level is the last one reported.';
            $from = $month . '-01 00:00:00'; $to = date('Y-m-d 00:00:00', strtotime($month . '-01 +1 month'));
            foreach (Vehicle::findAll() as $v) {
                $reports = array_values(array_filter(TaskReport::findByVehicle((int)$v['vehicle_id'], 500), fn($x) => $x['actual_depart'] >= $from && $x['actual_depart'] < $to));
                usort($reports, fn($a, $b) => strcmp($a['actual_return'], $b['actual_return']));
                $km = 0;
                foreach ($reports as $rp) {
                    $prev = TaskReport::previousMileage((int)$v['vehicle_id'], (int)$rp['report_id'], $rp['actual_depart']);
                    $km += $prev !== null ? max(0, (int)$rp['end_mileage'] - $prev) : 0;
                }
                if ($v['status'] === Vehicle::INACTIVE && !$reports) { continue; }
                $rep['rows'][] = [trim($v['plate_no'] . ' ' . $v['brand'] . ' ' . $v['model']), $v['type'], count($reports), $km, $reports ? end($reports)['fuel_level'] : '-', $v['status']];
            }
        }
        $n = count($rep['headers']);
        $tot = array_fill(0, $n, ''); $tot[0] = 'Total';
        for ($c = 1; $c < $n; $c++) {
            $isNum = (bool)$rep['rows']; $sum = 0;
            foreach ($rep['rows'] as $row) { if (!is_int($row[$c]) && !is_float($row[$c])) { $isNum = false; break; } $sum += $row[$c]; }
            if ($isNum) { $tot[$c] = is_float($sum) ? round($sum, 1) : $sum; }
        }
        if ($type === 'replacement') { $tot[1] = count($rep['rows']) . ' replacement(s)'; }
        $rep['totals'] = $tot;
        return $rep;
    }

    /** Exports the report as a PDF or Excel file (sends the download and stops). */
    public function exportReport(string $type, string $month, string $format): never
    {
        $rep = $this->generateReport($type, $month);
        if ($format === 'xlsx') { exportReportXlsx($rep, $type); }
        exportReportPdf($rep, $type);
    }
}
