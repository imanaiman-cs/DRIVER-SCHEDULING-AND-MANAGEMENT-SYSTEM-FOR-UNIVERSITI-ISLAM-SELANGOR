<?php
/** Reminder - an admin nudging an approver about a request that is still waiting. */
class Reminder extends Model
{
    public function lastSent(int $requestId): ?string
    {
        return $this->fetchOne('SELECT MAX(sent_at) AS t FROM reminders WHERE request_id = ?', [$requestId])['t'] ?? null;
    }

    public function add(int $requestId, int $sentBy): void
    {
        $this->execute('INSERT INTO reminders (request_id, sent_by) VALUES (?, ?)', [$requestId, $sentBy]);
    }

    /** Pending requests with their approver, the time left before they lapse and the last reminder. */
    public function pendingWithStatus(int $lapseHours): array
    {
        return $this->fetchAll(
            "SELECT r.id, r.destination_name, r.departure_datetime, r.submitted_at, r.approver_id,
                    ru.name AS requester_name, au.name AS approver_name,
                    TIMESTAMPDIFF(HOUR, r.submitted_at, NOW()) AS hours_waiting,
                    (SELECT COUNT(*) FROM reminders m WHERE m.request_id = r.id) AS reminders_sent,
                    (SELECT MAX(m.sent_at) FROM reminders m WHERE m.request_id = r.id) AS last_reminder
               FROM requests r JOIN users ru ON ru.id = r.requester_id LEFT JOIN users au ON au.id = r.approver_id
              WHERE r.status = 'Pending Approval' ORDER BY r.submitted_at",
            []
        );
    }
}
