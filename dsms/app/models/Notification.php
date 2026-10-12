<?php
/**
 * Notification - the in-app notifications (the bell). Writing e-mails is the
 * job of MailService; NotificationService (Step 5) does both at once.
 */
class Notification extends Model
{
    public function unreadCount(int $userId): int
    {
        try {
            return (int)$this->fetchOne('SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0', [$userId])['c'];
        } catch (Throwable $e) {
            return 0;      // the bell must never break a page
        }
    }

    public function add(int $userId, string $message, ?string $link = null): void
    {
        $this->execute('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)', [$userId, $message, $link]);
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        return $this->fetchAll('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit, [$userId]);
    }

    public function markRead(int $userId, int $id): void
    {
        $this->execute('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    public function markAllRead(int $userId): void
    {
        $this->execute('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$userId]);
    }
}
