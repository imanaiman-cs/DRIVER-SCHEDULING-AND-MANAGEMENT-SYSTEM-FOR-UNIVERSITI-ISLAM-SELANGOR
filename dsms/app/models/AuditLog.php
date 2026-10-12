<?php
/**
 * AuditLog - R10: the audit log is INSERT-ONLY.
 * This class deliberately has no update or delete method, and the database
 * triggers (see dsms.sql) reject UPDATE/DELETE as a second line of defence.
 */
class AuditLog extends Model
{
    /** user_id = null means the system itself did it (e.g. the lapse job). */
    public function record(string $action, ?int $requestId = null, ?string $reason = null, ?int $userId = null, ?string $role = null): void
    {
        if ($userId === null && Auth::check()) {
            $userId = Auth::id();
            $role   = Auth::user()['role'] ?? null;
        }
        $this->execute(
            'INSERT INTO audit_log (user_id, user_role, action, request_id, reason) VALUES (?, ?, ?, ?, ?)',
            [$userId, $role, $action, $requestId, $reason !== null ? mb_substr($reason, 0, 500) : null]
        );
    }

    /** Done by the system itself (lapse job, auto-complete): user_id stays NULL even if someone happens to be logged in. */
    public function recordSystem(string $action, ?int $requestId = null, ?string $reason = null): void
    {
        $this->execute(
            'INSERT INTO audit_log (user_id, user_role, action, request_id, reason) VALUES (NULL, \'system\', ?, ?, ?)',
            [$action, $requestId, $reason !== null ? mb_substr($reason, 0, 500) : null]
        );
    }

    /** Newest first; optional filters for the viewer page. */
    public function search(string $action = '', int $requestId = 0, int $limit = 200): array
    {
        $where = []; $args = [];
        if ($action !== '')  { $where[] = 'a.action = ?';     $args[] = $action; }
        if ($requestId > 0)  { $where[] = 'a.request_id = ?'; $args[] = $requestId; }
        $sql = 'SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY a.id DESC LIMIT ' . (int)$limit;
        return $this->fetchAll($sql, $args);
    }

    public function actions(): array
    {
        return array_column($this->fetchAll('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action');
    }
}
