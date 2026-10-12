<?php
/**
 * Jobs - the background housekeeping (R11).
 *
 *   1. LAPSE      A request still "Pending Approval" after LAPSE_HOURS (default 48 h), or whose
 *                 trip time has already passed, becomes "Lapsed" and the vehicle is released.
 *   2. EXPIRE     An approved request that never got confirmed before the trip started is cancelled
 *                 by the system (otherwise it would hold a vehicle for a trip that cannot happen).
 *   3. COMPLETE   A "Confirmed" trip whose return time has passed becomes "Completed".
 *
 * It runs from the cron script (cron/run_jobs.php, e.g. every 15 minutes) AND lightly on page
 * loads (at most once every few minutes), so the system still behaves correctly on a laptop
 * where no scheduler is set up.
 */
class Jobs
{
    private const THROTTLE_SECONDS = 300;

    /** @return array{lapsed:int, expired:int, completed:int} */
    public function run(): array
    {
        return ['lapsed' => $this->lapse(), 'expired' => $this->expire(), 'completed' => $this->complete()];
    }

    /** Run at most once every 5 minutes, whoever loads a page. A file lock stops two visitors running it together. */
    public function runThrottled(): void
    {
        $file = $GLOBALS['config']['log_dir'] . '/jobs_last_run.txt';
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            $last = (int)trim((string)stream_get_contents($fh));
            if (time() - $last >= self::THROTTLE_SECONDS) {
                try {
                    $this->run();
                } finally {
                    ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)time());
                }
            }
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    public function lapse(): int
    {
        $hours = (new Setting())->int('LAPSE_HOURS', 48);
        $days  = max(1, (int)round($hours / 24));
        $reason = $hours % 24 === 0
            ? "Your request lapsed because it was not approved within $days day" . ($days > 1 ? 's' : '') . '.'
            : "Your request lapsed because it was not approved within $hours hours.";
        return $this->each(
            "SELECT id FROM requests WHERE status = 'Pending Approval'
                AND (submitted_at < DATE_SUB(NOW(), INTERVAL $hours HOUR) OR departure_datetime <= NOW())",
            RequestStatus::LAPSED, 'lapse', $reason, 'Request #%d lapsed: %s'
        );
    }

    public function expire(): int
    {
        return $this->each(
            "SELECT id FROM requests WHERE status IN ('Approved','Driver Assigned') AND departure_datetime <= NOW()",
            RequestStatus::CANCELLED, 'auto_cancel', 'The trip time passed before the request was confirmed, so it was cancelled by the system.', 'Request #%d was cancelled by the system: %s'
        );
    }

    public function complete(): int
    {
        return $this->each(
            "SELECT id FROM requests WHERE status = 'Confirmed' AND return_datetime < NOW()",
            RequestStatus::COMPLETED, 'complete', null, 'Request #%d is completed.'
        );
    }

    /** Move every request the query finds to $to, as the system, then tell the people involved. */
    private function each(string $selectSql, string $to, string $action, ?string $reason, string $messageFormat): int
    {
        $model = new TripRequest();
        $db = Database::connection();
        $count = 0;
        foreach ($db->query($selectSql)->fetchAll() as $row) {
            $id = (int)$row['id'];
            try {
                $request = $model->find($id);
                $people  = $model->peopleOf($request);          // before cancelling clears the drivers
                $model->changeStatus($id, $to, $action, $reason, [], null, null, true);
                NotificationService::notify($people, sprintf($messageFormat, $id, $reason ?? ''), page_link('requests/show', ['id' => $id]));
                $count++;
            } catch (Throwable $e) {
                // Someone changed it at the same moment (it is no longer in the old status): skip it.
                app_log("Job $action on request #$id skipped: " . $e->getMessage(), 'error.log');
            }
        }
        return $count;
    }
}
