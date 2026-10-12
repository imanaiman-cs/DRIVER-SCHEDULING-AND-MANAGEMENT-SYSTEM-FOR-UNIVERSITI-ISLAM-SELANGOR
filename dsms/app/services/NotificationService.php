<?php
/**
 * NotificationService - tell people something happened.
 * Always writes the in-app notification (the bell). Step 5 adds the e-mail here too,
 * so no other class needs to know HOW a person is notified.
 */
class NotificationService
{
    /** @param int|int[] $userIds */
    public static function notify($userIds, string $message, ?string $link = null): void
    {
        $notification = new Notification();
        foreach (array_unique(array_filter((array)$userIds)) as $userId) {
            try {
                $notification->add((int)$userId, mb_substr($message, 0, 500), $link);
            } catch (Throwable $e) {
                // A failed notification must never undo the real action (approve, cancel, ...).
                app_log('Notification failed: ' . $e->getMessage(), 'error.log');
            }
        }
    }
}
