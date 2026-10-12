<?php
/**
 * RequestComment - the conversation on a request.
 * R9: a comment can NEVER be edited or deleted. This class has no update/delete method and the
 * database triggers also refuse UPDATE and DELETE, so the record stays trustworthy.
 */
class RequestComment extends Model
{
    public function forRequest(int $requestId): array
    {
        return $this->fetchAll(
            'SELECT c.*, u.name FROM request_comments c JOIN users u ON u.id = c.user_id WHERE c.request_id = ? ORDER BY c.created_at, c.id',
            [$requestId]
        );
    }

    public function add(int $requestId, int $userId, string $role, string $comment): void
    {
        $this->execute(
            'INSERT INTO request_comments (request_id, user_id, user_role, comment) VALUES (?, ?, ?, ?)',
            [$requestId, $userId, $role, $comment]
        );
    }
}
