<?php
// ============================================================
// SDD_CLASS_308 - RequestStatusHistory (Data Access Layer, SDD_PKG_300)
// Writes and reads the status history of each request. It is the audit record: insert only (database triggers
// refuse any update or delete).
// ============================================================

class RequestStatusHistory
{
    /** Inserts one history row. $userID null = done by the system. */
    public static function record(int $requestID, ?string $oldStatus, string $newStatus, ?int $userID, ?string $remark = null): void
    {
        Database::execute('INSERT INTO request_status_history (request_id, old_status, new_status, changed_by, remark) VALUES (?, ?, ?, ?, ?)',
            [$requestID, $oldStatus, $newStatus, $userID, $remark !== null ? mb_substr($remark, 0, 255) : null]);
    }

    /** The history of a request, oldest first, with the name and role of the user. */
    public static function findByRequest(int $requestID): array
    {
        return Database::query('SELECT h.*, u.name AS changed_by_name, u.role AS changed_by_role FROM request_status_history h
                                LEFT JOIN `user` u ON u.user_id = h.changed_by WHERE h.request_id = ? ORDER BY h.changed_at, h.history_id', [$requestID]);
    }

    /** Is there a history row of the request whose remark starts with $prefix? */
    public static function hasRemark(int $requestID, string $prefix): bool
    {
        return (bool)Database::value('SELECT 1 FROM request_status_history WHERE request_id = ? AND remark LIKE ? LIMIT 1', [$requestID, $prefix . '%']);
    }
}
