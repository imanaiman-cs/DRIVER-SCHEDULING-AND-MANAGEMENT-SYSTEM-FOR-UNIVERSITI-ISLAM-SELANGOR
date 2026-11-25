<?php
// ============================================================
// SDD_CLASS_314 - Notification (Data Access Layer, SDD_PKG_300)
// Reads and writes in-system notifications.
// ============================================================

class Notification
{
    /** Inserts the notification and returns its ID. */
    public static function create(int $userID, string $message, ?string $link = null): int
    {
        Database::execute('INSERT INTO notification (user_id, message, link) VALUES (?, ?, ?)', [$userID, mb_substr($message, 0, 255), $link]);
        return Database::lastInsertId();
    }

    /** Notifications of the user, unread first then newest. */
    public static function findByUser(int $userID, int $limit = 20, int $offset = 0): array
    {
        return Database::query('SELECT * FROM notification WHERE user_id = ? ORDER BY is_read ASC, date_sent DESC, notification_id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset, [$userID]);
    }

    public static function countByUser(int $userID): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM notification WHERE user_id = ?', [$userID]);
    }

    public static function countUnread(int $userID): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM notification WHERE user_id = ? AND is_read = 0', [$userID]);
    }

    public static function findByID(int $notificationID, int $userID): ?array
    {
        return Database::queryOne('SELECT * FROM notification WHERE notification_id = ? AND user_id = ?', [$notificationID, $userID]);
    }

    /** Sets isRead to true. */
    public static function markRead(int $notificationID, int $userID): bool
    {
        return Database::execute('UPDATE notification SET is_read = 1 WHERE notification_id = ? AND user_id = ?', [$notificationID, $userID]) > 0;
    }

    public static function markAllRead(int $userID): void
    {
        Database::execute('UPDATE notification SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$userID]);
    }

    public static function markEmailSent(int $notificationID): void
    {
        Database::execute('UPDATE notification SET email_sent = 1 WHERE notification_id = ?', [$notificationID]);
    }

    /** Notifications of the last two days whose e-mail was not sent (they are tried again by the scheduled job). */
    public static function findEmailNotSent(): array
    {
        return Database::query('SELECT n.*, u.email, u.name FROM notification n JOIN `user` u ON u.user_id = n.user_id
                                 WHERE n.email_sent = 0 AND n.date_sent >= DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY n.notification_id LIMIT 100');
    }
}
