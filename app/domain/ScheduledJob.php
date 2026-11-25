<?php
// ============================================================
// SDD_CLASS_211 - ScheduledJob (Domain Layer, SDD_PKG_200)
// Runs every hour (cron/run_jobs.php; also lightly on page loads): approval reminders after one day (UC020),
// lapsing requests not approved within two days, and re-sending e-mails that failed (UC019).
// ============================================================

class ScheduledJob
{
    /** Hours after submission before a reminder is sent (24). */
    private int $reminderHours;
    /** Hours after submission before a request lapses (48). */
    private int $lapseHours;
    private NotificationService $notifier;
    private RequestController $requests;

    public function __construct()
    {
        $this->reminderHours = (int)setting('REMINDER_HOURS');
        $this->lapseHours = (int)setting('LAPSE_HOURS');
        $this->notifier = new NotificationService();
        $this->requests = new RequestController();
    }

    /** Runs every job and returns the counts. */
    public function run(): array
    {
        return ['reminders' => $this->sendReminders(), 'lapsed' => $this->lapseRequests(), 'completed' => $this->completeRequests(),
                'emails' => $this->notifier->retryFailedEmails()];
    }

    /** One reminder e-mail to the approving head for each request pending more than one day (SDD 5.22.2.2). */
    public function sendReminders(): int
    {
        $n = 0;
        foreach (Request::findPendingOlderThan($this->reminderHours) as $r) {
            if ((int)$r['reminder_sent'] || strtotime($r['depart_datetime']) <= time()) { continue; }
            $head = $this->requests->approverOf($r);
            if (!$head) { continue; }
            $lapseAt = min(strtotime($r['date_submitted']) + $this->lapseHours * 3600, strtotime($r['depart_datetime']));
            $left = max(1, (int)round(($lapseAt - time()) / 3600));
            $sent = $this->notifier->sendEmail($head['email'], 'DSMS reminder: request #' . $r['request_id'] . ' is waiting for your approval',
                "Salam {$head['name']},\n\nReminder: vehicle request " . $this->requests->oneLine($r) . " is still waiting for your approval. It lapses in about $left hour(s) if it is not approved.\n\n"
                . 'Open: ' . NotificationService::url('staff/request.php?id=' . $r['request_id']) . "\n\nUIS Vehicle Section (DSMS)");
            if ($sent || !mailEnabled()) {
                Request::update((int)$r['request_id'], ['reminder_sent' => 1]);
                RequestStatusHistory::record((int)$r['request_id'], $r['status'], $r['status'], null, 'Reminder email sent to ' . $head['name']);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Marks requests pending more than two days as Lapsed and releases their vehicles (SDD 5.22.2.1). A request that is
     * still not approved when its departure time comes lapses too.
     */
    public function lapseRequests(): int
    {
        $n = 0;
        $list = [];
        foreach (Request::findPendingOlderThan($this->lapseHours) as $r) { $list[(int)$r['request_id']] = [$r, 'Not approved within two days']; }
        foreach (Request::findUnapprovedDeparted() as $r) { $list[(int)$r['request_id']] ??= [$r, 'Not approved before the departure time']; }
        foreach ($list as $id => [$r, $why]) {
            Database::beginTransaction();
            $now = Request::findByID($id, true);
            if ($now['status'] !== $r['status']) { Database::rollback(); continue; }
            Request::updateStatus($id, Request::LAPSED, null, $why);
            VehicleReservation::release($id);
            foreach (Task::findByRequest($id) as $t) { Task::updateStatus((int)$t['task_id'], Task::CANCELLED); }
            Database::commit();
            $this->notifier->notify([(int)$r['requester_id']], "Your vehicle request #$id has lapsed: " . lcfirst($why) . '. The vehicle reservation was released.', 'staff/request.php?id=' . $id);
            $n++;
        }
        return $n;
    }

    /** Approved requests without UIS drivers (Pay and Claim, Self Drive) are Completed once the return time has passed. */
    public function completeRequests(): int
    {
        $n = 0;
        foreach (Request::findApprovedReturned() as $r) {
            if ($r['driver_option'] === Request::WITH_DRIVER) { continue; }   // completed by the drivers' task reports
            Request::updateStatus((int)$r['request_id'], Request::COMPLETED, null, 'The trip has ended');
            $n++;
        }
        return $n;
    }

    /** Runs the jobs at most once every 5 minutes (page loads). */
    public static function runThrottled(): void
    {
        $f = sys_get_temp_dir() . '/dsms_jobs_' . md5(DB_NAME . __DIR__);
        if (is_file($f) && time() - (int)@filemtime($f) < 300) { return; }
        $h = @fopen($f, 'c');
        if (!$h || !flock($h, LOCK_EX | LOCK_NB)) { return; }
        touch($f);
        try { (new self())->run(); } catch (Throwable $e) { appLog('error', 'jobs: ' . $e->getMessage()); }
        flock($h, LOCK_UN); fclose($h);
    }
}
